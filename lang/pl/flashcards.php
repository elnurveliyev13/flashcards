<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Fiszki';
$string['modulenameplural'] = 'Fiszki';
$string['modulename_help'] = 'Aktywność fiszek z powtórkami rozłożonymi w czasie.';
$string['pluginname'] = 'Fiszki';
$string['pluginadministration'] = 'Administracja fiszkami';
$string['flashcardsname'] = 'Nazwa aktywności';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Interwały: 1,3,7,15,31,62,125,251';
$string['export'] = 'Eksportuj';
$string['import'] = 'Importuj';
$string['reset'] = 'Zresetuj postęp';
$string['profile'] = 'Profil:';
$string['activate'] = 'Aktywuj lekcję';
$string['choose'] = 'Wybierz lekcję';
$string['loadpack'] = 'Załaduj talię';
$string['due'] = 'Do nauki: {$a}';
$string['list'] = 'Lista fiszek';
$string['addown'] = 'Dodaj swoją fiszkę';
$string['front'] = 'Tekst';
$string['front_translation_mode_label'] = 'Kierunek tłumaczenia';
$string['front_translation_mode_hint'] = 'Dotknij, aby zmienić języki wejścia/wyjścia.';
$string['front_translation_status_idle'] = 'Tłumaczenie gotowe';
$string['front_translation_status_loading'] = 'Tłumaczenie...';
$string['front_translation_status_error'] = 'Błąd tłumaczenia';
$string['front_translation_reverse_hint'] = 'Wpisz w swoim języku, aby automatycznie przetłumaczyć na norweski.';
$string['front_translation_copy'] = 'Kopiuj tłumaczenie';
$string['focus_translation_label'] = 'Znaczenie fokusowe';
$string['fokus'] = 'Słowo/fraza fokusowa';
$string['focus_baseform'] = 'Forma podstawowa';
$string['focus_baseform_ph'] = 'Lemat lub bezokolicznik (opcjonalnie)';
$string['ai_helper_label'] = 'Asystent AI fokusa';
$string['ai_click_hint'] = 'Dotknij dowolnego słowa powyżej, aby wykryć stałe wyrażenie';
$string['front_suggest_collapse'] = 'Ukryj sugestie';
$string['ai_helper_disabled'] = 'Asystent AI wyłączony przez administratora';
$string['ai_detecting'] = 'Wykrywanie wyrażenia...';
$string['ai_helper_success'] = 'Fraza fokusowa dodana';
$string['ai_helper_error'] = 'Nie udało się wykryć wyrażenia';
$string['ai_no_text'] = 'Wpisz zdanie, aby włączyć asystenta';
$string['spacy_active_status'] = 'spaCy parsed {$a} tokens';
$string['spacy_inactive_status'] = 'spaCy analysis unavailable';
$string['spacy_model_label'] = 'Model: {$a}';
$string['token_create_card'] = 'Utworz fiszke';
$string['token_create_card_status'] = 'Szkic fiszki przygotowany';
$string['choose_focus_word'] = 'Wybierz słowo lub frazę fokusową';
$string['ai_question_label'] = 'Zapytaj AI';
$string['ai_question_placeholder'] = 'Wpisz pytanie dotyczące tego zdania...';
$string['ai_question_button'] = 'Zapytaj';
$string['ai_chat_empty'] = 'Zadaj pytanie AI o Twój tekst lub słowo/frazę fokusową';
$string['ai_chat_user'] = 'Ty';
$string['ai_chat_assistant'] = 'AI';
$string['ai_chat_error'] = 'AI nie mogło odpowiedzieć na to pytanie.';
$string['ai_chat_loading'] = 'Myśli...';
$string['check_text'] = 'Sprawdź tekst';
$string['no_errors_found'] = 'Nie znaleziono błędów!';
$string['apply_corrections'] = 'Zastosuj poprawki';
$string['keep_as_is'] = 'Zostaw jak jest';
$string['error_checking_failed'] = 'Sprawdzanie nie powiodło się';
$string['naturalness_suggestion'] = 'Bardziej naturalny wariant:';
$string['ask_ai_about_correction'] = 'Zapytaj AI';
$string['ai_sure'] = 'Jesteś pewien?';
$string['ai_explain_more'] = 'Wyjaśnij bardziej szczegółowo';
$string['ai_more_examples'] = 'Daj więcej przykładów';
$string['ai_thinking'] = 'Myśli...';
$string['focus_audio_badge'] = 'Audio fokusowe';
$string['front_audio_badge'] = 'Audio przodu';
$string['explanation'] = 'Wyjaśnienie';
$string['back'] = 'Tłumaczenie';
$string['back_en'] = 'Tłumaczenie';
$string['image'] = 'Obraz';
$string['audio'] = 'Audio';
$string['order_audio_word'] = 'Audio fokusowe';
$string['order_audio_text'] = 'Audio';
$string['tts_voice'] = 'Głos';
$string['tts_voice_hint'] = 'Wybierz głos przed poproszeniem asystenta AI o wygenerowanie audio.';
$string['tts_voice_placeholder'] = 'Głos domyślny';
$string['tts_voice_missing'] = 'Dodaj głosy syntezy mowy w ustawieniach wtyczki.';
$string['tts_voice_disabled'] = 'Podaj klucze ElevenLabs lub Amazon Polly, aby włączyć generowanie audio.';
$string['tts_status_success'] = 'Audio gotowe.';
$string['tts_status_error'] = 'Błąd generowania audio.';
$string['mediareport_title'] = 'Pliki audio fiszek';
$string['mediareport_filter_search'] = 'Szukaj tekstu lub ID fiszki';
$string['mediareport_filter_search_ph'] = 'np. bezokolicznik, tłumaczenie, ID fiszki';
$string['mediareport_filter_user'] = 'ID użytkownika-właściciela';
$string['mediareport_filter_user_ph'] = 'Pozostaw puste dla wszystkich użytkowników';
$string['mediareport_filter_perpage'] = 'Wierszy na stronę';
$string['mediareport_empty'] = 'Nie znaleziono fiszek z audio pasujących do twoich filtrów.';
$string['mediareport_card'] = 'Fiszka';
$string['mediareport_owner'] = 'Właściciel';
$string['mediareport_audio'] = 'Pliki audio';
$string['mediareport_updated'] = 'Zaktualizowano';
$string['mediareport_audio_sentence'] = 'Audio zdania';
$string['mediareport_audio_front'] = 'Audio przodu';
$string['mediareport_audio_focus'] = 'Audio fokusowe';
$string['mediareport_noaudio'] = 'Brak zapisanego audio dla tej fiszki.';
$string['mediareport_cardid'] = 'ID fiszki: {$a}';
$string['mediareport_deck'] = 'Talia: {$a}';
$string['choosefile'] = 'Wybierz plik';
$string['chooseaudiofile'] = 'Wybierz plik audio';
$string['showmore'] = 'Pokaż więcej';
$string['autosave'] = 'Postęp zapisany';
$string['easy'] = 'Łatwe';
$string['normal'] = 'Normalne';
$string['hard'] = 'Trudne';
$string['btnHardHint'] = 'Powtórz tę fiszkę dzisiaj';
$string['btnNormalHint'] = 'Następny przegląd jutro';
$string['btnEasyHint'] = 'Przejdź do następnego etapu';
$string['update'] = 'Aktualizuj';
$string['update_disabled_hint'] = 'Najpierw otwórz istniejącą fiszkę, aby włączyć aktualizację.';
$string['createnew'] = 'Utwórz';
$string['order'] = 'Kolejność (klikaj po kolei)';
$string['empty'] = 'Nic do nauki dzisiaj';
$string['resetform'] = 'Wyczyść';
$string['addtomycards'] = 'Dodaj do moich fiszek';
$string['install_app'] = 'Zainstaluj aplikację';

