<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Cartes mémoire';
$string['modulenameplural'] = 'Cartes mémoire';
$string['modulename_help'] = 'Activité de cartes mémoire à répétition espacée.';
$string['pluginname'] = 'Cartes mémoire';
$string['pluginadministration'] = 'Administration des cartes mémoire';
$string['flashcardsname'] = 'Nom de l\'activité';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Intervalles : 1,3,7,15,31,62,125,251';
$string['export'] = 'Exporter';
$string['import'] = 'Importer';
$string['reset'] = 'Réinitialiser la progression';
$string['profile'] = 'Profil :';
$string['activate'] = 'Activer la leçon';
$string['choose'] = 'Choisir la leçon';
$string['loadpack'] = 'Charger le paquet';
$string['due'] = 'À réviser : {$a}';
$string['list'] = 'Liste des cartes';
$string['addown'] = 'Ajouter votre carte';
$string['front'] = 'Texte';
$string['front_translation_mode_label'] = 'Direction de traduction';
$string['front_translation_mode_hint'] = 'Appuyez pour changer les langues d\'entrée/sortie.';
$string['front_translation_status_idle'] = 'Traduction prête';
$string['front_translation_status_loading'] = 'Traduction en cours...';
$string['front_translation_status_error'] = 'Échec de la traduction';
$string['front_translation_reverse_hint'] = 'Tapez dans votre langue pour le traduire automatiquement en norvégien.';
$string['front_translation_copy'] = 'Copier la traduction';
$string['focus_translation_label'] = 'Sens focal';
$string['fokus'] = 'Mot/phrase focal';
$string['focus_baseform'] = 'Forme de base';
$string['focus_baseform_ph'] = 'Lemme ou infinitif (facultatif)';
$string['ai_helper_label'] = 'Assistant IA focal';
$string['ai_click_hint'] = 'Appuyez sur n\'importe quel mot ci-dessus pour détecter une expression figée';
$string['front_suggest_collapse'] = 'Masquer les suggestions';
$string['ai_helper_disabled'] = 'Assistant IA désactivé par l\'administrateur';
$string['ai_detecting'] = 'Détection de l\'expression...';
$string['ai_helper_success'] = 'Phrase focale ajoutée';
$string['ai_helper_error'] = 'Impossible de détecter une expression';
$string['ai_no_text'] = 'Tapez une phrase pour activer l\'assistant';
$string['choose_focus_word'] = 'Choisissez le mot focal';
$string['ai_question_label'] = 'Demander à l\'IA';
$string['ai_question_placeholder'] = 'Tapez une question sur cette phrase...';
$string['ai_question_button'] = 'Demander';
$string['ai_chat_empty'] = 'Posez une question à l\'IA concernant votre texte ou mot/phrase focal';
$string['ai_chat_user'] = 'Vous';
$string['ai_chat_assistant'] = 'IA';
$string['ai_chat_error'] = 'L\'IA n\'a pas pu répondre à cette question.';
$string['ai_chat_loading'] = 'Réflexion...';
$string['check_text'] = 'Vérifier le texte';
$string['no_errors_found'] = 'Aucune erreur trouvée !';
$string['apply_corrections'] = 'Appliquer les corrections';
$string['keep_as_is'] = 'Garder tel quel';
$string['error_checking_failed'] = 'La vérification a échoué';
$string['naturalness_suggestion'] = 'Alternative plus naturelle :';
$string['ask_ai_about_correction'] = 'Demander à l\'IA';
$string['ai_sure'] = 'Tu es sûr ?';
$string['ai_explain_more'] = 'Explique en détail';
$string['ai_more_examples'] = 'Donne plus d\'exemples';
$string['ai_explain_simpler'] = 'Explique plus simplement';
$string['ai_thinking'] = 'Réflexion...';
$string['focus_audio_badge'] = 'Audio focal';
$string['front_audio_badge'] = 'Audio du recto';
$string['explanation'] = 'Explication';
$string['back'] = 'Traduction';
$string['back_en'] = 'Traduction';
$string['image'] = 'Image';
$string['audio'] = 'Audio';
$string['order_audio_word'] = 'Audio focal';
$string['order_audio_text'] = 'Audio';
$string['tts_voice'] = 'Voix';
$string['tts_voice_hint'] = 'Sélectionnez une voix avant de demander à l\'assistant IA de générer l\'audio.';
$string['tts_voice_placeholder'] = 'Voix par défaut';
$string['tts_voice_missing'] = 'Ajoutez des voix de synthèse vocale dans les paramètres du plugin.';
$string['tts_voice_disabled'] = 'Fournissez des clés ElevenLabs ou Amazon Polly pour activer la génération audio.';
$string['tts_status_success'] = 'Audio prêt.';
$string['tts_status_error'] = 'Échec de la génération audio.';
$string['mediareport_title'] = 'Fichiers audio des cartes mémoire';
$string['mediareport_filter_search'] = 'Rechercher du texte ou l\'ID de carte';
$string['mediareport_filter_search_ph'] = 'par ex. infinitif, traduction, ID de carte';
$string['mediareport_filter_user'] = 'ID de l\'utilisateur propriétaire';
$string['mediareport_filter_user_ph'] = 'Laisser vide pour tous les utilisateurs';
$string['mediareport_filter_perpage'] = 'Lignes par page';
$string['mediareport_empty'] = 'Aucune carte avec audio ne correspond à vos filtres.';
$string['mediareport_card'] = 'Carte';
$string['mediareport_owner'] = 'Propriétaire';
$string['mediareport_audio'] = 'Fichiers audio';
$string['mediareport_updated'] = 'Mis à jour';
$string['mediareport_audio_sentence'] = 'Audio de phrase';
$string['mediareport_audio_front'] = 'Audio du recto';
$string['mediareport_audio_focus'] = 'Audio focal';
$string['mediareport_noaudio'] = 'Aucun audio enregistré pour cette carte.';
$string['mediareport_cardid'] = 'ID de carte : {$a}';
$string['mediareport_deck'] = 'Paquet : {$a}';
$string['choosefile'] = 'Choisir un fichier';
$string['chooseaudiofile'] = 'Choisir un fichier audio';
$string['showmore'] = 'Afficher plus';
$string['autosave'] = 'Progression enregistrée';
$string['easy'] = 'Facile';
$string['normal'] = 'Normal';
$string['hard'] = 'Difficile';
$string['btnHardHint'] = 'Répéter cette carte aujourd\'hui';
$string['btnNormalHint'] = 'Prochaine révision demain';
$string['btnEasyHint'] = 'Passer à l\'étape suivante';
$string['update'] = 'Mettre à jour';
$string['update_disabled_hint'] = 'Ouvrez d\'abord une carte existante pour activer Mettre à jour.';
$string['createnew'] = 'Créer';
$string['order'] = 'Ordre (cliquer en séquence)';
$string['empty'] = 'Rien prévu aujourd\'hui';
$string['resetform'] = 'Réinitialiser le formulaire';
$string['addtomycards'] = 'Ajouter à mes cartes';
$string['install_app'] = 'Installer l\'application';

