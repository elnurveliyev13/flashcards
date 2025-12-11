<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Schede';
$string['modulenameplural'] = 'Schede';
$string['modulename_help'] = 'Attività di schede con ripetizione spaziata.';
$string['pluginname'] = 'Schede';
$string['pluginadministration'] = 'Amministrazione schede';
$string['flashcardsname'] = 'Nome dell\'attività';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Intervalli: 1,3,7,15,31,62,125,251';
$string['export'] = 'Esporta';
$string['import'] = 'Importa';
$string['reset'] = 'Reimposta progresso';
$string['profile'] = 'Profilo:';
$string['activate'] = 'Attiva lezione';
$string['choose'] = 'Scegli lezione';
$string['loadpack'] = 'Carica mazzo';
$string['due'] = 'Da ripassare: {$a}';
$string['list'] = 'Elenco schede';
$string['addown'] = 'Aggiungi la tua scheda';
$string['front'] = 'Testo';
$string['front_translation_mode_label'] = 'Direzione di traduzione';
$string['front_translation_mode_hint'] = 'Tocca per cambiare le lingue di input/output.';
$string['front_translation_status_idle'] = 'Traduzione pronta';
$string['front_translation_status_loading'] = 'Traduzione in corso...';
$string['front_translation_status_error'] = 'Errore di traduzione';
$string['front_translation_reverse_hint'] = 'Digita nella tua lingua per tradurlo automaticamente in norvegese.';
$string['front_translation_copy'] = 'Copia traduzione';
$string['focus_translation_label'] = 'Significato focale';
$string['fokus'] = 'Parola/frase focale';
$string['focus_baseform'] = 'Forma base';
$string['focus_baseform_ph'] = 'Lemma o infinito (opzionale)';
$string['ai_helper_label'] = 'Assistente AI focale';
$string['ai_click_hint'] = 'Tocca qualsiasi parola sopra per rilevare un\'espressione fissa';
$string['front_suggest_collapse'] = 'Nascondi suggerimenti';
$string['ai_helper_disabled'] = 'Assistente AI disabilitato dall\'amministratore';
$string['ai_detecting'] = 'Rilevamento espressione...';
$string['ai_helper_success'] = 'Frase focale aggiunta';
$string['ai_helper_error'] = 'Impossibile rilevare un\'espressione';
$string['ai_no_text'] = 'Digita una frase per abilitare l\'assistente';
$string['choose_focus_word'] = 'Scegli la parola o la frase focale';
$string['ai_question_label'] = 'Chiedi all\'AI';
$string['ai_question_placeholder'] = 'Digita una domanda su questa frase...';
$string['ai_question_button'] = 'Chiedi';
$string['ai_chat_empty'] = 'Fai una domanda all\'AI sul tuo testo o parola/frase focale';
$string['ai_chat_user'] = 'Tu';
$string['ai_chat_assistant'] = 'AI';
$string['ai_chat_error'] = 'L\'AI non ha potuto rispondere a questa domanda.';
$string['ai_chat_loading'] = 'Pensando...';
$string['check_text'] = 'Verifica testo';
$string['no_errors_found'] = 'Nessun errore trovato!';
$string['apply_corrections'] = 'Applica correzioni';
$string['keep_as_is'] = 'Lascia com\'è';
$string['error_checking_failed'] = 'La verifica è fallita';
$string['naturalness_suggestion'] = 'Alternativa più naturale:';
$string['ask_ai_about_correction'] = 'Chiedi all\'AI';
$string['ai_sure'] = 'Sei sicuro?';
$string['ai_explain_more'] = 'Spiega in dettaglio';
$string['ai_more_examples'] = 'Dai più esempi';
$string['ai_thinking'] = 'Pensando...';
$string['focus_audio_badge'] = 'Audio focale';
$string['front_audio_badge'] = 'Audio fronte';
$string['explanation'] = 'Spiegazione';
$string['back'] = 'Traduzione';
$string['back_en'] = 'Traduzione';
$string['image'] = 'Immagine';
$string['audio'] = 'Audio';
$string['order_audio_word'] = 'Audio focale';
$string['order_audio_text'] = 'Audio';
$string['tts_voice'] = 'Voce';
$string['tts_voice_hint'] = 'Seleziona una voce prima di chiedere all\'assistente AI di generare l\'audio.';
$string['tts_voice_placeholder'] = 'Voce predefinita';
$string['tts_voice_missing'] = 'Aggiungi voci di sintesi vocale nelle impostazioni del plugin.';
$string['tts_voice_disabled'] = 'Fornisci chiavi ElevenLabs o Amazon Polly per abilitare la generazione audio.';
$string['tts_status_success'] = 'Audio pronto.';
$string['tts_status_error'] = 'Errore di generazione audio.';
$string['mediareport_title'] = 'File audio delle schede';
$string['mediareport_filter_search'] = 'Cerca testo o ID scheda';
$string['mediareport_filter_search_ph'] = 'es. infinito, traduzione, ID scheda';
$string['mediareport_filter_user'] = 'ID utente proprietario';
$string['mediareport_filter_user_ph'] = 'Lascia vuoto per tutti gli utenti';
$string['mediareport_filter_perpage'] = 'Righe per pagina';
$string['mediareport_empty'] = 'Nessuna scheda con audio corrisponde ai tuoi filtri.';
$string['mediareport_card'] = 'Scheda';
$string['mediareport_owner'] = 'Proprietario';
$string['mediareport_audio'] = 'File audio';
$string['mediareport_updated'] = 'Aggiornato';
$string['mediareport_audio_sentence'] = 'Audio frase';
$string['mediareport_audio_front'] = 'Audio fronte';
$string['mediareport_audio_focus'] = 'Audio focale';
$string['mediareport_noaudio'] = 'Nessun audio salvato per questa scheda.';
$string['mediareport_cardid'] = 'ID scheda: {$a}';
$string['mediareport_deck'] = 'Mazzo: {$a}';
$string['choosefile'] = 'Scegli file';
$string['chooseaudiofile'] = 'Scegli file audio';
$string['showmore'] = 'Mostra altro';
$string['autosave'] = 'Progresso salvato';
$string['easy'] = 'Facile';
$string['normal'] = 'Normale';
$string['hard'] = 'Difficile';
$string['btnHardHint'] = 'Ripeti questa scheda oggi';
$string['btnNormalHint'] = 'Prossima revisione domani';
$string['btnEasyHint'] = 'Passa alla fase successiva';
$string['update'] = 'Aggiorna';
$string['update_disabled_hint'] = 'Apri prima una scheda esistente per abilitare Aggiorna.';
$string['createnew'] = 'Crea';
$string['order'] = 'Ordine (clicca in sequenza)';
$string['empty'] = 'Niente da fare oggi';
$string['resetform'] = 'Pulisci';
$string['addtomycards'] = 'Aggiungi alle mie schede';
$string['install_app'] = 'Installa app';

