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

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/ai_focus_model',
        get_string('settings_ai_focus_model', 'mod_flashcards'),
        get_string('settings_ai_focus_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_focus_reasoning_effort',
        get_string('settings_ai_focus_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_focus_reasoning_effort_desc', 'mod_flashcards'),
        'medium',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
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

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/openai_correction_model',
        get_string('settings_openai_correction_model', 'mod_flashcards'),
        get_string('settings_openai_correction_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_correction_reasoning_effort',
        get_string('settings_ai_correction_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_correction_reasoning_effort_desc', 'mod_flashcards'),
        'medium',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/ai_translation_model',
        get_string('settings_ai_translation_model', 'mod_flashcards'),
        get_string('settings_ai_translation_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_translation_reasoning_effort',
        get_string('settings_ai_translation_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_translation_reasoning_effort_desc', 'mod_flashcards'),
        'low',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/ai_question_model',
        get_string('settings_ai_question_model', 'mod_flashcards'),
        get_string('settings_ai_question_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_question_reasoning_effort',
        get_string('settings_ai_question_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_question_reasoning_effort_desc', 'mod_flashcards'),
        'medium',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/ai_construction_model',
        get_string('settings_ai_construction_model', 'mod_flashcards'),
        get_string('settings_ai_construction_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_construction_reasoning_effort',
        get_string('settings_ai_construction_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_construction_reasoning_effort_desc', 'mod_flashcards'),
        'low',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/ai_expression_model',
        get_string('settings_ai_expression_model', 'mod_flashcards'),
        get_string('settings_ai_expression_model_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/ai_expression_reasoning_effort',
        get_string('settings_ai_expression_reasoning_effort', 'mod_flashcards'),
        get_string('settings_ai_expression_reasoning_effort_desc', 'mod_flashcards'),
        'low',
        [
            'none' => get_string('settings_reasoning_effort_none', 'mod_flashcards'),
            'minimal' => get_string('settings_reasoning_effort_minimal', 'mod_flashcards'),
            'low' => get_string('settings_reasoning_effort_low', 'mod_flashcards'),
            'medium' => get_string('settings_reasoning_effort_medium', 'mod_flashcards'),
            'high' => get_string('settings_reasoning_effort_high', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/ai_doublecheck_correction',
        get_string('settings_ai_doublecheck_correction', 'mod_flashcards'),
        get_string('settings_ai_doublecheck_correction_desc', 'mod_flashcards'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/ai_multisampling_enabled',
        get_string('settings_ai_multisampling', 'mod_flashcards'),
        get_string('settings_ai_multisampling_desc', 'mod_flashcards'),
        0
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

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_language_code',
        get_string('settings_elevenlabs_language_code', 'mod_flashcards'),
        get_string('settings_elevenlabs_language_code_desc', 'mod_flashcards'),
        'no',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_model',
        get_string('settings_elevenlabs_focus_model', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_model_desc', 'mod_flashcards'),
        'eleven_v3',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_language_code',
        get_string('settings_elevenlabs_focus_language_code', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_language_code_desc', 'mod_flashcards'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_stability',
        get_string('settings_elevenlabs_focus_stability', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_stability_desc', 'mod_flashcards'),
        '0.95',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_similarity_boost',
        get_string('settings_elevenlabs_focus_similarity_boost', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_similarity_boost_desc', 'mod_flashcards'),
        '0.75',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_speed',
        get_string('settings_elevenlabs_focus_speed', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_speed_desc', 'mod_flashcards'),
        '0.85',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_focus_previous_text',
        get_string('settings_elevenlabs_focus_previous_text', 'mod_flashcards'),
        get_string('settings_elevenlabs_focus_previous_text_desc', 'mod_flashcards'),
        'Hva betyr:'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_tts_monthly_limit',
        get_string('settings_elevenlabs_tts_limit', 'mod_flashcards'),
        get_string('settings_elevenlabs_tts_limit_desc', 'mod_flashcards'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_tts_monthly_limit_global',
        get_string('settings_elevenlabs_tts_limit_global', 'mod_flashcards'),
        get_string('settings_elevenlabs_tts_limit_global_desc', 'mod_flashcards'),
        0,
        PARAM_INT
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

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/amazonpolly_tts_monthly_limit',
        get_string('settings_polly_tts_limit', 'mod_flashcards'),
        get_string('settings_polly_tts_limit_desc', 'mod_flashcards'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/amazonpolly_tts_monthly_limit_global',
        get_string('settings_polly_tts_limit_global', 'mod_flashcards'),
        get_string('settings_polly_tts_limit_global_desc', 'mod_flashcards'),
        0,
        PARAM_INT
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

    // ElevenLabs STT
    $settings->add(new admin_setting_heading(
        'mod_flashcards/elevenlabs_stt_heading',
        get_string('settings_elevenlabs_stt_section', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/elevenlabs_stt_enabled',
        get_string('settings_elevenlabs_stt_enable', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_enable_desc', 'mod_flashcards'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/elevenlabs_stt_apikey',
        get_string('settings_elevenlabs_stt_key', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_stt_model',
        get_string('settings_elevenlabs_stt_model', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_model_desc', 'mod_flashcards'),
        'scribe_v1',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_stt_language',
        get_string('settings_elevenlabs_stt_language', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_language_desc', 'mod_flashcards'),
        'nb',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_stt_clip_limit',
        get_string('settings_elevenlabs_stt_clip_limit', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_clip_limit_desc', 'mod_flashcards'),
        15,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_stt_monthly_limit',
        get_string('settings_elevenlabs_stt_monthly_limit', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_monthly_limit_desc', 'mod_flashcards'),
        36000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/elevenlabs_stt_timeout',
        get_string('settings_elevenlabs_stt_timeout', 'mod_flashcards'),
        get_string('settings_elevenlabs_stt_timeout_desc', 'mod_flashcards'),
        45,
        PARAM_INT
    ));

    // STT Provider Selection
    $settings->add(new admin_setting_heading(
        'mod_flashcards/stt_provider_heading',
        get_string('settings_stt_provider_section', 'mod_flashcards'),
        get_string('settings_stt_provider_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configselect(
        'mod_flashcards/stt_provider',
        get_string('settings_stt_provider', 'mod_flashcards'),
        get_string('settings_stt_provider_desc', 'mod_flashcards'),
        'whisper',
        [
            'whisper' => get_string('settings_stt_provider_whisper', 'mod_flashcards'),
            'elevenlabs' => get_string('settings_stt_provider_elevenlabs', 'mod_flashcards'),
        ]
    ));

    $settings->add(new admin_setting_heading(
        'mod_flashcards/googlevision_heading',
        get_string('settings_googlevision_section', 'mod_flashcards'),
        get_string('settings_googlevision_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/googlevision_enabled',
        get_string('settings_googlevision_enable', 'mod_flashcards'),
        get_string('settings_googlevision_enable_desc', 'mod_flashcards'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/googlevision_api_key',
        get_string('settings_googlevision_key', 'mod_flashcards'),
        get_string('settings_googlevision_key_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/googlevision_language',
        get_string('settings_googlevision_language', 'mod_flashcards'),
        get_string('settings_googlevision_language_desc', 'mod_flashcards'),
        'en',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/googlevision_timeout',
        get_string('settings_googlevision_timeout', 'mod_flashcards'),
        get_string('settings_googlevision_timeout_desc', 'mod_flashcards'),
        45,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/googlevision_monthly_limit',
        get_string('settings_googlevision_monthly_limit', 'mod_flashcards'),
        get_string('settings_googlevision_monthly_limit_desc', 'mod_flashcards'),
        120,
        PARAM_INT
    ));

    // Push Notifications (VAPID)
    $settings->add(new admin_setting_heading(
        'mod_flashcards/push_heading',
        get_string('settings_push_section', 'mod_flashcards'),
        get_string('settings_push_section_desc', 'mod_flashcards')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_flashcards/push_enabled',
        get_string('settings_push_enable', 'mod_flashcards'),
        get_string('settings_push_enable_desc', 'mod_flashcards'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/vapid_public_key',
        get_string('settings_vapid_public', 'mod_flashcards'),
        get_string('settings_vapid_public_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_flashcards/vapid_private_key',
        get_string('settings_vapid_private', 'mod_flashcards'),
        get_string('settings_vapid_private_desc', 'mod_flashcards'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_flashcards/vapid_subject',
        get_string('settings_vapid_subject', 'mod_flashcards'),
        get_string('settings_vapid_subject_desc', 'mod_flashcards'),
        'mailto:admin@example.com'
    ));

    $ADMIN->add('modsettings', new admin_externalpage(
            'mod_flashcards_ttsusage',
            get_string('ttsusage_title', 'mod_flashcards'),
            new moodle_url('/mod/flashcards/admin/tts_usage.php'),
            'moodle/site:config'
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