// Linguistic enrichment fields
$string['transcription'] = 'Transcription';
$string['pos'] = 'Partie du discours';
$string['pos_noun'] = 'Nom';
$string['pos_verb'] = 'Verbe';
$string['pos_adj'] = 'Adjectif';
$string['pos_adv'] = 'Adverbe';
$string['pos_other'] = 'Autre';
$string['gender'] = 'Genre';
$string['gender_neuter'] = 'Neutre (intetkjonn)';
$string['gender_masculine'] = 'Masculin (hankjonn)';
$string['gender_feminine'] = 'Féminin (hunkjonn)';
$string['noun_forms'] = 'Formes du nom';
$string['verb_forms'] = 'Formes du verbe';
$string['adj_forms'] = 'Formes de l\'adjectif';
$string['indef_sg'] = 'Singulier indéfini';
$string['def_sg'] = 'Singulier défini';
$string['indef_pl'] = 'Pluriel indéfini';
$string['def_pl'] = 'Pluriel défini';
$string['antonyms'] = 'Antonymes';
$string['collocations'] = 'Collocations courantes';
$string['examples'] = 'Exemples de phrases';
$string['cognates'] = 'Mots apparentés';
$string['sayings'] = 'Expressions courantes';
$string['autofill'] = 'Remplissage automatique';
$string['fetch_from_api'] = 'Récupérer via API';
$string['save'] = 'Enregistrer';
$string['skip'] = 'Passer';
$string['cancel'] = 'Annuler';
$string['fill_field'] = 'Veuillez remplir : {$a}';
$string['autofill_soon'] = 'Le remplissage automatique sera bientôt disponible';

