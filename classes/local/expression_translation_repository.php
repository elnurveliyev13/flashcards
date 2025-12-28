<?php

namespace mod_flashcards\local;

use core_text;

defined('MOODLE_INTERNAL') || die();

class expression_translation_repository {
    public static function normalize_phrase(string $phrase): string {
        $phrase = trim(core_text::strtolower($phrase));
        $phrase = preg_replace('/\s+/', ' ', $phrase);
        return $phrase;
    }

    /**
     * Upsert per-language translation data for an expression.
     *
     * @param string $expression
     * @param string $lang
     * @param array $data
     * @return void
     */
    public static function upsert(string $expression, string $lang, array $data): void {
        global $DB;
        $expression = trim($expression);
        $lang = trim(core_text::strtolower($lang));
        if ($expression === '' || $lang === '') {
            return;
        }
        $normalized = self::normalize_phrase($expression);
        if ($normalized === '') {
            return;
        }
        $translation = trim((string)($data['translation'] ?? ''));
        $note = trim((string)($data['note'] ?? ''));
        $source = trim((string)($data['source'] ?? ''));
        $confidence = trim((string)($data['confidence'] ?? ''));
        $examples = self::clean_examples($data['examples'] ?? []);
        $examplestrans = self::clean_examples($data['examples_trans'] ?? []);

        if ($translation === '' && $note === '' && empty($examples) && empty($examplestrans)) {
            return;
        }

        $record = $DB->get_record('flashcards_expr_translations', [
            'normalized' => $normalized,
            'lang' => $lang,
        ]);
        $now = time();
        if ($record) {
            $updated = false;
            if ($translation !== '' && trim((string)($record->translation ?? '')) === '') {
                $record->translation = $translation;
                $updated = true;
            }
            if ($note !== '' && trim((string)($record->note ?? '')) === '') {
                $record->note = $note;
                $updated = true;
            }
            if ($source !== '' && trim((string)($record->source ?? '')) === '') {
                $record->source = $source;
                $updated = true;
            }
            if ($confidence !== '' && trim((string)($record->confidence ?? '')) === '') {
                $record->confidence = $confidence;
                $updated = true;
            }
            $merged = self::merge_examples($record->examplesjson ?? '', $examples);
            if ($merged !== null) {
                $record->examplesjson = $merged;
                $updated = true;
            }
            $mergedTrans = self::merge_examples($record->examplestransjson ?? '', $examplestrans);
            if ($mergedTrans !== null) {
                $record->examplestransjson = $mergedTrans;
                $updated = true;
            }
            if ($updated) {
                $record->timemodified = $now;
                $DB->update_record('flashcards_expr_translations', $record);
            }
            return;
        }

        $record = (object)[
            'expression' => $expression,
            'normalized' => $normalized,
            'lang' => $lang,
            'translation' => $translation !== '' ? $translation : null,
            'note' => $note !== '' ? $note : null,
            'examplesjson' => !empty($examples) ? json_encode($examples, JSON_UNESCAPED_UNICODE) : null,
            'examplestransjson' => !empty($examplestrans) ? json_encode($examplestrans, JSON_UNESCAPED_UNICODE) : null,
            'source' => $source !== '' ? $source : null,
            'confidence' => $confidence !== '' ? $confidence : null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('flashcards_expr_translations', $record);
    }

    protected static function clean_examples($examples): array {
        if (!is_array($examples)) {
            return [];
        }
        $out = [];
        foreach ($examples as $example) {
            $example = trim((string)$example);
            if ($example !== '') {
                $out[] = $example;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Split example strings in format "NO | TRANSLATION".
     *
     * @param array $examples
     * @return array{examples:array<int,string>,translations:array<int,string>}
     */
    public static function split_examples_with_translations(array $examples): array {
        $cleanExamples = [];
        $translations = [];
        foreach ($examples as $example) {
            $example = trim((string)$example);
            if ($example === '') {
                continue;
            }
            $parts = explode('|', $example, 2);
            $left = trim($parts[0] ?? '');
            $right = trim($parts[1] ?? '');
            if ($left !== '') {
                $cleanExamples[] = $left;
            }
            if ($right !== '') {
                $translations[] = $right;
            }
        }
        return [
            'examples' => array_values(array_unique($cleanExamples)),
            'translations' => array_values(array_unique($translations)),
        ];
    }

    protected static function merge_examples(string $existingJson, array $incoming): ?string {
        if (empty($incoming)) {
            return null;
        }
        $existing = [];
        if ($existingJson !== '') {
            $decoded = json_decode($existingJson, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        $merged = array_values(array_unique(array_merge($existing, $incoming)));
        if ($merged === $existing) {
            return null;
        }
        return json_encode($merged, JSON_UNESCAPED_UNICODE);
    }
}
