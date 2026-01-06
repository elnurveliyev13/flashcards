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
            'meta' => self::decode_meta($record->meta ?? ''),
        ];
    }

    /**
     * Upsert a dictionary record (used to cache Ordbøkene lookups and aliases).
     *
     * @param string $phrase Key phrase to index (normalized)
     * @param array{entry:string,baseform?:string,grammar?:string,definition?:string,translation?:string,examples?:array<int,string>,meta?:array<mixed>} $data
     */
    public static function upsert(string $phrase, array $data): void {
        global $DB;
        if (!self::is_enabled()) {
            return;
        }
        $normalized = self::normalize_phrase($phrase);
        if ($normalized === '') {
            return;
        }
        $record = (object)[
            'entry' => (string)($data['entry'] ?? $phrase),
            'normalized' => $normalized,
            'baseform' => (string)($data['baseform'] ?? ''),
            'grammar' => (string)($data['grammar'] ?? ''),
            'definition' => (string)($data['definition'] ?? ''),
            'translation' => (string)($data['translation'] ?? ''),
            'examplesjson' => self::encode_examples($data['examples'] ?? []),
            'meta' => self::encode_meta($data['meta'] ?? []),
            'timemodified' => time(),
        ];
        $existing = $DB->get_record('flashcards_orbokene', ['normalized' => $normalized]);
        if ($existing) {
            $record->id = $existing->id;
            if (empty($existing->timecreated)) {
                $record->timecreated = time();
            }
            $DB->update_record('flashcards_orbokene', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('flashcards_orbokene', $record);
        }
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

    protected static function encode_examples(array $examples): string {
        $clean = [];
        foreach ($examples as $example) {
            $example = trim((string)$example);
            if ($example !== '') {
                $clean[] = $example;
            }
        }
        if (empty($clean)) {
            return '';
        }
        return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE);
    }

    protected static function decode_meta(string $json): array {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    protected static function encode_meta(array $meta): string {
        if (empty($meta)) {
            return '';
        }
        return json_encode($meta, JSON_UNESCAPED_UNICODE);
    }
}