// iOS Install Instructions
$string['ios_install_title'] = 'Installez cette application sur votre écran d\'accueil :';
$string['ios_install_step1'] = '1. Appuyez sur le bouton';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Sélectionnez';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Partager';
$string['ios_add_to_home'] = 'Sur l\'écran d\'accueil';

// Titles / tooltips
$string['title_camera'] = 'Caméra';
$string['title_take'] = 'Prendre une photo';
$string['title_closecam'] = 'Fermer la caméra';
$string['title_play'] = 'Lire';
$string['title_slow'] = 'Lire lentement';
$string['title_edit'] = 'Modifier';
$string['title_del'] = 'Supprimer';
$string['title_record'] = 'Enregistrer';
$string['title_stop'] = 'Arrêter';
$string['title_record_practice'] = 'Enregistrer la prononciation';
$string['press_hold_to_record'] = 'Appuyez et maintenez pour enregistrer';
$string['release_when_finished'] = 'Relâchez quand vous avez terminé';
$string['mic_permission_pending'] = 'Demander l’accès';
$string['mic_permission_requesting'] = 'Demande...';
$string['mic_permission_denied'] = 'Activer dans Safari';

// List table
$string['list_front'] = 'Mot/phrase focal';
$string['list_deck'] = 'Paquet';
$string['list_stage'] = 'Étape';
$string['list_added'] = 'Ajouté';
$string['list_due'] = 'Prochaine révision';
$string['list_play'] = 'Lire';
$string['search_ph'] = 'Rechercher...';
$string['cards'] = 'Cartes';
$string['close'] = 'Fermer';

// Access control messages
$string['access_denied'] = 'Accès refusé';
$string['access_expired_title'] = 'L\'accès aux cartes mémoire a expiré';
$string['access_expired_message'] = 'Vous n\'avez plus accès aux cartes mémoire. Veuillez vous inscrire à un cours pour retrouver l\'accès.';
$string['access_grace_message'] = 'Vous pouvez consulter vos cartes pendant encore {$a} jours. Inscrivez-vous à un cours pour créer de nouvelles cartes.';
$string['access_create_blocked'] = 'Vous ne pouvez pas créer de nouvelles cartes sans inscription active à un cours.';
$string['grace_period_restrictions'] = 'Pendant la période de grâce :';
$string['grace_can_review'] = '✓ Vous POUVEZ consulter les cartes existantes';
$string['grace_cannot_create'] = '✗ Vous NE POUVEZ PAS créer de nouvelles cartes';

// Enhanced access status messages
$string['access_status_active'] = 'Accès actif';
$string['access_status_active_desc'] = 'Vous avez un accès complet pour créer et consulter des cartes mémoire.';
$string['access_status_grace'] = 'Période de grâce ({$a} jours restants)';
$string['access_status_grace_desc'] = 'Vous pouvez consulter vos cartes existantes mais ne pouvez pas en créer de nouvelles. Inscrivez-vous à un cours pour restaurer l\'accès complet.';
$string['access_status_expired'] = 'Accès expiré';
$string['access_status_expired_desc'] = 'Votre accès a expiré. Inscrivez-vous à un cours pour retrouver l\'accès aux cartes mémoire.';
$string['access_enrol_now'] = 'S\'inscrire à un cours';
$string['access_days_remaining'] = '{$a} jours restants';

// Notifications
$string['messageprovider:grace_period_started'] = 'Période de grâce des cartes mémoire commencée';
$string['messageprovider:access_expiring_soon'] = 'Accès aux cartes mémoire expirant bientôt';
$string['messageprovider:access_expired'] = 'Accès aux cartes mémoire expiré';

$string['notification_grace_subject'] = 'Cartes mémoire : Période de grâce commencée';
$string['notification_grace_message'] = 'Vous n\'êtes plus inscrit à un cours de cartes mémoire. Vous pouvez consulter vos cartes existantes pendant {$a} jours. Pour créer de nouvelles cartes, veuillez vous inscrire à un cours.';
$string['notification_grace_message_html'] = '<p>Vous n\'êtes plus inscrit à un cours de cartes mémoire.</p><p>Vous pouvez <strong>consulter vos cartes existantes pendant {$a} jours</strong>.</p><p>Pour créer de nouvelles cartes, veuillez vous inscrire à un cours.</p>';

