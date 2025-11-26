<?php
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/local/ordbank_helper.php');

$cmid = optional_param('cmid', 0, PARAM_INT); // CHANGED: optional for global mode
$action = required_param('action', PARAM_ALPHANUMEXT);

require_sesskey();

// Global mode: no specific activity context
$globalmode = ($cmid === 0);

if ($globalmode) {
    // Global access mode - check via access_manager
    require_login(null, false); // Do not allow guests

    // Block guest users
    if (isguestuser()) {
        throw new require_login_exception('Guests are not allowed to access flashcards');
    }

    $context = context_system::instance();
    $access = \mod_flashcards\access_manager::check_user_access($USER->id);

    // Check permissions based on action
    if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media' || $action === 'transcribe_audio' || $action === 'recognize_image' || $action === 'ai_focus_helper' || $action === 'ai_translate' || $action === 'ai_question') {
        // Allow site administrators and managers regardless of grace period/access
        $createallowed = !empty($access['can_create']);
        if (is_siteadmin() || has_capability('moodle/site:config', $context) || has_capability('moodle/course:manageactivities', $context)) {
            $createallowed = true;
        }
        if (!$createallowed) {
            throw new moodle_exception('access_create_blocked', 'mod_flashcards');
        }
    } else if ($action === 'fetch' || $action === 'get_due_cards' || $action === 'get_deck_cards' || $action === 'list_decks' || $action === 'ordbank_focus_helper') {
        if (!$access['can_view']) {
            throw new moodle_exception('access_denied', 'mod_flashcards');
        }
    } else if ($action === 'save' || $action === 'review_card') {
        if (!$access['can_review']) {
            throw new moodle_exception('access_denied', 'mod_flashcards');
        }
    }

    $flashcardsid = null; // No specific instance in global mode
    $cm = null;
    $course = null;
} else {
    // Activity-specific mode (legacy)
    [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'flashcards');
    $context = context_module::instance($cm->id);
    require_login($course, true, $cm);
    require_capability('mod/flashcards:view', $context);
    $flashcardsid = $cm->instance;
}

$userid = $USER->id;

