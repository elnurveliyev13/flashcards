<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_flashcards\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight client for ord.uib.no (Ordbøkene) API.
 * No API key is required per official docs.
 */
class ordbokene_client {
    /** @var string base URL */
    protected const BASE = 'https://ord.uib.no/api/ordbok';

    /**
     * Lookup a word/expression.
     *
     * @param string $word Word or phrase
     * @param string $lang bm|nn|begge
     * @return array Structured data (grunnform, forms, expressions, meanings, examples, meta)
     */
    public static function lookup(string $word, string $lang = 'begge'): array {
        $word = trim($word);
        if ($word === '') {
            return [];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $url = self::BASE . '/' . $lang . '/' . rawurlencode($word);

        try {
            $curl = new \curl();
            $resp = $curl->get($url);
            if ($resp === false || $resp === '') {
                return [];
            }
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                return [];
            }
            return self::normalize($data, $lang, $url);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalize API response to internal structure.
     *
     * @param array $payload
     * @param string $lang
     * @param string $url
     * @return array
     */
    protected static function normalize(array $payload, string $lang, string $url): array {
        $out = [
            'source' => 'ordbokene',
            'dictmeta' => ['lang' => $lang, 'url' => $url],
            'baseform' => '',
            'pos' => '',
            'forms' => [],
            'expressions' => [],
            'meanings' => [],
            'examples' => [],
        ];

        // Grunnform / oppslag.
        $out['baseform'] = $payload['oppslag'] ?? ($payload['lemma'] ?? '');

        // Try to pick first article.
        $articles = $payload['artikler'] ?? $payload['artikkel'] ?? [];
        if (!is_array($articles)) {
            $articles = [];
        }
        $first = [];
        if (!empty($articles)) {
            $first = $articles[0];
        } elseif (!empty($payload)) {
            $first = $payload;
        }

        // POS (ordklasse).
        if (!empty($first['ordklasse'])) {
            $out['pos'] = $first['ordklasse'];
        }

        // Bøyning.
        if (!empty($first['bøyning']) && is_array($first['bøyning'])) {
            $out['forms'] = self::extract_forms($first['bøyning']);
        }

        // Betydning / bruk.
        if (!empty($first['betydning']) && is_array($first['betydning'])) {
            foreach ($first['betydning'] as $b) {
                if (!empty($b['definisjon'])) {
                    $out['meanings'][] = trim($b['definisjon']);
                }
            }
        }

        // Eksempel.
        if (!empty($first['eksempel']) && is_array($first['eksempel'])) {
            foreach ($first['eksempel'] as $ex) {
                if (!empty($ex['tekst'])) {
                    $out['examples'][] = trim($ex['tekst']);
                }
            }
        }

        // Faste uttrykk.
        if (!empty($first['faste_uttrykk']) && is_array($first['faste_uttrykk'])) {
            foreach ($first['faste_uttrykk'] as $fx) {
                if (!empty($fx['uttrykk'])) {
                    $out['expressions'][] = trim($fx['uttrykk']);
                }
            }
        }

        return array_filter($out, fn($v) => !empty($v));
    }

    /**
     * Extract verb/noun forms from bøyning-array.
     *
     * @param array $b
     * @return array
     */
    protected static function extract_forms(array $b): array {
        $forms = [];
        // The API may expose slot names; we map them to our internal keys.
        $map = [
            'infinitiv' => ['infinitiv'],
            'presens' => ['presens'],
            'preteritum' => ['preteritum'],
            'presens perfektum' => ['presens_perfektum', 'perfektum_presens'],
            'presens_perfektum' => ['presens_perfektum'],
            'imperativ' => ['imperativ'],
            'presens partisipp' => ['presens_partisipp'],
            'perfektum partisipp' => ['perfektum_partisipp'],
        ];
        foreach ($b as $slot => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($map as $needle => $targets) {
                if (mb_stripos($slot, $needle) !== false) {
                    foreach ($targets as $t) {
                        if (!isset($forms['verb'])) {
                            $forms['verb'] = [];
                        }
                        $forms['verb'][$t] = array_values(array_unique(array_filter($values)));
                    }
                }
            }
        }
        return $forms;
    }
}
