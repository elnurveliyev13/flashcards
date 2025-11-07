<?php

namespace mod_flashcards\local;

use coding_exception;
use context_user;
use moodle_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * ElevenLabs Text-To-Speech helper.
 */
class tts_service {
    /** @var string|null */
    protected $apikey;
    /** @var string */
    protected $model;
    /** @var bool */
    protected $enabled;
    /** @var string|null */
    protected $defaultvoice;

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->apikey = trim($config->elevenlabs_apikey ?? '') ?: getenv('FLASHCARDS_ELEVENLABS_KEY') ?: null;
        $this->model = trim($config->elevenlabs_model ?? '') ?: 'eleven_monolingual_v2';
        $this->defaultvoice = trim($config->elevenlabs_default_voice ?? '') ?: null;
        $this->enabled = !empty($this->apikey);
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Generate or reuse a TTS audio file.
     *
     * @param int $userid
     * @param string $text
     * @param array $options ['voice' => string, 'label' => string]
     * @return array{url:string,name:string,voice:string|null}
     * @throws moodle_exception
     */
    public function synthesize(int $userid, string $text, array $options = []): array {
        if (!$this->is_enabled()) {
            throw new coding_exception('tts service not configured');
        }
        $text = trim($text);
        if ($text === '') {
            throw new coding_exception('Empty text for TTS');
        }

        $voice = trim($options['voice'] ?? '') ?: $this->defaultvoice;
        if ($voice === '') {
            throw new coding_exception('Missing ElevenLabs voice id');
        }
        $label = trim($options['label'] ?? 'front');
        $filename = $this->build_filename($label, $voice, $text);

        $context = context_user::instance($userid);
        $fs = get_file_storage();
        if ($existing = $fs->get_file($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)) {
            return $this->format_file_response($context, $userid, $existing->get_filename(), $voice);
        }

        $payload = json_encode([
            'text' => $text,
            'model_id' => $this->model,
            'voice_settings' => [
                'stability' => 0.45,
                'similarity_boost' => 0.8,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $curl = new \curl();
        $headers = [
            'accept: audio/mpeg',
            'content-type: application/json',
            'xi-api-key: ' . $this->apikey,
        ];
        $endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voice);
        $response = $curl->post($endpoint, $payload, [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => 40,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        if ($response === false) {
            throw new moodle_exception('tts_http_error', 'mod_flashcards', '', null, $curl->error);
        }
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] >= 400) {
            throw new moodle_exception('tts_http_error', 'mod_flashcards', '', null, 'HTTP ' . $info['http_code'] . ': ' . $response);
        }

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_flashcards',
            'filearea'  => 'media',
            'itemid'    => $userid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        if ($existing = $fs->get_file($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)) {
            $existing->delete();
        }
        $file = $fs->create_file_from_string($filerecord, $response);

        return $this->format_file_response($context, $userid, $file->get_filename(), $voice);
    }

    protected function build_filename(string $label, string $voice, string $text): string {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($voice));
        $slug = trim($slug, '-') ?: 'voice';
        $hash = substr(sha1($voice . '|' . $label . '|' . $text), 0, 12);
        return "tts_{$label}_{$slug}_{$hash}.mp3";
    }

    protected function format_file_response(context_user $context, int $userid, string $filename, ?string $voice): array {
        $url = moodle_url::make_pluginfile_url($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)->out(false);
        return [
            'url' => $url,
            'name' => $filename,
            'voice' => $voice,
        ];
    }
}
