<?php

namespace mod_flashcards\local;

use coding_exception;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Google Cloud Vision OCR client used for quick photo uploads.
 */
class ocr_client {
    private const DEFAULT_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    /** @var bool */
    private $enabled = false;
    /** @var string */
    private $endpoint;
    /** @var string|null */
    private $apikey;
    /** @var string */
    private $language = 'en';
    /** @var int */
    private $timeout = 30;

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->apikey = trim($config->googlevision_api_key ?? '') ?: (getenv('FLASHCARDS_GOOGLEVISION_KEY') ?: null);
        $this->language = trim($config->googlevision_language ?? '') ?: 'en';
        $this->endpoint = trim($config->googlevision_endpoint ?? '') ?: (getenv('FLASHCARDS_GOOGLEVISION_ENDPOINT') ?: self::DEFAULT_ENDPOINT);
        $this->timeout = max(5, (int)($config->googlevision_timeout ?? 30));
        $this->enabled = !empty($config->googlevision_enabled) && !empty($this->apikey);
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Perform OCR by calling Google Cloud Vision REST API.
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

        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new moodle_exception('error_ocr_upload', 'mod_flashcards');
        }

        $requestbody = [
            'requests' => [
                [
                    'image' => [
                        'content' => base64_encode($content),
                    ],
                    'features' => [
                        [
                            'type' => 'TEXT_DETECTION',
                            'maxResults' => 1,
                        ],
                    ],
                ],
            ],
        ];
        $hint = $language ?: $this->language;
        if ($hint !== '') {
            $requestbody['requests'][0]['imageContext'] = [
                'languageHints' => [$hint],
            ];
        }

        $url = $this->endpoint . '?key=' . urlencode($this->apikey);
        $headers = [
            'Content-Type: application/json',
        ];

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestbody),
            CURLOPT_HTTPHEADER => $headers,
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

        $firstresponse = $data['responses'][0] ?? [];
        if (!empty($firstresponse['error'])) {
            $message = $this->extract_error_message($firstresponse['error'], $response);
            throw new moodle_exception('error_ocr_api', 'mod_flashcards', '', $message);
        }

        $text = trim((string)($firstresponse['fullTextAnnotation']['text'] ?? ''));
        if ($text === '') {
            if (!empty($firstresponse['textAnnotations'][0]['description'])) {
                $text = trim((string)$firstresponse['textAnnotations'][0]['description']);
            }
        }

        if ($text === '') {
            throw new moodle_exception('error_ocr_nodata', 'mod_flashcards');
        }

        return $text;
    }

    private function extract_error_message($data, string $fallback): string {
        if (is_array($data)) {
            if (!empty($data['message'])) {
                return (string)$data['message'];
            }
            if (!empty($data['description'])) {
                return (string)$data['description'];
            }
            if (!empty($data['error']['message'])) {
                return (string)$data['error']['message'];
            }
        }
        if (is_string($data) && trim($data) !== '') {
            return trim($data);
        }
        return $fallback !== '' ? mb_substr($fallback, 0, 400) : 'Unknown error';
    }
}
