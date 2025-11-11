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

        $systemprompt = <<<PROMPT
ROLE: Norwegian tutor for Ukrainian learners.

RULES:
- No inflections (API provides them).
- Nouns: mark countability and gender via article:
  - Countable: article w/o parentheses (en/ei/et).
  - Uncountable: article in parentheses (e.g., (et) vann).
- Structure output with exact labels below; keep it brief and level-appropriate.

FORMAT:
WORD: <base form with article or "å">
POS: <one of substantiv|adjektiv|pronomen|determinativ|verb|adverb|preposisjon|konjunksjon|subjunksjon|interjeksjon|phrase|other>
EXPL-NO: <simple Norwegian explanation>
TR-UK: <Ukrainian translation of meaning>
COLL: <3-5 most common collocations, most frequent first>
EX1: <NO sentence using a top collocation> | <UKR>
EX2: <NO> | <UKR>
EX3: <NO> | <UKR>
FORMS: <other useful lexical forms (verb/noun/adj variants) with tiny NO gloss + UKR>

NOTES:
- Focus on everyday, high-frequency uses.
- One core sense for A1; add secondary sense only if clearly frequent/relevant (B1).
- Avoid grammar lectures; show usage via collocations/examples.
- Treat multi-word expressions (after removing leading "å" or indefinite articles) as POS "phrase".
PROMPT;

        $userprompt = implode("\n", [
            'sentence: ' . $this->trim_prompt_text($fronttext),
            'clicked_word: ' . $this->trim_prompt_text($clickedword, 60),
            "level: {$level}",
            "ui_lang: {$uilang}",
            'instructions: Identify the natural expression in the sentence that includes the clicked word. Fill every label exactly as listed in the FORMAT block. '
                . 'Determine POS using only the allowed values; if the expression has two or more lexical words after removing a leading "å" or articles (en/ei/et), label it as "phrase". '
                . 'Separate collocations with ";" in frequency order. Each EX line must be "Norwegian sentence | Ukrainian sentence".'
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

        $parsed = $this->parse_structured_response($content, 'TR-UK');
        $focus = trim($parsed['word'] ?? '');
        if ($focus === '') {
            return $this->fallback_focus($clickedword);
        }

        return [
            'focus' => core_text::substr($focus, 0, 200),
            'baseform' => core_text::substr($focus, 0, 200),
            'pos' => $this->normalize_pos($parsed['pos'] ?? '', $focus),
            'definition' => core_text::substr($parsed['definition'] ?? '', 0, 600),
            'translation' => core_text::substr($parsed['translation'] ?? '', 0, 400),
            'collocations' => $parsed['collocations'] ?? [],
            'examples' => $parsed['examples'] ?? [],
            'forms' => $parsed['forms'] ?? '',
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
            'collocations' => [],
            'examples' => [],
            'forms' => '',
        ];
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
            'pos' => $data['POS'] ?? '',
            'definition' => $data['EXPL-NO'] ?? '',
            'translation' => $data[$translationlabel] ?? ($data['TR-UK'] ?? ''),
            'collocations' => $this->split_list($data['COLL'] ?? ''),
            'examples' => $this->collect_examples($data),
            'forms' => $data['FORMS'] ?? '',
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
