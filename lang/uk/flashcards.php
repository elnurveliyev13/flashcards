<?php
// Strings for component 'mod_flashcards'

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Картки';
$string['modulenameplural'] = 'Картки';
$string['modulename_help'] = 'Активність з інтервальним повторенням карток.';
$string['pluginname'] = 'Картки';
$string['pluginadministration'] = 'Адміністрування карток';
$string['flashcardsname'] = 'Назва активності';

// App UI strings
$string['app_title'] = 'MyMemory';
$string['intervals'] = 'Інтервали: 1,3,7,15,31,62,125,251';
$string['export'] = 'Експорт';
$string['import'] = 'Імпорт';
$string['reset'] = 'Скинути прогрес';
$string['profile'] = 'Профіль:';
$string['activate'] = 'Активувати урок';
$string['choose'] = 'Вибрати урок';
$string['loadpack'] = 'Завантажити колоду';
$string['due'] = 'До вивчення: {$a}';
$string['list'] = 'Список карток';
$string['addown'] = 'Додати свою картку';
$string['front'] = 'Текст';
$string['front_translation_mode_label'] = 'Напрямок перекладу';
$string['front_translation_mode_hint'] = 'Натисніть, щоб змінити мови введення/виведення.';
$string['front_translation_status_idle'] = 'Переклад готовий';
$string['front_translation_status_loading'] = 'Перекладається...';
$string['front_translation_status_error'] = 'Помилка перекладу';
$string['front_translation_reverse_hint'] = 'Введіть текст вашою мовою, щоб автоматично перекласти його норвезькою.';
$string['front_translation_copy'] = 'Копіювати переклад';
$string['focus_translation_label'] = 'Фокусне значення';
$string['fokus'] = 'Фокусне слово/фраза';
$string['focus_baseform'] = 'Базова форма';
$string['focus_baseform_ph'] = 'Лема або інфінітив (необов\'язково)';
$string['ai_helper_label'] = 'AI помічник фокусу';
$string['ai_click_hint'] = 'Натисніть будь-яке слово вище, щоб виявити сталий вираз';
$string['front_suggest_collapse'] = 'Згорнути підказки';
$string['ai_helper_disabled'] = 'AI помічник вимкнено адміністратором';
$string['ai_detecting'] = 'Виявлення виразу...';
$string['ai_helper_success'] = 'Фокусну фразу додано';
$string['ai_helper_error'] = 'Не вдалося виявити вираз';
$string['ai_no_text'] = 'Введіть речення, щоб увімкнути помічника';
$string['choose_focus_word'] = 'Оберіть фокус-слово';
$string['sentence_analysis'] = 'Граматичний та смисловий розбір';
$string['analysis_empty'] = 'Виберіть слово, щоб побачити граматичний розбір.';
$string['ordbokene_block_label'] = 'Ordbøkene';
$string['ordbokene_empty'] = 'Інформація зі словника зʼявиться тут після пошуку.';
$string['ordbokene_citation'] = '«Korleis». I: Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).';
$string['ai_question_label'] = 'Запитати AI';
$string['ai_question_placeholder'] = 'Введіть Ваше запитання…';
$string['ai_question_button'] = 'Запитати';
$string['ai_chat_empty'] = 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы';
$string['ai_chat_user'] = 'Ви';
$string['ai_chat_assistant'] = 'ШІ';
$string['ai_chat_error'] = 'ШІ не зміг відповісти на це запитання.';
$string['ai_chat_loading'] = 'Думає...';
$string['check_text'] = 'Перевірити текст';
$string['no_errors_found'] = 'Помилок не знайдено!';
$string['apply_corrections'] = 'Застосувати виправлення';
$string['keep_as_is'] = 'Залишити як є';
$string['error_checking_failed'] = 'Перевірка не вдалася';
$string['naturalness_suggestion'] = 'Більш природний варіант:';
$string['ask_ai_about_correction'] = 'Запитати AI';
$string['ai_sure'] = 'Ти впевнена?';
$string['ai_explain_more'] = 'Поясни детальніше';
$string['ai_more_examples'] = 'Дай більше прикладів';
$string['ai_explain_simpler'] = 'Поясни простіше';
$string['ai_thinking'] = 'Думає...';
$string['focus_audio_badge'] = 'Аудіо (слово)';
$string['front_audio_badge'] = 'Аудіо (текст)';
$string['private_audio_label'] = 'Приватне аудіо';
$string['explanation'] = 'Пояснення';
$string['back'] = 'Переклад';
$string['back_en'] = 'Переклад';
$string['image'] = 'Зображення';
$string['audio'] = 'Аудіо';
$string['order_audio_word'] = 'Фокусне аудіо';
$string['order_audio_text'] = 'Аудіо';
$string['undo'] = 'Скасувати';
$string['tts_voice'] = 'Голос';
$string['tts_voice_hint'] = 'Виберіть голос перед тим, як попросити AI помічника згенерувати аудіо.';
$string['tts_voice_placeholder'] = 'Голос за замовчуванням';
$string['tts_voice_missing'] = 'Додайте голоси для синтезу мовлення в налаштуваннях плагіна.';
$string['tts_voice_disabled'] = 'Надайте ключі ElevenLabs або Amazon Polly, щоб увімкнути генерацію аудіо.';
$string['tts_status_success'] = 'Аудіо готове.';
$string['tts_status_error'] = 'Помилка генерації аудіо.';
$string['whisper_status_idle'] = 'Розпізнавання мовлення готове';
$string['whisper_status_uploading'] = 'Завантаження приватного аудіо...';
$string['whisper_status_transcribing'] = 'Транскрибування...';
$string['whisper_status_success'] = 'Транскрипцію вставлено';
$string['whisper_status_error'] = 'Не вдалося транскрибувати аудіо';
$string['whisper_status_limit'] = 'Кліп задовгий';
$string['whisper_status_quota'] = 'Досягнуто місячний ліміт мовлення';
$string['whisper_status_retry'] = 'Спробувати ще раз';
$string['whisper_status_undo'] = 'Скасувати заміну';
$string['whisper_status_disabled'] = 'Розпізнавання мовлення недоступне';
$string['scan_text'] = 'Зчитати текст з фото';
$string['scan_text_hint'] = 'Скористайтеся камерою, щоб захопити слова й вставити їх у поле «Лицьова сторона».';
$string['ocr_status_idle'] = 'Сканер тексту готовий';
$string['ocr_status_processing'] = 'Сканування фото...';
$string['ocr_status_success'] = 'Текст вставлено';
$string['ocr_status_error'] = 'Не вдалося прочитати текст';
$string['ocr_status_disabled'] = 'Розпізнавання зображень недоступне';
$string['ocr_status_retry'] = 'Спробувати ще раз';
$string['ocr_status_undo'] = 'Скасувати заміну';
$string['mediareport_title'] = 'Аудіофайли карток';
$string['mediareport_filter_search'] = 'Пошук тексту або ID картки';
$string['mediareport_filter_search_ph'] = 'наприклад, інфінітив, переклад, ID картки';
$string['mediareport_filter_user'] = 'ID користувача-власника';
$string['mediareport_filter_user_ph'] = 'Залишіть порожнім для всіх користувачів';
$string['mediareport_filter_perpage'] = 'Рядків на сторінку';
$string['mediareport_empty'] = 'Не знайдено карток з аудіо, що відповідають вашим фільтрам.';
$string['mediareport_card'] = 'Картка';
$string['mediareport_owner'] = 'Власник';
$string['mediareport_audio'] = 'Аудіофайли';
$string['mediareport_updated'] = 'Оновлено';
$string['mediareport_audio_sentence'] = 'Аудіо речення';
$string['mediareport_audio_front'] = 'Аудіо лицьової сторони';
$string['mediareport_audio_focus'] = 'Фокусне аудіо';
$string['mediareport_noaudio'] = 'Немає збереженого аудіо для цієї картки.';
$string['mediareport_cardid'] = 'ID картки: {$a}';
$string['mediareport_deck'] = 'Колода: {$a}';
$string['choosefile'] = 'Вибрати файл';
$string['chooseaudiofile'] = 'Вибрати аудіофайл';
$string['showmore'] = 'Показати більше';
$string['autosave'] = 'Прогрес збережено';
$string['easy'] = 'Легко';
$string['normal'] = 'Норм.';
$string['hard'] = 'Важко';
$string['btnHardHint'] = 'Повторити цю картку сьогодні';
$string['btnNormalHint'] = 'Наступний огляд завтра';
$string['btnEasyHint'] = 'Перейти до наступного етапу';
$string['update'] = 'Оновити';
$string['update_disabled_hint'] = 'Відкрийте наявну картку, щоб активувати оновлення.';
$string['createnew'] = 'Створити';
$string['order'] = 'Порядок (натискайте послідовно)';
$string['empty'] = 'Сьогодні нічого не заплановано';
$string['resetform'] = 'Очистити форму';
$string['addtomycards'] = 'Додати до моїх карток';
$string['install_app'] = 'Встановити додаток';
$string['interface_language_label'] = 'Мова інтерфейсу';
$string['font_scale_label'] = 'Розмір шрифту';
$string['font_scale_default'] = 'Типовий (100%)';
$string['font_scale_plus15'] = 'Великий (+15%)';
$string['font_scale_plus30'] = 'Дуже великий (+30%)';
$string['preferences_toggle_label'] = 'Меню налаштувань';
$string['header_preferences_label'] = 'Налаштування відображення';

