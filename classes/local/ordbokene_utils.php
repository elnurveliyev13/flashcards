<?php
// Utility helpers for Ordbøkene expression discovery.

defined('MOODLE_INTERNAL') || die();

use mod_flashcards\local\ordbank_helper;
use mod_flashcards\local\ordbokene_client;

/**
 * Build expression candidates (lemmas + constrained n-grams starting with baselemma).
 *
 * @param string $fronttext Full sentence
 * @param string $base Base form / clicked word (may be inflected)
 * @return array
 */
function mod_flashcards_build_expression_candidates(string $fronttext, string $base): array {
    $cands = [];

    $spacyLemmas = [];
    try {
        $spacy = new \mod_flashcards\local\spacy_client();
        if ($spacy->is_enabled()) {
            $resp = $spacy->analyze_text($fronttext);
            if (!empty($resp['tokens']) && is_array($resp['tokens'])) {
                foreach ($resp['tokens'] as $tok) {
                    if (empty($tok['is_alpha'])) {
                        continue;
                    }
                    $lemma = core_text::strtolower((string)($tok['lemma'] ?? $tok['text'] ?? ''));
                    $lemma = trim($lemma);
                    if ($lemma !== '') {
                        $spacyLemmas[] = $lemma;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore spaCy failures and fall back to Ordbank-only logic.
        $spacyLemmas = [];
    }

    // Lemma for base (prefer verb lemma if any).
    $baseanalysis = ordbank_helper::analyze_token($base, []);
    $baselemma = core_text::strtolower(trim($baseanalysis['selected']['baseform'] ?? $base));
    // Heuristic: if same as surface and ends with "er", try replacing with "e" once.
    if ($baselemma === core_text::strtolower($base) && preg_match('~er$~', $baselemma)) {
        $guess = preg_replace('~er$~', 'e', $baselemma);
        if ($guess && $guess !== $baselemma) {
            $baselemma = $guess;
        }
    }
    $baselemma = mod_flashcards_normalize_infinitive($baselemma);

    $front = trim(core_text::strtolower($fronttext));

    // Split, clean punctuation.
    $rawtokens = [];
    foreach (array_values(array_filter(preg_split('/\s+/', $front))) as $t) {
        $clean = trim($t, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean !== '') {
            $rawtokens[] = $clean;
        }
    }

    // Lemmatize via ordbank_helper; fallback to token; also apply the "er->e" guess and reflexive normalization.
    $lemmas = [];
    $reflexives = ['seg', 'meg', 'deg', 'oss', 'dere', 'dem'];
    foreach ($rawtokens as $t) {
        $analysis = ordbank_helper::analyze_token($t, []);
        $lemma = $analysis['selected']['baseform'] ?? null;
        $lem = $lemma ? core_text::strtolower($lemma) : $t;
        if (in_array($lem, $reflexives, true)) {
            $lem = 'seg';
        }
        if ($lem === $t && preg_match('~er$~', $t)) {
            $guess = preg_replace('~er$~', 'e', $t);
            if ($guess && $guess !== $lem) {
                $lemmas[] = $guess;
            }
        }
        $lemmas[] = $lem;
    }
    if (!empty($spacyLemmas)) {
        $lemmas = $spacyLemmas;
    }

    // Prepositions actually present in text; do not force defaults to avoid phantom particles like "om".
    $preplist = ['over','om','for','med','til','av','på','pa','i','etter'];
    $prepsText = array_values(array_unique(array_intersect($preplist, $rawtokens)));

    // Base candidates (must start with baselemma).
    if ($baselemma !== '') {
        $cands[] = $baselemma;
        foreach ($prepsText as $prep) {
            $cands[] = trim($baselemma . ' ' . $prep);
            $cands[] = trim('være ' . $baselemma . ' ' . $prep);
        }
    }

    // Helper to make n-grams that start with baselemma.
    $build_ngrams = function(array $tokens, string $baselemma) {
        $out = [];
        $len = count($tokens);
        for ($n = 6; $n >= 2; $n--) {
            for ($i = 0; $i + $n <= $len; $i++) {
                if ($tokens[$i] !== $baselemma) {
                    continue;
                }
                $span = trim(implode(' ', array_slice($tokens, $i, $n)));
                if ($span) {
                    $out[] = $span;
                }
            }
        }
        return $out;
    };
    $cands = array_merge($cands, $build_ngrams($rawtokens, $baselemma), $build_ngrams($lemmas, $baselemma));

    // If base lemma appears after a preposition, allow phrase starting at that preposition.
    $tokensets = [$rawtokens, $lemmas];
    foreach ($tokensets as $tokens) {
        $len = count($tokens);
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i] !== $baselemma) {
                continue;
            }
            $prevIdx = $i - 1;
            if ($prevIdx < 0) {
                continue;
            }
            $prevTok = $tokens[$prevIdx];
            if (!in_array($prevTok, $preplist, true)) {
                continue;
            }
            $maxEnd = min($len, $i + 4);
            for ($end = $i + 1; $end <= $maxEnd; $end++) {
                $span = trim(implode(' ', array_slice($tokens, $prevIdx, $end - $prevIdx)));
                if ($span !== '') {
                    $cands[] = $span;
                    if (in_array('være', $tokens, true)) {
                        $cands[] = trim('være ' . $span);
                    }
                }
            }
        }
    }

    // Reflexive patterns: baselemma + ' seg ' + prep (if seg-like + prep exist in text).
    $hasSeg = count(array_intersect($reflexives, $rawtokens)) > 0;
    $prepsInText = array_values(array_unique(array_intersect($preplist, $rawtokens)));
    if ($hasSeg && !empty($prepsInText)) {
        foreach ($prepsInText as $prep) {
            $cands[] = trim($baselemma . ' seg ' . $prep);
        }
    }

    // Flexible templates with up to 2 gaps around base (only if slice starts with baselemma).
    $tokensets = [$rawtokens, $lemmas];
    foreach ($tokensets as $tokens) {
        $len = count($tokens);
        foreach ($tokens as $i => $tok) {
            if ($tok === $baselemma) {
                for ($gap = 1; $gap <= 2; $gap++) {
                    $start = $i;
                    $end = min($len, $i + 2 + $gap);
                    if ($end > $start) {
                        $slice = array_slice($tokens, $start, $end - $start);
                        $cands[] = trim(implode(' ', $slice));
                    }
                }
            }
        }
    }

    $cands = array_values(array_unique(array_filter($cands)));
    // Sort by length (longer first)
    usort($cands, function($a, $b){
        return strlen($b) <=> strlen($a);
    });
    return $cands;
}

/**
 * Normalize an expression to infinitive (remove leading "å" / whitespace).
 *
 * @param string $value
 * @return string
 */
function mod_flashcards_normalize_infinitive(string $value): string {
    $clean = preg_replace('/^å\s+/iu', '', trim($value));
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

/**
 * Lookup phrase via Ordbøkene by trying both direct lookup and search.
 *
 * @param string $expression
 * @param string $lang
 * @return array|null
 */
function mod_flashcards_lookup_or_search_expression(string $expression, string $lang = 'begge'): ?array {
    $norm = mod_flashcards_normalize_infinitive($expression);
    if ($norm === '') {
        return null;
    }
    try {
        $lookup = ordbokene_client::lookup($norm, $lang);
        if (!empty($lookup)) {
            $lookup['expression'] = mod_flashcards_normalize_infinitive($lookup['baseform'] ?? $norm);
            return $lookup;
        }
    } catch (\Throwable $ex) {
        // ignore
    }
    try {
        $search = ordbokene_client::search_expressions($norm, $lang);
        if (!empty($search)) {
            $search['expression'] = mod_flashcards_normalize_infinitive($search['baseform'] ?? $norm);
            return $search;
        }
    } catch (\Throwable $ex) {
        // ignore
    }
    return null;
}

/**
 * Lookup phrase via Ordbokene and return trace for debugging.
 *
 * @param string $expression
 * @param string $lang
 * @return array{match:?array,trace:array<string,mixed>}
 */
function mod_flashcards_lookup_or_search_expression_debug(string $expression, string $lang = 'begge'): array {
    $norm = mod_flashcards_normalize_infinitive($expression);
    $trace = [
        'expression' => $expression,
        'norm' => $norm,
        'lookup_hit' => false,
        'search_hit' => false,
        'lookup_error' => '',
        'search_error' => '',
        'match_source' => '',
        'match_expression' => '',
        'url' => '',
    ];
    if ($norm === '') {
        return ['match' => null, 'trace' => $trace];
    }
    try {
        $lookup = ordbokene_client::lookup($norm, $lang);
        if (!empty($lookup)) {
            $lookup['expression'] = mod_flashcards_normalize_infinitive($lookup['baseform'] ?? $norm);
            $trace['lookup_hit'] = true;
            $trace['match_source'] = 'lookup';
            $trace['match_expression'] = $lookup['expression'] ?? '';
            $trace['url'] = $lookup['dictmeta']['url'] ?? ($lookup['url'] ?? '');
            return ['match' => $lookup, 'trace' => $trace];
        }
    } catch (\Throwable $ex) {
        $trace['lookup_error'] = $ex->getMessage();
    }
    try {
        $search = ordbokene_client::search_expressions($norm, $lang);
        if (!empty($search)) {
            $search['expression'] = mod_flashcards_normalize_infinitive($search['baseform'] ?? $norm);
            $trace['search_hit'] = true;
            $trace['match_source'] = 'search';
            $trace['match_expression'] = $search['expression'] ?? '';
            $trace['url'] = $search['dictmeta']['url'] ?? ($search['url'] ?? '');
            return ['match' => $search, 'trace' => $trace];
        }
    } catch (\Throwable $ex) {
        $trace['search_error'] = $ex->getMessage();
    }
    return ['match' => null, 'trace' => $trace];
}

/**
 * Produce lightweight expression variants for better Ordbøkene coverage.
 *
 * @param string $expression
 * @return array
 */
function mod_flashcards_expand_expression_variants(string $expression): array {
    $expression = mod_flashcards_normalize_infinitive($expression);
    if ($expression === '') {
        return [];
    }
    // Only generate orthographic variants (no semantic "trimming"/prefixing).
    // Goal: improve Ordbøkene coverage for spelling/spacing variants like:
    // "i stedet for" <-> "istedenfor", "i stedet" <-> "isteden".
    $variants = [$expression];

    // Collapsed form (remove spaces): helps when Ordbøkene stores a single-token variant.
    if (strpos($expression, ' ') !== false) {
        $collapsed = preg_replace('/\s+/u', '', $expression);
        if (is_string($collapsed) && $collapsed !== '') {
            $variants[] = $collapsed;
        }
    }

    // Also try removing spaces around common clitics (very conservative).
    // Example: "i stedet" -> "istedet".
    $collapsed2 = preg_replace('/\s+/u', '', $expression);
    if (is_string($collapsed2) && $collapsed2 !== '' && $collapsed2 !== $expression) {
        $variants[] = $collapsed2;
    }

    $cleaned = [];
    foreach (array_unique($variants) as $variant) {
        $variant = trim($variant);
        if ($variant !== '') {
            $cleaned[] = $variant;
        }
    }
    return $cleaned;
}

/**
 * Try to resolve a multi-word expression via Ordbøkene.
 *
 * @param string $fronttext Full sentence (surface forms)
 * @param string $clicked Clicked token (surface form)
 * @param string $base Baseform/lemma if already known
 * @param string $lang bm|nn|begge
 * @return array|null
 */
function mod_flashcards_resolve_ordbokene_expression(string $fronttext, string $clicked, string $base = '', string $lang = 'begge'): ?array {
    $lang = in_array($lang, ['bm', 'nn', 'begge'], true) ? $lang : 'begge';
    $candidates = mod_flashcards_build_expression_candidates($fronttext, $base ?: $clicked);
    $tokenize = function(string $text): array {
        $tokens = [];
        foreach (array_filter(preg_split('/\s+/', core_text::strtolower($text))) as $tok) {
            $clean = trim($tok, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
            if ($clean !== '') {
                $tokens[] = $clean;
            }
        }
        return $tokens;
    };
    $tokensInOrder = function(array $needle, array $haystack): bool {
        if (empty($needle)) {
            return false;
        }
        $pos = 0;
        $len = count($haystack);
        foreach ($needle as $tok) {
            while ($pos < $len && $haystack[$pos] !== $tok) {
                $pos++;
            }
            if ($pos >= $len) {
                return false;
            }
            $pos++;
        }
        return true;
    };
    $sentTokens = $tokenize($fronttext);
    $seen = [];

    $buildResult = function(array $resolved, string $fallback): array {
        $expression = mod_flashcards_normalize_infinitive($resolved['expression'] ?? $resolved['baseform'] ?? $fallback);
        return [
            'expression' => $expression,
            'meanings' => $resolved['meanings'] ?? [],
            'examples' => $resolved['examples'] ?? [],
            'forms' => $resolved['forms'] ?? [],
            'dictmeta' => $resolved['dictmeta'] ?? [],
            'source' => 'ordbokene',
            'citation' => '«Korleis». I: Bokmålsordboka og Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).',
        ];
    };

    foreach ($candidates as $cand) {
        $norm = mod_flashcards_normalize_infinitive($cand);
        if ($norm === '' || isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $exprTokens = $tokenize($norm);
        if (!$tokensInOrder($exprTokens, $sentTokens)) {
            continue;
        }
        $resolved = mod_flashcards_lookup_or_search_expression($norm, $lang);
        if (!empty($resolved)) {
            return $buildResult($resolved, $norm);
        }
    }

    foreach (mod_flashcards_expand_expression_variants($clicked) as $variant) {
        $norm = mod_flashcards_normalize_infinitive($variant);
        if ($norm === '' || isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $resolved = mod_flashcards_lookup_or_search_expression($norm, $lang);
        if (!empty($resolved)) {
            return $buildResult($resolved, $norm);
        }
    }

    return null;
}


