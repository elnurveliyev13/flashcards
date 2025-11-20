<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Lernkarten';
$string['modulenameplural'] = 'Lernkarten';
$string['modulename_help'] = 'Lernkarten-Aktivität mit verteilter Wiederholung.';
$string['pluginname'] = 'Lernkarten';
$string['pluginadministration'] = 'Lernkarten-Administration';
$string['flashcardsname'] = 'Aktivitätsname';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Intervalle: 1,3,7,15,31,62,125,251';
$string['export'] = 'Exportieren';
$string['import'] = 'Importieren';
$string['reset'] = 'Fortschritt zurücksetzen';
$string['profile'] = 'Profil:';
$string['activate'] = 'Lektion aktivieren';
$string['choose'] = 'Lektion wählen';
$string['loadpack'] = 'Stapel laden';
$string['due'] = 'Fällig: {$a}';
$string['list'] = 'Kartenliste';
$string['addown'] = 'Eigene Karte hinzufügen';
$string['front'] = 'Text';
$string['front_translation_mode_label'] = 'Übersetzungsrichtung';
$string['front_translation_mode_hint'] = 'Tippen, um Ein-/Ausgabesprachen zu wechseln.';
$string['front_translation_status_idle'] = 'Übersetzung bereit';
$string['front_translation_status_loading'] = 'Übersetzung läuft...';
$string['front_translation_status_error'] = 'Übersetzungsfehler';
$string['front_translation_reverse_hint'] = 'Geben Sie Text in Ihrer Sprache ein, um ihn automatisch ins Norwegische zu übersetzen.';
$string['front_translation_copy'] = 'Übersetzung kopieren';
$string['focus_translation_label'] = 'Fokusbedeutung';
$string['fokus'] = 'Fokuswort/-phrase';
$string['focus_baseform'] = 'Grundform';
$string['focus_baseform_ph'] = 'Lemma oder Infinitiv (optional)';
$string['ai_helper_label'] = 'KI-Fokus-Assistent';
$string['ai_click_hint'] = 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen';
$string['ai_helper_disabled'] = 'KI-Assistent vom Administrator deaktiviert';
$string['ai_detecting'] = 'Ausdruck wird erkannt...';
$string['ai_helper_success'] = 'Fokusphrase hinzugefügt';
$string['ai_helper_error'] = 'Ausdruck konnte nicht erkannt werden';
$string['ai_no_text'] = 'Geben Sie einen Satz ein, um den Assistenten zu aktivieren';
$string['choose_focus_word'] = 'Fokuswort auswahlen';
$string['ai_question_label'] = 'KI fragen';
$string['ai_question_placeholder'] = 'Stellen Sie eine Frage zu diesem Satz...';
$string['ai_question_button'] = 'Fragen';
$string['ai_chat_empty'] = 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы';
$string['focus_audio_badge'] = 'Fokus-Audio';
$string['front_audio_badge'] = 'Vorderseiten-Audio';
$string['explanation'] = 'Erklärung';
$string['back'] = 'Übersetzung';
$string['back_en'] = 'Übersetzung';
$string['image'] = 'Bild';
$string['audio'] = 'Audio';
$string['order_audio_word'] = 'Audio (Wort)';
$string['order_audio_text'] = 'Audio (Text)';
$string['tts_voice'] = 'Stimme';
$string['tts_voice_hint'] = 'Wählen Sie eine Stimme aus, bevor Sie den KI-Assistenten bitten, Audio zu generieren.';
$string['tts_voice_placeholder'] = 'Standardstimme';
$string['tts_voice_missing'] = 'Fügen Sie Text-zu-Sprache-Stimmen in den Plugin-Einstellungen hinzu.';
$string['tts_voice_disabled'] = 'Geben Sie ElevenLabs- oder Amazon Polly-Schlüssel an, um Audio-Generierung zu aktivieren.';
$string['tts_status_success'] = 'Audio bereit.';
$string['tts_status_error'] = 'Audio-Generierung fehlgeschlagen.';
$string['mediareport_title'] = 'Lernkarten-Audiodateien';
$string['mediareport_filter_search'] = 'Text oder Karten-ID suchen';
$string['mediareport_filter_search_ph'] = 'z.B. Infinitiv, Übersetzung, Karten-ID';
$string['mediareport_filter_user'] = 'Eigentümer-Benutzer-ID';
$string['mediareport_filter_user_ph'] = 'Leer lassen für alle Benutzer';
$string['mediareport_filter_perpage'] = 'Zeilen pro Seite';
$string['mediareport_empty'] = 'Keine Karten mit Audio entsprechen Ihren Filtern.';
$string['mediareport_card'] = 'Karte';
$string['mediareport_owner'] = 'Eigentümer';
$string['mediareport_audio'] = 'Audiodateien';
$string['mediareport_updated'] = 'Aktualisiert';
$string['mediareport_audio_sentence'] = 'Satz-Audio';
$string['mediareport_audio_front'] = 'Vorderseiten-Audio';
$string['mediareport_audio_focus'] = 'Fokus-Audio';
$string['mediareport_noaudio'] = 'Kein gespeichertes Audio für diese Karte.';
$string['mediareport_cardid'] = 'Karten-ID: {$a}';
$string['mediareport_deck'] = 'Stapel: {$a}';
$string['choosefile'] = 'Datei auswählen';
$string['chooseaudiofile'] = 'Audiodatei auswählen';
$string['showmore'] = 'Mehr anzeigen';
$string['autosave'] = 'Fortschritt gespeichert';
$string['easy'] = 'Einfach';
$string['normal'] = 'Normal';
$string['hard'] = 'Schwer';
$string['update'] = 'Aktualisieren';
$string['update_disabled_hint'] = 'Öffnen Sie zuerst eine bestehende Karte, um Aktualisieren zu aktivieren.';
$string['createnew'] = 'Erstellen';
$string['order'] = 'Reihenfolge (in Reihenfolge klicken)';
$string['empty'] = 'Heute nichts fällig';
$string['resetform'] = 'Formular zurücksetzen';
$string['addtomycards'] = 'Zu meinen Karten hinzufügen';
$string['install_app'] = 'App installieren';

