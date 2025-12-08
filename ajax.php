<?php
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/local/ordbank_helper.php');
require_once(__DIR__ . '/classes/local/ordbokene_client.php');
require_once(__DIR__ . '/classes/local/ordbokene_utils.php');

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
    /**
     * Lightly clean verb forms coming from mixed sources (ordbank/ordbokene) to avoid noisy variants.
     */
    function mod_flashcards_prune_verb_forms(array $forms): array {
        if (empty($forms['verb']) || !is_array($forms['verb'])) {
            return $forms;
        }
        $v = $forms['verb'];
        $filter = function($list, callable $cb) {
            if (!is_array($list)) {
                $list = [$list];
            }
            $out = [];
            foreach ($list as $item) {
                $item = trim((string)$item);
                if ($item === '') {
                    continue;
                }
                if ($cb($item)) {
                    $out[] = $item;
                }
            }
            return array_values(array_unique($out));
        };
        $dropS = function($item) {
            // Drop reflexive-like "handles" noise when we want plain infinitive/presens/imperativ.
            return !preg_match('~/s$~i', $item);
        };
        $v['infinitiv'] = $filter($v['infinitiv'] ?? [], $dropS);
        $v['presens'] = $filter($v['presens'] ?? [], $dropS);
        $v['imperativ'] = $filter($v['imperativ'] ?? [], function($item) use ($dropS){
            return $dropS($item);
        });
        // Presens perfektum: drop derived noisy variants with handlede/handlende/handlete.
        $v['presens_perfektum'] = $filter($v['presens_perfektum'] ?? [], function($item){
            return !preg_match('/handlede|handlende|handlete/i', $item);
        });
        // Perfektum partisipp: drop handlende/handlede/handlete noise; keep core forms.
        $v['perfektum_partisipp'] = $filter($v['perfektum_partisipp'] ?? [], function($item){
            return !preg_match('/handlende|handlede|handlete/i', $item);
        });
        $forms['verb'] = $v;
        return $forms;
    }

