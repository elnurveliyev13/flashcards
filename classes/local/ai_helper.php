<?php

namespace mod_flashcards\local;

use coding_exception;
use core_text;
use moodle_exception;
use Throwable;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates AI focus phrase detection + dictionary lookup + TTS generation.
 */
class ai_helper {
    /** @var openai_client */
    protected $openai;
    /** @var tts_service */
    protected $tts;

    public function __construct(?openai_client $openai = null, ?tts_service $tts = null) {
        $this->openai = $openai ?? new openai_client();
        $this->tts = $tts ?? new tts_service();
    }

    /**
     * Main entry for AJAX handler.
     *
     * @param int $userid
     * @param string $fronttext
     * @param string $clickedword
     * @param array $options ['language' => string, 'voice' => string|null]
     * @return array
     * @throws moodle_exception
     */
    public function process_focus_request(int $userid, string $fronttext, string $clickedword, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }

        $language = trim($options['language'] ?? '') ?: 'uk';
        $level = trim($options['level'] ?? '');
        $focusdata = $this->openai->detect_focus_data($userid, $fronttext, $clickedword, [
            'language' => $language,
            'level' => $level,
        ]);

        $focusword = trim($focusdata['focus'] ?? '') ?: $clickedword;
        $focusword = $this->enforce_clicked_focus($focusword, $clickedword);
        $focusphrase = trim($focusdata['focusphrase'] ?? '');
        if ($focusphrase !== '') {
            $focusphrase = $this->strip_articles_and_markers($focusphrase);
            $focusword = $focusphrase;
        }

        if ($focusphrase !== '' && $this->phrase_has_missing_particles($focusphrase, $fronttext)) {
            $focusphrase = '';
            $focusword = $this->enforce_clicked_focus($clickedword, $clickedword);
        }

        // Base form: ALWAYS extract single word from clicked word (no articles, no å)
        $baseform = $this->extract_base_form($clickedword, $focusword);

        $pos = trim($focusdata['pos'] ?? '');

        $result = [
            'focusWord' => $focusword,
            'focusBaseform' => $baseform,
            'focusExpression' => $focusphrase ?: null,
            'pos' => $pos,
            'gender' => $focusdata['gender'] ?? '',
            'translation_lang' => $focusdata['translation_lang'] ?? $language,
        ];

        if ($focusphrase === '') {
            $result['focusWord'] = $this->build_lemma_focus_word(
                $result['focusWord'],
                $result['focusBaseform'],
                $result['pos'],
                $result['gender'] ?? ''
            );
        }

        if (!empty($focusdata['definition'])) {
            $result['definition'] = $focusdata['definition'];
        }
        if (!empty($focusdata['translation'])) {
            $result['translation'] = $focusdata['translation'];
        }
        if (!empty($focusdata['analysis']) && is_array($focusdata['analysis'])) {
            $result['analysis'] = $focusdata['analysis'];
        }
        if (!empty($focusdata['correction'])) {
            $result['correction'] = $focusdata['correction'];
        }
        if (!empty($focusdata['collocations']) && is_array($focusdata['collocations'])) {
            $result['collocations'] = $focusdata['collocations'];
        }
        if (!empty($focusdata['examples']) && is_array($focusdata['examples'])) {
            $result['examples'] = $focusdata['examples'];
        }

        if ($focusword !== '' && orbokene_repository::is_enabled() && ($dict = orbokene_repository::find($focusword))) {
            if (!empty($dict['baseform'])) {
                $result['focusBaseform'] = $dict['baseform'];
            }
            if (!empty($dict['definition'])) {
                $result['definition'] = $dict['definition'];
            }
            if (!empty($dict['translation'])) {
                $result['translation'] = $dict['translation'];
            }
            if (!empty($dict['examples'])) {
                $result['examples'] = $dict['examples'];
            }
            if (!empty($dict['grammar'])) {
                $result['grammar'] = $dict['grammar'];
            }
        }

        $lookupWord = trim($result['focusBaseform'] ?? '');
        if ($lookupWord !== '') {
            $transcription = self::lookup_phrase_transcription($lookupWord, $pos);
            if ($transcription) {
                $result['transcription'] = $transcription;
            }
        }

        $audio = [];
        $errors = [];
        if ($this->tts->is_enabled()) {
            $voice = $options['voice'] ?? null;
            $frontAudioText = $this->select_front_audio_text($fronttext, $result['examples'] ?? []);
            try {
                if ($frontAudioText !== '') {
                    $audio['front'] = $this->tts->synthesize($userid, $frontAudioText, [
                        'voice' => $voice,
                        'label' => 'front',
                    ]);
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
                debugging('[flashcards] TTS front_text failed: ' . $message, DEBUG_DEVELOPER);
                $errors['tts_front'] = $message;
            }
            try {
                $focusAudioText = trim((string)($result['focusBaseform'] ?? ''));
                if ($focusAudioText === '') {
                    $focusAudioText = trim((string)($result['focusWord'] ?? $focusword));
                }
                if ($focusAudioText !== '') {
                    if (!preg_match('/[.!?]$/', $focusAudioText)) {
                        $focusAudioText .= '.';
                    }
                    $audio['focus'] = $this->tts->synthesize($userid, $focusAudioText, [
                        'voice' => $voice,
                        'label' => 'focus',
                    ]);
                    $result['audioFocusText'] = $focusAudioText;
                    if (!empty($audio['focus']['provider_decision'])) {
                        $result['audioProviderDecision'] = $audio['focus']['provider_decision'];
                    }
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
                debugging('[flashcards] TTS focus failed: ' . $message, DEBUG_DEVELOPER);
                $errors['tts_focus'] = $message;
            }
        }

        if (!empty($audio)) {
            $result['audio'] = $audio;
        }
        if (!empty($errors)) {
            $result['errors'] = $errors;
        }

        // Include token usage information if available
        if (!empty($focusdata['usage'])) {
            $result['usage'] = $focusdata['usage'];
        }

        return $result;
    }

    public function translate_text(int $userid, string $text, string $source, string $target, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $textnorm = core_text::strtolower(trim($text));
        $direction = trim((string)($options['direction'] ?? ''));
        $cachekey = sha1('ai_translate:v1:' . $source . ':' . $target . ':' . $direction . ':' . $textnorm);
        try {
            $cache = \cache::make('mod_flashcards', 'ai_translate');
            $cached = $cache->get($cachekey);
            if (is_array($cached) && isset($cached['translation'])) {
                $cached['cached'] = true;
                return $cached;
            }
        } catch (\Throwable $e) {
            // Ignore cache failures.
        }

        $translation = $this->openai->translate_text($userid, $text, $source, $target, $options);
        $result = [
            'translation' => $translation['translation'] ?? '',
            'sourceLang' => $translation['source'] ?? $source,
            'targetLang' => $translation['target'] ?? $target,
        ];

        // Include token usage information if available
        if (!empty($translation['usage'])) {
            $result['usage'] = $translation['usage'];
        }

        try {
            $cache = \cache::make('mod_flashcards', 'ai_translate');
            $cache->set($cachekey, $result);
        } catch (\Throwable $e) {
            // Ignore cache failures.
        }

        return $result;
    }

    protected function truncate_debug_text(string $text, int $maxChars = 8000): string {
        $text = (string)$text;
        if ($maxChars <= 0) {
            return '';
        }
        if (core_text::strlen($text) <= $maxChars) {
            return $text;
        }
        return core_text::substr($text, 0, $maxChars) . "\n(truncated)";
    }

    /**
     * Enrich sentence elements with translations/notes via LLM.
     *
     * @param string $text Sentence (Norwegian)
     * @param array $words Word elements with pos/dep/lemma
     * @param array $expressions Multi-word expressions
     * @param string $language Interface language for output
     * @param int $userid User ID for usage tracking
     * @param bool $debugAi When true, include debug info (prompts + raw model output)
     * @return array{sentenceTranslation:string,elements:array<int,array<string,mixed>>,usage?:array}
     */
    public function enrich_sentence_elements(string $text, array $words, array $expressions, string $language, int $userid, bool $debugAi = false, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';
        $text = trim($text);
        if ($text === '') {
            return ['sentenceTranslation' => '', 'elements' => []];
        }

        $skipSentenceTranslation = !empty($options['skip_sentence_translation']);

        // Keep llm_enrich lightweight: sentence-level translation + multiword expressions only.
        // Word-by-word "gloss" tends to be misleading in context (e.g. "i dag" -> "dag=day"),
        // and it significantly increases token usage.
        $elements = [];
        $seen = [];
        $exprs = array_slice(array_values($expressions), 0, 20);
        foreach ($exprs as $expr) {
            $label = trim((string)($expr['expression'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = core_text::strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $elements[] = [
                'type' => 'phrase',
                'text' => $label,
            ];
        }

        $systemprompt = <<<"SYSTEMPROMPT"
You are an expert Norwegian (Bokmål) tutor.
Return ONLY valid JSON, no extra text.
All translations and notes must be in $langname.
SYSTEMPROMPT;

        $elementJson = json_encode($elements, JSON_UNESCAPED_UNICODE);
        $translationLine = $skipSentenceTranslation
            ? '- Set "sentenceTranslation" to an empty string (do NOT translate the full sentence).'
            : '- Provide a natural translation of the full sentence in "sentenceTranslation".';

        $userprompt = <<<"USERPROMPT"
Translate the Norwegian sentence (optional) and (if present) the listed multiword expressions.

Sentence:
"$text"

Expressions (do NOT add/remove/reorder):
$elementJson

Return ONLY valid JSON in this schema:
{
  "sentenceTranslation": "translation of the whole sentence",
  "elements": [
    {
      "type": "phrase",
      "text": "exact expression text",
      "translation": "short translation",
      "note": "optional short meaning note"
    }
  ]
}

Rules:
- Keep the SAME order as provided.
- Only output the provided phrases in "elements".
$translationLine
- If unsure, use an empty string.
USERPROMPT;

        $client = $this->openai;
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'translation');

        $payload = [
            'model' => $model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
        ];

        $modelkey = core_text::strtolower(trim((string)$model));
        if ($modelkey !== '' && $this->requires_default_temperature($modelkey)) {
            unset($payload['temperature']);
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload['reasoning_effort'] = $getReasoningMethod->invoke($client, 'translation');
        }
        if ($modelkey !== '' && !isset($payload['max_tokens']) && !isset($payload['max_completion_tokens'])) {
            if ($this->uses_max_completion_tokens($modelkey)) {
                $payload['max_completion_tokens'] = 320;
            } else {
                $payload['max_tokens'] = 320;
            }
        }

        $method = $reflection->getMethod('request');
        $method->setAccessible(true);
        $recordMethod = $reflection->getMethod('record_usage');
        $recordMethod->setAccessible(true);

        $response = $method->invoke($client, $payload);
        $recordMethod->invoke($client, $userid, $response->usage ?? null);

        $content = trim($response->choices[0]->message->content ?? '');
        $parsed = $this->parse_json_response($content);
        if ($parsed === null) {
            return ['sentenceTranslation' => '', 'elements' => []];
        }

        $result = [
            'sentenceTranslation' => trim((string)($parsed['sentenceTranslation'] ?? '')),
            'elements' => is_array($parsed['elements'] ?? null) ? $parsed['elements'] : [],
        ];
        if ($debugAi) {
            $result['_debug'] = [
                'task' => 'translation',
                'system' => $this->truncate_debug_text($systemprompt),
                'user' => $this->truncate_debug_text($userprompt),
                'payload' => $payload,
                'raw' => $this->truncate_debug_text($content),
            ];
        }
        $modelused = (string)($response->model ?? ($payload['model'] ?? ''));
        if ($modelused !== '') {
            $result['model'] = $modelused;
        }
        if (!empty($payload['reasoning_effort'])) {
            $result['reasoning_effort'] = $payload['reasoning_effort'];
        }

        if (!empty($response->usage)) {
            $result['usage'] = (array)$response->usage;
        }

        return $result;
    }

    /**
     * Confirm candidate expressions using LLM (only when Ordbokene could not confirm).
     *
     * @param string $text
     * @param array $candidates
     * @param string $language
     * @param int $userid
     * @param bool $debugAi When true, include debug info (prompts + raw model output)
     * @return array{confirmed:array<int,array<string,string>>,usage?:array}
     */
    public function confirm_expression_candidates(string $text, array $candidates, string $language, int $userid, bool $debugAi = false): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';
        $text = trim($text);
        if ($text === '' || empty($candidates)) {
            return ['confirmed' => []];
        }
        $list = [];
        foreach ($candidates as $cand) {
            $expr = trim((string)($cand['expression'] ?? ''));
            if ($expr !== '') {
                $list[] = $expr;
            }
        }
        $list = array_values(array_unique($list));
        if (empty($list)) {
            return ['confirmed' => []];
        }
        $systemprompt = <<<"SYSTEMPROMPT"
You are an expert Norwegian (Bokmål) linguist.
Return ONLY valid JSON, no extra text.
All translations and notes must be in $langname.
SYSTEMPROMPT;

        $candidateJson = json_encode($list, JSON_UNESCAPED_UNICODE);
        $userprompt = <<<"USERPROMPT"
Sentence:
"$text"

Candidate expressions (exact strings):
$candidateJson

Task:
Select ONLY the expressions that are actually used in this sentence (as multi-word expressions or fixed collocations).
Return JSON in this exact schema:
{
  "confirmed": [
    {
      "expression": "exact candidate string",
      "translation": "short translation",
      "note": "very short meaning note (optional)"
    }
  ]
}

Rules:
- Use ONLY expressions from the candidate list.
- Do NOT invent new expressions.
- If none apply, return an empty confirmed array.
USERPROMPT;

        $client = $this->openai;
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'expression');

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
        ];

        $modelkey = core_text::strtolower(trim((string)$model));
        if ($modelkey !== '' && $this->requires_default_temperature($modelkey)) {
            unset($payload['temperature']);
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload['reasoning_effort'] = $getReasoningMethod->invoke($client, 'expression');
        }

        $method = $reflection->getMethod('request');
        $method->setAccessible(true);
        $recordMethod = $reflection->getMethod('record_usage');
        $recordMethod->setAccessible(true);

        $response = $method->invoke($client, $payload);
        $recordMethod->invoke($client, $userid, $response->usage ?? null);

        $content = trim($response->choices[0]->message->content ?? '');
        $parsed = $this->parse_json_response($content);
        if ($parsed === null) {
            return ['confirmed' => []];
        }
        $confirmed = is_array($parsed['confirmed'] ?? null) ? $parsed['confirmed'] : [];
        $result = ['confirmed' => $confirmed];
        if ($debugAi) {
            $result['_debug'] = [
                'task' => 'expression_confirm',
                'system' => $this->truncate_debug_text($systemprompt),
                'user' => $this->truncate_debug_text($userprompt),
                'payload' => $payload,
                'raw' => $this->truncate_debug_text($content),
            ];
        }
        $modelused = (string)($response->model ?? ($payload['model'] ?? ''));
        if ($modelused !== '') {
            $result['model'] = $modelused;
        }
        if (!empty($payload['reasoning_effort'])) {
            $result['reasoning_effort'] = $payload['reasoning_effort'];
        }
        if (!empty($response->usage)) {
            $result['usage'] = (array)$response->usage;
        }
        return $result;
    }