// Linguistic enrichment fields
$string['transcription'] = 'Transkrypcja';
$string['pos'] = 'Część mowy';
$string['pos_noun'] = 'Rzeczownik';
$string['pos_verb'] = 'Czasownik';
$string['pos_adj'] = 'Przymiotnik';
$string['pos_adv'] = 'Przysłówek';
$string['pos_other'] = 'Inne';
$string['gender'] = 'Rodzaj';
$string['gender_neuter'] = 'Nijaki (intetkjønn)';
$string['gender_masculine'] = 'Męski (hankjønn)';
$string['gender_feminine'] = 'Żeński (hunkjønn)';
$string['noun_forms'] = 'Formy rzeczownika';
$string['verb_forms'] = 'Formy czasownika';
$string['adj_forms'] = 'Formy przymiotnika';
$string['indef_sg'] = 'Liczba pojedyncza nieokreślona';
$string['def_sg'] = 'Liczba pojedyncza określona';
$string['indef_pl'] = 'Liczba mnoga nieokreślona';
$string['def_pl'] = 'Liczba mnoga określona';
$string['antonyms'] = 'Antonimy';
$string['collocations'] = 'Typowe kolokacje';
$string['examples'] = 'Przykładowe zdania';
$string['cognates'] = 'Wyrazy pokrewne';
$string['sayings'] = 'Typowe powiedzenia';
$string['autofill'] = 'Autouzupełnianie';
$string['fetch_from_api'] = 'Pobierz przez API';
$string['save'] = 'Zapisz';
$string['skip'] = 'Pomiń';
$string['cancel'] = 'Anuluj';
$string['fill_field'] = 'Proszę wypełnić: {$a}';
$string['autofill_soon'] = 'Autouzupełnianie będzie wkrótce dostępne';

