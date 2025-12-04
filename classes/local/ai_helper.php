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
            try {
                $audio['front'] = $this->tts->synthesize($userid, $fronttext, [
                    'voice' => $voice,
                    'label' => 'front',
                ]);
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
                    $audio['focus'] = $this->tts->synthesize($userid, $focusAudioText, [
                        'voice' => $voice,
                        'label' => 'focus',
                    ]);
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

        return $result;
    }

    public function translate_text(int $userid, string $text, string $source, string $target, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }
        $translation = $this->openai->translate_text($userid, $text, $source, $target, $options);
        return [
            'translation' => $translation['translation'] ?? '',
            'sourceLang' => $translation['source'] ?? $source,
            'targetLang' => $translation['target'] ?? $target,
        ];
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
        return $this->openai->answer_question($userid, $fronttext, $question, $language);
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
    public function check_norwegian_text(string $text, string $language, int $userid): array {
        $languagemap = [
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'en' => 'English',
            'no' => 'Norwegian',
        ];
        $langname = $languagemap[$language] ?? 'English';
        $debugtiming = [];
        $overallstart = microtime(true);

        // First request: Find errors
        $systemprompt1 = "You are an experienced Norwegian (Bokmål) language teacher. Check student texts for grammatical errors with high attention to detail. You MUST respond in $langname language.";

        $userprompt1 = "Check this Norwegian sentence for grammatical errors:

\"$text\"

Look for: prepositions, word order, verb forms, agreement, spelling.

Rules:
- Fix ONLY grammar errors
- Do NOT add/remove words

JSON:
{
  \"hasErrors\": true/false,
  \"errors\": [{\"original\": \"wrong\", \"corrected\": \"correct\", \"issue\": \"\"}],
  \"correctedText\": \"fixed text\",
  \"explanation\": \"\"
}

IMPORTANT: Leave \"issue\" and \"explanation\" EMPTY (\"\").";

        $client = new openai_client();
        if (!$client->is_enabled()) {
            return [
                'hasErrors' => false,
                'errors' => [],
                'correctedText' => $text,
                'explanation' => '',
            ];
        }

        $config = get_config('mod_flashcards');
        $correctionmodel = trim((string)($config->openai_correction_model ?? ''));

        // Use the same model as configured in openai_client (taken from plugin settings).
        $reflection = new \ReflectionClass($client);
        $model = 'gpt-4o-mini';
        if ($reflection->hasProperty('model')) {
            $prop = $reflection->getProperty('model');
            $prop->setAccessible(true);
            $value = trim((string)$prop->getValue($client));
            if ($value !== '') {
                $model = $value;
            }
        }
        if ($correctionmodel !== '') {
            $model = $correctionmodel;
        }

        // STAGE 1: First API call - Find errors
        $payload1 = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt1],
                ['role' => 'user', 'content' => $userprompt1],
            ],
        ];

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            // First request
            $t1 = microtime(true);
            $response1 = $method->invoke($client, $payload1);
            $debugtiming['api_stage1'] = microtime(true) - $t1;

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response1->usage ?? null);

            $content1 = trim($response1->choices[0]->message->content ?? '');
            if ($content1 === '') {
                return ['hasErrors' => false, 'errors' => [], 'correctedText' => $text, 'explanation' => ''];
            }

            // Parse first response
            $json1 = null;
            if (preg_match('~\{.*\}~s', $content1, $m)) {
                $json1 = $m[0];
            }
            $result1 = $json1 ? json_decode($json1, true) : json_decode($content1, true);

            if (!is_array($result1) || !isset($result1['hasErrors'])) {
                return ['hasErrors' => false, 'errors' => [], 'correctedText' => $text, 'explanation' => ''];
            }

            // If no errors found, return immediately
            if (!$result1['hasErrors']) {
                $debugtiming['overall'] = microtime(true) - $overallstart;
                $result1['debugTiming'] = $debugtiming;
                return $result1;
            }

        // Optional STAGE 2: Second API call - Double-check and suggest natural alternative.
        // Controlled by admin setting to keep latency acceptable for slower models.
        $enabledoublecheck = !empty($config->ai_doublecheck_correction);
        if (!$enabledoublecheck) {
            $debugtiming['overall'] = microtime(true) - $overallstart;
            $result1['debugTiming'] = $debugtiming;
            return $result1;
        }

        $correctedText = $result1['correctedText'] ?? $text;

        $systemprompt2 = "You are a native Norwegian speaker. Review corrections critically. Do NOT suggest synonyms or minor word changes - only suggest if there's a REAL naturalness improvement. You MUST respond in $langname language.";

        $userprompt2 = "Original: \"$text\"