    /**
     * Run a tutor-style sentence explanation (structured JSON).
     *
     * @param int $userid
     * @param string $text
     * @param array $options
     * @return array
     * @throws moodle_exception
     */
    public function explain_sentence(int $userid, string $text, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $debugAi = !empty($options['debug_ai']);
        $cachekey = trim((string)($options['cache_key'] ?? ''));
        if (!$debugAi && $cachekey !== '') {
            try {
                $cache = \cache::make('mod_flashcards', 'ai_sentence_explain');
                $cached = $cache->get($cachekey);
                if (is_array($cached) && !empty($cached)) {
                    $cached['cached'] = true;
                    return $cached;
                }
            } catch (\Throwable $e) {
                // Ignore cache failures.
            }
        }

        // Payload should already contain prompt/messages on caller side; here we just pass through to OpenAI.
        $payload = $options['payload'] ?? [];
        $raw = $this->openai->chat($userid, $payload, $debugAi);

        $sentenceTranslation = trim((string)($raw['sentenceTranslation'] ?? ''));
        $analysis = trim((string)($raw['analysis'] ?? ''));
        $sections = [];
        if (!empty($raw['sections']) && is_array($raw['sections'])) {
            foreach ($raw['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = trim((string)($section['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $bullets = [];
                if (!empty($section['bullets']) && is_array($section['bullets'])) {
                    foreach ($section['bullets'] as $b) {
                        $b = trim((string)$b);
                        if ($b !== '') {
                            $bullets[] = $b;
                        }
                        if (count($bullets) >= 8) {
                            break;
                        }
                    }
                }
                $sections[] = [
                    'title' => $title,
                    'bullets' => $bullets,
                ];
                if (count($sections) >= 6) {
                    break;
                }
            }
        }

        $breakdownTitle = trim((string)($raw['breakdownTitle'] ?? ''));
        $breakdown = [];
        if (!empty($raw['breakdown']) && is_array($raw['breakdown'])) {
            foreach ($raw['breakdown'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $no = trim((string)($item['no'] ?? ''));
                $tr = trim((string)($item['tr'] ?? ''));
                $kind = trim((string)($item['kind'] ?? ''));
                if ($no === '' || $tr === '') {
                    continue;
                }
                $breakdown[] = ['no' => $no, 'tr' => $tr, 'kind' => $kind];
                if (count($breakdown) >= 12) {
                    break;
                }
            }
        }

        if (empty($sections) && !empty($breakdown)) {
            $title = $breakdownTitle !== '' ? $breakdownTitle : 'Breakdown';
            $bullets = [];
            foreach ($breakdown as $item) {
                $bullets[] = $item['no'] . ' — ' . $item['tr'];
            }
            $sections[] = [
                'title' => $title,
                'bullets' => array_slice($bullets, 0, 12),
            ];
        }

        if ($analysis === '' && !empty($sections)) {
            $parts = [];
            foreach ($sections as $s) {
                $block = $s['title'];
                $bullets = is_array($s['bullets'] ?? null) ? $s['bullets'] : [];
                foreach ($bullets as $b) {
                    $block .= "\n- " . $b;
                }
                $parts[] = $block;
            }
            $analysis = trim(implode("\n\n", $parts));
        }
        $exprs = [];
        $explicitExprs = [];
        foreach ($breakdown as $item) {
            if (($item['kind'] ?? '') !== 'expr') {
                continue;
            }
            $expr = trim((string)($item['no'] ?? ''));
            if ($expr === '') {
                continue;
            }
            $explicitExprs[] = [
                'expression' => $expr,
                'translation' => $item['tr'] ?? '',
                'note' => '',
                'breakdown' => [],
                'examples' => [],
            ];
        }
        if (!empty($explicitExprs)) {
            $exprs = $explicitExprs;
        }

        if (empty($exprs)) {
        $exprStop = array_fill_keys([
            'en', 'ei', 'et', 'den', 'det', 'de',
            'ett', 'to', 'tre', 'fire', 'fem', 'seks', 'sju', 'syv', 'åtte', 'ni', 'ti',
            'jeg', 'du', 'han', 'hun', 'vi', 'dere', 'de', 'meg', 'deg', 'oss', 'dem', 'seg',
        ], true);
        $seenExpr = [];
        foreach ($breakdown as $item) {
            $expr = trim((string)($item['no'] ?? ''));
            if ($expr === '') {
                continue;
            }
            $tokens = [];
            if (preg_match_all('/[\\p{L}\\p{M}]+/u', core_text::strtolower($expr), $m)) {
                $tokens = $m[0] ?? [];
            }
            $tokens = array_values(array_filter(array_map('trim', $tokens)));
            if (count($tokens) < 2) {
                continue;
            }
            // Allow expressions that start with infinitive marker "å" by stripping it.
            if ($tokens[0] === 'å' && count($tokens) >= 3) {
                array_shift($tokens);
            }
            $hasStop = false;
            foreach ($tokens as $t) {
                if (isset($exprStop[$t])) {
                    $hasStop = true;
                    break;
                }
            }
            if ($hasStop) {
                continue;
            }
            $exprNormalized = implode(' ', $tokens);
            if ($exprNormalized === '') {
                continue;
            }
            $key = core_text::strtolower($exprNormalized);
            if (isset($seenExpr[$key])) {
                continue;
            }
            $seenExpr[$key] = true;
            $exprs[] = [
                'expression' => $exprNormalized,
                'translation' => $item['tr'] ?? '',
                'note' => '',
                'breakdown' => [],
                'examples' => [],
            ];
            if (count($exprs) >= 3) {
                break;
            }
        }
        }
        $result = [
            'analysis' => $analysis,
            'sections' => $sections,
            'sentenceTranslation' => $sentenceTranslation,
            'breakdown' => $breakdown,
            'expressions' => $exprs,
        ];
        if (!empty($raw['usage'])) {
            $result['usage'] = (array)$raw['usage'];
        }
        if ($debugAi && !empty($raw['ai_debug'])) {
            $result['ai_debug'] = $raw['ai_debug'];
        }

        if (!$debugAi && $cachekey !== '') {
            $hasContent = $sentenceTranslation !== '' || $analysis !== '' ||
                !empty($sections) || !empty($exprs) || !empty($breakdown);
            if ($hasContent) {
                try {
                    $cache = \cache::make('mod_flashcards', 'ai_sentence_explain');
                    $cache->set($cachekey, $result);
                } catch (\Throwable $e) {
                    // Ignore cache failures.
                }
            }
        }
        return $result;
    }

    public function answer_question(int $userid, string $fronttext, string $question, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }

        $fronttext = trim($fronttext);
        $question = trim($question);
        if ($fronttext === '' || $question === '') {
            throw new coding_exception('Missing text for AI question');
        }

        $language = clean_param($options['language'] ?? 'uk', PARAM_ALPHANUMEXT);
        $result = $this->openai->answer_question($userid, $fronttext, $question, $language);

        // Include token usage information if available
        if (!empty($result['usage'])) {
            // Usage is already in the result from openai->answer_question
        }

        return $result;
    }

    /**
     * Delegate to OpenAI to pick the best definition among candidates using context.
     */
    public function choose_best_definition(string $fronttext, string $focusword, array $definitions, string $language, int $userid): ?array {
        return $this->openai->choose_best_definition($fronttext, $focusword, $definitions, $language, $userid);
    }

    protected function enforce_clicked_focus(string $focus, string $clicked): string {
        $focus = trim($focus);
        $clicked = trim($clicked);
        if ($clicked === '') {
            return $focus;
        }
        if ($focus === '') {
            return $clicked;
        }
        if ($this->focus_contains_clicked($focus, $clicked)) {
            return $focus;
        }
        return $clicked;
    }

    protected function focus_contains_clicked(string $focus, string $clicked): bool {
        $needle = $this->normalize_token($clicked);
        if ($needle === '') {
            return false;
        }
        $tokens = preg_split('/\s+/u', trim($focus));
        if (!$tokens) {
            $tokens = [$focus];
        }
        foreach ($tokens as $token) {
            $candidate = $this->normalize_token($token);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                return true;
            }
        }
        return false;
    }

