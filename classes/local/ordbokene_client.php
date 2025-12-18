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
     * Build a search URL with optional part-of-speech filter (wc).
     *
     * Ordbøkene supports wc=VERB/ADJ/ADV/... and wc reduces ambiguity for entries like "slå" (noun vs verb).
     */
    protected static function build_search_url(string $param, string $value, string $lang, string $scope = 'e', string $wc = ''): string {
        $url = self::SEARCH . '?' . $param . '=' . rawurlencode($value) . '&dict=' . ($lang === 'begge' ? 'bm,nn' : $lang) . '&scope=' . rawurlencode($scope);
        $wc = trim($wc);
        if ($wc !== '') {
            $url .= '&wc=' . rawurlencode($wc);
        }
        return $url;
    }

    /**
     * Lookup any expressions/lemma for a given span (multiword).
     * Returns ['expressions'=>[], 'baseform'=>string, 'dictmeta'=>[]]
     */
    public static function search_expressions(string $span, string $lang = 'begge', string $wc = ''): array {
        $span = trim($span);
        if ($span === '') {
            return [];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $curl = new \curl();
        $searches = [];
        // Primary: legacy article search with w= (supports simple phrases).
        $searches[] = self::build_search_url('w', $span, $lang, 'e', $wc);
        // Fallback: ord_2 API style with q= to match mid-phrase expressions (observed on ordbokene.no).
        $searches[] = self::build_search_url('q', $span, $lang, 'e', $wc);

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
     * @param string $wc Optional part-of-speech filter, e.g. VERB/ADJ/ADV
     * @return array Structured data (grunnform, forms, expressions, meanings, examples, meta)
     */
    public static function lookup(string $word, string $lang = 'begge', string $wc = ''): array {
        $word = trim($word);
        if ($word === '') {
            return [];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $searchurl = self::build_search_url('w', $word, $lang, 'e', $wc);
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
     * Fetch multiple dictionary entries for an ambiguous word (e.g. leie has verbs+nouns).
     * Returns a list of normalized articles with dict/lang/meta to let callers pick best sense.
     *
     * @param string $word
     * @param string $lang bm|nn|begge
     * @param int $limit total articles to fetch
     * @param string $wc Optional part-of-speech filter
     * @return array<int,array<string,mixed>>
     */
    public static function lookup_all(string $word, string $lang = 'begge', int $limit = 6, string $wc = ''): array {
        $word = trim($word);
        if ($word === '') {
            return [];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $searchurl = self::build_search_url('w', $word, $lang, 'e', $wc);
        $out = [];
        try {
            $curl = new \curl();
            $resp = $curl->get($searchurl);
            $search = json_decode($resp, true);
            if (!is_array($search) || empty($search['articles'])) {
                return [];
            }
            // Flatten ids per dict preserving order.
            $dictOrder = ['bm', 'nn'];
            foreach ($dictOrder as $dict) {
                if (empty($search['articles'][$dict]) || !is_array($search['articles'][$dict])) {
                    continue;
                }
                foreach (array_slice($search['articles'][$dict], 0, $limit) as $id) {
                    if (count($out) >= $limit) {
                        break 2;
                    }
                    $articleurl = sprintf(self::ARTICLE, $dict, (int)$id);
                    try {
                        $resp2 = $curl->get($articleurl);
                        $article = json_decode($resp2, true);
                        if (!is_array($article)) {
                            continue;
                        }
                        $norm = self::normalize_article($article, $dict, $articleurl);
                        if (!empty($norm)) {
                            $out[] = array_merge($norm, [
                                'dict' => $dict,
                                'id' => (int)$id,
                                'dictmeta' => ['lang' => $dict, 'url' => $articleurl],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
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
                $isperfpart = in_array('perfect_participle', $tags, true) || in_array('perfektum_partisipp', $tags, true) || in_array('<perfpart>', $tags, true);
                if (in_array('infinitive', $tags) || in_array('infinitiv', $tags)) {
                    $forms['verb']['infinitiv'][] = $wf;
                }
                if (in_array('present', $tags) || in_array('presens', $tags)) {
                    $forms['verb']['presens'][] = $wf;
                }
                if (in_array('past', $tags) || in_array('preteritum', $tags)) {
                    $forms['verb']['preteritum'][] = $wf;
                }
                if ($isperfpart) {
                    $forms['verb']['perfektum_partisipp'][] = $wf;
                    // Capture detailed gender/number variants to mirror ordbokene table.
                    $details = &$forms['verb']['perfektum_partisipp_detailed'];
                    if (in_array('masc/fem', $tags, true) || in_array('mask/fem', $tags, true) || in_array('mask', $tags, true) || in_array('fem', $tags, true)) {
                        $details['masc_fem'][] = $wf;
                    }
                    if (in_array('neuter', $tags, true) || in_array('neut', $tags, true)) {
                        $details['neuter'][] = $wf;
                    }
                    if (in_array('def', $tags, true) || in_array('definite', $tags, true) || in_array('best', $tags, true)) {
                        $details['definite'][] = $wf;
                    }
                    if (in_array('plur', $tags, true) || in_array('plural', $tags, true) || in_array('flertall', $tags, true)) {
                        $details['plural'][] = $wf;
                    }
                }
                if (in_array('<prespart>', $tags, true) || in_array('presens_partisipp', $tags, true) || in_array('prespart', $tags, true)) {
                    $forms['verb']['presens_partisipp'][] = $wf;
                }
                if (in_array('imperative', $tags) || in_array('imperativ', $tags)) {
                    $forms['verb']['imperativ'][] = $wf;
                }
            }
        }
        // Deduplicate
        if (!empty($forms['verb'])) {
            foreach ($forms['verb'] as $key => $arr) {
                if ($key === 'perfektum_partisipp_detailed' && is_array($arr)) {
                    foreach ($arr as $subkey => $subvals) {
                        $forms['verb'][$key][$subkey] = array_values(array_unique(array_filter($subvals)));
                    }
                    // Drop empty detail buckets to keep payload lean.
                    $forms['verb'][$key] = array_filter($forms['verb'][$key], fn($v) => !empty($v));
                    continue;
                }
                $forms['verb'][$key] = array_values(array_unique(array_filter($arr)));
            }
            // Derive presens perfektum from available participles to match ordbokene layout.
            $derived = [];
            foreach ($forms['verb']['perfektum_partisipp'] ?? [] as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $derived[] = 'har ' . $part;
                }
            }
            $existing = $forms['verb']['presens_perfektum'] ?? [];
            $forms['verb']['presens_perfektum'] = array_values(array_unique(array_filter(array_merge($existing, $derived))));
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
                        $clean = trim($content);
                        // Some articles contain placeholder content like "$" - ignore it.
                        if ($clean === '$') {
                            continue;
                        }
                        $out[] = $clean;
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
                    $clean = trim((string)$el['quote']['content']);
                    if ($clean === '' || $clean === '$') {
                        continue;
                    }
                    $out[] = $clean;
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }
}
