<?php

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'spacy' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 1209600, // 14 days
    ],
    'ai_translate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 1209600, // 14 days
    ],
    'ai_sentence_explain' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 1209600, // 14 days
    ],
];
