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
$string['app_title'] = 'SRS Cards';
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
$string['front'] = 'Front text';
$string['explanation'] = 'Explanation';
$string['back'] = 'Translation';
$string['image'] = 'Image';
$string['audio'] = 'Audio';
$string['choosefile'] = 'Choose file';
$string['showmore'] = 'Show more';
$string['autosave'] = 'Progress saved';
$string['easy'] = 'Easy';
$string['normal'] = 'Normal';
$string['hard'] = 'Hard';
$string['order'] = 'Order (click in sequence)';
$string['empty'] = 'Nothing due today';
$string['resetform'] = 'Reset form';
$string['addtomycards'] = 'Add to my cards';
$string['install_app'] = 'Install App';

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
$string['grace_can_review'] = 'РІСљвЂњ You CAN review existing cards';
$string['grace_cannot_create'] = 'РІСљвЂ” You CANNOT create new cards';

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

$string['cards_remaining'] = 'cards remaining';
$string['rating_actions'] = 'Rating actions';
$string['progress_label'] = 'Review progress';
// Overrides
$string['title_slow'] = 'Play slowly';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Add card';
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

// Placeholders
$string['collocations_ph'] = 'One per line...';
$string['examples_ph'] = 'Example sentences...';