    protected function normalize_token(string $value): string {
        $value = core_text::strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-z0-9æøå]/u', '', $value);
        return $value ?: '';
    }

    /**
     * Check whether a suggested phrase relies on particles/prepositions that are absent in the learner sentence.
     */
    protected function phrase_has_missing_particles(string $phrase, string $sentence): bool {
        $phraseTokens = $this->tokenize_words($phrase);
        if (count($phraseTokens) < 2) {
            return false;
        }
        $sentenceTokens = array_unique($this->tokenize_words($sentence));
        $sentenceSet = array_flip($sentenceTokens);
        foreach (array_slice($phraseTokens, 1) as $token) {
            if (isset($sentenceSet[$token])) {
                continue;
            }
            if ($this->is_particle_like($token)) {
                return true;
            }
        }
        return false;
    }

    protected function tokenize_words(string $text): array {
        $text = core_text::strtolower($text);
        preg_match_all('/[\p{L}]+/u', $text, $matches);
        return $matches[0] ?? [];
    }

    protected function is_particle_like(string $token): bool {
        $token = core_text::strtolower($token);
        $particles = [
            'om', 'opp', 'ut', 'inn', 'innom', 'ned', 'over', 'til', 'fra',
            'for', 'med', 'av', 'på', 'paa', 'pa', 'igjen', 'bort', 'fram', 'frem',
            'hjem', 'hjemme', 'hjemmefra', 'etter', 'under', 'uten', 'hos',
            'mot', 'mellom', 'rundt',
        ];
        return in_array($token, $particles, true);
    }

    /**
     * Remove Norwegian articles (en, ei, et) and infinitive marker (å) from the beginning of a word/phrase.
     * This ensures transcription lookup uses only the base word form.
     *
     * @param string $text The text to clean
     * @return string The cleaned text with articles and markers removed
     */
    protected static function strip_articles_and_markers(string $text): string {
        $text = trim($text);
        // Remove infinitive marker å at the beginning
        $text = preg_replace('/^å\s+/iu', '', $text);
        // Remove articles en, ei, et at the beginning
        $text = preg_replace('/^(en|ei|et)\s+/iu', '', $text);
        return trim($text);
    }

    /**
     * Lookup transcription for single words or phrases (multiple words).
     * For phrases, looks up each word separately and combines results.
     * Missing words are marked with [?].
     *
     * @param string $phrase The word or phrase to lookup
     * @param string|null $pos Part of speech
     * @return string|null Combined transcription or null if all words not found
     */
    protected static function lookup_phrase_transcription(string $phrase, ?string $pos): ?string {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return null;
        }

        // Split into words
        $words = preg_split('/\s+/u', $phrase);
        if (!$words) {
            return null;
        }

        $transcriptions = [];
        $foundAny = false;

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            // Strip articles/å from each word
            $cleanWord = self::strip_articles_and_markers($word);
            $trans = pronunciation_manager::lookup_transcription($cleanWord, $pos);

            if ($trans) {
                $transcriptions[] = $trans;
                $foundAny = true;
            } else {
                $transcriptions[] = '[?]';
            }
        }

        // Return combined transcription only if at least one word was found
        return $foundAny ? implode(' ', $transcriptions) : null;
    }

    /**
     * Extract single base word from clicked word (remove articles and å).
     * This is used to populate the "Base form" field.
     *
     * @param string $clickedword The word the user clicked
     * @param string $focusword The focus phrase/word returned by AI
     * @return string Single base word without articles/å
     */
    protected function extract_base_form(string $clickedword, string $focusword): string {
        // Try to find the clicked word within the focus phrase
        $focusTokens = preg_split('/\s+/u', trim($focusword));
        if (!$focusTokens) {
            $focusTokens = [$focusword];
        }

        $clickedNormalized = $this->normalize_token($clickedword);
        $bestMatch = null;

        // Find which token in focus phrase contains the clicked word
        foreach ($focusTokens as $token) {
            $tokenNormalized = $this->normalize_token($token);
            if ($tokenNormalized === '' || $clickedNormalized === '') {
                continue;
            }
            // Check if this token contains or matches the clicked word
            if (str_contains($tokenNormalized, $clickedNormalized) ||
                str_contains($clickedNormalized, $tokenNormalized)) {
                $bestMatch = $token;
                break;
            }
        }

        // If found a match in focus, use it; otherwise use clicked word
        $baseWord = $bestMatch ?: $clickedword;

        // Remove articles and å marker
        $baseWord = self::strip_articles_and_markers($baseWord);

        return trim($baseWord);
    }

    /**
     * Build a normalized focus word that always reflects the lemma rules.
     */
    protected function build_lemma_focus_word(string $focusword, string $baseform, string $pos, string $gender): string {
        $focusword = trim($focusword);
        $baseform = trim($baseform);
        $pos = core_text::strtolower(trim($pos));

        if ($baseform === '') {
            return $focusword;
        }

        switch ($pos) {
            case 'verb':
                $candidate = $this->ensure_infinitive_form($baseform);
                break;
            case 'substantiv':
                $candidate = $this->ensure_substantive_form($baseform, core_text::strtolower(trim($gender)));
                break;
            case 'adjektiv':
                $candidate = $this->ensure_adjective_form($baseform);
                break;
            case 'adverb':
                $candidate = $this->ensure_adverb_form($baseform);
                break;
            default:
                $candidate = self::strip_articles_and_markers($baseform);
                break;
        }

        if ($candidate === '') {
            return $focusword !== '' ? $focusword : $baseform;
        }

        return $candidate;
    }

    protected function ensure_infinitive_form(string $baseform): string {
        $clean = self::strip_articles_and_markers($baseform);
        $clean = preg_replace('/^å\s+/iu', '', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            return $baseform;
        }
        return 'å ' . $clean;
    }

    protected function ensure_substantive_form(string $baseform, string $gender): string {
        $clean = self::strip_articles_and_markers($baseform);
        $article = $this->article_for_gender($gender);
        if ($article === '') {
            return $clean;
        }
        return trim($article . ' ' . $clean);
    }

    protected function ensure_adjective_form(string $baseform): string {
        return self::strip_articles_and_markers($baseform);
    }

    protected function ensure_adverb_form(string $baseform): string {
        return self::strip_articles_and_markers($baseform);
    }

    protected function article_for_gender(string $gender): string {
        switch ($gender) {
            case 'hankjonn':
                return 'en';
            case 'hunkjonn':
                return 'ei';
            case 'intetkjonn':
                return 'et';
            default:
                return 'en';
        }
    }

    /**
     * Check Norwegian text for grammatical errors
     *
     * @param string $text Norwegian text to check
     * @param string $language User's interface language for explanations
     * @param int $userid User ID for API tracking
     * @return array Result with hasErrors, errors, correctedText, explanation
     */
    /**
     * Helper to accumulate token usage from multiple API calls
     */
    private function accumulate_usage(?object $responseUsage, array &$totalUsage): void {
        if (empty($responseUsage)) {
            return;
        }
        $usage = (array) $responseUsage;
        $totalUsage['prompt_tokens'] = ($totalUsage['prompt_tokens'] ?? 0) + ($usage['prompt_tokens'] ?? 0);
        $totalUsage['completion_tokens'] = ($totalUsage['completion_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0);
        $totalUsage['total_tokens'] = ($totalUsage['total_tokens'] ?? 0) + ($usage['total_tokens'] ?? 0);
    }

    /**
     * Remove error items that don't match the corrected sentence.
     *
     * @param array $errors
     * @param string $correctedText
     * @return array
     */
    private function filter_errors_for_corrected_text(array $errors, string $correctedText): array {
        $correctedText = trim($correctedText);
        if ($correctedText === '' || empty($errors)) {
            return $errors;
        }
        $haystack = core_text::strtolower($correctedText);
        $filtered = array_filter($errors, function($err) use ($haystack) {
            $original = trim((string)($err['original'] ?? ''));
            $corrected = trim((string)($err['corrected'] ?? ''));
            if ($original === '' || $corrected === '') {
                return true;
            }
            if ($original === $corrected) {
                return false;
            }
            $needle = core_text::strtolower($corrected);
            if ($needle === '') {
                return false;
            }
            return strpos($haystack, $needle) !== false;
        });
        return array_values($filtered);
    }

    /**
     * Suggest fixed expressions in a sentence (independent LLM analysis).
     *
     * @param string $text
     * @param string $language
     * @param int $userid
     * @param bool $debugAi When true, include debug info (prompts + raw model output)
     * @return array{expressions:array<int,array<string,string>>,usage?:array}
     */
    public function suggest_sentence_expressions(string $text, string $language, int $userid, bool $debugAi = false): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';
        $text = trim($text);
        if ($text === '') {
            return ['expressions' => []];
        }
        $systemprompt = <<<"SYSTEMPROMPT"
You are an expert Norwegian (Bokm\u00e5l) linguist.
Return ONLY valid JSON, no extra text.
All translations and notes must be in $langname.
SYSTEMPROMPT;

         $userprompt = <<<"USERPROMPT"
 Sentence:
 "$text"
 
 Task:
 Identify multiword expressions (2+ words) that are fixed collocations or idiomatic expressions used in this sentence.
 Be strict: do NOT include normal grammar fragments, only expressions that function as a single semantic unit.
 Do NOT output generic fragments like:
 - "med meg"
 - "alt læreren sa"
 - "til været i"
 - "for kaffe"
 If the sentence uses a reflexive pronoun (meg/deg/oss/dere/dem), normalize it to "seg" in the expression form.
 If the sentence uses a particle/preposition with a verb, include it (e.g. "fikk med meg" -> "få med seg").
 Prefer these idiom/collocation types:
 - verb + particle/preposition: "handle om", "stole på"
 - verb + seg + preposition: "venne seg til", "dreie seg om"
 - fixed prepositional/adverbial phrases: "i stedet for", "på grunn av", "i og med", "til og med"
 Avoid producing anything that is simply a topic clause or a literal substring without idiomatic meaning.
 
 Return JSON in this exact schema:
 {
   "expressions": [
     {
      "expression": "expression in Norwegian (grunnform if possible)",
      "translation": "short translation",
      "note": "very short meaning note (optional)"
    }
  ]
}

Rules:
- Return an empty expressions array if none.
- Do NOT include single words.
USERPROMPT;

        $client = $this->openai;
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'expression');

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
        ];

        $modelkey = core_text::strtolower(trim((string)$model));
        if ($modelkey !== '' && $this->requires_default_temperature($modelkey)) {
            unset($payload['temperature']);
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload['reasoning_effort'] = $getReasoningMethod->invoke($client, 'expression');
        }
        if ($modelkey !== '' && !isset($payload['max_tokens']) && !isset($payload['max_completion_tokens'])) {
            if ($this->uses_max_completion_tokens($modelkey)) {
                $payload['max_completion_tokens'] = 260;
            } else {
                $payload['max_tokens'] = 260;
            }
        }

        $method = $reflection->getMethod('request');
        $method->setAccessible(true);
        $recordMethod = $reflection->getMethod('record_usage');
        $recordMethod->setAccessible(true);

        $response = $method->invoke($client, $payload);
        $recordMethod->invoke($client, $userid, $response->usage ?? null);

        $content = trim($response->choices[0]->message->content ?? '');
        $parsed = $this->parse_json_response($content);
        if ($parsed === null) {
            return ['expressions' => []];
        }
        $expressions = is_array($parsed['expressions'] ?? null) ? $parsed['expressions'] : [];
        $result = ['expressions' => $expressions];
        if ($debugAi) {
            $result['_debug'] = [
                'task' => 'expression_suggest',
                'system' => $this->truncate_debug_text($systemprompt),
                'user' => $this->truncate_debug_text($userprompt),
                'payload' => $payload,
                'raw' => $this->truncate_debug_text($content),
            ];
        }
        $modelused = (string)($response->model ?? ($payload['model'] ?? ''));
        if ($modelused !== '') {
            $result['model'] = $modelused;
        }
        if (!empty($payload['reasoning_effort'])) {
            $result['reasoning_effort'] = $payload['reasoning_effort'];
        }
        if (!empty($response->usage)) {
            $result['usage'] = (array)$response->usage;
        }
        return $result;
    }

    /**
     * Remove error items that don't match the original sentence.
     *
     * @param array $errors
     * @param string $originalText
     * @return array
     */
    private function filter_errors_for_original_text(array $errors, string $originalText): array {
        $originalText = trim($originalText);
        if ($originalText === '' || empty($errors)) {
            return $errors;
        }
        $filtered = array_filter($errors, function($err) use ($originalText) {
            $original = (string)($err['original'] ?? '');
            $corrected = (string)($err['corrected'] ?? '');
            if ($original === '' || $corrected === '') {
                return true;
            }
            if (trim($original) === trim($corrected)) {
                return false;
            }
            return strpos($originalText, $original) !== false;
        });
        return array_values($filtered);
    }

    /**
     * Ensure errors actually transform original text into corrected text.
     *
     * @param array $result
     * @param string $originalText
     * @return array
     */
    private function enforce_errors_match_correction(array $result, string $originalText): array {
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if (empty($errors)) {
            return $result;
        }
        $corrected = (string)($result['correctedText'] ?? '');
        if (trim($corrected) === '') {
            return $result;
        }

        $positions = [];
        foreach ($errors as $idx => $err) {
            $orig = (string)($err['original'] ?? '');
            $corr = (string)($err['corrected'] ?? '');
            if ($orig === '' || $corr === '') {
                continue;
            }
            $pos = strpos($originalText, $orig);
            if ($pos === false) {
                return $this->force_no_errors_result($result, $originalText);
            }
            $positions[] = ['idx' => $idx, 'pos' => $pos, 'len' => strlen($orig)];
        }

        usort($positions, function($a, $b) {
            if ($a['pos'] === $b['pos']) {
                return $b['len'] <=> $a['len'];
            }
            return $a['pos'] <=> $b['pos'];
        });

        $working = $originalText;
        foreach ($positions as $item) {
            $err = $errors[$item['idx']] ?? null;
            if (!$err) {
                continue;
            }
            $orig = (string)($err['original'] ?? '');
            $corr = (string)($err['corrected'] ?? '');
            if ($orig === '' || $corr === '') {
                continue;
            }
            $pos = strpos($working, $orig);
            if ($pos === false) {
                return $this->force_no_errors_result($result, $originalText);
            }
            $working = substr_replace($working, $corr, $pos, strlen($orig));
        }

        $normalizedWorking = $this->normalize_whitespace_simple($working);
        $normalizedCorrected = $this->normalize_whitespace_simple($corrected);
        if ($normalizedWorking !== $normalizedCorrected) {
            return $this->force_no_errors_result($result, $originalText);
        }

        return $result;
    }

    /**
     * Normalize whitespace by collapsing runs to single spaces.
     *
     * @param string $text
     * @return string
     */
    private function normalize_whitespace_simple(string $text): string {
        $text = preg_replace('/\\s+/u', ' ', $text);
        return trim($text ?? '');
    }

    public function check_norwegian_text(string $text, string $language, int $userid): array {
        $text = $this->normalize_whitespace_simple($text);
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';
        $debugtiming = [];
        $overallstart = microtime(true);
        $totalUsage = []; // Accumulate token usage across all API calls

        // First request: Find errors
        $systemprompt1 = <<<"SYSTEMPROMPT"
You are a Norwegian (Bokm?l) language assistant.

Task:
- Check ONE sentence.
- Fix only real grammar, spelling, or word order errors.
- Keep the meaning.
- Keep it natural, but do NOT rewrite or paraphrase.

Rules:
- If you are not sure, treat it as correct.
- Use the JSON schema from the user message.
- Explanations must be in $langname.
- Output ONLY valid JSON.
SYSTEMPROMPT;

        $userprompt1 = <<<"USERPROMPT"
You will receive ONE Norwegian sentence written by a learner.

Sentence:
"$text"

Your tasks:

1) Corrected version:
   - Fix only clear grammar, spelling, or word order errors.
   - You may add or remove short function words only if required for correctness.
   - Keep the sentence as close as possible to the original.

