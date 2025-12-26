<?php

namespace mod_flashcards\local;

use coding_exception;
use context_user;
use moodle_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Text-To-Speech helper that routes between Amazon Polly and ElevenLabs.
 */
class tts_service {
    private const PROVIDER_ELEVENLABS = 'elevenlabs';
    private const PROVIDER_POLLY = 'polly';

    private const ELEVEN_MODEL_FOCUS = 'eleven_v3';
    private const ELEVEN_LANGUAGE_DEFAULT = 'no';

    /** @var string|null */
    protected $elevenapikey;
    /** @var string */
    protected $elevenmodel;
    /** @var string */
    protected $elevenlanguagecode;
    /** @var string */
    protected $elevenfocusmodel;
    /** @var string|null */
    protected $elevenfocuslanguagecode;
    /** @var string */
    protected $shortprovider;
    /** @var float */
    protected $focusstability;
    /** @var float */
    protected $focusspeed;
    /** @var string|null */
    protected $defaultvoice;
    /** @var bool */
    protected $enabled;
    /** @var bool */
    protected $elevenenabled;

    /** @var string|null */
    protected $pollyaccess;
    /** @var string|null */
    protected $pollysecret;
    /** @var string */
    protected $pollyregion;
    /** @var string|null */
    protected $pollyvoice;
    /** @var bool */
    protected $pollyenabled;
    /** @var array<string,string> */
    protected $pollyoverrides = [];
    /** @var int */
    protected $elevenlimit;
    /** @var int */
    protected $elevenlimitglobal;
    /** @var int */
    protected $pollylimit;
    /** @var int */
    protected $pollylimitglobal;
    /** @var array<string,int> */
    protected $globalusagecache = [];

    public function __construct() {
        $config = get_config('mod_flashcards');
        $this->elevenapikey = trim($config->elevenlabs_apikey ?? '') ?: getenv('FLASHCARDS_ELEVENLABS_KEY') ?: null;
        $this->elevenmodel = trim($config->elevenlabs_model ?? '') ?: 'eleven_monolingual_v2';
        $this->elevenlanguagecode = trim($config->elevenlabs_language_code ?? '') ?: self::ELEVEN_LANGUAGE_DEFAULT;
        $this->elevenfocusmodel = trim($config->elevenlabs_focus_model ?? '') ?: self::ELEVEN_MODEL_FOCUS;
        $this->elevenfocuslanguagecode = trim($config->elevenlabs_focus_language_code ?? '') ?: null;
        $this->shortprovider = strtolower(trim((string)($config->tts_short_provider ?? ''))) ?: self::PROVIDER_ELEVENLABS;
        $this->focusstability = (float)($config->elevenlabs_focus_stability ?? 0.95);
        $this->focusspeed = (float)($config->elevenlabs_focus_speed ?? 0.85);
        $this->defaultvoice = trim($config->elevenlabs_default_voice ?? '') ?: null;
        $this->elevenenabled = !empty($this->elevenapikey);
        $this->elevenlimit = (int)($config->elevenlabs_tts_monthly_limit ?? 0);
        $this->elevenlimitglobal = (int)($config->elevenlabs_tts_monthly_limit_global ?? 0);

        $this->pollyaccess = trim($config->amazonpolly_access_key ?? '') ?: getenv('FLASHCARDS_POLLY_KEY') ?: null;
        $this->pollysecret = trim($config->amazonpolly_secret_key ?? '') ?: getenv('FLASHCARDS_POLLY_SECRET') ?: null;
        $this->pollyregion = trim($config->amazonpolly_region ?? '') ?: getenv('FLASHCARDS_POLLY_REGION') ?: 'eu-west-1';
        $this->pollyvoice = trim($config->amazonpolly_voice_id ?? '') ?: null;
        $rawoverrides = (string)($config->amazonpolly_voice_map ?? '');
        $this->pollyoverrides = $this->parse_voice_overrides($rawoverrides);
        $this->pollyenabled = !empty($this->pollyaccess) && !empty($this->pollysecret);
        $this->pollylimit = (int)($config->amazonpolly_tts_monthly_limit ?? 0);
        $this->pollylimitglobal = (int)($config->amazonpolly_tts_monthly_limit_global ?? 0);

        $this->enabled = $this->elevenenabled || $this->pollyenabled;
    }

    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Generate or reuse a TTS audio file.
     *
     * @param int $userid
     * @param string $text
     * @param array $options ['voice' => string, 'label' => string, 'provider' => string]
     * @return array{url:string,name:string,voice:string|null,provider:string}
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
        $label = trim($options['label'] ?? 'front');
        if ($label === '') {
            $label = 'front';
        }
        $languagecode = trim((string)($options['language_code'] ?? '')) ?: $this->elevenlanguagecode;
        if ($label === 'focus' && !empty($this->elevenfocuslanguagecode)) {
            $languagecode = $this->elevenfocuslanguagecode;
        }