// Linguistic enrichment fields
$string['transcription'] = 'Транскрипція';
$string['pos'] = 'Частина мови';
$string['pos_noun'] = 'Іменник';
$string['pos_verb'] = 'Дієслово';
$string['pos_adj'] = 'Прикметник';
$string['pos_adv'] = 'Прислівник';
$string['pos_other'] = 'Інше';
$string['gender'] = 'Рід';
$string['gender_neuter'] = 'Середній (intetkjonn)';
$string['gender_masculine'] = 'Чоловічий (hankjonn)';
$string['gender_feminine'] = 'Жіночий (hunkjonn)';
$string['noun_forms'] = 'Форми іменника';
$string['verb_forms'] = 'Форми дієслова';
$string['adj_forms'] = 'Форми прикметника';
$string['indef_sg'] = 'Неозначена однина';
$string['def_sg'] = 'Означена однина';
$string['indef_pl'] = 'Неозначена множина';
$string['def_pl'] = 'Означена множина';
$string['antonyms'] = 'Антоніми';
$string['collocations'] = 'Загальні словосполучення';
$string['examples'] = 'Приклади речень';
$string['cognates'] = 'Споріднені слова';
$string['sayings'] = 'Загальні вислови';
$string['autofill'] = 'Автозаповнення';
$string['fetch_from_api'] = 'Отримати через API';
$string['save'] = 'Зберегти';
$string['skip'] = 'Пропустити';
$string['cancel'] = 'Скасувати';
$string['fill_field'] = 'Будь ласка, заповніть: {$a}';
$string['autofill_soon'] = 'Автозаповнення буде доступне незабаром';

