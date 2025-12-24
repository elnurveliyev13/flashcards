<?php
// Global flashcards landing (used by PWA start_url). Matches activity view assets.

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('Guests are not allowed to access flashcards');
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/mod/flashcards/my/index.php');
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('myflashcards', 'mod_flashcards'));
$PAGE->set_heading(get_string('myflashcards', 'mod_flashcards'));

// iOS strings used by install guide and UI.
$PAGE->requires->string_for_js('ios_install_title', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step1', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_install_step2', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_share_button', 'mod_flashcards');
$PAGE->requires->string_for_js('ios_add_to_home', 'mod_flashcards');

// Assets (same order and version as activity view).
$baseurl = (new moodle_url('/mod/flashcards/app/'))->out(false);
$ver = 2025122401; // cache buster; aligns with plugin version for asset reload.
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/ux-boot.js', ['v' => $ver]));
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/main.js', ['v' => $ver]));
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards-ux.js', ['v' => $ver]));
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/ios-install-guide.js', ['v' => $ver]));
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/bottom-bar-controller.js', ['v' => $ver]));
$PAGE->requires->css(new moodle_url('/mod/flashcards/assets/app.css', ['v' => $ver]));
$PAGE->requires->css(new moodle_url('/mod/flashcards/assets/ux-bottom.css', ['v' => $ver]));

// Init (global mode: cmid=0, instance=0)
$runtimeconfig = mod_flashcards_get_runtime_config();
$runtimejson = json_encode($runtimeconfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$init = "window.__flashcardsRuntimeConfig = ".$runtimejson.";";
$init .= "try{localStorage.setItem('srs-profile','U".$USER->id."');}catch(e){};";
$init .= "window.__flashcardsInitQueue = window.__flashcardsInitQueue || [];";
$init .= "if (typeof window.flashcardsInit === 'function') {"
  . "window.flashcardsInit('mod_flashcards_container', '".$baseurl."', 0, 0, '".sesskey()."', true);"
  . "} else {"
  . "window.__flashcardsInitQueue.push(['mod_flashcards_container', '".$baseurl."', 0, 0, '".sesskey()."', true]);"
  . "}";
$PAGE->requires->js_init_code($init);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_flashcards/app', []);
echo $OUTPUT->footer();
