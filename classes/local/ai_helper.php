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

        $systemprompt = "You are an experienced Norwegian (Bokmål) language teacher. Review student texts carefully, identify grammatical errors, and assess naturalness. Think step-by-step and double-check your analysis before responding.";

        $userprompt = "A student wrote this Norwegian sentence:

\"$text\"

TASK: Analyze this text in two stages:

STAGE 1 - Find grammatical errors:
Check for: word order, verb forms, prepositions, spelling, agreement
Rules:
- Fix ONLY actual grammatical errors
- Keep all other words unchanged
- Be precise in explanations (e.g., \"'ikke' must come BEFORE the verb in subordinate clauses\")

STAGE 2 - Assess naturalness:
After fixing errors, check if the corrected sentence sounds natural to a native speaker.
- If it sounds unnatural or awkward, provide a better suggestion in a separate 'suggestion' field
- Only suggest if there's a CLEARLY better way that natives would use
- Do NOT suggest minor stylistic changes

STAGE 3 - Self-check:
Review your corrections:
- Are all errors actually errors?
- Did you accidentally add/remove words?
- Are your explanations clear and accurate?
- Is your suggestion (if any) truly more natural?

Respond with valid JSON in $langname:
{
  \"hasErrors\": true/false,
  \"errors\": [
    {
      \"original\": \"incorrect part\",
      \"corrected\": \"corrected part\",
      \"issue\": \"clear explanation in $langname\"
    }
  ],
  \"correctedText\": \"grammatically correct text\",
  \"explanation\": \"overall explanation in $langname\",
  \"suggestion\": \"more natural alternative (optional, only if significantly better)\"
}";

        $client = new openai_client();
        if (!$client->is_enabled()) {
            return [
                'hasErrors' => false,
                'errors' => [],
                'correctedText' => $text,
                'explanation' => '',
            ];
        }

        // Use the same pattern as other methods in openai_client
        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.4,
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
            $reflection = new \ReflectionClass($client);
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
                    'hasErrors' => false,
                    'errors' => [],
                    'correctedText' => $text,
                    'explanation' => '',
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
                    'hasErrors' => false,
                    'errors' => [],
                    'correctedText' => $text,
                    'explanation' => '',
                ];
            }

            return $result;
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
        $payload = [
            'model' => 'gpt-4o-mini',
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
            $reflection = new \ReflectionClass($client);
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