// iOS Install Instructions
$string['ios_install_title'] = 'Встановіть цей додаток на головний екран:';
$string['ios_install_step1'] = '1. Натисніть кнопку';
$string['ios_install_step1_suffix'] = '';
$string['ios_install_step2'] = '2. Виберіть';
$string['ios_install_step2_suffix'] = '';
$string['ios_share_button'] = 'Поділитися';
$string['ios_add_to_home'] = 'На головний екран';

// Titles / tooltips
$string['title_camera'] = 'Камера';
$string['title_take'] = 'Зробити фото';
$string['title_closecam'] = 'Закрити камеру';
$string['title_play'] = 'Відтворити';
$string['title_slow'] = 'Відтворити повільно';
$string['title_edit'] = 'Редагувати';
$string['title_del'] = 'Видалити';
$string['title_record'] = 'Записати';
$string['title_stop'] = 'Зупинити';
$string['title_record_practice'] = 'Записати вимову';
$string['press_hold_to_record'] = 'Натисніть і утримуйте для запису';
$string['release_when_finished'] = 'Відпустіть, коли закінчите';
$string['mic_permission_pending'] = 'Надати доступ';
$string['mic_permission_requesting'] = 'Запитуємо...';
$string['mic_permission_denied'] = 'Увімкніть у Safari';

