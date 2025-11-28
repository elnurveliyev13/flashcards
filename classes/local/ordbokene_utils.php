<?php
// Utility for Ordbøkene expression candidate building.

defined('MOODLE_INTERNAL') || die();

/**
 * Build expression candidates (lemmas + raw, n-grams, templates with gaps).
 *
 * @param string $fronttext Full sentence
 * @param string $base Base form / clicked word (may be inflected)
 * @return array
 */
function mod_flashcards_build_expression_candidates(string $fronttext, string $base): array {
    $cands = [];
    $base = trim(core_text::strtolower($base));
    $front = trim(core_text::strtolower($fronttext));

    // Split, clean punctuation.
    $rawtokens = [];
    foreach (array_values(array_filter(preg_split('/\s+/', $front))) as $t) {
        $clean = trim($t, " \t\n\r\0\x0B,.;:!?<>\"'()[]{}");
        if ($clean !== '') {
            $rawtokens[] = $clean;
        }
    }

    // Lemmatize via ordbank_helper; fallback to token.
    $lemmas = [];
    foreach ($rawtokens as $t) {
        $analysis = \mod_flashcards\local\ordbank_helper::analyze_token($t, []);
        $lemma = $analysis['selected']['baseform'] ?? null;
        $lemmas[] = $lemma ? core_text::strtolower($lemma) : $t;
    }

    // Base candidates (try key preposition combos).
    if ($base !== '') {
        $cands[] = $base;
        foreach (['over','om','for','med','til','av'] as $prep) {
            $cands[] = trim($base . ' ' . $prep);
            $cands[] = trim('være ' . $base . ' ' . $prep);
        }
    }

    // Helper to make n-grams.
    $build_ngrams = function(array $tokens) {
        $out = [];
        $len = count($tokens);
        for ($n = 6; $n >= 2; $n--) {
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

    // Flexible templates with gaps (up to 2) around base (lemma/raw).
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
