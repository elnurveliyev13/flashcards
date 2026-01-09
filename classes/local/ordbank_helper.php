<?php

namespace mod_flashcards\local;

use core_text;
use dml_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper utilities for querying Norsk ordbank data and stitching it with pronunciation.
 *
 * This class is intentionally read-only and keeps a small in-memory cache to avoid
 * repeated lookups while a single request is being processed.
 */
class ordbank_helper {
    /** @var array<int,array<string,mixed>> */
    protected static $paradigmcache = [];
    /** @var bool|null */
    protected static $hasoppslaglc = null;
    /** @var array<string,array<string,mixed>>|null */
    protected static $argstrmap = null;

    /**
     * Return an explicit binary collation for exact byte-wise comparisons on MySQL/MariaDB.
     *
     * We intentionally do NOT rely on the database default collation because many Moodle installs
     * use accent-insensitive collations (e.g. utf8mb4_unicode_ci), where 'å' = 'a'.
     */
    protected static function mysql_bin_collation(): ?string {
        global $DB, $CFG;
        if (!method_exists($DB, 'get_dbfamily') || $DB->get_dbfamily() !== 'mysql') {
            return null;
        }
        $dbcollation = (string)($CFG->dboptions['dbcollation'] ?? '');
        return (stripos($dbcollation, 'utf8mb4') !== false) ? 'utf8mb4_bin' : 'utf8_bin';
    }

