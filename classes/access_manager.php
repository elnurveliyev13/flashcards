<?php
/**
 * Access Manager - handles user access control for flashcards system
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flashcards;

defined('MOODLE_INTERNAL') || die();

class access_manager {

    /** Access status constants */
    const STATUS_ACTIVE = 'active';
    const STATUS_GRACE = 'grace';
    const STATUS_EXPIRED = 'expired';

    /** Cache TTL: 24 hours */
    const CACHE_TTL = 86400;

    /** Grace period: 30 days */
    const GRACE_PERIOD_DAYS = 30;

    /**
     * Check user's access to flashcards system
     *
     * @param int $userid User ID
     * @param bool $forcerefresh Force refresh from database (skip cache)
     * @return array ['can_create' => bool, 'can_review' => bool, 'can_view' => bool, 'status' => string, 'days_remaining' => int]
     */
    public static function check_user_access($userid, $forcerefresh = false) {
        global $DB;

        // SECURITY: Validate userid (prevent guests with ID 0, 1, or negative)
        if (!$userid || $userid <= 0 || $userid == 1) {
            return [
                'can_view' => false,
                'can_review' => false,
                'can_create' => false,
                'status' => self::STATUS_EXPIRED,
                'days_remaining' => 0
            ];
        }

        // Site admins always have full access
        if (is_siteadmin($userid)) {
            return [
                'can_view' => true,
                'can_review' => true,
                'can_create' => true,
                'status' => self::STATUS_ACTIVE,
                'days_remaining' => null
            ];
        }

        // Get or create access record.
        $access = $DB->get_record('flashcards_user_access', ['userid' => $userid]);

        if (!$access) {
            // First time user - create active access.
            $access = self::create_user_access($userid);
        }

        $now = time();

        // Check if we need to refresh (cache expired or forced).
        $needsrefresh = $forcerefresh || ($now - $access->last_enrolment_check) > self::CACHE_TTL;

        if ($needsrefresh) {
            $access = self::refresh_user_status($userid, $access);
        }

        // Calculate permissions based on status.
        return self::calculate_permissions($access);
    }

    /**
     * Create initial access record for new user
     *
     * @param int $userid
     * @return stdClass
     */
    private static function create_user_access($userid) {
        global $DB;

        // SECURITY: Check if user actually has enrollment before granting active status
        $hasEnrolment = self::has_active_enrolment($userid);

        $record = (object)[
            'userid' => $userid,
            'status' => $hasEnrolment ? self::STATUS_ACTIVE : self::STATUS_EXPIRED,
            'last_enrolment_check' => time(),
            'grace_period_days' => self::GRACE_PERIOD_DAYS,
            'timemodified' => time()
        ];

        // If no enrollment, set blocked_at timestamp
        if (!$hasEnrolment) {
            $record->blocked_at = time();
        }

        $record->id = $DB->insert_record('flashcards_user_access', $record);

        return $record;
    }

    /**
     * Refresh user's access status by checking current enrolments
     *
     * @param int $userid
     * @param stdClass $access Current access record
     * @return stdClass Updated access record
     */
    private static function refresh_user_status($userid, $access) {
        global $DB;

        $now = time();
        $hasaccess = self::has_active_enrolment($userid);

        // State machine for access status.
        if ($hasaccess) {
            // User has active enrolment → set to active.
            $access->status = self::STATUS_ACTIVE;
            $access->grace_period_start = null;
            $access->blocked_at = null;
        } else {
            // No active enrolment.
            if ($access->status === self::STATUS_ACTIVE) {
                // Transition: active → grace.
                $access->status = self::STATUS_GRACE;
                $access->grace_period_start = $now;
                self::send_notification($userid, 'grace_period_started');
            } else if ($access->status === self::STATUS_GRACE) {
                // Check if grace period expired.
                $graceend = $access->grace_period_start + ($access->grace_period_days * 86400);
                if ($now > $graceend) {
                    // Transition: grace → expired.
                    $access->status = self::STATUS_EXPIRED;
                    $access->blocked_at = $now;
                    self::send_notification($userid, 'access_expired');
                } else {
                    // Still in grace period - check if warning needed.
                    $daysleft = ceil(($graceend - $now) / 86400);
                    if ($daysleft == 7) {
                        self::send_notification($userid, 'access_expiring_soon');
                    }
                }
            }
            // If already expired, stay expired.
        }

        $access->last_enrolment_check = $now;
        $access->timemodified = $now;

        $DB->update_record('flashcards_user_access', $access);

        return $access;
    }

    /**
     * Check if user has active enrolment in any flashcards-enabled course
     *
     * @param int $userid
     * @return bool
     */
    private static function has_active_enrolment($userid) {
        global $DB;

        // Find all courses that have flashcards activity module.
        $sql = "SELECT DISTINCT e.courseid
                FROM {enrol} e
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                JOIN {course_modules} cm ON cm.course = e.courseid
                JOIN {modules} m ON m.id = cm.module
                WHERE ue.userid = :userid
                  AND ue.status = 0
                  AND m.name = 'flashcards'
                  AND (ue.timestart = 0 OR ue.timestart <= :now1)
                  AND (ue.timeend = 0 OR ue.timeend > :now2)";

        $params = [
            'userid' => $userid,
            'now1' => time(),
            'now2' => time()
        ];

        $courses = $DB->get_records_sql($sql, $params);

        return !empty($courses);
    }

    /**
     * Calculate permissions based on access status
     *
     * @param stdClass $access
     * @return array
     */
    private static function calculate_permissions($access) {
        $now = time();

        $result = [
            'can_view' => true, // Always can view own cards.
            'can_review' => false,
            'can_create' => false,
            'status' => $access->status,
            'days_remaining' => null
        ];

        switch ($access->status) {
            case self::STATUS_ACTIVE:
                $result['can_review'] = true;
                $result['can_create'] = true;
                break;

            case self::STATUS_GRACE:
                $result['can_review'] = true; // Can review during grace period.
                $result['can_create'] = false; // Cannot create new cards.
                if ($access->grace_period_start) {
                    $graceend = $access->grace_period_start + ($access->grace_period_days * 86400);
                    $result['days_remaining'] = max(0, ceil(($graceend - $now) / 86400));
                }
                break;

            case self::STATUS_EXPIRED:
                $result['can_review'] = false;
                $result['can_create'] = false;
                $result['days_remaining'] = 0;
                break;
        }

        return $result;
    }

    /**
     * Get list of completed activity idnumbers for user
     *
     * @param int $userid
     * @return array Array of idnumbers
     */
    public static function get_user_completed_idnumbers($userid) {
        global $DB;

        $records = $DB->get_records('flashcards_completion_cache', ['userid' => $userid], '', 'idnumber');

        return array_keys($records);
    }

    /**
     * Check if user has completed activity with given idnumber
     *
     * @param int $userid
     * @param string $idnumber
     * @return bool
     */
    public static function has_completed_activity($userid, $idnumber) {
        global $DB;

        return $DB->record_exists('flashcards_completion_cache', [
            'userid' => $userid,
            'idnumber' => $idnumber
        ]);
    }

    /**
     * Send notification to user
     *
     * @param int $userid
     * @param string $type Notification type (grace_period_started | access_expiring_soon | access_expired)
     */
    private static function send_notification($userid, $type) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        $message = new \core\message\message();
        $message->component = 'mod_flashcards';
        $message->name = $type;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->notification = 1;

        switch ($type) {
            case 'grace_period_started':
                $message->subject = get_string('notification_grace_subject', 'mod_flashcards');
                $message->fullmessage = get_string('notification_grace_message', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
                $message->fullmessagehtml = get_string('notification_grace_message_html', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
                break;

            case 'access_expiring_soon':
                $message->subject = get_string('notification_expiring_subject', 'mod_flashcards');
                $message->fullmessage = get_string('notification_expiring_message', 'mod_flashcards');
                $message->fullmessagehtml = get_string('notification_expiring_message_html', 'mod_flashcards');
                break;

            case 'access_expired':
                $message->subject = get_string('notification_expired_subject', 'mod_flashcards');
                $message->fullmessage = get_string('notification_expired_message', 'mod_flashcards');
                $message->fullmessagehtml = get_string('notification_expired_message_html', 'mod_flashcards');
                break;
        }

        message_send($message);
    }

    /**
     * Bulk refresh all users' access status (called by scheduled task)
     *
     * @return array Statistics: ['checked' => int, 'transitioned' => int]
     */
    public static function bulk_refresh_all_users() {
        global $DB;

        $stats = ['checked' => 0, 'transitioned' => 0];

        // Get all users with active or grace status.
        $users = $DB->get_records_select('flashcards_user_access',
            "status IN (?, ?)",
            [self::STATUS_ACTIVE, self::STATUS_GRACE]);

        foreach ($users as $access) {
            $oldstatus = $access->status;
            $access = self::refresh_user_status($access->userid, $access);
            $stats['checked']++;

            if ($oldstatus !== $access->status) {
                $stats['transitioned']++;
            }
        }

        return $stats;
    }
}
