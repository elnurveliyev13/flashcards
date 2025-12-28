<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$lang = optional_param('lang', '', PARAM_ALPHANUMEXT);
$query = optional_param('query', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);
$editid = optional_param('edit', 0, PARAM_INT);
$save = optional_param('save', 0, PARAM_INT);

$baseurl = new moodle_url('/mod/flashcards/admin/expr_translations.php', [
    'lang' => $lang,
    'query' => $query,
    'perpage' => $perpage,
]);

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('exprtrans_title', 'mod_flashcards'));
$PAGE->set_heading(get_string('exprtrans_title', 'mod_flashcards'));
$PAGE->navbar->add(get_string('pluginname', 'mod_flashcards'));
$PAGE->navbar->add(get_string('exprtrans_title', 'mod_flashcards'));

if ($save && $editid) {
    require_sesskey();
    $record = $DB->get_record('flashcards_expr_translations', ['id' => $editid], '*', IGNORE_MISSING);
    if ($record) {
        $translation = optional_param('translation', '', PARAM_RAW_TRIMMED);
        $note = optional_param('note', '', PARAM_RAW_TRIMMED);
        $examples = optional_param('examples', '', PARAM_RAW_TRIMMED);
        $examplestrans = optional_param('examples_trans', '', PARAM_RAW_TRIMMED);
        $source = optional_param('source', '', PARAM_RAW_TRIMMED);
        $confidence = optional_param('confidence', '', PARAM_RAW_TRIMMED);

        $record->translation = $translation !== '' ? $translation : null;
        $record->note = $note !== '' ? $note : null;
        $record->source = $source !== '' ? $source : null;
        $record->confidence = $confidence !== '' ? $confidence : null;
        $record->examplesjson = $examples !== '' ? json_encode(split_lines($examples), JSON_UNESCAPED_UNICODE) : null;
        $record->examplestransjson = $examplestrans !== '' ? json_encode(split_lines($examplestrans), JSON_UNESCAPED_UNICODE) : null;
        $record->timemodified = time();
        $DB->update_record('flashcards_expr_translations', $record);
    }
    redirect($baseurl);
}

echo $OUTPUT->header();

// Filter form.
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mform fc-exprtrans-filters']);
echo html_writer::start_div('form-row');
echo html_writer::label(get_string('exprtrans_lang', 'mod_flashcards'), 'id_lang', false, ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'lang',
    'id' => 'id_lang',
    'value' => $lang,
    'placeholder' => 'en',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-row');
echo html_writer::label(get_string('exprtrans_query', 'mod_flashcards'), 'id_query', false, ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'query',
    'id' => 'id_query',
    'value' => $query,
]);
echo html_writer::end_div();
echo html_writer::start_div('form-row');
echo html_writer::label(get_string('exprtrans_perpage', 'mod_flashcards'), 'id_perpage', false, ['class' => 'form-label']);
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

