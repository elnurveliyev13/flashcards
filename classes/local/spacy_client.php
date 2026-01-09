<?php

namespace mod_flashcards\local;

use cache;

defined('MOODLE_INTERNAL') || die();

class spacy_client {
    /** @var string */
    private $url;
    /** @var int */
    private $timeout;

    public function __construct(?string $url = null, int $timeout = 30) {
        if ($url === null) {
            $config = get_config('mod_flashcards');
            $url = trim((string)($config->spacy_url ?? ''));
            if ($url === '') {
                $url = 'https://abcnorsk.no/spacy/analyze';
            }
        }
        $this->url = $url;
        $this->timeout = $timeout;
    }

    public function is_enabled(): bool {
        return $this->url !== '';
    }

    /**
     * Analyze text using spaCy API (cached by text hash).
     *
     * @param string $text
     * @return array
     */
    public function analyze_text(string $text): array {
        $text = trim($text);
        if ($text === '' || !$this->is_enabled()) {
            return [];
        }
        global $CFG;
        $cacheDisabled = !empty($CFG->mod_flashcards_disable_cache);

        $cachekey = hash('sha256', $text);
        $cache = null;
        if (!$cacheDisabled) {
            try {
                $cache = cache::make('mod_flashcards', 'spacy');
            } catch (\Throwable $e) {
                $cache = null;
            }
            if ($cache) {
                $cached = $cache->get($cachekey);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $curl = new \curl();
        $response = $curl->post($this->url, $payload, [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        if ($response === false) {
            $errno = $curl->get_errno();
            $error = $curl->error;
            throw new \RuntimeException("spaCy request failed ({$errno}): {$error}");
        }
        $info = $curl->get_info();
        $http = (int)($info['http_code'] ?? 0);
        if ($http < 200 || $http >= 300) {
            throw new \RuntimeException("spaCy HTTP {$http}: {$response}");
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON from spaCy.');
        }
        if (isset($data['tokens']) && is_array($data['tokens'])) {
            $data['tokens'] = array_values(array_filter($data['tokens'], fn($t) => is_array($t)));
        }
        if (isset($data['sents']) && is_array($data['sents'])) {
            $data['sents'] = array_values(array_filter($data['sents'], fn($t) => is_array($t)));
        }

        if ($cache && !$cacheDisabled) {
            $cache->set($cachekey, $data);
        }
        return $data;
    }
}
