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

        $parts = self::split_compound($selected['lemma_id'] ?? null, $selected['wordform'] ?? $token);

        return [
            'token' => $token,
            'selected' => $selected,
            'candidates' => array_values($candidates),
            'paradigm' => $paradigm,
            'parts' => $parts,
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

        // Deduplicate by lemma + tag + paradigme.
        $out = [];
        foreach ($records as $rec) {
            $key = implode('|', [
                (int)$rec->lemma_id,
                (string)$rec->tag,
                (string)$rec->paradigme_id,
                (int)$rec->boy_nummer,
            ]);
            if (!isset($out[$key])) {
                $out[$key] = [
                    'lemma_id' => (int)$rec->lemma_id,
                    'wordform' => $rec->wordform,
                    'tag' => $rec->tag,
                    'paradigme_id' => $rec->paradigme_id,
                    'boy_nummer' => (int)$rec->boy_nummer,
                    'ipa' => $rec->ipa_from_dict ?? null,
                    'xsampa' => $rec->xsampa ?? null,
                    'nofabet' => $rec->nofabet ?? null,
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
     * @param array $context e.g. ['prev' => 'en', 'next' => '...']
     * @return array<string,mixed>|null
     */
    protected static function narrow_by_context(array $candidates, array $context): ?array {
        if (count($candidates) <= 1) {
            return $candidates ? reset($candidates) : null;
        }

        $prev = isset($context['prev']) ? core_text::strtolower((string)$context['prev']) : null;
        $next = isset($context['next']) ? core_text::strtolower((string)$context['next']) : null;

        $pronouns = ['jeg','du','han','hun','vi','dere','de','eg','ho','me','dei'];
        $articles = ['en','ei','et','ein','eitt'];

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

            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $cand;
            }
        }

        return $best ?: ($candidates ? reset($candidates) : null);
    }
}