// Linguistic enrichment fields
$string['transcription'] = 'Trascrizione';
$string['pos'] = 'Parte del discorso';
$string['pos_noun'] = 'Nome';
$string['pos_verb'] = 'Verbo';
$string['pos_adj'] = 'Aggettivo';
$string['pos_adv'] = 'Avverbio';
$string['pos_other'] = 'Altro';
$string['gender'] = 'Genere';
$string['gender_neuter'] = 'Neutro (intetkjonn)';
$string['gender_masculine'] = 'Maschile (hankjonn)';
$string['gender_feminine'] = 'Femminile (hunkjonn)';
$string['noun_forms'] = 'Forme del nome';
$string['verb_forms'] = 'Forme del verbo';
$string['adj_forms'] = 'Forme dell\'aggettivo';
$string['indef_sg'] = 'Singolare indefinito';
$string['def_sg'] = 'Singolare definito';
$string['indef_pl'] = 'Plurale indefinito';
$string['def_pl'] = 'Plurale definito';
$string['antonyms'] = 'Antonimi';
$string['collocations'] = 'Collocazioni comuni';
$string['examples'] = 'Frasi di esempio';
$string['cognates'] = 'Cognati';
$string['sayings'] = 'Modi di dire comuni';
$string['autofill'] = 'Compilazione automatica';
$string['fetch_from_api'] = 'Recupera tramite API';
$string['save'] = 'Salva';
$string['skip'] = 'Salta';
$string['cancel'] = 'Annulla';
$string['fill_field'] = 'Si prega di compilare: {$a}';
$string['autofill_soon'] = 'La compilazione automatica sarà presto disponibile';

