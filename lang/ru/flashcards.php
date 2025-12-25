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
$string['front'] = 'Текст';
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
$string['front_suggest_collapse'] = 'Свернуть подсказки';
$string['ai_helper_disabled'] = 'AI помощник отключен администратором';
$string['ai_detecting'] = 'Выявление выражения...';
$string['ai_helper_success'] = 'Фокусная фраза добавлена';
$string['ai_helper_error'] = 'Не удалось выявить выражение';
$string['ai_no_text'] = 'Введите предложение, чтобы включить помощника';
$string['token_create_card'] = 'Создать карточку';
$string['token_create_card_status'] = 'Черновик карточки готов';
$string['choose_focus_word'] = 'Выберите фокус-слово или фразу';
$string['sentence_analysis'] = 'Грамматический и смысловой разбор';
$string['analysis_empty'] = 'Выберите слово, чтобы увидеть грамматический разбор.';
$string['ordbokene_block_label'] = 'Ordbøkene';
$string['ordbokene_empty'] = 'Информация из словаря появится здесь после поиска.';
$string['ordbokene_citation'] = '«Korleis». I: Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).';
$string['ai_question_label'] = 'Спросить ИИ';
$string['ai_question_placeholder'] = 'Введите Ваш вопрос...';
$string['ai_question_button'] = 'Спросить';
$string['ai_chat_empty'] = 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы';
$string['ai_chat_user'] = 'Вы';
$string['ai_chat_assistant'] = 'ИИ';
$string['ai_chat_error'] = 'ИИ не смог ответить на этот вопрос.';
$string['ai_chat_loading'] = 'Думает...';
$string['check_text'] = 'Проверить текст';
$string['no_errors_found'] = 'Ошибок не найдено!';
$string['apply_corrections'] = 'Применить исправления';
$string['keep_as_is'] = 'Оставить как есть';
$string['error_check_toggle_label'] = 'Показать проверку ошибок';
$string['error_check_collapse_label'] = 'Скрыть блок проверки ошибок';
$string['error_check_collapse_text'] = 'Свернуть';
$string['error_checking_failed'] = 'Проверка не удалась';
$string['naturalness_suggestion'] = 'Более естественный вариант:';
$string['ask_ai_about_correction'] = 'Спросить AI';
$string['ai_sure'] = 'Ты уверен?';
$string['ai_explain_more'] = 'Объясни подробнее';
$string['ai_more_examples'] = 'Дай больше примеров';
$string['ai_thinking'] = 'Думает...';
$string['focus_audio_badge'] = 'Фокусное аудио';
$string['front_audio_badge'] = 'Аудио лицевой стороны';
$string['private_audio_label'] = 'Приватное аудио';
$string['explanation'] = 'Объяснение';
$string['back'] = 'Перевод';
$string['back_en'] = 'Перевод';
$string['image'] = 'Изображение';
$string['audio'] = 'Аудио';
$string['order_audio_word'] = 'Фокусное аудио';
$string['order_audio_text'] = 'Аудио';
$string['undo'] = 'Отменить';
$string['tts_voice'] = 'Голос';
$string['tts_voice_hint'] = 'Выберите голос перед тем, как попросить AI помощника сгенерировать аудио.';
$string['tts_voice_placeholder'] = 'Голос по умолчанию';
$string['tts_voice_missing'] = 'Добавьте голоса для синтеза речи в настройках плагина.';
$string['tts_voice_disabled'] = 'Предоставьте ключи ElevenLabs или Amazon Polly, чтобы включить генерацию аудио.';
$string['tts_status_success'] = 'Аудио готово.';
$string['tts_status_error'] = 'Ошибка генерации аудио.';
$string['whisper_status_idle'] = 'Распознавание речи готово';
$string['whisper_status_uploading'] = 'Загрузка приватного аудио...';
$string['whisper_status_transcribing'] = 'Транскрибирование...';
$string['whisper_status_success'] = 'Транскрипция вставлена';
$string['whisper_status_error'] = 'Не удалось транскрибировать аудио';
$string['whisper_status_limit'] = 'Клип слишком длинный';
$string['whisper_status_quota'] = 'Достигнут месячный лимит речи';
$string['whisper_status_retry'] = 'Повторить';
$string['whisper_status_undo'] = 'Отменить замену';
$string['whisper_status_disabled'] = 'Распознавание речи недоступно';
$string['scan_text'] = 'Считать текст с фото';
$string['scan_text_hint'] = 'Используйте камеру, чтобы захватить слова и вставить их в поле «Лицевая сторона».';
$string['ocr_status_idle'] = 'Сканер текста готов';
$string['ocr_status_processing'] = 'Сканирование фото...';
$string['ocr_status_ready'] = 'Текст распознан — уточните выделение';
$string['ocr_status_success'] = 'Текст вставлен';
$string['ocr_status_error'] = 'Не удалось прочитать текст';
$string['ocr_status_disabled'] = 'Распознавание изображений недоступно';
$string['ocr_status_retry'] = 'Повторить';
$string['ocr_status_undo'] = 'Отменить замену';
$string['ocr_text_select_title'] = 'Выберите нужный фрагмент';
$string['ocr_text_select_hint'] = 'Потяните начальный и конечный ползунки, чтобы оставить только нужный текст.';
$string['ocr_text_select_apply'] = 'Использовать выделение';
$string['ocr_text_select_reset'] = 'Выделить всё';
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
$string['ttsusage_title'] = 'Использование TTS';
$string['ttsusage_desc'] = 'Помесячное использование синтеза речи по пользователям за {$a}.';
$string['ttsusage_month'] = 'Месяц';
$string['ttsusage_perpage'] = 'Строк на странице';
$string['ttsusage_user'] = 'Пользователь';
$string['ttsusage_eleven'] = 'ElevenLabs';
$string['ttsusage_polly'] = 'Amazon Polly';
$string['ttsusage_total'] = 'Итого';
$string['ttsusage_chars'] = '{$a} символов';
$string['ttsusage_requests'] = '{$a} запросов';
$string['ttsusage_empty'] = 'За выбранный месяц пока нет данных по TTS.';
$string['ttsusage_limit_eleven'] = 'Лимит ElevenLabs: {$a} символов';
$string['ttsusage_limit_polly'] = 'Лимит Polly: {$a} символов';
$string['choosefile'] = 'Выбрать файл';
$string['chooseaudiofile'] = 'Выбрать аудиофайл';
$string['showmore'] = 'Показать больше';
$string['autosave'] = 'Прогресс сохранен';
$string['easy'] = 'Легко';
$string['normal'] = 'Норм.';
$string['hard'] = 'Сложно';
$string['btnHardHint'] = 'Повторить эту карточку сегодня';
$string['btnNormalHint'] = 'Следующий обзор завтра';
$string['btnEasyHint'] = 'Перейти к следующему этапу';
$string['update'] = 'Обновить';
$string['update_disabled_hint'] = 'Откройте существующую карточку, чтобы активировать кнопку обновления.';
$string['createnew'] = 'Создать';
$string['order'] = 'Порядок (нажимайте последовательно)';
$string['empty'] = 'Сегодня ничего не запланировано';
$string['resetform'] = 'Очистить';
$string['addtomycards'] = 'Добавить в мои карточки';
$string['install_app'] = 'Установить как приложение';
$string['interface_language_label'] = 'Язык интерфейса';
$string['font_scale_label'] = 'Размер шрифта';
$string['font_scale_default'] = 'По умолчанию (100%)';
$string['font_scale_plus15'] = 'Крупный (+15%)';
$string['font_scale_plus30'] = 'Очень крупный (+30%)';
$string['preferences_toggle_label'] = 'Меню настроек';
$string['header_preferences_label'] = 'Настройки отображения';