Corrected: \"$correctedText\"

Tasks:
1. Check if any grammatical errors were missed in the correction
2. ONLY if the corrected text sounds unnatural or awkward, suggest a more natural alternative

JSON:
{
  \"additionalErrors\": [{\"original\": \"wrong\", \"corrected\": \"right\", \"issue\": \"\"}],
  \"suggestion\": \"\"
}

CRITICAL RULES:
- Leave \"suggestion\" EMPTY if corrected text is already natural
- Do NOT suggest synonym replacements (like changing \"lett\" to \"enkelt\")
- Only suggest if there's a clear naturalness or style improvement
- Leave \"issue\" EMPTY (\"\")";

            $payload2 = [
                'model' => $model,
                'temperature' => 0.2,
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

            $content2 = trim($response2->choices[0]->message->content ?? '');
            $json2 = null;
            if (preg_match('~\{.*\}~s', $content2, $m)) {
                $json2 = $m[0];
            }
            $result2 = $json2 ? json_decode($json2, true) : json_decode($content2, true);

            // Merge results
            $finalResult = $result1;

            if (is_array($result2)) {
                // Add additional errors if found
                if (!empty($result2['additionalErrors']) && is_array($result2['additionalErrors'])) {
                    $finalResult['errors'] = array_merge($finalResult['errors'] ?? [], $result2['additionalErrors']);
                    // Update correctedText if there were additional fixes
                    foreach ($result2['additionalErrors'] as $err) {
                        if (isset($err['original']) && isset($err['corrected'])) {
                            $finalResult['correctedText'] = str_replace($err['original'], $err['corrected'], $finalResult['correctedText']);
                        }
                    }
                }

                // Add suggestion if present
                if (!empty($result2['suggestion'])) {
                    $finalResult['suggestion'] = $result2['suggestion'];
                }
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
            ];
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

        $config = get_config('mod_flashcards');
        $correctionmodel = trim((string)($config->openai_correction_model ?? ''));

        $reflection = new \ReflectionClass($client);
        $model = 'gpt-4o-mini';
        if ($reflection->hasProperty('model')) {
            $prop = $reflection->getProperty('model');
            $prop->setAccessible(true);
            $value = trim((string)$prop->getValue($client));
            if ($value !== '') {
                $model = $value;
            }
        }
        if ($correctionmodel !== '') {
            $model = $correctionmodel;
        }

        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            $response = $method->invoke($client, $payload);

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response->usage ?? null);

            $answer = trim($response->choices[0]->message->content ?? '');

            return ['answer' => $answer];
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
        $config = get_config('mod_flashcards');
        $correctionmodel = trim((string)($config->openai_correction_model ?? ''));

        $reflection = new \ReflectionClass($client);
        $model = 'gpt-4o-mini';
        if ($reflection->hasProperty('model')) {
            $prop = $reflection->getProperty('model');
            $prop->setAccessible(true);
            $value = trim((string)$prop->getValue($client));
            if ($value !== '') {
                $model = $value;
            }
        }
        if ($correctionmodel !== '') {
            $model = $correctionmodel;
        }

        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => $messages,
        ];

        try {
            $method = $reflection->getMethod('request');
            $method->setAccessible(true);

            $response = $method->invoke($client, $payload);

            $recordMethod = $reflection->getMethod('record_usage');
            $recordMethod->setAccessible(true);
            $recordMethod->invoke($client, $userid, $response->usage ?? null);

            $answer = trim($response->choices[0]->message->content ?? '');

            return ['answer' => $answer];
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

        // Use the same pattern as other methods in openai_client
        $reflection = new \ReflectionClass($client);
        $model = 'gpt-4o-mini';
        if ($reflection->hasProperty('model')) {
            $prop = $reflection->getProperty('model');
            $prop->setAccessible(true);
            $value = trim((string)$prop->getValue($client));
            if ($value !== '') {
                $model = $value;
            }
        }

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
}
