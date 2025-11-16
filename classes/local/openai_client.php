<?php

namespace mod_flashcards\local;

use coding_exception;
use core_text;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * OpenAI chat-completions client that produces structured vocabulary explanations.
 */
class openai_client {
    private const POS_OPTIONS = [
        'substantiv',
        'adjektiv',
        'pronomen',
        'determinativ',
        'verb',
        'adverb',
        'preposisjon',
        'konjunksjon',
        'subjunksjon',
        'interjeksjon',
        'phrase',
        'other',
    ];

    private const GENDER_OPTIONS = [
        'hankjonn',
        'hunkjonn',
        'intetkjonn',
    ];

    private const DEFAULT_LEVEL = 'A2';

    /** @var string|null */
    protected $apikey;
    /** @var string */
    protected $baseurl;
    /** @var string */
    protected $model;
    /** @var bool */
    protected $enabled;

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->apikey = trim($config->openai_apikey ?? '') ?: getenv('FLASHCARDS_OPENAI_KEY') ?: null;
        $this->baseurl = trim($config->openai_baseurl ?? '');
        if ($this->baseurl === '') {
            $this->baseurl = 'https://api.openai.com/v1/chat/completions';
        }
        $this->model = trim($config->openai_model ?? '');
        if ($this->model === '') {
            $this->model = 'gpt-4o-mini';
        }
        $this->enabled = !empty($config->ai_focus_enabled) && !empty($this->apikey);
    }

    /**
     * Whether client is configured for usage.
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Ask ChatGPT to extract and explain the clicked word/expression.
     *
     * @param string $fronttext
     * @param string $clickedword
     * @param array $options ['language' => string, 'level' => string]
     * @return array
     * @throws moodle_exception
     */
    public function detect_focus_data(string $fronttext, string $clickedword, array $options = []): array {
        if (!$this->is_enabled()) {
            throw new coding_exception('openai client is not configured');
        }

        $fronttext = trim($fronttext);
        $clickedword = trim($clickedword);
        if ($fronttext === '' || $clickedword === '') {
            throw new coding_exception('Missing text for focus detection');
        }

        $uilang = $this->sanitize_language($options['language'] ?? '');
        $level = $this->sanitize_level($options['level'] ?? '');

        // Map language codes to full language names for the prompt
        $targetlang = $this->language_name($uilang);
        $translabel = 'TR-' . strtoupper($uilang);

        $systemprompt = <<<PROMPT
ROLE: Norwegian tutor for {$targetlang}-speaking learners.

RULES:
- Stay with the exact lexeme the learner tapped. The WORD/base form must keep the clicked lemma inside it (optionally within a longer idiom) and may never jump to a different verb/noun such as the nearest copula.
- Verbs: output infinitive with leading "�?"; nouns: indefinite singular; particles/pronouns/determiners: keep the clicked lemma itself (no substitutions).
- No inflections (API provides them).
- Nouns: mark countability and gender via article:
  - Countable: article w/o parentheses (en/ei/et).
  - Uncountable: article in parentheses (e.g., (et) vann).
- Use the sentence context to determine the part of speech. If the clicked word acts as an adverbial destination (e.g., "dra hjem"), mark it as "adverb" even if the lemma can be a noun.
- If an adjective is used adverbially (e.g., "spise sunt", "løpe fort"), classify it as "adverb".
- When the expression contains 2+ lexical words (after removing leading "å" or articles), mark POS as "phrase".
- If the clicked form belongs to a verb + particle/preposition expression (e.g., "gå opp", "se etter", "holde ut"), return the entire fixed expression as the WORD/base form.
- If the clicked verb needs an adjective/noun complement to form the meaning (e.g., "å ha rett", "å ta feil", "å gjøre ferdig"), include that complement so the WORD captures the full idiom and mark POS as "phrase".
- Recognize Norwegian correlative patterns with variable slots:
  * "jo ..., jo ..." (the more..., the more...) - use ellipsis notation (three dots)
  * "både ... og ..." (both ... and ...)
  * "enten ... eller ..." (either ... or ...)
  * "verken ... eller ..." / "hverken ... eller ..." (neither ... nor ...)
- IMPORTANT: Only identify the pattern if the clicked word is part of the pattern keywords themselves (e.g., clicking "jo" → "jo ..., jo ..."). If the user clicked a word within the pattern (e.g., "bedre" in "jo bedre"), return just that word, NOT the pattern.
- Output every verb or verb phrase in infinitive with a leading "å" (unless an article is required instead); never leave it in past/participle form.
- Prefer the idiomatic/contextual meaning of the expression over literal tense descriptions.
- IMPORTANT: Carefully check the ENTIRE learner sentence for ALL types of errors (spelling, grammar, word choice, verb forms, prepositions, articles, word order). When you find ANY error, provide a corrected version with ALL mistakes fixed, not just one. List each specific error briefly after the correction IN {$targetlang} LANGUAGE.
- If POS = substantiv, also return the contextual gender (hankjønn/hunkjønn/intetkjønn). Use "-" for all other POS.
- Structure output with exact labels below; keep it brief and level-appropriate.

FORMAT:
WORD: <base form with article or "å">
BASE-FORM: <lemma without any articles or "å" prefix - just the bare word>
POS: <one of substantiv|adjektiv|pronomen|determinativ|verb|adverb|preposisjon|konjunksjon|subjunksjon|interjeksjon|phrase|other>
GENDER: <hankjønn|hunkjønn|intetkjønn|-> (nouns only)
EXPL-NO: <simple Norwegian explanation>
{$translabel}: <{$targetlang} translation of meaning>
COLL: <0-5 common Norwegian collocations (no translations), semicolon-separated>
EX1: <NO sentence using a top collocation> | <{$targetlang}>
EX2: <NO> | <{$targetlang}>
EX3: <NO> | <{$targetlang}>
FORMS: <other useful lexical forms (verb/noun/adj variants) with tiny NO gloss + {$targetlang}>
CORR: <fully corrected sentence> — <list each error in {$targetlang}: "bruk"→"bruke" (explanation in {$targetlang}); "tit"→"tid" (explanation in {$targetlang}); etc.> (include this line whenever you spot ANY error in the sentence, not just when 90% sure)

NOTES:
- Focus on everyday, high-frequency uses.
- One core sense for A1; add secondary sense only if clearly frequent/relevant (B1).
- Avoid grammar lectures; show usage via collocations/examples.
- Include collocations only when they are common, level-appropriate Norwegian combinations. If none apply, leave COLL blank.
- {$targetlang} translations must sound natural; whenever a literal rendering would feel awkward, rewrite it naturally and add parentheses with a short explanation. Apply this rule only to EX sentence translations.
- Skip COLL entirely if you are unsure about natural usage; never invent awkward translations.
- Treat multi-word expressions (after removing leading "å" or indefinite articles) as POS "phrase".
- When the clicked form is part of an idiomatic verb + particle/preposition, keep the whole expression together (e.g., "å gå opp") and explain that idiomatic sense (e.g., "å forstå noe").
- Include the required adjective/noun complement when an expression depends on it (e.g., output "å ha rett" instead of "å ha").
- ALWAYS output CORR line whenever the learner sentence contains ANY error (spelling, grammar, verb forms, prepositions, articles, word order, word choice). List ALL errors found, not just the first one. Format: "<fully corrected sentence> — <error1: wrong→correct (reason in {$targetlang}); error2: wrong→correct (reason in {$targetlang}); etc.>".
- BASE-FORM field must contain ONLY the lemma without articles (en/ei/et) and without "å" prefix. For example: if WORD is "en helg", BASE-FORM should be "helg"; if WORD is "å gjøre", BASE-FORM should be "gjøre".
PROMPT;

        $userprompt = implode("\n", [
            'sentence: ' . $this->trim_prompt_text($fronttext),
            'clicked_word: ' . $this->trim_prompt_text($clickedword, 60),
            "level: {$level}",
            "ui_lang: {$uilang}",
            'instructions: Identify the expression in context that includes the clicked word. Decide POS by how the expression functions in this sentence (e.g., motion + destination => adverb). '
                . 'When POS is substantiv, choose the gender that matches the specific meaning in context and output hankjønn/hunkjønn/intetkjønn. '
                . 'If the clicked form belongs to a verb or verb phrase, output it in infinitive with a leading "å" and include any attached particles/prepositions or required complements (adjectives/nouns) that change the meaning. '
                . 'Prefer the idiomatic/contextual sense over literal tense explanations. '
                . 'IMPORTANT: Output BASE-FORM field with ONLY the bare lemma (without articles en/ei/et and without "å" prefix). For example: if WORD is "en helg", BASE-FORM should be "helg". '
                . 'IMPORTANT: Carefully analyze the ENTIRE sentence for ALL errors (spelling mistakes, wrong verb forms, incorrect prepositions/articles, word order issues, word choice errors). '
                . 'ALWAYS include CORR line if you find ANY error. List ALL mistakes you found with brief explanations IN ' . $targetlang . ' LANGUAGE (e.g., "bruk"→"bruke" (explanation in ' . $targetlang . '); "tit"→"tid" (explanation in ' . $targetlang . ')). '
                . 'Separate collocations with ";" and include only Norwegian text (no translations). Keep EX lines as "Norwegian sentence | ' . $targetlang . ' sentence".'
        ]);

        $payload = [
            'model' => $this->model,
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

        $response = $this->request($payload);
        $content = $response->choices[0]->message->content ?? '';
        if ($content === '') {
            throw new moodle_exception('ai_empty_response', 'mod_flashcards');
        }

        $parsed = $this->parse_structured_response($content, $translabel);
        $focus = trim($parsed['word'] ?? '');
        if ($focus === '') {
            return $this->fallback_focus($clickedword);
        }

        return [
            'focus' => core_text::substr($focus, 0, 200),
            'baseform' => core_text::substr($parsed['baseform'] ?: $focus, 0, 200),
            'pos' => $this->normalize_pos($parsed['pos'] ?? '', $focus),
            'definition' => core_text::substr($parsed['definition'] ?? '', 0, 600),
            'translation' => core_text::substr($parsed['translation'] ?? '', 0, 400),
            'translation_lang' => $uilang, // Add language code to result
            'gender' => $this->normalize_gender($parsed['gender'] ?? ''),
            'collocations' => $parsed['collocations'] ?? [],
            'examples' => $parsed['examples'] ?? [],
            'forms' => $parsed['forms'] ?? '',
            'correction' => core_text::substr($parsed['correction'] ?? '', 0, 400),
        ];
    }

    /**
     * Translate arbitrary learner text using ChatGPT.
     *
     * @param string $text
     * @param string $source
     * @param string $target
     * @param array $options
     * @return array
     * @throws moodle_exception
     */
    public function translate_text(string $text, string $source, string $target, array $options = []): array {
        if (!$this->is_enabled()) {
            throw new coding_exception('openai client is not configured');
        }
        $text = trim($text);
        if ($text === '') {
            throw new coding_exception('Missing text for translation');
        }
        $sourcecode = $this->sanitize_language($source);
        $targetcode = $this->sanitize_language($target);
        $sourcename = $this->language_name($sourcecode);
        $targetname = $this->language_name($targetcode);

        $systemprompt = "You are a precise translator from {$sourcename} to {$targetname}. "
            . "Return only the {$targetname} translation with natural, learner-friendly phrasing. No explanations.";
        $userprompt = implode("\n", [
            "SOURCE ({$sourcename}): " . $this->trim_prompt_text($text, 500),
            "TARGET LANGUAGE: {$targetname}",
            'RULES: Preserve numbers, names and formatting. If the input already looks like the target language, return it unchanged. Do not wrap the answer in quotes.'
        ]);

        $payload = [
            'model' => $this->model,
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

        $response = $this->request($payload);
        $content = trim($response->choices[0]->message->content ?? '');
        if ($content === '') {
            throw new moodle_exception('ai_empty_response', 'mod_flashcards');
        }

        return [
            'translation' => core_text::substr($content, 0, 800),
            'source' => $sourcecode,
            'target' => $targetcode,
        ];
    }

    public function answer_question(string $context, string $question, string $language, array $options = []): array {
        if (!$this->is_enabled()) {
            throw new coding_exception('openai client is not configured');
        }

        $context = trim($context);
        $question = trim($question);
        if ($context === '' || $question === '') {
            throw new coding_exception('Missing text for AI question');
        }

        $languageCode = $this->sanitize_language($language);
        $languageName = $this->language_name($languageCode);

        $systemprompt = "ROLE: Norwegian tutor for {$languageName}-speaking learners.\n"
            . "RULES: Use the Norwegian sentence context to answer the question. Provide concise, learner-friendly replies in {$languageName} without inventing facts.";
        $userprompt = implode("\n", [
            "CONTEXT (Norwegian): " . $this->trim_prompt_text($context, 500),
            "QUESTION ({$languageName}): " . $this->trim_prompt_text($question, 400),
        ]);

        $payload = [
            'model' => $this->model,
            'temperature' => $options['temperature'] ?? 0.35,
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

        $response = $this->request($payload);
        $content = trim($response->choices[0]->message->content ?? '');
        if ($content === '') {
            throw new moodle_exception('ai_empty_response', 'mod_flashcards');
        }

        return [
            'answer' => core_text::substr($content, 0, 1200),
            'language' => $languageCode,
        ];
    }

    protected function fallback_focus(string $word): array {
        $word = trim($word);
        if ($word === '') {
            $word = '?';
        }
        return [
            'focus' => $word,
            'baseform' => $word,
            'pos' => 'other',
            'definition' => '',
            'translation' => '',
            'gender' => '-',
            'collocations' => [],
            'examples' => [],
            'forms' => '',
        ];
    }

    protected function language_name(?string $code): string {
        $map = [
            'uk' => 'Ukrainian',
            'en' => 'English',
            'ru' => 'Russian',
            'pl' => 'Polish',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'no' => 'Norwegian',
            'nb' => 'Norwegian',
            'nn' => 'Norwegian',
        ];
        $key = core_text::strtolower(trim((string)$code));
        if ($key === '') {
            return 'English';
        }
        return $map[$key] ?? strtoupper($key);
    }

    protected function sanitize_language(?string $value): string {
        $value = core_text::strtolower(trim((string)$value));
        if ($value === '' || !preg_match('/^[a-z]{2,5}$/', $value)) {
            return 'uk';
        }
        return $value;
    }

    protected function sanitize_level(?string $value): string {
        $value = strtoupper(trim((string)$value));
        $allowed = ['A1', 'A2', 'B1'];
        if (!in_array($value, $allowed, true)) {
            return self::DEFAULT_LEVEL;
        }
        return $value;
    }

    protected function normalize_pos(string $pos, string $word): string {
        $pos = core_text::strtolower(trim($pos));
        if (in_array($pos, self::POS_OPTIONS, true)) {
            return $pos;
        }
        if ($this->should_mark_phrase($word)) {
            return 'phrase';
        }
        return 'other';
    }

    protected function should_mark_phrase(string $word): bool {
        $word = core_text::strtolower(trim($word));
        if ($word === '') {
            return false;
        }
        $tokens = preg_split('/\s+/', $word, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return false;
        }
        $first = $tokens[0];
        if ($first === 'å' || $first === 'aa') {
            array_shift($tokens);
        }
        if ($tokens && in_array($tokens[0], ['en', 'ei', 'et'], true)) {
            array_shift($tokens);
        }
        return count($tokens) >= 2;
    }

    protected function parse_structured_response(string $content, string $translationlabel): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^[\-\*\d\.\)\s]+/', '', $line);
            if (!preg_match('/^([A-Z0-9\-]+)\s*:\s*(.+)$/u', $line, $matches)) {
                continue;
            }
            $key = strtoupper($matches[1]);
            $data[$key] = trim($matches[2]);
        }

        return [
            'word' => $data['WORD'] ?? '',
            'baseform' => $data['BASE-FORM'] ?? '',
            'pos' => $data['POS'] ?? '',
            'definition' => $data['EXPL-NO'] ?? '',
            'translation' => $data[$translationlabel] ?? ($data['TR-UK'] ?? ''),
            'gender' => $data['GENDER'] ?? '',
            'collocations' => $this->parse_collocations($data['COLL'] ?? ''),
            'examples' => $this->collect_examples($data),
            'forms' => $data['FORMS'] ?? '',
            'correction' => $data['CORR'] ?? '',
        ];
    }

    protected function split_list(string $text, int $maxitems = 5): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?:;|•|\n)/u', $text);
        $clean = [];
        foreach ($parts as $part) {
            $part = core_text::substr(trim($part), 0, 160);
            if ($part === '') {
                continue;
            }
            $clean[] = $part;
            if (count($clean) >= $maxitems) {
                break;
            }
        }
        return $clean;
    }

    protected function collect_examples(array $data): array {
        $examples = [];
        foreach (['EX1', 'EX2', 'EX3'] as $key) {
            if (empty($data[$key])) {
                continue;
            }
            $examples[] = core_text::substr(trim($data[$key]), 0, 240);
        }
        return $examples;
    }

    protected function parse_collocations(string $text): array {
        $raw = $this->split_list($text, 5);
        $parsed = [];
        foreach ($raw as $entry) {
            $entry = core_text::substr(trim($entry), 0, 160);
            if ($entry === '') {
                continue;
            }
            $parsed[] = $entry;
        }
        return $parsed;
    }

    protected function normalize_gender(string $value): string {
        $value = core_text::strtolower(trim($value));
        if ($value === '') {
            return '-';
        }
        $map = [
            'm' => 'hankjonn',
            'masculine' => 'hankjonn',
            'hannkjonn' => 'hankjonn',
            'hankjønn' => 'hankjonn',
            'hankjonn' => 'hankjonn',
            'f' => 'hunkjonn',
            'feminine' => 'hunkjonn',
            'hunnkjonn' => 'hunkjonn',
            'hunkjønn' => 'hunkjonn',
            'hunkjonn' => 'hunkjonn',
            'n' => 'intetkjonn',
            'neuter' => 'intetkjonn',
            'intetkjønn' => 'intetkjonn',
            'intetkjonn' => 'intetkjonn',
        ];
        if (isset($map[$value])) {
            return $map[$value];
        }
        if (in_array($value, self::GENDER_OPTIONS, true)) {
            return $value;
        }
        return '-';
    }

    protected function trim_prompt_text(string $text, int $length = 400): string {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return core_text::substr($text, 0, $length);
    }

    protected function request(array $payload) {
        $curl = new \curl();
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ];
        $options = [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ];
        $response = $curl->post($this->baseurl, json_encode($payload, JSON_UNESCAPED_UNICODE), $options);
        if ($response === false) {
            $errno = $curl->get_errno();
            $error = $curl->error;
            throw new moodle_exception('ai_http_error', 'mod_flashcards', '', null, "cURL {$errno}: {$error}");
        }
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] >= 400) {
            throw new moodle_exception('ai_http_error', 'mod_flashcards', '', null, 'HTTP ' . $info['http_code'] . ': ' . $response);
        }
        $json = json_decode($response);
        if (!$json) {
            throw new moodle_exception('ai_invalid_json', 'mod_flashcards');
        }
        return $json;
    }
}