// Linguistic enrichment fields
$string['transcription'] = 'Транскрипция';
$string['pos'] = 'Часть речи';
$string['pos_noun'] = 'Существительное';
$string['pos_verb'] = 'Глагол';
$string['pos_adj'] = 'Прилагательное';
$string['pos_adv'] = 'Наречие';
$string['pos_other'] = 'Другое';
$string['gender'] = 'Род';
$string['gender_neuter'] = 'Средний (intetkjønn)';
$string['gender_masculine'] = 'Мужской (hankjønn)';
$string['gender_feminine'] = 'Женский (hunkjønn)';
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
$string['mic_permission_pending'] = 'Запросить доступ';
$string['mic_permission_requesting'] = 'Запрашиваем...';
$string['mic_permission_denied'] = 'Включите в Safari';

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
$string['dashboard_total_cards'] = 'Всего карточек';
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
$string['collocations_ph'] = '_ _ _';
$string['examples_ph'] = '_ _ _';
$string['front_placeholder'] = '_ _ _';
$string['translation_placeholder'] = '_ _ _';
$string['translation_en_placeholder'] = '_ _ _';
$string['translation_in_phrase'] = '_ _ _';
$string['explanation_placeholder'] = '_ _ _';
$string['focus_placeholder'] = '_ _ _';
$string['collocations_placeholder'] = '_ _ _';
$string['examples_placeholder'] = '_ _ _';
$string['antonyms_placeholder'] = '_ _ _';
$string['cognates_placeholder'] = '_ _ _';
$string['sayings_placeholder'] = '_ _ _';
$string['transcription_placeholder'] = '_ _ _';
$string['one_per_line_placeholder'] = '_ _ _';

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
$string['settings_elevenlabs_tts_limit'] = 'Месячный лимит ElevenLabs (символов на пользователя)';
$string['settings_elevenlabs_tts_limit_desc'] = '0 = без ограничений. После достижения лимита запросы переключаются на Polly, если она настроена.';
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
$string['settings_polly_tts_limit'] = 'Месячный лимит Polly (символов на пользователя)';
$string['settings_polly_tts_limit_desc'] = '0 = без ограничений. Запросы сверх лимита будут отклонены.';

