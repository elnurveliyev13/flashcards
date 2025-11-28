<?php
// Utility for Ordbøkene expression candidate building.
// Kept in a separate file to be required where needed.

defined('MOODLE_INTERNAL') || die();

/**
 * Build expression candidates (n-grams + templates with gaps).
 *
 * @param string $fronttext Full sentence
 * @param string $base Base form / clicked word
 * @return array
 */
function mod_flashcards_build_expression_candidates(string $fronttext, string $base): array {
    $cands = [];
    $base = trim(core_text::strtolower($base));
    $front = trim(core_text::strtolower($fronttext));
    $tokens = array_values(array_filter(preg_split('/\\s+/', $front)));
    // Base candidates
    if ($base !== '') {
        $cands[] = $base;
    }
    if ($base) {
        $cands[] = trim($base . ' over');
        $cands[] = trim('være ' . $base . ' over');
    }
    // n-grams length 2..5
    $len = count($tokens);
    for ($n = 5; $n >= 2; $n--) {
        for ($i = 0; $i + $n <= $len; $i++) {
            $span = trim(implode(' ', array_slice($tokens, $i, $n)));
            if ($span) {
                $cands[] = $span;
            }
        }
    }
    // Templates with up to 2 gaps around the base (если base входит в текст)
    $positions = [];
    foreach ($tokens as $i => $t) {
        if ($t === $base) {
            $positions[] = $i;
        }
    }
    foreach ($positions as $pos) {
        for ($gap = 1; $gap <= 2; $gap++) {
            $start = max(0, $pos - 1);
            $end = min($len, $pos + 2 + $gap);
            if ($end > $start) {
                $slice = array_slice($tokens, $start, $end - $start);
                $cands[] = trim(implode(' ', $slice));
            }
        }
    }
    $cands = array_values(array_unique(array_filter($cands)));
    // Сортируем по длине (длинные сначала)
    usort($cands, function($a, $b){
        return strlen($b) <=> strlen($a);
    });
    return $cands;
}
