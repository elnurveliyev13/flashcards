<?php

defined('MOODLE_INTERNAL') || die();

function flashcards_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:       return MOD_ARCHETYPE_OTHER;
        case FEATURE_BACKUP_MOODLE2:      return true;
        case FEATURE_SHOW_DESCRIPTION:    return true;
        default:                          return null;
    }
}

function flashcards_add_instance($data, $mform = null) {
    global $DB, $USER;
    $record = new stdClass();
    $record->course       = $data->course;
    $record->name         = $data->name;
    $record->intro        = $data->intro ?? '';
    $record->introformat  = $data->introformat ?? FORMAT_HTML;
    $record->timecreated  = time();
    $record->timemodified = time();
    return $DB->insert_record('flashcards', $record);
}

function flashcards_update_instance($data, $mform = null) {
    global $DB;
    $record = $DB->get_record('flashcards', ['id' => $data->instance], '*', MUST_EXIST);
    $record->name         = $data->name;
    $record->intro        = $data->intro ?? '';
    $record->introformat  = $data->introformat ?? FORMAT_HTML;
    $record->timemodified = time();
    return $DB->update_record('flashcards', $record);
}

function flashcards_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('flashcards', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('flashcards', ['id' => $id]);
    return true;
}

/**
 * Serves media files for flashcards plugin.
 *
 * Supports two contexts:
 * - User context (context_user): For cards created in global mode
 * - Module context (context_module): For cards created in activity mode
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param stdClass $context Context object
 * @param string $filearea File area
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return bool False if file not found, does not return if found (sends file)
 */
function mod_flashcards_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $DB, $USER;

    // Allow 'media' filearea only
    if ($filearea !== 'media') {
        return false;
    }

    // Require login for all files
    require_login();

    // Extract file info from args (do this ONCE at the beginning)
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/'.implode('/', $args).'/' : '/';

    // Debug logging (remove after fixing)
    debugging(sprintf(
        'Flashcards pluginfile: contextid=%d, contextlevel=%d, itemid=%d, filename=%s, userid=%d',
        $context->id, $context->contextlevel, $itemid, $filename, $USER->id
    ), DEBUG_DEVELOPER);

    // Handle both user context (global mode) and module context (activity mode)
    if ($context->contextlevel == CONTEXT_USER) {
        // Global mode: Files stored in user context
        // GLOBAL DECK FIX: In global mode, files are stored in the CREATOR's user context
        // but should be accessible by ANY authenticated user who owns that card
        // (cards are separated by ownerid field in flashcards_cards table)

        // itemid in user context = userid of file creator
        // Allow access if:
        // 1. User is accessing their own files (itemid == $USER->id), OR
        // 2. User is admin, OR
        // 3. File belongs to a card owned by current user (check via filename)

        if ($itemid == $USER->id || is_siteadmin()) {
            // User accessing their own files or admin - allow
            // (Files are stored in creator's context but belong to their cards)
        } else {
            // User trying to access another user's context
            // Check if this file belongs to a card owned by current user OR is shared
            $card = $DB->get_record_sql(
                "SELECT c.* FROM {flashcards_cards} c
                 WHERE c.payload LIKE ?
                   AND ((c.scope = 'private' AND c.ownerid = ?)
                        OR c.scope = 'shared')",
                ['%'.$filename.'%', $USER->id]
            );

            if (!$card) {
                // Not user's card and not shared, deny access
                debugging('Access denied: File does not belong to user or shared card', DEBUG_DEVELOPER);
                send_file_not_found();
            }
        }

    } else if ($context->contextlevel == CONTEXT_MODULE) {
        // Activity mode: Files stored in module context
        // Check capability to view flashcards
        require_capability('mod/flashcards:view', $context);

    } else {
        // Invalid context level
        debugging('Invalid context level: ' . $context->contextlevel, DEBUG_DEVELOPER);
        send_file_not_found();
    }

    // Retrieve file from Moodle file storage
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_flashcards', $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        debugging('File not found in storage: ' . $filename, DEBUG_DEVELOPER);
        send_file_not_found();
    }

    // Send the file
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Provide non-sensitive runtime configuration for the JS app.
 */
defined('FLASHCARDS_OCR_UPLOAD_LIMIT_BYTES') || define('FLASHCARDS_OCR_UPLOAD_LIMIT_BYTES', 6 * 1024 * 1024);

function mod_flashcards_get_usage_snapshot(?int $userid = null): array {
    global $USER;
    $targetid = $userid ?? ($USER->id ?? 0);
    if ($targetid <= 0) {
        return [
            'openaiTokens' => 0,
            'ocrTokens' => 0,
            'elevenlabsTtsTokens' => 0,
            'elevenlabsSttTokens' => 0,
            'whisperSttTokens' => 0,
        ];
    }

    $suffix = date('Ym');
    $fetch = function(string $name) use ($targetid): int {
        return max(0, (int)get_user_preferences($name, 0, $targetid));
    };

    return [
        'openaiTokens' => $fetch('mod_flashcards_openai_tokens_' . $suffix),
        'ocrTokens' => $fetch('mod_flashcards_googlevision_' . $suffix),
        'elevenlabsTtsTokens' => $fetch('mod_flashcards_elevenlabs_tts_' . $suffix),
        'elevenlabsSttTokens' => $fetch('mod_flashcards_elevenlabs_stt_' . $suffix),
        'whisperSttTokens' => $fetch('mod_flashcards_whisper_' . $suffix),
    ];
}