// Linguistic enrichment fields
$string['transcription'] = 'Transkription';
$string['pos'] = 'Wortart';
$string['pos_noun'] = 'Substantiv';
$string['pos_verb'] = 'Verb';
$string['pos_adj'] = 'Adjektiv';
$string['pos_adv'] = 'Adverb';
$string['pos_other'] = 'Andere';
$string['gender'] = 'Geschlecht';
$string['gender_neuter'] = 'Neutrum (intetkjonn)';
$string['gender_masculine'] = 'Maskulinum (hankjonn)';
$string['gender_feminine'] = 'Femininum (hunkjonn)';
$string['noun_forms'] = 'Substantivformen';
$string['verb_forms'] = 'Verbformen';
$string['adj_forms'] = 'Adjektivformen';
$string['indef_sg'] = 'Unbestimmter Singular';
$string['def_sg'] = 'Bestimmter Singular';
$string['indef_pl'] = 'Unbestimmter Plural';
$string['def_pl'] = 'Bestimmter Plural';
$string['antonyms'] = 'Antonyme';
$string['collocations'] = 'Häufige Kollokationen';
$string['examples'] = 'Beispielsätze';
$string['cognates'] = 'Kognaten';
$string['sayings'] = 'Häufige Redewendungen';
$string['autofill'] = 'Automatisches Ausfüllen';
$string['fetch_from_api'] = 'Über API abrufen';
$string['save'] = 'Speichern';
$string['skip'] = 'Überspringen';
$string['cancel'] = 'Abbrechen';
$string['fill_field'] = 'Bitte ausfüllen: {$a}';
$string['autofill_soon'] = 'Automatisches Ausfüllen wird bald verfügbar sein';

// iOS Install Instructions
$string['ios_install_title'] = 'Installieren Sie diese App auf Ihrem Startbildschirm:';
$string['ios_install_step1'] = '1. Tippen Sie auf die Schaltfläche';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Wählen Sie';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Teilen';
$string['ios_add_to_home'] = 'Zum Startbildschirm';

// Titles / tooltips
$string['title_camera'] = 'Kamera';
$string['title_take'] = 'Foto aufnehmen';
$string['title_closecam'] = 'Kamera schließen';
$string['title_play'] = 'Abspielen';
$string['title_slow'] = 'Langsam abspielen';
$string['title_edit'] = 'Bearbeiten';
$string['title_del'] = 'Löschen';
$string['title_record'] = 'Aufnehmen';
$string['title_stop'] = 'Stoppen';
$string['title_record_practice'] = 'Aussprache aufnehmen';
$string['press_hold_to_record'] = 'Drücken und halten zum Aufnehmen';
$string['release_when_finished'] = 'Loslassen, wenn fertig';

