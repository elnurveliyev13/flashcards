<?php
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_flashcards';
$plugin->version   = 2025103001; // YYYYMMDDXX.
$plugin->requires  = 2022041900; // Moodle 4.0 (adjust if needed).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.6.0-pwa-install'; // Add PWA install button with beforeinstallprompt handler
