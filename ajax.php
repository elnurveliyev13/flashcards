<?php
define('AJAX_SCRIPT', true);

// Bump this when changing sentence_elements pipeline behavior to help verify deployments/opcache.
define('MOD_FLASHCARDS_PIPELINE_REV', '2026-01-06.6');

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/weblib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/local/ordbank_helper.php');
require_once(__DIR__ . '/classes/local/ordbokene_client.php');
require_once(__DIR__ . '/classes/local/ordbokene_utils.php');

/**
 * Return an explicit binary collation for exact byte-wise comparisons on MySQL/MariaDB.
 *
 * Many Moodle installs use accent-insensitive collations (e.g. utf8mb4_unicode_ci), where 'å' = 'a'.
 */
function mod_flashcards_mysql_bin_collation(): ?string {
    global $DB, $CFG;
    if (!method_exists($DB, 'get_dbfamily') || $DB->get_dbfamily() !== 'mysql') {
        return null;
    }
    $dbcollation = (string)($CFG->dboptions['dbcollation'] ?? '');
    return (stripos($dbcollation, 'utf8mb4') !== false) ? 'utf8mb4_bin' : 'utf8_bin';
}

/**
 * True when a column exists (MySQL/MariaDB only, used for optional performance upgrades).
 */
function mod_flashcards_mysql_has_field(string $tablename, string $fieldname): bool {
    global $DB;
    try {
        if (!method_exists($DB, 'get_dbfamily') || $DB->get_dbfamily() !== 'mysql') {
            return false;
        }
        $dbman = $DB->get_manager();
        $table = new xmldb_table($tablename);
        $field = new xmldb_field($fieldname);
        return $dbman->field_exists($table, $field);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Map internal POS to Ordbøkene wc codes (optional filter to reduce ambiguity).
 */
function mod_flashcards_ordbokene_wc_from_pos(string $pos): string {
    $pos = core_text::strtolower(trim($pos));
    if ($pos === '') {
        return '';
    }
    if (in_array($pos, ['verb', 'vb'], true)) {
        return 'VERB';
    }
    if (in_array($pos, ['substantiv', 'noun', 'nn'], true)) {
        return 'NOUN';
    }
    if (in_array($pos, ['adjektiv', 'adjective', 'jj'], true)) {
        return 'ADJ';
    }
    if (in_array($pos, ['adverb', 'adv'], true)) {
        return 'ADV';
    }
    if (in_array($pos, ['pronomen', 'pronoun', 'pron'], true)) {
        return 'PRON';
    }
    return '';
}

/**
 * Tokenize to lowercase word tokens (letters only) for containment checks.
 */
function mod_flashcards_word_tokens(string $text): array {
    $text = core_text::strtolower($text);
    $text = mod_flashcards_normalize_text($text);
    if ($text === '') {
        return [];
    }
    if (!preg_match_all('/[\\p{L}\\p{M}]+/u', $text, $m)) {
        return [];
    }
    return array_values(array_filter(array_map('trim', $m[0] ?? [])));
}

/**
 * True when a lowercase token is present in text as a word token (diacritics-sensitive at PHP level).
 */
function mod_flashcards_token_in_text(string $token, string $text): bool {
    $token = core_text::strtolower(trim($token));
    if ($token === '' || $text === '') {
        return false;
    }
    $set = array_flip(mod_flashcards_word_tokens($text));
    return isset($set[$token]);
}

/**
 * Built-in meanings for частотных служебных слов, чтобы не ходить в Ordbøkene
 * и не получать нерелевантные статьи (напр. «for» в торговом смысле).
 *
 * @return array{expression:string,meanings:array<int,string>,examples:array<int,string>,forms:array<int,string>,dictmeta:array<mixed>,source:string,chosenMeaning:int}|null
 */
function mod_flashcards_builtin_function_word(string $word): ?array {
    $word = core_text::strtolower(trim($word));
    $map = [
        'for' => ['for (adv)', 'for mye / for stor / for vanskelig = too ...'],
        'til' => ['til (prep)', 'til skolen / til Norge / til deg = to / for / until'],
        'av' => ['av (prep)', 'et stykke av kaka = of / from / by'],
        'på' => ['på (prep)', 'på bordet / på skolen / på vei = on / at / onto'],
        'paa' => ['på (prep)', 'på bordet / på skolen / på vei = on / at / onto'], // грязный вариант
        'i' => ['i (prep)', 'i huset / i Norge = in / inside'],
        'om' => ['om (prep/konj)', 'snakke om / tenke om; hvis = about / around / if'],
        'med' => ['med (prep)', 'med venner / med meg = with'],
        'seg' => ['seg (refl)', 'refleksivt pronomen = oneself'],
        'det' => ['det (pron/det)', 'det er / det var = it / that'],
        'som' => ['som (konj/pron)', 'som lærer / som jeg sa = as / that / who'],
        'å' => ['å (inf‑mark)', 'å lese / å skrive = infinitive marker'],
        'åå' => ['å (inf‑mark)', 'å lese / å skrive = infinitive marker'], // возможный ввод с дубликатом
    ];
    if (!isset($map[$word])) {
        return null;
    }
    $expression = $word === 'paa' ? 'på' : $word;
    return [
        'expression' => $expression,
        'meanings' => $map[$word],
        'examples' => [],
        'forms' => [],
        'dictmeta' => [],
        'source' => 'builtin',
        'chosenMeaning' => 0,
    ];
}

/**
 * Check if a token is a common function word we want to keep as-is.
 */
function mod_flashcards_is_function_word(string $word): bool {
    $w = core_text::strtolower(trim($word));
    $functionwords = ['for','til','av','på','paa','i','om','med','seg','det','som','å','åå','aa'];
    return in_array($w, $functionwords, true);
}

/**
 * Parse arg codes from a tag (e.g., <trans11/på>, <refl4>).
 *
 * @return array<int,array{code:string,prep:string|null}>
 */
function mod_flashcards_extract_argcodes_from_tag(string $tag): array {
    $out = [];
    preg_match_all('/<([^>]+)>/', $tag, $m);
    foreach ($m[1] ?? [] as $raw) {
        if (preg_match('/^(trans|intrans|refl|ditrans|predik)/', $raw)) {
            $code = $raw;
            $prep = null;
            if (str_contains($raw, '/')) {
                [$code, $prep] = explode('/', $raw, 2);
                $prep = mod_flashcards_normalize_token(trim($prep));
            }
            $out[] = ['code' => $code, 'prep' => $prep ?: null];
        }
    }
    return $out;
}

/**
 * Notify course/site admins about a new card report.
 */
function mod_flashcards_notify_report(object $report, ?stdClass $course, ?stdClass $cm, int $userid): void {
    global $DB;

    $userfrom = $DB->get_record('user', ['id' => $userid]);
    $recipients = [];
    if ($course) {
        $coursecontext = context_course::instance($course->id);
        $recipients = get_enrolled_users($coursecontext, 'moodle/course:manageactivities');
    }
    if (empty($recipients)) {
        $recipients = get_admins();
    }
    if (empty($recipients)) {
        return;
    }

    $cardlabel = $report->cardtitle ?: $report->cardid;
    $linkparams = ['cardid' => $report->cardid];
    if (!empty($report->deckid)) {
        $linkparams['deckid'] = $report->deckid;
    }
    if (!empty($report->cmid)) {
        $linkparams['id'] = $report->cmid;
        $url = (new moodle_url('/mod/flashcards/view.php', $linkparams))->out(false);
    } else if ($cm) {
        $linkparams['id'] = $cm->id;
        $url = (new moodle_url('/mod/flashcards/view.php', $linkparams))->out(false);
    } else {
        $url = (new moodle_url('/mod/flashcards/my/index.php', $linkparams))->out(false);
    }

    foreach ($recipients as $admin) {
        $message = new \core\message\message();
        $message->component = 'mod_flashcards';
        $message->name = 'card_report';
        $message->userfrom = $userfrom ?: \core_user::get_support_user();
        $message->userto = $admin;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = get_string('report_open_card', 'mod_flashcards');
        $message->subject = get_string('report_notification_subject', 'mod_flashcards', (object)['card' => $cardlabel]);
        $message->fullmessage = get_string('report_notification_body', 'mod_flashcards', (object)[
            'user' => $userfrom ? fullname($userfrom) : '',
            'card' => $cardlabel,
            'message' => $report->message ?? '',
            'url' => $url,
        ]);
        $message->fullmessagehtml = get_string('report_notification_body_html', 'mod_flashcards', (object)[
            'user' => $userfrom ? fullname($userfrom) : '',
            'card' => $cardlabel,
            'message' => format_text($report->message ?? '', FORMAT_PLAIN),
            'url' => $url,
        ]);
        $message->fullmessageformat = FORMAT_HTML;
        message_send($message);
    }
}

/**
 * Normalize a whole text by applying token-level normalization to word chunks.
 */
function mod_flashcards_normalize_text(string $text): string {
    if ($text === '') {
        return $text;
    }
    $parts = preg_split('/(\\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        // Only normalize pure word chunks (letters/marks).
        if (preg_match('/^[\\p{L}\\p{M}]+$/u', $p)) {
            $parts[$i] = mod_flashcards_normalize_token($p);
        }
    }
    return implode('', $parts);
}

/**
 * Normalize a single token to handle common dirty inputs (pa->på, aa->å, a->å for inf marker).
 * Kept conservative to avoid breaking real words.
 */
function mod_flashcards_normalize_token(string $token): string {
    $t = core_text::strtolower(trim($token));
    if ($t === 'pa') { return 'på'; }
    if ($t === 'aa' || $t === 'a') { return 'å'; }
    if ($t === 'ae') { return 'æ'; }
    if ($t === 'oe') { return 'ø'; }
    return $t;
}

/**
 * Decide front-audio text based on UI rules (prefer uFront if long enough).
 *
 * @param string $fronttext
 * @param array $examples
 * @return string
 */
function mod_flashcards_choose_front_audio_text(string $fronttext, array $examples): string {
    $fronttext = trim($fronttext);
    if ($fronttext === '') {
        return '';
    }
    $articles = ['en','ei','et'];
    $markers = ['a','å','aa'];
    $count = 0;
    foreach (preg_split('/\s+/u', $fronttext) as $part) {
        $clean = trim($part, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean === '') {
            continue;
        }
        $lower = core_text::strtolower($clean);
        if (in_array($lower, $articles, true) || in_array($lower, $markers, true)) {
            continue;
        }
        $count++;
    }
    if ($count >= 3) {
        return $fronttext;
    }
    if (!empty($examples)) {
        $first = $examples[0];
        if (is_string($first)) {
            $parts = explode('|', $first, 2);
            $candidate = trim($parts[0] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        } else if (is_array($first)) {
            $candidate = trim((string)($first['text'] ?? $first['no'] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }
    return $fronttext;
}

/**
 * Build expression candidates from spaCy tokens around clicked word.
 *
 * @param string $fronttext
 * @param string $clickedword
 * @param array|null $spacydebug Optional: set to the raw spaCy response for debugging.
 * @return array<int,string>
 */
function mod_flashcards_spacy_expression_candidates(string $fronttext, string $clickedword, ?array &$spacydebug = null, ?array $spacyoverride = null): array {
    $cands = [];
    if ($fronttext === '' || $clickedword === '') {
        return $cands;
    }
    $spacy = $spacyoverride ?: mod_flashcards_spacy_analyze($fronttext);
    if (func_num_args() >= 3) {
        $spacydebug = $spacy;
    }
    $tokens = is_array($spacy['tokens'] ?? null) ? $spacy['tokens'] : [];
    if (empty($tokens)) {
        return $cands;
    }
    $clicked = mod_flashcards_normalize_token($clickedword);
    $reflexives = ['seg','meg','deg','oss','dere','dem'];
    $patternList = [
        'ADP NOUN',
        'ADP PRON',
        'ADP ADJ',
        'NOUN ADP',
        'VERB NOUN ADP',
        'AUX NOUN ADP',
        'ADJ ADP',
        'ADV ADP',
        'VERB ADP',
        'AUX ADP',
        'VERB PRON ADP',
        'AUX PRON ADP',
        'ADP NOUN ADP',
        'ADP PRON ADP',
        'ADP ADJ ADP',
        '(VERB|AUX) ADP NOUN',
        '(VERB|AUX) ADP PRON',
        '(VERB|AUX) ADP ADJ',
        '(VERB|AUX) ADP NOUN ADP',
        '(VERB|AUX) ADP PRON ADP',
        '(VERB|AUX) ADP ADJ ADP',
    ];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (empty($tok['is_alpha'])) {
            continue;
        }
        $tokNorm = mod_flashcards_normalize_token((string)($tok['text'] ?? ''));
        if ($tokNorm !== $clicked) {
            continue;
        }
        $pos = mod_flashcards_spacy_pos_to_coarse((string)($tok['pos'] ?? ''));
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        $prev2 = $tokens[$i - 2] ?? null;
        $next2 = $tokens[$i + 2] ?? null;
        $curLemma = core_text::strtolower((string)($tok['lemma'] ?? $tok['text'] ?? ''));
        $prevPos = $prev ? mod_flashcards_spacy_pos_to_coarse((string)($prev['pos'] ?? '')) : '';
        $nextPos = $next ? mod_flashcards_spacy_pos_to_coarse((string)($next['pos'] ?? '')) : '';
        $prevLemma = $prev ? core_text::strtolower((string)($prev['lemma'] ?? $prev['text'] ?? '')) : '';
        $nextLemma = $next ? core_text::strtolower((string)($next['lemma'] ?? $next['text'] ?? '')) : '';
        $prev2Pos = $prev2 ? mod_flashcards_spacy_pos_to_coarse((string)($prev2['pos'] ?? '')) : '';
        $prev2Lemma = $prev2 ? core_text::strtolower((string)($prev2['lemma'] ?? $prev2['text'] ?? '')) : '';
        $next2Pos = $next2 ? mod_flashcards_spacy_pos_to_coarse((string)($next2['pos'] ?? '')) : '';
        $next2Lemma = $next2 ? core_text::strtolower((string)($next2['lemma'] ?? $next2['text'] ?? '')) : '';

        if ($pos === 'NOUN') {
            if ($prev && $next && $prevPos === 'ADP' && $nextPos === 'ADP') {
                $phrase = trim($prevLemma . ' ' . $curLemma . ' ' . $nextLemma);
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
                if ($prev2 && in_array($prev2Pos, ['VERB','AUX'], true)) {
                    $pref = trim($prev2Lemma . ' ' . $phrase);
                    if ($pref !== '') {
                        $cands[] = $pref;
                    }
                }
            } else if ($prev && $next && in_array($prevPos, ['VERB','AUX'], true) && $nextPos === 'ADP') {
                $phrase = trim($prevLemma . ' ' . $curLemma . ' ' . $nextLemma);
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
            }
        } else if ($pos === 'VERB') {
            if ($next && $nextPos === 'ADP') {
                $phrase = trim($curLemma . ' ' . $nextLemma);
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
            }
            if ($next && $next2 && $nextPos === 'PRON' && $next2Pos === 'ADP') {
                $pron = in_array($nextLemma, $reflexives, true) ? 'seg' : $nextLemma;
                $phrase = trim($curLemma . ' ' . $pron . ' ' . $next2Lemma);
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
            }
        } else if ($pos === 'ADJ') {
            if ($next && $nextPos === 'ADP') {
                $phrase = trim($curLemma . ' ' . $nextLemma);
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
                if ($prev && in_array($prevPos, ['VERB','AUX'], true)) {
                    $pref = trim($prevLemma . ' ' . $phrase);
                    if ($pref !== '') {
                        $cands[] = $pref;
                    }
                }
            }
        }

        $winStart = max(0, $i - 3);
        $winEnd = min($count - 1, $i + 3);
        for ($s = $winStart; $s <= $i; $s++) {
            for ($e = $i; $e <= $winEnd; $e++) {
                $len = $e - $s + 1;
                if ($len < 2 || $len > 4) {
                    continue;
                }
                $span = array_slice($tokens, $s, $len);
                $spanPos = [];
                $spanLemma = [];
                $allAlpha = true;
                foreach ($span as $t) {
                    if (empty($t['is_alpha'])) {
                        $allAlpha = false;
                        break;
                    }
                    $p = mod_flashcards_spacy_pos_to_coarse((string)($t['pos'] ?? ''));
                    if ($p === 'PART') {
                        $p = 'ADP';
                    }
                    $spanPos[] = $p;
                    $lemma = core_text::strtolower((string)($t['lemma'] ?? $t['text'] ?? ''));
                    if ($p === 'PRON' && in_array($lemma, $reflexives, true)) {
                        $lemma = 'seg';
                    }
                    $spanLemma[] = $lemma;
                }
                if (!$allAlpha) {
                    continue;
                }
                $posStr = implode(' ', $spanPos);
                $matched = false;
                foreach ($patternList as $pattern) {
                    if (preg_match('~^' . $pattern . '$~', $posStr)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $phrase = trim(implode(' ', $spanLemma));
                if ($phrase !== '') {
                    $cands[] = $phrase;
                }
            }
        }
    }
    return array_values(array_unique(array_filter($cands)));
}

/**
 * Word tokens with offsets (plain text).
 *
 * @param string $text
 * @return array<int,array{text:string,start:int,end:int}>
 */
function mod_flashcards_word_tokens_with_offsets(string $text): array {
    $out = [];
    if ($text === '') {
        return $out;
    }
    if (preg_match_all('/\p{L}+/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $token = (string)($match[0] ?? '');
            $start = (int)($match[1] ?? 0);
            if ($token === '') {
                continue;
            }
            $out[] = [
                'text' => $token,
                'start' => $start,
                'end' => $start + strlen($token),
            ];
        }
    }
    return $out;
}

/**
 * Analyze text with spaCy (cached); returns empty array on failure.
 *
 * @param string $text
 * @return array
 */
function mod_flashcards_spacy_analyze(string $text): array {
    try {
        $client = new \mod_flashcards\local\spacy_client();
        if (!$client->is_enabled()) {
            return [];
        }
        return $client->analyze_text($text);
    } catch (\Throwable $e) {
        debugging('[flashcards] spaCy analyze failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return [];
    }
}

/**
 * Map spaCy POS to our coarse POS tags.
 *
 * @param string $pos
 * @return string
 */
function mod_flashcards_spacy_pos_to_coarse(string $pos): string {
    $pos = core_text::strtoupper(trim($pos));
    if ($pos === 'AUX') {
        return 'VERB';
    }
    if ($pos === 'CCONJ' || $pos === 'SCONJ') {
        return 'CONJ';
    }
    return $pos;
}

/**
 * Map spaCy POS to output POS tags (UI-facing).
 * Keeps SCONJ/CCONJ distinct so the UI can display subjunctions correctly.
 *
 * @param string $pos
 * @return string
 */
function mod_flashcards_spacy_pos_to_output(string $pos): string {
    $pos = core_text::strtoupper(trim($pos));
    // Preserve current behavior for AUX (UI expects "Verb"), but keep conjunction subtypes distinct.
    if ($pos === 'AUX') {
        return 'VERB';
    }
    return $pos;
}

/**
 * Build spaCy POS map aligned to word tokens.
 *
 * @param string $text
 * @param array $spacy
 * @param string $mode 'coarse' (default) or 'output'
 * @return array<int,string>
 */
function mod_flashcards_spacy_pos_map(string $text, array $spacy, string $mode = 'coarse'): array {
    $map = [];
    $spacytokens = is_array($spacy['tokens'] ?? null) ? $spacy['tokens'] : [];
    if (empty($spacytokens)) {
        return $map;
    }
    $wordtokens = mod_flashcards_word_tokens_with_offsets($text);
    if (empty($wordtokens)) {
        return $map;
    }
    $j = 0;
    $sn = count($spacytokens);
    $mode = core_text::strtolower(trim($mode));
    foreach ($wordtokens as $i => $w) {
        $wText = core_text::strtolower((string)($w['text'] ?? ''));
        $wNorm = mod_flashcards_normalize_token($wText);
        if ($wNorm === '') {
            continue;
        }
        for (; $j < $sn; $j++) {
            $s = $spacytokens[$j];
            if (empty($s['is_alpha'])) {
                continue;
            }
            $sText = core_text::strtolower((string)($s['text'] ?? ''));
            $sNorm = mod_flashcards_normalize_token($sText);
            if ($sNorm !== '' && $sNorm === $wNorm) {
                $rawpos = (string)($s['pos'] ?? '');
                if ($mode === 'output') {
                    $map[$i] = mod_flashcards_spacy_pos_to_output($rawpos);
                } else {
                    $map[$i] = mod_flashcards_spacy_pos_to_coarse($rawpos);
                }
                $j++;
                break;
            }
        }
    }
    return $map;
}

/**
 * Build spaCy lemma map aligned to word tokens.
 *
 * @param string $text
 * @param array $spacy
 * @return array<int,string>
 */
function mod_flashcards_spacy_lemma_map(string $text, array $spacy): array {
    $map = [];
    $spacytokens = is_array($spacy['tokens'] ?? null) ? $spacy['tokens'] : [];
    if (empty($spacytokens)) {
        return $map;
    }
    $wordtokens = mod_flashcards_word_tokens_with_offsets($text);
    if (empty($wordtokens)) {
        return $map;
    }
    $j = 0;
    $sn = count($spacytokens);
    $reflexives = ['seg','meg','deg','oss','dere','dem'];
    foreach ($wordtokens as $i => $w) {
        $wText = core_text::strtolower((string)($w['text'] ?? ''));
        $wNorm = mod_flashcards_normalize_token($wText);
        if ($wNorm === '') {
            continue;
        }
        for (; $j < $sn; $j++) {
            $s = $spacytokens[$j];
            if (empty($s['is_alpha'])) {
                continue;
            }
            $sText = core_text::strtolower((string)($s['text'] ?? ''));
            $sNorm = mod_flashcards_normalize_token($sText);
            if ($sNorm !== '' && $sNorm === $wNorm) {
                $lemma = core_text::strtolower((string)($s['lemma'] ?? $s['text'] ?? ''));
                if ($lemma !== '' && in_array($lemma, $reflexives, true)) {
                    $lemma = 'seg';
                }
                $map[$i] = $lemma;
                $j++;
                break;
            }
        }
    }
    return $map;
}

/**
 * Build spaCy dependency map aligned to word tokens.
 *
 * @param string $text
 * @param array $spacy
 * @return array<int,string>
 */
function mod_flashcards_spacy_dep_map(string $text, array $spacy): array {
    $map = [];
    $spacytokens = is_array($spacy['tokens'] ?? null) ? $spacy['tokens'] : [];
    if (empty($spacytokens)) {
        return $map;
    }
    $wordtokens = mod_flashcards_word_tokens_with_offsets($text);
    if (empty($wordtokens)) {
        return $map;
    }
    $j = 0;
    $sn = count($spacytokens);
    foreach ($wordtokens as $i => $w) {
        $wText = core_text::strtolower((string)($w['text'] ?? ''));
        $wNorm = mod_flashcards_normalize_token($wText);
        if ($wNorm === '') {
            continue;
        }
        for (; $j < $sn; $j++) {
            $s = $spacytokens[$j];
            if (empty($s['is_alpha'])) {
                continue;
            }
            $sText = core_text::strtolower((string)($s['text'] ?? ''));
            $sNorm = mod_flashcards_normalize_token($sText);
            if ($sNorm !== '' && $sNorm === $wNorm) {
                $map[$i] = (string)($s['dep'] ?? '');
                $j++;
                break;
            }
        }
    }
    return $map;
}

/**
 * Detect particle-like tokens that often belong to fixed verb/preposition expressions.
 */
function mod_flashcards_is_particle_like(string $token): bool {
    $token = mod_flashcards_normalize_token($token);
    if ($token === '') {
        return false;
    }
    $particles = [
        'om', 'opp', 'ut', 'inn', 'innom', 'ned', 'over', 'til', 'fra',
        'for', 'med', 'av', 'på', 'paa', 'pa', 'igjen', 'bort', 'fram', 'frem',
        'hjem', 'hjemme', 'hjemmefra', 'etter', 'under', 'uten', 'hos',
        'mot', 'mellom', 'rundt',
    ];
    return in_array($token, $particles, true);
}

/**
 * Build multiword expression candidates from word tokens using spaCy+Ordbank signals.
 *
 * @param array<int,array<string,mixed>> $words
 * @param array<int,string> $lemmaMap
 * @param array<int,string> $posMap
 * @param array<int,array<int,string>> $posCandidatesMap
 * @param array<int,array<int,string>> $verbPrepsMap
 * @param array<int,bool> $verbAnyPrepMap
 * @return array<int,array{lemma:string,surface:string,len:int,score:int,source:string}>
 */
function mod_flashcards_expression_candidates_from_words(array $words, array $lemmaMap, array $posMap, array $posCandidatesMap, array $verbPrepsMap, array $verbAnyPrepMap, array $depMap = []): array {
    $out = [];
    $count = count($words);
    if ($count < 2) {
        return $out;
    }
    $maxLen = 7;
    $corePos = ['VERB','NOUN','ADJ','ADP','PART','CONJ'];
    $reflexives = ['seg','meg','deg','oss','dere','dem'];
    $particleLexicon = [
        'opp','ned','ut','inn','bort','fram','tilbake','av','på','an','unna','innom','ifra','hjemme','fast',
    ];
    $twoWordSubjunctionWhitelist = ['selv om','som om','om enn','enn si'];
    $highValueDiscourseWhitelist = ['i det hele tatt','for det meste','med andre ord','før eller siden','ikke bare bare','ikke så verst'];
    $particleDepLabels = ['prt','compound:prt','compound_prt'];
    $blockedPos = ['VERB','AUX','SCONJ','CCONJ'];
    $gapAllowedNoun = ['DET','ADJ','ADV','NUM','PROPN'];
    $gapAllowedVerbPrep = ['DET','ADJ','ADV','NUM','PROPN','NOUN','PRON'];
    $gapAllowedAdj = ['ADV','PART'];
    $gapAllowedReflexive = ['ADV','PART','DET','ADJ','NOUN','PRON','NUM','PROPN'];
    $copularVerbs = ['v?re','bli'];

    $posSets = [];
    $lemmaForExpr = [];
    $surfaceLower = [];
    foreach ($words as $i => $w) {
        $surface = core_text::strtolower((string)($w['text'] ?? ''));
        $surfaceLower[$i] = $surface;
        $set = [];
        if (!empty($posMap[$i])) {
            $set[] = $posMap[$i];
        }
        if (!empty($posCandidatesMap[$i])) {
            $set = array_merge($set, $posCandidatesMap[$i]);
        }
        $set = array_values(array_unique(array_filter($set)));
        // Treat lower‑case proper nouns as common nouns for pattern matching (e.g. "for ordens skyld").
        if (in_array('PROPN', $set, true)) {
            $orig = (string)($w['text'] ?? '');
            if ($orig !== '' && core_text::strtolower($orig) === $orig) {
                $set[] = 'NOUN';
            }
        }
        $posTagSet = $set ?: ['X'];
        $posSets[$i] = $posTagSet;
        $lemma = $lemmaMap[$i] ?? '';
        if ($lemma === '') {
            $lemma = $surface;
        }
        $lemma = core_text::strtolower($lemma);
        // Normalize verb lemmas to infinitive so expression candidates hit Ordbokene
        // (e.g. "skilte" -> "skille").
        if (in_array('VERB', $posTagSet, true) || in_array('AUX', $posTagSet, true)) {
            $lemma = mod_flashcards_normalize_infinitive($lemma);
        }
        $lemmaForExpr[$i] = $lemma;
    }

    $rules = [
        ['id' => 'R01', 'pattern' => ['ADP','CONJ','ADP'], 'priority' => 1, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 1, 'value' => 'og'],
        ]],
        ['id' => 'R02', 'pattern' => ['ADP','NOUN','ADP'], 'priority' => 1],
        ['id' => 'R09', 'pattern' => ['VERB','PRON','ADP'], 'priority' => 1, 'verb_prep_required' => true, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 1, 'values' => $reflexives],
        ]],
        ['id' => 'R09B', 'pattern' => ['VERB','PRON','PRON','ADP'], 'priority' => 1, 'verb_prep_required' => true,
            'drop_indices' => [1],
            'constraints' => [
                ['type' => 'lemma_equals', 'index' => 1, 'value' => 'det'],
                ['type' => 'lemma_in', 'index' => 2, 'values' => $reflexives],
            ],
        ],
        ['id' => 'R10', 'pattern' => ['VERB','ADP','PRON'], 'priority' => 1, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 2, 'values' => $reflexives],
        ]],
        ['id' => 'R11', 'pattern' => ['VERB','NOUN','ADP'], 'priority' => 1, 'verb_prep_required' => true],
        ['id' => 'R03', 'pattern' => ['ADP','NOUN','NOUN'], 'priority' => 2],
        ['id' => 'R08', 'pattern' => ['ADV','CONJ','ADV'], 'priority' => 2, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 1, 'value' => 'og'],
        ]],
        ['id' => 'R14', 'pattern' => ['VERB','PRON','ADV'], 'priority' => 2, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 1, 'values' => $reflexives],
            ['type' => 'lemma_in', 'index' => 2, 'values' => $particleLexicon],
        ]],
        ['id' => 'R20', 'pattern' => ['ADP','NOUN','CONJ','NOUN'], 'priority' => 2, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 2, 'value' => 'og'],
        ]],
        ['id' => 'R26', 'pattern' => ['NOUN','ADP','NOUN'], 'priority' => 2, 'constraints' => [
            ['type' => 'lemma_repeat', 'left' => 0, 'right' => 2],
        ]],
        ['id' => 'R04', 'pattern' => ['ADP','NOUN'], 'priority' => 3, 'constraints' => [
            ['type' => 'lemma_not_in', 'index' => 0, 'values' => ['enn']],
        ]],
        ['id' => 'R06', 'pattern' => ['ADP','DET','ADJ'], 'priority' => 3],
        ['id' => 'R05', 'pattern' => ['ADP','DET','NOUN'], 'priority' => 3],
        ['id' => 'R07', 'pattern' => ['ADP','ADV','NOUN'], 'priority' => 3, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 1, 'values' => ['så']],
        ]],
        ['id' => 'R13', 'pattern' => ['VERB','ADV'], 'priority' => 3, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 1, 'values' => $particleLexicon],
        ]],
        ['id' => 'R15', 'pattern' => ['VERB','ADP','NOUN'], 'priority' => 3, 'verb_prep_required' => true, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 0, 'values' => ['gå','komme','være','sette','bli']],
        ]],
        ['id' => 'R21', 'pattern' => ['ADV','ADV'], 'priority' => 3, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 0, 'values' => $particleLexicon],
            ['type' => 'lemma_in', 'index' => 1, 'values' => $particleLexicon],
        ]],
        ['id' => 'R27', 'pattern' => ['VERB','CONJ','VERB'], 'priority' => 4, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 1, 'value' => 'og'],
        ]],
        ['id' => 'R24', 'pattern' => ['ADJ','CONJ','ADJ'], 'priority' => 4, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 1, 'value' => 'og'],
        ]],
        ['id' => 'R28', 'pattern' => ['ADV','CONJ'], 'priority' => 4, 'constraints' => [
            ['type' => 'whitelist_phrase', 'items' => $twoWordSubjunctionWhitelist],
        ]],
        ['id' => 'R29', 'pattern' => ['ADP','DET','ADJ','VERB'], 'priority' => 4, 'constraints' => [
            ['type' => 'whitelist_phrase', 'items' => $highValueDiscourseWhitelist],
        ]],
        ['id' => 'R30', 'pattern' => ['ADP','DET','NOUN'], 'priority' => 5, 'constraints' => [
            ['type' => 'whitelist_phrase', 'items' => $highValueDiscourseWhitelist],
        ]],
        ['id' => 'R31', 'pattern' => ['ADP','ADJ','NOUN'], 'priority' => 5, 'constraints' => [
            ['type' => 'whitelist_phrase', 'items' => $highValueDiscourseWhitelist],
        ]],
        ['id' => 'R32', 'pattern' => ['ADV','CONJ','ADV'], 'priority' => 5, 'constraints' => [
            ['type' => 'lemma_equals', 'index' => 1, 'value' => 'eller'],
        ]],
        ['id' => 'R12', 'pattern' => ['VERB','ADP'], 'priority' => 6, 'verb_prep_required' => true],
        ['id' => 'R16', 'pattern' => ['VERB','ADV','ADP'], 'priority' => 6, 'verb_prep_required' => true, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 1, 'values' => $particleLexicon],
        ]],
        ['id' => 'R17', 'pattern' => ['ADJ','ADP'], 'priority' => 6],
        ['id' => 'R18', 'pattern' => ['VERB','ADJ','ADP'], 'priority' => 6, 'verb_prep_required' => true, 'constraints' => [
            ['type' => 'lemma_in', 'index' => 0, 'values' => ['være','bli']],
        ]],
        ['id' => 'R19', 'pattern' => ['NOUN','ADP','NOUN'], 'priority' => 6],
        // R22 removed: VERB+NOUN is too noisy for expression candidates.
        // R23 removed: PRON+VERB+VERB is too noisy for expression candidates.
    ];

    $matchRule = function(int $start, array $rule) use ($posSets, $lemmaForExpr, $surfaceLower, $verbPrepsMap, $verbAnyPrepMap, $reflexives): ?array {
        $pattern = $rule['pattern'] ?? [];
        $len = count($pattern);
        $lemmaParts = [];
        $surfaceParts = [];
        $hasVerb = in_array('VERB', $pattern, true);
        $drop = $rule['drop_indices'] ?? [];
        $dropSet = is_array($drop) ? array_flip($drop) : [];
        $indexMap = [];
        $gapSpec = $rule['gap_after_first'] ?? null;
        if (is_array($gapSpec) && $len === 3) {
            $maxGap = (int)($gapSpec['max'] ?? 0);
            $allowPos = $gapSpec['allow_pos'] ?? [];
            if ($maxGap < 0) {
                return null;
            }
            $indexMap[0] = $start;
            $pronIndex = null;
            for ($j = $start + 1; $j <= $start + 1 + $maxGap; $j++) {
                if (!isset($posSets[$j])) {
                    return null;
                }
                if (!isset($posSets[$j]) || !in_array('PRON', $posSets[$j], true)) {
                    $posOk = true;
                    if (is_array($allowPos) && !empty($allowPos)) {
                        $posOk = (bool)array_intersect($posSets[$j], $allowPos);
                    }
                    if (!$posOk) {
                        return null;
                    }
                    continue;
                }
                $pronIndex = $j;
                break;
            }
            if ($pronIndex === null) {
                return null;
            }
            $adpIndex = $pronIndex + 1;
            if (!isset($posSets[$adpIndex]) || !in_array('ADP', $posSets[$adpIndex], true)) {
                return null;
            }
            $indexMap[1] = $pronIndex;
            $indexMap[2] = $adpIndex;
        }
        for ($i = 0; $i < $len; $i++) {
            $idx = $indexMap[$i] ?? ($start + $i);
            $pos = $pattern[$i];
            $set = $posSets[$idx] ?? [];
            if (empty($set) || !in_array($pos, $set, true)) {
                return null;
            }
            $lemmaTok = $lemmaForExpr[$idx] ?? '';
            $surfaceTok = $surfaceLower[$idx] ?? '';
            if ($lemmaTok === '' || $surfaceTok === '') {
                return null;
            }
            $lemmaTokNorm = $lemmaTok;
            if ($pos === 'PRON' && in_array($surfaceTok, $reflexives, true)) {
                $lemmaTokNorm = 'seg';
            }
            if (!isset($dropSet[$i])) {
                $useLemma = $hasVerb && ($pos === 'VERB' || $lemmaTokNorm === 'seg');
                $lemmaParts[] = $useLemma ? $lemmaTokNorm : $surfaceTok;
                $surfaceParts[] = $surfaceTok;
            }
        }
        $constraints = $rule['constraints'] ?? [];
        foreach ($constraints as $c) {
            $type = $c['type'] ?? '';
            if ($type === 'lemma_equals') {
                $patternIdx = (int)($c['index'] ?? 0);
                $idx = $indexMap[$patternIdx] ?? ($start + $patternIdx);
                $val = (string)($c['value'] ?? '');
                if ($val === '' || ($lemmaForExpr[$idx] ?? '') !== $val) {
                    return null;
                }
            } else if ($type === 'lemma_in') {
                $patternIdx = (int)($c['index'] ?? 0);
                $idx = $indexMap[$patternIdx] ?? ($start + $patternIdx);
                $values = $c['values'] ?? [];
                if (!is_array($values) || empty($values)) {
                    return null;
                }
                $lemma = $lemmaForExpr[$idx] ?? '';
                if ($lemma === '' || !in_array($lemma, $values, true)) {
                    $surface = $surfaceLower[$idx] ?? '';
                    if ($surface === '' || !in_array($surface, $values, true)) {
                        return null;
                    }
                }
            } else if ($type === 'lemma_not_in') {
                $patternIdx = (int)($c['index'] ?? 0);
                $idx = $indexMap[$patternIdx] ?? ($start + $patternIdx);
                $values = $c['values'] ?? [];
                if (!is_array($values) || empty($values)) {
                    continue;
                }
                $lemma = $lemmaForExpr[$idx] ?? '';
                if ($lemma !== '' && in_array($lemma, $values, true)) {
                    return null;
                }
            } else if ($type === 'lemma_repeat') {
                $leftIdx = (int)($c['left'] ?? 0);
                $rightIdx = (int)($c['right'] ?? 0);
                $left = $indexMap[$leftIdx] ?? ($start + $leftIdx);
                $right = $indexMap[$rightIdx] ?? ($start + $rightIdx);
                if (($lemmaForExpr[$left] ?? '') === '' || ($lemmaForExpr[$right] ?? '') === '') {
                    return null;
                }
                if ($lemmaForExpr[$left] !== $lemmaForExpr[$right]) {
                    return null;
                }
            } else if ($type === 'whitelist_phrase') {
                $items = $c['items'] ?? [];
                if (!is_array($items) || empty($items)) {
                    return null;
                }
                $phrase = implode(' ', $lemmaParts);
                if (!in_array($phrase, $items, true)) {
                    return null;
                }
            }
        }
        if (!empty($rule['verb_prep_required'])) {
            $verbPos = array_search('VERB', $pattern, true);
            if ($verbPos !== false) {
                $verbIdx = $start + $verbPos;
                $preps = $verbPrepsMap[$verbIdx] ?? [];
                $allowAny = !empty($verbAnyPrepMap[$verbIdx]);
                if (!$allowAny) {
                    $prepSet = !empty($preps) ? array_flip($preps) : [];
                    $matched = false;
                    foreach ($pattern as $pIdx => $pos) {
                        if ($pos !== 'ADP') {
                            continue;
                        }
                        $pLemma = $lemmaForExpr[$start + $pIdx] ?? '';
                        if ($pLemma !== '' && isset($prepSet[$pLemma])) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return null;
                    }
                }
            }
        }
        $priority = (int)($rule['priority'] ?? 5);
        $score = max(1, 10 - $priority) + $len;
        return [
            'lemma' => implode(' ', $lemmaParts),
            'surface' => implode(' ', $surfaceParts),
            'len' => $len,
            'score' => $score,
            'source' => $rule['id'] ?? 'rule',
            'start' => $start,
            'end' => $start + $len - 1,
        ];
    };

    $seenRule = [];
    $posIn = function(int $idx, array $allowed) use ($posSets, $posMap): bool {
        $primary = $posMap[$idx] ?? '';
        if ($primary !== '' && in_array($primary, $allowed, true)) {
            return true;
        }
        $set = $posSets[$idx] ?? [];
        return !empty(array_intersect($set, $allowed));
    };
    $isParticleToken = function(int $idx) use ($lemmaForExpr, $posMap, $posSets, $depMap, $particleLexicon, $particleDepLabels): bool {
        $lemma = $lemmaForExpr[$idx] ?? '';
        if ($lemma === '' || !in_array($lemma, $particleLexicon, true)) {
            return false;
        }
        $dep = $depMap[$idx] ?? '';
        if ($dep !== '' && in_array($dep, $particleDepLabels, true)) {
            return true;
        }
        $primary = $posMap[$idx] ?? '';
        if (in_array($primary, ['ADV','PART'], true)) {
            return true;
        }
        $set = $posSets[$idx] ?? [];
        return !empty(array_intersect($set, ['ADV','PART']));
    };
    $addCandidate = function(string $expr, int $start, int $end, string $source, int $score, ?int $maxGap = null) use (&$out, &$seenRule) {
        $expr = trim($expr);
        if ($expr === '') {
            return;
        }
        $key = core_text::strtolower($expr);
        if (isset($seenRule[$key])) {
            return;
        }
        $seenRule[$key] = true;
        $len = count(array_filter(explode(' ', $expr)));
        $candidate = [
            'lemma' => $expr,
            'surface' => $expr,
            'len' => $len,
            'score' => $score,
            'source' => $source,
            'start' => $start,
            'end' => $end,
        ];
        if ($maxGap !== null) {
            $candidate['max_gap'] = $maxGap;
        }
        $out[] = $candidate;
    };
    $addCandidateSurface = function(string $lemmaExpr, string $surfaceExpr, int $start, int $end, string $source, int $score, ?int $maxGap = null) use (&$out, &$seenRule) {
        $lemmaExpr = trim($lemmaExpr);
        $surfaceExpr = trim($surfaceExpr);
        if ($lemmaExpr === '' || $surfaceExpr === '') {
            return;
        }
        $key = core_text::strtolower($lemmaExpr);
        if (isset($seenRule[$key])) {
            return;
        }
        $seenRule[$key] = true;
        $len = count(array_filter(explode(' ', $lemmaExpr)));
        $candidate = [
            'lemma' => $lemmaExpr,
            'surface' => $surfaceExpr,
            'len' => $len,
            'score' => $score,
            'source' => $source,
            'start' => $start,
            'end' => $end,
        ];
        if ($maxGap !== null) {
            $candidate['max_gap'] = $maxGap;
        }
        $out[] = $candidate;
    };
    if (!empty($depMap)) {
        for ($i = 0; $i < $count; $i++) {
            if (($posMap[$i] ?? '') !== 'VERB') {
                continue;
            }
            $verbLemma = $lemmaForExpr[$i] ?? '';
            if ($verbLemma === '') {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $surface = $surfaceLower[$j] ?? '';
                if ($surface === '' || !in_array($surface, $reflexives, true)) {
                    continue;
                }
                $dep = $depMap[$j] ?? '';
                if ($dep !== '' && !in_array($dep, ['obj','iobj','expl','nmod','obl'], true)) {
                    continue;
                }
                $blocked = false;
                for ($k = $i + 1; $k < $j; $k++) {
                    if (in_array(($posMap[$k] ?? ''), $blockedPos, true)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                $prepIdx = null;
                $prepAllowed = true;
                $maxRefGap = 8;
                for ($k = $j + 1; $k < $count && ($k - $j - 1) <= $maxRefGap; $k++) {
                    if (in_array(($posMap[$k] ?? ''), $blockedPos, true)) {
                        break;
                    }
                    if (($posMap[$k] ?? '') !== 'ADP') {
                        if (!$posIn($k, $gapAllowedReflexive)) {
                            break;
                        }
                        continue;
                    }
                    $prepLemma = $lemmaForExpr[$k] ?? '';
                    if ($prepLemma === '') {
                        continue;
                    }
                    $preps = $verbPrepsMap[$i] ?? [];
                    $allowAny = !empty($verbAnyPrepMap[$i]);
                    if (!$allowAny && !empty($preps)) {
                        $prepSet = array_flip($preps);
                        if (!isset($prepSet[$prepLemma])) {
                            $prepAllowed = false;
                        }
                    }
                    $prepIdx = $k;
                    break;
                }
                if ($prepIdx === null) {
                    continue;
                }
                $expr = trim($verbLemma . ' seg ' . ($lemmaForExpr[$prepIdx] ?? ''));
                if ($expr === '') {
                    continue;
                }
                $surfaceExpr = trim(
                    ($surfaceLower[$i] ?? $verbLemma) . ' ' .
                    ($surfaceLower[$j] ?? 'seg') . ' ' .
                    ($surfaceLower[$prepIdx] ?? ($lemmaForExpr[$prepIdx] ?? ''))
                );
                $gap1 = $j - $i - 1;
                $gap2 = $prepIdx - $j - 1;
                $maxGap = max($gap1, $gap2);
                $source = $prepAllowed ? 'dep_reflexive' : 'dep_reflexive_soft';
                $score = $prepAllowed ? 12 : 8;
                $addCandidateSurface($expr, $surfaceExpr, $i, $prepIdx, $source, $score, $maxGap);
            }
        }
    }
    for ($i = 0; $i < $count; $i++) {
        if (($posMap[$i] ?? '') !== 'VERB') {
            continue;
        }
            $verbLemma = $lemmaForExpr[$i] ?? '';
            if ($verbLemma === '') {
                continue;
            }
            $maxParticleGap = 8;
            for ($j = $i + 1; $j < $count && ($j - $i - 1) <= $maxParticleGap; $j++) {
            if (in_array(($posMap[$j] ?? ''), $blockedPos, true)) {
                break;
            }
            if (!$isParticleToken($j)) {
                continue;
            }
            $particleLemma = $lemmaForExpr[$j] ?? '';
            if ($particleLemma === '') {
                continue;
            }
            $segIdx = null;
            for ($k = $i + 1; $k < $j; $k++) {
                $surface = $surfaceLower[$k] ?? '';
                if ($surface === '' || !in_array($surface, $reflexives, true)) {
                    continue;
                }
                $dep = $depMap[$k] ?? '';
                if ($dep !== '' && !in_array($dep, ['obj','iobj','expl','nmod','obl'], true)) {
                    continue;
                }
                if (in_array(($posMap[$k] ?? ''), $blockedPos, true)) {
                    break;
                }
                $segIdx = $k;
                break;
            }
            if ($segIdx !== null) {
                $gap1 = $segIdx - $i - 1;
                $gap2 = $j - $segIdx - 1;
                $maxGap = max($gap1, $gap2);
                $expr = trim($verbLemma . ' seg ' . $particleLemma);
                $surfaceExpr = trim(
                    ($surfaceLower[$i] ?? $verbLemma) . ' ' .
                    ($surfaceLower[$segIdx] ?? 'seg') . ' ' .
                    ($surfaceLower[$j] ?? $particleLemma)
                );
                $addCandidateSurface($expr, $surfaceExpr, $i, $j, 'dep_reflexive_particle', 11, $maxGap);
            } else {
                $gap = $j - $i - 1;
                $expr = trim($verbLemma . ' ' . $particleLemma);
                $addCandidate($expr, $i, $j, 'dep_particle', 10, $gap);
            }
        }
        if (in_array($verbLemma, $copularVerbs, true)) {
            $maxAdjGap = 3;
            $adjIdx = null;
            for ($j = $i + 1; $j < $count && ($j - $i - 1) <= $maxAdjGap; $j++) {
                if (in_array(($posMap[$j] ?? ''), $blockedPos, true)) {
                    break;
                }
                if (($posMap[$j] ?? '') === 'ADJ') {
                    $adjIdx = $j;
                    break;
                }
                if (!$posIn($j, $gapAllowedAdj)) {
                    break;
                }
            }
            if ($adjIdx !== null) {
                $adpIdx = null;
                for ($k = $adjIdx + 1; $k < $count && ($k - $adjIdx - 1) <= 1; $k++) {
                    if (in_array(($posMap[$k] ?? ''), $blockedPos, true)) {
                        break;
                    }
                    if (($posMap[$k] ?? '') === 'ADP') {
                        $adpIdx = $k;
                        break;
                    }
                    if (!$posIn($k, $gapAllowedAdj)) {
                        break;
                    }
                }
                if ($adpIdx !== null) {
                    $adjLemma = $lemmaForExpr[$adjIdx] ?? '';
                    $prepLemma = $lemmaForExpr[$adpIdx] ?? '';
                    if ($adjLemma !== '' && $prepLemma !== '') {
                        $gap1 = $adjIdx - $i - 1;
                        $gap2 = $adpIdx - $adjIdx - 1;
                        $maxGap = max($gap1, $gap2);
                        $expr = trim($verbLemma . ' ' . $adjLemma . ' ' . $prepLemma);
                        $addCandidate($expr, $i, $adpIdx, 'gap_adj_prep', 9, $maxGap);
                    }
                }
            }
        }
        $maxVerbPrepGap = 6;
        $prepIdx = null;
        $prepAllowed = true;
        for ($j = $i + 1; $j < $count && ($j - $i - 1) <= $maxVerbPrepGap; $j++) {
            if (in_array(($posMap[$j] ?? ''), $blockedPos, true)) {
                break;
            }
            if (($posMap[$j] ?? '') !== 'ADP') {
                if (!$posIn($j, $gapAllowedVerbPrep)) {
                    break;
                }
                continue;
            }
            $prepLemma = $lemmaForExpr[$j] ?? '';
            if ($prepLemma === '') {
                continue;
            }
            $preps = $verbPrepsMap[$i] ?? [];
            $allowAny = !empty($verbAnyPrepMap[$i]);
            if (!$allowAny && !empty($preps)) {
                $prepSet = array_flip($preps);
                if (!isset($prepSet[$prepLemma])) {
                    $prepAllowed = false;
                }
            }
            $prepIdx = $j;
            break;
        }
        if ($prepIdx !== null) {
            $prepLemma = $lemmaForExpr[$prepIdx] ?? '';
            if ($prepLemma !== '') {
                $expr = trim($verbLemma . ' ' . $prepLemma);
                $gap = $prepIdx - $i - 1;
                $source = $prepAllowed ? 'gap_verb_prep' : 'gap_verb_prep_soft';
                $score = $prepAllowed ? 9 : 7;
                $addCandidate($expr, $i, $prepIdx, $source, $score, $gap);
            }
        }
        $maxNounGap = 4;
        $nounIdx = null;
        for ($j = $i + 1; $j < $count && ($j - $i - 1) <= $maxNounGap; $j++) {
            if (in_array(($posMap[$j] ?? ''), $blockedPos, true)) {
                break;
            }
            if (($posMap[$j] ?? '') === 'NOUN') {
                $nounIdx = $j;
                break;
            }
            if (!$posIn($j, $gapAllowedNoun)) {
                break;
            }
        }
        if ($nounIdx !== null) {
            $adpIdx = $nounIdx + 1;
            if ($adpIdx < $count && ($posMap[$adpIdx] ?? '') === 'ADP') {
                $prepLemma = $lemmaForExpr[$adpIdx] ?? '';
                if ($prepLemma !== '') {
                    $preps = $verbPrepsMap[$i] ?? [];
                    $allowAny = !empty($verbAnyPrepMap[$i]);
                    if ($allowAny || empty($preps) || in_array($prepLemma, $preps, true)) {
                        $nounLemma = $lemmaForExpr[$nounIdx] ?? '';
                        if ($nounLemma !== '') {
                            $gap = $nounIdx - $i - 1;
                            $expr = trim($verbLemma . ' ' . $nounLemma . ' ' . $prepLemma);
                            $addCandidate($expr, $i, $adpIdx, 'gap_noun_prep', 9, $gap);
                        }
                    }
                }
            }
        }
    }
    foreach ($rules as $rule) {
        $len = count($rule['pattern'] ?? []);
        if ($len < 2) {
            continue;
        }
        for ($start = 0; $start + $len - 1 < $count; $start++) {
            $candidate = $matchRule($start, $rule);
            if ($candidate === null) {
                continue;
            }
            $key = core_text::strtolower($candidate['lemma']);
            if ($key === '' || isset($seenRule[$key])) {
                continue;
            }
            $seenRule[$key] = true;
            $out[] = $candidate;
        }
    }

    $buildCandidate = function(int $start, int $end, string $source, int $scoreBase) use ($posSets, $posMap, $lemmaForExpr, $surfaceLower, $corePos, $reflexives, $verbPrepsMap, $verbAnyPrepMap): ?array {
        $lemmaParts = [];
        $surfaceParts = [];
        $coreLen = 0;
        $adpCount = 0;
        $hasVerb = false;
        $hasAdjNoun = false;
        $corePosSeq = [];
        $verbIndices = [];
        $adpTokens = [];
        $lastCorePos = '';
        $lastCoreIsReflexive = false;

        for ($j = $start; $j <= $end; $j++) {
            $posSet = $posSets[$j] ?? [];
            $lemmaTok = $lemmaForExpr[$j] ?? $surfaceLower[$j] ?? '';
            $surfaceTok = $surfaceLower[$j] ?? '';
            if ($lemmaTok === '' || $surfaceTok === '') {
                continue;
            }
            $isReflexive = in_array($surfaceTok, $reflexives, true);
            if ($isReflexive) {
                $lemmaTok = 'seg';
            }
            $primary = $posMap[$j] ?? '';
            if (!$isReflexive && in_array($primary, ['PRON', 'DET'], true)) {
                continue;
            }
            $isParticle = in_array('PART', $posSet, true) ||
                (in_array('ADV', $posSet, true) && mod_flashcards_is_particle_like($lemmaTok));
            $isCore = $isReflexive || $isParticle || !empty(array_intersect($posSet, $corePos));
            if (!$isCore) {
                continue;
            }
            $coreLen++;
            $lemmaParts[] = $lemmaTok;
            $surfaceParts[] = $surfaceTok;

            $corePosVal = '';
            if ($primary !== '' && in_array($primary, $corePos, true)) {
                $corePosVal = $primary;
            } elseif ($isParticle) {
                $corePosVal = 'PART';
            } else {
                foreach ($corePos as $pos) {
                    if (in_array($pos, $posSet, true)) {
                        $corePosVal = $pos;
                        break;
                    }
                }
            }
            if ($corePosVal === '') {
                $corePosVal = $isReflexive ? 'PRON' : 'X';
            }
            $corePosSeq[] = $corePosVal;
            if ($corePosVal === 'ADP' || $corePosVal === 'PART') {
                $adpCount++;
                $adpTokens[] = $lemmaTok;
            }
            if ($corePosVal === 'VERB') {
                $hasVerb = true;
                $verbIndices[] = $j;
            }
            if ($corePosVal === 'NOUN' || $corePosVal === 'ADJ') {
                $hasAdjNoun = true;
            }
            $lastCorePos = $corePosVal;
            $lastCoreIsReflexive = $isReflexive;
        }

        if ($coreLen < 2) {
            return null;
        }
        if ($adpCount < 1) {
            return null;
        }
        if (!$hasVerb && !$hasAdjNoun) {
            return null;
        }
        if (count($verbIndices) > 1) {
            return null;
        }
        if ($hasVerb) {
            $firstPos = $corePosSeq[0] ?? '';
            if ($firstPos !== 'VERB') {
                return null;
            }
            if ($adpCount >= 1 && !empty($verbIndices)) {
                $verbIdx = $verbIndices[0];
                $preps = $verbPrepsMap[$verbIdx] ?? [];
                $allowAny = !empty($verbAnyPrepMap[$verbIdx]);
                $prepSet = !empty($preps) ? array_flip($preps) : [];
                if (!$allowAny) {
                    $matched = false;
                    foreach ($adpTokens as $prep) {
                        if ($prep !== '' && isset($prepSet[$prep])) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return null;
                    }
                }
            }
        } else {
            if ($coreLen > 4) {
                return null;
            }
            $posSeq = implode(' ', $corePosSeq);
            $allowed = [
                'NOUN ADP',
                'ADJ ADP',
                'ADP NOUN',
                'ADP ADJ',
                'ADP NOUN ADP',
                'ADP ADJ ADP',
                'NOUN ADP NOUN',
            ];
            if (!in_array($posSeq, $allowed, true)) {
                return null;
            }
        }
        $expr = trim(implode(' ', array_filter($lemmaParts)));
        $surface = trim(implode(' ', array_filter($surfaceParts)));
        if ($expr === '' || $surface === '') {
            return null;
        }
        $score = $scoreBase;
        if ($coreLen >= 3) {
            $score += 2;
        }
        if ($coreLen >= 4) {
            $score += 1;
        }
        if ($hasVerb) {
            $score += 2;
        }
        if ($adpCount >= 2) {
            $score += 2;
        } else if ($adpCount >= 1) {
            $score += 1;
        }
        if ($adpCount >= 1 && in_array($lastCorePos, ['NOUN','PRON'], true) && !$lastCoreIsReflexive) {
            $score -= 3;
        }
        return [
            'lemma' => $expr,
            'surface' => $surface,
            'len' => $coreLen,
            'score' => $score,
            'source' => $source,
            'start' => $start,
            'end' => $end,
        ];
    };

    // General pattern builder removed: rely on explicit MWE rules + argstr expansion.

    $protectedSpans = [];
    foreach ($out as $cand) {
        if (!is_array($cand)) {
            continue;
        }
        if (($cand['source'] ?? '') === 'argstr') {
            continue;
        }
        if (!isset($cand['start'], $cand['end'])) {
            continue;
        }
        $start = (int)$cand['start'];
        $end = (int)$cand['end'];
        if ($start < 0 || $end < $start) {
            continue;
        }
        $verbLemma = '';
        for ($k = $start; $k <= $end; $k++) {
            if (($posMap[$k] ?? '') === 'VERB') {
                $verbLemma = (string)($lemmaForExpr[$k] ?? '');
                break;
            }
        }
        $protectedSpans[] = ['start' => $start, 'end' => $end, 'verb' => $verbLemma];
    }
    $verbIndices = array_unique(array_merge(array_keys($verbPrepsMap), array_keys($verbAnyPrepMap)));
    foreach ($verbIndices as $i) {
        $preps = $verbPrepsMap[$i] ?? [];
        $allowAny = !empty($verbAnyPrepMap[$i]);
        if (empty($preps) && !$allowAny) {
            continue;
        }
        $prepset = !empty($preps) ? array_flip($preps) : [];
        for ($j = $i + 1; $j < $count && $j <= $i + 4; $j++) {
            $tok = $surfaceLower[$j] ?? '';
            if ($tok === '') {
                continue;
            }
            if (!$allowAny && !isset($prepset[$tok])) {
                continue;
            }
            $end = $j;
            $nextIdx = $j + 1;
            if (isset($posSets[$nextIdx])) {
                $nextSet = $posSets[$nextIdx];
                if (array_intersect($nextSet, ['NOUN','PRON','ADJ'])) {
                    $end = $nextIdx;
                }
            }
            $overlapsForeign = false;
            if (!empty($protectedSpans)) {
                foreach ($protectedSpans as $span) {
                    $s = $span['start'];
                    $e = $span['end'];
                    if ($end < $s || $i > $e) {
                        continue;
                    }
                    $otherVerb = (string)($span['verb'] ?? '');
                    if ($otherVerb === '' || $otherVerb !== ($lemmaForExpr[$i] ?? '')) {
                        $overlapsForeign = true;
                        break;
                    }
                }
            }
            if ($overlapsForeign) {
                continue;
            }
            $candidate = $buildCandidate($i, $end, 'argstr', 2);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }
    }

    return $out;
}

/**
 * Resolve lexical multiword expressions directly from token spans via Ordbokene cache/lookup.
 *
 * @param array<int,string> $surfaceTokens
 * @param array<int,string> $lemmaTokens
 * @param array<int,string> $posMap
 * @param string $lang
 * @param int $maxLookups
 * @return array<int,array<string,mixed>>
 */
function mod_flashcards_resolve_lexical_expressions(array $surfaceTokens, array $lemmaTokens, array $posMap, array $words = [], string $sentenceText = '', string $lang = 'begge', int $maxLookups = 10): array {
    $out = [];
    $count = count($surfaceTokens);
    if ($count < 2) {
        return $out;
    }
    $minLen = 2;
    $maxLen = 5;
    $reflexives = ['seg','meg','deg','oss','dere','dem'];
    $contentPos = ['VERB','NOUN','ADJ','ADV'];
    $allowedPos = ['VERB','NOUN','ADJ','ADV','ADP','CONJ','PART','PRON','DET'];
    $seenCand = [];
    $seenExpr = [];
    $lookups = 0;

    for ($start = 0; $start < $count; $start++) {
        for ($end = $start + $minLen - 1; $end < $count && $end < $start + $maxLen; $end++) {
            $len = $end - $start + 1;
            $posSeq = [];
            $hasContent = false;
            $invalid = false;
            $lemmaParts = [];
            $surfaceParts = [];
            for ($i = $start; $i <= $end; $i++) {
                $lemmaTok = $lemmaTokens[$i] ?? '';
                $surfaceTok = $surfaceTokens[$i] ?? '';
                if ($lemmaTok === '' || $surfaceTok === '') {
                    $invalid = true;
                    break;
                }
                $pos = $posMap[$i] ?? '';
                if ($pos === '' || !in_array($pos, $allowedPos, true)) {
                    $invalid = true;
                    break;
                }
                if (in_array($pos, ['PRON','DET'], true) && !in_array($lemmaTok, $reflexives, true)) {
                    $invalid = true;
                    break;
                }
                if (in_array($pos, $contentPos, true)) {
                    $hasContent = true;
                }
                $posSeq[] = $pos;
                $lemmaParts[] = $lemmaTok;
                $surfaceParts[] = $surfaceTok;
            }
            if ($invalid) {
                continue;
            }
            if ($posSeq[0] === 'CONJ' || $posSeq[$len - 1] === 'CONJ') {
                continue;
            }
            $isAdpConjAdp = $len === 3 && $posSeq[0] === 'ADP' && $posSeq[1] === 'CONJ' && $posSeq[2] === 'ADP';
            if (!$hasContent && !$isAdpConjAdp) {
                continue;
            }
            $lemmaPhrase = trim(implode(' ', $lemmaParts));
            $surfacePhrase = trim(implode(' ', $surfaceParts));
            // Для поиска по орфословарю используем только лемматизированную форму.
            // Если леммы нет (редкие случаи), кандидат пропускаем.
            if ($lemmaPhrase === '') {
                continue;
            }
            $candidates = [$lemmaPhrase];
            foreach ($candidates as $cand) {
                $key = core_text::strtolower($cand);
                if (isset($seenCand[$key])) {
                    continue;
                }
                $seenCand[$key] = true;
                $match = null;
                if (\mod_flashcards\local\orbokene_repository::is_enabled()) {
                    $cached = \mod_flashcards\local\orbokene_repository::find($cand);
                    if (!empty($cached)) {
                        $cachedmeta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
                        $cacheddictmeta = $cachedmeta['dictmeta'] ?? null;
                        if (!is_array($cacheddictmeta)) {
                            $cacheddictmeta = ['source' => 'cache'];
                        } else if (empty($cacheddictmeta['source'])) {
                            $cacheddictmeta['source'] = 'cache';
                        }
                        $cachedsource = (string)($cachedmeta['source'] ?? 'cache');
                        // If this cache entry came from Ordbøkene freetext but lacks dict meta (older cached row),
                        // refresh it once and treat as Ordbøkene-confirmed.
                        if ($cachedsource === 'ordbokene_freetext' && (empty($cacheddictmeta['url']) || empty($cacheddictmeta['lang']))) {
                            try {
                                $refresh = mod_flashcards_ordbokene_freetext_confirm_map([$cand], $lang, $words);
                                $rk = \mod_flashcards\local\orbokene_repository::normalize_phrase($cand);
                                if ($rk !== '' && !empty($refresh[$rk]) && is_array($refresh[$rk])) {
                                    $match = $refresh[$rk];
                                }
                            } catch (\Throwable $e) {
                                // ignore refresh errors, fallback to cached payload
                            }
                        }
                        if (!empty($match)) {
                            // refreshed already
                        } else {
                        $expression = $cached['baseform'] ?? $cached['entry'] ?? $cand;
                        $meaning = $cached['definition'] ?? $cached['translation'] ?? '';
                        $matchsource = ($cachedsource === 'ordbokene_freetext') ? 'ordbokene' : 'cache';
                        if ($matchsource === 'ordbokene') {
                            $cacheddictmeta['source'] = 'ordbokene';
                        }
                        $match = [
                            'expression' => $expression,
                            'meanings' => $meaning ? [$meaning] : [],
                            'examples' => $cached['examples'] ?? [],
                            'dictmeta' => $cacheddictmeta,
                            'source' => $matchsource,
                            'variants' => $cachedmeta['variants'] ?? [],
                            'chosenMeaning' => $cachedmeta['chosenMeaning'] ?? null,
                            'meanings_all' => $cachedmeta['meanings_all'] ?? null,
                        ];
                        }
                    }
                }
                if (empty($match) && $lookups < $maxLookups) {
                    $lookups++;
                    $match = mod_flashcards_lookup_or_search_expression($cand, $lang);
                }
                if (empty($match)) {
                    continue;
                }
                $expression = (string)($match['expression'] ?? $cand);
                $exprTokens = mod_flashcards_word_tokens($expression);
                if (count($exprTokens) < 2) {
                    continue;
                }
                $exprMatch = mod_flashcards_find_phrase_match($lemmaTokens, $exprTokens, 1);
                if ($exprMatch === null) {
                    $exprMatch = mod_flashcards_find_phrase_match($surfaceTokens, $exprTokens, 1);
                }
                if ($exprMatch === null) {
                    continue;
                }
                $mkey = core_text::strtolower($expression);
                if (isset($seenExpr[$mkey])) {
                    continue;
                }
                $seenExpr[$mkey] = true;
                $meaning = '';
                if (!empty($match['meanings']) && is_array($match['meanings'])) {
                    $meaning = trim((string)($match['meanings'][0] ?? ''));
                }
                $source = $match['source'] ?? 'ordbokene';
                if ($source === 'ordbokene' && !empty($words) && is_array($words)) {
                    $match = mod_flashcards_pick_ordbokene_sense_for_sentence($match, $words, $exprMatch['indices'] ?? []);
                    if (!empty($match['meanings']) && is_array($match['meanings'])) {
                        $meaning = trim((string)($match['meanings'][0] ?? ''));
                    }
                }
                $confidence = ($source === 'cache' || $source === 'ordbokene') ? 'high' : 'medium';
                // Variants are only orthographic variants provided by Ordbøkene itself (e.g. "løse, løyse").
                // Do not include inflected surface forms.
                $variants = [];
                if (!empty($match['variants']) && is_array($match['variants'])) {
                    $variants = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $match['variants'])))));
                    if (count($variants) < 2) {
                        $variants = [];
                    } else if (count($variants) > 4) {
                        $variants = array_slice($variants, 0, 4);
                    }
                }
                $out[] = [
                    'expression' => $expression,
                    'translation' => '',
                    'explanation' => $meaning,
                    'examples' => $match['examples'] ?? [],
                    'examples_sentence' => ($source === 'ordbokene' && empty($match['examples'])) ? [($sentenceText !== '' ? $sentenceText : trim(implode(' ', $surfaceTokens)))] : [],
                    'dictmeta' => $match['dictmeta'] ?? [],
                    'source' => $source,
                    'confidence' => $confidence,
                    'variants' => $variants,
                    'chosenMeaning' => $match['chosenMeaning'] ?? null,
                    'meanings_all' => $match['meanings_all'] ?? null,
                    'rule' => 'lexical_orbokene',
                    'start' => $exprMatch['start'],
                    'end' => $exprMatch['end'],
                    'indices' => $exprMatch['indices'],
                    'len' => count($exprTokens),
                ];
            }
        }
    }
    return $out;
}