$string['settings_whisper_section'] = 'Распознавание речи Whisper';
$string['settings_whisper_section_desc'] = 'Настройте OpenAI Whisper, чтобы автоматически превращать записи учащихся в текст лицевой стороны.';
$string['settings_whisper_enable'] = 'Включить транскрипцию Whisper';
$string['settings_whisper_enable_desc'] = 'Разрешить кнопке «Записать аудио» вызывать Whisper через сервер Moodle.';
$string['settings_whisper_key'] = 'API-ключ OpenAI для Whisper';
$string['settings_whisper_key_desc'] = 'Хранится безопасно на сервере. Никогда не показывается учащимся.';
$string['settings_whisper_language'] = 'Язык распознавания';
$string['settings_whisper_language_desc'] = 'Двухбуквенный код, передаваемый в Whisper (по умолчанию nb для норвежского букмола).';
$string['settings_whisper_model'] = 'Модель Whisper';
$string['settings_whisper_model_desc'] = 'По умолчанию whisper-1. Обновите, если OpenAI выпустит новую STT-модель.';
$string['settings_whisper_clip_limit'] = 'Лимит длины клипа (секунды)';
$string['settings_whisper_clip_limit_desc'] = 'Клипы длиннее этого значения отклоняются до вызова Whisper.';
$string['settings_whisper_monthly_limit'] = 'Месячная квота на пользователя (секунды)';
$string['settings_whisper_monthly_limit_desc'] = 'Защищает ваш бюджет API. 10 часов ~ 36000 секунд.';
$string['settings_whisper_timeout'] = 'Тайм-аут API (секунды)';
$string['settings_whisper_timeout_desc'] = 'Прерывать зависшие запросы Whisper через это время.';