// List table
$string['list_front'] = 'Фокусне слово/фраза';
$string['list_deck'] = 'Колода';
$string['list_stage'] = 'Етап';
$string['list_added'] = 'Додано';
$string['list_due'] = 'Наступний огляд';
$string['list_play'] = 'Відтворити';
$string['search_ph'] = 'Пошук...';
$string['cards'] = 'Картки';
$string['close'] = 'Закрити';

// Access control messages
$string['access_denied'] = 'Доступ заборонено';
$string['access_expired_title'] = 'Термін доступу до карток минув';
$string['access_expired_message'] = 'У вас більше немає доступу до карток. Будь ласка, зареєструйтеся на курс, щоб відновити доступ.';
$string['access_grace_message'] = 'Ви можете переглядати свої картки ще {$a} днів. Зареєструйтеся на курс, щоб створювати нові картки.';
$string['access_create_blocked'] = 'Ви не можете створювати нові картки без активної реєстрації на курс.';
$string['grace_period_restrictions'] = 'Під час пільгового періоду:';
$string['grace_can_review'] = '✓ Ви МОЖЕТЕ переглядати існуючі картки';
$string['grace_cannot_create'] = '✗ Ви НЕ МОЖЕТЕ створювати нові картки';

// Enhanced access status messages
$string['access_status_active'] = 'Активний доступ';
$string['access_status_active_desc'] = 'У вас є повний доступ до створення та перегляду карток.';
$string['access_status_grace'] = 'Пільговий період (залишилося {$a} днів)';
$string['access_status_grace_desc'] = 'Ви можете переглядати існуючі картки, але не можете створювати нові. Зареєструйтеся на курс, щоб відновити повний доступ.';
$string['access_status_expired'] = 'Термін доступу минув';
$string['access_status_expired_desc'] = 'Термін вашого доступу минув. Зареєструйтеся на курс, щоб відновити доступ до карток.';
$string['access_enrol_now'] = 'Зареєструватися на курс';
$string['access_days_remaining'] = 'Залишилося {$a} днів';

// Notifications
$string['messageprovider:grace_period_started'] = 'Розпочато пільговий період карток';
$string['messageprovider:access_expiring_soon'] = 'Доступ до карток незабаром закінчиться';
$string['messageprovider:access_expired'] = 'Доступ до карток закінчився';

$string['notification_grace_subject'] = 'Картки: Розпочато пільговий період';
$string['notification_grace_message'] = 'Ви більше не зареєстровані на курс карток. Ви можете переглядати існуючі картки протягом {$a} днів. Щоб створювати нові картки, будь ласка, зареєструйтеся на курс.';
$string['notification_grace_message_html'] = '<p>Ви більше не зареєстровані на курс карток.</p><p>Ви можете <strong>переглядати існуючі картки протягом {$a} днів</strong>.</p><p>Щоб створювати нові картки, будь ласка, зареєструйтеся на курс.</p>';

$string['notification_expiring_subject'] = 'Картки: Доступ закінчиться через 7 днів';
$string['notification_expiring_message'] = 'Ваш доступ до карток закінчиться через 7 днів. Зареєструйтеся на курс, щоб зберегти доступ.';
$string['notification_expiring_message_html'] = '<p><strong>Ваш доступ до карток закінчиться через 7 днів.</strong></p><p>Зареєструйтеся на курс, щоб зберегти доступ до карток.</p>';

$string['notification_expired_subject'] = 'Картки: Доступ закінчився';
$string['notification_expired_message'] = 'Ваш доступ до карток закінчився. Зареєструйтеся на курс, щоб відновити доступ.';
$string['notification_expired_message_html'] = '<p><strong>Ваш доступ до карток закінчився.</strong></p><p>Зареєструйтеся на курс, щоб відновити доступ до карток.</p>';

// Global page strings
$string['myflashcards'] = 'Мої картки';
$string['myflashcards_welcome'] = 'Ласкаво просимо до ваших карток!';
$string['access_denied_full'] = 'У вас немає доступу для перегляду карток. Будь ласка, зареєструйтеся на курс з активністю карток.';
$string['browse_courses'] = 'Переглянути доступні курси';