2) Alternative version:
   - Only if a more natural phrasing is clearly better.
   - Otherwise use the corrected version.

3) Errors list:
   - For each change, include one short fragment with original and corrected.
   - Do not use full sentence unless everything is wrong.

If the sentence is correct:
- hasErrors = false
- errors = []
- correctedText = original
- alternativeText = original
- explanation = short confirmation in $langname

IMPORTANT:
- Never create an error item if "original" == "corrected".
- Every change in correctedText must have an error item.

JSON FORMAT:
{
  "hasErrors": true/false,
  "errors": [
    {
      "original": "wrong fragment",
      "corrected": "correct fragment",
      "issue": "very short explanation in $langname",
      "category": "spelling | grammar | word order | preposition | article | capitalization | other",
      "certainty": "high | medium | low"
    }
  ],
  "correctedText": "main corrected sentence",
  "alternativeText": "more natural alternative or same as correctedText",
  "explanation": "very short global explanation in $langname (1-2 sentences)"
}

Rules for JSON:
- Use ONLY double quotes for strings.
- Do NOT use trailing commas.
- Booleans must be: true or false (not strings).
- No comments, no extra text, no markdown, no backticks.
Before you output your final answer, do a brief internal self-check to avoid unnecessary changes.
USERPROMPT;
        $client = new openai_client();
        if (!$client->is_enabled()) {
            return [
                'hasErrors' => false,
                'errors' => [],
                'correctedText' => $text,
                'explanation' => '',
            ];
        }

        // Get task-specific model
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'correction');

        // Check if multi-sampling is enabled
        $enableMultisampling = !empty($config->ai_multisampling_enabled);

        error_log('check_norwegian_text: multi-sampling enabled = ' . ($enableMultisampling ? 'YES' : 'NO'));

        if ($enableMultisampling) {
            // === MULTI-SAMPLING STRATEGY ===
            // Generate 3 parallel requests with different temperatures

            error_log('check_norwegian_text: Starting multi-sampling for text: ' . substr($text, 0, 50));

            $requests = [
                ['temperature' => 0.1, 'weight' => 1.5],  // Conservative
                ['temperature' => 0.15, 'weight' => 1.0], // Base
                ['temperature' => 0.2, 'weight' => 0.8],  // Creative
            ];

            $t1 = microtime(true);
            $multisamplingResult = $this->request_parallel_curlmulti($requests, $systemprompt1, $userprompt1, $model, $userid);
            $debugtiming['api_stage1_multisampling'] = microtime(true) - $t1;

            // Extract responses and errors
            $responses = $multisamplingResult['responses'] ?? [];
            $multisamplingErrors = $multisamplingResult['errors'] ?? [];

            // Accumulate usage from multi-sampling (handled internally in request_parallel_curlmulti)
            // But we need to sum it up for the totalUsage
            // Note: usage is recorded per response in request_parallel_curlmulti, but we need to accumulate here
            // For simplicity, since multi-sampling responses don't have usage in them,
            // we'll rely on record_usage being called internally

            // Add debug info about model detection
            $modelkey = core_text::strtolower(trim((string)$model));
            $usesReasoningModel = $this->requires_default_temperature($modelkey);
            $debugtiming['multisampling_model'] = $model;
            $debugtiming['multisampling_detected_as_reasoning'] = $usesReasoningModel;
            $debugtiming['multisampling_responses_count'] = count($responses);

            // Add error details to debug output (visible in browser console)
            if (!empty($multisamplingErrors)) {
                $debugtiming['multisampling_errors'] = $multisamplingErrors;
            }

            if (empty($responses)) {
                error_log('check_norwegian_text: multisampling returned no valid responses, falling back to single request');
                $enableMultisampling = false;
            } else {
                // Merge responses by consensus
                $result1 = $this->merge_responses_by_consensus($responses, $requests, $text);

                if (!empty($result1['errors']) && is_array($result1['errors'])) {
                    $result1['errors'] = array_values(array_filter($result1['errors'], function($err) {
                        if (!isset($err['original'], $err['corrected'])) {
                            return true;
                        }
                        return trim((string)$err['original']) !== trim((string)$err['corrected']);
                    }));
                    $result1['errors'] = $this->filter_errors_for_corrected_text(
                        $result1['errors'],
                        (string)($result1['correctedText'] ?? $text)
                    );
                    $result1['errors'] = $this->filter_errors_for_original_text($result1['errors'], $text);
                    $result1 = $this->enforce_errors_match_correction($result1, $text);
                }
                if (empty($result1['errors'])) {
                    $result1['hasErrors'] = false;
                    $result1['correctedText'] = $text;
                    if (isset($result1['alternativeText'])) {
                        $result1['alternativeText'] = $text;
                    }
                }


            // If no errors found, return immediately
            if (!$result1['hasErrors']) {
                $debugtiming['overall'] = microtime(true) - $overallstart;
                $result1['debugTiming'] = $debugtiming;
                // Include token usage information if available
                if (!empty($totalUsage)) {
                    $result1['usage'] = $totalUsage;
                }
                // Drop any spurious error items where original and corrected are identical
                if (!empty($result1['errors']) && is_array($result1['errors'])) {
                    $result1['errors'] = array_values(array_filter($result1['errors'], function($err) {
                        if (!isset($err['original'], $err['corrected'])) {
                            return true;
                        }
                        return trim((string)$err['original']) !== trim((string)$err['corrected']);
                    }));
                    $result1['errors'] = $this->filter_errors_for_corrected_text(
                        $result1['errors'],
                        (string)($result1['correctedText'] ?? $text)
                    );
                    $result1['errors'] = $this->filter_errors_for_original_text($result1['errors'], $text);
                    $result1 = $this->enforce_errors_match_correction($result1, $text);
                }
                return $result1;
            }

                // Continue to STAGE 2 if enabled
                $enabledoublecheck = !empty($config->ai_doublecheck_correction);
                if (!$enabledoublecheck) {
                    $debugtiming['overall'] = microtime(true) - $overallstart;
                    $result1['debugTiming'] = $debugtiming;
                    // Include token usage information if available
                    if (!empty($totalUsage)) {
                        $result1['usage'] = $totalUsage;
                    }
                    if (!empty($result1['errors']) && is_array($result1['errors'])) {
                        $result1['errors'] = array_values(array_filter($result1['errors'], function($err) {
                            if (!isset($err['original'], $err['corrected'])) {
                                return true;
                            }
                            return trim((string)$err['original']) !== trim((string)$err['corrected']);
                        }));
                    }
                    return $result1;
                }

                // STAGE 2 with multisampling result
                $correctedText = $result1['correctedText'] ?? $text;

                $systemprompt2 = <<<"SYSTEMPROMPT2"
You are a native speaker of Norwegian (Bokmål) and an experienced editor for learner texts.

Task:
- Review an ALREADY CORRECTED learner sentence.
- Find ONLY clear remaining mistakes (if any).
- Optionally suggest a slightly more natural version.

Important:
- Be conservative. If you are not sure something is wrong, treat it as correct.
- Do NOT change the meaning.
- Do NOT introduce unnecessary synonyms or advanced constructions.
- All explanations must be in $langname.
- Output ONLY valid JSON in the exact schema from the user message.
SYSTEMPROMPT2;

                $userprompt2 = <<<"USERPROMPT2"
Original (learner sentence): "$text"
Corrected (first pass): "$correctedText"

Your tasks:

1) Check if the corrected sentence still contains ANY clear errors in Norwegian (Bokmål):
   - grammar
   - spelling
   - agreement
   - word order
   - prepositions
   - obvious wrong word choice

  2) ONLY if you see a clearly more natural and typical way to say the SAME thing:
     - suggest ONE more natural alternative
     - keep the language simple and not overly advanced
   - do NOT change the meaning