$string['notification_expiring_subject'] = 'Cartes mémoire : Accès expirant dans 7 jours';
$string['notification_expiring_message'] = 'Votre accès aux cartes mémoire expirera dans 7 jours. Inscrivez-vous à un cours pour conserver l\'accès.';
$string['notification_expiring_message_html'] = '<p><strong>Votre accès aux cartes mémoire expirera dans 7 jours.</strong></p><p>Inscrivez-vous à un cours pour conserver l\'accès à vos cartes.</p>';

$string['notification_expired_subject'] = 'Cartes mémoire : Accès expiré';
$string['notification_expired_message'] = 'Votre accès aux cartes mémoire a expiré. Inscrivez-vous à un cours pour retrouver l\'accès.';
$string['notification_expired_message_html'] = '<p><strong>Votre accès aux cartes mémoire a expiré.</strong></p><p>Inscrivez-vous à un cours pour retrouver l\'accès à vos cartes.</p>';

// Global page strings
$string['myflashcards'] = 'Mes cartes mémoire';
$string['myflashcards_welcome'] = 'Bienvenue à vos cartes mémoire !';
$string['access_denied_full'] = 'Vous n\'avez pas l\'accès pour consulter les cartes mémoire. Veuillez vous inscrire à un cours avec une activité de cartes mémoire.';
$string['browse_courses'] = 'Parcourir les cours disponibles';

// Scheduled tasks
$string['task_check_user_access'] = 'Vérifier l\'accès des utilisateurs aux cartes mémoire et les périodes de grâce';
$string['task_cleanup_orphans'] = 'Nettoyer les enregistrements de progression orphelins des cartes mémoire';

$string['cards_remaining'] = 'cartes restantes';
$string['rating_actions'] = 'Actions d\'évaluation';
$string['progress_label'] = 'Progression de révision';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Créer';
$string['tab_study'] = 'Étudier';
$string['tab_dashboard'] = 'Tableau';

// Quick Input
$string['quickinput_title'] = 'Ajouter une nouvelle carte';
$string['quick_audio'] = 'Enregistrer l\'audio';
$string['quick_photo'] = 'Prendre une photo';
$string['show_advanced'] = 'Afficher avancé ▼';
$string['hide_advanced'] = 'Masquer avancé ▲';
$string['card_created'] = 'Carte créée !';
$string['quickinput_created_today'] = '{$a} créé(es) aujourd\'hui';

// Dashboard
$string['dashboard_cards_due'] = 'Cartes à réviser aujourd\'hui';
$string['dashboard_total_cards'] = 'Total de cartes';
$string['dashboard_active_vocab'] = 'Vocabulaire actif';
$string['dashboard_streak'] = 'Série actuelle (jours)';
$string['dashboard_study_time'] = 'Temps d\'étude cette semaine';
$string['dashboard_stage_chart'] = 'Distribution des étapes de cartes';
$string['dashboard_activity_chart'] = 'Activité de révision (7 derniers jours)';
$string['dashboard_achievements'] = 'Réalisations';

// Achievements
$string['achievement_first_card'] = 'Première carte';
$string['achievement_week_warrior'] = 'Guerrier de la semaine (série de 7 jours)';
$string['achievement_century'] = 'Centenaire (100 cartes)';
$string['achievement_study_bug'] = 'Mordu d\'étude (10 heures)';
$string['achievement_master'] = 'Maître (1 carte à l\'étape 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Niveau A0 - Débutant';
$string['achievement_level_a1'] = 'Niveau A1 - Élémentaire';
$string['achievement_level_a2'] = 'Niveau A2 - Pré-intermédiaire';
$string['achievement_level_b1'] = 'Niveau B1 - Intermédiaire';
$string['achievement_level_b2'] = 'Niveau B2 - Intermédiaire supérieur';

// Placeholders
$string['collocations_ph'] = 'Un par ligne...';
$string['examples_ph'] = 'Exemples de phrases...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Je t\'aime';
$string['translation_en_placeholder'] = 'I love you';

