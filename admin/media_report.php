<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$search = optional_param('search', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);

$baseparams = [];
if ($search !== '') {
    $baseparams['search'] = $search;
}
if ($userid) {
    $baseparams['userid'] = $userid;
}
if ($perpage !== 50) {
    $baseparams['perpage'] = $perpage;
}

$baseurl = new moodle_url('/mod/flashcards/admin/media_report.php', $baseparams);

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('mediareport_title', 'mod_flashcards'));
$PAGE->set_heading(get_string('mediareport_title', 'mod_flashcards'));
$PAGE->navbar->add(get_string('pluginname', 'mod_flashcards'));
$PAGE->navbar->add(get_string('mediareport_title', 'mod_flashcards'));

$where = [];
$params = [];

if ($search !== '') {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $where[] = '(' . $DB->sql_like('c.cardid', ':searchid', false) .
        ' OR ' . $DB->sql_like('c.payload', ':searchpayload', false) . ')';
    $params['searchid'] = $like;
    $params['searchpayload'] = $like;
}

if ($userid) {
    $where[] = 'c.ownerid = :ownerid';
    $params['ownerid'] = $userid;
}

$whereclause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $DB->count_records_sql("SELECT COUNT(1) FROM {flashcards_cards} c {$whereclause}", $params);

$records = $DB->get_records_sql(
    "SELECT c.*, d.title as decktitle, u.firstname, u.lastname
       FROM {flashcards_cards} c
  LEFT JOIN {flashcards_decks} d ON d.id = c.deckid
  LEFT JOIN {user} u ON u.id = c.ownerid
      {$whereclause}
   ORDER BY c.timemodified DESC",
    $params,
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();

// Filter form.
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mform fc-media-report-filters']);
echo html_writer::start_div('form-row');
echo html_writer::label(get_string('mediareport_filter_search', 'mod_flashcards'), 'id_search', false, ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'id_search',
    'value' => $search,
    'placeholder' => get_string('mediareport_filter_search_ph', 'mod_flashcards'),
]);
echo html_writer::end_div();

echo html_writer::start_div('form-row');
echo html_writer::label(get_string('mediareport_filter_user', 'mod_flashcards'), 'id_userid', false, ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'userid',
    'id' => 'id_userid',
    'value' => $userid ?: '',
    'min' => 0,
    'placeholder' => get_string('mediareport_filter_user_ph', 'mod_flashcards'),
]);
echo html_writer::end_div();

echo html_writer::start_div('form-row');
echo html_writer::label(get_string('mediareport_filter_perpage', 'mod_flashcards'), 'id_perpage', false, ['class' => 'form-label']);
echo html_writer::select(
    [25 => 25, 50 => 50, 100 => 100],
    'perpage',
    $perpage,
    null,
    ['id' => 'id_perpage']
);
echo html_writer::end_div();

echo html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$records) {
    echo $OUTPUT->notification(get_string('mediareport_empty', 'mod_flashcards'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('mediareport_card', 'mod_flashcards'),
    get_string('mediareport_owner', 'mod_flashcards'),
    get_string('mediareport_audio', 'mod_flashcards'),
    get_string('mediareport_updated', 'mod_flashcards'),
];

$fieldlabels = [
    'audio' => get_string('mediareport_audio_sentence', 'mod_flashcards'),
    'audioFront' => get_string('mediareport_audio_front', 'mod_flashcards'),
    'focusAudio' => get_string('mediareport_audio_focus', 'mod_flashcards'),
];

foreach ($records as $record) {
    $payload = json_decode($record->payload ?? '', true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $cardtitle = format_string($payload['text'] ?? $payload['front'] ?? '');
    if ($cardtitle === '') {
        $cardtitle = get_string('mediareport_cardid', 'mod_flashcards', $record->cardid);
    }

    $audiolist = [];
    foreach ($fieldlabels as $field => $label) {
        if (!empty($payload[$field]) && is_string($payload[$field])) {
            $url = $payload[$field];
            $audiolist[] = html_writer::link($url, $label, ['target' => '_blank', 'rel' => 'noopener']);
        }
    }

    if ($record->ownerid) {
        $userstub = (object)[
            'id' => $record->ownerid,
            'firstname' => $record->firstname ?? '',
            'lastname' => $record->lastname ?? '',
        ];
        $ownername = fullname($userstub) . ' (ID ' . $record->ownerid . ')';
    } else {
        $ownername = get_string('none');
    }

    $table->data[] = [
        html_writer::div(
            html_writer::tag('strong', $cardtitle) . html_writer::empty_tag('br') .
            get_string('mediareport_cardid', 'mod_flashcards', $record->cardid) . '<br>' .
            get_string('mediareport_deck', 'mod_flashcards', $record->decktitle ?? get_string('none')),
            'fc-card-info'
        ),
        $ownername,
        !empty($audiolist) ? html_writer::alist($audiolist) : get_string('mediareport_noaudio', 'mod_flashcards'),
        userdate($record->timemodified),
    ];
}

echo html_writer::table($table);

echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
