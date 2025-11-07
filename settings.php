<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('modsettingflashcards', get_string('pluginname', 'mod_flashcards'));
    $ADMIN->add('modsettings', $settings);

    // AI focus helper (ChatGPT)
    $settings->add(new admin_setting_heading(
        'mod_flashcards/ai_heading',
        get_string('settings_ai_section', 'mod_flashcards'),
        get_string('settings_ai_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/ai_focus_enabled',
        get_string('settings_ai_enable', 'mod_flashcards'),
        get_string('settings_ai_enable_desc', 'mod_flashcards'),
        1
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/openai_apikey',
        get_string('settings_openai_key', 'mod_flashcards'),
        get_string('settings_openai_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/openai_model',
        get_string('settings_openai_model', 'mod_flashcards'),
        get_string('settings_openai_model_desc', 'mod_flashcards'),
        'gpt-4o-mini'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/openai_baseurl',
        get_string('settings_openai_url', 'mod_flashcards'),
        get_string('settings_openai_url_desc', 'mod_flashcards'),
        'https://api.openai.com/v1/chat/completions'
    ));

    // ElevenLabs TTS
    $settings->add(new admin_setting_heading(
        'mod_flashcards/tts_heading',
        get_string('settings_tts_section', 'mod_flashcards'),
        get_string('settings_tts_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/elevenlabs_apikey',
        get_string('settings_elevenlabs_key', 'mod_flashcards'),
        get_string('settings_elevenlabs_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_default_voice',
        get_string('settings_elevenlabs_voice', 'mod_flashcards'),
        get_string('settings_elevenlabs_voice_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_flashcards/elevenlabs_voice_map',
        get_string('settings_elevenlabs_voice_map', 'mod_flashcards'),
        get_string('settings_elevenlabs_voice_map_desc', 'mod_flashcards'),
        "Ida=21m00Tcm4TlvDq8ikWAM\nAksel=IKne3meq5aSn9XLyUdCD"
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_model',
        get_string('settings_elevenlabs_model', 'mod_flashcards'),
        get_string('settings_elevenlabs_model_desc', 'mod_flashcards'),
        'eleven_monolingual_v2'
    ));

    // Orbøkene dictionary
    $settings->add(new admin_setting_heading(
        'mod_flashcards/orbokene_heading',
        get_string('settings_orbokene_section', 'mod_flashcards'),
        get_string('settings_orbokene_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/orbokene_enabled',
        get_string('settings_orbokene_enable', 'mod_flashcards'),
        get_string('settings_orbokene_enable_desc', 'mod_flashcards'),
        0
    ));

}
