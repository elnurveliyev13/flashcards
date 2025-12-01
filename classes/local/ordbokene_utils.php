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

    // Prepositions actually present in text; do not force defaults to avoid phantom particles like "om".
    $preplist = ['over','om','for','med','til','av','på','pa','i'];
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
    foreach ($candidates as $cand) {
        $norm = mod_flashcards_normalize_infinitive($cand);
        if ($norm === '') {
            continue;
        }
        // Skip expressions whose tokens are not present in the sentence in the same order to avoid phantom combinations (e.g., 'slå til' when 'til' precedes 'slå').
        $exprTokens = $tokenize($norm);
        if (!$tokensInOrder($exprTokens, $sentTokens)) {
            continue;
        }
        try {
            $lookup = ordbokene_client::lookup($norm, $lang);
        } catch (\Throwable $e) {
            continue;
        }
        if (!empty($lookup)) {
            $expression = mod_flashcards_normalize_infinitive($lookup['baseform'] ?? $norm);
            return [
                'expression' => $expression,
                'meanings' => $lookup['meanings'] ?? [],
                'examples' => $lookup['examples'] ?? [],
                'forms' => $lookup['forms'] ?? [],
                'dictmeta' => $lookup['dictmeta'] ?? [],
                'source' => 'ordbokene',
                'citation' => '«Korleis». I: Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).',
            ];
        }
    }
    return null;
}
