<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Карточки';
$string['modulenameplural'] = 'Карточки';
$string['modulename_help'] = 'Активность с интервальным повторением карточек.';
$string['pluginname'] = 'Карточки';
$string['pluginadministration'] = 'Администрирование карточек';
$string['flashcardsname'] = 'Название активности';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Интервалы: 1,3,7,15,31,62,125,251';
$string['export'] = 'Экспорт';
$string['import'] = 'Импорт';
$string['reset'] = 'Сбросить прогресс';
$string['profile'] = 'Профиль:';
$string['activate'] = 'Активировать урок';
$string['choose'] = 'Выбрать урок';
$string['loadpack'] = 'Загрузить колоду';
$string['due'] = 'К изучению: {$a}';
$string['list'] = 'Список карточек';
$string['addown'] = 'Добавить свою карточку';
$string['front'] = 'Лицевая сторона карточки';
$string['front_translation_mode_label'] = 'Направление перевода';
$string['front_translation_mode_hint'] = 'Нажмите, чтобы изменить языки ввода/вывода.';
$string['front_translation_status_idle'] = 'Перевод готов';
$string['front_translation_status_loading'] = 'Переводится...';
$string['front_translation_status_error'] = 'Ошибка перевода';
$string['front_translation_reverse_hint'] = 'Введите текст на вашем языке, чтобы автоматически перевести его на норвежский.';
$string['front_translation_copy'] = 'Копировать перевод';
$string['focus_translation_label'] = 'Фокусное значение';
$string['fokus'] = 'Фокусное слово/фраза';
$string['focus_baseform'] = 'Базовая форма';
$string['focus_baseform_ph'] = 'Лемма или инфинитив (необязательно)';
$string['ai_helper_label'] = 'AI помощник фокуса';
$string['ai_click_hint'] = 'Нажмите любое слово выше, чтобы выявить устойчивое выражение';
$string['ai_helper_disabled'] = 'AI помощник отключен администратором';
$string['ai_detecting'] = 'Выявление выражения...';
$string['ai_helper_success'] = 'Фокусная фраза добавлена';
$string['ai_helper_error'] = 'Не удалось выявить выражение';
$string['ai_no_text'] = 'Введите предложение, чтобы включить помощника';
$string['choose_focus_word'] = 'Выберите фокус-слово';
$string['ai_question_label'] = 'Спросить ИИ';
$string['ai_question_placeholder'] = 'Введите Ваш вопрос...';
$string['ai_question_button'] = 'Спросить';
$string['ai_chat_empty'] = 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы';
$string['focus_audio_badge'] = 'Фокусное аудио';
$string['front_audio_badge'] = 'Аудио лицевой стороны';
$string['explanation'] = 'Объяснение';
$string['back'] = 'Перевод';
$string['back_en'] = 'Перевод';
$string['image'] = 'Изображение';
$string['audio'] = 'Аудио';
$string['order_audio_word'] = 'Аудио (слово)';
$string['order_audio_text'] = 'Аудио (текст)';
$string['tts_voice'] = 'Голос';
$string['tts_voice_hint'] = 'Выберите голос перед тем, как попросить AI помощника сгенерировать аудио.';
$string['tts_voice_placeholder'] = 'Голос по умолчанию';
$string['tts_voice_missing'] = 'Добавьте голоса для синтеза речи в настройках плагина.';
$string['tts_voice_disabled'] = 'Предоставьте ключи ElevenLabs или Amazon Polly, чтобы включить генерацию аудио.';
$string['tts_status_success'] = 'Аудио готово.';
$string['tts_status_error'] = 'Ошибка генерации аудио.';
$string['mediareport_title'] = 'Аудиофайлы карточек';
$string['mediareport_filter_search'] = 'Поиск текста или ID карточки';
$string['mediareport_filter_search_ph'] = 'например, инфинитив, перевод, ID карточки';
$string['mediareport_filter_user'] = 'ID пользователя-владельца';
$string['mediareport_filter_user_ph'] = 'Оставьте пустым для всех пользователей';
$string['mediareport_filter_perpage'] = 'Строк на страницу';
$string['mediareport_empty'] = 'Не найдено карточек с аудио, соответствующих вашим фильтрам.';
$string['mediareport_card'] = 'Карточка';
$string['mediareport_owner'] = 'Владелец';
$string['mediareport_audio'] = 'Аудиофайлы';
$string['mediareport_updated'] = 'Обновлено';
$string['mediareport_audio_sentence'] = 'Аудио предложения';
$string['mediareport_audio_front'] = 'Аудио лицевой стороны';
$string['mediareport_audio_focus'] = 'Фокусное аудио';
$string['mediareport_noaudio'] = 'Нет сохраненного аудио для этой карточки.';
$string['mediareport_cardid'] = 'ID карточки: {$a}';
$string['mediareport_deck'] = 'Колода: {$a}';
$string['choosefile'] = 'Выбрать файл';
$string['chooseaudiofile'] = 'Выбрать аудиофайл';
$string['showmore'] = 'Показать больше';
$string['autosave'] = 'Прогресс сохранен';
$string['easy'] = 'Легко';
$string['normal'] = 'Нормально';
$string['hard'] = 'Сложно';
$string['update'] = 'Обновить';
$string['update_disabled_hint'] = 'Откройте существующую карточку, чтобы активировать кнопку обновления.';
$string['createnew'] = 'Создать';
$string['order'] = 'Порядок (нажимайте последовательно)';
$string['empty'] = 'Сегодня ничего не запланировано';
$string['resetform'] = 'Сбросить форму';
$string['addtomycards'] = 'Добавить в мои карточки';
$string['install_app'] = 'Установить приложение';