/**
 * True if $needle tokens appear contiguously inside $hay.
 *
 * @param array<int,string> $hay
 * @param array<int,string> $needle
 * @return bool
 */
function mod_flashcards_tokens_contain_phrase(array $hay, array $needle): bool {
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $h === 0 || $n > $h) {
        return false;
    }
    for ($i = 0; $i <= $h - $n; $i++) {
        $slice = array_slice($hay, $i, $n);
        if ($slice === $needle) {
            return true;
        }
    }
    return false;
}

/**
 * Find a phrase span in $hay using in-order matching with small gaps.
 *
 * @param array<int,string> $hay
 * @param array<int,string> $needle
 * @param int $maxGap
 * @return array{start:int,end:int,indices:array<int,int>}|null
 */
function mod_flashcards_find_phrase_match(array $hay, array $needle, int $maxGap = 2): ?array {
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $h === 0 || $n > $h) {
        return null;
    }
    $pos = -1;
    $prevIdx = null;
    $indices = [];
    foreach ($needle as $tok) {
        $found = false;
        for ($i = $pos + 1; $i < $h; $i++) {
            if ($hay[$i] === $tok) {
                if ($prevIdx !== null && ($i - $prevIdx - 1) > $maxGap) {
                    return null;
                }
                $pos = $i;
                $prevIdx = $i;
                $indices[] = $i;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return null;
        }
    }
    $start = $indices[0] ?? null;
    $end = $indices[count($indices) - 1] ?? null;
    if ($start === null || $end === null) {
        return null;
    }
    return ['start' => $start, 'end' => $end, 'indices' => $indices];
}