// iOS Install Instructions
$string['ios_install_title'] = 'Installa questa app sulla tua schermata Home:';
$string['ios_install_step1'] = '1. Tocca il pulsante';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Seleziona';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Condividi';
$string['ios_add_to_home'] = 'Aggiungi a Home';

// Titles / tooltips
$string['title_camera'] = 'Fotocamera';
$string['title_take'] = 'Scatta foto';
$string['title_closecam'] = 'Chiudi fotocamera';
$string['title_play'] = 'Riproduci';
$string['title_slow'] = 'Riproduci lentamente';
$string['title_edit'] = 'Modifica';
$string['title_del'] = 'Elimina';
$string['title_record'] = 'Registra';
$string['title_stop'] = 'Ferma';
$string['title_record_practice'] = 'Registra pronuncia';
$string['press_hold_to_record'] = 'Premi e tieni premuto per registrare';
$string['release_when_finished'] = 'Rilascia quando hai finito';
$string['mic_permission_pending'] = 'Richiedi accesso';
$string['mic_permission_requesting'] = 'Richiesta...';
$string['mic_permission_denied'] = 'Abilita in Safari';

// List table
$string['list_front'] = 'Parola/frase focale';
$string['list_deck'] = 'Mazzo';
$string['list_stage'] = 'Fase';
$string['list_added'] = 'Aggiunto';
$string['list_due'] = 'Prossimo ripasso';
$string['list_play'] = 'Riproduci';
$string['search_ph'] = 'Cerca...';
$string['cards'] = 'Schede';
$string['close'] = 'Chiudi';

// Access control messages
$string['access_denied'] = 'Accesso negato';
$string['access_expired_title'] = 'L\'accesso alle schede è scaduto';
$string['access_expired_message'] = 'Non hai più accesso alle schede. Si prega di iscriversi a un corso per ripristinare l\'accesso.';
$string['access_grace_message'] = 'Puoi rivedere le tue schede per altri {$a} giorni. Iscriviti a un corso per creare nuove schede.';
$string['access_create_blocked'] = 'Non puoi creare nuove schede senza un\'iscrizione attiva a un corso.';
$string['grace_period_restrictions'] = 'Durante il periodo di grazia:';
$string['grace_can_review'] = '✓ PUOI rivedere le schede esistenti';
$string['grace_cannot_create'] = '✗ NON PUOI creare nuove schede';

// Enhanced access status messages
$string['access_status_active'] = 'Accesso attivo';
$string['access_status_active_desc'] = 'Hai accesso completo per creare e rivedere schede.';
$string['access_status_grace'] = 'Periodo di grazia ({$a} giorni rimanenti)';
$string['access_status_grace_desc'] = 'Puoi rivedere le tue schede esistenti ma non puoi crearne di nuove. Iscriviti a un corso per ripristinare l\'accesso completo.';
$string['access_status_expired'] = 'Accesso scaduto';
$string['access_status_expired_desc'] = 'Il tuo accesso è scaduto. Iscriviti a un corso per ripristinare l\'accesso alle schede.';
$string['access_enrol_now'] = 'Iscriviti a un corso';
$string['access_days_remaining'] = '{$a} giorni rimanenti';

// Notifications
$string['messageprovider:grace_period_started'] = 'Periodo di grazia schede iniziato';
$string['messageprovider:access_expiring_soon'] = 'Accesso alle schede in scadenza';
$string['messageprovider:access_expired'] = 'Accesso alle schede scaduto';

