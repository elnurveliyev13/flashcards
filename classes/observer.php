<?php
/**
 * Event observers for flashcards module
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flashcards;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Observer for course_module_completion_updated event
     *
     * Updates completion cache when a user completes an activity
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event) {
        global $DB;

        $data = $event->get_record_snapshot('course_modules_completion', $event->objectid);

        // Only process if completion state is COMPLETE
        if ($data->completionstate != COMPLETION_COMPLETE &&
            $data->completionstate != COMPLETION_COMPLETE_PASS) {
            return;
        }

        $userid = $event->relateduserid;
        $cmid = $event->contextinstanceid;

        // Get course module to check idnumber
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, idnumber');
        if (!$cm || empty($cm->idnumber)) {
            return; // No idnumber set - nothing to track
        }

        $idnumber = trim($cm->idnumber);

        // Check if already cached
        $exists = $DB->record_exists('flashcards_completion_cache', [
            'userid' => $userid,
            'idnumber' => $idnumber
        ]);

        if (!$exists) {
            // Add to completion cache
            $record = (object)[
                'userid' => $userid,
                'idnumber' => $idnumber,
                'cmid' => $cmid,
                'completed_at' => time()
            ];

            $DB->insert_record('flashcards_completion_cache', $record);

            // Log to error_log instead of mtrace (mtrace breaks AJAX responses!)
            error_log("Flashcards: User {$userid} completed activity with idnumber '{$idnumber}'");
        }
    }

    /**
     * Observer for user_enrolment_created event
     *
     * Refresh user's access status when they enrol in a course
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event) {
        $userid = $event->relateduserid;

        // Check if course has flashcards activity
        $courseid = $event->courseid;
        if (self::course_has_flashcards($courseid)) {
            // Refresh user's access status immediately
            $access = \mod_flashcards\access_manager::check_user_access($userid, true);
            error_log("Flashcards: User {$userid} enrolled in flashcards course, access status: {$access['status']}");
        }
    }

    /**
     * Observer for user_enrolment_deleted event
     *
     * Refresh user's access status when they unenrol from a course
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        $userid = $event->relateduserid;

        // Check if course has flashcards activity
        $courseid = $event->courseid;
        if (self::course_has_flashcards($courseid)) {
            // Refresh user's access status immediately
            $access = \mod_flashcards\access_manager::check_user_access($userid, true);
            error_log("Flashcards: User {$userid} unenrolled from flashcards course, access status: {$access['status']}");
        }
    }

    /**
     * Check if course has flashcards activity
     *
     * @param int $courseid
     * @return bool
     */
    private static function course_has_flashcards($courseid) {
        global $DB;

        $sql = "SELECT COUNT(*)
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = :courseid
                  AND m.name = 'flashcards'";

        $count = $DB->count_records_sql($sql, ['courseid' => $courseid]);

        return $count > 0;
    }
}
