<?php

namespace mod_flashcards\local;

use coding_exception;
use Exception;
use invalid_parameter_exception;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class api {
    public static function fetch_progress($flashcardsid, $userid) {
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
    
    public static function save_progress_batch($flashcardsid, $userid, array $records) {
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
    public static function list_decks($userid, $globalmode = false) {
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
    
    public static function create_deck($userid, $title, $globalmode = false) {
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
    public static function ensure_progress_exists($userid, $deckid, $flashcardsid = null) {
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
    
    public static function get_deck_cards($userid, $deckid, $offset = 0, $limit = 100, $globalmode = false) {
        global $DB;
        // Verify deck exists (no course check in global mode)
        $DB->get_record('flashcards_decks', ['id' => $deckid], '*', MUST_EXIST);
    
        // CRITICAL FIX: Ensure progress records exist before querying
        self::ensure_progress_exists($userid, $deckid, null);
    
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
        $examplemap = self::fetch_example_translations([$deckid => $ids]);
    
        $out = [];
        foreach ($recs as $r) {
            // In global mode, show shared cards + user's own private cards
            if ($r->scope === 'private' && (int)$r->ownerid !== (int)$userid) { continue; }
            $payload = json_decode($r->payload, true);
            if (!is_array($payload)) { $payload = []; }
            $payload = self::populate_transcription_if_missing($payload);
            if (!empty($transmap[$r->cardid])) {
                $payload['translations'] = $transmap[$r->cardid];
            }
            $cardkey = ((int)$r->deckid) . '::' . $r->cardid;
            $payload = self::normalize_examples_on_fetch($payload, $examplemap[$cardkey] ?? [], function_exists('current_language') ? current_language() : null);
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
    
    public static function get_due_cards_optimized($userid, $flashcardsid = null, $limit = 1000, $globalmode = false) {
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
        $examplemap = [];
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
            $examplemap = self::fetch_example_translations($bydeck);
        }

        $out = [];
        foreach ($recs as $r) {
            $payload = json_decode($r->payload, true);
            if (!is_array($payload)) { $payload = []; }
            $payload = self::populate_transcription_if_missing($payload);
            $key = ((int)$r->deckid).'::'.$r->cardid;
            if (!empty($transmap[$key])) { $payload['translations'] = $transmap[$key]; }
            $payload = self::normalize_examples_on_fetch($payload, $examplemap[$key] ?? [], function_exists('current_language') ? current_language() : null);
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
    
    protected static function populate_transcription_if_missing(array $payload): array {
        $current = trim((string)($payload['transcription'] ?? ''));
        if ($current !== '') {
            return $payload;
        }

        // Only search transcription for Base form field (no fallback to fokus or text)
        $baseform = $payload['focusBase'] ?? $payload['focus_baseform'] ?? '';
        $baseform = trim((string)$baseform);

        if ($baseform === '') {
            return $payload; // No base form provided - leave transcription empty
        }

        $pos = $payload['pos'] ?? null;
        $transcription = self::lookup_phrase_transcription($baseform, $pos);

        if ($transcription) {
            $payload['transcription'] = $transcription;
        }

        return $payload;
    }

    /**
     * Remove Norwegian articles (en, ei, et) and infinitive marker (å) from the beginning of a word/phrase.
     * This ensures transcription lookup uses only the base word form.
     *
     * @param string $text The text to clean
     * @return string The cleaned text with articles and markers removed
     */
    protected static function strip_articles_and_markers(string $text): string {
        $text = trim($text);
        // Remove infinitive marker å at the beginning
        $text = preg_replace('/^å\s+/iu', '', $text);
        // Remove articles en, ei, et at the beginning
        $text = preg_replace('/^(en|ei|et)\s+/iu', '', $text);
        return trim($text);
    }

    /**
     * Lookup transcription for single words or phrases (multiple words).
     * For phrases, looks up each word separately and combines results.
     * Missing words are marked with [?].
     *
     * @param string $phrase The word or phrase to lookup
     * @param string|null $pos Part of speech
     * @return string|null Combined transcription or null if all words not found
     */
    protected static function lookup_phrase_transcription(string $phrase, ?string $pos): ?string {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return null;
        }

        // Split into words
        $words = preg_split('/\s+/u', $phrase);
        if (!$words) {
            return null;
        }

        $transcriptions = [];
        $foundAny = false;

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            // Strip articles/å from each word
            $cleanWord = self::strip_articles_and_markers($word);
            $trans = pronunciation_manager::lookup_transcription($cleanWord, $pos);

            if ($trans) {
                $transcriptions[] = $trans;
                $foundAny = true;
            } else {
                $transcriptions[] = '[?]';
            }
        }

        // Return combined transcription only if at least one word was found
        return $foundAny ? implode(' ', $transcriptions) : null;
    }

    /**
     * Normalize examples to base Norwegian text list and collect per-language translations.
     *
     * @param array $payload Card payload (modified in-place to keep only base examples)
     * @param string|null $defaultlang Lang code to use when parsing legacy "no | translation" strings
     * @return array{0: array, 1: int} [$translationsMap, $baseExampleCount]
     */
    protected static function normalize_examples_for_storage(array &$payload, ?string $defaultlang = null): array {
        $translations = [];
        $examples = $payload['examples'] ?? [];
        if (!is_array($examples)) {
            $examples = [];
        }

        $base = [];
        foreach ($examples as $idx => $ex) {
            $text = '';
            if (is_array($ex)) {
                $text = trim((string)($ex['text'] ?? $ex['no'] ?? ''));
                if (!empty($ex['translations']) && is_array($ex['translations'])) {
                    foreach ($ex['translations'] as $lng => $val) {
                        $lng = strtolower(substr((string)$lng, 0, 10));
                        $val = trim((string)$val);
                        if ($lng !== '' && $val !== '') {
                            $translations[$lng][(int)$idx] = $val;
                        }
                    }
                }
            } else {
                $parts = explode('|', (string)$ex, 2);
                $text = trim($parts[0]);
                if (count($parts) === 2) {
                    $trans = trim($parts[1]);
                    if ($trans !== '') {
                        $lng = $defaultlang ? strtolower(substr($defaultlang, 0, 10)) : '';
                        if ($lng !== '') {
                            $translations[$lng][(int)$idx] = $trans;
                        }
                    }
                }
            }

            if ($text !== '') {
                $base[] = $text;
            }
        }

        // Merge explicit exampleTranslations payload (lang => [idx => text])
        if (!empty($payload['exampleTranslations']) && is_array($payload['exampleTranslations'])) {
            foreach ($payload['exampleTranslations'] as $lng => $arr) {
                if (!is_array($arr)) { continue; }
                $lng = strtolower(substr((string)$lng, 0, 10));
                if ($lng === '') { continue; }
                foreach ($arr as $i => $val) {
                    $val = trim((string)$val);
                    if ($val === '') { continue; }
                    $translations[$lng][(int)$i] = $val;
                }
            }
        }

        $payload['examples'] = $base;
        return [$translations, count($base)];
    }

    /**
     * Upsert per-language example translations without touching languages not provided.
     *
     * @param int $deckid Deck id
     * @param string $cardid Card id
     * @param array $translations Map lang => [idx => text]
     * @param int $basecount Number of examples (indexes beyond this are removed)
     */
    protected static function upsert_example_translations(int $deckid, string $cardid, array $translations, int $basecount): void {
        global $DB;
        $deckid = (int)$deckid;
        $cardid = trim($cardid);
        if ($cardid === '') {
            return;
        }

        // Remove translations for deleted examples (all languages)
        $DB->delete_records_select(
            'flashcards_card_example_trans',
            'deckid = :deckid AND cardid = :cardid AND example_idx >= :maxidx',
            ['deckid' => $deckid, 'cardid' => $cardid, 'maxidx' => $basecount]
        );

        if (empty($translations)) {
            return;
        }

        $now = time();
        foreach ($translations as $lng => $items) {
            if (!is_array($items)) { continue; }
            $lng = strtolower(substr((string)$lng, 0, 10));
            if ($lng === '') { continue; }

            foreach ($items as $idx => $text) {
                $idx = (int)$idx;
                if ($idx < 0 || $idx >= $basecount) {
                    continue;
                }
                $text = trim((string)$text);
                $existing = $DB->get_record('flashcards_card_example_trans', [
                    'deckid' => $deckid,
                    'cardid' => $cardid,
                    'example_idx' => $idx,
                    'lang' => $lng
                ]);

                if ($text === '') {
                    if ($existing) {
                        $DB->delete_records('flashcards_card_example_trans', ['id' => $existing->id]);
                    }
                    continue;
                }

                $row = (object)[
                    'deckid' => $deckid,
                    'cardid' => $cardid,
                    'example_idx' => $idx,
                    'lang' => $lng,
                    'text' => $text,
                    'timemodified' => $now,
                ];

                if ($existing) {
                    $row->id = $existing->id;
                    $DB->update_record('flashcards_card_example_trans', $row);
                } else {
                    $DB->insert_record('flashcards_card_example_trans', $row);
                }
            }
        }
    }

    /**
     * Normalize examples on fetch, merging DB translations and legacy inline translations.
     *
     * @param array $payload Card payload
     * @param array $dbtranslations Map lang => [idx => text] loaded from DB table
     * @param string|null $langhint Language to assign for legacy inline translations
     * @return array Updated payload
     */
    protected static function normalize_examples_on_fetch(array $payload, array $dbtranslations = [], ?string $langhint = null): array {
        $exampletranslations = [];
        if (!empty($payload['exampleTranslations']) && is_array($payload['exampleTranslations'])) {
            foreach ($payload['exampleTranslations'] as $lng => $items) {
                if (!is_array($items)) { continue; }
                $lng = strtolower(substr((string)$lng, 0, 10));
                if ($lng === '') { continue; }
                foreach ($items as $idx => $val) {
                    $val = trim((string)$val);
                    if ($val === '') { continue; }
                    $exampletranslations[$lng][(int)$idx] = $val;
                }
            }
        }

        // Overlay DB translations (source of truth)
        foreach ($dbtranslations as $lng => $items) {
            if (!is_array($items)) { continue; }
            $lng = strtolower(substr((string)$lng, 0, 10));
            if ($lng === '') { continue; }
            foreach ($items as $idx => $val) {
                $val = trim((string)$val);
                if ($val === '') { continue; }
                $exampletranslations[$lng][(int)$idx] = $val;
            }
        }

        $examples = $payload['examples'] ?? [];
        if (!is_array($examples)) {
            $payload['examples'] = [];
            return $payload;
        }

        $langcode = $langhint ? strtolower(substr($langhint, 0, 10)) : '';
        if ($langcode === '' && function_exists('current_language')) {
            $langcode = strtolower(substr(current_language(), 0, 10));
        }

        $clean = [];
        foreach ($examples as $idx => $ex) {
            $text = '';
            if (is_array($ex)) {
                $text = trim((string)($ex['text'] ?? $ex['no'] ?? ''));
            } else {
                $parts = explode('|', (string)$ex, 2);
                $text = trim($parts[0]);
                if (count($parts) === 2 && $langcode !== '') {
                    $trans = trim($parts[1]);
                    if ($trans !== '') {
                        $exampletranslations[$langcode][(int)$idx] = $trans;
                    }
                }
            }
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        // Drop translations that no longer have a base example
        $max = count($clean);
        if ($max >= 0) {
            foreach ($exampletranslations as $lng => $items) {
                foreach ($items as $i => $val) {
                    if ((int)$i >= $max || trim((string)$val) === '') {
                        unset($exampletranslations[$lng][$i]);
                    }
                }
                if (empty($exampletranslations[$lng])) {
                    unset($exampletranslations[$lng]);
                }
            }
        }

        $payload['examples'] = $clean;
        if (!empty($exampletranslations)) {
            $payload['exampleTranslations'] = $exampletranslations;
        } else {
            unset($payload['exampleTranslations']);
        }

        return $payload;
    }

    /**
     * Fetch per-language example translations for provided cards.
     *
     * @param array $bydeck Map deckId => array of cardIds
     * @return array Map "deck::cardid" => [lang => [idx => text]]
     */
    protected static function fetch_example_translations(array $bydeck): array {
        global $DB;
        $out = [];
        foreach ($bydeck as $deckid => $ids) {
            if (empty($ids)) { continue; }
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
            $rows = $DB->get_records_select(
                'flashcards_card_example_trans',
                'deckid = :deckid AND cardid ' . $insql,
                ['deckid' => (int)$deckid] + $inparams
            );
            foreach ($rows as $row) {
                $key = ((int)$deckid) . '::' . $row->cardid;
                $lng = strtolower((string)$row->lang);
                $idx = (int)$row->example_idx;
                $txt = trim((string)$row->text);
                if ($lng === '' || $idx < 0 || $txt === '') {
                    continue;
                }
                $out[$key][$lng][$idx] = $txt;
            }
        }
        return $out;
    }

    public static function normalize_card_id($text) {
        $text = trim((string)$text);
        if ($text === '') { $text = uniqid('c', false); }
        $text = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $text);
        return substr($text, 0, 64);
    }
    
    public static function upsert_card($userid, array $payload, $globalmode = false, $context = null) {
        global $DB;
        $deckid = (int)($payload['deckId'] ?? 0);
        $cardid = self::normalize_card_id($payload['cardId'] ?? '');
        $scope = clean_param($payload['scope'] ?? 'private', PARAM_ALPHA);
        // Extract payload and translations
        $pp = $payload['payload'] ?? [];
        if (!is_array($pp)) { $pp = []; }
        $pp = self::populate_transcription_if_missing($pp);
        $defaultlang = function_exists('current_language') ? current_language() : '';
        [$exampletranslations, $examplecount] = self::normalize_examples_for_storage($pp, $defaultlang);
        if (!empty($exampletranslations)) {
            $pp['exampleTranslations'] = $exampletranslations;
        } else {
            unset($pp['exampleTranslations']);
        }
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

        // Upsert example translations (per language, per example index)
        self::upsert_example_translations($deckid, $cardid, $exampletranslations, $examplecount);

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
        $isnewcard = false;
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
            $isnewcard = true;
        }

        // Track card creation in stats (only for new cards, not updates)
        if ($isnewcard) {
            self::update_card_creation_stats($userid);
        }

        // Return deckId and cardId so client can update localStorage with correct IDs
        return [
            'deckId' => $deckid,
            'cardId' => $cardid,
        ];
    }
    
    public static function delete_card($userid, $deckid, $cardid, $globalmode = false, $context = null) {
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
            $DB->delete_records('flashcards_card_example_trans', ['deckid' => $deckid, 'cardid' => $cardid]);

            // Decrement total_cards_created counter for the card owner
            if ($rec->ownerid !== null) {
                try {
                    $stats = self::get_user_stats((int)$rec->ownerid);
                    if ($stats && isset($stats->total_cards_created) && $stats->total_cards_created > 0) {
                        $stats->total_cards_created--;
                        $stats->timemodified = time();
                        $DB->update_record('flashcards_user_stats', $stats);
                    }
                } catch (Exception $e) {
                    error_log("Flashcards: Failed to update stats after deletion: " . $e->getMessage());
                }
            }
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
    public static function today0() { return time(); } // Return current timestamp instead of midnight
    
    // Exponential intervals: 2^n days (converted to minutes for testing)
    // Stage 0: card created (no deadline)
    // Stage 1-10: 1, 2, 4, 8, 16, 32, 64, 128, 256, 512 days (in test: minutes)
    // Stage 11+: completed (checkmark)
    public static function srs_due_ts($currentTime, $step, $easy) {
        $currentTime = (int)$currentTime;
        if ($currentTime <= 0) { $currentTime = self::today0(); }
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
    
    public static function get_due_cards($cm, $userid, $flashcardsid, $deckid = 0) {
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
                    $payload = json_decode($c->payload, true);
                    if (!is_array($payload)) { $payload = []; }
                    $payload = self::populate_transcription_if_missing($payload);
                    $out[] = ['deckId' => (int)$c->deckid, 'cardId' => $c->cardid, 'payload' => $payload];
                }
            }
        }
        return $out;
    }
    
    public static function review_card($cm, $userid, $flashcardsid, $deckid, $cardid, $rating, $globalmode = false) {
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
            $due = self::srs_due_ts($now, $step, $easy);
        }
    
        $p->step = $step;
        $p->due = $due;
        $p->lastat = $now;
        $p->timemodified = $now;
        $DB->update_record('flashcards_progress', $p);

        // Track review stats
        // TODO: Calculate actual study time (time between card shown and rated)
        // For now, use 0 as we don't track card show time yet
        self::update_review_stats($userid, $rating, 0);
    }

    // ----------------- Dashboard & Statistics Methods -----------------

    /**
     * Get or create user stats record
     * @param int $userid User ID
     * @return stdClass User stats record
     */
    private static function get_user_stats($userid) {
        global $DB;

        $stats = $DB->get_record('flashcards_user_stats', ['userid' => $userid]);
        if (!$stats) {
            // Create new stats record
            $now = time();
            $stats = (object)[
                'userid' => $userid,
                'total_reviews' => 0,
                'total_cards_created' => 0,
                'current_streak_days' => 0,
                'longest_streak_days' => 0,
                'last_study_date' => 0,
                'first_study_date' => 0,
                'easy_count' => 0,
                'normal_count' => 0,
                'hard_count' => 0,
                'total_study_time' => 0,
                'timemodified' => $now
            ];
            $stats->id = $DB->insert_record('flashcards_user_stats', $stats);
        }
        return $stats;
    }

    /**
     * Update user stats after a review
     * @param int $userid User ID
     * @param int $rating Rating (1=hard, 2=normal, 3=easy)
     * @param int $studytime Study time in seconds
     */
    public static function update_review_stats($userid, $rating, $studytime = 0) {
        global $DB;

        $stats = self::get_user_stats($userid);
        $now = time();
        $today = strtotime('today', $now);

        // Increment total reviews
        $stats->total_reviews++;

        // Increment rating-specific counter
        if ($rating <= 1) {
            $stats->hard_count++;
        } else if ($rating == 2) {
            $stats->normal_count++;
        } else {
            $stats->easy_count++;
        }

        // Update study time
        $stats->total_study_time += $studytime;

        // Calculate streak
        $lastStudyDay = strtotime('today', $stats->last_study_date);
        if ($stats->last_study_date == 0) {
            // First study ever
            $stats->first_study_date = $now;
            $stats->current_streak_days = 1;
        } else if ($lastStudyDay == $today) {
            // Same day - streak doesn't change
        } else if ($lastStudyDay == strtotime('-1 day', $today)) {
            // Consecutive day - increment streak
            $stats->current_streak_days++;
        } else {
            // Streak broken - reset to 1
            $stats->current_streak_days = 1;
        }

        // Update longest streak
        if ($stats->current_streak_days > $stats->longest_streak_days) {
            $stats->longest_streak_days = $stats->current_streak_days;
        }

        $stats->last_study_date = $now;
        $stats->timemodified = $now;
        $DB->update_record('flashcards_user_stats', $stats);

        // Update daily log
        self::update_daily_log($userid, $today, 'reviews_count', 1);
        self::update_daily_log($userid, $today, 'study_time', $studytime);
    }

    /**
     * Update user stats after creating a card
     * @param int $userid User ID
     */
    public static function update_card_creation_stats($userid) {
        global $DB;

        $stats = self::get_user_stats($userid);
        $now = time();
        $today = strtotime('today', $now);

        // Increment total cards created
        $stats->total_cards_created++;

        // Set first study date if not set
        if ($stats->first_study_date == 0) {
            $stats->first_study_date = $now;
        }

        $stats->timemodified = $now;
        $DB->update_record('flashcards_user_stats', $stats);

        // Update daily log
        self::update_daily_log($userid, $today, 'cards_created', 1);
    }

    /**
     * Update daily log for a specific metric
     * @param int $userid User ID
     * @param int $logdate Date timestamp (midnight)
     * @param string $field Field to increment (reviews_count, cards_created, study_time)
     * @param int $increment Amount to increment
     */
    private static function update_daily_log($userid, $logdate, $field, $increment) {
        global $DB;

        $log = $DB->get_record('flashcards_daily_log', ['userid' => $userid, 'log_date' => $logdate]);
        if (!$log) {
            // Create new log entry
            $log = (object)[
                'userid' => $userid,
                'log_date' => $logdate,
                'reviews_count' => 0,
                'cards_created' => 0,
                'study_time' => 0
            ];
            $log->id = $DB->insert_record('flashcards_daily_log', $log);
        }

        // Increment the field
        $log->$field += $increment;
        $DB->update_record('flashcards_daily_log', $log);
    }

    /**
     * Recalculate total_cards_created from actual database count
     * This fixes any discrepancies that may have occurred
     * @param int $userid User ID
     * @return int The actual card count
     */
    public static function recalculate_total_cards($userid) {
        global $DB;

        // Count actual cards owned by this user
        $actual_count = $DB->count_records_select(
            'flashcards_cards',
            'ownerid = :userid AND scope = :scope',
            ['userid' => $userid, 'scope' => 'private']
        );

        // Update stats table
        $stats = self::get_user_stats($userid);
        $stats->total_cards_created = $actual_count;
        $stats->timemodified = time();
        $DB->update_record('flashcards_user_stats', $stats);

        return $actual_count;
    }

    /**
     * Get dashboard data for a user
     * @param int $userid User ID
     * @return array Dashboard data
     */
    public static function get_dashboard_data($userid) {
        global $DB;

        $stats = self::get_user_stats($userid);
        $now = time();
        $today = strtotime('today', $now);

        // Get cards due today count (only cards that still exist and are visible to the user)
        $sqlDue = "SELECT COUNT(1)
                     FROM {flashcards_progress} p
                     JOIN {flashcards_cards} c ON c.deckid = p.deckid AND c.cardid = p.cardid
                     JOIN {flashcards_decks} d ON d.id = c.deckid
                    WHERE p.userid = :userid
                      AND p.due <= :now
                      AND p.hidden = 0
                      AND ((d.scope = 'private' AND (d.userid IS NULL OR d.userid = :userid2))
                           OR d.scope = 'shared')
                      AND ((c.scope = 'private' AND c.ownerid = :ownerid)
                           OR c.scope = 'shared')";
        $dueToday = (int)$DB->count_records_sql($sqlDue, [
            'userid' => $userid,
            'userid2' => $userid,
            'ownerid' => $userid,
            'now' => $now,
        ]);

        // Get stage distribution
        $sql = "SELECT step, COUNT(*) as count
                FROM {flashcards_progress}
                WHERE userid = :userid AND hidden = 0
                GROUP BY step
                ORDER BY step ASC";
        $stageData = $DB->get_records_sql($sql, ['userid' => $userid]);
        $activeVocabScore = self::calculate_active_vocab_from_rows($stageData);

        $stageDistribution = [];
        foreach ($stageData as $row) {
            $stageDistribution[] = [
                'stage' => (int)$row->step,
                'count' => (int)$row->count
            ];
        }

        // Get last 7 days of activity
        $activityData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = strtotime("-{$i} days", $today);
            $log = $DB->get_record('flashcards_daily_log', ['userid' => $userid, 'log_date' => $date]);
            $activityData[] = [
                'date' => $date,
                'reviews' => $log ? (int)$log->reviews_count : 0,
                'cardsCreated' => $log ? (int)$log->cards_created : 0
            ];
        }

        // Get today's cards created count
        $todayLog = $DB->get_record('flashcards_daily_log', ['userid' => $userid, 'log_date' => $today]);
        $cardsCreatedToday = $todayLog ? (int)$todayLog->cards_created : 0;

        return [
            'stats' => [
                'dueToday' => $dueToday,
                'totalCardsCreated' => (int)$stats->total_cards_created,
                'activeVocab' => round($activeVocabScore, 2),
                'currentStreak' => (int)$stats->current_streak_days,
                'longestStreak' => (int)$stats->longest_streak_days,
                'totalStudyTime' => (int)$stats->total_study_time,
                'totalReviews' => (int)$stats->total_reviews,
                'easyCount' => (int)$stats->easy_count,
                'normalCount' => (int)$stats->normal_count,
                'hardCount' => (int)$stats->hard_count,
                'cardsCreatedToday' => $cardsCreatedToday
            ],
            'stageDistribution' => $stageDistribution,
            'activityData' => $activityData
        ];
    }

    /**
     * Calculate the active vocabulary score for a user using the normalized log formula.
     *
     * @param int $userid User ID
     * @return float
     */
    public static function calculate_active_vocab($userid): float {
        global $DB;

        $sql = "SELECT step, COUNT(*) as count
                  FROM {flashcards_progress}
                 WHERE userid = :userid AND hidden = 0
              GROUP BY step";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);
        return self::calculate_active_vocab_from_rows($rows);
    }

    /**
     * Convert stage distribution rows into an active vocabulary score.
     *
     * @param iterable $stageRows Rows with ->step and ->count
     * @return float
     */
    private static function calculate_active_vocab_from_rows($stageRows): float {
        $logmax = log(11);
        if ($logmax <= 0) {
            return 0.0;
        }

        $score = 0.0;
        if (!empty($stageRows)) {
            foreach ($stageRows as $row) {
                $step = isset($row->step) ? (int)$row->step : 0;
                $count = isset($row->count) ? (int)$row->count : 0;
                if ($count <= 0 || $step < 1) {
                    continue;
                }
                if ($step > 10) {
                    $step = 10;
                }
                $score += $count * (log(1 + $step) / $logmax);
            }
        }
        return $score;
    }
}
