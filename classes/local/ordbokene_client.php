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
    /** @var string base URL for search */
    protected const SEARCH = 'https://ord.uib.no/api/articles';
    /** @var string base URL for article fetch */
    protected const ARTICLE = 'https://ord.uib.no/%s/article/%d.json';

    /**
     * Lookup any expressions/lemma for a given span (multiword).
     * Returns ['expressions'=>[], 'baseform'=>string, 'dictmeta'=>[]]
     */
    public static function search_expressions(string $span, string $lang = 'begge'): array {
        $span = trim($span);
        if ($span === '') {
            return [];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $curl = new \curl();
        $searches = [];
        // Primary: legacy article search with w= (supports simple phrases).
        $searches[] = self::SEARCH . '?w=' . rawurlencode($span) . '&dict=' . ($lang === 'begge' ? 'bm,nn' : $lang) . '&scope=e';
        // Fallback: ord_2 API style with q= to match mid-phrase expressions (observed on ordbokene.no).
        $searches[] = self::SEARCH . '?q=' . rawurlencode($span) . '&dict=' . ($lang === 'begge' ? 'bm,nn' : $lang) . '&scope=e';

        foreach ($searches as $searchurl) {
            try {
                $resp = $curl->get($searchurl);
                $search = json_decode($resp, true);
                if (!is_array($search) || empty($search['articles'])) {
                    continue;
                }
                $articleid = null;
                $articlelang = null;
                if (!empty($search['articles']['bm'][0])) {
                    $articleid = (int)$search['articles']['bm'][0];
                    $articlelang = 'bm';
                } else if (!empty($search['articles']['nn'][0])) {
                    $articleid = (int)$search['articles']['nn'][0];
                    $articlelang = 'nn';
                }
                if (!$articleid || !$articlelang) {
                    continue;
                }
                $articleurl = sprintf(self::ARTICLE, $articlelang, $articleid);
                $resp2 = $curl->get($articleurl);
                $article = json_decode($resp2, true);
                if (!is_array($article)) {
                    continue;
                }
                $norm = self::normalize_article($article, $articlelang, $articleurl);
                if (!empty($norm)) {
                    return $norm;
                }
            } catch (\Throwable $e) {
                // Try next fallback.
                continue;
            }
        }
        return [];
    }

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
        $searchurl = self::SEARCH . '?w=' . rawurlencode($word) . '&dict=' . ($lang === 'begge' ? 'bm,nn' : $lang) . '&scope=e';
        try {
            $curl = new \curl();
            $resp = $curl->get($searchurl);
            $search = json_decode($resp, true);
            if (!is_array($search) || empty($search['articles'])) {
                return [];
            }
            $articleid = null;
            $articlelang = null;
            if (!empty($search['articles']['bm'][0])) {
                $articleid = (int)$search['articles']['bm'][0];
                $articlelang = 'bm';
            } else if (!empty($search['articles']['nn'][0])) {
                $articleid = (int)$search['articles']['nn'][0];
                $articlelang = 'nn';
            }
            if (!$articleid || !$articlelang) {
                return [];
            }
            $articleurl = sprintf(self::ARTICLE, $articlelang, $articleid);
            $resp2 = $curl->get($articleurl);
            $article = json_decode($resp2, true);
            if (!is_array($article)) {
                return [];
            }
            return self::normalize_article($article, $articlelang, $articleurl);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalize article json to internal structure.
     *
     * @param array $article
     * @param string $lang
     * @param string $url
     * @return array
     */
    protected static function normalize_article(array $article, string $lang, string $url): array {
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

        // Baseform from first lemma.
        if (!empty($article['lemmas'][0]['lemma'])) {
            $out['baseform'] = $article['lemmas'][0]['lemma'];
        }
        // Forms from paradigm_info.
        $out['forms'] = self::extract_forms_from_paradigm($article['lemmas'][0]['paradigm_info'] ?? []);
        // Expressions from sub-articles in definitions.
        $out['expressions'] = self::extract_expressions($article['body']['definitions'] ?? []);
        // Meanings/examples: take first definition explanation and examples if any.
        $out['meanings'] = self::extract_meanings($article['body']['definitions'] ?? []);
        $out['examples'] = self::extract_examples($article['body']['definitions'] ?? []);

        return array_filter($out, fn($v) => !empty($v));
    }

    protected static function extract_forms_from_paradigm(array $paradigm): array {
        $forms = [];
        foreach ($paradigm as $p) {
            if (empty($p['inflection']) || !is_array($p['inflection'])) {
                continue;
            }
            foreach ($p['inflection'] as $inf) {
                if (empty($inf['tags']) || empty($inf['word_form'])) {
                    continue;
                }
                $tags = array_map('strtolower', $inf['tags']);
                $wf = $inf['word_form'];
                if (in_array('infinitive', $tags) || in_array('infinitiv', $tags)) {
                    $forms['verb']['infinitiv'][] = $wf;
                }
                if (in_array('present', $tags) || in_array('presens', $tags)) {
                    $forms['verb']['presens'][] = $wf;
                }
                if (in_array('past', $tags) || in_array('preteritum', $tags)) {
                    $forms['verb']['preteritum'][] = $wf;
                }
                if (in_array('perfect_participle', $tags) || in_array('perfektum_partisipp', $tags)) {
                    $forms['verb']['perfektum_partisipp'][] = $wf;
                }
                if (in_array('imperative', $tags) || in_array('imperativ', $tags)) {
                    $forms['verb']['imperativ'][] = $wf;
                }
            }
        }
        // Deduplicate
        if (!empty($forms['verb'])) {
            $forms['verb'] = array_map(function($arr){
                return array_values(array_unique(array_filter($arr)));
            }, $forms['verb']);
        }
        return $forms;
    }

    protected static function extract_expressions(array $definitions): array {
        $expr = [];
        foreach ($definitions as $def) {
            foreach ($def['elements'] ?? [] as $el) {
                if (($el['type_'] ?? '') === 'sub_article' && !empty($el['lemmas'])) {
                    foreach ($el['lemmas'] as $l) {
                        if (is_string($l) && $l !== '') {
                            $expr[] = $l;
                        }
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($expr)));
    }

    protected static function extract_meanings(array $definitions): array {
        $out = [];
        foreach ($definitions as $def) {
            foreach ($def['elements'] ?? [] as $el) {
                if (($el['type_'] ?? '') === 'definition' || ($el['type_'] ?? '') === 'explanation') {
                    $content = $el['content'] ?? '';
                    if (is_string($content) && trim($content) !== '') {
                        $out[] = trim($content);
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    protected static function extract_examples(array $definitions): array {
        $out = [];
        foreach ($definitions as $def) {
            foreach ($def['elements'] ?? [] as $el) {
                if (($el['type_'] ?? '') === 'example' && !empty($el['quote']['content'])) {
                    $out[] = trim($el['quote']['content']);
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }
}