// iOS Install Instructions
$string['ios_install_title'] = 'Zainstaluj tę aplikację na ekranie głównym:';
$string['ios_install_step1'] = '1. Dotknij przycisku';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Wybierz';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Udostępnij';
$string['ios_add_to_home'] = 'Dodaj do ekranu głównego';

// Titles / tooltips
$string['title_camera'] = 'Kamera';
$string['title_take'] = 'Zrób zdjęcie';
$string['title_closecam'] = 'Zamknij kamerę';
$string['title_play'] = 'Odtwórz';
$string['title_slow'] = 'Odtwórz wolno';
$string['title_edit'] = 'Edytuj';
$string['title_del'] = 'Usuń';
$string['title_record'] = 'Nagraj';
$string['title_stop'] = 'Zatrzymaj';
$string['title_record_practice'] = 'Nagraj wymowę';
$string['press_hold_to_record'] = 'Naciśnij i przytrzymaj, aby nagrać';
$string['release_when_finished'] = 'Puść, gdy skończysz';
$string['mic_permission_pending'] = 'Poproś o dostęp';
$string['mic_permission_requesting'] = 'Trwa żądanie...';
$string['mic_permission_denied'] = 'Włącz w Safari';

// List table
$string['list_front'] = 'Słowo/fraza fokusowa';
$string['list_deck'] = 'Talia';
$string['list_stage'] = 'Etap';
$string['list_added'] = 'Dodano';
$string['list_due'] = 'Następny przegląd';
$string['list_play'] = 'Odtwórz';
$string['search_ph'] = 'Szukaj...';
$string['cards'] = 'Fiszki';
$string['close'] = 'Zamknij';

// Access control messages
$string['access_denied'] = 'Dostęp zabroniony';
$string['access_expired_title'] = 'Dostęp do fiszek wygasł';
$string['access_expired_message'] = 'Nie masz już dostępu do fiszek. Proszę zapisz się na kurs, aby odzyskać dostęp.';
$string['access_grace_message'] = 'Możesz przeglądać swoje fiszki przez kolejne {$a} dni. Zapisz się na kurs, aby tworzyć nowe fiszki.';
$string['access_create_blocked'] = 'Nie możesz tworzyć nowych fiszek bez aktywnego zapisu na kurs.';
$string['grace_period_restrictions'] = 'Podczas okresu karencji:';
$string['grace_can_review'] = '✓ MOŻESZ przeglądać istniejące fiszki';
$string['grace_cannot_create'] = '✗ NIE MOŻESZ tworzyć nowych fiszek';

