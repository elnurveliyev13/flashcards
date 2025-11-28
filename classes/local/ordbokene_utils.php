<?php
// Utility for Ordbøkene expression candidate building.

defined('MOODLE_INTERNAL') || die();

use mod_flashcards\local\ordbank_helper;

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

    $front = trim(core_text::strtolower($fronttext));

    // Split, clean punctuation.
    $rawtokens = [];
    foreach (array_values(array_filter(preg_split('/\s+/', $front))) as $t) {
        $clean = trim($t, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean !== '') {
            $rawtokens[] = $clean;
        }
    }

    // Lemmatize via ordbank_helper; fallback to token; also apply the "er->e" guess.
    $lemmas = [];
    foreach ($rawtokens as $t) {
        $analysis = ordbank_helper::analyze_token($t, []);
        $lemma = $analysis['selected']['baseform'] ?? null;
        $lem = $lemma ? core_text::strtolower($lemma) : $t;
        if ($lem === $t && preg_match('~er$~', $t)) {
            $guess = preg_replace('~er$~', 'e', $t);
            if ($guess && $guess !== $lem) {
                $lemmas[] = $guess;
            }
        }
        $lemmas[] = $lem;
    }

    // Prepositions actually present in text (fallback to om/over).
    $prepsText = array_values(array_unique(array_intersect(
        ['over','om','for','med','til','av','på','i'],
        $rawtokens
    )));
    if (empty($prepsText)) {
        $prepsText = ['om','over'];
    }

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