// Scheduled tasks
$string['task_check_user_access'] = 'Перевірити доступ користувачів до карток та пільгові періоди';
$string['task_cleanup_orphans'] = 'Очистити осиротілі записи прогресу карток';

$string['cards_remaining'] = 'карток залишилось';
$string['rating_actions'] = 'Дії оцінювання';
$string['progress_label'] = 'Прогрес перегляду';

// Tab navigation (v0.7.0)
$string['tab_quickinput'] = 'Створити';
$string['tab_study'] = 'Навчання';
$string['tab_dashboard'] = 'Панель';

// Quick Input
$string['quickinput_title'] = 'Додати нову картку';
$string['quick_audio'] = 'Записати аудіо';
$string['quick_photo'] = 'Зробити фото';
$string['show_advanced'] = 'Показати додаткові ▼';
$string['hide_advanced'] = 'Сховати додаткові ▲';
$string['card_created'] = 'Картку створено!';
$string['quickinput_created_today'] = '{$a} створено сьогодні';

// Dashboard
$string['dashboard_cards_due'] = 'Картки на сьогодні';
$string['dashboard_total_cards'] = 'Всього створено карток';
$string['dashboard_active_vocab'] = 'Активний словник';
$string['dashboard_streak'] = 'Поточна серія (днів)';
$string['dashboard_study_time'] = 'Час навчання цього тижня';
$string['dashboard_stage_chart'] = 'Розподіл карток за етапами';
$string['dashboard_activity_chart'] = 'Активність перегляду (останні 7 днів)';
$string['dashboard_achievements'] = 'Досягнення';

// Achievements
$string['achievement_first_card'] = 'Перша картка';
$string['achievement_week_warrior'] = 'Воїн тижня (7-денна серія)';
$string['achievement_century'] = 'Століття (100 карток)';
$string['achievement_study_bug'] = 'Жук навчання (10 годин)';
$string['achievement_master'] = 'Майстер (1 картка на етапі 7+)';

// Language Level Achievements (based on Active Vocabulary)
$string['achievement_level_a0'] = 'Рівень A0 - Початківець';
$string['achievement_level_a1'] = 'Рівень A1 - Елементарний';
$string['achievement_level_a2'] = 'Рівень A2 - Базовий';
$string['achievement_level_b1'] = 'Рівень B1 - Середній';
$string['achievement_level_b2'] = 'Рівень B2 - Вище середнього';

// Placeholders
$string['collocations_ph'] = 'По одному на рядок...';
$string['examples_ph'] = 'Приклади речень...';
$string['translation_en_placeholder'] = 'I love you';
$string['translation_placeholder'] = 'Я тебе кохаю';
$string['explanation_placeholder'] = 'Пояснення...';
$string['focus_placeholder'] = 'Фокусне слово/фраза...';
$string['collocations_placeholder'] = 'словосполучення...';
$string['examples_placeholder'] = 'приклади...';
$string['antonyms_placeholder'] = 'антоніми...';
$string['cognates_placeholder'] = 'споріднені слова...';
$string['sayings_placeholder'] = 'вислови...';
$string['transcription_placeholder'] = '[МФА напр. /hu:s/]';
$string['one_per_line_placeholder'] = 'по одному на рядок...';
$string['translation_in_phrase'] = 'Переклад на ';
$string['front_placeholder'] = '_ _ _';

// Settings - AI & TTS
$string['settings_ai_section'] = 'AI помічник';
$string['settings_ai_section_desc'] = 'Налаштуйте модель ChatGPT, яка використовується для виявлення сталих виразів, коли учень натискає на слово.';
$string['settings_ai_enable'] = 'Увімкнути AI помічника фокусу';
$string['settings_ai_enable_desc'] = 'Дозволити учням виділяти слово в тексті лицьової сторони та дозволити AI виявити відповідний вираз.';
$string['settings_openai_key'] = 'Ключ OpenAI API';
$string['settings_openai_key_desc'] = 'Зберігається безпечно на сервері. Необхідний для помічника фокусу.';
$string['settings_openai_model'] = 'Модель OpenAI';
$string['settings_openai_model_desc'] = 'Наприклад, gpt-4o-mini. Помічник використовує chat-completions.';
$string['settings_openai_url'] = 'Кінцева точка OpenAI';
$string['settings_openai_url_desc'] = 'Змініть тільки при використанні кінцевої точки, сумісної з проксі.';

