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

        // Ensure transcription present if available in pron dict.
        if (empty($selected['ipa'])) {
            $pron = pronunciation_manager::lookup((string)($selected['wordform'] ?? $token), null);
            if (!$pron && !empty($selected['baseform'])) {
                $pron = pronunciation_manager::lookup((string)$selected['baseform'], null);
            }
            if ($pron) {
                $selected['ipa'] = $pron['ipa'] ?? null;
                $selected['xsampa'] = $pron['xsampa'] ?? null;
                $selected['nofabet'] = $pron['nofabet'] ?? null;
            }
        }

        $parts = self::split_compound($selected['lemma_id'] ?? null, $selected['wordform'] ?? $token);
        $forms = self::fetch_forms($selected['lemma_id'] ?? 0, $selected['tag'] ?? '');
        $gender = self::detect_gender_from_tag($selected['tag'] ?? '');

        return [
            'token' => $token,
            'selected' => $selected,
            'candidates' => array_values($candidates),
            'paradigm' => $paradigm,
            'parts' => $parts,
            'forms' => $forms,
            'gender' => $gender,
            'ambiguous' => $originalcount > 1,
        ];
    }

    /**
     * Normalize ordbank tag to POS string for pronunciation lookup.
     */
    protected static function normalize_tag_to_pos(string $tag): ?string {
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
                $prep = trim($prep);
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
                       l.GRUNNFORM AS baseform
                  FROM {ordbank_fullform} f
             LEFT JOIN {ordbank_lemma} l ON l.LEMMA_ID = f.LEMMA_ID
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
                $pos = self::normalize_tag_to_pos($rec->tag ?? '');
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
     * @return array<int,string>
     */
    public static function split_compound(?int $lemmaid, string $oppslag): array {
        global $DB;

        $oppslag = trim($oppslag);
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
                return array_values(array_filter(array_map('trim', explode('-', $oppslag))));
            }
            // Try naive split before final noun-like tail if "s" fuge present.
            if (preg_match('~^(.+?)(s)(bolig|hus|mann|menn|vei|gate|plass|verk|arbeid|tid|sted|by|rom|bok|bok|rett)~iu', $oppslag, $m)) {
                return array_values(array_filter([$m[1], $m[2], $m[3]]));
            }
            return [$oppslag];
        }

        $parts = array_filter([
            $rec->forledd ?? '',
            $rec->fuge ?? '',
            $rec->etterledd ?? '',
        ], fn(string $v) => $v !== '');

        return $parts ?: [$oppslag];
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
        $next = isset($context['next']) ? core_text::strtolower((string)$context['next']) : null;
        $next2 = isset($context['next2']) ? core_text::strtolower((string)$context['next2']) : null;

        $pronouns = ['jeg','du','han','hun','vi','dere','de','eg','ho','me','dei','det','den','dette','disse','hva','hvem','hvor','når'];
        $articles = ['en','ei','et','ein','eitt'];
        $determiners = ['den','det','de','denne','dette','disse','min','mitt','mi','mine','din','ditt','di','dine','sin','sitt','si','sine','hans','hennes','vår','vårt','våre','deres'];
        $auxverbs = ['er','var','har','hadde','blir','ble','vil','skal','kan','må','bør','kunne','skulle','ville'];
        $prepseg = ['om','over','for','med','til','av','på','pa','i'];
        $functionwords = ['for','til','av','på','paa','i','om','med','seg','det','som','å','åå','aa'];

        $wordLower = core_text::strtolower((string)($candidates[array_key_first($candidates)]['wordform'] ?? ''));

        $best = null;
        $bestscore = -1;
        foreach ($candidates as $cand) {
            $tag = core_text::strtolower((string)($cand['tag'] ?? ''));
            $score = 0;
            $isverb = str_contains($tag, 'verb');
            $isnoun = str_contains($tag, 'subst');
            $isadj  = str_contains($tag, 'adj');
            $isadv  = str_contains($tag, 'adv');
            $isprep = str_contains($tag, 'prep');

            $candword = core_text::strtolower((string)($cand['wordform'] ?? ''));
            // Prefer exact surface match (avoid 'for' -> 'fôr').
            if ($wordLower !== '' && $candword === $wordLower) {
                $score += 12;
            }
            // Penalize diacritics drift (e.g., 'for' vs 'fôr').
            if ($wordLower !== '' && $candword !== $wordLower && iconv('UTF-8', 'ASCII//TRANSLIT', $candword) === $wordLower) {
                $score -= 15;
            }
            // Extract arg codes from tag (<trans1>, <refl4/på>, etc.).
            $argcodes = self::extract_argcodes_from_tag($tag);

            if ($cand['paradigme_id'] ?? null) {
                $score += 1;
            }
            if ($isverb) { $score += 5; }
            if ($isnoun) { $score += 2; }
            if ($isadj)  { $score += 1; }
            if ($isadv)  { $score += 1; }
            // Function words (for/til/av/på/...) strongly prefer adv/prep/konj; penalize verb/subst.
            if (in_array(core_text::strtolower((string)($cand['wordform'] ?? '')), $functionwords, true)) {
                if ($isadv || $isprep || str_contains($tag, 'konj')) {
                    $score += 15;
                }
                if ($isverb || $isnoun) {
                    $score -= 20;
                }
            }

            if (in_array($prev, $pronouns, true) && $isverb) {
                $score += 4;
            }
            if ($prev === 'å' && $isverb) {
                $score += 3;
            }
            if ((in_array($prev, $articles, true) || in_array($prev, $determiners, true)) && $isnoun) {
                $score += 12;
            }
            // Determiners/articles strongly disfavour verbs (e.g. "et får" should be noun, not "får"=verb).
            if ((in_array($prev, $articles, true) || in_array($prev, $determiners, true)) && $isverb) {
                $score -= 10;
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
            // Valency/arg codes: reward matching prepositions or seg.
            foreach ($argcodes as $ac) {
                $code = $ac['code'] ?? '';
                $prep = $ac['prep'] ?? null;
                if ($isverb && $next === 'seg') {
                    $score += 3;
                }
                if ($prep !== null) {
                    if ($next === $prep || $prev === $prep || $next2 === $prep) {
                        $score += 4;
                    }
                }
                if ($code && str_contains($code, 'refl') && ($next === 'seg' || $next2 === 'seg')) {
                    $score += 4;
                }
            }
            // Copula + "for" + adjective => should be adverb "for" (too), not verb "fare".
            if ($prev === 'er' && $isadv && ($cand['wordform'] ?? '') === 'for' && $next !== null && !in_array($next, $articles, true)) {
                $score += 18;
            }
            if ($prev === 'er' && $isverb && ($cand['wordform'] ?? '') === 'for') {
                $score -= 15;
            }
            // Common reflexive pattern where "seg" is the second word after the verb ("dreier det seg ...").
            if ($next2 === 'seg' && $isverb) {
                $score += 6;
            }
            if ($next2 === 'seg' && $isnoun) {
                $score -= 4;
            }
            if ($isverb && (str_contains($tag, '<refl') || str_contains($tag, 'refl'))) {
                $score += 2;
            }
            // Question inversion: verb before pronoun/determiner (e.g. "Hva dreier det seg om").
            if ($next !== null && (in_array($next, $pronouns, true) || in_array($next, $determiners, true)) && $isverb) {
                $score += 8;
            }
            if ($next !== null && (in_array($next, $pronouns, true) || in_array($next, $determiners, true)) && $isnoun) {
                $score -= 3;
            }
            // Aux + participle patterns (e.g. "har slått").
            if (in_array($prev, $auxverbs, true) && $isverb && (str_contains($tag, 'perf-part') || str_contains($tag, '<perf-part>') || str_contains($tag, 'part'))) {
                $score += 6;
            }
            // Special-case: "Det er for ADJ" ("for" = adverb 'too'), not preterit of "fare".
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
            foreach ($records as $rec) {
                $t = core_text::strtolower((string)$rec->tag);
                if (!$rec->oppslag) {
                    continue;
                }
                if (str_contains($t, 'ent ub')) {
                    $noun['indef_sg'][] = $rec->oppslag;
                } elseif (str_contains($t, 'ent be')) {
                    $noun['def_sg'][] = $rec->oppslag;
                } elseif (str_contains($t, 'fl ub')) {
                    $noun['indef_pl'][] = $rec->oppslag;
                } elseif (str_contains($t, 'fl be')) {
                    $noun['def_pl'][] = $rec->oppslag;
                }
            }
            $noun = array_map(function($arr){
                $arr = array_values(array_unique(array_filter($arr)));
                return $arr;
            }, $noun);
            $noun = array_filter($noun, fn($v) => !empty($v));
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
            return 'en/ei';
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
}
