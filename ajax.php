<?php
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

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
    if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media') {
        // Allow site administrators and managers regardless of grace period/access
        $createallowed = !empty($access['can_create']);
        if (is_siteadmin() || has_capability('moodle/site:config', $context) || has_capability('moodle/course:manageactivities', $context)) {
            $createallowed = true;
        }
        if (!$createallowed) {
            throw new moodle_exception('access_create_blocked', 'mod_flashcards');
        }
    } else if ($action === 'fetch' || $action === 'get_due_cards' || $action === 'get_deck_cards' || $action === 'list_decks') {
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

    default:
        throw new moodle_exception('invalidaction');
}