// List table
$string['list_front'] = 'Fokuswort/-phrase';
$string['list_deck'] = 'Stapel';
$string['list_stage'] = 'Stufe';
$string['list_added'] = 'Hinzugefügt';
$string['list_due'] = 'Nächste Fälligkeit';
$string['list_play'] = 'Abspielen';
$string['search_ph'] = 'Suchen...';
$string['cards'] = 'Karten';
$string['close'] = 'Schließen';

// Access control messages
$string['access_denied'] = 'Zugriff verweigert';
$string['access_expired_title'] = 'Lernkarten-Zugriff ist abgelaufen';
$string['access_expired_message'] = 'Sie haben keinen Zugriff mehr auf Lernkarten. Bitte melden Sie sich für einen Kurs an, um den Zugriff wiederherzustellen.';
$string['access_grace_message'] = 'Sie können Ihre Karten für weitere {$a} Tage überprüfen. Melden Sie sich für einen Kurs an, um neue Karten zu erstellen.';
$string['access_create_blocked'] = 'Sie können keine neuen Karten ohne aktive Kursanmeldung erstellen.';
$string['grace_period_restrictions'] = 'Während der Kulanzfrist:';
$string['grace_can_review'] = '✓ Sie KÖNNEN vorhandene Karten überprüfen';
$string['grace_cannot_create'] = '✗ Sie KÖNNEN KEINE neuen Karten erstellen';

// Enhanced access status messages
$string['access_status_active'] = 'Aktiver Zugriff';
$string['access_status_active_desc'] = 'Sie haben vollen Zugriff zum Erstellen und Überprüfen von Lernkarten.';
$string['access_status_grace'] = 'Kulanzfrist ({$a} Tage verbleibend)';
$string['access_status_grace_desc'] = 'Sie können Ihre vorhandenen Karten überprüfen, aber keine neuen erstellen. Melden Sie sich für einen Kurs an, um vollen Zugriff wiederherzustellen.';
$string['access_status_expired'] = 'Zugriff abgelaufen';
$string['access_status_expired_desc'] = 'Ihr Zugriff ist abgelaufen. Melden Sie sich für einen Kurs an, um den Zugriff auf Lernkarten wiederherzustellen.';
$string['access_enrol_now'] = 'Für einen Kurs anmelden';
$string['access_days_remaining'] = '{$a} Tage verbleibend';

// Notifications
$string['messageprovider:grace_period_started'] = 'Lernkarten-Kulanzfrist begonnen';
$string['messageprovider:access_expiring_soon'] = 'Lernkarten-Zugriff läuft bald ab';
$string['messageprovider:access_expired'] = 'Lernkarten-Zugriff abgelaufen';

$string['notification_grace_subject'] = 'Lernkarten: Kulanzfrist begonnen';
$string['notification_grace_message'] = 'Sie sind nicht mehr in einem Lernkarten-Kurs angemeldet. Sie können Ihre vorhandenen Karten für {$a} Tage überprüfen. Um neue Karten zu erstellen, melden Sie sich bitte für einen Kurs an.';
$string['notification_grace_message_html'] = '<p>Sie sind nicht mehr in einem Lernkarten-Kurs angemeldet.</p><p>Sie können <strong>Ihre vorhandenen Karten für {$a} Tage überprüfen</strong>.</p><p>Um neue Karten zu erstellen, melden Sie sich bitte für einen Kurs an.</p>';

$string['notification_expiring_subject'] = 'Lernkarten: Zugriff läuft in 7 Tagen ab';
$string['notification_expiring_message'] = 'Ihr Lernkarten-Zugriff läuft in 7 Tagen ab. Melden Sie sich für einen Kurs an, um den Zugriff zu behalten.';
$string['notification_expiring_message_html'] = '<p><strong>Ihr Lernkarten-Zugriff läuft in 7 Tagen ab.</strong></p><p>Melden Sie sich für einen Kurs an, um den Zugriff auf Ihre Karten zu behalten.</p>';