        $tokens = $this->estimate_tts_tokens($text);
        $preferred = $options['provider'] ?? null;
        $resolved = $this->resolve_provider($text, $preferred);
        $provider = $this->choose_provider_with_limits($userid, $text, $preferred, $tokens);
        $fallbackmeta = null;
        if ($provider !== $resolved) {
            $fallbackmeta = [
                'from' => $resolved,
                'to' => $provider,
                'reason' => 'quota',
            ];
        }
        if ($provider === self::PROVIDER_POLLY) {
            $pollyvoice = $this->resolve_polly_voice($voice);
            if ($pollyvoice === '') {
                throw new coding_exception('Missing Amazon Polly voice');
            }
            $out = $this->synthesize_with_polly($userid, $text, $label, $pollyvoice);
            if (is_array($fallbackmeta)) {
                $out['fallback'] = $fallbackmeta;
            }
            return $out;
        }

        if ($voice === '') {
            throw new coding_exception('Missing ElevenLabs voice id');
        }
        try {
            $out = $this->synthesize_with_elevenlabs($userid, $text, $label, $voice, $languagecode);
            if (is_array($fallbackmeta)) {
                $out['fallback'] = $fallbackmeta;
            }
            return $out;
        } catch (\moodle_exception $ex) {
            // Fallback to Polly on provider errors if available and under limit.
            if ($this->pollyenabled && !$this->would_exceed_limit($userid, self::PROVIDER_POLLY, $tokens)) {
                $pollyvoice = $this->resolve_polly_voice($voice);
                if ($pollyvoice !== '') {
                    debugging('[flashcards] ElevenLabs TTS failed, falling back to Polly: ' . $ex->getMessage(), DEBUG_DEVELOPER);
                    $out = $this->synthesize_with_polly($userid, $text, $label, $pollyvoice);
                    $out['fallback'] = [
                        'from' => self::PROVIDER_ELEVENLABS,
                        'to' => self::PROVIDER_POLLY,
                        'reason' => 'provider_error',
                        'details' => $ex->getMessage(),
                    ];
                    return $out;
                }
            }
            throw $ex;
        }
    }

    protected function synthesize_with_elevenlabs(int $userid, string $text, string $label, string $voice, string $languagecode): array {
        if (!$this->elevenenabled) {
            throw new coding_exception('ElevenLabs not configured');
        }

        $model = $this->get_eleven_model_for_request($label, $text);
        $voicesettings = $this->get_eleven_voice_settings_for_label($label);
        $filename = $this->build_filename($label, $voice, $text, self::PROVIDER_ELEVENLABS, [
            'model' => $model,
            'language_code' => $languagecode,
            'voice_settings' => $voicesettings,
        ]);
        $context = context_user::instance($userid);
        $fs = get_file_storage();
        if ($existing = $fs->get_file($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)) {
            return $this->format_file_response($context, $userid, $existing->get_filename(), $voice, self::PROVIDER_ELEVENLABS);
        }

        $payload = json_encode([
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => $voicesettings,
            'pronunciation_dictionary_locators' => [],
            'seed' => 0,
            'previous_text' => '',
            'next_text' => '',
            'previous_request_ids' => [],
            'next_request_ids' => [],
            'apply_text_normalization' => 'off',
            'apply_language_text_normalization' => false,
            'use_pvc_as_ivc' => false,
            'language_code' => $languagecode,
        ], JSON_UNESCAPED_UNICODE);

        $curl = new \curl();
        $headers = [
            'accept: audio/mpeg',
            'content-type: application/json',
            'xi-api-key: ' . $this->elevenapikey,
        ];
        $endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voice) . '?output_format=mp3_44100_128';
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

        $file = $this->store_audio_file($context, $userid, $filename, $response);
        $this->record_tts_usage($userid, $text, self::PROVIDER_ELEVENLABS);
        return $this->format_file_response($context, $userid, $file->get_filename(), $voice, self::PROVIDER_ELEVENLABS);
    }

    protected function synthesize_with_polly(int $userid, string $text, string $label, string $voice): array {
        if (!$this->pollyenabled) {
            throw new coding_exception('Amazon Polly not configured');
        }

        $filename = $this->build_filename($label, $voice, $text, self::PROVIDER_POLLY);
        $context = context_user::instance($userid);
        $fs = get_file_storage();
        if ($existing = $fs->get_file($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)) {
            return $this->format_file_response($context, $userid, $existing->get_filename(), $voice, self::PROVIDER_POLLY);
        }

        $response = $this->request_polly_stream($voice, $text);
        $file = $this->store_audio_file($context, $userid, $filename, $response);
        $this->record_tts_usage($userid, $text, self::PROVIDER_POLLY);
        return $this->format_file_response($context, $userid, $file->get_filename(), $voice, self::PROVIDER_POLLY);
    }

    protected function request_polly_stream(string $voice, string $text): string {
        $payload = [
            'Text' => $text,
            'VoiceId' => $voice,
            'OutputFormat' => 'mp3',
            'SampleRate' => '22050',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $host = 'polly.' . $this->pollyregion . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/v1/speech';
        $amzdate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        $payloadhash = hash('sha256', $body);
        $canonicalheaders = implode("\n", [
            'content-type:application/json',
            'host:' . $host,
            'x-amz-content-sha256:' . $payloadhash,
            'x-amz-date:' . $amzdate,
        ]) . "\n";
        $signedheaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalrequest = "POST\n/v1/speech\n\n" . $canonicalheaders . "\n" . $signedheaders . "\n" . $payloadhash;
        $credentialscope = $datestamp . '/' . $this->pollyregion . '/polly/aws4_request';
        $stringtosign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$credentialscope}\n" . hash('sha256', $canonicalrequest);
        $signingkey = $this->build_signature_key($this->pollysecret, $datestamp, $this->pollyregion, 'polly');
        $signature = hash_hmac('sha256', $stringtosign, $signingkey);
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->pollyaccess,
            $credentialscope,
            $signedheaders,
            $signature
        );

        $headers = [
            'Accept: audio/mpeg',
            'Content-Type: application/json',
            'Host: ' . $host,
            'X-Amz-Date: ' . $amzdate,
            'X-Amz-Content-Sha256: ' . $payloadhash,
            'Authorization: ' . $authorization,
        ];

        $curl = new \curl();
        $response = $curl->post($endpoint, $body, [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        if ($response === false) {
            throw new moodle_exception('tts_http_error', 'mod_flashcards', '', null, $curl->error);
        }
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] >= 400) {
            throw new moodle_exception('tts_http_error', 'mod_flashcards', '', null, 'HTTP ' . $info['http_code'] . ': ' . $response);
        }

        return $response;
    }

    protected function build_signature_key(string $secret, string $date, string $region, string $service): string {
        $kdate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kregion = hash_hmac('sha256', $region, $kdate, true);
        $kservice = hash_hmac('sha256', $service, $kregion, true);
        return hash_hmac('sha256', 'aws4_request', $kservice, true);
    }

    protected function build_filename(string $label, string $voice, string $text, string $provider, array $meta = []): string {
        $providerSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($provider));
        $providerSlug = trim($providerSlug, '-') ?: 'tts';
        $labelSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($label));
        $labelSlug = trim($labelSlug, '-') ?: 'front';
        $voiceSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($voice));
        $voiceSlug = trim($voiceSlug, '-') ?: 'voice';
        $metapart = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $hash = substr(sha1($provider . '|' . $voice . '|' . $label . '|' . $metapart . '|' . $text), 0, 12);
        return "tts_{$providerSlug}_{$labelSlug}_{$voiceSlug}_{$hash}.mp3";
    }

    protected function get_eleven_model_for_request(string $label, string $text): string {
        $label = strtolower(trim($label));
        if ($label === 'focus') {
            return $this->elevenfocusmodel;
        }
        if ($this->count_words($text) <= 2) {
            return $this->elevenfocusmodel;
        }
        return $this->elevenmodel;
    }

    /**
     * Eleven v3 (alpha) is sensitive to text structure; keep focus delivery stable and slightly slower.
     *
     * @return array{stability:float,similarity_boost:float,style:float,speed:float,use_speaker_boost:bool}
     */
    protected function get_eleven_voice_settings_for_label(string $label): array {
        $label = strtolower(trim($label));
        if ($label === 'focus') {
            return [
                'stability' => max(0.0, min(1.0, $this->focusstability)),
                'similarity_boost' => 0.4,
                'style' => 0,
                'speed' => max(0.0, min(2.0, $this->focusspeed)),
                'use_speaker_boost' => false,
            ];
        }
        return [
            'stability' => 0.8,
            'similarity_boost' => 0.4,
            'style' => 0,
            'speed' => 0.9,
            'use_speaker_boost' => false,
        ];
    }

    protected function format_file_response(context_user $context, int $userid, string $filename, ?string $voice, string $provider): array {
        $url = moodle_url::make_pluginfile_url($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)->out(false);
        return [
            'url' => $url,
            'name' => $filename,
            'voice' => $voice,
            'provider' => $provider,
        ];
    }

    protected function resolve_provider(string $text, ?string $preferred): string {
        $preferred = strtolower(trim((string)$preferred));
        if ($preferred === self::PROVIDER_POLLY && $this->pollyenabled) {
            return self::PROVIDER_POLLY;
        }
        if ($preferred === self::PROVIDER_ELEVENLABS && $this->elevenenabled) {
            return self::PROVIDER_ELEVENLABS;
        }

        $wordcount = $this->count_words($text);
        if ($wordcount <= 2) {
            if ($this->shortprovider === self::PROVIDER_POLLY && $this->pollyenabled) {
                return self::PROVIDER_POLLY;
            }
            if ($this->shortprovider === self::PROVIDER_ELEVENLABS && $this->elevenenabled) {
                return self::PROVIDER_ELEVENLABS;
            }
            // Legacy: short prompts prefer Polly when available.
            if ($this->shortprovider === 'auto') {
                if ($this->pollyenabled) {
                    return self::PROVIDER_POLLY;
                }
                if ($this->elevenenabled) {
                    return self::PROVIDER_ELEVENLABS;
                }
            }
            // Fallback to any configured provider.
            if ($this->elevenenabled) {
                return self::PROVIDER_ELEVENLABS;
            }
            if ($this->pollyenabled) {
                return self::PROVIDER_POLLY;
            }
        }
        if ($this->elevenenabled) {
            return self::PROVIDER_ELEVENLABS;
        }
        if ($this->pollyenabled) {
            return self::PROVIDER_POLLY;
        }

        throw new coding_exception('No TTS providers are configured');
    }

    protected function record_tts_usage(int $userid, string $text, string $provider): void {
        global $DB;
        if ($userid <= 0) {
            return;
        }
        $tokens = $this->estimate_tts_tokens($text);
        if ($tokens <= 0) {
            return;
        }
        $period = $this->current_period_start();
        $now = time();

        $existing = $DB->get_record('flashcards_tts_usage', [
            'userid' => $userid,
            'provider' => $provider,
            'period_start' => $period,
        ], '*', IGNORE_MISSING);

        if ($existing) {
            $existing->characters += $tokens;
            $existing->requests += 1;
            $existing->timemodified = $now;
            $DB->update_record('flashcards_tts_usage', $existing);
        } else {
            $record = (object)[
                'userid' => $userid,
                'provider' => $provider,
                'period_start' => $period,
                'characters' => $tokens,
                'requests' => 1,
                'timemodified' => $now,
            ];
            $DB->insert_record('flashcards_tts_usage', $record);
        }
    }

    protected function estimate_tts_tokens(string $text): int {
        // ElevenLabs charges by character count
        $clean = trim(preg_replace('/\s+/u', ' ', $text));
        if ($clean === '') {
            return 0;
        }
        return mb_strlen($clean, 'UTF-8');
    }

    protected function current_period_start(): int {
        $year = (int)gmdate('Y');
        $month = (int)gmdate('n');
        return gmmktime(0, 0, 0, $month, 1, $year);
    }

    protected function get_usage(int $userid, string $provider): ?\stdClass {
        global $DB;
        if ($userid <= 0) {
            return null;
        }
        return $DB->get_record('flashcards_tts_usage', [
            'userid' => $userid,
            'provider' => $provider,
            'period_start' => $this->current_period_start(),
        ], '*', IGNORE_MISSING) ?: null;
    }

    protected function get_limit_for_provider(string $provider): int {
        if ($provider === self::PROVIDER_ELEVENLABS) {
            return $this->elevenlimit;
        }
        if ($provider === self::PROVIDER_POLLY) {
            return $this->pollylimit;
        }
        return 0;
    }

    protected function get_global_limit_for_provider(string $provider): int {
        if ($provider === self::PROVIDER_ELEVENLABS) {
            return $this->elevenlimitglobal;
        }
        if ($provider === self::PROVIDER_POLLY) {
            return $this->pollylimitglobal;
        }
        return 0;
    }

    protected function get_global_usage(string $provider): int {
        global $DB;
        if (isset($this->globalusagecache[$provider])) {
            return $this->globalusagecache[$provider];
        }
        $sql = 'SELECT COALESCE(SUM(characters), 0) AS used
                  FROM {flashcards_tts_usage}
                 WHERE provider = :provider AND period_start = :period';
        $params = [
            'provider' => $provider,
            'period' => $this->current_period_start(),
        ];
        $used = (int)($DB->get_field_sql($sql, $params) ?? 0);
        $this->globalusagecache[$provider] = $used;
        return $used;
    }

    protected function would_exceed_limit(int $userid, string $provider, int $tokens): bool {
        $limit = $this->get_limit_for_provider($provider);
        if ($limit > 0 && $userid > 0) {
            $usage = $this->get_usage($userid, $provider);
            $used = $usage ? (int)$usage->characters : 0;
            if (($used + $tokens) > $limit) {
                return true;
            }
        }
        $globallimit = $this->get_global_limit_for_provider($provider);
        if ($globallimit > 0) {
            $globalused = $this->get_global_usage($provider);
            if (($globalused + $tokens) > $globallimit) {
                return true;
            }
        }
        return false;
    }

    protected function choose_provider_with_limits(int $userid, string $text, ?string $preferred, int $tokens): string {
        $provider = $this->resolve_provider($text, $preferred);

        if ($provider === self::PROVIDER_ELEVENLABS && $this->would_exceed_limit($userid, self::PROVIDER_ELEVENLABS, $tokens)) {
            if ($this->pollyenabled && !$this->would_exceed_limit($userid, self::PROVIDER_POLLY, $tokens)) {
                return self::PROVIDER_POLLY;
            }
            throw new moodle_exception('error_tts_quota', 'mod_flashcards', '', 'ElevenLabs');
        }

        if ($provider === self::PROVIDER_POLLY && $this->would_exceed_limit($userid, self::PROVIDER_POLLY, $tokens)) {
            if ($this->elevenenabled && !$this->would_exceed_limit($userid, self::PROVIDER_ELEVENLABS, $tokens)) {
                return self::PROVIDER_ELEVENLABS;
            }
            throw new moodle_exception('error_tts_quota', 'mod_flashcards', '', 'Amazon Polly');
        }

        return $provider;
    }

    protected function count_words(string $text): int {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/[^\p{L}\p{N}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function resolve_polly_voice(?string $elevenvoice): string {
        $elevenvoice = trim((string)$elevenvoice);
        if ($elevenvoice !== '' && isset($this->pollyoverrides[$elevenvoice])) {
            return $this->pollyoverrides[$elevenvoice];
        }
        if (!empty($this->pollyvoice)) {
            return $this->pollyvoice;
        }
        return '';
    }

    protected function parse_voice_overrides(string $raw): array {
        $map = [];
        if ($raw === '') {
            return $map;
        }
        $lines = preg_split("/\r?\n/", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$eleven, $polly] = array_map('trim', explode('=', $line, 2));
            if ($eleven !== '' && $polly !== '') {
                $map[$eleven] = $polly;
            }
        }
        return $map;
    }

    protected function store_audio_file(context_user $context, int $userid, string $filename, string $contents): \stored_file {
        $fs = get_file_storage();
        if ($existing = $fs->get_file($context->id, 'mod_flashcards', 'media', $userid, '/', $filename)) {
            $existing->delete();
        }
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_flashcards',
            'filearea' => 'media',
            'itemid' => $userid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        return $fs->create_file_from_string($filerecord, $contents);
    }
}