    /**
     * True when the optimized indexed column exists (recommended for large tables).
     */
    protected static function has_oppslag_lc(): bool {
        global $DB;
        if (self::$hasoppslaglc !== null) {
            return self::$hasoppslaglc;
        }
        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('ordbank_fullform');
            $field = new \xmldb_field('oppslag_lc');
            self::$hasoppslaglc = $dbman->field_exists($table, $field);
        } catch (\Throwable $e) {
            self::$hasoppslaglc = false;
        }
        return self::$hasoppslaglc;
    }

    /**
     * High level helper that returns the best guess for a token and supporting data.
     *
     * @param string $token The surface form from the text.
     * @param array $context Optional context tokens (e.g. ['prev' => 'en', 'next' => '...']).
     * @return array|null
     */
    public static function analyze_token(string $token, array $context = []): ?array {
        $candidates = self::find_candidates($token);
        if (empty($candidates)) {
            return null;
        }
        $originalcount = count($candidates);

        $selected = self::narrow_by_context($candidates, $context);
        if (!$selected && !empty($candidates)) {
            $selected = reset($candidates);
        }

        if (!$selected) {
            return [
                'token' => $token,
                'selected' => null,
                'candidates' => array_values($candidates),
                'ambiguous' => true,
            ];
        }

        $paradigm = null;
        if (!empty($selected['paradigme_id'])) {
            $paradigm = self::build_paradigm((int)$selected['paradigme_id']);
        }

        // Ensure transcription present if available in pron dict: prefer baseform first, then surface.
        if (empty($selected['ipa'])) {
            $pron = null;
            if (!empty($selected['baseform'])) {
                $pron = pronunciation_manager::lookup((string)$selected['baseform'], null);
            }
            if (!$pron) {
                $pron = pronunciation_manager::lookup((string)($selected['wordform'] ?? $token), null);
            }
            if ($pron) {
                $selected['ipa'] = $pron['ipa'] ?? null;
                $selected['xsampa'] = $pron['xsampa'] ?? null;
                $selected['nofabet'] = $pron['nofabet'] ?? null;
            }
        }

        $parts = self::split_compound($selected['lemma_id'] ?? null, $selected['baseform'] ?? $selected['wordform'] ?? $token);
        $forms = self::fetch_forms($selected['lemma_id'] ?? 0, $selected['tag'] ?? '');
        $genderprofile = self::detect_gender_profile($selected['tag'] ?? '', $forms);
        $gender = $genderprofile['gender'] ?? '';
        $genderambiguous = !empty($genderprofile['ambiguous']);
        if ($gender === '' && !$genderambiguous) {
            $gender = self::detect_gender_from_tag($selected['tag'] ?? '');
        }
        $nounnumber = self::detect_noun_number_restriction($selected, $forms);

        return [
            'token' => $token,
            'selected' => $selected,
            'candidates' => array_values($candidates),
            'paradigm' => $paradigm,
            'parts' => $parts,
            'forms' => $forms,
            'gender' => $gender,
            'gender_ambiguous' => $genderambiguous,
            'noun_number' => $nounnumber,
            'ambiguous' => $originalcount > 1,
        ];
    }

    /**
     * Normalize ordbank tag to POS string for pronunciation lookup.
     */
    protected static function normalize_tag_to_pos(string $tag, string $ordklasse = ''): ?string {
        $ok = core_text::strtolower(trim($ordklasse));
        if ($ok !== '') {
            if (str_contains($ok, 'subst')) {
                return 'substantiv';
            }
            if (str_contains($ok, 'verb')) {
                return 'verb';
            }
            if (str_contains($ok, 'adj')) {
                return 'adjektiv';
            }
            if (str_contains($ok, 'adv')) {
                return 'adverb';
            }
            if (str_contains($ok, 'pron')) {
                return 'pronomen';
            }
            if (str_contains($ok, 'det')) {
                return 'determinativ';
            }
            if (str_contains($ok, 'prep')) {
                return 'preposisjon';
            }
            if (str_contains($ok, 'konj')) {
                return 'konjunksjon';
            }
        }
        $lower = core_text::strtolower(trim($tag));
        if (str_contains($lower, 'subst')) {
            return 'substantiv';
        }
        if (str_contains($lower, 'verb')) {
            return 'verb';
        }
        if (str_contains($lower, 'adj')) {
            return 'adjektiv';
        }
        if (str_contains($lower, 'adv')) {
            return 'adverb';
        }
        if (str_contains($lower, 'pron')) {
            return 'pronomen';
        }
        if (str_contains($lower, 'det')) {
            return 'determinativ';
        }
        if (str_contains($lower, 'prep')) {
            return 'preposisjon';
        }
        if (str_contains($lower, 'konj')) {
            return 'konjunksjon';
        }
        if (str_contains($lower, 'subj')) {
            return 'subjunksjon';
        }
        if (str_contains($lower, 'int')) {
            return 'interjeksjon';
        }
        return null;
    }

    /**
     * Local token normalizer (keeps logic close to mod_flashcards_normalize_token without hard dependency).
     */
    protected static function normalize_token_local(string $token): string {
        $token = core_text::strtolower(trim($token));
        $token = strtr($token, [
            'å' => 'a',
            'æ' => 'ae',
            'ø' => 'o',
            'ƒ?' => 'a',
            'ƒ³' => 'o',
            'ƒñ' => 'a',
            'ƒ¦' => 'ae',
            'ƒ¶' => 'o',
        ]);
        if (function_exists('mod_flashcards_normalize_token')) {
            $token = mod_flashcards_normalize_token($token);
        }
        return trim($token);
    }

    /**
     * Parse a raw argstr definition into a lightweight metadata structure.
     *
     * @return array{requires_pp:bool,preps:array<int,string>}
     */
    protected static function parse_argstr_definition(string $raw): array {
        $raw = core_text::strtolower($raw);
        $requirespp = str_contains($raw, 'pp:');
        $preps = [];
        if (preg_match_all('/kjerne\\s*=\\s*([a-zƒ?]+)/iu', $raw, $m)) {
            foreach ($m[1] as $prep) {
                $prep = self::normalize_token_local($prep);
                if ($prep !== '' && $prep !== 'var') {
                    $preps[] = $prep;
                }
            }
        }
        $preps = array_values(array_unique(array_filter($preps)));
        return ['requires_pp' => $requirespp, 'preps' => $preps];
    }

    /**
     * Load argument structure metadata from DB (ordbank_argstr) or local corpus file.
     *
     * @return array<string,array{requires_pp:bool,preps:array<int,string>}>
     */
    protected static function load_argstr_map(): array {
        global $DB, $CFG;
        if (self::$argstrmap !== null) {
            return self::$argstrmap;
        }
        $map = [];
        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('ordbank_argstr');
            if ($dbman->table_exists($table)) {
                $columns = array_keys($DB->get_columns('ordbank_argstr'));
                $codecol = '';
                $defcol = '';
                foreach ($columns as $col) {
                    $lc = core_text::strtolower($col);
                    if ($codecol === '' && (str_contains($lc, 'kode') || str_contains($lc, 'code'))) {
                        $codecol = $col;
                    }
                    if ($defcol === '' && (str_contains($lc, 'def') || str_contains($lc, 'struk') || str_contains($lc, 'arg'))) {
                        $defcol = $col;
                    }
                }
                foreach ($DB->get_records('ordbank_argstr') as $row) {
                    $code = '';
                    if ($codecol !== '' && isset($row->$codecol)) {
                        $code = (string)$row->$codecol;
                    } else {
                        $first = (array)$row;
                        $code = (string)reset($first);
                    }
                    $code = core_text::strtolower(trim($code));
                    if ($code === '') {
                        continue;
                    }
                    $def = '';
                    if ($defcol !== '' && isset($row->$defcol)) {
                        $def = (string)$row->$defcol;
                    }
                    $map[$code] = self::parse_argstr_definition($def);
                }
            }
        } catch (\Throwable $e) {
            // Ignore DB lookup failures and fall back to corpus file.
        }

        if (empty($map)) {
            $paths = [
                $CFG->dirroot . '/mod/flashcards/.corpus/ordlist/norsk_ordbank_argstr.txt',
                __DIR__ . '/../../.corpus/ordlist/norsk_ordbank_argstr.txt',
            ];
            foreach ($paths as $path) {
                if (!is_readable($path)) {
                    continue;
                }
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$lines) {
                    continue;
                }
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!preg_match('/^arg_code\\(([^,]+),\\[(.*)\\]\\)\\./i', $line, $m)) {
                        continue;
                    }
                    $code = core_text::strtolower(trim($m[1]));
                    if ($code === '') {
                        continue;
                    }
                    $map[$code] = self::parse_argstr_definition($m[2]);
                }
                if (!empty($map)) {
                    break;
                }
            }
        }
        self::$argstrmap = $map;
        return self::$argstrmap;
    }

    /**
     * Aggregate argstr metadata for a list of arg codes (<trans1>, <refl4/om>, ...).
     *
     * @param array<int,array{code:string,prep:?string}> $codes
     * @return array{preps:array<int,string>,requires_pp:bool}
     */
    public static function argcode_meta(array $codes): array {
        $map = self::load_argstr_map();
        $meta = ['preps' => [], 'requires_pp' => false];
        foreach ($codes as $ac) {
            $code = core_text::strtolower((string)($ac['code'] ?? $ac ?? ''));
            if (!empty($ac['prep'])) {
                $p = self::normalize_token_local((string)$ac['prep']);
                if ($p !== '') {
                    $meta['preps'][] = $p;
                }
            }
            if ($code === '') {
                continue;
            }
            if (isset($map[$code])) {
                $meta['preps'] = array_merge($meta['preps'], $map[$code]['preps'] ?? []);
                if (!empty($map[$code]['requires_pp'])) {
                    $meta['requires_pp'] = true;
                }
            }
        }
        $meta['preps'] = array_values(array_unique(array_filter($meta['preps'])));
        return $meta;
    }

    /**
     * Extract valency codes (trans/refl/ditrans/predik) from a tag.
     *
     * @return array<int,array{code:string,prep:?string}>
     */
    public static function extract_argcodes_from_tag(string $tag): array {
        $out = [];
        if ($tag === '') {
            return $out;
        }
        if (!preg_match_all('/<([^>]+)>/u', $tag, $m)) {
            return $out;
        }
        foreach ($m[1] as $raw) {
            $raw = core_text::strtolower(trim($raw));
            if (!preg_match('/^(trans|intrans|refl|ditrans|predik)/', $raw)) {
                continue;
            }
            $code = $raw;
            $prep = null;
            if (str_contains($raw, '/')) {
                [$code, $prep] = explode('/', $raw, 2);
                $prep = self::normalize_token_local($prep);
            }
            $out[] = ['code' => $code, 'prep' => $prep ?: null];
        }
        return $out;
    }

    /**
     * Find all ordbank entries matching a surface form.
     *
     * @param string $wordform
     * @return array<int,array<string,mixed>>
     */
    public static function find_candidates(string $wordform): array {
        global $DB;

        $normalized = core_text::strtolower(trim($wordform));
        if ($normalized === '') {
            return [];
        }

        if (self::has_oppslag_lc()) {
            $where = 'f.OPPSLAG_LC = :w';
        } else {
            $bin = self::mysql_bin_collation();
            $where = 'LOWER(f.OPPSLAG) = :w';
            if ($bin) {
                $where = 'LOWER(f.OPPSLAG) COLLATE ' . $bin . ' = :w';
            }
        }
        $sql = "SELECT f.LEMMA_ID,
                       f.OPPSLAG AS wordform,
                       f.TAG,
                       f.PARADIGME_ID,
                       f.BOY_NUMMER,
                       l.GRUNNFORM AS baseform,
                       p.ORDKLASSE,
                       p.ORDKLASSE_UTDYPING,
                       b.BOY_GRUPPE,
                       b.BOY_TEKST,
                       b.ORDBOK_TEKST
                  FROM {ordbank_fullform} f
             LEFT JOIN {ordbank_lemma} l ON l.LEMMA_ID = f.LEMMA_ID
             LEFT JOIN {ordbank_paradigme} p ON p.PARADIGME_ID = f.PARADIGME_ID
             LEFT JOIN {ordbank_boying} b ON b.BOY_NUMMER = f.BOY_NUMMER
                 WHERE {$where}";

        try {
            $records = $DB->get_records_sql($sql, ['w' => $normalized]);
        } catch (dml_exception $e) {
            debugging('[flashcards] ordbank_helper::find_candidates failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }

        $out = [];
        foreach ($records as $rec) {
            $key = implode('|', [
                (int)$rec->lemma_id,
                (string)$rec->tag,
                (string)$rec->paradigme_id,
                (int)$rec->boy_nummer,
            ]);
            if (!isset($out[$key])) {
                $pos = self::normalize_tag_to_pos($rec->tag ?? '', $rec->ordklasse ?? '');
                $pron = \mod_flashcards\local\pronunciation_manager::lookup((string)$rec->wordform, $pos);
                if (!$pron && !empty($rec->baseform)) {
                    $pron = \mod_flashcards\local\pronunciation_manager::lookup((string)$rec->baseform, $pos);
                }
                $ipa = $pron ? $pron['ipa'] : null;
                $xsampa = $pron ? $pron['xsampa'] : null;
                $nofabet = $pron ? $pron['nofabet'] : null;
                $out[$key] = [
                    'lemma_id' => (int)$rec->lemma_id,
                    'wordform' => $rec->wordform,
                    'tag' => $rec->tag,
                    'paradigme_id' => $rec->paradigme_id,
                    'boy_nummer' => (int)$rec->boy_nummer,
                    'ipa' => $ipa,
                    'xsampa' => $xsampa,
                    'nofabet' => $nofabet,
                    'baseform' => $rec->baseform ?? null,
                    'ordklasse' => $rec->ordklasse ?? null,
                    'ordklasse_utdyping' => $rec->ordklasse_utdyping ?? null,
                    'boy_group' => $rec->boy_gruppe ?? ($rec->boy_group ?? null),
                    'boy_text' => $rec->boy_tekst ?? null,
                    'ordbok_boy_text' => $rec->ordbok_tekst ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * Build paradigm slots for a given paradigme_id.
     *
     * @param int $paradigmeid
     * @return array<int,array<string,string>>
     */
    public static function build_paradigm(int $paradigmeid): array {
        global $DB;

        if (isset(self::$paradigmcache[$paradigmeid])) {
            return self::$paradigmcache[$paradigmeid];
        }

        $forms = [];
        try {
            $rows = $DB->get_records('ordbank_paradigme_boying', ['PARADIGME_ID' => $paradigmeid], 'BOY_NUMMER');
        } catch (dml_exception $e) {
            debugging('[flashcards] build_paradigm failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }

        foreach ($rows as $row) {
            $boy = $DB->get_record('ordbank_boying', ['BOY_NUMMER' => $row->boy_nummer]);
            $forms[] = [
                'boy_nummer' => (int)$row->boy_nummer,
                'label' => $boy->boy_tekst ?? '',
                'ordbok_label' => $boy->ordbok_tekst ?? '',
                'pattern' => $row->boy_uttrykk ?? '',
            ];
        }

        self::$paradigmcache[$paradigmeid] = $forms;
        return $forms;
    }

    /**
     * Try to split a compound into parts using leddanalyse.
     *
     * @param int|null $lemmaid
     * @param string $oppslag
     * @param bool|null $fromLeddanalyse Optional reference set to true when leddanalyse returned 2+ parts.
     * @return array<int,string>
     */
    public static function split_compound(?int $lemmaid, string $oppslag, ?bool &$fromLeddanalyse = null): array {
        global $DB;

        $oppslag = trim($oppslag);
        if ($fromLeddanalyse !== null) {
            $fromLeddanalyse = false;
        }
        $conditions = [];
        $params = [];
        if ($lemmaid) {
            $conditions[] = 'lemma_id = :lemmaid';
            $params['lemmaid'] = $lemmaid;
        }
        if ($oppslag !== '') {
            $conditions[] = 'LOWER(oppslag) = :oppslag';
            $params['oppslag'] = core_text::strtolower($oppslag);
        }

        if (empty($conditions)) {
            return [$oppslag];
        }

        $sql = 'SELECT * FROM {ordbank_leddanalyse} WHERE ' . implode(' AND ', $conditions);
        $rec = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);
        if (!$rec) {
            // Heuristic fallback: split simple compounds with hyphen or s-fuge before last segment.
            if (str_contains($oppslag, '-')) {
                if ($fromLeddanalyse !== null) {
                    $fromLeddanalyse = false;
                }
                return array_values(array_filter(array_map('trim', explode('-', $oppslag))));
            }
            // Try naive split before final noun-like tail if "s" fuge present.
            if (preg_match('~^(.+?)(s)(bolig|hus|mann|menn|vei|gate|plass|verk|arbeid|tid|sted|by|rom|bok|bok|rett)~iu', $oppslag, $m)) {
                if ($fromLeddanalyse !== null) {
                    $fromLeddanalyse = false;
                }
                return array_values(array_filter([$m[1], $m[2], $m[3]]));
            }
            if ($fromLeddanalyse !== null) {
                $fromLeddanalyse = false;
            }
            return [$oppslag];
        }

        $parts = array_filter([
            $rec->forledd ?? '',
            $rec->fuge ?? '',
            $rec->etterledd ?? '',
        ], fn(string $v) => $v !== '');

        if ($fromLeddanalyse !== null) {
            $fromLeddanalyse = count($parts) > 1;
        }

        return $parts ? array_values($parts) : [$oppslag];
    }

    /**
     * Apply simple heuristics using surrounding tokens to narrow down candidates.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array $context e.g. ['prev' => 'en', 'next' => '...', 'next2' => 'om']
     * @return array<string,mixed>|null
     */
    protected static function narrow_by_context(array $candidates, array $context): ?array {
        if (count($candidates) <= 1) {
            return $candidates ? reset($candidates) : null;
        }

        $prev = isset($context['prev']) ? core_text::strtolower((string)$context['prev']) : null;
        $prev2 = isset($context['prev2']) ? core_text::strtolower((string)$context['prev2']) : null;
        $next = isset($context['next']) ? core_text::strtolower((string)$context['next']) : null;
        $next2 = isset($context['next2']) ? core_text::strtolower((string)$context['next2']) : null;
        $spacyPos = isset($context['spacy_pos']) ? core_text::strtoupper((string)$context['spacy_pos']) : '';

        if ($spacyPos !== '') {
            $posMatches = array_filter($candidates, function($cand) use ($spacyPos) {
                $tag = core_text::strtolower((string)($cand['tag'] ?? ''));
                $ordklasse = core_text::strtolower((string)($cand['ordklasse'] ?? ''));
                $isverb = str_contains($tag, 'verb') || str_contains($ordklasse, 'verb');
                $isnoun = str_contains($tag, 'subst') || str_contains($ordklasse, 'subst');
                $isadj  = str_contains($tag, 'adj') || str_contains($ordklasse, 'adj');
                $isadv  = str_contains($tag, 'adv') || str_contains($ordklasse, 'adv');
                $isprep = str_contains($tag, 'prep') || str_contains($ordklasse, 'prep');
                $ispron = str_contains($tag, 'pron') || str_contains($ordklasse, 'pron');
                $isdet = str_contains($tag, 'det') || str_contains($ordklasse, 'det');
                $iskonj = str_contains($tag, 'konj') || str_contains($ordklasse, 'konj');
                $candpos = '';
                if ($isverb) { $candpos = 'VERB'; }
                if ($isnoun) { $candpos = 'NOUN'; }
                if ($isadj) { $candpos = 'ADJ'; }
                if ($isadv) { $candpos = 'ADV'; }
                if ($isprep) { $candpos = 'ADP'; }
                if ($ispron) { $candpos = 'PRON'; }
                if ($isdet) { $candpos = 'DET'; }
                if ($iskonj) { $candpos = 'CONJ'; }
                return $candpos !== '' && $candpos === $spacyPos;
            });
            if (!empty($posMatches)) {
                $candidates = array_values($posMatches);
            }
        }

        $pronouns = ['jeg','du','han','hun','vi','dere','de','eg','ho','me','dei','det','den','dette','disse','hva','hvem','hvor','nar'];
        $articles = ['en','ei','et','ein','eitt'];
        $determiners = ['den','det','de','denne','dette','disse','min','mitt','mi','mine','din','ditt','di','dine','sin','sitt','si','sine','hans','hennes','var','vart','vare','deres'];
        $auxverbs = ['er','var','har','hadde','blir','ble','vil','skal','kan','ma','bor','kunne','skulle','ville'];
        $prepseg = ['om','over','for','med','til','av','pa','paa','i'];
        $functionwords = ['for','til','av','pa','paa','i','om','med','seg','det','som','aa'];

        $wordLower = core_text::strtolower((string)($candidates[array_key_first($candidates)]['wordform'] ?? ''));

        $best = null;
        $bestscore = -INF;
        foreach ($candidates as $cand) {
            $tag = core_text::strtolower((string)($cand['tag'] ?? ''));
            $ordklasse = core_text::strtolower((string)($cand['ordklasse'] ?? ''));
            $boygroup = core_text::strtolower((string)($cand['boy_group'] ?? ($cand['boy_gruppe'] ?? '')));
            $boynum = (int)($cand['boy_nummer'] ?? 0);
            $score = 0;
            $isverb = str_contains($tag, 'verb') || str_contains($ordklasse, 'verb');
            $isnoun = str_contains($tag, 'subst') || str_contains($ordklasse, 'subst');
            $isadj  = str_contains($tag, 'adj') || str_contains($ordklasse, 'adj');
            $isadv  = str_contains($tag, 'adv') || str_contains($ordklasse, 'adv');
            $isprep = str_contains($tag, 'prep') || str_contains($ordklasse, 'prep');

            $candword = core_text::strtolower((string)($cand['wordform'] ?? ''));
            if ($wordLower !== '' && $candword === $wordLower) {
                $score += 12;
            }
            $argcodes = self::extract_argcodes_from_tag($tag);
            $argmeta = self::argcode_meta($argcodes);

            if ($cand['paradigme_id'] ?? null) {
                $score += 1;
            }
            if ($isnoun) { $score += 3; }
            if ($isverb) { $score += 3; }
            if ($isadj)  { $score += 2; }
            if ($isadv)  { $score += 1; }
            if ($spacyPos !== '') {
                $candpos = '';
                if ($isverb) { $candpos = 'VERB'; }
                if ($isnoun) { $candpos = 'NOUN'; }
                if ($isadj) { $candpos = 'ADJ'; }
                if ($isadv) { $candpos = 'ADV'; }
                if ($isprep) { $candpos = 'ADP'; }
                if ($candpos !== '' && $candpos === $spacyPos) {
                    $score += 8;
                } else if ($candpos !== '') {
                    $score -= 6;
                }
            }
            // Penalize imperatives when spaCy sees a noun in prepositional context.
            if ($spacyPos === 'NOUN' && $isverb && $boynum === 11 && ($prev === 'på' || $next === 'etter' || $prev2 === 'på' || $next2 === 'etter')) {
                $score -= 12;
            }
            if ($candword !== '' && in_array($candword, $functionwords, true)) {
                if ($isadv || $isprep || str_contains($tag, 'konj')) {
                    $score += 15;
                }
                if ($isverb || $isnoun) {
                    $score -= 20;
                }
            }

            $articleNear = (in_array($prev, $articles, true) || in_array($prev, $determiners, true) ||
                            in_array($prev2, $articles, true) || in_array($prev2, $determiners, true));
            if ($articleNear && $isnoun) {
                $score += 12;
            }
            if ($articleNear && $isverb) {
                $score -= 12;
            }
            if ($articleNear && $boygroup !== '' && str_contains($boygroup, 'substantiv')) {
                $score += 6;
            }
            if ($articleNear && $boygroup !== '' && str_contains($boygroup, 'verb')) {
                $score -= 6;
            }
            if ($articleNear && $boynum === 1) {
                $score += 6;
            }
            if ($articleNear && $boynum === 11) {
                $score -= 6;
            }

            if ($next !== null && $isadj && !in_array($next, $articles, true)) {
                $score += 1;
            }
            if ($next === 'seg' && $isverb) {
                $score += 5;
            }
            if ($next === 'seg' && $isverb && in_array($next2, $prepseg, true)) {
                $score += 2;
            }
            $prepMatched = false;
            foreach ($argcodes as $ac) {
                $code = $ac['code'] ?? '';
                $prep = $ac['prep'] ?? null;
                if ($isverb && ($next === 'seg' || $prev === 'seg' || $prev2 === 'seg')) {
                    $score += 4;
                }
                if ($prep !== null) {
                    if ($next === $prep || $prev === $prep || $next2 === $prep || $prev2 === $prep) {
                        $score += 8;
                        $prepMatched = true;
                    }
                }
                if ($code && str_contains($code, 'refl') && ($next === 'seg' || $next2 === 'seg' || $prev2 === 'seg')) {
                    $score += 8;
                }
            }
            foreach ($argmeta['preps'] as $p) {
                if ($p !== '' && ($next === $p || $prev === $p || $next2 === $p || $prev2 === $p)) {
                    $score += 6;
                    $prepMatched = true;
                }
            }
            if (($argmeta['requires_pp'] ?? false) && $isverb && !$prepMatched && ($next || $next2)) {
                $score -= 2;
            }
            if (in_array($prev, $pronouns, true) && $isverb) {
                $score += 4;
            }
            if ($prev === 'a' && $isverb) {
                $score += 3;
            }
            if ($next2 === 'seg' && ($next === 'det' || in_array($next, $pronouns, true)) && $isverb) {
                $score += 10;
            }
            if ($prev === 'er' && $isadv && ($cand['wordform'] ?? '') === 'for' && $next !== null && !in_array($next, $articles, true)) {
                $score += 18;
            }
            if ($prev === 'er' && $isverb && ($cand['wordform'] ?? '') === 'for') {
                $score -= 15;
            }
            if ($next2 === 'seg' && $isverb) {
                $score += 6;
            }
            if ($next2 === 'seg' && $isnoun) {
                $score -= 4;
            }
            if ($isverb && (str_contains($tag, '<refl') || str_contains($tag, 'refl'))) {
                $score += 2;
            }
            if ($next !== null && (in_array($next, $pronouns, true) || in_array($next, $determiners, true)) && $isverb) {
                $score += 8;
            }
            if ($next !== null && (in_array($next, $pronouns, true) || in_array($next, $determiners, true)) && $isnoun) {
                $score -= 3;
            }
            if (in_array($prev, $auxverbs, true) && $isverb && (str_contains($tag, 'perf-part') || str_contains($tag, '<perf-part>') || str_contains($tag, 'part'))) {
                $score += 6;
            }
            if (($cand['wordform'] ?? '') === 'for' && $prev === 'er' && $isadv) {
                $score += 6;
            }
            if (($cand['wordform'] ?? '') === 'for' && $prev === 'er' && $isverb) {
                $score -= 6;
            }

            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $cand;
            }
        }

        return $best ?: ($candidates ? reset($candidates) : null);
    }

    /**
     * Fetch verb/noun/adjective forms from fullform table for UI population.
     */
    public static function fetch_forms(int $lemmaid, string $tag): array {
        global $DB;
        if ($lemmaid <= 0) {
            return [];
        }
        $lower = core_text::strtolower($tag);
        $out = [];
        try {
            $records = $DB->get_records('ordbank_fullform', ['lemma_id' => $lemmaid]);
        } catch (\Throwable $e) {
            return [];
        }
        if (str_contains($lower, 'verb')) {
            $verb = [
                'infinitiv' => [],
                'presens' => [],
                'preteritum' => [],
                'perfektum_partisipp' => [],
                'imperativ' => [],
                'presens_perfektum' => [],
                'presens_partisipp' => [],
            ];
            foreach ($records as $rec) {
                $t = core_text::strtolower((string)$rec->tag);
                if (!$rec->oppslag) {
                    continue;
                }
                $boy = (int)$rec->boy_nummer;
                $ispart = str_contains($t, 'part');
                if ($boy === 1 || str_contains($t, 'inf')) {
                    $verb['infinitiv'][] = $rec->oppslag;
                }
                if (($boy === 2 || str_contains($t, 'pres')) && !$ispart) {
                    $verb['presens'][] = $rec->oppslag;
                }
                if ($boy === 4 || str_contains($t, 'pret')) {
                    $verb['preteritum'][] = $rec->oppslag;
                }
                if ($boy === 5 || str_contains($t, 'perf') || $ispart) {
                    $verb['perfektum_partisipp'][] = $rec->oppslag;
                }
                if (preg_match('/pres[^a-z]?part/u', $t)) {
                    $verb['presens_partisipp'][] = $rec->oppslag;
                }
                if ($boy === 3 || str_contains($t, 'imper')) {
                    $verb['imperativ'][] = $rec->oppslag;
                }
                if (empty($verb['infinitiv']) && $boy === 0 && str_contains($t, 'verb')) {
                    $verb['infinitiv'][] = $rec->oppslag;
                }
            }
            $verb = array_map(function($arr) {
                $arr = array_values(array_unique(array_filter($arr)));
                return $arr;
            }, $verb);
            if (!empty($verb['perfektum_partisipp'])) {
                $derived = array_map(fn($v) => 'har ' . $v, $verb['perfektum_partisipp']);
                $verb['presens_perfektum'] = array_values(array_unique(array_merge($verb['presens_perfektum'], $derived)));
            } else {
                $verb['presens_perfektum'] = array_values(array_unique(array_filter($verb['presens_perfektum'])));
            }
            if (!empty($verb['presens'])) {
                $nonende = array_filter($verb['presens'], fn($v) => !preg_match('/ende$/u', $v));
                if (!empty($nonende)) {
                    $verb['presens'] = array_values($nonende);
                }
            }
            $verb = array_filter($verb, fn($v) => !empty($v));
            if (!empty($verb)) {
                $out['verb'] = $verb;
            }
        } elseif (str_contains($lower, 'subst')) {
            $noun = [
                'indef_sg' => [],
                'def_sg' => [],
                'indef_pl' => [],
                'def_pl' => [],
            ];
            $nounByGender = [
                'hankjonn' => [
                    'indef_sg' => [],
                    'def_sg' => [],
                    'indef_pl' => [],
                    'def_pl' => [],
                ],
                'hunkjonn' => [
                    'indef_sg' => [],
                    'def_sg' => [],
                    'indef_pl' => [],
                    'def_pl' => [],
                ],
                'intetkjonn' => [
                    'indef_sg' => [],
                    'def_sg' => [],
                    'indef_pl' => [],
                    'def_pl' => [],
                ],
            ];
            foreach ($records as $rec) {
                $t = core_text::strtolower((string)$rec->tag);
                if (!$rec->oppslag) {
                    continue;
                }
                $genders = [];
                if (str_contains($t, 'mask')) {
                    $genders[] = 'hankjonn';
                }
                if (str_contains($t, 'fem')) {
                    $genders[] = 'hunkjonn';
                }
                if (str_contains($t, 'noy') || str_contains($t, 'nøy') || str_contains($t, 'neut')) {
                    $genders[] = 'intetkjonn';
                }
                if (str_contains($t, 'ent ub')) {
                    $noun['indef_sg'][] = $rec->oppslag;
                    foreach ($genders as $gender) {
                        $nounByGender[$gender]['indef_sg'][] = $rec->oppslag;
                    }
                } elseif (str_contains($t, 'ent be')) {
                    $noun['def_sg'][] = $rec->oppslag;
                    foreach ($genders as $gender) {
                        $nounByGender[$gender]['def_sg'][] = $rec->oppslag;
                    }
                } elseif (str_contains($t, 'fl ub')) {
                    $noun['indef_pl'][] = $rec->oppslag;
                    foreach ($genders as $gender) {
                        $nounByGender[$gender]['indef_pl'][] = $rec->oppslag;
                    }
                } elseif (str_contains($t, 'fl be')) {
                    $noun['def_pl'][] = $rec->oppslag;
                    foreach ($genders as $gender) {
                        $nounByGender[$gender]['def_pl'][] = $rec->oppslag;
                    }
                }
            }
            $noun = array_map(function($arr){
                $arr = array_values(array_unique(array_filter($arr)));
                return $arr;
            }, $noun);
            $noun = array_filter($noun, fn($v) => !empty($v));
            foreach ($nounByGender as $gender => $bucket) {
                $bucket = array_map(function($arr){
                    $arr = array_values(array_unique(array_filter($arr)));
                    return $arr;
                }, $bucket);
                $bucket = array_filter($bucket, fn($v) => !empty($v));
                $nounByGender[$gender] = $bucket;
            }
            $nounByGender = array_filter($nounByGender, fn($v) => !empty($v));
            if (!empty($nounByGender)) {
                $noun['by_gender'] = $nounByGender;
            }
            if (!empty($noun)) {
                $out['noun'] = $noun;
            }
        }
        return $out;
    }

    /**
     * Detect simple gender/article hint from tag.
     */
    protected static function detect_gender_from_tag(string $tag): string {
        $t = core_text::strtolower($tag);
        if (!str_contains($t, 'subst')) {
            return '';
        }
        $hasmask = str_contains($t, 'mask');
        $hasfem = str_contains($t, 'fem');
        $hasneut = str_contains($t, 'nøy') || str_contains($t, 'noy');
        if ($hasmask && $hasfem) {
            return 'ei/en';
        }
        if ($hasmask) {
            return 'en';
        }
        if ($hasfem) {
            return 'ei';
        }
        if ($hasneut) {
            return 'et';
        }
        return '';
    }

    /**
     * Detect gender/article hints from paradigm data when available.
     *
     * @param string $tag Ordbank tag.
     * @param array $forms Forms array from fetch_forms().
     * @return array{gender:string, ambiguous:bool}
     */
    protected static function detect_gender_profile(string $tag, array $forms): array {
        $has = [
            'hankjonn' => false,
            'hunkjonn' => false,
            'intetkjonn' => false,
        ];
        $bygender = $forms['noun']['by_gender'] ?? null;
        if (is_array($bygender) && !empty($bygender)) {
            foreach ($has as $key => $_) {
                if (!empty($bygender[$key])) {
                    $has[$key] = true;
                }
            }
        } else {
            $t = core_text::strtolower($tag);
            if (str_contains($t, 'mask')) {
                $has['hankjonn'] = true;
            }
            if (str_contains($t, 'fem')) {
                $has['hunkjonn'] = true;
            }
            if (str_contains($t, 'nƒñy') || str_contains($t, 'noy') || str_contains($t, 'neut')) {
                $has['intetkjonn'] = true;
            }
        }
        $hasmasc = $has['hankjonn'];
        $hasfem = $has['hunkjonn'];
        $hasneut = $has['intetkjonn'];
        $ambiguous = $hasneut && ($hasmasc || $hasfem);
        $gender = '';
        if (!$ambiguous) {
            if ($hasmasc && $hasfem) {
                $gender = 'ei/en';
            } elseif ($hasmasc) {
                $gender = 'en';
            } elseif ($hasfem) {
                $gender = 'ei';
            } elseif ($hasneut) {
                $gender = 'et';
            }
        }
        return [
            'gender' => $gender,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Detect noun number restriction (singular-only or plural-only).
     *
     * @param array<string,mixed> $selected
     * @param array<string,mixed> $forms
     * @return string
     */
    protected static function detect_noun_number_restriction(array $selected, array $forms): string {
        $nounforms = $forms['noun'] ?? [];
        $hasSg = !empty($nounforms['indef_sg']) || !empty($nounforms['def_sg']);
        $hasPl = !empty($nounforms['indef_pl']) || !empty($nounforms['def_pl']);
        if ($hasSg && !$hasPl) {
            return 'sg_only';
        }
        if ($hasPl && !$hasSg) {
            return 'pl_only';
        }

        $details = (string)($selected['ordklasse_utdyping'] ?? '');
        if ($details === '') {
            $details = (string)($selected['boy_group'] ?? ($selected['boy_gruppe'] ?? ''));
        }
        if ($details === '') {
            $details = (string)($selected['tag'] ?? '');
        }
        if ($details === '') {
            return '';
        }
        $lower = core_text::strtolower($details);
        $hasFl = preg_match('/(^|[^a-z])fl([^a-z]|$)/u', $lower) === 1;
        $hasEnt = preg_match('/(^|[^a-z])ent([^a-z]|$)/u', $lower) === 1;
        if ($hasFl && !$hasEnt) {
            return 'pl_only';
        }
        if ($hasEnt && !$hasFl) {
            return 'sg_only';
        }
        return '';
    }
}