$string['notification_expired_subject'] = 'Lernkarten: Zugriff abgelaufen';
$string['notification_expired_message'] = 'Ihr Lernkarten-Zugriff ist abgelaufen. Melden Sie sich für einen Kurs an, um den Zugriff wiederherzustellen.';
$string['notification_expired_message_html'] = '<p><strong>Ihr Lernkarten-Zugriff ist abgelaufen.</strong></p><p>Melden Sie sich für einen Kurs an, um den Zugriff auf Ihre Karten wiederherzustellen.</p>';

// Global page strings
$string['myflashcards'] = 'Meine Lernkarten';
$string['myflashcards_welcome'] = 'Willkommen zu Ihren Lernkarten!';
$string['access_denied_full'] = 'Sie haben keinen Zugriff zum Anzeigen von Lernkarten. Bitte melden Sie sich für einen Kurs mit Lernkarten-Aktivität an.';
$string['browse_courses'] = 'Verfügbare Kurse durchsuchen';

// Scheduled tasks
$string['task_check_user_access'] = 'Lernkarten-Benutzerzugriff und Kulanzfristen überprüfen';
$string['task_cleanup_orphans'] = 'Verwaiste Lernkarten-Fortschrittsdatensätze bereinigen';

$string['cards_remaining'] = 'Karten verbleibend';
$string['rating_actions'] = 'Bewertungsaktionen';
$string['progress_label'] = 'Überprüfungsfortschritt';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Erstellen';
$string['tab_study'] = 'Studieren';
$string['tab_dashboard'] = 'Dashboard';

// Quick Input
$string['quickinput_title'] = 'Neue Karte hinzufügen';
$string['quick_audio'] = 'Audio aufnehmen';
$string['quick_photo'] = 'Foto aufnehmen';
$string['show_advanced'] = 'Erweitert anzeigen ▼';
$string['hide_advanced'] = 'Erweitert ausblenden ▲';
$string['card_created'] = 'Karte erstellt!';
$string['quickinput_created_today'] = '{$a} heute erstellt';

// Dashboard
$string['dashboard_cards_due'] = 'Heute fällige Karten';
$string['dashboard_total_cards'] = 'Gesamt erstellte Karten';
$string['dashboard_active_vocab'] = 'Aktiver Wortschatz';
$string['dashboard_streak'] = 'Aktuelle Serie (Tage)';
$string['dashboard_study_time'] = 'Lernzeit diese Woche';
$string['dashboard_stage_chart'] = 'Kartenstufen-Verteilung';
$string['dashboard_activity_chart'] = 'Überprüfungsaktivität (letzte 7 Tage)';
$string['dashboard_achievements'] = 'Erfolge';

// Achievements
$string['achievement_first_card'] = 'Erste Karte';
$string['achievement_week_warrior'] = 'Wochenkrieger (7-Tages-Serie)';
$string['achievement_century'] = 'Jahrhundert (100 Karten)';
$string['achievement_study_bug'] = 'Lernwurm (10 Stunden)';
$string['achievement_master'] = 'Meister (1 Karte auf Stufe 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Niveau A0 - Anfänger';
$string['achievement_level_a1'] = 'Niveau A1 - Grundstufe';
$string['achievement_level_a2'] = 'Niveau A2 - Untere Mittelstufe';
$string['achievement_level_b1'] = 'Niveau B1 - Mittelstufe';
$string['achievement_level_b2'] = 'Niveau B2 - Obere Mittelstufe';

// Placeholders
$string['collocations_ph'] = 'Eine pro Zeile...';
$string['examples_ph'] = 'Beispielsätze...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Ich liebe dich';
$string['translation_en_placeholder'] = 'I love you';
$string['translation_in_phrase'] = 'Übersetzung auf ';

