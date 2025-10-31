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
        echo json_encode([ 'ok' => true, 'data' => fetch_progress($flashcardsid, $userid) ]);
        break;

    case 'save':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        save_progress_batch($flashcardsid, $userid, $payload['records']);
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
        echo json_encode(['ok' => true, 'data' => list_decks($userid, $globalmode)]);
        break;
    case 'create_deck':
        if (!$globalmode) {
            require_capability('moodle/course:manageactivities', $context);
        }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $title = clean_param($payload['title'] ?? '', PARAM_TEXT);
        echo json_encode(['ok' => true, 'data' => create_deck($userid, $title, $globalmode)]);
        break;
    case 'get_deck_cards':
        $deckid = required_param('deckid', PARAM_INT);
        $offset = optional_param('offset', 0, PARAM_INT);
        $limit = optional_param('limit', 100, PARAM_INT);
        echo json_encode(['ok' => true, 'data' => get_deck_cards($userid, $deckid, $offset, $limit, $globalmode)]);
        break;
    case 'get_due_cards':
        // Get only cards that are due today (optimized for performance)
        $limit = optional_param('limit', 1000, PARAM_INT); // Increased from 100 to 1000
        echo json_encode(['ok' => true, 'data' => get_due_cards_optimized($userid, $flashcardsid, $limit, $globalmode)]);
        break;
    case 'upsert_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $result = upsert_card($userid, $payload, $globalmode, $context);
        echo json_encode(['ok' => true, 'data' => $result]);
        break;
    case 'delete_card':
        $deckid = required_param('deckid', PARAM_RAW_TRIMMED); // May be string or int
        $cardid = required_param('cardid', PARAM_RAW_TRIMMED);
        // Convert to int if numeric
        if (is_numeric($deckid)) {
            $deckid = (int)$deckid;
        }
        delete_card($userid, $deckid, $cardid, $globalmode, $context);
        echo json_encode(['ok' => true]);
        break;

    // --- SRS queue (fixed intervals) ---
    case 'review_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $deckid = (int)($payload['deckId'] ?? 0);
        $cardid = clean_param($payload['cardId'] ?? '', PARAM_RAW_TRIMMED);
        $rating = (int)($payload['rating'] ?? 0); // 1=hard, 2=normal, 3=easy
        review_card($globalmode ? null : $cm, $userid, $flashcardsid, $deckid, $cardid, $rating, $globalmode);
        echo json_encode(['ok' => true]);
        break;

    default:
        throw new moodle_exception('invalidaction');
}

function fetch_progress($flashcardsid, $userid) {
    global $DB;

    // GLOBAL MODE FIX: In global mode (flashcardsid=null or 0), fetch ALL progress for user
    if ($flashcardsid === null || $flashcardsid === 0) {
        $recs = $DB->get_records('flashcards_progress', ['userid' => $userid]);
    } else {
        // Activity mode: fetch only for specific activity
        $recs = $DB->get_records('flashcards_progress', [
            'flashcardsid' => $flashcardsid,
            'userid' => $userid
        ]);
    }

    $out = [];
    foreach ($recs as $r) {
        if (!isset($out[$r->deckid])) $out[$r->deckid] = [];
        $out[$r->deckid][$r->cardid] = [
            'step' => (int)$r->step,
            'due' => (int)$r->due,
            'addedAt' => (int)$r->addedat,
            'lastAt' => (int)$r->lastat,
            'hidden' => (int)$r->hidden,
        ];
    }
    return $out;
}

function save_progress_batch($flashcardsid, $userid, array $records) {
    global $DB;
    $now = time();
    foreach ($records as $rec) {
        $deckid = clean_param($rec['deckId'] ?? '', PARAM_RAW_TRIMMED);
        $cardid = clean_param($rec['cardId'] ?? '', PARAM_RAW_TRIMMED);
        if ($deckid === '' || $cardid === '') { continue; }
        $data = new stdClass();
        $data->flashcardsid = $flashcardsid;
        $data->userid = $userid;
        $data->deckid = $deckid;
        $data->cardid = $cardid;
        $data->step = isset($rec['step']) ? (int)$rec['step'] : 0;
        $data->due = isset($rec['due']) ? (int)$rec['due'] : 0;
        $data->addedat = isset($rec['addedAt']) ? (int)$rec['addedAt'] : 0;
        $data->lastat = isset($rec['lastAt']) ? (int)$rec['lastAt'] : 0;
        // SYNC FIX: Accept hidden state from frontend (for shared cards user wants to hide)
        $data->hidden = empty($rec['hidden']) ? 0 : 1;
        $data->timemodified = $now;

        // PROGRESS SYNC FIX: Always find by userid+cardid only (regardless of deckid or flashcardsid)
        // This ensures progress syncs across all decks and activity/global pages
        $existing = $DB->get_record('flashcards_progress', [
            'userid' => $userid,
            'cardid' => $cardid,
        ]);

        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('flashcards_progress', $data);
        } else {
            $DB->insert_record('flashcards_progress', $data);
        }
    }
}