// Linguistic enrichment fields
$string['transcription'] = 'Транскрипция';
$string['pos'] = 'Часть речи';
$string['pos_noun'] = 'Существительное';
$string['pos_verb'] = 'Глагол';
$string['pos_adj'] = 'Прилагательное';
$string['pos_adv'] = 'Наречие';
$string['pos_other'] = 'Другое';
$string['gender'] = 'Род';
$string['gender_neuter'] = 'Средний (intetkjonn)';
$string['gender_masculine'] = 'Мужской (hankjonn)';
$string['gender_feminine'] = 'Женский (hunkjonn)';
$string['noun_forms'] = 'Формы существительного';
$string['verb_forms'] = 'Формы глагола';
$string['adj_forms'] = 'Формы прилагательного';
$string['indef_sg'] = 'Неопределенная единственное';
$string['def_sg'] = 'Определенная единственное';
$string['indef_pl'] = 'Неопределенная множественное';
$string['def_pl'] = 'Определенная множественное';
$string['antonyms'] = 'Антонимы';
$string['collocations'] = 'Общие словосочетания';
$string['examples'] = 'Примеры предложений';
$string['cognates'] = 'Родственные слова';
$string['sayings'] = 'Общие высказывания';
$string['autofill'] = 'Автозаполнение';
$string['fetch_from_api'] = 'Получить через API';
$string['save'] = 'Сохранить';
$string['skip'] = 'Пропустить';
$string['cancel'] = 'Отмена';
$string['fill_field'] = 'Пожалуйста, заполните: {$a}';
$string['autofill_soon'] = 'Автозаполнение будет доступно в ближайшее время';

// iOS Install Instructions
$string['ios_install_title'] = 'Установите это приложение на главный экран:';
$string['ios_install_step1'] = '1. Нажмите кнопку';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Выберите';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Поделиться';
$string['ios_add_to_home'] = 'На главный экран';

// Titles / tooltips
$string['title_camera'] = 'Камера';
$string['title_take'] = 'Сделать фото';
$string['title_closecam'] = 'Закрыть камеру';
$string['title_play'] = 'Воспроизвести';
$string['title_slow'] = 'Воспроизвести медленно';
$string['title_edit'] = 'Редактировать';
$string['title_del'] = 'Удалить';
$string['title_record'] = 'Записать';
$string['title_stop'] = 'Остановить';
$string['title_record_practice'] = 'Записать произношение';
$string['press_hold_to_record'] = 'Нажмите и удерживайте для записи';
$string['release_when_finished'] = 'Отпустите, когда закончите';
$string['mic_permission_pending'] = 'Разрешите доступ к микрофону, чтобы начать запись';
$string['mic_permission_requesting'] = 'Ожидаем разрешение на доступ к микрофону...';
$string['mic_permission_denied'] = 'Доступ к микрофону заблокирован. Проверьте настройки Safari.';

// List table
$string['list_front'] = 'Фокусное слово/фраза';
$string['list_deck'] = 'Колода';
$string['list_stage'] = 'Этап';
$string['list_added'] = 'Добавлено';
$string['list_due'] = 'Следующий обзор';
$string['list_play'] = 'Воспроизвести';
$string['search_ph'] = 'Поиск...';
$string['cards'] = 'Карточки';
$string['close'] = 'Закрыть';

// Access control messages
$string['access_denied'] = 'Доступ запрещен';
$string['access_expired_title'] = 'Срок доступа к карточкам истек';
$string['access_expired_message'] = 'У вас больше нет доступа к карточкам. Пожалуйста, зарегистрируйтесь на курс, чтобы восстановить доступ.';
$string['access_grace_message'] = 'Вы можете просматривать свои карточки еще {$a} дней. Зарегистрируйтесь на курс, чтобы создавать новые карточки.';
$string['access_create_blocked'] = 'Вы не можете создавать новые карточки без активной регистрации на курс.';
$string['grace_period_restrictions'] = 'Во время льготного периода:';
$string['grace_can_review'] = '✓ Вы МОЖЕТЕ просматривать существующие карточки';
$string['grace_cannot_create'] = '✗ Вы НЕ МОЖЕТЕ создавать новые карточки';

