<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Flashcards';
$string['modulenameplural'] = 'Flashcards';
$string['modulename_help'] = 'Spaced-repetition flashcards activity.';
$string['pluginname'] = 'Flashcards';
$string['pluginadministration'] = 'Flashcards administration';
$string['flashcardsname'] = 'Activity name';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Intervals: 1,3,7,15,31,62,125,251';
$string['export'] = 'Export';
$string['import'] = 'Import';
$string['reset'] = 'Reset progress';
$string['profile'] = 'Profile:';
$string['activate'] = 'Activate lesson';
$string['choose'] = 'Choose lesson';
$string['loadpack'] = 'Load deck';
$string['due'] = 'Due: {$a}';
$string['list'] = 'Cards list';
$string['addown'] = 'Add your card';
$string['front'] = 'Front of the card';
$string['front_translation_mode_label'] = 'Translation direction';
$string['front_translation_mode_hint'] = 'Tap to switch input/output languages.';
$string['front_translation_status_idle'] = 'Translation ready';
$string['front_translation_status_loading'] = 'Translating...';
$string['front_translation_status_error'] = 'Translation failed';
$string['front_translation_reverse_hint'] = 'Type in your language to translate it into Norwegian automatically.';
$string['front_translation_copy'] = 'Copy translation';
$string['focus_translation_label'] = 'Focus meaning';
$string['fokus'] = 'Fokus word/phrase';
$string['focus_baseform'] = 'Base form';
$string['focus_baseform_ph'] = 'Lemma or infinitive (optional)';
$string['ai_helper_label'] = 'AI focus helper';
$string['ai_click_hint'] = 'Tap any word above to detect a fixed expression';
$string['ai_helper_disabled'] = 'AI helper is disabled by the administrator';
$string['ai_detecting'] = 'Detecting expression...';
$string['ai_helper_success'] = 'Focus phrase added';
$string['ai_helper_error'] = 'Could not detect an expression';
$string['ai_no_text'] = 'Type a sentence to enable the helper';
$string['focus_audio_badge'] = 'Focus audio';
$string['front_audio_badge'] = 'Front audio';
$string['explanation'] = 'Explanation';
$string['back'] = 'Translation';
$string['back_en'] = 'Translation';
$string['image'] = 'Image';
$string['audio'] = 'Audio';
$string['tts_voice'] = 'Voice';
$string['tts_voice_hint'] = 'Select a voice before asking the AI helper to generate audio.';
$string['tts_voice_placeholder'] = 'Default voice';
$string['tts_voice_missing'] = 'Add text-to-speech voices in the plugin settings.';
$string['tts_voice_disabled'] = 'Provide ElevenLabs or Amazon Polly keys to enable audio generation.';
$string['tts_status_success'] = 'Audio ready.';
$string['tts_status_error'] = 'Audio generation failed.';
$string['mediareport_title'] = 'Flashcards audio files';
$string['mediareport_filter_search'] = 'Search text or card ID';
$string['mediareport_filter_search_ph'] = 'e.g. infinitive, translation, card ID';
$string['mediareport_filter_user'] = 'Owner user ID';
$string['mediareport_filter_user_ph'] = 'Leave empty for all users';
$string['mediareport_filter_perpage'] = 'Rows per page';
$string['mediareport_empty'] = 'No cards with audio matched your filters.';
$string['mediareport_card'] = 'Card';
$string['mediareport_owner'] = 'Owner';
$string['mediareport_audio'] = 'Audio files';
$string['mediareport_updated'] = 'Updated';
$string['mediareport_audio_sentence'] = 'Sentence audio';
$string['mediareport_audio_front'] = 'Front audio';
$string['mediareport_audio_focus'] = 'Focus audio';
$string['mediareport_noaudio'] = 'No stored audio for this card.';
$string['mediareport_cardid'] = 'Card ID: {$a}';
$string['mediareport_deck'] = 'Deck: {$a}';
$string['choosefile'] = 'Choose file';
$string['chooseaudiofile'] = 'Choose audio file';
$string['showmore'] = 'Show more';
$string['autosave'] = 'Progress saved';
$string['easy'] = 'Easy';
$string['normal'] = 'Normal';
$string['hard'] = 'Hard';
$string['update'] = 'Update';
$string['createnew'] = 'Create new';
$string['order'] = 'Order (click in sequence)';
$string['empty'] = 'Nothing due today';
$string['resetform'] = 'Reset form';
$string['addtomycards'] = 'Add to my cards';
$string['install_app'] = 'Install App';
$string['interface_language_label'] = 'Interface language';
$string['font_scale_label'] = 'Font size';
$string['font_scale_default'] = 'Default (100%)';
$string['font_scale_plus15'] = 'Large (+15%)';
$string['font_scale_plus30'] = 'Extra large (+30%)';
$string['preferences_toggle_label'] = 'Preferences menu';
$string['header_preferences_label'] = 'Display preferences';

