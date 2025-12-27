<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Tarjetas';
$string['modulenameplural'] = 'Tarjetas';
$string['modulename_help'] = 'Actividad de tarjetas de repetición espaciada.';
$string['pluginname'] = 'Tarjetas';
$string['pluginadministration'] = 'Administración de tarjetas';
$string['flashcardsname'] = 'Nombre de la actividad';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Intervalos: 1,3,7,15,31,62,125,251';
$string['export'] = 'Exportar';
$string['import'] = 'Importar';
$string['reset'] = 'Restablecer progreso';
$string['profile'] = 'Perfil:';
$string['activate'] = 'Activar lección';
$string['choose'] = 'Elegir lección';
$string['loadpack'] = 'Cargar mazo';
$string['due'] = 'Pendientes: {$a}';
$string['list'] = 'Lista de tarjetas';
$string['addown'] = 'Añadir tu tarjeta';
$string['front'] = 'Texto';
$string['front_translation_mode_label'] = 'Dirección de traducción';
$string['front_translation_mode_hint'] = 'Toca para cambiar idiomas de entrada/salida.';
$string['front_translation_status_idle'] = 'Traducción lista';
$string['front_translation_status_loading'] = 'Traduciendo...';
$string['front_translation_status_error'] = 'Error de traducción';
$string['front_translation_reverse_hint'] = 'Escribe en tu idioma para traducirlo automáticamente al noruego.';
$string['front_translation_copy'] = 'Copiar traducción';
$string['focus_translation_label'] = 'Significado focal';
$string['fokus'] = 'Palabra/frase focal';
$string['focus_baseform'] = 'Forma base';
$string['focus_baseform_ph'] = 'Lema o infinitivo (opcional)';
$string['ai_helper_label'] = 'Asistente IA focal';
$string['ai_click_hint'] = 'Toca cualquier palabra arriba para detectar una expresión fija';
$string['front_suggest_collapse'] = 'Ocultar sugerencias';
$string['ai_helper_disabled'] = 'Asistente IA desactivado por el administrador';
$string['ai_detecting'] = 'Detectando expresión...';
$string['ai_helper_success'] = 'Frase focal añadida';
$string['ai_helper_error'] = 'No se pudo detectar una expresión';
$string['ai_no_text'] = 'Escribe una oración para habilitar el asistente';
$string['spacy_active_status'] = 'spaCy parsed {$a} tokens';
$string['spacy_inactive_status'] = 'spaCy analysis unavailable';
$string['spacy_model_label'] = 'Model: {$a}';
$string['choose_focus_word'] = 'Elige la palabra o frase focal';
$string['ai_question_label'] = 'Preguntar a la IA';
$string['ai_question_placeholder'] = 'Escribe una pregunta sobre esta frase...';
$string['ai_question_button'] = 'Preguntar';
$string['ai_chat_empty'] = 'Haz una pregunta a la IA sobre tu texto o palabra/frase focal';
$string['ai_chat_user'] = 'Tú';
$string['ai_chat_assistant'] = 'IA';
$string['ai_chat_error'] = 'La IA no pudo responder a esa pregunta.';
$string['ai_chat_loading'] = 'Pensando...';
$string['check_text'] = 'Verificar texto';
$string['no_errors_found'] = '¡No se encontraron errores!';
$string['apply_corrections'] = 'Aplicar correcciones';
$string['keep_as_is'] = 'Dejar como está';
$string['error_checking_failed'] = 'La verificación falló';
$string['naturalness_suggestion'] = 'Alternativa más natural:';
$string['ask_ai_about_correction'] = 'Preguntar a la IA';
$string['ai_sure'] = '¿Estás seguro?';
$string['ai_explain_more'] = 'Explica en detalle';
$string['ai_more_examples'] = 'Da más ejemplos';
$string['ai_thinking'] = 'Pensando...';
$string['focus_audio_badge'] = 'Audio focal';
$string['front_audio_badge'] = 'Audio del anverso';
$string['explanation'] = 'Explicación';
$string['back'] = 'Traducción';
$string['back_en'] = 'Traducción';
$string['image'] = 'Imagen';
$string['audio'] = 'Audio';
$string['order_audio_word'] = 'Audio focal';
$string['order_audio_text'] = 'Audio';
$string['tts_voice'] = 'Voz';
$string['tts_voice_hint'] = 'Selecciona una voz antes de pedir al asistente IA que genere audio.';
$string['tts_voice_placeholder'] = 'Voz predeterminada';
$string['tts_voice_missing'] = 'Añade voces de síntesis de voz en la configuración del plugin.';
$string['tts_voice_disabled'] = 'Proporciona claves de ElevenLabs o Amazon Polly para habilitar la generación de audio.';
$string['tts_status_success'] = 'Audio listo.';
$string['tts_status_error'] = 'Error de generación de audio.';
$string['mediareport_title'] = 'Archivos de audio de tarjetas';
$string['mediareport_filter_search'] = 'Buscar texto o ID de tarjeta';
$string['mediareport_filter_search_ph'] = 'ej. infinitivo, traducción, ID de tarjeta';
$string['mediareport_filter_user'] = 'ID de usuario propietario';
$string['mediareport_filter_user_ph'] = 'Dejar vacío para todos los usuarios';
$string['mediareport_filter_perpage'] = 'Filas por página';
$string['mediareport_empty'] = 'No se encontraron tarjetas con audio que coincidan con tus filtros.';
$string['mediareport_card'] = 'Tarjeta';
$string['mediareport_owner'] = 'Propietario';
$string['mediareport_audio'] = 'Archivos de audio';
$string['mediareport_updated'] = 'Actualizado';
$string['mediareport_audio_sentence'] = 'Audio de oración';
$string['mediareport_audio_front'] = 'Audio del anverso';
$string['mediareport_audio_focus'] = 'Audio focal';
$string['mediareport_noaudio'] = 'No hay audio guardado para esta tarjeta.';
$string['mediareport_cardid'] = 'ID de tarjeta: {$a}';
$string['mediareport_deck'] = 'Mazo: {$a}';
$string['choosefile'] = 'Elegir archivo';
$string['chooseaudiofile'] = 'Elegir archivo de audio';
$string['showmore'] = 'Mostrar más';
$string['autosave'] = 'Progreso guardado';
$string['easy'] = 'Fácil';
$string['normal'] = 'Normal';
$string['hard'] = 'Difícil';
$string['btnHardHint'] = 'Repetir esta tarjeta hoy';
$string['btnNormalHint'] = 'Próxima revisión mañana';
$string['btnEasyHint'] = 'Pasar a la siguiente etapa';
$string['update'] = 'Actualizar';
$string['update_disabled_hint'] = 'Abra primero una tarjeta existente para habilitar Actualizar.';
$string['createnew'] = 'Crear';
$string['order'] = 'Orden (hacer clic en secuencia)';
$string['empty'] = 'Nada pendiente hoy';
$string['resetform'] = 'Limpiar';
$string['addtomycards'] = 'Añadir a mis tarjetas';
$string['install_app'] = 'Instalar aplicación';