switch ($action) {
    case 'fetch':
        echo json_encode([ 'ok' => true, 'data' => \mod_flashcards\local\api::fetch_progress($flashcardsid, $userid) ]);
        break;

    case 'save':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        \mod_flashcards\local\api::save_progress_batch($flashcardsid, $userid, $payload['records']);
        echo json_encode(['ok' => true]);
        break;

    case 'upload_media':
        // Accepts multipart/form-data with field 'file' and optional 'type' (image|audio).
        // Stores in Moodle file storage and returns a pluginfile URL.
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new invalid_parameter_exception('No file');
        }
        $type = optional_param('type', 'file', PARAM_ALPHA);
        $cardid = optional_param('cardid', '', PARAM_RAW_TRIMMED); // Card ID for unique file storage
        $originalname = clean_param($_FILES['file']['name'], PARAM_FILE);
        $fs = get_file_storage();

        // Generate UNIQUE filename based on cardid and type to prevent collisions
        // Format: {cardid}_{type}.{ext} or {timestamp}_{type}.{ext} if no cardid
        $extension = pathinfo($originalname, PATHINFO_EXTENSION);
        if (!$extension) {
            $extension = ($type === 'image') ? 'jpg' : 'webm';
        }

        if ($cardid) {
            // Use cardid in filename for uniqueness
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $cardid) . '_' . $type . '.' . $extension;
        } else {
            // Fallback: use timestamp
            $filename = time() . '_' . $type . '.' . $extension;
        }

        // Use simple itemid based on userid (all user's files in one itemid)
        $itemid = $userid;
        $filepath = '/';

        // IMPORTANT: ALWAYS use user context for file storage, even in activity mode!
        // Why? If activity is deleted, files in module context are deleted too.
        // But cards should persist - they belong to USER, not to specific activity.
        // User's files are stored in their user context and survive activity deletion.
        $filecontext = context_user::instance($userid);

        $fileinfo = [
            'contextid' => $filecontext->id,
            'component' => 'mod_flashcards',
            'filearea'  => 'media',
            'itemid'    => $itemid,
            'filepath'  => $filepath,
            'filename'  => $filename,
            'timecreated' => time(),
        ];
        // Remove existing with same name/path to allow overwrite (for re-uploads to same card).
        if ($existing = $fs->get_file($filecontext->id, 'mod_flashcards', 'media', $itemid, $filepath, $filename)) {
            $existing->delete();
        }
        $file = $fs->create_file_from_pathname($fileinfo, $_FILES['file']['tmp_name']);
        $url = moodle_url::make_pluginfile_url($filecontext->id, 'mod_flashcards', 'media', $itemid, $filepath, $filename)->out(false);

        // Debug logging
        debugging(sprintf(
            'File uploaded: contextid=%d, itemid=%d, filename=%s, filesize=%d, url=%s',
            $filecontext->id, $itemid, $filename, $file->get_filesize(), $url
        ), DEBUG_DEVELOPER);

        echo json_encode(['ok' => true, 'data' => ['url' => $url, 'type' => $type, 'name' => $filename]]);
        break;

    case 'transcribe_audio':
        $response = ['ok' => false];
        $tempfile = null;
        try {
            if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                throw new invalid_parameter_exception('No file');
            }
            $maxsize = 8 * 1024 * 1024; // 8 MB safety cap.
            $filesize = (int)($_FILES['file']['size'] ?? 0);
            if ($filesize <= 0) {
                throw new invalid_parameter_exception('Invalid file size');
            }
            if ($filesize > $maxsize) {
                throw new moodle_exception('error_whisper_filesize', 'mod_flashcards', '', display_size($maxsize));
            }

            $duration = (int)round(optional_param('duration', 0, PARAM_FLOAT));
            $language = trim(optional_param('language', '', PARAM_ALPHANUMEXT));
            $originalname = clean_param($_FILES['file']['name'] ?? 'audio.webm', PARAM_FILE);
            $mimetype = clean_param($_FILES['file']['type'] ?? '', PARAM_RAW_TRIMMED);

            $basedir = make_temp_directory('mod_flashcards');
            $tempfile = tempnam($basedir, 'stt');
            if ($tempfile === false) {
                throw new moodle_exception('error_stt_upload', 'mod_flashcards');
            }
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempfile)) {
                throw new moodle_exception('error_stt_upload', 'mod_flashcards');
            }

            // Determine which STT provider to use
            $config = get_config('mod_flashcards');
            $sttprovider = trim($config->stt_provider ?? '') ?: 'whisper';

            // Check provider availability and fallback logic
            $whisperkey = trim($config->whisper_apikey ?? '') ?: getenv('FLASHCARDS_WHISPER_KEY') ?: '';
            $whisperenabled = !empty($config->whisper_enabled) && $whisperkey !== '';

            $elevenlabssttkey = trim($config->elevenlabs_stt_apikey ?? '')
                ?: trim($config->elevenlabs_apikey ?? '')
                ?: getenv('FLASHCARDS_ELEVENLABS_KEY') ?: '';
            $elevenlabssttenabled = !empty($config->elevenlabs_stt_enabled) && $elevenlabssttkey !== '';

            // Select client based on provider
            if ($sttprovider === 'elevenlabs' && $elevenlabssttenabled) {
                $client = new \mod_flashcards\local\elevenlabs_stt_client();
            } else if ($whisperenabled) {
                $client = new \mod_flashcards\local\whisper_client();
            } else if ($elevenlabssttenabled) {
                $client = new \mod_flashcards\local\elevenlabs_stt_client();
            } else {
                throw new moodle_exception('error_stt_disabled', 'mod_flashcards');
            }

            $text = $client->transcribe(
                $tempfile,
                $originalname,
                $mimetype ?: mime_content_type($tempfile) ?: 'application/octet-stream',
                $userid,
                $duration,
                $language !== '' ? $language : null
            );
            $response = ['ok' => true, 'data' => [
                'text' => $text,
                'usage' => mod_flashcards_get_usage_snapshot($userid),
            ]];
            http_response_code(200);
        } catch (\moodle_exception $ex) {
            http_response_code(400);
            $response = [
                'ok' => false,
                'error' => $ex->getMessage(),
                'errorcode' => $ex->errorcode,
            ];
        } catch (\Throwable $ex) {
            http_response_code(400);
            debugging('STT transcription failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $response = [
                'ok' => false,
                'error' => get_string('error_stt_api', 'mod_flashcards', $ex->getMessage()),
                'errorcode' => 'unknown',
            ];
        } finally {
            if ($tempfile && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        break;

    case 'recognize_image':
        $response = ['ok' => false];
        $tempfile = null;
        try {
            if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                throw new invalid_parameter_exception('No file');
            }
            $maxsize = FLASHCARDS_OCR_UPLOAD_LIMIT_BYTES;
            $filesize = (int)($_FILES['file']['size'] ?? 0);
            if ($filesize <= 0) {
                throw new invalid_parameter_exception('Invalid file size');
            }
            if ($filesize > $maxsize) {
                throw new moodle_exception('error_ocr_filesize', 'mod_flashcards', '', display_size($maxsize));
            }

            $originalname = clean_param($_FILES['file']['name'] ?? 'ocr.png', PARAM_FILE);
            $mimetype = clean_param($_FILES['file']['type'] ?? '', PARAM_RAW_TRIMMED);

            $basedir = make_temp_directory('mod_flashcards');
            $tempfile = tempnam($basedir, 'ocr');
            if ($tempfile === false) {
                throw new moodle_exception('error_ocr_upload', 'mod_flashcards');
            }
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempfile)) {
                throw new moodle_exception('error_ocr_upload', 'mod_flashcards');
            }

            $client = new \mod_flashcards\local\ocr_client();
            $text = $client->recognize($tempfile, $originalname, $mimetype, $userid);
            $response = ['ok' => true, 'data' => ['text' => $text]];
            http_response_code(200);
        } catch (\moodle_exception $ex) {
            http_response_code(400);
            $response = [
                'ok' => false,
                'error' => $ex->getMessage(),
                'errorcode' => $ex->errorcode,
            ];
        } catch (\Throwable $ex) {
            http_response_code(400);
            debugging('OCR recognition failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $response = [
                'ok' => false,
                'error' => get_string('error_ocr_api', 'mod_flashcards', $ex->getMessage()),
                'errorcode' => 'unknown',
            ];
        } finally {
            if ($tempfile && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        break;

    // --- Decks & Cards CRUD ---
    case 'list_decks':
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::list_decks($userid, $globalmode)]);
        break;
    case 'create_deck':
        if (!$globalmode) {
            require_capability('moodle/course:manageactivities', $context);
        }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $title = clean_param($payload['title'] ?? '', PARAM_TEXT);
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::create_deck($userid, $title, $globalmode)]);
        break;
    case 'get_deck_cards':
        $deckid = required_param('deckid', PARAM_INT);
        $offset = optional_param('offset', 0, PARAM_INT);
        $limit = optional_param('limit', 100, PARAM_INT);
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::get_deck_cards($userid, $deckid, $offset, $limit, $globalmode)]);
        break;
    case 'get_due_cards':
        // Get only cards that are due today (optimized for performance)
        $limit = optional_param('limit', 1000, PARAM_INT); // Increased from 100 to 1000
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::get_due_cards_optimized($userid, $flashcardsid, $limit, $globalmode)]);
        break;
    case 'upsert_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $result = \mod_flashcards\local\api::upsert_card($userid, $payload, $globalmode, $context);
        echo json_encode(['ok' => true, 'data' => $result]);
        break;
    case 'delete_card':
        $deckid = required_param('deckid', PARAM_RAW_TRIMMED); // May be string or int
        $cardid = required_param('cardid', PARAM_RAW_TRIMMED);
        // Convert to int if numeric
        if (is_numeric($deckid)) {
            $deckid = (int)$deckid;
        }
        \mod_flashcards\local\api::delete_card($userid, $deckid, $cardid, $globalmode, $context);
        echo json_encode(['ok' => true]);
        break;

    // --- SRS queue (fixed intervals) ---
    case 'review_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $deckid = (int)($payload['deckId'] ?? 0);
        $cardid = clean_param($payload['cardId'] ?? '', PARAM_RAW_TRIMMED);
        $rating = (int)($payload['rating'] ?? 0); // 1=hard, 2=normal, 3=easy
        \mod_flashcards\local\api::review_card($globalmode ? null : $cm, $userid, $flashcardsid, $deckid, $cardid, $rating, $globalmode);
        echo json_encode(['ok' => true]);
        break;

    // --- Dashboard & Statistics ---
    case 'get_dashboard_data':
        $data = \mod_flashcards\local\api::get_dashboard_data($userid);
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'recalculate_stats':
        $actual_count = \mod_flashcards\local\api::recalculate_total_cards($userid);
        $active_vocab = \mod_flashcards\local\api::calculate_active_vocab($userid);
        echo json_encode(['ok' => true, 'data' => [
            'totalCardsCreated' => $actual_count,
            'activeVocab' => round($active_vocab, 2),
        ]]);
        break;

    case 'ai_focus_helper':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $clickedword = trim($payload['focusWord'] ?? '');
        if ($fronttext === '' || $clickedword === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);
        $level = strtoupper(clean_param($payload['level'] ?? '', PARAM_ALPHANUMEXT));
        if (!in_array($level, ['A1', 'A2', 'B1'], true)) {
            $level = '';
        }
        $voiceid = clean_param($payload['voiceId'] ?? '', PARAM_ALPHANUMEXT);
        $helper = new \mod_flashcards\local\ai_helper();
        $data = $helper->process_focus_request($userid, $fronttext, $clickedword, [
            'language' => $language,
            'level' => $level,
            'voice' => $voiceid ?: null,
        ]);
        $data['usage'] = mod_flashcards_get_usage_snapshot($userid);
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'ordbank_focus_helper':
        try {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                throw new invalid_parameter_exception('Invalid payload');
            }
            $word = trim($payload['word'] ?? '');
            $prev = trim($payload['prev'] ?? '');
            $next = trim($payload['next'] ?? '');
            if ($word === '') {
                throw new invalid_parameter_exception('Missing word');
            }
            $context = [];
            if ($prev !== '') {
                $context['prev'] = $prev;
            }
            if ($next !== '') {
                $context['next'] = $next;
            }
            // Debug: check how many raw matches exist
            $debug = [];
            try {
                $debug['fullform_count'] = $DB->count_records_select('ordbank_fullform', 'LOWER(OPPSLAG)=?', [core_text::strtolower($word)]);
                $sample = $DB->get_records_sql("SELECT LEMMA_ID, OPPSLAG, TAG FROM {ordbank_fullform} WHERE LOWER(OPPSLAG)=:w LIMIT 5", ['w' => core_text::strtolower($word)]);
                $debug['fullform_sample'] = array_values($sample);
            } catch (\Throwable $dbgex) {
                $debug['fullform_count_error'] = $dbgex->getMessage();
            }
            $data = \mod_flashcards\local\ordbank_helper::analyze_token($word, $context);
            if (!$data && !empty($debug['fullform_sample'])) {
                // Fallback: return first sample as a minimal candidate to unblock UI
                $first = $debug['fullform_sample'][0];
                $data = [
                    'token' => $word,
                    'selected' => [
                        'lemma_id' => (int)($first->lemma_id ?? 0),
                        'wordform' => $first->oppslag ?? $word,
                        'tag' => $first->tag ?? '',
                        'paradigme_id' => null,
                        'boy_nummer' => 0,
                        'ipa' => null,
                    ],
                    'candidates' => [$first],
                    'paradigm' => [],
                    'parts' => [$first->oppslag ?? $word],
                ];
            }
            if (!$data) {
                echo json_encode(['ok' => false, 'error' => 'No matches found in ordbank', 'debug' => $debug]);
            } else {
                echo json_encode(['ok' => true, 'data' => $data, 'debug' => $debug]);
            }
        } catch (\Throwable $ex) {
            debugging('[flashcards] ordbank_focus_helper failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        }
        break;

    case 'ai_translate':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $source = clean_param($payload['sourceLang'] ?? 'no', PARAM_ALPHANUMEXT);
        $target = clean_param($payload['targetLang'] ?? 'en', PARAM_ALPHANUMEXT);
        $direction = ($payload['direction'] ?? '') === 'user-no' ? 'user-no' : 'no-user';
        $helper = new \mod_flashcards\local\ai_helper();
        $data = $helper->translate_text($userid, $text, $source, $target, ['direction' => $direction]);
        $data['usage'] = mod_flashcards_get_usage_snapshot($userid);
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'ai_question':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $question = trim($payload['question'] ?? '');
        if ($fronttext === '' || $question === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $language = clean_param($payload['language'] ?? 'uk', PARAM_ALPHANUMEXT);
        $helper = new \mod_flashcards\local\ai_helper();
        $data = $helper->answer_question($userid, $fronttext, $question, ['language' => $language]);
        $data['usage'] = mod_flashcards_get_usage_snapshot($userid);
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'push_subscribe':
        // Register push notification subscription
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['subscription'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $sub = $payload['subscription'];
        if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            throw new invalid_parameter_exception('Invalid subscription format');
        }
        $lang = clean_param($payload['lang'] ?? 'en', PARAM_ALPHANUMEXT);
        $now = time();

        // Check if subscription with same endpoint exists for this user
        $existing = $DB->get_record('flashcards_push_subs', [
            'userid' => $userid,
            'endpoint' => $sub['endpoint']
        ]);

        if ($existing) {
            // Update existing subscription
            $existing->p256dh = $sub['keys']['p256dh'];
            $existing->auth = $sub['keys']['auth'];
            $existing->lang = $lang;
            $existing->enabled = 1;
            $existing->timemodified = $now;
            $DB->update_record('flashcards_push_subs', $existing);
            $subid = $existing->id;
        } else {
            // Create new subscription
            $record = (object)[
                'userid' => $userid,
                'endpoint' => $sub['endpoint'],
                'p256dh' => $sub['keys']['p256dh'],
                'auth' => $sub['keys']['auth'],
                'lang' => $lang,
                'enabled' => 1,
                'timecreated' => $now,
                'timemodified' => $now
            ];
            $subid = $DB->insert_record('flashcards_push_subs', $record);
        }

        echo json_encode(['ok' => true, 'data' => ['id' => $subid]]);
        break;

    case 'push_unsubscribe':
        // Remove push notification subscription
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['endpoint'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $endpoint = $payload['endpoint'];

        // Delete subscription for this user with matching endpoint
        $DB->delete_records('flashcards_push_subs', [
            'userid' => $userid,
            'endpoint' => $endpoint
        ]);

        echo json_encode(['ok' => true]);
        break;

    case 'push_update_lang':
        // Update language preference for all user's subscriptions
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['lang'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $lang = clean_param($payload['lang'], PARAM_ALPHANUMEXT);

        $DB->execute(
            "UPDATE {flashcards_push_subs} SET lang = ?, timemodified = ? WHERE userid = ?",
            [$lang, time(), $userid]
        );

        echo json_encode(['ok' => true]);
        break;

    case 'get_vapid_key':
        // Return VAPID public key for push subscription
        $config = get_config('mod_flashcards');
        $vapidpublic = trim($config->vapid_public_key ?? '');
        if ($vapidpublic === '') {
            throw new moodle_exception('Push notifications not configured');
        }
        echo json_encode(['ok' => true, 'data' => ['publicKey' => $vapidpublic]]);
        break;

    default:
        throw new moodle_exception('invalidaction');
}