// Enhanced access status messages
$string['access_status_active'] = 'Активный доступ';
$string['access_status_active_desc'] = 'У вас есть полный доступ к созданию и просмотру карточек.';
$string['access_status_grace'] = 'Льготный период (осталось {$a} дней)';
$string['access_status_grace_desc'] = 'Вы можете просматривать существующие карточки, но не можете создавать новые. Зарегистрируйтесь на курс, чтобы восстановить полный доступ.';
$string['access_status_expired'] = 'Срок доступа истек';
$string['access_status_expired_desc'] = 'Срок вашего доступа истек. Зарегистрируйтесь на курс, чтобы восстановить доступ к карточкам.';
$string['access_enrol_now'] = 'Зарегистрироваться на курс';
$string['access_days_remaining'] = 'Осталось {$a} дней';

// Notifications
$string['messageprovider:grace_period_started'] = 'Начался льготный период карточек';
$string['messageprovider:access_expiring_soon'] = 'Доступ к карточкам скоро закончится';
$string['messageprovider:access_expired'] = 'Доступ к карточкам закончился';

$string['notification_grace_subject'] = 'Карточки: Начался льготный период';
$string['notification_grace_message'] = 'Вы больше не зарегистрированы на курс карточек. Вы можете просматривать существующие карточки в течение {$a} дней. Чтобы создавать новые карточки, пожалуйста, зарегистрируйтесь на курс.';
$string['notification_grace_message_html'] = '<p>Вы больше не зарегистрированы на курс карточек.</p><p>Вы можете <strong>просматривать существующие карточки в течение {$a} дней</strong>.</p><p>Чтобы создавать новые карточки, пожалуйста, зарегистрируйтесь на курс.</p>';

$string['notification_expiring_subject'] = 'Карточки: Доступ закончится через 7 дней';
$string['notification_expiring_message'] = 'Ваш доступ к карточкам закончится через 7 дней. Зарегистрируйтесь на курс, чтобы сохранить доступ.';
$string['notification_expiring_message_html'] = '<p><strong>Ваш доступ к карточкам закончится через 7 дней.</strong></p><p>Зарегистрируйтесь на курс, чтобы сохранить доступ к карточкам.</p>';

$string['notification_expired_subject'] = 'Карточки: Доступ закончился';
$string['notification_expired_message'] = 'Ваш доступ к карточкам закончился. Зарегистрируйтесь на курс, чтобы восстановить доступ.';
$string['notification_expired_message_html'] = '<p><strong>Ваш доступ к карточкам закончился.</strong></p><p>Зарегистрируйтесь на курс, чтобы восстановить доступ к карточкам.</p>';

// Global page strings
$string['myflashcards'] = 'Мои карточки';
$string['myflashcards_welcome'] = 'Добро пожаловать в ваши карточки!';
$string['access_denied_full'] = 'У вас нет доступа для просмотра карточек. Пожалуйста, зарегистрируйтесь на курс с активностью карточек.';
$string['browse_courses'] = 'Просмотреть доступные курсы';

// Scheduled tasks
$string['task_check_user_access'] = 'Проверить доступ пользователей к карточкам и льготные периоды';
$string['task_cleanup_orphans'] = 'Очистить осиротевшие записи прогресса карточек';

$string['cards_remaining'] = 'карточек осталось';
$string['rating_actions'] = 'Действия оценивания';
$string['progress_label'] = 'Прогресс просмотра';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Создать';
$string['tab_study'] = 'Обучение';
$string['tab_dashboard'] = 'Панель';

// Quick Input
$string['quickinput_title'] = 'Добавить новую карточку';
$string['quick_audio'] = 'Записать аудио';
$string['quick_photo'] = 'Сделать фото';
$string['show_advanced'] = 'Показать дополнительные ▼';
$string['hide_advanced'] = 'Скрыть дополнительные ▲';
$string['card_created'] = 'Карточка создана!';
$string['quickinput_created_today'] = '{$a} создано сегодня';

// Dashboard
$string['dashboard_cards_due'] = 'Карточки на сегодня';
$string['dashboard_total_cards'] = 'Всего создано карточек';
$string['dashboard_active_vocab'] = 'Активный словарь';
$string['dashboard_streak'] = 'Текущая серия (дней)';
$string['dashboard_study_time'] = 'Время обучения на этой неделе';
$string['dashboard_stage_chart'] = 'Распределение карточек по этапам';
$string['dashboard_activity_chart'] = 'Активность просмотра (последние 7 дней)';
$string['dashboard_achievements'] = 'Достижения';