/**
 * Fetch lemma suggestions from Ordbøkene (ord.uib.no) API.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_suggestions(string $query, int $limit = 12): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $url = 'https://ord.uib.no/api/articles?w=' . rawurlencode($query) . '&dict=bm,nn&scope=e';
    $suggestions = [];
    try {
        $curl = new \curl();
        $resp = $curl->get($url);
        $json = json_decode($resp, true);
        if (is_array($json) && !empty($json['articles'])) {
            foreach ($json['articles'] as $dict => $ids) {
                if (!is_array($ids)) {
                    continue;
                }
                foreach (array_slice($ids, 0, $limit) as $id) {
                    $articleurl = sprintf('https://ord.uib.no/%s/article/%d.json', $dict, (int)$id);
                    try {
                        $resp2 = $curl->get($articleurl);
                        $article = json_decode($resp2, true);
                        if (!is_array($article) || empty($article['lemmas'][0]['lemma'])) {
                            continue;
                        }
                        $lemma = trim($article['lemmas'][0]['lemma']);
                        if ($lemma === '') {
                            continue;
                        }
                        $suggestions[] = [
                            'lemma' => $lemma,
                            'dict' => $dict,
                            'id' => (int)$id,
                            'url' => $articleurl,
                        ];
                    } catch (\Throwable $e) {
                        // Skip failed article fetch.
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    $seen = [];
    $deduped = [];
    foreach ($suggestions as $s) {
        $key = core_text::strtolower(($s['lemma'] ?? '') . '|' . ($s['dict'] ?? ''));
        if (isset($seen[$key]) || ($s['lemma'] ?? '') === '') {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $s;
        if (count($deduped) >= $limit) {
            break;
        }
    }
    return $deduped;
}

/**
 * Try to fetch multi-word expressions from Ordbøkene first.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_expressions(string $query, int $limit = 8): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    // Try full query first, then shorter trailing spans (e.g. last 3/2 tokens).
    $parts = array_values(array_filter(preg_split('/\s+/u', $query)));
    $spans = [$query];
    if (count($parts) >= 3) {
        $spans[] = implode(' ', array_slice($parts, -3));
    }
    if (count($parts) >= 2) {
        $spans[] = implode(' ', array_slice($parts, -2));
    }
    $out = [];
    $seen = [];
    foreach ($spans as $span) {
        $span = trim($span);
        if ($span === '' || isset($seen[$span])) {
            continue;
        }
        $seen[$span] = true;
        try {
            $data = \mod_flashcards\local\ordbokene_client::search_expressions($span, 'begge');
            if (empty($data)) {
                continue;
            }
            $exprs = [];
            if (!empty($data['expressions']) && is_array($data['expressions'])) {
                $exprs = array_map('strval', $data['expressions']);
            }
            // If no expressions array, try baseform from article as a fallback.
            if (empty($exprs) && !empty($data['baseform'])) {
                $exprs[] = (string)$data['baseform'];
            }
            foreach ($exprs as $expr) {
                $expr = trim($expr);
                if ($expr === '') {
                    continue;
                }
                $key = core_text::strtolower($expr . '|ordbokene');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'lemma' => $expr,
                    'dict' => 'ordbokene',
                    'source' => 'ordbokene',
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        } catch (\Throwable $e) {
            // Ignore and continue with next span.
        }
    }
    return $out;
}

/**
 * Fallback: lookup spans directly and return baseforms as expressions.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_lookup_spans(string $query, int $limit = 6): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $parts = array_values(array_filter(preg_split('/\s+/u', $query)));
    $spans = [$query];
    if (count($parts) >= 3) {
        $spans[] = implode(' ', array_slice($parts, -3));
    }
    if (count($parts) >= 2) {
        $spans[] = implode(' ', array_slice($parts, -2));
    }
    $out = [];
    $seen = [];
    foreach ($spans as $span) {
        $span = trim($span);
        if ($span === '' || isset($seen[$span])) {
            continue;
        }
        $seen[$span] = true;
        try {
            $data = \mod_flashcards\local\ordbokene_client::lookup($span, 'begge');
            if (empty($data)) {
                continue;
            }
            $base = trim((string)($data['baseform'] ?? ''));
            if ($base !== '') {
                $key = core_text::strtolower($base . '|ordbokene');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = [
                        'lemma' => $base,
                        'dict' => 'ordbokene',
                        'source' => 'ordbokene',
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore and continue
        }
    }
    return $out;
}

/**
 * Use /api/suggest with include=eif to surface expressions and inflections.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_suggest(string $query, int $limit = 12): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $buildUrls = function(string $q) use ($limit): array {
        return [
            sprintf('https://ord.uib.no/api/suggest?q=%s&dict=bm,nn&include=efis&n=%d', rawurlencode($q), $limit),
            sprintf('https://ord.uib.no/api/suggest?q=%s%%&dict=bm,nn&include=efis&n=%d', rawurlencode($q), $limit),
        ];
    };
    $urls = $buildUrls($query);
    $out = [];
    $seen = [];
    try {
        $curl = new \curl();
        foreach ($urls as $url) {
            $resp = $curl->get($url);
            $json = json_decode($resp, true);
            if (!is_array($json) || empty($json['a'])) {
                continue;
            }
            foreach (['exact','inflect','freetext','similar'] as $bucket) {
                if (empty($json['a'][$bucket]) || !is_array($json['a'][$bucket])) {
                    continue;
                }
                foreach ($json['a'][$bucket] as $item) {
                    $lemma = trim((string)($item[0] ?? ''));
                    $langs = [];
                    if (!empty($item[1]) && is_array($item[1])) {
                        $langs = array_values(array_filter(array_map('strval', $item[1])));
                    }
                    if ($lemma === '') {
                        continue;
                    }
                    $key = core_text::strtolower($lemma . '|ordbokene');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = [
                        'lemma' => $lemma,
                        'dict' => 'ordbokene',
                        'source' => 'ordbokene',
                        'langs' => $langs,
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // ignore and return what we have
    }
    return $out;
}

/**
 * Filter suggestions so multi-word queries keep only lemmas that contain all tokens.
 *
 * @param array $items
 * @param string $query
 * @return array
 */
