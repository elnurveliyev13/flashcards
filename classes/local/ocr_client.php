<?php

namespace mod_flashcards\local;

use coding_exception;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal wrapper around OCR.space so we can send image snapshots to the plugin.
 */
class ocr_client {
    private const DEFAULT_ENDPOINT = 'https://api.ocr.space/parse/image';

    /** @var bool */
    private $enabled = false;
    /** @var string */
    private $endpoint;
    /** @var string */
    private $language = 'eng';
    /** @var string|null */
    private $apikey;
    /** @var int */
    private $timeout = 30;

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->apikey = trim($config->ocr_apikey ?? '') ?: (getenv('FLASHCARDS_OCR_KEY') ?: null);
        $this->language = trim($config->ocr_language ?? '') ?: 'eng';
        $this->endpoint = trim($config->ocr_endpoint ?? '') ?: self::DEFAULT_ENDPOINT;
        $this->timeout = max(5, (int)($config->ocr_timeout ?? 30));
        $this->enabled = !empty($config->ocr_enabled) && !empty($this->apikey);
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Recognize text inside a snapshot that was already uploaded to temporary storage.
     *
     * @throws moodle_exception
     */
    public function recognize(string $filepath, string $filename, ?string $mimetype, ?string $language = null): string {
        if (!$this->is_enabled()) {
            throw new moodle_exception('error_ocr_disabled', 'mod_flashcards');
        }
        if (!is_readable($filepath)) {
            throw new coding_exception('Missing image file for OCR');
        }

        $payload = [
            'apikey' => $this->apikey,
            'language' => $language ?: ($this->language ?: 'eng'),
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'true',
            'OCREngine' => 2,
        ];
        $payload['file'] = curl_file_create(
            $filepath,
            $mimetype ?: mime_content_type($filepath) ?: 'image/jpeg',
            $filename ?: 'ocr.png'
        );

        $curl = curl_init($this->endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new moodle_exception('error_ocr_api', 'mod_flashcards', '', $error ?: 'cURL failure');
        }
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode($response, true);
        if ($status >= 400 || !is_array($data)) {
            $message = $this->extract_error_message($data, $response);
            throw new moodle_exception('error_ocr_api', 'mod_flashcards', '', $message);
        }
        if (!empty($data['IsErroredOnProcessing'])) {
            $message = $this->extract_error_message($data, $response);
            throw new moodle_exception('error_ocr_api', 'mod_flashcards', '', $message);
        }

        $parts = [];
        foreach ($data['ParsedResults'] ?? [] as $result) {
            $parsed = trim($result['ParsedText'] ?? '');
            if ($parsed !== '') {
                $parts[] = $parsed;
            }
        }
        if (empty($parts)) {
            throw new moodle_exception('error_ocr_nodata', 'mod_flashcards');
        }

        return implode("\n", $parts);
    }

    private function extract_error_message($data, string $fallback): string {
        if (is_array($data)) {
            if (!empty($data['ErrorMessage'])) {
                return (string)$data['ErrorMessage'];
            }
            if (!empty($data['ErrorDetails'])) {
                return (string)$data['ErrorDetails'];
            }
        }
        if (is_string($data) && trim($data) !== '') {
            return trim($data);
        }
        return $fallback !== '' ? mb_substr($fallback, 0, 400) : 'Unknown error';
    }
}