// Enhanced access status messages
$string['access_status_active'] = 'Aktywny dostęp';
$string['access_status_active_desc'] = 'Masz pełny dostęp do tworzenia i przeglądania fiszek.';
$string['access_status_grace'] = 'Okres karencji (pozostało {$a} dni)';
$string['access_status_grace_desc'] = 'Możesz przeglądać istniejące fiszki, ale nie możesz tworzyć nowych. Zapisz się na kurs, aby przywrócić pełny dostęp.';
$string['access_status_expired'] = 'Dostęp wygasł';
$string['access_status_expired_desc'] = 'Twój dostęp wygasł. Zapisz się na kurs, aby odzyskać dostęp do fiszek.';
$string['access_enrol_now'] = 'Zapisz się na kurs';
$string['access_days_remaining'] = 'Pozostało {$a} dni';

// Notifications
$string['messageprovider:grace_period_started'] = 'Rozpoczął się okres karencji fiszek';
$string['messageprovider:access_expiring_soon'] = 'Dostęp do fiszek wkrótce wygasa';
$string['messageprovider:access_expired'] = 'Dostęp do fiszek wygasł';

$string['notification_grace_subject'] = 'Fiszki: Rozpoczął się okres karencji';
$string['notification_grace_message'] = 'Nie jesteś już zapisany na kurs fiszek. Możesz przeglądać istniejące fiszki przez {$a} dni. Aby tworzyć nowe fiszki, proszę zapisz się na kurs.';
$string['notification_grace_message_html'] = '<p>Nie jesteś już zapisany na kurs fiszek.</p><p>Możesz <strong>przeglądać istniejące fiszki przez {$a} dni</strong>.</p><p>Aby tworzyć nowe fiszki, proszę zapisz się na kurs.</p>';

$string['notification_expiring_subject'] = 'Fiszki: Dostęp wygasa za 7 dni';
$string['notification_expiring_message'] = 'Twój dostęp do fiszek wygaśnie za 7 dni. Zapisz się na kurs, aby zachować dostęp.';
$string['notification_expiring_message_html'] = '<p><strong>Twój dostęp do fiszek wygaśnie za 7 dni.</strong></p><p>Zapisz się na kurs, aby zachować dostęp do fiszek.</p>';

$string['notification_expired_subject'] = 'Fiszki: Dostęp wygasł';
$string['notification_expired_message'] = 'Twój dostęp do fiszek wygasł. Zapisz się na kurs, aby odzyskać dostęp.';
$string['notification_expired_message_html'] = '<p><strong>Twój dostęp do fiszek wygasł.</strong></p><p>Zapisz się na kurs, aby odzyskać dostęp do fiszek.</p>';

// Global page strings
$string['myflashcards'] = 'Moje fiszki';
$string['myflashcards_welcome'] = 'Witaj w swoich fiszkach!';
$string['access_denied_full'] = 'Nie masz dostępu do przeglądania fiszek. Proszę zapisz się na kurs z aktywnością fiszek.';
$string['browse_courses'] = 'Przeglądaj dostępne kursy';

// Scheduled tasks
$string['task_check_user_access'] = 'Sprawdź dostęp użytkowników do fiszek i okresy karencji';
$string['task_cleanup_orphans'] = 'Wyczyść osierocone rekordy postępu fiszek';

$string['cards_remaining'] = 'fiszek pozostało';
$string['rating_actions'] = 'Akcje oceniania';
$string['progress_label'] = 'Postęp przeglądu';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Utwórz';
$string['tab_study'] = 'Nauka';
$string['tab_dashboard'] = 'Panel';

// Quick Input
$string['quickinput_title'] = 'Dodaj nową fiszkę';
$string['quick_audio'] = 'Nagraj audio';
$string['quick_photo'] = 'Zrób zdjęcie';
$string['show_advanced'] = 'Pokaż zaawansowane ▼';
$string['hide_advanced'] = 'Ukryj zaawansowane ▲';
$string['card_created'] = 'Fiszka utworzona!';
$string['quickinput_created_today'] = '{$a} utworzono dzisiaj';

// Dashboard
$string['dashboard_cards_due'] = 'Fiszki do nauki dzisiaj';
$string['dashboard_total_cards'] = 'Łączna liczba fiszek';
$string['dashboard_active_vocab'] = 'Aktywne słownictwo';
$string['dashboard_streak'] = 'Obecna seria (dni)';
$string['dashboard_study_time'] = 'Czas nauki w tym tygodniu';
$string['dashboard_stage_chart'] = 'Rozkład etapów fiszek';
$string['dashboard_activity_chart'] = 'Aktywność przeglądu (ostatnie 7 dni)';
$string['dashboard_achievements'] = 'Osiągnięcia';