$string['settings_tts_section'] = 'Синтез мовлення';
$string['settings_tts_section_desc'] = 'Налаштуйте провайдерів мовлення для повних речень (ElevenLabs) та коротких фокусних фраз (Amazon Polly).';
$string['settings_elevenlabs_key'] = 'Ключ ElevenLabs API';
$string['settings_elevenlabs_key_desc'] = 'Зберігається безпечно на сервері та ніколи не відображається учням.';
$string['settings_elevenlabs_voice'] = 'ID голосу за замовчуванням';
$string['settings_elevenlabs_voice_desc'] = 'Використовується, коли учень не вибирає конкретний голос.';
$string['settings_elevenlabs_voice_map'] = 'Параметри голосу';
$string['settings_elevenlabs_voice_map_desc'] = 'Визначте один голос на рядок, використовуючи формат Назва=voice-id. Приклад: Ida=21m00Tcm4TlvDq8ikWAM';
$string['settings_elevenlabs_model'] = 'ID моделі ElevenLabs';
$string['settings_elevenlabs_model_desc'] = 'За замовчуванням eleven_monolingual_v2. Змініть тільки якщо ваш обліковий запис використовує іншу модель.';
$string['settings_polly_section'] = 'Amazon Polly';
$string['settings_polly_section_desc'] = 'Використовується для надкоротких фраз (два слова або менше) для зниження затримки.';
$string['settings_polly_key'] = 'ID ключа доступу AWS';
$string['settings_polly_key_desc'] = 'Потрібна політика IAM AmazonPollyFullAccess або еквівалентна.';
$string['settings_polly_secret'] = 'Секретний ключ доступу AWS';
$string['settings_polly_secret_desc'] = 'Зберігається безпечно на сервері та ніколи не відображається учням.';
$string['settings_polly_region'] = 'Регіон AWS';
$string['settings_polly_region_desc'] = 'Приклад: eu-west-1. Повинен відповідати регіону, де доступний Polly.';
$string['settings_polly_voice'] = 'Голос Polly за замовчуванням';
$string['settings_polly_voice_desc'] = 'Назва голосу (наприклад, Liv, Ida), що використовується, коли не визначено перевизначення.';
$string['settings_polly_voice_map'] = 'Перевизначення голосу Polly';
$string['settings_polly_voice_map_desc'] = 'Необов\'язкове зіставлення між ID голосів ElevenLabs та назвами голосів Polly. Використовуйте формат elevenVoiceId=PollyVoice на рядок.';

$string['settings_whisper_section'] = 'Розпізнавання мовлення Whisper';
$string['settings_whisper_section_desc'] = 'Налаштуйте OpenAI Whisper, щоб автоматично перетворювати записи учнів на текст лицьової сторони.';
$string['settings_whisper_enable'] = 'Увімкнути транскрипцію Whisper';
$string['settings_whisper_enable_desc'] = 'Дозволити кнопці «Записати аудіо» викликати Whisper через сервер Moodle.';
$string['settings_whisper_key'] = 'API-ключ OpenAI для Whisper';
$string['settings_whisper_key_desc'] = 'Зберігається безпечно на сервері. Ніколи не показується учням.';
$string['settings_whisper_language'] = 'Мова розпізнавання';
$string['settings_whisper_language_desc'] = 'Дволітерний код, що передається у Whisper (типово nb для норвезького букмола).';
$string['settings_whisper_model'] = 'Модель Whisper';
$string['settings_whisper_model_desc'] = 'Типово whisper-1. Оновіть, якщо OpenAI випустить нову STT-модель.';
$string['settings_whisper_clip_limit'] = 'Обмеження довжини кліпу (секунди)';
$string['settings_whisper_clip_limit_desc'] = 'Кліпи, довші за це значення, відхиляються до виклику Whisper.';
$string['settings_whisper_monthly_limit'] = 'Місячна квота на користувача (секунди)';
$string['settings_whisper_monthly_limit_desc'] = 'Захищає ваш бюджет API. 10 годин ~ 36000 секунд.';
$string['settings_whisper_timeout'] = 'Тайм-аут API (секунди)';
$string['settings_whisper_timeout_desc'] = 'Переривати завислі запити Whisper після цього часу.';