/**
 * True if $needle tokens appear in order inside $hay with small gaps.
 *
 * @param array<int,string> $hay
 * @param array<int,string> $needle
 * @param int $maxGap
 * @return bool
 */
function mod_flashcards_tokens_contain_phrase_fuzzy(array $hay, array $needle, int $maxGap = 2): bool {
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $h === 0 || $n > $h) {
        return false;
    }
    $pos = -1;
    $prevIdx = null;
    foreach ($needle as $tok) {
        $found = false;
        for ($i = $pos + 1; $i < $h; $i++) {
            if ($hay[$i] === $tok) {
                if ($prevIdx !== null && ($i - $prevIdx - 1) > $maxGap) {
                    return false;
                }
                $pos = $i;
                $prevIdx = $i;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }
    return true;
}

/**
 * Filter overlapping expressions to keep context-appropriate phrases.
 *
 * @param array<int,array<string,mixed>> $expressions
 * @param array<int,string> $posMap
 * @return array<int,array<string,mixed>>
 */
function mod_flashcards_filter_expression_overlaps(array $expressions, array $posMap): array {
    $keep = [];
    $suppress = [];
    $count = count($expressions);
    if ($count < 2) {
        return $expressions;
    }
    $overlaps = function(array $a, array $b): bool {
        $aStart = $a['start'] ?? null;
        $aEnd = $a['end'] ?? null;
        $bStart = $b['start'] ?? null;
        $bEnd = $b['end'] ?? null;
        if (!is_int($aStart) || !is_int($aEnd) || !is_int($bStart) || !is_int($bEnd)) {
            return false;
        }
        return $aStart <= $bEnd && $bStart <= $aEnd;
    };
    $contains = function(array $a, array $b): bool {
        $aStart = $a['start'] ?? null;
        $aEnd = $a['end'] ?? null;
        $bStart = $b['start'] ?? null;
        $bEnd = $b['end'] ?? null;
        if (!is_int($aStart) || !is_int($aEnd) || !is_int($bStart) || !is_int($bEnd)) {
            return false;
        }
        return $aStart <= $bStart && $aEnd >= $bEnd;
    };
    $isConfirmed = function(array $item): bool {
        $confidence = $item['confidence'] ?? '';
        return in_array($confidence, ['high','medium'], true);
    };
    $isShortVerbPrep = function(array $item) use ($posMap): bool {
        $indices = $item['indices'] ?? [];
        if (($item['len'] ?? 0) !== 2 || count($indices) !== 2) {
            return false;
        }
        $first = $indices[0] ?? null;
        $second = $indices[1] ?? null;
        if (!is_int($first) || !is_int($second)) {
            return false;
        }
        $pos1 = $posMap[$first] ?? '';
        $pos2 = $posMap[$second] ?? '';
        return in_array($pos1, ['VERB','AUX'], true) && $pos2 === 'ADP';
    };
    for ($i = 0; $i < $count; $i++) {
        $cur = $expressions[$i];
        if ($isShortVerbPrep($cur)) {
            $adpIndex = $cur['indices'][1] ?? null;
            if (!is_int($adpIndex)) {
                continue;
            }
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $other = $expressions[$j];
                if (($other['len'] ?? 0) < 3) {
                    continue;
                }
                if (!$overlaps($cur, $other)) {
                    continue;
                }
                $otherIndices = $other['indices'] ?? [];
                if (!in_array($adpIndex, $otherIndices, true)) {
                    continue;
                }
                $hasNounAfter = false;
                foreach ($otherIndices as $idx) {
                    if ($idx > $adpIndex && in_array(($posMap[$idx] ?? ''), ['NOUN','ADJ'], true)) {
                        $hasNounAfter = true;
                        break;
                    }
                }
                if ($hasNounAfter) {
                    $suppress[$i] = true;
                    break;
                }
            }
        }
    }
    for ($i = 0; $i < $count; $i++) {
        $cur = $expressions[$i];
        if (!$isConfirmed($cur)) {
            continue;
        }
        for ($j = 0; $j < $count; $j++) {
            if ($i === $j) {
                continue;
            }
            $other = $expressions[$j];
            if (!$contains($cur, $other)) {
                continue;
            }
            if (($cur['len'] ?? 0) <= ($other['len'] ?? 0)) {
                continue;
            }
            $suppress[$j] = true;
        }
    }
    foreach ($expressions as $idx => $expr) {
        if (!empty($suppress[$idx])) {
            continue;
        }
        $keep[] = $expr;
    }
    return $keep;
}

/**
 * Lemmatize tokens using ordbank (best-effort).
 *
 * @param array<int,string> $tokens
 * @return array<int,string>
 */
function mod_flashcards_tokens_to_lemma(array $tokens): array {
    static $cache = [];
    $out = [];
    foreach ($tokens as $tok) {
        $tok = core_text::strtolower($tok);
        if ($tok === '') {
            $out[] = $tok;
            continue;
        }
        if (isset($cache[$tok])) {
            $out[] = $cache[$tok];
            continue;
        }
        $lemma = $tok;
        try {
            $cands = \mod_flashcards\local\ordbank_helper::find_candidates($tok);
            if (!empty($cands)) {
                foreach ($cands as $cand) {
                    if (!empty($cand['baseform'])) {
                        $lemma = core_text::strtolower((string)$cand['baseform']);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $cache[$tok] = $lemma;
        $out[] = $lemma;
    }
    return $out;
}

/**
 * Try to confirm an expression by checking Ordbokene examples for its head lemma.
 *
 * @param string $lemma
 * @param string $surfaceExpr
 * @param string $lemmaExpr
 * @param string $lang
 * @return array|null
 */
function mod_flashcards_orbokene_example_match(string $lemma, string $surfaceExpr, string $lemmaExpr, string $lang): ?array {
    $lemma = trim(core_text::strtolower($lemma));
    if ($lemma === '') {
        return null;
    }
    $examples = [];
    if (\mod_flashcards\local\orbokene_repository::is_enabled()) {
        $cached = \mod_flashcards\local\orbokene_repository::find($lemma);
        if (!empty($cached['examples']) && is_array($cached['examples'])) {
            $examples = $cached['examples'];
        }
    }
    if (empty($examples)) {
        $entry = \mod_flashcards\local\ordbokene_client::lookup($lemma, $lang);
        if (!empty($entry['examples']) && is_array($entry['examples'])) {
            $examples = $entry['examples'];
        }
    }
    if (empty($examples)) {
        return null;
    }
    $surfaceTokens = mod_flashcards_word_tokens($surfaceExpr);
    $lemmaTokens = mod_flashcards_word_tokens($lemmaExpr);
    foreach ($examples as $example) {
        $ex = trim((string)$example);
        if ($ex === '') {
            continue;
        }
        $exTokens = mod_flashcards_word_tokens($ex);
        if (!empty($surfaceTokens) && mod_flashcards_tokens_contain_phrase_fuzzy($exTokens, $surfaceTokens)) {
            return [
                'expression' => $surfaceExpr,
                'meanings' => [],
                'examples' => [$ex],
                'dictmeta' => ['source' => 'examples'],
                'source' => 'examples',
            ];
        }
        if (!empty($lemmaTokens)) {
            $exLemmaTokens = mod_flashcards_tokens_to_lemma($exTokens);
            if (mod_flashcards_tokens_contain_phrase_fuzzy($exLemmaTokens, $lemmaTokens)) {
                return [
                    'expression' => $surfaceExpr,
                    'meanings' => [],
                    'examples' => [$ex],
                    'dictmeta' => ['source' => 'examples'],
                    'source' => 'examples',
                ];
            }
        }
    }
    return null;
}

/**
 * Pick the best Ordbøkene meaning/examples for a matched expression using sentence context.
 *
 * Ordbøkene entries may contain multiple meanings; `ordbokene_client` exposes them as `senses` with examples per sense.
 * This function selects the sense whose examples best match the sentence content words outside the expression span.
 *
 * @param array<string,mixed> $match Ordbøkene match payload
 * @param array<int,array<string,mixed>> $words Sentence tokens (surface/lemma/pos)
 * @param array<int,int> $excludeIndices Token indices that belong to the expression span
 * @return array<string,mixed> Updated match with filtered meanings/examples and chosenMeaning metadata
 */
function mod_flashcards_pick_ordbokene_sense_for_sentence(array $match, array $words, array $excludeIndices = []): array {
    $senses = $match['senses'] ?? null;
    if (!is_array($senses) || empty($senses)) {
        return $match;
    }

    $exclude = [];
    foreach ($excludeIndices as $idx) {
        $exclude[(int)$idx] = true;
    }

    $keywords = [];
    foreach ($words as $i => $w) {
        if (!empty($exclude[$i]) || !is_array($w)) {
            continue;
        }
        $pos = core_text::strtoupper((string)($w['pos'] ?? ''));
        if (!in_array($pos, ['NOUN', 'PROPN', 'ADJ'], true)) {
            continue;
        }
        $surface = core_text::strtolower(trim((string)($w['text'] ?? '')));
        $lemma = core_text::strtolower(trim((string)($w['lemma'] ?? $surface)));
        foreach ([$surface, $lemma] as $kw) {
            $kw = trim($kw);
            if ($kw === '' || mb_strlen($kw) < 3) {
                continue;
            }
            // Skip very common noise tokens.
            if (in_array($kw, ['den', 'det', 'dei', 'de', 'en', 'ei', 'et'], true)) {
                continue;
            }
            $keywords[$kw] = true;
        }
    }
    $keywords = array_keys($keywords);
    if (empty($keywords)) {
        return $match;
    }

    $allMeanings = [];
    $bestIdx = 0;
    $bestHits = -1;
    $bestScore = -INF;
    foreach ($senses as $idx => $sense) {
        if (!is_array($sense)) {
            continue;
        }
        $meaning = trim((string)($sense['meaning'] ?? ''));
        $allMeanings[] = $meaning;
        $examples = $sense['examples'] ?? [];
        if (!is_array($examples)) {
            $examples = [];
        }

        $exampleTokens = [];
        foreach ($examples as $ex) {
            $ex = trim((string)$ex);
            if ($ex === '') {
                continue;
            }
            foreach (mod_flashcards_word_tokens($ex) as $tok) {
                $tok = core_text::strtolower((string)$tok);
                if ($tok !== '') {
                    $exampleTokens[$tok] = true;
                }
            }
        }

        $hits = 0;
        $score = 0;
        // De-prioritize placeholder meanings like "Se:" if there are alternatives.
        if ($meaning !== '' && preg_match('~^Se:?\s*$~iu', $meaning)) {
            $score -= 1;
        }
        foreach ($keywords as $kw) {
            if (isset($exampleTokens[$kw])) {
                $hits++;
                $score += 3;
            }
        }
        // If nothing in the sentence matches any sense examples, default to the first meaning
        // (this prevents picking a wrong sense just because it has examples).
        if ($hits > $bestHits || ($hits === $bestHits && $score > $bestScore)) {
            $bestHits = $hits;
            $bestScore = $score;
            $bestIdx = (int)$idx;
        }
    }
    if ($bestHits <= 0) {
        // Heuristic fallback for ambiguous particle verbs where example-keyword overlap is often zero.
        // Example: "de går ut" should prefer the "dra til utested" sense over "ikke være gyldig lenger".
        $bestIdx = 0;
        $expr = trim(core_text::strtolower((string)($match['expression'] ?? '')));
        $exprTokens = $expr !== '' ? preg_split('/\\s+/u', $expr) : [];
        $exprTokens = is_array($exprTokens) ? array_values(array_filter(array_map('trim', $exprTokens))) : [];
        $looksLikeParticleVerb = (count($exprTokens) === 2 && in_array($exprTokens[1], [
            'ut','inn','opp','ned','av','på','til','over','fram','frem','tilbake','igjen',
        ], true));

        if ($looksLikeParticleVerb && !empty($exclude)) {
            $startIdx = min(array_keys($exclude));
            $prev = $words[$startIdx - 1] ?? null;
            $prevPos = is_array($prev) ? core_text::strtoupper((string)($prev['pos'] ?? '')) : '';
            $prevLemma = is_array($prev) ? core_text::strtolower(trim((string)($prev['lemma'] ?? ''))) : '';
            $prevSurface = is_array($prev) ? core_text::strtolower(trim((string)($prev['text'] ?? ''))) : '';

            $contextTokens = [];
            foreach ($words as $i => $w) {
                if (!empty($exclude[$i]) || !is_array($w)) {
                    continue;
                }
                $surface = core_text::strtolower(trim((string)($w['text'] ?? '')));
                $lemma = core_text::strtolower(trim((string)($w['lemma'] ?? $surface)));
                foreach ([$surface, $lemma] as $t) {
                    $t = trim($t);
                    if ($t !== '' && mb_strlen($t) >= 2) {
                        $contextTokens[$t] = true;
                    }
                }
            }
            $contextTokens = array_keys($contextTokens);

            $expiryNeedles = ['gyldig', 'frist', 'avtale', 'kontrakt', 'tillatelse', 'lisens', 'garanti', 'dato', 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
            $outingNeedles = ['utested', 'byen', 'på byen', 'fest', 'kino', 'restaurant', 'bar', 'pub', 'klubb'];

            $isAgentive = in_array($prevPos, ['PRON', 'PROPN'], true);
            $isExpiryContext = false;
            if (in_array($prevLemma, ['frist', 'avtale', 'kontrakt', 'tillatelse', 'lisens', 'garanti'], true)) {
                $isExpiryContext = true;
            }
            foreach ($contextTokens as $t) {
                if (preg_match('/\\d/u', $t)) {
                    $isExpiryContext = true;
                    break;
                }
                if (in_array($t, $expiryNeedles, true)) {
                    $isExpiryContext = true;
                    break;
                }
            }

            $bestExpiryIdx = 0;
            $bestExpiryScore = -1;
            $bestOutIdx = 0;
            $bestOutScore = -1;
            foreach ($senses as $idx => $sense) {
                if (!is_array($sense)) {
                    continue;
                }
                $meaning = core_text::strtolower(trim((string)($sense['meaning'] ?? '')));
                $examples = $sense['examples'] ?? [];
                if (!is_array($examples)) {
                    $examples = [];
                }
                $blob = $meaning . ' ' . core_text::strtolower(implode(' ', array_map('strval', $examples)));

                $exp = 0;
                $out = 0;
                foreach ($expiryNeedles as $n) {
                    if ($n !== '' && str_contains($blob, $n)) {
                        $exp++;
                    }
                }
                foreach ($outingNeedles as $n) {
                    if ($n !== '' && str_contains($blob, $n)) {
                        $out++;
                    }
                }
                // Extra bias: if the sentence subject right before the verb is a pronoun ("de", "han", "hun"),
                // prefer "outing" when available (even if no markers match).
                if ($isAgentive && in_array($prevSurface, ['de','han','hun','jeg','du','vi','dere'], true)) {
                    $out += 1;
                }
                if ($exp > $bestExpiryScore) {
                    $bestExpiryScore = $exp;
                    $bestExpiryIdx = (int)$idx;
                }
                if ($out > $bestOutScore) {
                    $bestOutScore = $out;
                    $bestOutIdx = (int)$idx;
                }
            }

            if ($isExpiryContext && $bestExpiryScore > 0) {
                $bestIdx = $bestExpiryIdx;
            } else if ($isAgentive && $bestOutScore >= 0 && ($bestOutScore > $bestExpiryScore || !$isExpiryContext)) {
                $bestIdx = $bestOutIdx;
            }
        }
    }

    $bestSense = $senses[$bestIdx] ?? null;
    if (!is_array($bestSense)) {
        return $match;
    }

    $bestMeaning = trim((string)($bestSense['meaning'] ?? ''));
    $bestExamples = $bestSense['examples'] ?? [];
    if (!is_array($bestExamples)) {
        $bestExamples = [];
    }
    $bestExamples = array_values(array_filter(array_map('trim', array_map('strval', $bestExamples))));
    if (count($bestExamples) > 6) {
        $bestExamples = array_slice($bestExamples, 0, 6);
    }

    $match['chosenMeaning'] = $bestIdx;
    $match['meanings_all'] = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $allMeanings)))));
    if ($bestMeaning !== '') {
        $match['meanings'] = [$bestMeaning];
    }
    $match['examples'] = $bestExamples;
    return $match;
}

/**
 * Confirm expressions via Ordbøkene freetext scope (lookup by meanings/inline phrases), then cache as aliases.
 *
 * Uses /api/articles?scope=f to find articles where the phrase appears somewhere in the article text.
 * To avoid false positives, we only accept a match when the phrase equals:
 * - an Ordbøkene expression lemma (sub-article), OR
 * - a sense meaning (explanation/definition) in the normalized article payload.
 *
 * @param array<int,string> $phrases
 * @param string $lang bm|nn|begge
 * @param array<int,array<string,mixed>> $words
 * @return array<string,array<string,mixed>> map normalized_phrase => match payload
 */
