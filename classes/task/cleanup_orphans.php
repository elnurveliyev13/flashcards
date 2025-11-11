<?php
/**
 * Scheduled task: cleanup orphaned records left after partial deletes.
 *
 * @package    mod_flashcards
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flashcards\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

/**
 * Removes flashcards_progress rows that no longer have a matching card.
 */
class cleanup_orphans extends scheduled_task {

    /**
     * Returns task name for admin UI.
     */
    public function get_name() {
        return get_string('task_cleanup_orphans', 'mod_flashcards');
    }

    /**
     * Execute cleanup.
     */
    public function execute() {
        global $DB;

        $deleted = 0;
        $batchsize = 500;

        mtrace('Flashcards: looking for orphaned progress records...');

        do {
            $ids = $DB->get_fieldset_sql(
                "SELECT p.id
                   FROM {flashcards_progress} p
              LEFT JOIN {flashcards_cards} c
                     ON c.deckid = p.deckid AND c.cardid = p.cardid
                  WHERE c.id IS NULL
                  LIMIT ?",
                [$batchsize]
            );

            if (empty($ids)) {
                break;
            }

            list($insql, $inparams) = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('flashcards_progress', "id {$insql}", $inparams);
            $deleted += count($ids);
            mtrace("  - Deleted {$deleted} orphaned progress records so far...");

        } while (true);

        if ($deleted === 0) {
            mtrace('Flashcards: no orphaned progress records found.');
        } else {
            mtrace("Flashcards: cleanup complete, deleted {$deleted} orphaned progress records.");
        }
    }
}