JSON:
{
  "additionalErrors": [
    {
      "original": "wrong fragment",
      "corrected": "correct fragment",
      "issue": "very short explanation in $langname"
    }
  ],
  "suggestion": "more natural version OR empty string if you keep the corrected sentence"
}

CRITICAL RULES:
- Leave "additionalErrors" as an empty array if you are not sure about any mistake.
- Leave "suggestion" as an EMPTY string ("") if the corrected sentence is already natural enough.
- Do NOT propose pure synonym changes (e.g. "lett" -> "enkelt") unless the original is clearly unnatural.
- Do NOT output anything outside the JSON object.
USERPROMPT2;

        $payload2 = [
            'model' => $model,
            'temperature' => 0.0,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt2],
                ['role' => 'user', 'content' => $userprompt2],
            ],
        ];

        if ($requiresDefaultTempMethod->invoke($client, $modelkey)) {
            if (!empty($reasoningUsed)) {
                $payload2['reasoning_effort'] = $reasoningUsed;
            } else {
                $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
                $getReasoningMethod->setAccessible(true);
                $payload2['reasoning_effort'] = $getReasoningMethod->invoke($client, 'correction');
                $reasoningUsed = $payload2['reasoning_effort'];
            }
            unset($payload2['temperature']);
        }

                try {
                    $method = $reflection->getMethod('request');
                    $method->setAccessible(true);
                    $recordMethod = $reflection->getMethod('record_usage');
                    $recordMethod->setAccessible(true);

                    $t2 = microtime(true);
                    $response2 = $method->invoke($client, $payload2);
                    $debugtiming['api_stage2'] = microtime(true) - $t2;
                    $recordMethod->invoke($client, $userid, $response2->usage ?? null);

                    // Accumulate usage from STAGE 2
                    $this->accumulate_usage($response2->usage ?? null, $totalUsage);

                    $content2 = trim($response2->choices[0]->message->content ?? '');
                    $result2 = $this->parse_json_response($content2);
                    if ($result2 === null) {
                        error_log('check_norwegian_text: invalid JSON in multisampling stage2 response, retrying once');
                        $retryStart = microtime(true);
                        $response2 = $method->invoke($client, $payload2);
                        $debugtiming['api_stage2_retry'] = microtime(true) - $retryStart;
                        $recordMethod->invoke($client, $userid, $response2->usage ?? null);
                        $this->accumulate_usage($response2->usage ?? null, $totalUsage);
                        $content2 = trim($response2->choices[0]->message->content ?? '');
                        $result2 = $this->parse_json_response($content2);
                    }

                    if ($result2 === null) {
                        error_log('check_norwegian_text: multisampling stage2 response still invalid after retry');
                        $result2 = [];
                    }

                    // Merge STAGE 2 results
                    $finalResult = $result1;

                    if (is_array($result2)) {
                        if (!empty($result2['additionalErrors']) && is_array($result2['additionalErrors'])) {
                            $cleanAdditional = [];

                            foreach ($result2['additionalErrors'] as $err) {
                                if (!isset($err['original']) || !isset($err['corrected'])) {
                                    continue;
                                }

                                // 🔹 Новый фильтр: пропускаем «ошибки», которые ничего не меняют
                                if (trim($err['original']) === trim($err['corrected'])) {
                                    continue;
                                }

                                $cleanAdditional[] = $err;

                                $finalResult['correctedText'] = str_replace(
                                    $err['original'],
                                    $err['corrected'],
                                    $finalResult['correctedText']
                                );
                            }

                            if (!empty($cleanAdditional)) {
                                $finalResult['errors'] = array_merge($finalResult['errors'] ?? [], $cleanAdditional);
                            }
                        }

                        if (!empty($finalResult['errors']) && is_array($finalResult['errors'])) {
                            $seen = [];
                            $deduped = [];

                            foreach ($finalResult['errors'] as $err) {
                                if (!isset($err['original']) || !isset($err['corrected'])) {
                                    $deduped[] = $err;
                                    continue;
                                }
                                $key = $err['original'] . '||' . $err['corrected'];
                                if (isset($seen[$key])) {
                                    continue;
                                }
                                $seen[$key] = true;
                                $deduped[] = $err;
                            }

                            $finalResult['errors'] = $deduped;
                        }

                        if (!empty($result2['suggestion'])) {
                            $finalResult['suggestion'] = $result2['suggestion'];
                        }
                    }

                    $debugtiming['overall'] = microtime(true) - $overallstart;
                    $finalResult['debugTiming'] = $debugtiming;

                    // Include token usage information if available
                    if (!empty($totalUsage)) {
                        $finalResult['usage'] = $totalUsage;
                    }
                    if (!empty($result1['model'] ?? '')) {
                        $finalResult['model'] = $result1['model'];
                    }
                    if (!empty($result1['reasoning_effort'] ?? '')) {
                        $finalResult['reasoning_effort'] = $result1['reasoning_effort'];
                    } else {
                        $finalResult['reasoning_effort'] = $reasoningUsed ?? 'none';
                    }

                    return $finalResult;
                } catch (\Exception $e) {
                    error_log('Error in check_norwegian_text STAGE 2 (multisampling): ' . $e->getMessage());
                    $debugtiming['overall'] = microtime(true) - $overallstart;
                    $result1['debugTiming'] = $debugtiming;
                    return $result1;
                }
            }
        }

        if (!$enableMultisampling) {
            // === ORIGINAL STRATEGY (single request) ===
        // STAGE 1: First API call - Find errors
        $payload1 = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt1],
                ['role' => 'user', 'content' => $userprompt1],
            ],
        ];

        $reasoningUsed = null;
        $modelkey = core_text::strtolower(trim($model));
        $requiresDefaultTempMethod = $reflection->getMethod('requires_default_temperature');
        $requiresDefaultTempMethod->setAccessible(true);
        if ($requiresDefaultTempMethod->invoke($client, $modelkey)) {
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload1['reasoning_effort'] = $getReasoningMethod->invoke($client, 'correction');
            $reasoningUsed = $payload1['reasoning_effort'];
            unset($payload1['temperature']);
        }

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            // First request
            $t1 = microtime(true);
            $response1 = $method->invoke($client, $payload1);
            $debugtiming['api_stage1'] = microtime(true) - $t1;
            $modelused = $response1->model ?? ($payload1['model'] ?? '');
            $reasoningUsed = $payload1['reasoning_effort'] ?? null;

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response1->usage ?? null);

            // Accumulate usage from STAGE 1
            $this->accumulate_usage($response1->usage ?? null, $totalUsage);

            $content1 = trim($response1->choices[0]->message->content ?? '');
            if ($content1 === '') {
                return ['hasErrors' => false, 'errors' => [], 'correctedText' => $text, 'explanation' => ''];
            }

            // Parse first response
            $result1 = $this->parse_json_response($content1);
            if ($result1 === null) {
                error_log('check_norwegian_text: invalid JSON in stage1 response, retrying once');
                $retryStart = microtime(true);
                $responseRetry = $method->invoke($client, $payload1);
                $debugtiming['api_stage1_retry'] = microtime(true) - $retryStart;
                $recordMethod->invoke($client, $userid, $responseRetry->usage ?? null);
                $this->accumulate_usage($responseRetry->usage ?? null, $totalUsage);
                $contentRetry = trim($responseRetry->choices[0]->message->content ?? '');
                $result1 = $this->parse_json_response($contentRetry);
                $modelused = $responseRetry->model ?? $modelused;
            }

            if (!is_array($result1) || !isset($result1['hasErrors'])) {
                return ['hasErrors' => false, 'errors' => [], 'correctedText' => $text, 'explanation' => ''];
            }

            if (!empty($result1['errors']) && is_array($result1['errors'])) {
                $result1['errors'] = array_values(array_filter($result1['errors'], function($err) {
                    if (!isset($err['original'], $err['corrected'])) {
                        return true;
                    }
                    return trim((string)$err['original']) !== trim((string)$err['corrected']);
                }));
                $result1['errors'] = $this->filter_errors_for_corrected_text(
                    $result1['errors'],
                    (string)($result1['correctedText'] ?? $text)
                );
                $result1['errors'] = $this->filter_errors_for_original_text($result1['errors'], $text);
                $result1 = $this->enforce_errors_match_correction($result1, $text);
            }
            if (empty($result1['errors'])) {
                $result1['hasErrors'] = false;
                $result1['correctedText'] = $text;
                if (isset($result1['alternativeText'])) {
                    $result1['alternativeText'] = $text;
                }
            }

            if (!empty($modelused)) {
                $result1['model'] = $modelused;
            }
            $reasoningUsed = $reasoningUsed ?? 'none';
            $result1['reasoning_effort'] = $reasoningUsed;


            // If no errors found, return immediately
            if (!$result1['hasErrors']) {
            $debugtiming['overall'] = microtime(true) - $overallstart;
            $result1['debugTiming'] = $debugtiming;
            // Include token usage information if available
            if (!empty($totalUsage)) {
                $result1['usage'] = $totalUsage;
            }
            // Preserve model/reasoning info if set
            if (!empty($result1['model'])) {
                $result1['model'] = $result1['model'];
            }
            if (!empty($result1['reasoning_effort'])) {
                $result1['reasoning_effort'] = $result1['reasoning_effort'];
            }
            return $result1;
        }

        // Optional STAGE 2: Second API call - Double-check and suggest natural alternative.
        // Controlled by admin setting to keep latency acceptable for slower models.
        $enabledoublecheck = !empty($config->ai_doublecheck_correction);
        if (!$enabledoublecheck) {
            $debugtiming['overall'] = microtime(true) - $overallstart;
            $result1['debugTiming'] = $debugtiming;
            // Include token usage information if available
            if (!empty($totalUsage)) {
                $result1['usage'] = $totalUsage;
            }
            return $result1;
        }

        $correctedText = $result1['correctedText'] ?? $text;

        $systemprompt2 = <<<"SYSTEMPROMPT2"
