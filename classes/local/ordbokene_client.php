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
    /** @var int max articles to inspect for exact lemma match */
    protected const MATCH_LIMIT = 6;
    /** @var string freetext/lemma article listing endpoint */
    protected const ARTICLES = 'https://ord.uib.no/api/articles';

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
     * List matching article IDs via /api/articles (supports scope=f freetext).
     *
     * @param string $w Query string (supports wildcards and | concatenation)
     * @param string $lang bm|nn|begge
     * @param string $scope e|f|i|ef|...
     * @param string $wc Optional part-of-speech filter, e.g. VERB/ADJ/ADV
     * @return array{bm:array<int,int>,nn:array<int,int>}
     */
    public static function list_article_ids(string $w, string $lang = 'begge', string $scope = 'e', string $wc = ''): array {
        $w = trim($w);
        if ($w === '') {
            return ['bm' => [], 'nn' => []];
        }
        $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
        $scope = trim($scope) !== '' ? trim($scope) : 'e';
        $url = self::ARTICLES . '?w=' . rawurlencode($w) . '&dict=' . ($lang === 'begge' ? 'bm,nn' : $lang) . '&scope=' . rawurlencode($scope);
        $wc = trim($wc);
        if ($wc !== '') {
            $url .= '&wc=' . rawurlencode($wc);
        }
        try {
            $curl = new \curl();
            $resp = $curl->get($url);
            $data = json_decode($resp, true);
            if (!is_array($data) || empty($data['articles']) || !is_array($data['articles'])) {
                return ['bm' => [], 'nn' => []];
            }
            $bm = !empty($data['articles']['bm']) && is_array($data['articles']['bm']) ? array_map('intval', $data['articles']['bm']) : [];
            $nn = !empty($data['articles']['nn']) && is_array($data['articles']['nn']) ? array_map('intval', $data['articles']['nn']) : [];
            return ['bm' => $bm, 'nn' => $nn];
        } catch (\Throwable $e) {
            return ['bm' => [], 'nn' => []];
        }
    }

    /**
     * Fetch and normalize an article by ID.
     *
     * @param int $articleid
     * @param string $dict bm|nn
     * @return array Normalized article payload or empty array
     */
    public static function fetch_article(int $articleid, string $dict = 'bm'): array {
        $dict = in_array($dict, ['bm', 'nn'], true) ? $dict : 'bm';
        if ($articleid <= 0) {
            return [];
        }
        try {
            $articleurl = sprintf(self::ARTICLE, $dict, $articleid);
            $curl = new \curl();
            $resp = $curl->get($articleurl);
            $article = json_decode($resp, true);
            if (!is_array($article)) {
                return [];
            }
            return self::normalize_article($article, $dict, $articleurl);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function normalize_lemma_key(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = core_text::strtolower($text);
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;
        return $text;
    }

    /**
     * Fetch multiple URLs in parallel (best-effort).
     *
     * @param array<int,string> $urls
     * @param int $timeout
     * @return array<int,array{body:string,http:int}>
     */
    protected static function fetch_urls_parallel(array $urls, int $timeout = 10): array {
        $urls = array_values(array_filter($urls, 'is_string'));
        if (empty($urls) || !function_exists('curl_multi_init')) {
            return [];
        }
        global $CFG;
        $proxyhost = trim((string)($CFG->proxyhost ?? ''));
        $proxyport = (int)($CFG->proxyport ?? 0);
        $proxyuser = trim((string)($CFG->proxyuser ?? ''));
        $proxypass = (string)($CFG->proxypassword ?? '');
        $proxytype = strtolower(trim((string)($CFG->proxytype ?? '')));
        $noproxy = trim((string)($CFG->proxybypass ?? ($CFG->noproxy ?? '')));
        $proxytypeMap = [
            'http' => CURLPROXY_HTTP,
            'https' => defined('CURLPROXY_HTTPS') ? CURLPROXY_HTTPS : CURLPROXY_HTTP,
            'socks5' => CURLPROXY_SOCKS5,
            'socks4' => CURLPROXY_SOCKS4,
            'socks4a' => defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4,
        ];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $idx => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($proxyhost !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxyhost);
                if ($proxyport > 0) {
                    curl_setopt($ch, CURLOPT_PROXYPORT, $proxyport);
                }
                if ($proxyuser !== '') {
                    $auth = $proxyuser;
                    if ($proxypass !== '') {
                        $auth .= ':' . $proxypass;
                    }
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
                }
                if (isset($proxytypeMap[$proxytype])) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, $proxytypeMap[$proxytype]);
                }
                if ($noproxy !== '') {
                    curl_setopt($ch, CURLOPT_NOPROXY, $noproxy);
                }
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);
        $responses = [];
        foreach ($handles as $idx => $ch) {
            $body = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $responses[$idx] = [
                'body' => is_string($body) ? $body : '',
                'http' => (int)($info['http_code'] ?? 0),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $responses;
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

        $parallel = self::fetch_urls_parallel($searches, 10);
        if (!empty($parallel)) {
            foreach ($searches as $idx => $searchurl) {
                $entry = $parallel[$idx] ?? null;
                $http = is_array($entry) ? (int)($entry['http'] ?? 0) : 0;
                $resp = is_array($entry) ? (string)($entry['body'] ?? '') : '';
                if ($http < 200 || $http >= 300 || $resp === '') {
                    continue;
                }
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
                $curl = new \curl();
                $resp2 = $curl->get($articleurl);
                $article = json_decode($resp2, true);
                if (!is_array($article)) {
                    continue;
                }
                $norm = self::normalize_article($article, $articlelang, $articleurl);
                if (!empty($norm)) {
                    return $norm;
                }
            }
            return [];
        }
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
            $target = self::normalize_lemma_key($word);
            $fallback = null;
            $dictOrder = ['bm', 'nn'];
            $checked = 0;
            foreach ($dictOrder as $dict) {
                if (empty($search['articles'][$dict]) || !is_array($search['articles'][$dict])) {
                    continue;
                }
                foreach ($search['articles'][$dict] as $id) {
                    if ($checked >= self::MATCH_LIMIT) {
                        break 2;
                    }
                    $checked++;
                    $articleurl = sprintf(self::ARTICLE, $dict, (int)$id);
                    $resp2 = $curl->get($articleurl);
                    $article = json_decode($resp2, true);
                    if (!is_array($article)) {
                        continue;
                    }
                    $lemma = self::normalize_lemma_key((string)($article['lemmas'][0]['lemma'] ?? ''));
                    $norm = self::normalize_article($article, $dict, $articleurl);
                    if ($fallback === null && !empty($norm)) {
                        $fallback = $norm;
                    }
                    if ($lemma !== '' && $target !== '' && $lemma === $target && !empty($norm)) {
                        return $norm;
                    }
                }
            }
            return $fallback ?? [];
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
            'variants' => [],
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
        // Orthographic variants from lemma list, as shown in Ordbøkene header (e.g. "løse, løyse").
        if (!empty($article['lemmas']) && is_array($article['lemmas'])) {
            $vars = [];
            foreach ($article['lemmas'] as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $lemma = trim((string)($l['lemma'] ?? ''));
                if ($lemma === '') {
                    continue;
                }
                $lemma = preg_replace('/\\s+/u', ' ', $lemma) ?? $lemma;
                $vars[] = $lemma;
            }
            $vars = array_values(array_unique(array_filter($vars)));
            if (count($vars) >= 2) {
                $out['variants'] = $vars;
            }
        }
        if (!empty($article['word_class'])) {
            $out['pos'] = (string)$article['word_class'];
        }
        // Forms from paradigm_info.
        $out['forms'] = self::extract_forms_from_paradigm($article['lemmas'][0]['paradigm_info'] ?? []);
        // Expressions from sub-articles in definitions.
        $out['expressions'] = self::extract_expressions($article['body']['definitions'] ?? []);
        // Meanings/examples: collect from nested definition elements.
        $definitions = $article['body']['definitions'] ?? [];
        $defelements = self::collect_definition_elements($definitions);
        $senses = self::extract_senses($definitions, (string)($out['baseform'] ?? ''));
        if (!empty($senses)) {
            $out['senses'] = $senses;
            $out['meanings'] = array_values(array_unique(array_filter(array_map(function($sense) {
                return is_array($sense) ? trim((string)($sense['meaning'] ?? '')) : '';
            }, $senses))));
        } else {
            $out['meanings'] = self::extract_meanings($defelements);
        }
        $out['examples'] = self::extract_examples($defelements, (string)($out['baseform'] ?? ''));

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

    protected static function collect_definition_elements(array $definitions): array {
        $out = [];
        $stack = $definitions;
        while (!empty($stack)) {
            $def = array_shift($stack);
            if (!is_array($def)) {
                continue;
            }
            $elements = $def['elements'] ?? [];
            if (!is_array($elements)) {
                continue;
            }
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $out[] = $el;
                if (!empty($el['elements']) && is_array($el['elements'])) {
                    $stack[] = ['elements' => $el['elements']];
                }
            }
        }
        return $out;
    }

    protected static function collect_definition_elements_for_definition(array $definition): array {
        $out = [];
        $stack = [$definition];
        while (!empty($stack)) {
            $def = array_shift($stack);
            if (!is_array($def)) {
                continue;
            }
            $elements = $def['elements'] ?? [];
            if (!is_array($elements)) {
                continue;
            }
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $out[] = $el;
                if (!empty($el['elements']) && is_array($el['elements'])) {
                    $stack[] = ['elements' => $el['elements']];
                }
            }
        }
        return $out;
    }

    protected static function extract_sub_article_labels(array $elements): array {
        $labels = [];
        foreach ($elements as $el) {
            if (($el['type_'] ?? '') !== 'sub_article' || empty($el['lemmas'])) {
                continue;
            }
            foreach ($el['lemmas'] as $l) {
                if (is_string($l) && trim($l) !== '') {
                    $labels[] = trim($l);
                } else if (is_array($l) && !empty($l['lemma'])) {
                    $lemma = trim((string)$l['lemma']);
                    if ($lemma !== '') {
                        $labels[] = $lemma;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($labels)));
    }

    protected static function extract_senses(array $definitions, string $lemma = ''): array {
        $senses = [];
        foreach ($definitions as $def) {
            if (!is_array($def)) {
                continue;
            }

            // Prefer splitting senses by inner "definition" blocks when present.
            // This prevents mixing examples from one meaning into another (e.g. "ta på seg" clothing vs responsibility).
            $subdefs = [];
            if (!empty($def['elements']) && is_array($def['elements'])) {
                foreach ($def['elements'] as $el) {
                    if (is_array($el) && ($el['type_'] ?? '') === 'definition') {
                        $subdefs[] = $el;
                    }
                }
            }

            $defBlocks = !empty($subdefs) ? $subdefs : [$def];
            foreach ($defBlocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $elements = self::collect_definition_elements_for_definition($block);
                if (empty($elements)) {
                    continue;
                }
                $meanings = self::extract_meanings($elements);
                $examples = self::extract_examples($elements, $lemma);
                if (empty($meanings)) {
                    $labels = self::extract_sub_article_labels($elements);
                    foreach ($labels as $label) {
                        $senses[] = [
                            'meaning' => $label,
                            'examples' => $examples,
                        ];
                    }
                    continue;
                }
                foreach ($meanings as $meaning) {
                    $senses[] = [
                        'meaning' => $meaning,
                        'examples' => $examples,
                    ];
                }
            }
        }
        return $senses;
    }

    protected static function extract_expressions(array $definitions): array {
        $expr = [];
        $elements = self::collect_definition_elements($definitions);
        foreach ($elements as $el) {
            if (($el['type_'] ?? '') === 'sub_article' && !empty($el['lemmas'])) {
                foreach ($el['lemmas'] as $l) {
                    if (is_string($l) && $l !== '') {
                        $expr[] = $l;
                    } else if (is_array($l) && !empty($l['lemma'])) {
                        $lemma = trim((string)$l['lemma']);
                        if ($lemma !== '') {
                            $expr[] = $lemma;
                        }
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($expr)));
    }

    protected static function extract_meanings(array $elements): array {
        $out = [];
        foreach ($elements as $el) {
            if (($el['type_'] ?? '') === 'definition' || ($el['type_'] ?? '') === 'explanation') {
                $content = $el['content'] ?? '';
                if (is_string($content) && trim($content) !== '') {
                    $clean = trim($content);
                    // Drop pure cross-references like "Se:" / "Se: X" which are not meanings.
                    // (They otherwise become a misleading "meaning" for expressions like "ta på seg".)
                    if (preg_match('~^(se|sjå)\\s*:?\\s*\\S*\\s*$~iu', $clean)) {
                        continue;
                    }
                    // Some articles contain placeholder content like "$" - ignore/strip it.
                    if (str_contains($clean, '$')) {
                        $clean = trim(preg_replace('/\\s*\\$+\\s*/u', ' ', $clean) ?? '');
                    }
                    if ($clean === '' || $clean === '$') {
                        continue;
                    }
                    // Drop degenerate leftovers like "og" after placeholder stripping.
                    if (mb_strlen($clean) < 3) {
                        continue;
                    }
                    $out[] = $clean;
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    protected static function extract_examples(array $elements, string $lemma = ''): array {
        $out = [];
        foreach ($elements as $el) {
            if (($el['type_'] ?? '') === 'example' && !empty($el['quote']['content'])) {
                $clean = trim((string)$el['quote']['content']);
                if (str_contains($clean, '$')) {
                    $usage = '';
                    if (!empty($el['quote']['items']) && is_array($el['quote']['items'])) {
                        foreach ($el['quote']['items'] as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $itemtype = (string)($item['type_'] ?? $item['type'] ?? '');
                            if ($itemtype === 'usage' && !empty($item['text'])) {
                                $usage = trim((string)$item['text']);
                                if ($usage !== '') {
                                    break;
                                }
                            }
                        }
                    }
                    $replacement = $usage !== '' ? $usage : $lemma;
                    if ($replacement !== '') {
                        $clean = preg_replace('/\\$+/u', $replacement, $clean) ?? $clean;
                    } else {
                        $clean = trim(preg_replace('/\\s*\\$+\\s*/u', ' ', $clean) ?? '');
                    }
                }
                $clean = trim(preg_replace('/\\s+/u', ' ', $clean) ?? '');
                if ($clean === '' || $clean === '$') {
                    continue;
                }
                if (mb_strlen($clean) < 3) {
                    continue;
                }
                $out[] = $clean;
            }
        }
        return array_values(array_unique(array_filter($out)));
    }
}
