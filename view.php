<?php

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.

// Fetch CM and course separately so we can handle orphaned records gracefully.
$cm = get_coursemodule_from_id('flashcards', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/flashcards:view', $context);

$PAGE->set_url('/mod/flashcards/view.php', ['id' => $id]);
$PAGE->set_title(format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));

// iOS PWA meta tags
$PAGE->requires->string_for_js('ios_install_title', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step1', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step2', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_share_button', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_add_to_home', 'mod_flashcards');

// Prepare JS before header to ensure deterministic order.
$baseurl = (new moodle_url('/mod/flashcards/app/'))->out(false);
$ver = 2025102605; // cache buster; aligns with plugin version (UX improvements).
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards.js', ['v' => $ver]));
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards-ux.js', ['v' => $ver]));
// Force client profile to Moodle user id for automatic sync.
$init = "try{localStorage.setItem('srs-profile','U".$USER->id."');}catch(e){};";
$init .= "window.flashcardsInit('mod_flashcards_container', '".$baseurl."', ".$cm->id.", ".$cm->instance.", '".sesskey()."')";
$PAGE->requires->js_init_code($init);

// Output page (native, no AMD). Render mustache template and attach plain JS.
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name), 2);
// If instance record is missing (e.g., previous save was interrupted), show a friendly message
// instead of a fatal error.
if (!$DB->record_exists('flashcards', ['id' => $cm->instance])) {
    $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
    echo $OUTPUT->notification('This Flashcards activity was not created completely. Please delete it and create again.', 'notifyproblem');
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}
echo $OUTPUT->render_from_template('mod_flashcards/app', []);
echo $OUTPUT->footer();
