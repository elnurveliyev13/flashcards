<?php

namespace mod_flashcards\local;

use coding_exception;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Lightweight OpenAI Whisper client used for browser recordings.
 */
class whisper_client {
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    /** @var bool */
    private $enabled = false;
    /** @var string|null */
    private $apikey;
    /** @var string */
    private $model = 'whisper-1';
    /** @var string */
    private $language = 'nb';
    /** @var int */
    private $cliplimit = 15;
    /** @var int */
    private $monthlylimit = 36000;
    /** @var int */
    private $timeout = 45;
    /** @var string */
    private $endpoint = self::DEFAULT_ENDPOINT;

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->apikey = trim($config->whisper_apikey ?? '') ?: (getenv('FLASHCARDS_WHISPER_KEY') ?: null);
        $this->model = trim($config->whisper_model ?? '') ?: 'whisper-1';
        $this->language = trim($config->whisper_language ?? '') ?: 'nb';
        $this->cliplimit = max(1, (int)($config->whisper_clip_limit ?? 15));
        $this->monthlylimit = max($this->cliplimit, (int)($config->whisper_monthly_limit ?? 36000));
        $this->timeout = max(5, (int)($config->whisper_timeout ?? 45));
        $this->endpoint = self::DEFAULT_ENDPOINT;
        $this->enabled = !empty($config->whisper_enabled) && !empty($this->apikey);
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * @throws moodle_exception
     */
    public function transcribe(string $filepath, string $filename, ?string $mimetype, int $userid, int $duration, ?string $requestlanguage = null): string {
        if (!$this->is_enabled()) {
            throw new moodle_exception('error_whisper_disabled', 'mod_flashcards');
        }
        if (!is_readable($filepath)) {
            throw new coding_exception('Missing audio file for transcription');
        }

        $duration = $this->sanitize_duration($duration);
        if ($duration > $this->cliplimit) {
            throw new moodle_exception('error_whisper_clip', 'mod_flashcards', '', $this->cliplimit);
        }

        $this->ensure_monthly_quota($userid, $duration);

        $headers = [
            'Authorization: Bearer ' . $this->apikey,
        ];
        $language = $requestlanguage !== null && $requestlanguage !== '' ? $requestlanguage : $this->language;
        $payload = [
            'file' => curl_file_create(
                $filepath,
                $mimetype ?: 'application/octet-stream',
                $filename !== '' ? $filename : 'audio.webm'
            ),
            'model' => $this->model,
            'response_format' => 'json',
        ];
        if ($language !== '') {
            $payload['language'] = $language;
        }

        $curl = curl_init($this->endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new moodle_exception('error_whisper_api', 'mod_flashcards', '', $error ?: 'cURL failure');
        }

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode($response, true);
        if ($status >= 400 || !is_array($data)) {
            $message = $this->extract_error_message($data, $response);
            throw new moodle_exception('error_whisper_api', 'mod_flashcards', '', $message);
        }

        $text = trim((string)($data['text'] ?? ''));
        if ($text === '') {
            throw new moodle_exception('error_whisper_api', 'mod_flashcards', '', 'Empty transcription response');
        }

        $this->record_usage($userid, $duration);
        return $text;
    }

    private function sanitize_duration(int $duration): int {
        if ($duration <= 0) {
            return 1;
        }
        return min($duration, $this->cliplimit);
    }

    private function ensure_monthly_quota(int $userid, int $duration): void {
        $prefname = $this->preference_name();
        $used = (int)get_user_preferences($prefname, 0, $userid);
        if ($used + $duration > $this->monthlylimit) {
            $limit = format_time($this->monthlylimit);
            throw new moodle_exception('error_whisper_quota', 'mod_flashcards', '', $limit);
        }
    }

    private function record_usage(int $userid, int $duration): void {
        $prefname = $this->preference_name();
        $used = (int)get_user_preferences($prefname, 0, $userid);
        $newvalue = min($this->monthlylimit, $used + $duration);
        set_user_preference($prefname, $newvalue, $userid);
    }

    private function preference_name(): string {
        return 'mod_flashcards_whisper_' . date('Ym');
    }

    private function extract_error_message($data, string $fallback): string {
        if (is_array($data)) {
            if (!empty($data['error']['message'])) {
                return (string)$data['error']['message'];
            }
            if (!empty($data['message'])) {
                return (string)$data['message'];
            }
        }
        $fallback = trim($fallback);
        return $fallback !== '' ? mb_substr($fallback, 0, 400) : 'Unknown error';
    }
}
