/* global M */
import { log as baseDebugLog } from './modules/debug.js';
import { idbPut, idbGet, urlFor } from './modules/storage.js';
import { createIOSRecorder } from './modules/recorder.js';

function flashcardsInit(rootid, baseurl, cmid, instanceid, sesskey, globalMode){
    const root = document.getElementById(rootid);
    if(!root) return;
    const $ = s => root.querySelector(s);
    const $$ = s => Array.from(root.querySelectorAll(s));
    const uniq = a => [...new Set(a.filter(Boolean))];
    const formatActiveVocab = value => {
      const numeric = Number(value);
      if (!Number.isFinite(numeric) || numeric < 0) {
        return '0';
      }
      const decimals = numeric >= 100 ? 0 : 1;
      return numeric.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
    };

    function initAutogrow(){
      $$('.autogrow').forEach(el=>{
        if(!el || el.dataset.autogrowBound) return;
        const resize = ()=>{
          el.style.height = 'auto';
          const min = parseFloat(window.getComputedStyle(el).minHeight) || 0;
          const next = Math.max(el.scrollHeight, min);
          el.style.height = `${next}px`;
        };
        el.addEventListener('input', resize);
        el.addEventListener('change', resize);
        el.dataset.autogrowBound = '1';
        resize();
      });
    }

    initAutogrow();

    const FONT_SCALE_STORAGE_KEY = 'flashcards_font_scale';
    const FONT_SCALE_STEPS = {
      '100': 1,
      '115': 1.15,
      '130': 1.3
    };
    let currentFontScale = 1;
    let currentFontScaleKey = '100';

    function shouldSkipFontScale(node){
      if (!(node instanceof HTMLElement)) {
        return true;
      }
      const tag = node.tagName;
      return tag === 'SCRIPT' || tag === 'STYLE' || node.dataset.noFontScale === 'true';
    }

    function forEachFontNode(scope, callback, includeRoot = true){
      if (!scope) {
        return;
      }
      if (includeRoot && scope instanceof HTMLElement && !shouldSkipFontScale(scope)) {
        callback(scope);
      }
      if (!(scope instanceof HTMLElement)) {
        return;
      }
      scope.querySelectorAll('*').forEach(node => {
        if (shouldSkipFontScale(node)) {
          return;
        }
        callback(node);
      });
    }

    function cacheFontBases(scope){
      forEachFontNode(scope, node => {
        if (!node.dataset.fontBase) {
          const size = parseFloat(window.getComputedStyle(node).fontSize);
          if (Number.isFinite(size) && size > 0) {
            node.dataset.fontBase = String(size);
            if (node.dataset.fontInline === undefined) {
              node.dataset.fontInline = node.style.fontSize || '';
            }
          }
        } else if (node.dataset.fontInline === undefined) {
          node.dataset.fontInline = node.style.fontSize || '';
        }
      });
    }

    function applyFontScale(scope, scale){
      forEachFontNode(scope, node => {
        const base = parseFloat(node.dataset.fontBase);
        if (!Number.isFinite(base) || base <= 0) {
          return;
        }
        if (scale === 1) {
          const original = node.dataset.fontInline ?? '';
          if (original) {
            node.style.fontSize = original;
          } else {
            node.style.removeProperty('font-size');
          }
        } else {
          node.style.fontSize = (base * scale).toFixed(2) + 'px';
        }
      });
    }

    function initFontScalePreference(container){
      if (!container) {
        return;
      }
      const buttons = Array.from(container.querySelectorAll('[data-scale]'));
      if (!buttons.length) {
        return;
      }
      function markActive(key){
        buttons.forEach(btn => {
          const active = btn.dataset.scale === key;
          btn.classList.toggle('active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      }
      function applyScaleKey(key){
        const normalizedKey = FONT_SCALE_STEPS[key] ? key : '100';
        currentFontScaleKey = normalizedKey;
        currentFontScale = FONT_SCALE_STEPS[normalizedKey] || 1;
        markActive(currentFontScaleKey);
        if (currentFontScale !== 1) {
          applyFontScale(root, currentFontScale);
        } else {
          applyFontScale(root, 1);
        }
      }
      let savedValue = '100';
      try {
        const stored = localStorage.getItem(FONT_SCALE_STORAGE_KEY);
        if (stored && FONT_SCALE_STEPS[stored]) {
          savedValue = stored;
        }
      } catch (_e) {}
      applyScaleKey(savedValue);
      buttons.forEach(btn => {
        btn.addEventListener('click', () => {
          const key = btn.dataset.scale;
          applyScaleKey(key);
          try {
            localStorage.setItem(FONT_SCALE_STORAGE_KEY, key);
          } catch (_e) {}
        });
      });
      const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
          mutation.addedNodes.forEach(node => {
            if (!(node instanceof HTMLElement)) {
              return;
            }
            cacheFontBases(node);
            if (currentFontScale !== 1) {
              applyFontScale(node, currentFontScale);
            }
          });
        });
      });
      observer.observe(root, { childList: true, subtree: true });
    }

    const fontScaleControls = document.querySelector('.pref-font-buttons');
    if (fontScaleControls) {
      cacheFontBases(root);
      initFontScalePreference(fontScaleControls);
    }

    const prefsToggle = document.getElementById('prefsToggle');
    const prefsPanel = document.getElementById('prefsPanel');
    if (prefsToggle && prefsPanel) {
      let prefsOpen = false;
      const updatePrefsState = open => {
        prefsOpen = open;
        prefsPanel.classList.toggle('open', open);
        prefsPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
        prefsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      prefsToggle.addEventListener('click', e => {
        e.preventDefault();
        e.stopPropagation();
        updatePrefsState(!prefsOpen);
      });
      document.addEventListener('click', event => {
        if (!prefsOpen) return;
        const target = event.target;
        if (prefsPanel.contains(target) || prefsToggle.contains(target)) {
          return;
        }
        updatePrefsState(false);
      });
      document.addEventListener('keydown', event => {
        if (!prefsOpen) return;
        if (event.key === 'Escape' || event.key === 'Esc') {
          event.preventDefault();
          updatePrefsState(false);
        }
      });
      window.addEventListener('resize', () => {
        if (prefsOpen) {
          updatePrefsState(false);
        }
      });
      updatePrefsState(false);
    }

    // Global mode detection: cmid = 0 OR globalMode = true
    const isGlobalMode = globalMode === true || cmid === 0;
    const runtimeConfig = window.__flashcardsRuntimeConfig || {};
    const aiConfig = runtimeConfig.ai || {};
    const aiEnabled = !!aiConfig.enabled;
    const voiceOptionsRaw = Array.isArray(aiConfig.voices) ? aiConfig.voices : [];
    let selectedVoice = aiConfig.defaultVoice || (voiceOptionsRaw[0]?.voice || '');
    const dataset = root?.dataset || {};
    const aiStrings = {
      click: dataset.aiClick || 'Tap a word to highlight an expression',
      disabled: dataset.aiDisabled || 'AI focus helper is disabled',
      detecting: dataset.aiDetecting || 'Detecting expression…',
      success: dataset.aiSuccess || 'Focus phrase updated',
      error: dataset.aiError || 'Unable to detect an expression',
      notext: dataset.aiNoText || 'Type a sentence first',
      focusAudio: dataset.focusAudioLabel || 'Focus audio',
      frontAudio: dataset.frontAudioLabel || 'Audio',
      voicePlaceholder: dataset.voicePlaceholder || 'Default voice',
      voiceMissing: dataset.voiceMissing || 'Add ElevenLabs voices in plugin settings',
      voiceDisabled: dataset.voiceDisabled || 'Enter your ElevenLabs API key to enable audio.',
      ttsSuccess: dataset.ttsSuccess || 'Audio ready.',
      ttsError: dataset.ttsError || 'Audio generation failed.',
      frontTransShow: dataset.frontTransShow || 'Show translation',
      frontTransHide: dataset.frontTransHide || 'Hide translation',
      translationIdle: dataset.translationIdle || 'Translation ready',
      translationLoading: dataset.translationLoading || 'Translating�',
      translationError: dataset.translationError || 'Translation failed',
      translationReverseHint: dataset.translationReverseHint || 'Type in your language to translate into Norwegian automatically.'
    };
    let frontTranslationSlot = null;
    let frontTranslationToggle = null;
    let frontTranslationVisible = false;

    // Language detection (prefer saved preference, then Moodle, fallback to browser)
    // Prefer Moodle page lang and URL param over browser
    function pageLang(){
      try{
        const qp = new URLSearchParams(window.location.search).get('lang');
        if(qp) return qp;
        const html = document.documentElement && document.documentElement.lang;
        if(html) return html;
      }catch(_e){}
      return null;
    }

    // Load saved translation language preference
    function getSavedTransLang(){
      try{
        return localStorage.getItem('flashcards_translation_lang') || null;
      }catch(_e){
        return null;
      }
    }

    function saveTransLang(lang){
      try{
        localStorage.setItem('flashcards_translation_lang', lang);
      }catch(_e){}
    }

    // ========== INTERFACE LANGUAGE SYSTEM ==========
    // Load saved interface language preference (separate from translation language)
    function getSavedInterfaceLang(){
      try{
        return localStorage.getItem('flashcards_interface_lang') || null;
      }catch(_e){
        return null;
      }
    }

    function saveInterfaceLang(lang){
      try{
        localStorage.setItem('flashcards_interface_lang', lang);
      }catch(_e){}
    }

    // Interface translations dictionary (COMPLETE)
    const interfaceTranslations = {
      en: {
        app_title: 'MyMemory',
        interface_language_label: 'Interface language',
        font_scale_label: 'Font size',
        tab_quickinput: 'Create new card',
        tab_study: 'Study',
        tab_dashboard: 'Dashboard',
        quick_audio: 'Record Audio',
        quick_photo: 'Take Photo',
        choosefile: 'Choose file',
        chooseaudiofile: 'Choose audio file',
        tts_voice: 'Voice',
        tts_voice_hint: 'Select a voice before asking the AI helper to generate audio.',
        front: 'Front of the card',
        front_translation_toggle_show: 'Show translation',
        front_translation_toggle_hide: 'Hide translation',
        front_translation_mode_label: 'Translation direction',
        front_translation_mode_hint: 'Tap to switch input/output languages.',
        front_translation_status_idle: 'Translation ready',
        front_translation_copy: 'Copy translation',
        focus_translation_label: 'Focus meaning',
        fokus: 'Fokus word/phrase',
        focus_baseform: 'Base form',
        focus_baseform_ph: 'Lemma or infinitive (optional)',
        ai_helper_label: 'AI focus helper',
        ai_click_hint: 'Tap any word above to detect a fixed expression',
        ai_no_text: 'Type a sentence to enable the helper',
        explanation: 'Explanation',
        back: 'Translation',
        back_en: 'Translation (English)',
        save: 'Save',
        cancel: 'Cancel',
        show_advanced: 'Show Advanced ▼',
        hide_advanced: 'Hide Advanced ▲',
        empty: 'Nothing due today',
        transcription: 'Transcription',
        pos: 'Part of speech',
        gender: 'Gender',
        noun_forms: 'Noun forms',
        verb_forms: 'Verb forms',
        adj_forms: 'Adjective forms',
        collocations: 'Common collocations',
        examples: 'Example sentences',
        antonyms: 'Antonyms',
        cognates: 'Cognates',
        sayings: 'Common sayings',
        fill_field: 'Please fill: {$a}',
        update: 'Update',
        createnew: 'Create new',
        audio: 'Audio',
        image: 'Image',
        order: 'Order (click in sequence)',
        easy: 'Easy',
        normal: 'Normal',
        hard: 'Hard',
        dashboard_total_cards: 'Total Cards Created',
        dashboard_active_vocab: 'Active vocabulary',
        dashboard_streak: 'Current Streak (days)',
        dashboard_stage_chart: 'Card Stages Distribution',
        dashboard_activity_chart: 'Review Activity (Last 7 Days)',
        dashboard_achievements: 'Achievements',
        achievement_week_warrior: 'Week Warrior (7-day streak)',
        achievement_level_a0: 'Level A0 - Beginner',
        achievement_level_a1: 'Level A1 - Elementary',
        achievement_level_a2: 'Level A2 - Pre-Intermediate',
        achievement_level_b1: 'Level B1 - Intermediate',
        achievement_level_b2: 'Level B2 - Upper-Intermediate',
        save: 'Save',
        skip: 'Skip',
        showmore: 'Show more',
        front_audio_badge: 'Audio',
        focus_audio_badge: 'Focus audio',
        front_placeholder: 'Jeg elsker deg',
        ai_click_hint: 'Tap any word above to detect a fixed expression',
        translation_en_placeholder: 'I love you',
        translation_placeholder: 'Jeg elsker deg',
        explanation_placeholder: 'Explanation...',
        focus_placeholder: 'Focus word/phrase...',
        collocations_placeholder: 'collocations...',
        examples_placeholder: 'examples...',
        antonyms_placeholder: 'antonyms...',
        cognates_placeholder: 'cognates...',
        sayings_placeholder: 'sayings...',
        transcription_placeholder: '[IPA e.g. /hu:s/]',
        one_per_line_placeholder: 'one per line...'
      },
      uk: {
        app_title: 'MyMemory',
        interface_language_label: 'Мова інтерфейсу',
        font_scale_label: 'Розмір шрифту',
        tab_quickinput: 'Створити нову картку',
        tab_study: 'Навчання',
        tab_dashboard: 'Панель',
        quick_audio: 'Записати аудіо',
        quick_photo: 'Зробити фото',
        choosefile: 'Вибрати файл',
        chooseaudiofile: 'Вибрати аудіофайл',
        tts_voice: 'Голос',
        tts_voice_hint: 'Виберіть голос перед тим, як попросити AI помічника згенерувати аудіо.',
        front: 'Лицьова сторона картки',
        front_translation_toggle_show: 'Показати переклад',
        front_translation_toggle_hide: 'Сховати переклад',
        front_translation_mode_label: 'Напрямок перекладу',
        front_translation_mode_hint: 'Натисніть, щоб змінити мови введення/виведення',
        front_translation_status_idle: 'Переклад готовий',
        front_translation_copy: 'Копіювати переклад',
        focus_translation_label: 'Фокусне значення',
        fokus: 'Фокусне слово/фраза',
        focus_baseform: 'Базова форма',
        focus_baseform_ph: 'Лема або інфінітив (необов\'язково)',
        ai_helper_label: 'AI помічник фокусу',
        ai_click_hint: 'Натисніть будь-яке слово вище, щоб виявити сталий вираз',
        ai_no_text: 'Введіть речення, щоб увімкнути помічника',
        explanation: 'Пояснення',
        back: 'Переклад',
        back_en: 'Переклад (англійська)',
        save: 'Зберегти',
        cancel: 'Скасувати',
        show_advanced: 'Показати додаткові ▼',
        hide_advanced: 'Сховати додаткові ▲',
        empty: 'Сьогодні нічого не заплановано',
        transcription: 'Транскрипція',
        pos: 'Частина мови',
        gender: 'Рід',
        noun_forms: 'Форми іменника',
        verb_forms: 'Форми дієслова',
        adj_forms: 'Форми прикметника',
        collocations: 'Загальні словосполучення',
        examples: 'Приклади речень',
        antonyms: 'Антоніми',
        cognates: 'Споріднені слова',
        sayings: 'Загальні вислови',
        fill_field: 'Будь ласка, заповніть: {$a}',
        update: 'Оновити',
        createnew: 'Створити нову',
        audio: 'Аудіо',
        image: 'Зображення',
        order: 'Порядок (натискайте послідовно)',
        easy: 'Легко',
        normal: 'Нормально',
        hard: 'Важко',
        dashboard_total_cards: 'Всього створено карток',
        dashboard_active_vocab: 'Активний словник',
        dashboard_streak: 'Поточна серія (днів)',
        dashboard_stage_chart: 'Розподіл карток за етапами',
        dashboard_activity_chart: 'Активність перегляду (останні 7 днів)',
        dashboard_achievements: 'Досягнення',
        achievement_week_warrior: 'Воїн тижня (7-денна серія)',
        achievement_level_a0: 'Рівень A0 - Початківець',
        achievement_level_a1: 'Рівень A1 - Елементарний',
        achievement_level_a2: 'Рівень A2 - Базовий',
        achievement_level_b1: 'Рівень B1 - Середній',
        achievement_level_b2: 'Рівень B2 - Вище середнього',
        save: 'Зберегти',
        skip: 'Пропустити',
        showmore: 'Показати більше',
        front_audio_badge: 'Аудіо лицьової сторони',
        focus_audio_badge: 'Фокусне аудіо',
        front_placeholder: 'Jeg elsker deg',
        ai_click_hint: 'Натисніть будь-яке слово вище, щоб виявити сталий вираз',
        translation_en_placeholder: 'I love you',
        translation_placeholder: 'Jeg elsker deg',
        explanation_placeholder: 'Пояснення...',
        focus_placeholder: 'Фокусне слово/фраза...',
        collocations_placeholder: 'словосполучення...',
        examples_placeholder: 'приклади...',
        antonyms_placeholder: 'антоніми...',
        cognates_placeholder: 'споріднені слова...',
        sayings_placeholder: 'вислови...',
        transcription_placeholder: '[МФА напр. /hu:s/]',
        one_per_line_placeholder: 'по одному на рядок...'
      },
      ru: {
        app_title: 'MyMemory',
        interface_language_label: 'Язык интерфейса',
        font_scale_label: 'Размер шрифта',
        tab_quickinput: 'Создать новую карточку',
        tab_study: 'Обучение',
        tab_dashboard: 'Панель',
        quick_audio: 'Записать аудио',
        quick_photo: 'Сделать фото',
        choosefile: 'Выбрать файл',
        chooseaudiofile: 'Выбрать аудиофайл',
        tts_voice: 'Голос',
        tts_voice_hint: 'Выберите голос перед тем, как попросить AI помощника сгенерировать аудио.',
        front: 'Лицевая сторона карточки',
        front_translation_toggle_show: 'Показать перевод',
        front_translation_toggle_hide: 'Скрыть перевод',
        front_translation_mode_label: 'Направление перевода',
        front_translation_mode_hint: 'Нажмите, чтобы изменить языки ввода/вывода',
        front_translation_status_idle: 'Перевод готов',
        front_translation_copy: 'Копировать перевод',
        focus_translation_label: 'Фокусное значение',
        fokus: 'Фокусное слово/фраза',
        focus_baseform: 'Базовая форма',
        focus_baseform_ph: 'Лемма или инфинитив (необязательно)',
        ai_helper_label: 'AI помощник фокуса',
        ai_click_hint: 'Нажмите любое слово выше, чтобы выявить устойчивое выражение',
        ai_no_text: 'Введите предложение, чтобы включить помощника',
        explanation: 'Объяснение',
        back: 'Перевод',
        back_en: 'Перевод (английский)',
        save: 'Сохранить',
        cancel: 'Отмена',
        show_advanced: 'Показать дополнительные ▼',
        hide_advanced: 'Скрыть дополнительные ▲',
        empty: 'Сегодня ничего не запланировано',
        transcription: 'Транскрипция',
        pos: 'Часть речи',
        gender: 'Род',
        noun_forms: 'Формы существительного',
        verb_forms: 'Формы глагола',
        adj_forms: 'Формы прилагательного',
        collocations: 'Общие словосочетания',
        examples: 'Примеры предложений',
        antonyms: 'Антонимы',
        cognates: 'Родственные слова',
        sayings: 'Общие выражения',
        fill_field: 'Пожалуйста, заполните: {$a}',
        update: 'Обновить',
        createnew: 'Создать новую',
        audio: 'Аудио',
        image: 'Изображение',
        order: 'Порядок (нажимайте последовательно)',
        easy: 'Легко',
        normal: 'Нормально',
        hard: 'Сложно',
        dashboard_total_cards: 'Всего создано карточек',
        dashboard_active_vocab: 'Активный словарь',
        dashboard_streak: 'Текущая серия (дней)',
        dashboard_stage_chart: 'Распределение карточек по этапам',
        dashboard_activity_chart: 'Активность просмотра (последние 7 дней)',
        dashboard_achievements: 'Достижения',
        achievement_week_warrior: 'Воин недели (7-дневная серия)',
        achievement_level_a0: 'Уровень A0 - Начинающий',
        achievement_level_a1: 'Уровень A1 - Элементарный',
        achievement_level_a2: 'Уровень A2 - Базовый',
        achievement_level_b1: 'Уровень B1 - Средний',
        achievement_level_b2: 'Уровень B2 - Выше среднего',
        save: 'Сохранить',
        skip: 'Пропустить',
        showmore: 'Показать больше',
        front_audio_badge: 'Аудио лицевой стороны',
        focus_audio_badge: 'Фокусное аудио',
        front_placeholder: 'Jeg elsker deg',
        ai_click_hint: 'Нажмите любое слово выше, чтобы выявить устойчивое выражение',
        translation_en_placeholder: 'I love you',
        translation_placeholder: 'Jeg elsker deg',
        explanation_placeholder: 'Объяснение...',
        focus_placeholder: 'Фокусное слово/фраза...',
        collocations_placeholder: 'словосочетания...',
        examples_placeholder: 'примеры...',
        antonyms_placeholder: 'антонимы...',
        cognates_placeholder: 'родственные слова...',
        sayings_placeholder: 'выражения...',
        transcription_placeholder: '[МФА напр. /hu:s/]',
        one_per_line_placeholder: 'по одному на строку...'
      },
      fr: {
        app_title: 'MyMemory',
        interface_language_label: 'Langue de l\'interface',
        font_scale_label: 'Taille du texte',
        tab_quickinput: 'Créer une nouvelle carte',
        tab_study: 'Étudier',
        tab_dashboard: 'Tableau de bord',
        quick_audio: 'Enregistrer l\'audio',
        quick_photo: 'Prendre une photo',
        choosefile: 'Choisir un fichier',
        chooseaudiofile: 'Choisir un fichier audio',
        tts_voice: 'Voix',
        tts_voice_hint: 'Sélectionnez une voix avant de demander à l\'assistant IA de générer l\'audio.',
        front: 'Recto',
        front_translation_toggle_show: 'Afficher la traduction',
        front_translation_toggle_hide: 'Masquer la traduction',
        front_translation_mode_label: 'Direction de traduction',
        front_translation_mode_hint: 'Appuyez pour changer les langues d\'entrée/sortie',
        front_translation_status_idle: 'Traduction prête',
        front_translation_copy: 'Copier la traduction',
        focus_translation_label: 'Signification focale',
        fokus: 'Mot/phrase focal',
        focus_baseform: 'Forme de base',
        focus_baseform_ph: 'Lemme ou infinitif (facultatif)',
        ai_helper_label: 'Assistant IA focal',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour détecter une expression figée',
        ai_no_text: 'Saisissez une phrase pour activer l\'assistant',
        explanation: 'Explication',
        back: 'Traduction',
        save: 'Enregistrer',
        cancel: 'Annuler',
        show_advanced: 'Afficher avancé ▼',
        hide_advanced: 'Masquer avancé ▲',
        empty: 'Rien prévu aujourd\'hui',
        transcription: 'Transcription',
        pos: 'Partie du discours',
        gender: 'Genre',
        noun_forms: 'Formes du nom',
        verb_forms: 'Formes du verbe',
        adj_forms: 'Formes de l\'adjectif',
        collocations: 'Collocations courantes',
        examples: 'Exemples de phrases',
        antonyms: 'Antonymes',
        cognates: 'Mots apparentés',
        sayings: 'Expressions courantes',
        fill_field: 'Veuillez remplir : {$a}',
        update: 'Mettre à jour',
        createnew: 'Créer nouveau',
        audio: 'Audio',
        image: 'Image',
        order: 'Ordre (cliquer en séquence)',
        easy: 'Facile',
        normal: 'Normal',
        hard: 'Difficile',
        dashboard_total_cards: 'Total de cartes créées',
        dashboard_active_vocab: 'Vocabulaire actif',
        dashboard_streak: 'Série actuelle (jours)',
        dashboard_stage_chart: 'Distribution des étapes de cartes',
        dashboard_activity_chart: 'Activité de révision (7 derniers jours)',
        dashboard_achievements: 'Réalisations',
        achievement_week_warrior: 'Guerrier de la semaine (série de 7 jours)',
        achievement_level_a0: 'Niveau A0 - Débutant',
        achievement_level_a1: 'Niveau A1 - Élémentaire',
        achievement_level_a2: 'Niveau A2 - Pré-intermédiaire',
        achievement_level_b1: 'Niveau B1 - Intermédiaire',
        achievement_level_b2: 'Niveau B2 - Intermédiaire supérieur',
        save: 'Enregistrer',
        skip: 'Passer',
        showmore: 'Afficher plus',
        front_audio_badge: 'Audio du recto',
        focus_audio_badge: 'Audio focal',
        front_placeholder: 'Recto...',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour détecter une expression figée',
        translation_en_placeholder: 'Traduction (anglais)...',
        translation_placeholder: 'Traduction...',
        explanation_placeholder: 'Explication...',
        focus_placeholder: 'Mot/phrase focus...',
        collocations_placeholder: 'collocations...',
        examples_placeholder: 'exemples...',
        antonyms_placeholder: 'antonymes...',
        cognates_placeholder: 'mots apparentés...',
        sayings_placeholder: 'expressions...',
        transcription_placeholder: '[API par ex. /hu:s/]',
        one_per_line_placeholder: 'un par ligne...'
      },
      es: {
        app_title: 'MyMemory',
        interface_language_label: 'Idioma de la interfaz',
        font_scale_label: 'Tamaño de fuente',
        tab_quickinput: 'Crear nueva tarjeta',
        tab_study: 'Estudiar',
        tab_dashboard: 'Panel',
        quick_audio: 'Grabar audio',
        quick_photo: 'Tomar foto',
        choosefile: 'Elegir archivo',
        chooseaudiofile: 'Elegir archivo de audio',
        tts_voice: 'Voz',
        tts_voice_hint: 'Selecciona una voz antes de pedir al asistente IA que genere audio.',
        front: 'Anverso',
        front_translation_toggle_show: 'Mostrar traducción',
        front_translation_toggle_hide: 'Ocultar traducción',
        front_translation_mode_label: 'Dirección de traducción',
        front_translation_mode_hint: 'Toca para cambiar los idiomas de entrada/salida',
        front_translation_status_idle: 'Traducción lista',
        front_translation_copy: 'Copiar traducción',
        focus_translation_label: 'Significado focal',
        fokus: 'Palabra/frase focal',
        focus_baseform: 'Forma base',
        focus_baseform_ph: 'Lema o infinitivo (opcional)',
        ai_helper_label: 'Asistente IA focal',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresión fija',
        ai_no_text: 'Escribe una oración para activar el asistente',
        explanation: 'Explicación',
        back: 'Traducción',
        save: 'Guardar',
        cancel: 'Cancelar',
        show_advanced: 'Mostrar avanzado ▼',
        hide_advanced: 'Ocultar avanzado ▲',
        empty: 'Nada pendiente hoy',
        transcription: 'Transcripción',
        pos: 'Categoría gramatical',
        gender: 'Género',
        noun_forms: 'Formas del sustantivo',
        verb_forms: 'Formas del verbo',
        adj_forms: 'Formas del adjetivo',
        collocations: 'Colocaciones comunes',
        examples: 'Oraciones de ejemplo',
        antonyms: 'Antónimos',
        cognates: 'Cognados',
        sayings: 'Expresiones comunes',
        fill_field: 'Por favor, complete: {$a}',
        update: 'Actualizar',
        createnew: 'Crear nueva',
        audio: 'Audio',
        image: 'Imagen',
        order: 'Orden (hacer clic en secuencia)',
        easy: 'Fácil',
        normal: 'Normal',
        hard: 'Difícil',
        dashboard_total_cards: 'Total de tarjetas creadas',
        dashboard_active_vocab: 'Vocabulario activo',
        dashboard_streak: 'Racha actual (días)',
        dashboard_stage_chart: 'Distribución de etapas de tarjetas',
        dashboard_activity_chart: 'Actividad de revisión (últimos 7 días)',
        dashboard_achievements: 'Logros',
        achievement_week_warrior: 'Guerrero de la semana (racha de 7 días)',
        achievement_level_a0: 'Nivel A0 - Principiante',
        achievement_level_a1: 'Nivel A1 - Elemental',
        achievement_level_a2: 'Nivel A2 - Pre-intermedio',
        achievement_level_b1: 'Nivel B1 - Intermedio',
        achievement_level_b2: 'Nivel B2 - Intermedio superior',
        save: 'Guardar',
        skip: 'Omitir',
        showmore: 'Mostrar más',
        front_audio_badge: 'Audio del anverso',
        focus_audio_badge: 'Audio focal',
        front_placeholder: 'Anverso...',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresión fija',
        translation_en_placeholder: 'Traducción (inglés)...',
        translation_placeholder: 'Traducción...',
        explanation_placeholder: 'Explicación...',
        focus_placeholder: 'Palabra/frase focal...',
        collocations_placeholder: 'colocaciones...',
        examples_placeholder: 'ejemplos...',
        antonyms_placeholder: 'antónimos...',
        cognates_placeholder: 'cognados...',
        sayings_placeholder: 'expresiones...',
        transcription_placeholder: '[AFI ej. /hu:s/]',
        one_per_line_placeholder: 'uno por línea...'
      },
      pl: {
        app_title: 'MyMemory',
        interface_language_label: 'Język interfejsu',
        font_scale_label: 'Rozmiar czcionki',
        tab_quickinput: 'Utwórz nową fiszkę',
        tab_study: 'Nauka',
        tab_dashboard: 'Panel',
        quick_audio: 'Nagraj audio',
        quick_photo: 'Zrób zdjęcie',
        choosefile: 'Wybierz plik',
        chooseaudiofile: 'Wybierz plik audio',
        tts_voice: 'Głos',
        tts_voice_hint: 'Wybierz głos przed poproszeniem asystenta AI o wygenerowanie audio.',
        front: 'Przód',
        front_translation_toggle_show: 'Pokaż tłumaczenie',
        front_translation_toggle_hide: 'Ukryj tłumaczenie',
        front_translation_mode_label: 'Kierunek tłumaczenia',
        front_translation_mode_hint: 'Dotknij, aby zmienić języki wejścia/wyjścia',
        front_translation_status_idle: 'Tłumaczenie gotowe',
        front_translation_copy: 'Kopiuj tłumaczenie',
        focus_translation_label: 'Fokusowe znaczenie',
        fokus: 'Słowo/fraza fokusowa',
        focus_baseform: 'Forma podstawowa',
        focus_baseform_ph: 'Lemat lub bezokolicznik (opcjonalnie)',
        ai_helper_label: 'Asystent AI fokusa',
        ai_click_hint: 'Dotknij dowolnego słowa powyżej, aby wykryć stałe wyrażenie',
        ai_no_text: 'Wpisz zdanie, aby włączyć asystenta',
        explanation: 'Wyjaśnienie',
        back: 'Tłumaczenie',
        save: 'Zapisz',
        cancel: 'Anuluj',
        show_advanced: 'Pokaż zaawansowane ▼',
        hide_advanced: 'Ukryj zaawansowane ▲',
        empty: 'Nic do nauki dzisiaj',
        transcription: 'Transkrypcja',
        pos: 'Część mowy',
        gender: 'Rodzaj',
        noun_forms: 'Formy rzeczownika',
        verb_forms: 'Formy czasownika',
        adj_forms: 'Formy przymiotnika',
        collocations: 'Typowe kolokacje',
        examples: 'Przykładowe zdania',
        antonyms: 'Antonimy',
        cognates: 'Wyrazy pokrewne',
        sayings: 'Powszechne wyrażenia',
        fill_field: 'Proszę wypełnić: {$a}',
        update: 'Aktualizuj',
        createnew: 'Utwórz nową',
        audio: 'Audio',
        image: 'Obraz',
        order: 'Kolejność (klikaj po kolei)',
        easy: 'Łatwe',
        normal: 'Normalne',
        hard: 'Trudne',
        dashboard_total_cards: 'Łączna liczba utworzonych fiszek',
        dashboard_active_vocab: 'Aktywne słownictwo',
        dashboard_streak: 'Obecna seria (dni)',
        dashboard_stage_chart: 'Rozkład etapów fiszek',
        dashboard_activity_chart: 'Aktywność przeglądu (ostatnie 7 dni)',
        dashboard_achievements: 'Osiągnięcia',
        achievement_week_warrior: 'Wojownik tygodnia (7-dniowa seria)',
        achievement_level_a0: 'Poziom A0 - Początkujący',
        achievement_level_a1: 'Poziom A1 - Elementarny',
        achievement_level_a2: 'Poziom A2 - Podstawowy',
        achievement_level_b1: 'Poziom B1 - Średniozaawansowany',
        achievement_level_b2: 'Poziom B2 - Zaawansowany średni',
        save: 'Zapisz',
        skip: 'Pomiń',
        showmore: 'Pokaż więcej',
        front_audio_badge: 'Audio przodu',
        focus_audio_badge: 'Audio fokusowe',
        front_placeholder: 'Przód...',
        ai_click_hint: 'Dotknij dowolnego słowa powyżej, aby wykryć stałe wyrażenie',
        translation_en_placeholder: 'Tłumaczenie (angielski)...',
        translation_placeholder: 'Tłumaczenie...',
        explanation_placeholder: 'Wyjaśnienie...',
        focus_placeholder: 'Słowo/fraza fokusowa...',
        collocations_placeholder: 'kolokacje...',
        examples_placeholder: 'przykłady...',
        antonyms_placeholder: 'antonimy...',
        cognates_placeholder: 'wyrazy pokrewne...',
        sayings_placeholder: 'wyrażenia...',
        transcription_placeholder: '[IPA np. /hu:s/]',
        one_per_line_placeholder: 'po jednym w wierszu...'
      },
      it: {
        app_title: 'MyMemory',
        interface_language_label: 'Lingua dell\'interfaccia',
        font_scale_label: 'Dimensione del testo',
        tab_quickinput: 'Crea nuova scheda',
        tab_study: 'Studiare',
        tab_dashboard: 'Pannello',
        quick_audio: 'Registra audio',
        quick_photo: 'Scatta foto',
        choosefile: 'Scegli file',
        chooseaudiofile: 'Scegli file audio',
        tts_voice: 'Voce',
        tts_voice_hint: 'Seleziona una voce prima di chiedere all\'assistente IA di generare l\'audio.',
        front: 'Fronte',
        front_translation_toggle_show: 'Mostra traduzione',
        front_translation_toggle_hide: 'Nascondi traduzione',
        front_translation_mode_label: 'Direzione di traduzione',
        front_translation_mode_hint: 'Tocca per cambiare le lingue di input/output',
        front_translation_status_idle: 'Traduzione pronta',
        front_translation_copy: 'Copia traduzione',
        focus_translation_label: 'Significato focale',
        fokus: 'Parola/frase focale',
        focus_baseform: 'Forma base',
        focus_baseform_ph: 'Lemma o infinito (opzionale)',
        ai_helper_label: 'Assistente IA focale',
        ai_click_hint: 'Tocca qualsiasi parola sopra per rilevare un\'espressione fissa',
        ai_no_text: 'Digita una frase per attivare l\'assistente',
        explanation: 'Spiegazione',
        back: 'Traduzione',
        save: 'Salva',
        cancel: 'Annulla',
        show_advanced: 'Mostra avanzate ▼',
        hide_advanced: 'Nascondi avanzate ▲',
        empty: 'Niente da fare oggi',
        transcription: 'Trascrizione',
        pos: 'Parte del discorso',
        gender: 'Genere',
        noun_forms: 'Forme del nome',
        verb_forms: 'Forme del verbo',
        adj_forms: 'Forme dell\'aggettivo',
        collocations: 'Collocazioni comuni',
        examples: 'Frasi di esempio',
        antonyms: 'Antonimi',
        cognates: 'Parole affini',
        sayings: 'Espressioni comuni',
        fill_field: 'Si prega di compilare: {$a}',
        update: 'Aggiorna',
        createnew: 'Crea nuova',
        audio: 'Audio',
        image: 'Immagine',
        order: 'Ordine (clicca in sequenza)',
        easy: 'Facile',
        normal: 'Normale',
        hard: 'Difficile',
        dashboard_total_cards: 'Totale schede create',
        dashboard_active_vocab: 'Vocabolario attivo',
        dashboard_streak: 'Serie attuale (giorni)',
        dashboard_stage_chart: 'Distribuzione fasi schede',
        dashboard_activity_chart: 'Attività di ripasso (ultimi 7 giorni)',
        dashboard_achievements: 'Traguardi',
        achievement_week_warrior: 'Guerriero della settimana (serie di 7 giorni)',
        achievement_level_a0: 'Livello A0 - Principiante',
        achievement_level_a1: 'Livello A1 - Elementare',
        achievement_level_a2: 'Livello A2 - Pre-intermedio',
        achievement_level_b1: 'Livello B1 - Intermedio',
        achievement_level_b2: 'Livello B2 - Intermedio superiore',
        save: 'Salva',
        skip: 'Salta',
        showmore: 'Mostra altro',
        front_audio_badge: 'Audio fronte',
        focus_audio_badge: 'Audio focale',
        front_placeholder: 'Fronte...',
        ai_click_hint: 'Tocca qualsiasi parola sopra per rilevare un\'espressione fissa',
        translation_en_placeholder: 'Traduzione (inglese)...',
        translation_placeholder: 'Traduzione...',
        explanation_placeholder: 'Spiegazione...',
        focus_placeholder: 'Parola/frase focale...',
        collocations_placeholder: 'collocazioni...',
        examples_placeholder: 'esempi...',
        antonyms_placeholder: 'antonimi...',
        cognates_placeholder: 'parole affini...',
        sayings_placeholder: 'espressioni...',
        transcription_placeholder: '[IPA es. /hu:s/]',
        one_per_line_placeholder: 'uno per riga...'
      },
      de: {
        app_title: 'MyMemory',
        interface_language_label: 'Sprache der Oberfläche',
        font_scale_label: 'Schriftgröße',
        tab_quickinput: 'Neue Karte erstellen',
        tab_study: 'Studieren',
        tab_dashboard: 'Dashboard',
        quick_audio: 'Audio aufnehmen',
        quick_photo: 'Foto aufnehmen',
        choosefile: 'Datei auswählen',
        chooseaudiofile: 'Audiodatei auswählen',
        tts_voice: 'Stimme',
        tts_voice_hint: 'Wählen Sie eine Stimme aus, bevor Sie den KI-Assistenten bitten, Audio zu generieren.',
        front: 'Vorderseite',
        front_translation_toggle_show: 'Übersetzung anzeigen',
        front_translation_toggle_hide: 'Übersetzung ausblenden',
        front_translation_mode_label: 'Übersetzungsrichtung',
        front_translation_mode_hint: 'Tippen Sie, um die Eingabe-/Ausgabesprachen zu ändern',
        front_translation_status_idle: 'Übersetzung bereit',
        front_translation_copy: 'Übersetzung kopieren',
        focus_translation_label: 'Fokusbedeutung',
        fokus: 'Fokuswort/-phrase',
        focus_baseform: 'Grundform',
        focus_baseform_ph: 'Lemma oder Infinitiv (optional)',
        ai_helper_label: 'KI-Fokus-Assistent',
        ai_click_hint: 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen',
        ai_no_text: 'Geben Sie einen Satz ein, um den Assistenten zu aktivieren',
        explanation: 'Erklärung',
        back: 'Übersetzung',
        save: 'Speichern',
        cancel: 'Abbrechen',
        show_advanced: 'Erweitert anzeigen ▼',
        hide_advanced: 'Erweitert ausblenden ▲',
        empty: 'Heute nichts fällig',
        transcription: 'Transkription',
        pos: 'Wortart',
        gender: 'Geschlecht',
        noun_forms: 'Substantivformen',
        verb_forms: 'Verbformen',
        adj_forms: 'Adjektivformen',
        collocations: 'Häufige Kollokationen',
        examples: 'Beispielsätze',
        antonyms: 'Antonyme',
        cognates: 'Verwandte Wörter',
        sayings: 'Häufige Redewendungen',
        fill_field: 'Bitte ausfüllen: {$a}',
        update: 'Aktualisieren',
        createnew: 'Neue erstellen',
        audio: 'Audio',
        image: 'Bild',
        order: 'Reihenfolge (in Reihenfolge klicken)',
        easy: 'Einfach',
        normal: 'Normal',
        hard: 'Schwer',
        dashboard_total_cards: 'Gesamt erstellte Karten',
        dashboard_active_vocab: 'Aktiver Wortschatz',
        dashboard_streak: 'Aktuelle Serie (Tage)',
        dashboard_stage_chart: 'Kartenstufen-Verteilung',
        dashboard_activity_chart: 'Überprüfungsaktivität (letzte 7 Tage)',
        dashboard_achievements: 'Erfolge',
        achievement_week_warrior: 'Wochenkrieger (7-Tages-Serie)',
        achievement_level_a0: 'Niveau A0 - Anfänger',
        achievement_level_a1: 'Niveau A1 - Grundstufe',
        achievement_level_a2: 'Niveau A2 - Untere Mittelstufe',
        achievement_level_b1: 'Niveau B1 - Mittelstufe',
        achievement_level_b2: 'Niveau B2 - Obere Mittelstufe',
        save: 'Speichern',
        skip: 'Überspringen',
        showmore: 'Mehr anzeigen',
        front_audio_badge: 'Vorderseiten-Audio',
        focus_audio_badge: 'Fokus-Audio',
        front_placeholder: 'Vorderseite...',
        ai_click_hint: 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen',
        translation_en_placeholder: 'Übersetzung (Englisch)...',
        translation_placeholder: 'Übersetzung...',
        explanation_placeholder: 'Erklärung...',
        focus_placeholder: 'Fokus Wort/Phrase...',
        collocations_placeholder: 'Kollokationen...',
        examples_placeholder: 'Beispiele...',
        antonyms_placeholder: 'Antonyme...',
        cognates_placeholder: 'Verwandte Wörter...',
        sayings_placeholder: 'Redewendungen...',
        transcription_placeholder: '[IPA z.B. /hu:s/]',
        one_per_line_placeholder: 'eines pro Zeile...'
      }
    };

    // Get interface string in current interface language
    
    function t(key){
      const lang = currentInterfaceLang || 'en';
      const translations = interfaceTranslations[lang] || interfaceTranslations.en;
      return translations[key] || interfaceTranslations.en[key] || key;
    }

    // Update interface elements with translations
    function updateInterfaceTexts(){
      // Update elements by ID (legacy method)
      const els = {
        't_appTitle': 'app_title',
        't_tab_quickinput': 'tab_quickinput',
        't_tab_study': 'tab_study',
        't_tab_dashboard': 'tab_dashboard'
      };
      Object.entries(els).forEach(function(pair){
        const id = pair[0];
        const key = pair[1];
        const el = document.getElementById(id);
        if(el){
          el.textContent = t(key);
        }
      });

      // Update ALL elements with data-i18n attribute
      const i18nElements = document.querySelectorAll('[data-i18n]');
      i18nElements.forEach(function(el){
        const key = el.getAttribute('data-i18n');
        if(key && interfaceTranslations[currentInterfaceLang || 'en'][key]){
          el.textContent = t(key);
        }
      });

      // Update placeholder attributes
      const i18nPlaceholders = document.querySelectorAll('[data-i18n-placeholder]');
      i18nPlaceholders.forEach(function(el){
        const key = el.getAttribute('data-i18n-placeholder');
        if(key && interfaceTranslations[currentInterfaceLang || 'en'][key]){
          el.placeholder = t(key);
        }
      });

      // Update title attributes
      const i18nTitles = document.querySelectorAll('[data-i18n-title]');
      i18nTitles.forEach(function(el){
        const key = el.getAttribute('data-i18n-title');
        if(key && interfaceTranslations[currentInterfaceLang || 'en'][key]){
          el.title = t(key);
        }
      });

      // Update aiStrings object dynamically
      aiStrings.notext = t('ai_no_text');
      aiStrings.translationIdle = t('front_translation_status_idle');
      aiStrings.frontTransShow = t('front_translation_toggle_show');
      aiStrings.frontTransHide = t('front_translation_toggle_hide');
      aiStrings.frontAudio = t('front_audio_badge');
      aiStrings.focusAudio = t('focus_audio_badge');
      aiStrings.click = t('ai_click_hint');

      // Update focus hint with new language
      if(typeof updateFocusHint === 'function'){
        updateFocusHint();
      }
      if(typeof updateTranslationModeLabels === 'function'){
        updateTranslationModeLabels();
      }
      // Update order preview with translated chip names
      if(typeof updateOrderPreview === 'function'){
        updateOrderPreview();
      }
      // Update focus helper status text with new language
      if(typeof setFocusStatus === 'function'){
        const focusStatusEl = document.getElementById('focusHelperStatus');
        if(focusStatusEl && focusStatusEl.dataset.state === 'idle'){
          setFocusStatus('idle', aiStrings.notext);
        }
      }
      // Also update the empty state span if it exists
      const emptySpan = document.querySelector('.focus-helper-empty');
      if(emptySpan){
        emptySpan.textContent = aiStrings.notext;
      }
    }
    // ========== END INTERFACE LANGUAGE SYSTEM ==========

    const savedInterfaceLang = getSavedInterfaceLang();
    const savedTransLang = getSavedTransLang();
    // Priority: saved translation lang > saved interface lang > page/Moodle lang > browser
    const rawLang = savedTransLang || savedInterfaceLang || pageLang() || (window.M && M.cfg && M.cfg.lang) || (navigator.language || 'en');
    const userLang = (rawLang || 'en').toLowerCase();
    let userLang2 = userLang.split(/[\-_]/)[0] || 'en';
    
    // Initialize interface language (use saved interface lang or fallback to translation lang)
    let currentInterfaceLang = savedInterfaceLang || userLang2;

    const voiceSelectEl = document.getElementById('ttsVoice');
    const voiceSlotEl = document.getElementById('slot_ttsVoice');
    const voiceStatusEl = document.getElementById('ttsVoiceStatus');
    const setTtsStatus = (text, state) => {
      if(!voiceStatusEl) return;
      voiceStatusEl.textContent = text || '';
      voiceStatusEl.dataset.state = state || '';
    };
    if(voiceSelectEl){
      if(voiceOptionsRaw.length){
        const placeholderOpt=document.createElement('option');
        placeholderOpt.value='';
        placeholderOpt.textContent = aiStrings.voicePlaceholder;
        voiceSelectEl.appendChild(placeholderOpt);
        voiceOptionsRaw.forEach(opt=>{
          if(!opt || !opt.voice) return;
          const option=document.createElement('option');
          option.value = opt.voice;
          option.textContent = opt.label || opt.voice;
          voiceSelectEl.appendChild(option);
        });
        // Set default voice after DOM updates (ensure visual selection)
        requestAnimationFrame(() => {
          if(selectedVoice){
            voiceSelectEl.value = selectedVoice;
          }
        });
        voiceSelectEl.addEventListener('change', ()=>{ selectedVoice = voiceSelectEl.value; setTtsStatus('', ''); });
        if(voiceSlotEl) voiceSlotEl.classList.remove('hidden');
        if(!aiConfig.ttsEnabled){
          setTtsStatus(aiStrings.voiceDisabled, 'warn');
        }
      } else {
        if(voiceSlotEl) voiceSlotEl.classList.add('hidden');
        setTtsStatus(aiStrings.voiceMissing, 'warn');
      }
    } else if(voiceSlotEl){
      voiceSlotEl.classList.add('hidden');
    }

    function languageName(code){
      const c = (code||'').toLowerCase();
      const map = {
        en:'English', no:'Norsk', nb:'Norsk', nn:'Nynorsk',
        uk:'Українська', ru:'Русский', pl:'Polski', de:'Deutsch', fr:'Français', es:'Español', it:'Italiano'
      };
      return map[c] || c.toUpperCase();
    }

    function setFrontTranslationVisibility(visible){
      frontTranslationVisible = !!visible;
      if(frontTranslationSlot){
        frontTranslationSlot.classList.toggle('collapsed', !frontTranslationVisible);
        frontTranslationSlot.setAttribute('aria-hidden', frontTranslationVisible ? 'false' : 'true');
      }
      updateFrontTranslationToggleState();
    }

    function updateFrontTranslationToggleState(){
      if(!frontTranslationToggle){
        return;
      }
      const langLabel = languageName(userLang2);
      const base = frontTranslationVisible ? t('front_translation_toggle_hide') : t('front_translation_toggle_show');
      frontTranslationToggle.textContent = `${base} (${langLabel})`;
      frontTranslationToggle.setAttribute('aria-expanded', frontTranslationVisible ? 'true' : 'false');
    }

    // Function to update translation language UI
    function updateTranslationLangUI(){
      try {
        const slotLocal = $("#slot_translation_local");
        const slotEn = $("#slot_translation_en");
        const tagLocal = $("#tag_trans_local");
        if(tagLocal){ tagLocal.innerHTML = `Translation (${languageName(userLang2)}) <span id="langChangeHint" style="font-size:0.8em;opacity:0.6;cursor:pointer;" title="Click to change translation language">??</span>`; }
        if(userLang2 === 'en'){
          if(slotLocal) slotLocal.classList.add('hidden');
          if(slotEn) slotEn.classList.remove('hidden');
          if(frontTranslationToggle){ frontTranslationToggle.classList.add('hidden'); }
          setFrontTranslationVisibility(false);
        } else {
          if(slotLocal) slotLocal.classList.remove('hidden');
          if(slotEn) slotEn.classList.remove('hidden');
          if(frontTranslationToggle){ frontTranslationToggle.classList.remove('hidden'); }
          updateFrontTranslationToggleState();
        }

        // Add click handler for language change hint
        const langChangeHint = document.getElementById('langChangeHint');
        if(langChangeHint && !langChangeHint.dataset.bound){
          langChangeHint.dataset.bound = '1';
          langChangeHint.addEventListener('click', showLanguageSelector);
        }
        updateTranslationModeLabels();
        scheduleTranslationRefresh();
      } catch(_e){}
    }
    // Show language selector dialog
    function showLanguageSelector(){
      const languages = [
        {code: 'uk', name: 'Українська'},
        {code: 'en', name: 'English'},
        {code: 'ru', name: 'Русский'},
        {code: 'pl', name: 'Polski'},
        {code: 'de', name: 'Deutsch'},
        {code: 'fr', name: 'Français'},
        {code: 'es', name: 'Español'},
        {code: 'it', name: 'Italiano'}
      ];

      const currentLang = userLang2;
      const msg = `Current translation language: ${languageName(currentLang)}\n\nSelect new translation language:\n\n` +
        languages.map((l, i) => `${i+1}. ${l.name} ${l.code === currentLang ? '(current)' : ''}`).join('\n');

      const choice = prompt(msg + '\n\nEnter number (1-' + languages.length + ') or cancel to keep current:');

      if(choice){
        const index = parseInt(choice, 10) - 1;
        if(index >= 0 && index < languages.length){
          const newLang = languages[index].code;
          if(newLang !== currentLang){
            userLang2 = newLang;
            saveTransLang(newLang);
            updateTranslationLangUI();
            alert(`Translation language changed to ${languages[index].name}.\n\nNew cards will be created with ${languages[index].name} translations.\nExisting cards will show ${languages[index].name} translations if available.`);
          }
        }
      }
    }

    function updateTranslationModeLabels(){
      if(!translationForwardLabel && !translationReverseLabel){
        return;
      }
      const langLabel = languageName(userLang2);
      const norwegianLabel = languageName('no');
      if(translationForwardLabel){
        translationForwardLabel.textContent = `${norwegianLabel} → ${langLabel}`;
      }
      if(translationReverseLabel){
        translationReverseLabel.textContent = `${langLabel} → ${norwegianLabel}`;
      }
    }

    function setTranslationPreview(text, state, custom){
      if(frontTranslationOutput){
        const clean = (text || '').trim();
        frontTranslationOutput.textContent = clean || '�';
      }
      if(frontTranslationStatus){
        const label = custom || (state === 'loading' ? aiStrings.translationLoading :
          (state === 'error' ? aiStrings.translationError : aiStrings.translationIdle));
        frontTranslationStatus.dataset.state = state || '';
        frontTranslationStatus.textContent = label;
      }
    }

    function setFocusTranslation(text){
      if(!focusTranslationText){
        return;
      }
      const clean = (text || '').trim();
      focusTranslationText.textContent = clean || '�';
    }

    function applyTranslationDirection(dir){
      translationDirection = dir === 'user-no' ? 'user-no' : 'no-user';
      if(translationDirectionEl){
        translationDirectionEl.querySelectorAll('button').forEach(btn=>{
          btn.classList.toggle('active', btn.dataset.dir === translationDirection);
        });
      }
      if(translationModeHint){
        translationModeHint.textContent = (translationDirection === 'user-no')
          ? aiStrings.translationReverseHint
          : (translationHintDefault || aiStrings.translationIdle);
      }
      if(translationDirection === 'user-no'){
        setFrontTranslationVisibility(true);
        if(frontTranslationToggle){
          frontTranslationToggle.classList.add('hidden');
        }
      } else if(frontTranslationToggle){
        frontTranslationToggle.classList.remove('hidden');
      }
      scheduleTranslationRefresh();
    }

    function scheduleTranslationRefresh(){
      if(translationDebounce){
        clearTimeout(translationDebounce);
      }
      translationDebounce = setTimeout(runTranslationHelper, 650);
    }

    async function runTranslationHelper(){
      const sourceEl = translationDirection === 'user-no' ? translationInputLocal : frontInput;
      if(!sourceEl){
        return;
      }
      if(!aiEnabled){
        setTranslationPreview('', 'error', aiStrings.disabled);
        return;
      }
      const sourceText = (sourceEl.value || '').trim();
      if(sourceText.length < 2){
        if(translationDirection === 'user-no'){
          setTranslationPreview('', '', aiStrings.translationReverseHint);
        }else{
          setTranslationPreview('', '', aiStrings.translationIdle);
        }
        return;
      }
      if(translationAbortController){
        translationAbortController.abort();
      }
      const controller = new AbortController();
      translationAbortController = controller;
      const seq = ++translationRequestSeq;
      setTranslationPreview(frontTranslationOutput ? frontTranslationOutput.textContent : '', 'loading');
      const payload = {
        text: sourceText,
        sourceLang: translationDirection === 'user-no' ? userLang2 : 'no',
        targetLang: translationDirection === 'user-no' ? 'no' : userLang2,
        direction: translationDirection
      };
      try{
        const data = await api('ai_translate', {}, 'POST', payload, {signal: controller.signal});
        if(seq !== translationRequestSeq){
          return;
        }
        const translated = (data.translation || '').trim();
        if(translationDirection === 'no-user'){
          if(translationInputLocal){
            translationInputLocal.value = translated;
          }
          if(translationInputEn && userLang2 === 'en'){
            translationInputEn.value = translated;
          }
        }else if(frontInput){
          frontInput.value = translated;
          try{
            frontInput.dispatchEvent(new Event('input', {bubbles:true}));
          }catch(_e){}
        }
        setTranslationPreview(translated, 'success');
      }catch(err){
        if(controller.signal.aborted){
          return;
        }
        console.error('[Flashcards][AI] translation failed', err);
        setTranslationPreview('', 'error');
      }finally{
        if(translationAbortController === controller){
          translationAbortController = null;
        }
      }
    }

    // Prepare translation inputs visibility/labels
    // Dynamic Examples and Collocations Lists
    let examplesData = []; // Array of {no: "Norwegian text", trans: "Translation"}
    let collocationsData = [];

    function createItemElement(type, index, data) {
      const container = document.createElement('div');
      container.style.cssText = 'display: flex; flex-direction: column; gap: 6px; padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px;';
      container.dataset.index = index;

      const topRow = document.createElement('div');
      topRow.style.cssText = 'display: flex; justify-content: space-between; align-items: center;';

      const label = document.createElement('span');
      label.style.cssText = 'font-size: 0.9em; opacity: 0.7;';
      label.textContent = `${type} #${index + 1}`;

      const btnRemove = document.createElement('button');
      btnRemove.type = 'button';
      btnRemove.className = 'fc-link-btn';
      btnRemove.style.cssText = 'font-size: 0.85em; padding: 2px 6px; color: #ef4444;';
      btnRemove.textContent = '× Remove';
      btnRemove.addEventListener('click', () => {
        if(type === 'Example') {
          examplesData.splice(index, 1);
          renderExamples();
        } else {
          collocationsData.splice(index, 1);
          renderCollocations();
        }
      });

      topRow.appendChild(label);
      topRow.appendChild(btnRemove);
      container.appendChild(topRow);

      const inputNo = document.createElement('input');
      inputNo.type = 'text';
      inputNo.placeholder = 'Norwegian text...';
      inputNo.style.cssText = 'width: 100%; padding: 8px; background: #0b1220; color: #f1f5f9; border: 1px solid #374151; border-radius: 6px;';
      inputNo.value = data.no || '';
      inputNo.addEventListener('input', (e) => {
        if(type === 'Example') examplesData[index].no = e.target.value;
        else collocationsData[index].no = e.target.value;
      });
      container.appendChild(inputNo);

      const transRow = document.createElement('div');
      transRow.style.cssText = 'display: flex; align-items: center; gap: 8px;';

      const inputTrans = document.createElement('input');
      inputTrans.type = 'text';
      inputTrans.placeholder = `Translation (${languageName(userLang2)})...`;
      inputTrans.style.cssText = 'flex: 1; padding: 8px; background: #0b1220; color: #f1f5f9; border: 1px solid #374151; border-radius: 6px;';
      inputTrans.value = data.trans || '';
      inputTrans.classList.add('hidden');
      inputTrans.addEventListener('input', (e) => {
        if(type === 'Example') examplesData[index].trans = e.target.value;
        else collocationsData[index].trans = e.target.value;
      });

      const btnToggle = document.createElement('button');
      btnToggle.type = 'button';
      btnToggle.className = 'fc-link-btn';
      btnToggle.style.cssText = 'font-size: 0.85em; padding: 4px 8px;';
      btnToggle.textContent = '👁 Show/Hide';
      btnToggle.addEventListener('click', (e) => {
        e.preventDefault();
        inputTrans.classList.toggle('hidden');
      });

      transRow.appendChild(inputTrans);
      transRow.appendChild(btnToggle);
      container.appendChild(transRow);

      return container;
    }

    function renderExamples() {
      const list = $('#examplesList');
      if(!list) return;
      list.innerHTML = '';
      examplesData.forEach((item, idx) => {
        list.appendChild(createItemElement('Example', idx, item));
      });
    }

    function renderCollocations() {
      const list = $('#collocationsList');
      if(!list) return;
      list.innerHTML = '';
      collocationsData.forEach((item, idx) => {
        list.appendChild(createItemElement('Collocation', idx, item));
      });
    }

    const btnAddExample = $('#btnAddExample');
    if(btnAddExample) {
      btnAddExample.addEventListener('click', (e) => {
        e.preventDefault();
        examplesData.push({no: '', trans: ''});
        renderExamples();
      });
    }

    const btnAddCollocation = $('#btnAddCollocation');
    if(btnAddCollocation) {
      btnAddCollocation.addEventListener('click', (e) => {
        e.preventDefault();
        collocationsData.push({no: '', trans: ''});
        renderCollocations();
      });
    }

    // POS helper: compact option labels + info text
    const POS_INFO = {
      substantiv: '<i>Substantiv - navn pa steder, personer, ting og fenomener</i>',
      adjektiv: '<i>Adjektiv - beskriver substantiv</i>',
      pronomen: '<i>Pronomen - settes i stedet for et substantiv</i>',
      determinativ: '<i>Determinativ - bestemmer substantivet n?rmere</i>',
      verb: '<i>Verb - navn pa en handling</i>',
      adverb: '<i>Adverb - beskriver/modifiserer verb, adjektiv eller andre adverb</i>',
      preposisjon: '<i>Preposisjon - plassering i tid/rom i forhold til annet ord</i>',
      konjunksjon: '<i>Konjunksjon - binder sammen like ordledd eller helsetninger</i>',
      subjunksjon: '<i>Subjunksjon - innleder leddsetninger</i>',
      interjeksjon: '<i>Interjeksjon - lydmalende ord, folelser eller meninger</i>'
    };
    function compactPOSOptions(){
      const sel = document.getElementById('uPOS');
      if(!sel) return;
      Array.from(sel.options).forEach(o=>{
        const v=(o.value||'').toLowerCase();
        if(!v) return;
        const map={substantiv:'Substantiv',adjektiv:'Adjektiv',pronomen:'Pronomen',determinativ:'Determinativ',verb:'Verb',adverb:'Adverb',preposisjon:'Preposisjon',konjunksjon:'Konjunksjon',subjunksjon:'Subjunksjon',interjeksjon:'Interjeksjon',other:'Annet'};
        if(map[v]) o.textContent = map[v];
      });
    }
    function updatePOSHelp(){
      const sel = document.getElementById('uPOS');
      const help = document.getElementById('uPOSHelp');
      if(!sel || !help) return;
      const v = (sel.value||'').toLowerCase();
      help.innerHTML = POS_INFO[v] || '';
      help.style.display = help.innerHTML ? 'block' : 'none';
    }
    compactPOSOptions();

    const frontInput = $("#uFront");
    const fokusInput = $("#uFokus");
    const focusBaseInput = document.getElementById('uFocusBase');
    const focusWordList = document.getElementById('focusWordList');
    const focusStatusEl = document.getElementById('focusHelperStatus');
    const focusHintEl = document.getElementById('focusHelperHint');
    const focusHelperState = { tokens: [], activeIndex: null, abortController: null };
    frontTranslationSlot = document.getElementById('slot_front_translation');
    frontTranslationToggle = document.getElementById('btnToggleFrontTranslation');
    const translationInputLocal = document.getElementById('uTransLocal');
    const translationInputEn = document.getElementById('uTransEn');
    const translationDirectionEl = document.getElementById('translationDirection');
    const translationForwardLabel = document.getElementById('translationModeForward');
    const translationReverseLabel = document.getElementById('translationModeReverse');
    const translationModeHint = document.getElementById('translationModeHint');
    const copyTranslationBtn = document.getElementById('btnCopyFrontTranslation');
    const frontTranslationOutput = document.getElementById('frontTranslationOutput');
    const frontTranslationStatus = document.getElementById('frontTranslationStatus');
    const focusTranslationText = document.getElementById('focusTranslationText');
    const translationHintDefault = translationModeHint ? translationModeHint.textContent : '';
    let translationDirection = 'no-user';
    let translationDebounce = null;
    let translationAbortController = null;
    let translationRequestSeq = 0;
    if(frontTranslationToggle){
      frontTranslationToggle.addEventListener('click', ()=>{
        setFrontTranslationVisibility(!frontTranslationVisible);
      });
    }
    setFrontTranslationVisibility(false);
    updateTranslationLangUI();
    if(translationDirectionEl){
      translationDirectionEl.querySelectorAll('button').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          applyTranslationDirection(btn.dataset.dir || 'no-user');
        });
      });
    }
    if(frontInput){
      frontInput.addEventListener('input', ()=>{
        if(translationDirection === 'no-user'){
          scheduleTranslationRefresh();
        }
      });
    }
    if(translationInputLocal){
      translationInputLocal.addEventListener('input', ()=>{
        if(translationDirection === 'user-no'){
          scheduleTranslationRefresh();
        }
      });
    }
    if(copyTranslationBtn && navigator?.clipboard){
      copyTranslationBtn.addEventListener('click', async ()=>{
        const text = (frontTranslationOutput && frontTranslationOutput.textContent || '').trim();
        if(!text || text === '-'){
          return;
        }
        try{
          await navigator.clipboard.writeText(text);
          if(frontTranslationStatus){
            const prev = frontTranslationStatus.textContent;
            frontTranslationStatus.textContent = 'Copied!';
            setTimeout(()=>{
              if(frontTranslationStatus.textContent === 'Copied!'){
                frontTranslationStatus.textContent = prev;
              }
            }, 1200);
          }
        }catch(err){
          console.warn('[Flashcards] Copy failed', err);
        }
      });
    }
    setTranslationPreview('', '', aiStrings.translationIdle);
    setFocusTranslation('');
    applyTranslationDirection('no-user');
    const wordRegex = (()=>{ try { void new RegExp('\\p{L}', 'u'); return /[\p{L}\p{M}\d'’\-]+/gu; } catch(_e){ return /[A-Za-z0-9'’\-]+/g; } })();

    function setFocusStatus(state, text){
      if(!focusStatusEl) return;
      focusStatusEl.dataset.state = state || '';
      focusStatusEl.textContent = text || '';
    }

    function updateFocusHint(){
      if(!focusHintEl) return;
      focusHintEl.textContent = aiEnabled ? aiStrings.click : aiStrings.disabled;
    }

    function extractFocusTokens(text){
      if(!text) return [];
      const matches = text.match(wordRegex);
      if(!matches) return [];
      return matches.map((token, index)=>({ text: token, index }));
    }

    function updateChipActiveState(){
      if(!focusWordList) return;
      Array.from(focusWordList.querySelectorAll('.focus-chip')).forEach(btn=>{
        const idx = Number(btn.dataset.index || -1);
        btn.classList.toggle('active', idx === focusHelperState.activeIndex);
      });
    }

    function renderFocusChips(){
      if(!focusWordList) return;
      const text = frontInput ? frontInput.value : '';
      const tokens = extractFocusTokens(text);
      focusHelperState.tokens = tokens;
      focusWordList.innerHTML = '';
      if(!text || !text.trim()){
        setFocusStatus('', '');
      }
      if(!tokens.length){
        const span=document.createElement('span');
        span.className='focus-helper-empty';
        span.textContent = aiStrings.notext;
        focusWordList.appendChild(span);
        return;
      }
      tokens.forEach((token, idx)=>{
        const btn=document.createElement('button');
        btn.type='button';
        btn.className='focus-chip';
        btn.textContent = token.text;
        btn.dataset.index = String(idx);
        if(!aiEnabled){
          btn.disabled = true;
        }else{
          btn.addEventListener('click', ()=> triggerFocusHelper(token, idx));
        }
        if(idx === focusHelperState.activeIndex){
          btn.classList.add('active');
        }
        focusWordList.appendChild(btn);
      });
    }

    function applyAiPayload(data){
      if(!data) return '';
      let correctionMessage = '';
      if(typeof data.correction === 'string' && data.correction.trim()){
        correctionMessage = data.correction.trim();
      }
      if(data.focusWord && fokusInput){
        fokusInput.value = data.focusWord;
        try{
          fokusInput.dispatchEvent(new Event('input', {bubbles:true}));
        }catch(_e){}
      }
      if(data.focusBaseform && focusBaseInput){
        const current = (focusBaseInput.value || '').trim();
        if(!current || /ingen/i.test(current)){
          focusBaseInput.value = data.focusBaseform;
        }
      }
      if(data.definition){
        const expl = document.getElementById('uExplanation');
        if(expl && !expl.value.trim()){
          expl.value = data.definition;
        }
      }
      if(Object.prototype.hasOwnProperty.call(data, 'translation')){
        if(translationInputLocal && !translationInputLocal.value.trim()){
          translationInputLocal.value = data.translation || '';
        }
      }
      setFocusTranslation(data.translation || '');
      if(data.transcription){
        const trEl = document.getElementById('uTranscription');
        if(trEl && !trEl.value.trim()){
          trEl.value = data.transcription;
        }
      }
      if(Array.isArray(data.collocations) && data.collocations.length){
        const collEl = document.getElementById('uCollocations');
        if(collEl && !collEl.value.trim()){
          const formatted = data.collocations.map(item=>{
            if(!item) return '';
            if(typeof item === 'string') return item;
            return (item.no||item.text||'').trim();
          }).filter(Boolean);
          if(formatted.length){
            collEl.value = formatted.join('\n');
          }
        }
      }
      if(Array.isArray(data.examples) && data.examples.length){
        const examplesEl = document.getElementById('uExamples');
        if(examplesEl && !examplesEl.value.trim()){
          examplesEl.value = data.examples.join('\\n');
        }
      }
      if(data.audio && data.audio.front && data.audio.front.url){
        lastAudioUrl = data.audio.front.url;
        lastAudioKey = null;
        showAudioBadge(aiStrings.frontAudio);
        const prev=document.getElementById('audPrev');
        if(prev){
          try{prev.pause();}catch(_e){}
          prev.src = lastAudioUrl;
          prev.classList.remove('hidden');
          try{prev.load();}catch(_e){}
        }
      }
      if(data.audio && data.audio.focus && data.audio.focus.url){
        setFocusAudio(data.audio.focus.url, aiStrings.focusAudio);
      }
      if(data.pos){
        const posField = document.getElementById('uPOS');
        if(posField){
          posField.value = data.pos;
          updatePOSHelp();
          togglePOSUI();
        }
      }
      if(data.gender && data.gender !== '-'){
        const genderField = document.getElementById('uGender');
        if(genderField && !genderField.value){
          genderField.value = data.gender;
        }
      }
      if(data.errors && (data.errors.tts_front || data.errors.tts_focus)){
        const msgs = [];
        if(data.errors.tts_front) msgs.push(`Front: ${data.errors.tts_front}`);
        if(data.errors.tts_focus) msgs.push(`Focus: ${data.errors.tts_focus}`);
        setTtsStatus(`${aiStrings.ttsError} ${msgs.join(' | ')}`, 'error');
      } else if(data.audio && (data.audio.front || data.audio.focus)){
        setTtsStatus(aiStrings.ttsSuccess, 'success');
      } else {
        setTtsStatus('', '');
      }
      return correctionMessage;
    }

    async function triggerFocusHelper(token, idx){
      if(!aiEnabled){
        setFocusStatus('disabled', aiStrings.disabled);
        return;
      }
      if(!frontInput || !frontInput.value.trim()){
        setFocusStatus('idle', aiStrings.notext);
        return;
      }
      focusHelperState.activeIndex = idx;
      updateChipActiveState();
      if(focusHelperState.abortController){
        focusHelperState.abortController.abort();
      }
      const controller = new AbortController();
      focusHelperState.abortController = controller;
      setFocusStatus('loading', aiStrings.detecting);
      try{
        const payload = {
          frontText: frontInput.value.trim(),
          focusWord: token.text,
          language: userLang2
        };
        if(selectedVoice){
          payload.voiceId = selectedVoice;
        }
        const data = await api('ai_focus_helper', {}, 'POST', payload, {signal: controller.signal});
        const correctionMsg = applyAiPayload(data);
        if(correctionMsg){
          setFocusStatus('correction', correctionMsg);
        }else{
          setFocusStatus('success', aiStrings.success);
        }
      }catch(err){
        if(controller.signal.aborted){
          return;
        }
        console.error('[Flashcards][AI] focus helper failed', err);
        setFocusStatus('error', err?.message || aiStrings.error);
      }finally{
        if(focusHelperState.abortController === controller){
          focusHelperState.abortController = null;
        }
      }
    }

    updateFocusHint();
    if(frontInput){
      frontInput.addEventListener('input', ()=>{
        focusHelperState.activeIndex = null;
        if(focusHelperState.abortController){
          focusHelperState.abortController.abort();
          focusHelperState.abortController = null;
        }
        renderFocusChips();
        if(aiEnabled){
          setFocusStatus('', '');
        }
      });
    }
    renderFocusChips();
    if(!aiEnabled){
      setFocusStatus('disabled', aiStrings.disabled);
    }

    function pickTranslationFromPayload(p){
      const t = p && p.translations ? p.translations : null;
      if(t && typeof t === 'object'){
        return t[userLang2] || t[userLang] || t.en || '';
      }
      return p && (p.translation || p.back) || '';
    }

    function resolveAsset(u){ if(!u) return null; if(/^https?:|^blob:|^data:/.test(u)) return u; u=(u+'').replace(/^\/+/, ''); return baseurl + u; }
    async function api(action, params={}, method='GET', body, extraOpts){
      const opts = {method, headers:{'Content-Type':'application/json'}};
      if(body!==undefined && body!==null){
        opts.body = JSON.stringify(body);
      }
      if(extraOpts){
        if(extraOpts.headers){
          opts.headers = Object.assign({}, opts.headers, extraOpts.headers);
        }
        if(extraOpts.signal){
          opts.signal = extraOpts.signal;
        }
      }
      const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
      // In global mode, pass cmid=0; in activity mode, pass actual cmid
      url.searchParams.set('cmid', isGlobalMode ? 0 : cmid);
      url.searchParams.set('action', action);
      url.searchParams.set('sesskey', sesskey);
      Object.entries(params||{}).forEach(([k,v])=>url.searchParams.set(k,v));
      const r = await fetch(url.toString(), opts);
      const j = await r.json();
      if(!j || j.ok!==true) throw new Error(j && j.error || 'Server error');
      return j.data;
    }

    // Helper function to refresh dashboard stats (callable from anywhere)
    async function refreshDashboardStats(){
      try {
        const ajaxUrl = baseurl.replace(/\/app\/?$/, '') + '/ajax.php';
        const resp = await fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            sesskey,
            cmid: isGlobalMode ? 0 : cmid,
            action: 'get_dashboard_data'
          })
        });
        const json = await resp.json();
        if (!json.ok) return;

        const data = json.data;
        console.log('[STATS] Dashboard stats refreshed:', data.stats);
        const dueToday = data.stats.dueToday || 0;
        const activeVocabValue = data.stats.activeVocab ?? 0;

        // Update stats in dashboard tab
        const statTotalCards = $('#statTotalCards');
        if (statTotalCards) statTotalCards.textContent = data.stats.totalCardsCreated || 0;
        const statActiveVocab = $('#statActiveVocab');
        if (statActiveVocab) statActiveVocab.textContent = formatActiveVocab(activeVocabValue);

        // Update stats in header
        const headerTotalCards = $('#headerTotalCards');
        if (headerTotalCards) headerTotalCards.textContent = data.stats.totalCardsCreated || 0;
        const headerActiveVocab = $('#headerActiveVocab');
        if (headerActiveVocab) headerActiveVocab.textContent = formatActiveVocab(activeVocabValue);

        // Update study badge
        const studyBadge = $('#studyBadge');
        if (studyBadge) {
          studyBadge.textContent = dueToday > 0 ? String(dueToday) : '';
          studyBadge.classList.toggle('hidden', dueToday <= 0);
        }
      } catch (err) {
        console.error('[STATS] Error refreshing stats:', err);
      }
    }

    function normalizeServerCard(item){
      if(!item) return null;
      const payload = item.payload || {};
      const frontAudio = payload.audioFront || payload.audio || null;
      return {
        id: item.cardId,
        text: payload.text || payload.front || "",
        fokus: payload.fokus || "",
        focusBase: payload.focusBase || payload.focus_baseform || "",
        translation: pickTranslationFromPayload(payload),
        translations: (payload.translations && typeof payload.translations === 'object') ? payload.translations : null,
        explanation: payload.explanation || "",
        image: payload.image || null,
        imageKey: payload.imageKey || null,
        audio: frontAudio,
        audioFront: frontAudio,
        audioKey: payload.audioKey || null,
        focusAudio: payload.focusAudio || payload.audioFocus || null,
        focusAudioKey: payload.focusAudioKey || null,
        order: Array.isArray(payload.order) ? payload.order : [],
        transcription: payload.transcription || "",
        pos: payload.pos || "",
        gender: payload.gender || "",
        forms: payload.forms || null,
        antonyms: Array.isArray(payload.antonyms) ? payload.antonyms : [],
        collocations: Array.isArray(payload.collocations) ? payload.collocations : [],
        examples: Array.isArray(payload.examples) ? payload.examples : [],
        cognates: Array.isArray(payload.cognates) ? payload.cognates : [],
        sayings: Array.isArray(payload.sayings) ? payload.sayings : [],
        scope: item.scope || 'private',
        ownerid: (typeof item.ownerid === 'number' || item.ownerid === null) ? item.ownerid : null
      };
    }

    async function syncFromServer(){
      try{
        const dueItems = await api('get_due_cards', {limit: 1000}).catch(()=>[]);
        const deckCards = {};
        const deckTitles = {};
        const fallbackProgress = {};
        let progressData = null;

        (dueItems||[]).forEach(it => {
          const deckId = String(it.deckId || MY_DECK_ID);
          if(!deckCards[deckId]) deckCards[deckId] = [];
          const card = normalizeServerCard(it);
          if(card) deckCards[deckId].push(card);
          if(!deckTitles[deckId]) {
            deckTitles[deckId] = deckId === MY_DECK_ID ? "My cards" : `Deck ${deckId}`;
          }
          if(it.progress){
            if(!fallbackProgress[deckId]) fallbackProgress[deckId] = {};
            fallbackProgress[deckId][it.cardId] = it.progress;
          }
        });

        try{
          progressData = await api('fetch');
        }catch(err){
          console.error('fetch progress failed', err);
        }

        const deckIdsToFetch = new Set(Object.keys(deckCards));
        if(progressData && typeof progressData === 'object'){
          Object.keys(progressData).forEach(deckKey => deckIdsToFetch.add(String(deckKey)));
        }
        if(state && state.decks){
          Object.keys(state.decks).forEach(id => deckIdsToFetch.add(String(id)));
        }

        if(isGlobalMode){
          const meta = await api('list_decks').catch(()=>[]);
          (meta||[]).forEach(d => {
            const deckId = String((d && d.id) || MY_DECK_ID);
            deckIdsToFetch.add(deckId);
            if(d && d.title){
              deckTitles[deckId] = d.title;
            }
          });
        }

        for(const deckId of deckIdsToFetch){
          if(deckId === MY_DECK_ID) continue;
          const numericId = Number(deckId);
          if(!Number.isFinite(numericId)) continue;
          try{
            const cards = await api('get_deck_cards', {deckid:numericId, offset:0, limit:1000});
            if(cards && cards.length){
              if(!deckCards[deckId]) deckCards[deckId] = [];
              cards.forEach(item => {
                const card = normalizeServerCard(item);
                if(card) deckCards[deckId].push(card);
              });
            }
          }catch(err){
            console.error('get_deck_cards failed', err);
          }
        }

        Object.entries(deckCards).forEach(([deckId, cards])=>{
          if(!registry[deckId]) {
            registry[deckId] = {
              id: deckId,
              title: deckTitles[deckId] || (deckId === MY_DECK_ID ? "My cards" : `Deck ${deckId}`),
              cards: []
            };
          } else if(deckTitles[deckId]) {
            registry[deckId].title = deckTitles[deckId];
          }

          cards.forEach(card => {
            const existingIdx = registry[deckId].cards.findIndex(c => c.id === card.id);
            if(existingIdx >= 0){
              const existing = registry[deckId].cards[existingIdx];
              registry[deckId].cards[existingIdx] = {
                ...existing,
                ...card,
                order: card.order.length > 0 ? card.order : existing.order
              };
            } else {
              registry[deckId].cards.push(card);
            }
          });
        });
        saveRegistry();

        if(!state.decks) state.decks = {};
        if(!state.hidden) state.hidden = {};

        if(!progressData){
          try{
            progressData = await api('fetch');
          }catch(err){
            console.error('fetch progress failed', err);
          }
        }

        const applyProgress = (deckId, cardId, prog)=>{
          if(!prog) return;
          if(!state.decks[deckId]) state.decks[deckId] = {};
          state.decks[deckId][cardId] = {
            step: prog.step || 0,
            due: (prog.due || 0) * 1000,
            addedAt: (prog.addedAt || 0) * 1000,
            lastAt: (prog.lastAt || 0) * 1000,
            hidden: prog.hidden ? 1 : 0
          };
          if(prog.hidden){
            if(!state.hidden[deckId]) state.hidden[deckId] = {};
            state.hidden[deckId][cardId] = true;
          } else if(state.hidden && state.hidden[deckId]) {
            delete state.hidden[deckId][cardId];
          }
        };

        if(progressData && typeof progressData === 'object'){
          Object.entries(progressData).forEach(([deckKey, cards])=>{
            const deckId = String(deckKey || MY_DECK_ID);
            Object.entries(cards || {}).forEach(([cardId, prog])=>{
              applyProgress(deckId, cardId, prog);
            });
          });
        } else {
          Object.entries(fallbackProgress).forEach(([deckId, cards])=>{
            Object.entries(cards || {}).forEach(([cardId, prog])=>{
              applyProgress(deckId, cardId, prog);
            });
          });
        }
        saveState();

        ensureAllProgress();
      }catch(e){ console.error('syncFromServer error:', e); }
    }
    function ensureAllProgress(){ Object.values(registry||{}).forEach(d=>ensureDeckProgress(d.id,d.cards)); }

    // Dates / SRS
    // TEST MODE: Using minutes instead of days for quick testing
    function today0(){return Date.now();} // Return current timestamp instead of midnight

    // Calculate due timestamp based on exponential intervals (2^n)
    // Step 0: card created (due=now)
    // Step 1-10: 1,2,4,8,16,32,64,128,256,512 days (in test: minutes)
    // Step 11+: completed (checkmark)
    function calculateDue(currentTime, step, easy){
      if(step <= 0) return currentTime;
      if(step > 10) return currentTime + (512 * 60 * 1000); // Far in future
      const daysInterval = Math.pow(2, step - 1);
      const actualInterval = easy ? daysInterval : Math.max(1, daysInterval / 2);
      return currentTime + (actualInterval * 60 * 1000); // Convert to milliseconds
    }

    // Storage
    const PROFILE_KEY="srs-profile";
    function storageKey(base){return `${base}:${localStorage.getItem(PROFILE_KEY)||"Guest"}`;}
    const STORAGE_STATE="srs-v6:state", STORAGE_REG="srs-v6:registry";
    const MY_DECK_ID="my-deck";
    const DEFAULT_ORDER=["audio","image","text","explanation","translation"];
    let state,registry,queue=[],current=-1,visibleSlots=1,currentItem=null;
    function loadState(){state=JSON.parse(localStorage.getItem(storageKey(STORAGE_STATE))||'{"active":{},"decks":{},"hidden":{}}'); registry=JSON.parse(localStorage.getItem(storageKey(STORAGE_REG))||'{}');}
    function saveState(){localStorage.setItem(storageKey(STORAGE_STATE),JSON.stringify(state));}
    function saveRegistry(){localStorage.setItem(storageKey(STORAGE_REG),JSON.stringify(registry));}
    function ensureDeckProgress(deckId,cards){ if(!state.decks[deckId]) state.decks[deckId]={}; const m=state.decks[deckId]; (cards||[]).forEach(c=>{ if(!m[c.id]) m[c.id]={step:0,due:today0(),addedAt:today0(),lastAt:null}; }); }
    function refreshSelect(){const s=$("#deckSelect"); s.innerHTML=""; Object.values(registry||{}).forEach(d=>{const o=document.createElement("option");o.value=d.id;o.textContent=`${d.title} - ${d.cards.length} kort`; s.appendChild(o);});}
    function updateBadge(){const act=Object.keys(state?.active||{}).filter(id=>state.active[id]); const lbl=$('#badge'); if(!lbl) return; const base=(lbl.dataset&&lbl.dataset.label)||lbl.textContent; const actlbl=$('#btnActivate')? $('#btnActivate').textContent : 'Activate'; lbl.textContent = act.length?`${actlbl}: ${act.length}`:base; }

    // UI helpers
    const slotContainer=$("#slotContainer"), emptyState=$("#emptyState");

    // New pill-based stage visualization
    // Stage 0-10: fill pill left-to-right, show number
    // Stage 11+: green pill with checkmark
    function setStage(step){
      if(step < 0) step = 0;
      const stageBadge = $("#stageBadge");
      const stageText = $("#stageText");

      if(!stageBadge) return;
      stageBadge.classList.remove("hidden");

      // Stage 11+: completed (green checkmark)
      if(step > 10){
        if(stageText) stageText.textContent = "\u2713";
        stageBadge.style.background = "linear-gradient(90deg, #4caf50 100%, #e0e0e0 0%)";
        stageBadge.style.color = "#fff";
        stageBadge.style.justifyContent = "center";
        return;
      }

      // Stage 0-10: show number and fill percentage
      const fillPercent = step === 0 ? 0 : (step / 10) * 100;
      if(stageText) stageText.textContent = String(step);
      stageBadge.style.background = `linear-gradient(90deg, #2196f3 ${fillPercent}%, #e0e0e0 ${fillPercent}%)`;
      stageBadge.style.color = step > 5 ? "#fff" : "#333"; // White text when >50% filled
      stageBadge.style.justifyContent = "center";
    }
    function setDue(n){
      const el=$("#due");
      if(el) el.textContent = String(n);
      const studyBadge = $("#studyBadge");
      if(studyBadge){
        studyBadge.textContent = n > 0 ? String(n) : "";
        studyBadge.classList.toggle('hidden', !(n > 0));
      }
    }

    let audioURL=null; const player=new Audio();
    let lastImageKey=null,lastAudioKey=null; let lastImageUrl=null,lastAudioUrl=null; let lastAudioBlob=null; let rec=null,recChunks=[];
    let focusAudioUrl=null;
    const IS_IOS = /iP(hone|ad|od)/.test(navigator.userAgent);
    const DEBUG_REC = (function(){
      try{
        const qs = new URLSearchParams(location.search);
        if(qs.get('debugrec') === '1'){ localStorage.setItem('flashcards-debugrec','1'); return true; }
        if(qs.get('debugrec') === '0'){ localStorage.removeItem('flashcards-debugrec'); return false; }
        return localStorage.getItem('flashcards-debugrec') === '1';
      }catch(_e){ return false; }
    })();
    const debugLog = message => baseDebugLog(DEBUG_REC, message);
    const recorderLog = message => {
      if(!DEBUG_REC) return;
      try{ debugLog('[Recorder] ' + message); }catch(_e){}
    };

    const storageFallback = new Map();
    async function idbPutSafe(key, blob){
      try{
        await idbPut(key, blob);
        return true;
      }catch(_e){
        storageFallback.set(key, blob);
        return false;
      }
    }
    async function idbGetSafe(key){
      try{
        const value = await idbGet(key);
        if(value !== undefined && value !== null){
          return value;
        }
      }catch(_e){}
      return storageFallback.get(key) || null;
    }
    async function urlForSafe(key){
      try{
        const val = await urlFor(key);
        if(val) return val;
      }catch(_e){}
      const blob = storageFallback.get(key);
      return blob ? URL.createObjectURL(blob) : null;
    }
    const IOS_WORKER_URL = ((window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '') + '/mod/flashcards/assets/recorder-worker.js';

    let iosRecorderGlobal = null;
    if(IS_IOS){
      try{
        iosRecorderGlobal = createIOSRecorder(IOS_WORKER_URL, debugLog);
      }catch(_e){
        iosRecorderGlobal = null;
      }
    }
    let useIOSRecorderGlobal = false;

    // Keep reference to active mic stream and current preview URL to ensure proper cleanup (iOS)
    let micStream=null, previewAudioURL=null;
    function resetAudioPreview(){
      try{
        if(previewAudioURL){
          try{ URL.revokeObjectURL(previewAudioURL); }catch(_e){}
          previewAudioURL=null;
        }
        const mainAudio=$("#audPrev");
        if(mainAudio){
          try{ mainAudio.pause(); }catch(_e){}
          mainAudio.removeAttribute('src');
          try{ mainAudio.load(); }catch(_e){}
          mainAudio.classList.add("hidden");
        }
        hideAudioBadge();
      }catch(_e){}
    }
    let advancedVisible=false;
    function setAdvancedVisibility(show){
      advancedVisible = !!show;
      const advanced = $("#editorAdvancedFields");
      if(advanced){
        advanced.classList.toggle("hidden", !advancedVisible);
      }
      const label = $("#editorAdvancedLabel");
      if(label){
        label.textContent = advancedVisible ? t('hide_advanced') : t('show_advanced');
      }
      const icon = $("#editorAdvancedIcon");
      if(icon){
        icon.textContent = advancedVisible ? '^' : '�';
      }
    }
    function showAudioBadge(label){
      const badge=document.getElementById('audBadge');
      const nameEl=document.getElementById('audName');
      if(nameEl) nameEl.textContent = label || '';
      if(badge) badge.classList.remove('hidden');
    }
    function hideAudioBadge(){
      const badge=document.getElementById('audBadge');
      if(badge) badge.classList.add('hidden');
      const nameEl=document.getElementById('audName');
      if(nameEl) nameEl.textContent = '';
    }
    function showFocusAudioBadge(label){
      const badge=document.getElementById('focusAudBadge');
      const nameEl=document.getElementById('focusAudName');
      if(nameEl) nameEl.textContent = label || aiStrings.focusAudio;
      if(badge) badge.classList.remove('hidden');
    }
    function hideFocusAudioBadge(){
      const badge=document.getElementById('focusAudBadge');
      if(badge) badge.classList.add('hidden');
      const nameEl=document.getElementById('focusAudName');
      if(nameEl) nameEl.textContent = '';
    }
    function clearFocusAudio(){
      focusAudioUrl = null;
      hideFocusAudioBadge();
      const audio=document.getElementById('focusAudPrev');
      if(audio){
        try{ audio.pause(); }catch(_e){}
        audio.removeAttribute('src');
        try{ audio.load(); }catch(_e){}
        audio.classList.add('hidden');
      }
    }
    function setFocusAudio(url, label){
      if(!url){
        clearFocusAudio();
        return;
      }
      focusAudioUrl = url;
      showFocusAudioBadge(label || aiStrings.focusAudio);
      const audio=document.getElementById('focusAudPrev');
      if(audio){
        try{audio.pause();}catch(_e){}
        audio.src = url;
        audio.classList.remove('hidden');
        try{audio.load();}catch(_e){}
      }
    }
    function showImageBadge(label){
      const badge=document.getElementById('imgBadge');
      const nameEl=document.getElementById('imgName');
      if(nameEl) nameEl.textContent = label || '';
      if(badge) badge.classList.remove('hidden');
    }
    function hideImageBadge(){
      const badge=document.getElementById('imgBadge');
      if(badge) badge.classList.add('hidden');
      const nameEl=document.getElementById('imgName');
      if(nameEl) nameEl.textContent = '';
    }
    function clearAudioSelection(){
      lastAudioKey=null;
      lastAudioUrl=null;
      lastAudioBlob=null;
      const audioInput=$("#uAudio");
      if(audioInput) audioInput.value="";
      resetAudioPreview();
    }
    function clearImageSelection(){
      lastImageKey=null;
      lastImageUrl=null;
      const imageInput=$("#uImage");
      if(imageInput) imageInput.value="";
      const img=$("#imgPrev");
      if(img){
        img.classList.add("hidden");
        img.removeAttribute('src');
      }
      hideImageBadge();
    }
    let editingCardId=null; // Track which card is being edited to prevent cross-contamination
    // Top-right action icons helpers
    const btnEdit = $("#btnEdit"), btnDel=$("#btnDel"), btnPlayBtn=$("#btnPlay"), btnPlaySlowBtn=$("#btnPlaySlow"), btnUpdate=$("#btnUpdate");
    function setIconVisibility(hasCard){
      // Edit/Delete should remain visible even if no card (like original), but disabled.
      if(btnEdit){ btnEdit.classList.remove('hidden'); btnEdit.disabled = !hasCard; }
      if(btnDel){ btnDel.classList.remove('hidden'); btnDel.disabled = !hasCard; }
    }
    function hidePlayIcons(){ if(btnPlayBtn) btnPlayBtn.classList.add('hidden'); if(btnPlaySlowBtn) btnPlaySlowBtn.classList.add('hidden'); }
    function playAudioFromUrl(url, rate){
      if(!url) return;
      try{
        player.src = url;
        player.playbackRate = rate || 1;
        player.currentTime = 0;
        player.play().catch(()=>{});
      }catch(_e){}
    }
    function attachAudio(url){
      audioURL=url;
      if(btnPlayBtn) btnPlayBtn.classList.remove("hidden");
      if(btnPlaySlowBtn) btnPlaySlowBtn.classList.remove("hidden");
      if(btnPlayBtn){
        btnPlayBtn.onclick=()=>{ if(!audioURL)return; playAudioFromUrl(audioURL,1); };
      }
      if(btnPlaySlowBtn){
        btnPlaySlowBtn.onclick=()=>{ if(!audioURL)return; playAudioFromUrl(audioURL,0.67); };
      }
    }
    function openEditor(){
      const front = $("#uFront");
      if(front){
        setTimeout(()=>front.focus(), 50);
      }
    }
    function closeEditor(){
      editingCardId=null;
      const _btnUpdate = $("#btnUpdate");
      if(_btnUpdate) _btnUpdate.disabled = true;
      setAdvancedVisibility(false);
    }
    function normalizeLessonCard(c){
      if(c.front && !c.text) c.text=c.front;
      if(c.back&&!c.translation) c.translation=c.back;

      // Select translation based on user language
      if(c.translations && typeof c.translations === 'object'){
        const preferredTranslation = c.translations[userLang2] || c.translations['en'] || '';
        if(preferredTranslation){
          c.translation = preferredTranslation;
        }
      }

      if(!Array.isArray(c.order)||!c.order.length){
        c.order=[];
        if(c.audio||c.audioKey||c.focusAudio||c.focusAudioKey) c.order.push("audio");
        if(c.image||c.imageKey) c.order.push("image");
        if(c.text) c.order.push("text");
        if(c.explanation) c.order.push("explanation");
        if(c.translation) c.order.push("translation");
      }
      c.order=uniq(c.order);
      return c;
    }
    async function buildSlot(kind, card){
      const el=document.createElement("div");
      el.className="slot";
      if(kind==="text" && card.text){
        const tr = card.transcription ? `<div class="transcription-text">[${card.transcription}]</div>` : "";
        el.innerHTML=`<div class="front">${card.text}</div>${tr}`;
        return el;
      }
      if(kind==="explanation" && card.explanation){
        el.innerHTML=`<div class="back">${card.explanation}</div>`;
        return el;
      }
      if(kind==="translation" && card.translation){
        el.innerHTML=`<div class="back">${card.translation}</div>`;
        return el;
      }
      if(kind==="image" && (card.image||card.imageKey)){
        const url=card.imageKey?await urlForSafe(card.imageKey):resolveAsset(card.image);
        if(url){
          const img=document.createElement("img");
          img.src=url;
          img.className="media";
          el.appendChild(img);
          return el;
        }
      }
      if(kind==="audio"){
        const tracks=[];
        let frontUrl=null;
        if(card.audioKey){
          frontUrl=await urlForSafe(card.audioKey);
        }else if(card.audio){
          frontUrl=resolveAsset(card.audio);
        }
        if(frontUrl){
          tracks.push({url:frontUrl,type:'front'});
        }
        let focusUrl=null;
        if(card.focusAudioKey){
          focusUrl=await urlForSafe(card.focusAudioKey);
        }else if(card.focusAudio){
          focusUrl=resolveAsset(card.focusAudio) || card.focusAudio;
        }
        if(focusUrl){
          tracks.push({url:focusUrl,type:'focus'});
        }
        if(!tracks.length){
          return null;
        }
        const row=document.createElement("div");
        row.className="audio-chip-row";
        tracks.forEach((track, idx)=>{
          const btn=document.createElement("button");
          btn.type="button";
          btn.className="pill"+(track.type==='focus'?' pill-focus':'');
          btn.textContent = track.type==='focus' ? aiStrings.focusAudio : aiStrings.frontAudio;
          btn.addEventListener("click", ()=>playAudioFromUrl(track.url,1));
          row.appendChild(btn);
          if(track.type==='front'){
            attachAudio(track.url);
            el.dataset.autoplay=track.url;
          }
          if(!el.dataset.autoplay && idx===0){
            el.dataset.autoplay = track.url;
          }
        });
        el.appendChild(row);
        return el;
      }
      return null;
    }
    async function renderCard(card, count){ slotContainer.innerHTML=""; hidePlayIcons(); audioURL=null; const allSlots=[]; for(const kind of card.order){ const el=await buildSlot(kind,card); if(el) allSlots.push(el); } const items=allSlots.slice(0,count); if(!items.length){ const d=document.createElement("div"); d.className="front"; d.textContent="-"; items.push(d); } items.forEach(x=>slotContainer.appendChild(x)); if(count===1 && items[0] && items[0].dataset && items[0].dataset.autoplay){ player.src=items[0].dataset.autoplay; player.playbackRate=1; player.currentTime=0; player.play().catch(()=>{}); } card._availableSlots=allSlots.length; }

    function buildQueue(){
      const now=today0();
      queue=[]; current=-1; currentItem=null;
      const act=Object.keys(state?.active||{}).filter(id=>state.active[id]);

      act.forEach(id=>{
        const d=registry[id];
        if(!d) {
          debugLog(`Deck ${id} is active but not found in registry`);
          return;
        }
        ensureDeckProgress(id,d.cards);

        debugLog(`Processing deck ${id}: ${(d.cards||[]).length} cards`);

        (d.cards||[]).forEach(c=>{
          if(state.hidden && state.hidden[id] && state.hidden[id][c.id]) return;
          const nc=normalizeLessonCard(Object.assign({}, c));
          const r=state.decks[id][c.id];

          const isDue = r.due <= now;
          if(!isDue) {
            debugLog(`Card ${c.id} not due -> ${r.due}`);
          } else {
            debugLog(`Card ${c.id} due -> ${r.due}`);
            queue.push({deckId:id,card:nc,rec:r,index:queue.length});
          }
        });
      });

      debugLog(`Total due cards: ${queue.length}`);
      setDue(queue.length);
      if(queue.length===0){
        slotContainer.innerHTML="";
        const sb=$("#stageBadge"); if(sb) sb.classList.add("hidden");
        emptyState.classList.remove("hidden");
        const br=$("#btnRevealNext"); if(br){ br.disabled=true; br.classList.remove("primary"); }
        setIconVisibility(false); hidePlayIcons();
        return;
      }
      emptyState.classList.add("hidden");
      current=0; visibleSlots=1; currentItem=queue[current];
      showCurrent();
    }
    function updateRevealButton(){ const more = currentItem && currentItem.card._availableSlots && visibleSlots < currentItem.card._availableSlots; const br=$("#btnRevealNext"); if(br){ br.disabled=!more; br.classList.toggle("primary",!!more); } }
    async function showCurrent(){ if(!currentItem){ updateRevealButton(); setIconVisibility(false); hidePlayIcons(); return; } setStage(currentItem.rec.step||0); await renderCard(currentItem.card, visibleSlots); setIconVisibility(true); updateRevealButton(); try{ maybePromptForStage(); }catch(_e){} }

    async function syncProgressToServer(deckId, cardId, rec){
      try{
        const payload = {records:[{deckId, cardId, step:rec.step, due:Math.floor(rec.due/1000), addedAt:Math.floor(rec.addedAt/1000), lastAt:Math.floor(rec.lastAt/1000), hidden:rec.hidden||0}]};
        await api('save', {}, 'POST', payload);
      }catch(e){ console.error('Failed to sync progress:', e); }
    }
    // Easy: advance stage, full interval (2^n)
    function rateEasy(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Advance to next stage (max 11, where 11 = checkmark)
      it.rec.step = Math.min(11, it.rec.step + 1);
      it.rec.lastAt = now;
      it.rec.due = calculateDue(now, it.rec.step, true); // true = easy (full interval)

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Remove from queue
      queue.splice(current, 1);
      if(queue.length === 0){
        buildQueue();
      } else {
        currentItem = queue[Math.min(current, queue.length - 1)];
        visibleSlots = 1;
        showCurrent();
        setDue(queue.length);
      }
    }

    // Normal: advance stage, half interval (2^n / 2)
    function rateNormal(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Advance to next stage (max 11)
      it.rec.step = Math.min(11, it.rec.step + 1);
      it.rec.lastAt = now;
      it.rec.due = calculateDue(now, it.rec.step, false); // false = normal (half interval)

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Remove from queue
      queue.splice(current, 1);
      if(queue.length === 0){
        buildQueue();
      } else {
        currentItem = queue[Math.min(current, queue.length - 1)];
        visibleSlots = 1;
        showCurrent();
        setDue(queue.length);
      }
    }

    // Hard: stage stays same, card reappears tomorrow (1 minute in test mode)
    function rateHard(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Step doesn't change
      it.rec.lastAt = now;
      it.rec.due = now + (1 * 60 * 1000); // +1 minute

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Move to end of queue
      queue.push(it);
      queue.splice(current, 1);
      currentItem = queue[Math.min(current, queue.length - 1)];
      visibleSlots = 1;
      showCurrent();
      setDue(queue.length);
    }

    $("#btnRevealNext").addEventListener("click",()=>{ if(!currentItem)return; visibleSlots=Math.min(currentItem.card.order.length, visibleSlots+1); showCurrent(); });

    // Fallback handlers for bottom action bar (work even if flashcards-ux.js is not loaded)
    const _btnEasyBottom = $("#btnEasyBottom");
    const _btnNormalBottom = $("#btnNormalBottom");
    const _btnHardBottom = $("#btnHardBottom");
    if(_btnEasyBottom && !_btnEasyBottom.dataset.bound){ _btnEasyBottom.dataset.bound = '1'; _btnEasyBottom.addEventListener('click', e=>{ e.preventDefault(); rateEasy(); }); _btnEasyBottom.disabled = false; }
    if(_btnNormalBottom && !_btnNormalBottom.dataset.bound){ _btnNormalBottom.dataset.bound = '1'; _btnNormalBottom.addEventListener('click', e=>{ e.preventDefault(); rateNormal(); }); _btnNormalBottom.disabled = false; }
    if(_btnHardBottom && !_btnHardBottom.dataset.bound){ _btnHardBottom.dataset.bound = '1'; _btnHardBottom.addEventListener('click', e=>{ e.preventDefault(); rateHard(); }); _btnHardBottom.disabled = false; }

    // Keep bottom action bar aligned to content width (avoids overflow on Android themes)
    function syncBottomBarWidth(){
      const bar = $("#bottomActions");
      const wrap = root.querySelector('.wrap');
      if(!bar || !wrap) return;
      const rect = wrap.getBoundingClientRect();
      // Align to the visible content area
      bar.style.width = rect.width + 'px';
      bar.style.left = rect.left + 'px';
      bar.style.right = 'auto';
      bar.style.transform = 'none';
    }
    // Initial and reactive alignment
    try{
      syncBottomBarWidth();
      window.addEventListener('resize', syncBottomBarWidth, {passive:true});
      window.addEventListener('orientationchange', syncBottomBarWidth);
      window.addEventListener('scroll', syncBottomBarWidth, {passive:true});
      // Re-align after fonts/UI settle
      setTimeout(syncBottomBarWidth, 200);
      setTimeout(syncBottomBarWidth, 600);
    }catch(_e){}

    var _btnReset=$("#btnReset"); if(_btnReset){ _btnReset.addEventListener("click",()=>{ if(confirm("Reset?")){ state={active:{},decks:{},hidden:{}}; saveState(); buildQueue(); updateBadge(); } }); }
    var _btnExport=$("#btnExport"); if(_btnExport){ _btnExport.addEventListener("click",()=>{ const blob=new Blob([JSON.stringify({state,registry},null,2)],{type:"application/json"}); const a=document.createElement("a"); a.href=URL.createObjectURL(blob); a.download="srs_export.json"; a.click(); }); }
    var _btnImport=$("#btnImport"); var _fileImport=$("#fileImport"); if(_btnImport && _fileImport){ _btnImport.addEventListener("click",()=>_fileImport.click()); _fileImport.addEventListener("change",async e=>{const f=e.target.files?.[0]; if(!f)return; try{ const d=JSON.parse(await f.text()); if(d.state)state=d.state; if(d.registry)registry=d.registry; saveState(); saveRegistry(); refreshSelect(); updateBadge(); buildQueue();
      closeEditor();
      $("#status").textContent="OK"; setTimeout(()=>$("#status").textContent="",1200); }catch{ $("#status").textContent="Bad deck"; setTimeout(()=>$("#status").textContent="",1500); }}); }
    $("#packFile").addEventListener("change",async e=>{const f=e.target.files?.[0]; if(!f)return; try{ const p=JSON.parse(await f.text()); if(!p.id)p.id="pack-"+Date.now().toString(36); p.cards=(p.cards||[]).map(x=>normalizeLessonCard(x)); registry[p.id]=p; saveRegistry(); refreshSelect(); if(!state.active) state.active={}; state.active[p.id]=true; saveState(); updateBadge(); buildQueue(); $("#deckSelect").value=p.id; }catch{ $("#status").textContent="Bad deck"; setTimeout(()=>$("#status").textContent="",1500); }});
    $("#btnActivate").addEventListener("click",()=>{ const id=$("#deckSelect").value; if(!id)return; if(!state.active) state.active={}; state.active[id]=true; saveState(); updateBadge(); buildQueue(); });

    $("#btnEdit").addEventListener("click",()=>{
      if(!currentItem) return;
      const quickTabBtn = document.getElementById('tabQuickInput');
      if(quickTabBtn && !quickTabBtn.classList.contains('fc-tab-active')){
        quickTabBtn.click();
      }
      const c=currentItem.card;

      // Set editing card ID to prevent media from being attached to other cards
      editingCardId = c.id;

      // Enable Update button (both buttons always visible now)
      if(btnUpdate) btnUpdate.disabled = false;

      // Reset media variables when starting to edit
      lastImageKey = c.imageKey || null;
      lastAudioKey = c.audioKey || null;
      lastImageUrl = c.image || null;
      lastAudioUrl = c.audio || null;
      if(c.focusAudio){
        setFocusAudio(c.focusAudio, aiStrings.focusAudio);
      } else {
        clearFocusAudio();
      }

      $("#uFront").value=c.text||"";
      $("#uFokus").value=c.fokus||"";
      const focusBaseField=document.getElementById('uFocusBase');
      if(focusBaseField) focusBaseField.value = c.focusBase || '';
      $("#uExplanation").value=c.explanation||"";
      const _trscr = document.getElementById('uTranscription'); if(_trscr) _trscr.value = c.transcription||'';
      const _posSel = document.getElementById('uPOS'); if(_posSel) { _posSel.value = c.pos||''; }
      const _genSel = document.getElementById('uGender'); if(_genSel) { _genSel.value = c.gender||''; }
      const nf=(c.forms&&c.forms.noun)||{}; const _nf1=document.getElementById('uNounIndefSg'); if(_nf1) _nf1.value = nf.indef_sg||''; const _nf2=document.getElementById('uNounDefSg'); if(_nf2) _nf2.value = nf.def_sg||''; const _nf3=document.getElementById('uNounIndefPl'); if(_nf3) _nf3.value = nf.indef_pl||''; const _nf4=document.getElementById('uNounDefPl'); if(_nf4) _nf4.value = nf.def_pl||'';
      const vf=(c.forms&&c.forms.verb)||{}; const _vf1=document.getElementById('uVerbInfinitiv'); if(_vf1) _vf1.value = vf.infinitiv||''; const _vf2=document.getElementById('uVerbPresens'); if(_vf2) _vf2.value = vf.presens||''; const _vf3=document.getElementById('uVerbPreteritum'); if(_vf3) _vf3.value = vf.preteritum||''; const _vf4=document.getElementById('uVerbPerfektum'); if(_vf4) _vf4.value = vf.perfektum_partisipp||''; const _vf5=document.getElementById('uVerbImperativ'); if(_vf5) _vf5.value = vf.imperativ||'';
      const af=(c.forms&&c.forms.adj)||{}; const _af1=document.getElementById('uAdjPositiv'); if(_af1) _af1.value = af.positiv||''; const _af2=document.getElementById('uAdjKomparativ'); if(_af2) _af2.value = af.komparativ||''; const _af3=document.getElementById('uAdjSuperlativ'); if(_af3) _af3.value = af.superlativ||'';
      const _ant=document.getElementById('uAntonyms'); if(_ant) _ant.value = Array.isArray(c.antonyms)?c.antonyms.join('\n'):'';

      // Parse examples and collocations from string format "text | translation"
      function parseItems(arr) {
        if(!Array.isArray(arr)) return [];
        return arr.map(str => {
          if(typeof str !== 'string') return {no: String(str), trans: ''};
          const parts = str.split('|').map(s => s.trim());
          return {no: parts[0] || '', trans: parts[1] || ''};
        });
      }

      // Load into dynamic lists for Extended Editor
      examplesData = parseItems(c.examples);
      collocationsData = parseItems(c.collocations);
      renderExamples();
      renderCollocations();

      // Also load into Quick Input textareas (for backward compatibility)
      const _col=document.getElementById('uCollocations');
      if(_col) _col.value = Array.isArray(c.collocations)?c.collocations.map(s => typeof s === 'string' ? s.split('|')[0].trim() : s).join('\n'):'';
      const _exs=document.getElementById('uExamples');
      if(_exs) _exs.value = Array.isArray(c.examples)?c.examples.map(s => typeof s === 'string' ? s.split('|')[0].trim() : s).join('\n'):'';

      const _cog=document.getElementById('uCognates'); if(_cog) _cog.value = Array.isArray(c.cognates)?c.cognates.join('\n'):'';
      const _say=document.getElementById('uSayings'); if(_say) _say.value = Array.isArray(c.sayings)?c.sayings.join('\n'):'';
      try{ togglePOSUI(); }catch(_e){}
      const __tr = c.translations || {};
       const __tl = $("#uTransLocal"); if(__tl) __tl.value = (userLang2 !== 'en') ? (__tr[userLang2] || c.translation || "") : "";
       const __te = $("#uTransEn"); if(__te) __te.value = (__tr.en || (userLang2 === 'en' ? (c.translation || "") : ""));
       const previewText = (__tl && __tl.value) || (__te && __te.value) || c.translation || "";
       if(translationDirection === 'no-user'){
         setTranslationPreview(previewText, previewText ? 'success' : '', previewText ? null : aiStrings.translationIdle);
       }
       setFocusTranslation(previewText);
      const hasAdvanced = Boolean(
        (c.explanation && c.explanation.trim()) ||
        (__te && __te.value && __te.value.trim()) ||
        (c.transcription && c.transcription.trim()) ||
        (c.pos && c.pos.trim()) ||
        (c.gender && c.gender.trim()) ||
        (nf && (nf.indef_sg || nf.def_sg || nf.indef_pl || nf.def_pl)) ||
        (Array.isArray(c.collocations) && c.collocations.length) ||
        (Array.isArray(c.examples) && c.examples.length) ||
        (Array.isArray(c.antonyms) && c.antonyms.length) ||
        (Array.isArray(c.cognates) && c.cognates.length) ||
        (Array.isArray(c.sayings) && c.sayings.length) ||
        (Array.isArray(c.order) && c.order.length && c.order.length !== DEFAULT_ORDER.length)
      );
      setAdvancedVisibility(hasAdvanced);
      renderFocusChips();
      setFocusStatus('', '');

      (async()=>{
        $("#imgPrev").classList.add("hidden");
        $("#audPrev").classList.add("hidden");
        hideImageBadge();
        hideAudioBadge();

        if(c.imageKey){
          const u=await urlForSafe(c.imageKey);
          if(u){
            $("#imgPrev").src=u;
            $("#imgPrev").classList.remove("hidden");
            showImageBadge('Image');
          }
        } else if(c.image){
          const resolved = resolveAsset(c.image);
          if(resolved){
            $("#imgPrev").src=resolved;
            $("#imgPrev").classList.remove("hidden");
            showImageBadge(c.image.split('/').pop() || 'Image');
          }
        }

        if(c.audioKey){
          const u=await urlForSafe(c.audioKey);
          if(u){
            $("#audPrev").src=u;
            $("#audPrev").classList.remove("hidden");
            showAudioBadge('Audio');
          }
        } else if(c.audio){
          const resolved = resolveAsset(c.audio);
          if(resolved){
            $("#audPrev").src=resolved;
            $("#audPrev").classList.remove("hidden");
            showAudioBadge(c.audio.split('/').pop() || 'Audio');
          }
        }
      })();

      orderChosen=[...(c.order||[])];
      updateOrderPreview();
      openEditor();
    const _btnAddNew = $("#btnAddNew");
    if(_btnAddNew && !_btnAddNew.dataset.bound){
      _btnAddNew.dataset.bound = "1";
      _btnAddNew.addEventListener("click", ()=>{
        const quickTabBtn = document.getElementById('tabQuickInput');
        if(quickTabBtn && !quickTabBtn.classList.contains('fc-tab-active')){
          quickTabBtn.click();
        }
        editingCardId=null;
        resetForm();
        const _up=$("#btnUpdate"); if(_up) _up.disabled=true;
        openEditor();
      });
    }
    });
    $("#btnDel").addEventListener("click",async()=>{ if(!currentItem) return; const {deckId,card}=currentItem; if(!confirm("Delete this card?")) return; console.log('[DELETE] Deleting card:', card.id, 'from deck:', deckId); try { await api('delete_card', {deckid:deckId, cardid:card.id}, 'POST'); console.log('[DELETE] Card deleted successfully'); } catch(e) { console.error('[DELETE] API error:', e); } const arr=registry[deckId]?.cards||[]; const ix=arr.findIndex(x=>x.id===card.id); if(ix>=0){arr.splice(ix,1); saveRegistry();} if(state.decks[deckId]) delete state.decks[deckId][card.id]; if(state.hidden && state.hidden[deckId]) delete state.hidden[deckId][card.id]; saveState(); buildQueue(); console.log('[DELETE] Refreshing dashboard...'); await refreshDashboardStats(); console.log('[DELETE] Dashboard refreshed'); });

    // removed duplicate var line
    const imagePickerBtn = document.getElementById('btnImagePicker');
    if(imagePickerBtn && !imagePickerBtn.dataset.bound){
      imagePickerBtn.dataset.bound = '1';
      imagePickerBtn.addEventListener("click", e=>{ e.preventDefault(); const input=document.getElementById('uImage'); if(input) input.click(); });
    }
    const audioUploadBtn = document.getElementById('btnAudioUpload');
    if(audioUploadBtn && !audioUploadBtn.dataset.bound){
      audioUploadBtn.dataset.bound = '1';
      audioUploadBtn.addEventListener("click", async e=>{ 
        e.preventDefault();
        let handled = false;
        try{
          if(typeof window.showOpenFilePicker === 'function'){
            const [h] = await window.showOpenFilePicker({
              multiple: false,
              excludeAcceptAllOption: true,
              types: [{ description: 'Audio', accept: { 'audio/*': ['.mp3','.m4a','.wav','.ogg','.webm'] } }]
            });
            if(h){
              const f = await h.getFile();
              if(f){
                $("#audName").textContent = f.name;
                showAudioBadge(f.name);
                lastAudioKey = "my-"+Date.now().toString(36)+"-aud";
                const stored = await idbPutSafe(lastAudioKey, f);
                if(!stored){
                  recorderLog('File picker audio stored in memory fallback');
                  lastAudioKey = null;
                  lastAudioBlob = f;
                } else {
                  lastAudioBlob = null;
                }
                lastAudioUrl = null;
                $("#audPrev").src = URL.createObjectURL(f);
                $("#audPrev").classList.remove("hidden");
                handled = true;
              }
            }
          }
        }catch(_e){}
        if(!handled){
          const input=document.getElementById('uAudio');
          if(input) input.click();
        }
      });
    }
    // POS visibility toggles + autofill placeholders
    function togglePOSUI(){
      const pos = (document.getElementById('uPOS')?.value||'').toLowerCase();
      const genderSlot = document.getElementById('slot_gender');
      const nounFormsSlot = document.getElementById('slot_noun_forms');
      const verbFormsSlot = document.getElementById('slot_verb_forms');
      const adjFormsSlot = document.getElementById('slot_adj_forms');
      if(genderSlot) genderSlot.classList.toggle('hidden', pos !== 'substantiv');
      if(nounFormsSlot) nounFormsSlot.classList.toggle('hidden', pos !== 'substantiv');
      if(verbFormsSlot) verbFormsSlot.classList.toggle('hidden', pos !== 'verb');
      if(adjFormsSlot) adjFormsSlot.classList.toggle('hidden', pos !== 'adjektiv');
    }
    const _uPOS = document.getElementById('uPOS');
    if(_uPOS && !_uPOS.dataset.bound){
      _uPOS.dataset.bound='1';
      _uPOS.addEventListener('change', togglePOSUI);
      updatePOSHelp();
      togglePOSUI();
    }
    async function uploadMedia(file,type,cardId){ const fd=new FormData(); fd.append('file',file,file.name||('blob.'+(type==='audio'?'webm':'jpg'))); fd.append('type',type); const url=new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php'); url.searchParams.set('cmid',cmid); url.searchParams.set('action','upload_media'); url.searchParams.set('sesskey',sesskey); if(cardId) url.searchParams.set('cardid',cardId); const r= await fetch(url.toString(),{method:'POST',body:fd}); const j=await r.json(); if(j && j.ok && j.data && j.data.url) return j.data.url; throw new Error('upload failed'); }
    $("#uImage").addEventListener("change", async e=>{
      const f=e.target.files?.[0];
      if(!f)return;
      $("#imgName").textContent=f.name;
      showImageBadge(f.name);
      lastImageKey="my-"+Date.now().toString(36)+"-img";
      await idbPutSafe(lastImageKey,f);
      lastImageUrl=null;
      const objectURL = URL.createObjectURL(f);
      const imgPrev=$("#imgPrev");
      if(imgPrev){
        imgPrev.src=objectURL;
        imgPrev.classList.remove("hidden");
      }
    });
    $("#uAudio").addEventListener("change", async e=>{
      const f=e.target.files?.[0];
      if(!f) return;
      resetAudioPreview();
      $("#audName").textContent=f.name;
      showAudioBadge(f.name);
      lastAudioUrl=null;
      lastAudioBlob=null;
      lastAudioKey="my-"+Date.now().toString(36)+"-aud";
      const manualStore = await idbPutSafe(lastAudioKey,f);
      if(!manualStore){
        recorderLog('Manual audio storage fallback (IndexedDB unavailable)');
        lastAudioKey=null;
        lastAudioBlob=f;
      } else {
        lastAudioBlob=null;
      }
      const objectURL=URL.createObjectURL(f);
      previewAudioURL = objectURL;
      const a=$("#audPrev");
      if(a){
        try{a.pause();}catch(_e){}
        a.src=objectURL;
        a.classList.remove("hidden");
        try{a.load();}catch(_e){}
      }
    });
    $("#btnClearImg").addEventListener("click",()=>{ clearImageSelection(); });
    $("#btnClearAud").addEventListener("click",()=>{ clearAudioSelection(); });
    const btnClearFocusAud = $("#btnClearFocusAud");
    if(btnClearFocusAud){
      btnClearFocusAud.addEventListener("click", ()=>{ clearFocusAudio(); });
    }
                        // Hold-to-record: press and hold to record; release to stop
    (function(){
      var recBtn = $("#btnRec");
      var stopBtn = $("#btnStop");
      if(stopBtn) stopBtn.classList.add("hidden");
      if(!recBtn) return;
      var isRecording = false;
      var timerEl = document.getElementById('recTimer');
      var hintEl  = document.getElementById('recHint');
      var tInt=null, t0=0;
      var autoStopTimer=null;
      if(IS_IOS && !iosRecorderGlobal){
        try{
          iosRecorderGlobal = createIOSRecorder(IOS_WORKER_URL, debugLog);
        }catch(_e){
          iosRecorderGlobal = null;
        }
      }
      const useIOSRecorder = !!(IS_IOS && iosRecorderGlobal && iosRecorderGlobal.supported());
      useIOSRecorderGlobal = useIOSRecorder;
      const iosRecorderInstance = useIOSRecorder ? iosRecorderGlobal : null;
      if(IS_IOS && DEBUG_REC){
        debugLog('[Recorder] mode:', useIOSRecorder ? 'web-audio' : 'media-recorder');
      }

      function t(s){ try{ return (M && M.str && M.str.mod_flashcards && M.str.mod_flashcards[s]) || ''; }catch(_e){ return ''; } }
      function setHintIdle(){ if(hintEl){ hintEl.textContent = t('press_hold_to_record') || 'Press and hold to record'; } }
      function setHintActive(){ if(hintEl){ hintEl.textContent = t('release_when_finished') || 'Release when you finish'; } }
      function fmt(t){ t=Math.max(0,Math.floor(t/1000)); var m=('0'+Math.floor(t/60)).slice(-2), s=('0'+(t%60)).slice(-2); return m+':'+s; }
      function timerStart(){ if(!timerEl) return; timerEl.classList.remove('hidden'); t0=Date.now(); timerEl.textContent='00:00'; if(tInt) try{clearInterval(tInt);}catch(_e){}; tInt=setInterval(function(){ try{ timerEl.textContent = fmt(Date.now()-t0); }catch(_e){} }, 250); }
      function timerStop(){ if(tInt) try{clearInterval(tInt);}catch(_e){}; tInt=null; if(timerEl) timerEl.classList.add('hidden'); }
      function bestMime(){
        try{
          if(!(window.MediaRecorder && MediaRecorder.isTypeSupported)) return '';
          var cand = ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/ogg','audio/mp4;codecs=mp4a.40.2','audio/mp4'];
          for(var i=0;i<cand.length;i++){ if(MediaRecorder.isTypeSupported(cand[i])) return cand[i]; }
        }catch(_e){}
        return '';
      }
      function stopMicStream(){ try{ if(micStream){ micStream.getTracks().forEach(function(t){ try{t.stop();}catch(_e){} }); micStream = null; } }catch(_e){} }
      function fallbackCapture(){
        try{
          if(useIOSRecorder && iosRecorderInstance){ try{ iosRecorderInstance.releaseMic(); }catch(_e){} }
          recorderLog('Fallback to native file picker');
          var input=document.getElementById('uAudio');
          if(input){ input.setAttribute('accept','audio/*'); input.setAttribute('capture','microphone'); try{ input.click(); }catch(_e){} }
        }catch(_e){ recorderLog('Fallback capture error: '+(_e?.message||_e)); }
      }
      function normalizeAudioMime(mt){
        mt = (mt||'').toLowerCase();
        if(!mt) return '';
        if(mt.includes('mp4') || mt.includes('m4a') || mt.includes('aac')) return 'audio/mp4';
        if(mt.includes('3gpp')) return 'audio/3gpp';
        if(mt.includes('webm')) return 'audio/webm';
        if(mt.includes('ogg')) return 'audio/ogg';
        if(mt.includes('wav')) return 'audio/wav';
        return mt.startsWith('video/mp4') ? 'audio/mp4' : mt;
      }
      async function handleRecordedBlob(blob){
        if(!blob){ return; }
        if(blob.size <= 0){
          lastAudioKey = null;
          lastAudioUrl = null;
          lastAudioBlob = null;
          resetAudioPreview();
          const statusEl = $("#status");
          if(statusEl){ statusEl.textContent = 'Recording failed'; setTimeout(()=>{ statusEl.textContent=''; }, 2000); }
          recorderLog('Empty blob received from recorder');
          return;
        }
        lastAudioUrl = null;
        lastAudioBlob = null;
        lastAudioKey = "my-" + Date.now().toString(36) + "-aud";
        const stored = await idbPutSafe(lastAudioKey, blob);
        if(!stored){
          recorderLog('IndexedDB store failed; using in-memory blob');
          lastAudioKey = null;
          lastAudioBlob = blob;
        }
        const a = $("#audPrev");
        resetAudioPreview();
        const objectURL = URL.createObjectURL(blob);
        previewAudioURL = objectURL;
        if(a){
          a.src = objectURL;
          a.classList.remove("hidden");
          try{ a.load(); }catch(_e){}
        }
        const nameNode = $("#audName"); if(nameNode) nameNode.textContent = 'Recorded audio';
        showAudioBadge('Recorded audio');
        if(stored){
          recorderLog('handleRecordedBlob stored via IDB');
        } else {
          recorderLog('handleRecordedBlob using in-memory blob fallback (size '+blob.size+')');
        }
      }
      let iosStartPromise = null;
      let startPending = false;

      async function startRecording(){
        if(isRecording || startPending) return;
        startPending = true;
        if(autoStopTimer){ try{clearTimeout(autoStopTimer);}catch(_e){}; autoStopTimer=null; }
        if(useIOSRecorder){
          iosStartPromise = (async()=>{
            try{
              recorderLog('iOS recorder start()');
              await iosRecorderInstance.start();
              isRecording = true;
              recBtn.classList.add("recording");
              timerStart();
              setHintActive();
              autoStopTimer = setTimeout(()=>{ if(isRecording){ stopRecording().catch(()=>{}); } }, 120000);
            }catch(err){
              isRecording = false;
              recorderLog('iOS start failed: '+(err?.message||err));
              try{ await iosRecorderInstance.releaseMic(); }catch(_e){}
              fallbackCapture();
              throw err;
            }finally{
              iosStartPromise = null;
              startPending = false;
            }
          })();
          return iosStartPromise;
        }
        try{
          if(!window.MediaRecorder){ fallbackCapture(); return; }
          stopMicStream();
          const stream = await navigator.mediaDevices.getUserMedia({audio:true, video:false});
          micStream = stream;
          recChunks = [];
          let mime = bestMime();
          try{ rec = mime ? new MediaRecorder(stream, {mimeType:mime}) : new MediaRecorder(stream); }
          catch(_er){
            try{ rec = new MediaRecorder(stream); mime = rec.mimeType || mime || ''; }
            catch(_er2){ stopMicStream(); fallbackCapture(); return; }
          }
          try{ if(rec && rec.mimeType){ mime = rec.mimeType; } }catch(_e){}
          rec.ondataavailable = e => { if(e.data && e.data.size>0) recChunks.push(e.data); };
          rec.onstop = async () => {
            try{
              const detectedTypeRaw = (recChunks && recChunks[0] && recChunks[0].type) ? recChunks[0].type : (mime || '');
              const finalType = normalizeAudioMime(detectedTypeRaw || mime || '');
              const blob = new Blob(recChunks, {type: finalType || undefined});
              recorderLog('MediaRecorder stop -> chunks='+recChunks.length+' size='+blob.size+' type='+finalType);
              await handleRecordedBlob(blob);
            }catch(err){ recorderLog('MediaRecorder stop failed: '+(err?.message||err)); }
            stopMicStream();
            rec = null;
            isRecording = false;
            startPending = false;
          };
          try{ rec.start(1000); }catch(_e){ rec.start(); }
          isRecording = true;
          recBtn.classList.add("recording");
          timerStart();
          setHintActive();
          autoStopTimer = setTimeout(()=>{ if(rec && isRecording){ try{ rec.stop(); }catch(_e){} } }, 120000);
        }catch(err){
          isRecording = false;
          rec = null;
          stopMicStream();
          recorderLog('MediaRecorder start failed: '+(err?.message||err));
          fallbackCapture();
        }finally{
          if(!useIOSRecorder){
            startPending = false;
          }
        }
      }
      async function stopRecording(){
        if(autoStopTimer){ try{clearTimeout(autoStopTimer);}catch(_e){}; autoStopTimer=null; }
        if(useIOSRecorder){
          if(iosStartPromise){ try{ await iosStartPromise; }catch(_e){ recorderLog('Start promise rejected: '+(_e?.message||_e)); } }
          startPending = false;
          if(!isRecording){
            try{ await iosRecorderInstance.releaseMic(); }catch(_e){}
            recBtn.classList.remove("recording");
            timerStop();
            setHintIdle();
            return;
          }
          try{
            const blob = await iosRecorderInstance.exportWav();
            await handleRecordedBlob(blob);
            recorderLog('iOS export size: '+(blob ? blob.size : 0));
          }catch(err){ recorderLog('iOS stop failed: '+(err?.message||err)); }
          finally {
            try{ await iosRecorderInstance.releaseMic(); }catch(_e){}
            isRecording = false;
            recBtn.classList.remove("recording");
            timerStop();
            setHintIdle();
          }
          return;
        }
        try{ if(rec){ rec.stop(); } }catch(_e){}
        isRecording = false;
        recBtn.classList.remove("recording");
        timerStop();
        setHintIdle();
        startPending = false;
      }
      let holdActive = false;
      let activePointerToken = null;
      let holdTimeout = null;
      function onDown(e){
        if(e.type === 'mousedown' && e.button !== 0) return;
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(holdActive) return;
        holdActive = true;
        activePointerToken = (e.pointerId !== undefined) ? e.pointerId : (e.type.indexOf('touch') === 0 ? 'touch' : 'mouse');
        try{ e.preventDefault(); }catch(_e){}
        // Add 0.5-second delay before starting recording to prevent accidental permission requests
        holdTimeout = setTimeout(() => {
          const startPromise = startRecording();
          if(startPromise && typeof startPromise.then === 'function'){
            startPromise.catch(err=>{ recorderLog('start promise rejected: '+(err?.message||err)); });
          }
        }, 500);
        function onceUp(ev){
          if(activePointerToken !== null){
            if(typeof activePointerToken === 'number'){
              if(ev && ev.pointerId !== undefined && ev.pointerId !== activePointerToken){ return; }
            }
          }
          window.removeEventListener("pointerup", onceUp);
          window.removeEventListener("pointercancel", onceUp);
          window.removeEventListener("mouseup", onceUp);
          window.removeEventListener("touchend", onceUp);
          window.removeEventListener("touchcancel", onceUp);
          holdActive = false;
          activePointerToken = null;
          // Clear the timeout if button released before delay
          if(holdTimeout){
            clearTimeout(holdTimeout);
            holdTimeout = null;
          }
          const stopPromise = stopRecording();
          if(stopPromise && typeof stopPromise.then === 'function'){
            stopPromise.catch(err=>{ recorderLog('stop promise rejected: '+(err?.message||err)); });
          }
        }
        window.addEventListener("pointerup", onceUp);
        window.addEventListener("pointercancel", onceUp);
        window.addEventListener("mouseup", onceUp);
        window.addEventListener("touchend", onceUp);
        window.addEventListener("touchcancel", onceUp);
      }
      recBtn.addEventListener("pointerdown", onDown);
      recBtn.addEventListener("mousedown", onDown);
      recBtn.addEventListener("touchstart", onDown, {passive:false});
      try{ recBtn.addEventListener("click", function(e){ try{e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();}catch(_e){} }, true); }catch(_e){}
      setHintIdle();
    })();
    let orderChosen=[];
    function updateOrderPreview(){ const chipsMap={ audio: t('audio') || 'audio', image: t('image') || 'image', text: t('front') || 'text', explanation: t('explanation') || 'explanation', translation: t('back') || 'translation' }; $$("#orderChips .chip").forEach(ch=>{ ch.classList.toggle("active", orderChosen.includes(ch.dataset.kind)); }); const pretty=(orderChosen.length?orderChosen:DEFAULT_ORDER).map(k=>chipsMap[k]).join(' → '); const prevEl = document.getElementById('orderPreview'); if(prevEl) prevEl.textContent=pretty; }
    $("#orderChips").addEventListener("click",e=>{const btn=e.target.closest(".chip"); if(!btn)return; const k=btn.dataset.kind; const i=orderChosen.indexOf(k); if(i===-1) orderChosen.push(k); else orderChosen.splice(i,1); updateOrderPreview();});
    function resetForm(){
      $("#uFront").value="";
      $("#uFokus").value="";
      const baseField=document.getElementById('uFocusBase');
      if(baseField) baseField.value='';
      const _tl=$("#uTransLocal"); if(_tl) _tl.value="";
      const _te=$("#uTransEn"); if(_te) _te.value="";

      const _expl = $("#uExplanation"); if(_expl) _expl.value="";

      // Reset dynamic lists
      examplesData = [];
      collocationsData = [];
      renderExamples();
      renderCollocations();
      const _trscr = document.getElementById('uTranscription'); if(_trscr) _trscr.value = '';
      const _posField = document.getElementById('uPOS'); if(_posField) _posField.value = '';
      const _genderField = document.getElementById('uGender'); if(_genderField) _genderField.value = '';
      ['uNounIndefSg','uNounDefSg','uNounIndefPl','uNounDefPl','uVerbInfinitiv','uVerbPresens','uVerbPreteritum','uVerbPerfektum','uVerbImperativ','uAdjPositiv','uAdjKomparativ','uAdjSuperlativ','uAntonyms','uCollocations','uExamples','uCognates','uSayings']
        .forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
      togglePOSUI();
      updatePOSHelp();
      setAdvancedVisibility(false);
      clearImageSelection();
      clearAudioSelection();
      clearFocusAudio();
      if(useIOSRecorderGlobal && iosRecorderGlobal){ try{ iosRecorderGlobal.releaseMic(); }catch(_e){} }
      editingCardId=null; // Clear editing card ID when resetting form
      orderChosen=[];
      updateOrderPreview();
      renderFocusChips();
      if(aiEnabled){
        setFocusStatus('', '');
      }else{
        setFocusStatus('disabled', aiStrings.disabled);
      }

      // Disable Update button when no card is being edited
      const btnUpdate = $("#btnUpdate");
       if(btnUpdate) btnUpdate.disabled = true;

       // Safety: ensure no active mic stream remains (clears iOS mic indicator)
       try{ if(typeof stopMicStream==='function') stopMicStream(); }catch(_e){}
       setFocusTranslation('');
       if(translationAbortController){
         translationAbortController.abort();
         translationAbortController = null;
       }
       if(translationDirection !== 'no-user'){
         applyTranslationDirection('no-user');
       }else{
         setTranslationPreview('', '', aiStrings.translationIdle);
       }
     }
    $("#btnFormReset").addEventListener("click", resetForm);
    const quickResetBtn = $("#btnQuickFormReset");
    if(quickResetBtn){
      quickResetBtn.addEventListener("click", e=>{
        e.preventDefault();
        resetForm();
      });
    }
    // Bind global "+" (Create new card) button at init
    (function(){
      const addBtn = $("#btnAddNew");
      if(addBtn && !addBtn.dataset.bound){
        addBtn.dataset.bound = "1";
        addBtn.addEventListener("click", e => {
          e.preventDefault();
          const quickTabBtn = document.getElementById('tabQuickInput');
          if(quickTabBtn && !quickTabBtn.classList.contains('fc-tab-active')){
            quickTabBtn.click();
          }
          editingCardId = null;
          resetForm();
          const _up=$("#btnUpdate"); if(_up) _up.disabled=true;
          openEditor();
        });
      }
    })();
    // Shared function for both Add and Update buttons
    async function saveCard(isUpdate){
      const text=$("#uFront").value.trim(), fokus=$("#uFokus").value.trim(), expl=$("#uExplanation").value.trim();
      const trLocalEl=$("#uTransLocal"), trEnEl=$("#uTransEn");
      const trLocal = trLocalEl ? trLocalEl.value.trim() : "";
      const trEn = trEnEl ? trEnEl.value.trim() : "";
      if(!text && !expl && !trLocal && !trEn && !lastImageKey && !lastAudioKey && $("#imgPrev").classList.contains("hidden") && $("#audPrev").classList.contains("hidden")){
        $("#status").textContent="Empty"; setTimeout(()=>$("#status").textContent="",1000); return;
      }

      // If updating: keep current id; If adding new: always create a fresh id
      const id = isUpdate && editingCardId ? editingCardId : ("my-"+Date.now().toString(36));

      // Upload media files to server NOW (with correct cardId)
      if(lastImageKey && !lastImageUrl){
        try{
          const blob = await idbGetSafe(lastImageKey);
          if(blob){
            lastImageUrl = await uploadMedia(new File([blob], 'image.jpg', {type: blob.type || 'image/jpeg'}), 'image', id);
          }
        }catch(e){ console.error('Image upload failed:', e); }
      }

      if(lastAudioKey && !lastAudioUrl){
        try{
          const blob = await idbGetSafe(lastAudioKey);
          if(blob){
            lastAudioUrl = await uploadMedia((function(){
              var mt = (blob && blob.type) ? (blob.type+'') : '';
              var ext = 'webm';
              if(mt){
                var low = mt.toLowerCase();
                if(low.indexOf('audio/wav') === 0) ext = 'wav';
                else if(low.indexOf('audio/mp4') === 0 || low.indexOf('audio/m4a') === 0 || low.indexOf('video/mp4') === 0) ext = 'mp4';
                else if(low.indexOf('audio/mpeg') === 0 || low.indexOf('audio/mp3') === 0) ext = 'mp3';
                else if(low.indexOf('audio/ogg') === 0) ext = 'ogg';
                else if(low.indexOf('audio/webm') === 0) ext = 'webm';
              }
              return new File([blob], 'audio.'+ext, {type: blob.type || ('audio/'+ext)});
            })(), 'audio', id);
            lastAudioBlob = null;
          }
        }catch(e){ console.error('Audio upload failed:', e); }
      } else if(lastAudioBlob && !lastAudioUrl){
        try{
          const blob = lastAudioBlob;
          const mt = (blob && blob.type) ? (blob.type+'') : '';
          let ext = 'webm';
          if(mt){
            const low = mt.toLowerCase();
            if(low.indexOf('audio/wav') === 0) ext = 'wav';
            else if(low.indexOf('audio/mp4') === 0 || low.indexOf('audio/m4a') === 0 || low.indexOf('video/mp4') === 0) ext = 'mp4';
            else if(low.indexOf('audio/mpeg') === 0 || low.indexOf('audio/mp3') === 0) ext = 'mp3';
            else if(low.indexOf('audio/ogg') === 0) ext = 'ogg';
            else if(low.indexOf('audio/webm') === 0) ext = 'webm';
          }
          lastAudioUrl = await uploadMedia(new File([blob], 'audio.'+ext, {type: blob.type || ('audio/'+ext)}), 'audio', id);
          lastAudioBlob = null;
        }catch(e){ console.error('Audio upload failed (memory fallback):', e); }
      }

      const translations={};
      if(userLang2 !== 'en' && trLocal){ translations[userLang2]=trLocal; }
      if(trEn){ translations['en']=trEn; }
      const translationDisplay = (userLang2 !== 'en' ? (translations[userLang2] || translations['en'] || "") : (translations['en'] || ""));
      const payload={id,text,fokus,explanation:expl,translation:translationDisplay,translations,order:(orderChosen.length?orderChosen:[...DEFAULT_ORDER])};
      const focusBase=(document.getElementById('uFocusBase')?.value||'').trim();
      if(focusBase) payload.focusBase = focusBase;
      // Enrichment fields
      const trscr=(document.getElementById('uTranscription')?.value||'').trim(); if(trscr) payload.transcription=trscr;
      const posv=(document.getElementById('uPOS')?.value||''); if(posv) payload.pos=posv;
      const g=(document.getElementById('uGender')?.value||''); if(g && posv==='substantiv') payload.gender=g;
      if(posv==='substantiv'){
        const nf={
          indef_sg:(document.getElementById('uNounIndefSg')?.value||'').trim(),
          def_sg:(document.getElementById('uNounDefSg')?.value||'').trim(),
          indef_pl:(document.getElementById('uNounIndefPl')?.value||'').trim(),
          def_pl:(document.getElementById('uNounDefPl')?.value||'').trim(),
        };
        if(nf.indef_sg||nf.def_sg||nf.indef_pl||nf.def_pl){ payload.forms={noun:nf}; }
      }
      if(posv==='verb'){
        const vf={
          infinitiv:(document.getElementById('uVerbInfinitiv')?.value||'').trim(),
          presens:(document.getElementById('uVerbPresens')?.value||'').trim(),
          preteritum:(document.getElementById('uVerbPreteritum')?.value||'').trim(),
          perfektum_partisipp:(document.getElementById('uVerbPerfektum')?.value||'').trim(),
          imperativ:(document.getElementById('uVerbImperativ')?.value||'').trim(),
        };
        if(vf.infinitiv||vf.presens||vf.preteritum||vf.perfektum_partisipp||vf.imperativ){ payload.forms={verb:vf}; }
      }
      if(posv==='adjektiv'){
        const af={
          positiv:(document.getElementById('uAdjPositiv')?.value||'').trim(),
          komparativ:(document.getElementById('uAdjKomparativ')?.value||'').trim(),
          superlativ:(document.getElementById('uAdjSuperlativ')?.value||'').trim(),
        };
        if(af.positiv||af.komparativ||af.superlativ){ payload.forms={adj:af}; }
      }
      function linesToArr(id){ return (document.getElementById(id)?.value||'').split(/\r?\n/).map(s=>s.trim()).filter(Boolean); }
      const antonyms=linesToArr('uAntonyms'); if(antonyms.length) payload.antonyms=antonyms;

      // Collocations and Examples from dynamic lists (Extended Editor)
      if(collocationsData.length > 0) {
        payload.collocations = collocationsData.filter(item => item.no).map(item => {
          return item.trans ? `${item.no} | ${item.trans}` : item.no;
        });
      } else {
        // Fallback to Quick Input textarea (Advanced fields)
        const collocs=linesToArr('uCollocations'); if(collocs.length) payload.collocations=collocs;
      }

      if(examplesData.length > 0) {
        payload.examples = examplesData.filter(item => item.no).map(item => {
          return item.trans ? `${item.no} | ${item.trans}` : item.no;
        });
      } else {
        // Fallback to Quick Input textarea (Advanced fields)
        const examples=linesToArr('uExamples'); if(examples.length) payload.examples=examples;
      }

      const cognates=linesToArr('uCognates'); if(cognates.length) payload.cognates=cognates;
      const sayings=linesToArr('uSayings'); if(sayings.length) payload.sayings=sayings;
      if(lastImageUrl) payload.image=lastImageUrl; else if(lastImageKey) payload.imageKey=lastImageKey;
      if(lastAudioUrl){
        payload.audio=lastAudioUrl;
        payload.audioFront=lastAudioUrl;
      } else if(lastAudioKey){
        payload.audioKey=lastAudioKey;
      }
      if(focusAudioUrl){
        payload.focusAudio = focusAudioUrl;
      }

      // Save to server and get back the real deckId
      let serverDeckId = MY_DECK_ID;
      try{
        const result = await api('upsert_card', {}, 'POST', {deckId:null,cardId:id,scope:'private',payload});
        if(result && result.deckId) {
          serverDeckId = String(result.deckId);
        }
      }catch(e){
        console.error('upsert_card error:', e);
        // Check if it's an access error and provide specific messaging
        if(e.message && (e.message.includes('access') || e.message.includes('grace') || e.message.includes('blocked'))) {
          // Determine specific error message based on access status
          let errorMessage = "Access denied. Cannot create cards.";
          if (window.flashcardsAccessInfo) {
            const access = window.flashcardsAccessInfo;
            if (access.status === 'grace') {
              const daysLeft = access.days_remaining || 0;
              errorMessage = (M?.str?.mod_flashcards?.access_status_grace || 'Grace Period ({$a} days remaining)').replace('{$a}', daysLeft) +
                           ': ' + (M?.str?.mod_flashcards?.access_create_blocked || 'You cannot create new cards without an active course enrolment.');
            } else if (access.status === 'expired') {
              errorMessage = (M?.str?.mod_flashcards?.access_status_expired || 'Access Expired') +
                           ': ' + (M?.str?.mod_flashcards?.access_expired_message || 'You no longer have access to flashcards. Please enrol in a course to regain access.');
            }
          }
          $("#status").textContent = errorMessage;
          setTimeout(()=>$("#status").textContent="", 5000); // Show longer for access errors
          return; // STOP - don't save to localStorage
        }
        // For other errors (network, etc), continue with local save
      }

      // Also keep local for offline use (use server's deckId if available)
      const localDeckId = serverDeckId;
      if(!registry[localDeckId]) registry[localDeckId]={id:localDeckId,title:"My cards",cards:[]};

      // If editing AND updating: update existing card; else push new
      if(isUpdate && editingCardId){
        const existingIdx = registry[localDeckId].cards.findIndex(c => c.id === editingCardId);
        if(existingIdx >= 0){
          registry[localDeckId].cards[existingIdx] = {id,...payload};
        } else {
          registry[localDeckId].cards.push({id,...payload});
        }
      } else {
        registry[localDeckId].cards.push({id,...payload});
      }

      saveRegistry();

      const isNewCard = !isUpdate || !editingCardId;
      if(!state.decks[localDeckId]) state.decks[localDeckId]={};
      if(!state.decks[localDeckId][id]){
        state.decks[localDeckId][id]={step:0,due:today0(),addedAt:today0(),lastAt:null};
      }
      state.active[localDeckId]=true;
      saveState();

      resetForm();
      // Don't sync from server - we already have the latest data locally
      // await syncFromServer();
      refreshSelect();
      updateBadge();
      buildQueue();
      closeEditor();
      $("#status").textContent= isUpdate ? "Updated" : "Added";

      // Refresh stats if new card was created
      if(isNewCard){
        console.log('[SAVE] New card created, refreshing stats...');
        await refreshDashboardStats();
      }
      setTimeout(()=>$("#status").textContent="",1200);
    }

    $("#btnAdd").addEventListener("click", ()=> saveCard(false));
    if(btnUpdate) btnUpdate.addEventListener("click", ()=> saveCard(true));

    const listPlayer = new Audio();
    async function audioURLFromCard(c){ if(c.audioKey) return await urlForSafe(c.audioKey); return resolveAsset(c.audio) || null; }
    function fmtDateTime(ts){ return ts? new Date(ts).toLocaleString() : '-'; }

    // Format stage as inline pill (for cards list table)
    function formatStageBadge(step){
      if(step < 0) step = 0;
      if(step > 10){
        // Completed: green checkmark
        return '<span class="badge" style="background:#4caf50;color:#fff;padding:4px 8px;border-radius:12px;">&#10003;</span>';
      }
      // Stage 0-10: number with fill
      const fillPercent = step === 0 ? 0 : (step / 10) * 100;
      const textColor = step > 5 ? '#fff' : '#333';
      const bg = `linear-gradient(90deg, #2196f3 ${fillPercent}%, #e0e0e0 ${fillPercent}%)`;
      return `<span class="badge" style="background:${bg};color:${textColor};padding:4px 8px;border-radius:12px;">${step}</span>`;
    }
    // Pagination state for Cards List
    let listCurrentPage = 1;
    const LIST_PAGE_SIZE = 50; // Cards per page

    function buildListRows(){
      const tbody=$("#listTable tbody");
      tbody.innerHTML="";
      const rows=[];
      Object.values(registry||{}).forEach(d=>{
        ensureDeckProgress(d.id,d.cards);
        (d.cards||[]).forEach(c=>{
          const rec=state.decks[d.id][c.id];
          rows.push({deckTitle:d.title, deckId:d.id, id:c.id, card:c, front:c.text||c.front||"", stage:rec.step||0, added:rec.addedAt||null, due:rec.due||null});
        });
      });

      // Apply search filter
      const q=$("#listSearch").value.toLowerCase();
      let filtered=rows.filter(r=>!q || r.front.toLowerCase().includes(q) || (r.deckTitle||"").toLowerCase().includes(q));

      // Apply due date filter
      const dueFilter=$("#listFilterDue").value;
      if(dueFilter==='due'){
        const now=today0();
        filtered=filtered.filter(r=> (r.due ?? 0) <= now);
      }

      // Sort by due date
      filtered.sort((a,b)=> (a.due||0)-(b.due||0) );

      // Update count
      $("#listCount").textContent=filtered.length;

      // Calculate pagination
      const totalPages=Math.max(1, Math.ceil(filtered.length / LIST_PAGE_SIZE));
      if(listCurrentPage > totalPages) listCurrentPage = totalPages;
      if(listCurrentPage < 1) listCurrentPage = 1;

      const startIdx=(listCurrentPage - 1) * LIST_PAGE_SIZE;
      const endIdx=Math.min(startIdx + LIST_PAGE_SIZE, filtered.length);
      const paginated=filtered.slice(startIdx, endIdx);

      // Show/hide pagination controls
      if(filtered.length > LIST_PAGE_SIZE){
        $("#listPagination").style.display="flex";
        $("#pageInfo").textContent=`Page ${listCurrentPage} of ${totalPages}`;
        $("#btnPagePrev").disabled = listCurrentPage <= 1;
        $("#btnPageNext").disabled = listCurrentPage >= totalPages;
      } else {
        $("#listPagination").style.display="none";
      }

      // Render paginated rows
      paginated.forEach(async r=>{
        const tr=document.createElement("tr");
        const langs = Object.keys(r.card?.translations||{});
        tr.innerHTML=`<td>${r.front||"-"}</td><td>${formatStageBadge(r.stage)}</td><td>${fmtDateTime(r.due)}</td><td class="row playcell" style="gap:6px"></td>`;
        const cell=tr.lastElementChild;
        // Audio buttons removed per new UX
        const edit=document.createElement("button");
        edit.className="iconbtn";
        edit.textContent="\u270E";
        edit.title="Edit";
        edit.onclick=()=>{
          const pack=registry[r.deckId];
          const card=pack.cards.find(x=>x.id===r.id);
          if(card){
            // Set up for editing this card
            currentItem={deckId:r.deckId,card:normalizeLessonCard({...card}),rec:state.decks[r.deckId][r.id],index:0};
            visibleSlots=1;
            showCurrent();
            $("#listModal").style.display="none";
            // Trigger edit button to populate form
            $("#btnEdit").click();
          }
        };
        cell.appendChild(edit);
        const del=document.createElement("button");
        del.className="iconbtn";
        del.textContent="\u00D7";
        del.title="Delete";
        del.onclick=async()=>{
          if(!confirm("Delete this card?")) return;
          console.log('[DELETE-LIST] Deleting card:', r.id, 'from deck:', r.deckId);
          try {
            await api('delete_card', {deckid:r.deckId, cardid:r.id}, 'POST');
            console.log('[DELETE-LIST] Card deleted successfully');
          } catch(e) { console.error('[DELETE-LIST] API error:', e); }
          const arr=registry[r.deckId]?.cards||[];
          const ix=arr.findIndex(x=>x.id===r.id);
          if(ix>=0){arr.splice(ix,1); saveRegistry();}
          if(state.decks[r.deckId]) delete state.decks[r.deckId][r.id];
          if(state.hidden && state.hidden[r.deckId]) delete state.hidden[r.deckId][r.id];
          saveState();
          buildQueue();
          buildListRows();
          console.log('[DELETE-LIST] Refreshing dashboard...');
          await refreshDashboardStats();
          console.log('[DELETE-LIST] Dashboard refreshed');
        };
        cell.appendChild(del);
        tbody.appendChild(tr);
      });
    }
    $$("#btnList").forEach(function(_el){ _el.addEventListener("click",()=>{
      listCurrentPage = 1; // Reset to page 1 when opening modal
      $("#listModal").style.display="flex";
      buildListRows();
    }); });
    $("#btnCloseList").addEventListener("click",()=>{ $("#listModal").style.display="none"; });
    $("#listSearch").addEventListener("input", ()=>{
      listCurrentPage = 1; // Reset to page 1 when searching
      buildListRows();
    });
    $("#listFilterDue").addEventListener("change", ()=>{
      listCurrentPage = 1; // Reset to page 1 when filtering
      buildListRows();
    });
    $("#btnPagePrev").addEventListener("click", ()=>{
      if(listCurrentPage > 1) {
        listCurrentPage--;
        buildListRows();
      }
    });
    $("#btnPageNext").addEventListener("click", ()=>{
      listCurrentPage++;
      buildListRows();
    });

    document.addEventListener("keydown",e=>{ if($("#listModal").style.display==="flex") return; const tag=(e.target.tagName||"").toLowerCase(); if(tag==="input"||tag==="textarea"||e.target.isContentEditable) return; if(e.code==="Space"){ e.preventDefault(); const br=$("#btnRevealNext"); if(br && !br.disabled) br.click(); } if(e.key==="e"||e.key==="E") rateEasy(); if(e.key==="n"||e.key==="N") rateNormal(); if(e.key==="h"||e.key==="H") rateHard(); });

    try { if('serviceWorker' in navigator){ navigator.serviceWorker.register(baseurl + 'sw.js'); } } catch(e){}
    try { if (!document.querySelector('link[rel="manifest"]')) { const l=document.createElement('link'); l.rel='manifest'; l.href=baseurl + 'manifest.webmanifest'; document.head.appendChild(l);} } catch(e){}

    // Add iOS-specific meta tags for PWA
    try {
      if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
        const metaCapable = document.createElement('meta');
        metaCapable.name = 'apple-mobile-web-app-capable';
        metaCapable.content = 'yes';
        document.head.appendChild(metaCapable);
      }
      if (!document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]')) {
        const metaStatus = document.createElement('meta');
        metaStatus.name = 'apple-mobile-web-app-status-bar-style';
        metaStatus.content = 'black-translucent';
        document.head.appendChild(metaStatus);
      }
      if (!document.querySelector('meta[name="apple-mobile-web-app-title"]')) {
        const metaTitle = document.createElement('meta');
        metaTitle.name = 'apple-mobile-web-app-title';
        metaTitle.content = 'SRS Cards';
        document.head.appendChild(metaTitle);
      }
      if (!document.querySelector('link[rel="apple-touch-icon"]')) {
        const linkIcon = document.createElement('link');
        linkIcon.rel = 'apple-touch-icon';
        linkIcon.href = baseurl + 'icons/icon-192.png';
        document.head.appendChild(linkIcon);
      }
      debugLog('[PWA] iOS meta tags added');
    } catch(e) {
      debugLog('[PWA] Error adding iOS meta tags:', e);
    }

    // PWA Install Prompt
    let deferredInstallPrompt = null;
    const btnInstallApp = $("#btnInstallApp");

    // Debug: log button state on init
    debugLog('[PWA] Install button in DOM:', !!btnInstallApp);
    debugLog('[PWA] Service Worker support:', 'serviceWorker' in navigator);
    debugLog('[PWA] User agent:', navigator.userAgent);

    window.addEventListener('beforeinstallprompt', (e) => {
      // Prevent Chrome 67 and earlier from automatically showing the prompt
      e.preventDefault();
      // Stash the event so it can be triggered later
      deferredInstallPrompt = e;
      // Show install button
      if(btnInstallApp) {
        btnInstallApp.classList.remove('hidden');
        debugLog('[PWA] Install prompt available - button shown');
      } else {
        console.error('[PWA] Button not found in DOM!');
      }
    });

    if(btnInstallApp) {
      btnInstallApp.addEventListener('click', async () => {
        if(!deferredInstallPrompt) {
          debugLog('[PWA] No install prompt available');
          return;
        }
        // Show the install prompt
        deferredInstallPrompt.prompt();
        // Wait for the user to respond to the prompt
        const { outcome } = await deferredInstallPrompt.userChoice;
        debugLog(`[PWA] User choice: ${outcome}`);
        if(outcome === 'accepted') {
          debugLog('[PWA] User accepted the install prompt');
        }
        // Clear the deferred prompt
        deferredInstallPrompt = null;
        // Hide install button
        btnInstallApp.classList.add('hidden');
      });
    }

    // Hide install button if already installed
    window.addEventListener('appinstalled', () => {
      debugLog('[PWA] App successfully installed');
      if(btnInstallApp) btnInstallApp.classList.add('hidden');
      deferredInstallPrompt = null;
    });

    // iOS Install Hint (since iOS doesn't support beforeinstallprompt)
    const iosInstallHint = $("#iosInstallHint");
    const iosHintClose = $("#iosHintClose");
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;
    const hintDismissedKey = 'ios-install-hint-dismissed';

    debugLog('[PWA] iOS device:', isIOS);
    debugLog('[PWA] Standalone mode:', isInStandaloneMode);

    // Check if user previously dismissed the hint
    const isHintDismissed = localStorage.getItem(hintDismissedKey) === 'true';

    if(iosInstallHint && isIOS && !isInStandaloneMode && !isHintDismissed) {
      // Show iOS install hint for iOS users who haven't installed the app yet
      iosInstallHint.classList.remove('hidden');
      debugLog('[PWA] iOS install hint shown');
    } else if(iosInstallHint) {
      debugLog('[PWA] iOS hint hidden (not iOS, already installed, or dismissed by user)');
    }

    // Handle close button click
    if(iosHintClose) {
      iosHintClose.addEventListener('click', () => {
        if(iosInstallHint) {
          iosInstallHint.classList.add('hidden');
          localStorage.setItem(hintDismissedKey, 'true');
          debugLog('[PWA] iOS hint dismissed by user');
        }
      });
    }

    if(!localStorage.getItem(PROFILE_KEY)) localStorage.setItem(PROFILE_KEY,"Guest");
    // Profile selector (optional UI element)
    const profileSel = $("#profileSel");
    if(profileSel){
      (function(){ const sel=profileSel; const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]'); const cur=localStorage.getItem(PROFILE_KEY)||"Guest"; if(!list.includes(cur)) list.push(cur); localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)])); sel.innerHTML=""; JSON.parse(localStorage.getItem("srs-profiles")).forEach(p=>{const o=document.createElement("option");o.value=p;o.textContent=p;sel.appendChild(o);}); sel.value=cur; })();
      profileSel.addEventListener("change",e=>{ localStorage.setItem(PROFILE_KEY,e.target.value); loadState(); refreshSelect(); updateBadge(); buildQueue(); });
    }
    const btnAddProfile = $("#btnAddProfile");
    if(btnAddProfile){
      btnAddProfile.addEventListener("click",()=>{ const name=prompt("Profile name:",""); if(!name)return; const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]'); list.push(name); localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)])); localStorage.setItem(PROFILE_KEY,name); const sel=$("#profileSel"); if(sel){ sel.innerHTML=""; JSON.parse(localStorage.getItem("srs-profiles")).forEach(p=>{const o=document.createElement("option");o.value=p;o.textContent=p;sel.appendChild(o);}); sel.value=name; } loadState(); refreshSelect(); updateBadge(); buildQueue(); });
    }

    // Initialize form: disable Update button by default
    if(btnUpdate) btnUpdate.disabled = true;

    // Stage-based prompts: ask to fill missing fields per step
    const shownPrompts = new Set();
    function promptKey(deckId, cardId, step){ return `${deckId}::${cardId}::${step}`; }
    function firstMissingForStep(card){
      const order = ['transcription','pos','gender_or_forms','examples','collocations','antonyms','cognates','sayings'];
      for(const key of order){
        if(key==='gender_or_forms'){
          if((card.pos||'').toLowerCase()==='substantiv'){
            const hasGender = !!(card.gender);
            const nf = card.forms && card.forms.noun || {};
            const hasForms = !!(nf.indef_sg||nf.def_sg||nf.indef_pl||nf.def_pl);
            if(!hasGender) return 'gender';
            if(!hasForms) return 'nounForms';
          }
        } else {
          if(key==='transcription' && !card.transcription) return 'transcription';
          if(key==='pos' && !card.pos) return 'pos';
          if(key==='examples' && (!Array.isArray(card.examples) || card.examples.length===0)) return 'examples';
          if(key==='collocations' && (!Array.isArray(card.collocations) || card.collocations.length===0)) return 'collocations';
          if(key==='antonyms' && (!Array.isArray(card.antonyms) || card.antonyms.length===0)) return 'antonyms';
          if(key==='cognates' && (!Array.isArray(card.cognates) || card.cognates.length===0)) return 'cognates';
          if(key==='sayings' && (!Array.isArray(card.sayings) || card.sayings.length===0)) return 'sayings';
        }
      }
      return null;
    }
    function openFieldPrompt(field, card){
      const fp = document.getElementById('fieldPrompt');
      const body = document.getElementById('fpBody');
      const title = document.getElementById('fpTitle');
      if(!fp||!body||!title) return;
      body.innerHTML = '';
      let editor = null;
      function inputEl(type='text'){ const i=document.createElement('input'); i.type=type; i.style.width='100%'; i.style.padding='10px'; i.style.background='#0b1220'; i.style.color='#f1f5f9'; i.style.border='1px solid #374151'; i.style.borderRadius='10px'; return i; }
      function textareaEl(){ const t=document.createElement('textarea'); t.className='autogrow'; t.style.width='100%'; t.style.minHeight='44px'; t.style.padding='10px'; t.style.background='#0b1220'; t.style.color='#f1f5f9'; t.style.border='1px solid #374151'; t.style.borderRadius='10px'; return t; }
      const labelMap = {
        transcription: t('transcription'),
        pos: t('pos'),
        gender: t('gender'),
        nounForms: t('noun_forms'),
        examples: t('examples'),
        collocations: t('collocations'),
        antonyms: t('antonyms'),
        cognates: t('cognates') || 'Cognates',
        sayings: t('sayings') || 'Common sayings'
      };
      title.textContent = t('fill_field').replace('{$a}', labelMap[field] || field);
      if(field==='transcription'){
        editor = inputEl('text'); editor.value = card.transcription||''; body.appendChild(editor);
      } else if(field==='pos'){
        const sel=document.createElement('select'); sel.id='fpPOS'; sel.innerHTML = `
          <option value="">-</option>
          <option value="substantiv">Substantiv</option>
          <option value="verb">${M?.str?.mod_flashcards?.pos_verb||'Verb'}</option>
          <option value="adj">${M?.str?.mod_flashcards?.pos_adj||'Adjective'}</option>
          <option value="adv">${M?.str?.mod_flashcards?.pos_adv||'Adverb'}</option>
          <option value="other">${M?.str?.mod_flashcards?.pos_other||'Other'}</option>`;
        sel.value = card.pos||''; editor = sel; body.appendChild(editor);
      } else if(field==='gender'){
        const sel=document.createElement('select'); sel.id='fpGender'; sel.innerHTML = `
          <option value="">-</option>
          <option value="intetkjonn">${M?.str?.mod_flashcards?.gender_neuter||'Neuter'}</option>
          <option value="hankjonn">${M?.str?.mod_flashcards?.gender_masculine||'Masculine'}</option>
          <option value="hunkjonn">${M?.str?.mod_flashcards?.gender_feminine||'Feminine'}</option>`;
        sel.value = card.gender||''; editor = sel; body.appendChild(editor);
      } else if(field==='nounForms'){
        const wrap=document.createElement('div'); wrap.style.display='grid'; wrap.style.gap='6px';
        function row(lbl,id,val){ const r=document.createElement('div'); r.innerHTML = `<div class="small">${lbl}</div>`; const i=inputEl('text'); i.id=id; i.value=val||''; r.appendChild(i); return r; }
        const nf = (card.forms&&card.forms.noun) || {};
        wrap.appendChild(row(M?.str?.mod_flashcards?.indef_sg||'Indefinite singular','fpNfIndefSg', nf.indef_sg));
        wrap.appendChild(row(M?.str?.mod_flashcards?.def_sg||'Definite singular','fpNfDefSg', nf.def_sg));
        wrap.appendChild(row(M?.str?.mod_flashcards?.indef_pl||'Indefinite plural','fpNfIndefPl', nf.indef_pl));
        wrap.appendChild(row(M?.str?.mod_flashcards?.def_pl||'Definite plural','fpNfDefPl', nf.def_pl));
        editor = wrap; body.appendChild(editor);
      } else {
        editor = textareaEl(); const arr = Array.isArray(card[field]) ? card[field] : []; editor.value = arr.join('\n'); body.appendChild(editor);
      }
      initAutogrow();
      fp.style.display='flex';
      function close(){ fp.style.display='none'; }
      const btnClose = document.getElementById('fpClose'); if(btnClose){ btnClose.onclick = close; }
      const btnSkip = document.getElementById('fpSkip'); if(btnSkip){ btnSkip.onclick = close; }
      const btnSave = document.getElementById('fpSave');
      if(btnSave){ btnSave.onclick = async ()=>{
        let updated = false;
        if(field==='transcription'){
          const v = editor.value.trim(); if(v){ currentItem.card.transcription = v; updated = true; }
        } else if(field==='pos'){
          const v = editor.value; currentItem.card.pos = v; updated = true;
        } else if(field==='gender'){
          const v = editor.value; currentItem.card.gender = v; updated = true;
        } else if(field==='nounForms'){
          const nf = {indef_sg: document.getElementById('fpNfIndefSg').value.trim(), def_sg: document.getElementById('fpNfDefSg').value.trim(), indef_pl: document.getElementById('fpNfIndefPl').value.trim(), def_pl: document.getElementById('fpNfDefPl').value.trim()};
          currentItem.card.forms = currentItem.card.forms || {}; currentItem.card.forms.noun = nf; updated = true;
        } else {
          const lines = editor.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
          currentItem.card[field] = lines; updated = true;
        }
        if(updated){
          try{
            const payload = buildPayloadFromCard(currentItem.card);
            await api('upsert_card', {}, 'POST', { deckId: currentItem.deckId, cardId: currentItem.card.id, scope: currentItem.card.scope||'private', payload });
          }catch(_e){}
        }
        close();
      }; }
    }
    function maybePromptForStage(){ if(!currentItem) return; if(currentItem.card && currentItem.card.scope !== 'private') return; const step=currentItem.rec?.step||0; const pkey=promptKey(currentItem.deckId,currentItem.card.id,step); if(shownPrompts.has(pkey)) return; const field=firstMissingForStep(currentItem.card); if(!field) return; shownPrompts.add(pkey); openFieldPrompt(field,currentItem.card); }
    function buildPayloadFromCard(c){
      const p={ id:c.id, text:c.text||'', fokus:c.fokus||'', explanation:c.explanation||'', translation:c.translation||'', translations:c.translations||{}, order:Array.isArray(c.order)?c.order:[...DEFAULT_ORDER] };
      if(c.focusBase) p.focusBase = c.focusBase;
      if(c.image) p.image=c.image;
      if(c.imageKey) p.imageKey=c.imageKey;
      if(c.audio) p.audio=c.audio;
      if(c.audioFront) p.audioFront=c.audioFront;
      if(c.audioKey) p.audioKey=c.audioKey;
      if(c.focusAudio) p.focusAudio=c.focusAudio;
      if(c.focusAudioKey) p.focusAudioKey=c.focusAudioKey;
      if(c.transcription) p.transcription=c.transcription;
      if(c.pos) p.pos=c.pos;
      if(c.gender) p.gender=c.gender;
      if(c.forms) p.forms=c.forms;
      if(Array.isArray(c.antonyms)) p.antonyms=c.antonyms;
      if(Array.isArray(c.collocations)) p.collocations=c.collocations;
      if(Array.isArray(c.examples)) p.examples=c.examples;
      if(Array.isArray(c.cognates)) p.cognates=c.cognates;
      if(Array.isArray(c.sayings)) p.sayings=c.sayings;
      return p;
    }

    // Auto-clear cache on plugin version update
    const CACHE_VERSION = "2025103107"; // Must match version.php
    const currentCacheVersion = localStorage.getItem("flashcards-cache-version");
    if (currentCacheVersion !== CACHE_VERSION) {
      debugLog(`[Flashcards] Cache version mismatch: ${currentCacheVersion} -> ${CACHE_VERSION}. Clearing cache...`);
      // Clear all flashcards-related localStorage keys
      const keysToRemove = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && (key.startsWith('srs-v6:') || key === 'srs-profile' || key === 'srs-profiles')) {
          keysToRemove.push(key);
        }
      }
      keysToRemove.forEach(key => localStorage.removeItem(key));

      // Clear IndexedDB media cache
      try {
        indexedDB.deleteDatabase("srs-media");
      } catch(e) {
        console.warn('[Flashcards] Failed to clear IndexedDB:', e);
      }

      // Set new version
      localStorage.setItem("flashcards-cache-version", CACHE_VERSION);
      debugLog('[Flashcards] Cache cleared successfully');
    }

    // Check access permissions and show status banner
    if (window.flashcardsAccessInfo) {
      const access = window.flashcardsAccessInfo;
      debugLog('[Flashcards] Access info:', access);

      // Show access status banner
      showAccessStatusBanner(access);

      if (!access.can_create) {
        // Hide card creation form during grace period or when access expired
        const formEl = $("#cardCreationForm");
        if (formEl) {
          formEl.style.display = 'none';
          debugLog('[Flashcards] Card creation form hidden (can_create=false)');
        }
      }
    }

    // Function to show access status banner
    function showAccessStatusBanner(access) {
      const banner = $("#accessStatusBanner");
      const title = $("#accessStatusTitle");
      const desc = $("#accessStatusDesc");
      const icon = $("#accessStatusIcon");
      const actions = $("#accessStatusActions");

      if (!banner || !title || !desc || !icon) return;

      // Clear previous content
      actions.innerHTML = '';

      // Determine status and styling
      let statusClass = '';
      let statusTitle = '';
      let statusDesc = '';
      let statusIcon = '';

      if (access.status === 'active') {
        statusClass = 'access-active';
        statusTitle = M?.str?.mod_flashcards?.access_status_active || 'Active Access';
        statusDesc = M?.str?.mod_flashcards?.access_status_active_desc || 'You have full access to create and review flashcards.';
        statusIcon = '✅';
      } else if (access.status === 'grace') {
        statusClass = 'access-grace';
        const daysLeft = access.days_remaining || 0;
        statusTitle = (M?.str?.mod_flashcards?.access_status_grace || 'Grace Period ({$a} days remaining)').replace('{$a}', daysLeft);
        statusDesc = M?.str?.mod_flashcards?.access_status_grace_desc || 'You can review your existing cards but cannot create new ones. Enrol in a course to restore full access.';
        statusIcon = '⏰';

        // Add enrol button for grace period
        const enrolBtn = document.createElement('button');
        enrolBtn.className = 'btn-secondary';
        enrolBtn.textContent = M?.str?.mod_flashcards?.access_enrol_now || 'Enrol in a Course';
        enrolBtn.onclick = () => {
          // Redirect to course enrolment page or show message
          alert(M?.str?.mod_flashcards?.access_enrol_now || 'Please enrol in a course to restore full access.');
        };
        actions.appendChild(enrolBtn);
      } else if (access.status === 'expired') {
        statusClass = 'access-expired';
        statusTitle = M?.str?.mod_flashcards?.access_status_expired || 'Access Expired';
        statusDesc = M?.str?.mod_flashcards?.access_status_expired_desc || 'Your access has expired. Enrol in a course to regain access to flashcards.';
        statusIcon = '❌';

        // Add enrol button for expired access
        const enrolBtn = document.createElement('button');
        enrolBtn.className = 'btn-primary';
        enrolBtn.textContent = M?.str?.mod_flashcards?.access_enrol_now || 'Enrol in a Course';
        enrolBtn.onclick = () => {
          // Redirect to course enrolment page or show message
          alert(M?.str?.mod_flashcards?.access_enrol_now || 'Please enrol in a course to regain access.');
        };
        actions.appendChild(enrolBtn);
      }

      // Apply styling and content
      banner.className = `access-status-banner ${statusClass}`;
      title.textContent = statusTitle;
      desc.textContent = statusDesc;
      icon.textContent = statusIcon;

      // Show banner
      banner.classList.remove('hidden');
      debugLog('[Access Banner] Showing banner with status:', access.status);
    }

    loadState(); (async()=>{
      await syncFromServer();

      // Debug: log what we got from server
      debugLog('[Flashcards] Registry after sync:', Object.keys(registry || {}).map(id => ({
        id,
        title: registry[id].title,
        cardCount: registry[id].cards?.length || 0
      })));

      // Auto-activate ALL decks that have cards (from server or local)
      if(!state.active) state.active={};

      // Activate all decks in registry that have cards
      Object.keys(registry || {}).forEach(deckId => {
        if(registry[deckId].cards && registry[deckId].cards.length > 0) {
          state.active[deckId] = true;
          debugLog(`[Flashcards] Auto-activated deck: ${deckId} (${registry[deckId].cards.length} cards)`);
        }
      });

      saveState();
      refreshSelect();
      updateBadge();
      buildQueue();

      debugLog('[Flashcards] Active decks:', Object.keys(state.active || {}).filter(id => state.active[id]));
    })();

    // ========== TAB NAVIGATION & DASHBOARD (v0.7.0) ==========
    (function initTabs() {
      const tabQuickInput = $('#tabQuickInput');
      const tabStudy = $('#tabStudy');
      const tabDashboard = $('#tabDashboard');
      const quickInputSection = $('#quickInputSection');
      const studySection = $('#studySection');
      const dashboardSection = $('#dashboardSection');
      const quickInputBadge = $('#quickInputBadge');

      if (!tabQuickInput || !tabStudy || !tabDashboard) {
        debugLog('[Tabs] Tab elements not found, skipping tab init');
        return;
      }

      // Helper: Switch to a tab
      function switchTab(tabName) {
        console.log('[Tabs] switchTab called with:', tabName);
        console.log('[Tabs] Elements:', {
          quickInputSection: !!quickInputSection,
          studySection: !!studySection,
          dashboardSection: !!dashboardSection,
          tabQuickInput: !!tabQuickInput,
          tabStudy: !!tabStudy,
          tabDashboard: !!tabDashboard
        });

        // Hide all sections
        [quickInputSection, studySection, dashboardSection].forEach(el => {
          if (el) {
            el.classList.remove('fc-tab-active');
            console.log('[Tabs] Removed fc-tab-active from', el.id);
          }
        });

        // Deactivate all tabs
        [tabQuickInput, tabStudy, tabDashboard].forEach(el => {
          if (el) {
            el.classList.remove('fc-tab-active');
            el.setAttribute('aria-selected', 'false');
            console.log('[Tabs] Deactivated tab', el.id);
          }
        });

        // Activate selected tab
        if (tabName === 'quickInput' && quickInputSection) {
          quickInputSection.classList.add('fc-tab-active');
          tabQuickInput.classList.add('fc-tab-active');
          tabQuickInput.setAttribute('aria-selected', 'true');
          console.log('[Tabs] Activated Quick Input');
          // Auto-focus text input
          const frontInput = $('#uFront');
          if (frontInput) setTimeout(() => frontInput.focus(), 100);
        } else if (tabName === 'study' && studySection) {
          studySection.classList.add('fc-tab-active');
          tabStudy.classList.add('fc-tab-active');
          tabStudy.setAttribute('aria-selected', 'true');
          console.log('[Tabs] Activated Study');
          // Study view will auto-refresh from existing queue
        } else if (tabName === 'dashboard' && dashboardSection) {
          dashboardSection.classList.add('fc-tab-active');
          tabDashboard.classList.add('fc-tab-active');
          tabDashboard.setAttribute('aria-selected', 'true');
          console.log('[Tabs] Activated Dashboard');
          // Load dashboard data
          loadDashboard();
        }

        // Show/hide bottom action bar based on active tab
        const bottomActions = document.getElementById('bottomActions');
        if (bottomActions) {
          if (tabName === 'study') {
            // Show rating buttons on Study tab
            bottomActions.classList.remove('hidden');
            if (root) root.setAttribute('data-bottom-visible', '1');
          } else {
            // Hide on Quick Input and Dashboard tabs
            bottomActions.classList.add('hidden');
            if (root) root.removeAttribute('data-bottom-visible');
          }
        }

        debugLog(`[Tabs] Switched to ${tabName}`);
      }

      // Tab click handlers
      if (tabQuickInput) {
        tabQuickInput.addEventListener('click', () => {
          console.log('[Tabs] Quick Input tab clicked');
          switchTab('quickInput');
        });
      }
      if (tabStudy) {
        tabStudy.addEventListener('click', () => {
          console.log('[Tabs] Study tab clicked');
          switchTab('study');
        });
      }
      if (tabDashboard) {
        tabDashboard.addEventListener('click', () => {
          console.log('[Tabs] Dashboard tab clicked');
          switchTab('dashboard');
        });
      }

      // Update Quick Input badge (cards created today)
      function updateQuickInputBadge() {
        if (!quickInputBadge) return;
        // This will be updated when dashboard data is loaded
        // For now, placeholder
        quickInputBadge.textContent = '';
      }

      // Load Dashboard Data
      async function loadDashboard() {
        try {
          // baseurl = '/mod/flashcards/app/', so go up one level for ajax.php
          const ajaxUrl = baseurl.replace(/\/app\/?$/, '') + '/ajax.php';
          const resp = await fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              sesskey,
              cmid: isGlobalMode ? 0 : cmid,
              action: 'get_dashboard_data'
            })
          });
          const json = await resp.json();
          if (!json.ok) throw new Error('Dashboard API failed');

          const data = json.data;
          debugLog('[Dashboard] Loaded data:', data);
          const dueToday = data.stats.dueToday || 0;
          const activeVocabValue = data.stats.activeVocab ?? 0;

          // Update stats
          const statTotalCards = $('#statTotalCards');
          const statStreak = $('#statStreak');
          const statActiveVocab = $('#statActiveVocab');

          if (statTotalCards) statTotalCards.textContent = data.stats.totalCardsCreated || 0;
          if (statStreak) statStreak.textContent = data.stats.currentStreak || 0;
          if (statActiveVocab) statActiveVocab.textContent = formatActiveVocab(activeVocabValue);

          // Update header stats
          const headerTotalCards = $('#headerTotalCards');
          const headerStreak = $('#headerStreak');
          const headerActiveVocab = $('#headerActiveVocab');
          if (headerTotalCards) headerTotalCards.textContent = data.stats.totalCardsCreated || 0;
          if (headerStreak) headerStreak.textContent = data.stats.currentStreak || 0;
          if (headerActiveVocab) headerActiveVocab.textContent = formatActiveVocab(activeVocabValue);

          // Update Study badge (due cards count)
          const studyBadge = $('#studyBadge');
          if (studyBadge) {
            studyBadge.textContent = dueToday > 0 ? String(dueToday) : '';
            studyBadge.classList.toggle('hidden', dueToday <= 0);
          }

          // Render charts
          renderStageChart(data.stageDistribution);
          renderActivityChart(data.activityData);

          // Update achievements
          updateAchievements(data.stats);
        } catch (err) {
          debugLog('[Dashboard] Error loading data:', err);
        }
      }

      // Render Stage Distribution Chart (Chart.js)
      function renderStageChart(stageData) {
        const canvas = $('#stageChart');
        if (!canvas || typeof Chart === 'undefined') {
          debugLog('[Dashboard] Chart.js not loaded or canvas not found');
          return;
        }

        const labels = stageData.map(d => `Stage ${d.stage}`);
        const data = stageData.map(d => d.count);
        const stageEmojis = ['🌰', '🌱', '🌿', '☘️', '🍀', '🌷', '🌼', '🌳', '🌴', '✅', '🏆', '👑'];

        new Chart(canvas, {
          type: 'doughnut',
          data: {
            labels: labels.map((label, i) => `${stageEmojis[i] || ''} ${label}`),
            datasets: [{
              data: data,
              backgroundColor: [
                '#8B4513', '#90EE90', '#32CD32', '#228B22', '#00FF00',
                '#FFB6C1', '#FFD700', '#00CED1', '#1E90FF', '#FF69B4'
              ]
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'right' }
            }
          }
        });
      }

      // Render Activity Chart (Chart.js)
      function renderActivityChart(activityData) {
        const canvas = $('#activityChart');
        if (!canvas || typeof Chart === 'undefined') return;

        const labels = activityData.map(d => {
          const date = new Date(d.date * 1000);
          return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const reviews = activityData.map(d => d.reviews);
        const cardsCreated = activityData.map(d => d.cardsCreated);

        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Reviews',
                data: reviews,
                backgroundColor: '#3B82F6'
              },
              {
                label: 'Cards Created',
                data: cardsCreated,
                backgroundColor: '#10B981'
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
          }
        });
      }

      // Update Achievement Progress
      function updateAchievements(stats) {
        const activeVocab = Math.round(stats.activeVocab || 0);

        const achievements = [
          { id: 2, threshold: 7, current: stats.currentStreak, icon: '🔥' },

          // Language Level Achievements (based on Active Vocabulary)
          { id: 5, threshold: 100, current: activeVocab, icon: '🌱' },  // A0
          { id: 6, threshold: 600, current: activeVocab, icon: '🌿' },  // A1
          { id: 7, threshold: 1500, current: activeVocab, icon: '🍀' }, // A2
          { id: 8, threshold: 2500, current: activeVocab, icon: '🌳' }, // B1
          { id: 9, threshold: 4500, current: activeVocab, icon: '🏆' }  // B2
        ];

        achievements.forEach(ach => {
          const card = $(`#achievement${ach.id}`);
          const progress = $(`#ach${ach.id}Progress`);
          if (!card || !progress) return;

          const completed = ach.current >= ach.threshold;
          if (completed) {
            card.classList.add('fc-achievement-completed');
            progress.textContent = '✅ Completed';
          } else {
            progress.textContent = `${ach.current}/${ach.threshold}`;
          }
        });
      }

      // Initialize: Default to Quick Input tab
      switchTab('quickInput');

      // Auto-sync stats with database on EVERY page load
      console.log('[INIT] Synchronizing stats with database...');
      (async () => {
        try {
          await api('recalculate_stats', {}, 'POST');
          console.log('[INIT] Stats synchronized with database successfully');
          // Refresh dashboard to show updated stats
          await loadDashboard();
        } catch (e) {
          console.error('[INIT] Stats sync failed:', e);
          // Still load dashboard even if sync failed
          loadDashboard();
        }
      })();
    })();

    // ========== QUICK EDITOR PANEL ==========
    (function initEditorPanel() {
      setAdvancedVisibility(false);
      const toggleBtn = $('#btnEditorAdvanced');
      if (toggleBtn && !toggleBtn.dataset.bound) {
        toggleBtn.dataset.bound = '1';
        toggleBtn.addEventListener('click', e => {
          try{ e.preventDefault(); }catch(_e){}
          setAdvancedVisibility(!advancedVisible);
        });
      }
    })();

    // ========== INITIALIZE INTERFACE LANGUAGE SELECTOR ==========
    (function initInterfaceLangSelector(){
      const langSelEl = document.getElementById('langSel');
      if(langSelEl){
        // Set current value to saved interface language
        langSelEl.value = currentInterfaceLang || 'en';
        
        // Add change event listener
                langSelEl.addEventListener('change', function(){
          const newLang = this.value;
          if(!newLang || newLang === currentInterfaceLang){
            return;
          }
          currentInterfaceLang = newLang;
          saveInterfaceLang(newLang);
          updateInterfaceTexts();
          userLang2 = newLang;
          try{ saveTransLang(newLang); }catch(_e){}
          if(typeof updateTranslationLangUI === 'function'){
            updateTranslationLangUI();
          }
          if(typeof updateTranslationModeLabels === 'function'){
            updateTranslationModeLabels();
          }
          if(typeof scheduleTranslationRefresh === 'function'){
            scheduleTranslationRefresh();
          }
          const langNames = {
            en: 'English', uk: 'Українська', ru: 'Українська',
            fr: 'Français', es: 'Español', pl: 'Polski',
            it: 'Italiano', de: 'Deutsch'
          };
          const langName = langNames[newLang] || newLang;
          const emptyEl = $('#emptyMessage');
          if(emptyEl){
            const oldText = emptyEl.textContent;
            emptyEl.textContent = 'Interface language: ' + langName;
            setTimeout(function(){ emptyEl.textContent = oldText || t('empty'); }, 2000);
          }
        });

      }
      
      // Apply interface translations on initial load
      updateInterfaceTexts();
    })();
    // ========== END INTERFACE LANGUAGE SELECTOR ==========

  }
export { flashcardsInit };