function mod_flashcards_ordbokene_freetext_confirm_map(array $phrases, string $lang, array $words): array {
    if (empty($phrases)) {
        return [];
    }
    $normalizedList = [];
    foreach ($phrases as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }
        $key = \mod_flashcards\local\orbokene_repository::normalize_phrase($p);
        if ($key !== '') {
            $normalizedList[$key] = $p;
        }
    }
    if (empty($normalizedList)) {
        return [];
    }

    // One freetext search for all phrases (| concatenation), then verify inside fetched articles.
    $query = implode('|', array_keys($normalizedList));
    $ids = \mod_flashcards\local\ordbokene_client::list_article_ids($query, $lang, 'f');

    $articleQueue = [];
    foreach (['bm', 'nn'] as $dict) {
        foreach (array_slice(($ids[$dict] ?? []), 0, 8) as $id) {
            $articleQueue[] = ['dict' => $dict, 'id' => (int)$id];
        }
    }
    if (empty($articleQueue)) {
        return [];
    }

    $articles = [];
    foreach ($articleQueue as $item) {
        $dict = (string)($item['dict'] ?? 'bm');
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $norm = \mod_flashcards\local\ordbokene_client::fetch_article($id, $dict);
        if (!empty($norm)) {
            $articles[] = $norm;
        }
    }
    if (empty($articles)) {
        return [];
    }

    $out = [];
    foreach ($normalizedList as $needleNorm => $needleRaw) {
        $best = null;
        $bestScore = -INF;
        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }
            $exprs = [];
            if (!empty($article['expressions']) && is_array($article['expressions'])) {
                foreach ($article['expressions'] as $e) {
                    $k = \mod_flashcards\local\orbokene_repository::normalize_phrase((string)$e);
                    if ($k !== '') {
                        $exprs[$k] = true;
                    }
                }
            }
            $meanings = [];
            if (!empty($article['meanings']) && is_array($article['meanings'])) {
                foreach ($article['meanings'] as $m) {
                    $k = \mod_flashcards\local\orbokene_repository::normalize_phrase((string)$m);
                    if ($k !== '') {
                        $meanings[$k] = true;
                    }
                }
            }

            $matchType = null;
            if (isset($exprs[$needleNorm])) {
                $matchType = 'expression';
            } else if (isset($meanings[$needleNorm])) {
                $matchType = 'meaning';
            } else {
                continue;
            }

            $score = 0;
            if ($matchType === 'expression') {
                $score += 10;
            } else {
                $score += 3;
            }
            $needleTokens = mod_flashcards_word_tokens($needleRaw);
            $firstTok = $needleTokens[0] ?? '';
            $baseform = core_text::strtolower(trim((string)($article['baseform'] ?? '')));
            if ($firstTok !== '' && $baseform !== '' && $firstTok === \mod_flashcards\local\orbokene_repository::normalize_phrase($baseform)) {
                $score += 5;
            }
            $pos = core_text::strtolower(trim((string)($article['pos'] ?? '')));
            if ($pos !== '' && str_contains($pos, 'verb') && !empty($needleTokens)) {
                $score += 1;
            }

            // Build a "virtual match" for the needle itself, using the matched article's sense data.
            // For meaning-only matches, restrict senses/meanings to the matching meaning and do not inherit lemma variants.
            $senses = $article['senses'] ?? [];
            if (!is_array($senses)) {
                $senses = [];
            }
            $virtualMeanings = $article['meanings'] ?? [];
            $virtualExamples = $article['examples'] ?? [];
            $virtualVariants = $article['variants'] ?? [];
            if ($matchType === 'meaning') {
                $virtualMeanings = [$needleRaw];
                $virtualExamples = [];
                $virtualVariants = [];
                if (!empty($senses)) {
                    $filtered = [];
                    foreach ($senses as $sense) {
                        if (!is_array($sense)) {
                            continue;
                        }
                        $m = \mod_flashcards\local\orbokene_repository::normalize_phrase((string)($sense['meaning'] ?? ''));
                        if ($m !== '' && $m === $needleNorm) {
                            $filtered[] = $sense;
                        }
                    }
                    if (!empty($filtered)) {
                        $senses = $filtered;
                    }
                }
            }
            $dictmeta = $article['dictmeta'] ?? [];
            if (!is_array($dictmeta)) {
                $dictmeta = [];
            }
            $dictmeta['source'] = 'ordbokene';
            $match = [
                'expression' => $needleRaw,
                'meanings' => $virtualMeanings,
                'examples' => $virtualExamples,
                'senses' => $senses,
                'variants' => $virtualVariants,
                'dictmeta' => $dictmeta,
                'source' => 'ordbokene',
                'meta' => [
                    'match_type' => $matchType,
                    'via_article' => $article['baseform'] ?? '',
                ],
            ];

            // Pick best sense for the sentence context (also filters examples by sense).
            $match = mod_flashcards_pick_ordbokene_sense_for_sentence($match, $words, []);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $match;
            }
        }

        if (empty($best) || !is_array($best)) {
            continue;
        }

        $out[$needleNorm] = $best;

        // Cache as alias so future calls are fast and don't hit the network.
        try {
            \mod_flashcards\local\orbokene_repository::upsert($needleRaw, [
                'entry' => $needleRaw,
                'baseform' => (string)($best['expression'] ?? $needleRaw),
                'definition' => !empty($best['meanings'][0]) ? (string)$best['meanings'][0] : '',
                'examples' => $best['examples'] ?? [],
                'meta' => [
                    'source' => 'ordbokene_freetext',
                    'dictmeta' => $best['dictmeta'] ?? [],
                    'chosenMeaning' => $best['chosenMeaning'] ?? null,
                    'meanings_all' => $best['meanings_all'] ?? null,
                    'variants' => $best['variants'] ?? [],
                    'via' => $best['meta']['via_article'] ?? '',
                    'match_type' => $best['meta']['match_type'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }

    return $out;
}

/**
 * Find token index matching word + context.
 *
 * @param array<int,string> $tokens
 * @return int|null
 */
function mod_flashcards_find_token_index(array $tokens, string $word, string $prev, string $next, string $prev2, string $next2): ?int {
    $word = mod_flashcards_normalize_token($word);
    if ($word === '') {
        return null;
    }
    $candidates = [];
    foreach ($tokens as $i => $tok) {
        if (mod_flashcards_normalize_token($tok) === $word) {
            $candidates[] = $i;
        }
    }
    if (empty($candidates)) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    $prev = mod_flashcards_normalize_token($prev);
    $next = mod_flashcards_normalize_token($next);
    $prev2 = mod_flashcards_normalize_token($prev2);
    $next2 = mod_flashcards_normalize_token($next2);
    $best = $candidates[0];
    $bestScore = -INF;
    foreach ($candidates as $i) {
        $score = 0;
        $tPrev = $tokens[$i - 1] ?? '';
        $tNext = $tokens[$i + 1] ?? '';
        $tPrev2 = $tokens[$i - 2] ?? '';
        $tNext2 = $tokens[$i + 2] ?? '';
        if ($prev !== '' && mod_flashcards_normalize_token($tPrev) === $prev) { $score += 2; }
        if ($next !== '' && mod_flashcards_normalize_token($tNext) === $next) { $score += 2; }
        if ($prev2 !== '' && mod_flashcards_normalize_token($tPrev2) === $prev2) { $score += 1; }
        if ($next2 !== '' && mod_flashcards_normalize_token($tNext2) === $next2) { $score += 1; }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $i;
        }
    }
    return $best;
}

/**
 * Map an ordbank tag to coarse POS.
 */
function mod_flashcards_pos_from_tag(string $tag, string $ordklasse = ''): string {
    $ok = core_text::strtolower(trim($ordklasse));
    if ($ok !== '') {
        if (str_contains($ok, 'adv')) { return 'ADV'; }
        if (str_contains($ok, 'verb')) { return 'VERB'; }
        if (str_contains($ok, 'subst')) { return 'NOUN'; }
        if (str_contains($ok, 'adj')) { return 'ADJ'; }
        if (str_contains($ok, 'prep')) { return 'ADP'; }
        if (str_contains($ok, 'pron')) { return 'PRON'; }
        if (str_contains($ok, 'det')) { return 'DET'; }
        if (str_contains($ok, 'konj')) { return 'CONJ'; }
    }
    $t = core_text::strtolower($tag);
    if (str_contains($t, 'adv')) { return 'ADV'; }
    if (str_contains($t, 'verb')) { return 'VERB'; }
    if (str_contains($t, 'subst')) { return 'NOUN'; }
    if (str_contains($t, 'adj')) { return 'ADJ'; }
    if (str_contains($t, 'prep')) { return 'ADP'; }
    if (str_contains($t, 'pron')) { return 'PRON'; }
    if (str_contains($t, 'konj')) { return 'CONJ'; }
    return 'X';
}

/**
 * Lightweight POS decoder (Viterbi) over the full sentence to disambiguate homographs.
 * Returns the best candidate for the clicked token index, or null if cannot decode.
 *
 * @param array<int,string> $tokens normalized tokens
 * @param int $clickedIdx index of clicked token in $tokens
 * @return array<string,mixed>|null
 */
function mod_flashcards_decode_pos(array $tokens, int $clickedIdx, array $spacyPosMap = []): ?array {
    global $DB;
    if ($clickedIdx < 0 || $clickedIdx >= count($tokens)) {
        return null;
    }
    // Build candidates per token.
    $candlist = [];
    foreach ($tokens as $tok) {
        $cands = \mod_flashcards\local\ordbank_helper::find_candidates($tok);
        if (empty($cands)) {
            $candlist[] = [];
            continue;
        }
        // Cap to 6 to avoid explosion.
        $candlist[] = array_slice(array_values($cands), 0, 6);
    }
    if (empty($candlist[$clickedIdx])) {
        return null;
    }
    $n = count($tokens);
    $pronouns = ['jeg','du','han','hun','vi','dere','de','eg','ho','me','dei','det','den','dette','disse','hva','hvem','hvor','når'];
    $articles = ['en','ei','et','ein','eitt'];
    $determin = ['den','det','de','denne','dette','disse','min','mitt','mi','mine','din','ditt','di','dine','sin','sitt','si','sine','hans','hennes','vår','vårt','våre','deres'];
    $aux = ['er','var','har','hadde','blir','ble','vil','skal','kan','må','bør','kunne','skulle','ville'];

    $trans = function(string $prev, string $cur): int {
        // transition weights between coarse POS
        $score = 0;
        if ($prev === 'DET' && $cur === 'NOUN') $score += 4;
        if ($prev === 'PRON' && $cur === 'VERB') $score += 3;
        if ($prev === 'ADP' && in_array($cur, ['NOUN','PRON'], true)) $score += 3;
        if ($prev === 'AUX' && $cur === 'VERB') $score += 4;
        if ($prev === 'VERB' && $cur === 'ADP') $score += 1;
        if ($prev === 'ADV' && $cur === 'ADJ') $score += 1;
        if ($cur === 'X') $score -= 2;
        return $score;
    };
    $emission = function(array $cand, ?string $prevTok, ?string $nextTok, ?string $curTok, ?string $prev2Tok, ?string $next2Tok, ?string $spacyPos) use ($pronouns,$articles,$determin,$aux): int {
        $pos = mod_flashcards_pos_from_tag($cand['tag'] ?? '', $cand['ordklasse'] ?? '');
        $tok = core_text::strtolower((string)($cand['wordform'] ?? ''));
        $argcodes = \mod_flashcards\local\ordbank_helper::extract_argcodes_from_tag((string)($cand['tag'] ?? ''));
        $argmeta = \mod_flashcards\local\ordbank_helper::argcode_meta($argcodes);
        $boygroup = core_text::strtolower((string)($cand['boy_group'] ?? ($cand['boy_gruppe'] ?? '')));
        $boynum = (int)($cand['boy_nummer'] ?? 0);
        $taglower = core_text::strtolower((string)($cand['tag'] ?? ''));
        $score = 0;
        if ($curTok) {
            $curLower = core_text::strtolower($curTok);
            if ($tok === $curLower) {
                $score += 12; // exact surface match
            } else {
                $asciiTok = @iconv('UTF-8', 'ASCII//TRANSLIT', $tok);
                $asciiCur = @iconv('UTF-8', 'ASCII//TRANSLIT', $curLower);
                if ($asciiTok && $asciiCur && $asciiTok === $asciiCur) {
                    $score -= 12; // penalize diacritic drift (for vs for)
                }
            }
        }
        if (in_array($pos, ['VERB','NOUN','ADJ','ADV'], true)) $score += 1;
        if ($spacyPos) {
            if ($spacyPos === $pos) {
                $score += 6;
            } else if (in_array($pos, ['VERB','NOUN','ADJ','ADV','ADP'], true)) {
                $score -= 4;
            }
        }
        $articleNear = (in_array($prevTok, $articles, true) || in_array($prevTok, $determin, true) ||
                        in_array($prev2Tok, $articles, true) || in_array($prev2Tok, $determin, true));
        if ($articleNear && $pos === 'NOUN') { $score += 10; }
        if ($articleNear && $pos === 'VERB') { $score -= 8; }
        if ($articleNear && $boygroup !== '' && str_contains($boygroup, 'substantiv')) { $score += 5; }
        if ($articleNear && $boynum === 1) { $score += 4; }
        if ($articleNear && $boynum === 11) { $score -= 4; }

        if (in_array($prevTok, $articles, true) || in_array($prevTok, $determin, true)) {
            if ($pos === 'NOUN') $score += 4;
            if ($pos === 'VERB') $score -= 3;
        }
        if (in_array($prevTok, $pronouns, true) && $pos === 'VERB') {
            $score += 3;
        }
        if ($prevTok === 'a' && $pos === 'VERB') {
            $score += 5;
        }
        if ($pos === 'VERB' && $nextTok === 'seg') {
            $score += 4;
        }
        $prepMatched = false;
        foreach ($argcodes as $ac) {
            $prep = $ac['prep'] ?? null;
            $code = $ac['code'] ?? '';
            if ($prep !== null && ($nextTok === $prep || $prevTok === $prep || $next2Tok === $prep || $prev2Tok === $prep)) {
                $score += 5;
                $prepMatched = true;
            }
            if ($pos === 'VERB' && ($nextTok === 'seg' || $prevTok === 'seg' || $prev2Tok === 'seg')) {
                $score += 2;
            }
            if ($code && str_contains($code, 'refl') && ($nextTok === 'seg' || $next2Tok === 'seg' || $prev2Tok === 'seg')) {
                $score += 6;
            }
        }
        foreach ($argmeta['preps'] as $p) {
            if ($p !== '' && ($nextTok === $p || $prevTok === $p || $next2Tok === $p || $prev2Tok === $p)) {
                $score += 5;
                $prepMatched = true;
            }
        }
        if (($argmeta['requires_pp'] ?? false) && $pos === 'VERB' && !$prepMatched && ($nextTok || $next2Tok)) {
            $score -= 2;
        }
        if (in_array($prevTok, $pronouns, true) && $pos === 'VERB') {
            $score += 3;
        }
        if (in_array($prevTok, $aux, true) && $pos === 'VERB' && (str_contains($taglower, 'perf') || str_contains($taglower, 'part'))) {
            $score += 3;
        }
        if ($tok === 'for' && $pos === 'ADV') {
            $score += 8;
        }
        if ($tok === 'for' && $pos === 'VERB') {
            $score -= 8;
        }
        if ($tok === 'for' && $pos === 'NOUN') {
            $score -= 6;
        }
        return $score;
    };

    $dp = [];
    $back = [];
    for ($i = 0; $i < $n; $i++) {
        $dp[$i] = [];
        $back[$i] = [];
        $prevTok = $tokens[$i-1] ?? null;
        $prev2Tok = $tokens[$i-2] ?? null;
        $nextTok = $tokens[$i+1] ?? null;
        $next2Tok = $tokens[$i+2] ?? null;
        $curTok = $tokens[$i] ?? null;
        foreach ($candlist[$i] as $k => $cand) {
            $spacyPos = $spacyPosMap[$i] ?? null;
            $emit = $emission($cand, $prevTok, $nextTok, $curTok, $prev2Tok, $next2Tok, $spacyPos);
            if ($i === 0) {
                $dp[$i][$k] = $emit;
                $back[$i][$k] = -1;
            } else {
                $best = -INF;
                $bestj = -1;
                foreach ($dp[$i-1] as $j => $prevScore) {
                    $prevPos = mod_flashcards_pos_from_tag($candlist[$i-1][$j]['tag'] ?? '', $candlist[$i-1][$j]['ordklasse'] ?? '');
                    $curPos = mod_flashcards_pos_from_tag($cand['tag'] ?? '', $cand['ordklasse'] ?? '');
                    $score = $prevScore + $emit + $trans($prevPos, $curPos);
                    if ($score > $best) { $best = $score; $bestj = $j; }
                }
                $dp[$i][$k] = $best;
                $back[$i][$k] = $bestj;
            }
        }
    }
    // Backtrack best path.
    $last = $n - 1;
    if (empty($dp[$last])) {
        return null;
    }
    $bestk = array_keys($dp[$last], max($dp[$last]))[0];
    $path = array_fill(0, $n, 0);
    for ($i = $last; $i >= 0; $i--) {
        $path[$i] = $bestk;
        $bestk = $back[$i][$bestk] ?? 0;
    }
    $bestCand = $candlist[$clickedIdx][$path[$clickedIdx]] ?? null;
    return $bestCand ?: null;
}
/**
 * Build word-level context around the first occurrence of a clicked token in a sentence.
 *
 * This mirrors the client-side behavior (word tokens only, punctuation ignored) so Ordbank
 * disambiguation can use surrounding tokens.
 *
 * @return array{prev?:string,prev2?:string,next?:string,next2?:string}
 */
function mod_flashcards_context_from_sentence(string $sentence, string $clicked): array {
    $sentence = mod_flashcards_normalize_text(core_text::strtolower(trim($sentence)));
    $clicked = mod_flashcards_normalize_token(core_text::strtolower(trim($clicked)));
    if ($sentence === '' || $clicked === '') {
        return [];
    }

    $rawtokens = [];
    foreach (array_values(array_filter(preg_split('/\s+/u', $sentence))) as $t) {
        $clean = trim($t, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean !== '') {
            $rawtokens[] = $clean;
        }
    }
    if (empty($rawtokens)) {
        return [];
    }

    $idx = null;
    foreach ($rawtokens as $i => $tok) {
        if ($tok === $clicked) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        // Fallback: try substring match when the clicked token came with punctuation.
        foreach ($rawtokens as $i => $tok) {
            if ($tok !== '' && ($tok === $clicked || str_contains($tok, $clicked) || str_contains($clicked, $tok))) {
                $idx = $i;
                break;
            }
        }
    }
    if ($idx === null) {
        return [];
    }

    $out = [];
    if ($idx > 0) {
        $out['prev'] = $rawtokens[$idx - 1];
    }
    if ($idx > 1) {
        $out['prev2'] = $rawtokens[$idx - 2];
    }
    if (isset($rawtokens[$idx + 1])) {
        $out['next'] = $rawtokens[$idx + 1];
    }
    if (isset($rawtokens[$idx + 2])) {
        $out['next2'] = $rawtokens[$idx + 2];
    }
    return $out;
}

$cmid = optional_param('cmid', 0, PARAM_INT); // CHANGED: optional for global mode
$action = required_param('action', PARAM_ALPHANUMEXT);

require_sesskey();

// Global mode: no specific activity context
$globalmode = ($cmid === 0);

if ($globalmode) {
    // Global access mode - check via access_manager
    require_login(null, false); // Do not allow guests

    // Block guest users
    if (isguestuser()) {
        throw new require_login_exception('Guests are not allowed to access flashcards');
    }

    $context = context_system::instance();
    $access = \mod_flashcards\access_manager::check_user_access($USER->id);

    // Check permissions based on action
    if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media' || $action === 'transcribe_audio' || $action === 'recognize_image' || $action === 'ai_focus_helper' || $action === 'ai_translate' || $action === 'ai_question') {
        // Allow site administrators and managers regardless of grace period/access
        $createallowed = !empty($access['can_create']);
        if (is_siteadmin() || has_capability('moodle/site:config', $context) || has_capability('moodle/course:manageactivities', $context)) {
            $createallowed = true;
        }
        if (!$createallowed) {
            throw new moodle_exception('access_create_blocked', 'mod_flashcards');
        }
    } else if ($action === 'fetch' || $action === 'get_due_cards' || $action === 'get_deck_cards' || $action === 'list_decks' || $action === 'ordbank_focus_helper' || $action === 'sentence_elements') {
        if (!$access['can_view']) {
            throw new moodle_exception('access_denied', 'mod_flashcards');
        }
    } else if ($action === 'save' || $action === 'review_card') {
        if (!$access['can_review']) {
            throw new moodle_exception('access_denied', 'mod_flashcards');
        }
    }

    $flashcardsid = null; // No specific instance in global mode
    $cm = null;
    $course = null;
} else {
    // Activity-specific mode (legacy)
    [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'flashcards');
    $context = context_module::instance($cm->id);
    require_login($course, true, $cm);
    require_capability('mod/flashcards:view', $context);
    $flashcardsid = $cm->instance;
}

    $userid = $USER->id;
    /**
     * Lightly clean verb forms coming from mixed sources (ordbank/ordbokene) to avoid noisy variants.
     */
    function mod_flashcards_prune_verb_forms(array $forms): array {
        if (empty($forms['verb']) || !is_array($forms['verb'])) {
            return $forms;
        }
        $v = $forms['verb'];
        $filter = function($list, callable $cb) {
            if (!is_array($list)) {
                $list = [$list];
            }
            $out = [];
            foreach ($list as $item) {
                $item = trim((string)$item);
                if ($item === '') {
                    continue;
                }
                if ($cb($item)) {
                    $out[] = $item;
                }
            }
            return array_values(array_unique($out));
        };
        $dropPassiveS = function(string $item): bool {
            // Drop passives (-s/-es) when we want active forms.
            return !preg_match('~s$~ui', $item);
        };
        // Active slots: remove passives.
        $v['infinitiv'] = $filter($v['infinitiv'] ?? [], $dropPassiveS);
        $v['presens'] = $filter($v['presens'] ?? [], $dropPassiveS);
        $v['imperativ'] = $filter($v['imperativ'] ?? [], $dropPassiveS);
        // Presens perfektum: keep only "har + perfektum_partisipp" (one word), no -ende/-s/-es.
        $v['presens_perfektum'] = $filter($v['presens_perfektum'] ?? [], function(string $item){
            $item = trim($item);
            if (!preg_match('~^har\\s+.+t$~ui', $item)) {
                return false;
            }
            return !preg_match('~ende$~ui', $item) && !preg_match('~s$~ui', $item);
        });
        // Avoid long lists like "har gjennomførte": keep only the first clean perfektum.
        if (count($v['presens_perfektum']) > 1) {
            $v['presens_perfektum'] = [$v['presens_perfektum'][0]];
        }
        // Perfektum partisipp: drop -ende noise; keep core forms.
        $v['perfektum_partisipp'] = $filter($v['perfektum_partisipp'] ?? [], function(string $item){
            return !preg_match('~ende$~ui', $item);
        });
        $forms['verb'] = $v;
        return $forms;
    }

/**
 * Fetch lemma suggestions from Ordbøkene (ord.uib.no) API.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_suggestions(string $query, int $limit = 12): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $url = 'https://ord.uib.no/api/articles?w=' . rawurlencode($query) . '&dict=bm,nn&scope=e';
    $suggestions = [];
    try {
        $curl = new \curl();
        $resp = $curl->get($url);
        $json = json_decode($resp, true);
        if (is_array($json) && !empty($json['articles'])) {
            foreach ($json['articles'] as $dict => $ids) {
                if (!is_array($ids)) {
                    continue;
                }
                foreach (array_slice($ids, 0, $limit) as $id) {
                    $articleurl = sprintf('https://ord.uib.no/%s/article/%d.json', $dict, (int)$id);
                    try {
                        $resp2 = $curl->get($articleurl);
                        $article = json_decode($resp2, true);
                        if (!is_array($article) || empty($article['lemmas'][0]['lemma'])) {
                            continue;
                        }
                        $lemma = trim($article['lemmas'][0]['lemma']);
                        if ($lemma === '') {
                            continue;
                        }
                        $suggestions[] = [
                            'lemma' => $lemma,
                            'dict' => $dict,
                            'id' => (int)$id,
                            'url' => $articleurl,
                        ];
                    } catch (\Throwable $e) {
                        // Skip failed article fetch.
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    $seen = [];
    $deduped = [];
    foreach ($suggestions as $s) {
        $key = core_text::strtolower(($s['lemma'] ?? '') . '|' . ($s['dict'] ?? ''));
        if (isset($seen[$key]) || ($s['lemma'] ?? '') === '') {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $s;
        if (count($deduped) >= $limit) {
            break;
        }
    }
    return $deduped;
}

/**
 * Try to fetch multi-word expressions from Ordbøkene first.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_expressions(string $query, int $limit = 8): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    // Try full query first, then shorter trailing spans (e.g. last 3/2 tokens).
    $parts = array_values(array_filter(preg_split('/\s+/u', $query)));
    $spans = [$query];
    if (count($parts) >= 3) {
        $spans[] = implode(' ', array_slice($parts, -3));
    }
    if (count($parts) >= 2) {
        $spans[] = implode(' ', array_slice($parts, -2));
    }
    $out = [];
    $seen = [];
    foreach ($spans as $span) {
        $span = trim($span);
        if ($span === '' || isset($seen[$span])) {
            continue;
        }
        $seen[$span] = true;
        try {
            $data = \mod_flashcards\local\ordbokene_client::search_expressions($span, 'begge');
            if (empty($data)) {
                continue;
            }
            $exprs = [];
            if (!empty($data['expressions']) && is_array($data['expressions'])) {
                $exprs = array_map('strval', $data['expressions']);
            }
            // If no expressions array, try baseform from article as a fallback.
            if (empty($exprs) && !empty($data['baseform'])) {
                $exprs[] = (string)$data['baseform'];
            }
            foreach ($exprs as $expr) {
                $expr = trim($expr);
                if ($expr === '') {
                    continue;
                }
                $key = core_text::strtolower($expr . '|ordbokene');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'lemma' => $expr,
                    'dict' => 'ordbokene',
                    'source' => 'ordbokene',
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        } catch (\Throwable $e) {
            // Ignore and continue with next span.
        }
    }
    return $out;
}

/**
 * Fallback: lookup spans directly and return baseforms as expressions.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_lookup_spans(string $query, int $limit = 6): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $parts = array_values(array_filter(preg_split('/\s+/u', $query)));
    $spans = [$query];
    if (count($parts) >= 3) {
        $spans[] = implode(' ', array_slice($parts, -3));
    }
    if (count($parts) >= 2) {
        $spans[] = implode(' ', array_slice($parts, -2));
    }
    $out = [];
    $seen = [];
    foreach ($spans as $span) {
        $span = trim($span);
        if ($span === '' || isset($seen[$span])) {
            continue;
        }
        $seen[$span] = true;
        try {
            $data = \mod_flashcards\local\ordbokene_client::lookup($span, 'begge');
            if (empty($data)) {
                continue;
            }
            $base = trim((string)($data['baseform'] ?? ''));
            if ($base !== '') {
                $key = core_text::strtolower($base . '|ordbokene');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = [
                        'lemma' => $base,
                        'dict' => 'ordbokene',
                        'source' => 'ordbokene',
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore and continue
        }
    }
    return $out;
}

/**
 * Use /api/suggest with include=eif to surface expressions and inflections.
 *
 * @param string $query
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function flashcards_fetch_ordbokene_suggest(string $query, int $limit = 12): array {
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return [];
    }
    $buildUrls = function(string $q) use ($limit): array {
        return [
            sprintf('https://ord.uib.no/api/suggest?q=%s&dict=bm,nn&include=efis&n=%d', rawurlencode($q), $limit),
            sprintf('https://ord.uib.no/api/suggest?q=%s%%&dict=bm,nn&include=efis&n=%d', rawurlencode($q), $limit),
        ];
    };
    $urls = $buildUrls($query);
    $out = [];
    $seen = [];
    try {
        $curl = new \curl();
        foreach ($urls as $url) {
            $resp = $curl->get($url);
            $json = json_decode($resp, true);
            if (!is_array($json) || empty($json['a'])) {
                continue;
            }
            foreach (['exact','inflect','freetext','similar'] as $bucket) {
                if (empty($json['a'][$bucket]) || !is_array($json['a'][$bucket])) {
                    continue;
                }
                foreach ($json['a'][$bucket] as $item) {
                    $lemma = trim((string)($item[0] ?? ''));
                    $langs = [];
                    if (!empty($item[1]) && is_array($item[1])) {
                        $langs = array_values(array_filter(array_map('strval', $item[1])));
                    }
                    if ($lemma === '') {
                        continue;
                    }
                    $key = core_text::strtolower($lemma . '|ordbokene');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = [
                        'lemma' => $lemma,
                        'dict' => 'ordbokene',
                        'source' => 'ordbokene',
                        'langs' => $langs,
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // ignore and return what we have
    }
    return $out;
}

/**
 * Filter suggestions so multi-word queries keep only lemmas that contain all tokens.
 *
 * @param array $items
 * @param string $query
 * @return array
 */
function flashcards_filter_multiword(array $items, string $query): array {
    $tokens = array_values(array_filter(preg_split('/\s+/u', trim($query))));
    if (count($tokens) < 2) {
        return $items;
    }
    $lowerTokens = array_map(function($t){ return core_text::strtolower($t); }, $tokens);
    $out = [];
    foreach ($items as $item) {
        $lemma = core_text::strtolower((string)($item['lemma'] ?? ''));
        if ($lemma === '') {
            continue;
        }
        $ok = true;
        foreach ($lowerTokens as $tok) {
            if (strpos($lemma, $tok) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $out[] = $item;
        }
    }
    return $out ?: $items;
}

switch ($action) {
    case 'fetch':
        echo json_encode([ 'ok' => true, 'data' => \mod_flashcards\local\api::fetch_progress($flashcardsid, $userid) ]);
        break;

    case 'save':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        \mod_flashcards\local\api::save_progress_batch($flashcardsid, $userid, $payload['records']);
        echo json_encode(['ok' => true]);
        break;

    case 'upload_media':
        // Accepts multipart/form-data with field 'file' and optional 'type' (image|audio).
        // Stores in Moodle file storage and returns a pluginfile URL.
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new invalid_parameter_exception('No file');
        }
        $type = optional_param('type', 'file', PARAM_ALPHA);
        $cardid = optional_param('cardid', '', PARAM_RAW_TRIMMED); // Card ID for unique file storage
        $originalname = clean_param($_FILES['file']['name'], PARAM_FILE);
        $fs = get_file_storage();

        // Generate UNIQUE filename based on cardid and type to prevent collisions
        // Format: {cardid}_{type}.{ext} or {timestamp}_{type}.{ext} if no cardid
        $extension = pathinfo($originalname, PATHINFO_EXTENSION);
        if (!$extension) {
            $extension = ($type === 'image') ? 'jpg' : 'webm';
        }

        if ($cardid) {
            // Use cardid in filename for uniqueness
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $cardid) . '_' . $type . '.' . $extension;
        } else {
            // Fallback: use timestamp
            $filename = time() . '_' . $type . '.' . $extension;
        }

        // Use simple itemid based on userid (all user's files in one itemid)
        $itemid = $userid;
        $filepath = '/';

        // IMPORTANT: ALWAYS use user context for file storage, even in activity mode!
        // Why? If activity is deleted, files in module context are deleted too.
        // But cards should persist - they belong to USER, not to specific activity.
        // User's files are stored in their user context and survive activity deletion.
        $filecontext = context_user::instance($userid);

        $fileinfo = [
            'contextid' => $filecontext->id,
            'component' => 'mod_flashcards',
            'filearea'  => 'media',
            'itemid'    => $itemid,
            'filepath'  => $filepath,
            'filename'  => $filename,
            'timecreated' => time(),
        ];
        // Remove existing with same name/path to allow overwrite (for re-uploads to same card).
        if ($existing = $fs->get_file($filecontext->id, 'mod_flashcards', 'media', $itemid, $filepath, $filename)) {
            $existing->delete();
        }
        $file = $fs->create_file_from_pathname($fileinfo, $_FILES['file']['tmp_name']);
        $url = moodle_url::make_pluginfile_url($filecontext->id, 'mod_flashcards', 'media', $itemid, $filepath, $filename)->out(false);

        // Debug logging
        debugging(sprintf(
            'File uploaded: contextid=%d, itemid=%d, filename=%s, filesize=%d, url=%s',
            $filecontext->id, $itemid, $filename, $file->get_filesize(), $url
        ), DEBUG_DEVELOPER);

        echo json_encode(['ok' => true, 'data' => ['url' => $url, 'type' => $type, 'name' => $filename]]);
        break;

    case 'transcribe_audio':
        $response = ['ok' => false];
        $tempfile = null;
        try {
            if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                throw new invalid_parameter_exception('No file');
            }
            $maxsize = 8 * 1024 * 1024; // 8 MB safety cap.
            $filesize = (int)($_FILES['file']['size'] ?? 0);
            if ($filesize <= 0) {
                throw new invalid_parameter_exception('Invalid file size');
            }
            if ($filesize > $maxsize) {
                throw new moodle_exception('error_whisper_filesize', 'mod_flashcards', '', display_size($maxsize));
            }

            $duration = (int)round(optional_param('duration', 0, PARAM_FLOAT));
            $language = trim(optional_param('language', '', PARAM_ALPHANUMEXT));
            $originalname = clean_param($_FILES['file']['name'] ?? 'audio.webm', PARAM_FILE);
            $mimetype = clean_param($_FILES['file']['type'] ?? '', PARAM_RAW_TRIMMED);

            $basedir = make_temp_directory('mod_flashcards');
            $tempfile = tempnam($basedir, 'stt');
            if ($tempfile === false) {
                throw new moodle_exception('error_stt_upload', 'mod_flashcards');
            }
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempfile)) {
                throw new moodle_exception('error_stt_upload', 'mod_flashcards');
            }

            // Determine which STT provider to use
            $config = get_config('mod_flashcards');
            $sttprovider = trim($config->stt_provider ?? '') ?: 'whisper';

            // Check provider availability and fallback logic
            $whisperkey = trim($config->whisper_apikey ?? '') ?: getenv('FLASHCARDS_WHISPER_KEY') ?: '';
            $whisperenabled = !empty($config->whisper_enabled) && $whisperkey !== '';

            $elevenlabssttkey = trim($config->elevenlabs_stt_apikey ?? '')
                ?: trim($config->elevenlabs_apikey ?? '')
                ?: getenv('FLASHCARDS_ELEVENLABS_KEY') ?: '';
            $elevenlabssttenabled = !empty($config->elevenlabs_stt_enabled) && $elevenlabssttkey !== '';

            // Select client based on provider
            if ($sttprovider === 'elevenlabs' && $elevenlabssttenabled) {
                $client = new \mod_flashcards\local\elevenlabs_stt_client();
            } else if ($whisperenabled) {
                $client = new \mod_flashcards\local\whisper_client();
            } else if ($elevenlabssttenabled) {
                $client = new \mod_flashcards\local\elevenlabs_stt_client();
            } else {
                throw new moodle_exception('error_stt_disabled', 'mod_flashcards');
            }

            $text = $client->transcribe(
                $tempfile,
                $originalname,
                $mimetype ?: mime_content_type($tempfile) ?: 'application/octet-stream',
                $userid,
                $duration,
                $language !== '' ? $language : null
            );
            $response = ['ok' => true, 'data' => [
                'text' => $text,
                'usage' => mod_flashcards_get_usage_snapshot($userid),
            ]];
            http_response_code(200);
        } catch (\moodle_exception $ex) {
            http_response_code(400);
            $response = [
                'ok' => false,
                'error' => $ex->getMessage(),
                'errorcode' => $ex->errorcode,
            ];
        } catch (\Throwable $ex) {
            http_response_code(400);
            debugging('STT transcription failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $response = [
                'ok' => false,
                'error' => get_string('error_stt_api', 'mod_flashcards', $ex->getMessage()),
                'errorcode' => 'unknown',
            ];
        } finally {
            if ($tempfile && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        break;

    case 'recognize_image':
        $response = ['ok' => false];
        $tempfile = null;
        try {
            if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                throw new invalid_parameter_exception('No file');
            }
            $maxsize = FLASHCARDS_OCR_UPLOAD_LIMIT_BYTES;
            $filesize = (int)($_FILES['file']['size'] ?? 0);
            if ($filesize <= 0) {
                throw new invalid_parameter_exception('Invalid file size');
            }
            if ($filesize > $maxsize) {
                throw new moodle_exception('error_ocr_filesize', 'mod_flashcards', '', display_size($maxsize));
            }

            $originalname = clean_param($_FILES['file']['name'] ?? 'ocr.png', PARAM_FILE);
            $mimetype = clean_param($_FILES['file']['type'] ?? '', PARAM_RAW_TRIMMED);

            $basedir = make_temp_directory('mod_flashcards');
            $tempfile = tempnam($basedir, 'ocr');
            if ($tempfile === false) {
                throw new moodle_exception('error_ocr_upload', 'mod_flashcards');
            }
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempfile)) {
                throw new moodle_exception('error_ocr_upload', 'mod_flashcards');
            }

            $client = new \mod_flashcards\local\ocr_client();
            $text = $client->recognize($tempfile, $originalname, $mimetype, $userid);
            $response = ['ok' => true, 'data' => ['text' => $text]];
            http_response_code(200);
        } catch (\moodle_exception $ex) {
            http_response_code(400);
            $response = [
                'ok' => false,
                'error' => $ex->getMessage(),
                'errorcode' => $ex->errorcode,
            ];
        } catch (\Throwable $ex) {
            http_response_code(400);
            debugging('OCR recognition failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $response = [
                'ok' => false,
                'error' => get_string('error_ocr_api', 'mod_flashcards', $ex->getMessage()),
                'errorcode' => 'unknown',
            ];
        } finally {
            if ($tempfile && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        break;

    // --- Decks & Cards CRUD ---
    case 'list_decks':
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::list_decks($userid, $globalmode)]);
        break;
    case 'create_deck':
        if (!$globalmode) {
            require_capability('moodle/course:manageactivities', $context);
        }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $title = clean_param($payload['title'] ?? '', PARAM_TEXT);
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::create_deck($userid, $title, $globalmode)]);
        break;
    case 'get_deck_cards':
        $deckid = required_param('deckid', PARAM_INT);
        $offset = optional_param('offset', 0, PARAM_INT);
        $limit = optional_param('limit', 100, PARAM_INT);
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::get_deck_cards($userid, $deckid, $offset, $limit, $globalmode)]);
        break;
    case 'get_due_cards':
        // Get only cards that are due today (optimized for performance)
        $limit = optional_param('limit', 1000, PARAM_INT); // Increased from 100 to 1000
        echo json_encode(['ok' => true, 'data' => \mod_flashcards\local\api::get_due_cards_optimized($userid, $flashcardsid, $limit, $globalmode)]);
        break;
    case 'upsert_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $result = \mod_flashcards\local\api::upsert_card($userid, $payload, $globalmode, $context);
        echo json_encode(['ok' => true, 'data' => $result]);
        break;
    case 'delete_card':
        $deckid = required_param('deckid', PARAM_RAW_TRIMMED); // May be string or int
        $cardid = required_param('cardid', PARAM_RAW_TRIMMED);
        // Convert to int if numeric
        if (is_numeric($deckid)) {
            $deckid = (int)$deckid;
        }
        \mod_flashcards\local\api::delete_card($userid, $deckid, $cardid, $globalmode, $context);
        echo json_encode(['ok' => true]);
        break;

    // --- SRS queue (fixed intervals) ---
    case 'review_card':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $deckid = (int)($payload['deckId'] ?? 0);
        $cardid = clean_param($payload['cardId'] ?? '', PARAM_RAW_TRIMMED);
        $rating = (int)($payload['rating'] ?? 0); // 1=hard, 2=normal, 3=easy
        \mod_flashcards\local\api::review_card($globalmode ? null : $cm, $userid, $flashcardsid, $deckid, $cardid, $rating, $globalmode);
        echo json_encode(['ok' => true]);
        break;

    // --- Dashboard & Statistics ---
    case 'get_dashboard_data':
        $data = \mod_flashcards\local\api::get_dashboard_data($userid);
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'recalculate_stats':
        $actual_count = \mod_flashcards\local\api::recalculate_total_cards($userid);
        $active_vocab = \mod_flashcards\local\api::calculate_active_vocab($userid);
        echo json_encode(['ok' => true, 'data' => [
            'totalCardsCreated' => $actual_count,
            'activeVocab' => round($active_vocab, 2),
        ]]);
        break;

    case 'check_text_errors':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->check_norwegian_text($text, $language, $userid);

        echo json_encode($result);
        break;

    case 'sentence_elements':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $debug = !empty($payload['debug']);
        $debugAi = !empty($payload['debug_ai']) && is_siteadmin();
        $aiDebug = [];
        $enrich = !empty($payload['enrich']) || !empty($payload['useLlm']) || !empty($payload['llm']);
        $skipSentenceTranslation = !empty($payload['skip_sentence_translation']);
        $language = clean_param($payload['language'] ?? 'en', PARAM_ALPHANUMEXT);
        $overallstart = microtime(true);
        $timing = [];
        $dataDebugLlm = null;
        $llmSuggested = [];
        $llmConfirmMeta = null;
        $t0 = microtime(true);
        $spacy = mod_flashcards_spacy_analyze($text);
        $timing['spacy'] = microtime(true) - $t0;
        // Use coarse POS for pattern matching, but keep output POS more precise (e.g. SCONJ vs CONJ) for UI.
        $posMap = mod_flashcards_spacy_pos_map($text, $spacy, 'coarse');
        $posMapOut = mod_flashcards_spacy_pos_map($text, $spacy, 'output');
        $lemmaMap = mod_flashcards_spacy_lemma_map($text, $spacy);
        $depMap = mod_flashcards_spacy_dep_map($text, $spacy);
        $wordtokens = mod_flashcards_word_tokens_with_offsets($text);
        $words = [];
        foreach ($wordtokens as $i => $w) {
            $words[] = [
                'index' => $i,
                'text' => (string)($w['text'] ?? ''),
                'start' => (int)($w['start'] ?? 0),
                'end' => (int)($w['end'] ?? 0),
                'pos' => $posMapOut[$i] ?? ($posMap[$i] ?? ''),
                'lemma' => $lemmaMap[$i] ?? '',
                'dep' => $depMap[$i] ?? '',
            ];
        }
        $posCandidatesMap = [];
        $verbPrepsMap = [];
        $verbAnyPrepMap = [];
        $exprLemmaMap = $lemmaMap;
        $t0 = microtime(true);
        $ordbankCache = [];
        foreach ($words as $i => $w) {
            $token = mod_flashcards_normalize_token((string)($w['text'] ?? ''));
            if ($token === '') {
                continue;
            }
            $spacyPos = $posMap[$i] ?? '';
            if ($spacyPos !== 'VERB') {
                continue;
            }
            if (!array_key_exists($token, $ordbankCache)) {
                $ordbankCache[$token] = \mod_flashcards\local\ordbank_helper::find_candidates($token);
            }
            $cands = $ordbankCache[$token];
            if (empty($cands)) {
                continue;
            }
            $bestBase = '';
            foreach ($cands as $cand) {
                $pos = mod_flashcards_pos_from_tag((string)($cand['tag'] ?? ''), (string)($cand['ordklasse'] ?? ''));
                if ($pos !== 'X') {
                    $posCandidatesMap[$i][] = $pos;
                }
                if ($pos === 'VERB') {
                    $argcodes = \mod_flashcards\local\ordbank_helper::extract_argcodes_from_tag((string)($cand['tag'] ?? ''));
                    $argmeta = \mod_flashcards\local\ordbank_helper::argcode_meta($argcodes);
                    if (!empty($argmeta['preps'])) {
                        if (!isset($verbPrepsMap[$i])) {
                            $verbPrepsMap[$i] = [];
                        }
                        $verbPrepsMap[$i] = array_merge($verbPrepsMap[$i], $argmeta['preps']);
                    }
                    if (!empty($argmeta['requires_pp'])) {
                        $verbAnyPrepMap[$i] = true;
                    }
                }
                if ($bestBase === '' && $spacyPos !== '' && $pos === $spacyPos && !empty($cand['baseform'])) {
                    $bestBase = (string)$cand['baseform'];
                }
            }
            if ($bestBase === '') {
                foreach ($cands as $cand) {
                    if (!empty($cand['baseform'])) {
                        $bestBase = (string)$cand['baseform'];
                        break;
                    }
                }
            }
            if ($bestBase !== '') {
                $exprLemmaMap[$i] = core_text::strtolower($bestBase);
            }
            if (!empty($posCandidatesMap[$i])) {
                $posCandidatesMap[$i] = array_values(array_unique($posCandidatesMap[$i]));
            }
            if (!empty($verbPrepsMap[$i])) {
                $verbPrepsMap[$i] = array_values(array_unique($verbPrepsMap[$i]));
            }
        }
        $timing['ordbank'] = microtime(true) - $t0;
        $sentenceSurfaceTokens = [];
        $sentenceLemmaTokens = [];
        foreach ($words as $i => $w) {
            $surface = mod_flashcards_normalize_token((string)($w['text'] ?? ''));
            $sentenceSurfaceTokens[] = $surface;
            // Для глобального лемма-токен ряда используем первичную лемму из spaCy,
            // чтобы не терять инфинитив (например, "skille seg ut"), даже если exprLemmaMap переопределялось baseform-ами.
            $lemma = $lemmaMap[$i] ?? $surface;
            $sentenceLemmaTokens[] = mod_flashcards_normalize_token((string)$lemma);
        }
        $inOrder = function(array $needle, array $hay): bool {
            if (empty($needle) || empty($hay)) {
                return false;
            }
            $pos = 0;
            $n = count($hay);
            foreach ($needle as $tok) {
                $found = false;
                for (; $pos < $n; $pos++) {
                    if ($hay[$pos] === $tok) {
                        $found = true;
                        $pos++;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            return true;
        };
        $lang = 'begge';
        $t0 = microtime(true);
        $cands = mod_flashcards_expression_candidates_from_words($words, $exprLemmaMap, $posMap, $posCandidatesMap, $verbPrepsMap, $verbAnyPrepMap, $depMap);
        if (empty($cands)) {
            $fallbackCands = [];
            $seenFallback = [];
            $countWords = count($words);
            $maxGap = 6;
            for ($i = 0; $i < $countWords; $i++) {
                if (($posMap[$i] ?? '') !== 'VERB') {
                    continue;
                }
                $verbLemma = $exprLemmaMap[$i] ?? '';
                if ($verbLemma === '') {
                    continue;
                }
                for ($j = $i + 1; $j < $countWords && ($j - $i - 1) <= $maxGap; $j++) {
                    if (in_array(($posMap[$j] ?? ''), ['VERB','AUX','SCONJ','CCONJ'], true)) {
                        break;
                    }
                    if (($posMap[$j] ?? '') !== 'ADP') {
                        continue;
                    }
                    $prepLemma = $exprLemmaMap[$j] ?? '';
                    if ($prepLemma === '') {
                        continue;
                    }
                    $expr = trim($verbLemma . ' ' . $prepLemma);
                    $key = core_text::strtolower($expr);
                    if ($key === '' || isset($seenFallback[$key])) {
                        continue;
                    }
                    $seenFallback[$key] = true;
                    $fallbackCands[] = [
                        'lemma' => $expr,
                        'surface' => $expr,
                        'len' => 2,
                        'score' => 6,
                        'source' => 'gap_verb_prep_fallback',
                        'start' => $i,
                        'end' => $j,
                        'max_gap' => $j - $i - 1,
                    ];
                }
            }
            if (!empty($fallbackCands)) {
                $cands = array_merge($cands, $fallbackCands);
            }
        }
        $timing['expr_candidates'] = microtime(true) - $t0;
        if (!empty($cands)) {
            usort($cands, function($a, $b) {
                if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                    return ($b['len'] ?? 0) <=> ($a['len'] ?? 0);
                }
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });
        }
        $candRawCount = count($cands);
        $cands = array_slice($cands, 0, 20);

        // Ordbøkene freetext fallback (scope=f): for strong patterns that are valid but lack a dedicated Ordbøkene header entry
        // (e.g. "kle på seg" appears as a meaning/phrase inside other articles).
        $freetextStart = microtime(true);
        $freetextAllowSources = [
            'R09',
            'R10',
            'dep_reflexive',
            'dep_reflexive_soft',
            'dep_reflexive_particle',
            'dep_reflexive_particle_soft',
            'dep_particle',
        ];
        $freetextNeedles = [];
        foreach ($cands as $cand) {
            if (!is_array($cand)) {
                continue;
            }
            $src = (string)($cand['source'] ?? '');
            if (!in_array($src, $freetextAllowSources, true)) {
                continue;
            }
            $expr = trim((string)($cand['lemma'] ?? ''));
            if ($expr === '' || count(mod_flashcards_word_tokens($expr)) < 2) {
                continue;
            }
            $key = \mod_flashcards\local\orbokene_repository::normalize_phrase($expr);
            if ($key === '') {
                continue;
            }
            $freetextNeedles[$key] = $expr;
            if (count($freetextNeedles) >= 5) {
                break;
            }
        }
        $ordbokeneFreetextMap = [];
        if (!empty($freetextNeedles) && \mod_flashcards\local\orbokene_repository::is_enabled()) {
            try {
                $ordbokeneFreetextMap = mod_flashcards_ordbokene_freetext_confirm_map(array_values($freetextNeedles), $lang, $words);
            } catch (\Throwable $e) {
                $ordbokeneFreetextMap = [];
            }
        }
        // Only add freetext timing to debug to keep the public response minimal.
        if ($debug) {
            $timing['ordbokene_freetext'] = microtime(true) - $freetextStart;
        }

        $t0 = microtime(true);
        $resolved = mod_flashcards_resolve_lexical_expressions($sentenceSurfaceTokens, $sentenceLemmaTokens, $posMap, $words, $text, $lang, 10);
        $timing['expr_lexical'] = microtime(true) - $t0;
        $lexicalCount = count($resolved);
        $seenCand = [];
        $seenResolved = [];
        $debugLookupMap = [];
        $debugLookupLimit = 10;
        if (!empty($resolved)) {
            foreach ($resolved as $item) {
                $key = core_text::strtolower((string)($item['expression'] ?? ''));
                if ($key !== '') {
                    $seenResolved[$key] = true;
                }
            }
        }
        $t0 = microtime(true);
        foreach ($cands as $cand) {
            $expr = trim((string)($cand['lemma'] ?? ''));
            $surfaceExpr = trim((string)($cand['surface'] ?? ''));
            if ($expr === '' && $surfaceExpr === '') {
                continue;
            }
            $candSource = (string)($cand['source'] ?? '');
            $candDebugKey = $expr !== '' ? $expr : $surfaceExpr;
            $candDebugTrace = [];
            $candStart = isset($cand['start']) ? (int)$cand['start'] : null;
            $candEnd = isset($cand['end']) ? (int)$cand['end'] : null;
            if ($candStart !== null && $candEnd !== null && !empty($resolved)) {
                foreach ($resolved as $prev) {
                    if (!isset($prev['start'], $prev['end'], $prev['len'])) {
                        continue;
                    }
                    $prevSource = (string)($prev['source'] ?? '');
                    if (!in_array($prevSource, ['ordbokene','cache','examples'], true)) {
                        continue;
                    }
                    $overlaps = $candStart <= $prev['end'] && $prev['start'] <= $candEnd;
                    if ($overlaps && ($prev['len'] ?? 0) >= ($cand['len'] ?? 0)) {
                        continue 2;
                    }
                }
            }
            $headLemma = '';
            $startIdx = isset($cand['start']) ? (int)$cand['start'] : null;
            $endIdx = isset($cand['end']) ? (int)$cand['end'] : null;
            if ($startIdx !== null && $endIdx !== null && $startIdx <= $endIdx) {
                for ($i = $startIdx; $i <= $endIdx; $i++) {
                    $pos = $posMap[$i] ?? '';
                    $posCandidates = $posCandidatesMap[$i] ?? [];
                    $posList = $pos !== '' ? array_merge([$pos], $posCandidates) : $posCandidates;
                    $posList = array_values(array_unique(array_filter($posList)));
                    if (array_intersect($posList, ['NOUN','ADJ'])) {
                        $headLemma = (string)($exprLemmaMap[$i] ?? '');
                        break;
                    }
                }
                if ($headLemma === '') {
                    for ($i = $startIdx; $i <= $endIdx; $i++) {
                        $pos = $posMap[$i] ?? '';
                        $posCandidates = $posCandidatesMap[$i] ?? [];
                        $posList = $pos !== '' ? array_merge([$pos], $posCandidates) : $posCandidates;
                        $posList = array_values(array_unique(array_filter($posList)));
                        if (in_array('VERB', $posList, true)) {
                            $headLemma = (string)($exprLemmaMap[$i] ?? '');
                            break;
                        }
                    }
                }
                if ($headLemma === '') {
                    $headLemma = (string)($exprLemmaMap[$startIdx] ?? '');
                }
            }
            $key = core_text::strtolower($expr !== '' ? $expr : $surfaceExpr);
            if (isset($seenCand[$key])) {
                continue;
            }
            $seenCand[$key] = true;
            // Build a lemma-normalized variant for lookup (helps "skilte seg ut" -> "skille seg ut").
            $lemmaVariant = '';
            if ($startIdx !== null && $endIdx !== null) {
                $lemmaParts = [];
                for ($i = $startIdx; $i <= $endIdx; $i++) {
                    $lem = $exprLemmaMap[$i] ?? '';
                    if ($lem === '') {
                        continue;
                    }
                    $lower = core_text::strtolower($lem);
                    if (in_array($lower, ['meg','deg','oss','dere','dem'], true)) {
                        $lem = 'seg';
                    }
                    $lemmaParts[] = $lem;
                }
                if (!empty($lemmaParts)) {
                    $lemmaVariant = mod_flashcards_normalize_infinitive(trim(implode(' ', $lemmaParts)));
                }
            }
            $variants = [];
            if ($lemmaVariant !== '') {
                $variants[] = $lemmaVariant;
            }
            if ($expr !== '') {
                $variants = array_merge($variants, mod_flashcards_expand_expression_variants($expr));
                $variants[] = $expr;
            }
            if ($surfaceExpr !== '' && $surfaceExpr !== $expr) {
                $variants[] = $surfaceExpr;
            }
            $variants = array_values(array_unique(array_filter($variants)));
            $match = null;
            $lemmaTokens = $expr !== '' ? mod_flashcards_word_tokens($expr) : [];
            $surfaceTokens = $surfaceExpr !== '' ? mod_flashcards_word_tokens($surfaceExpr) : [];
            $phraseMatch = null;
            $maxGap = isset($cand['max_gap']) ? max(0, (int)$cand['max_gap']) : 2;
            if (!empty($lemmaTokens)) {
                $phraseMatch = mod_flashcards_find_phrase_match($sentenceLemmaTokens, $lemmaTokens, $maxGap);
            }
            if ($phraseMatch === null && !empty($surfaceTokens)) {
                $phraseMatch = mod_flashcards_find_phrase_match($sentenceSurfaceTokens, $surfaceTokens, $maxGap);
            }
            if ($phraseMatch !== null && $candStart !== null && $candEnd !== null) {
                if ($phraseMatch['start'] < $candStart || $phraseMatch['end'] > $candEnd) {
                    $phraseMatch = null;
                }
            }
            if ($phraseMatch === null) {
                continue;
            }
            foreach ($variants as $variant) {
                if (\mod_flashcards\local\orbokene_repository::is_enabled()) {
                    $cached = \mod_flashcards\local\orbokene_repository::find($variant);
                    if (!empty($cached)) {
                        if ($debug) {
                            $candDebugTrace[] = [
                                'method' => 'cache',
                                'expression' => $variant,
                                'hit' => true,
                            ];
                        }
                        $cachedmeta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
                        $cacheddictmeta = $cachedmeta['dictmeta'] ?? null;
                        if (!is_array($cacheddictmeta)) {
                            $cacheddictmeta = ['source' => 'cache'];
                        } else if (empty($cacheddictmeta['source'])) {
                            $cacheddictmeta['source'] = 'cache';
                        }
                        $cachedsource = (string)($cachedmeta['source'] ?? 'cache');
                        // If this cache entry came from Ordbøkene freetext but lacks dict meta (older cached row),
                        // refresh it once and treat as Ordbøkene-confirmed.
                        if ($cachedsource === 'ordbokene_freetext' && (empty($cacheddictmeta['url']) || empty($cacheddictmeta['lang']))) {
                            try {
                                $refresh = mod_flashcards_ordbokene_freetext_confirm_map([$variant], $lang, $words);
                                $rk = \mod_flashcards\local\orbokene_repository::normalize_phrase($variant);
                                if ($rk !== '' && !empty($refresh[$rk]) && is_array($refresh[$rk])) {
                                    if ($debug) {
                                        $candDebugTrace[] = [
                                            'method' => 'cache_refresh',
                                            'expression' => $variant,
                                            'hit' => true,
                                        ];
                                    }
                                    $match = $refresh[$rk];
                                    break;
                                }
                            } catch (\Throwable $e) {
                                // ignore refresh errors, fallback to cached payload
                            }
                        }
                        $expression = $cached['baseform'] ?? $cached['entry'] ?? $variant;
                        $meaning = $cached['definition'] ?? $cached['translation'] ?? '';
                        $matchsource = ($cachedsource === 'ordbokene_freetext') ? 'ordbokene' : 'cache';
                        if ($matchsource === 'ordbokene') {
                            $cacheddictmeta['source'] = 'ordbokene';
                        }
                        $match = [
                            'expression' => $expression,
                            'meanings' => $meaning ? [$meaning] : [],
                            'examples' => $cached['examples'] ?? [],
                            'dictmeta' => $cacheddictmeta,
                            'source' => $matchsource,
                            'variants' => $cachedmeta['variants'] ?? [],
                            'chosenMeaning' => $cachedmeta['chosenMeaning'] ?? null,
                            'meanings_all' => $cachedmeta['meanings_all'] ?? null,
                        ];
                        break;
                    }
                }
                if ($debug) {
                    $debugResult = mod_flashcards_lookup_or_search_expression_debug($variant, $lang);
                    $candDebugTrace[] = $debugResult['trace'];
                    $match = $debugResult['match'];
                } else {
                    $match = mod_flashcards_lookup_or_search_expression($variant, $lang);
                }
                if (!empty($match)) {
                    // Guardrail: avoid confirming multiword candidates by a single-token Ordbøkene hit
                    // that matches only one component token (e.g. "få med" -> "med").
                    $searchedTokens = mod_flashcards_word_tokens($variant);
                    $matchExpr = trim((string)($match['expression'] ?? $match['baseform'] ?? ''));
                    $matchTokens = $matchExpr !== '' ? mod_flashcards_word_tokens($matchExpr) : [];
                    if (count($searchedTokens) >= 2 && count($matchTokens) === 1) {
                        $single = core_text::strtolower((string)($matchTokens[0] ?? ''));
                        $searchedLower = array_map(function($t) {
                            return core_text::strtolower((string)$t);
                        }, $searchedTokens);
                        if ($single !== '' && in_array($single, $searchedLower, true)) {
                            $match = null;
                            continue;
                        }
                    }
                    break;
                }
            }
            if (empty($match)) {
                if ($headLemma !== '') {
                    $exampleMatch = mod_flashcards_orbokene_example_match(
                        $headLemma,
                        $surfaceExpr !== '' ? $surfaceExpr : $expr,
                        $expr !== '' ? $expr : $surfaceExpr,
                        $lang
                    );
                    if (!empty($exampleMatch)) {
                        if ($debug) {
                            $candDebugTrace[] = [
                                'method' => 'example_match',
                                'expression' => $surfaceExpr !== '' ? $surfaceExpr : $expr,
                                'hit' => true,
                            ];
                        }
                        $match = $exampleMatch;
                    }
                }
            }
            if (empty($match) && !empty($ordbokeneFreetextMap)) {
                foreach ([$expr, $surfaceExpr] as $candidateKey) {
                    $k = \mod_flashcards\local\orbokene_repository::normalize_phrase($candidateKey);
                    if ($k !== '' && isset($ordbokeneFreetextMap[$k])) {
                        $match = $ordbokeneFreetextMap[$k];
                        break;
                    }
                }
            }
            if ($debug && !empty($candDebugTrace) && count($debugLookupMap) < $debugLookupLimit) {
                $debugLookupMap[] = [
                    'candidate' => $candDebugKey,
                    'source' => $candSource,
                    'traces' => $candDebugTrace,
                ];
            }
            if (empty($match)) {
                // Fallback: allow ordbank-based noun+prep collocation patterns.
                $fallbackExpr = $expr !== '' ? $expr : $surfaceExpr;
                if ($fallbackExpr === '') {
                    continue;
                }
                if (count(mod_flashcards_word_tokens($fallbackExpr)) < 2) {
                    continue;
                }
                $match = [
                    'expression' => $fallbackExpr,
                    'meanings' => [],
                    'examples' => [],
                    'dictmeta' => [],
                    'source' => 'pattern',
                ];
            }
            if (!empty($match) && ($match['source'] ?? '') === 'ordbokene') {
                $exclude = is_array($phraseMatch['indices'] ?? null) ? $phraseMatch['indices'] : [];
                $match = mod_flashcards_pick_ordbokene_sense_for_sentence($match, $words, $exclude);
            }
            $expression = (string)($match['expression'] ?? '');
            if ($expression === '') {
                $expression = $expr !== '' ? $expr : $surfaceExpr;
            }
            $exprTokens = mod_flashcards_word_tokens($expression);
            if (count($exprTokens) < 2) {
                $fallbackExpr = $surfaceExpr !== '' ? $surfaceExpr : $expr;
                if ($fallbackExpr !== '') {
                    $fallbackTokens = mod_flashcards_word_tokens($fallbackExpr);
                    if (count($fallbackTokens) >= 2) {
                        $expression = $fallbackExpr;
                        $exprTokens = $fallbackTokens;
                    }
                }
            }
            if (count($exprTokens) < 2) {
                continue;
            }
            $exprMatch = mod_flashcards_find_phrase_match($sentenceLemmaTokens, $exprTokens, $maxGap);
            if ($exprMatch === null) {
                $exprMatch = mod_flashcards_find_phrase_match($sentenceSurfaceTokens, $exprTokens, $maxGap);
            }
            if ($exprMatch === null && $surfaceExpr !== '' && $surfaceExpr !== $expression) {
                $surfaceExprTokens = mod_flashcards_word_tokens($surfaceExpr);
                if (!empty($surfaceExprTokens)) {
                    $exprMatch = mod_flashcards_find_phrase_match($sentenceSurfaceTokens, $surfaceExprTokens, $maxGap);
                }
            }
            if ($exprMatch !== null && $candStart !== null && $candEnd !== null) {
                if ($exprMatch['start'] < $candStart || $exprMatch['end'] > $candEnd) {
                    $exprMatch = null;
                }
            }
            if ($exprMatch === null) {
                continue;
            }
            $mkey = core_text::strtolower($expression);
            if (isset($seenResolved[$mkey])) {
                continue;
            }
            $seenResolved[$mkey] = true;
            $meaning = '';
            if (!empty($match['meanings']) && is_array($match['meanings'])) {
                $meaning = trim((string)($match['meanings'][0] ?? ''));
            }
            // Variants are only orthographic variants provided by Ordbøkene itself (e.g. "løse, løyse").
            // Do not include inflected surface forms like "tar på seg".
            $variants = [];
            if (!empty($match['variants']) && is_array($match['variants'])) {
                $variants = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $match['variants'])))));
                if (count($variants) < 2) {
                    $variants = [];
                } else if (count($variants) > 4) {
                    $variants = array_slice($variants, 0, 4);
                }
            }
            // Ordbøkene sometimes returns inflected verb-phrase lemmas alongside the baseform (e.g. "ta på seg, tar på seg").
            // For UI purposes, `variants` must only represent orthographic alternatives, not conjugations.
            if (!empty($variants) && !empty($exprMatch['indices']) && is_array($exprMatch['indices'])) {
                $firstIndex = min(array_map('intval', $exprMatch['indices']));
                $firstPos = core_text::strtoupper((string)($posMap[$firstIndex] ?? ''));
                if (in_array($firstPos, ['VERB', 'AUX'], true)) {
                    $baseTokens = mod_flashcards_word_tokens($expression);
                    if (count($baseTokens) >= 2) {
                        $baseRest = array_slice($baseTokens, 1);
                        $baseFirst = $baseTokens[0] ?? '';
                        $variants = array_values(array_filter($variants, function(string $v) use ($baseFirst, $baseRest) {
                            $vTokens = mod_flashcards_word_tokens($v);
                            if (count($vTokens) !== (count($baseRest) + 1)) {
                                return true;
                            }
                            if (array_slice($vTokens, 1) !== $baseRest) {
                                return true;
                            }
                            $vFirst = $vTokens[0] ?? '';
                            if ($vFirst === '' || $vFirst === $baseFirst) {
                                return true;
                            }
                            // Drop common finite forms when only the verb token differs.
                            return !preg_match('~(r|te|de)$~u', $vFirst);
                        }));
                        if (count($variants) < 2) {
                            $variants = [];
                        }
                    }
                }
            }
            $confidence = 'low';
            $source = $match['source'] ?? 'pattern';
            if ($source === 'pattern' && in_array($candSource, ['R02','R04'], true)) {
                continue;
            }
            if ($source === 'cache' || $source === 'ordbokene') {
                $confidence = 'high';
            } else if ($source === 'examples') {
                $confidence = 'medium';
            } else if ($source === 'pattern' && in_array($candSource, ['R09','R10'], true)) {
                $confidence = 'medium';
            } else if ($source === 'pattern' && in_array($candSource, ['dep_reflexive','dep_reflexive_particle'], true)) {
                $confidence = 'medium';
            }
            $resolved[] = [
                'expression' => $expression,
                'translation' => '',
                'explanation' => $meaning,
                'examples' => $match['examples'] ?? [],
                // If Ordbøkene confirms the expression but the chosen meaning has no examples,
                // show the current sentence as a safe usage example instead of mixing wrong examples from other meanings.
                'examples_sentence' => ($source === 'ordbokene' && empty($match['examples'])) ? [$text] : [],
                'dictmeta' => $match['dictmeta'] ?? [],
                'source' => $source,
                'confidence' => $confidence,
                'variants' => $variants,
                'chosenMeaning' => $match['chosenMeaning'] ?? null,
                'meanings_all' => $match['meanings_all'] ?? null,
                'rule' => $candSource,
                'start' => $exprMatch['start'],
                'end' => $exprMatch['end'],
                'indices' => $exprMatch['indices'],
                'len' => count($exprTokens),
            ];
            if (count($resolved) >= 12) {
                break;
            }
        }
        $timing['expr_resolve'] = microtime(true) - $t0;
        if (!empty($resolved)) {
            $resolved = mod_flashcards_filter_expression_overlaps($resolved, $posMap);
        }
        if ($enrich && !empty($resolved)) {
            try {
                $helper = new \mod_flashcards\local\ai_helper();
                $t0 = microtime(true);
                $llm = $helper->suggest_sentence_expressions($text, $language, $userid, $debugAi);
                $timing['llm_confirm'] = microtime(true) - $t0;
                if ($debugAi && !empty($llm['_debug']) && is_array($llm['_debug'])) {
                    $aiDebug['llm_confirm'] = $llm['_debug'];
                    unset($llm['_debug']);
                }
                $suggested = is_array($llm['expressions'] ?? null) ? $llm['expressions'] : [];
                $suggestedList = [];
                foreach ($suggested as $item) {
                    $expr = trim((string)($item['expression'] ?? ''));
                    if ($expr !== '') {
                        $suggestedList[] = $expr;
                    }
                    if (count($suggestedList) >= 8) {
                        break;
                    }
                }
                $llmSuggested = $suggestedList;
                $suggestedMap = [];
                foreach ($suggested as $item) {
                    $expr = trim((string)($item['expression'] ?? ''));
                    if ($expr === '') {
                        continue;
                    }
                    $key = \mod_flashcards\local\expression_translation_repository::normalize_phrase($expr);
                    if ($key !== '') {
                        $suggestedMap[$key] = $item;
                    }
                }
                foreach ($resolved as $idx => $item) {
                    $source = $item['source'] ?? '';
                    if (in_array($source, ['ordbokene','cache','examples'], true)) {
                        continue;
                    }
                    $rule = trim((string)($item['rule'] ?? ''));
                    $strongRule = in_array($rule, [
                        'R09',
                        'R10',
                        'dep_reflexive',
                        'dep_reflexive_soft',
                        'dep_reflexive_particle',
                        'dep_reflexive_particle_soft',
                    ], true);
                    // Strong structural rules are explainable; don't require LLM overlap.
                    if ($strongRule) {
                        continue;
                    }
                    $expr = trim((string)($item['expression'] ?? ''));
                    if ($expr === '') {
                        $resolved[$idx]['confidence'] = 'low';
                        continue;
                    }
                    $key = \mod_flashcards\local\expression_translation_repository::normalize_phrase($expr);
                    if ($key === '' || !isset($suggestedMap[$key])) {
                        $resolved[$idx]['confidence'] = 'low';
                        continue;
                    }
                    $resolved[$idx]['confidence'] = 'medium';
                    $resolved[$idx]['source'] = 'llm';
                    $translation = trim((string)($suggestedMap[$key]['translation'] ?? ''));
                    $note = trim((string)($suggestedMap[$key]['note'] ?? ''));
                    $explanation = $translation;
                    if ($note !== '') {
                        $explanation = $translation !== '' ? ($translation . ' - ' . $note) : $note;
                    }
                    if ($translation !== '' && empty($resolved[$idx]['translation'])) {
                        $resolved[$idx]['translation'] = $translation;
                    }
                    if ($explanation !== '' && empty($resolved[$idx]['explanation'])) {
                        $resolved[$idx]['explanation'] = $explanation;
                    }
                }
                if ($debug && !empty($llm)) {
                    $dataDebugLlm = [
                        'confirm_model' => $llm['model'] ?? '',
                        'confirm_reasoning_effort' => $llm['reasoning_effort'] ?? '',
                        'suggested' => $suggestedList,
                    ];
                }
                $llmConfirmMeta = [
                    'model' => $llm['model'] ?? '',
                    'reasoning_effort' => $llm['reasoning_effort'] ?? '',
                ];
                if ($debugAi && !empty($llm['usage'])) {
                    $llmConfirmMeta['usage'] = $llm['usage'];
                }
            } catch (\Throwable $ex) {
                // Ignore LLM fallback errors, keep deterministic results.
            }
        }
        if (!empty($resolved)) {
            $resolved = array_values(array_filter($resolved, function($item) {
                return isset($item['confidence']) && $item['confidence'] !== 'low';
            }));
        }
        if (!empty($resolved)) {
            $resolved = mod_flashcards_filter_expression_overlaps($resolved, $posMap);
        }
        if (!empty($resolved)) {
            $resolved = array_values(array_map(function($item) {
                unset($item['start'], $item['end'], $item['indices'], $item['len']);
                return $item;
            }, $resolved));
        }
        // Expose the candidate list in the response (trimmed) to make the pipeline transparent without using debug=1.
        $publicCandidates = [];
        foreach (array_slice($cands, 0, 20) as $cand) {
            if (!is_array($cand)) {
                continue;
            }
            $publicCandidates[] = [
                'lemma' => (string)($cand['lemma'] ?? ''),
                'surface' => (string)($cand['surface'] ?? ''),
                'source' => (string)($cand['source'] ?? ''),
                'score' => (int)($cand['score'] ?? 0),
                'len' => (int)($cand['len'] ?? 0),
                'start' => isset($cand['start']) ? (int)$cand['start'] : null,
                'end' => isset($cand['end']) ? (int)$cand['end'] : null,
                'max_gap' => isset($cand['max_gap']) ? (int)$cand['max_gap'] : null,
            ];
        }
        $data = [
            'pipeline_rev' => MOD_FLASHCARDS_PIPELINE_REV,
            'text' => $text,
            'words' => $words,
            'expressions' => $resolved,
            'expr_candidates' => $publicCandidates,
            'llm_suggested' => $llmSuggested,
            'llm_confirm' => $llmConfirmMeta,
        ];
        if ($enrich) {
            try {
                $helper = new \mod_flashcards\local\ai_helper();
                $t0 = microtime(true);
                $enrichment = $helper->enrich_sentence_elements(
                    $text,
                    $words,
                    $resolved,
                    $language,
                    $userid,
                    $debugAi,
                    ['skip_sentence_translation' => $skipSentenceTranslation]
                );
                if ($debugAi && !empty($enrichment['_debug']) && is_array($enrichment['_debug'])) {
                    $aiDebug['llm_enrich'] = $enrichment['_debug'];
                    unset($enrichment['_debug']);
                }
                $data['enrichment'] = $enrichment;
                $timing['llm_enrich'] = microtime(true) - $t0;
            } catch (\Throwable $ex) {
                $data['enrichment'] = [
                    'sentenceTranslation' => '',
                    'elements' => [],
                    'error' => $ex->getMessage(),
                ];
            }
        }
        if (!empty($resolved)) {
            $phraseMap = [];
            if (!empty($data['enrichment']['elements']) && is_array($data['enrichment']['elements'])) {
                foreach ($data['enrichment']['elements'] as $element) {
                    if (!is_array($element) || ($element['type'] ?? '') !== 'phrase') {
                        continue;
                    }
                    $label = trim((string)($element['text'] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $key = \mod_flashcards\local\expression_translation_repository::normalize_phrase($label);
                    if ($key === '') {
                        continue;
                    }
                    $phraseMap[$key] = [
                        'translation' => trim((string)($element['translation'] ?? '')),
                        'note' => trim((string)($element['note'] ?? '')),
                    ];
                }
            }
            foreach ($resolved as $item) {
                $expr = trim((string)($item['expression'] ?? ''));
                if ($expr === '') {
                    continue;
                }
                $confidence = trim((string)($item['confidence'] ?? ''));
                if (!in_array($confidence, ['medium','high'], true)) {
                    continue;
                }
                $key = \mod_flashcards\local\expression_translation_repository::normalize_phrase($expr);
                $fromMap = $key !== '' && isset($phraseMap[$key]) ? $phraseMap[$key] : [];
                $translation = trim((string)($fromMap['translation'] ?? ''));
                if ($translation === '') {
                    $translation = trim((string)($item['translation'] ?? ''));
                }
                $note = trim((string)($fromMap['note'] ?? ''));
                if ($note === '') {
                    $note = trim((string)($item['explanation'] ?? ''));
                }
                $examples = !empty($item['examples']) && is_array($item['examples']) ? $item['examples'] : [];
                try {
                    \mod_flashcards\local\expression_translation_repository::upsert($expr, $language, [
                        'translation' => $translation,
                        'note' => $note,
                        'examples' => $examples,
                        'examples_trans' => [],
                        'source' => trim((string)($item['source'] ?? '')),
                        'confidence' => $confidence,
                    ]);
                } catch (\Throwable $ex) {
                    // Ignore caching errors to avoid breaking sentence_elements.
                }
            }
        }
        if ($debug) {
            $spacytokens = is_array($spacy['tokens'] ?? null) ? $spacy['tokens'] : [];
            $debugtokens = [];
            foreach (array_slice($spacytokens, 0, 60) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $debugtokens[] = [
                    'text' => (string)($t['text'] ?? ''),
                    'pos' => (string)($t['pos'] ?? ''),
                    'lemma' => (string)($t['lemma'] ?? ''),
                    'dep' => (string)($t['dep'] ?? ''),
                    'is_alpha' => !empty($t['is_alpha']),
                ];
            }
            $debugCandidates = [];
            foreach (array_slice($cands, 0, 10) as $cand) {
                if (!is_array($cand)) {
                    continue;
                }
                $debugCandidates[] = [
                    'lemma' => (string)($cand['lemma'] ?? ''),
                    'source' => (string)($cand['source'] ?? ''),
                    'score' => (int)($cand['score'] ?? 0),
                    'len' => (int)($cand['len'] ?? 0),
                    'start' => isset($cand['start']) ? (int)$cand['start'] : null,
                    'end' => isset($cand['end']) ? (int)$cand['end'] : null,
                    'max_gap' => isset($cand['max_gap']) ? (int)$cand['max_gap'] : null,
                ];
            }
            $timing['overall'] = microtime(true) - $overallstart;
            $data['debug'] = [
                'spacy' => [
                    'model' => $spacy['model'] ?? '',
                    'text' => $spacy['text'] ?? '',
                    'token_count' => is_array($spacy['tokens'] ?? null) ? count($spacy['tokens']) : 0,
                    'tokens' => $debugtokens,
                ],
                'expr_probe' => [
                    'verb_preps' => $verbPrepsMap,
                    'verb_any_prep' => array_keys($verbAnyPrepMap),
                ],
                'timing' => $timing,
                'stats' => [
                    'candidate_count' => $candRawCount ?? count($cands),
                    'candidate_count_used' => count($cands),
                    'lexical_count' => $lexicalCount ?? 0,
                    'resolved_count' => count($resolved),
                ],
                'expr_candidates' => $debugCandidates,
                'expr_lookup' => $debugLookupMap,
            ];
            if (!empty($dataDebugLlm)) {
                $data['debug']['llm'] = $dataDebugLlm;
            }
        }
        if ($debugAi && !empty($aiDebug)) {
            $data['ai_debug'] = $aiDebug;
        }
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'ai_answer_question':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }

        // Support both old format (prompt) and new format (messages[])
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            // New format: messages array for chat context
            $messages = $payload['messages'];
            if (empty($messages)) {
                throw new invalid_parameter_exception('Missing messages');
            }
        } else {
            // Legacy format: single prompt (for backwards compatibility)
            $prompt = trim($payload['prompt'] ?? '');
            if ($prompt === '') {
                throw new invalid_parameter_exception('Missing prompt');
            }
            // Convert to messages format
            $messages = [
                ['role' => 'user', 'content' => $prompt]
            ];
        }

        $language = clean_param($payload['language'] ?? 'en', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->answer_ai_question_with_context($messages, $language, $userid);

        // Note: usage from operation is already in $result, don't overwrite with snapshot
        echo json_encode($result);
        break;

    case 'ai_detect_constructions':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $focusword = trim($payload['focusWord'] ?? '');
        if ($fronttext === '' || $focusword === '') {
            throw new invalid_parameter_exception('Missing text or focus word');
        }
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);

        $helper = new \mod_flashcards\local\ai_helper();
        $result = $helper->detect_constructions($fronttext, $focusword, $language, $userid);

        echo json_encode($result);
        break;

    case 'ai_focus_helper':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $clickedword = mod_flashcards_normalize_token(trim($payload['focusWord'] ?? ''));
        if ($fronttext === '' || $clickedword === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $isfunctionword = mod_flashcards_is_function_word($clickedword);
        $language = clean_param($payload['language'] ?? 'no', PARAM_ALPHANUMEXT);
        $level = strtoupper(clean_param($payload['level'] ?? '', PARAM_ALPHANUMEXT));
        if (!in_array($level, ['A1', 'A2', 'B1'], true)) {
            $level = '';
        }
        $voiceid = clean_param($payload['voiceId'] ?? '', PARAM_ALPHANUMEXT);
        $orbokeneenabled = get_config('mod_flashcards', 'orbokene_enabled');
        $debugspacy = !empty($payload['debug']) || !empty($payload['debugSpacy']) || !empty($payload['debug_spacy']);
        $spacyPayload = null;
        $payloadText = trim((string)($payload['spacyText'] ?? ''));
        $payloadTokens = $payload['spacyTokens'] ?? null;
        if ($payloadText !== '' && $payloadText === $fronttext && is_array($payloadTokens)) {
            $cleanTokens = [];
            foreach ($payloadTokens as $tok) {
                if (!is_array($tok)) {
                    continue;
                }
                $text = trim((string)($tok['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $cleanTokens[] = [
                    'text' => $text,
                    'pos' => (string)($tok['pos'] ?? ''),
                    'lemma' => (string)($tok['lemma'] ?? ''),
                    'dep' => (string)($tok['dep'] ?? ''),
                    'is_alpha' => !empty($tok['is_alpha']),
                ];
            }
            if (!empty($cleanTokens)) {
                $spacyPayload = ['tokens' => $cleanTokens, 'source' => 'payload'];
            }
        }

        try {
            // Check if using reasoning model for focus task (may take longer)
            $config = get_config('mod_flashcards');
            $focusModel = trim($config->ai_focus_model ?? '');
            $isReasoningModel = false;
            if ($focusModel !== '') {
                $modelkey = core_text::strtolower(trim($focusModel));
                $isReasoningModel = (strpos($modelkey, '5-mini') !== false ||
                                   strpos($modelkey, '5-nano') !== false ||
                                   strpos($modelkey, 'o1-mini') !== false ||
                                   strpos($modelkey, 'o1-preview') !== false);
            }

            $helper = new \mod_flashcards\local\ai_helper();
            $openaiexpr = new \mod_flashcards\local\openai_client();
            $data = [
                'focusWord' => $clickedword,
                'focusBaseform' => $clickedword,
                'pos' => '',
                'gender' => '',
                'translation_lang' => $language,
                'definition' => '',
                'translation' => '',
                'analysis' => [],
                'collocations' => [],
                'examples' => [],
            ];
            $usedAi = false;
            $debugai = [];
            $spacyDebug = null;
            $spacyUsed = false;
            // Validate against ordbank: focus word/baseform must exist as a wordform and resolve data from ordbank.
            $focuscheck = mod_flashcards_normalize_token((string)($data['focusBaseform'] ?? $data['focusWord'] ?? $clickedword));
            // Guardrail: do not accept AI-proposed focus words that are not present in the learner sentence.
            // This prevents rare but costly mismatches like "for" -> "fôr" when both exist as valid words.
            if ($fronttext !== '' && $clickedword !== '') {
                $clickedlc = core_text::strtolower($clickedword);
                if ($focuscheck !== '' && $focuscheck !== $clickedlc) {
                    $focusInText = mod_flashcards_token_in_text($focuscheck, $fronttext);
                    $clickedInText = mod_flashcards_token_in_text($clickedlc, $fronttext);
                    if (!$focusInText && $clickedInText) {
                        $focuscheck = $clickedlc;
                    }
                }
            }
            // Try POS sequence decoding across the full sentence to pick the best candidate for the clicked token.
            $tokens = mod_flashcards_word_tokens($fronttext);
            $clickedIdx = array_search($clickedword, $tokens, true);
            $spacyPosMap = [];
            if ($clickedword !== '') {
                $cands = \mod_flashcards\local\ordbank_helper::find_candidates($clickedword);
                if (count($cands) > 1) {
                    $spacy = $spacyPayload ?: mod_flashcards_spacy_analyze($fronttext);
                    $spacyUsed = true;
                    if ($debugspacy && $spacyDebug === null) {
                        $spacyDebug = $spacy;
                    }
                    $spacyPosMap = mod_flashcards_spacy_pos_map($fronttext, $spacy);
                }
            }
            $ctx = mod_flashcards_context_from_sentence($fronttext, $clickedword);
            if ($clickedIdx !== false && isset($spacyPosMap[$clickedIdx])) {
                $ctx['spacy_pos'] = $spacyPosMap[$clickedIdx];
            }
            $dpSelected = null;
            if ($clickedIdx !== false) {
                $dpSelected = mod_flashcards_decode_pos($tokens, (int)$clickedIdx, $spacyPosMap);
            }
            if ($dpSelected && $clickedIdx !== false && isset($spacyPosMap[$clickedIdx])) {
                $dpPos = mod_flashcards_pos_from_tag((string)($dpSelected['tag'] ?? ''), (string)($dpSelected['ordklasse'] ?? ''));
                if ($dpPos !== $spacyPosMap[$clickedIdx]) {
                    $dpSelected = null;
                }
            }
            $ob = null;
            if ($dpSelected) {
                // Build a minimal ob from the decoded candidate.
                $selected = $dpSelected;
                $ob = [
                    'selected' => $selected,
                    'forms' => \mod_flashcards\local\ordbank_helper::fetch_forms((int)($selected['lemma_id'] ?? 0), (string)($selected['tag'] ?? '')),
                    'parts' => [],
                    'ambiguous' => false,
                ];
            } else {
                if ($focuscheck !== '') {
                    $ob = \mod_flashcards\local\ordbank_helper::analyze_token($focuscheck, $ctx);
                }
                // If helper didn't return, try clicked word as fallback.
                if ((!$ob || empty($ob['selected'])) && !empty($clickedword)) {
                    $ob = \mod_flashcards\local\ordbank_helper::analyze_token(core_text::strtolower($clickedword), $ctx);
                }
            }
            // If still nothing, try a direct lookup to confirm existence.
            if ((!$ob || empty($ob['selected']))) {
                // For multi-word expressions, skip strict ordbank validation.
                if (str_contains($focuscheck, ' ')) {
                    $ob = [
                        'selected' => [
                            'lemma_id' => 0,
                            'wordform' => $focuscheck,
                            'baseform' => $focuscheck,
                            'tag' => '',
                            'paradigme_id' => null,
                            'boy_nummer' => 0,
                            'ipa' => null,
                            'gender' => '',
                        ],
                            'forms' => [],
                        ];
                } else {
                    if (mod_flashcards_mysql_has_field('ordbank_fullform', 'oppslag_lc')) {
                        $exists = $DB->count_records_select('ordbank_fullform', 'OPPSLAG_LC = ?', [$focuscheck]);
                    } else {
                        $bin = mod_flashcards_mysql_bin_collation();
                        $where = 'LOWER(OPPSLAG)=?';
                        if ($bin) {
                            $where = 'LOWER(OPPSLAG) COLLATE ' . $bin . ' = ?';
                        }
                        $exists = $DB->count_records_select('ordbank_fullform', $where, [$focuscheck]);
                    }
                    if (!$exists) {
                        // Keep processing with minimal stub instead of erroring out.
                        $ob = [
                            'selected' => [
                                'lemma_id' => 0,
                                'wordform' => $focuscheck,
                                'baseform' => $focuscheck,
                                'tag' => '',
                                'paradigme_id' => null,
                                'boy_nummer' => 0,
                                'ipa' => null,
                                'gender' => '',
                            ],
                            'forms' => [],
                        ];
                    } else {
                        // Build a minimal selected from first match
                        if (mod_flashcards_mysql_has_field('ordbank_fullform', 'oppslag_lc')) {
                            $first = $DB->get_record_sql("SELECT * FROM {ordbank_fullform} WHERE OPPSLAG_LC = :w LIMIT 1", ['w' => $focuscheck]);
                        } else {
                            $bin = mod_flashcards_mysql_bin_collation();
                            $where2 = 'LOWER(OPPSLAG)=:w';
                            if ($bin) {
                                $where2 = 'LOWER(OPPSLAG) COLLATE ' . $bin . ' = :w';
                            }
                            $first = $DB->get_record_sql("SELECT * FROM {ordbank_fullform} WHERE {$where2} LIMIT 1", ['w' => $focuscheck]);
                        }
                        $ob = [
                            'selected' => [
                                'lemma_id' => (int)$first->lemma_id,
                                'wordform' => $first->oppslag,
                                'baseform' => null,
                                'tag' => $first->tag,
                                'paradigme_id' => $first->paradigme_id,
                                'boy_nummer' => (int)$first->boy_nummer,
                                'ipa' => null,
                                'gender' => '',
                            ],
                            'forms' => [],
                        ];
                    }
                }
            }
            if ((!$ob || empty($ob['selected'])) && $openaiexpr->is_enabled()) {
                $data = $helper->process_focus_request($userid, $fronttext, $clickedword, [
                    'language' => $language,
                    'level' => $level,
                    'voice' => $voiceid ?: null,
                ]);
                $usedAi = true;
                $focuscheck = mod_flashcards_normalize_token((string)($data['focusBaseform'] ?? $data['focusWord'] ?? $clickedword));
                if ($fronttext !== '' && $clickedword !== '') {
                    $clickedlc = core_text::strtolower($clickedword);
                    if ($focuscheck !== '' && $focuscheck !== $clickedlc) {
                        $focusInText = mod_flashcards_token_in_text($focuscheck, $fronttext);
                        $clickedInText = mod_flashcards_token_in_text($clickedlc, $fronttext);
                        if (!$focusInText && $clickedInText) {
                            $focuscheck = $clickedlc;
                        }
                    }
                }
                $ob = \mod_flashcards\local\ordbank_helper::analyze_token($focuscheck, $ctx);
                if ((!$ob || empty($ob['selected'])) && !empty($clickedword)) {
                    $ob = \mod_flashcards\local\ordbank_helper::analyze_token(core_text::strtolower($clickedword), $ctx);
                }
            }
            // Override AI outputs with ordbank-confirmed data to avoid made-up words/IPA.
            $selected = $ob['selected'];
            $data['focusWord'] = $selected['baseform'] ?? $selected['wordform'] ?? $focuscheck;
            $data['focusBaseform'] = $selected['baseform'] ?? $data['focusWord'];
            $taglower = core_text::strtolower($selected['tag'] ?? '');
            $aiPos = core_text::strtolower($data['pos'] ?? '');
            $ordbankpos = '';
            if ($taglower !== '') {
                if (str_contains($taglower, 'verb')) {
                    $ordbankpos = 'verb';
                } else if (str_contains($taglower, 'subst')) {
                    $ordbankpos = 'substantiv';
                } else if (str_contains($taglower, 'adj')) {
                    $ordbankpos = 'adjektiv';
                }
            }
            if ($ordbankpos !== '') {
                $data['pos'] = $ordbankpos;
                // If AI POS differed, drop AI meaning/examples to avoid noun/verb drift.
                if ($aiPos && $aiPos !== $ordbankpos) {
                    $data['definition'] = '';
                    $data['analysis'] = [];
                    $data['examples'] = [];
                    $data['collocations'] = [];
                }
            }
            // Avoid injecting verb paradigms when AI decided this is not a verb (e.g., phrases like "være klar over").
            $allowforms = !($aiPos && $aiPos !== 'verb' && $ordbankpos === 'verb');
            if ($isbuiltin) {
                $allowforms = false;
            }
            if (!$data['pos']) {
                if (str_contains($taglower, 'verb')) {
                    $data['pos'] = 'verb';
                } else if (str_contains($taglower, 'subst')) {
                    $data['pos'] = 'substantiv';
                }
            }
            if (!empty($selected['ipa'])) {
                $data['transcription'] = $selected['ipa'];
            }
            if (!empty($selected['gender'])) {
                $data['gender'] = $selected['gender'];
            }
            if ($isbuiltin) {
                // Do not carry over pronunciation/forms for function words.
                $data['transcription'] = '';
                $selected['ipa'] = null;
            }
            $data['forms'] = $allowforms ? ($ob['forms'] ?? []) : [];
            if ($allowforms && !$isbuiltin && (empty($data['forms']) || $data['forms'] === []) && !empty($selected['lemma_id'])) {
                $data['forms'] = \mod_flashcards\local\ordbank_helper::fetch_forms((int)$selected['lemma_id'], (string)($selected['tag'] ?? ''));
            }
            if (!empty($ob['parts'])) {
                $data['parts'] = $ob['parts'];
            }
            // If still no forms and we have a baseform, try ordbank by baseform.
            // Important: only do this when POS/ordbank say it's a verb, to avoid pulling verb paradigms for adverbs like "for".
            if ($allowforms && (empty($data['forms']) || $data['forms'] === []) && !empty($data['focusBaseform'])
                && ($data['pos'] === 'verb' || $ordbankpos === 'verb')) {
                $tmp = \mod_flashcards\local\ordbank_helper::analyze_token(core_text::strtolower($data['focusBaseform']), []);
                if (!empty($tmp['forms'])) {
                    $data['forms'] = $tmp['forms'];
                }
                if (empty($data['parts']) && !empty($tmp['parts'])) {
                    $data['parts'] = $tmp['parts'];
                }
            }
            if (empty($data['parts']) && !empty($data['focusWord'])) {
                $data['parts'] = [$data['focusWord']];
            }
            // Always try Ordbokene: resolve expression/meaning first, then regenerate translation/definition/examples for that expression.
            $debugai = [];
            $resolvedExpr = null;
            $skipordbokene = false;
            $exprAutoSelected = false;
            $builtin = mod_flashcards_builtin_function_word($clickedword);
            $poslower = core_text::strtolower($data['pos'] ?? '');
            $functionpos = ['adv', 'adverb', 'prep', 'preposisjon', 'konj', 'konjunksjon', 'pron', 'pronomen', 'det', 'determiner', 'inf', 'part', 'partikkel'];
            $isbuiltin = false;
            if ($isfunctionword && $builtin) {
                $data['ordbokene'] = $builtin;
                $surface = $clickedword ?: ($data['focusWord'] ?? $builtin['expression']);
                $data['focusExpression'] = $surface;
                $data['expressions'] = [$surface];
                $data['focusWord'] = $surface;
                $data['focusBaseform'] = $surface;
                $data['pos'] = 'adverb';
                // Minimal selected stub to avoid leaking forms from other paths.
                $selected = [
                    'lemma_id' => 0,
                    'wordform' => $surface,
                    'baseform' => $surface,
                    'tag' => 'adv',
                    'paradigme_id' => null,
                    'boy_nummer' => 0,
                    'ipa' => null,
                    'gender' => '',
                ];
                $ob = [
                    'selected' => $selected,
                    'forms' => [],
                    'parts' => [$surface],
                    'ambiguous' => false,
                ];
                $data['forms'] = [];
                $data['parts'] = [$surface];
                $isbuiltin = true;
                if (empty($data['definition']) && !empty($builtin['meanings'][1])) {
                    $data['definition'] = $builtin['meanings'][1];
                }
                if (empty($data['translation'])) {
                    $data['translation'] = $builtin['meanings'][1] ?? ($builtin['meanings'][0] ?? '');
                }
                $skipordbokene = true;
            }
            // Pre-compute wc from POS/tag and short-circuit Ordbokene for verbs to avoid noun leakage.
            $wc = mod_flashcards_ordbokene_wc_from_pos($data['pos'] ?? '');
            if (!empty($selected['tag'])) {
                $taglower = core_text::strtolower((string)$selected['tag']);
                if (str_contains($taglower, 'verb')) {
                    $wc = 'VERB';
                } else if (str_contains($taglower, 'subst')) {
                    $wc = 'NOUN';
                } else if (str_contains($taglower, 'adj')) {
                    $wc = 'ADJ';
                } else if (str_contains($taglower, 'adv')) {
                    $wc = 'ADV';
                }
            }
            if ($wc === 'PREP') {
                $wc = '';
            }
            $spacyExprs = [];
            if ($orbokeneenabled) {
                $spacyUsed = true;
                $spacyExprs = mod_flashcards_spacy_expression_candidates($fronttext, $clickedword, $spacyDebug, $spacyPayload);
            }
            if ($wc === 'VERB') {
                $skipordbokene = true;
                $debugai['ordbokene'] = ['expression' => null];
            }
            $lang = ($language === 'nn') ? 'nn' : (($language === 'nb' || $language === 'no') ? 'bm' : 'begge');
            if ($orbokeneenabled && !$skipordbokene) {
                $lang = ($language === 'nn') ? 'nn' : (($language === 'nb' || $language === 'no') ? 'bm' : 'begge');
                $lookupWord = $data['focusBaseform'] ?? $data['focusWord'] ?? $clickedword;
                $spacyMatches = [];
                foreach ($spacyExprs as $expr) {
                    $spacyResolved = mod_flashcards_lookup_or_search_expression($expr, $lang);
                    if (!empty($spacyResolved)) {
                        $expression = mod_flashcards_normalize_infinitive($spacyResolved['baseform'] ?? $expr);
                        if (!isset($spacyMatches[$expression])) {
                            $meanings = $spacyResolved['meanings'] ?? [];
                            $firstMeaning = $meanings[0] ?? '';
                            $spacyMatches[$expression] = [
                                'expression' => $expression,
                                'translation' => $firstMeaning,
                                'explanation' => $firstMeaning,
                                'examples' => $spacyResolved['examples'] ?? [],
                                'forms' => $spacyResolved['forms'] ?? [],
                                'dictmeta' => $spacyResolved['dictmeta'] ?? [],
                            ];
                        }
                    }
                }
                if (count($spacyMatches) > 1) {
                    $data['expressionNeedsConfirmation'] = true;
                    $data['expressionSuggestions'] = array_values($spacyMatches);
                    $debugai['ordbokene']['spacyExpressions'] = array_keys($spacyMatches);
                    $debugai['ordbokene']['expressionSource'] = 'spacy_multi';
                } else if (count($spacyMatches) === 1) {
                    $only = array_values($spacyMatches)[0];
                    $resolvedExpr = [
                        'expression' => $only['expression'],
                        'meanings' => $only['translation'] ? [$only['translation']] : [],
                        'examples' => $only['examples'] ?? [],
                        'forms' => $only['forms'] ?? [],
                        'dictmeta' => $only['dictmeta'] ?? [],
                        'source' => 'ordbokene',
                        'citation' => '«Korleis». I: Bokmålsordboka og Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).',
                    ];
                    $debugai['ordbokene']['expressionSource'] = 'spacy_single';
                }
                $spacyAutoExpr = '';
                if (!$resolvedExpr && empty($data['expressionNeedsConfirmation']) && empty($spacyMatches) && !empty($spacyExprs)) {
                    $spacyTokens = is_array($spacyDebug['tokens'] ?? null) ? $spacyDebug['tokens'] : [];
                    $verbLemmas = [];
                    $adpLemmas = [];
                    foreach ($spacyTokens as $t) {
                        if (empty($t['is_alpha'])) {
                            continue;
                        }
                        $lemma = core_text::strtolower((string)($t['lemma'] ?? $t['text'] ?? ''));
                        $pos = mod_flashcards_spacy_pos_to_coarse((string)($t['pos'] ?? ''));
                        if (in_array($pos, ['VERB', 'AUX'], true) && $lemma !== '') {
                            $verbLemmas[$lemma] = true;
                        } else if (in_array($pos, ['ADP', 'PART'], true) && $lemma !== '') {
                            $adpLemmas[$lemma] = true;
                        }
                    }
                    $ranked = [];
                    foreach ($spacyExprs as $expr) {
                        $expr = trim((string)$expr);
                        if ($expr === '') {
                            continue;
                        }
                        $parts = array_values(array_filter(preg_split('/\\s+/u', $expr)));
                        $len = count($parts);
                        if ($len < 2) {
                            continue;
                        }
                        $adpCount = 0;
                        foreach ($parts as $part) {
                            $pl = core_text::strtolower($part);
                            if (isset($adpLemmas[$pl])) {
                                $adpCount++;
                            }
                        }
                        $score = 0;
                        if ($len >= 3) {
                            $score += 2;
                        }
                        if ($len >= 4) {
                            $score += 1;
                        }
                        $first = core_text::strtolower($parts[0] ?? '');
                        $last = core_text::strtolower($parts[$len - 1] ?? '');
                        if ($first !== '' && isset($verbLemmas[$first])) {
                            $score += 2;
                        }
                        if ($adpCount >= 2) {
                            $score += 2;
                        } else if ($adpCount >= 1) {
                            $score += 1;
                        }
                        if ($last !== '' && isset($adpLemmas[$last])) {
                            $score += 1;
                        }
                        if ($clickedword !== '' && in_array(core_text::strtolower($clickedword), array_map('core_text::strtolower', $parts), true)) {
                            $score += 1;
                        }
                        $ranked[] = ['expr' => $expr, 'score' => $score, 'len' => $len];
                    }
                    if (!empty($ranked)) {
                        usort($ranked, function($a, $b){
                            if ($a['score'] === $b['score']) {
                                return $b['len'] <=> $a['len'];
                            }
                            return $b['score'] <=> $a['score'];
                        });
                        $top = $ranked[0];
                        $second = $ranked[1] ?? null;
                        $gap = $second ? ($top['score'] - $second['score']) : $top['score'];
                        if ($top['score'] >= 4 && $gap >= 2) {
                            $spacyAutoExpr = $top['expr'];
                        }
                    }
                }
                if ($spacyAutoExpr !== '') {
                    $data['focusExpression'] = $spacyAutoExpr;
                    $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$spacyAutoExpr])));
                    $data['focusWord'] = $spacyAutoExpr;
                    $data['pos'] = 'phrase';
                    $data['parts'] = [$spacyAutoExpr];
                    $exprAutoSelected = true;
                    $debugai['ordbokene']['expressionSource'] = 'spacy_auto';
                } else if (empty($spacyMatches) && empty($resolvedExpr) && !empty($spacyExprs)) {
                    $exprs = array_values(array_unique(array_filter($spacyExprs)));
                    if (!empty($exprs)) {
                        $data['expressionNeedsConfirmation'] = true;
                        $data['expressionSuggestions'] = array_map(function($expr){
                            return [
                                'expression' => $expr,
                                'translation' => '',
                                'explanation' => '',
                                'examples' => [],
                                'forms' => [],
                                'dictmeta' => [],
                            ];
                        }, $exprs);
                        $debugai['ordbokene']['spacyExpressions'] = $exprs;
                        $debugai['ordbokene']['expressionSource'] = 'spacy_unconfirmed';
                    }
                }
                if (!$resolvedExpr && empty($data['expressionNeedsConfirmation']) && $spacyAutoExpr === '') {
                    $resolvedExpr = mod_flashcards_resolve_ordbokene_expression($fronttext, $clickedword, $lookupWord, $lang);
                    if ($resolvedExpr) {
                        $debugai['ordbokene']['expressionSource'] = 'resolve_ordbokene';
                    }
                }
                if ($skipordbokene) {
                    $entries = [];
                } else {
                $wc = mod_flashcards_ordbokene_wc_from_pos($data['pos'] ?? '');
                if (!empty($selected['tag'])) {
                    $taglower = core_text::strtolower((string)$selected['tag']);
                    if (str_contains($taglower, 'verb')) {
                        $wc = 'VERB';
                    } else if (str_contains($taglower, 'subst')) {
                        $wc = 'NOUN';
                    } else if (str_contains($taglower, 'adj')) {
                        $wc = 'ADJ';
                    } else if (str_contains($taglower, 'adv')) {
                        $wc = 'ADV';
                    }
                }
                // Ordbøkene POS filter ("wc") is useful, but PREP in particular is unreliable and often returns nothing.
                if ($wc === 'PREP') {
                    $wc = '';
                }
                // For verbs, avoid pulling noun-only entries; skip Ordbøkene when wc=VERB.
                if ($wc === 'VERB') {
                    $entries = [];
                } else {
                    $entries = \mod_flashcards\local\ordbokene_client::lookup_all($lookupWord, $lang, 6, $wc);
                }
                if ($resolvedExpr && !empty($resolvedExpr['expression'])) {
                    $entries = array_values(array_merge([[
                        'baseform' => $resolvedExpr['expression'],
                        'meanings' => $resolvedExpr['meanings'] ?? [],
                        'examples' => $resolvedExpr['examples'] ?? [],
                        'forms' => $resolvedExpr['forms'] ?? [],
                        'dictmeta' => $resolvedExpr['dictmeta'] ?? [],
                        'source' => 'ordbokene',
                    ]], $entries));
                }
                $chosen = null;
                if (!empty($entries)) {
                    // Heuristic cleanup: drop clearly irrelevant trade/rail meanings for ADV "for".
                    if ($wc === 'ADV' && core_text::strtolower($lookupWord) === 'for') {
                        $entries = array_values(array_map(function($e){
                            if (isset($e['meanings']) && is_array($e['meanings'])) {
                                $e['meanings'] = array_values(array_filter($e['meanings'], function($m){
                                    $m = core_text::strtolower((string)$m);
                                    return !(
                                        str_contains($m, 'handel') ||
                                        str_contains($m, 'jernbane') ||
                                        str_contains($m, 'kostnad') ||
                                        str_contains($m, 'kjøper') ||
                                        str_contains($m, 'frakt') ||
                                        str_contains($m, 'leveres')
                                    );
                                }));
                            }
                            return $e;
                        }, $entries));
                        $entries = array_values(array_filter($entries, function($e){
                            return !empty($e['meanings']);
                        }));
                    }
                    $deflist = [];
                    $map = [];
                    foreach ($entries as $ei => $entry) {
                        $meanings = [];
                        if (!empty($entry['meanings']) && is_array($entry['meanings'])) {
                            $meanings = $entry['meanings'];
                        } else if (!empty($entry['definition'])) {
                            $meanings = [$entry['definition']];
                        }
                        foreach ($meanings as $mi => $def) {
                            $def = trim((string)$def);
                            if ($def === '') {
                                continue;
                            }
                            $deflist[] = $def;
                            $map[] = ['entry' => $ei, 'meaning' => $mi];
                        }
                    }
                    if (count($deflist) > 1) {
                        $best = $helper->choose_best_definition($fronttext, $lookupWord, $deflist, $language, $userid);
                        if ($best && isset($best['index']) && isset($map[$best['index']])) {
                            $chosen = $map[$best['index']];
                        }
                    }
                    if ($chosen === null && !empty($entries)) {
                        $chosen = ['entry' => 0, 'meaning' => 0];
                }
                if ($chosen !== null) {
                    $entry = $entries[$chosen['entry']];
                    $meaning = $entry['meanings'][$chosen['meaning']] ?? ($entry['meanings'][0] ?? '');
                    $expression = $entry['baseform'] ?? $lookupWord;
                    $examples = $entry['examples'] ?? [];
                    if (!empty($entry['senses']) && is_array($entry['senses'])) {
                        $sense = $entry['senses'][$chosen['meaning']] ?? null;
                        if (is_array($sense) && !empty($sense['examples']) && is_array($sense['examples'])) {
                            $examples = $sense['examples'];
                        }
                    }
                        $variants = [];
                        $variantMap = [];
                        $addVariant = function(string $value) use (&$variants, &$variantMap) {
                            $value = trim($value);
                            if ($value === '') {
                                return;
                            }
                            $key = core_text::strtolower($value);
                            if (isset($variantMap[$key])) {
                                return;
                            }
                            $variantMap[$key] = true;
                            $variants[] = $value;
                        };
                        $addVariant((string)($data['focusExpression'] ?? ''));
                        $addVariant((string)$expression);
                        $addVariant((string)($entry['baseform'] ?? ''));
                        $addVariant((string)($entry['expression'] ?? ''));
                        if (count($variants) < 2) {
                            $variants = [];
                        } else if (count($variants) > 4) {
                            $variants = array_slice($variants, 0, 4);
                        }
                        $data['ordbokene'] = [
                            'expression' => $expression,
                            'meanings' => $entry['meanings'] ?? [],
                            'examples' => $examples,
                            'forms' => $entry['forms'] ?? [],
                            'dictmeta' => $entry['dictmeta'] ?? [],
                            'senses' => $entry['senses'] ?? [],
                            'source' => 'ordbokene',
                            'chosenMeaning' => $chosen['meaning'],
                            'chosenMeaningText' => $meaning,
                            'url' => $entry['dictmeta']['url'] ?? '',
                            'variants' => $variants,
                            'citation' => sprintf('"%s". I: Nynorskordboka. Sprakradet og Universitetet i Bergen. https://ordbokene.no (henta %s).', $expression, date('d.m.Y')),
                        ];
                        // Do not override focusWord/expression chosen from Ordbank.
                        $debugai['ordbokene'] = ['expression' => $expression, 'url' => $entry['dictmeta']['url'] ?? '', 'chosen' => $chosen];
                        $seed = !empty($examples) ? $examples : [];
                        if ($openaiexpr->is_enabled()) {
                            $gen = $openaiexpr->generate_expression_content($expression, $meaning, $seed, $fronttext, $language, $level);
                            if (!empty($gen['translation'])) {
                                $data['translation'] = $gen['translation'];
                            }
                            if (!empty($gen['definition'])) {
                                $data['definition'] = $gen['definition'];
                            }
                            if (!empty($gen['examples'])) {
                                $data['examples'] = $gen['examples'];
                            }
                        }
                        if (!empty($meaning)) {
                            $data['analysis'] = [
                                [
                                    'text' => $expression,
                                    'translation' => $meaning,
                                ],
                            ];
                        }
                    } else {
                        unset($data['ordbokene']);
                    }
                }
                }
            }
            if (!isset($data['ordbokene']) && !$skipordbokene) {
                $fallbackExpr = $resolvedExpr ?: mod_flashcards_resolve_ordbokene_expression($fronttext, $clickedword, $data['focusBaseform'] ?? '', $lang);
                if ($fallbackExpr) {
                    $data['ordbokene'] = $fallbackExpr;
                    $data['focusExpression'] = $fallbackExpr['expression'];
                    $data['focusWord'] = $fallbackExpr['expression'];
                    $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$fallbackExpr['expression']])));
                    $meaning = !empty($fallbackExpr['meanings'][0]) ? $fallbackExpr['meanings'][0] : '';
                    $seed = !empty($fallbackExpr['examples']) ? $fallbackExpr['examples'] : [];
                    if ($openaiexpr->is_enabled()) {
                        $gen = $openaiexpr->generate_expression_content($fallbackExpr['expression'], $meaning, $seed, $fronttext, $language, $level);
                        if (!empty($gen['translation'])) {
                            $data['translation'] = $gen['translation'];
                        }
                        if (!empty($gen['definition'])) {
                            $data['definition'] = $gen['definition'];
                        }
                        if (!empty($gen['examples'])) {
                            $data['examples'] = $gen['examples'];
                        }
                    }
                    if (!empty($meaning)) {
                        $data['analysis'] = [
                            [
                                'text' => $fallbackExpr['expression'],
                                'translation' => $meaning,
                            ],
                        ];
                    }
                    if (empty($data['ordbokene']['citation'])) {
                        $data['ordbokene']['citation'] = sprintf('"%s". I: Nynorskordboka. Sprakradet og Universitetet i Bergen. https://ordbokene.no (henta %s).', $fallbackExpr['expression'], date('d.m.Y'));
                    }
                    $debugai['ordbokene'] = ['expression' => $fallbackExpr['expression'], 'url' => $fallbackExpr['dictmeta']['url'] ?? ''];
                } else {
                    $debugai['ordbokene'] = ['expression' => null];
                }
            }
            if (!$usedAi && empty($data['expressionNeedsConfirmation'])) {
                $hasTranslation = trim((string)($data['translation'] ?? '')) !== '';
                $hasDefinition = trim((string)($data['definition'] ?? '')) !== '';
                $hasExamples = !empty($data['examples']) && is_array($data['examples']);
                if (!$hasTranslation && !$hasDefinition && !$hasExamples && $openaiexpr->is_enabled()) {
                    $expr = (string)($data['focusExpression'] ?? $data['focusWord'] ?? $data['focusBaseform'] ?? $clickedword);
                    $meaning = (string)($data['definition'] ?? '');
                    $gen = $openaiexpr->generate_expression_content($expr, $meaning, [], $fronttext, $language, $level);
                    if (!empty($gen['translation'])) {
                        $data['translation'] = $gen['translation'];
                    }
                    if (!empty($gen['definition'])) {
                        $data['definition'] = $gen['definition'];
                    }
                    if (!empty($gen['examples'])) {
                        $data['examples'] = $gen['examples'];
                    }
                    if (!empty($gen['definition'])) {
                        $data['analysis'] = [
                            [
                                'text' => $expr,
                                'translation' => $gen['definition'],
                            ],
                        ];
                    }
                }
            }
            // Always prefer Ordbokene verb forms to mirror dictionary table.
            if (!empty($data['ordbokene']) && ($data['ordbokene']['source'] ?? '') !== 'builtin') {
                $wc = mod_flashcards_ordbokene_wc_from_pos($data['pos'] ?? '');
                if (!empty($selected['tag'])) {
                    $taglower = core_text::strtolower((string)$selected['tag']);
                    if (str_contains($taglower, 'verb')) {
                        $wc = 'VERB';
                    } else if (str_contains($taglower, 'subst')) {
                        $wc = 'NOUN';
                    }
                }
                $baseLookup = \mod_flashcards\local\ordbokene_client::lookup($data['focusBaseform'] ?? $lookupWord, $lang, $wc);
                if (!empty($baseLookup['forms'])) {
                    $data['forms'] = $baseLookup['forms'];
                }
            }
            // Ensure focus baseform stays on the lemma (not the expression surface form).
            if (!$isbuiltin && !empty($selected['baseform'])) {
                $data['focusBaseform'] = $selected['baseform'];
            }
            if ($isbuiltin) {
                $data['transcription'] = '';
            }
            // Final form cleanup for verbs to avoid noisy variants.
            $data['forms'] = mod_flashcards_prune_verb_forms($data['forms'] ?? []);
            if (empty($data['gender']) && !empty($selected['gender'])) {
                $data['gender'] = $selected['gender'];
            }
            // Ensure compound parts are present: split by lemma/baseform if missing.
            $hasLeddanalyseParts = false;
            $leddanalyseParts = [];
            if (!empty($selected['lemma_id'])) {
                $partsFromLeddanalyse = false;
                $leddanalyseParts = \mod_flashcards\local\ordbank_helper::split_compound(
                    (int)$selected['lemma_id'],
                    $selected['baseform'] ?? ($selected['wordform'] ?? $clickedword),
                    $partsFromLeddanalyse
                );
                if (
                    !empty($partsFromLeddanalyse)
                    && is_array($leddanalyseParts)
                    && count($leddanalyseParts) > 1
                ) {
                    $hasLeddanalyseParts = true;
                }
            }
            if ((empty($data['parts']) || $data['parts'] === []) && !empty($leddanalyseParts)) {
                $data['parts'] = $leddanalyseParts;
            }
            // If Ordbokene was skipped due to VERB, still surface spaCy expression candidates for manual choice.
            if ($orbokeneenabled && $skipordbokene && empty($data['expressionNeedsConfirmation']) && !$exprAutoSelected && !empty($spacyExprs)) {
                $spacyMatches = [];
                foreach ($spacyExprs as $expr) {
                    $spacyResolved = mod_flashcards_lookup_or_search_expression($expr, $lang);
                    if (!empty($spacyResolved)) {
                        $expression = mod_flashcards_normalize_infinitive($spacyResolved['baseform'] ?? $expr);
                        if (!isset($spacyMatches[$expression])) {
                            $meanings = $spacyResolved['meanings'] ?? [];
                            $firstMeaning = $meanings[0] ?? '';
                            $spacyMatches[$expression] = [
                                'expression' => $expression,
                                'translation' => $firstMeaning,
                                'explanation' => $firstMeaning,
                                'examples' => $spacyResolved['examples'] ?? [],
                                'forms' => $spacyResolved['forms'] ?? [],
                                'dictmeta' => $spacyResolved['dictmeta'] ?? [],
                            ];
                        }
                    }
                }
                if (!empty($spacyMatches)) {
                    $data['expressionNeedsConfirmation'] = true;
                    $data['expressionSuggestions'] = array_values($spacyMatches);
                    $debugai['ordbokene']['spacyExpressions'] = array_keys($spacyMatches);
                    $debugai['ordbokene']['expressionSource'] = 'spacy_fallback';
                } else {
                    $exprs = array_values(array_unique(array_filter($spacyExprs)));
                    if (!empty($exprs)) {
                        $data['expressionNeedsConfirmation'] = true;
                        $data['expressionSuggestions'] = array_map(function($expr){
                            return ['expression' => $expr];
                        }, $exprs);
                        $debugai['ordbokene']['spacyExpressions'] = $exprs;
                        $debugai['ordbokene']['expressionSource'] = 'spacy_unconfirmed';
                    }
                }
            }
            // Regenerate front audio after final example selection to keep TTS in sync with UI.
            try {
                $tts = new \mod_flashcards\local\tts_service();
                if ($tts->is_enabled()) {
                    $frontAudioText = mod_flashcards_choose_front_audio_text($fronttext, $data['examples'] ?? []);
                    if ($frontAudioText !== '') {
                        $frontAudio = $tts->synthesize($userid, $frontAudioText, [
                            'voice' => $voiceid ?: null,
                            'label' => 'front',
                        ]);
                        if (!isset($data['audio']) || !is_array($data['audio'])) {
                            $data['audio'] = [];
                        }
                        $data['audio']['front'] = $frontAudio;
                    }
                }
            } catch (\Throwable $e) {
                if (!isset($data['errors']) || !is_array($data['errors'])) {
                    $data['errors'] = [];
                }
                $data['errors']['tts_front'] = $e->getMessage();
            }
            // Ensure focus audio exists for the chosen focus word/expression.
            try {
                $tts = $tts ?? new \mod_flashcards\local\tts_service();
                if ($tts->is_enabled()) {
                    $focusAudioText = trim((string)($data['focusExpression'] ?? $data['focusWord'] ?? $data['focusBaseform'] ?? $clickedword));
                    if ($focusAudioText !== '') {
                        if (!preg_match('/[.!?]$/', $focusAudioText)) {
                            $focusAudioText .= '.';
                        }
                        if (!isset($data['audio']) || !is_array($data['audio'])) {
                            $data['audio'] = [];
                        }
                        if (empty($data['audio']['focus'])) {
                            $data['audio']['focus'] = $tts->synthesize($userid, $focusAudioText, [
                                'voice' => $voiceid ?: null,
                                'label' => 'focus',
                            ]);
                            $data['audioFocusText'] = $focusAudioText;
                            if (!empty($data['audio']['focus']['provider_decision'])) {
                                $data['audioProviderDecision'] = $data['audio']['focus']['provider_decision'];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!isset($data['errors']) || !is_array($data['errors'])) {
                    $data['errors'] = [];
                }
                $data['errors']['tts_focus'] = $e->getMessage();
            }
            // Normalize compound parts to clean array for UI, and if only one chunk, try to re-split via leddanalyse.
            if (!empty($data['parts']) && is_array($data['parts'])) {
                $data['parts'] = array_values(array_filter($data['parts'], function($v){
                    return is_string($v) && trim($v) !== '';
                }));
                if (count($data['parts']) === 1 && !empty($selected['lemma_id'])) {
                    $repartsFromLeddanalyse = false;
                    $reparts = \mod_flashcards\local\ordbank_helper::split_compound(
                        (int)$selected['lemma_id'],
                        $selected['baseform'] ?? ($selected['wordform'] ?? $clickedword),
                        $repartsFromLeddanalyse
                    );
                    if (!empty($reparts) && is_array($reparts) && count($reparts) > 1) {
                        $data['parts'] = array_values($reparts);
                        if (!empty($repartsFromLeddanalyse)) {
                            $hasLeddanalyseParts = true;
                        }
                    }
                }
            }
            $data['partsFromLeddanalyse'] = $hasLeddanalyseParts;
            $exprForCache = trim((string)($data['focusExpression'] ?? $data['focusWord'] ?? ''));
            if ($exprForCache !== '') {
                $hasTranslation = trim((string)($data['translation'] ?? '')) !== '';
                $hasDefinition = trim((string)($data['definition'] ?? '')) !== '';
                $hasExamples = !empty($data['examples']) && is_array($data['examples']);
                if ($hasTranslation || $hasDefinition || $hasExamples) {
                    $split = \mod_flashcards\local\expression_translation_repository::split_examples_with_translations(
                        $hasExamples ? $data['examples'] : []
                    );
                    $examplesNo = $split['examples'] ?? [];
                    $examplesTrans = $split['translations'] ?? [];
                    $confidence = !empty($data['ordbokene']['source']) ? 'high' : 'medium';
                    try {
                        \mod_flashcards\local\expression_translation_repository::upsert($exprForCache, $language, [
                            'translation' => trim((string)($data['translation'] ?? '')),
                            'note' => trim((string)($data['definition'] ?? '')),
                            'examples' => $examplesNo,
                            'examples_trans' => $examplesTrans,
                            'source' => trim((string)($data['ordbokene']['source'] ?? 'ai')),
                            'confidence' => $confidence,
                        ]);
                    } catch (\Throwable $ex) {
                        // Ignore caching errors to avoid breaking ai_focus_helper.
                    }
                }
            }
            // Note: usage from operation is already in $data, don't overwrite with snapshot
            $resp = ['ok' => true, 'data' => $data];
            if ($debugspacy) {
                $spacyTokens = [];
                if (is_array($spacyDebug) && !empty($spacyDebug['tokens']) && is_array($spacyDebug['tokens'])) {
                    foreach ($spacyDebug['tokens'] as $t) {
                        $spacyTokens[] = [
                            'text' => $t['text'] ?? '',
                            'lemma' => $t['lemma'] ?? '',
                            'pos' => $t['pos'] ?? '',
                            'tag' => $t['tag'] ?? '',
                            'morph' => $t['morph'] ?? [],
                            'start' => $t['start'] ?? null,
                            'end' => $t['end'] ?? null,
                        ];
                    }
                }
                $debugai['spacy'] = [
                    'used' => $spacyUsed,
                    'model' => $spacyDebug['model'] ?? '',
                    'text' => $spacyDebug['text'] ?? $fronttext,
                    'tokens' => $spacyTokens,
                ];
            }
            if (!empty($debugai)) {
                $resp['debug'] = $debugai;
            }
            echo json_encode($resp);

        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'ai_invalid_json') {
                // Return detailed error info to browser console
                echo json_encode([
                    'ok' => false,
                    'error' => 'Unexpected response from the AI service.',
                    'errorcode' => 'ai_invalid_json',
                    'details' => 'Check browser console for API response details',
                    'debug' => [
                        'action' => 'ai_focus_helper',
                        'payload' => $payload,
                        'response_preview' => $e->a ?: 'No preview available'
                    ]
                ]);
            } else {
                throw $e;
            }
        }
        break;

    case 'generate_front_audio':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $voiceId = trim($payload['voiceId'] ?? $payload['voice'] ?? '');
        $tts = new \mod_flashcards\local\tts_service();
        try {
            $audio = $tts->synthesize($userid, $text, [
                'voice' => $voiceId,
                'label' => 'front',
            ]);
            echo json_encode([
                'ok' => true,
                'data' => [
                    'audio' => [
                        'front' => $audio,
                    ],
                ],
            ]);
        } catch (\moodle_exception $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'tts_failure',
                'details' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'tts_failure',
                'details' => $e->getMessage(),
            ]);
        }
        break;

    case 'ordbokene_suggest':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $query = trim($payload['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['ok' => true, 'data' => []]);
            break;
        }
        $data = flashcards_fetch_ordbokene_suggestions($query, 12);
        // Merge duplicates (bm/nn) for the same lemma.
        $merged = [];
        foreach ($data as $item) {
            $lemma = trim((string)($item['lemma'] ?? ''));
            if ($lemma === '') {
                continue;
            }
            $key = core_text::strtolower($lemma);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'lemma' => $lemma,
                    'dict' => 'ordbokene',
                    'langs' => [],
                ];
            }
            $dict = trim((string)($item['dict'] ?? ''));
            if ($dict !== '') {
                $merged[$key]['langs'][] = $dict;
            }
            if (empty($merged[$key]['url']) && !empty($item['url'])) {
                $merged[$key]['url'] = $item['url'];
            }
            if (empty($merged[$key]['id']) && !empty($item['id'])) {
                $merged[$key]['id'] = $item['id'];
            }
        }
        $out = array_values(array_map(function($row){
            $row['langs'] = array_values(array_unique(array_filter($row['langs'] ?? [])));
            if (empty($row['langs'])) {
                unset($row['langs']);
            }
            return $row;
        }, $merged));
        echo json_encode(['ok' => true, 'data' => $out]);
        break;

    case 'front_suggest':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $query = trim($payload['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['ok' => true, 'data' => []]);
            break;
        }
        $tokens = array_values(array_filter(preg_split('/\s+/u', $query)));
        $isMultiWord = count($tokens) >= 2;
        $limit = 12;
        $results = [];
        $seen = [];
        $ordbokeneindex = [];
        $mergeordbokene = function(array $item) use (&$results, &$ordbokeneindex, $limit): bool {
            $lemma = trim((string)($item['lemma'] ?? ''));
            if ($lemma === '') {
                return true;
            }
            $langparts = [];
            if (!empty($item['langs']) && is_array($item['langs'])) {
                $langparts = array_values(array_filter(array_map(function($v){
                    return core_text::strtolower(trim((string)$v));
                }, $item['langs'])));
            }
            $dictval = trim((string)($item['dict'] ?? ''));
            if ($dictval !== '') {
                $langparts[] = core_text::strtolower($dictval);
            }
            $langparts = array_values(array_unique(array_filter($langparts)));
            $lemmakey = core_text::strtolower($lemma);
            if (isset($ordbokeneindex[$lemmakey])) {
                $idx = $ordbokeneindex[$lemmakey];
                $existinglangs = [];
                if (!empty($results[$idx]['langs']) && is_array($results[$idx]['langs'])) {
                    $existinglangs = array_values(array_filter(array_map('strval', $results[$idx]['langs'])));
                }
                $merged = array_values(array_unique(array_merge($existinglangs, $langparts)));
                if (!empty($merged)) {
                    $results[$idx]['langs'] = $merged;
                }
                return true;
            }
            if (count($results) >= $limit) {
                return false;
            }
            $entry = [
                'lemma' => $lemma,
                'dict' => 'ordbokene',
                'source' => 'ordbokene',
            ];
            if (!empty($item['id'])) {
                $entry['id'] = $item['id'];
            }
            if (!empty($item['url'])) {
                $entry['url'] = $item['url'];
            }
            if (!empty($langparts)) {
                $entry['langs'] = $langparts;
            }
            $results[] = $entry;
            $ordbokeneindex[$lemmakey] = count($results) - 1;
            return true;
        };

        // Use the last token for local ordbank prefix search (handles "dreie s" -> search "s").
        $parts = preg_split('/\s+/u', $query);
        $prefix = is_array($parts) && count($parts) ? trim((string)end($parts)) : $query;
        if (core_text::strlen($prefix) >= 2) {
            try {
                $records = $DB->get_records_sql(
                    "SELECT DISTINCT f.OPPSLAG AS lemma, f.LEMMA_ID, l.GRUNNFORM AS baseform
                       FROM {ordbank_fullform} f
                  LEFT JOIN {ordbank_lemma} l ON l.LEMMA_ID = f.LEMMA_ID
                      WHERE f.OPPSLAG LIKE :q
                   ORDER BY f.OPPSLAG ASC",
                    ['q' => $prefix . '%'],
                    0,
                    $limit
                );
                foreach ($records as $rec) {
                    $key = core_text::strtolower($rec->lemma . '|ordbank');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'lemma' => $rec->lemma,
                        'baseform' => $rec->baseform ?? null,
                        'dict' => 'ordbank',
                        'source' => 'ordbank',
                    ];
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // DB lookup is best-effort; fall through to remote suggestions.
            }
        }

        // If this is a single word and local already filled the limit, stop here.
        if (!$isMultiWord && count($results) >= $limit) {
            echo json_encode(['ok' => true, 'data' => array_slice($results, 0, $limit)]);
            break;
        }

        // Note: for multi-word queries we keep going to remote to fetch expressions that match all tokens.

        // Suggest endpoint (includes expressions/inflections) first.
        if (count($results) < $limit) {
            $suggestRemote = flashcards_fetch_ordbokene_suggest($query, $limit);
            $suggestRemote = flashcards_filter_multiword($suggestRemote, $query);
            foreach ($suggestRemote as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Remote expressions (ord.uib.no full query to prioritize multi-word hits).
        if (count($results) < $limit) {
            $expressions = flashcards_fetch_ordbokene_expressions($query, 6);
            $expressions = flashcards_filter_multiword($expressions, $query);
            foreach ($expressions as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Fallback: direct lookup for spans to pull baseforms when expressions array is empty.
        if (count($results) < $limit) {
            $lookupspans = flashcards_fetch_ordbokene_lookup_spans($query, $limit);
            foreach ($lookupspans as $item) {
                if (!$mergeordbokene($item)) {
                    break;
                }
            }
        }

        // Remote lemma suggestions next (ord.uib.no full query).
        if (count($results) < $limit) {
            try {
                $remote = flashcards_fetch_ordbokene_suggestions($query, $limit);
                $remote = flashcards_filter_multiword($remote, $query);
                foreach ($remote as $item) {
                    if (!$mergeordbokene($item)) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // If remote fails, we still fall back to local below.
            }
        }

        // Re-rank: for multi-word queries prefer lemmas that contain all tokens (and multi-word/ordbokene first).
        if ($isMultiWord && !empty($results)) {
            $qlower = core_text::strtolower(trim($query));
            $tokenLower = array_map(fn($t) => core_text::strtolower($t), $tokens);
            $idx = 0;
            $scored = array_map(function($item) use ($qlower, $tokenLower, &$idx) {
                $lemma = core_text::strtolower((string)($item['lemma'] ?? ''));
                $containsAll = true;
                foreach ($tokenLower as $t) {
                    if ($t === '' || strpos($lemma, $t) === false) {
                        $containsAll = false;
                        break;
                    }
                }
                $hasSpace = (bool)preg_match('/\s/u', $lemma);
                $dictScore = (isset($item['dict']) && core_text::strtolower((string)$item['dict']) === 'ordbokene') ? 0 : 1;
                $score = [
                    $lemma === $qlower ? 0 : 1,    // exact match is best
                    $containsAll ? 0 : 1,         // must contain all tokens
                    $hasSpace ? 0 : 1,            // multi-word higher
                    $dictScore,                   // prefer ordbokene over local
                    (strpos($lemma, $qlower) !== false) ? 0 : 1, // contains full query
                    $idx++,                       // stable fallback
                ];
                return ['score' => $score, 'item' => $item];
            }, $results);
            usort($scored, function($a, $b) {
                return $a['score'] <=> $b['score'];
            });
            $results = array_values(array_map(fn($s) => $s['item'], $scored));
        }

        $ordered = [];
        $other = [];
        foreach ($results as $item) {
            $dictval = core_text::strtolower(trim((string)($item['dict'] ?? $item['source'] ?? '')));
            if ($dictval === 'ordbokene') {
                $ordered[] = $item;
            } else {
                $other[] = $item;
            }
        }
        $results = array_merge($ordered, $other);
        echo json_encode(['ok' => true, 'data' => array_slice($results, 0, $limit)]);
        break;
    case 'ordbokene_ping':
        // Minimal connectivity test to Ordbøkene (ord.uib.no).
        $url = 'https://ord.uib.no/api/articles?w=klar&dict=bm,nn&scope=e';
        $result = ['ok' => false, 'url' => $url];
        try {
            $curl = new \curl();
            $resp = $curl->get($url);
            $result['http_code'] = $curl->info['http_code'] ?? null;
            $result['raw'] = $resp;
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                $result['ok'] = true;
                $result['json'] = $decoded;
            } else {
                $result['error'] = 'Invalid JSON';
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        echo json_encode($result);
        break;

    case 'ordbank_focus_helper':
        try {
            $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $word = mod_flashcards_normalize_token(trim($payload['word'] ?? ''));
        $fronttext = trim($payload['frontText'] ?? '');
        $prev = mod_flashcards_normalize_token(trim($payload['prev'] ?? ''));
        $prev2 = mod_flashcards_normalize_token(trim($payload['prev2'] ?? ''));
        $next = mod_flashcards_normalize_token(trim($payload['next'] ?? ''));
        $next2 = mod_flashcards_normalize_token(trim($payload['next2'] ?? ''));
        if ($word === '') {
            throw new invalid_parameter_exception('Missing word');
        }
            $context = [];
            if ($prev !== '') {
                $context['prev'] = $prev;
            }
            if ($prev2 !== '') {
                $context['prev2'] = $prev2;
            }
            if ($next !== '') {
                $context['next'] = $next;
            }
            if ($next2 !== '') {
                $context['next2'] = $next2;
            }
            // Debug: check how many raw matches exist
            $debug = [];
            try {
                $needle = core_text::strtolower($word);
                if (mod_flashcards_mysql_has_field('ordbank_fullform', 'oppslag_lc')) {
                    $debug['fullform_count'] = $DB->count_records_select('ordbank_fullform', 'OPPSLAG_LC = ?', [$needle]);
                    $sample = $DB->get_records_sql("SELECT LEMMA_ID, OPPSLAG, TAG FROM {ordbank_fullform} WHERE OPPSLAG_LC = :w LIMIT 5", ['w' => $needle]);
                } else {
                    $bin = mod_flashcards_mysql_bin_collation();
                    $where = 'LOWER(OPPSLAG)=?';
                    $where2 = 'LOWER(OPPSLAG)=:w';
                    if ($bin) {
                        $where = 'LOWER(OPPSLAG) COLLATE ' . $bin . ' = ?';
                        $where2 = 'LOWER(OPPSLAG) COLLATE ' . $bin . ' = :w';
                    }
                    $debug['fullform_count'] = $DB->count_records_select('ordbank_fullform', $where, [$needle]);
                    $sample = $DB->get_records_sql("SELECT LEMMA_ID, OPPSLAG, TAG FROM {ordbank_fullform} WHERE {$where2} LIMIT 5", ['w' => $needle]);
                }
                $debug['fullform_sample'] = array_values($sample);
            } catch (\Throwable $dbgex) {
                $debug['fullform_count_error'] = $dbgex->getMessage();
            }
            $spacypos = '';
            $fullformcount = (int)($debug['fullform_count'] ?? 0);
            if ($fronttext !== '' && $fullformcount > 1) {
                $spacy = mod_flashcards_spacy_analyze($fronttext);
                $spacyPosMap = mod_flashcards_spacy_pos_map($fronttext, $spacy);
                if (!empty($spacyPosMap)) {
                    $tokens = mod_flashcards_word_tokens($fronttext);
                    $idx = mod_flashcards_find_token_index($tokens, $word, $prev, $next, $prev2, $next2);
                    if ($idx !== null && isset($spacyPosMap[$idx])) {
                        $spacypos = $spacyPosMap[$idx];
                        $context['spacy_pos'] = $spacypos;
                    }
                }
            }
            if ($spacypos !== '') {
                $debug['spacy_pos'] = $spacypos;
            }
            $data = \mod_flashcards\local\ordbank_helper::analyze_token($word, $context);
            // Ensure baseform is present if we have lemma_id but baseform is empty.
            if (!empty($data['selected']['lemma_id']) && empty($data['selected']['baseform'])) {
                $lemma = $DB->get_record('ordbank_lemma', ['lemma_id' => $data['selected']['lemma_id']]);
                if ($lemma && !empty($lemma->grunnform)) {
                    $data['selected']['baseform'] = $lemma->grunnform;
                    // Also replace parts if they only contain the surface form.
                    if (!empty($data['parts']) && count($data['parts']) === 1) {
                        $data['parts'] = [$lemma->grunnform];
                    }
                }
            }
            if (!$data && !empty($debug['fullform_sample'])) {
                // Fallback: return first sample as a minimal candidate to unblock UI
                $first = $debug['fullform_sample'][0];
                $baseform = null;
                if (!empty($first->lemma_id)) {
                    $lemma = $DB->get_record('ordbank_lemma', ['lemma_id' => $first->lemma_id]);
                    $baseform = $lemma->grunnform ?? null;
                }
                $data = [
                    'token' => $word,
                    'selected' => [
                        'lemma_id' => (int)($first->lemma_id ?? 0),
                        'wordform' => $first->oppslag ?? $word,
                        'tag' => $first->tag ?? '',
                        'paradigme_id' => null,
                        'boy_nummer' => 0,
                        'ipa' => null,
                        'baseform' => $baseform,
                        'gender' => '',
                    ],
                    'forms' => [],
                    'candidates' => [$first],
                    'paradigm' => [],
                    'parts' => [$baseform ?? $first->oppslag ?? $word],
                    'ambiguous' => true,
                ];
            }
            // Optionally enrich with ordbokene expressions/meanings (normalized).
            $orbokeneenabled = get_config('mod_flashcards', 'orbokene_enabled');
            $ordbokene_debug = [];
            if ($orbokeneenabled) {
                $lang = 'begge';
                $ordbokene_debug['enabled'] = true;
                $ordbokene = mod_flashcards_resolve_ordbokene_expression($fronttext, $word, $data['selected']['baseform'] ?? $word, $lang);
                if ($ordbokene) {
                    $ordbokene_debug['expression'] = $ordbokene['expression'];
                    $ordbokene_debug['url'] = $ordbokene['dictmeta']['url'] ?? '';
                    $data['ordbokene'] = $ordbokene;
                    $data['focusExpression'] = $ordbokene['expression'];
                    $data['expressions'] = array_values(array_unique(array_merge($data['expressions'] ?? [], [$ordbokene['expression']])));
                    if (empty($data['definition']) && !empty($ordbokene['meanings'])) {
                        $data['definition'] = $ordbokene['meanings'][0];
                    }
                    if (empty($data['examples']) && !empty($ordbokene['examples'])) {
                        $data['examples'] = $ordbokene['examples'];
                    }
                    if (empty($data['forms']) && !empty($ordbokene['forms'])) {
                        $data['forms'] = $ordbokene['forms'];
                    }
                    if (empty($data['selected']['wordform'])) {
                        $data['selected']['wordform'] = $ordbokene['expression'];
                    }
                    if (empty($data['selected']['baseform'])) {
                        $data['selected']['baseform'] = $ordbokene['expression'];
                    }
                    $data['selected']['baseform'] = mod_flashcards_normalize_infinitive($data['selected']['baseform']);
                    if (empty($data['analysis']) || !is_array($data['analysis'])) {
                        $data['analysis'] = [];
                    }
                    if (!empty($ordbokene['meanings'][0])) {
                        $data['analysis'][] = [
                            'text' => $ordbokene['expression'],
                            'translation' => $ordbokene['meanings'][0],
                        ];
                    }
                } else {
                    $ordbokene_debug['expression'] = null;
                }
                if ((!$ordbokene || empty($ordbokene['meanings'])) && !empty($data['selected']['baseform'])) {
                    $fallback = \mod_flashcards\local\ordbokene_client::lookup($data['selected']['baseform'], $lang);
                    $ordbokene_debug['fallback'] = [
                        'word' => $data['selected']['baseform'],
                        'ok' => !empty($fallback),
                        'url' => $fallback['dictmeta']['url'] ?? ''
                    ];
                    if (!empty($fallback)) {
                        if (empty($data['definition']) && !empty($fallback['meanings'])) {
                            $data['definition'] = $fallback['meanings'][0];
                        }
                        if (empty($data['examples']) && !empty($fallback['examples'])) {
                            $data['examples'] = $fallback['examples'];
                        }
                        if (empty($data['forms']) && !empty($fallback['forms'])) {
                            $data['forms'] = $fallback['forms'];
                        }
                        if (empty($data['dictmeta']) && !empty($fallback['dictmeta'])) {
                            $data['dictmeta'] = $fallback['dictmeta'];
                        }
                    }
                }
            } else {
                $ordbokene_debug['enabled'] = false;
            }

if (!empty($ordbokene_debug)) {
            $debug['ordbokene'] = $ordbokene_debug;
        }
        if (!$data) {
            echo json_encode(['ok' => false, 'error' => 'No matches found in ordbank', 'debug' => $debug]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data, 'debug' => $debug]);
        }
        } catch (\Throwable $ex) {
            debugging('[flashcards] ordbank_focus_helper failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        }
        break;

    case 'expression_focus_helper':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $expression = trim($payload['expression'] ?? '');
        $fronttext = trim($payload['frontText'] ?? '');
        $language = clean_param($payload['language'] ?? 'en', PARAM_ALPHANUMEXT);
        $meta = is_array($payload['meta']) ? $payload['meta'] : [];
        if ($expression === '') {
            throw new invalid_parameter_exception('Missing expression');
        }
        $lang = ($language === 'nn') ? 'nn' : ((in_array($language, ['nb', 'no'], true) ? 'bm' : 'begge'));
        $openaiexpr = new \mod_flashcards\local\openai_client();
        $resolved = mod_flashcards_resolve_ordbokene_expression($fronttext, $expression, $expression, $lang);
        if (empty($resolved)) {
            $resolved = \mod_flashcards\local\ordbokene_client::lookup($expression, $lang);
            if (!empty($resolved['baseform'])) {
                $resolved['expression'] = mod_flashcards_normalize_infinitive($resolved['baseform']);
            } else {
                $resolved['expression'] = mod_flashcards_normalize_infinitive($expression);
            }
        }
        if (empty($resolved)) {
            $search = \mod_flashcards\local\ordbokene_client::search_expressions($expression, $lang);
            if (!empty($search)) {
                $search['expression'] = mod_flashcards_normalize_infinitive($search['baseform'] ?? $expression);
                $resolved = $search;
            }
        }
        if (empty($resolved)) {
            $resolved = ['expression' => mod_flashcards_normalize_infinitive($expression)];
        }
        $baseExpression = (string)($resolved['expression'] ?? $expression);
        $metaExamples = [];
        if (!empty($meta['examples'])) {
            if (is_array($meta['examples'])) {
                foreach ($meta['examples'] as $item) {
                    if (is_string($item)) {
                        $metaExamples[] = trim($item);
                    } else if (is_array($item) && !empty($item['text'])) {
                        $metaExamples[] = trim($item['text']);
                    }
                }
            } else if (is_string($meta['examples'])) {
                $metaExamples[] = trim($meta['examples']);
            }
            $metaExamples = array_values(array_filter($metaExamples));
        }
        $translation = trim((string)($meta['translation'] ?? ''));
        $definition = trim((string)($meta['definition'] ?? ''));
        $explanation = trim((string)($meta['explanation'] ?? ''));
        if (!$translation && !empty($resolved['meanings'])) {
            $translation = (string)($resolved['meanings'][0] ?? '');
        }
        if (!$definition && $translation) {
            $definition = $translation;
        }
        $analysis = [];
        if ($explanation) {
            $analysis[] = ['text' => $baseExpression, 'translation' => $explanation];
        } else if ($translation) {
            $analysis[] = ['text' => $baseExpression, 'translation' => $translation];
        }
        $examples = $metaExamples ?: ($resolved['examples'] ?? []);
        if ($translation === '' && $definition === '' && empty($examples) && $openaiexpr->is_enabled()) {
            $gen = $openaiexpr->generate_expression_content($baseExpression, '', [], $fronttext, $language, '');
            if (!empty($gen['translation'])) {
                $translation = $gen['translation'];
            }
            if (!empty($gen['definition'])) {
                $definition = $gen['definition'];
            }
            if (!empty($gen['examples'])) {
                $examples = $gen['examples'];
            }
        }
        $data = [
            'focusWord' => $expression,
            'focusBaseform' => $baseExpression,
            'focusExpression' => $baseExpression,
            'selected' => [
                'lemma_id' => 0,
                'wordform' => $expression,
                'baseform' => $baseExpression,
                'tag' => 'phrase',
                'paradigme_id' => null,
                'boy_nummer' => 0,
                'ipa' => null,
                'gender' => '',
            ],
            'pos' => 'phrase',
            'forms' => $resolved['forms'] ?? [],
            'parts' => [$baseExpression],
            'ordbokene' => array_merge($resolved, [
                'expression' => $baseExpression,
                'source' => 'ordbokene',
            ]),
            'analysis' => $analysis,
            'translation' => $translation,
            'definition' => $definition,
            'examples' => $examples,
        ];
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'ai_translate':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $source = clean_param($payload['sourceLang'] ?? 'no', PARAM_ALPHANUMEXT);
        $target = clean_param($payload['targetLang'] ?? 'en', PARAM_ALPHANUMEXT);
        $direction = ($payload['direction'] ?? '') === 'user-no' ? 'user-no' : 'no-user';
        $helper = new \mod_flashcards\local\ai_helper();
        $data = $helper->translate_text($userid, $text, $source, $target, ['direction' => $direction]);
        // Note: usage from operation is already in $data, don't overwrite with snapshot
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'ai_sentence_explain':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $uiLang = clean_param($payload['uiLang'] ?? 'en', PARAM_ALPHANUMEXT);
        $uiLangName = 'English';
        if ($uiLang === 'ru') {
            $uiLangName = 'Russian';
        } else if ($uiLang === 'uk') {
            $uiLangName = 'Ukrainian';
        } else if ($uiLang === 'pl') {
            $uiLangName = 'Polish';
        }
        $system = "You are an expert Norwegian (Bokmål) tutor.\n"
            . "Write ONLY in {$uiLangName}.\n"
            . "Norwegian is allowed ONLY inside: expressions[].expression and expressions[].examples[].no.\n"
            . "Return ONLY valid JSON (no markdown fences, no extra text).\n"
            . "Style: very simple, learner-friendly, short sections and bullets. No heavy linguistics.";
        $schema = <<<JSON
{
  "sentenceTranslation": "natural translation of the full sentence in {$uiLangName}",
  "breakdownTitle": "short title in {$uiLangName}",
  "breakdown": [
    { "no": "Norwegian chunk (2-6 words)", "tr": "short meaning in {$uiLangName}" }
  ]
}
JSON;
        $userPrompt = "Sentence:\n\"{$text}\"\n\nTask:\n1) sentenceTranslation: give a natural translation.\n2) breakdown: give a short breakdown into meaningful parts.\n   - 3-6 items, short and clear.\n   - Each item: Norwegian chunk (2-6 words) + short meaning in {$uiLangName}.\n   - Use ONLY {$uiLangName} in meanings and title.\n   - Norwegian is allowed ONLY in breakdown[].no.\n\nRules for breakdown chunks:\n- Use meaningful parts only (no single function words).\n- Avoid chunks that include articles (en/ei/et), numerals (ett/to/tre/...), or the infinitive marker \"å\".\n- Keep it compact.\n\nReturn JSON EXACTLY in this schema:\n{$schema}";
        $config = get_config('mod_flashcards');
        $model = trim((string)($config->ai_sentence_explain_model ?? ''));
        if ($model === '') {
            $model = trim((string)($config->openai_model ?? ''));
        }
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }
        $reasoning = trim((string)($config->ai_sentence_explain_reasoning_effort ?? 'minimal'));
        $maxTokensRaw = trim((string)($config->ai_sentence_explain_max_tokens ?? ''));
        $maxTokens = ($maxTokensRaw === '') ? 520 : (int)$maxTokensRaw;
        if ($maxTokens < 80) {
            $maxTokens = 80;
        } else if ($maxTokens > 2000) {
            $maxTokens = 2000;
        }

        $modelkey = core_text::strtolower(trim($model));
        $usesMaxCompletionTokens = (
            strpos($modelkey, '5-mini') !== false ||
            strpos($modelkey, '5-nano') !== false ||
            strpos($modelkey, 'gpt-5') !== false ||
            strpos($modelkey, 'o1-') !== false
        );

        $chatPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        // Enforce strict JSON output when supported (reduces parse failures and empty UI).
        if (strpos($modelkey, 'o1-') === false) {
            $chatPayload['response_format'] = ['type' => 'json_object'];
        }

        // Only send reasoning_effort for models that support it (gpt-5 / o1).
        if ($usesMaxCompletionTokens && $reasoning !== '' && $reasoning !== 'none') {
            $chatPayload['reasoning_effort'] = $reasoning;
        }

        // Use correct max tokens parameter by model family.
        if ($usesMaxCompletionTokens) {
            // Ensure reasoning models have enough headroom for visible output.
            if ($maxTokens < 200) {
                $maxTokens = 200;
            }
            $chatPayload['max_completion_tokens'] = $maxTokens;
        } else {
            $chatPayload['max_tokens'] = $maxTokens;
        }
        $helper = new \mod_flashcards\local\ai_helper();
        $cachekey = sha1('ai_sentence_explain:v7:' . $uiLang . ':' . core_text::strtolower($text) . ':' . $model . ':' . $reasoning . ':' . $maxTokens);
        $resp = $helper->explain_sentence($userid, $text, [
            'payload' => $chatPayload,
            'debug_ai' => is_siteadmin(),
            'cache_key' => $cachekey,
        ]);
        echo json_encode(['ok' => true, 'data' => $resp]);
        break;

    case 'ai_question':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $fronttext = trim($payload['frontText'] ?? '');
        $question = trim($payload['question'] ?? '');
        if ($fronttext === '' || $question === '') {
            throw new invalid_parameter_exception('Missing text');
        }
        $language = clean_param($payload['language'] ?? 'uk', PARAM_ALPHANUMEXT);
        $helper = new \mod_flashcards\local\ai_helper();
        $data = $helper->answer_question($userid, $fronttext, $question, ['language' => $language]);
        // Note: usage from operation is already in $data, don't overwrite with snapshot
        echo json_encode(['ok' => true, 'data' => $data]);
        break;

    case 'push_subscribe':
        // Register push notification subscription
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['subscription'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $sub = $payload['subscription'];
        if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            throw new invalid_parameter_exception('Invalid subscription format');
        }
        $lang = clean_param($payload['lang'] ?? 'en', PARAM_ALPHANUMEXT);
        $now = time();

        // Check if subscription with same endpoint exists for this user
        $existing = $DB->get_record('flashcards_push_subs', [
            'userid' => $userid,
            'endpoint' => $sub['endpoint']
        ]);

        if ($existing) {
            // Update existing subscription
            $existing->p256dh = $sub['keys']['p256dh'];
            $existing->auth = $sub['keys']['auth'];
            $existing->lang = $lang;
            $existing->enabled = 1;
            $existing->timemodified = $now;
            $DB->update_record('flashcards_push_subs', $existing);
            $subid = $existing->id;
        } else {
            // Create new subscription
            $record = (object)[
                'userid' => $userid,
                'endpoint' => $sub['endpoint'],
                'p256dh' => $sub['keys']['p256dh'],
                'auth' => $sub['keys']['auth'],
                'lang' => $lang,
                'enabled' => 1,
                'timecreated' => $now,
                'timemodified' => $now
            ];
            $subid = $DB->insert_record('flashcards_push_subs', $record);
        }

        echo json_encode(['ok' => true, 'data' => ['id' => $subid]]);
        break;

    case 'push_unsubscribe':
        // Remove push notification subscription
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['endpoint'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $endpoint = $payload['endpoint'];

        // Delete subscription for this user with matching endpoint
        $DB->delete_records('flashcards_push_subs', [
            'userid' => $userid,
            'endpoint' => $endpoint
        ]);

        echo json_encode(['ok' => true]);
        break;

    case 'push_update_lang':
        // Update language preference for all user's subscriptions
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['lang'])) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $lang = clean_param($payload['lang'], PARAM_ALPHANUMEXT);

        $DB->execute(
            "UPDATE {flashcards_push_subs} SET lang = ?, timemodified = ? WHERE userid = ?",
            [$lang, time(), $userid]
        );

        echo json_encode(['ok' => true]);
        break;

    case 'get_vapid_key':
        // Return VAPID public key for push subscription
        $config = get_config('mod_flashcards');
        $vapidpublic = trim($config->vapid_public_key ?? '');
        if ($vapidpublic === '') {
            throw new moodle_exception('Push notifications not configured');
        }
        echo json_encode(['ok' => true, 'data' => ['publicKey' => $vapidpublic]]);
        break;

    case 'submit_report':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new invalid_parameter_exception('Invalid payload');
        }
        $deckid = isset($payload['deckid']) ? (int)$payload['deckid'] : null;
        $cardid = clean_param($payload['cardid'] ?? '', PARAM_ALPHANUMEXT);
        $cardtitle = clean_param($payload['cardtitle'] ?? '', PARAM_TEXT);
        $message = clean_param($payload['message'] ?? '', PARAM_TEXT);
        if ($cardid === '') {
            throw new invalid_parameter_exception('Missing card');
        }
        $now = time();
        $rec = (object)[
            'deckid' => $deckid ?: null,
            'cardid' => $cardid,
            'cardtitle' => $cardtitle ?: null,
            'userid' => $userid,
            'courseid' => $course->id ?? null,
            'cmid' => $cmid ?: null,
            'message' => $message ?: null,
            'status' => 'open',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $rec->id = $DB->insert_record('flashcards_reports', $rec);
        mod_flashcards_notify_report($rec, $course ?? null, $cm ?? null, $userid);
        echo json_encode(['ok' => true, 'data' => ['id' => $rec->id]]);
        break;

    case 'list_reports':
        if ($globalmode) {
            require_capability('moodle/site:config', $context);
        } else {
            require_capability('moodle/course:manageactivities', $context);
        }
        $where = '1=1';
        $params = [];
        if (!$globalmode && $course) {
            $where = '(courseid = :courseid OR courseid IS NULL)';
            $params['courseid'] = $course->id;
        }
        $records = $DB->get_records_select('flashcards_reports', $where, $params, 'timecreated DESC', '*', 0, 50);
        $userids = [];
        foreach ($records as $recUser) {
            $userids[] = (int)$recUser->userid;
        }
        $userids = array_unique($userids);
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', 'id,firstname,lastname') : [];
        $out = [];
        foreach ($records as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'deckid' => $r->deckid,
                'cardid' => $r->cardid,
                'cardtitle' => $r->cardtitle,
                'message' => $r->message,
                'status' => $r->status,
                'timecreated' => (int)$r->timecreated,
                'user' => isset($users[$r->userid]) ? fullname($users[$r->userid]) : '',
            ];
        }
        echo json_encode(['ok' => true, 'data' => ['reports' => $out]]);
        break;

    default:
        throw new moodle_exception('invalidaction');
}
