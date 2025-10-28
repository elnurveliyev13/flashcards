<?php

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);

$PAGE->set_url('/mod/flashcards/index.php', ['id' => $courseid]);
$PAGE->set_title(get_string('modulenameplural', 'mod_flashcards'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_flashcards'));

// Minimal list of instances.
if ($cms = get_coursemodules_in_course('flashcards', $course->id)) {
    echo html_writer::start_tag('ul');
    foreach ($cms as $cm) {
        $name = format_string($cm->name, true);
        $url = new moodle_url('/mod/flashcards/view.php', ['id' => $cm->id]);
        echo html_writer::tag('li', html_writer::link($url, $name));
    }
    echo html_writer::end_tag('ul');
} else {
    echo html_writer::div(get_string('none'), 'notifymessage');
}

echo $OUTPUT->footer();