// Achievements
$string['achievement_first_card'] = 'Первая карточка';
$string['achievement_week_warrior'] = 'Воин недели (7-дневная серия)';
$string['achievement_century'] = 'Век (100 карточек)';
$string['achievement_study_bug'] = 'Жук обучения (10 часов)';
$string['achievement_master'] = 'Мастер (1 карточка на этапе 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Уровень A0 - Начинающий';
$string['achievement_level_a1'] = 'Уровень A1 - Элементарный';
$string['achievement_level_a2'] = 'Уровень A2 - Базовый';
$string['achievement_level_b1'] = 'Уровень B1 - Средний';
$string['achievement_level_b2'] = 'Уровень B2 - Выше среднего';

// Placeholders
$string['collocations_ph'] = 'По одному на строку...';
$string['examples_ph'] = 'Примеры предложений...';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = 'Я тебя люблю';
$string['translation_en_placeholder'] = 'I love you';
$string['translation_in_phrase'] = 'Перевод на ';

// Settings - AI & TTS
$string['settings_ai_section'] = 'AI помощник';
$string['settings_ai_section_desc'] = 'Настройте модель ChatGPT, которая используется для выявления устойчивых выражений, когда ученик нажимает на слово.';
$string['settings_ai_enable'] = 'Включить AI помощника фокуса';
$string['settings_ai_enable_desc'] = 'Позволить ученикам выделять слово в тексте лицевой стороны и позволить AI выявить соответствующее выражение.';
$string['settings_openai_key'] = 'Ключ OpenAI API';
$string['settings_openai_key_desc'] = 'Хранится безопасно на сервере. Необходим для помощника фокуса.';
$string['settings_openai_model'] = 'Модель OpenAI';
$string['settings_openai_model_desc'] = 'Например, gpt-4o-mini. Помощник использует chat-completions.';
$string['settings_openai_url'] = 'Конечная точка OpenAI';
$string['settings_openai_url_desc'] = 'Измените только при использовании конечной точки, совместимой с прокси.';

$string['settings_tts_section'] = 'Синтез речи';
$string['settings_tts_section_desc'] = 'Настройте провайдеров речи для полных предложений (ElevenLabs) и коротких фокусных фраз (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Ключ ElevenLabs API';
$string['settings_elevenlabs_key_desc'] = 'Хранится безопасно на сервере и никогда не отображается ученикам.';
$string['settings_elevenlabs_voice'] = 'ID голоса по умолчанию';
$string['settings_elevenlabs_voice_desc'] = 'Используется, когда ученик не выбирает конкретный голос.';
$string['settings_elevenlabs_voice_map'] = 'Параметры голоса';
$string['settings_elevenlabs_voice_map_desc'] = 'Определите один голос на строку, используя формат Название=voice-id. Пример: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID модели ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'По умолчанию eleven_monolingual_v2. Измените только если ваша учетная запись использует другую модель.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Используется для сверхкоротких фраз (два слова или меньше) для снижения задержки.';
$string['settings_polly_key'] = 'ID ключа доступа AWS';
$string['settings_polly_key_desc'] = 'Требуется политика IAM AmazonPollyFullAccess или эквивалентная.';
$string['settings_polly_secret'] = 'Секретный ключ доступа AWS';
$string['settings_polly_secret_desc'] = 'Хранится безопасно на сервере и никогда не отображается ученикам.';
$string['settings_polly_region'] = 'Регион AWS';
$string['settings_polly_region_desc'] = 'Пример: eu-west-1. Должен соответствовать региону, где доступен Polly.';
$string['settings_polly_voice'] = 'Голос Polly по умолчанию';
$string['settings_polly_voice_desc'] = 'Название голоса (например, Liv, Ida), используемое, когда не определено переопределение.';
$string['settings_polly_voice_map'] = 'Переопределения голоса Polly';
$string['settings_polly_voice_map_desc'] = 'Необязательное сопоставление между ID голосов ElevenLabs и названиями голосов Polly. Используйте формат elevenVoiceId=PollyVoice на строку.';

$string['settings_orbokene_section'] = 'Словарь Orbøkene';
$string['settings_orbokene_section_desc'] = 'Если включено, AI помощник попытается обогатить выявленные выражения данными из таблицы flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Включить автозаполнение словаря';
$string['settings_orbokene_enable_desc'] = 'Если включено, соответствующие записи в кеше Orbøkene заполняют определение, перевод и примеры.';

// Fill field dialog
$string['fill_field'] = 'Пожалуйста, заполните: {$a}';

// Errors
$string['ai_http_error'] = 'Сервис AI недоступен. Пожалуйста, попробуйте позже.';
$string['ai_invalid_json'] = 'Неожиданный ответ от сервиса AI.';
$string['ai_disabled'] = 'AI помощник еще не настроен.';
$string['tts_http_error'] = 'Синтез речи временно недоступен.';
