<?php

namespace mod_flashcards\local;

use coding_exception;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * ElevenLabs Speech-to-Text client for browser recordings.
 *
 * API Documentation: https://elevenlabs.io/docs/api-reference/speech-to-text
 */
class elevenlabs_stt_client {
    private const DEFAULT_ENDPOINT = 'https://api.elevenlabs.io/v1/speech-to-text';

    /** @var bool */
    private $enabled = false;
    /** @var string|null */
    private $apikey;
    /** @var string */
    private $model = 'scribe_v1';
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

        // ElevenLabs STT can use the same API key as TTS or a separate one
        $this->apikey = trim($config->elevenlabs_stt_apikey ?? '')
            ?: trim($config->elevenlabs_apikey ?? '')
            ?: (getenv('FLASHCARDS_ELEVENLABS_KEY') ?: null);

        $this->model = trim($config->elevenlabs_stt_model ?? '') ?: 'scribe_v1';
        $this->language = trim($config->elevenlabs_stt_language ?? '') ?: 'nb';
        $this->cliplimit = max(1, (int)($config->elevenlabs_stt_clip_limit ?? 15));
        $this->monthlylimit = max($this->cliplimit, (int)($config->elevenlabs_stt_monthly_limit ?? 36000));
        $this->timeout = max(5, (int)($config->elevenlabs_stt_timeout ?? 45));
        $this->endpoint = self::DEFAULT_ENDPOINT;
        $this->enabled = !empty($config->elevenlabs_stt_enabled) && !empty($this->apikey);
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Transcribe audio using ElevenLabs Speech-to-Text API.
     *
     * @param string $filepath Path to audio file
     * @param string $filename Original filename
     * @param string|null $mimetype MIME type
     * @param int $userid User ID for quota tracking
     * @param int $duration Duration in seconds
     * @param string|null $requestlanguage Optional language override
     * @return string Transcribed text
     * @throws moodle_exception
     */
    public function transcribe(string $filepath, string $filename, ?string $mimetype, int $userid, int $duration, ?string $requestlanguage = null): string {
        if (!$this->is_enabled()) {
            throw new moodle_exception('error_elevenlabs_stt_disabled', 'mod_flashcards');
        }
        if (!is_readable($filepath)) {
            throw new coding_exception('Missing audio file for transcription');
        }

        $duration = $this->sanitize_duration($duration);
        if ($duration > $this->cliplimit) {
            throw new moodle_exception('error_elevenlabs_stt_clip', 'mod_flashcards', '', $this->cliplimit);
        }

        $this->ensure_monthly_quota($userid, $duration);

        $headers = [
            'xi-api-key: ' . $this->apikey,
        ];

        $language = $requestlanguage !== null && $requestlanguage !== '' ? $requestlanguage : $this->language;

        // ElevenLabs STT uses multipart form-data
        $payload = [
            'file' => curl_file_create(
                $filepath,
                $mimetype ?: 'application/octet-stream',
                $filename !== '' ? $filename : 'audio.webm'
            ),
            'model_id' => $this->model,
        ];

        // Add language_code if specified (ElevenLabs uses language_code parameter)
        if ($language !== '') {
            $payload['language_code'] = $language;
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
            throw new moodle_exception('error_elevenlabs_stt_api', 'mod_flashcards', '', $error ?: 'cURL failure');
        }

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode($response, true);
        if ($status >= 400 || !is_array($data)) {
            $message = $this->extract_error_message($data, $response);
            throw new moodle_exception('error_elevenlabs_stt_api', 'mod_flashcards', '', $message);
        }

        // ElevenLabs returns 'text' field in response
        $text = trim((string)($data['text'] ?? ''));
        if ($text === '') {
            throw new moodle_exception('error_elevenlabs_stt_api', 'mod_flashcards', '', 'Empty transcription response');
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
            throw new moodle_exception('error_elevenlabs_stt_quota', 'mod_flashcards', '', $limit);
        }
    }

    private function record_usage(int $userid, int $duration): void {
        $prefname = $this->preference_name();
        $used = (int)get_user_preferences($prefname, 0, $userid);
        $newvalue = min($this->monthlylimit, $used + $duration);
        set_user_preference($prefname, $newvalue, $userid);
    }

    private function preference_name(): string {
        return 'mod_flashcards_elevenlabs_stt_' . date('Ym');
    }

    private function extract_error_message($data, string $fallback): string {
        if (is_array($data)) {
            // ElevenLabs error format
            if (!empty($data['detail']['message'])) {
                return (string)$data['detail']['message'];
            }
            if (!empty($data['detail'])) {
                return is_string($data['detail']) ? $data['detail'] : json_encode($data['detail']);
            }
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