// ----------------- New helpers: decks/cards and SRS -----------------
function list_decks($userid, $globalmode = false) {
    global $DB;

    if ($globalmode) {
        // Global mode: return all decks (user's private + global "My cards" + shared from completed activities)
        $sql = "SELECT DISTINCT d.*
                FROM {flashcards_decks} d
                LEFT JOIN {flashcards_cards} c ON c.deckid = d.id
                WHERE (d.scope = 'private' AND d.userid = :userid)
                   OR (d.scope = 'private' AND d.userid IS NULL) /* Global 'My cards' deck */
                   OR d.scope = 'shared'
                ORDER BY d.title ASC";
        $recs = $DB->get_records_sql($sql, ['userid' => $userid]);
    } else {
        // Activity mode: return decks for specific course (legacy)
        // Note: $cm not available here, need to pass courseid instead
        throw new coding_exception('Activity mode not supported in list_decks - pass courseid');
    }

    $out = [];
    foreach ($recs as $d) {
        $out[] = [
            'id' => (int)$d->id,
            'title' => $d->title,
            'scope' => $d->scope ?? 'private'
        ];
    }
    return $out;
}

function create_deck($userid, $title, $globalmode = false) {
    global $DB;
    if ($title === '') { throw new invalid_parameter_exception('Missing title'); }
    $now = time();
    $rec = (object) [
        'courseid' => null, // Global decks don't belong to specific course
        'userid' => $userid,
        'scope' => 'private', // User-created decks are always private
        'title' => $title,
        'meta' => json_encode(new stdClass()),
        'createdby' => $userid,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $rec->id = $DB->insert_record('flashcards_decks', $rec);
    return ['id' => (int)$rec->id, 'title' => $rec->title, 'scope' => $rec->scope];
}

/**
 * Ensure progress records exist for user's cards
 * CRITICAL FIX: Cards without progress records don't appear in INNER JOIN queries
 *
 * @param int $userid User ID
 * @param int $deckid Deck ID
 * @param int|null $flashcardsid Activity instance ID (null for global mode)
 */
function ensure_progress_exists($userid, $deckid, $flashcardsid = null) {
    global $DB;

    // Find all cards in this deck that user should see
    $sql = "SELECT c.*
            FROM {flashcards_cards} c
            WHERE c.deckid = :deckid
              AND ((c.scope = 'private' AND c.ownerid = :ownerid)
                   OR c.scope = 'shared')";

    $cards = $DB->get_records_sql($sql, ['deckid' => $deckid, 'ownerid' => $userid]);

    $now = time();
    $created = 0;

    foreach ($cards as $card) {
        // Check if progress record exists
        $exists = $DB->record_exists('flashcards_progress', [
            'userid' => $userid,
            'deckid' => $deckid,
            'cardid' => $card->cardid
        ]);

        if (!$exists) {
            // Create initial progress record
            $progress = (object)[
                'flashcardsid' => $flashcardsid,
                'userid' => $userid,
                'deckid' => $deckid,
                'cardid' => $card->cardid,
                'step' => 0,
                'due' => $now, // Due immediately
                'addedat' => $now,
                'lastat' => 0,
                'hidden' => 0,
                'timemodified' => $now
            ];

            try {
                $DB->insert_record('flashcards_progress', $progress);
                $created++;
            } catch (Exception $e) {
                // Ignore duplicates (race condition)
                error_log("Failed to create progress for card {$card->cardid}: " . $e->getMessage());
            }
        }
    }

    if ($created > 0) {
        error_log("Flashcards: Created {$created} missing progress records for user {$userid} in deck {$deckid}");
    }
}

function get_deck_cards($userid, $deckid, $offset = 0, $limit = 100, $globalmode = false) {
    global $DB;
    // Verify deck exists (no course check in global mode)
    $DB->get_record('flashcards_decks', ['id' => $deckid], '*', MUST_EXIST);

    // CRITICAL FIX: Ensure progress records exist before querying
    ensure_progress_exists($userid, $deckid, null);

    // Get cards with pagination
    $sql = "SELECT * FROM {flashcards_cards} WHERE deckid = :deckid ORDER BY id ASC";
    $recs = $DB->get_records_sql($sql, ['deckid' => $deckid], $offset, $limit);

    // Preload translations
    $ids = array_map(function($r){return $r->cardid;}, $recs);
    $transmap = [];
    if (!empty($ids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_select('flashcards_card_trans', 'deckid = :deckid AND cardid ' . $insql, ['deckid' => $deckid] + $inparams);
        foreach ($rows as $row) {
            $transmap[$row->cardid][strtolower($row->lang)] = $row->text;
        }
    }

    $out = [];
    foreach ($recs as $r) {
        // In global mode, show shared cards + user's own private cards
        if ($r->scope === 'private' && (int)$r->ownerid !== (int)$userid) { continue; }
        $payload = json_decode($r->payload, true);
        if (!is_array($payload)) { $payload = []; }
        if (!empty($transmap[$r->cardid])) {
            $payload['translations'] = $transmap[$r->cardid];
        }
        $out[] = [
            'deckId' => (int)$r->deckid,
            'cardId' => $r->cardid,
            'scope' => $r->scope,
            'ownerid' => is_null($r->ownerid) ? null : (int)$r->ownerid,
            'payload' => $payload,
            'timemodified' => (int)$r->timemodified,
        ];
    }
    return $out;
}

function get_due_cards_optimized($userid, $flashcardsid = null, $limit = 1000, $globalmode = false) {
    global $DB;

    $now = time();

    if ($globalmode) {
        // Global mode: get all decks user has access to (their private + shared)
        // Get due cards with JOIN to progress table (optimized query)
        // GLOBAL MODE FIX: Removed flashcardsid filter to show old cards
        // GLOBAL DECK FIX: After migration, Deck 1 has userid=NULL (global deck for all users)
        // We need to allow decks where:
        //   - scope='private' AND userid=NULL (global "My cards" deck) OR
        //   - scope='private' AND userid=current_user (legacy user-specific deck) OR
        //   - scope='shared' (shared lesson decks)
        $sql = "SELECT c.*, p.step, p.due, p.addedat, p.lastat, d.scope as deck_scope
                FROM {flashcards_cards} c
                INNER JOIN {flashcards_progress} p ON p.deckid = c.deckid AND p.cardid = c.cardid
                INNER JOIN {flashcards_decks} d ON d.id = c.deckid
                WHERE p.userid = :userid
                  AND p.due <= :now
                  AND p.hidden = 0
                  AND ((d.scope = 'private' AND (d.userid IS NULL OR d.userid = :userid2))
                       OR d.scope = 'shared')
                  AND ((c.scope = 'private' AND c.ownerid = :ownerid)
                       OR c.scope = 'shared')
                ORDER BY p.due ASC, c.id ASC";

        $params = [
            'userid' => $userid,
            'userid2' => $userid,
            'ownerid' => $userid,
            'now' => $now
        ];
    } else {
        // Activity mode: access is controlled by enrollment/capabilities,
        // but show ALL cards user has access to (same as global mode).
        // The flashcardsid is only used for access control, NOT for filtering cards.
        // This ensures users see all their cards regardless of which activity they're viewing.
        $sql = "SELECT c.*, p.step, p.due, p.addedat, p.lastat, d.scope as deck_scope
                FROM {flashcards_cards} c
                INNER JOIN {flashcards_progress} p ON p.deckid = c.deckid AND p.cardid = c.cardid
                INNER JOIN {flashcards_decks} d ON d.id = c.deckid
                WHERE p.userid = :userid
                  AND p.due <= :now
                  AND p.hidden = 0
                  AND ((d.scope = 'private' AND (d.userid IS NULL OR d.userid = :userid2))
                       OR d.scope = 'shared')
                  AND ((c.scope = 'private' AND c.ownerid = :ownerid)
                       OR c.scope = 'shared')
                ORDER BY p.due ASC, c.id ASC";

        $params = [
            'userid' => $userid,
            'userid2' => $userid,
            'ownerid' => $userid,
            'now' => $now
        ];
    }

    $recs = $DB->get_records_sql($sql, $params, 0, $limit);

    // Preload translations for all fetched cards
    $transmap = [];
    if (!empty($recs)) {
        $bydeck = [];
        foreach ($recs as $r) { $bydeck[(int)$r->deckid][] = $r->cardid; }
        foreach ($bydeck as $dk => $ids) {
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
            $rows = $DB->get_records_select('flashcards_card_trans', 'deckid = :deckid AND cardid ' . $insql, ['deckid' => $dk] + $inparams);
            foreach ($rows as $row) {
                $transmap[$dk.'::'.$row->cardid][strtolower($row->lang)] = $row->text;
            }
        }
    }

    $out = [];
    foreach ($recs as $r) {
        $payload = json_decode($r->payload, true);
        if (!is_array($payload)) { $payload = []; }
        $key = ((int)$r->deckid).'::'.$r->cardid;
        if (!empty($transmap[$key])) { $payload['translations'] = $transmap[$key]; }
        $out[] = [
            'deckId' => (int)$r->deckid,
            'cardId' => $r->cardid,
            'scope' => $r->scope,
            'ownerid' => is_null($r->ownerid) ? null : (int)$r->ownerid,
            'payload' => $payload,
            'timemodified' => (int)$r->timemodified,
            'progress' => [
                'step' => (int)$r->step,
                'due' => (int)$r->due,
                'addedAt' => (int)$r->addedat,
                'lastAt' => (int)$r->lastat,
            ],
        ];
    }
    return $out;
}

function normalize_card_id($text) {
    $text = trim((string)$text);
    if ($text === '') { $text = uniqid('c', false); }
    $text = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $text);
    return substr($text, 0, 64);
}

function upsert_card($userid, array $payload, $globalmode = false, $context = null) {
    global $DB;
    $deckid = (int)($payload['deckId'] ?? 0);
    $cardid = normalize_card_id($payload['cardId'] ?? '');
    $scope = clean_param($payload['scope'] ?? 'private', PARAM_ALPHA);
    // Extract payload and translations
    $pp = $payload['payload'] ?? [];
    if (!is_array($pp)) { $pp = []; }
    $translations = [];
    if (isset($pp['translations']) && is_array($pp['translations'])) {
        foreach ($pp['translations'] as $lng => $txt) {
            $lng = strtolower(substr((string)$lng, 0, 10));
            $txt = trim((string)$txt);
            if ($lng && $txt !== '') { $translations[$lng] = $txt; }
        }
    }
    $pjson = json_encode($pp, JSON_UNESCAPED_UNICODE);
    if ($cardid === '') { throw new invalid_parameter_exception('Missing cardId'); }

    // If deck is not provided, create/find a personal deck for this user.
    if (!$deckid) {
        $title = 'My cards';
        // SINGLE GLOBAL DECK FIX: Find/create ONE "My cards" deck for ALL users
        // User separation done via ownerid field in flashcards_cards, not via separate decks
        // Deck is 'private' scope but userid=null means it's the global user deck
        $deck = $DB->get_record('flashcards_decks', [
            'title' => $title,
            'scope' => 'private',
            'userid' => null // NULL userid = global "My cards" deck for all users
        ]);
        if (!$deck) {
            $now = time();
            $deck = (object)[
                'courseid' => null,
                'userid' => null, // NULL = global deck
                'scope' => 'private', // Private scope (user-created cards, not shared lessons)
                'title' => $title,
                'meta' => json_encode(new stdClass()),
                'createdby' => $userid, // Track who created the deck initially
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $deck->id = $DB->insert_record('flashcards_decks', $deck);
        }
        $deckid = (int)$deck->id;
    }

    // Verify deck exists (no course check in global mode)
    $deck = $DB->get_record('flashcards_decks', ['id' => $deckid], '*', MUST_EXIST);

    // Verify user has access to this deck
    // Special case: "My cards" deck (userid=null, scope=private) is accessible by all authenticated users
    if ($deck->scope === 'private' && $deck->userid !== null && (int)$deck->userid !== (int)$userid) {
        throw new moodle_exception('access_denied', 'mod_flashcards');
    }

    $ownerid = ($scope === 'private') ? $userid : null;
    $existing = $DB->get_record('flashcards_cards', ['deckid' => $deckid, 'cardid' => $cardid]);
    $now = time();
    $rec = (object) [
        'deckid' => $deckid,
        'cardid' => $cardid,
        'ownerid' => $ownerid,
        'scope' => $scope,
        'payload' => $pjson,
        'timecreated' => $existing ? $existing->timecreated : $now,
        'timemodified' => $now,
    ];
    if ($existing) { $rec->id = $existing->id; $DB->update_record('flashcards_cards', $rec); }
    else { $DB->insert_record('flashcards_cards', $rec); }

    // Upsert translations rows
    foreach ($translations as $lng => $txt) {
        $exist = $DB->get_record('flashcards_card_trans', ['deckid'=>$deckid,'cardid'=>$cardid,'lang'=>$lng]);
        $row = (object)[
            'deckid' => $deckid,
            'cardid' => $cardid,
            'lang' => $lng,
            'text' => $txt,
            'timemodified' => time(),
        ];
        if ($exist) { $row->id = $exist->id; $DB->update_record('flashcards_card_trans', $row); }
        else { $DB->insert_record('flashcards_card_trans', $row); }
    }

    // Ensure initial progress for the owner so the card appears in due list.
    // Stage 0: newly created card with due=now (appears immediately)
    // PROGRESS SYNC FIX: Check by userid+cardid only (new unique constraint)
    if (!$DB->record_exists('flashcards_progress', ['userid' => $userid, 'cardid' => $cardid])) {
        $p = (object)[
            'flashcardsid' => $globalmode ? null : 0,
            'userid' => $userid,
            'deckid' => $deckid,
            'cardid' => $cardid,
            'step' => 0,
            'addedat' => $now,
            'due' => $now, // Appears immediately in queue
            'lastat' => 0,
            'hidden' => 0,
            'timemodified' => $now,
        ];
        $DB->insert_record('flashcards_progress', $p);
    }

    // Return deckId and cardId so client can update localStorage with correct IDs
    return [
        'deckId' => $deckid,
        'cardId' => $cardid,
    ];
}

function delete_card($userid, $deckid, $cardid, $globalmode = false, $context = null) {
    global $DB;
    $rec = $DB->get_record('flashcards_cards', ['deckid' => $deckid, 'cardid' => $cardid], '*', MUST_EXIST);

    // In global mode, only owner can delete private cards
    // In activity mode, course managers can also delete
    $canmanage = false;
    if (!$globalmode && $context) {
        $canmanage = has_capability('moodle/course:manageactivities', $context);
    }

    if ($rec->scope === 'private' && (int)$rec->ownerid !== (int)$userid && !$canmanage) {
        throw new moodle_exception('access_denied', 'mod_flashcards');
    }

    // CRITICAL FIX: Different deletion behavior for private vs shared cards
    if ($rec->scope === 'private' && (int)$rec->ownerid === (int)$userid) {
        // User deleting their OWN private card → actually delete from database
        $DB->delete_records('flashcards_cards', ['id' => $rec->id]);
        // Delete ALL progress records for this card (not just current user)
        // Rationale: Card no longer exists, so all progress references are orphaned
        $DB->delete_records('flashcards_progress', ['deckid' => $deckid, 'cardid' => $cardid]);
        $DB->delete_records('flashcards_card_trans', ['deckid' => $deckid, 'cardid' => $cardid]);
    } else {
        // User "deleting" a SHARED card → just hide it for this user
        // Card remains in database, but marked as hidden in progress
        $DB->set_field('flashcards_progress', 'hidden', 1, [
            'deckid' => $deckid,
            'cardid' => $cardid,
            'userid' => $userid
        ]);
    }
}

// TEST MODE: Using minutes instead of days for quick testing
function today0() { return time(); } // Return current timestamp instead of midnight

// Exponential intervals: 2^n days (converted to minutes for testing)
// Stage 0: card created (no deadline)
// Stage 1-10: 1, 2, 4, 8, 16, 32, 64, 128, 256, 512 days (in test: minutes)
// Stage 11+: completed (checkmark)
function srs_due_ts($currentTime, $step, $easy) {
    $currentTime = (int)$currentTime;
    if ($currentTime <= 0) { $currentTime = today0(); }
    $step = (int)$step;

    // Stage 0: no next due (card just created)
    if ($step <= 0) { return $currentTime; }

    // Stage 11+: completed (no more reviews needed)
    if ($step > 10) { return $currentTime + (512 * 60); } // Keep far in future

    // Calculate exponential interval: 2^(step-1) days
    $daysInterval = pow(2, $step - 1);

    // If "Normal" pressed, halve the interval
    if (!$easy) {
        $daysInterval = max(1, $daysInterval / 2);
    }

    // Convert days to minutes for testing (multiply by 60 seconds)
    return $currentTime + ($daysInterval * 60);
}

function get_due_cards($cm, $userid, $flashcardsid, $deckid = 0) {
    global $DB;
    $decks = $DB->get_records('flashcards_decks', ['courseid' => $cm->course], 'id ASC', 'id');
    if (!$decks) { return []; }
    $deckids = array_map(function($o){return (int)$o->id;}, $decks);
    if ($deckid) { $deckids = array_values(array_filter($deckids, function($id) use ($deckid){ return (int)$id === (int)$deckid; })); }
    list($insql, $inparams) = $DB->get_in_or_equal($deckids, SQL_PARAMS_NAMED, 'd');
    $cards = $DB->get_records_sql('SELECT * FROM {flashcards_cards} WHERE deckid '.$insql.' ORDER BY id ASC', $inparams);
    if (!$cards) { return []; }
    $now = time();

    // Load progress for these cards for this user & activity.
    $prog = [];
    $rows = $DB->get_records('flashcards_progress', ['flashcardsid' => $flashcardsid, 'userid' => $userid]);
    foreach ($rows as $r) { $prog[$r->deckid.'::'.$r->cardid] = $r; }

    $out = [];
    foreach ($cards as $c) {
        if ($c->scope === 'private' && (int)$c->ownerid !== (int)$userid) { continue; }
        $key = $c->deckid.'::'.$c->cardid;
        $p = $prog[$key] ?? null;
        if ($p) {
            if ((int)$p->due <= $now && (int)$p->hidden === 0) {
                $out[] = ['deckId' => (int)$c->deckid, 'cardId' => $c->cardid, 'payload' => json_decode($c->payload, true)];
            }
        }
    }
    return $out;
}

function review_card($cm, $userid, $flashcardsid, $deckid, $cardid, $rating, $globalmode = false) {
    global $DB;
    $card = $DB->get_record('flashcards_cards', ['deckid' => $deckid, 'cardid' => $cardid], '*', MUST_EXIST);
    $now = time();

    // PROGRESS SYNC FIX: Always find by userid+cardid only (syncs across decks/pages)
    $p = $DB->get_record('flashcards_progress', ['userid' => $userid, 'cardid' => $cardid]);
    if (!$p) {
        $p = (object)[
            'flashcardsid' => $flashcardsid,
            'userid' => $userid,
            'deckid' => $deckid,
            'cardid' => $cardid,
            'step' => 0,
            'addedat' => $now,
            'due' => $now,
            'lastat' => 0,
            'hidden' => 0,
            'timemodified' => $now,
        ];
        $p->id = $DB->insert_record('flashcards_progress', $p);
    }

    $step = (int)$p->step;

    // Rating: 1=Hard, 2=Normal, 3=Easy
    if ($rating <= 1) {
        // Hard: stage stays same, card appears tomorrow (1 minute in test mode)
        // Step doesn't change
        $due = $now + 60; // +1 minute
    } else {
        // Normal (2) or Easy (3): advance to next stage
        $step = min($step + 1, 11); // Max stage 11 (stage 10 shows "10", stage 11 shows checkmark)

        // Calculate due date
        $easy = ($rating >= 3); // Easy = full interval, Normal = half interval
        $due = srs_due_ts($now, $step, $easy);
    }

    $p->step = $step;
    $p->due = $due;
    $p->lastat = $now;
    $p->timemodified = $now;
    $DB->update_record('flashcards_progress', $p);
}