function flashcards_filter_multiword(array $items, string $query): array {
    $tokens = array_values(array_filter(preg_split('/\s+/u', trim($query))));
    if (count($tokens) < 2) {
        return $items;
    }
    $lowerTokens = array_map(function($t){ return core_text::strtolower($t); }, $tokens);
    $out = [];
    foreach ($items as $item) {
        $lemma = core_text::strtolower((string)($item['lemma'] ?? ''));
        if ($lemma === '') {
            continue;
        }
        $ok = true;
        foreach ($lowerTokens as $tok) {
            if (strpos($lemma, $tok) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $out[] = $item;
        }
    }
    return $out ?: $items;
}

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

    case 'check_text_errors':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->check_norwegian_text($text, $language, $userid);

        echo json_encode($result);
        break;

    case 'ai_answer_question':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }

        // Support both old format (prompt) and new format (messages[])
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            // New format: messages array for chat context
            $messages = $payload['messages'];
            if (empty($messages)) {
                throw new invalid_parameter_exception('Missing messages');
            }
        } else {
            // Legacy format: single prompt (for backwards compatibility)
            $prompt = trim($payload['prompt'] ?? '');
            if ($prompt === '') {
                throw new invalid_parameter_exception('Missing prompt');
            }
            // Convert to messages format
            $messages = [
                ['role' => 'user', 'content' => $prompt]
            ];
        }

        $language = clean_param($payload['language'] ?? 'en', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->answer_ai_question_with_context($messages, $language, $userid);

        // Note: usage from operation is already in $result, don't overwrite with snapshot
        echo json_encode($result);
        break;

    case 'ai_detect_constructions':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $focusword = trim($payload['focusWord'] ?? '');
        if ($fronttext === '' || $focusword === '') {
            throw new invalid_parameter_exception('Missing text or focus word');
        }
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->detect_constructions($fronttext, $focusword, $language, $userid);

        echo json_encode($result);
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
        $orbokeneenabled = get_config('mod_flashcards', 'orbokene_enabled');

        try {
            $helper = new \mod_flashcards\local\ai_helper();
            $openaiexpr = new \mod_flashcards\local\openai_client();
            $data = $helper->process_focus_request($userid, $fronttext, $clickedword, [
                'language' => $language,
                'level' => $level,
                'voice' => $voiceid ?: null,
            ]);
            $debugai = [];
            // Validate against ordbank: focus word/baseform must exist as a wordform and resolve data from ordbank.
            $focuscheck = core_text::strtolower(trim($data['focusBaseform'] ?? $data['focusWord'] ?? ''));
            $ob = null;
            if ($focuscheck !== '') {
                $ob = \mod_flashcards\local\ordbank_helper::analyze_token($focuscheck, []);
            }
            // If helper didn't return, try clicked word as fallback.
            if ((!$ob || empty($ob['selected'])) && !empty($clickedword)) {
                $ob = \mod_flashcards\local\ordbank_helper::analyze_token(core_text::strtolower($clickedword), []);
            }
            // If still nothing, try a direct lookup to confirm existence.
            if ((!$ob || empty($ob['selected']))) {
                // For multi-word expressions, skip strict ordbank validation.
                if (str_contains($focuscheck, ' ')) {
                    $ob = [
                        'selected' => [
                            'lemma_id' => 0,
                            'wordform' => $focuscheck,
                            'baseform' => $focuscheck,
                            'tag' => '',
                            'paradigme_id' => null,
                            'boy_nummer' => 0,
                            'ipa' => null,
                            'gender' => '',
                        ],
                        'forms' => [],
                    ];
                } else {
                    $exists = $DB->count_records_select('ordbank_fullform', 'LOWER(OPPSLAG)=?', [$focuscheck]);
                    if (!$exists) {
                        // Keep processing with minimal stub instead of erroring out.
                        $ob = [
                            'selected' => [
                                'lemma_id' => 0,
                                'wordform' => $focuscheck,
                                'baseform' => $focuscheck,
                                'tag' => '',
                                'paradigme_id' => null,
                                'boy_nummer' => 0,
                                'ipa' => null,
                                'gender' => '',
                            ],
                            'forms' => [],
                        ];
                    } else {
                        // Build a minimal selected from first match
                        $first = $DB->get_record_sql("SELECT * FROM {ordbank_fullform} WHERE LOWER(OPPSLAG)=:w LIMIT 1", ['w' => $focuscheck]);
                        $ob = [
                            'selected' => [
                                'lemma_id' => (int)$first->lemma_id,
                                'wordform' => $first->oppslag,
                                'baseform' => null,
                                'tag' => $first->tag,
                                'paradigme_id' => $first->paradigme_id,
                                'boy_nummer' => (int)$first->boy_nummer,
                                'ipa' => null,
                                'gender' => '',
                            ],
                            'forms' => [],
                        ];
                    }
                }
            }
            // Override AI outputs with ordbank-confirmed data to avoid made-up words/IPA.
            $selected = $ob['selected'];
            $data['focusWord'] = $selected['baseform'] ?? $selected['wordform'] ?? $focuscheck;
            $data['focusBaseform'] = $selected['baseform'] ?? $data['focusWord'];
            $taglower = core_text::strtolower($selected['tag'] ?? '');
            $aiPos = core_text::strtolower($data['pos'] ?? '');
            $ordbankpos = '';
            if ($taglower !== '') {
                if (str_contains($taglower, 'verb')) {
                    $ordbankpos = 'verb';
                } else if (str_contains($taglower, 'subst')) {
                    $ordbankpos = 'substantiv';
                } else if (str_contains($taglower, 'adj')) {
                    $ordbankpos = 'adjektiv';
                }
            }
            // Avoid injecting verb paradigms when AI decided this is not a verb (e.g., phrases like "være klar over").
            $allowforms = !($aiPos && $aiPos !== 'verb' && $ordbankpos === 'verb');
            if (!$data['pos']) {
                if (str_contains($taglower, 'verb')) {
                    $data['pos'] = 'verb';
                } else if (str_contains($taglower, 'subst')) {
                    $data['pos'] = 'substantiv';
                }
            }
            if (!empty($selected['ipa'])) {
                $data['transcription'] = $selected['ipa'];
            }
            if (!empty($selected['gender'])) {
                $data['gender'] = $selected['gender'];
            }
            $data['forms'] = $allowforms ? ($ob['forms'] ?? []) : [];
            if ($allowforms && (empty($data['forms']) || $data['forms'] === []) && !empty($selected['lemma_id'])) {
                $data['forms'] = \mod_flashcards\local\ordbank_helper::fetch_forms((int)$selected['lemma_id'], (string)($selected['tag'] ?? ''));
            }
            if (empty($data['parts']) && !empty($ob['parts'])) {
                $data['parts'] = $ob['parts'];
            }
            // If still no forms and we have a baseform (even when POS=phrase), try ordbank by baseform.
            if ($allowforms && (empty($data['forms']) || $data['forms'] === []) && !empty($data['focusBaseform'])) {
                $tmp = \mod_flashcards\local\ordbank_helper::analyze_token(core_text::strtolower($data['focusBaseform']), []);
                if (!empty($tmp['forms'])) {
                    $data['forms'] = $tmp['forms'];
                }
                if (empty($data['parts']) && !empty($tmp['parts'])) {
                    $data['parts'] = $tmp['parts'];
                }
            }
            if (empty($data['parts']) && !empty($data['focusWord'])) {
                $data['parts'] = [$data['focusWord']];
            }
            // Always try Ordbokene: resolve expression/meaning first, then regenerate translation/definition/examples for that expression.
            $debugai = [];
            if ($orbokeneenabled) {
                $lang = ($language === 'nn') ? 'nn' : (($language === 'nb' || $language === 'no') ? 'bm' : 'begge');
                $lookupWord = $data['focusBaseform'] ?? $data['focusWord'] ?? $clickedword;
                $resolvedExpr = mod_flashcards_resolve_ordbokene_expression($fronttext, $clickedword, $lookupWord, $lang);
                $entries = \mod_flashcards\local\ordbokene_client::lookup_all($lookupWord, $lang, 6);
                if ($resolvedExpr && !empty($resolvedExpr['expression'])) {
                    $entries = array_values(array_merge([[
                        'baseform' => $resolvedExpr['expression'],
                        'meanings' => $resolvedExpr['meanings'] ?? [],
                        'examples' => $resolvedExpr['examples'] ?? [],
                        'forms' => $resolvedExpr['forms'] ?? [],
                        'dictmeta' => $resolvedExpr['dictmeta'] ?? [],
                        'source' => 'ordbokene',
                    ]], $entries));
                }
                $chosen = null;
                if (!empty($entries)) {
                    $deflist = [];
                    $map = [];
                    foreach ($entries as $ei => $entry) {
                        $meanings = [];
                        if (!empty($entry['meanings']) && is_array($entry['meanings'])) {
                            $meanings = $entry['meanings'];
                        } else if (!empty($entry['definition'])) {
                            $meanings = [$entry['definition']];
                        }
                        foreach ($meanings as $mi => $def) {
                            $def = trim((string)$def);
                            if ($def === '') {
                                continue;
                            }
                            $deflist[] = $def;
                            $map[] = ['entry' => $ei, 'meaning' => $mi];
                        }
                    }
                    if (count($deflist) > 1) {
                        $best = $helper->choose_best_definition($fronttext, $lookupWord, $deflist, $language, $userid);
                        if ($best && isset($best['index']) && isset($map[$best['index']])) {
                            $chosen = $map[$best['index']];
                        }
                    }
                    if ($chosen === null && !empty($entries)) {
                        $chosen = ['entry' => 0, 'meaning' => 0];
                    }
                    if ($chosen !== null) {
                        $entry = $entries[$chosen['entry']];
                        $meaning = $entry['meanings'][$chosen['meaning']] ?? ($entry['meanings'][0] ?? '');
                        $expression = $entry['baseform'] ?? $lookupWord;
                        $data['ordbokene'] = [
                            'expression' => $expression,
                            'meanings' => $entry['meanings'] ?? [],
                            'examples' => $entry['examples'] ?? [],
                            'forms' => $entry['forms'] ?? [],
                            'dictmeta' => $entry['dictmeta'] ?? [],
                            'source' => 'ordbokene',
                            'chosenMeaning' => $chosen['meaning'],
                            'url' => $entry['dictmeta']['url'] ?? '',
                            'citation' => sprintf('"%s". I: Nynorskordboka. Sprakradet og Universitetet i Bergen. https://ordbokene.no (henta %s).', $expression, date('d.m.Y')),
                        ];
                        $data['focusExpression'] = $expression;
                        $data['focusWord'] = $expression;
                        $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$expression])));
                        $debugai['ordbokene'] = ['expression' => $expression, 'url' => $entry['dictmeta']['url'] ?? '', 'chosen' => $chosen];
                        $seed = !empty($entry['examples']) ? $entry['examples'] : [];
                        if ($openaiexpr->is_enabled()) {
                            $gen = $openaiexpr->generate_expression_content($expression, $meaning, $seed, $fronttext, $language, $level);
                            if (!empty($gen['translation'])) {
                                $data['translation'] = $gen['translation'];
                            }
                            if (!empty($gen['definition'])) {
                                $data['definition'] = $gen['definition'];
                            }
                            if (!empty($gen['examples'])) {
                                $data['examples'] = $gen['examples'];
                            }
                        }
                        if (!empty($meaning)) {
                            $data['analysis'] = [
                                [
                                    'text' => $expression,
                                    'translation' => $meaning,
                                ],
                            ];
                        }
                    }
                }
            }
            if (!isset($data['ordbokene'])) {
                $fallbackExpr = $resolvedExpr ?: mod_flashcards_resolve_ordbokene_expression($fronttext, $clickedword, $data['focusBaseform'] ?? '', $lang);
                if ($fallbackExpr) {
                    $data['ordbokene'] = $fallbackExpr;
                    $data['focusExpression'] = $fallbackExpr['expression'];
                    $data['focusWord'] = $fallbackExpr['expression'];
                    $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$fallbackExpr['expression']])));
                    $meaning = !empty($fallbackExpr['meanings'][0]) ? $fallbackExpr['meanings'][0] : '';
                    $seed = !empty($fallbackExpr['examples']) ? $fallbackExpr['examples'] : [];
                    if ($openaiexpr->is_enabled()) {
                        $gen = $openaiexpr->generate_expression_content($fallbackExpr['expression'], $meaning, $seed, $fronttext, $language, $level);
                        if (!empty($gen['translation'])) {
                            $data['translation'] = $gen['translation'];
                        }
                        if (!empty($gen['definition'])) {
                            $data['definition'] = $gen['definition'];
                        }
                        if (!empty($gen['examples'])) {
                            $data['examples'] = $gen['examples'];
                        }
                    }
                    if (!empty($meaning)) {
                        $data['analysis'] = [
                            [
                                'text' => $fallbackExpr['expression'],
                                'translation' => $meaning,
                            ],
                        ];
                    }
                    if (empty($data['ordbokene']['citation'])) {
                        $data['ordbokene']['citation'] = sprintf('"%s". I: Nynorskordboka. Sprakradet og Universitetet i Bergen. https://ordbokene.no (henta %s).', $fallbackExpr['expression'], date('d.m.Y'));
                    }
                    $debugai['ordbokene'] = ['expression' => $fallbackExpr['expression'], 'url' => $fallbackExpr['dictmeta']['url'] ?? ''];
                } else {
                    $debugai['ordbokene'] = ['expression' => null];
                }
            }
            // Always prefer Ordbokene verb forms to mirror dictionary table.
            if (!empty($data['ordbokene'])) {
                $baseLookup = \mod_flashcards\local\ordbokene_client::lookup($data['focusBaseform'] ?? $lookupWord, $lang);
                if (!empty($baseLookup['forms'])) {
                    $data['forms'] = $baseLookup['forms'];
                }
            }
            // Ensure focus baseform stays on the lemma (not the expression surface form).
            if (!empty($selected['baseform'])) {
                $data['focusBaseform'] = $selected['baseform'];
            }
            // Final form cleanup for verbs to avoid noisy variants.
            $data['forms'] = mod_flashcards_prune_verb_forms($data['forms'] ?? []);
            if (empty($data['gender']) && !empty($selected['gender'])) {
                $data['gender'] = $selected['gender'];
            }
            // Note: usage from operation is already in $data, don't overwrite with snapshot
            $resp = ['ok' => true, 'data' => $data];
            if (!empty($debugai)) {
                $resp['debug'] = $debugai;
            }
            echo json_encode($resp);

        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'ai_invalid_json') {
                // Return detailed error info to browser console
                echo json_encode([
                    'ok' => false,
                    'error' => 'Unexpected response from the AI service.',
                    'errorcode' => 'ai_invalid_json',
                    'details' => 'Check browser console for API response details',
                    'debug' => [
                        'action' => 'ai_focus_helper',
                        'payload' => $payload,
                        'response_preview' => substr($e->getMessage(), strpos($e->getMessage(), 'response preview =') + 18) ?: 'No preview available'
                    ]
                ]);
            } else {
                throw $e;
            }
        }
        break;

    case 'ordbokene_suggest':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $query = trim($payload['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['ok' => true, 'data' => []]);
            break;
        }
        $data = flashcards_fetch_ordbokene_suggestions($query, 12);
        // Merge duplicates (bm/nn) for the same lemma.
        $merged = [];
        foreach ($data as $item) {
            $lemma = trim((string)($item['lemma'] ?? ''));
            if ($lemma === '') {
                continue;
            }
            $key = core_text::strtolower($lemma);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'lemma' => $lemma,
                    'dict' => 'ordbokene',
                    'langs' => [],
                ];
            }
            $dict = trim((string)($item['dict'] ?? ''));
            if ($dict !== '') {
                $merged[$key]['langs'][] = $dict;
            }
            if (empty($merged[$key]['url']) && !empty($item['url'])) {
                $merged[$key]['url'] = $item['url'];
            }
            if (empty($merged[$key]['id']) && !empty($item['id'])) {
                $merged[$key]['id'] = $item['id'];
            }
        }
        $out = array_values(array_map(function($row){
            $row['langs'] = array_values(array_unique(array_filter($row['langs'] ?? [])));
            if (empty($row['langs'])) {
                unset($row['langs']);
            }
            return $row;
        }, $merged));
        echo json_encode(['ok' => true, 'data' => $out]);
        break;

    case 'front_suggest':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $query = trim($payload['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['ok' => true, 'data' => []]);
            break;
        }
        $tokens = array_values(array_filter(preg_split('/\s+/u', $query)));
        $isMultiWord = count($tokens) >= 2;
        $limit = 12;
        $results = [];
        $seen = [];
        $ordbokeneindex = [];
        $mergeordbokene = function(array $item) use (&$results, &$ordbokeneindex, $limit): bool {
            $lemma = trim((string)($item['lemma'] ?? ''));
            if ($lemma === '') {
                return true;
            }
            $langparts = [];
            if (!empty($item['langs']) && is_array($item['langs'])) {
                $langparts = array_values(array_filter(array_map(function($v){
                    return core_text::strtolower(trim((string)$v));
                }, $item['langs'])));
            }
            $dictval = trim((string)($item['dict'] ?? ''));
            if ($dictval !== '') {
                $langparts[] = core_text::strtolower($dictval);
            }
            $langparts = array_values(array_unique(array_filter($langparts)));
            $lemmakey = core_text::strtolower($lemma);
            if (isset($ordbokeneindex[$lemmakey])) {
                $idx = $ordbokeneindex[$lemmakey];
                $existinglangs = [];
                if (!empty($results[$idx]['langs']) && is_array($results[$idx]['langs'])) {
                    $existinglangs = array_values(array_filter(array_map('strval', $results[$idx]['langs'])));
                }
                $merged = array_values(array_unique(array_merge($existinglangs, $langparts)));
                if (!empty($merged)) {
                    $results[$idx]['langs'] = $merged;
                }
                return true;
            }
            if (count($results) >= $limit) {
                return false;
            }
            $entry = [
                'lemma' => $lemma,
                'dict' => 'ordbokene',
                'source' => 'ordbokene',
            ];
            if (!empty($item['id'])) {
                $entry['id'] = $item['id'];
            }
            if (!empty($item['url'])) {
                $entry['url'] = $item['url'];
            }
            if (!empty($langparts)) {
                $entry['langs'] = $langparts;
            }
            $results[] = $entry;
            $ordbokeneindex[$lemmakey] = count($results) - 1;
            return true;
        };

        // Use the last token for local ordbank prefix search (handles "dreie s" -> search "s").
        $parts = preg_split('/\s+/u', $query);
        $prefix = is_array($parts) && count($parts) ? trim((string)end($parts)) : $query;
        if (core_text::strlen($prefix) >= 2) {
            try {
                $records = $DB->get_records_sql(
                    "SELECT DISTINCT f.OPPSLAG AS lemma, f.LEMMA_ID, l.GRUNNFORM AS baseform
                       FROM {ordbank_fullform} f
                  LEFT JOIN {ordbank_lemma} l ON l.LEMMA_ID = f.LEMMA_ID
                      WHERE f.OPPSLAG LIKE :q
                   ORDER BY f.OPPSLAG ASC",
                    ['q' => $prefix . '%'],
                    0,
                    $limit
                );
                foreach ($records as $rec) {
                    $key = core_text::strtolower($rec->lemma . '|ordbank');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'lemma' => $rec->lemma,
                        'baseform' => $rec->baseform ?? null,
                        'dict' => 'ordbank',
                        'source' => 'ordbank',
                    ];
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // DB lookup is best-effort; fall through to remote suggestions.
            }
        }

        // If this is a single word and local already filled the limit, stop here.
        if (!$isMultiWord && count($results) >= $limit) {
            echo json_encode(['ok' => true, 'data' => array_slice($results, 0, $limit)]);
            break;
        }

        // Note: for multi-word queries we keep going to remote to fetch expressions that match all tokens.

        // Suggest endpoint (includes expressions/inflections) first.
        if (count($results) < $limit) {
            $suggestRemote = flashcards_fetch_ordbokene_suggest($query, $limit);
            $suggestRemote = flashcards_filter_multiword($suggestRemote, $query);
            foreach ($suggestRemote as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Remote expressions (ord.uib.no full query to prioritize multi-word hits).
        if (count($results) < $limit) {
            $expressions = flashcards_fetch_ordbokene_expressions($query, 6);
            $expressions = flashcards_filter_multiword($expressions, $query);
            foreach ($expressions as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Fallback: direct lookup for spans to pull baseforms when expressions array is empty.
        if (count($results) < $limit) {
            $lookupspans = flashcards_fetch_ordbokene_lookup_spans($query, $limit);
            foreach ($lookupspans as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Remote lemma suggestions next (ord.uib.no full query).
        if (count($results) < $limit) {
            try {
                $remote = flashcards_fetch_ordbokene_suggestions($query, $limit);
                $remote = flashcards_filter_multiword($remote, $query);
                foreach ($remote as $item) {
                    if (!$mergeordbokene($item)) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // If remote fails, we still fall back to local below.
            }
        }

        // Re-rank: for multi-word queries prefer lemmas that contain all tokens (and multi-word/ordbokene first).
        if ($isMultiWord && !empty($results)) {
            $qlower = core_text::strtolower(trim($query));
            $tokenLower = array_map(fn($t) => core_text::strtolower($t), $tokens);
            $idx = 0;
            $scored = array_map(function($item) use ($qlower, $tokenLower, &$idx) {
                $lemma = core_text::strtolower((string)($item['lemma'] ?? ''));
                $containsAll = true;
                foreach ($tokenLower as $t) {
                    if ($t === '' || strpos($lemma, $t) === false) {
                        $containsAll = false;
                        break;
                    }
                }
                $hasSpace = (bool)preg_match('/\s/u', $lemma);
                $dictScore = (isset($item['dict']) && core_text::strtolower((string)$item['dict']) === 'ordbokene') ? 0 : 1;
                $score = [
                    $lemma === $qlower ? 0 : 1,    // exact match is best
                    $containsAll ? 0 : 1,         // must contain all tokens
                    $hasSpace ? 0 : 1,            // multi-word higher
                    $dictScore,                   // prefer ordbokene over local
                    (strpos($lemma, $qlower) !== false) ? 0 : 1, // contains full query
                    $idx++,                       // stable fallback
                ];
                return ['score' => $score, 'item' => $item];
            }, $results);
            usort($scored, function($a, $b) {
                return $a['score'] <=> $b['score'];
            });
            $results = array_values(array_map(fn($s) => $s['item'], $scored));
        }

        $ordered = [];
        $other = [];
        foreach ($results as $item) {
            $dictval = core_text::strtolower(trim((string)($item['dict'] ?? $item['source'] ?? '')));
            if ($dictval === 'ordbokene') {
                $ordered[] = $item;
            } else {
                $other[] = $item;
            }
        }
        $results = array_merge($ordered, $other);
        echo json_encode(['ok' => true, 'data' => array_slice($results, 0, $limit)]);
        break;
    case 'ordbokene_ping':
        // Minimal connectivity test to Ordbøkene (ord.uib.no).
        $url = 'https://ord.uib.no/api/articles?w=klar&dict=bm,nn&scope=e';
        $result = ['ok' => false, 'url' => $url];
        try {
            $curl = new \curl();
            $resp = $curl->get($url);
            $result['http_code'] = $curl->info['http_code'] ?? null;
            $result['raw'] = $resp;
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                $result['ok'] = true;
                $result['json'] = $decoded;
            } else {
                $result['error'] = 'Invalid JSON';
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        echo json_encode($result);
        break;

    case 'ordbank_focus_helper':
        try {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                throw new invalid_parameter_exception('Invalid payload');
            }
            $word = trim($payload['word'] ?? '');
            $fronttext = trim($payload['frontText'] ?? '');
            $prev = trim($payload['prev'] ?? '');
            $next = trim($payload['next'] ?? '');
            $next2 = trim($payload['next2'] ?? '');
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
            if ($next2 !== '') {
                $context['next2'] = $next2;
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
            // Ensure baseform is present if we have lemma_id but baseform is empty.
            if (!empty($data['selected']['lemma_id']) && empty($data['selected']['baseform'])) {
                $lemma = $DB->get_record('ordbank_lemma', ['lemma_id' => $data['selected']['lemma_id']]);
                if ($lemma && !empty($lemma->grunnform)) {
                    $data['selected']['baseform'] = $lemma->grunnform;
                    // Also replace parts if they only contain the surface form.
                    if (!empty($data['parts']) && count($data['parts']) === 1) {
                        $data['parts'] = [$lemma->grunnform];
                    }
                }
            }
            if (!$data && !empty($debug['fullform_sample'])) {
                // Fallback: return first sample as a minimal candidate to unblock UI
                $first = $debug['fullform_sample'][0];
                $baseform = null;
                if (!empty($first->lemma_id)) {
                    $lemma = $DB->get_record('ordbank_lemma', ['lemma_id' => $first->lemma_id]);
                    $baseform = $lemma->grunnform ?? null;
                }
                $data = [
                    'token' => $word,
                    'selected' => [
                        'lemma_id' => (int)($first->lemma_id ?? 0),
                        'wordform' => $first->oppslag ?? $word,
                        'tag' => $first->tag ?? '',
                        'paradigme_id' => null,
                        'boy_nummer' => 0,
                        'ipa' => null,
                        'baseform' => $baseform,
                        'gender' => '',
                    ],
                    'forms' => [],
                    'candidates' => [$first],
                    'paradigm' => [],
                    'parts' => [$baseform ?? $first->oppslag ?? $word],
                    'ambiguous' => true,
                ];
            }
            // Optionally enrich with ordbokene expressions/meanings (normalized).
            $orbokeneenabled = get_config('mod_flashcards', 'orbokene_enabled');
            $ordbokene_debug = [];
            if ($orbokeneenabled) {
                $lang = 'begge';
                $ordbokene_debug['enabled'] = true;
                $ordbokene = mod_flashcards_resolve_ordbokene_expression($fronttext, $word, $data['selected']['baseform'] ?? $word, $lang);
                if ($ordbokene) {
                    $ordbokene_debug['expression'] = $ordbokene['expression'];
                    $ordbokene_debug['url'] = $ordbokene['dictmeta']['url'] ?? '';
                    $data['ordbokene'] = $ordbokene;
                    $data['focusExpression'] = $ordbokene['expression'];
                    $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$ordbokene['expression']])));
                    if (empty($data['definition']) && !empty($ordbokene['meanings'])) {
                        $data['definition'] = $ordbokene['meanings'][0];
                    }
                    if (empty($data['examples']) && !empty($ordbokene['examples'])) {
                        $data['examples'] = $ordbokene['examples'];
                    }
                    if (empty($data['forms']) && !empty($ordbokene['forms'])) {
                        $data['forms'] = $ordbokene['forms'];
                    }
                    if (empty($data['selected']['wordform'])) {
                        $data['selected']['wordform'] = $ordbokene['expression'];
                    }
                    if (empty($data['selected']['baseform'])) {
                        $data['selected']['baseform'] = $ordbokene['expression'];
                    }
                    $data['selected']['baseform'] = mod_flashcards_normalize_infinitive($data['selected']['baseform']);
                    if (empty($data['analysis']) || !is_array($data['analysis'])) {
                        $data['analysis'] = [];
                    }
                    if (!empty($ordbokene['meanings'][0])) {
                        $data['analysis'][] = [
                            'text' => $ordbokene['expression'],
                            'translation' => $ordbokene['meanings'][0],
                        ];
                    }
                } else {
                    $ordbokene_debug['expression'] = null;
                }
                if ((!$ordbokene || empty($ordbokene['meanings'])) && !empty($data['selected']['baseform'])) {
                    $fallback = \mod_flashcards\local\ordbokene_client::lookup($data['selected']['baseform'], $lang);
                    $ordbokene_debug['fallback'] = [
                        'word' => $data['selected']['baseform'],
                        'ok' => !empty($fallback),
                        'url' => $fallback['dictmeta']['url'] ?? ''
                    ];
                    if (!empty($fallback)) {
                        if (empty($data['definition']) && !empty($fallback['meanings'])) {
                            $data['definition'] = $fallback['meanings'][0];
                        }
                        if (empty($data['examples']) && !empty($fallback['examples'])) {
                            $data['examples'] = $fallback['examples'];
                        }
                        if (empty($data['forms']) && !empty($fallback['forms'])) {
                            $data['forms'] = $fallback['forms'];
                        }
                        if (empty($data['dictmeta']) && !empty($fallback['dictmeta'])) {
                            $data['dictmeta'] = $fallback['dictmeta'];
                        }
                    }
                }
            } else {
                $ordbokene_debug['enabled'] = false;
            }

if (!empty($ordbokene_debug)) {
            $debug['ordbokene'] = $ordbokene_debug;
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
        // Note: usage from operation is already in $data, don't overwrite with snapshot
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
        // Note: usage from operation is already in $data, don't overwrite with snapshot
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
