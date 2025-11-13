<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('modsettingflashcards', get_string('pluginname', 'mod_flashcards'));

    if ($ADMIN->fulltree) {
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

    // Amazon Polly
    $settings->add(new admin_setting_heading(
        'mod_flashcards/polly_heading',
        get_string('settings_polly_section', 'mod_flashcards'),
        get_string('settings_polly_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/amazonpolly_access_key',
        get_string('settings_polly_key', 'mod_flashcards'),
        get_string('settings_polly_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/amazonpolly_secret_key',
        get_string('settings_polly_secret', 'mod_flashcards'),
        get_string('settings_polly_secret_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/amazonpolly_region',
        get_string('settings_polly_region', 'mod_flashcards'),
        get_string('settings_polly_region_desc', 'mod_flashcards'),
        'eu-west-1'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/amazonpolly_voice_id',
        get_string('settings_polly_voice', 'mod_flashcards'),
        get_string('settings_polly_voice_desc', 'mod_flashcards'),
        'Liv'
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_flashcards/amazonpolly_voice_map',
        get_string('settings_polly_voice_map', 'mod_flashcards'),
        get_string('settings_polly_voice_map_desc', 'mod_flashcards'),
        ''
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

    // Whisper STT (OpenAI)
    $settings->add(new admin_setting_heading(
        'mod_flashcards/whisper_heading',
        get_string('settings_whisper_section', 'mod_flashcards'),
        get_string('settings_whisper_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/whisper_enabled',
        get_string('settings_whisper_enable', 'mod_flashcards'),
        get_string('settings_whisper_enable_desc', 'mod_flashcards'),
        1
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/whisper_apikey',
        get_string('settings_whisper_key', 'mod_flashcards'),
        get_string('settings_whisper_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/whisper_model',
        get_string('settings_whisper_model', 'mod_flashcards'),
        get_string('settings_whisper_model_desc', 'mod_flashcards'),
        'whisper-1',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/whisper_language',
        get_string('settings_whisper_language', 'mod_flashcards'),
        get_string('settings_whisper_language_desc', 'mod_flashcards'),
        'nb',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/whisper_clip_limit',
        get_string('settings_whisper_clip_limit', 'mod_flashcards'),
        get_string('settings_whisper_clip_limit_desc', 'mod_flashcards'),
        15,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/whisper_monthly_limit',
        get_string('settings_whisper_monthly_limit', 'mod_flashcards'),
        get_string('settings_whisper_monthly_limit_desc', 'mod_flashcards'),
        36000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/whisper_timeout',
        get_string('settings_whisper_timeout', 'mod_flashcards'),
        get_string('settings_whisper_timeout_desc', 'mod_flashcards'),
        45,
        PARAM_INT
    ));

        $ADMIN->add('modsettings', new admin_externalpage(
            'mod_flashcards_mediareport',
            get_string('mediareport_title', 'mod_flashcards'),
            new moodle_url('/mod/flashcards/admin/media_report.php'),
            'moodle/site:config'
        ));
    }
} else {
    $settings = null;
}