// Linguistic enrichment fields
$string['transcription'] = 'Transcription';
$string['pos'] = 'Part of speech';
$string['pos_noun'] = 'Noun';
$string['pos_verb'] = 'Verb';
$string['pos_adj'] = 'Adjective';
$string['pos_adv'] = 'Adverb';
$string['pos_other'] = 'Other';
$string['gender'] = 'Gender';
$string['gender_neuter'] = 'Neuter (intetkjonn)';
$string['gender_masculine'] = 'Masculine (hankjonn)';
$string['gender_feminine'] = 'Feminine (hunkjonn)';
$string['noun_forms'] = 'Noun forms';
$string['verb_forms'] = 'Verb forms';
$string['adj_forms'] = 'Adjective forms';
$string['indef_sg'] = 'Indefinite singular';
$string['def_sg'] = 'Definite singular';
$string['indef_pl'] = 'Indefinite plural';
$string['def_pl'] = 'Definite plural';
$string['antonyms'] = 'Antonyms';
$string['collocations'] = 'Common collocations';
$string['examples'] = 'Example sentences';
$string['cognates'] = 'Cognates';
$string['sayings'] = 'Common sayings';
$string['autofill'] = 'Auto-fill';
$string['fetch_from_api'] = 'Fetch via API';
$string['save'] = 'Save';
$string['skip'] = 'Skip';
$string['cancel'] = 'Cancel';
$string['fill_field'] = 'Please fill: {$a}';
$string['autofill_soon'] = 'Auto-fill will be available soon';

// iOS Install Instructions
$string['ios_install_title'] = 'Install this app on your Home Screen:';
$string['ios_install_step1'] = '1. Tap the';
$string['ios_install_step1_suffix'] = 'button';
$string['ios_install_step2'] = '2. Select';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Share';
$string['ios_add_to_home'] = 'Add to Home Screen';

// Titles / tooltips
$string['title_camera'] = 'Camera';
$string['title_take'] = 'Take photo';
$string['title_closecam'] = 'Close camera';
$string['title_play'] = 'Play';
$string['title_slow'] = 'Play 0.67Р“вЂ”';
$string['title_edit'] = 'Edit';
$string['title_del'] = 'Delete';
$string['title_record'] = 'Record';
$string['title_stop'] = 'Stop';
$string['press_hold_to_record'] = 'Press and hold to record';
$string['release_when_finished'] = 'Release when you finish';

// List table
$string['list_front'] = 'Front';
$string['list_deck'] = 'Deck';
$string['list_stage'] = 'Stage';
$string['list_added'] = 'Added';
$string['list_due'] = 'Next due';
$string['list_play'] = 'Play';
$string['search_ph'] = 'Search...';
$string['cards'] = 'Cards';
$string['close'] = 'Close';

// Access control messages
$string['access_denied'] = 'Access denied';
$string['access_expired_title'] = 'Flashcards access has expired';
$string['access_expired_message'] = 'You no longer have access to flashcards. Please enrol in a course to regain access.';
$string['access_grace_message'] = 'You can review your cards for {$a} more days. Enrol in a course to create new cards.';
$string['access_create_blocked'] = 'You cannot create new cards without an active course enrolment.';
$string['grace_period_restrictions'] = 'During grace period:';
$string['grace_can_review'] = '✓ You CAN review existing cards';
$string['grace_cannot_create'] = '✗ You CANNOT create new cards';