// Settings - AI & TTS
$string['settings_ai_section'] = 'Assistant IA';
$string['settings_ai_section_desc'] = 'Configurez le modèle ChatGPT utilisé pour détecter les expressions figées lorsqu\'un apprenant clique sur un mot.';
$string['settings_ai_enable'] = 'Activer l\'assistant IA focal';
$string['settings_ai_enable_desc'] = 'Permettre aux apprenants de surligner un mot dans le texte du recto et laisser l\'IA détecter l\'expression correspondante.';
$string['settings_openai_key'] = 'Clé API OpenAI';
$string['settings_openai_key_desc'] = 'Stockée en toute sécurité sur le serveur. Requise pour l\'assistant focal.';
$string['settings_openai_model'] = 'Modèle OpenAI';
$string['settings_openai_model_desc'] = 'Par exemple gpt-4o-mini. L\'assistant utilise chat-completions.';
$string['settings_openai_url'] = 'Point de terminaison OpenAI';
$string['settings_openai_url_desc'] = 'Remplacer uniquement lors de l\'utilisation d\'un point de terminaison compatible proxy.';

$string['settings_tts_section'] = 'Synthèse vocale';
$string['settings_tts_section_desc'] = 'Configurez les fournisseurs de parole pour les phrases complètes (ElevenLabs) et les phrases focales courtes (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Clé API ElevenLabs';
$string['settings_elevenlabs_key_desc'] = 'Stockée en toute sécurité sur le serveur et jamais exposée aux apprenants.';
$string['settings_elevenlabs_voice'] = 'ID de voix par défaut';
$string['settings_elevenlabs_voice_desc'] = 'Utilisé lorsque l\'apprenant ne sélectionne pas de voix spécifique.';
$string['settings_elevenlabs_voice_map'] = 'Options de voix';
$string['settings_elevenlabs_voice_map_desc'] = 'Définissez une voix par ligne en utilisant le format Nom=voice-id. Exemple : Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID du modèle ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'Par défaut eleven_monolingual_v2. Mettre à jour uniquement si votre compte utilise un modèle différent.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Utilisé pour les phrases ultra-courtes (deux mots ou moins) pour garder la latence faible.';
$string['settings_polly_key'] = 'ID de clé d\'accès AWS';
$string['settings_polly_key_desc'] = 'Nécessite la politique IAM AmazonPollyFullAccess ou équivalente.';
$string['settings_polly_secret'] = 'Clé d\'accès secrète AWS';
$string['settings_polly_secret_desc'] = 'Stockée en toute sécurité sur le serveur et jamais exposée aux apprenants.';
$string['settings_polly_region'] = 'Région AWS';
$string['settings_polly_region_desc'] = 'Exemple : eu-west-1. Doit correspondre à la région où Polly est disponible.';
$string['settings_polly_voice'] = 'Voix Polly par défaut';
$string['settings_polly_voice_desc'] = 'Nom de la voix (par ex. Liv, Ida) utilisé lorsqu\'aucun remplacement n\'est défini.';
$string['settings_polly_voice_map'] = 'Remplacements de voix Polly';
$string['settings_polly_voice_map_desc'] = 'Mappage optionnel entre les ID de voix ElevenLabs et les noms de voix Polly. Utilisez le format elevenVoiceId=PollyVoice par ligne.';

$string['settings_orbokene_section'] = 'Dictionnaire Orbøkene';
$string['settings_orbokene_section_desc'] = 'Lorsqu\'activé, l\'assistant IA tentera d\'enrichir les expressions détectées avec les données de la table flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Activer le remplissage automatique du dictionnaire';
$string['settings_orbokene_enable_desc'] = 'Si activé, les entrées correspondantes dans le cache Orbøkene remplissent la définition, la traduction et les exemples.';

// Fill field dialog
$string['fill_field'] = 'Veuillez remplir : {$a}';

// Errors
$string['ai_http_error'] = 'Le service IA est indisponible. Veuillez réessayer plus tard.';
$string['ai_invalid_json'] = 'Réponse inattendue du service IA.';
$string['ai_disabled'] = 'L\'assistant IA n\'est pas encore configuré.';
$string['tts_http_error'] = 'La synthèse vocale est temporairement indisponible.';
$string['whisper_status_idle'] = 'Speech-to-text ready';
$string['whisper_status_uploading'] = 'Uploading Private audio...';
$string['whisper_status_transcribing'] = 'Transcribing...';
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