$string['settings_elevenlabs_stt_section'] = 'Распознавание речи ElevenLabs';
$string['settings_elevenlabs_stt_section_desc'] = 'Настройте ElevenLabs STT как альтернативу Whisper для транскрибирования записей учащихся.';
$string['settings_elevenlabs_stt_enable'] = 'Включить ElevenLabs STT';
$string['settings_elevenlabs_stt_enable_desc'] = 'Разрешить использование ElevenLabs для транскрипции речи.';
$string['settings_elevenlabs_stt_key'] = 'API-ключ ElevenLabs для STT';
$string['settings_elevenlabs_stt_key_desc'] = 'Оставьте пустым, чтобы использовать тот же ключ, что и TTS. Хранится безопасно на сервере.';
$string['settings_elevenlabs_stt_language'] = 'Язык распознавания';
$string['settings_elevenlabs_stt_language_desc'] = 'Двухбуквенный код для ElevenLabs (по умолчанию nb для норвежского букмола).';
$string['settings_elevenlabs_stt_model'] = 'Модель ElevenLabs STT';
$string['settings_elevenlabs_stt_model_desc'] = 'По умолчанию scribe_v1. Используйте scribe_v1_experimental для новых возможностей.';
$string['settings_elevenlabs_stt_clip_limit'] = 'Лимит длины клипа (секунды)';
$string['settings_elevenlabs_stt_clip_limit_desc'] = 'Клипы длиннее этого значения отклоняются до вызова ElevenLabs.';
$string['settings_elevenlabs_stt_monthly_limit'] = 'Месячная квота на пользователя (секунды)';
$string['settings_elevenlabs_stt_monthly_limit_desc'] = 'Защищает ваш бюджет API. 10 часов ~ 36000 секунд.';
$string['settings_elevenlabs_stt_timeout'] = 'Тайм-аут API (секунды)';
$string['settings_elevenlabs_stt_timeout_desc'] = 'Прерывать зависшие запросы ElevenLabs STT через это время.';

// STT Provider selection
$string['settings_stt_provider_section'] = 'Провайдер распознавания речи';
$string['settings_stt_provider_section_desc'] = 'Выберите сервис для транскрибирования аудиозаписей.';
$string['settings_stt_provider'] = 'Активный провайдер STT';
$string['settings_stt_provider_desc'] = 'Выберите основной сервис распознавания речи. При недоступности используется резервный.';
$string['settings_stt_provider_whisper'] = 'OpenAI Whisper';
$string['settings_stt_provider_elevenlabs'] = 'ElevenLabs';

$string['settings_googlevision_section'] = 'Google Vision OCR';
$string['settings_googlevision_section_desc'] = 'Используйте Google Cloud Vision, чтобы превращать текст на снимках в содержимое поля «Лицевая сторона».';
$string['settings_googlevision_enable'] = 'Включить Google Vision OCR';
$string['settings_googlevision_enable_desc'] = 'Позволить кнопке «Сканировать текст» отправлять изображения в Google Vision через сервер Moodle.';
$string['settings_googlevision_key'] = 'API-ключ Google Vision';
$string['settings_googlevision_key_desc'] = 'Хранится безопасно на сервере; создайте ключ на console.cloud.google.com/vision.';
$string['settings_googlevision_language'] = 'Подсказка языка OCR';
$string['settings_googlevision_language_desc'] = 'Двухбуквенный код или локаль (например, en, nb, es), подсказывающая Vision API нужный язык.';
$string['settings_googlevision_timeout'] = 'Тайм-аут API (секунды)';
$string['settings_googlevision_timeout_desc'] = 'Прерывать зависшие запросы Vision через это время.';
$string['settings_googlevision_monthly_limit'] = 'Месячные запросы OCR на пользователя';
$string['settings_googlevision_monthly_limit_desc'] = 'Ограничьте, сколько сканов изображений может отправить ученик в месяц.';
$string['error_ocr_disabled'] = 'Распознавание изображений отключено.';
$string['error_ocr_upload'] = 'Не удалось загрузить изображение для OCR.';
$string['error_ocr_api'] = 'Ошибка сервиса OCR: {$a}';
$string['error_ocr_nodata'] = 'Сервис OCR не вернул текст.';
$string['error_ocr_filesize'] = 'Изображение превышает допустимый размер {$a}.';
$string['error_vision_quota'] = 'Достигнут месячный лимит OCR ({$a}).';
$string['ocr_crop_title'] = 'Обрезать страницу';
$string['ocr_crop_hint'] = 'Выделите область на изображении, которую нужно распознать.';
$string['attach_image'] = 'Прикрепить как изображение';
$string['use_for_ocr'] = 'Использовать';