// Settings - AI & TTS
$string['settings_ai_section'] = 'KI-Assistent';
$string['settings_ai_section_desc'] = 'Konfigurieren Sie das ChatGPT-Modell, das verwendet wird, um feste Ausdrücke zu erkennen, wenn ein Lernender auf ein Wort klickt.';
$string['settings_ai_enable'] = 'KI-Fokus-Assistent aktivieren';
$string['settings_ai_enable_desc'] = 'Lernenden ermöglichen, ein Wort im Vorderseitentext zu markieren und KI den passenden Ausdruck erkennen zu lassen.';
$string['settings_openai_key'] = 'OpenAI-API-Schlüssel';
$string['settings_openai_key_desc'] = 'Sicher auf dem Server gespeichert. Erforderlich für den Fokus-Assistenten.';
$string['settings_openai_model'] = 'OpenAI-Modell';
$string['settings_openai_model_desc'] = 'Zum Beispiel gpt-4o-mini. Der Assistent verwendet chat-completions.';
$string['settings_openai_url'] = 'OpenAI-Endpunkt';
$string['settings_openai_url_desc'] = 'Nur überschreiben, wenn ein Proxy-kompatibler Endpunkt verwendet wird.';

$string['settings_tts_section'] = 'Text-zu-Sprache';
$string['settings_tts_section_desc'] = 'Konfigurieren Sie Sprachanbieter für vollständige Sätze (ElevenLabs) und kurze Fokusphrasen (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'ElevenLabs-API-Schlüssel';
$string['settings_elevenlabs_key_desc'] = 'Sicher auf dem Server gespeichert und niemals Lernenden zugänglich gemacht.';
$string['settings_elevenlabs_voice'] = 'Standard-Stimmen-ID';
$string['settings_elevenlabs_voice_desc'] = 'Wird verwendet, wenn der Lernende keine spezifische Stimme auswählt.';
$string['settings_elevenlabs_voice_map'] = 'Stimmenoptionen';
$string['settings_elevenlabs_voice_map_desc'] = 'Definieren Sie eine Stimme pro Zeile im Format Name=voice-id. Beispiel: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ElevenLabs-Modell-ID';
$string['settings_elevenlabs_model_desc'] = 'Standard ist eleven_monolingual_v2. Nur aktualisieren, wenn Ihr Konto ein anderes Modell verwendet.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Verwendet für ultrakurze Phrasen (zwei Wörter oder weniger), um die Latenz niedrig zu halten.';
$string['settings_polly_key'] = 'AWS-Zugriffsschlüssel-ID';
$string['settings_polly_key_desc'] = 'Erfordert die IAM-Richtlinie AmazonPollyFullAccess oder gleichwertig.';
$string['settings_polly_secret'] = 'AWS-Geheimer Zugriffsschlüssel';
$string['settings_polly_secret_desc'] = 'Sicher auf dem Server gespeichert und niemals Lernenden zugänglich gemacht.';
$string['settings_polly_region'] = 'AWS-Region';
$string['settings_polly_region_desc'] = 'Beispiel: eu-west-1. Muss mit der Region übereinstimmen, in der Polly verfügbar ist.';
$string['settings_polly_voice'] = 'Standard-Polly-Stimme';
$string['settings_polly_voice_desc'] = 'Stimmenname (z.B. Liv, Ida), der verwendet wird, wenn keine Überschreibung definiert ist.';
$string['settings_polly_voice_map'] = 'Polly-Stimmen-Überschreibungen';
$string['settings_polly_voice_map_desc'] = 'Optionale Zuordnung zwischen ElevenLabs-Stimmen-IDs und Polly-Stimmennamen. Verwenden Sie das Format elevenVoiceId=PollyVoice pro Zeile.';

$string['settings_orbokene_section'] = 'Orbøkene-Wörterbuch';
$string['settings_orbokene_section_desc'] = 'Wenn aktiviert, versucht der KI-Assistent, erkannte Ausdrücke mit Daten aus der Tabelle flashcards_orbokene anzureichern.';
$string['settings_orbokene_enable'] = 'Wörterbuch-Autofill aktivieren';
$string['settings_orbokene_enable_desc'] = 'Wenn aktiviert, füllen übereinstimmende Einträge im Orbøkene-Cache Definition, Übersetzung und Beispiele.';

// Fill field dialog
$string['fill_field'] = 'Bitte ausfüllen: {$a}';

// Errors
$string['ai_http_error'] = 'Der KI-Dienst ist nicht verfügbar. Bitte versuchen Sie es später erneut.';
$string['ai_invalid_json'] = 'Unerwartete Antwort vom KI-Dienst.';
$string['ai_disabled'] = 'Der KI-Assistent ist noch nicht konfiguriert.';
$string['tts_http_error'] = 'Text-zu-Sprache ist vorübergehend nicht verfügbar.';
