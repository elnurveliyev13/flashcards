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

        $sql = "SELECT f.LEMMA_ID,
                       f.OPPSLAG AS wordform,
                       f.TAG,
                       f.PARADIGME_ID,
                       f.BOY_NUMMER,
                       l.GRUNNFORM AS baseform,
                       p.ipa AS ipa_from_dict,
                       p.xsampa,
                       p.nofabet
                  FROM {ordbank_fullform} f
             LEFT JOIN {ordbank_lemma} l ON l.LEMMA_ID = f.LEMMA_ID
             LEFT JOIN {flashcards_pron_dict} p ON LOWER(p.wordform) = LOWER(f.OPPSLAG)
                 WHERE LOWER(f.OPPSLAG) = :w";

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
                $ipa = $rec->ipa_from_dict ?? null;
                $xsampa = $rec->xsampa ?? null;
                $nofabet = $rec->nofabet ?? null;
                // Fallback to pron dict by surface and baseform if IPA is still empty.
                if (empty($ipa)) {
                    $pronSurface = \mod_flashcards\local\pronunciation_manager::lookup((string)$rec->wordform, null);
                    if ($pronSurface) {
                        $ipa = $pronSurface['ipa'] ?? $ipa;
                        $xsampa = $pronSurface['xsampa'] ?? $xsampa;
                        $nofabet = $pronSurface['nofabet'] ?? $nofabet;
                    }
                }
                if (empty($ipa) && !empty($rec->baseform)) {
                    $pron = \mod_flashcards\local\pronunciation_manager::lookup((string)$rec->baseform, null);
                    if ($pron) {
                        $ipa = $pron['ipa'] ?? $ipa;
                        $xsampa = $pron['xsampa'] ?? $xsampa;
                        $nofabet = $pron['nofabet'] ?? $nofabet;
                    }
                }
                // Final DB fallback: direct select from flashcards_pron_dict (surface then baseform).
                if (empty($ipa)) {
                    try {
                        global $DB;
                        $p = $DB->get_record_select('flashcards_pron_dict', 'LOWER(wordform) = ?', [core_text::strtolower((string)$rec->wordform)], '*', IGNORE_MULTIPLE);
                        if (!$p && !empty($rec->baseform)) {
                            $p = $DB->get_record_select('flashcards_pron_dict', 'LOWER(wordform) = ?', [core_text::strtolower((string)$rec->baseform)], '*', IGNORE_MULTIPLE);
                        }
                        if ($p) {
                            $ipa = $p->ipa ?? $ipa;
                            $xsampa = $p->xsampa ?? $xsampa;
                            $nofabet = $p->nofabet ?? $nofabet;
                        }
                    } catch (\Throwable $e) {
                        // ignore pronunciation lookup errors
                    }
                }
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

        $pronouns = ['jeg','du','han','hun','vi','dere','de','eg','ho','me','dei','det'];
        $articles = ['en','ei','et','ein','eitt'];
        $prepseg = ['om','over','for','med','til','av','på','pa','i'];

        $best = null;
        $bestscore = -1;
        foreach ($candidates as $cand) {
            $tag = core_text::strtolower((string)($cand['tag'] ?? ''));
            $score = 0;
            $isverb = str_contains($tag, 'verb');
            $isnoun = str_contains($tag, 'subst');
            $isadj  = str_contains($tag, 'adj');

            if ($cand['paradigme_id'] ?? null) {
                $score += 1;
            }
            if ($isverb) { $score += 5; }
            if ($isnoun) { $score += 2; }
            if ($isadj)  { $score += 1; }

            if (in_array($prev, $pronouns, true) && $isverb) {
                $score += 4;
            }
            if ($prev === 'å' && $isverb) {
                $score += 3;
            }
            if (in_array($prev, $articles, true) && $isnoun) {
                $score += 3;
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
