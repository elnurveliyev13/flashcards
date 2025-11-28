<?php
// Utility for Ordbøkene expression candidate building.

defined('MOODLE_INTERNAL') || die();

/**
 * Build expression candidates (n-grams + templates with gaps, using raw tokens and lemmas).
 *
 * @param string $fronttext Full sentence
 * @param string $base Base form / clicked word
 * @return array
 */
function mod_flashcards_build_expression_candidates(string $fronttext, string $base): array {
    $cands = [];
    $base = trim(core_text::strtolower($base));
    $front = trim(core_text::strtolower($fronttext));

    // Split and clean punctuation.
    $rawtokens = [];
    foreach (array_values(array_filter(preg_split('/\s+/', $front))) as $t) {
        $clean = trim($t, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean !== '') {
            $rawtokens[] = $clean;
        }
    }

    // Lemmatize via ordbank_helper; if no lemma, keep token.
    $lemmas = [];
    foreach ($rawtokens as $t) {
        $analysis = \mod_flashcards\local\ordbank_helper::analyze_token($t, []);
        $lemma = $analysis['selected']['baseform'] ?? null;
        $lemmas[] = $lemma ? core_text::strtolower($lemma) : $t;
    }

    // Base candidates.
    if ($base !== '') {
        $cands[] = $base;
        $cands[] = trim($base . ' over');
        $cands[] = trim('være ' . $base . ' over');
    }

    // N-grams (raw and lemma) length 2..5.
    $build_ngrams = function(array $tokens) {
        $out = [];
        $len = count($tokens);
        for ($n = 5; $n >= 2; $n--) {
            for ($i = 0; $i + $n <= $len; $i++) {
                $span = trim(implode(' ', array_slice($tokens, $i, $n)));
                if ($span) {
                    $out[] = $span;
                }
            }
        }
        return $out;
    };
    $cands = array_merge($cands, $build_ngrams($rawtokens), $build_ngrams($lemmas));

    // Flexible templates with up to 2 gaps around base (use raw and lemma tokens).
    $tokensets = [$rawtokens, $lemmas];
    foreach ($tokensets as $tokens) {
        $len = count($tokens);
        foreach ($tokens as $i => $tok) {
            if ($tok === $base) {
                for ($gap = 1; $gap <= 2; $gap++) {
                    $start = max(0, $i - 1);
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
