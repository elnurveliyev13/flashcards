<?php
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_flashcards';
$plugin->version   = 2025121300; // YYYYMMDDXX. Added per-language example translations table
$plugin->requires  = 2022041900; // Moodle 4.0 (adjust if needed).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.14.0-push-notifications'; // Added push notifications for due cards reminders
