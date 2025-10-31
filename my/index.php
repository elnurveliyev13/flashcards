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
$PAGE->set_pagelayout('embedded'); // Minimal chrome for app-like view
$PAGE->set_title(get_string('myflashcards', 'mod_flashcards'));
$PAGE->set_heading(get_string('myflashcards', 'mod_flashcards'));

// iOS PWA meta tags
$PAGE->requires->string_for_js('ios_install_title', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step1', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step2', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_share_button', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_add_to_home', 'mod_flashcards');

// Check user's access via access_manager.
// Force refresh to ensure status is current (expired enrollments detected immediately)
$access = \mod_flashcards\access_manager::check_user_access($USER->id, true);

// DEBUG: Log access info for troubleshooting
error_log('[FLASHCARDS DEBUG] Access info for user ' . $USER->id . ': ' . print_r($access, true));

// Prepare JS before header.
$baseurl = (new moodle_url('/mod/flashcards/app/'))->out(false);
$ver = 2025103107; // Global access version.
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards.js', ['v' => $ver]));

// Force client profile to Moodle user id for automatic sync.
$init = "try{localStorage.setItem('srs-profile','U".$USER->id."');}catch(e){};";
// Pass access information to JavaScript (with proper escaping)
$accessjson = json_encode($access, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($accessjson === false) {
    error_log('[FLASHCARDS ERROR] Failed to encode access info to JSON: ' . json_last_error_msg());
    $accessjson = '{"can_view":false,"can_create":false,"status":"error"}';
}
$init .= "window.flashcardsAccessInfo = ".$accessjson.";";
// Global mode: no cmid, no instance - pass 0 for both.
$init .= "window.flashcardsInit('mod_flashcards_container', '".$baseurl."', 0, 0, '".sesskey()."', true)";
$PAGE->requires->js_init_code($init);

// Output page.
echo $OUTPUT->header();

// Show access status banner.
if ($access['status'] === \mod_flashcards\access_manager::STATUS_GRACE) {
    $message = get_string('access_grace_message', 'mod_flashcards', $access['days_remaining']);
    $message .= '<br><strong>' . get_string('grace_period_restrictions', 'mod_flashcards') . '</strong>';
    $message .= '<ul>';
    $message .= '<li>' . get_string('grace_can_review', 'mod_flashcards') . '</li>';
    $message .= '<li>' . get_string('grace_cannot_create', 'mod_flashcards') . '</li>';
    $message .= '</ul>';
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