$string['notification_grace_subject'] = 'Schede: Periodo di grazia iniziato';
$string['notification_grace_message'] = 'Non sei più iscritto a un corso di schede. Puoi rivedere le tue schede esistenti per {$a} giorni. Per creare nuove schede, si prega di iscriversi a un corso.';
$string['notification_grace_message_html'] = '<p>Non sei più iscritto a un corso di schede.</p><p>Puoi <strong>rivedere le tue schede esistenti per {$a} giorni</strong>.</p><p>Per creare nuove schede, si prega di iscriversi a un corso.</p>';

$string['notification_expiring_subject'] = 'Schede: Accesso in scadenza tra 7 giorni';
$string['notification_expiring_message'] = 'Il tuo accesso alle schede scadrà tra 7 giorni. Iscriviti a un corso per mantenere l\'accesso.';
$string['notification_expiring_message_html'] = '<p><strong>Il tuo accesso alle schede scadrà tra 7 giorni.</strong></p><p>Iscriviti a un corso per mantenere l\'accesso alle tue schede.</p>';

$string['notification_expired_subject'] = 'Schede: Accesso scaduto';
$string['notification_expired_message'] = 'Il tuo accesso alle schede è scaduto. Iscriviti a un corso per ripristinare l\'accesso.';
$string['notification_expired_message_html'] = '<p><strong>Il tuo accesso alle schede è scaduto.</strong></p><p>Iscriviti a un corso per ripristinare l\'accesso alle schede.</p>';

// Global page strings
$string['myflashcards'] = 'Le mie schede';
$string['myflashcards_welcome'] = 'Benvenuto alle tue schede!';
$string['access_denied_full'] = 'Non hai accesso per visualizzare le schede. Si prega di iscriversi a un corso con attività di schede.';
$string['browse_courses'] = 'Sfoglia corsi disponibili';

// Scheduled tasks
$string['task_check_user_access'] = 'Controlla accesso utenti alle schede e periodi di grazia';
$string['task_cleanup_orphans'] = 'Pulisci record di progresso schede orfani';

$string['cards_remaining'] = 'schede rimanenti';
$string['rating_actions'] = 'Azioni di valutazione';
$string['progress_label'] = 'Progresso ripasso';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Crea';
$string['tab_study'] = 'Studiare';
$string['tab_dashboard'] = 'Pannello';

// Quick Input
$string['quickinput_title'] = 'Aggiungi nuova scheda';
$string['quick_audio'] = 'Registra audio';
$string['quick_photo'] = 'Scatta foto';
$string['show_advanced'] = 'Mostra avanzate ▼';
$string['hide_advanced'] = 'Nascondi avanzate ▲';
$string['card_created'] = 'Scheda creata!';
$string['quickinput_created_today'] = '{$a} create oggi';

// Dashboard
$string['dashboard_cards_due'] = 'Schede da ripassare oggi';
$string['dashboard_total_cards'] = 'Totale schede';
$string['dashboard_active_vocab'] = 'Vocabolario attivo';
$string['dashboard_streak'] = 'Serie attuale (giorni)';
$string['dashboard_study_time'] = 'Tempo di studio questa settimana';
$string['dashboard_stage_chart'] = 'Distribuzione fasi schede';
$string['dashboard_activity_chart'] = 'Attività di ripasso (ultimi 7 giorni)';
$string['dashboard_achievements'] = 'Traguardi';

// Achievements
$string['achievement_first_card'] = 'Prima scheda';
$string['achievement_week_warrior'] = 'Guerriero della settimana (serie di 7 giorni)';
$string['achievement_century'] = 'Secolo (100 schede)';
$string['achievement_study_bug'] = 'Appassionato di studio (10 ore)';
$string['achievement_master'] = 'Maestro (1 scheda alla fase 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Livello A0 - Principiante';
$string['achievement_level_a1'] = 'Livello A1 - Elementare';
$string['achievement_level_a2'] = 'Livello A2 - Pre-intermedio';
$string['achievement_level_b1'] = 'Livello B1 - Intermedio';
$string['achievement_level_b2'] = 'Livello B2 - Intermedio superiore';

// Placeholders
$string['collocations_ph'] = 'Una per riga...';
$string['examples_ph'] = 'Frasi di esempio...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Ti amo';
$string['translation_en_placeholder'] = 'I love you';