You are a native speaker of Norwegian (Bokmål) and an experienced editor for learner texts.

Task:
- Review an ALREADY CORRECTED learner sentence.
- Find ONLY clear remaining mistakes (if any).
- Optionally suggest a slightly more natural version.

Important:
- Be conservative. If you are not sure something is wrong, treat it as correct.
- Do NOT change the meaning.
- Do NOT introduce unnecessary synonyms or advanced constructions.
- All explanations must be in $langname.
- Output ONLY valid JSON in the exact schema from the user message.
SYSTEMPROMPT2;

        $userprompt2 = <<<"USERPROMPT2"
Original (learner sentence): "$text"
Corrected (first pass): "$correctedText"

Your tasks:

1) Check if the corrected sentence still contains ANY clear errors in Norwegian (Bokmål):
   - grammar
   - spelling
   - agreement
   - word order
   - prepositions
   - obvious wrong word choice

  2) ONLY if you see a clearly more natural and typical way to say the SAME thing:
     - suggest ONE more natural alternative
     - keep the language simple and not overly advanced
   - do NOT change the meaning

JSON:
{
  "additionalErrors": [
    {
      "original": "wrong fragment",
      "corrected": "correct fragment",
      "issue": "very short explanation in $langname"
    }
  ],
  "suggestion": "more natural version OR empty string if you keep the corrected sentence"
}