// Edit form.
if ($editid) {
    $record = $DB->get_record('flashcards_expr_translations', ['id' => $editid], '*', IGNORE_MISSING);
    if ($record) {
        $examples = join("\n", json_decode($record->examplesjson ?? '', true) ?: []);
        $examplestrans = join("\n", json_decode($record->examplestransjson ?? '', true) ?: []);
        echo html_writer::tag('h3', get_string('exprtrans_edit', 'mod_flashcards'));
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'class' => 'mform fc-exprtrans-edit',
            'action' => $baseurl,
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'edit', 'value' => $record->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('div', get_string('exprtrans_expression', 'mod_flashcards') . ': ' . format_string($record->expression), ['class' => 'mb-2']);
        echo html_writer::tag('div', get_string('exprtrans_lang', 'mod_flashcards') . ': ' . s($record->lang), ['class' => 'mb-2']);
        echo html_writer::label(get_string('exprtrans_translation', 'mod_flashcards'), 'id_translation', false, ['class' => 'form-label']);
        echo html_writer::tag('textarea', s($record->translation ?? ''), [
            'name' => 'translation',
            'id' => 'id_translation',
            'rows' => 2,
            'class' => 'form-control',
        ]);
        echo html_writer::label(get_string('exprtrans_note', 'mod_flashcards'), 'id_note', false, ['class' => 'form-label']);
        echo html_writer::tag('textarea', s($record->note ?? ''), [
            'name' => 'note',
            'id' => 'id_note',
            'rows' => 2,
            'class' => 'form-control',
        ]);
        echo html_writer::label(get_string('exprtrans_examples', 'mod_flashcards'), 'id_examples', false, ['class' => 'form-label']);
        echo html_writer::tag('textarea', s($examples), [
            'name' => 'examples',
            'id' => 'id_examples',
            'rows' => 4,
            'class' => 'form-control',
        ]);
        echo html_writer::label(get_string('exprtrans_examples_trans', 'mod_flashcards'), 'id_examples_trans', false, ['class' => 'form-label']);
        echo html_writer::tag('textarea', s($examplestrans), [
            'name' => 'examples_trans',
            'id' => 'id_examples_trans',
            'rows' => 4,
            'class' => 'form-control',
        ]);
        echo html_writer::label(get_string('exprtrans_source', 'mod_flashcards'), 'id_source', false, ['class' => 'form-label']);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'source',
            'id' => 'id_source',
            'value' => s($record->source ?? ''),
            'class' => 'form-control',
        ]);
        echo html_writer::label(get_string('exprtrans_confidence', 'mod_flashcards'), 'id_confidence', false, ['class' => 'form-label']);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'confidence',
            'id' => 'id_confidence',
            'value' => s($record->confidence ?? ''),
            'class' => 'form-control',
        ]);
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
    }
}

$params = [];
$where = [];
if ($lang !== '') {
    $where[] = 'lang = :lang';
    $params['lang'] = core_text::strtolower($lang);
}
if ($query !== '') {
    $where[] = $DB->sql_like('normalized', ':query', false);
    $params['query'] = '%' . core_text::strtolower($query) . '%';
}
$wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $DB->count_records_sql("SELECT COUNT(1) FROM {flashcards_expr_translations} {$wheresql}", $params);
$records = $DB->get_records_sql(
    "SELECT *
       FROM {flashcards_expr_translations}
       {$wheresql}
   ORDER BY timemodified DESC, id DESC",
    $params,
    $page * $perpage,
    $perpage
);

if (!$records) {
    echo $OUTPUT->notification(get_string('exprtrans_empty', 'mod_flashcards'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('exprtrans_expression', 'mod_flashcards'),
    get_string('exprtrans_lang', 'mod_flashcards'),
    get_string('exprtrans_translation', 'mod_flashcards'),
    get_string('exprtrans_note', 'mod_flashcards'),
    get_string('exprtrans_examples_count', 'mod_flashcards'),
    get_string('exprtrans_examples_trans_count', 'mod_flashcards'),
    get_string('exprtrans_source', 'mod_flashcards'),
    get_string('exprtrans_confidence', 'mod_flashcards'),
    get_string('exprtrans_updated', 'mod_flashcards'),
    get_string('exprtrans_actions', 'mod_flashcards'),
];

foreach ($records as $record) {
    $examples = json_decode($record->examplesjson ?? '', true);
    $examplestrans = json_decode($record->examplestransjson ?? '', true);
    $editurl = new moodle_url('/mod/flashcards/admin/expr_translations.php', [
        'edit' => $record->id,
        'lang' => $lang,
        'query' => $query,
        'perpage' => $perpage,
    ]);
    $table->data[] = [
        format_string($record->expression),
        s($record->lang),
        s($record->translation ?? ''),
        s($record->note ?? ''),
        is_array($examples) ? count($examples) : 0,
        is_array($examplestrans) ? count($examplestrans) : 0,
        s($record->source ?? ''),
        s($record->confidence ?? ''),
        userdate((int)($record->timemodified ?? 0)),
        html_writer::link($editurl, get_string('edit')),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
echo $OUTPUT->footer();

function split_lines(string $text): array {
    $lines = preg_split('/\R/u', trim($text));
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}
