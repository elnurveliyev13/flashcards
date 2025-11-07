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
    public function detect_focus_phrase(string $fronttext, string $clickedword, string $language = 'no'): string {
        if (!$this->is_enabled()) {
            throw new coding_exception('openai client is not configured');
        }

        $fronttext = trim($fronttext);
        $clickedword = trim($clickedword);
        if ($fronttext === '' || $clickedword === '') {
            throw new coding_exception('Missing text for focus detection');
        }

        $systemprompt = 'Du er en språkekspert som hjelper med å identifisere faste uttrykk i norsk. '
            . 'Svar kun med selve uttrykket (én linje) uten forklaring. '
            . 'Hvis du ikke finner et uttrykk, svar med bare det opprinnelige ordet.';

        $userprompt = implode("\n", [
            "Setning: {$fronttext}",
            "Ord: {$clickedword}",
            "Språk: {$language}",
            "Gi meg kun uttrykket som matcher."
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
        if (empty($response->choices[0]->message->content)) {
            throw new moodle_exception('ai_empty_response', 'mod_flashcards');
        }
        $phrase = $response->choices[0]->message->content;
        // Some responses may contain quotes or markdown code fences.
        $phrase = trim(str_replace(['`', '"'], '', $phrase));
        $phrase = preg_replace("/\s+/", ' ', $phrase);
        return core_text::substr($phrase, 0, 200);
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