// Linguistic enrichment fields
$string['transcription'] = 'Transcripción';
$string['pos'] = 'Categoría gramatical';
$string['pos_noun'] = 'Sustantivo';
$string['pos_verb'] = 'Verbo';
$string['pos_adj'] = 'Adjetivo';
$string['pos_adv'] = 'Adverbio';
$string['pos_other'] = 'Otro';
$string['gender'] = 'Género';
$string['gender_neuter'] = 'Neutro (intetkjønn)';
$string['gender_masculine'] = 'Masculino (hankjønn)';
$string['gender_feminine'] = 'Femenino (hunkjønn)';
$string['noun_forms'] = 'Formas del sustantivo';
$string['verb_forms'] = 'Formas del verbo';
$string['adj_forms'] = 'Formas del adjetivo';
$string['indef_sg'] = 'Singular indefinido';
$string['def_sg'] = 'Singular definido';
$string['indef_pl'] = 'Plural indefinido';
$string['def_pl'] = 'Plural definido';
$string['antonyms'] = 'Antónimos';
$string['collocations'] = 'Colocaciones comunes';
$string['examples'] = 'Oraciones de ejemplo';
$string['cognates'] = 'Cognados';
$string['sayings'] = 'Expresiones comunes';
$string['autofill'] = 'Autocompletar';
$string['fetch_from_api'] = 'Obtener vía API';
$string['save'] = 'Guardar';
$string['skip'] = 'Omitir';
$string['cancel'] = 'Cancelar';
$string['fill_field'] = 'Por favor completa: {$a}';
$string['autofill_soon'] = 'Autocompletar estará disponible pronto';