$string['settings_elevenlabs_stt_section'] = 'Розпізнавання мовлення ElevenLabs';
$string['settings_elevenlabs_stt_section_desc'] = 'Налаштуйте ElevenLabs STT як альтернативу Whisper для транскрибування записів учнів.';
$string['settings_elevenlabs_stt_enable'] = 'Увімкнути ElevenLabs STT';
$string['settings_elevenlabs_stt_enable_desc'] = 'Дозволити використовувати ElevenLabs для транскрипції мовлення.';
$string['settings_elevenlabs_stt_key'] = 'API-ключ ElevenLabs для STT';
$string['settings_elevenlabs_stt_key_desc'] = 'Залиште порожнім, щоб використати той самий ключ, що й для TTS. Зберігається безпечно на сервері.';
$string['settings_elevenlabs_stt_language'] = 'Мова розпізнавання';
$string['settings_elevenlabs_stt_language_desc'] = 'Дволітерний код для ElevenLabs (типово nb для норвезького букмола).';
$string['settings_elevenlabs_stt_model'] = 'Модель ElevenLabs STT';
$string['settings_elevenlabs_stt_model_desc'] = 'Типово scribe_v1. Використовуйте scribe_v1_experimental для нових можливостей.';
$string['settings_elevenlabs_stt_clip_limit'] = 'Обмеження довжини кліпу (секунди)';
$string['settings_elevenlabs_stt_clip_limit_desc'] = 'Кліпи, довші за це значення, відхиляються до виклику ElevenLabs.';
$string['settings_elevenlabs_stt_monthly_limit'] = 'Місячна квота на користувача (секунди)';
$string['settings_elevenlabs_stt_monthly_limit_desc'] = 'Захищає ваш бюджет API. 10 годин ~ 36000 секунд.';
$string['settings_elevenlabs_stt_timeout'] = 'Тайм-аут API (секунди)';
$string['settings_elevenlabs_stt_timeout_desc'] = 'Переривати завислі запити ElevenLabs STT після цього часу.';

// STT Provider selection
$string['settings_stt_provider_section'] = 'Постачальник розпізнавання мовлення';
$string['settings_stt_provider_section_desc'] = 'Виберіть сервіс для транскрибування аудіозаписів.';
$string['settings_stt_provider'] = 'Активний постачальник STT';
$string['settings_stt_provider_desc'] = 'Виберіть основний сервіс розпізнавання мовлення. Якщо недоступний, використовується запасний.';
$string['settings_stt_provider_whisper'] = 'OpenAI Whisper';
$string['settings_stt_provider_elevenlabs'] = 'ElevenLabs';

$string['settings_googlevision_section'] = 'Google Vision OCR';
$string['settings_googlevision_section_desc'] = 'Використовуйте Google Cloud Vision, щоб перетворювати текст на знімках у вміст поля «Лицьова сторона».';
$string['settings_googlevision_enable'] = 'Увімкнути Google Vision OCR';
$string['settings_googlevision_enable_desc'] = 'Дозволити кнопці «Сканувати текст» надсилати зображення до Google Vision через сервер Moodle.';
$string['settings_googlevision_key'] = 'API-ключ Google Vision';
$string['settings_googlevision_key_desc'] = 'Надійно зберігається на сервері; створіть ключ на console.cloud.google.com/vision.';
$string['settings_googlevision_language'] = 'Підказка мови OCR';
$string['settings_googlevision_language_desc'] = 'Дволітерний код або локаль (наприклад, en, nb, es), що підказує Vision API потрібну мову.';
$string['settings_googlevision_timeout'] = 'Тайм-аут API (секунди)';
$string['settings_googlevision_timeout_desc'] = 'Переривати завислі запити Vision після цього часу.';
$string['settings_googlevision_monthly_limit'] = 'Місячні запити OCR на користувача';
$string['settings_googlevision_monthly_limit_desc'] = 'Обмежте, скільки сканів зображень може надіслати учень щомісяця.';
$string['error_ocr_disabled'] = 'Розпізнавання зображень вимкнено.';
$string['error_ocr_upload'] = 'Не вдалося завантажити зображення для OCR.';
$string['error_ocr_api'] = 'Помилка сервісу OCR: {$a}';
$string['error_ocr_nodata'] = 'Сервіс OCR не повернув текст.';
$string['error_ocr_filesize'] = 'Зображення перевищує допустимий розмір {$a}.';
$string['error_vision_quota'] = 'Вичерпано місячний ліміт OCR ({$a}).';
$string['ocr_crop_title'] = 'Обрізати сторінку';
$string['ocr_crop_hint'] = 'Виділіть на зображенні точну область для розпізнавання.';
$string['attach_image'] = 'Додати як зображення';
$string['use_for_ocr'] = 'Використати';