// Achievements
$string['achievement_first_card'] = 'Pierwsza fiszka';
$string['achievement_week_warrior'] = 'Wojownik tygodnia (7-dniowa seria)';
$string['achievement_century'] = 'Stulecie (100 fiszek)';
$string['achievement_study_bug'] = 'Pasjonat nauki (10 godzin)';
$string['achievement_master'] = 'Mistrz (1 fiszka na etapie 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Poziom A0 - Początkujący';
$string['achievement_level_a1'] = 'Poziom A1 - Elementarny';
$string['achievement_level_a2'] = 'Poziom A2 - Podstawowy';
$string['achievement_level_b1'] = 'Poziom B1 - Średniozaawansowany';
$string['achievement_level_b2'] = 'Poziom B2 - Zaawansowany średni';

// Placeholders
$string['collocations_ph'] = 'Jeden na linię...';
$string['examples_ph'] = 'Przykładowe zdania...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Kocham cię';
$string['translation_en_placeholder'] = 'I love you';

// Settings - AI & TTS
$string['settings_ai_section'] = 'Asystent AI';
$string['settings_ai_section_desc'] = 'Skonfiguruj model ChatGPT używany do wykrywania stałych wyrażeń, gdy uczeń kliknie słowo.';
$string['settings_ai_enable'] = 'Włącz asystenta AI fokusa';
$string['settings_ai_enable_desc'] = 'Pozwól uczniom podświetlić słowo w tekście przodu i pozwól AI wykryć pasujące wyrażenie.';
$string['settings_openai_key'] = 'Klucz API OpenAI';
$string['settings_openai_key_desc'] = 'Przechowywany bezpiecznie na serwerze. Wymagany dla asystenta fokusa.';
$string['settings_openai_model'] = 'Model OpenAI';
$string['settings_openai_model_desc'] = 'Na przykład gpt-4o-mini. Asystent używa chat-completions.';
$string['settings_openai_url'] = 'Punkt końcowy OpenAI';
$string['settings_openai_url_desc'] = 'Nadpisz tylko przy użyciu punktu końcowego kompatybilnego z proxy.';

$string['settings_tts_section'] = 'Synteza mowy';
$string['settings_tts_section_desc'] = 'Skonfiguruj dostawców mowy dla pełnych zdań (ElevenLabs) i krótkich fraz fokusowych (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Klucz API ElevenLabs';
$string['settings_elevenlabs_key_desc'] = 'Przechowywany bezpiecznie na serwerze i nigdy nie ujawniany uczniom.';
$string['settings_elevenlabs_voice'] = 'Domyślny ID głosu';
$string['settings_elevenlabs_voice_desc'] = 'Używany, gdy uczeń nie wybierze konkretnego głosu.';
$string['settings_elevenlabs_voice_map'] = 'Opcje głosu';
$string['settings_elevenlabs_voice_map_desc'] = 'Zdefiniuj jeden głos na linię, używając formatu Nazwa=voice-id. Przykład: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID modelu ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'Domyślnie eleven_monolingual_v2. Zaktualizuj tylko jeśli twoje konto używa innego modelu.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Używane dla ultra-krótkich fraz (dwa słowa lub mniej), aby utrzymać niskie opóźnienie.';
$string['settings_polly_key'] = 'ID klucza dostępu AWS';
$string['settings_polly_key_desc'] = 'Wymaga polityki IAM AmazonPollyFullAccess lub równoważnej.';
$string['settings_polly_secret'] = 'Tajny klucz dostępu AWS';
$string['settings_polly_secret_desc'] = 'Przechowywany bezpiecznie na serwerze i nigdy nie ujawniany uczniom.';
$string['settings_polly_region'] = 'Region AWS';
$string['settings_polly_region_desc'] = 'Przykład: eu-west-1. Musi odpowiadać regionowi, w którym Polly jest dostępny.';
$string['settings_polly_voice'] = 'Domyślny głos Polly';
$string['settings_polly_voice_desc'] = 'Nazwa głosu (np. Liv, Ida) używana, gdy nie zdefiniowano nadpisania.';
$string['settings_polly_voice_map'] = 'Nadpisania głosu Polly';
$string['settings_polly_voice_map_desc'] = 'Opcjonalne mapowanie między ID głosów ElevenLabs a nazwami głosów Polly. Użyj formatu elevenVoiceId=PollyVoice na linię.';