// iOS Install Instructions
$string['ios_install_title'] = 'Instala esta aplicación en tu pantalla de inicio:';
$string['ios_install_step1'] = '1. Toca el botón';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Selecciona';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Compartir';
$string['ios_add_to_home'] = 'Añadir a pantalla de inicio';

// Titles / tooltips
$string['title_camera'] = 'Cámara';
$string['title_take'] = 'Tomar foto';
$string['title_closecam'] = 'Cerrar cámara';
$string['title_play'] = 'Reproducir';
$string['title_slow'] = 'Reproducir lentamente';
$string['title_edit'] = 'Editar';
$string['title_del'] = 'Eliminar';
$string['title_record'] = 'Grabar';
$string['title_stop'] = 'Detener';
$string['title_record_practice'] = 'Grabar pronunciación';
$string['press_hold_to_record'] = 'Presiona y mantén para grabar';
$string['release_when_finished'] = 'Suelta cuando termines';
$string['mic_permission_pending'] = 'Solicitar acceso';
$string['mic_permission_requesting'] = 'Solicitando...';
$string['mic_permission_denied'] = 'Activa en Safari';

// List table
$string['list_front'] = 'Palabra/frase focal';
$string['list_deck'] = 'Mazo';
$string['list_stage'] = 'Etapa';
$string['list_added'] = 'Añadido';
$string['list_due'] = 'Próxima revisión';
$string['list_play'] = 'Reproducir';
$string['search_ph'] = 'Buscar...';
$string['cards'] = 'Tarjetas';
$string['close'] = 'Cerrar';

// Access control messages
$string['access_denied'] = 'Acceso denegado';
$string['access_expired_title'] = 'El acceso a tarjetas ha expirado';
$string['access_expired_message'] = 'Ya no tienes acceso a tarjetas. Por favor, inscríbete en un curso para recuperar el acceso.';
$string['access_grace_message'] = 'Puedes revisar tus tarjetas durante {$a} días más. Inscríbete en un curso para crear nuevas tarjetas.';
$string['access_create_blocked'] = 'No puedes crear nuevas tarjetas sin una inscripción activa en un curso.';
$string['grace_period_restrictions'] = 'Durante el período de gracia:';
$string['grace_can_review'] = '✓ PUEDES revisar tarjetas existentes';
$string['grace_cannot_create'] = '✗ NO PUEDES crear nuevas tarjetas';

// Enhanced access status messages
$string['access_status_active'] = 'Acceso activo';
$string['access_status_active_desc'] = 'Tienes acceso completo para crear y revisar tarjetas.';
$string['access_status_grace'] = 'Período de gracia ({$a} días restantes)';
$string['access_status_grace_desc'] = 'Puedes revisar tus tarjetas existentes pero no puedes crear nuevas. Inscríbete en un curso para restaurar el acceso completo.';
$string['access_status_expired'] = 'Acceso expirado';
$string['access_status_expired_desc'] = 'Tu acceso ha expirado. Inscríbete en un curso para recuperar el acceso a tarjetas.';
$string['access_enrol_now'] = 'Inscribirse en un curso';
$string['access_days_remaining'] = '{$a} días restantes';