// Enhanced access status messages
$string['access_status_active'] = 'Active Access';
$string['access_status_active_desc'] = 'You have full access to create and review flashcards.';
$string['access_status_grace'] = 'Grace Period ({$a} days remaining)';
$string['access_status_grace_desc'] = 'You can review your existing cards but cannot create new ones. Enrol in a course to restore full access.';
$string['access_status_expired'] = 'Access Expired';
$string['access_status_expired_desc'] = 'Your access has expired. Enrol in a course to regain access to flashcards.';
$string['access_enrol_now'] = 'Enrol in a Course';
$string['access_days_remaining'] = '{$a} days remaining';

// Notifications
$string['messageprovider:grace_period_started'] = 'Flashcards grace period started';
$string['messageprovider:access_expiring_soon'] = 'Flashcards access expiring soon';
$string['messageprovider:access_expired'] = 'Flashcards access expired';

$string['notification_grace_subject'] = 'Flashcards: Grace period started';
$string['notification_grace_message'] = 'You are no longer enrolled in a flashcards course. You can review your existing cards for {$a} days. To create new cards, please enrol in a course.';
$string['notification_grace_message_html'] = '<p>You are no longer enrolled in a flashcards course.</p><p>You can <strong>review your existing cards for {$a} days</strong>.</p><p>To create new cards, please enrol in a course.</p>';

$string['notification_expiring_subject'] = 'Flashcards: Access expiring in 7 days';
$string['notification_expiring_message'] = 'Your flashcards access will expire in 7 days. Enrol in a course to keep access.';
$string['notification_expiring_message_html'] = '<p><strong>Your flashcards access will expire in 7 days.</strong></p><p>Enrol in a course to keep access to your cards.</p>';

$string['notification_expired_subject'] = 'Flashcards: Access expired';
$string['notification_expired_message'] = 'Your flashcards access has expired. Enrol in a course to regain access.';
$string['notification_expired_message_html'] = '<p><strong>Your flashcards access has expired.</strong></p><p>Enrol in a course to regain access to your cards.</p>';

// Global page strings
$string['myflashcards'] = 'My Flashcards';
$string['myflashcards_welcome'] = 'Welcome to your flashcards!';
$string['access_denied_full'] = 'You do not have access to view flashcards. Please enrol in a course with flashcards activity.';
$string['browse_courses'] = 'Browse available courses';

// Scheduled tasks
$string['task_check_user_access'] = 'Check flashcards user access and grace periods';
$string['task_cleanup_orphans'] = 'Cleanup orphaned flashcards progress records';

$string['cards_remaining'] = 'cards remaining';
$string['rating_actions'] = 'Rating actions';
$string['progress_label'] = 'Review progress';
// Overrides
$string['title_slow'] = 'Play slowly';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Create new card';
$string['tab_study'] = 'Practice';
$string['tab_dashboard'] = 'Dashboard';

// Quick Input
$string['quickinput_title'] = 'Add New Card';
$string['quick_audio'] = 'Record Audio';
$string['quick_photo'] = 'Take Photo';
$string['show_advanced'] = 'Show Advanced ▼';
$string['hide_advanced'] = 'Hide Advanced ▲';
$string['card_created'] = 'Card created!';
$string['quickinput_created_today'] = '{$a} created today';

// Dashboard
$string['dashboard_cards_due'] = 'Cards Due Today';
$string['dashboard_total_cards'] = 'Total Cards Created';
$string['dashboard_active_vocab'] = 'Active vocabulary';
$string['dashboard_streak'] = 'Current Streak (days)';
$string['dashboard_study_time'] = 'Study Time This Week';
$string['dashboard_stage_chart'] = 'Card Stages Distribution';
$string['dashboard_activity_chart'] = 'Review Activity (Last 7 Days)';
$string['dashboard_achievements'] = 'Achievements';

// Achievements
$string['achievement_first_card'] = 'First Card';
$string['achievement_week_warrior'] = 'Week Warrior (7-day streak)';
$string['achievement_century'] = 'Century (100 cards)';
$string['achievement_study_bug'] = 'Study Bug (10 hours)';
$string['achievement_master'] = 'Master (1 card at stage 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Level A0 - Beginner';
$string['achievement_level_a1'] = 'Level A1 - Elementary';
$string['achievement_level_a2'] = 'Level A2 - Pre-Intermediate';
$string['achievement_level_b1'] = 'Level B1 - Intermediate';
$string['achievement_level_b2'] = 'Level B2 - Upper-Intermediate';

