<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_flashcards_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025102401) {
        $table = new xmldb_table('flashcards_progress');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('flashcardsid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('deckid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('cardid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('step', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('due', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('addedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('hidden', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('flashcards_user_idx', XMLDB_INDEX_NOTUNIQUE, ['flashcardsid', 'userid']);
        $table->add_index('progress_unique', XMLDB_INDEX_UNIQUE, ['flashcardsid', 'userid', 'deckid', 'cardid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2025102401, 'flashcards');
    }

    // Add decks and cards repository tables.
    if ($oldversion < 2025102501) {
        // flashcards_decks.
        $table = new xmldb_table('flashcards_decks');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('meta', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // flashcards_cards.
        $table = new xmldb_table('flashcards_cards');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('deckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cardid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('unique_card', XMLDB_INDEX_UNIQUE, ['deckid', 'cardid']);
        $table->add_index('deck_idx', XMLDB_INDEX_NOTUNIQUE, ['deckid']);
        $table->add_index('owner_idx', XMLDB_INDEX_NOTUNIQUE, ['ownerid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2025102501, 'flashcards');
    }

    // Global access system - remove activity dependency, add user access control.
    if ($oldversion < 2025102700) {
        // 1. Modify flashcards_decks: add userid, scope, access_idnumbers, make courseid nullable.
        $table = new xmldb_table('flashcards_decks');

        // Add userid field (owner for private decks).
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add scope field (private | shared).
        $field = new xmldb_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'private', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add access_idnumbers field (JSON array of completion idnumbers for shared decks).
        $field = new xmldb_field('access_idnumbers', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Make courseid nullable (private decks don't belong to a course).
        // IMPORTANT: Must drop index first, then change field, then recreate index.
        $index = new xmldb_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        // Recreate index on courseid (nullable fields can have indexes in Moodle).
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add index on userid.
        $index = new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 2. Modify flashcards_progress: make flashcardsid nullable (decoupled from activity).
        $table = new xmldb_table('flashcards_progress');

        // Drop indexes that depend on flashcardsid field.
        $index = new xmldb_index('flashcards_user_idx', XMLDB_INDEX_NOTUNIQUE, ['flashcardsid', 'userid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $index = new xmldb_index('progress_unique', XMLDB_INDEX_UNIQUE, ['flashcardsid', 'userid', 'deckid', 'cardid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Now safe to change field.
        $field = new xmldb_field('flashcardsid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        // Recreate indexes (nullable fields can be indexed).
        $index = new xmldb_index('flashcards_user_idx', XMLDB_INDEX_NOTUNIQUE, ['flashcardsid', 'userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('progress_unique', XMLDB_INDEX_UNIQUE, ['flashcardsid', 'userid', 'deckid', 'cardid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 3. Create flashcards_user_access table.
        $table = new xmldb_table('flashcards_user_access');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('last_enrolment_check', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('grace_period_start', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('grace_period_days', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30');
        $table->add_field('blocked_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 4. Create flashcards_completion_cache table.
        $table = new xmldb_table('flashcards_completion_cache');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('idnumber', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_idnumber_uix', XMLDB_INDEX_UNIQUE, ['userid', 'idnumber']);
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('idnumber_idx', XMLDB_INDEX_NOTUNIQUE, ['idnumber']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 5. Migrate existing decks data.
        // All existing decks with courseid should become 'shared' scope.
        // Private decks (created by users) will be migrated when they create first card in new system.
        $DB->execute("UPDATE {flashcards_decks} SET scope = 'shared' WHERE courseid IS NOT NULL");

        // Initialize flashcards_user_access for all users who have progress records.
        $users = $DB->get_records_sql("SELECT DISTINCT userid FROM {flashcards_progress}");
        foreach ($users as $user) {
            if (!$DB->record_exists('flashcards_user_access', ['userid' => $user->userid])) {
                $record = (object)[
                    'userid' => $user->userid,
                    'status' => 'active',
                    'last_enrolment_check' => time(),
                    'grace_period_days' => 30,
                    'timemodified' => time()
                ];
                $DB->insert_record('flashcards_user_access', $record);
            }
        }

        upgrade_mod_savepoint(true, 2025102700, 'flashcards');
    }

    // Add per-language translations table and migrate existing data.
    if ($oldversion < 2025103103) {
        $table = new xmldb_table('flashcards_card_trans');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('deckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cardid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('text', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('deck_card_lang_uix', XMLDB_INDEX_UNIQUE, ['deckid', 'cardid', 'lang']);
        $table->add_index('deck_idx', XMLDB_INDEX_NOTUNIQUE, ['deckid']);
        $table->add_index('lang_idx', XMLDB_INDEX_NOTUNIQUE, ['lang']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Best-effort migration: read translations from payload JSON, if present.
        $lastid = 0;
        $batchsize = 500;
        do {
            $records = $DB->get_records_select('flashcards_cards', 'id > :lastid', ['lastid' => $lastid], 'id ASC', 'id, deckid, cardid, payload', $lastid, $batchsize);
            if (!$records) { break; }
            foreach ($records as $rec) {
                $lastid = (int)$rec->id;
                $p = json_decode($rec->payload ?? '{}');
                if (!$p) { continue; }
                // 1) New-format object p->translations
                if (isset($p->translations) && is_object($p->translations)) {
                    foreach ($p->translations as $lang => $text) {
                        $lang = strtolower(substr((string)$lang, 0, 10));
                        $text = (string)$text;
                        if ($lang && $text !== '') {
                            // Upsert by unique index
                            $existing = $DB->get_record('flashcards_card_trans', ['deckid'=>$rec->deckid,'cardid'=>$rec->cardid,'lang'=>$lang]);
                            $row = (object)[
                                'deckid' => (int)$rec->deckid,
                                'cardid' => (string)$rec->cardid,
                                'lang' => $lang,
                                'text' => $text,
                                'timemodified' => time(),
                            ];
                            if ($existing) { $row->id = $existing->id; $DB->update_record('flashcards_card_trans', $row); }
                            else { $DB->insert_record('flashcards_card_trans', $row); }
                        }
                    }
                } else if (!empty($p->translation)) {
                    // 2) Legacy single translation => put into English by default
                    $lang = 'en';
                    $text = (string)$p->translation;
                    $existing = $DB->get_record('flashcards_card_trans', ['deckid'=>$rec->deckid,'cardid'=>$rec->cardid,'lang'=>$lang]);
                    $row = (object)[
                        'deckid' => (int)$rec->deckid,
                        'cardid' => (string)$rec->cardid,
                        'lang' => $lang,
                        'text' => $text,
                        'timemodified' => time(),
                    ];
                    if ($existing) { $row->id = $existing->id; $DB->update_record('flashcards_card_trans', $row); }
                    else { $DB->insert_record('flashcards_card_trans', $row); }
                }
            }
        } while (count($records) === $batchsize);

        upgrade_mod_savepoint(true, 2025103103, 'flashcards');
    }

    // Fix for version 2025102700: Handle cases where upgrade was interrupted.
    // This step ensures all fields are properly set even if previous upgrade failed partway.
    if ($oldversion < 2025102701) {
        $table = new xmldb_table('flashcards_decks');

        // Ensure scope field exists with default.
        $field = new xmldb_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'private', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ensure userid field exists.
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ensure access_idnumbers field exists.
        $field = new xmldb_field('access_idnumbers', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ensure userid index exists.
        $index = new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Ensure user_access table exists.
        $table = new xmldb_table('flashcards_user_access');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('last_enrolment_check', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('grace_period_start', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('grace_period_days', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30');
            $table->add_field('blocked_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        // Ensure completion_cache table exists.
        $table = new xmldb_table('flashcards_completion_cache');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('idnumber', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_idnumber_uix', XMLDB_INDEX_UNIQUE, ['userid', 'idnumber']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('idnumber_idx', XMLDB_INDEX_NOTUNIQUE, ['idnumber']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2025102701, 'flashcards');
    }

    // Additional safety check for version 2025102701: ensure progress table indexes are correct.
    if ($oldversion < 2025102702) {
        $table = new xmldb_table('flashcards_progress');

        // Ensure both indexes exist (they may have been dropped but not recreated).
        $index = new xmldb_index('flashcards_user_idx', XMLDB_INDEX_NOTUNIQUE, ['flashcardsid', 'userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('progress_unique', XMLDB_INDEX_UNIQUE, ['flashcardsid', 'userid', 'deckid', 'cardid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2025102702, 'flashcards');
    }

    // Global mode compatibility fix: Update flashcardsid to NULL and consolidate duplicate decks.
    if ($oldversion < 2025102705) {
        // Fix 1: Set flashcardsid to NULL for all progress records (global mode compatibility).
        // This allows fetch_progress() to retrieve all cards regardless of flashcardsid.
        mtrace('Flashcards: Setting flashcardsid to NULL for global mode compatibility...');
        $DB->execute("UPDATE {flashcards_progress} SET flashcardsid = NULL WHERE flashcardsid IS NOT NULL");

        // Fix 2: Consolidate duplicate "My cards" decks per user.
        // This happens when users create cards in both activity and global modes.
        mtrace('Flashcards: Consolidating duplicate "My cards" decks...');

        $sql = "SELECT userid, COUNT(*) as cnt
                FROM {flashcards_decks}
                WHERE scope = 'private' AND title = 'My cards'
                GROUP BY userid
                HAVING cnt > 1";
        $duplicates = $DB->get_records_sql($sql);

        foreach ($duplicates as $dup) {
            mtrace("  - User {$dup->userid} has {$dup->cnt} duplicate decks, consolidating...");

            // Get all "My cards" decks for this user, oldest first.
            $decks = $DB->get_records('flashcards_decks', [
                'userid' => $dup->userid,
                'scope' => 'private',
                'title' => 'My cards'
            ], 'id ASC');

            if (count($decks) > 1) {
                $decksarray = array_values($decks);
                $keep = array_shift($decksarray); // Keep first (oldest) deck.

                foreach ($decksarray as $deck) {
                    // Update cards to point to kept deck.
                    $DB->execute("UPDATE {flashcards_cards} SET deckid = ? WHERE deckid = ?",
                        [$keep->id, $deck->id]);

                    // Update progress to point to kept deck.
                    // Use UPDATE IGNORE pattern to handle duplicates.
                    $cards = $DB->get_records('flashcards_progress', ['deckid' => $deck->id]);
                    foreach ($cards as $card) {
                        // Check if this card already exists in kept deck.
                        $exists = $DB->record_exists('flashcards_progress', [
                            'userid' => $card->userid,
                            'deckid' => $keep->id,
                            'cardid' => $card->cardid
                        ]);

                        if (!$exists) {
                            // Move to kept deck.
                            $card->deckid = $keep->id;
                            $DB->update_record('flashcards_progress', $card);
                        } else {
                            // Duplicate - keep the one with higher step.
                            $existing = $DB->get_record('flashcards_progress', [
                                'userid' => $card->userid,
                                'deckid' => $keep->id,
                                'cardid' => $card->cardid
                            ]);

                            if ($card->step > $existing->step) {
                                $existing->step = $card->step;
                                $existing->due = $card->due;
                                $existing->lastat = $card->lastat;
                                $DB->update_record('flashcards_progress', $existing);
                            }

                            // Delete duplicate.
                            $DB->delete_records('flashcards_progress', ['id' => $card->id]);
                        }
                    }

                    // Delete duplicate deck.
                    $DB->delete_records('flashcards_decks', ['id' => $deck->id]);
                    mtrace("    - Deleted duplicate deck ID {$deck->id}");
                }
            }
        }

        mtrace('Flashcards: Global mode compatibility fix complete.');
        upgrade_mod_savepoint(true, 2025102705, 'flashcards');
    }

    if ($oldversion < 2025102706) {
        mtrace('Flashcards: Fixing progress tracking to sync across decks...');

        $table = new xmldb_table('flashcards_progress');

        // Step 1: Drop old unique index (flashcardsid, userid, deckid, cardid)
        $oldindex = new xmldb_index('progress_unique', XMLDB_INDEX_UNIQUE, ['flashcardsid', 'userid', 'deckid', 'cardid']);
        if ($dbman->index_exists($table, $oldindex)) {
            mtrace('  - Dropping old progress_unique index...');
            $dbman->drop_index($table, $oldindex);
        }

        // Step 2: Consolidate duplicate progress records (keep highest step)
        mtrace('  - Consolidating duplicate progress records...');

        // IMPROVED: Use direct SQL to handle all duplicates in one pass
        // First pass: Mark which records to keep (highest step, then most recent)
        $sql = "SELECT p1.id
                FROM {flashcards_progress} p1
                WHERE EXISTS (
                    SELECT 1
                    FROM {flashcards_progress} p2
                    WHERE p2.userid = p1.userid
                      AND p2.cardid = p1.cardid
                      AND (p2.step > p1.step OR (p2.step = p1.step AND p2.lastat > p1.lastat))
                )";
        $toDelete = $DB->get_records_sql($sql);

        if (!empty($toDelete)) {
            mtrace("  - Found " . count($toDelete) . " duplicate records to remove");
            foreach ($toDelete as $record) {
                $DB->delete_records('flashcards_progress', ['id' => $record->id]);
            }
        }

        // Second pass: Verify and clean any remaining duplicates (safety check)
        $sql = "SELECT userid, cardid, COUNT(*) as cnt
                FROM {flashcards_progress}
                GROUP BY userid, cardid
                HAVING cnt > 1";
        $remaining = $DB->get_records_sql($sql);

        if (!empty($remaining)) {
            mtrace("  - Found " . count($remaining) . " remaining duplicates, cleaning manually...");
            foreach ($remaining as $dup) {
                $records = $DB->get_records('flashcards_progress', [
                    'userid' => $dup->userid,
                    'cardid' => $dup->cardid
                ], 'step DESC, lastat DESC, id ASC');

                $recordsarray = array_values($records);
                $keep = array_shift($recordsarray); // Keep first (highest step)

                mtrace("    - User {$dup->userid}, Card {$dup->cardid}: keeping ID {$keep->id} (step {$keep->step})");

                // Delete all others
                foreach ($recordsarray as $record) {
                    $DB->delete_records('flashcards_progress', ['id' => $record->id]);
                }
            }
        } else {
            mtrace("  - All duplicates resolved");
        }

        // Step 3: Drop flashcards_user_idx index as well (references flashcardsid)
        $userindex = new xmldb_index('flashcards_user_idx', XMLDB_INDEX_NOTUNIQUE, ['flashcardsid', 'userid']);
        if ($dbman->index_exists($table, $userindex)) {
            mtrace('  - Dropping flashcards_user_idx index...');
            $dbman->drop_index($table, $userindex);
        }

        // Step 4: Final verification before creating unique index
        mtrace('  - Final verification: checking for any remaining duplicates...');
        $sql = "SELECT userid, cardid, COUNT(*) as cnt
                FROM {flashcards_progress}
                GROUP BY userid, cardid
                HAVING cnt > 1";
        $finalCheck = $DB->get_records_sql($sql);

        if (!empty($finalCheck)) {
            // Emergency: force delete all but one record
            mtrace('  - Emergency cleanup: ' . count($finalCheck) . ' duplicates still exist');
            foreach ($finalCheck as $dup) {
                // Get all records for this (userid, cardid)
                $records = $DB->get_records('flashcards_progress', [
                    'userid' => $dup->userid,
                    'cardid' => $dup->cardid
                ], 'step DESC, lastat DESC, id DESC'); // Keep highest step, most recent

                $recordsarray = array_values($records);
                $keep = array_shift($recordsarray); // Keep first record

                mtrace("    - Emergency: User {$dup->userid}, Card {$dup->cardid}: keeping ID {$keep->id}");

                // Delete ALL others by ID
                foreach ($recordsarray as $record) {
                    $DB->delete_records('flashcards_progress', ['id' => $record->id]);
                }
            }

            // Verify once more
            $sql = "SELECT userid, cardid, COUNT(*) as cnt
                    FROM {flashcards_progress}
                    GROUP BY userid, cardid
                    HAVING cnt > 1";
            $stillDuplicate = $DB->get_records_sql($sql);
            if (!empty($stillDuplicate)) {
                throw new moodle_exception('Unable to remove duplicates from flashcards_progress. Found: ' . count($stillDuplicate));
            }
        }

        // Step 5: Create new unique index (userid, cardid)
        $newindex = new xmldb_index('user_card_unique', XMLDB_INDEX_UNIQUE, ['userid', 'cardid']);
        if (!$dbman->index_exists($table, $newindex)) {
            mtrace('  - Creating new user_card_unique index...');
            $dbman->add_index($table, $newindex);
        }

        // Step 5: Recreate flashcards_user_idx without flashcardsid
        $newuserindex = new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $newuserindex)) {
            mtrace('  - Creating userid_idx index...');
            $dbman->add_index($table, $newuserindex);
        }

        mtrace('Flashcards: Progress sync fix complete - progress now tracks by userid+cardid only.');

        // Step 6: Consolidate all "My cards" decks into one global deck
        mtrace('Flashcards: Consolidating all "My cards" decks into single global deck...');

        // Find or create global "My cards" deck (userid=null, scope=private)
        $globalDeck = $DB->get_record('flashcards_decks', [
            'title' => 'My cards',
            'scope' => 'private',
            'userid' => null
        ]);

        if (!$globalDeck) {
            // Create global deck
            $now = time();
            $globalDeck = (object)[
                'courseid' => null,
                'userid' => null, // NULL = global deck
                'scope' => 'private',
                'title' => 'My cards',
                'meta' => json_encode(new stdClass()),
                'createdby' => 2, // System user (admin)
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $globalDeck->id = $DB->insert_record('flashcards_decks', $globalDeck);
            mtrace("  - Created global 'My cards' deck (ID: {$globalDeck->id})");
        } else {
            mtrace("  - Using existing global 'My cards' deck (ID: {$globalDeck->id})");
        }

        // Find all per-user "My cards" decks
        $sql = "SELECT * FROM {flashcards_decks}
                WHERE title = 'My cards'
                  AND scope = 'private'
                  AND userid IS NOT NULL";
        $userDecks = $DB->get_records_sql($sql);

        mtrace("  - Found " . count($userDecks) . " per-user 'My cards' decks to consolidate");

        foreach ($userDecks as $deck) {
            // Move all cards from user deck to global deck
            $cards = $DB->get_records('flashcards_cards', ['deckid' => $deck->id]);
            mtrace("    - Deck ID {$deck->id} (user {$deck->userid}): " . count($cards) . " cards");

            foreach ($cards as $card) {
                // Check if card with same cardid already exists in global deck
                $existing = $DB->get_record('flashcards_cards', [
                    'deckid' => $globalDeck->id,
                    'cardid' => $card->cardid
                ]);

                if ($existing) {
                    // Card already exists in global deck - skip (unique constraint on deckid+cardid)
                    mtrace("      - Card {$card->cardid} already exists in global deck, skipping");
                    // Delete the duplicate card from user deck
                    $DB->delete_records('flashcards_cards', ['id' => $card->id]);
                } else {
                    // Move card to global deck
                    $DB->execute("UPDATE {flashcards_cards} SET deckid = ? WHERE id = ?",
                        [$globalDeck->id, $card->id]);
                }
            }

            // Update progress records to point to global deck
            $DB->execute("UPDATE {flashcards_progress} SET deckid = ? WHERE deckid = ?",
                [$globalDeck->id, $deck->id]);

            // Delete the user deck
            $DB->delete_records('flashcards_decks', ['id' => $deck->id]);
            mtrace("    - Deleted user deck ID {$deck->id}");
        }

        mtrace('Flashcards: Deck consolidation complete - all cards now in single global "My cards" deck.');
        upgrade_mod_savepoint(true, 2025102706, 'flashcards');
    }

    if ($oldversion < 2025102707) {
        mtrace('Flashcards: Fixing deckid type and recreating Deck 1...');

        $table = new xmldb_table('flashcards_progress');

        // Step 1: Change deckid type from CHAR to INT
        mtrace('  - Changing deckid field type from CHAR to INT...');
        $field = new xmldb_field('deckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'userid');

        // First, convert all existing string values to integers
        mtrace('  - Converting existing deckid values to integers...');
        $DB->execute("UPDATE {flashcards_progress} SET deckid = CAST(deckid AS UNSIGNED) WHERE deckid REGEXP '^[0-9]+$'");

        // For 'my-deck' values, set to 1 (will be our global deck)
        $DB->execute("UPDATE {flashcards_progress} SET deckid = 1 WHERE deckid NOT REGEXP '^[0-9]+$'");

        // Now change the field type
        $dbman->change_field_type($table, $field);

        // Step 2: Delete old decks (1 and 5)
        mtrace('  - Deleting old deck records...');
        $DB->delete_records('flashcards_decks', ['id' => 1]);
        $DB->delete_records('flashcards_decks', ['id' => 5]);

        // Step 3: Create NEW global Deck with ID=1
        mtrace('  - Creating new global Deck with ID=1...');
        $now = time();
        $newDeck = (object)[
            'id' => 1,
            'courseid' => null,
            'userid' => null, // NULL = global deck for all users
            'scope' => 'private', // Private scope (user-created cards)
            'access_idnumbers' => null,
            'title' => 'My cards',
            'meta' => json_encode(new stdClass()),
            'createdby' => 2, // Admin
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        // Force insert with ID=1
        $DB->execute("INSERT INTO {flashcards_decks} (id, courseid, userid, scope, access_idnumbers, title, meta, createdby, timecreated, timemodified)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            1, null, null, 'private', null, 'My cards', json_encode(new stdClass()), 2, $now, $now
        ]);

        mtrace("  - Created global Deck with ID=1");

        // Step 4: Move all cards from Deck 5 to Deck 1
        mtrace('  - Moving all cards to Deck 1...');
        $movedCards = $DB->execute("UPDATE {flashcards_cards} SET deckid = 1 WHERE deckid = 5");
        mtrace("  - Moved cards to Deck 1");

        // Step 5: Update all progress records to use deckid=1
        mtrace('  - Updating progress records to use deckid=1...');
        $DB->execute("UPDATE {flashcards_progress} SET deckid = 1 WHERE deckid = 5");
        $DB->execute("UPDATE {flashcards_progress} SET deckid = 1 WHERE deckid != 1");
        mtrace("  - All progress records now use deckid=1");

        // Step 6: Clean orphaned progress records (progress without cards)
        mtrace('  - Cleaning orphaned progress records...');
        $sql = "DELETE p FROM {flashcards_progress} p
                LEFT JOIN {flashcards_cards} c ON c.deckid = p.deckid AND c.cardid = p.cardid
                WHERE c.id IS NULL";
        $DB->execute($sql);
        mtrace('  - Orphaned progress records cleaned');

        // Step 7: Reset AUTO_INCREMENT for flashcards_decks to 2 (so next deck will be ID=2)
        mtrace('  - Resetting AUTO_INCREMENT for flashcards_decks...');
        $DB->execute("ALTER TABLE {flashcards_decks} AUTO_INCREMENT = 2");

        mtrace('Flashcards: Deck 1 recreated, deckid type fixed, all data consolidated.');
        upgrade_mod_savepoint(true, 2025102707, 'flashcards');
    }

    if ($oldversion < 2025102712) {
        mtrace('Flashcards: Migrating media files from module contexts to user contexts...');

        // Problem: Files created in activity mode were stored in module context.
        // When activity is deleted, files are deleted too, breaking cards.
        // Solution: Move all files from module contexts to user contexts.

        $fs = get_file_storage();

        // Find all files in mod_flashcards that are in module contexts
        $sql = "SELECT f.*, ctx.instanceid as cmid
                FROM {files} f
                JOIN {context} ctx ON ctx.id = f.contextid
                WHERE f.component = 'mod_flashcards'
                  AND f.filearea = 'media'
                  AND ctx.contextlevel = :ctxmodule
                  AND f.filename != '.'
                ORDER BY f.id";

        $modulefiles = $DB->get_records_sql($sql, ['ctxmodule' => CONTEXT_MODULE]);

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($modulefiles as $oldfile) {
            try {
                // itemid in our system = userid (the owner of the file)
                $userid = $oldfile->itemid;

                if (!$userid || $userid <= 0) {
                    mtrace("  - Skipping file {$oldfile->filename}: invalid userid");
                    $skipped++;
                    continue;
                }

                // Get user context
                $usercontext = context_user::instance($userid, IGNORE_MISSING);
                if (!$usercontext) {
                    mtrace("  - Skipping file {$oldfile->filename}: user context not found for userid={$userid}");
                    $skipped++;
                    continue;
                }

                // Check if file already exists in user context (maybe already migrated)
                $storedfile = $fs->get_file_instance($oldfile);
                if (!$storedfile) {
                    mtrace("  - Skipping: file instance not found");
                    $skipped++;
                    continue;
                }

                $existing = $fs->get_file(
                    $usercontext->id,
                    $oldfile->component,
                    $oldfile->filearea,
                    $oldfile->itemid,
                    $oldfile->filepath,
                    $oldfile->filename
                );

                if ($existing) {
                    // File already exists in user context, just delete old one
                    mtrace("  - File {$oldfile->filename} already in user context, deleting old copy");
                    $storedfile->delete();
                    $migrated++;
                    continue;
                }

                // Create new file in user context
                $newfilerecord = [
                    'contextid' => $usercontext->id,
                    'component' => $oldfile->component,
                    'filearea' => $oldfile->filearea,
                    'itemid' => $oldfile->itemid,
                    'filepath' => $oldfile->filepath,
                    'filename' => $oldfile->filename,
                ];

                // Create copy in user context
                $fs->create_file_from_storedfile($newfilerecord, $storedfile);

                // Delete old file from module context
                $storedfile->delete();

                mtrace("  - Migrated: {$oldfile->filename} (contextid {$oldfile->contextid} → {$usercontext->id})");
                $migrated++;

            } catch (Exception $e) {
                mtrace("  - ERROR migrating {$oldfile->filename}: " . $e->getMessage());
                $errors++;
            }
        }

        mtrace("Migration complete: migrated={$migrated}, skipped={$skipped}, errors={$errors}");

        upgrade_mod_savepoint(true, 2025102712, 'flashcards');
    }

    // Add dashboard tables: user stats and daily log (v0.7.0)
    if ($oldversion < 2025110301) {
        mtrace('Flashcards: Adding dashboard tables (user_stats and daily_log)...');

        // Create flashcards_user_stats table
        $table = new xmldb_table('flashcards_user_stats');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_reviews', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total_cards_created', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('current_streak_days', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('longest_streak_days', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_study_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('first_study_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('easy_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('normal_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('hard_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total_study_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('  - Created flashcards_user_stats table');
        }

        // Create flashcards_daily_log table
        $table = new xmldb_table('flashcards_daily_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('log_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reviews_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cards_created', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('study_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_date_uix', XMLDB_INDEX_UNIQUE, ['userid', 'log_date']);
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('date_idx', XMLDB_INDEX_NOTUNIQUE, ['log_date']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('  - Created flashcards_daily_log table');
        }

        // Initialize stats for existing users
        mtrace('  - Initializing stats for existing users...');
        $users = $DB->get_records_sql("SELECT DISTINCT userid FROM {flashcards_progress}");
        foreach ($users as $user) {
            if (!$DB->record_exists('flashcards_user_stats', ['userid' => $user->userid])) {
                // Count existing cards owned by this user
                $totalCards = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT p.cardid)
                     FROM {flashcards_progress} p
                     WHERE p.userid = :userid",
                    ['userid' => $user->userid]
                );

                $record = (object)[
                    'userid' => $user->userid,
                    'total_reviews' => 0,
                    'total_cards_created' => $totalCards, // Use actual count
                    'current_streak_days' => 0,
                    'longest_streak_days' => 0,
                    'last_study_date' => 0,
                    'first_study_date' => 0,
                    'easy_count' => 0,
                    'normal_count' => 0,
                    'hard_count' => 0,
                    'total_study_time' => 0,
                    'timemodified' => time()
                ];
                $DB->insert_record('flashcards_user_stats', $record);
                mtrace("    - User {$user->userid}: {$totalCards} cards");
            }
        }

        mtrace('Flashcards: Dashboard tables created and initialized successfully.');
        upgrade_mod_savepoint(true, 2025110301, 'flashcards');
    }

    // Fix total_cards_created for existing users (v0.7.0 - part 2)
    if ($oldversion < 2025110302) {
        mtrace('Flashcards: Recalculating total_cards_created for existing users...');

        $stats = $DB->get_records('flashcards_user_stats');
        foreach ($stats as $stat) {
            // Count actual cards owned by user
            $totalCards = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT p.cardid)
                 FROM {flashcards_progress} p
                 WHERE p.userid = :userid",
                ['userid' => $stat->userid]
            );

            if ($totalCards != $stat->total_cards_created) {
                $stat->total_cards_created = $totalCards;
                $stat->timemodified = time();
                $DB->update_record('flashcards_user_stats', $stat);
                mtrace("  - User {$stat->userid}: updated from {$stat->total_cards_created} to {$totalCards} cards");
            }
        }

        mtrace('Flashcards: Total cards recalculated successfully.');
        upgrade_mod_savepoint(true, 2025110302, 'flashcards');
    }

    if ($oldversion < 2025110700) {
        $table = new xmldb_table('flashcards_orbokene');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('entry', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('normalized', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('baseform', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('grammar', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('definition', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('translation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('examplesjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('meta', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('normalized_uix', XMLDB_INDEX_UNIQUE, ['normalized']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('Flashcards: Added flashcards_orbokene table for AI dictionary cache.');
        }

        upgrade_mod_savepoint(true, 2025110700, 'flashcards');
    }

    // Add push notification subscriptions table (v0.14.0)
    if ($oldversion < 2025112000) {
        mtrace('Flashcards: Adding push notification subscriptions table...');

        $table = new xmldb_table('flashcards_push_subs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('endpoint', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('p256dh', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('auth', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'en');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('enabled_idx', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('  - Created flashcards_push_subs table');
        }

        mtrace('Flashcards: Push notification subscriptions table created successfully.');
        upgrade_mod_savepoint(true, 2025112000, 'flashcards');
    }

    if ($oldversion < 2025121200) {
        mtrace('Flashcards: Adding TTS usage tracking table...');

        $table = new xmldb_table('flashcards_tts_usage');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('period_start', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('characters', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('requests', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('user_provider_period_uix', XMLDB_INDEX_UNIQUE, ['userid', 'provider', 'period_start']);
        $table->add_index('provider_idx', XMLDB_INDEX_NOTUNIQUE, ['provider']);
        $table->add_index('period_idx', XMLDB_INDEX_NOTUNIQUE, ['period_start']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('  - Created flashcards_tts_usage table');
        }

        upgrade_mod_savepoint(true, 2025121200, 'flashcards');
    }

    // Per-language example translations table.
    if ($oldversion < 2025121300) {
        mtrace('Flashcards: Adding per-language example translations table...');

        $table = new xmldb_table('flashcards_card_example_trans');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('deckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cardid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('example_idx', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('text', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('deck_card_example_lang_uix', XMLDB_INDEX_UNIQUE, ['deckid', 'cardid', 'example_idx', 'lang']);
        $table->add_index('deck_idx', XMLDB_INDEX_NOTUNIQUE, ['deckid']);
        $table->add_index('lang_idx', XMLDB_INDEX_NOTUNIQUE, ['lang']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace('  - Created flashcards_card_example_trans table');
        }

        upgrade_mod_savepoint(true, 2025121300, 'flashcards');
    }

    // Cache buster bump for recorder UI visuals (no DB changes).
    if ($oldversion < 2025122401) {
        mtrace('Flashcards: Cache version bump for recorder UI refresh...');
        upgrade_mod_savepoint(true, 2025122401, 'flashcards');
    }

    return true;
}