// Notifications
$string['messageprovider:grace_period_started'] = 'Período de gracia de tarjetas iniciado';
$string['messageprovider:access_expiring_soon'] = 'Acceso a tarjetas expirando pronto';
$string['messageprovider:access_expired'] = 'Acceso a tarjetas expirado';

$string['notification_grace_subject'] = 'Tarjetas: Período de gracia iniciado';
$string['notification_grace_message'] = 'Ya no estás inscrito en un curso de tarjetas. Puedes revisar tus tarjetas existentes durante {$a} días. Para crear nuevas tarjetas, por favor inscríbete en un curso.';
$string['notification_grace_message_html'] = '<p>Ya no estás inscrito en un curso de tarjetas.</p><p>Puedes <strong>revisar tus tarjetas existentes durante {$a} días</strong>.</p><p>Para crear nuevas tarjetas, por favor inscríbete en un curso.</p>';

$string['notification_expiring_subject'] = 'Tarjetas: Acceso expirando en 7 días';
$string['notification_expiring_message'] = 'Tu acceso a tarjetas expirará en 7 días. Inscríbete en un curso para mantener el acceso.';
$string['notification_expiring_message_html'] = '<p><strong>Tu acceso a tarjetas expirará en 7 días.</strong></p><p>Inscríbete en un curso para mantener el acceso a tus tarjetas.</p>';

$string['notification_expired_subject'] = 'Tarjetas: Acceso expirado';
$string['notification_expired_message'] = 'Tu acceso a tarjetas ha expirado. Inscríbete en un curso para recuperar el acceso.';
$string['notification_expired_message_html'] = '<p><strong>Tu acceso a tarjetas ha expirado.</strong></p><p>Inscríbete en un curso para recuperar el acceso a tus tarjetas.</p>';

// Global page strings
$string['myflashcards'] = 'Mis tarjetas';
$string['myflashcards_welcome'] = '¡Bienvenido a tus tarjetas!';
$string['access_denied_full'] = 'No tienes acceso para ver tarjetas. Por favor, inscríbete en un curso con actividad de tarjetas.';
$string['browse_courses'] = 'Explorar cursos disponibles';

// Scheduled tasks
$string['task_check_user_access'] = 'Verificar acceso de usuarios a tarjetas y períodos de gracia';
$string['task_cleanup_orphans'] = 'Limpiar registros de progreso huérfanos de tarjetas';

$string['cards_remaining'] = 'tarjetas restantes';
$string['rating_actions'] = 'Acciones de calificación';
$string['progress_label'] = 'Progreso de revisión';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Crear';
$string['tab_study'] = 'Estudiar';
$string['tab_dashboard'] = 'Panel';

// Quick Input
$string['quickinput_title'] = 'Añadir nueva tarjeta';
$string['quick_audio'] = 'Grabar audio';
$string['quick_photo'] = 'Tomar foto';
$string['show_advanced'] = 'Mostrar avanzado ▼';
$string['hide_advanced'] = 'Ocultar avanzado ▲';
$string['card_created'] = '¡Tarjeta creada!';
$string['quickinput_created_today'] = '{$a} creadas hoy';

// Dashboard
$string['dashboard_cards_due'] = 'Tarjetas pendientes hoy';
$string['dashboard_total_cards'] = 'Total de tarjetas';
$string['dashboard_active_vocab'] = 'Vocabulario activo';
$string['dashboard_streak'] = 'Racha actual (días)';
$string['dashboard_study_time'] = 'Tiempo de estudio esta semana';
$string['dashboard_stage_chart'] = 'Distribución de etapas de tarjetas';
$string['dashboard_activity_chart'] = 'Actividad de revisión (últimos 7 días)';
$string['dashboard_achievements'] = 'Logros';