// Push notifications settings
$string['settings_push_section'] = 'Push-уведомления';
$string['settings_push_section_desc'] = 'Отправлять ежедневные напоминания о карточках. Требуются ключи VAPID для Web Push.';
$string['settings_push_enable'] = 'Включить push-уведомления';
$string['settings_push_enable_desc'] = 'Разрешить пользователям получать push-уведомления о карточках к повторению.';
$string['settings_vapid_public'] = 'Публичный ключ VAPID';
$string['settings_vapid_public_desc'] = 'Публичный ключ для Web Push (base64url).';
$string['settings_vapid_private'] = 'Приватный ключ VAPID';
$string['settings_vapid_private_desc'] = 'Приватный ключ для Web Push (base64url). Храните в секрете!';
$string['settings_vapid_subject'] = 'Тема VAPID';
$string['settings_vapid_subject_desc'] = 'Контактный email для push-сервиса (например, mailto:admin@example.com).';

// Push notification task
$string['task_send_push_notifications'] = 'Отправка push-уведомлений о карточках к повторению';

$string['settings_orbokene_section'] = 'Словарь Orbøkene';
$string['settings_orbokene_section_desc'] = 'Если включено, AI помощник попытается обогатить выявленные выражения данными из таблицы flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Включить автозаполнение словаря';
$string['settings_orbokene_enable_desc'] = 'Если включено, соответствующие записи в кеше Orbøkene заполняют определение, перевод и примеры.';

// Fill field dialog
$string['fill_field'] = 'Пожалуйста, заполните: {$a}';
$string['report_issue'] = 'Сообщить о проблеме';
$string['report_placeholder'] = 'Опишите, что не так с этой карточкой...';
$string['report_submit'] = 'Отправить';
$string['report_sent'] = 'Сообщение отправлено администраторам';
$string['report_sending'] = 'Отправка...';
$string['report_error'] = 'Не удалось отправить сообщение. Попробуйте снова.';
$string['report_no_card'] = 'Сначала выберите карточку';
$string['report_for'] = 'Карточка';
$string['report_card'] = 'Карточка';
$string['report_admin_list'] = 'Свежие обращения';
$string['report_empty'] = 'Пока нет обращений';
$string['report_open_card'] = 'Открыть карточку';
$string['report_comment_label'] = 'Комментарий';
$string['report_notification_subject'] = 'Flashcards: сообщение о проблеме в "{$a->card}"';
$string['report_notification_body'] = 'Пользователь {$a->user} сообщил о проблеме в "{$a->card}". Комментарий: {$a->message}';
$string['report_notification_body_html'] = '<p>Пользователь <strong>{$a->user}</strong> сообщил о проблеме в "<strong>{$a->card}</strong>".</p><p>Комментарий: {$a->message}</p><p><a href="{$a->url}">Открыть карточку</a></p>';

// Errors
$string['ai_http_error'] = 'Сервис AI недоступен. Пожалуйста, попробуйте позже.';
$string['ai_invalid_json'] = 'Неожиданный ответ от сервиса AI.';
$string['ai_disabled'] = 'AI помощник еще не настроен.';
$string['tts_http_error'] = 'Синтез речи временно недоступен.';
$string['error_whisper_disabled'] = 'Распознавание речи сейчас недоступно.';
$string['error_whisper_clip'] = 'Приватное аудио длиннее {$a} секунд.';
$string['error_whisper_quota'] = 'Достигнут месячный лимит распознавания речи ({$a}).';
$string['error_whisper_upload'] = 'Не удалось обработать загруженный аудиофайл.';
$string['error_whisper_api'] = 'Сервис распознавания речи вернул ошибку: {$a}';
$string['error_whisper_filesize'] = 'Аудиофайл слишком большой (макс. {$a}).';
$string['error_tts_quota'] = 'Вы достигли месячного лимита синтеза речи для {$a}.';
$string['error_elevenlabs_stt_disabled'] = 'Распознавание речи ElevenLabs сейчас недоступно.';
$string['error_elevenlabs_stt_clip'] = 'Приватное аудио длиннее {$a} секунд.';
$string['error_elevenlabs_stt_quota'] = 'Достигнут месячный лимит речи ({$a}).';
$string['error_elevenlabs_stt_api'] = 'Сбой распознавания ElevenLabs: {$a}';
$string['error_stt_disabled'] = 'Распознавание речи не настроено. Включите Whisper или ElevenLabs STT.';
$string['error_stt_upload'] = 'Не удалось обработать загруженный аудиофайл.';
$string['error_stt_api'] = 'Сервис распознавания речи вернул ошибку: {$a}';