// Placeholders
$string['collocations_ph'] = 'One per line...';
$string['examples_ph'] = 'Example sentences...';
$string['front_placeholder'] = 'Jeg elsker deg';
$string['translation_en_placeholder'] = 'I love you';
$string['translation_placeholder'] = 'Jeg elsker deg';
$string['explanation_placeholder'] = 'Explanation...';
$string['focus_placeholder'] = 'Focus word/phrase...';
$string['collocations_placeholder'] = 'collocations...';
$string['examples_placeholder'] = 'examples...';
$string['antonyms_placeholder'] = 'antonyms...';
$string['cognates_placeholder'] = 'cognates...';
$string['sayings_placeholder'] = 'sayings...';
$string['transcription_placeholder'] = '[IPA e.g. /hu:s/]';
$string['one_per_line_placeholder'] = 'one per line...';

// Settings - AI & TTS
$string['settings_ai_section'] = 'AI assistant';
$string['settings_ai_section_desc'] = 'Configure the ChatGPT model used to detect fixed expressions when a learner clicks a word.';
$string['settings_ai_enable'] = 'Enable AI focus helper';
$string['settings_ai_enable_desc'] = 'Allow learners to highlight a word in the Front text and let AI detect the matching expression.';
$string['settings_openai_key'] = 'OpenAI API key';
$string['settings_openai_key_desc'] = 'Stored securely on the server. Required for the focus helper.';
$string['settings_openai_model'] = 'OpenAI model';
$string['settings_openai_model_desc'] = 'For example gpt-4o-mini. The helper uses chat-completions.';
$string['settings_openai_url'] = 'OpenAI endpoint';
$string['settings_openai_url_desc'] = 'Override only when using a proxy-compatible endpoint.';

$string['settings_tts_section'] = 'Text-to-Speech';
$string['settings_tts_section_desc'] = 'Configure speech providers for full sentences (ElevenLabs) and short focus phrases (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'ElevenLabs API key';
$string['settings_elevenlabs_key_desc'] = 'Stored securely on the server and never exposed to learners.';
$string['settings_elevenlabs_voice'] = 'Default voice ID';
$string['settings_elevenlabs_voice_desc'] = 'Used when the learner does not select a specific voice.';
$string['settings_elevenlabs_voice_map'] = 'Voice options';
$string['settings_elevenlabs_voice_map_desc'] = 'Define one voice per line using the format Name=voice-id. Example: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ElevenLabs model ID';
$string['settings_elevenlabs_model_desc'] = 'Defaults to eleven_monolingual_v2. Update only if your account uses a different model.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Used for ultra-short phrases (two words or fewer) to keep latency low.';
$string['settings_polly_key'] = 'AWS access key ID';
$string['settings_polly_key_desc'] = 'Requires the AmazonPollyFullAccess or equivalent IAM policy.';
$string['settings_polly_secret'] = 'AWS secret access key';
$string['settings_polly_secret_desc'] = 'Stored securely on the server and never exposed to learners.';
$string['settings_polly_region'] = 'AWS region';
$string['settings_polly_region_desc'] = 'Example: eu-west-1. Must match the region where Polly is available.';
$string['settings_polly_voice'] = 'Default Polly voice';
$string['settings_polly_voice_desc'] = 'Voice name (e.g. Liv, Ida) used when no override is defined.';
$string['settings_polly_voice_map'] = 'Polly voice overrides';
$string['settings_polly_voice_map_desc'] = 'Optional mapping between ElevenLabs voice IDs and Polly voice names. Use the format elevenVoiceId=PollyVoice per line.';

$string['settings_orbokene_section'] = 'Orbøkene dictionary';
$string['settings_orbokene_section_desc'] = 'When enabled the AI helper will try to enrich detected expressions with data from the flashcards_orbokene table.';
$string['settings_orbokene_enable'] = 'Enable dictionary auto-fill';
$string['settings_orbokene_enable_desc'] = 'If enabled, matching entries in the Orbøkene cache populate definition, translation and examples.';

// Fill field dialog
$string['fill_field'] = 'Please fill: {$a}';

// Errors
$string['ai_http_error'] = 'The AI service is unavailable. Please try again later.';
$string['ai_invalid_json'] = 'Unexpected response from the AI service.';
$string['ai_disabled'] = 'The AI helper is not configured yet.';
$string['tts_http_error'] = 'Text-to-speech is temporarily unavailable.';
