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
$string['front'] = 'Лицьова сторона';
$string['front_translation_toggle_show'] = 'Показати переклад';
$string['front_translation_toggle_hide'] = 'Сховати переклад';
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
$string['ai_helper_disabled'] = 'AI помічник вимкнено адміністратором';
$string['ai_detecting'] = 'Виявлення виразу...';
$string['ai_helper_success'] = 'Фокусну фразу додано';
$string['ai_helper_error'] = 'Не вдалося виявити вираз';
$string['ai_no_text'] = 'Введіть речення, щоб увімкнути помічника';
$string['focus_audio_badge'] = 'Фокусне аудіо';
$string['front_audio_badge'] = 'Аудіо лицьової сторони';
$string['explanation'] = 'Пояснення';
$string['back'] = 'Переклад';
$string['image'] = 'Зображення';
$string['audio'] = 'Аудіо';
$string['tts_voice'] = 'Голос';
$string['tts_voice_hint'] = 'Виберіть голос перед тим, як попросити AI помічника згенерувати аудіо.';
$string['tts_voice_placeholder'] = 'Голос за замовчуванням';
$string['tts_voice_missing'] = 'Додайте голоси для синтезу мовлення в налаштуваннях плагіна.';
$string['tts_voice_disabled'] = 'Надайте ключі ElevenLabs або Amazon Polly, щоб увімкнути генерацію аудіо.';
$string['tts_status_success'] = 'Аудіо готове.';
$string['tts_status_error'] = 'Помилка генерації аудіо.';
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
$string['normal'] = 'Нормально';
$string['hard'] = 'Важко';
$string['update'] = 'Оновити';
$string['createnew'] = 'Створити нову';
$string['order'] = 'Порядок (натискайте послідовно)';
$string['empty'] = 'Сьогодні нічого не заплановано';
$string['resetform'] = 'Скинути форму';
$string['addtomycards'] = 'Додати до моїх карток';
$string['install_app'] = 'Встановити додаток';

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
$string['press_hold_to_record'] = 'Натисніть і утримуйте для запису';
$string['release_when_finished'] = 'Відпустіть, коли закінчите';

// List table
$string['list_front'] = 'Лицьова сторона';
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
$string['tab_quickinput'] = 'Швидкий ввід';
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

$string['settings_orbokene_section'] = 'Словник Orbøkene';
$string['settings_orbokene_section_desc'] = 'Якщо увімкнено, AI помічник спробує збагатити виявлені вирази даними з таблиці flashcards_orbokene.';
$string['settings_orbokene_enable'] = 'Увімкнути автозаповнення словника';
$string['settings_orbokene_enable_desc'] = 'Якщо увімкнено, відповідні записи в кеші Orbøkene заповнюють визначення, переклад та приклади.';

// Errors
$string['ai_http_error'] = 'Сервіс AI недоступний. Будь ласка, спробуйте пізніше.';
$string['ai_invalid_json'] = 'Неочікувана відповідь від сервісу AI.';
$string['ai_disabled'] = 'AI помічник ще не налаштовано.';
$string['tts_http_error'] = 'Синтез мовлення тимчасово недоступний.';
