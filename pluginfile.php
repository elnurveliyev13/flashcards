<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

/**
 * Serves media files for flashcards plugin.
 *
 * Supports two contexts:
 * - User context (context_user): For cards created in global mode
 * - Module context (context_module): For cards created in activity mode
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param stdClass $context Context object
 * @param string $filearea File area
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return bool False if file not found, does not return if found (sends file)
 */
function mod_flashcards_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $DB, $USER;

    // Allow 'media' filearea only
    if ($filearea !== 'media') {
        return false;
    }

    // Require login for all files
    require_login();

    // Extract file info from args (do this ONCE at the beginning)
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/'.implode('/', $args).'/' : '/';

    // Debug logging (remove after fixing)
    debugging(sprintf(
        'Flashcards pluginfile: contextid=%d, contextlevel=%d, itemid=%d, filename=%s, userid=%d',
        $context->id, $context->contextlevel, $itemid, $filename, $USER->id
    ), DEBUG_DEVELOPER);

    // Handle both user context (global mode) and module context (activity mode)
    if ($context->contextlevel == CONTEXT_USER) {
        // Global mode: Files stored in user context
        // GLOBAL DECK FIX: In global mode, files are stored in the CREATOR's user context
        // but should be accessible by ANY authenticated user who owns that card
        // (cards are separated by ownerid field in flashcards_cards table)

        // itemid in user context = userid of file creator
        // Allow access if:
        // 1. User is accessing their own files (itemid == $USER->id), OR
        // 2. User is admin, OR
        // 3. File belongs to a card owned by current user (check via filename)

        if ($itemid == $USER->id || is_siteadmin()) {
            // User accessing their own files or admin - allow
            // (Files are stored in creator's context but belong to their cards)
        } else {
            // User trying to access another user's context
            // Check if this file belongs to a card owned by current user OR is shared
            $card = $DB->get_record_sql(
                "SELECT c.* FROM {flashcards_cards} c
                 WHERE c.payload LIKE ?
                   AND ((c.scope = 'private' AND c.ownerid = ?)
                        OR c.scope = 'shared')",
                ['%'.$filename.'%', $USER->id]
            );

            if (!$card) {
                // Not user's card and not shared, deny access
                debugging('Access denied: File does not belong to user or shared card', DEBUG_DEVELOPER);
                send_file_not_found();
            }
        }

    } else if ($context->contextlevel == CONTEXT_MODULE) {
        // Activity mode: Files stored in module context
        // Check capability to view flashcards
        require_capability('mod/flashcards:view', $context);

    } else {
        // Invalid context level
        debugging('Invalid context level: ' . $context->contextlevel, DEBUG_DEVELOPER);
        send_file_not_found();
    }

    // Retrieve file from Moodle file storage
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_flashcards', $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        debugging('File not found in storage: ' . $filename, DEBUG_DEVELOPER);
        send_file_not_found();
    }

    // Send the file
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
