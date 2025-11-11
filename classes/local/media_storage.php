<?php

namespace mod_flashcards\local;

use coding_exception;
use stored_file;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Mirrors audio/media assets into a human-readable folder structure inside moodledata.
 */
class media_storage {

    /**
     * Synchronise audio references from a card payload into the readable media folder.
     *
     * @param int $userid
     * @param string $cardid
     * @param array $payload
     * @param int|null $deckid
     */
    public static function sync_from_payload(int $userid, string $cardid, array $payload, ?int $deckid = null): void {
        if ($cardid === '') {
            return;
        }

        $candidates = [
            'audio' => $payload['audio'] ?? null,
            'audioFront' => $payload['audioFront'] ?? null,
            'focusAudio' => $payload['focusAudio'] ?? null,
        ];

        foreach ($candidates as $label => $url) {
            if (!is_string($url) || strpos($url, '/pluginfile.php/') === false) {
                continue;
            }
            $file = self::get_file_from_url($url);
            if (!$file) {
                continue;
            }
            self::mirror_stored_file($file, $userid, $cardid, $label, $deckid, $url);
        }
    }

    /**
     * Delete mirrored media for a card.
     *
     * @param int $userid
     * @param string $cardid
     */
    public static function delete_card_media(int $userid, string $cardid): void {
        global $CFG;
        $dir = self::card_dir($userid, $cardid, false);
        if (!$dir || !is_dir($dir)) {
            return;
        }
        require_once($CFG->libdir . '/filelib.php');
        fulldelete($dir);
    }

    /**
     * Copy a stored_file into the readable directory and update manifest.
     *
     * @param stored_file $file
     * @param int $userid
     * @param string $cardid
     * @param string $label
     * @param int|null $deckid
     * @param string|null $sourceurl
     */
    protected static function mirror_stored_file(stored_file $file, int $userid, string $cardid, string $label, ?int $deckid, ?string $sourceurl = null): void {
        $dir = self::card_dir($userid, $cardid, true);
        if (!$dir) {
            return;
        }
        $basename = $file->get_filename();
        $safeLabel = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($label));
        $target = $dir . DIRECTORY_SEPARATOR . ($safeLabel ? "{$safeLabel}_" : '') . $basename;
        $file->copy_content_to($target);
        self::update_manifest($dir, [
            'label' => $label,
            'filename' => basename($target),
            'original' => $basename,
            'sourceUrl' => $sourceurl,
            'copiedAt' => time(),
        ], $cardid, $deckid);
    }

    /**
     * Update (or create) manifest.json for a card directory.
     *
     * @param string $dir
     * @param array $entry
     * @param string $cardid
     * @param int|null $deckid
     */
    protected static function update_manifest(string $dir, array $entry, string $cardid, ?int $deckid): void {
        $path = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = [
            'cardId' => $cardid,
            'deckId' => $deckid,
            'updatedAt' => time(),
            'files' => [],
        ];
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                $manifest = array_merge($manifest, $data);
                if (!isset($manifest['files']) || !is_array($manifest['files'])) {
                    $manifest['files'] = [];
                }
            }
        }
        // Remove previous entries with same label to avoid duplicates.
        $manifest['files'] = array_values(array_filter($manifest['files'], function ($item) use ($entry) {
            return !isset($item['label']) || $item['label'] !== $entry['label'];
        }));
        $manifest['files'][] = $entry;
        $manifest['updatedAt'] = time();
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build (and optionally create) the directory for a card.
     *
     * @param int $userid
     * @param string $cardid
     * @param bool $create
     * @return string|null
     */
    protected static function card_dir(int $userid, string $cardid, bool $create): ?string {
        global $CFG;
        $safeCard = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cardid);
        $safeCard = trim($safeCard, '-') ?: 'card';
        $subpath = "mod_flashcards/media/user_{$userid}/card_{$safeCard}";
        if ($create) {
            require_once($CFG->libdir . '/filelib.php');
            return make_upload_directory($subpath);
        }
        $path = $CFG->dataroot . '/' . $subpath;
        return file_exists($path) ? $path : null;
    }

    /**
     * Resolve pluginfile URL into a stored_file.
     *
     * @param string $url
     * @return stored_file|null
     */
    protected static function get_file_from_url(string $url): ?stored_file {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || strpos($path, '/pluginfile.php/') === false) {
            return null;
        }
        $path = ltrim(substr($path, strpos($path, '/pluginfile.php/') + strlen('/pluginfile.php/')), '/');
        $parts = explode('/', $path);
        if (count($parts) < 5) {
            return null;
        }
        $contextid = (int)array_shift($parts);
        $component = array_shift($parts);
        $filearea = array_shift($parts);
        $itemid = (int)array_shift($parts);
        if (!$contextid || !$component || !$filearea) {
            return null;
        }
        $filename = array_pop($parts);
        $filepath = '/' . implode('/', $parts);
        if (substr($filepath, -1) !== '/') {
            $filepath .= '/';
        }
        $fs = get_file_storage();
        return $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
    }
}
