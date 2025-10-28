<?php
/**
 * Definition of Flashcards scheduled tasks
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'mod_flashcards\task\check_user_access',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '2',    // Run at 2:00 AM daily
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ]
];
