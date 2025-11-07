<?php

namespace mod_flashcards\local;

use coding_exception;
use core_text;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides read access to the Orbøkene dictionary cache table.
 */
class orbokene_repository {
    public static function is_enabled(): bool {
        $config = get_config('mod_flashcards');
        return !empty($config->orbokene_enabled);
    }

    public static function normalize_phrase(string $phrase): string {
        $phrase = trim(core_text::strtolower($phrase));
        $phrase = preg_replace('/\s+/', ' ', $phrase);
        return $phrase;
    }

    /**
     * Fetch a dictionary record by phrase.
     *
     * @param string $phrase
     * @return array|null
     */
    public static function find(string $phrase): ?array {
        global $DB;
        if (!self::is_enabled()) {
            return null;
        }
        $normalized = self::normalize_phrase($phrase);
        if ($normalized === '') {
            return null;
        }
        $record = $DB->get_record('flashcards_orbokene', ['normalized' => $normalized]);
        if (!$record) {
            return null;
        }
        return [
            'entry' => $record->entry,
            'baseform' => $record->baseform,
            'grammar' => $record->grammar,
            'definition' => $record->definition,
            'translation' => $record->translation,
            'examples' => self::decode_examples($record->examplesjson ?? ''),
        ];
    }

    protected static function decode_examples(string $json): array {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $clean = [];
        foreach ($data as $example) {
            $example = trim((string)$example);
            if ($example !== '') {
                $clean[] = $example;
            }
        }
        return $clean;
    }
}
