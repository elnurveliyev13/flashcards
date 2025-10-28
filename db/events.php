<?php
/**
 * Event observers definitions
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\mod_flashcards\observer::course_module_completion_updated',
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\mod_flashcards\observer::user_enrolment_created',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\mod_flashcards\observer::user_enrolment_deleted',
    ],
];
