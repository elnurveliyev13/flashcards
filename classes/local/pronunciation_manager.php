<?php

namespace mod_flashcards\local;

use core_text;
use dml_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides lookups into the flashcards_pron_dict table.
 */
class pronunciation_manager {
    /** @var array<string,string|null> */
    protected static $posmap = [
        'substantiv' => 'NN',
        'noun' => 'NN',
        'verb' => 'VB',
        'adjektiv' => 'JJ',
        'adjective' => 'JJ',
        'adverb' => 'ADV',
        'pronomen' => 'PRON',
        'pronoun' => 'PRON',
        'determinativ' => 'DET',
        'preposisjon' => 'PREP',
        'preposition' => 'PREP',
        'konjunksjon' => 'KONJ',
        'conjunction' => 'KONJ',
        'subjunksjon' => 'SUBJ',
        'interjeksjon' => 'INT',
        'phrase' => null,
        'other' => null,
    ];

    /**
     * Retrieve a dictionary entry for the provided wordform.
     */
    public static function lookup(string $wordform, ?string $pos = null): ?array {
        global $DB;
        $wordform = trim($wordform);
        if ($wordform === '') {
            return null;
        }
        $normalized = core_text::strtolower($wordform);
        $params = ['wordform' => $normalized];
        $wheresql = 'LOWER(wordform) = :wordform';
        $poscode = self::normalize_pos($pos);
        if ($poscode !== null) {
            $params['pos'] = $poscode;
            $wheresql .= ' AND pos = :pos';
        }
        try {
            $record = $DB->get_record_select('flashcards_pron_dict', $wheresql, $params, '*', IGNORE_MULTIPLE);
        } catch (dml_exception $e) {
            debugging('[flashcards] Pronunciation lookup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        if (!$record && $poscode !== null) {
            $record = $DB->get_record_select('flashcards_pron_dict', 'LOWER(wordform) = :wordform', ['wordform' => $normalized], '*', IGNORE_MULTIPLE);
        }
        if (!$record) {
            return null;
        }
        return [
            'wordform' => $record->wordform,
            'pos' => $record->pos,
            'ipa' => $record->ipa,
            'xsampa' => $record->xsampa,
            'nofabet' => $record->nofabet,
        ];
    }

    /**
     * Convenience method that returns a display-ready transcription.
     */
    public static function lookup_transcription(string $wordform, ?string $pos = null): ?string {
        $entry = self::lookup($wordform, $pos);
        if (!$entry) {
            return null;
        }
        return self::pick_transcription($entry);
    }

    /**
     * Determine the best transcription string for UI display.
     *
     * @param array $entry
     */
    public static function pick_transcription(array $entry): ?string {
        foreach (['ipa', 'xsampa', 'nofabet'] as $field) {
            if (empty($entry[$field])) {
                continue;
            }
            $value = trim((string)$entry[$field]);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    protected static function normalize_pos(?string $pos): ?string {
        $pos = trim((string)$pos);
        if ($pos === '') {
            return null;
        }
        $poslower = core_text::strtolower($pos);
        if (array_key_exists($poslower, self::$posmap)) {
            return self::$posmap[$poslower];
        }
        $posupper = core_text::strtoupper($pos);
        if (strlen($posupper) <= 4) {
            return $posupper;
        }
        return null;
    }
}