// Achievements
$string['achievement_first_card'] = 'Primera tarjeta';
$string['achievement_week_warrior'] = 'Guerrero de la semana (racha de 7 días)';
$string['achievement_century'] = 'Centenario (100 tarjetas)';
$string['achievement_study_bug'] = 'Bicho de estudio (10 horas)';
$string['achievement_master'] = 'Maestro (1 tarjeta en etapa 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Nivel A0 - Principiante';
$string['achievement_level_a1'] = 'Nivel A1 - Elemental';
$string['achievement_level_a2'] = 'Nivel A2 - Pre-intermedio';
$string['achievement_level_b1'] = 'Nivel B1 - Intermedio';
$string['achievement_level_b2'] = 'Nivel B2 - Intermedio superior';

// Placeholders
$string['collocations_ph'] = 'Una por línea...';
$string['examples_ph'] = 'Oraciones de ejemplo...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Te amo';
$string['translation_en_placeholder'] = 'I love you';

// Settings - AI & TTS
$string['settings_ai_section'] = 'Asistente IA';
$string['settings_ai_section_desc'] = 'Configura el modelo ChatGPT usado para detectar expresiones fijas cuando un estudiante hace clic en una palabra.';
$string['settings_ai_enable'] = 'Activar asistente IA focal';
$string['settings_ai_enable_desc'] = 'Permitir a los estudiantes resaltar una palabra en el texto del anverso y dejar que IA detecte la expresión correspondiente.';
$string['settings_openai_key'] = 'Clave API de OpenAI';
$string['settings_openai_key_desc'] = 'Almacenada de forma segura en el servidor. Requerida para el asistente focal.';
$string['settings_openai_model'] = 'Modelo OpenAI';
$string['settings_openai_model_desc'] = 'Por ejemplo gpt-4o-mini. El asistente usa chat-completions.';
$string['settings_openai_url'] = 'Punto final de OpenAI';
$string['settings_openai_url_desc'] = 'Sobrescribir solo cuando se use un punto final compatible con proxy.';

$string['settings_tts_section'] = 'Síntesis de voz';
$string['settings_tts_section_desc'] = 'Configura proveedores de voz para oraciones completas (ElevenLabs) y frases focales cortas (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Clave API de ElevenLabs';
$string['settings_elevenlabs_key_desc'] = 'Almacenada de forma segura en el servidor y nunca expuesta a estudiantes.';
$string['settings_elevenlabs_voice'] = 'ID de voz predeterminado';
$string['settings_elevenlabs_voice_desc'] = 'Usado cuando el estudiante no selecciona una voz específica.';
$string['settings_elevenlabs_voice_map'] = 'Opciones de voz';
$string['settings_elevenlabs_voice_map_desc'] = 'Define una voz por línea usando el formato Nombre=voice-id. Ejemplo: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID del modelo ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'Predeterminado eleven_monolingual_v2. Actualizar solo si tu cuenta usa un modelo diferente.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Usado para frases ultra-cortas (dos palabras o menos) para mantener baja la latencia.';
$string['settings_polly_key'] = 'ID de clave de acceso AWS';
$string['settings_polly_key_desc'] = 'Requiere la política IAM AmazonPollyFullAccess o equivalente.';
$string['settings_polly_secret'] = 'Clave de acceso secreta AWS';
$string['settings_polly_secret_desc'] = 'Almacenada de forma segura en el servidor y nunca expuesta a estudiantes.';
$string['settings_polly_region'] = 'Región AWS';
$string['settings_polly_region_desc'] = 'Ejemplo: eu-west-1. Debe coincidir con la región donde Polly está disponible.';
$string['settings_polly_voice'] = 'Voz Polly predeterminada';
$string['settings_polly_voice_desc'] = 'Nombre de voz (ej. Liv, Ida) usado cuando no se define anulación.';
$string['settings_polly_voice_map'] = 'Anulaciones de voz Polly';
$string['settings_polly_voice_map_desc'] = 'Mapeo opcional entre IDs de voz ElevenLabs y nombres de voz Polly. Usa el formato elevenVoiceId=PollyVoice por línea.';