$string['settings_orbokene_section'] = 'Słownik Orbøkene';
$string['settings_orbokene_section_desc'] = 'Gdy włączone, asystent AI będzie próbował wzbogacić wykryte wyrażenia danymi z tabeli flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Włącz autouzupełnianie słownika';
$string['settings_orbokene_enable_desc'] = 'Jeśli włączone, pasujące wpisy w pamięci podręcznej Orbøkene wypełnią definicję, tłumaczenie i przykłady.';

// Fill field dialog
$string['fill_field'] = 'Proszę wypełnić: {$a}';

// Errors
$string['ai_http_error'] = 'Usługa AI jest niedostępna. Proszę spróbować później.';
$string['ai_invalid_json'] = 'Nieoczekiwana odpowiedź z usługi AI.';
$string['ai_disabled'] = 'Asystent AI nie jest jeszcze skonfigurowany.';
$string['tts_http_error'] = 'Synteza mowy jest tymczasowo niedostępna.';
$string[''whisper_status_idle''] = 'Speech-to-text ready';
$string[''whisper_status_uploading''] = 'Uploading Private audio...';
$string[''whisper_status_transcribing''] = 'Transcribing...';
$string[''whisper_status_success''] = 'Transcription inserted';
$string[''whisper_status_error''] = 'Could not transcribe audio';
$string[''whisper_status_limit''] = 'Clip is too long';
$string[''whisper_status_quota''] = 'Monthly speech limit reached';
$string[''whisper_status_retry''] = 'Retry';
$string[''whisper_status_undo''] = 'Undo replace';
$string[''whisper_status_disabled''] = 'Speech-to-text unavailable';
$string[''settings_whisper_section''] = 'Whisper speech-to-text';
$string[''settings_whisper_section_desc''] = 'Configure OpenAI Whisper to turn learner recordings into Front text automatically.';
$string[''settings_whisper_enable''] = 'Enable Whisper transcription';
$string[''settings_whisper_enable_desc''] = 'Allow the Record Audio button to call Whisper via the Moodle server.';
$string[''settings_whisper_key''] = 'OpenAI API key for Whisper';
$string[''settings_whisper_key_desc''] = 'Stored securely on the server. Never exposed to learners.';
$string[''settings_whisper_model''] = 'Whisper model';
$string[''settings_whisper_model_desc''] = 'Default whisper-1. Update if OpenAI releases a newer STT model.';
$string[''settings_whisper_language''] = 'Recognition language';
$string[''settings_whisper_language_desc''] = 'Two-letter code passed to Whisper (default nb for Norsk bokmal).';
$string[''settings_whisper_clip_limit''] = 'Clip length limit (seconds)';
$string[''settings_whisper_clip_limit_desc''] = 'Clips longer than this value are rejected before calling Whisper.';
$string[''settings_whisper_monthly_limit''] = 'Monthly quota per user (seconds)';
$string[''settings_whisper_monthly_limit_desc''] = 'Protects your API budget. 10 hours ~ 36000 seconds.';
$string[''settings_whisper_timeout''] = 'API timeout (seconds)';
$string[''settings_whisper_timeout_desc''] = 'Abort stalled Whisper requests after this many seconds.';
$string[''error_whisper_disabled''] = 'Speech-to-text is not available right now.';
$string[''error_whisper_clip''] = 'Private audio is longer than {$a} seconds.';
$string[''error_whisper_quota''] = 'You reached your monthly speech limit ({$a}).';
$string[''error_whisper_upload''] = 'Could not process the uploaded audio file.';
$string[''error_whisper_api''] = 'Speech-to-text service failed: {$a}';
$string[''error_whisper_filesize''] = 'Audio file is too large (max {$a}).';
