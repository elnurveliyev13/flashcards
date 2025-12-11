<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$month = optional_param('month', gmdate('Y-m'), PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);

// Normalize month to YYYY-MM and derive period start (UTC, first day of month).
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = gmdate('Y-m');
}
[$year, $mon] = array_map('intval', explode('-', $month));
$periodstart = gmmktime(0, 0, 0, $mon, 1, $year);

$baseurl = new moodle_url('/mod/flashcards/admin/tts_usage.php', [
    'month' => $month,
    'perpage' => $perpage,
]);

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('ttsusage_title', 'mod_flashcards'));
$PAGE->set_heading(get_string('ttsusage_title', 'mod_flashcards'));
$PAGE->navbar->add(get_string('pluginname', 'mod_flashcards'));
$PAGE->navbar->add(get_string('ttsusage_title', 'mod_flashcards'));

$config = get_config('mod_flashcards');
$elevenlimit = (int)($config->elevenlabs_tts_monthly_limit ?? 0);
$pollylimit = (int)($config->amazonpolly_tts_monthly_limit ?? 0);

$countsql = "SELECT COUNT(DISTINCT u.id)
               FROM {flashcards_tts_usage} t
               JOIN {user} u ON u.id = t.userid
              WHERE t.period_start = :periodstart";
$total = $DB->count_records_sql($countsql, ['periodstart' => $periodstart]);

$usagesql = "SELECT u.id,
                    u.firstname,
                    u.lastname,
                    SUM(CASE WHEN t.provider = 'elevenlabs' THEN t.characters ELSE 0 END) AS eleven_chars,
                    SUM(CASE WHEN t.provider = 'elevenlabs' THEN t.requests ELSE 0 END) AS eleven_requests,
                    SUM(CASE WHEN t.provider = 'polly' THEN t.characters ELSE 0 END) AS polly_chars,
                    SUM(CASE WHEN t.provider = 'polly' THEN t.requests ELSE 0 END) AS polly_requests
               FROM {flashcards_tts_usage} t
               JOIN {user} u ON u.id = t.userid
              WHERE t.period_start = :periodstart
           GROUP BY u.id, u.firstname, u.lastname
           ORDER BY u.lastname, u.firstname, u.id";

$records = $DB->get_records_sql($usagesql, ['periodstart' => $periodstart], $page * $perpage, $perpage);

echo $OUTPUT->header();

echo html_writer::start_div('fc-ttsusage-intro');
echo html_writer::tag('p', get_string('ttsusage_desc', 'mod_flashcards', $month));
$limitbits = [];
if ($elevenlimit > 0) {
    $limitbits[] = get_string('ttsusage_limit_eleven', 'mod_flashcards', $elevenlimit);
}
if ($pollylimit > 0) {
    $limitbits[] = get_string('ttsusage_limit_polly', 'mod_flashcards', $pollylimit);
}
if (!empty($limitbits)) {
    echo html_writer::tag('p', implode(' · ', $limitbits));
}
echo html_writer::end_div();

// Filter form (month + page size).
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mform fc-ttsusage-filters']);
echo html_writer::start_div('form-row');
echo html_writer::label(get_string('ttsusage_month', 'mod_flashcards'), 'id_month', false, ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'month',
    'name' => 'month',
    'id' => 'id_month',
    'value' => $month,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-row');
echo html_writer::label(get_string('ttsusage_perpage', 'mod_flashcards'), 'id_perpage', false, ['class' => 'form-label']);
echo html_writer::select(
    [25 => 25, 50 => 50, 100 => 100, 200 => 200],
    'perpage',
    $perpage,
    null,
    ['id' => 'id_perpage']
);
echo html_writer::end_div();

echo html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$records) {
    echo $OUTPUT->notification(get_string('ttsusage_empty', 'mod_flashcards'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('ttsusage_user', 'mod_flashcards'),
    get_string('ttsusage_eleven', 'mod_flashcards'),
    get_string('ttsusage_polly', 'mod_flashcards'),
    get_string('ttsusage_total', 'mod_flashcards'),
];

foreach ($records as $record) {
    $userfullname = fullname((object)[
        'firstname' => $record->firstname,
        'lastname' => $record->lastname,
    ]) . ' (ID ' . $record->id . ')';

    $elevenchars = (int)$record->eleven_chars;
    $pollychars = (int)$record->polly_chars;
    $totalchars = $elevenchars + $pollychars;

    $eleven = html_writer::div(
        get_string('ttsusage_chars', 'mod_flashcards', $elevenchars) . '<br>' .
        get_string('ttsusage_requests', 'mod_flashcards', (int)$record->eleven_requests),
        'fc-ttsusage-provider'
    );

    $polly = html_writer::div(
        get_string('ttsusage_chars', 'mod_flashcards', $pollychars) . '<br>' .
        get_string('ttsusage_requests', 'mod_flashcards', (int)$record->polly_requests),
        'fc-ttsusage-provider'
    );

    $table->data[] = [
        $userfullname,
        $eleven,
        $polly,
        get_string('ttsusage_chars', 'mod_flashcards', $totalchars),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