function mod_flashcards_get_runtime_config(): array {
    global $USER;
    $config = get_config('mod_flashcards');
    $elevenenabled = !empty($config->elevenlabs_apikey);
    $pollyaccess = trim($config->amazonpolly_access_key ?? '') ?: getenv('FLASHCARDS_POLLY_KEY') ?: '';
    $pollysecret = trim($config->amazonpolly_secret_key ?? '') ?: getenv('FLASHCARDS_POLLY_SECRET') ?: '';
    $pollyenabled = ($pollyaccess !== '' && $pollysecret !== '');
    // Whisper STT config
    $whisperkey = trim($config->whisper_apikey ?? '') ?: getenv('FLASHCARDS_WHISPER_KEY') ?: '';
    $whisperenabled = !empty($config->whisper_enabled) && $whisperkey !== '';
    $whisperclip = max(1, (int)($config->whisper_clip_limit ?? 15));
    $whispermonthly = max($whisperclip, (int)($config->whisper_monthly_limit ?? 36000));
    $whisperlang = trim($config->whisper_language ?? '') ?: 'nb';
    $whispertimeout = max(5, (int)($config->whisper_timeout ?? 45));

    // ElevenLabs STT config
    $elevenlabssttkey = trim($config->elevenlabs_stt_apikey ?? '')
        ?: trim($config->elevenlabs_apikey ?? '')
        ?: getenv('FLASHCARDS_ELEVENLABS_KEY') ?: '';
    $elevenlabssttenabled = !empty($config->elevenlabs_stt_enabled) && $elevenlabssttkey !== '';
    $elevenlabssttclip = max(1, (int)($config->elevenlabs_stt_clip_limit ?? 15));
    $elevenlabssttmonthly = max($elevenlabssttclip, (int)($config->elevenlabs_stt_monthly_limit ?? 36000));
    $elevenlabssttlang = trim($config->elevenlabs_stt_language ?? '') ?: 'nb';
    $elevenlabsstttimeout = max(5, (int)($config->elevenlabs_stt_timeout ?? 45));

    // Determine active STT provider
    $sttprovider = trim($config->stt_provider ?? '') ?: 'whisper';
    $sttenabled = false;
    $sttclip = 15;
    $sttmonthly = 36000;
    $sttlang = 'nb';
    $stttimeout = 45;

    if ($sttprovider === 'elevenlabs' && $elevenlabssttenabled) {
        $sttenabled = true;
        $sttclip = $elevenlabssttclip;
        $sttmonthly = $elevenlabssttmonthly;
        $sttlang = $elevenlabssttlang;
        $stttimeout = $elevenlabsstttimeout;
    } else if ($whisperenabled) {
        $sttprovider = 'whisper';
        $sttenabled = true;
        $sttclip = $whisperclip;
        $sttmonthly = $whispermonthly;
        $sttlang = $whisperlang;
        $stttimeout = $whispertimeout;
    } else if ($elevenlabssttenabled) {
        $sttprovider = 'elevenlabs';
        $sttenabled = true;
        $sttclip = $elevenlabssttclip;
        $sttmonthly = $elevenlabssttmonthly;
        $sttlang = $elevenlabssttlang;
        $stttimeout = $elevenlabsstttimeout;
    }
    $googlevisionkey = trim($config->googlevision_api_key ?? '') ?: getenv('FLASHCARDS_GOOGLEVISION_KEY') ?: '';
    $googlevisionEnabled = !empty($config->googlevision_enabled) && !empty($googlevisionkey);
    $googlevisionLanguage = trim($config->googlevision_language ?? '') ?: 'en';
    $googlevisionMonthly = max(1, (int)($config->googlevision_monthly_limit ?? 120));
    $googlevisionTimeout = max(5, (int)($config->googlevision_timeout ?? 45));
    $voices = [];
    $rawvoicemap = $config->elevenlabs_voice_map ?? '';
    if ($rawvoicemap !== '') {
        $lines = preg_split("/\r?\n/", $rawvoicemap);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$label, $voice] = array_map('trim', explode('=', $line, 2));
            if ($label !== '' && $voice !== '') {
                $voices[] = [
                    'label' => $label,
                    'voice' => $voice,
                ];
            }
        }
    }

    return [
        'ai' => [
            'enabled' => !empty($config->ai_focus_enabled) && !empty($config->openai_apikey),
            'ttsEnabled' => $elevenenabled || $pollyenabled,
            'ttsProviders' => [
                'elevenlabs' => $elevenenabled,
                'polly' => $pollyenabled,
            ],
            'defaultVoice' => $config->elevenlabs_default_voice ?? '',
            'voices' => $voices,
            'dictionaryEnabled' => !empty($config->orbokene_enabled),
        ],
        'stt' => [
            'enabled' => $sttenabled,
            'provider' => $sttprovider,
            'language' => $sttlang,
            'clipLimit' => $sttclip,
            'monthlyLimit' => $sttmonthly,
            'timeout' => $stttimeout,
        ],
        'ocr' => [
            'enabled' => $googlevisionEnabled,
            'language' => $googlevisionLanguage,
            'maxFileSize' => FLASHCARDS_OCR_UPLOAD_LIMIT_BYTES,
            'timeout' => $googlevisionTimeout,
            'monthlyLimit' => $googlevisionMonthly,
        ],
        'usage' => mod_flashcards_get_usage_snapshot($USER->id ?? null),
    ];
}
