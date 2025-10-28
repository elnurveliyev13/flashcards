<?php
/**
 * Global flashcards page - not tied to specific activity
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login, do not allow guests
require_login(null, false);

// Block guest users
if (isguestuser()) {
    throw new require_login_exception('Guests are not allowed to access flashcards');
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/mod/flashcards/my/index.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myflashcards', 'mod_flashcards'));
$PAGE->set_heading(get_string('myflashcards', 'mod_flashcards'));

// Check user's access via access_manager.
$access = \mod_flashcards\access_manager::check_user_access($USER->id);

// Prepare JS before header.
$baseurl = (new moodle_url('/mod/flashcards/app/'))->out(false);
$ver = 2025102700; // Global access version.
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards.js', ['v' => $ver]));

// Force client profile to Moodle user id for automatic sync.
$init = "try{localStorage.setItem('srs-profile','U".$USER->id."');}catch(e){};";
// Global mode: no cmid, no instance - pass 0 for both.
$init .= "window.flashcardsInit('mod_flashcards_container', '".$baseurl."', 0, 0, '".sesskey()."', true)";
$PAGE->requires->js_init_code($init);

// Output page.
echo $OUTPUT->header();

// Show access status banner.
if ($access['status'] === \mod_flashcards\access_manager::STATUS_GRACE) {
    $message = get_string('access_grace_message', 'mod_flashcards', $access['days_remaining']);
    echo $OUTPUT->notification($message, 'warning');
} else if ($access['status'] === \mod_flashcards\access_manager::STATUS_EXPIRED) {
    $message = get_string('access_expired_message', 'mod_flashcards');
    echo $OUTPUT->notification($message, 'error');

    // Show link to available courses.
    $coursesurl = new moodle_url('/course/index.php');
    echo html_writer::div(
        html_writer::link($coursesurl, get_string('browse_courses', 'mod_flashcards'), ['class' => 'btn btn-primary']),
        'mt-3'
    );
} else {
    // Active status - show welcome message.
    echo $OUTPUT->heading(get_string('myflashcards_welcome', 'mod_flashcards'), 3);
}

// Render app container (if not expired).
if ($access['can_view']) {
    echo $OUTPUT->render_from_template('mod_flashcards/app', []);
} else {
    echo html_writer::div(
        get_string('access_denied_full', 'mod_flashcards'),
        'alert alert-danger'
    );
}

echo $OUTPUT->footer();
