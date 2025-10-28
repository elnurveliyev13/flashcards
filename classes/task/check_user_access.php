<?php
/**
 * Scheduled task: Check user access and update grace periods
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flashcards\task;

defined('MOODLE_INTERNAL') || die();

class check_user_access extends \core\task\scheduled_task {

    /**
     * Get task name
     */
    public function get_name() {
        return get_string('task_check_user_access', 'mod_flashcards');
    }

    /**
     * Execute task: refresh access status for all users
     */
    public function execute() {
        mtrace('Starting flashcards access check...');

        $stats = \mod_flashcards\access_manager::bulk_refresh_all_users();

        mtrace("Checked {$stats['checked']} users, {$stats['transitioned']} status transitions");
        mtrace('Flashcards access check completed');
    }
}