$string['settings_orbokene_section'] = 'Diccionario Orbøkene';
$string['settings_orbokene_section_desc'] = 'Cuando está habilitado, el asistente IA intentará enriquecer las expresiones detectadas con datos de la tabla flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Habilitar autocompletado de diccionario';
$string['settings_orbokene_enable_desc'] = 'Si está habilitado, las entradas coincidentes en el caché Orbøkene poblarán definición, traducción y ejemplos.';

// Fill field dialog
$string['fill_field'] = 'Por favor, complete: {$a}';

// Errors
$string['ai_http_error'] = 'El servicio IA no está disponible. Por favor, inténtalo más tarde.';
$string['ai_invalid_json'] = 'Respuesta inesperada del servicio IA.';
$string['ai_disabled'] = 'El asistente IA aún no está configurado.';
$string['tts_http_error'] = 'La síntesis de voz está temporalmente no disponible.';
$string['whisper_status_idle'] = 'Preparado para voz a texto';
$string['whisper_status_uploading'] = 'Subiendo audio privado...';
$string['whisper_status_transcribing'] = 'Transcribiendo...';
$string['whisper_status_success'] = 'Transcripción insertada';
$string['whisper_status_error'] = 'No se pudo transcribir el audio';
$string['whisper_status_limit'] = 'El clip es demasiado largo';
$string['whisper_status_quota'] = 'Límite mensual de voz alcanzado';
$string['whisper_status_retry'] = 'Reintentar';
$string['whisper_status_undo'] = 'Deshacer reemplazo';
$string['whisper_status_disabled'] = 'Voz a texto no disponible';
$string['settings_whisper_section'] = 'Whisper voz a texto';
$string['settings_whisper_section_desc'] = 'Configura OpenAI Whisper para convertir automáticamente las grabaciones de estudiantes en texto del anverso.';
$string['settings_whisper_enable'] = 'Habilitar transcripción Whisper';
$string['settings_whisper_enable_desc'] = 'Permitir que el botón Grabar audio llame a Whisper a través del servidor Moodle.';
$string['settings_whisper_key'] = 'Clave API de OpenAI para Whisper';
$string['settings_whisper_key_desc'] = 'Almacenada de forma segura en el servidor. Nunca expuesta a estudiantes.';
$string['settings_whisper_model'] = 'Modelo Whisper';
$string['settings_whisper_model_desc'] = 'Predeterminado whisper-1. Actualizar si OpenAI lanza un modelo STT más nuevo.';
$string['settings_whisper_language'] = 'Idioma de reconocimiento';
$string['settings_whisper_language_desc'] = 'Código de dos letras pasado a Whisper (predeterminado nb para noruego bokmal).';
$string['settings_whisper_clip_limit'] = 'Límite de duración del clip (segundos)';
$string['settings_whisper_clip_limit_desc'] = 'Los clips más largos que este valor son rechazados antes de llamar a Whisper.';
$string['settings_whisper_monthly_limit'] = 'Cuota mensual por usuario (segundos)';
$string['settings_whisper_monthly_limit_desc'] = 'Protege tu presupuesto de API. 10 horas ~ 36000 segundos.';
$string['settings_whisper_timeout'] = 'Tiempo de espera de API (segundos)';
$string['settings_whisper_timeout_desc'] = 'Abortar solicitudes Whisper estancadas después de estos segundos.';
$string['error_whisper_disabled'] = 'Voz a texto no está disponible ahora.';
$string['error_whisper_clip'] = 'El audio privado es más largo que {$a} segundos.';
$string['error_whisper_quota'] = 'Alcanzaste tu límite mensual de voz ({$a}).';
$string['error_whisper_upload'] = 'No se pudo procesar el archivo de audio subido.';
$string['error_whisper_api'] = 'Servicio de voz a texto falló: {$a}';
$string['error_whisper_filesize'] = 'El archivo de audio es demasiado grande (máx {$a}).';