// Push notifications settings
$string['settings_push_section'] = 'Push-сповіщення';
$string['settings_push_section_desc'] = 'Надсилати щоденні нагадування про картки. Потрібні ключі VAPID для Web Push.';
$string['settings_push_enable'] = 'Увімкнути push-сповіщення';
$string['settings_push_enable_desc'] = 'Дозволити користувачам отримувати push-сповіщення про картки до повторення.';
$string['settings_vapid_public'] = 'Публічний ключ VAPID';
$string['settings_vapid_public_desc'] = 'Публічний ключ для Web Push (base64url).';
$string['settings_vapid_private'] = 'Приватний ключ VAPID';
$string['settings_vapid_private_desc'] = 'Приватний ключ для Web Push (base64url). Зберігайте в таємниці!';
$string['settings_vapid_subject'] = 'Тема VAPID';
$string['settings_vapid_subject_desc'] = 'Контактний email для push-сервісу (наприклад, mailto:admin@example.com).';

// Push notification task
$string['task_send_push_notifications'] = 'Надсилати push-сповіщення про картки до повторення';

$string['settings_orbokene_section'] = 'Словник Orbøkene';
$string['settings_orbokene_section_desc'] = 'Якщо увімкнено, AI помічник спробує збагатити виявлені вирази даними з таблиці flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Увімкнути автозаповнення словника';
$string['settings_orbokene_enable_desc'] = 'Якщо увімкнено, відповідні записи в кеші Orbøkene заповнюють визначення, переклад та приклади.';

// Fill field dialog
$string['fill_field'] = 'Будь ласка, заповніть: {$a}';

// Errors
$string['ai_http_error'] = 'Сервіс AI недоступний. Будь ласка, спробуйте пізніше.';
$string['ai_invalid_json'] = 'Неочікувана відповідь від сервісу AI.';
$string['ai_disabled'] = 'AI помічник ще не налаштовано.';
$string['tts_http_error'] = 'Синтез мовлення тимчасово недоступний.';
$string['error_whisper_disabled'] = 'Розпізнавання мовлення зараз недоступне.';
$string['error_whisper_clip'] = 'Приватне аудіо довше ніж {$a} секунд.';
$string['error_whisper_quota'] = 'Вичерпано місячний ліміт розпізнавання мовлення ({$a}).';
$string['error_whisper_upload'] = 'Не вдалося обробити завантажений аудіофайл.';
$string['error_whisper_api'] = 'Сервіс розпізнавання мовлення повернув помилку: {$a}';
$string['error_whisper_filesize'] = 'Аудіофайл занадто великий (макс. {$a}).';
$string['error_elevenlabs_stt_disabled'] = 'Розпізнавання мовлення ElevenLabs зараз недоступне.';
$string['error_elevenlabs_stt_clip'] = 'Приватне аудіо довше ніж {$a} секунд.';
$string['error_elevenlabs_stt_quota'] = 'Вичерпано місячний ліміт мовлення ({$a}).';
$string['error_elevenlabs_stt_api'] = 'Помилка розпізнавання ElevenLabs: {$a}';
$string['error_stt_disabled'] = 'Розпізнавання мовлення не налаштовано. Увімкніть Whisper або ElevenLabs STT.';
$string['error_stt_upload'] = 'Не вдалося обробити завантажений аудіофайл.';
$string['error_stt_api'] = 'Сервіс розпізнавання мовлення повернув помилку: {$a}';
