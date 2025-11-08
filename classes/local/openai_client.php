<?php

namespace mod_flashcards\local;

use coding_exception;
use core_text;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Thin OpenAI chat-completions client that is tailored for phrase detection.
 */
class openai_client {
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
     * Ask ChatGPT to extract a fixed expression that contains the clicked word.
     *
     * @param string $fronttext
     * @param string $clickedword
     * @param string $language
     * @return string
     * @throws moodle_exception
     */
    public function detect_focus_data(string $fronttext, string $clickedword, string $language = 'no'): array {
        if (!$this->is_enabled()) {
            throw new coding_exception('openai client is not configured');
        }

        $fronttext = trim($fronttext);
        $clickedword = trim($clickedword);
        if ($fronttext === '' || $clickedword === '') {
            throw new coding_exception('Missing text for focus detection');
        }

        $systemprompt = 'Du er en norsk språkekspert. Du mottar en hel setning og et ord brukeren klikket på. '
            . 'Du skal svare med ett JSON-objekt uten forklaring. '
            . 'Format: {"focus":"...","baseform":"...","pos":"..."}'
            . 'Regler: '
            . '1) Finn et fast uttrykk som inneholder ordet. Hvis det finnes (f.eks. "å ha lyst på"), sett "focus" til hele uttrykket slik det vanligvis skrives, '
            . 'og "baseform" til leksikalsk form (for verb start alltid med "å", for uttrykk behold samme tekst). '
            . '2) Hvis ingen uttrykk finnes, bruk selve ordet i dictionary-form: '
            . 'verb -> infinitiv med "å", adjektiv -> positiv form, substantiv -> inkluder ubestemt artikkel i parentes (f.eks. "(en) sjokolade" eller "et hus"), '
            . 'pronomen/determinativ/preposisjon -> originalt ord. '
            . '3) "pos" må være én av: substantiv, adjektiv, pronomen, determinativ, verb, adverb, preposisjon, konjunksjon, subjunksjon, interjeksjon, phrase, other. '
            . '4) Ikke skriv tekst utenfor JSON. Ingen kommentarer.'
            . 'Eksempel: Setning "Jeg har lyst på sjokolade", ord "har" -> {"focus":"å ha lyst på","baseform":"å ha lyst på","pos":"phrase"}.';

        $userprompt = implode("\n", [
            "Setning: {$fronttext}",
            "Ord: {$clickedword}",
            "Språk: {$language}",
            "Returner JSON i henhold til reglene."
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
        $json = $this->extract_json($content);
        if (!$json || !is_array($json)) {
            return $this->fallback_focus($clickedword);
        }

        $focus = trim((string)($json['focus'] ?? ''));
        $base = trim((string)($json['baseform'] ?? ''));
        $pos = trim((string)($json['pos'] ?? ''));
        if ($focus === '' || preg_match('/ingen\s+uttryk/i', $focus)) {
            return $this->fallback_focus($clickedword);
        }
        if ($base === '') {
            $base = $focus;
        }
        if ($pos === '') {
            $pos = 'other';
        }

        return [
            'focus' => core_text::substr($focus, 0, 200),
            'baseform' => core_text::substr($base, 0, 200),
            'pos' => $pos,
        ];
    }

    protected function extract_json(string $content): ?array {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
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
        ];
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
