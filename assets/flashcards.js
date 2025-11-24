/* global M */
import { log as baseDebugLog } from './modules/debug.js';
import { idbPut, idbGet, urlFor } from './modules/storage.js';
import { createIOSRecorder } from './modules/recorder.js';

function flashcardsInit(rootid, baseurl, cmid, instanceid, sesskey, globalMode){
    const root = document.getElementById(rootid);
    if(!root) return;
    const $ = s => root.querySelector(s);
    const $$ = s => Array.from(root.querySelectorAll(s));
    const getModString = key => {
      try{
        return (M && M.str && M.str.mod_flashcards && M.str.mod_flashcards[key]) || '';
      }catch(_e){
        return '';
      }
    };
    const uniq = a => [...new Set(a.filter(Boolean))];
    const formatActiveVocab = value => {
      const numeric = Math.round(Number(value) || 0);
      return numeric.toString();
    };
    function formatFileSize(bytes){
      if(!Number.isFinite(bytes) || bytes <= 0){
        return '';
      }
      const units = ['B','kB','MB','GB','TB'];
      let index = 0;
      let value = bytes;
      while(value >= 1024 && index < units.length - 1){
        value /= 1024;
        index++;
      }
      const display = index > 0 ? value.toFixed(1) : value.toFixed(0);
      return `${display} ${units[index]}`;
    }

    const TEXTAREA_VALUE_HOOK_FLAG = '__flashcardsAutogrowValueHooked';
    (function ensureAutogrowValueRefresh(){
      if(HTMLTextAreaElement.prototype[TEXTAREA_VALUE_HOOK_FLAG]) {
        return;
      }
      const descriptor = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
      if(!descriptor || !descriptor.get || !descriptor.set) {
        return;
      }
      const { get, set } = descriptor;
      Object.defineProperty(HTMLTextAreaElement.prototype, 'value', {
        configurable: descriptor.configurable ?? true,
        enumerable: descriptor.enumerable ?? false,
        get(){
          return get.call(this);
        },
        set(value){
          const previous = get.call(this);
          set.call(this, value);
          if(this.classList && this.classList.contains('autogrow') && previous !== value) {
            this.dispatchEvent(new Event('autogrow:refresh'));
          }
        }
      });
      HTMLTextAreaElement.prototype[TEXTAREA_VALUE_HOOK_FLAG] = true;
    })();

    function initAutogrow(){
      const parsePx = value => Number.parseFloat(value) || 0;
      const getLineCount = value => {
        if (!value) {
          return 0;
        }
        const normalized = value.replace(/\r/g, '').replace(/\n+$/,'');
        if (!normalized) {
          return 1;
        }
        const segments = normalized.split('\n');
        const filled = segments.filter(segment => segment.trim().length > 0).length;
        const lineCount = Math.max(filled, segments.length);
        return lineCount || 1;
      };
      $$('.autogrow').forEach(el=>{
        if(!el || el.dataset.autogrowBound) return;
        const resize = ()=>{
          if(!el) return;
          const style = window.getComputedStyle(el);
          const lineHeight = parsePx(style.lineHeight) || parsePx(style.fontSize) || 16;
          const verticalPad = parsePx(style.paddingTop) + parsePx(style.paddingBottom);
          const verticalBorder = parsePx(style.borderTopWidth) + parsePx(style.borderBottomWidth);
          const baseMinHeight = parsePx(style.minHeight);
          const singleLineHeight = baseMinHeight > 0 ? baseMinHeight : (lineHeight + verticalPad + verticalBorder);
          const visibleLines = Math.max(1, getLineCount(el.value || ''));
          const extraLines = Math.max(0, visibleLines - 1);
          const targetFromLines = singleLineHeight + (extraLines * lineHeight);
          el.style.height = 'auto';
          const scrollBased = el.scrollHeight || 0;
          const next = Math.max(targetFromLines, scrollBased, baseMinHeight);
          el.style.height = `${next}px`;
        };
        ['input','change','paste','autogrow:refresh','focus','blur'].forEach(evt=>{
          el.addEventListener(evt, resize);
        });
        el.dataset.autogrowBound = '1';
        // Force resize on next frame to ensure layout is ready
        requestAnimationFrame(() => {
          resize();
          // Second resize after a small delay for mobile devices
          setTimeout(resize, 100);
        });
      });
    }

    initAutogrow();

    // Initialize textarea clear buttons
    function initTextareaClearButtons(){
      $$('.autogrow').forEach(el=>{
        if(!el || el.dataset.clearBound) return;
        // Skip if already wrapped
        if(el.parentElement && el.parentElement.classList.contains('textarea-wrap')) return;

        // Create wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'textarea-wrap';

        // Create clear button
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'textarea-clear';
        clearBtn.innerHTML = '×';
        clearBtn.title = 'Clear';
        clearBtn.setAttribute('aria-label', 'Clear field');

        // Wrap textarea
        el.parentNode.insertBefore(wrapper, el);
        wrapper.appendChild(el);
        wrapper.appendChild(clearBtn);

        // Handle click
        clearBtn.addEventListener('click', (e)=>{
          e.preventDefault();
          el.value = '';
          el.dispatchEvent(new Event('input', {bubbles: true}));
          el.dispatchEvent(new Event('autogrow:refresh'));
          el.focus();
        });

        el.dataset.clearBound = '1';
      });
    }

    initTextareaClearButtons();

    // Re-run autogrow on window resize and orientation change (for mobile devices)
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        $$('.autogrow').forEach(el => {
          if(el && el.dataset.autogrowBound) {
            el.dispatchEvent(new Event('autogrow:refresh'));
          }
        });
      }, 150);
    });
    window.addEventListener('orientationchange', () => {
      setTimeout(() => {
        $$('.autogrow').forEach(el => {
          if(el && el.dataset.autogrowBound) {
            el.dispatchEvent(new Event('autogrow:refresh'));
          }
        });
      }, 300);
    });

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
        // Re-calculate textarea heights after font scale change
        setTimeout(() => {
          $$('.autogrow').forEach(el => {
            if(el && el.dataset.autogrowBound) {
              el.dispatchEvent(new Event('autogrow:refresh'));
            }
          });
        }, 50);
      }
      let savedValue = '115';
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
      const attachPrefsPanel = () => {
        if (!document.body) {
          return;
        }
        document.body.appendChild(prefsPanel);
      };
      const repositionPrefsPanel = () => {
        const rect = prefsToggle.getBoundingClientRect();
        const gutter = 12;
        prefsPanel.style.top = `${Math.max(gutter, rect.bottom + gutter)}px`;
        prefsPanel.style.right = `${Math.max(gutter, window.innerWidth - rect.right + gutter)}px`;
        prefsPanel.style.left = 'auto';
      };
      const refreshPrefsPanelPosition = () => {
        if (prefsPanel.classList.contains('open')) {
          repositionPrefsPanel();
        }
      };

      let prefsOpen = false;
      const updatePrefsState = open => {
        prefsOpen = open;
        prefsPanel.classList.toggle('open', open);
        prefsPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
        prefsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
          attachPrefsPanel();
          repositionPrefsPanel();
        }
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
      window.addEventListener('scroll', refreshPrefsPanelPosition, { passive: true });
      updatePrefsState(false);
    }

    // Global mode detection: cmid = 0 OR globalMode = true
    const isGlobalMode = globalMode === true || cmid === 0;
    const runtimeConfig = window.__flashcardsRuntimeConfig || {};
    const aiConfig = runtimeConfig.ai || {};
    const sttConfig = runtimeConfig.stt || {};
    const aiEnabled = !!aiConfig.enabled;
    const voiceOptionsRaw = Array.isArray(aiConfig.voices) ? aiConfig.voices : [];
    const savedVoiceChoice = getSavedVoice();
    let selectedVoice = savedVoiceChoice || aiConfig.defaultVoice || (voiceOptionsRaw[0]?.voice || '');
    const dataset = root?.dataset || {};
    const privateAudioLabel = dataset.privateAudioLabel || 'Private audio';
    const sttStrings = {
      idle: 'Status',
      uploading: dataset.sttUploading || 'Uploading Private audio…',
      transcribing: dataset.sttTranscribing || 'Transcribing…',
      success: dataset.sttSuccess || 'Transcription inserted',
      error: dataset.sttError || 'Transcription failed',
      limit: dataset.sttLimit || 'Clip is longer than allowed',
      quota: dataset.sttQuota || 'Monthly speech limit reached',
      retry: dataset.sttRetry || 'Retry',
      undo: dataset.sttUndo || 'Undo',
      disabled: dataset.sttDisabled || 'Speech-to-text unavailable'
    };
    const sttEnabled = !!sttConfig.enabled;
    const sttClipLimit = Number(sttConfig.clipLimit || 15);
    const sttLanguage = sttConfig.language || 'nb';
    const ocrConfig = runtimeConfig.ocr || {};
    const ocrEnabled = !!ocrConfig.enabled;
    const ocrMaxFileSize = Number(ocrConfig.maxFileSize || 0);
    const ocrStrings = {
      idle: 'Status',
      processing: dataset.ocrProcessing || 'Scanning photo...',
      success: dataset.ocrSuccess || 'Text inserted',
      error: dataset.ocrError || 'Could not read the text',
      disabled: dataset.ocrDisabled || 'Image OCR unavailable',
      retry: dataset.ocrRetry || 'Retry',
      undo: dataset.ocrUndo || 'Undo replace'
    };
    let mediaUsageSummary = buildMediaUsageSummary(runtimeConfig.usage);

    function buildMediaUsageSummary(usage){
      if(!usage || typeof usage !== 'object'){
        return '';
      }
      const parts = [];
      const addPart = (label, rawValue, unit)=>{
        if(rawValue === undefined || rawValue === null){
          return;
        }
        const value = Number(rawValue);
        if(!Number.isFinite(value)){
          return;
        }
        const suffix = unit ? ` ${unit}` : '';
        parts.push(`${label} ${value}${suffix}`);
      };
      addPart('OA', usage.openaiTokens, 'tok');
      addPart('OCR', usage.ocrTokens, 'img');
      addPart('EL TTS', usage.elevenlabsTtsTokens, 'chr');
      addPart('EL STT', usage.elevenlabsSttTokens, 's');
      return parts.join(' · ');
    }
    function updateUsageFromResponse(data){
      if(data && data.usage && typeof data.usage === 'object'){
        mediaUsageSummary = buildMediaUsageSummary(data.usage);
        // Refresh status display with new usage
        if(mediaStatusIndicator){
          const currentText = mediaStatusIndicator.textContent.split(' · ')[0] || aiStrings.translationIdle;
          mediaStatusIndicator.textContent = mediaUsageSummary ? `${currentText} · ${mediaUsageSummary}` : currentText;
        }
      }
    }
    const cropModal = $("#ocrCropModal");
    const cropStage = $("#ocrCropStage");
    const cropStageContainer = root.querySelector('.ocr-crop-body');
    const cropCanvas = $("#ocrCropCanvas");
    const cropRectEl = $("#ocrCropRect");
    const cropApplyBtn = $("#ocrCropApply");
    const cropCancelBtn = $("#ocrCropCancel");
    const cropUndoBtn = $("#ocrCropUndo");
    const cropToolbar = $("#ocrCropToolbar");
    const aiStrings = {
      disabled: dataset.aiDisabled || 'AI focus helper is disabled',
      detecting: dataset.aiDetecting || 'Detecting expression…',
      success: dataset.aiSuccess || 'Focus phrase updated',
      error: dataset.aiError || 'Unable to detect an expression',
      notext: dataset.aiNoText || 'Type a sentence to enable the helper',
      focusAudio: dataset.focusAudioLabel || 'Focus audio',
      frontAudio: dataset.frontAudioLabel || 'Audio',
      voicePlaceholder: dataset.voicePlaceholder || 'Default voice',
      voiceMissing: dataset.voiceMissing || 'Add ElevenLabs voices in plugin settings',
      voiceDisabled: dataset.voiceDisabled || 'Enter your ElevenLabs API key to enable audio.',
      ttsSuccess: dataset.ttsSuccess || 'Audio ready.',
      ttsError: dataset.ttsError || 'Audio generation failed.',
      frontTransShow: dataset.frontTransShow || 'Show translation',
      frontTransHide: dataset.frontTransHide || 'Hide translation',
      translationIdle: 'Status',
      translationLoading: dataset.translationLoading || 'Translating…',
      translationError: dataset.translationError || 'Translation failed',
      translationReverseHint: dataset.translationReverseHint || 'Type in your language to translate into Norwegian automatically.',
      aiChatEmpty: dataset.aiChatEmpty || 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
      aiChatUser: dataset.aiChatUser || 'You',
      aiChatAssistant: dataset.aiChatAssistant || 'AI',
      aiChatError: dataset.aiChatError || 'AI could not answer that question.',
      aiChatLoading: dataset.aiChatLoading || 'Thinking…',
      // Dictation exercise strings
      dictationPlaceholder: dataset.dictationPlaceholder || 'Напишите то, что услышали...',
      dictationCheck: dataset.dictationCheck || 'Проверить',
      dictationReplay: dataset.dictationReplay || 'Прослушать снова',
      dictationEmptyInput: dataset.dictationEmptyInput || 'Введите текст',
      dictationCorrect: dataset.dictationCorrect || 'Правильно!',
      dictationPerfect: dataset.dictationPerfect || 'Отлично! Всё правильно!',
      dictationHint: dataset.dictationHint || 'Смотрите правильный ответ ниже',
      dictationCorrectAnswer: dataset.dictationCorrectAnswer || 'Правильный ответ:',
      dictationYourAnswer: dataset.dictationYourAnswer || 'Ваш ответ:',
      dictationShouldBe: dataset.dictationShouldBe || 'Должно быть:',
      dictationError: dataset.dictationError || 'ошибка',
      dictationErrors2: dataset.dictationErrors2 || 'ошибки',
      dictationErrors5: dataset.dictationErrors5 || 'ошибок',
      dictationMissingWord: dataset.dictationMissingWord || 'Пропущено слово',
      dictationExtraWord: dataset.dictationExtraWord || 'Лишнее слово'
    };
    let frontTranslationSlot = null;
    // frontTranslationToggle and frontTranslationVisible removed - translation is always visible
    let translationInputLocal = null;
    let translationInputEn = null;

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

    const VOICE_SELECTION_KEY = 'flashcards_tts_voice';
    const DEFAULT_INTERFACE_FALLBACK = 'uk';

    function getSavedVoice(){
      try{
        return localStorage.getItem(VOICE_SELECTION_KEY) || null;
      }catch(_e){
        return null;
      }
    }

    function saveVoice(voice){
      try{
        if(voice){
          localStorage.setItem(VOICE_SELECTION_KEY, voice);
        }else{
          localStorage.removeItem(VOICE_SELECTION_KEY);
        }
      }catch(_e){}
    }

    // Interface translations dictionary (COMPLETE)
    const interfaceTranslations = {
      en: {
        app_title: 'MyMemory',
        interface_language_label: 'Interface language',
        font_scale_label: 'Font size',
        tab_quickinput: 'Create',
        tab_study: 'Study',
        tab_dashboard: 'Dashboard',
        quick_audio: 'Record Audio',
        quick_photo: 'Take Photo',
        choosefile: 'Choose file',
        chooseaudiofile: 'Choose audio file',
        tts_voice: 'Voice',
        tts_voice_hint: 'Select a voice before asking the AI helper to generate audio.',
        front: 'Text',
        front_translation_toggle_show: 'Show translation',
        front_translation_toggle_hide: 'Hide translation',
        front_translation_mode_label: 'Translation direction',
        front_translation_mode_hint: 'Tap to switch input/output languages.',
        front_translation_status_idle: 'Translation ready',
        front_translation_copy: 'Copy translation',
        focus_translation_label: 'Focus meaning',
        fokus: 'Fokus word/phrase',
        focus_baseform: 'Base form',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'AI focus helper',
        ai_click_hint: 'Tap any word above to detect a fixed expression',
        ai_no_text: 'Type a sentence to enable the helper',
        choose_focus_word: 'Choose focus word',
        ai_question_label: 'Ask the AI',
        ai_question_placeholder: 'Type a question about this sentence...',
        ai_question_button: 'Ask',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'You',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'The AI could not answer that question.',
        ai_chat_loading: 'Thinking...',
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
        createnew: 'Create',
        audio: 'Audio',
        order_audio_word: 'Focus audio',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Tap any word above to detect a fixed expression',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      uk: {
        app_title: 'MyMemory',
        interface_language_label: 'Мова інтерфейсу',
        font_scale_label: 'Розмір шрифту',
        tab_quickinput: 'Створити',
        tab_study: 'Навчання',
        tab_dashboard: 'Панель',
        quick_audio: 'Записати аудіо',
        quick_photo: 'Зробити фото',
        choosefile: 'Вибрати файл',
        chooseaudiofile: 'Вибрати аудіофайл',
        tts_voice: 'Голос',
        tts_voice_hint: 'Виберіть голос перед тим, як попросити AI помічника згенерувати аудіо.',
        front: 'Текст',
        front_translation_toggle_show: 'Показати переклад',
        front_translation_toggle_hide: 'Сховати переклад',
        front_translation_mode_label: 'Напрямок перекладу',
        front_translation_mode_hint: 'Натисніть, щоб змінити мови введення/виведення',
        front_translation_status_idle: 'Переклад готовий',
        front_translation_copy: 'Копіювати переклад',
        focus_translation_label: 'Фокусне значення',
        fokus: 'Фокусне слово/фраза',
        focus_baseform: 'Базова форма',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'AI помічник фокусу',
        ai_click_hint: 'Натисніть будь-яке слово вище, щоб виявити сталий вираз',
        ai_no_text: 'Введіть речення, щоб увімкнути помічника',
        choose_focus_word: 'Оберіть фокус-слово',
        ai_question_label: 'Запитати AI',
        ai_question_placeholder: 'Введіть Ваше запитання…',
        ai_question_button: 'Запитати',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Ви',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'AI не зміг відповісти на це запитання.',
        ai_chat_loading: 'Обробка...',
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
        createnew: 'Створити',
        audio: 'Аудіо',
        order_audio_word: 'Фокусне аудіо',
        order_audio_text: 'Аудіо',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Натисніть будь-яке слово вище, щоб виявити сталий вираз',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      ru: {
        app_title: 'MyMemory',
        interface_language_label: 'Язык интерфейса',
        font_scale_label: 'Размер шрифта',
        tab_quickinput: 'Создать',
        tab_study: 'Обучение',
        tab_dashboard: 'Панель',
        quick_audio: 'Записать аудио',
        quick_photo: 'Сделать фото',
        choosefile: 'Выбрать файл',
        chooseaudiofile: 'Выбрать аудиофайл',
        tts_voice: 'Голос',
        tts_voice_hint: 'Выберите голос перед тем, как попросить AI помощника сгенерировать аудио.',
        front: 'Текст',
        front_translation_toggle_show: 'Показать перевод',
        front_translation_toggle_hide: 'Скрыть перевод',
        front_translation_mode_label: 'Направление перевода',
        front_translation_mode_hint: 'Нажмите, чтобы изменить языки ввода/вывода',
        front_translation_status_idle: 'Перевод готов',
        front_translation_copy: 'Копировать перевод',
        focus_translation_label: 'Фокусное значение',
        fokus: 'Фокусное слово/фраза',
        focus_baseform: 'Базовая форма',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'AI помощник фокуса',
        ai_click_hint: 'Нажмите любое слово выше, чтобы выявить устойчивое выражение',
        ai_no_text: 'Введите предложение, чтобы включить помощника',
        choose_focus_word: 'Выберите фокус-слово',
        ai_question_label: 'Спросить ИИ',
        ai_question_placeholder: 'Введите Ваш вопрос...',
        ai_question_button: 'Спросить',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Вы',
        ai_chat_assistant: 'ИИ',
        ai_chat_error: 'ИИ не смог ответить на этот вопрос.',
        ai_chat_loading: 'Думает...',
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
        createnew: 'Создать',
        audio: 'Аудио',
        order_audio_word: 'Фокусное аудио',
        order_audio_text: 'Аудио',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Нажмите любое слово выше, чтобы выявить устойчивое выражение',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      fr: {
        app_title: 'MyMemory',
        interface_language_label: 'Langue de l\'interface',
        font_scale_label: 'Taille du texte',
        tab_quickinput: 'Créer',
        tab_study: 'Étudier',
        tab_dashboard: 'Tableau de bord',
        quick_audio: 'Enregistrer l\'audio',
        quick_photo: 'Prendre une photo',
        choosefile: 'Choisir un fichier',
        chooseaudiofile: 'Choisir un fichier audio',
        tts_voice: 'Voix',
        tts_voice_hint: 'Sélectionnez une voix avant de demander à l\'assistant IA de générer l\'audio.',
        front: 'Texte',
        front_translation_toggle_show: 'Afficher la traduction',
        front_translation_toggle_hide: 'Masquer la traduction',
        front_translation_mode_label: 'Direction de traduction',
        front_translation_mode_hint: 'Appuyez pour changer les langues d\'entrée/sortie',
        front_translation_status_idle: 'Traduction prête',
        front_translation_copy: 'Copier la traduction',
        focus_translation_label: 'Signification focale',
        fokus: 'Mot/phrase focal',
        focus_baseform: 'Forme de base',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Assistant IA focal',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour détecter une expression figée',
        ai_no_text: 'Saisissez une phrase pour activer l\'assistant',
        choose_focus_word: 'Choisissez le mot focal',
        ai_question_label: 'Demander à l\'IA',
        ai_question_placeholder: 'Tapez une question sur cette phrase...',
        ai_question_button: 'Demander',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Vous',
        ai_chat_assistant: 'IA',
        ai_chat_error: 'L\'IA n\'a pas pu répondre à cette question.',
        ai_chat_loading: 'Réflexion...',
        explanation: 'Explication',
        back: 'Traduction',
        back_en: 'Traduction (anglais)',
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
        createnew: 'Créer',
        audio: 'Audio',
        order_audio_word: 'Audio focal',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour détecter une expression figée',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      es: {
        app_title: 'MyMemory',
        interface_language_label: 'Idioma de la interfaz',
        font_scale_label: 'Tamaño de fuente',
        tab_quickinput: 'Crear',
        tab_study: 'Estudiar',
        tab_dashboard: 'Panel',
        quick_audio: 'Grabar audio',
        quick_photo: 'Tomar foto',
        choosefile: 'Elegir archivo',
        chooseaudiofile: 'Elegir archivo de audio',
        tts_voice: 'Voz',
        tts_voice_hint: 'Selecciona una voz antes de pedir al asistente IA que genere audio.',
        front: 'Texto',
        front_translation_toggle_show: 'Mostrar traducción',
        front_translation_toggle_hide: 'Ocultar traducción',
        front_translation_mode_label: 'Dirección de traducción',
        front_translation_mode_hint: 'Toca para cambiar los idiomas de entrada/salida',
        front_translation_status_idle: 'Traducción lista',
        front_translation_copy: 'Copiar traducción',
        focus_translation_label: 'Significado focal',
        fokus: 'Palabra/frase focal',
        focus_baseform: 'Forma base',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Asistente IA focal',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresión fija',
        ai_no_text: 'Escribe una oración para activar el asistente',
        choose_focus_word: 'Elige la palabra focal',
        ai_question_label: 'Preguntar a la IA',
        ai_question_placeholder: 'Escribe una pregunta sobre esta frase...',
        ai_question_button: 'Preguntar',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Tú',
        ai_chat_assistant: 'IA',
        ai_chat_error: 'La IA no pudo responder a esa pregunta.',
        ai_chat_loading: 'Pensando...',
        explanation: 'Explicación',
        back: 'Traducción',
        back_en: 'Traducción (inglés)',
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
        createnew: 'Crear',
        audio: 'Audio',
        order_audio_word: 'Audio focal',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresión fija',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      pl: {
        app_title: 'MyMemory',
        interface_language_label: 'Język interfejsu',
        font_scale_label: 'Rozmiar czcionki',
        tab_quickinput: 'Utwórz',
        tab_study: 'Nauka',
        tab_dashboard: 'Panel',
        quick_audio: 'Nagraj audio',
        quick_photo: 'Zrób zdjęcie',
        choosefile: 'Wybierz plik',
        chooseaudiofile: 'Wybierz plik audio',
        tts_voice: 'Głos',
        tts_voice_hint: 'Wybierz głos przed poproszeniem asystenta AI o wygenerowanie audio.',
        front: 'Tekst',
        front_translation_toggle_show: 'Pokaż tłumaczenie',
        front_translation_toggle_hide: 'Ukryj tłumaczenie',
        front_translation_mode_label: 'Kierunek tłumaczenia',
        front_translation_mode_hint: 'Dotknij, aby zmienić języki wejścia/wyjścia',
        front_translation_status_idle: 'Tłumaczenie gotowe',
        front_translation_copy: 'Kopiuj tłumaczenie',
        focus_translation_label: 'Fokusowe znaczenie',
        fokus: 'Słowo/fraza fokusowa',
        focus_baseform: 'Forma podstawowa',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Asystent AI fokusa',
        ai_click_hint: 'Dotknij dowolnego słowa powyżej, aby wykryć stałe wyrażenie',
        ai_no_text: 'Wpisz zdanie, aby włączyć asystenta',
        choose_focus_word: 'Wybierz słowo fokusowe',
        ai_question_label: 'Zapytaj AI',
        ai_question_placeholder: 'Wpisz pytanie dotyczące tego zdania...',
        ai_question_button: 'Zapytaj',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Ty',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'AI nie mogła odpowiedzieć na to pytanie.',
        ai_chat_loading: 'Myśli...',
        explanation: 'Wyjaśnienie',
        back: 'Tłumaczenie',
        back_en: 'Tłumaczenie (angielski)',
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
        createnew: 'Utwórz',
        audio: 'Audio',
        order_audio_word: 'Audio fokusowe',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Dotknij dowolnego słowa powyżej, aby wykryć stałe wyrażenie',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      it: {
        app_title: 'MyMemory',
        interface_language_label: 'Lingua dell\'interfaccia',
        font_scale_label: 'Dimensione del testo',
        tab_quickinput: 'Crea',
        tab_study: 'Studiare',
        tab_dashboard: 'Pannello',
        quick_audio: 'Registra audio',
        quick_photo: 'Scatta foto',
        choosefile: 'Scegli file',
        chooseaudiofile: 'Scegli file audio',
        tts_voice: 'Voce',
        tts_voice_hint: 'Seleziona una voce prima di chiedere all\'assistente IA di generare l\'audio.',
        front: 'Testo',
        front_translation_toggle_show: 'Mostra traduzione',
        front_translation_toggle_hide: 'Nascondi traduzione',
        front_translation_mode_label: 'Direzione di traduzione',
        front_translation_mode_hint: 'Tocca per cambiare le lingue di input/output',
        front_translation_status_idle: 'Traduzione pronta',
        front_translation_copy: 'Copia traduzione',
        focus_translation_label: 'Significato focale',
        fokus: 'Parola/frase focale',
        focus_baseform: 'Forma base',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Assistente IA focale',
        ai_click_hint: 'Tocca qualsiasi parola sopra per rilevare un\'espressione fissa',
        ai_no_text: 'Digita una frase per attivare l\'assistente',
        choose_focus_word: 'Scegli la parola focale',
        ai_question_label: 'Chiedi all\'AI',
        ai_question_placeholder: 'Digita una domanda su questa frase...',
        ai_question_button: 'Chiedi',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Tu',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'L\'AI non ha potuto rispondere a quella domanda.',
        ai_chat_loading: 'Sta pensando...',
        explanation: 'Spiegazione',
        back: 'Traduzione',
        back_en: 'Traduzione (inglese)',
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
        createnew: 'Crea',
        audio: 'Audio',
        order_audio_word: 'Audio focale',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Tocca qualsiasi parola sopra per rilevare un\'espressione fissa',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
      },
      de: {
        app_title: 'MyMemory',
        interface_language_label: 'Sprache der Oberfläche',
        font_scale_label: 'Schriftgröße',
        tab_quickinput: 'Erstellen',
        tab_study: 'Studieren',
        tab_dashboard: 'Dashboard',
        quick_audio: 'Audio aufnehmen',
        quick_photo: 'Foto aufnehmen',
        choosefile: 'Datei auswählen',
        chooseaudiofile: 'Audiodatei auswählen',
        tts_voice: 'Stimme',
        tts_voice_hint: 'Wählen Sie eine Stimme aus, bevor Sie den KI-Assistenten bitten, Audio zu generieren.',
        front: 'Text',
        front_translation_toggle_show: 'Übersetzung anzeigen',
        front_translation_toggle_hide: 'Übersetzung ausblenden',
        front_translation_mode_label: 'Übersetzungsrichtung',
        front_translation_mode_hint: 'Tippen Sie, um die Eingabe-/Ausgabesprachen zu ändern',
        front_translation_status_idle: 'Übersetzung bereit',
        front_translation_copy: 'Übersetzung kopieren',
        focus_translation_label: 'Fokusbedeutung',
        fokus: 'Fokuswort/-phrase',
        focus_baseform: 'Grundform',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'KI-Fokus-Assistent',
        ai_click_hint: 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen',
        ai_no_text: 'Geben Sie einen Satz ein, um den Assistenten zu aktivieren',
        choose_focus_word: 'Fokuswort auswählen',
        ai_question_label: 'KI fragen',
        ai_question_placeholder: 'Stellen Sie eine Frage zu diesem Satz...',
        ai_question_button: 'Fragen',
        ai_chat_empty: 'Поставте запитання AI стосовно Вашого тексту або фокусного слова/ фразы',
        ai_chat_user: 'Sie',
        ai_chat_assistant: 'KI',
        ai_chat_error: 'Die KI konnte diese Frage nicht beantworten.',
        ai_chat_loading: 'Denkt nach...',
        explanation: 'Erklärung',
        back: 'Übersetzung',
        back_en: 'Übersetzung (Englisch)',
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
        createnew: 'Erstellen',
        audio: 'Audio',
        order_audio_word: 'Fokus-Audio',
        order_audio_text: 'Audio',
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
        front_placeholder: '_ _ _',
        ai_click_hint: 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen',
        translation_en_placeholder: '_ _ _',
        translation_placeholder: '_ _ _',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
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
      aiStrings.aiChatEmpty = t('ai_chat_empty');
      aiStrings.aiChatUser = t('ai_chat_user');
      aiStrings.aiChatAssistant = t('ai_chat_assistant');
      aiStrings.aiChatError = t('ai_chat_error');
      aiStrings.aiChatLoading = t('ai_chat_loading');

      // Update focus hint with new language
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
      if(typeof renderAiChat === 'function'){
        renderAiChat();
      }

      // Update floating edit bar buttons (Update/Create new)
      const saveBarUpdate = document.getElementById('saveBarUpdate');
      const saveBarAdd = document.getElementById('saveBarAdd');
      if(saveBarUpdate){
        saveBarUpdate.textContent = t('update');
      }
      if(saveBarAdd){
        saveBarAdd.textContent = t('createnew');
      }
    }
    // ========== END INTERFACE LANGUAGE SYSTEM ==========

    const savedInterfaceLang = getSavedInterfaceLang();
    const savedTransLang = getSavedTransLang();
    const moodleLang = (window.M && M.cfg && M.cfg.lang) || pageLang();
    const fallbackLang = DEFAULT_INTERFACE_FALLBACK;
    const rawLang = savedInterfaceLang || savedTransLang || moodleLang || (navigator.language || fallbackLang);
    const userLang = (rawLang || fallbackLang).toLowerCase();
    let userLang2 = userLang.split(/[\-_]/)[0] || fallbackLang;

    // Initialize interface language (use saved interface lang or fallback to computed language)
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
        voiceSelectEl.addEventListener('change', ()=>{ selectedVoice = voiceSelectEl.value; saveVoice(selectedVoice); setTtsStatus('', ''); });
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

    // Translation visibility functions removed - translation is always visible

    // Function to update translation language UI
    function updateTranslationLangUI(){
      try {
        const slotLocal = $("#slot_translation_local");
        const slotEn = $("#slot_translation_en");
        const tagLocal = $("#tag_trans_local");
        if(tagLocal){ tagLocal.textContent = t('back'); }
        if(userLang2 === 'en'){
          if(slotLocal) slotLocal.classList.remove('hidden');
          if(slotEn) slotEn.classList.add('hidden');
        } else {
          if(slotLocal) slotLocal.classList.remove('hidden');
          if(slotEn) slotEn.classList.remove('hidden');
        }

        // Language change hint removed
        const langChangeHint = document.getElementById('langChangeHint');
        if(langChangeHint && !langChangeHint.dataset.bound){
          langChangeHint.dataset.bound = '1';
          langChangeHint.addEventListener('click', showLanguageSelector);
        }
        updateTranslationModeLabels();
        scheduleTranslationRefresh();
        updateInterfaceTexts();
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

    function setTranslationPreview(state, custom){
      const label = custom || (state === 'loading' ? aiStrings.translationLoading :
        (state === 'error' ? aiStrings.translationError : aiStrings.translationIdle));
      setMediaStatus(state, label);
    }

    function setMediaStatus(state, label){
      if(!mediaStatusIndicator){
        return;
      }
      const text = label || aiStrings.translationIdle;
      const summary = mediaUsageSummary;
      mediaStatusIndicator.textContent = summary ? `${text} · ${summary}` : text;
      mediaStatusIndicator.dataset.state = state || '';
      mediaStatusIndicator.classList.remove('error','success');
      if(state === 'error'){
        mediaStatusIndicator.classList.add('error');
      } else if(state === 'success'){
        mediaStatusIndicator.classList.add('success');
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
      translationButtons.forEach(btn=>{
        btn.classList.toggle('active', btn.dataset.dir === translationDirection);
      });
      if(translationModeHint){
        translationModeHint.textContent = (translationDirection === 'user-no')
          ? aiStrings.translationReverseHint
          : (translationHintDefault || aiStrings.translationIdle);
      }
      // Translation is always visible - no toggle needed
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
        setTranslationPreview('error', aiStrings.disabled);
        return;
      }
      const sourceText = (sourceEl.value || '').trim();
      if(sourceText.length < 2){
        if(translationDirection === 'user-no'){
          setTranslationPreview('', aiStrings.translationReverseHint);
        }else{
          setTranslationPreview('', aiStrings.translationIdle);
        }
        return;
      }
      if(translationAbortController){
        translationAbortController.abort();
      }
      const controller = new AbortController();
      translationAbortController = controller;
      const seq = ++translationRequestSeq;
      setTranslationPreview('loading');
      const payload = {
        text: sourceText,
        sourceLang: translationDirection === 'user-no' ? userLang2 : 'no',
        targetLang: translationDirection === 'user-no' ? 'no' : userLang2,
        direction: translationDirection
      };
      try{
        const data = await api('ai_translate', {}, 'POST', payload, {signal: controller.signal});
        updateUsageFromResponse(data);
        if(seq !== translationRequestSeq){
          return;
        }
        const translated = (data.translation || '').trim();
        if(translationDirection === 'no-user'){
          if(translationInputLocal){
            translationInputLocal.value = translated;
          }
          // Request English translation separately if user language is not English
          if(translationInputEn && userLang2 !== 'en'){
            try{
              const enPayload = {
                text: sourceText,
                sourceLang: 'no',
                targetLang: 'en',
                direction: 'no-en'
              };
              const enData = await api('ai_translate', {}, 'POST', enPayload);
              updateUsageFromResponse(enData);
              const enTranslated = (enData.translation || '').trim();
              translationInputEn.value = enTranslated;
            }catch(enErr){
              console.error('[Flashcards][AI] English translation failed', enErr);
            }
          }
        }else if(frontInput){
          frontInput.value = translated;
          try{
            frontInput.dispatchEvent(new Event('input', {bubbles:true}));
          }catch(_e){}
        }
        setTranslationPreview('success');
      }catch(err){
        if(controller.signal.aborted){
          return;
        }
        console.error('[Flashcards][AI] translation failed', err);
        setTranslationPreview('error');
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
      inputNo.style.cssText = 'width: 100%; padding: 8px; background: #0b1220; color: #ffffff; font-weight: 500; border: 1px solid #374151; border-radius: 6px;';
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
      inputTrans.placeholder = `${t('back')} (${languageName(userLang2)})...`;
      inputTrans.style.cssText = 'flex: 1; padding: 8px; background: #0b1220; color: #94a3b8; font-family: Georgia, "Times New Roman", serif; font-style: italic; border: 1px solid #374151; border-radius: 6px;';
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
    const focusHelperState = { tokens: [], activeIndex: null, abortController: null };
    frontTranslationSlot = document.getElementById('slot_front_translation');
    // frontTranslationToggle removed - button no longer exists
    translationInputLocal = document.getElementById('uTransLocal');
    translationInputEn = document.getElementById('uTransEn');
    const translationForwardLabel = document.getElementById('translationModeForward');
    const translationReverseLabel = document.getElementById('translationModeReverse');
    const translationModeHint = document.getElementById('translationModeHint');
    const translationButtons = Array.from(root.querySelectorAll('[data-translation-btn]'));
    const mediaStatusIndicator = document.getElementById('mediaStatusIndicator');
    const focusTranslationText = document.getElementById('focusTranslationText');
    const translationHintDefault = translationModeHint ? translationModeHint.textContent : '';
    let translationDirection = 'no-user';
    let translationDebounce = null;
    let translationAbortController = null;
    let translationRequestSeq = 0;
    // Translation toggle removed - translation is always visible
    updateTranslationLangUI();
    translationButtons.forEach(btn=>{
      btn.addEventListener('click', ()=>{
        applyTranslationDirection(btn.dataset.dir || 'no-user');
      });
    });
    if(frontInput){
      frontInput.addEventListener('input', ()=>{
        if(translationDirection === 'no-user'){
          scheduleTranslationRefresh();
        }
      });
      frontInput.addEventListener('focus', ()=>{
        if(translationDirection !== 'no-user' && !frontInput.value.trim()){
          applyTranslationDirection('no-user');
        }
      });
    }
    if(translationInputLocal){
      translationInputLocal.addEventListener('input', ()=>{
        if(translationDirection === 'user-no'){
          scheduleTranslationRefresh();
        }
      });
      translationInputLocal.addEventListener('focus', ()=>{
        if(translationDirection !== 'user-no' && !translationInputLocal.value.trim()){
          applyTranslationDirection('user-no');
        }
      });
    }
    setTranslationPreview('', aiStrings.translationIdle);
    setFocusTranslation('');
    applyTranslationDirection('no-user');
    const wordRegex = (()=>{ try { void new RegExp('\\p{L}', 'u'); return /[\p{L}\p{M}\d'’\-]+/gu; } catch(_e){ return /[A-Za-z0-9'’\-]+/g; } })();

    function setFocusStatus(state, text){
      if(!focusStatusEl) return;
      focusStatusEl.dataset.state = state || '';
      focusStatusEl.textContent = text || '';
    }

    function extractFocusTokens(text){
      if(!text) return [];
      // Split text into words and non-words (punctuation, spaces)
      const parts = [];
      let lastIndex = 0;
      let wordIndex = 0;
      text.replace(wordRegex, (match, offset) => {
        // Add punctuation/spaces before this word
        if(offset > lastIndex){
          parts.push({ text: text.slice(lastIndex, offset), isWord: false, index: -1 });
        }
        // Add the word itself
        parts.push({ text: match, isWord: true, index: wordIndex++ });
        lastIndex = offset + match.length;
        return match;
      });
      // Add any remaining punctuation/spaces after the last word
      if(lastIndex < text.length){
        parts.push({ text: text.slice(lastIndex), isWord: false, index: -1 });
      }
      return parts;
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
      // Store only word tokens for focusHelperState
      focusHelperState.tokens = tokens.filter(t => t.isWord);
      focusWordList.innerHTML = '';
      if(!text || !text.trim()){
        setFocusStatus('', '');
      }
      const wordTokens = tokens.filter(t => t.isWord);
      if(!wordTokens.length){
        const span=document.createElement('span');
        span.className='focus-helper-empty';
        span.textContent = aiStrings.notext;
        focusWordList.appendChild(span);
        return;
      }
      tokens.forEach((token)=>{
        if(token.isWord){
          const btn=document.createElement('button');
          btn.type='button';
          btn.className='focus-chip';
          btn.textContent = token.text;
          btn.dataset.index = String(token.index);
          if(!aiEnabled){
            btn.disabled = true;
          }else{
            btn.addEventListener('click', ()=> triggerFocusHelper(token, token.index));
          }
          if(token.index === focusHelperState.activeIndex){
            btn.classList.add('active');
          }
          focusWordList.appendChild(btn);
        } else {
          // Punctuation/spaces - display as non-clickable text
          const span = document.createElement('span');
          span.className = 'focus-punctuation';
          span.textContent = token.text;
          focusWordList.appendChild(span);
        }
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
          examplesEl.value = data.examples.join('\n');
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
        updateUsageFromResponse(data);
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

    const aiQuestionInput = document.getElementById('uAiQuestion');
    const aiQuestionBtn = document.getElementById('btnAskAi');
    const aiChatPane = document.getElementById('aiChatPane');
    const aiChatMessages = [];

    function renderAiChat(){
      if(!aiChatPane){
        return;
      }
      aiChatPane.innerHTML = '';
      if(!aiEnabled && !aiChatMessages.length){
        const hint = document.createElement('p');
        hint.className = 'ai-chat-empty';
        hint.textContent = aiStrings.disabled;
        aiChatPane.appendChild(hint);
        return;
      }
      if(!aiChatMessages.length){
        const hint = document.createElement('p');
        hint.className = 'ai-chat-empty';
        hint.textContent = aiStrings.aiChatEmpty;
        aiChatPane.appendChild(hint);
        return;
      }
      aiChatMessages.forEach(msg=>{
        const wrapper = document.createElement('div');
        wrapper.className = `ai-chat-message ${msg.role}`;
        const meta = document.createElement('div');
        meta.className = 'ai-chat-meta';
        meta.textContent = msg.role === 'user' ? aiStrings.aiChatUser : aiStrings.aiChatAssistant;
        wrapper.appendChild(meta);
        const body = document.createElement('p');
        body.textContent = msg.text;
        wrapper.appendChild(body);
        aiChatPane.appendChild(wrapper);
      });
      aiChatPane.scrollTop = aiChatPane.scrollHeight;
    }

    async function askAiQuestion(){
      if(!aiEnabled || !aiQuestionInput || !aiQuestionBtn){
        return;
      }
      const question = (aiQuestionInput.value || '').trim();
      if(!question){
        return;
      }
      aiQuestionBtn.disabled = true;
      aiChatMessages.push({role:'user', text:question});
      aiChatMessages.push({role:'assistant', text:aiStrings.aiChatLoading, pending:true});
      renderAiChat();
      aiQuestionInput.value = '';
      try{
        const payload = {
          frontText: frontInput ? (frontInput.value || '').trim() : '',
          question,
          language: userLang2
        };
        const result = await api('ai_question', {}, 'POST', payload);
        updateUsageFromResponse(result);
        const answer = result && result.answer ? result.answer.trim() : '';
        const response = {
          role: 'assistant',
          text: answer || aiStrings.aiChatError
        };
        const pendingIndex = aiChatMessages.findIndex(m=>m.pending);
        if(pendingIndex >= 0){
          aiChatMessages[pendingIndex] = response;
        }else{
          aiChatMessages.push(response);
        }
      }catch(err){
        console.error('[Flashcards] AI question failed', err);
        const fallback = {
          role: 'assistant',
          text: aiStrings.aiChatError
        };
        const pendingIndex = aiChatMessages.findIndex(m=>m.pending);
        if(pendingIndex >= 0){
          aiChatMessages[pendingIndex] = fallback;
        }else{
          aiChatMessages.push(fallback);
        }
      }finally{
        if(aiQuestionBtn){
          aiQuestionBtn.disabled = !aiEnabled;
        }
        renderAiChat();
      }
    }

    if(aiQuestionBtn){
      aiQuestionBtn.addEventListener('click', askAiQuestion);
      aiQuestionBtn.disabled = !aiEnabled;
    }
    if(aiQuestionInput){
      aiQuestionInput.addEventListener('keydown', event=>{
        if(event.key === 'Enter' && !event.shiftKey){
          event.preventDefault();
          askAiQuestion();
        }
      });
    }
    renderAiChat();

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
      // null means "not set" (use auto-build), [] or ["audio"] means "explicitly chosen"
      const orderFromServer = Array.isArray(payload.order) ? payload.order : null;
      console.log('[DEBUG normalizeServerCard] cardId:', item.cardId, 'payload.order:', payload.order, 'orderFromServer:', orderFromServer);
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
        order: orderFromServer,
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
    function ratingIntervalDays(step, mode){
      const safeStep = Math.max(0, Number(step) || 0);
      if(mode === 'hard'){
        return 1;
      }
      const nextStep = Math.min(11, safeStep + 1);
      const baseInterval = Math.pow(2, Math.max(0, nextStep - 1));
      const capped = Math.min(512, baseInterval);
      if(mode === 'normal'){
        return Math.max(1, capped / 2);
      }
      return Math.max(1, capped);
    }
    const ratingHintLocaleMap = {
      en:{one:'day', other:'days'},
      nb:{one:'dag', other:'dager'},
      no:{one:'dag', other:'dager'},
      nn:{one:'dag', other:'dager'},
      ru:{one:'день', few:'дня', many:'дней', other:'дней'},
      uk:{one:'день', few:'дні', many:'днів', other:'днів'},
      de:{one:'Tag', other:'Tage'},
      fr:{one:'jour', other:'jours'},
      es:{one:'día', other:'días'},
      it:{one:'giorno', other:'giorni'},
      pl:{one:'dzień', few:'dni', many:'dni', other:'dni'}
    };
    const pluralRulesCache = {};
    function getInterfaceLanguage(){
      const html = document.documentElement || null;
      const langAttr = (html && (html.lang || (html.getAttribute ? html.getAttribute('lang') : ''))) || '';
      const normalized = langAttr.trim().toLowerCase();
      const base = normalized.split('-')[0] || 'en';
      return {base, locale: normalized || base || 'en'};
    }
    function formatIntervalDaysLabel(value){
      if(!Number.isFinite(value)){
        return '';
      }
      const days = Math.max(1, Math.round(value));
      const {base, locale} = getInterfaceLanguage();
      const labels = ratingHintLocaleMap[base] || ratingHintLocaleMap.en;
      let form = 'other';
      try{
        pluralRulesCache[locale] = pluralRulesCache[locale] || new Intl.PluralRules(locale);
        form = pluralRulesCache[locale].select(days);
      }catch(_e){}
      const word = labels[form] || labels.other || labels.one || ratingHintLocaleMap.en.other;
      return `${days} ${word}`;
    }
    function updateRatingActionHints(rec){
      const mappings = [
        {id:'btnEasyHint', mode:'easy'},
        {id:'btnNormalHint', mode:'normal'},
        {id:'btnHardHint', mode:'hard'}
      ];
      mappings.forEach(cfg=>{
        const target = document.getElementById(cfg.id);
        if(!target) return;
        if(!rec){
          target.textContent='';
          return;
        }
        const days = ratingIntervalDays(rec.step || 0, cfg.mode);
        target.textContent = formatIntervalDaysLabel(days);
      });
    }

    // Storage
    const PROFILE_KEY="srs-profile";
    function storageKey(base){return `${base}:${localStorage.getItem(PROFILE_KEY)||"Guest"}`;}
    const STORAGE_STATE="srs-v6:state", STORAGE_REG="srs-v6:registry";
    const MY_DECK_ID="my-deck";
    // NOTE: "audio" = front text audio (sentence), "audio_text" = focus/baseform audio (word)
    // UI shows: Audio (text) for "audio", Audio (word) for "audio_text"
    // Default order: word audio first, then transcription, then sentence audio
    const DEFAULT_ORDER=["audio_text","transcription","audio","text","translation"];
    let state,registry,queue=[],current=-1,visibleSlots=1,currentItem=null;
    let activeTab='quickInput';
    let tabSwitcher=null;
    let pendingStudyRender=false;
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
    function setCreatedMeta(ts){
      const badge = $("#createdBadge");
      const value = $("#createdValue");
      if(!badge || !value) return;
      if(!ts){
        badge.classList.add("hidden");
        value.textContent = "--";
        try{ value.removeAttribute("datetime"); }catch(_e){}
        return;
      }
      badge.classList.remove("hidden");
      const dt = new Date(ts);
      value.textContent = fmtDateTime(ts);
      try{ value.setAttribute("datetime", dt.toISOString()); }catch(_e){}
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
    let lastAudioDurationSec=0;
    let privateAudioOnly=false;
    let sttAbortController=null;
    let sttUndoValue=null;
    let ocrAbortController=null;
    let ocrLastFile=null;
    let ocrUndoValue=null;
    let pendingImageFile=null;
    let cropImage=null;
    let cropImageUrl=null;
    let cropRect=null;
    let cropCtx=null;
    let cropDragMode=null;
    let cropActiveHandle=null;
    let cropDragStart=null;
    let cropDragStartRect=null;
    let cropHistory=[];
    let cropResizeHandler=null;
    let cropViewportHandler=null;
    let viewZoom=1;
    let baseZoom=1;
    let viewX=0;
    let viewY=0;
    const CANVAS_DPR = 1;
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
    const sttStatusEl = $("#sttStatus");
    const sttRetryBtn = $("#sttRetry");
    const sttUndoBtn = $("#sttUndo");
    const ocrStatusEl = $("#ocrStatus");
    const ocrRetryBtn = $("#ocrRetry");
    const ocrUndoBtn = $("#ocrUndo");
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
    const IOS_MIC_PERMISSION_KEY = 'flashcards-ios-mic-permission';
    function readIOSPermissionState(){
      if(!IS_IOS){
        return 'granted';
      }
      try{
        const stored = localStorage.getItem(IOS_MIC_PERMISSION_KEY);
        if(stored === 'granted' || stored === 'denied'){
          return stored;
        }
      }catch(_e){}
      return 'unknown';
    }
    let iosMicPermissionState = readIOSPermissionState();
    function updateIOSMicPermissionIndicators(){
      if(!IS_IOS){
        return;
      }
      $$('.micbtn').forEach(btn=>{
        if(!btn){
          return;
        }
        btn.classList.toggle('mic-permission-pending', iosMicPermissionState === 'unknown' || iosMicPermissionState === 'requesting');
        btn.classList.toggle('mic-permission-denied', iosMicPermissionState === 'denied');
      });
    }
    function setIOSMicPermissionState(state, opts){
      if(!IS_IOS){
        return;
      }
      const persist = !opts || opts.persist !== false;
      iosMicPermissionState = state;
      if(persist){
        try{
          if(state === 'granted' || state === 'denied'){
            localStorage.setItem(IOS_MIC_PERMISSION_KEY, state);
          }else{
            localStorage.removeItem(IOS_MIC_PERMISSION_KEY);
          }
        }catch(_e){}
      }
      updateIOSMicPermissionIndicators();
    }
    function markIOSMicPermissionRequesting(){
      if(!IS_IOS || iosMicPermissionState === 'granted'){
        return;
      }
      setIOSMicPermissionState('requesting', {persist:false});
    }
    function handleIOSMicPermissionError(err){
      if(!IS_IOS){
        return;
      }
      const code = (err && (err.name || err.code)) ? (err.name || err.code) : '';
      if(typeof code === 'string' && /notallowed|permission|security/i.test(code)){
        setIOSMicPermissionState('denied');
        return;
      }
      if(iosMicPermissionState === 'granted' || iosMicPermissionState === 'denied'){
        updateIOSMicPermissionIndicators();
        return;
      }
      setIOSMicPermissionState('unknown');
    }
    updateIOSMicPermissionIndicators();
    async function requestIOSMicPermission(){
      if(!IS_IOS){
        return;
      }
      markIOSMicPermissionRequesting();
      updateIOSMicPermissionIndicators();
      let granted = false;
      try{
        if(navigator?.mediaDevices?.getUserMedia){
          const stream = await navigator.mediaDevices.getUserMedia({audio:true});
          granted = true;
          try{
            stream.getTracks().forEach(track=>{ try{ track.stop(); }catch(_e){}; });
          }catch(_e){}
        }else if(iosRecorderGlobal && typeof iosRecorderGlobal.start === 'function'){
          await iosRecorderGlobal.start();
          await iosRecorderGlobal.releaseMic();
          granted = true;
        }
      }catch(err){
        granted = false;
        handleIOSMicPermissionError(err);
      }
      if(granted){
        setIOSMicPermissionState('granted');
      }else if(iosMicPermissionState === 'requesting'){
        setIOSMicPermissionState('unknown', {persist:false});
      }
      updateIOSMicPermissionIndicators();
    }
    async function initIOSMicPermissionWatcher(){
      if(!IS_IOS){
        return;
      }
      const navigatorObj = window.navigator;
      const canQuery = !!(navigatorObj && navigatorObj.permissions && navigatorObj.permissions.query);
      if(!canQuery){
        return;
      }
      const applyState = status=>{
        if(!status){
          return;
        }
        const state = status.state;
        const mapped = state === 'granted' ? 'granted' : (state === 'denied' ? 'denied' : 'unknown');
        setIOSMicPermissionState(mapped);
      };
      try{
        const status = await navigatorObj.permissions.query({name:'microphone'});
        if(!status){
          return;
        }
        applyState(status);
        const handler = ()=>applyState(status);
        if(typeof status.addEventListener === 'function'){
          status.addEventListener('change', handler);
        }else{
          status.onchange = handler;
        }
      }catch(err){
        recorderLog('Permission query failed: '+(err?.message||err));
      }
    }
    initIOSMicPermissionWatcher();

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
    function setSttUndoVisible(show){
      if(!sttUndoBtn) return;
      sttUndoBtn.classList.toggle('hidden', !show);
    }
    function setSttRetryVisible(show){
      if(!sttRetryBtn) return;
      sttRetryBtn.classList.toggle('hidden', !show);
    }
    function setSttStatus(state, customText){
      const message = customText || sttStrings[state] || sttStrings.idle;
      const isError = state === 'error' || state === 'limit' || state === 'quota';
      if(!isError && state !== 'disabled'){
        setMediaStatus(state, message);
      } else {
        setMediaStatus('idle', sttStrings.idle);
      }
      const isSuccess = state === 'success';
      const showSttStatus = isError || state === 'disabled';
      setSttRetryVisible(isError);
      if(state === 'idle' || state === 'disabled'){
        setSttUndoVisible(false);
      }
      if(!sttStatusEl){
        return;
      }
      sttStatusEl.textContent = message;
      sttStatusEl.classList.remove('error','success');
      if(isError){
        sttStatusEl.classList.add('error');
      } else if(isSuccess){
        sttStatusEl.classList.add('success');
      }
      if(state === 'disabled'){
        sttStatusEl.classList.add('error');
      }
      sttStatusEl.classList.toggle('hidden', !showSttStatus);
    }
    function setOcrRetryVisible(show){
      if(!ocrRetryBtn) return;
      ocrRetryBtn.classList.toggle('hidden', !show);
    }
    function setOcrUndoVisible(show){
      if(!ocrUndoBtn) return;
      ocrUndoBtn.classList.toggle('hidden', !show);
    }
    function setOcrStatus(state, customText){
      const message = customText || ocrStrings[state] || ocrStrings.idle;
      setMediaStatus(state, message);
      const isError = state === 'error';
      setOcrRetryVisible(isError);
      if(state === 'idle' || state === 'disabled'){
        setOcrUndoVisible(false);
      }
      if(!ocrStatusEl){
        return;
      }
      ocrStatusEl.textContent = message;
      ocrStatusEl.classList.remove('error','success');
      if(isError){
        ocrStatusEl.classList.add('error');
      } else if(state === 'success'){
        ocrStatusEl.classList.add('success');
      }
      if(state === 'disabled'){
        ocrStatusEl.classList.add('error');
      }
    }
    function getCropContext(){
      if(!cropCanvas){
        return null;
      }
      if(!cropCtx){
        cropCtx = cropCanvas.getContext('2d');
      }
      return cropCtx;
    }
    const clamp = (val, min, max) => Math.min(Math.max(val, min), max);
    function resetCropHistory(){
      cropHistory = [];
      updateUndoState();
    }
    function pushCropHistory(){
      if(!cropRect) return;
      cropHistory.push({
        rect: {...cropRect},
        viewZoom,
        viewX,
        viewY,
      });
      if(cropHistory.length > 30){
        cropHistory.shift();
      }
      updateUndoState();
    }
    function updateUndoState(){
      if(cropUndoBtn){
        cropUndoBtn.disabled = cropHistory.length === 0;
      }
    }
    function updateViewToFitImage(){
      if(!cropImage || !cropCanvas){
        return;
      }
      const fitZoomX = cropCanvas.width / cropImage.width;
      const fitZoomY = cropCanvas.height / cropImage.height;
      baseZoom = Math.min(fitZoomX, fitZoomY);
      viewZoom = baseZoom;
      const viewWidth = cropCanvas.width / viewZoom;
      const viewHeight = cropCanvas.height / viewZoom;
      viewX = Math.max(0, (cropImage.width - viewWidth) / 2);
      viewY = Math.max(0, (cropImage.height - viewHeight) / 2);
    }
    function ensureViewBounds(){
      if(!cropImage || !cropCanvas){
        return;
      }
      const viewWidth = cropCanvas.width / viewZoom;
      const viewHeight = cropCanvas.height / viewZoom;
      viewX = clamp(viewX, 0, Math.max(0, cropImage.width - viewWidth));
      viewY = clamp(viewY, 0, Math.max(0, cropImage.height - viewHeight));
    }
    function parsePxValue(value){
      const parsed = Number.parseFloat(value);
      return Number.isFinite(parsed) ? parsed : 0;
    }
    function getCanvasCssScale(){
      if(!cropCanvas){
        return {scaleX:1, scaleY:1};
      }
      const style = window.getComputedStyle(cropCanvas);
      const cssWidth = cropCanvas.clientWidth || parsePxValue(style.width) || cropCanvas.width || 1;
      const cssHeight = cropCanvas.clientHeight || parsePxValue(style.height) || cropCanvas.height || 1;
      const scaleX = cssWidth && cropCanvas.width ? cssWidth / cropCanvas.width : 1;
      const scaleY = cssHeight && cropCanvas.height ? cssHeight / cropCanvas.height : 1;
      return {scaleX, scaleY};
    }
    function getViewportSize(){
      const vv = window.visualViewport;
      if(vv){
        return {
          width: Math.max(1, Math.floor(vv.width)),
          height: Math.max(1, Math.floor(vv.height)),
        };
      }
      return {
        width: Math.max(1, window.innerWidth || document.documentElement.clientWidth || 0),
        height: Math.max(1, window.innerHeight || document.documentElement.clientHeight || 0),
      };
    }
    function getCropContainerSize(viewportWidth, viewportHeight){
      if(!cropStageContainer){
        return {width: viewportWidth, height: viewportHeight};
      }
      const styles = window.getComputedStyle(cropStageContainer);
      const width = Math.max(
        0,
        cropStageContainer.clientWidth
          - parsePxValue(styles.paddingLeft)
          - parsePxValue(styles.paddingRight)
      );
      const height = Math.max(
        0,
        cropStageContainer.clientHeight
          - parsePxValue(styles.paddingTop)
          - parsePxValue(styles.paddingBottom)
      );
      if(width <= 0 || height <= 0){
        return {width: viewportWidth, height: viewportHeight};
      }
      return {
        width: Math.min(width, viewportWidth),
        height: Math.min(height, viewportHeight),
      };
    }
    function layoutCropStage(resetView = false){
      if(!cropStage || !cropCanvas || !cropImage){
        return;
      }
      const viewportSize = getViewportSize();
      const viewportWidth = viewportSize.width;
      const viewportHeight = viewportSize.height;
      const containerSize = getCropContainerSize(viewportWidth, viewportHeight);
      const toolbarRect = cropToolbar?.getBoundingClientRect();
      const toolbarReserve = (toolbarRect ? toolbarRect.height : 56) + 32;
      const minStageWidth = 220;
      const minStageHeight = 220;
      const usableWidth = Math.max(minStageWidth, Math.min(containerSize.width, viewportWidth));
      const viewportSafeHeight = Math.max(minStageHeight, viewportHeight - toolbarReserve);
      const usableHeight = Math.max(minStageHeight, Math.min(containerSize.height, viewportSafeHeight));
      const aspect = cropImage.height ? (cropImage.width / cropImage.height) : 1;
      let displayWidth = usableWidth;
      let displayHeight = displayWidth / aspect;
      if(displayHeight > usableHeight){
        displayHeight = usableHeight;
        displayWidth = displayHeight * aspect;
      }
      displayWidth = Math.max(minStageWidth, Math.min(displayWidth, viewportWidth));
      displayHeight = Math.max(minStageHeight, Math.min(displayHeight, viewportHeight - toolbarReserve));
      const physicalWidth = Math.max(1, Math.round(displayWidth * CANVAS_DPR));
      const physicalHeight = Math.max(1, Math.round(displayHeight * CANVAS_DPR));
      cropStage.style.width = `${displayWidth}px`;
      cropStage.style.height = `${displayHeight}px`;
      cropStage.style.maxWidth = '100%';
      cropStage.style.maxHeight = '100%';
      cropCanvas.width = physicalWidth;
      cropCanvas.height = physicalHeight;
      cropCanvas.style.width = `${displayWidth}px`;
      cropCanvas.style.height = `${displayHeight}px`;
      if(resetView){
        updateViewToFitImage();
        focusOnCropRect(true);
      } else {
        ensureViewBounds();
      }
      drawCropPreview();
    }
    function bindCropResize(){
      if(cropResizeHandler){
        return;
      }
      cropResizeHandler = ()=>{
        layoutCropStage(false);
      };
      window.addEventListener('resize', cropResizeHandler);
      window.addEventListener('orientationchange', cropResizeHandler);
      const vv = window.visualViewport;
      if(vv && !cropViewportHandler){
        cropViewportHandler = ()=> layoutCropStage(false);
        vv.addEventListener('resize', cropViewportHandler);
        vv.addEventListener('scroll', cropViewportHandler);
      }
    }
    function unbindCropResize(){
      if(!cropResizeHandler){
        return;
      }
      window.removeEventListener('resize', cropResizeHandler);
      window.removeEventListener('orientationchange', cropResizeHandler);
      cropResizeHandler = null;
      if(cropViewportHandler){
        const vv = window.visualViewport;
        if(vv){
          vv.removeEventListener('resize', cropViewportHandler);
          vv.removeEventListener('scroll', cropViewportHandler);
        }
        cropViewportHandler = null;
      }
    }

    function focusOnCropRect(force = false){
      if(!cropImage || !cropRect){
        return;
      }
      if(cropDragMode && !force){
        return;
      }
      // Check if entire image fits in canvas at baseZoom
      const baseViewWidth = cropCanvas.width / baseZoom;
      const baseViewHeight = cropCanvas.height / baseZoom;
      const imageFitsEntirely = baseViewWidth >= cropImage.width && baseViewHeight >= cropImage.height;

      if(imageFitsEntirely){
        // Image fits entirely - use baseZoom and center it
        viewZoom = baseZoom;
        viewX = Math.max(0, (cropImage.width - baseViewWidth) / 2);
        viewY = Math.max(0, (cropImage.height - baseViewHeight) / 2);
      } else {
        // Image doesn't fit - zoom to fit cropRect with padding
        const paddingX = Math.max(8, Math.min(24, cropCanvas.width * 0.02));
        const paddingY = Math.max(8, Math.min(24, cropCanvas.height * 0.02));
        const zoomX = (cropCanvas.width - paddingX * 2) / cropRect.width;
        const zoomY = (cropCanvas.height - paddingY * 2) / cropRect.height;
        const targetZoom = Math.min(8, Math.max(baseZoom, Math.min(zoomX, zoomY)));
        viewZoom = targetZoom;
        const viewWidth = cropCanvas.width / viewZoom;
        const viewHeight = cropCanvas.height / viewZoom;
        const centerX = cropRect.x + cropRect.width / 2;
        const centerY = cropRect.y + cropRect.height / 2;
        viewX = clamp(centerX - viewWidth / 2, 0, Math.max(0, cropImage.width - viewWidth));
        viewY = clamp(centerY - viewHeight / 2, 0, Math.max(0, cropImage.height - viewHeight));
      }
      ensureViewBounds();
    }
    function imageToCanvasPoint(point){
      return {
        x: (point.x - viewX) * viewZoom,
        y: (point.y - viewY) * viewZoom,
      };
    }
    function canvasToImagePoint(point){
      return {
        x: point.x / viewZoom + viewX,
        y: point.y / viewZoom + viewY,
      };
    }
    function displayRect(rect){
      const topLeft = imageToCanvasPoint({x:rect.x, y:rect.y});
      const bottomRight = imageToCanvasPoint({x:rect.x + rect.width, y:rect.y + rect.height});
      return {
        x: topLeft.x,
        y: topLeft.y,
        width: bottomRight.x - topLeft.x,
        height: bottomRight.y - topLeft.y,
      };
    }
    function displayRectCss(rect){
      const base = displayRect(rect);
      const {scaleX, scaleY} = getCanvasCssScale();
      return {
        x: base.x * scaleX,
        y: base.y * scaleY,
        width: base.width * scaleX,
        height: base.height * scaleY,
      };
    }
    function clampCropRect(rect){
      if(!cropImage){
        return rect;
      }
      const x = Math.max(0, Math.min(cropImage.width, rect.x));
      const y = Math.max(0, Math.min(cropImage.height, rect.y));
      const width = Math.max(1, Math.min(cropImage.width - x, rect.width));
      const height = Math.max(1, Math.min(cropImage.height - y, rect.height));
      return {x, y, width, height};
    }
    function normalizeRect(from, to){
      const x = Math.min(from.x, to.x);
      const y = Math.min(from.y, to.y);
      const width = Math.max(1, Math.abs(to.x - from.x));
      const height = Math.max(1, Math.abs(to.y - from.y));
      return {x, y, width, height};
    }
    function drawCropPreview(){
      const ctx = getCropContext();
      if(!ctx || !cropImage || !cropRect){
        return;
      }
      const viewWidth = cropCanvas.width / viewZoom;
      const viewHeight = cropCanvas.height / viewZoom;
      // Clamp source rect to image bounds
      const srcX = viewX;
      const srcY = viewY;
      const srcW = Math.min(viewWidth, cropImage.width - srcX);
      const srcH = Math.min(viewHeight, cropImage.height - srcY);
      // Calculate dest rect proportionally
      const dstW = srcW * viewZoom;
      const dstH = srcH * viewZoom;
      ctx.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
      ctx.drawImage(
        cropImage,
        srcX, srcY, srcW, srcH,
        0, 0, dstW, dstH
      );
      const rect = displayRect(cropRect);
      ctx.save();
      ctx.fillStyle = 'rgba(0,0,0,0.55)';
      ctx.fillRect(0, 0, cropCanvas.width, cropCanvas.height);
      ctx.globalCompositeOperation = 'destination-out';
      ctx.fillStyle = 'rgba(0,0,0,1)';
      ctx.fillRect(rect.x, rect.y, rect.width, rect.height);
      ctx.globalCompositeOperation = 'source-over';
      ctx.beginPath();
      ctx.rect(rect.x, rect.y, rect.width, rect.height);
      ctx.clip();
      ctx.drawImage(
        cropImage,
        srcX, srcY, srcW, srcH,
        0, 0, dstW, dstH
      );
      ctx.restore();
      refreshCropRectOverlay();
    }
    function refreshCropRectOverlay(){
      if(!cropRectEl || !cropImage || !cropRect){
        return;
      }
      const rect = displayRectCss(cropRect);
      cropRectEl.style.left = `${rect.x}px`;
      cropRectEl.style.top = `${rect.y}px`;
      cropRectEl.style.width = `${rect.width}px`;
      cropRectEl.style.height = `${rect.height}px`;
    }
    function getPointerImageCoords(event){
      if(!cropCanvas || !cropImage){
        return {x:0,y:0};
      }
      const rect = cropCanvas.getBoundingClientRect();
      const canvasX = ((event.clientX - rect.left) / rect.width) * cropCanvas.width;
      const canvasY = ((event.clientY - rect.top) / rect.height) * cropCanvas.height;
      const imagePoint = canvasToImagePoint({x:canvasX, y:canvasY});
      return {
        x: clamp(imagePoint.x, 0, cropImage.width),
        y: clamp(imagePoint.y, 0, cropImage.height),
      };
    }
    function handleCropPointerDown(e){
      if(!cropImage || !cropStage) return;
      if(e.button !== undefined && e.button !== 0) return;
      const handleEl = e.target.closest('.ocr-crop-handle');
      const rectEl = cropRectEl;
      cropDragStart = getPointerImageCoords(e);
      if(!cropRect){
        cropRect = {x:0,y:0,width:cropImage.width,height:cropImage.height};
      }
      pushCropHistory();
      cropDragStartRect = {...cropRect};
      if(handleEl){
        cropDragMode = 'resize';
        cropActiveHandle = handleEl.dataset.handle || '';
      } else if(rectEl && rectEl.contains(e.target)){
        cropDragMode = 'move';
        cropActiveHandle = null;
      } else {
        cropDragMode = 'draw';
        cropActiveHandle = null;
        cropRect = clampCropRect(normalizeRect(cropDragStart, cropDragStart));
        drawCropPreview();
      }
      e.preventDefault();
      try{ cropStage.setPointerCapture(e.pointerId); }catch(_e){}
    }
    function handleCropPointerMove(e){
      if(!cropImage || !cropDragMode || !cropDragStart){
        return;
      }
      const point = getPointerImageCoords(e);
      e.preventDefault();
      if(cropDragMode === 'move' && cropDragStartRect){
        const dx = point.x - cropDragStart.x;
        const dy = point.y - cropDragStart.y;
        const maxX = cropImage.width - cropDragStartRect.width;
        const maxY = cropImage.height - cropDragStartRect.height;
        const newX = Math.min(Math.max(0, cropDragStartRect.x + dx), Math.max(0, maxX));
        const newY = Math.min(Math.max(0, cropDragStartRect.y + dy), Math.max(0, maxY));
        cropRect = {x:newX, y:newY, width:cropDragStartRect.width, height:cropDragStartRect.height};
      } else if(cropDragMode === 'resize' && cropDragStartRect){
        cropRect = resizeCropRect(cropDragStartRect, cropActiveHandle, point);
      } else if(cropDragMode === 'draw'){
        cropRect = clampCropRect(normalizeRect(cropDragStart, point));
      }
      drawCropPreview();
    }
    function handleCropPointerUp(e){
      if(cropStage){
        try{ cropStage.releasePointerCapture(e.pointerId); }catch(_e){}
      }
      cropDragMode = null;
      cropActiveHandle = null;
      cropDragStart = null;
      cropDragStartRect = null;
      focusOnCropRect();
      drawCropPreview();
    }
    function resizeCropRect(baseRect, handle, point){
      if(!cropImage){
        return baseRect;
      }
      let left = baseRect.x;
      let top = baseRect.y;
      let right = baseRect.x + baseRect.width;
      let bottom = baseRect.y + baseRect.height;
      const minSize = 16;
      const clampX = val => Math.min(Math.max(0, val), cropImage.width);
      const clampY = val => Math.min(Math.max(0, val), cropImage.height);
      if(handle && handle.includes('w')){
        left = clampX(point.x);
        left = Math.min(left, right - minSize);
      }
      if(handle && handle.includes('e')){
        right = clampX(point.x);
        right = Math.max(right, left + minSize);
      }
      if(handle && handle.includes('n')){
        top = clampY(point.y);
        top = Math.min(top, bottom - minSize);
      }
      if(handle && handle.includes('s')){
        bottom = clampY(point.y);
        bottom = Math.max(bottom, top + minSize);
      }
      const width = Math.max(minSize, right - left);
      const height = Math.max(minSize, bottom - top);
      return clampCropRect({x:left, y:top, width, height});
    }
    function closeCropper(){
      if(cropModal){
        cropModal.classList.add('hidden');
        cropModal.style.display = 'none';
      }
      document.body.classList.remove('cropper-open');
      unbindCropResize();
      pendingImageFile = null;
      cropImage = null;
      cropRect = null;
      cropDragMode=null;
      cropActiveHandle=null;
      cropDragStart=null;
      cropDragStartRect=null;
      viewZoom=1;
      baseZoom=1;
      viewX=0;
      viewY=0;
      resetCropHistory();
      if(cropImageUrl){
        try{ URL.revokeObjectURL(cropImageUrl); }catch(_e){}
        cropImageUrl = null;
      }
      const ctx = getCropContext();
      if(ctx && cropCanvas){
        ctx.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
      }
      if(cropRectEl){
        cropRectEl.style.left = '0px';
        cropRectEl.style.top = '0px';
        cropRectEl.style.width = '0px';
        cropRectEl.style.height = '0px';
      }
    }
    async function loadCropImage(file){
      return new Promise((resolve,reject)=>{
        const img = new Image();
        img.onload = ()=>resolve(img);
        img.onerror = reject;
        if(cropImageUrl){
          try{ URL.revokeObjectURL(cropImageUrl); }catch(_e){}
        }
        cropImageUrl = URL.createObjectURL(file);
        img.src = cropImageUrl;
      });
    }
    async function openCropper(file){
      if(!file || !cropModal){
        return;
      }
      pendingImageFile = file;
      if(cropModal){
        cropModal.classList.remove('hidden');
        cropModal.style.display = 'flex';
      }
      document.body.classList.add('cropper-open');
      try{
        await new Promise(resolve => requestAnimationFrame(resolve));
        cropImage = await loadCropImage(file);
        cropRect = {x:0, y:0, width:cropImage.width, height:cropImage.height};
        console.log('[Cropper] Image loaded:', cropImage.width, 'x', cropImage.height);
        console.log('[Cropper] Initial cropRect:', JSON.stringify(cropRect));
        layoutCropStage(true);
        console.log('[Cropper] After layout - canvas:', cropCanvas.width, 'x', cropCanvas.height);
        console.log('[Cropper] After layout - baseZoom:', baseZoom, 'viewZoom:', viewZoom);
        console.log('[Cropper] After layout - viewX:', viewX, 'viewY:', viewY);
        console.log('[Cropper] After layout - cropRect:', JSON.stringify(cropRect));
        const rectCss = displayRectCss(cropRect);
        console.log('[Cropper] displayRectCss:', JSON.stringify(rectCss));
        resetCropHistory();
        pushCropHistory();
        bindCropResize();
      }catch(err){
        setOcrStatus('error', err?.message || ocrStrings.error);
        closeCropper();
      }
    }
    async function applyCropForOcr(){
      if(!cropImage || !pendingImageFile){
        return;
      }
      if(!cropRect){
        cropRect = {x:0, y:0, width:cropImage.width, height:cropImage.height};
      }
      const width = Math.max(1, Math.round(cropRect.width));
      const height = Math.max(1, Math.round(cropRect.height));
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(cropImage, cropRect.x, cropRect.y, cropRect.width, cropRect.height, 0, 0, width, height);
      const blob = await new Promise((resolve,reject)=>{
        canvas.toBlob(b=> b ? resolve(b) : reject(new Error('Crop failed')), 'image/jpeg', 0.92);
      });
      const croppedFile = new File([blob], pendingImageFile.name || 'ocr.jpg', {type: blob.type || 'image/jpeg'});
      ocrLastFile = croppedFile;
      closeCropper();
      triggerOcrRecognition(croppedFile);
    }
    setSttStatus(sttEnabled ? 'idle' : 'disabled');
    setOcrStatus(ocrEnabled ? 'idle' : 'disabled');
    if(cropStage && !cropStage.dataset.bound){
      cropStage.dataset.bound = '1';
      cropStage.addEventListener('pointerdown', handleCropPointerDown);
      window.addEventListener('pointermove', handleCropPointerMove, {passive:false});
      window.addEventListener('pointerup', handleCropPointerUp);
      window.addEventListener('pointercancel', handleCropPointerUp);
    }
    if(cropApplyBtn){
      cropApplyBtn.addEventListener('click', ()=>{
        if(!pendingImageFile || !ocrEnabled){
          setOcrStatus('disabled');
          return;
        }
        applyCropForOcr().catch(err=> setOcrStatus('error', err?.message || ocrStrings.error));
      });
    }
    if(cropUndoBtn){
      cropUndoBtn.addEventListener('click', e=>{
        e.preventDefault();
        if(!cropHistory.length) return;
        const snapshot = cropHistory.pop();
        cropRect = snapshot.rect;
        viewZoom = snapshot.viewZoom;
        viewX = snapshot.viewX;
        viewY = snapshot.viewY;
        updateUndoState();
        drawCropPreview();
      });
    }
    const closeCrop = ()=>{ closeCropper(); };
    if(cropCancelBtn) cropCancelBtn.addEventListener('click', closeCrop);
    updateUndoState();
    if(sttUndoBtn){
      sttUndoBtn.addEventListener('click', e=>{
        e.preventDefault();
        if(!sttUndoValue) return;
        const front = $("#uFront");
        if(front){
          front.value = sttUndoValue;
          try{ front.focus(); }catch(_e){}
          front.dispatchEvent(new Event('input', {bubbles:true}));
          front.dispatchEvent(new Event('change', {bubbles:true}));
        }
        sttUndoValue = null;
        setSttUndoVisible(false);
        setSttStatus(sttEnabled ? 'idle' : 'disabled');
      });
    }
    if(ocrUndoBtn){
      ocrUndoBtn.addEventListener('click', e=>{
        e.preventDefault();
        if(!ocrUndoValue) return;
        const front = $("#uFront");
        if(front){
          front.value = ocrUndoValue;
          try{ front.focus(); }catch(_e){}
          front.dispatchEvent(new Event('input', {bubbles:true}));
          front.dispatchEvent(new Event('change', {bubbles:true}));
        }
        ocrUndoValue = null;
        setOcrUndoVisible(false);
        setOcrStatus(ocrEnabled ? 'idle' : 'disabled');
      });
    }
    if(sttRetryBtn){
      sttRetryBtn.addEventListener('click', async e=>{
        e.preventDefault();
        try{
          const blob = await getCurrentAudioBlob();
          if(blob){
            triggerTranscription(blob).catch(err=>recorderLog('Retry failed: '+(err?.message||err)));
          }
        }catch(_e){
          setSttStatus('error', sttStrings.error);
        }
      });
    }
    if(ocrRetryBtn){
      ocrRetryBtn.addEventListener('click', async e=>{
        e.preventDefault();
        if(!ocrLastFile){
          setOcrStatus('error', ocrStrings.error);
          return;
        }
        try{
          await triggerOcrRecognition(ocrLastFile);
        }catch(err){
          setOcrStatus('error', err?.message || ocrStrings.error);
        }
      });
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
    }
    const mediaPillRegistry = [];
    let mediaPillsInitialized = false;
    function updateMediaPillPlayingState(badge, playing){
      if(!badge){
        return;
      }
      badge.classList.toggle('media-pill-playing', !!playing);
      badge.setAttribute('aria-pressed', playing ? 'true' : 'false');
    }
    function pauseOtherMediaPills(exceptAudio){
      mediaPillRegistry.forEach(entry=>{
        const otherAudio = entry.audio;
        if(otherAudio && otherAudio !== exceptAudio){
          try{ otherAudio.pause(); }catch(_e){}
        }
      });
    }
    function registerMediaPill(badgeId, audioId){
      const badge = document.getElementById(badgeId);
      const audio = document.getElementById(audioId);
      if(!badge || !audio){
        return;
      }
      function togglePlayback(){
        if(audio.paused){
          pauseOtherMediaPills(audio);
          const playPromise = audio.play();
          if(playPromise && typeof playPromise.catch === 'function'){
            playPromise.catch(()=>{});
          }
        } else {
          audio.pause();
        }
      }
      badge.addEventListener('click', event=>{
        if(event.target?.closest('.iconbtn')){
          return;
        }
        togglePlayback();
      });
      badge.addEventListener('keydown', event=>{
        if(event.key === 'Enter' || event.key === ' '){
          event.preventDefault();
          togglePlayback();
        }
      });
      audio.addEventListener('play', ()=>updateMediaPillPlayingState(badge, true));
      audio.addEventListener('pause', ()=>updateMediaPillPlayingState(badge, false));
      audio.addEventListener('ended', ()=>updateMediaPillPlayingState(badge, false));
      audio.addEventListener('error', ()=>updateMediaPillPlayingState(badge, false));
      mediaPillRegistry.push({badge, audio});
    }
    function initMediaPillPlayback(){
      if(mediaPillsInitialized){
        return;
      }
      mediaPillsInitialized = true;
      registerMediaPill('audBadge', 'audPrev');
      registerMediaPill('focusAudBadge', 'focusAudPrev');
    }
    initMediaPillPlayback();
    function showAudioBadge(label){
      const badge=document.getElementById('audBadge');
      const nameEl=document.getElementById('audName');
      if(nameEl) nameEl.textContent = label || '';
      if(badge){
        badge.classList.remove('hidden');
      }
    }
    function hideAudioBadge(){
      const badge=document.getElementById('audBadge');
      if(badge){
        badge.classList.add('hidden');
        updateMediaPillPlayingState(badge, false);
      }
      const nameEl=document.getElementById('audName');
      if(nameEl) nameEl.textContent = '';
    }
    async function getCurrentAudioBlob(){
      if(lastAudioBlob){
        return lastAudioBlob;
      }
      if(lastAudioKey){
        try{
          return await idbGetSafe(lastAudioKey);
        }catch(_e){}
      }
      return null;
    }
    async function measureBlobDuration(blob){
      if(!blob){
        return 0;
      }
      return await new Promise(resolve=>{
        try{
          const audio = document.createElement('audio');
          audio.preload = 'metadata';
          audio.src = URL.createObjectURL(blob);
          audio.addEventListener('loadedmetadata', ()=>{
            try{ URL.revokeObjectURL(audio.src); }catch(_e){}
            if(Number.isFinite(audio.duration)){
              resolve(Math.max(0, Math.round(audio.duration)));
            }else{
              resolve(0);
            }
          });
          audio.addEventListener('error', ()=>{
            try{ URL.revokeObjectURL(audio.src); }catch(_e){}
            resolve(0);
          });
        }catch(_e){
          resolve(0);
        }
      });
    }
    async function ensureAudioDuration(blob){
      if(lastAudioDurationSec > 0){
        return lastAudioDurationSec;
      }
      const measured = await measureBlobDuration(blob);
      if(measured > 0){
        lastAudioDurationSec = measured;
      }
      return lastAudioDurationSec;
    }
    function insertFrontText(text){
      const trimmed = (text || '').trim();
      if(trimmed === ''){
        return null;
      }
      const front = $("#uFront");
      if(!front){
        return null;
      }
      const previous = front.value;
      front.value = trimmed;
      try{ front.focus(); }catch(_e){}
      front.dispatchEvent(new Event('input', {bubbles:true}));
      front.dispatchEvent(new Event('change', {bubbles:true}));
      return { trimmed, previous };
    }
    function applyTranscriptionResult(text){
      const result = insertFrontText(text);
      if(!result){
        return;
      }
      if(result.previous && result.previous !== result.trimmed){
        sttUndoValue = result.previous;
        setSttUndoVisible(true);
      } else {
        sttUndoValue = null;
        setSttUndoVisible(false);
      }
    }
    function applyOcrResult(text){
      const result = insertFrontText(text);
      if(!result){
        return;
      }
      if(result.previous && result.previous !== result.trimmed){
        ocrUndoValue = result.previous;
        setOcrUndoVisible(true);
      } else {
        ocrUndoValue = null;
        setOcrUndoVisible(false);
      }
    }
    async function triggerTranscription(blob){
      if(!sttEnabled || !blob){
        return;
      }
      const duration = Math.max(1, await ensureAudioDuration(blob));
      if(sttClipLimit && duration > sttClipLimit){
        setSttStatus('limit');
        return;
      }
      const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
      url.searchParams.set('cmid', cmid);
      url.searchParams.set('action', 'transcribe_audio');
      url.searchParams.set('sesskey', sesskey);
      const fd = new FormData();
      fd.append('file', blob, 'private-audio.webm');
      fd.append('duration', String(duration));
      if(sttLanguage){
        fd.append('language', sttLanguage);
      }
      if(sttAbortController){
        try{ sttAbortController.abort(); }catch(_e){}
      }
      const controller = new AbortController();
      sttAbortController = controller;
      setSttStatus('uploading');
      const stageTimer = window.setTimeout(()=>{
        if(sttAbortController === controller){
          setSttStatus('transcribing');
        }
      }, 800);
      try{
        const response = await fetch(url.toString(), {method:'POST', body:fd, signal:controller.signal});
        let payload=null;
        try{
          payload = await response.json();
        }catch(_e){
          if(!response.ok){
            throw new Error('HTTP '+response.status);
          }
          throw _e;
        }
        if(!payload || payload.ok !== true){
          const code = payload?.errorcode || '';
          const message = payload?.error || sttStrings.error;
          if(code === 'error_whisper_clip'){
            setSttStatus('limit', message);
            return;
          }
          if(code === 'error_whisper_quota'){
            setSttStatus('quota', message);
            return;
          }
          throw new Error(message);
        }
        const text = (payload.data && payload.data.text ? (payload.data.text + '') : '').trim();
        if(!text){
          throw new Error('Empty transcription');
        }
        updateUsageFromResponse(payload.data);
        lastAudioDurationSec = Math.min(duration, sttClipLimit || duration);
        applyTranscriptionResult(text);
        setSttStatus('success');
        clearAudioSelection();
        window.setTimeout(()=>{
          if(!sttAbortController){
            setSttStatus('idle');
          }
        }, 3500);
      }catch(err){
        if(err && err.name === 'AbortError'){
          setSttStatus('idle');
        }else{
          setSttStatus('error', err?.message || sttStrings.error);
        }
      }finally{
        clearTimeout(stageTimer);
      if(sttAbortController === controller){
          sttAbortController = null;
        }
      }
    }
    async function triggerOcrRecognition(file){
      if(!ocrEnabled || !file){
        setOcrStatus('disabled');
        return;
      }
      if(ocrMaxFileSize && file.size > ocrMaxFileSize){
        const pretty = formatFileSize(ocrMaxFileSize);
        const limitMessage = pretty ? `Image must be ${pretty} or smaller.` : ocrStrings.error;
        setOcrStatus('error', limitMessage);
        return;
      }
      const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
      url.searchParams.set('cmid', cmid);
      url.searchParams.set('action', 'recognize_image');
      url.searchParams.set('sesskey', sesskey);
      const fd = new FormData();
      fd.append('file', file, file.name || 'ocr-image.jpg');
      if(ocrAbortController){
        try{ ocrAbortController.abort(); }catch(_e){}
      }
      const controller = new AbortController();
      ocrAbortController = controller;
      setOcrStatus('processing');
      try{
        const response = await fetch(url.toString(), {method:'POST', body:fd, signal:controller.signal});
        let payload = null;
        try{
          payload = await response.json();
        }catch(_e){
          if(!response.ok){
            throw new Error('HTTP '+response.status);
          }
          throw _e;
        }
        if(!payload || payload.ok !== true){
          const message = payload?.error || ocrStrings.error;
          throw new Error(message);
        }
        const text = (payload.data && payload.data.text ? (payload.data.text + '') : '').trim();
        if(!text){
          throw new Error('Empty OCR result');
        }
        applyOcrResult(text);
        setOcrStatus('success');
        ocrLastFile = file;
        window.setTimeout(()=>{
          if(!ocrAbortController){
            setOcrStatus('idle');
          }
        }, 3500);
      }catch(err){
        if(err && err.name === 'AbortError'){
          setOcrStatus('idle');
        }else{
          setOcrStatus('error', err?.message || ocrStrings.error);
        }
      }finally{
        if(ocrAbortController === controller){
          ocrAbortController = null;
        }
      }
    }
    function showFocusAudioBadge(label){
      const badge=document.getElementById('focusAudBadge');
      const nameEl=document.getElementById('focusAudName');
      if(nameEl) nameEl.textContent = label || aiStrings.focusAudio;
      if(badge){
        badge.classList.remove('hidden');
      }
    }
    function hideFocusAudioBadge(){
      const badge=document.getElementById('focusAudBadge');
      if(badge){
        badge.classList.add('hidden');
        updateMediaPillPlayingState(badge, false);
      }
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
      lastAudioDurationSec=0;
      privateAudioOnly=false;
      const audioInput=$("#uAudio");
      if(audioInput) audioInput.value="";
      resetAudioPreview();
      if(!sttAbortController){
        setSttStatus(sttEnabled ? 'idle' : 'disabled');
      }
      sttUndoValue=null;
      setSttUndoVisible(false);
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
    function hidePlayIcons(){
      if(btnPlayBtn) btnPlayBtn.classList.add('hidden');
      if(btnPlaySlowBtn) btnPlaySlowBtn.classList.add('hidden');
      const btnRecordStudy = $("#btnRecordStudy");
      if(btnRecordStudy) btnRecordStudy.classList.add('hidden');
      const recorderDock = document.getElementById('metaRecorder');
      if(recorderDock) recorderDock.classList.add('hidden');
      // Clear pronunciation practice audio
      if(window.setStudyRecorderAudio){
        window.setStudyRecorderAudio(null);
      }
    }
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
      console.log('[DEBUG] attachAudio called with:', url);

      // Set current audio URL for pronunciation practice
      if(window.setStudyRecorderAudio){
        console.log('[DEBUG] Calling setStudyRecorderAudio');
        window.setStudyRecorderAudio(url);
      }else{
        console.log('[DEBUG] window.setStudyRecorderAudio not available');
      }

      if(btnPlayBtn) btnPlayBtn.classList.remove("hidden");
      if(btnPlaySlowBtn) btnPlaySlowBtn.classList.remove("hidden");

      // Show pronunciation practice button
      const btnRecordStudy = $("#btnRecordStudy");
      if(btnRecordStudy){
        btnRecordStudy.classList.remove("hidden");
      }
      const recorderDock = document.getElementById('metaRecorder');
      if(recorderDock){
        recorderDock.classList.remove('hidden');
      }

      if(btnPlayBtn){
        btnPlayBtn.onclick=()=>{ if(!audioURL)return; playAudioFromUrl(audioURL,1); };
      }
      if(btnPlaySlowBtn){
        btnPlaySlowBtn.onclick=()=>{ if(!audioURL)return; playAudioFromUrl(audioURL,0.67); };
      }
    }
    function stopStudyPlayback(){
      try{ player.pause(); }catch(_e){}
      try{ player.currentTime = 0; }catch(_e){}
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

      // Auto-build order ONLY if order was never explicitly set (null/undefined)
      // Empty array [] means user explicitly chose no layers (valid choice)
      console.log('[DEBUG normalizeLessonCard] BEFORE - id:', c.id, 'order:', c.order, 'isArray:', Array.isArray(c.order));
      if(!Array.isArray(c.order)){
        console.log('[DEBUG normalizeLessonCard] Auto-building order for card:', c.id);
        c.order=[];
        // Order: word audio first, then transcription, then sentence audio, then text, then translation
        if(c.focusAudio||c.focusAudioKey) c.order.push("audio_text");
        if(c.transcription) c.order.push("transcription");
        if(c.audio||c.audioKey) c.order.push("audio");
        if(c.text) c.order.push("text");
        if(c.translation) c.order.push("translation");
      }
      c.order=uniq(c.order);
      console.log('[DEBUG normalizeLessonCard] AFTER - id:', c.id, 'order:', c.order);
      return c;
    }
    async function resolveAudioUrl(card, type){
      if(type==='front'){
        if(card.audioKey){
          return await urlForSafe(card.audioKey);
        }else if(card.audio){
          return resolveAsset(card.audio);
        }
      }else if(type==='focus'){
        if(card.focusAudioKey){
          return await urlForSafe(card.focusAudioKey);
        }else if(card.focusAudio){
          const resolved = resolveAsset(card.focusAudio);
          return resolved || card.focusAudio;
        }
      }
      return null;
    }
    function renderAudioButtons(el, tracks, options = {}){
      if(!Array.isArray(tracks) || !tracks.length){
        return el;
      }
      const wrap = document.createElement("div");
      wrap.className = "audio-slot-controls";
      const { attachFront=false } = options;
      tracks.forEach((track, idx)=>{
        const block = document.createElement("div");
        block.className = "audio-control-block";
        const header = document.createElement("div");
        header.className = "audio-control-header";
        header.textContent = track.type === 'focus' ? (aiStrings.focusAudio || 'Focus audio') : (aiStrings.frontAudio || 'Audio');
        block.appendChild(header);
        const row = document.createElement("div");
        row.className = "audio-control-row";
        const playBtn = document.createElement("button");
        playBtn.type = "button";
        playBtn.className = "audio-control-button";
        playBtn.title = t('title_play') || 'Play';
        playBtn.innerHTML = '<span class="icon">&#128266;</span>';
        playBtn.addEventListener("click", ()=>{
          attachAudio(track.url);
          playAudioFromUrl(track.url, 1);
        });
        const slow85 = document.createElement("button");
        slow85.type = "button";
        slow85.className = "audio-control-button";
        slow85.title = '0.85x';
        slow85.innerHTML = '<span class="slow-label">0.85</span><span class="icon">&#128034;</span>';
        slow85.addEventListener("click", ()=>{
          attachAudio(track.url);
          playAudioFromUrl(track.url, 0.85);
        });
        const slow70 = document.createElement("button");
        slow70.type = "button";
        slow70.className = "audio-control-button";
        slow70.title = '0.7x';
        slow70.innerHTML = '<span class="slow-label">0.7</span><span class="icon">&#128034;</span>';
        slow70.addEventListener("click", ()=>{
          attachAudio(track.url);
          playAudioFromUrl(track.url, 0.7);
        });
        row.appendChild(playBtn);
        row.appendChild(slow85);
        row.appendChild(slow70);
        block.appendChild(row);
        wrap.appendChild(block);
        if(attachFront && track.type==='front'){
          attachAudio(track.url);
        }
        if(!el.dataset.autoplay && (track.type === 'front' || idx === 0)){
          el.dataset.autoplay = track.url;
        }
      });
      el.appendChild(wrap);
      return el;
    }

    // ========== DICTATION EXERCISE LOGIC ==========
    async function setupDictationExercise(slotEl, card, dictationId){
      const exerciseEl = slotEl.querySelector(`[data-dictation-id="${dictationId}"]`);
      if(!exerciseEl) return;

      const inputEl = exerciseEl.querySelector('.dictation-input');
      const checkBtn = exerciseEl.querySelector('.dictation-check-btn');
      const replayBtn = exerciseEl.querySelector('.dictation-replay-btn');
      const resultEl = exerciseEl.querySelector('.dictation-result');
      const correctAnswerEl = exerciseEl.querySelector('.dictation-correct-answer');

      if(!inputEl || !checkBtn || !replayBtn || !resultEl || !correctAnswerEl) return;

      const correctText = inputEl.dataset.correctText || card.text;
      let audioPlayed = false;
      let checkCount = 0;

      // Get audio URL for replay button (but don't auto-play here - handled by showCurrent autoplay logic)
      const frontUrl = await resolveAudioUrl(card, 'front');

      // Replay button - play audio again at 0.85x
      replayBtn.addEventListener('click', () => {
        if(frontUrl){
          playAudioFromUrl(frontUrl, 0.85);
        }
      });

      // Check button - verify user input and show visual feedback
      checkBtn.addEventListener('click', () => {
        const userInput = inputEl.value.trim();

        if(!userInput){
          resultEl.innerHTML = '<div class="dictation-error-msg">⚠️ ' + (aiStrings.dictationEmptyInput || 'Введите текст') + '</div>';
          resultEl.classList.remove('hidden');
          return;
        }

        checkCount++;

        // Compare user input with correct text
        const comparison = compareTexts(userInput, correctText);

        // Show visual feedback
        renderComparisonResult(resultEl, comparison);
        resultEl.classList.remove('hidden');

        // Show correct answer after check
        correctAnswerEl.classList.remove('hidden');

        // Disable input after successful completion
        if(comparison.isCorrect){
          inputEl.disabled = true;
          inputEl.classList.add('dictation-success');
          checkBtn.disabled = true;
          checkBtn.textContent = '✓ ' + (aiStrings.dictationCorrect || 'Правильно!');
          checkBtn.classList.remove('primary');
          checkBtn.classList.add('success');
        } else if(checkCount >= 2){
          // After 2 attempts, show hint
          inputEl.placeholder = aiStrings.dictationHint || 'Смотрите правильный ответ ниже';
        }
      });

      // Allow Enter key to trigger check
      inputEl.addEventListener('keydown', (e) => {
        if(e.key === 'Enter' && !e.shiftKey){
          e.preventDefault();
          checkBtn.click();
        }
      });
    }

    // Compare two texts character by character and word by word
﻿    // Compare dictation answers with move-aware logic
    function compareTexts(userInput, correctText){
      const userNorm = (userInput || '').trim();
      const correctNorm = (correctText || '').trim();

      if(userNorm === correctNorm){
        return {
          isCorrect: true,
          userTokens: tokenizeText(userInput),
          originalTokens: tokenizeText(correctText),
          matches: [],
          extras: [],
          missing: [],
          movePlan: { moveBlocks: [], rewriteGroups: [], tokenMeta: [], gapMeta: {}, gapsNeeded: new Set(), missingByPosition: {} },
          errorCount: 0
        };
      }

      const userTokens = tokenizeText(userNorm);
      const originalTokens = tokenizeText(correctNorm);

      const similarity = buildSimilarityMatrix(userTokens, originalTokens);
      const assignment = solveMaxAssignment(similarity);
      const MIN_ACCEPTABLE = 0.35;

      const matches = [];
      const matchedUser = new Set();
      const matchedOrig = new Set();

      assignment.forEach((item, idx) => {
        if(!item) return;
        const { row, col, weight } = item;
        if(row >= userTokens.length || col >= originalTokens.length) return;
        if(weight < MIN_ACCEPTABLE) return;
        matches.push({
          id: idx,
          userIndex: row,
          origIndex: col,
          userToken: userTokens[row],
          origToken: originalTokens[col],
          score: weight,
          exact: userTokens[row].raw === originalTokens[col].raw
        });
        matchedUser.add(row);
        matchedOrig.add(col);
      });

      const extras = [];
      userTokens.forEach((token, index)=>{
        if(!matchedUser.has(index)){
          extras.push({ userIndex:index, token });
        }
      });

      const missing = [];
      originalTokens.forEach((token, index)=>{
        if(!matchedOrig.has(index)){
          missing.push({ origIndex:index, token });
        }
      });

      const lisIndices = longestIncreasingSubsequence(matches.map(m=>m.origIndex));
      const lisSet = new Set(lisIndices.map(idx=> matches[idx]?.id).filter(Boolean));
      const movePlan = buildMovePlan(matches, lisSet, userTokens, originalTokens);
      const missingByPosition = buildMissingByPosition(missing, matches, userTokens.length);
      movePlan.missingByPosition = missingByPosition;

      const spellingIssues = matches.filter(m => m.userToken.raw !== m.origToken.raw).length;
      const orderIssues = matches.filter(m => !lisSet.has(m.id)).length;
      const moveIssues = movePlan.rewriteGroups.length + movePlan.moveBlocks.filter(b=>!b.resolvedByRewrite).length;
      const errorCount = extras.length + missing.length + spellingIssues + orderIssues + moveIssues;

      return {
        isCorrect: false,
        userTokens,
        originalTokens,
        matches,
        extras,
        missing,
        movePlan,
        errorCount
      };
    }

    const MIN_SIMILARITY_SCORE = 0.35;
    const STEM_SIMILARITY_SCORE = 0.85;

    function tokenizeText(text){
      const tokens = [];
      if(!text){
        return tokens;
      }
      const pattern = /[\p{L}\p{M}]+|\d+|[^\s\p{L}\p{M}\d]/gu;
      let match;
      let idx = 0;
      while((match = pattern.exec(text)) !== null){
        const raw = match[0];
        tokens.push({
          raw,
          norm: raw.toLowerCase(),
          type: /^[\p{L}\p{M}\d]+$/u.test(raw) ? 'word' : 'punct',
          index: idx++
        });
      }
      return tokens;
    }

    function normalizePunctuation(char){
      const map = {
        '—': '-',
        '–': '-',
        '?': '-',
        '…': '...',
        '“': '"',
        '”': '"',
        '«': '"',
        '»': '"',
        "'": "'"
      };
      return map[char] || char;
    }

    function simpleStem(value){
      if(!value) return '';
      const normalized = value.toLowerCase().replace(/^[^\p{L}\p{M}]+|[^\p{L}\p{M}]+$/gu, '');
      return normalized.replace(/(ene|ane|ene|het|ene|ers|er|en|et|e)$/u, '');
    }

    function levenshtein(a,b){
      if(a === b) return 0;
      if(!a.length) return b.length;
      if(!b.length) return a.length;
      const dp = Array.from({length: a.length + 1}, (_, i) => i);
      for(let j = 1; j <= b.length; j++){
        let prev = j - 1;
        dp[0] = j;
        for(let i = 1; i <= a.length; i++){
          const temp = dp[i];
          if(a[i-1] === b[j-1]){
            dp[i] = prev;
          } else {
            dp[i] = 1 + Math.min(prev, dp[i-1], dp[i]);
          }
          prev = temp;
        }
      }
      return dp[a.length];
    }

    function tokenSimilarity(a, b){
      if(!a || !b) return 0;
      if(a.type === 'punct' && b.type === 'punct'){
        const normA = normalizePunctuation(a.raw);
        const normB = normalizePunctuation(b.raw);
        if(normA === normB) return 0.8;
        return 0.2;
      }
      if(a.type !== b.type){
        return 0.05;
      }
      if(a.norm === b.norm){
        return 1;
      }
      const stemA = simpleStem(a.norm);
      const stemB = simpleStem(b.norm);
      if(stemA && stemA === stemB){
        return STEM_SIMILARITY_SCORE;
      }
      const distance = levenshtein(a.norm, b.norm);
      const maxLen = Math.max(a.norm.length, b.norm.length) || 1;
      const closeness = Math.max(0, 1 - (distance / maxLen));
      if(closeness >= 0.8) return 0.6 + (closeness * 0.25);
      if(closeness >= 0.6) return 0.5 + (closeness * 0.2);
      return closeness * 0.5;
    }

    function buildSimilarityMatrix(userTokens, originalTokens){
      const matrix = [];
      for(let i = 0; i < userTokens.length; i++){
        matrix[i] = [];
        for(let j = 0; j < originalTokens.length; j++){
          matrix[i][j] = tokenSimilarity(userTokens[i], originalTokens[j]);
        }
      }
      return matrix;
    }

    function solveMaxAssignment(weights){
      const rows = weights.length;
      const cols = weights[0] ? weights[0].length : 0;
      const n = Math.max(rows, cols);
      if(!n) return [];
      let maxWeight = 0;
      for(let i = 0; i < rows; i++){
        for(let j = 0; j < cols; j++){
          maxWeight = Math.max(maxWeight, weights[i][j]);
        }
      }
      const big = maxWeight + 1;
      const cost = Array.from({length: n}, (_, i)=>{
        const row = [];
        for(let j = 0; j < n; j++){
          if(i < rows && j < cols){
            row.push(big - weights[i][j]);
          } else {
            row.push(big);
          }
        }
        return row;
      });
      const u = Array(n + 1).fill(0);
      const v = Array(n + 1).fill(0);
      const p = Array(n + 1).fill(0);
      const way = Array(n + 1).fill(0);
      for(let i = 1; i <= n; i++){
        p[0] = i;
        let j0 = 0;
        const minv = Array(n + 1).fill(Infinity);
        const used = Array(n + 1).fill(false);
        do{
          used[j0] = true;
          const i0 = p[j0];
          let delta = Infinity;
          let j1 = 0;
          for(let j = 1; j <= n; j++){
            if(used[j]) continue;
            const cur = cost[i0 - 1][j - 1] - u[i0] - v[j];
            if(cur < minv[j]){
              minv[j] = cur;
              way[j] = j0;
            }
            if(minv[j] < delta){
              delta = minv[j];
              j1 = j;
            }
          }
          for(let j = 0; j <= n; j++){
            if(used[j]){
              u[p[j]] += delta;
              v[j] -= delta;
            } else {
              minv[j] -= delta;
            }
          }
          j0 = j1;
        } while(p[j0] !== 0);
        do{
          const j1 = way[j0];
          p[j0] = p[j1];
          j0 = j1;
        } while(j0 !== 0);
      }
      const result = [];
      for(let j = 1; j <= n; j++){
        if(p[j] && p[j] - 1 < rows && j - 1 < cols){
          result.push({ row: p[j] - 1, col: j - 1, weight: weights[p[j] - 1][j - 1] });
        }
      }
      return result;
    }

    function longestIncreasingSubsequence(arr){
      const tails = [];
      const prev = Array(arr.length).fill(-1);
      const idxs = [];
      for(let i = 0; i < arr.length; i++){
        let l = 0, r = tails.length;
        while(l < r){
          const m = (l + r) >> 1;
          if(arr[tails[m]] < arr[i]) l = m + 1; else r = m;
        }
        if(l === tails.length){
          tails.push(i);
        } else {
          tails[l] = i;
        }
        prev[i] = l > 0 ? tails[l - 1] : -1;
        idxs[l] = i;
      }
      let k = tails.length ? tails[tails.length - 1] : -1;
      const result = [];
      while(k !== -1){
        result.push(k);
        k = prev[k];
      }
      return result.reverse();
    }

    function buildMovePlan(matches, lisSet, userTokens, originalTokens){
      const orderedMatches = [...matches].sort((a,b)=>a.userIndex - b.userIndex);
      const lisMatches = orderedMatches.filter(m=>lisSet.has(m.id));
      const lisOrigSorted = lisMatches.map(m=>m.origIndex).sort((a,b)=>a-b);
      const lisOrigToStudent = new Map();
      lisMatches.forEach(m=>{
        lisOrigToStudent.set(m.origIndex, m.userIndex);
      });

      const findNeighbors = (value)=>{
        let prev = -1;
        let next = null;
        for(const idx of lisOrigSorted){
          if(idx < value){
            prev = idx;
          } else if(idx > value){
            next = idx;
            break;
          }
        }
        return { prev, next };
      };

      const metaByUser = Array(userTokens.length).fill(null);
      const moveBlocks = [];
      const gapMeta = {};
      let current = null;

      orderedMatches.forEach((m)=>{
        const inLis = lisSet.has(m.id);
        const { prev, next } = findNeighbors(m.origIndex);
        const gapKey = `${prev}-${next === null ? 'END' : next}`;
        gapMeta[gapKey] = { before: prev, after: next };
        const hasError = m.userToken.raw !== m.origToken.raw;
        metaByUser[m.userIndex] = {
          match: m,
          inLis,
          targetGapKey: gapKey,
          targetGap: { before: prev, after: next },
          hasError,
          needsMove: !inLis
        };
        if(!inLis){
          const canJoin = current && current.targetGapKey === gapKey && m.userIndex === current.end + 1 && m.origIndex > current.lastOrigIndex;
          if(canJoin){
            current.tokens.push(m.userIndex);
            current.end = m.userIndex;
            current.lastOrigIndex = m.origIndex;
            current.hasError = current.hasError || hasError;
          } else {
            if(current){
              moveBlocks.push(current);
            }
            current = {
              id: `move-${moveBlocks.length + 1}`,
              tokens: [m.userIndex],
              start: m.userIndex,
              end: m.userIndex,
              lastOrigIndex: m.origIndex,
              targetGapKey: gapKey,
              targetGap: { before: prev, after: next },
              hasError,
              resolvedByRewrite: false
            };
          }
        } else if(current){
          moveBlocks.push(current);
          current = null;
        }
      });
      if(current){
        moveBlocks.push(current);
      }

      const gapGroups = {};
      moveBlocks.forEach(block=>{
        if(!gapGroups[block.targetGapKey]){
          gapGroups[block.targetGapKey] = [];
        }
        gapGroups[block.targetGapKey].push(block);
      });

      const rewriteGroups = [];
      Object.keys(gapGroups).forEach(key=>{
        const group = gapGroups[key];
        if(group.length <= 1){
          return;
        }
        const target = group[0].targetGap;
        const prevLisIndex = target.before !== -1 && lisOrigToStudent.has(target.before) ? lisOrigToStudent.get(target.before) : -1;
        const nextLisIndex = target.after !== null && lisOrigToStudent.has(target.after) ? lisOrigToStudent.get(target.after) : userTokens.length;
        const minBlockStart = Math.min(...group.map(g=>g.start));
        const maxBlockEnd = Math.max(...group.map(g=>g.end));
        const rewriteStart = Math.min(minBlockStart, prevLisIndex >= 0 ? prevLisIndex + 1 : minBlockStart);
        let rewriteEnd = Math.max(maxBlockEnd, nextLisIndex);
        if(rewriteEnd === userTokens.length - 1 && userTokens[rewriteEnd] && userTokens[rewriteEnd].type === 'punct'){
          rewriteEnd -= 1;
        }
        const origIndexesInRange = orderedMatches
          .filter(m=>m.userIndex >= rewriteStart && m.userIndex <= rewriteEnd)
          .map(m=>m.origIndex);
        const maxOrig = Math.max(...origIndexesInRange, target.after !== null ? target.after - 1 : originalTokens.length - 1);
        const correctTokens = originalTokens.filter(t=> t.index > target.before && t.index <= maxOrig);
        const correctText = correctTokens.map(t=>t.raw).join(' ');
        const rewriteId = `rewrite-${rewriteGroups.length + 1}`;
        rewriteGroups.push({
          id: rewriteId,
          start: rewriteStart,
          end: rewriteEnd,
          targetGapKey: key,
          targetGap: target,
          correctText
        });
        group.forEach(block=>{ block.resolvedByRewrite = true; });
        for(let i = rewriteStart; i <= rewriteEnd; i++){
          if(!metaByUser[i]){
            metaByUser[i] = {};
          }
          metaByUser[i].rewriteGroupId = rewriteId;
          metaByUser[i].targetGapKey = key;
          metaByUser[i].targetGap = target;
        }
      });

      moveBlocks.forEach(block=>{
        if(block.resolvedByRewrite) return;
        block.tokens.forEach(idx=>{
          if(!metaByUser[idx]) metaByUser[idx] = {};
          metaByUser[idx].moveBlockId = block.id;
          metaByUser[idx].targetGapKey = block.targetGapKey;
          metaByUser[idx].targetGap = block.targetGap;
        });
      });

      const gapsNeeded = new Set();
      moveBlocks.forEach(block=>{ gapsNeeded.add(block.targetGapKey); });
      rewriteGroups.forEach(group=> gapsNeeded.add(group.targetGapKey));

      return { moveBlocks, rewriteGroups, tokenMeta: metaByUser, gapMeta, gapsNeeded };
    }

    function buildMissingByPosition(missing, matches, userLength){
      const map = {};
      if(!missing || !missing.length){
        return map;
      }
      const matchedByOrig = new Map();
      matches.forEach(m=>{
        matchedByOrig.set(m.origIndex, m.userIndex);
      });
      const sortedMissing = [...missing].sort((a,b)=>a.origIndex - b.origIndex);
      sortedMissing.forEach(item=>{
        let prevOrig = -1;
        let prevUser = -1;
        let nextOrig = null;
        matchedByOrig.forEach((userIdx, origIdx)=>{
          if(origIdx < item.origIndex && origIdx > prevOrig){
            prevOrig = origIdx;
            prevUser = userIdx;
          }
          if(origIdx > item.origIndex){
            if(nextOrig === null || origIdx < nextOrig){
              nextOrig = origIdx;
            }
          }
        });
        const gapKey = `${prevOrig}-${nextOrig === null ? 'END' : nextOrig}`;
        const pos = Math.max(0, prevUser + 1);
        if(!map[pos]){
          map[pos] = [];
        }
        map[pos].push({ token: item.token, gapKey });
      });
      if(map[userLength]){
        map[userLength] = map[userLength].sort((a,b)=>a.token.index - b.token.index);
      }
      return map;
    }

    function renderComparisonResult(resultEl, comparison){
      resultEl.innerHTML = '';
      const wrapper = document.createElement('div');
      wrapper.className = 'dictation-comparison';

      if(comparison.isCorrect){
        const success = document.createElement('div');
        success.className = 'dictation-success-msg';
        success.textContent = `? ${aiStrings.dictationPerfect || 'Отлично! Всё правильно!'}`;
        wrapper.appendChild(success);
        resultEl.appendChild(wrapper);
        return;
      }

      const errorCount = comparison.errorCount || 0;
      const errorWord = errorCount === 1 ? (aiStrings.dictationError || 'ошибка') :
                        errorCount < 5 ? (aiStrings.dictationErrors2 || 'ошибки') :
                        (aiStrings.dictationErrors5 || 'ошибок');
      const summary = document.createElement('div');
      summary.className = 'dictation-errors-summary';
      summary.textContent = `?? ${errorCount} ${errorWord}`;
      wrapper.appendChild(summary);

      const visual = document.createElement('div');
      visual.className = 'dictation-visual';

      const userRow = buildUserLine(comparison);
      const correctRow = buildCorrectLine(comparison);
      visual.appendChild(userRow);
      visual.appendChild(correctRow);

      wrapper.appendChild(visual);
      resultEl.appendChild(wrapper);

      requestAnimationFrame(()=> drawDictationArrows(visual));
    }

    function buildUserLine(comparison){
      const row = document.createElement('div');
      row.className = 'dictation-comparison-row';
      const label = document.createElement('div');
      label.className = 'dictation-comparison-label';
      label.textContent = aiStrings.dictationYourAnswer || 'Ваш ответ:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-user';

      const missingByPos = comparison.movePlan.missingByPosition || {};
      const meta = comparison.movePlan.tokenMeta || [];
      let idx = 0;
      while(idx < comparison.userTokens.length){
        if(missingByPos[idx]){
          missingByPos[idx].forEach(miss=>{
            const missSpan = document.createElement('span');
            missSpan.className = 'dictation-missing-token';
            missSpan.dataset.gapAnchor = miss.gapKey;
            const caret = document.createElement('span');
            caret.className = 'dictation-missing-caret';
            caret.textContent = '?';
            const word = document.createElement('span');
            word.className = 'dictation-missing-word';
            word.textContent = miss.token.raw;
            missSpan.appendChild(word);
            missSpan.appendChild(caret);
            line.appendChild(missSpan);
          });
        }
        const token = comparison.userTokens[idx];
        const metaInfo = meta[idx] || {};
        if(metaInfo.rewriteGroupId){
          const group = comparison.movePlan.rewriteGroups.find(r=>r.id === metaInfo.rewriteGroupId);
          const start = group ? group.start : idx;
          const end = group ? group.end : idx;
          const segmentTokens = comparison.userTokens.slice(start, end + 1).map(t=>t.raw).join(' ');
          const rewriteEl = document.createElement('span');
          rewriteEl.className = 'dictation-rewrite-block';
          if(group){
            rewriteEl.dataset.targetGap = group.targetGapKey;
          }
          const corrected = document.createElement('span');
          corrected.className = 'dictation-rewrite-correct';
          corrected.textContent = group ? group.correctText : '';
          const original = document.createElement('span');
          original.className = 'dictation-rewrite-original';
          original.textContent = segmentTokens;
          rewriteEl.appendChild(corrected);
          rewriteEl.appendChild(original);
          line.appendChild(rewriteEl);
          idx = end + 1;
          continue;
        }
        if(metaInfo.moveBlockId){
          const block = comparison.movePlan.moveBlocks.find(b=>b.id === metaInfo.moveBlockId);
          const blockEl = document.createElement('span');
          blockEl.className = 'dictation-move-block';
          blockEl.dataset.targetGap = metaInfo.targetGapKey;
          block.tokens.forEach(tIdx=>{
            const tMeta = meta[tIdx] || {};
            const t = comparison.userTokens[tIdx];
            const span = createTokenSpan(t, tMeta);
            span.classList.add('dictation-token-move');
            blockEl.appendChild(span);
          });
          line.appendChild(blockEl);
          idx = block.end + 1;
          continue;
        }
        const span = createTokenSpan(token, metaInfo);
        line.appendChild(span);
        idx++;
      }
      if(missingByPos[comparison.userTokens.length]){
        missingByPos[comparison.userTokens.length].forEach(miss=>{
          const missSpan = document.createElement('span');
          missSpan.className = 'dictation-missing-token';
          missSpan.dataset.gapAnchor = miss.gapKey;
          const caret = document.createElement('span');
          caret.className = 'dictation-missing-caret';
          caret.textContent = '?';
          const word = document.createElement('span');
          word.className = 'dictation-missing-word';
          word.textContent = miss.token.raw;
          missSpan.appendChild(word);
          missSpan.appendChild(caret);
          line.appendChild(missSpan);
        });
      }
      row.appendChild(label);
      row.appendChild(line);
      return row;
    }

    function buildCorrectLine(comparison){
      const row = document.createElement('div');
      row.className = 'dictation-comparison-row';
      const label = document.createElement('div');
      label.className = 'dictation-comparison-label';
      label.textContent = aiStrings.dictationShouldBe || 'Должно быть:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-correct';

      const gapsNeeded = comparison.movePlan.gapsNeeded || new Set();
      const gapMeta = comparison.movePlan.gapMeta || {};
      const gapsByBefore = new Map();
      gapsNeeded.forEach(key=>{
        const meta = gapMeta[key];
        if(!meta) return;
        const before = meta.before === undefined ? -1 : meta.before;
        if(!gapsByBefore.has(before)){
          gapsByBefore.set(before, []);
        }
        gapsByBefore.get(before).push(key);
      });

      const insertAnchors = (beforeIdx)=>{
        const list = gapsByBefore.get(beforeIdx) || [];
        list.forEach(key=>{
          const anchor = document.createElement('span');
          anchor.className = 'dictation-gap-anchor';
          anchor.dataset.gapAnchor = key;
          const mark = document.createElement('span');
          mark.className = 'dictation-gap-mark';
          anchor.appendChild(mark);
          line.appendChild(anchor);
        });
      };

      insertAnchors(-1);
      comparison.originalTokens.forEach((token, idx)=>{
        const span = document.createElement('span');
        span.className = 'dictation-token dictation-token-ok';
        span.textContent = token.raw;
        line.appendChild(span);
        insertAnchors(idx);
      });

      row.appendChild(label);
      row.appendChild(line);
      return row;
    }

    function createTokenSpan(token, meta){
      const span = document.createElement('span');
      span.className = 'dictation-token';
      span.textContent = token.raw;
      if(meta && meta.hasError){
        span.classList.add('dictation-token-error');
        const fix = document.createElement('span');
        fix.className = 'dictation-token-correction';
        fix.textContent = meta.match ? meta.match.origToken.raw : '';
        span.appendChild(fix);
      }
      if(meta && meta.match){
        if(meta.inLis){
          span.classList.add(meta.hasError ? 'dictation-token-warn' : 'dictation-token-ok');
        }
      }
      if(meta && meta.needsMove){
        span.classList.add('dictation-token-move');
      }
      if(!meta || (!meta.match && !meta.hasError && !meta.moveBlockId && !meta.rewriteGroupId)){
        span.classList.add('dictation-token-extra');
      }
      return span;
    }

    function drawDictationArrows(container){
      const svgNS = 'http://www.w3.org/2000/svg';
      const old = container.querySelector('.dictation-arrow-layer');
      if(old){
        old.remove();
      }
      const blocks = container.querySelectorAll('[data-target-gap]');
      const anchors = container.querySelectorAll('[data-gap-anchor]');
      if(!blocks.length || !anchors.length){
        return;
      }
      const svg = document.createElementNS(svgNS, 'svg');
      svg.classList.add('dictation-arrow-layer');
      svg.setAttribute('width', container.offsetWidth);
      svg.setAttribute('height', container.offsetHeight);
      svg.setAttribute('viewBox', `0 0 ${container.offsetWidth} ${container.offsetHeight}`);
      const defs = document.createElementNS(svgNS, 'defs');
      const marker = document.createElementNS(svgNS, 'marker');
      marker.setAttribute('id', 'dictation-arrowhead');
      marker.setAttribute('markerWidth', '10');
      marker.setAttribute('markerHeight', '7');
      marker.setAttribute('refX', '10');
      marker.setAttribute('refY', '3.5');
      marker.setAttribute('orient', 'auto');
      const path = document.createElementNS(svgNS, 'path');
      path.setAttribute('d', 'M0,0 L10,3.5 L0,7 Z');
      path.setAttribute('fill', '#60a5fa');
      marker.appendChild(path);
      defs.appendChild(marker);
      svg.appendChild(defs);

      const containerRect = container.getBoundingClientRect();
      blocks.forEach(block=>{
        const gapKey = block.dataset.targetGap;
        const target = container.querySelector(`[data-gap-anchor="${gapKey}"]`);
        if(!target){
          return;
        }
        const blockRect = block.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const startX = blockRect.left + (blockRect.width / 2) - containerRect.left;
        const startY = blockRect.top - containerRect.top;
        const endX = targetRect.left + (targetRect.width / 2) - containerRect.left;
        const endY = targetRect.top - containerRect.top + targetRect.height * 0.2;
        const controlY = Math.min(startY, endY) - 28;
        const p = document.createElementNS(svgNS, 'path');
        p.setAttribute('d', `M ${startX} ${startY} C ${startX} ${controlY}, ${endX} ${controlY}, ${endX} ${endY}`);
        p.setAttribute('fill', 'none');
        p.setAttribute('stroke', '#60a5fa');
        p.setAttribute('stroke-width', '2');
        p.setAttribute('marker-end', 'url(#dictation-arrowhead)');
        svg.appendChild(p);
      });
      container.appendChild(svg);
    }

    function escapeHtml(str){
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }


    async function buildSlot(kind, card){
      const el=document.createElement("div");
      el.className="slot";
      const cardOrder = Array.isArray(card.order) ? card.order : [];
      if(kind==="transcription" && card.transcription){
        el.innerHTML=`<div class="transcription-text">${card.transcription}</div>`;
        el.dataset.slotType = 'transcription';
        return el;
      }
      if(kind==="text" && card.text){
        const showInlineTranscription = card.transcription && !cardOrder.includes('transcription');
        const tr = showInlineTranscription ? `<div class="transcription-text">${card.transcription}</div>` : "";

        // DICTATION EXERCISE: Create interactive text input exercise
        const dictationId = 'dictation-' + Math.random().toString(36).substr(2, 9);

        el.innerHTML=`
          <div class="dictation-exercise" data-dictation-id="${dictationId}">
            <div class="dictation-input-wrapper">
              <textarea
                class="dictation-input"
                placeholder="${aiStrings.dictationPlaceholder || 'Type what you hear...'}"
                rows="3"
                data-correct-text="${card.text.replace(/"/g, '&quot;')}"
              ></textarea>
            </div>
            <div class="dictation-controls">
              <button type="button" class="dictation-check-btn primary">
                ${aiStrings.dictationCheck || 'Проверить'}
              </button>
              <button type="button" class="dictation-replay-btn" title="${aiStrings.dictationReplay || 'Прослушать снова'}">
                🔊 0.85x
              </button>
            </div>
            <div class="dictation-result hidden"></div>
            <div class="dictation-correct-answer hidden">
              <div class="correct-answer-label">${aiStrings.dictationCorrectAnswer || 'Правильный ответ:'}</div>
              <div class="correct-answer-text">${card.text}</div>
            </div>
          </div>
          ${tr}
        `;

        // Setup dictation exercise behavior
        setTimeout(() => {
          setupDictationExercise(el, card, dictationId);
        }, 100);

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
        const frontUrl = await resolveAudioUrl(card, 'front');
        const includeFocus = !cardOrder.includes('audio_text');
        let focusUrl = null;
        if(includeFocus){
          focusUrl = await resolveAudioUrl(card, 'focus');
        }
        const tracks=[];
        if(frontUrl){
          tracks.push({url:frontUrl,type:'front'});
        }
        if(includeFocus && focusUrl){
          tracks.push({url:focusUrl,type:'focus'});
        }
        if(!tracks.length){
          return null;
        }
        renderAudioButtons(el, tracks, {attachFront:true});
        return el;
      }
      if(kind==="audio_text"){
        const focusUrl = await resolveAudioUrl(card, 'focus');
        if(!focusUrl){
          return null;
        }
        renderAudioButtons(el, [{url:focusUrl,type:'focus'}]);
        return el;
      }
      return null;
    }
    function initialVisibleSlots(card){
      return 1;
    }
    async function renderCard(card, count, prevCount=0){
      slotContainer.innerHTML="";
      hidePlayIcons();
      audioURL=null;
      const allSlots=[];
      for(const kind of card.order){
        const el=await buildSlot(kind,card);
        if(el) allSlots.push(el);
      }
      const items=allSlots.slice(0,count);
      if(!items.length){
        const d=document.createElement("div");
        d.className="front";
        d.textContent="-";
        items.push(d);
      }
      items.forEach(x=>slotContainer.appendChild(x));
      // Autoplay audio: first slot when opening card, or newly revealed slot when clicking Show More
      let autoplayUrl = null;
      let autoplayRate = 1;
      if(count===1 && items[0] && items[0].dataset && items[0].dataset.autoplay){
        // First opening - play first slot's audio
        autoplayUrl = items[0].dataset.autoplay;
      } else if(prevCount > 0 && count > prevCount){
        // Show More clicked
        const newSlot = items[count - 1];
        // Check if new slot is transcription - play previous slot's audio at 0.7 speed
        if(newSlot && newSlot.dataset && newSlot.dataset.slotType === 'transcription'){
          // Find previous slot's audio
          const prevSlot = items[count - 2];
          if(prevSlot && prevSlot.dataset && prevSlot.dataset.autoplay){
            autoplayUrl = prevSlot.dataset.autoplay;
            autoplayRate = 0.7;
          }
        } else if(newSlot && newSlot.dataset && newSlot.dataset.autoplay){
          // Play newly revealed slot's audio if it has one
          autoplayUrl = newSlot.dataset.autoplay;
        }
      }
      if(autoplayUrl){
        player.src=autoplayUrl;
        player.playbackRate=autoplayRate;
        player.currentTime=0;
        player.play().catch(()=>{});
      }
      card._availableSlots=allSlots.length;

      // Show inline field prompt when all slots are revealed
      const allRevealed = count >= allSlots.length;
      if(allRevealed && currentItem){
        showInlineFieldPrompt();
      } else {
        hideInlineFieldPrompt();
      }
    }

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
        setCreatedMeta(null);
        emptyState.classList.remove("hidden");
        const br=$("#btnRevealNext"); if(br){ br.disabled=true; br.classList.remove("primary"); }
        setIconVisibility(false); hidePlayIcons();
        updateRatingActionHints(null);
        return;
      }
        emptyState.classList.add("hidden");
      current = 0;
      currentItem = queue[current];
      visibleSlots = initialVisibleSlots(currentItem?.card);
      showCurrent();
    }
    // Ensure the Study queue highlights the requested card (even if it needs a temporary entry).
    function focusQueueCard(deckId, cardId){
      if(!deckId || !cardId) return false;
      const idx = queue.findIndex(item => item.deckId === deckId && item.card && item.card.id === cardId);
      if(idx >= 0){
        current = idx;
        currentItem = queue[current];
        visibleSlots = initialVisibleSlots(currentItem.card);
        return true;
      }
      const deck = registry[deckId];
      if(!deck) return false;
      const card = deck.cards.find(c => c.id === cardId);
      if(!card) return false;
      ensureDeckProgress(deckId, deck.cards);
      let recRecord = state.decks?.[deckId]?.[cardId];
      if(!recRecord){
        recRecord = {step:0,due:today0(),addedAt:today0(),lastAt:null};
        state.decks = state.decks || {};
        state.decks[deckId] = state.decks[deckId] || {};
        state.decks[deckId][cardId] = recRecord;
      }
      const normalizedCard = normalizeLessonCard({...card});
      const entry = {deckId, card:normalizedCard, rec:recRecord, index:0};
      queue.unshift(entry);
      current = 0;
      currentItem = entry;
      visibleSlots = initialVisibleSlots(currentItem.card);
      setDue(queue.length);
      return true;
    }
    function updateRevealButton(){ const more = currentItem && currentItem.card._availableSlots && visibleSlots < currentItem.card._availableSlots; const br=$("#btnRevealNext"); if(br){ br.disabled=!more; br.classList.toggle("primary",!!more); } }
    async function showCurrent(forceRender=false, prevSlots=0){
      if(!currentItem){
        pendingStudyRender=false;
        updateRevealButton();
        setIconVisibility(false);
        hidePlayIcons();
        setCreatedMeta(null);
        updateRatingActionHints(null);
        return;
      }
      if(!forceRender && activeTab!=='study'){
        pendingStudyRender=true;
        return;
      }
      pendingStudyRender=false;
      setStage(currentItem.rec.step||0);
      setCreatedMeta(currentItem.rec?.addedAt || 0);
      await renderCard(currentItem.card, visibleSlots, prevSlots);
      setIconVisibility(true);
      updateRevealButton();
      updateRatingActionHints(currentItem.rec || null);
      // Note: maybePromptForStage() replaced by inline prompt in renderCard
    }

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
        visibleSlots = initialVisibleSlots(currentItem?.card);
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
        visibleSlots = initialVisibleSlots(currentItem?.card);
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
        visibleSlots = initialVisibleSlots(currentItem?.card);
        showCurrent();
      setDue(queue.length);
    }

    $("#btnRevealNext").addEventListener("click",()=>{ if(!currentItem)return; const prevSlots=visibleSlots; visibleSlots=Math.min(currentItem.card.order.length, visibleSlots+1); showCurrent(false, prevSlots); });

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
         setTranslationPreview(previewText ? 'success' : '', previewText ? null : aiStrings.translationIdle);
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
      // Re-initialize autogrow for textareas after populating form
      setTimeout(() => { initAutogrow(); initTextareaClearButtons(); }, 50);
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
    const imageInput = $("#uImage");
    if(imageInput){
      imageInput.addEventListener("change", async e=>{
        const f=e.target.files?.[0];
        if(!f) return;
        openCropper(f);
        e.target.value = '';
      });
    }
    $("#uAudio").addEventListener("change", async e=>{
      const f=e.target.files?.[0];
      if(!f) return;
      privateAudioOnly=false;
      lastAudioDurationSec=0;
      resetAudioPreview();
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
        privateAudioOnly = true;
        const approxDuration = Math.max(1, Math.round((Date.now() - (t0 || Date.now())) / 1000));
        if(Number.isFinite(approxDuration)){
          lastAudioDurationSec = approxDuration;
        }
        if(stored){
          recorderLog('handleRecordedBlob stored via IDB');
        } else {
          recorderLog('handleRecordedBlob using in-memory blob fallback (size '+blob.size+')');
        }
        if(sttEnabled){
          triggerTranscription(blob);
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
              markIOSMicPermissionRequesting();
              await iosRecorderInstance.start();
              setIOSMicPermissionState('granted');
              isRecording = true;
              recBtn.classList.add("recording");
              timerStart();
              setHintActive();
              autoStopTimer = setTimeout(()=>{ if(isRecording){ stopRecording().catch(()=>{}); } }, 120000);
            }catch(err){
              isRecording = false;
              recorderLog('iOS start failed: '+(err?.message||err));
              handleIOSMicPermissionError(err);
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
        const iosPermissionPending = IS_IOS && iosMicPermissionState !== 'granted';
        if(iosPermissionPending){
          markIOSMicPermissionRequesting();
          requestIOSMicPermission().catch(()=>{});
        }else{
          // Short delay (150ms) before starting recording to prevent accidental taps
          holdTimeout = setTimeout(() => {
            const startPromise = startRecording();
            if(startPromise && typeof startPromise.then === 'function'){
              startPromise.catch(err=>{ recorderLog('start promise rejected: '+(err?.message||err)); });
            }
          }, 150);
        }
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

    // ========== STUDY TAB PRONUNCIATION PRACTICE ==========
    let pronunciationPracticeInitialized = false;
    function initStudyPronunciationPractice(){
      if(pronunciationPracticeInitialized){
        console.log('[PronunciationPractice] Already initialized, skipping');
        return;
      }

      console.log('[PronunciationPractice] Starting initialization...');
      const btnRecordStudy = $("#btnRecordStudy");
      const timerEl = $("#recTimerStudy");
      console.log('[PronunciationPractice] btnRecordStudy:', btnRecordStudy);
      console.log('[PronunciationPractice] timerEl:', timerEl);
      if(!btnRecordStudy || !timerEl){
        console.log('[PronunciationPractice] ERROR: Required elements not found! btnRecordStudy='+(!!btnRecordStudy)+', timerEl='+(!!timerEl));
        return;
      }

      pronunciationPracticeInitialized = true;

      let isRecording = false;
      let studyRecorder = null;
      let studyRecorderChunks = [];
      let studyMicStream = null;
      let studyTimerInterval = null;
      let studyTimerStart = 0;
      let currentCardAudioUrl = null;
      let capturedCardAudioUrl = null; // Snapshot of audio URL at recording start (prevents race condition)
      let studentRecordingBlob = null;
      let autoStopTimer = null;
      let holdActive = false;
      let holdTimeout = null;
      let activePointerToken = null;
      let studentAudioPlayer = null;
      let playbackLoopActive = false;
      let studentAudioUrl = null;
      let studyAudioContext = null;
      let studyBufferSource = null;

      // Reuse iOS recorder if available
      const useIOSRecorder = !!(IS_IOS && iosRecorderGlobal && iosRecorderGlobal.supported());
      const iosRecorderInstance = useIOSRecorder ? iosRecorderGlobal : null;
      let pendingIOSRelease = null;

      function releaseIOSMic(){
        if(!iosRecorderInstance){
          return Promise.resolve();
        }
        if(!pendingIOSRelease){
          pendingIOSRelease = iosRecorderInstance.releaseMic()
            .catch(err=>{
              console.log('[PronunciationPractice] releaseMic failed: '+(err?.message||err));
            })
            .finally(()=>{
              pendingIOSRelease = null;
            });
        }
        return pendingIOSRelease;
      }

      async function waitForIOSRelease(){
        if(pendingIOSRelease){
          try{
            await pendingIOSRelease;
          }catch(_e){}
        }
      }

      // Timer functions
      function formatTime(ms){
        const t = Math.max(0, Math.floor(ms/1000));
        const m = ('0'+Math.floor(t/60)).slice(-2);
        const s = ('0'+(t%60)).slice(-2);
        return m+':'+s;
      }

      function startTimer(){
        timerEl.classList.remove('hidden');
        studyTimerStart = Date.now();
        timerEl.textContent = '00:00';
        if(studyTimerInterval) clearInterval(studyTimerInterval);
        studyTimerInterval = setInterval(()=>{
          try{ timerEl.textContent = formatTime(Date.now() - studyTimerStart); }catch(_e){}
        }, 250);
      }

      function stopTimer(){
        if(studyTimerInterval) clearInterval(studyTimerInterval);
        studyTimerInterval = null;
        timerEl.classList.add('hidden');
      }

      // Mic stream management
      function stopStudyMicStream(){
        try{
          if(studyMicStream){
            studyMicStream.getTracks().forEach(t=>{ try{t.stop();}catch(_e){} });
            studyMicStream = null;
          }
        }catch(_e){}
      }

      // Best MIME type selection (same as Quick Input)
      function bestMime(){
        try{
          if(!(window.MediaRecorder && MediaRecorder.isTypeSupported)) return '';
          const cand = ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/ogg','audio/mp4;codecs=mp4a.40.2','audio/mp4'];
          for(let i=0; i<cand.length; i++){
            if(MediaRecorder.isTypeSupported(cand[i])) return cand[i];
          }
        }catch(_e){}
        return '';
      }

      // Normalize audio MIME type
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

      // Stop playback loop
      function stopPlaybackLoop(){
        playbackLoopActive = false;
        try{ player.pause(); player.currentTime = 0; }catch(_e){}
        try{
          if(studentAudioPlayer){
            studentAudioPlayer.pause();
            studentAudioPlayer.currentTime = 0;
          }
        }catch(_e){}
        stopStudyBufferSource();
        if(studentAudioUrl){
          try{ URL.revokeObjectURL(studentAudioUrl); }catch(_e){}
          studentAudioUrl = null;
        }
      }

      function stopStudyBufferSource(){
        if(!studyBufferSource) return;
        try{ studyBufferSource.onended = null; }catch(_e){}
        try{ studyBufferSource.stop(); }catch(_e){}
        try{ studyBufferSource.disconnect(); }catch(_e){}
        studyBufferSource = null;
      }

      async function ensureStudyAudioContextUnlocked(){
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if(!AudioCtx){
          return null;
        }
        if(!studyAudioContext){
          try{
            studyAudioContext = new AudioCtx();
          }catch(_e){
            studyAudioContext = null;
            return null;
          }
        }
        if(studyAudioContext && studyAudioContext.state !== 'running'){
          try{ await studyAudioContext.resume(); }catch(_e){}
        }
        return studyAudioContext;
      }

      async function decodeStudyAudioBuffer(blob){
        if(!blob){
          return null;
        }
        const ctx = await ensureStudyAudioContextUnlocked();
        if(!ctx){
          return null;
        }
        let arrayBuffer;
        try{
          arrayBuffer = await blob.arrayBuffer();
        }catch(_e){
          return null;
        }
        return new Promise((resolve, reject) => {
          let done = false;
          const doneResolve = value => { if(done) return; done = true; resolve(value); };
          const doneReject = err => { if(done) return; done = true; reject(err); };
          try{
            const maybePromise = ctx.decodeAudioData(arrayBuffer, doneResolve, doneReject);
            if(maybePromise && typeof maybePromise.then === 'function'){
              maybePromise.then(doneResolve, doneReject);
            }
          }catch(err){
            doneReject(err);
          }
        });
      }

      // Playback chain: student → original → STOP (one-time playback)
      async function playbackChain(){
        console.log('[PronunciationPractice] playbackChain called');
        console.log('[PronunciationPractice] studentRecordingBlob:', studentRecordingBlob);

        if(!studentRecordingBlob){
          console.log('[PronunciationPractice] ERROR: Missing data! Cannot start playback');
          return;
        }

        // Stop any current playback FIRST
        stopPlaybackLoop();

        // THEN activate new loop
        playbackLoopActive = true;

        console.log('[PronunciationPractice] Starting one-time playback: student only');

        if(IS_IOS){
          try{
            const decodedBuffer = await decodeStudyAudioBuffer(studentRecordingBlob);
            if(decodedBuffer){
              console.log('[PronunciationPractice] Starting audio context playback (iOS)');
              stopStudyBufferSource();
              if(studyAudioContext){
                studyBufferSource = studyAudioContext.createBufferSource();
                studyBufferSource.buffer = decodedBuffer;
                studyBufferSource.onended = () => {
                  console.log('[PronunciationPractice] Student finished (buffer), stopping playback');
                  playbackLoopActive = false;
                  stopStudyBufferSource();
                  studentRecordingBlob = null;
                };
                studyBufferSource.connect(studyAudioContext.destination);
                try{
                  studyBufferSource.start(0);
                }catch(err){
                  console.log('[PronunciationPractice] BufferSource start failed: '+(err?.message||err));
                  playbackLoopActive = false;
                  stopStudyBufferSource();
                  studentRecordingBlob = null;
                }
                return;
              }
            }
          }catch(err){
            console.log('[PronunciationPractice] iOS AudioContext playback failed: '+(err?.message||err));
          }
        }

        // Clean up previous student player if needed
        if(studentAudioPlayer){
          try{
            studentAudioPlayer.pause();
            if(studentAudioPlayer.src && studentAudioPlayer.src.startsWith('blob:')){
              URL.revokeObjectURL(studentAudioPlayer.src);
            }
          }catch(_e){}
        }

        const studentUrl = URL.createObjectURL(studentRecordingBlob);
        studentAudioUrl = studentUrl;
        studentAudioPlayer = new Audio(studentUrl);

        studentAudioPlayer.onended = () => {
          console.log('[PronunciationPractice] Student finished, stopping playback');
          playbackLoopActive = false;
          if(studentAudioUrl){
            try{ URL.revokeObjectURL(studentAudioUrl); }catch(_e){}
            studentAudioUrl = null;
          }
          studentRecordingBlob = null;
        };

        studentAudioPlayer.play().catch(err=>{
          console.log('[PronunciationPractice] Student playback failed: '+(err?.message||err));
          playbackLoopActive = false;
          if(studentAudioUrl){
            try{ URL.revokeObjectURL(studentAudioUrl); }catch(_e){}
            studentAudioUrl = null;
          }
          studentRecordingBlob = null;
        });
      }

      // Handle recorded blob
      async function handleStudentRecording(blob){
        if(!blob || blob.size <= 0){
          console.log('[PronunciationPractice] Empty blob received');
          return;
        }

        console.log('[PronunciationPractice] Recording complete: ' + blob.size + ' bytes');
        studentRecordingBlob = blob;

        // Automatically start playback chain
        await playbackChain();
      }

      // Start recording
      async function startStudyRecording(){
        if(isRecording) return;

        if(autoStopTimer){ clearTimeout(autoStopTimer); autoStopTimer=null; }

        // Stop any active playback loop
        stopPlaybackLoop();

        // Update captured URL if currentCardAudioUrl is available
        // (Don't overwrite with null if it's already set from setStudyRecorderAudio)
        if(currentCardAudioUrl){
          capturedCardAudioUrl = currentCardAudioUrl;
        }
        console.log('[PronunciationPractice] Starting recording, capturedCardAudioUrl:', capturedCardAudioUrl);

        // iOS recorder path
        if(useIOSRecorder){
          try{
            await waitForIOSRelease();
            console.log('[PronunciationPractice] Starting iOS recorder');
            markIOSMicPermissionRequesting();
            await iosRecorderInstance.start();
            setIOSMicPermissionState('granted');
            isRecording = true;
            btnRecordStudy.classList.add("recording");
            startTimer();
            autoStopTimer = setTimeout(()=>{ if(isRecording){ stopStudyRecording().catch(()=>{}); } }, 30000); // 30s max
          }catch(err){
            console.log('[PronunciationPractice] iOS start failed: '+(err?.message||err));
            handleIOSMicPermissionError(err);
            isRecording = false;
            try{ await iosRecorderInstance.releaseMic(); }catch(_e){}
          }
          return;
        }

        // Standard MediaRecorder path
        try{
          if(!window.MediaRecorder){
            console.log('[PronunciationPractice] MediaRecorder not supported');
            return;
          }

          stopStudyMicStream();
          const stream = await navigator.mediaDevices.getUserMedia({audio:true, video:false});
          studyMicStream = stream;
          studyRecorderChunks = [];

          let mime = bestMime();
          try{
            studyRecorder = mime ? new MediaRecorder(stream, {mimeType:mime}) : new MediaRecorder(stream);
          }catch(_er){
            try{
              studyRecorder = new MediaRecorder(stream);
              mime = studyRecorder.mimeType || mime || '';
            }catch(_er2){
              stopStudyMicStream();
              console.log('[PronunciationPractice] MediaRecorder creation failed');
              return;
            }
          }

          if(studyRecorder.mimeType) mime = studyRecorder.mimeType;

          studyRecorder.ondataavailable = e => {
            console.log('[PronunciationPractice] ondataavailable: '+(e.data?.size||0)+' bytes');
            if(e.data && e.data.size>0){
              studyRecorderChunks.push(e.data);
              console.log('[PronunciationPractice] Total chunks now: '+studyRecorderChunks.length);
            }
          };

          studyRecorder.onstop = async () => {
            console.log('[PronunciationPractice] onstop triggered, chunks: '+studyRecorderChunks.length);
            try{
              if(studyRecorderChunks.length === 0){
                console.log('[PronunciationPractice] ERROR: No chunks recorded!');
                stopStudyMicStream();
                studyRecorder = null;
                isRecording = false;
                return;
              }
              const detectedType = (studyRecorderChunks[0]?.type) || mime || '';
              const finalType = normalizeAudioMime(detectedType);
              const blob = new Blob(studyRecorderChunks, {type: finalType || undefined});
              console.log('[PronunciationPractice] Created blob: '+blob.size+' bytes, type: '+blob.type);

              if(blob.size === 0){
                console.log('[PronunciationPractice] ERROR: Blob is empty!');
                stopStudyMicStream();
                studyRecorder = null;
                isRecording = false;
                return;
              }

              await handleStudentRecording(blob);
            }catch(err){
              console.log('[PronunciationPractice] onstop error: '+(err?.message||err));
            }
            stopStudyMicStream();
            studyRecorder = null;
            isRecording = false;
          };

          try{ studyRecorder.start(1000); }catch(_e){ studyRecorder.start(); }

          isRecording = true;
          btnRecordStudy.classList.add("recording");
          startTimer();
          autoStopTimer = setTimeout(()=>{
            if(studyRecorder && isRecording){
              try{ studyRecorder.stop(); }catch(_e){}
            }
          }, 30000); // 30s max

        }catch(err){
          console.log('[PronunciationPractice] Start failed: '+(err?.message||err));
          isRecording = false;
          studyRecorder = null;
          stopStudyMicStream();
        }
      }

      // Stop recording
      async function stopStudyRecording(){
        if(autoStopTimer){ clearTimeout(autoStopTimer); autoStopTimer=null; }

        // iOS recorder path
        if(useIOSRecorder){
          if(!isRecording){
            await releaseIOSMic();
            btnRecordStudy.classList.remove("recording");
            stopTimer();
            return;
          }

          let exportedBlob = null;
          try{
            exportedBlob = await iosRecorderInstance.exportWav();
            console.log('[PronunciationPractice] iOS export: '+(exportedBlob?.size||0)+' bytes');
          }catch(err){
            console.log('[PronunciationPractice] iOS stop failed: '+(err?.message||err));
          }

          await releaseIOSMic();
          isRecording = false;
          btnRecordStudy.classList.remove("recording");
          stopTimer();

          if(exportedBlob){
            await handleStudentRecording(exportedBlob);
          }
          return;
        }

        // Standard MediaRecorder path
        try{
          if(studyRecorder && studyRecorder.state !== 'inactive'){
            // Request any remaining data before stopping
            console.log('[PronunciationPractice] Requesting final data, current chunks: '+studyRecorderChunks.length);
            try{ studyRecorder.requestData(); }catch(_e){}
            // Small delay to allow requestData to complete
            await new Promise(resolve => setTimeout(resolve, 100));
            studyRecorder.stop();
          }
        }catch(_e){
          console.log('[PronunciationPractice] Stop error: '+(_e?.message||_e));
        }
        isRecording = false;
        btnRecordStudy.classList.remove("recording");
        stopTimer();
      }

      // Hold-to-record interaction (same as Quick Input)
      function onDown(e){
        if(e.type === 'mousedown' && e.button !== 0) return;
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(holdActive) return;

        holdActive = true;
        activePointerToken = (e.pointerId !== undefined) ? e.pointerId : (e.type.indexOf('touch') === 0 ? 'touch' : 'mouse');
        try{ e.preventDefault(); }catch(_e){}
        ensureStudyAudioContextUnlocked().catch(()=>{});
        const iosPermissionPending = IS_IOS && iosMicPermissionState !== 'granted';
        if(iosPermissionPending){
          markIOSMicPermissionRequesting();
          requestIOSMicPermission().catch(()=>{});
        }else{
          // Short delay (150ms) before recording starts to avoid accidental taps
          holdTimeout = setTimeout(() => {
            const startPromise = startStudyRecording();
            if(startPromise && typeof startPromise.then === 'function'){
              startPromise.catch(err=>{ console.log('[PronunciationPractice] Start promise rejected: '+(err?.message||err)); });
            }
          }, 150);
        }

        function onceUp(ev){
          if(activePointerToken !== null){
            if(typeof activePointerToken === 'number'){
              if(ev && ev.pointerId !== undefined && ev.pointerId !== activePointerToken) return;
            }
          }

          window.removeEventListener("pointerup", onceUp);
          window.removeEventListener("pointercancel", onceUp);
          window.removeEventListener("mouseup", onceUp);
          window.removeEventListener("touchend", onceUp);
          window.removeEventListener("touchcancel", onceUp);

          holdActive = false;
          activePointerToken = null;

          if(holdTimeout){
            clearTimeout(holdTimeout);
            holdTimeout = null;
          }

          const stopPromise = stopStudyRecording();
          if(stopPromise && typeof stopPromise.then === 'function'){
            stopPromise.catch(err=>{ console.log('[PronunciationPractice] Stop promise rejected: '+(err?.message||err)); });
          }
        }

        window.addEventListener("pointerup", onceUp);
        window.addEventListener("pointercancel", onceUp);
        window.addEventListener("mouseup", onceUp);
        window.addEventListener("touchend", onceUp);
        window.addEventListener("touchcancel", onceUp);
      }

      if(btnRecordStudy){
        btnRecordStudy.disabled = false;
        btnRecordStudy.addEventListener("pointerdown", onDown);
        btnRecordStudy.addEventListener("mousedown", onDown);
        btnRecordStudy.addEventListener("touchstart", onDown, {passive:false});
        try{
          btnRecordStudy.addEventListener("click", function(e){
            try{e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();}catch(_e){}
          }, true);
        }catch(_e){}
      }

      // Public function to set current audio URL
      window.setStudyRecorderAudio = function(url){
        console.log('[PronunciationPractice] setStudyRecorderAudio called with:', url);
        currentCardAudioUrl = url;
        capturedCardAudioUrl = url; // Also update captured URL so it's ready for recording
        studentRecordingBlob = null; // Reset student recording when card changes
        stopPlaybackLoop(); // Stop any active playback
      };

      // If a card audio URL was attached before the recorder initialized,
      // reuse it immediately so the first recording has something to play back.
      if(!currentCardAudioUrl && audioURL){
        console.log('[PronunciationPractice] Applying pending audioURL to study recorder');
        window.setStudyRecorderAudio(audioURL);
      }

      console.log('[PronunciationPractice] Initialized successfully');
    }

    let orderChosen=[];
    function updateOrderPreview(){
      const chipsMap = {
        audio: t('order_audio_text') || 'Audio (text)',
        transcription: t('transcription') || 'transcription',
        audio_text: t('order_audio_word') || 'Audio (word)',
        text: t('front') || 'text',
        translation: t('back') || 'translation',
        image: t('image') || 'image',
        explanation: t('explanation') || 'explanation',
      };
      $$("#orderChips .chip").forEach(ch=>{
        ch.classList.toggle("active", orderChosen.includes(ch.dataset.kind));
      });
      const pretty = (orderChosen.length ? orderChosen : DEFAULT_ORDER).map(k=>chipsMap[k]).join(' → ');
      const prevEl = document.getElementById('orderPreview');
      if(prevEl) prevEl.textContent = pretty;
    }
    $("#orderChips").addEventListener("click",e=>{
      const btn=e.target.closest(".chip");
      if(!btn)return;
      // Handle reset button
      if(btn.id === 'chip_reset'){
        orderChosen=[];
        updateOrderPreview();
        return;
      }
      const k=btn.dataset.kind;
      const i=orderChosen.indexOf(k);
      if(i===-1) orderChosen.push(k);
      else orderChosen.splice(i,1);
      updateOrderPreview();
    });
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
      privateAudioOnly = false;
      lastAudioDurationSec = 0;
      if(sttAbortController){
        try{ sttAbortController.abort(); }catch(_e){}
        sttAbortController = null;
      }
      sttUndoValue = null;
      setSttUndoVisible(false);
      setSttStatus(sttEnabled ? 'idle' : 'disabled');
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
        setTranslationPreview('', aiStrings.translationIdle);
       }
       // Re-initialize autogrow for textareas after reset
       setTimeout(() => { initAutogrow(); initTextareaClearButtons(); }, 50);
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

      if(!privateAudioOnly){
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
      } else {
        lastAudioUrl = null;
      }

      const translations={};
      if(userLang2 !== 'en' && trLocal){ translations[userLang2]=trLocal; }
      if(trEn){ translations['en']=trEn; }
      const translationDisplay = (userLang2 !== 'en' ? (translations[userLang2] || translations['en'] || "") : (translations['en'] || ""));
      const finalOrder = orderChosen.length ? orderChosen : [...DEFAULT_ORDER];
      console.log('[DEBUG saveCard] orderChosen:', orderChosen, 'finalOrder:', finalOrder);
      const payload={id,text,fokus,explanation:expl,translation:translationDisplay,translations,order:finalOrder};
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
      if(!privateAudioOnly){
        if(lastAudioUrl){
          payload.audio=lastAudioUrl;
          payload.audioFront=lastAudioUrl;
        } else if(lastAudioKey){
          payload.audioKey=lastAudioKey;
        }
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
      focusQueueCard(localDeckId, id);
      if(tabSwitcher) tabSwitcher('study');
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
          rows.push({deckTitle:d.title, deckId:d.id, id:c.id, card:c, fokus:c.fokus||"", stage:rec.step||0, added:rec.addedAt||null, due:rec.due||null});
        });
      });

      // Apply search filter
      const q=$("#listSearch").value.toLowerCase();
      let filtered=rows.filter(r=>!q || r.fokus.toLowerCase().includes(q) || (r.deckTitle||"").toLowerCase().includes(q));

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
        tr.innerHTML=`<td>${r.fokus||"-"}</td><td>${formatStageBadge(r.stage)}</td><td>${fmtDateTime(r.due)}</td><td class="row playcell" style="gap:6px"></td>`;
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
            visibleSlots=initialVisibleSlots(currentItem.card);
            showCurrent();
            $("#listModal").style.display="none";
            // Trigger edit button to populate form
            $("#btnEdit").click();
          }
        };
        cell.appendChild(edit);
        const del=document.createElement("button");
        del.className="iconbtn";
        del.textContent="🗑";
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

    // ========== PUSH NOTIFICATIONS ==========
    const PUSH_SUBSCRIPTION_KEY = 'flashcards_push_subscribed';

    async function initPushNotifications() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        debugLog('[Push] Push notifications not supported');
        return;
      }

      // Check if already subscribed
      const alreadySubscribed = localStorage.getItem(PUSH_SUBSCRIPTION_KEY) === 'true';
      if (alreadySubscribed) {
        debugLog('[Push] Already subscribed');
        return;
      }

      // Wait a bit before showing permission request (not immediately on load)
      // Only ask after user has used the app for a while
      const visitCount = parseInt(localStorage.getItem('flashcards_visit_count') || '0', 10);
      localStorage.setItem('flashcards_visit_count', String(visitCount + 1));

      // Ask for permission after 3rd visit
      if (visitCount < 3) {
        debugLog('[Push] Waiting for more visits before asking for permission');
        return;
      }

      // Check current permission state
      if (Notification.permission === 'denied') {
        debugLog('[Push] Notification permission denied');
        return;
      }

      // If permission not yet granted, we'll need to ask
      // For now, we auto-subscribe if permission is already granted
      if (Notification.permission === 'granted') {
        await subscribeToPush();
      }
    }

    async function subscribeToPush() {
      try {
        // Get VAPID public key from server
        const vapidResp = await fetch(baseurl + 'ajax.php?cmid=0&action=get_vapid_key&sesskey=' + M.cfg.sesskey);
        const vapidData = await vapidResp.json();

        if (!vapidData.ok || !vapidData.data?.publicKey) {
          debugLog('[Push] VAPID key not available');
          return;
        }

        const vapidPublicKey = vapidData.data.publicKey;

        // Get service worker registration
        const registration = await navigator.serviceWorker.ready;

        // Subscribe to push
        const subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
        });

        // Send subscription to server
        const lang = currentInterfaceLang || 'en';
        const resp = await fetch(baseurl + 'ajax.php?cmid=0&action=push_subscribe&sesskey=' + M.cfg.sesskey, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            subscription: subscription.toJSON(),
            lang: lang
          })
        });

        const result = await resp.json();
        if (result.ok) {
          localStorage.setItem(PUSH_SUBSCRIPTION_KEY, 'true');
          debugLog('[Push] Subscribed successfully');
        } else {
          debugLog('[Push] Subscription failed:', result);
        }
      } catch (err) {
        debugLog('[Push] Error subscribing:', err);
      }
    }

    async function updatePushSubscriptionLang(newLang) {
      if (localStorage.getItem(PUSH_SUBSCRIPTION_KEY) !== 'true') {
        return;
      }

      try {
        await fetch(baseurl + 'ajax.php?cmid=0&action=push_update_lang&sesskey=' + M.cfg.sesskey, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ lang: newLang })
        });
        debugLog('[Push] Language updated to:', newLang);
      } catch (err) {
        debugLog('[Push] Error updating language:', err);
      }
    }

    function urlBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    // Initialize push notifications
    initPushNotifications();

    // Make updatePushSubscriptionLang available for language change handler
    window.flashcards_updatePushLang = updatePushSubscriptionLang;
    // ========== END PUSH NOTIFICATIONS ==========

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

    // Inline field prompt (embedded in card instead of popup)
    function hideInlineFieldPrompt(){
      const existing = slotContainer.querySelector('.inline-field-prompt');
      if(existing) existing.remove();
    }

    function showInlineFieldPrompt(){
      if(!currentItem) return;
      if(activeTab !== 'study') return;
      if(currentItem.card && currentItem.card.scope !== 'private') return;

      const step = currentItem.rec?.step || 0;
      const pkey = promptKey(currentItem.deckId, currentItem.card.id, step);
      if(shownPrompts.has(pkey)) return;

      const field = firstMissingForStep(currentItem.card);
      if(!field) return;

      shownPrompts.add(pkey);

      // Remove existing inline prompt if any
      hideInlineFieldPrompt();

      // Create inline prompt element
      const wrap = document.createElement('div');
      wrap.className = 'slot inline-field-prompt';

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

      // Header
      const header = document.createElement('div');
      header.className = 'inline-prompt-header';
      header.innerHTML = `<span class="tag">${t('fill_field').replace('{$a}', labelMap[field] || field)}</span>`;
      wrap.appendChild(header);

      // Input area
      const inputWrap = document.createElement('div');
      inputWrap.className = 'inline-prompt-input';
      let editor = null;

      function inputEl(type='text'){
        const i = document.createElement('input');
        i.type = type;
        i.className = 'inline-prompt-field';
        return i;
      }

      function textareaEl(){
        const t = document.createElement('textarea');
        t.className = 'autogrow inline-prompt-field';
        t.style.lineHeight = '1.5';
        return t;
      }

      function selectEl(){
        const s = document.createElement('select');
        s.className = 'inline-prompt-field';
        return s;
      }

      if(field === 'transcription'){
        editor = inputEl('text');
        editor.value = currentItem.card.transcription || '';
        editor.placeholder = '[transkrɪpʃən]';
        inputWrap.appendChild(editor);
      } else if(field === 'pos'){
        editor = selectEl();
        editor.innerHTML = `
          <option value="">-</option>
          <option value="substantiv">Substantiv</option>
          <option value="verb">${M?.str?.mod_flashcards?.pos_verb||'Verb'}</option>
          <option value="adj">${M?.str?.mod_flashcards?.pos_adj||'Adjective'}</option>
          <option value="adv">${M?.str?.mod_flashcards?.pos_adv||'Adverb'}</option>
          <option value="other">${M?.str?.mod_flashcards?.pos_other||'Other'}</option>`;
        editor.value = currentItem.card.pos || '';
        inputWrap.appendChild(editor);
      } else if(field === 'gender'){
        editor = selectEl();
        editor.innerHTML = `
          <option value="">-</option>
          <option value="intetkjonn">${M?.str?.mod_flashcards?.gender_neuter||'Neuter'}</option>
          <option value="hankjonn">${M?.str?.mod_flashcards?.gender_masculine||'Masculine'}</option>
          <option value="hunkjonn">${M?.str?.mod_flashcards?.gender_feminine||'Feminine'}</option>`;
        editor.value = currentItem.card.gender || '';
        inputWrap.appendChild(editor);
      } else if(field === 'nounForms'){
        const grid = document.createElement('div');
        grid.style.display = 'grid';
        grid.style.gap = '8px';

        function formRow(lbl, id, val){
          const r = document.createElement('div');
          const label = document.createElement('div');
          label.className = 'small';
          label.textContent = lbl;
          r.appendChild(label);
          const inp = inputEl('text');
          inp.id = id;
          inp.value = val || '';
          r.appendChild(inp);
          return r;
        }

        const nf = (currentItem.card.forms && currentItem.card.forms.noun) || {};
        grid.appendChild(formRow(M?.str?.mod_flashcards?.indef_sg||'Indefinite singular', 'inlineNfIndefSg', nf.indef_sg));
        grid.appendChild(formRow(M?.str?.mod_flashcards?.def_sg||'Definite singular', 'inlineNfDefSg', nf.def_sg));
        grid.appendChild(formRow(M?.str?.mod_flashcards?.indef_pl||'Indefinite plural', 'inlineNfIndefPl', nf.indef_pl));
        grid.appendChild(formRow(M?.str?.mod_flashcards?.def_pl||'Definite plural', 'inlineNfDefPl', nf.def_pl));
        editor = grid;
        inputWrap.appendChild(grid);
      } else {
        // Array fields: examples, collocations, antonyms, cognates, sayings
        editor = textareaEl();
        const arr = Array.isArray(currentItem.card[field]) ? currentItem.card[field] : [];
        editor.value = arr.join('\n');
        editor.placeholder = t(field + '_placeholder') || 'One per line...';
        inputWrap.appendChild(editor);
      }

      wrap.appendChild(inputWrap);

      // Buttons
      const btnRow = document.createElement('div');
      btnRow.className = 'inline-prompt-buttons';

      const btnSave = document.createElement('button');
      btnSave.className = 'ok';
      btnSave.textContent = t('save');
      btnSave.addEventListener('click', async () => {
        let updated = false;

        if(field === 'transcription'){
          const v = editor.value.trim();
          if(v){ currentItem.card.transcription = v; updated = true; }
        } else if(field === 'pos'){
          currentItem.card.pos = editor.value;
          updated = true;
        } else if(field === 'gender'){
          currentItem.card.gender = editor.value;
          updated = true;
        } else if(field === 'nounForms'){
          const nf = {
            indef_sg: document.getElementById('inlineNfIndefSg')?.value.trim() || '',
            def_sg: document.getElementById('inlineNfDefSg')?.value.trim() || '',
            indef_pl: document.getElementById('inlineNfIndefPl')?.value.trim() || '',
            def_pl: document.getElementById('inlineNfDefPl')?.value.trim() || ''
          };
          currentItem.card.forms = currentItem.card.forms || {};
          currentItem.card.forms.noun = nf;
          updated = true;
        } else {
          const lines = editor.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
          currentItem.card[field] = lines;
          updated = true;
        }

        if(updated){
          try{
            const payload = buildPayloadFromCard(currentItem.card);
            await api('upsert_card', {}, 'POST', { deckId: currentItem.deckId, cardId: currentItem.card.id, scope: currentItem.card.scope||'private', payload });
          }catch(_e){}
        }

        hideInlineFieldPrompt();
      });

      const btnSkip = document.createElement('button');
      btnSkip.className = 'mid';
      btnSkip.textContent = t('skip');
      btnSkip.addEventListener('click', () => {
        hideInlineFieldPrompt();
      });

      btnRow.appendChild(btnSave);
      btnRow.appendChild(btnSkip);
      wrap.appendChild(btnRow);

      // Add to slot container
      slotContainer.appendChild(wrap);
      initAutogrow();
      initTextareaClearButtons();

      // Focus on first input
      setTimeout(() => {
        const firstInput = wrap.querySelector('input, textarea, select');
        if(firstInput) firstInput.focus();
      }, 100);
    }

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
    function closeFieldPrompt(){
      const modal=document.getElementById('fieldPrompt');
      if(modal) modal.style.display='none';
    }
    function openFieldPrompt(field, card){
      const fp = document.getElementById('fieldPrompt');
      const body = document.getElementById('fpBody');
      const title = document.getElementById('fpTitle');
      if(!fp||!body||!title) return;
      body.innerHTML = '';
      let editor = null;
      function inputEl(type='text'){ const i=document.createElement('input'); i.type=type; i.style.width='100%'; i.style.padding='10px'; i.style.background='#0b1220'; i.style.color='#f1f5f9'; i.style.border='1px solid #374151'; i.style.borderRadius='10px'; return i; }
      function textareaEl(){ const t=document.createElement('textarea'); t.className='autogrow'; t.style.width='100%'; t.style.padding='10px'; t.style.background='#0b1220'; t.style.color='#f1f5f9'; t.style.border='1px solid #374151'; t.style.borderRadius='10px'; t.style.lineHeight='1.5'; return t; }
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
      initTextareaClearButtons();
      fp.style.display='flex';
      const close=()=>closeFieldPrompt();
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
    function maybePromptForStage(){ if(!currentItem) return; if(activeTab!=='study') return; if(currentItem.card && currentItem.card.scope !== 'private') return; const step=currentItem.rec?.step||0; const pkey=promptKey(currentItem.deckId,currentItem.card.id,step); if(shownPrompts.has(pkey)) return; const field=firstMissingForStep(currentItem.card); if(!field) return; shownPrompts.add(pkey); openFieldPrompt(field,currentItem.card); }
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
          // Re-initialize autogrow for textareas
          setTimeout(() => { initAutogrow(); initTextareaClearButtons(); }, 50);
        } else if (tabName === 'study' && studySection) {
          studySection.classList.add('fc-tab-active');
          tabStudy.classList.add('fc-tab-active');
          tabStudy.setAttribute('aria-selected', 'true');
          console.log('[Tabs] Activated Study');
          // Study view will auto-refresh from existing queue
          // Re-initialize autogrow for textareas
          setTimeout(() => { initAutogrow(); initTextareaClearButtons(); }, 50);
          // Initialize pronunciation practice recorder with retry
          let retryCount = 0;
          const maxRetries = 5;
          const retryDelay = 200;

          function tryInitPronunciationPractice(){
            try{
              console.log('[DEBUG] Attempt', retryCount + 1, '- About to call initStudyPronunciationPractice');
              const btnExists = !!$("#btnRecordStudy");
              const timerExists = !!$("#recTimerStudy");
              console.log('[DEBUG] Elements check: btnRecordStudy=', btnExists, ', recTimerStudy=', timerExists);

              if(!btnExists || !timerExists){
                retryCount++;
                if(retryCount < maxRetries){
                  console.log('[DEBUG] Elements not ready, retry in', retryDelay, 'ms');
                  setTimeout(tryInitPronunciationPractice, retryDelay);
                }else{
                  console.error('[ERROR] Max retries reached, elements still not found');
                }
                return;
              }

              initStudyPronunciationPractice();
              console.log('[DEBUG] initStudyPronunciationPractice called successfully');
            }catch(err){
              console.error('[ERROR] Failed to initialize pronunciation practice:', err);
              console.error('[ERROR] Stack:', err.stack);
            }
          }

          setTimeout(tryInitPronunciationPractice, 100);
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

        activeTab = tabName;
        if (tabName === 'study') {
          if (currentItem) {
            showCurrent(true);
          } else {
            pendingStudyRender = false;
          }
        } else {
          stopStudyPlayback();
          closeFieldPrompt();
        }

        debugLog(`[Tabs] Switched to ${tabName}`);
      }

      tabSwitcher = switchTab;

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
          // Update push notification language preference
          if(typeof window.flashcards_updatePushLang === 'function'){
            window.flashcards_updatePushLang(newLang);
          }
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

    // ========== MOBILE LANGUAGE SELECTOR MODAL ==========
    (function initMobileLangModal(){
      const langBtnMobile = document.getElementById('langBtnMobile');
      const languageModal = document.getElementById('languageModal');
      const langSelEl = document.getElementById('langSel');

      if(!langBtnMobile || !languageModal) return;

      let modalOpening = false;

      // Update mobile button text to match current language
      function updateMobileButtonText(){
        if(langBtnMobile && langSelEl){
          langBtnMobile.textContent = (langSelEl.value || 'EN').toUpperCase();
        }
      }

      // Open modal on button click (with touchstart for iOS)
      function openLanguageModal(e){
        if(modalOpening) return;
        modalOpening = true;

        e.preventDefault();
        e.stopPropagation();

        languageModal.classList.add('active');
        // Update selected state
        const currentLang = langSelEl ? langSelEl.value : 'en';
        document.querySelectorAll('.language-modal-option').forEach(function(opt){
          if(opt.getAttribute('data-lang') === currentLang){
            opt.classList.add('selected');
          } else {
            opt.classList.remove('selected');
          }
        });

        setTimeout(function(){ modalOpening = false; }, 300);
      }

      langBtnMobile.addEventListener('touchstart', openLanguageModal, { passive: false });
      langBtnMobile.addEventListener('click', function(e){
        if(!modalOpening){
          openLanguageModal(e);
        }
      });

      // Close modal on background click
      languageModal.addEventListener('click', function(e){
        if(e.target === languageModal){
          languageModal.classList.remove('active');
        }
      });

      // Close modal on Escape key
      document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && languageModal.classList.contains('active')){
          languageModal.classList.remove('active');
        }
      });

      // Handle language selection
      let languageSelecting = false;

      document.querySelectorAll('.language-modal-option').forEach(function(opt){
        function selectLanguage(e){
          if(languageSelecting) return;
          languageSelecting = true;

          e.preventDefault();
          e.stopPropagation();

          const selectedLang = opt.getAttribute('data-lang');
          if(langSelEl && selectedLang){
            langSelEl.value = selectedLang;
            // Trigger change event to update everything
            const event = new Event('change', { bubbles: true });
            langSelEl.dispatchEvent(event);
            // Update mobile button
            updateMobileButtonText();
            // Close modal
            setTimeout(function(){
              languageModal.classList.remove('active');
              languageSelecting = false;
            }, 100);
          } else {
            languageSelecting = false;
          }
        }

        opt.addEventListener('touchstart', selectLanguage, { passive: false });
        opt.addEventListener('click', function(e){
          if(!languageSelecting){
            selectLanguage(e);
          }
        });
      });

      // Initial update
      updateMobileButtonText();

      // Listen to desktop select changes to sync mobile button
      if(langSelEl){
        langSelEl.addEventListener('change', updateMobileButtonText);
      }
    })();
    // ========== END MOBILE LANGUAGE SELECTOR MODAL ==========

  }
export { flashcardsInit };