// Settings - AI & TTS
$string['settings_ai_section'] = 'Assistente AI';
$string['settings_ai_section_desc'] = 'Configura il modello ChatGPT utilizzato per rilevare espressioni fisse quando uno studente clicca una parola.';
$string['settings_ai_enable'] = 'Abilita assistente AI focale';
$string['settings_ai_enable_desc'] = 'Permetti agli studenti di evidenziare una parola nel testo del fronte e lascia che l\'AI rilevi l\'espressione corrispondente.';
$string['settings_openai_key'] = 'Chiave API OpenAI';
$string['settings_openai_key_desc'] = 'Memorizzata in modo sicuro sul server. Richiesta per l\'assistente focale.';
$string['settings_openai_model'] = 'Modello OpenAI';
$string['settings_openai_model_desc'] = 'Ad esempio gpt-4o-mini. L\'assistente utilizza chat-completions.';
$string['settings_openai_url'] = 'Endpoint OpenAI';
$string['settings_openai_url_desc'] = 'Sostituisci solo quando si utilizza un endpoint compatibile con proxy.';

$string['settings_tts_section'] = 'Sintesi vocale';
$string['settings_tts_section_desc'] = 'Configura fornitori di sintesi vocale per frasi complete (ElevenLabs) e brevi frasi focali (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Chiave API ElevenLabs';
$string['settings_elevenlabs_key_desc'] = 'Memorizzata in modo sicuro sul server e mai esposta agli studenti.';
$string['settings_elevenlabs_voice'] = 'ID voce predefinito';
$string['settings_elevenlabs_voice_desc'] = 'Utilizzato quando lo studente non seleziona una voce specifica.';
$string['settings_elevenlabs_voice_map'] = 'Opzioni voce';
$string['settings_elevenlabs_voice_map_desc'] = 'Definisci una voce per riga utilizzando il formato Nome=voice-id. Esempio: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID modello ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'Predefinito eleven_monolingual_v2. Aggiorna solo se il tuo account utilizza un modello diverso.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Utilizzato per frasi ultra-brevi (due parole o meno) per mantenere bassa la latenza.';
$string['settings_polly_key'] = 'ID chiave di accesso AWS';
$string['settings_polly_key_desc'] = 'Richiede la policy IAM AmazonPollyFullAccess o equivalente.';
$string['settings_polly_secret'] = 'Chiave di accesso segreta AWS';
$string['settings_polly_secret_desc'] = 'Memorizzata in modo sicuro sul server e mai esposta agli studenti.';
$string['settings_polly_region'] = 'Regione AWS';
$string['settings_polly_region_desc'] = 'Esempio: eu-west-1. Deve corrispondere alla regione in cui Polly è disponibile.';
$string['settings_polly_voice'] = 'Voce Polly predefinita';
$string['settings_polly_voice_desc'] = 'Nome della voce (es. Liv, Ida) utilizzato quando non è definita una sostituzione.';
$string['settings_polly_voice_map'] = 'Sostituzioni voce Polly';
$string['settings_polly_voice_map_desc'] = 'Mappatura opzionale tra ID voce ElevenLabs e nomi voce Polly. Usa il formato elevenVoiceId=PollyVoice per riga.';

$string['settings_orbokene_section'] = 'Dizionario Orbøkene';
$string['settings_orbokene_section_desc'] = 'Quando abilitato, l\'assistente AI cercherà di arricchire le espressioni rilevate con dati dalla tabella flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Abilita compilazione automatica dizionario';
$string['settings_orbokene_enable_desc'] = 'Se abilitato, le voci corrispondenti nella cache Orbøkene popolano definizione, traduzione ed esempi.';

// Fill field dialog
$string['fill_field'] = 'Si prega di compilare: {$a}';

// Errors
$string['ai_http_error'] = 'Il servizio AI non è disponibile. Si prega di riprovare più tardi.';
$string['ai_invalid_json'] = 'Risposta imprevista dal servizio AI.';
$string['ai_disabled'] = 'L\'assistente AI non è ancora configurato.';
$string['tts_http_error'] = 'La sintesi vocale è temporaneamente non disponibile.';
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