CRITICAL RULES:
- Leave "additionalErrors" as an empty array if you are not sure about any mistake.
- Leave "suggestion" as an EMPTY string ("") if the corrected sentence is already natural enough.
- Do NOT propose pure synonym changes (e.g. "lett" -> "enkelt") unless the original is clearly unnatural.
- Do NOT output anything outside the JSON object.
USERPROMPT2;

            $payload2 = [
                'model' => $model,
                'temperature' => 0.0,
                'messages' => [
                    ['role' => 'system', 'content' => $systemprompt2],
                    ['role' => 'user', 'content' => $userprompt2],
                ],
            ];

            // Second request
            $t2 = microtime(true);
            $response2 = $method->invoke($client, $payload2);
            $debugtiming['api_stage2'] = microtime(true) - $t2;
            $recordMethod->invoke($client, $userid, $response2->usage ?? null);

            // Accumulate usage from STAGE 2
            $this->accumulate_usage($response2->usage ?? null, $totalUsage);

            $content2 = trim($response2->choices[0]->message->content ?? '');
            $result2 = $this->parse_json_response($content2);
            if ($result2 === null) {
                error_log('check_norwegian_text: invalid JSON in stage2 response, retrying once');
                $retryStart = microtime(true);
                $response2 = $method->invoke($client, $payload2);
                $debugtiming['api_stage2_retry'] = microtime(true) - $retryStart;
                $recordMethod->invoke($client, $userid, $response2->usage ?? null);
                $this->accumulate_usage($response2->usage ?? null, $totalUsage);
                $content2 = trim($response2->choices[0]->message->content ?? '');
                $result2 = $this->parse_json_response($content2);
            }

            if ($result2 === null) {
                error_log('check_norwegian_text: stage2 response still invalid after retry');
                $result2 = [];
            }

            // Merge results
            $finalResult = $result1;

            if (is_array($result2)) {
                // Add additional errors if found
                if (!empty($result2['additionalErrors']) && is_array($result2['additionalErrors'])) {
                    $cleanAdditional = [];

                    foreach ($result2['additionalErrors'] as $err) {
                        if (!isset($err['original']) || !isset($err['corrected'])) {
                            continue;
                        }

                        // 🔹 Новый фильтр
                        if (trim($err['original']) === trim($err['corrected'])) {
                            continue;
                        }

                        $cleanAdditional[] = $err;

                        $finalResult['correctedText'] = str_replace(
                            $err['original'],
                            $err['corrected'],
                            $finalResult['correctedText']
                        );
                    }

                    if (!empty($cleanAdditional)) {
                        $finalResult['errors'] = array_merge($finalResult['errors'] ?? [], $cleanAdditional);
                    }
                }

                if (!empty($finalResult['errors']) && is_array($finalResult['errors'])) {
                    $seen = [];
                    $deduped = [];

                    foreach ($finalResult['errors'] as $err) {
                        if (!isset($err['original']) || !isset($err['corrected'])) {
                            $deduped[] = $err;
                            continue;
                        }
                        $key = $err['original'] . '||' . $err['corrected'];
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $deduped[] = $err;
                    }

                    $finalResult['errors'] = $deduped;
                }

                // Add suggestion if present
                if (!empty($result2['suggestion'])) {
                    $finalResult['suggestion'] = $result2['suggestion'];
                }
            }

            if (!empty($finalResult['errors']) && is_array($finalResult['errors'])) {
                $finalResult['errors'] = array_values(array_filter($finalResult['errors'], function($err) {
                    if (!isset($err['original'], $err['corrected'])) {
                        return true;
                    }
                    return trim((string)$err['original']) !== trim((string)$err['corrected']);
                }));
                $finalResult['errors'] = $this->filter_errors_for_corrected_text(
                    $finalResult['errors'],
                    (string)($finalResult['correctedText'] ?? $text)
                );
                $finalResult['errors'] = $this->filter_errors_for_original_text($finalResult['errors'], $text);
                $finalResult = $this->enforce_errors_match_correction($finalResult, $text);
            }
            if (empty($finalResult['errors'])) {
                $finalResult['hasErrors'] = false;
                $finalResult['correctedText'] = $text;
                if (isset($finalResult['alternativeText'])) {
                    $finalResult['alternativeText'] = $text;
                }
            } else {
                $finalResult['hasErrors'] = true;
            }

            $debugtiming['overall'] = microtime(true) - $overallstart;
            $finalResult['debugTiming'] = $debugtiming;

            return $finalResult;
        } catch (\Exception $e) {
            error_log('Error in check_norwegian_text: ' . $e->getMessage());
            return [
                'hasErrors' => false,
                'errors' => [],
                'correctedText' => $text,
                'explanation' => '',
                'usage' => $totalUsage,
            ];
        }
    }

    }

    /**
     * Answer AI question about text correction
     *
     * @param string $prompt Full user prompt with context
     * @param string $language User's interface language
     * @param int $userid User ID for API tracking
     * @return array Result with answer
     */
    public function answer_ai_question(string $prompt, string $language, int $userid): array {
        $client = new openai_client();
        if (!$client->is_enabled()) {
            return ['answer' => ''];
        }

        $systemprompt = "You are an experienced Norwegian language teacher. Answer student questions about grammar and corrections clearly and helpfully.";

        // Get task-specific model for question answering
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'question');

        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Add reasoning_effort for models that support it
        $modelkey = core_text::strtolower(trim($model));
        $requiresDefaultTempMethod = $reflection->getMethod('requires_default_temperature');
        $requiresDefaultTempMethod->setAccessible(true);
        if ($requiresDefaultTempMethod->invoke($client, $modelkey)) {
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload['reasoning_effort'] = $getReasoningMethod->invoke($client, 'question');
            unset($payload['temperature']); // Remove temperature for reasoning models
        }

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            $response = $method->invoke($client, $payload);

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response->usage ?? null);

            $answer = trim($response->choices[0]->message->content ?? '');

            $result = [
                'answer' => $answer,
                'model' => $response->model ?? ($payload['model'] ?? ''),
            ];

            if (!empty($payload['reasoning_effort'])) {
                $result['reasoning_effort'] = $payload['reasoning_effort'];
            }

            // Include token usage information if available
            if (isset($response->usage)) {
                $result['usage'] = (array) $response->usage;
            }

            return $result;
        } catch (\Exception $e) {
            error_log('Error in answer_ai_question: ' . $e->getMessage());
            return ['answer' => ''];
        }
    }

    /**
     * Answer AI question with full conversation context (messages array)
     *
     * @param array $messages Array of message objects with role and content
     * @param string $language User's interface language
     * @param int $userid User ID for API tracking
     * @return array Result with answer
     */
    public function answer_ai_question_with_context(array $messages, string $language, int $userid): array {
        $client = new openai_client();
        if (!$client->is_enabled()) {
            return ['answer' => ''];
        }

        // Messages array already includes system prompt from frontend
        // Just pass through to OpenAI

        // Get task-specific model for question answering
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'question');

        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => $messages,
        ];

        // Add reasoning_effort for models that support it
        $modelkey = core_text::strtolower(trim($model));
        $requiresDefaultTempMethod = $reflection->getMethod('requires_default_temperature');
        $requiresDefaultTempMethod->setAccessible(true);
        if ($requiresDefaultTempMethod->invoke($client, $modelkey)) {
            $getReasoningMethod = $reflection->getMethod('get_reasoning_effort_for_task');
            $getReasoningMethod->setAccessible(true);
            $payload['reasoning_effort'] = $getReasoningMethod->invoke($client, 'question');
            unset($payload['temperature']); // Remove temperature for reasoning models
        }

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            $response = $method->invoke($client, $payload);

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response->usage ?? null);

            $answer = trim($response->choices[0]->message->content ?? '');

            $result = [
                'answer' => $answer,
                'model' => $response->model ?? ($payload['model'] ?? ''),
            ];

            if (!empty($payload['reasoning_effort'])) {
                $result['reasoning_effort'] = $payload['reasoning_effort'];
            }

            // Include token usage information if available
            if (isset($response->usage)) {
                $result['usage'] = (array) $response->usage;
            }

            return $result;
        } catch (\Exception $e) {
            error_log('Error in answer_ai_question_with_context: ' . $e->getMessage());
            return ['answer' => ''];
        }
    }

    /**
     * Detect grammatical constructions and multi-word expressions in Norwegian sentence
     *
     * @param string $text Full Norwegian sentence
     * @param string $focusword Word that user clicked on
     * @param string $language User's interface language for translations
     * @param int $userid User ID for API tracking
     * @return array Result with constructions and focusConstruction
     */
    public function detect_constructions(string $text, string $focusword, string $language, int $userid): array {
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';

        $systemprompt = "You are an expert Norwegian linguist. Analyze sentences and identify multi-word expressions. Respond ONLY with valid JSON.";

        $userprompt = "Analyze this Norwegian sentence and identify ALL multi-word expressions and grammatical constructions:

Sentence: \"$text\"
Focus word (clicked by user): \"$focusword\"

Identify these types of constructions:
1. **Verb + preposition** combinations (e.g., \"holde på med\", \"få til\", \"se på\")
2. **være + adjective/adverb** expressions (e.g., \"være klar over\", \"være glad i\")
3. **Reflexive verbs** (e.g., \"skamme seg\", \"glede seg til\", \"skaffe seg\")
4. **Other fixed expressions** and collocations

For EACH construction found:
- List exact tokens from the sentence (preserving case and form)
- Provide their indices in the sentence (0-based, counting only word tokens, not punctuation)
- Give normalized/infinitive form (grunnform): verbs with \"å\", nouns with article, etc.
- Translate to $langname
- Classify type: \"expression\", \"reflexive\", \"verb_prep\", or \"other\"

For the focus word \"$focusword\":
- Determine if it's part of any construction
- If yes, return that construction details in focusConstruction
- Include baseForm (the clicked word in base form) and POS (part of speech)

IMPORTANT: Respond ONLY with valid JSON in this exact format:
{
  \"constructions\": [
    {
      \"tokens\": [\"er\", \"klar\", \"over\"],
      \"tokenIndices\": [1, 2, 3],
      \"normalized\": \"være klar over\",
      \"translation\": \"translation in $langname\",
      \"type\": \"expression\"
    }
  ],
  \"focusConstruction\": {
    \"tokens\": [\"er\", \"klar\", \"over\"],
    \"tokenIndices\": [1, 2, 3],
    \"normalized\": \"være klar over\",
    \"translation\": \"translation in $langname\",
    \"baseForm\": \"klar\",
    \"pos\": \"adjective\"
  }
}

If focus word is NOT part of any construction, set focusConstruction to null.
If NO constructions found, return empty constructions array.";

        $client = new openai_client();
        if (!$client->is_enabled()) {
            return [
                'constructions' => [],
                'focusConstruction' => null,
            ];
        }

        // Get task-specific model for construction detection
        $reflection = new \ReflectionClass($client);
        $getModelMethod = $reflection->getMethod('get_model_for_task');
        $getModelMethod->setAccessible(true);
        $model = $getModelMethod->invoke($client, 'construction');

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemprompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userprompt,
                ],
            ],
        ];

        try {
            // Call protected request method via reflection
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);
            $response = $method->invoke($client, $payload);

            // Record usage
            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response->usage ?? null);

            $content = trim($response->choices[0]->message->content ?? '');
            if ($content === '') {
                return [
                    'constructions' => [],
                    'focusConstruction' => null,
                ];
            }

            // Extract JSON from response
            $json = null;
            if (preg_match('~\{.*\}~s', $content, $m)) {
                $json = $m[0];
            }
            $result = $json ? json_decode($json, true) : json_decode($content, true);

            if (!is_array($result)) {
                return [
                    'constructions' => [],
                    'focusConstruction' => null,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            error_log('Error in detect_constructions: ' . $e->getMessage());
            return [
                'constructions' => [],
                'focusConstruction' => null,
            ];
        }
    }

    /**
     * Execute multiple parallel requests with different temperatures (fallback: sequential)
     *
     * @param array $requests Array of ['temperature' => float, 'weight' => float]
     * @param string $systemprompt System prompt
     * @param string $userprompt User prompt
     * @param string $model Model to use
     * @param int $userid User ID for usage tracking
     * @return array Array of parsed responses
     */
    protected function request_parallel_fallback(array $requests, string $systemprompt, string $userprompt, string $model, int $userid): array {
        $client = new openai_client();
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('request');
        $method->setAccessible(true);
        $recordMethod = $reflection->getMethod('record_usage');
        $recordMethod->setAccessible(true);

        $responses = [];
        foreach ($requests as $idx => $req) {
            $payload = [
                'model' => $model,
                'temperature' => $req['temperature'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemprompt],
                    ['role' => 'user', 'content' => $userprompt],
                ],
            ];

            try {
                $response = $method->invoke($client, $payload);
                $recordMethod->invoke($client, $userid, $response->usage ?? null);

                $content = trim($response->choices[0]->message->content ?? '');
                if ($content === '') {
                    continue;
                }

                // Parse JSON from content
                $json = null;
                if (preg_match('~\{.*\}~s', $content, $m)) {
                    $json = $m[0];
                }
                $parsed = $json ? json_decode($json, true) : json_decode($content, true);

                if (is_array($parsed) && isset($parsed['hasErrors'])) {
                    $responses[$idx] = $parsed;
                }
            } catch (\Exception $e) {
                error_log('Error in request_parallel_fallback (request ' . $idx . '): ' . $e->getMessage());
                // Continue with other requests even if one fails
            }
        }

        return $responses;
    }

    /**
     * Execute multiple parallel requests with curl_multi (TRUE parallelism)
     *
     * @param array $requests Array of ['temperature' => float, 'weight' => float]
     * @param string $systemprompt System prompt
     * @param string $userprompt User prompt
     * @param string $model Model to use
     * @param int $userid User ID for usage tracking
     * @return array Array with 'responses' (parsed responses) and 'errors' (error details for debugging)
     */
    protected function request_parallel_curlmulti(array $requests, string $systemprompt, string $userprompt, string $model, int $userid): array {
        $client = new openai_client();

        // Check if client is enabled
        if (!$client->is_enabled()) {
            error_log('request_parallel_curlmulti: Client not enabled');
            return [];
        }

        $reflection = new \ReflectionClass($client);

        // Get API key and base URL directly from config (same as openai_client constructor)
        $config = get_config('mod_flashcards');
        $apikey = trim($config->openai_apikey ?? '') ?: getenv('FLASHCARDS_OPENAI_KEY') ?: null;
        $baseurl = trim($config->openai_baseurl ?? '');
        if ($baseurl === '') {
            $baseurl = 'https://api.openai.com/v1/chat/completions';
        }

        if (empty($apikey)) {
            error_log('request_parallel_curlmulti: No API key available');
            return [];
        }

        // Initialize curl_multi handle
        $mh = curl_multi_init();
        $handles = [];
        $payloads = [];
        $errors = []; // Collect error details for debugging

        // Check if model supports custom temperature
        $modelkey = core_text::strtolower(trim((string)$model));
        $useDefaultTemp = ($modelkey !== '' && $this->requires_default_temperature($modelkey));
        $timeout = $useDefaultTemp ? 90 : 30; // Reasoning models are MUCH slower

        error_log('request_parallel_curlmulti: Starting with ' . count($requests) . ' requests');
        error_log('request_parallel_curlmulti: API URL = ' . $baseurl);
        error_log('request_parallel_curlmulti: Model ' . $model . ' requires default temperature = ' .
                  ($useDefaultTemp ? 'YES (will use reasoning_effort)' : 'NO (will use temperature)'));
        error_log('request_parallel_curlmulti: Timeout set to ' . $timeout . ' seconds');

        // Create all curl handles
        foreach ($requests as $idx => $req) {
            // Build base payload
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemprompt],
                    ['role' => 'user', 'content' => $userprompt],
                ],
            ];

            // Add temperature OR reasoning_effort depending on model type
            if ($useDefaultTemp) {
                // Reasoning models (gpt-5-mini, gpt-5-nano, o1-mini) don't support temperature
                // Use reasoning_effort instead
                $payload['reasoning_effort'] = 'medium';
                error_log('request_parallel_curlmulti [req ' . $idx . ']: reasoning_effort=medium (reasoning model)');
            } else {
                // Regular models (gpt-4o-mini etc.) support temperature
                $payload['temperature'] = $req['temperature'];
                error_log('request_parallel_curlmulti [req ' . $idx . ']: temperature=' . $req['temperature']);
            }

            $payloads[$idx] = $payload;

            $ch = curl_init($baseurl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apikey,
            ]);
            // Reasoning models are MUCH slower - need longer timeout
            $timeout = $useDefaultTemp ? 90 : 30;
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        // Execute all requests in parallel
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                // Wait for activity on any curl connection
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);

        error_log('request_parallel_curlmulti: All requests completed, status = ' . $status);

        // Collect responses
        $responses = [];
        $recordMethod = $reflection->getMethod('record_usage');
        $recordMethod->setAccessible(true);

        foreach ($handles as $idx => $ch) {
            try {
                $response = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);

                // Check HTTP status
                if (!empty($info['http_code']) && $info['http_code'] >= 400) {
                    $errorDetail = [
                        'request_index' => $idx,
                        'http_code' => $info['http_code'],
                        'error_body' => substr($response, 0, 500), // First 500 chars
                        'payload' => $payloads[$idx],
                    ];
                    $errors[] = $errorDetail;

                    error_log('Error in request_parallel_curlmulti (request ' . $idx . '): HTTP ' . $info['http_code']);
                    error_log('Error response body: ' . substr($response, 0, 500));
                    error_log('Request payload was: ' . json_encode($payloads[$idx], JSON_UNESCAPED_UNICODE));
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                if ($response === false || empty($response)) {
                    $errorDetail = [
                        'request_index' => $idx,
                        'error_type' => 'empty_response',
                        'curl_error' => curl_error($ch),
                        'payload' => $payloads[$idx],
                    ];
                    $errors[] = $errorDetail;

                    error_log('Error in request_parallel_curlmulti (request ' . $idx . '): Empty response');
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                // Parse JSON response
                $json = json_decode($response);
                if (!$json) {
                    $errorDetail = [
                        'request_index' => $idx,
                        'error_type' => 'invalid_json',
                        'response_preview' => substr($response, 0, 200),
                        'payload' => $payloads[$idx],
                    ];
                    $errors[] = $errorDetail;

                    error_log('Error in request_parallel_curlmulti (request ' . $idx . '): Invalid JSON');
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                // Record usage
                if (isset($json->usage)) {
                    $recordMethod->invoke($client, $userid, $json->usage);
                }

                // Extract content
                $content = trim($json->choices[0]->message->content ?? '');
                if ($content === '') {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                // Parse JSON from content
                $jsonMatch = null;
                if (preg_match('~\{.*\}~s', $content, $m)) {
                    $jsonMatch = $m[0];
                }
                $parsed = $jsonMatch ? json_decode($jsonMatch, true) : json_decode($content, true);

                if (is_array($parsed) && isset($parsed['hasErrors'])) {
                    $responses[$idx] = $parsed;
                }
            } catch (\Exception $e) {
                $errorDetail = [
                    'request_index' => $idx,
                    'error_type' => 'exception',
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'payload' => $payloads[$idx] ?? null,
                ];
                $errors[] = $errorDetail;

                error_log('Error in request_parallel_curlmulti (request ' . $idx . '): ' . $e->getMessage());
            } finally {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($mh);

        error_log('request_parallel_curlmulti: Returning ' . count($responses) . ' valid responses and ' . count($errors) . ' errors');

        return [
            'responses' => $responses,
            'errors' => $errors,
        ];
    }

    /**
     * Check if model requires default temperature (no custom temperature support)
     *
     * Some models like gpt-5-mini, gpt-5-nano, o1-mini don't support the temperature
     * parameter and instead use reasoning_effort.
     *
     * @param string $modelkey Lowercase model name
     * @return bool True if model doesn't support temperature parameter
     */
    private function requires_default_temperature(string $modelkey): bool {
        // gpt-5-mini / gpt-5-nano and their dated variants (with or without "gpt-" prefix)
        // Check for "5-mini" or "5-nano" anywhere in the string to catch both "gpt-5-mini" and "5-mini"
        if (strpos($modelkey, '5-mini') !== false) {
            return true;
        }
        if (strpos($modelkey, '5-nano') !== false) {
            return true;
        }
        // o1-mini and o1-preview also don't support temperature
        if (strpos($modelkey, 'o1-mini') !== false || strpos($modelkey, 'o1-preview') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Check if model uses max_completion_tokens instead of max_tokens.
     *
     * @param string $modelkey Lowercase model name
     */
    private function uses_max_completion_tokens(string $modelkey): bool {
        return strpos($modelkey, '5-mini') !== false ||
               strpos($modelkey, '5-nano') !== false ||
               strpos($modelkey, 'o1-') !== false;
    }

    /**
     * Parse JSON output from the AI response, allowing for embedded JSON blocks.
     *
     * @param string $content Raw response text
     * @return array|null Parsed array or null on failure
     */
    protected function parse_json_response(string $content): ?array {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        $jsonCandidate = null;
        if (preg_match('~\{.*\}~s', $trimmed, $matches)) {
            $jsonCandidate = $matches[0];
        }

        $payload = $jsonCandidate ?? $trimmed;
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Merge multiple responses by consensus (weighted voting)
     *
     * @param array $responses Array of parsed API responses
     * @param array $requests Array of request configs with weights
     * @param string $originalText Original text for correction reconstruction
     * @return array Merged result with consensus errors
     */
    protected function merge_responses_by_consensus(array $responses, array $requests, string $originalText): array {
        if (empty($responses)) {
            return [
                'hasErrors' => false,
                'errors' => [],
                'correctedText' => $originalText,
                'explanation' => '',
            ];
        }

        // If only one response, return it as-is
        if (count($responses) === 1) {
            return reset($responses);
        }

        // DEBUG: Log raw responses before consensus
        $rawResponsesDebug = [];
        foreach ($responses as $idx => $response) {
            $rawResponsesDebug["response_$idx"] = [
                'hasErrors' => $response['hasErrors'] ?? false,
                'errors' => $response['errors'] ?? [],
            ];
        }

        // Collect all errors from all responses
        $allErrors = [];
        foreach ($responses as $idx => $response) {
            $weight = $requests[$idx]['weight'] ?? 1.0;

            if (empty($response['errors']) || !is_array($response['errors'])) {
                continue;
            }

            foreach ($response['errors'] as $error) {
                if (!isset($error['original']) || !isset($error['corrected'])) {
                    continue;
                }

                // 🔹 Новый фильтр: игнорируем «ошибки», которые ничего не меняют
                if (trim($error['original']) === trim($error['corrected'])) {
                    continue;
                }

                $key = $error['original']; // Use original word as key

                if (!isset($allErrors[$key])) {
                    $allErrors[$key] = [
                        'votes' => 0,
                        'weightedVotes' => 0,
                        'corrections' => [],
                        'issues' => [],
                    ];
                }

                $allErrors[$key]['votes']++;
                $allErrors[$key]['weightedVotes'] += $weight;
                $allErrors[$key]['corrections'][] = $error['corrected'];

                if (!empty($error['issue'])) {
                    $allErrors[$key]['issues'][] = $error['issue'];
                }
            }
        }

        // Filter errors by consensus (minimum 2 votes)
        $confirmedErrors = [];
        foreach ($allErrors as $original => $data) {
            if ($data['votes'] >= 2) {
                // Choose most common correction
                $correctionCounts = array_count_values($data['corrections']);
                arsort($correctionCounts);
                $mostCommonCorrection = key($correctionCounts);

                // Choose most common issue explanation
                $issue = '';
                if (!empty($data['issues'])) {
                    $issueCounts = array_count_values($data['issues']);
                    arsort($issueCounts);
                    $issue = key($issueCounts);
                }

                $confirmedErrors[] = [
                    'original' => $original,
                    'corrected' => $mostCommonCorrection,
                    'issue' => $issue,
                    'confidence' => $data['weightedVotes'], // For debugging
                ];
            }
        }

        // Use base response (temperature 0.3, index 1) as foundation
        $baseResponse = $responses[1] ?? $responses[array_key_first($responses)];

        // Replace errors with confirmed ones
        $baseResponse['errors'] = $confirmedErrors;

        // Reconstruct correctedText based on confirmed errors
        $correctedText = $originalText;
        foreach ($confirmedErrors as $err) {
            $correctedText = str_replace($err['original'], $err['corrected'], $correctedText);
        }
        $baseResponse['correctedText'] = $correctedText;

        // Update hasErrors flag
        $baseResponse['hasErrors'] = !empty($confirmedErrors);

        // Add consensus metadata for debugging
        $baseResponse['consensusInfo'] = [
            'totalResponses' => count($responses),
            'confirmedErrors' => count($confirmedErrors),
            'discardedErrors' => count($allErrors) - count($confirmedErrors),
            'rawResponses' => $rawResponsesDebug, // DEBUG: Show what each model returned
            'allErrorsGrouped' => $allErrors, // DEBUG: Show how errors were grouped and voted
        ];

        return $baseResponse;
    }

    protected function select_front_audio_text(string $fronttext, array $examples): string {
        $base = trim($fronttext);
        if ($base === '') {
            return '';
        }
        foreach ($examples as $example) {
            $candidate = '';
            if (is_string($example)) {
                $parts = explode('|', $example, 2);
                $candidate = trim($parts[0] ?? '');
            } elseif (is_array($example)) {
                $candidate = trim((string)($example['text'] ?? $example['no'] ?? ''));
            }
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return $base;
    }
}
