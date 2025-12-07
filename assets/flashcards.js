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
        clearBtn.innerHTML = '\u00D7';
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
      uploading: dataset.sttUploading || 'Uploading Private audio',
      transcribing: dataset.sttTranscribing || 'Transcribing',
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
    function updateUsageFromResponse(_data){
      // Token usage is no longer displayed in the media status indicator.
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
      detecting: dataset.aiDetecting || 'Detecting expression',
      success: dataset.aiSuccess || 'Focus phrase updated',
      error: dataset.aiError || 'Unable to detect an expression',
      notext: dataset.aiNoText || 'Type a sentence to enable the helper',
      analysisEmpty: dataset.analysisEmpty || 'Select a word to see the grammar breakdown.',
      ordbokeneEmpty: dataset.ordbokeneEmpty || 'Dictionary info will appear here after lookup.',
      ordbokeneCitation: dataset.ordbokeneCitation || '«Korleis». I: Nynorskordboka. Språkrådet og Universitetet i Bergen. https://ordbøkene.no (henta 25.1.2022).',
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
      translationLoading: dataset.translationLoading || 'Translating',
      translationError: dataset.translationError || 'Translation failed',
      translationReverseHint: dataset.translationReverseHint || 'Type in your language to translate into Norwegian automatically.',
      aiChatEmpty: dataset.aiChatEmpty || '  AI      / ',
      aiChatUser: dataset.aiChatUser || 'You',
      aiChatAssistant: dataset.aiChatAssistant || 'AI',
      aiChatError: dataset.aiChatError || 'AI could not answer that question.',
      aiChatLoading: dataset.aiChatLoading || 'Thinking',
      // Dictation exercise strings
      dictationPlaceholder: dataset.dictationPlaceholder || 'Type what you hear...',
      dictationCheck: dataset.dictationCheck || '',
      dictationReplay: dataset.dictationReplay || ' ',
      dictationEmptyInput: dataset.dictationEmptyInput || ' ',
      dictationCorrect: dataset.dictationCorrect || '!',
      dictationPerfect: dataset.dictationPerfect || '!  !',
      dictationHint: dataset.dictationHint || '   ',
      dictationCorrectAnswer: dataset.dictationCorrectAnswer || ' :',
      dictationYourAnswer: dataset.dictationYourAnswer || ' :',
      dictationShouldBe: dataset.dictationShouldBe || ' :',
      dictationError: dataset.dictationError || '',
      dictationErrors2: dataset.dictationErrors2 || '',
      dictationErrors5: dataset.dictationErrors5 || '',
      dictationMissingWord: dataset.dictationMissingWord || ' ',
      dictationExtraWord: dataset.dictationExtraWord || ' '
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
        tab_study: 'Practice',
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
        fokus: 'Focus word/phrase',
        focus_baseform: 'Base form',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'AI focus helper',
        ai_click_hint: 'Tap any word above to detect a fixed expression',
        ai_no_text: 'Type a sentence to enable the helper',
        choose_focus_word: 'Choose focus word',
        ai_question_label: 'Ask the AI',
        ai_question_placeholder: 'Type a question about this sentence...',
        ai_question_button: 'Ask',
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'You',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'The AI could not answer that question.',
        ai_chat_loading: 'Thinking...',
        explanation: 'Explanation',
        back: 'Translation',
        back_en: 'Translation (English)',
        save: 'Save',
        cancel: 'Cancel',
        show_advanced: 'Show Advanced ',
        hide_advanced: 'Hide Advanced ^',
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
        btnHardHint: 'Repeat this card today',
        btnNormalHint: 'Next review tomorrow',
        btnEasyHint: 'Move to the next stage',
        dashboard_total_cards: 'Total Cards',
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
        sentence_analysis: 'Grammar & meaning breakdown',
        analysis_empty: 'Select a word to see the grammar breakdown.',
        check_text: 'Check text',
        checking_text: 'Checking text...',
        no_errors_found: 'No errors found!',
        errors_found: 'Errors found!',
        corrected_version: 'Corrected version:',
        apply_corrections: 'Apply corrections',
        keep_as_is: 'Keep it as it is',
        error_checking_failed: 'Error checking failed',
        naturalness_suggestion: 'More natural alternative:',
        ask_ai_about_correction: 'Ask AI',
        ai_sure: 'Are you sure?',
        ai_explain_more: 'Explain in detail',
        ai_more_examples: 'Give more examples',
        ai_explain_simpler: 'Explain simpler',
        ai_thinking: 'Thinking...',
      },
      uk: {
        app_title: 'MyMemory',
        interface_language_label: '\u041c\u043e\u0432\u0430 \u0456\u043d\u0442\u0435\u0440\u0444\u0435\u0439\u0441\u0443',
        font_scale_label: '\u0420\u043e\u0437\u043c\u0456\u0440 \u0448\u0440\u0438\u0444\u0442\u0443',
        tab_quickinput: '\u0421\u0442\u0432\u043e\u0440\u0438\u0442\u0438',
        tab_study: '\u041d\u0430\u0432\u0447\u0430\u043d\u043d\u044f',
        tab_dashboard: '\u041f\u0430\u043d\u0435\u043b\u044c',
        quick_audio: '\u0417\u0430\u043f\u0438\u0441\u0430\u0442\u0438 \u0430\u0443\u0434\u0456\u043e',
        quick_photo: '\u0417\u0440\u043e\u0431\u0438\u0442\u0438 \u0444\u043e\u0442\u043e',
        choosefile: '\u0412\u0438\u0431\u0440\u0430\u0442\u0438 \u0444\u0430\u0439\u043b',
        chooseaudiofile: '\u0412\u0438\u0431\u0440\u0430\u0442\u0438 \u0430\u0443\u0434\u0456\u043e\u0444\u0430\u0439\u043b',
        tts_voice: '\u0413\u043e\u043b\u043e\u0441',
        tts_voice_hint: '\u0412\u0438\u0431\u0435\u0440\u0456\u0442\u044c \u0433\u043e\u043b\u043e\u0441 \u043f\u0435\u0440\u0435\u0434 \u0442\u0438\u043c, \u044f\u043a \u043f\u043e\u043f\u0440\u043e\u0441\u0438\u0442\u0438 AI \u043f\u043e\u043c\u0456\u0447\u043d\u0438\u043a\u0430 \u0437\u0433\u0435\u043d\u0435\u0440\u0443\u0432\u0430\u0442\u0438 \u0430\u0443\u0434\u0456\u043e.',
        front: '\u0422\u0435\u043a\u0441\u0442',
        front_translation_toggle_show: 'Show translation',
        front_translation_toggle_hide: 'Hide translation',
        front_translation_mode_label: '\u041d\u0430\u043f\u0440\u044f\u043c\u043e\u043a \u043f\u0435\u0440\u0435\u043a\u043b\u0430\u0434\u0443',
        front_translation_mode_hint: '\u041d\u0430\u0442\u0438\u0441\u043d\u0456\u0442\u044c, \u0449\u043e\u0431 \u0437\u043c\u0456\u043d\u0438\u0442\u0438 \u043c\u043e\u0432\u0438 \u0432\u0432\u0435\u0434\u0435\u043d\u043d\u044f/\u0432\u0438\u0432\u0435\u0434\u0435\u043d\u043d\u044f.',
        front_translation_status_idle: '\u041f\u0435\u0440\u0435\u043a\u043b\u0430\u0434 \u0433\u043e\u0442\u043e\u0432\u0438\u0439',
        front_translation_copy: '\u041a\u043e\u043f\u0456\u044e\u0432\u0430\u0442\u0438 \u043f\u0435\u0440\u0435\u043a\u043b\u0430\u0434',
        focus_translation_label: '\u0424\u043e\u043a\u0443\u0441\u043d\u0435 \u0437\u043d\u0430\u0447\u0435\u043d\u043d\u044f',
        fokus: '\u0424\u043e\u043a\u0443\u0441\u043d\u0435 \u0441\u043b\u043e\u0432\u043e/\u0444\u0440\u0430\u0437\u0430',
        focus_baseform: '\u0411\u0430\u0437\u043e\u0432\u0430 \u0444\u043e\u0440\u043c\u0430',
        focus_baseform_ph: '\u041b\u0435\u043c\u0430 \u0430\u0431\u043e \u0456\u043d\u0444\u0456\u043d\u0456\u0442\u0438\u0432 (\u043d\u0435\u043e\u0431\u043e\u0432\'\u044f\u0437\u043a\u043e\u0432\u043e)',
        ai_helper_label: 'AI \u043f\u043e\u043c\u0456\u0447\u043d\u0438\u043a \u0444\u043e\u043a\u0443\u0441\u0443',
        ai_click_hint: '\u041d\u0430\u0442\u0438\u0441\u043d\u0456\u0442\u044c \u0431\u0443\u0434\u044c-\u044f\u043a\u0435 \u0441\u043b\u043e\u0432\u043e \u0432\u0438\u0449\u0435, \u0449\u043e\u0431 \u0432\u0438\u044f\u0432\u0438\u0442\u0438 \u0441\u0442\u0430\u043b\u0438\u0439 \u0432\u0438\u0440\u0430\u0437',
        ai_no_text: '\u0412\u0432\u0435\u0434\u0456\u0442\u044c \u0440\u0435\u0447\u0435\u043d\u043d\u044f, \u0449\u043e\u0431 \u0443\u0432\u0456\u043c\u043a\u043d\u0443\u0442\u0438 \u043f\u043e\u043c\u0456\u0447\u043d\u0438\u043a\u0430',
        choose_focus_word: '\u041e\u0431\u0435\u0440\u0456\u0442\u044c \u0444\u043e\u043a\u0443\u0441-\u0441\u043b\u043e\u0432\u043e',
        ai_question_label: '\u0417\u0430\u043f\u0438\u0442\u0430\u0442\u0438 AI',
        ai_question_placeholder: '\u0412\u0432\u0435\u0434\u0456\u0442\u044c \u0412\u0430\u0448\u0435 \u0437\u0430\u043f\u0438\u0442\u0430\u043d\u043d\u044f\u2026',
        ai_question_button: '\u0417\u0430\u043f\u0438\u0442\u0430\u0442\u0438',
        ai_chat_empty: '\u041f\u043e\u0441\u0442\u0430\u0432\u0442\u0435 \u0437\u0430\u043f\u0438\u0442\u0430\u043d\u043d\u044f AI \u0441\u0442\u043e\u0441\u043e\u0432\u043d\u043e \u0412\u0430\u0448\u043e\u0433\u043e \u0442\u0435\u043a\u0441\u0442\u0443 \u0430\u0431\u043e \u0444\u043e\u043a\u0443\u0441\u043d\u043e\u0433\u043e \u0441\u043b\u043e\u0432\u0430/ \u0444\u0440\u0430\u0437\u044b',
        ai_chat_user: '\u0412\u0438',
        ai_chat_assistant: '\u0428\u0406',
        ai_chat_error: '\u0428\u0406 \u043d\u0435 \u0437\u043c\u0456\u0433 \u0432\u0456\u0434\u043f\u043e\u0432\u0456\u0441\u0442\u0438 \u043d\u0430 \u0446\u0435 \u0437\u0430\u043f\u0438\u0442\u0430\u043d\u043d\u044f.',
        ai_chat_loading: '\u0414\u0443\u043c\u0430\u0454...',
        explanation: '\u041f\u043e\u044f\u0441\u043d\u0435\u043d\u043d\u044f',
        back: '\u041f\u0435\u0440\u0435\u043a\u043b\u0430\u0434',
        back_en: '\u041f\u0435\u0440\u0435\u043a\u043b\u0430\u0434',
        save: '\u0417\u0431\u0435\u0440\u0435\u0433\u0442\u0438',
        cancel: '\u0421\u043a\u0430\u0441\u0443\u0432\u0430\u0442\u0438',
        show_advanced: '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u0438 \u0434\u043e\u0434\u0430\u0442\u043a\u043e\u0432\u0456 \u25bc',
        hide_advanced: '\u0421\u0445\u043e\u0432\u0430\u0442\u0438 \u0434\u043e\u0434\u0430\u0442\u043a\u043e\u0432\u0456 \u25b2',
        empty: '\u0421\u044c\u043e\u0433\u043e\u0434\u043d\u0456 \u043d\u0456\u0447\u043e\u0433\u043e \u043d\u0435 \u0437\u0430\u043f\u043b\u0430\u043d\u043e\u0432\u0430\u043d\u043e',
        transcription: '\u0422\u0440\u0430\u043d\u0441\u043a\u0440\u0438\u043f\u0446\u0456\u044f',
        pos: '\u0427\u0430\u0441\u0442\u0438\u043d\u0430 \u043c\u043e\u0432\u0438',
        gender: '\u0420\u0456\u0434',
        noun_forms: '\u0424\u043e\u0440\u043c\u0438 \u0456\u043c\u0435\u043d\u043d\u0438\u043a\u0430',
        verb_forms: '\u0424\u043e\u0440\u043c\u0438 \u0434\u0456\u0454\u0441\u043b\u043e\u0432\u0430',
        adj_forms: '\u0424\u043e\u0440\u043c\u0438 \u043f\u0440\u0438\u043a\u043c\u0435\u0442\u043d\u0438\u043a\u0430',
        collocations: '\u0417\u0430\u0433\u0430\u043b\u044c\u043d\u0456 \u0441\u043b\u043e\u0432\u043e\u0441\u043f\u043e\u043b\u0443\u0447\u0435\u043d\u043d\u044f',
        examples: '\u041f\u0440\u0438\u043a\u043b\u0430\u0434\u0438 \u0440\u0435\u0447\u0435\u043d\u044c',
        antonyms: '\u0410\u043d\u0442\u043e\u043d\u0456\u043c\u0438',
        cognates: '\u0421\u043f\u043e\u0440\u0456\u0434\u043d\u0435\u043d\u0456 \u0441\u043b\u043e\u0432\u0430',
        sayings: '\u0417\u0430\u0433\u0430\u043b\u044c\u043d\u0456 \u0432\u0438\u0441\u043b\u043e\u0432\u0438',
        fill_field: '\u0411\u0443\u0434\u044c \u043b\u0430\u0441\u043a\u0430, \u0437\u0430\u043f\u043e\u0432\u043d\u0456\u0442\u044c: {$a}',
        update: '\u041e\u043d\u043e\u0432\u0438\u0442\u0438',
        createnew: '\u0421\u0442\u0432\u043e\u0440\u0438\u0442\u0438',
        audio: '\u0410\u0443\u0434\u0456\u043e',
        order_audio_word: '\u0424\u043e\u043a\u0443\u0441\u043d\u0435 \u0430\u0443\u0434\u0456\u043e',
        order_audio_text: '\u0410\u0443\u0434\u0456\u043e',
        image: '\u0417\u043e\u0431\u0440\u0430\u0436\u0435\u043d\u043d\u044f',
        order: '\u041f\u043e\u0440\u044f\u0434\u043e\u043a (\u043d\u0430\u0442\u0438\u0441\u043a\u0430\u0439\u0442\u0435 \u043f\u043e\u0441\u043b\u0456\u0434\u043e\u0432\u043d\u043e)',
        easy: '\u041b\u0435\u0433\u043a\u043e',
        normal: '\u041d\u043e\u0440\u043c.',
        hard: '\u0412\u0430\u0436\u043a\u043e',
        btnHardHint: '\u041f\u043e\u0432\u0442\u043e\u0440\u0438\u0442\u0438 \u0446\u044e \u043a\u0430\u0440\u0442\u043a\u0443 \u0441\u044c\u043e\u0433\u043e\u0434\u043d\u0456',
        btnNormalHint: '\u041d\u0430\u0441\u0442\u0443\u043f\u043d\u0438\u0439 \u043e\u0433\u043b\u044f\u0434 \u0437\u0430\u0432\u0442\u0440\u0430',
        btnEasyHint: '\u041f\u0435\u0440\u0435\u0439\u0442\u0438 \u0434\u043e \u043d\u0430\u0441\u0442\u0443\u043f\u043d\u043e\u0433\u043e \u0435\u0442\u0430\u043f\u0443',
        dashboard_total_cards: '\u0412\u0441\u044c\u043e\u0433\u043e \u043a\u0430\u0440\u0442\u043e\u043a',
        dashboard_active_vocab: '\u0410\u043a\u0442\u0438\u0432\u043d\u0438\u0439 \u0441\u043b\u043e\u0432\u043d\u0438\u043a',
        dashboard_streak: '\u041f\u043e\u0442\u043e\u0447\u043d\u0430 \u0441\u0435\u0440\u0456\u044f (\u0434\u043d\u0456\u0432)',
        dashboard_stage_chart: '\u0420\u043e\u0437\u043f\u043e\u0434\u0456\u043b \u043a\u0430\u0440\u0442\u043e\u043a \u0437\u0430 \u0435\u0442\u0430\u043f\u0430\u043c\u0438',
        dashboard_activity_chart: '\u0410\u043a\u0442\u0438\u0432\u043d\u0456\u0441\u0442\u044c \u043f\u0435\u0440\u0435\u0433\u043b\u044f\u0434\u0443 (\u043e\u0441\u0442\u0430\u043d\u043d\u0456 7 \u0434\u043d\u0456\u0432)',
        dashboard_achievements: '\u0414\u043e\u0441\u044f\u0433\u043d\u0435\u043d\u043d\u044f',
        achievement_week_warrior: '\u0412\u043e\u0457\u043d \u0442\u0438\u0436\u043d\u044f (7-\u0434\u0435\u043d\u043d\u0430 \u0441\u0435\u0440\u0456\u044f)',
        achievement_level_a0: '\u0420\u0456\u0432\u0435\u043d\u044c A0 - \u041f\u043e\u0447\u0430\u0442\u043a\u0456\u0432\u0435\u0446\u044c',
        achievement_level_a1: '\u0420\u0456\u0432\u0435\u043d\u044c A1 - \u0415\u043b\u0435\u043c\u0435\u043d\u0442\u0430\u0440\u043d\u0438\u0439',
        achievement_level_a2: '\u0420\u0456\u0432\u0435\u043d\u044c A2 - \u0411\u0430\u0437\u043e\u0432\u0438\u0439',
        achievement_level_b1: '\u0420\u0456\u0432\u0435\u043d\u044c B1 - \u0421\u0435\u0440\u0435\u0434\u043d\u0456\u0439',
        achievement_level_b2: '\u0420\u0456\u0432\u0435\u043d\u044c B2 - \u0412\u0438\u0449\u0435 \u0441\u0435\u0440\u0435\u0434\u043d\u044c\u043e\u0433\u043e',
        save: '\u0417\u0431\u0435\u0440\u0435\u0433\u0442\u0438',
        skip: '\u041f\u0440\u043e\u043f\u0443\u0441\u0442\u0438\u0442\u0438',
        showmore: '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u0438 \u0431\u0456\u043b\u044c\u0448\u0435',
        front_audio_badge: '\u0410\u0443\u0434\u0456\u043e (\u0442\u0435\u043a\u0441\u0442)',
        focus_audio_badge: '\u0410\u0443\u0434\u0456\u043e (\u0441\u043b\u043e\u0432\u043e)',
        front_placeholder: '_ _ _',
        ai_click_hint: '\u041d\u0430\u0442\u0438\u0441\u043d\u0456\u0442\u044c \u0431\u0443\u0434\u044c-\u044f\u043a\u0435 \u0441\u043b\u043e\u0432\u043e \u0432\u0438\u0449\u0435, \u0449\u043e\u0431 \u0432\u0438\u044f\u0432\u0438\u0442\u0438 \u0441\u0442\u0430\u043b\u0438\u0439 \u0432\u0438\u0440\u0430\u0437',
        translation_en_placeholder: 'I love you',
        translation_placeholder: '\u042f \u0442\u0435\u0431\u0435 \u043a\u043e\u0445\u0430\u044e',
        explanation_placeholder: '\u041f\u043e\u044f\u0441\u043d\u0435\u043d\u043d\u044f...',
        focus_placeholder: '\u0424\u043e\u043a\u0443\u0441\u043d\u0435 \u0441\u043b\u043e\u0432\u043e/\u0444\u0440\u0430\u0437\u0430...',
        collocations_placeholder: '\u0441\u043b\u043e\u0432\u043e\u0441\u043f\u043e\u043b\u0443\u0447\u0435\u043d\u043d\u044f...',
        examples_placeholder: '\u043f\u0440\u0438\u043a\u043b\u0430\u0434\u0438...',
        antonyms_placeholder: '\u0430\u043d\u0442\u043e\u043d\u0456\u043c\u0438...',
        cognates_placeholder: '\u0441\u043f\u043e\u0440\u0456\u0434\u043d\u0435\u043d\u0456 \u0441\u043b\u043e\u0432\u0430...',
        sayings_placeholder: '\u0432\u0438\u0441\u043b\u043e\u0432\u0438...',
        transcription_placeholder: '[\u041c\u0424\u0410 \u043d\u0430\u043f\u0440. /hu:s/]',
        one_per_line_placeholder: '\u043f\u043e \u043e\u0434\u043d\u043e\u043c\u0443 \u043d\u0430 \u0440\u044f\u0434\u043e\u043a...',
        sentence_analysis: 'Граматика та значення',
        analysis_empty: 'Оберіть слово, щоб побачити граматичний розбір.',
        check_text: 'Перевірити текст',
        checking_text: 'Перевіряємо текст...',
        no_errors_found: 'Помилок не знайдено!',
        errors_found: 'Знайдено помилки!',
        corrected_version: 'Виправлена версія:',
        apply_corrections: 'Застосувати виправлення',
        keep_as_is: 'Залишити як є',
        error_checking_failed: 'Помилка перевірки',
        naturalness_suggestion: 'Більш природний варіант:',
        ask_ai_about_correction: 'Запитати AI',
        ai_sure: 'Ти впевнений?',
        ai_explain_more: 'Поясни детальніше',
        ai_more_examples: 'Дай більше прикладів',
        ai_explain_simpler: 'Поясни простіше',
        ai_thinking: 'Думаю...',
      },
      ru: {
        app_title: 'MyMemory',
        interface_language_label: '\u042f\u0437\u044b\u043a \u0438\u043d\u0442\u0435\u0440\u0444\u0435\u0439\u0441\u0430',
        font_scale_label: '\u0420\u0430\u0437\u043c\u0435\u0440 \u0448\u0440\u0438\u0444\u0442\u0430',
        tab_quickinput: '\u0421\u043e\u0437\u0434\u0430\u0442\u044c',
        tab_study: '\u041e\u0431\u0443\u0447\u0435\u043d\u0438\u0435',
        tab_dashboard: '\u041f\u0430\u043d\u0435\u043b\u044c',
        quick_audio: '\u0417\u0430\u043f\u0438\u0441\u0430\u0442\u044c \u0430\u0443\u0434\u0438\u043e',
        quick_photo: '\u0421\u0434\u0435\u043b\u0430\u0442\u044c \u0444\u043e\u0442\u043e',
        choosefile: '\u0412\u044b\u0431\u0440\u0430\u0442\u044c \u0444\u0430\u0439\u043b',
        chooseaudiofile: '\u0412\u044b\u0431\u0440\u0430\u0442\u044c \u0430\u0443\u0434\u0438\u043e\u0444\u0430\u0439\u043b',
        tts_voice: '\u0413\u043e\u043b\u043e\u0441',
        tts_voice_hint: '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0433\u043e\u043b\u043e\u0441 \u043f\u0435\u0440\u0435\u0434 \u0442\u0435\u043c, \u043a\u0430\u043a \u043f\u043e\u043f\u0440\u043e\u0441\u0438\u0442\u044c AI \u043f\u043e\u043c\u043e\u0449\u043d\u0438\u043a\u0430 \u0441\u0433\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0430\u0443\u0434\u0438\u043e.',
        front: '\u0422\u0435\u043a\u0441\u0442',
        front_translation_toggle_show: 'Show translation',
        front_translation_toggle_hide: 'Hide translation',
        front_translation_mode_label: '\u041d\u0430\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u0435 \u043f\u0435\u0440\u0435\u0432\u043e\u0434\u0430',
        front_translation_mode_hint: '\u041d\u0430\u0436\u043c\u0438\u0442\u0435, \u0447\u0442\u043e\u0431\u044b \u0438\u0437\u043c\u0435\u043d\u0438\u0442\u044c \u044f\u0437\u044b\u043a\u0438 \u0432\u0432\u043e\u0434\u0430/\u0432\u044b\u0432\u043e\u0434\u0430.',
        front_translation_status_idle: '\u041f\u0435\u0440\u0435\u0432\u043e\u0434 \u0433\u043e\u0442\u043e\u0432',
        front_translation_copy: '\u041a\u043e\u043f\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u043f\u0435\u0440\u0435\u0432\u043e\u0434',
        focus_translation_label: '\u0424\u043e\u043a\u0443\u0441\u043d\u043e\u0435 \u0437\u043d\u0430\u0447\u0435\u043d\u0438\u0435',
        fokus: '\u0424\u043e\u043a\u0443\u0441\u043d\u043e\u0435 \u0441\u043b\u043e\u0432\u043e/\u0444\u0440\u0430\u0437\u0430',
        focus_baseform: '\u0411\u0430\u0437\u043e\u0432\u0430\u044f \u0444\u043e\u0440\u043c\u0430',
        focus_baseform_ph: '\u041b\u0435\u043c\u043c\u0430 \u0438\u043b\u0438 \u0438\u043d\u0444\u0438\u043d\u0438\u0442\u0438\u0432 (\u043d\u0435\u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u043e)',
        ai_helper_label: 'AI \u043f\u043e\u043c\u043e\u0449\u043d\u0438\u043a \u0444\u043e\u043a\u0443\u0441\u0430',
        ai_click_hint: '\u041d\u0430\u0436\u043c\u0438\u0442\u0435 \u043b\u044e\u0431\u043e\u0435 \u0441\u043b\u043e\u0432\u043e \u0432\u044b\u0448\u0435, \u0447\u0442\u043e\u0431\u044b \u0432\u044b\u044f\u0432\u0438\u0442\u044c \u0443\u0441\u0442\u043e\u0439\u0447\u0438\u0432\u043e\u0435 \u0432\u044b\u0440\u0430\u0436\u0435\u043d\u0438\u0435',
        ai_no_text: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u043f\u0440\u0435\u0434\u043b\u043e\u0436\u0435\u043d\u0438\u0435, \u0447\u0442\u043e\u0431\u044b \u0432\u043a\u043b\u044e\u0447\u0438\u0442\u044c \u043f\u043e\u043c\u043e\u0449\u043d\u0438\u043a\u0430',
        choose_focus_word: '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0444\u043e\u043a\u0443\u0441-\u0441\u043b\u043e\u0432\u043e',
        ai_question_label: '\u0421\u043f\u0440\u043e\u0441\u0438\u0442\u044c \u0418\u0418',
        ai_question_placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0412\u0430\u0448 \u0432\u043e\u043f\u0440\u043e\u0441...',
        ai_question_button: '\u0421\u043f\u0440\u043e\u0441\u0438\u0442\u044c',
        ai_chat_empty: '\u041f\u043e\u0441\u0442\u0430\u0432\u0442\u0435 \u0437\u0430\u043f\u0438\u0442\u0430\u043d\u043d\u044f AI \u0441\u0442\u043e\u0441\u043e\u0432\u043d\u043e \u0412\u0430\u0448\u043e\u0433\u043e \u0442\u0435\u043a\u0441\u0442\u0443 \u0430\u0431\u043e \u0444\u043e\u043a\u0443\u0441\u043d\u043e\u0433\u043e \u0441\u043b\u043e\u0432\u0430/ \u0444\u0440\u0430\u0437\u044b',
        ai_chat_user: '\u0412\u044b',
        ai_chat_assistant: '\u0418\u0418',
        ai_chat_error: '\u0418\u0418 \u043d\u0435 \u0441\u043c\u043e\u0433 \u043e\u0442\u0432\u0435\u0442\u0438\u0442\u044c \u043d\u0430 \u044d\u0442\u043e\u0442 \u0432\u043e\u043f\u0440\u043e\u0441.',
        ai_chat_loading: '\u0414\u0443\u043c\u0430\u0435\u0442...',
        explanation: '\u041e\u0431\u044a\u044f\u0441\u043d\u0435\u043d\u0438\u0435',
        back: '\u041f\u0435\u0440\u0435\u0432\u043e\u0434',
        back_en: '\u041f\u0435\u0440\u0435\u0432\u043e\u0434',
        save: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c',
        cancel: '\u041e\u0442\u043c\u0435\u043d\u0430',
        show_advanced: '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u044c \u0434\u043e\u043f\u043e\u043b\u043d\u0438\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u25bc',
        hide_advanced: '\u0421\u043a\u0440\u044b\u0442\u044c \u0434\u043e\u043f\u043e\u043b\u043d\u0438\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u25b2',
        empty: '\u0421\u0435\u0433\u043e\u0434\u043d\u044f \u043d\u0438\u0447\u0435\u0433\u043e \u043d\u0435 \u0437\u0430\u043f\u043b\u0430\u043d\u0438\u0440\u043e\u0432\u0430\u043d\u043e',
        transcription: '\u0422\u0440\u0430\u043d\u0441\u043a\u0440\u0438\u043f\u0446\u0438\u044f',
        pos: '\u0427\u0430\u0441\u0442\u044c \u0440\u0435\u0447\u0438',
        gender: '\u0420\u043e\u0434',
        noun_forms: '\u0424\u043e\u0440\u043c\u044b \u0441\u0443\u0449\u0435\u0441\u0442\u0432\u0438\u0442\u0435\u043b\u044c\u043d\u043e\u0433\u043e',
        verb_forms: '\u0424\u043e\u0440\u043c\u044b \u0433\u043b\u0430\u0433\u043e\u043b\u0430',
        adj_forms: '\u0424\u043e\u0440\u043c\u044b \u043f\u0440\u0438\u043b\u0430\u0433\u0430\u0442\u0435\u043b\u044c\u043d\u043e\u0433\u043e',
        collocations: '\u041e\u0431\u0449\u0438\u0435 \u0441\u043b\u043e\u0432\u043e\u0441\u043e\u0447\u0435\u0442\u0430\u043d\u0438\u044f',
        examples: '\u041f\u0440\u0438\u043c\u0435\u0440\u044b \u043f\u0440\u0435\u0434\u043b\u043e\u0436\u0435\u043d\u0438\u0439',
        antonyms: '\u0410\u043d\u0442\u043e\u043d\u0438\u043c\u044b',
        cognates: '\u0420\u043e\u0434\u0441\u0442\u0432\u0435\u043d\u043d\u044b\u0435 \u0441\u043b\u043e\u0432\u0430',
        sayings: '\u041e\u0431\u0449\u0438\u0435 \u0432\u044b\u0441\u043a\u0430\u0437\u044b\u0432\u0430\u043d\u0438\u044f',
        fill_field: '\u041f\u043e\u0436\u0430\u043b\u0443\u0439\u0441\u0442\u0430, \u0437\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u0435: {$a}',
        update: '\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c',
        createnew: '\u0421\u043e\u0437\u0434\u0430\u0442\u044c',
        audio: '\u0410\u0443\u0434\u0438\u043e',
        order_audio_word: '\u0424\u043e\u043a\u0443\u0441\u043d\u043e\u0435 \u0430\u0443\u0434\u0438\u043e',
        order_audio_text: '\u0410\u0443\u0434\u0438\u043e',
        image: '\u0418\u0437\u043e\u0431\u0440\u0430\u0436\u0435\u043d\u0438\u0435',
        order: '\u041f\u043e\u0440\u044f\u0434\u043e\u043a (\u043d\u0430\u0436\u0438\u043c\u0430\u0439\u0442\u0435 \u043f\u043e\u0441\u043b\u0435\u0434\u043e\u0432\u0430\u0442\u0435\u043b\u044c\u043d\u043e)',
        easy: '\u041b\u0435\u0433\u043a\u043e',
        normal: '\u041d\u043e\u0440\u043c.',
        hard: '\u0421\u043b\u043e\u0436\u043d\u043e',
        btnHardHint: '\u041f\u043e\u0432\u0442\u043e\u0440\u0438\u0442\u044c \u044d\u0442\u0443 \u043a\u0430\u0440\u0442\u043e\u0447\u043a\u0443 \u0441\u0435\u0433\u043e\u0434\u043d\u044f',
        btnNormalHint: '\u0421\u043b\u0435\u0434\u0443\u044e\u0449\u0438\u0439 \u043e\u0431\u0437\u043e\u0440 \u0437\u0430\u0432\u0442\u0440\u0430',
        btnEasyHint: '\u041f\u0435\u0440\u0435\u0439\u0442\u0438 \u043a \u0441\u043b\u0435\u0434\u0443\u044e\u0449\u0435\u043c\u0443 \u044d\u0442\u0430\u043f\u0443',
        dashboard_total_cards: '\u0412\u0441\u0435\u0433\u043e \u043a\u0430\u0440\u0442\u043e\u0447\u0435\u043a',
        dashboard_active_vocab: '\u0410\u043a\u0442\u0438\u0432\u043d\u044b\u0439 \u0441\u043b\u043e\u0432\u0430\u0440\u044c',
        dashboard_streak: '\u0422\u0435\u043a\u0443\u0449\u0430\u044f \u0441\u0435\u0440\u0438\u044f (\u0434\u043d\u0435\u0439)',
        dashboard_stage_chart: '\u0420\u0430\u0441\u043f\u0440\u0435\u0434\u0435\u043b\u0435\u043d\u0438\u0435 \u043a\u0430\u0440\u0442\u043e\u0447\u0435\u043a \u043f\u043e \u044d\u0442\u0430\u043f\u0430\u043c',
        dashboard_activity_chart: '\u0410\u043a\u0442\u0438\u0432\u043d\u043e\u0441\u0442\u044c \u043f\u0440\u043e\u0441\u043c\u043e\u0442\u0440\u0430 (\u043f\u043e\u0441\u043b\u0435\u0434\u043d\u0438\u0435 7 \u0434\u043d\u0435\u0439)',
        dashboard_achievements: '\u0414\u043e\u0441\u0442\u0438\u0436\u0435\u043d\u0438\u044f',
        achievement_week_warrior: '\u0412\u043e\u0438\u043d \u043d\u0435\u0434\u0435\u043b\u0438 (7-\u0434\u043d\u0435\u0432\u043d\u0430\u044f \u0441\u0435\u0440\u0438\u044f)',
        achievement_level_a0: '\u0423\u0440\u043e\u0432\u0435\u043d\u044c A0 - \u041d\u0430\u0447\u0438\u043d\u0430\u044e\u0449\u0438\u0439',
        achievement_level_a1: '\u0423\u0440\u043e\u0432\u0435\u043d\u044c A1 - \u042d\u043b\u0435\u043c\u0435\u043d\u0442\u0430\u0440\u043d\u044b\u0439',
        achievement_level_a2: '\u0423\u0440\u043e\u0432\u0435\u043d\u044c A2 - \u0411\u0430\u0437\u043e\u0432\u044b\u0439',
        achievement_level_b1: '\u0423\u0440\u043e\u0432\u0435\u043d\u044c B1 - \u0421\u0440\u0435\u0434\u043d\u0438\u0439',
        achievement_level_b2: '\u0423\u0440\u043e\u0432\u0435\u043d\u044c B2 - \u0412\u044b\u0448\u0435 \u0441\u0440\u0435\u0434\u043d\u0435\u0433\u043e',
        save: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c',
        skip: '\u041f\u0440\u043e\u043f\u0443\u0441\u0442\u0438\u0442\u044c',
        showmore: '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u044c \u0431\u043e\u043b\u044c\u0448\u0435',
        front_audio_badge: '\u0410\u0443\u0434\u0438\u043e \u043b\u0438\u0446\u0435\u0432\u043e\u0439 \u0441\u0442\u043e\u0440\u043e\u043d\u044b',
        focus_audio_badge: '\u0424\u043e\u043a\u0443\u0441\u043d\u043e\u0435 \u0430\u0443\u0434\u0438\u043e',
        front_placeholder: '_ _ _',
        ai_click_hint: '\u041d\u0430\u0436\u043c\u0438\u0442\u0435 \u043b\u044e\u0431\u043e\u0435 \u0441\u043b\u043e\u0432\u043e \u0432\u044b\u0448\u0435, \u0447\u0442\u043e\u0431\u044b \u0432\u044b\u044f\u0432\u0438\u0442\u044c \u0443\u0441\u0442\u043e\u0439\u0447\u0438\u0432\u043e\u0435 \u0432\u044b\u0440\u0430\u0436\u0435\u043d\u0438\u0435',
        translation_en_placeholder: 'I love you',
        translation_placeholder: '\u042f \u0442\u0435\u0431\u044f \u043b\u044e\u0431\u043b\u044e',
        explanation_placeholder: '_ _ _',
        focus_placeholder: '_ _ _',
        collocations_placeholder: '_ _ _',
        examples_placeholder: '_ _ _',
        antonyms_placeholder: '_ _ _',
        cognates_placeholder: '_ _ _',
        sayings_placeholder: '_ _ _',
        transcription_placeholder: '_ _ _',
        one_per_line_placeholder: '_ _ _',
        sentence_analysis: 'Грамматика и значение',
        analysis_empty: 'Выберите слово, чтобы увидеть грамматический разбор.',
        check_text: 'Проверить текст',
        checking_text: 'Проверяем текст...',
        no_errors_found: 'Ошибок не найдено!',
        errors_found: 'Найдены ошибки!',
        corrected_version: 'Исправленная версия:',
        apply_corrections: 'Применить исправления',
        keep_as_is: 'Оставить как есть',
        error_checking_failed: 'Ошибка проверки',
        naturalness_suggestion: 'Более естественный вариант:',
        ask_ai_about_correction: 'Спросить AI',
        ai_sure: 'Ты уверен?',
        ai_explain_more: 'Объясни подробнее',
        ai_more_examples: 'Дай больше примеров',
        ai_explain_simpler: 'Объясни проще',
        ai_thinking: 'Думаю...',
      },
      fr: {
        app_title: 'MyMemory',
        interface_language_label: 'Langue de l\'interface',
        font_scale_label: 'Taille du texte',
        tab_quickinput: 'Creer',
        tab_study: 'Pratique',
        tab_dashboard: 'Tableau',
        quick_audio: 'Enregistrer l\'audio',
        quick_photo: 'Prendre une photo',
        choosefile: 'Choisir un fichier',
        chooseaudiofile: 'Choisir un fichier audio',
        tts_voice: 'Voix',
        tts_voice_hint: 'Selectionnez une voix avant de demander a l\'assistant IA de generer l\'audio.',
        front: 'Texte',
        front_translation_toggle_show: 'Afficher la traduction',
        front_translation_toggle_hide: 'Masquer la traduction',
        front_translation_mode_label: 'Direction de traduction',
        front_translation_mode_hint: 'Appuyez pour changer les langues d\'entree/sortie',
        front_translation_status_idle: 'Traduction prete',
        front_translation_copy: 'Copier la traduction',
        focus_translation_label: 'Signification focale',
        fokus: 'Mot/phrase focal',
        focus_baseform: 'Forme de base',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Assistant IA focal',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour detecter une expression figee',
        ai_no_text: 'Saisissez une phrase pour activer l\'assistant',
        choose_focus_word: 'Choisissez le mot focal',
        ai_question_label: 'Demander a l\'IA',
        ai_question_placeholder: 'Tapez une question sur cette phrase...',
        ai_question_button: 'Demander',
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'Vous',
        ai_chat_assistant: 'IA',
        ai_chat_error: 'L\'IA n\'a pas pu repondre a cette question.',
        ai_chat_loading: 'Reflexion...',
        explanation: 'Explication',
        back: 'Traduction',
        back_en: 'Traduction (anglais)',
        save: 'Enregistrer',
        cancel: 'Annuler',
        show_advanced: 'Afficher avance ',
        hide_advanced: 'Masquer avance ^',
        empty: 'Rien prevu aujourd\'hui',
        transcription: 'Transcription',
        pos: 'Partie du discours',
        gender: 'Genre',
        noun_forms: 'Formes du nom',
        verb_forms: 'Formes du verbe',
        adj_forms: 'Formes de l\'adjectif',
        collocations: 'Collocations courantes',
        examples: 'Exemples de phrases',
        antonyms: 'Antonymes',
        cognates: 'Mots apparentes',
        sayings: 'Expressions courantes',
        fill_field: 'Veuillez remplir : {$a}',
        update: 'Mettre a jour',
        createnew: 'Creer',
        audio: 'Audio',
        order_audio_word: 'Audio focal',
        order_audio_text: 'Audio',
        image: 'Image',
        order: 'Ordre (cliquer en sequence)',
        easy: 'Facile',
        normal: 'Normal',
        hard: 'Difficile',
        btnHardHint: 'Repeter cette carte aujourd\'hui',
        btnNormalHint: 'Prochaine revision demain',
        btnEasyHint: 'Passer a l\'etape suivante',
        dashboard_total_cards: 'Total de cartes',
        dashboard_active_vocab: 'Vocabulaire actif',
        dashboard_streak: 'Serie actuelle (jours)',
        dashboard_stage_chart: 'Distribution des etapes de cartes',
        dashboard_activity_chart: 'Activite de revision (7 derniers jours)',
        dashboard_achievements: 'Realisations',
        achievement_week_warrior: 'Guerrier de la semaine (serie de 7 jours)',
        achievement_level_a0: 'Niveau A0 - Debutant',
        achievement_level_a1: 'Niveau A1 - Elementaire',
        achievement_level_a2: 'Niveau A2 - Pre-intermediaire',
        achievement_level_b1: 'Niveau B1 - Intermediaire',
        achievement_level_b2: 'Niveau B2 - Intermediaire superieur',
        save: 'Enregistrer',
        skip: 'Passer',
        showmore: 'Afficher plus',
        front_audio_badge: 'Audio du recto',
        focus_audio_badge: 'Audio focal',
        front_placeholder: '_ _ _',
        ai_click_hint: 'Appuyez sur n\'importe quel mot ci-dessus pour detecter une expression figee',
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
        font_scale_label: 'Tamano de fuente',
        tab_quickinput: 'Crear',
        tab_study: 'Práctica',
        tab_dashboard: 'Panel',
        quick_audio: 'Grabar audio',
        quick_photo: 'Tomar foto',
        choosefile: 'Elegir archivo',
        chooseaudiofile: 'Elegir archivo de audio',
        tts_voice: 'Voz',
        tts_voice_hint: 'Selecciona una voz antes de pedir al asistente IA que genere audio.',
        front: 'Texto',
        front_translation_toggle_show: 'Mostrar traduccion',
        front_translation_toggle_hide: 'Ocultar traduccion',
        front_translation_mode_label: 'Direccion de traduccion',
        front_translation_mode_hint: 'Toca para cambiar los idiomas de entrada/salida',
        front_translation_status_idle: 'Traduccion lista',
        front_translation_copy: 'Copiar traduccion',
        focus_translation_label: 'Significado focal',
        fokus: 'Palabra/frase focal',
        focus_baseform: 'Forma base',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Asistente IA focal',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresion fija',
        ai_no_text: 'Escribe una oracion para activar el asistente',
        choose_focus_word: 'Elige la palabra focal',
        ai_question_label: 'Preguntar a la IA',
        ai_question_placeholder: 'Escribe una pregunta sobre esta frase...',
        ai_question_button: 'Preguntar',
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'Tu',
        ai_chat_assistant: 'IA',
        ai_chat_error: 'La IA no pudo responder a esa pregunta.',
        ai_chat_loading: 'Pensando...',
        explanation: 'Explicacion',
        back: 'Traduccion',
        back_en: 'Traduccion (ingles)',
        save: 'Guardar',
        cancel: 'Cancelar',
        show_advanced: 'Mostrar avanzado ',
        hide_advanced: 'Ocultar avanzado ^',
        empty: 'Nada pendiente hoy',
        transcription: 'Transcripcion',
        pos: 'Categoria gramatical',
        gender: 'Genero',
        noun_forms: 'Formas del sustantivo',
        verb_forms: 'Formas del verbo',
        adj_forms: 'Formas del adjetivo',
        collocations: 'Colocaciones comunes',
        examples: 'Oraciones de ejemplo',
        antonyms: 'Antonimos',
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
        easy: 'Facil',
        normal: 'Normal',
        hard: 'Dificil',
        btnHardHint: 'Repetir esta tarjeta hoy',
        btnNormalHint: 'Proxima revision manana',
        btnEasyHint: 'Pasar a la siguiente etapa',
        dashboard_total_cards: 'Total de tarjetas',
        dashboard_active_vocab: 'Vocabulario activo',
        dashboard_streak: 'Racha actual (dias)',
        dashboard_stage_chart: 'Distribucion de etapas de tarjetas',
        dashboard_activity_chart: 'Actividad de revision (ultimos 7 dias)',
        dashboard_achievements: 'Logros',
        achievement_week_warrior: 'Guerrero de la semana (racha de 7 dias)',
        achievement_level_a0: 'Nivel A0 - Principiante',
        achievement_level_a1: 'Nivel A1 - Elemental',
        achievement_level_a2: 'Nivel A2 - Pre-intermedio',
        achievement_level_b1: 'Nivel B1 - Intermedio',
        achievement_level_b2: 'Nivel B2 - Intermedio superior',
        save: 'Guardar',
        skip: 'Omitir',
        showmore: 'Mostrar mas',
        front_audio_badge: 'Audio del anverso',
        focus_audio_badge: 'Audio focal',
        front_placeholder: '_ _ _',
        ai_click_hint: 'Toca cualquier palabra arriba para detectar una expresion fija',
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
        interface_language_label: 'Jezyk interfejsu',
        font_scale_label: 'Rozmiar czcionki',
        tab_quickinput: 'Utworz',
        tab_study: 'Praktyka',
        tab_dashboard: 'Panel',
        quick_audio: 'Nagraj audio',
        quick_photo: 'Zrob zdjecie',
        choosefile: 'Wybierz plik',
        chooseaudiofile: 'Wybierz plik audio',
        tts_voice: 'Glos',
        tts_voice_hint: 'Wybierz glos przed poproszeniem asystenta AI o wygenerowanie audio.',
        front: 'Tekst',
        front_translation_toggle_show: 'Pokaz tlumaczenie',
        front_translation_toggle_hide: 'Ukryj tlumaczenie',
        front_translation_mode_label: 'Kierunek tlumaczenia',
        front_translation_mode_hint: 'Dotknij, aby zmienic jezyki wejscia/wyjscia',
        front_translation_status_idle: 'Tlumaczenie gotowe',
        front_translation_copy: 'Kopiuj tlumaczenie',
        focus_translation_label: 'Fokusowe znaczenie',
        fokus: 'Slowo/fraza fokusowa',
        focus_baseform: 'Forma podstawowa',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'Asystent AI fokusa',
        ai_click_hint: 'Dotknij dowolnego slowa powyzej, aby wykryc stale wyrazenie',
        ai_no_text: 'Wpisz zdanie, aby wlaczyc asystenta',
        choose_focus_word: 'Wybierz slowo fokusowe',
        ai_question_label: 'Zapytaj AI',
        ai_question_placeholder: 'Wpisz pytanie dotyczace tego zdania...',
        ai_question_button: 'Zapytaj',
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'Ty',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'AI nie mogla odpowiedziec na to pytanie.',
        ai_chat_loading: 'Mysli...',
        explanation: 'Wyjasnienie',
        back: 'Tlumaczenie',
        back_en: 'Tlumaczenie (angielski)',
        save: 'Zapisz',
        cancel: 'Anuluj',
        show_advanced: 'Pokaz zaawansowane ',
        hide_advanced: 'Ukryj zaawansowane ^',
        empty: 'Nic do nauki dzisiaj',
        transcription: 'Transkrypcja',
        pos: 'Czesc mowy',
        gender: 'Rodzaj',
        noun_forms: 'Formy rzeczownika',
        verb_forms: 'Formy czasownika',
        adj_forms: 'Formy przymiotnika',
        collocations: 'Typowe kolokacje',
        examples: 'Przykladowe zdania',
        antonyms: 'Antonimy',
        cognates: 'Wyrazy pokrewne',
        sayings: 'Powszechne wyrazenia',
        fill_field: 'Prosze wypelnic: {$a}',
        update: 'Aktualizuj',
        createnew: 'Utworz',
        audio: 'Audio',
        order_audio_word: 'Audio fokusowe',
        order_audio_text: 'Audio',
        image: 'Obraz',
        order: 'Kolejnosc (klikaj po kolei)',
        easy: 'Latwe',
        normal: 'Normalne',
        hard: 'Trudne',
        btnHardHint: 'Powtorz te fiszke dzisiaj',
        btnNormalHint: 'Nastepny przeglad jutro',
        btnEasyHint: 'Przejdz do nastepnego etapu',
        dashboard_total_cards: 'Laczna liczba fiszek',
        dashboard_active_vocab: 'Aktywne slownictwo',
        dashboard_streak: 'Obecna seria (dni)',
        dashboard_stage_chart: 'Rozklad etapow fiszek',
        dashboard_activity_chart: 'Aktywnosc przegladu (ostatnie 7 dni)',
        dashboard_achievements: 'Osiagniecia',
        achievement_week_warrior: 'Wojownik tygodnia (7-dniowa seria)',
        achievement_level_a0: 'Poziom A0 - Poczatkujacy',
        achievement_level_a1: 'Poziom A1 - Elementarny',
        achievement_level_a2: 'Poziom A2 - Podstawowy',
        achievement_level_b1: 'Poziom B1 - Sredniozaawansowany',
        achievement_level_b2: 'Poziom B2 - Zaawansowany sredni',
        save: 'Zapisz',
        skip: 'Pomin',
        showmore: 'Pokaz wiecej',
        front_audio_badge: 'Audio przodu',
        focus_audio_badge: 'Audio fokusowe',
        front_placeholder: '_ _ _',
        ai_click_hint: 'Dotknij dowolnego slowa powyzej, aby wykryc stale wyrazenie',
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
        sentence_analysis: 'Gramatyka i znaczenie',
        analysis_empty: 'Wybierz słowo, aby zobaczyć analizę gramatyczną.',
        check_text: 'Sprawdź tekst',
        checking_text: 'Sprawdzanie tekstu...',
        no_errors_found: 'Nie znaleziono błędów!',
        errors_found: 'Znaleziono błędy!',
        corrected_version: 'Poprawiona wersja:',
        apply_corrections: 'Zastosuj poprawki',
        keep_as_is: 'Zostaw jak jest',
        error_checking_failed: 'Błąd sprawdzania',
        naturalness_suggestion: 'Bardziej naturalna alternatywa:',
        ask_ai_about_correction: 'Zapytaj AI',
        ai_sure: 'Jesteś pewien?',
        ai_explain_more: 'Wyjaśnij szczegółowo',
        ai_more_examples: 'Daj więcej przykładów',
        ai_explain_simpler: 'Wyjaśnij prościej',
        ai_thinking: 'Myślę...',
      },
      it: {
        app_title: 'MyMemory',
        interface_language_label: 'Lingua dell\'interfaccia',
        font_scale_label: 'Dimensione del testo',
        tab_quickinput: 'Crea',
        tab_study: 'Pratica',
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
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'Tu',
        ai_chat_assistant: 'AI',
        ai_chat_error: 'L\'AI non ha potuto rispondere a quella domanda.',
        ai_chat_loading: 'Sta pensando...',
        explanation: 'Spiegazione',
        back: 'Traduzione',
        back_en: 'Traduzione (inglese)',
        save: 'Salva',
        cancel: 'Annulla',
        show_advanced: 'Mostra avanzate ',
        hide_advanced: 'Nascondi avanzate ^',
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
        btnHardHint: 'Ripeti questa scheda oggi',
        btnNormalHint: 'Prossima revisione domani',
        btnEasyHint: 'Passa alla fase successiva',
        dashboard_total_cards: 'Totale schede',
        dashboard_active_vocab: 'Vocabolario attivo',
        dashboard_streak: 'Serie attuale (giorni)',
        dashboard_stage_chart: 'Distribuzione fasi schede',
        dashboard_activity_chart: 'Attivita di ripasso (ultimi 7 giorni)',
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
        interface_language_label: 'Sprache der Oberflache',
        font_scale_label: 'Schriftgro?e',
        tab_quickinput: 'Erstellen',
        tab_study: 'Praxis',
        tab_dashboard: 'Dashboard',
        quick_audio: 'Audio aufnehmen',
        quick_photo: 'Foto aufnehmen',
        choosefile: 'Datei auswahlen',
        chooseaudiofile: 'Audiodatei auswahlen',
        tts_voice: 'Stimme',
        tts_voice_hint: 'Wahlen Sie eine Stimme aus, bevor Sie den KI-Assistenten bitten, Audio zu generieren.',
        front: 'Text',
        front_translation_toggle_show: 'Ubersetzung anzeigen',
        front_translation_toggle_hide: 'Ubersetzung ausblenden',
        front_translation_mode_label: 'Ubersetzungsrichtung',
        front_translation_mode_hint: 'Tippen Sie, um die Eingabe-/Ausgabesprachen zu andern',
        front_translation_status_idle: 'Ubersetzung bereit',
        front_translation_copy: 'Ubersetzung kopieren',
        focus_translation_label: 'Fokusbedeutung',
        fokus: 'Fokuswort/-phrase',
        focus_baseform: 'Grundform',
        focus_baseform_ph: '_ _ _',
        ai_helper_label: 'KI-Fokus-Assistent',
        ai_click_hint: 'Tippen Sie auf ein beliebiges Wort oben, um einen festen Ausdruck zu erkennen',
        ai_no_text: 'Geben Sie einen Satz ein, um den Assistenten zu aktivieren',
        choose_focus_word: 'Fokuswort auswahlen',
        ai_question_label: 'KI fragen',
        ai_question_placeholder: 'Stellen Sie eine Frage zu diesem Satz...',
        ai_question_button: 'Fragen',
        ai_chat_empty: '  AI      / ',
        ai_chat_user: 'Sie',
        ai_chat_assistant: 'KI',
        ai_chat_error: 'Die KI konnte diese Frage nicht beantworten.',
        ai_chat_loading: 'Denkt nach...',
        explanation: 'Erklarung',
        back: 'Ubersetzung',
        back_en: 'Ubersetzung (Englisch)',
        save: 'Speichern',
        cancel: 'Abbrechen',
        show_advanced: 'Erweitert anzeigen ',
        hide_advanced: 'Erweitert ausblenden ^',
        empty: 'Heute nichts fallig',
        transcription: 'Transkription',
        pos: 'Wortart',
        gender: 'Geschlecht',
        noun_forms: 'Substantivformen',
        verb_forms: 'Verbformen',
        adj_forms: 'Adjektivformen',
        collocations: 'Haufige Kollokationen',
        examples: 'Beispielsatze',
        antonyms: 'Antonyme',
        cognates: 'Verwandte Worter',
        sayings: 'Haufige Redewendungen',
        fill_field: 'Bitte ausfullen: {$a}',
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
        btnHardHint: 'Diese Karte heute wiederholen',
        btnNormalHint: 'Nachste Wiederholung morgen',
        btnEasyHint: 'Zur nachsten Stufe wechseln',
        dashboard_total_cards: 'Gesamt Karten',
        dashboard_active_vocab: 'Aktiver Wortschatz',
        dashboard_streak: 'Aktuelle Serie (Tage)',
        dashboard_stage_chart: 'Kartenstufen-Verteilung',
        dashboard_activity_chart: 'Uberprufungsaktivitat (letzte 7 Tage)',
        dashboard_achievements: 'Erfolge',
        achievement_week_warrior: 'Wochenkrieger (7-Tages-Serie)',
        achievement_level_a0: 'Niveau A0 - Anfanger',
        achievement_level_a1: 'Niveau A1 - Grundstufe',
        achievement_level_a2: 'Niveau A2 - Untere Mittelstufe',
        achievement_level_b1: 'Niveau B1 - Mittelstufe',
        achievement_level_b2: 'Niveau B2 - Obere Mittelstufe',
        save: 'Speichern',
        skip: 'Uberspringen',
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
    let currentInterfaceLang = null;

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
      aiStrings.analysisEmpty = t('analysis_empty');
      aiStrings.ordbokeneEmpty = t('ordbokene_empty');
      aiStrings.ordbokeneCitation = t('ordbokene_citation');

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
    currentInterfaceLang = savedInterfaceLang || userLang2;

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
        // Ensure a visible default selection on first load.
        const initialVoice = selectedVoice;
        if(initialVoice){
          voiceSelectEl.value = initialVoice;
          if(!savedVoiceChoice){
            // Persist the default so it stays selected next time.
            saveVoice(initialVoice);
          }
        } else {
          // No saved/default voice – keep the placeholder selected.
          voiceSelectEl.value = '';
        }
        voiceSelectEl.addEventListener('change', ()=>{
          selectedVoice = voiceSelectEl.value;
          saveVoice(selectedVoice);
          setTtsStatus('', '');
        });
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
        uk:'', ru:'', pl:'Polski', de:'Deutsch', fr:'Francais', es:'Espanol', it:'Italiano'
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
        {code: 'uk', name: ''},
        {code: 'en', name: 'English'},
        {code: 'ru', name: ''},
        {code: 'pl', name: 'Polski'},
        {code: 'de', name: 'Deutsch'},
        {code: 'fr', name: 'Francais'},
        {code: 'es', name: 'Espanol'},
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
        translationForwardLabel.textContent = `${norwegianLabel} > ${langLabel}`;
      }
      if(translationReverseLabel){
        translationReverseLabel.textContent = `${langLabel} > ${norwegianLabel}`;
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
      mediaStatusIndicator.textContent = text;
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
      focusTranslationText.textContent = clean || '?';
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

      // Auto-focus the appropriate input field and move cursor to end
      if(translationDirection === 'user-no'){
        // Reverse mode: focus uTransLocal
        if(translationInputLocal){
          translationInputLocal.focus();
          // Move cursor to end of text
          const len = translationInputLocal.value.length;
          translationInputLocal.setSelectionRange(len, len);
        }
      }else{
        // Forward mode: focus uFront
        if(frontInput){
          frontInput.focus();
          // Move cursor to end of text
          const len = frontInput.value.length;
          frontInput.setSelectionRange(len, len);
        }
      }
    }

    function scheduleTranslationRefresh(){
      if(translationDebounce){
        clearTimeout(translationDebounce);
      }
      translationDebounce = setTimeout(runTranslationHelper, 1400);
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
      btnRemove.textContent = '? Remove';
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
      btnToggle.textContent = '?? Show/Hide';
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
    const frontInputWrap = frontInput ? frontInput.closest('.front-input-wrap') : null;
    const frontSuggest = frontInputWrap ? frontInputWrap.querySelector('[data-front-suggest]') : null;
    let frontSuggestList = null;
    let frontSuggestToggle = null;
    let frontSuggestPanel = null;
    let frontSuggestCollapsed = false;
    const frontSuggestToggleLabel = getModString('front_suggest_collapse') || 'Hide suggestions';
    const setFrontSuggestToggleState = (collapsed) => {
      if(!frontSuggestToggle){
        return;
      }
      frontSuggestToggle.classList.toggle('collapsed', collapsed);
      frontSuggestToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      const icon = collapsed ? '▼' : '▲';
      frontSuggestToggle.innerHTML = `
        <span aria-hidden="true" class="front-suggest-toggle-icon">${icon}</span>
        <span class="front-suggest-toggle-text">${frontSuggestToggleLabel}</span>
      `;
    };
    if(frontSuggest){
      frontSuggest.classList.add('front-suggest-has-toggle');
      frontSuggestToggle = document.createElement('button');
      frontSuggestToggle.type = 'button';
      frontSuggestToggle.className = 'front-suggest-toggle';
      frontSuggestToggle.title = frontSuggestToggleLabel;
      frontSuggestToggle.setAttribute('aria-label', frontSuggestToggleLabel);
      frontSuggestToggle.addEventListener('click', e=>{
        e.preventDefault();
        e.stopPropagation();
        frontSuggestCollapsed = true;
        setFrontSuggestToggleState(true);
        hideFrontSuggest();
      });
      frontSuggestList = document.createElement('div');
      frontSuggestList.className = 'front-suggest-list';
      frontSuggestPanel = document.createElement('div');
      frontSuggestPanel.className = 'front-suggest-panel';
      frontSuggestPanel.appendChild(frontSuggestToggle);
      frontSuggestPanel.appendChild(frontSuggestList);
      frontSuggest.appendChild(frontSuggestPanel);
      setFrontSuggestToggleState(false);
    }
    const fokusInput = $("#uFokus");
    let fokusSuggest = $("#fokusSuggest");
    // Create suggest container if missing (template cache fallback).
    if(!fokusSuggest && fokusInput && fokusInput.parentElement){
      const div = document.createElement('div');
      div.id = 'fokusSuggest';
      div.className = 'fokus-suggest';
      fokusInput.parentElement.appendChild(div);
      fokusSuggest = div;
    }
    const focusBaseInput = document.getElementById('uFocusBase');
    const focusWordList = document.getElementById('focusWordList');
    const focusStatusEl = document.getElementById('focusHelperStatus');
    const focusAnalysisList = document.getElementById('focusAnalysisList');
    const ordbokeneBlock = document.getElementById('ordbokeneBlock');
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
    const translationStatusRow = mediaStatusIndicator ? mediaStatusIndicator.closest('.translation-status-row') : null;
    const mediaStatusTargetId = 'yui_3_18_1_1_1764889193288_12';
    const checkTextBtnInline = document.getElementById('checkTextBtn');
    const mediaStatusTarget = document.getElementById(mediaStatusTargetId) ||
      (checkTextBtnInline ? checkTextBtnInline.parentElement : null);
    if(mediaStatusIndicator && mediaStatusTarget){
      // Place the media status indicator inside the requested field (same row as checkTextBtn, right aligned).
      mediaStatusIndicator.classList.add('translation-status-inline');
      const host = mediaStatusTarget;
      if(host && !host.contains(mediaStatusIndicator)){
        host.appendChild(mediaStatusIndicator);
      }
      if(translationStatusRow && translationStatusRow !== host){
        translationStatusRow.remove();
      }
    }
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
        if(frontSuggestCollapsed){
          frontSuggestCollapsed = false;
          setFrontSuggestToggleState(false);
        }
        if(translationDirection === 'no-user'){
          scheduleTranslationRefresh();
        }
        scheduleFrontSuggest();
      });
      frontInput.addEventListener('focus', ()=>{
        if(translationDirection !== 'no-user' && !frontInput.value.trim()){
          applyTranslationDirection('no-user');
        }
        scheduleFrontSuggest();
      });
      frontInput.addEventListener('click', scheduleFrontSuggest);
      frontInput.addEventListener('blur', ()=>{
        setTimeout(hideFrontSuggest, 120);
      });
      frontInput.addEventListener('keydown', e=>{
        if(e.key === 'Escape'){
          hideFrontSuggest();
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
    const wordRegex = (()=>{ try { void new RegExp('\\p{L}', 'u'); return /[\p{L}\p{M}\d'\-]+/gu; } catch(_e){ return /[A-Za-z0-9'\-]+/g; } })();
    const countWords = (text)=> (text.match(wordRegex) || []).length;

    // Front-side autocomplete (ordbokene + local ordbank)
    const FRONT_SUGGEST_DEBOUNCE = 170;
    const frontSuggestCache = {};
    let frontSuggestTimer = null;
    let frontSuggestRequestSeq = 0;
    let frontSuggestActive = '';
    let frontSuggestRendered = '';
    let frontSuggestAbort = null;
    let frontSuggestScheduledQuery = '';

    function currentFrontQuery(){
      if(!frontInput){
        return '';
      }
      const value = (frontInput.value || '').trim();
      return value.length > 120 ? value.slice(-120) : value;
    }

    function hideFrontSuggest(){
      if(!frontSuggest){
        return;
      }
      if(frontSuggestList){
        frontSuggestList.innerHTML = '';
      } else {
        frontSuggest.innerHTML = '';
      }
      frontSuggest.classList.remove('open');
    }

    function renderFrontSuggest(list, query){
      if(!frontSuggest){
        return;
      }
      if(frontSuggestCollapsed){
        return;
      }
      const container = frontSuggestList || frontSuggest;
      container.innerHTML = '';
      const formatDictLabel = (item)=>{
        const langs = (item.langs || []).map(l=>{
          const v = (l||'').toLowerCase();
          if(v==='ordbokene' || v==='ordbank') return '';
          if(v==='bm' || v==='bokmål' || v==='bokmal' || v==='bokmaal') return 'bm';
          if(v==='nn' || v==='nynorsk') return 'nn';
          if(v) return v.toUpperCase();
          return '';
        }).filter(Boolean);
        const langLabel = langs.length ? langs.join('/') : '';
        const dictKey = ((item.dict || item.source || '')).toLowerCase();
        let source = '';
        if(dictKey === 'ordbokene'){
          source = 'ordbøkene';
        } else if(dictKey === 'ordbank'){
          source = 'ordbank';
        } else if(dictKey){
          source = dictKey;
        } else if(item.dict){
          source = item.dict;
        } else if(item.source){
          source = item.source;
        }
        return { langLabel, source };
      };
      if(!Array.isArray(list) || !list.length){
        frontSuggest.classList.remove('open');
        return;
      }
      list.forEach(item=>{
        const btn = document.createElement('div');
        btn.className = 'fokus-suggest-item';
        const lemma = document.createElement('span');
        lemma.className = 'lemma';
        lemma.textContent = item.lemma || '';
        const dict = document.createElement('span');
        dict.className = 'dict';
        const dictInfo = formatDictLabel(item);
        const label = dictInfo.source || '';
        dict.textContent = dictInfo.langLabel ? `${label} (${dictInfo.langLabel})` : label;
        if(dictInfo.langLabel){
          dict.title = dictInfo.langLabel;
        }else{
          dict.removeAttribute('title');
        }
        btn.appendChild(lemma);
        btn.appendChild(dict);
        btn.style.cursor = 'default';
        container.appendChild(btn);
      });
      frontSuggest.classList.add('open');
      setFrontSuggestToggleState(false);
    }

    function applyFrontSuggestion(value, query){
      if(!frontInput || !value){
        return;
      }
      let next = value;
      if(query){
        const current = frontInput.value || '';
        const q = query.trim();
        if(q){
          const idx = current.toLowerCase().lastIndexOf(q.toLowerCase());
          if(idx >= 0){
            next = current.slice(0, idx) + value + current.slice(idx + q.length);
          }
        }
      }
      frontInput.value = next;
      try{
        frontInput.dispatchEvent(new Event('input', {bubbles:true}));
        frontInput.dispatchEvent(new Event('autogrow:refresh'));
        frontInput.focus();
      }catch(_e){}
    }

    async function fetchFrontSuggest(query){
      if(!frontSuggest){
        return;
      }
      const seq = ++frontSuggestRequestSeq;
      frontSuggestActive = query;
      if(frontSuggestAbort){
        try{ frontSuggestAbort.abort(); }catch(_e){}
      }
      frontSuggestAbort = new AbortController();
      const key = query.toLowerCase();
      if(frontSuggestCache[key]){
        if(seq === frontSuggestRequestSeq && frontSuggestActive === query){
          frontSuggestRendered = query;
          renderFrontSuggest(frontSuggestCache[key], query);
        }
        return;
      }
      try{
        const resp = await api('front_suggest', {}, 'POST', {query}, {signal: frontSuggestAbort.signal});
        const list = Array.isArray(resp) ? resp : [];
        frontSuggestCache[key] = list;
        if(seq === frontSuggestRequestSeq && frontSuggestActive === query){
          frontSuggestRendered = query;
          renderFrontSuggest(list, query);
        }
      }catch(err){
        if(err?.name !== 'AbortError'){
          console.error('[Flashcards] front suggest failed', err);
        }
        hideFrontSuggest();
      }
    }

    function scheduleFrontSuggest(){
      if(frontSuggestCollapsed){
        return;
      }
      if(!frontInput){
        return;
      }
      const wordCount = countWords(frontInput.value || '');
      if(wordCount > 4){
        if(frontSuggestAbort){
          try{ frontSuggestAbort.abort(); }catch(_e){}
          frontSuggestAbort = null;
        }
        hideFrontSuggest();
        frontSuggestScheduledQuery = '';
        return;
      }
      const q = currentFrontQuery();
      if(frontSuggestTimer){
        clearTimeout(frontSuggestTimer);
      }
      if(!q || q.length < 2){
        hideFrontSuggest();
        frontSuggestScheduledQuery = '';
        return;
      }
      if(q === frontSuggestScheduledQuery && frontSuggestTimer){
        return;
      }
      frontSuggestScheduledQuery = q;
      // gentle debounce to wait for pauses and avoid repeated requests
        frontSuggestTimer = setTimeout(()=>{
          frontSuggestTimer = null;
          fetchFrontSuggest(q);
        }, FRONT_SUGGEST_DEBOUNCE);
    }

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
        renderSentenceAnalysis([]);
        renderOrdbokeneBlock(null);
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
          btn.addEventListener('click', ()=>{
            focusHelperState.activeIndex = token.index;
            updateChipActiveState();
            if(aiEnabled){
              triggerFocusHelper(token, token.index, tokens);
            } else {
              triggerOrdbankHelper(token, token.index, tokens);
            }
            triggerFokusSuggestForInput();
          });
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

    function renderSentenceAnalysis(items){
      if(!focusAnalysisList) return;
      focusAnalysisList.innerHTML = '';
      const list = Array.isArray(items) ? items.filter(it => it && (it.text || it.token)) : [];
      if(!list.length){
        focusAnalysisList.textContent = aiStrings.analysisEmpty || '';
        return;
      }
      list.forEach(entry => {
        const chip = document.createElement('div');
        chip.className = 'analysis-item';
        const textEl = document.createElement('span');
        textEl.className = 'analysis-text';
        textEl.textContent = entry.text || entry.token || '';
        chip.appendChild(textEl);
        if(entry.translation){
          const trEl = document.createElement('span');
          trEl.className = 'analysis-translation';
          trEl.textContent = entry.translation;
          chip.appendChild(trEl);
        }
        focusAnalysisList.appendChild(chip);
      });
    }

    function renderOrdbokeneBlock(info){
      if(!ordbokeneBlock) return;
      ordbokeneBlock.innerHTML = '';
      if(!info || !info.expression){
        ordbokeneBlock.textContent = aiStrings.ordbokeneEmpty || '';
        return;
      }
      const chosenIdx = Number.isInteger(info.chosenMeaning) ? info.chosenMeaning : null;
      const head = document.createElement('div');
      head.className = 'ordbokene-head';
      const expressionUrl = info.expression ? `https://ordbokene.no/nob/bm,nn/${encodeURIComponent((info.expression || '').trim())}` : '';
      if(expressionUrl){
        const link = document.createElement('a');
        link.className = 'ordbokene-expression-link';
        link.href = expressionUrl;
        link.rel = 'noreferrer noopener';
        link.target = '_blank';
        link.textContent = info.expression;
        head.appendChild(link);
      } else {
        head.textContent = info.expression;
      }
      ordbokeneBlock.appendChild(head);

      if(Array.isArray(info.meanings) && info.meanings.length){
        const meaningsWrap = document.createElement('div');
        meaningsWrap.className = 'ordbokene-meanings';
        const meanings = info.meanings.slice();
        if(chosenIdx !== null && chosenIdx >=0 && chosenIdx < meanings.length){
          const chosenVal = meanings.splice(chosenIdx,1)[0];
          meanings.unshift(chosenVal);
        }
        meanings.slice(0,2).forEach((m, i) => {
          const p = document.createElement('div');
          p.className = 'ordbokene-meaning';
          if(i===0 && chosenIdx !== null){
            p.classList.add('ordbokene-meaning--highlight');
          }
          p.textContent = m;
          meaningsWrap.appendChild(p);
        });
        ordbokeneBlock.appendChild(meaningsWrap);
      }

      if(Array.isArray(info.examples) && info.examples.length){
        const examplesWrap = document.createElement('div');
        examplesWrap.className = 'ordbokene-examples';
        info.examples.slice(0,2).forEach(ex => {
          const exEl = document.createElement('div');
          exEl.className = 'ordbokene-example';
          exEl.textContent = ex;
          examplesWrap.appendChild(exEl);
        });
        ordbokeneBlock.appendChild(examplesWrap);
      }

      const citation = document.createElement('div');
      citation.className = 'ordbokene-citation';
      const citationText = info.citation || aiStrings.ordbokeneCitation || '';
      if(citationText){
        citation.appendChild(document.createTextNode(citationText));
      }
      ordbokeneBlock.appendChild(citation);
    }

    function applyFocusMeta(payload, opts = {}){
      if(opts && opts.silent){
        return;
      }
      if(payload){
        // Prefer Ordbokene-picked expression/meaning for analysis, translation, examples.
        if(payload.ordbokene && payload.ordbokene.expression){
          const chosenIdx = Number.isInteger(payload.ordbokene.chosenMeaning) ? payload.ordbokene.chosenMeaning : 0;
          const pickMeaning = (Array.isArray(payload.ordbokene.meanings) && payload.ordbokene.meanings[chosenIdx]) ? payload.ordbokene.meanings[chosenIdx] : '';
          const cleanMeaning = (pickMeaning || '').replace(/^["']|["']$/g,'').trim();
          const translationVal = cleanMeaning || (payload.definition || payload.translation || '');
          const analysis = [{
            text: payload.ordbokene.expression,
            translation: translationVal
          }];
          payload.translation = translationVal;
          payload.definition = cleanMeaning || payload.definition || '';
          if(Array.isArray(payload.ordbokene.examples) && payload.ordbokene.examples.length){
            payload.examples = payload.ordbokene.examples;
          } else {
            payload.examples = [];
          }
          renderSentenceAnalysis(analysis);
        } else {
          renderSentenceAnalysis(payload.analysis || payload.sentenceAnalysis || []);
        }
        renderOrdbokeneBlock(payload.ordbokene || null);
      } else {
        renderSentenceAnalysis([]);
        renderOrdbokeneBlock(null);
      }
    }

    // --- Fokus autocomplete (ordbokene) ---
    let suggestTimer = null;
    const suggestCache = {};

    function triggerFokusSuggestForInput(){
      if(!fokusInput) return;
      const q = (fokusInput.value || '').trim();
      if(suggestTimer){
        clearTimeout(suggestTimer);
      }
      if(q.length < 2){
        hideFokusSuggest();
        return;
      }
      suggestTimer = setTimeout(()=>fetchFokusSuggest(q), 200);
    }

    function hideFokusSuggest(){
      if(fokusSuggest){
        fokusSuggest.innerHTML = '';
        fokusSuggest.classList.remove('open');
      }
    }

    function renderFokusSuggest(list){
      if(!fokusSuggest){
        return;
      }
      fokusSuggest.innerHTML = '';
      if(!Array.isArray(list) || !list.length){
        fokusSuggest.classList.remove('open');
        return;
      }
      list.forEach(item=>{
        const btn = document.createElement('div');
        btn.className = 'fokus-suggest-item';
        const lemma = document.createElement('span');
        lemma.className = 'lemma';
        lemma.textContent = item.lemma || '';
        const dict = document.createElement('span');
        dict.className = 'dict';
        dict.textContent = item.dict || '';
        btn.appendChild(lemma);
        btn.appendChild(dict);
        btn.addEventListener('mousedown', (e)=>{
          e.preventDefault();
          const val = item.lemma || '';
          if(fokusInput){
            fokusInput.value = val;
            try{ fokusInput.dispatchEvent(new Event('input', {bubbles:true})); }catch(_e){}
          }
          hideFokusSuggest();
          // Trigger ordbank helper immediately with the selected lemma.
          const dummyToken = {text: val, isWord: true, index: 0};
          triggerOrdbankHelper(dummyToken, 0, [dummyToken]);
        });
        fokusSuggest.appendChild(btn);
      });
      fokusSuggest.classList.add('open');
    }

    async function fetchFokusSuggest(query){
      const key = `${userLang2}:${query}`;
      if(suggestCache[key]){
        renderFokusSuggest(suggestCache[key]);
        return;
      }
      try{
        const resp = await api('ordbokene_suggest', {}, 'POST', {query, lang: userLang2});
        if(resp && resp.ok && Array.isArray(resp.data)){
          suggestCache[key] = resp.data;
          renderFokusSuggest(resp.data);
        }else{
          renderFokusSuggest([]);
        }
      }catch(_e){
        renderFokusSuggest([]);
      }
    }

    if(fokusInput){
      fokusInput.addEventListener('input', triggerFokusSuggestForInput);
      fokusInput.addEventListener('focus', triggerFokusSuggestForInput);
      fokusInput.addEventListener('blur', ()=>{
        setTimeout(hideFokusSuggest, 120);
      });
      fokusInput.addEventListener('keydown', (e)=>{
        if(e.key === 'Escape'){
          hideFokusSuggest();
        }
      });
      fokusInput.addEventListener('click', triggerFokusSuggestForInput);
    }

    function renderCompoundParts(parts, fallbackText){
      const container = document.getElementById('compoundParts');
      if(!container){
        return;
      }
      container.innerHTML = '';
      const cleanParts = Array.isArray(parts) ? parts.filter(p => typeof p === 'string' && p.trim() !== '') : [];
      const items = cleanParts.length ? cleanParts : (fallbackText ? [fallbackText] : []);
      if(!items.length){
        container.textContent = '';
        return;
      }
      items.forEach((part, idx) => {
        const chip = document.createElement('span');
        const isFuge = (items.length >= 2 && idx === 1) || /^-+$/.test(part.trim());
        const colorIdx = (idx % 4) + 1;
        chip.className = `ledd-chip ${isFuge ? 'ledd-fuge' : ('ledd-' + colorIdx)}`;
        chip.textContent = part;
        container.appendChild(chip);
        if(idx !== items.length - 1){
          const sep = document.createElement('span');
          sep.className = 'ledd-sep';
          sep.textContent = '|';
          container.appendChild(sep);
        }
      });
    }

    function renderFormsPreview(forms, posVal){
      const container = document.getElementById('formsPreview');
      if(!container){
        return;
      }
      container.innerHTML = '';
      if(!forms || typeof forms !== 'object'){
        return;
      }
      const normalizeList = (val) => {
        if(Array.isArray(val)){
          return val.map(v => (typeof v === 'string' ? v : String(v || ''))).map(v => v.trim()).filter(Boolean);
        }
        if(typeof val === 'string'){
          return val.split(/[,\\n]/).map(v => v.trim()).filter(Boolean);
        }
        return [];
      };
      const uniqVals = (val) => Array.from(new Set(normalizeList(val)));
      const makePills = (values) => {
        const frag = document.createDocumentFragment();
        uniqVals(values).forEach(v => {
          const pill = document.createElement('span');
          pill.className = 'form-pill';
          pill.textContent = v;
          frag.appendChild(pill);
        });
        return frag;
      };
      const addTableRow = (tbody, label, values) => {
        const vals = uniqVals(values);
        if(!vals.length){
          return;
        }
        const tr = document.createElement('tr');
        const th = document.createElement('th');
        th.scope = 'row';
        th.textContent = label;
        tr.appendChild(th);
        const td = document.createElement('td');
        const wrap = document.createElement('div');
        wrap.className = 'form-value-wrap';
        wrap.appendChild(makePills(vals));
        td.appendChild(wrap);
        tr.appendChild(td);
        tbody.appendChild(tr);
      };
      const addGroupedRow = (tbody, label, groups, fallback) => {
        const cleanFallback = uniqVals(fallback);
        const prepared = (groups || []).map(group => ({
          label: group.label,
          values: uniqVals(group.values || [])
        })).filter(group => group.label && group.values.length);
        if(!prepared.length && !cleanFallback.length){
          return;
        }
        const tr = document.createElement('tr');
        const th = document.createElement('th');
        th.scope = 'row';
        th.textContent = label;
        tr.appendChild(th);
        const td = document.createElement('td');
        const block = document.createElement('div');
        block.className = 'forms-subgrid';
        if(cleanFallback.length){
          const baseLine = document.createElement('div');
          baseLine.className = 'forms-subline';
          const baseLabel = document.createElement('span');
          baseLabel.className = 'sub-label';
          baseLabel.textContent = 'Generelt';
          const baseVals = document.createElement('span');
          baseVals.className = 'sub-values';
          baseVals.appendChild(makePills(cleanFallback));
          baseLine.appendChild(baseLabel);
          baseLine.appendChild(baseVals);
          block.appendChild(baseLine);
        }
        prepared.forEach(group => {
          const row = document.createElement('div');
          row.className = 'forms-subline';
          const subLabel = document.createElement('span');
          subLabel.className = 'sub-label';
          subLabel.textContent = group.label;
          const subValues = document.createElement('span');
          subValues.className = 'sub-values';
          subValues.appendChild(makePills(group.values));
          row.appendChild(subLabel);
          row.appendChild(subValues);
          block.appendChild(row);
        });
        td.appendChild(block);
        tr.appendChild(td);
        tbody.appendChild(tr);
      };
      const addChip = (target, label, values) => {
        const vals = uniqVals(values);
        if(!vals.length){
          return;
        }
        const chip = document.createElement('div');
        chip.className = 'form-chip';
        const title = document.createElement('strong');
        title.textContent = label;
        chip.appendChild(title);
        const body = document.createElement('div');
        body.appendChild(makePills(vals));
        chip.appendChild(body);
        target.appendChild(chip);
      };
      if(forms.verb){
        const tbody = document.createElement('tbody');
        addTableRow(tbody, 'Infinitiv', forms.verb.infinitiv || []);
        addTableRow(tbody, 'Presens', forms.verb.presens || []);
        addTableRow(tbody, 'Preteritum', forms.verb.preteritum || []);
        const presPerfBase = uniqVals(forms.verb.presens_perfektum || []);
        const presPerfDerived = uniqVals((forms.verb.perfektum_partisipp || []).map(v => `har ${v}`));
        const presPerfCombined = Array.from(new Set([...presPerfBase, ...presPerfDerived]));
        addTableRow(tbody, 'Presens perfektum', presPerfCombined);
        addTableRow(tbody, 'Imperativ', forms.verb.imperativ || []);
        const detailed = forms.verb.perfektum_partisipp_detailed || {};
        const perfGroups = [
          { label: 'Hankjønn/hunkjønn', values: detailed.masc_fem || detailed.mask_fem || [] },
          { label: 'Intetkjønn', values: detailed.neuter || [] },
          { label: 'Bestemt', values: detailed.definite || [] },
          { label: 'Flertall', values: detailed.plural || [] }
        ];
        addGroupedRow(tbody, 'Perfektum partisipp', perfGroups, forms.verb.perfektum_partisipp || []);
        addTableRow(tbody, 'Presens partisipp', forms.verb.presens_partisipp || []);
        if(!tbody.children.length){
          return;
        }
        const table = document.createElement('table');
        table.className = 'forms-table';
        table.appendChild(tbody);
        container.appendChild(table);
      } else if(forms.noun){
        const grid = document.createElement('div');
        grid.className = 'forms-chip-grid';
        addChip(grid, 'Indef. sg', forms.noun.indef_sg || []);
        addChip(grid, 'Def. sg', forms.noun.def_sg || []);
        addChip(grid, 'Indef. pl', forms.noun.indef_pl || []);
        addChip(grid, 'Def. pl', forms.noun.def_pl || []);
        if(grid.childElementCount){
          container.appendChild(grid);
        }
      } else if(posVal && Array.isArray(forms)){
        const grid = document.createElement('div');
        grid.className = 'forms-chip-grid';
        addChip(grid, 'Former', forms);
        if(grid.childElementCount){
          container.appendChild(grid);
        }
      }
    }

    function applyAiPayload(data){
      if(!data) return '';
      let correctionMessage = '';
      if(typeof data.correction === 'string' && data.correction.trim()){
        correctionMessage = data.correction.trim();
      }
      // Try to detect multiword expressions from ordbokene in the original front text.
      const dictExpressionAi = data.focusExpression || (data.ordbokene && data.ordbokene.expression) || '';
      let matchedExpression = '';
      if(Array.isArray(data.expressions) && data.expressions.length && frontInput && frontInput.value){
        const frontLower = frontInput.value.toLowerCase();
        matchedExpression = data.expressions
          .map(e => (e || '').trim())
          .filter(Boolean)
          .filter(e => frontLower.includes(e.toLowerCase()))
          .sort((a,b) => b.length - a.length)[0] || '';
      }
      const expressionResolvedAi = (dictExpressionAi || matchedExpression || '').trim();
      if((data.focusWord || expressionResolvedAi) && fokusInput){
        const posVal = resolvePosFromTag(data.pos || '');
        let focusVal = (expressionResolvedAi || data.focusWord || '').trim();
        if(!expressionResolvedAi){
          if(posVal === 'verb'){
            focusVal = `(??) ${focusVal}`;
          } else if(posVal === 'substantiv' && data.gender){
            focusVal = `(${data.gender}) ${focusVal}`;
          }
        }
        fokusInput.value = focusVal;
        try{
          fokusInput.dispatchEvent(new Event('input', {bubbles:true}));
        }catch(_e){}
      }
      if(data.focusBaseform && focusBaseInput){
        const current = (focusBaseInput.value || '').trim();
        if(!current || /ingen/i.test(current) || data.ordbokene){
          focusBaseInput.value = data.focusBaseform;
        }
      }
      if(data.definition){
        const expl = document.getElementById('uExplanation');
        if(expl && (!expl.value.trim() || data.ordbokene)){
          expl.value = data.definition;
        }
      }
      if(Object.prototype.hasOwnProperty.call(data, 'translation')){
        if(translationInputLocal && (!translationInputLocal.value.trim() || data.ordbokene)){
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
      // Populate forms from ordbank (when present)
      const posVal = resolvePosFromTag(data.pos || '');
      if(posVal === 'verb' && data.forms && data.forms.verb){
        const vf = data.forms.verb;
        const joinForms = val => Array.isArray(val) ? val.join('\n') : (val || '');
        const _vf1=document.getElementById('uVerbInfinitiv'); if(_vf1 && !_vf1.value) _vf1.value = joinForms(vf.infinitiv);
        const _vf2=document.getElementById('uVerbPresens'); if(_vf2 && !_vf2.value) _vf2.value = joinForms(vf.presens);
        const _vf3=document.getElementById('uVerbPreteritum'); if(_vf3 && !_vf3.value) _vf3.value = joinForms(vf.preteritum);
        const _vf4=document.getElementById('uVerbPerfektum'); if(_vf4 && !_vf4.value) _vf4.value = joinForms(vf.perfektum_partisipp);
        const _vf5=document.getElementById('uVerbImperativ'); if(_vf5 && !_vf5.value) _vf5.value = joinForms(vf.imperativ);
      } else if(posVal === 'substantiv' && data.forms && data.forms.noun){
        const nf = data.forms.noun;
        const joinForms = val => Array.isArray(val) ? val.join('\n') : (val || '');
        const _nf1=document.getElementById('uNounIndefSg'); if(_nf1 && !_nf1.value) _nf1.value = joinForms(nf.indef_sg);
        const _nf2=document.getElementById('uNounDefSg'); if(_nf2 && !_nf2.value) _nf2.value = joinForms(nf.def_sg);
        const _nf3=document.getElementById('uNounIndefPl'); if(_nf3 && !_nf3.value) _nf3.value = joinForms(nf.indef_pl);
        const _nf4=document.getElementById('uNounDefPl'); if(_nf4 && !_nf4.value) _nf4.value = joinForms(nf.def_pl);
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
        if(examplesEl){
          // Prefer the latest payload examples (override when ordbokene provided).
          if(!examplesEl.value.trim() || data.ordbokene){
            examplesEl.value = data.examples.join('\n');
          }
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
      renderCompoundParts(data.parts || [], data.focusWord || data.focusBaseform || '');
      renderFormsPreview(data.forms || {}, posVal);
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
      applyFocusMeta(data);
      return correctionMessage;
    }

    async function triggerFocusHelper(token, idx, tokens){
      if(!frontInput || !frontInput.value.trim()){
        setFocusStatus('idle', aiStrings.notext);
        return;
      }
      focusHelperState.activeIndex = idx;
      updateChipActiveState();
      // Always prefill via deterministic ordbank, even when AI is enabled.
      let prefilled = false;
      let prefillData = null;
      try{
        const prefillResp = await triggerOrdbankHelper(token, idx, tokens, {silent:true});
        prefillData = prefillResp;
        prefilled = !!(prefillResp && prefillResp.selected);
      }catch(_prefillErr){
        // best-effort, continue to AI
      }
      if(!aiEnabled){
        if(prefilled){
          if(prefillData){
            applyFocusMeta(prefillData, {silent:false});
          }
          setFocusStatus('success', aiStrings.success || '');
        } else {
          setFocusStatus('disabled', aiStrings.disabled);
        }
        return;
      }
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
        // Fallback to deterministic ordbank lookup when AI is disabled/unavailable.
        if(!prefilled && tokens && Array.isArray(tokens) && tokens.length){
          try{
            await triggerOrdbankHelper(token, idx, tokens);
          }catch(_fallbackErr){
            // Ignore fallback errors; primary error already surfaced.
          }
        }
      }finally{
        if(focusHelperState.abortController === controller){
          focusHelperState.abortController = null;
        }
      }
    }

    async function triggerOrdbankHelper(token, idx, tokens, options = {}){
      if(!frontInput || !token || !token.text){
        return;
      }
      const silent = options && options.silent === true;
      focusHelperState.activeIndex = idx;
      updateChipActiveState();
      if(!silent){
        setFocusStatus('loading', aiStrings.detecting);
      }
      const wordTokens = tokens.filter(t=>t.isWord);
      const prev = wordTokens.filter(t=>t.index < idx).pop();
      const next = wordTokens.filter(t=>t.index > idx).shift();
      const next2 = wordTokens.filter(t=>t.index > idx).slice(0,2)[1];
      try{
        const payload = {
          word: token.text,
          prev: prev ? prev.text : '',
          next: next ? next.text : '',
          next2: next2 ? next2.text : '',
          frontText: frontInput.value || ''
        };
        const resp = await api('ordbank_focus_helper', {}, 'POST', payload);
        if(!resp || resp.ok === false){
          if(!silent){
            const msg = resp && resp.error ? resp.error : (aiStrings.error || 'Error');
            setFocusStatus('error', msg);
            if(resp && resp.debug){
              console.warn('[Flashcards][Ordbokene debug]', resp.debug.ordbokene);
            }
            if(resp && resp.debug){
              console.warn('[Flashcards][Ordbank] debug:', resp.debug);
            }
          }
          return null;
        }
        // API may return {ok:true,data:{...}} or just data; handle both.
        const data = (resp && typeof resp === 'object' && resp.hasOwnProperty('data')) ? resp.data : resp;
        if(data && data.ambiguous && Array.isArray(data.candidates) && !data.selected){
          if(!silent){
            setFocusStatus('error', aiStrings.choose || 'Choose one');
            renderCandidateChoices(data.candidates);
          }
          return data;
        }
        if(!data || !data.selected){
          if(!silent){
            setFocusStatus('error', aiStrings.error || 'Error');
            console.warn('[Flashcards][Ordbank] missing data.selected in response', resp);
          }
          return null;
        }
        if(resp && resp.debug && resp.debug.ordbokene){
          console.info('[Flashcards][Ordbokene debug]', resp.debug.ordbokene);
        }
        // Try to detect multiword expressions
        const dictExpression = data.focusExpression || (data.ordbokene && data.ordbokene.expression) || '';
        let matchedExpression = '';
        if(Array.isArray(data.expressions) && data.expressions.length && frontInput && frontInput.value){
          const frontLower = frontInput.value.toLowerCase();
          matchedExpression = data.expressions
            .map(e => (e || '').trim())
            .filter(Boolean)
            .filter(e => frontLower.includes(e.toLowerCase()))
            .sort((a,b) => b.length - a.length)[0] || '';
        }

        const expressionResolved = (dictExpression || matchedExpression || '').trim();

        const posVal = resolvePosFromTag(data.selected.tag || data.pos || '');
        let focusWordResolved = data.selected.baseform || data.selected.wordform || token.text;
        if(silent){
          return data;
        }
        if(expressionResolved){
          focusWordResolved = expressionResolved;
        } else {
          // prefix infinitive/article
          if(posVal === 'verb'){
            focusWordResolved = `(å) ${focusWordResolved}`;
          } else if(posVal === 'substantiv'){
            const art = data.selected.gender || '';
            if(art){
              focusWordResolved = `(${art}) ${focusWordResolved}`;
            }
          }
        }
        focusWordResolved = (focusWordResolved || '').trim();
        if(fokusInput){
          fokusInput.value = focusWordResolved;
          try{ fokusInput.dispatchEvent(new Event('input', {bubbles:true})); }catch(_e){}
        }
        if(focusBaseInput && (focusBaseInput.value || '').trim() === ''){
          focusBaseInput.value = data.selected.baseform || data.selected.wordform || token.text;
        }
        if(data.selected && data.selected.ipa){
          const trEl = document.getElementById('uTranscription');
          if(trEl && !trEl.value.trim()){
            trEl.value = data.selected.ipa;
          }
        }
        renderCompoundParts(data.parts || [], focusWordResolved);
        renderFormsPreview(data.forms || {}, posVal);
        if(!silent){
          applyFocusMeta(data, {silent:false});
          setFocusStatus('success', aiStrings.success || '');
        }
        return data;
      }catch(err){
        console.error('[Flashcards][Ordbank] focus helper failed', err);
        if(!silent){
          setFocusStatus('error', aiStrings.error || 'Error');
        }
        return null;
      }finally{
        if(!aiEnabled && focusHelperState.abortController){
          focusHelperState.abortController = null;
        }
      }
    }

    function renderCandidateChoices(cands){
      if(!focusStatusEl) return;
      const container = document.createElement('div');
      container.className = 'focus-candidate-list';
      cands.forEach((c, i)=>{
        const btn = document.createElement('button');
        btn.type='button';
        btn.className='focus-chip';
        const base = c.baseform || c.oppslag || c.wordform || c.tag || `#${i+1}`;
        const pos = resolvePosFromTag(c.tag || '');
        btn.textContent = pos ? `${base} (${pos})` : base;
        btn.addEventListener('click', ()=>{
          // simulate selection
          const fakeData = {
            selected: {
              baseform: c.baseform || base,
              wordform: c.wordform || c.oppslag || base,
              tag: c.tag || '',
              ipa: c.ipa || null
            },
            candidates: cands,
            ambiguous: false
          };
          applyOrdbankSelection(fakeData, {text: base});
          setFocusStatus('success', aiStrings.success || '');
          container.remove();
        });
        container.appendChild(btn);
      });
      focusStatusEl.textContent = aiStrings.choose || 'Choose one';
      focusStatusEl.appendChild(container);
    }

    function resolvePosFromTag(tag){
      const t = (tag||'').toLowerCase();
      if(t.includes('verb')) return 'verb';
      if(t.includes('subst')) return 'substantiv';
      if(t.includes('adj')) return 'adjektiv';
      return '';
    }

    function applyOrdbankSelection(data, token){
        const posVal = resolvePosFromTag(data.selected.tag || '');
        let focusWordResolved = data.selected.baseform || data.selected.wordform || (token && token.text) || '';
        if(posVal === 'verb'){
          focusWordResolved = `(å) ${focusWordResolved}`;
        } else if(posVal === 'substantiv'){
          const art = data.selected.gender || '';
          if(art){
            focusWordResolved = `(${art}) ${focusWordResolved}`;
          }
        }
        if(fokusInput){
          fokusInput.value = focusWordResolved;
          try{ fokusInput.dispatchEvent(new Event('input', {bubbles:true})); }catch(_e){}
        }
        if(focusBaseInput && (focusBaseInput.value || '').trim() === ''){
          focusBaseInput.value = data.selected.baseform || data.selected.wordform || '';
        }
        if(data.selected && data.selected.ipa){
          const trEl = document.getElementById('uTranscription');
          if(trEl && !trEl.value.trim()){
            trEl.value = data.selected.ipa;
          }
        }
        renderCompoundParts(data.parts || [], focusWordResolved);
        renderFormsPreview(data.forms || {}, posVal);
        if(posVal === 'verb'){
          const vf = (data.forms && data.forms.verb) || {};
          const joinForms = val => Array.isArray(val) ? val.join('\n') : (val || '');
          const _vf1=document.getElementById('uVerbInfinitiv'); if(_vf1 && !_vf1.value) _vf1.value = joinForms(vf.infinitiv);
          const _vf2=document.getElementById('uVerbPresens'); if(_vf2 && !_vf2.value) _vf2.value = joinForms(vf.presens);
          const _vf3=document.getElementById('uVerbPreteritum'); if(_vf3 && !_vf3.value) _vf3.value = joinForms(vf.preteritum);
          const _vf4=document.getElementById('uVerbPerfektum'); if(_vf4 && !_vf4.value) _vf4.value = joinForms(vf.perfektum_partisipp);
          const _vf5=document.getElementById('uVerbImperativ'); if(_vf5 && !_vf5.value) _vf5.value = joinForms(vf.imperativ);
        } else if(posVal === 'substantiv'){
          const nf = (data.forms && data.forms.noun) || {};
          const joinForms = val => Array.isArray(val) ? val.join('\n') : (val || '');
          const _nf1=document.getElementById('uNounIndefSg'); if(_nf1 && !_nf1.value) _nf1.value = joinForms(nf.indef_sg);
          const _nf2=document.getElementById('uNounDefSg'); if(_nf2 && !_nf2.value) _nf2.value = joinForms(nf.def_sg);
          const _nf3=document.getElementById('uNounIndefPl'); if(_nf3 && !_nf3.value) _nf3.value = joinForms(nf.indef_pl);
          const _nf4=document.getElementById('uNounDefPl'); if(_nf4 && !_nf4.value) _nf4.value = joinForms(nf.def_pl);
        }
        const posField = document.getElementById('uPOS');
        if(posField && posVal){
          posField.value = posVal;
          if(typeof updatePOSHelp === 'function') {
            try{ updatePOSHelp(); }catch(_e){}
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
              // Server data takes priority. Only use existing.order if server has no order at all (null/undefined)
              const finalOrder = Array.isArray(card.order) ? card.order : (Array.isArray(existing.order) ? existing.order : null);
              registry[deckId].cards[existingIdx] = {
                ...existing,
                ...card,
                order: finalOrder
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
      en:{one:'dag', other:'dager'},
      nb:{one:'dag', other:'dager'},
      no:{one:'dag', other:'dager'},
      nn:{one:'dag', other:'dager'},
      ru:{one:'', few:'', many:'', other:''},
      uk:{one:'', few:'', many:'', other:''},
      de:{one:'Tag', other:'Tage'},
      fr:{one:'jour', other:'jours'},
      es:{one:'dia', other:'dias'},
      it:{one:'giorno', other:'giorni'},
      pl:{one:'dzien', few:'dni', many:'dni', other:'dni'}
    };
    const pluralRulesCache = {};
    function getInterfaceLanguage(){
      const html = document.documentElement || null;
      const attrLang = (html && (html.lang || (html.getAttribute ? html.getAttribute('lang') : ''))) || '';
      const preferred = currentInterfaceLang || attrLang;
      const normalized = (preferred || attrLang || '').trim().toLowerCase();
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
      return `+${days} ${word}`;
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
    let pendingShowMoreScroll=false;
    let studyBottomActions=null;
    let studyMetaPanel=null;
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
    // Strip STT stage directions like "(utandning)" or "(3 sekunders pause)" before inserting text.
    function sanitizeTranscription(text){
      if(!text) return '';
      let cleaned = (text || '').trim();
      const stagePatterns = [
        /\(\s*(?:utandning|inn?andning|pust|exhale|inhale|breath(?:ing)?|sighs?|laughter|laughs?|hoste?r?|coughs?|pause|paus|sekund(?:er)?\s*pause|second(?:s)?\s*pause|bakgrunns?(?:stoy|stoey)|background\s+noise|st(?:oy|stoey)|noise|musikk|music)[^)]*\)\s*/gi,
        /\[\s*(?:utandning|inn?andning|pust|exhale|inhale|breath(?:ing)?|sighs?|laughter|laughs?|hoste?r?|coughs?|pause|paus|sekund(?:er)?\s*pause|second(?:s)?\s*pause|bakgrunns?(?:stoy|stoey)|background\s+noise|st(?:oy|stoey)|noise|musikk|music)[^\]]*\]\s*/gi,
        /\(\s*\d+\s*(?:sekund(?:er)?|second(?:s)?)(?:\s*pause)?\s*\)\s*/gi
      ];
      stagePatterns.forEach(re=>{ cleaned = cleaned.replace(re, ' '); });
      cleaned = cleaned.replace(/\([^)]*\)\s*/g, ' '); // drop any remaining parenthetical notes
      cleaned = cleaned.replace(/\s{2,}/g,' ').trim();
      cleaned = cleaned.replace(/^[-.,;:]+\s*/, '');
      return cleaned || (text || '').trim();
    }
    function applyTranscriptionResult(text){
      const result = insertFrontText(sanitizeTranscription(text));
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
      const resultEl = exerciseEl.querySelector('.dictation-result');
      const correctAnswerEl = exerciseEl.querySelector('.dictation-correct-answer');

      if(!inputEl || !checkBtn || !resultEl || !correctAnswerEl) return;

      const correctText = inputEl.dataset.correctText || card.text;
      let audioPlayed = false;
      let checkCount = 0;

      // Check button - verify user input and show visual feedback
      checkBtn.addEventListener('click', () => {
        const userInput = inputEl.value.trim();

        if(!userInput){
          resultEl.innerHTML = '<div class="dictation-error-msg">?? ' + (aiStrings.dictationEmptyInput || ' ') + '</div>';
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
          checkBtn.style.display = 'none'; // Hide the button completely
        } else if(checkCount >= 2){
          // After 2 attempts, show hint
          inputEl.placeholder = aiStrings.dictationHint || '   ';
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
    // Compare dictation answers with move-aware logic
    function compareTexts(userInput, correctText){
      const userNorm = (userInput || '').trim();
      const correctNorm = (correctText || '').trim();

      const userTokens = tokenizeText(userNorm);
      const originalTokens = tokenizeText(correctNorm);

      const emptyPlan = buildEmptyMovePlan(userTokens.length);

      if(userNorm === correctNorm){
        return {
          isCorrect: true,
          mode: 'arrows',
          userTokens,
          originalTokens,
          matches: [],
          extras: [],
          missing: [],
          movePlan: emptyPlan,
          errorCount: 0,
          orderIssues: 0,
          moveIssues: 0
        };
      }

      const similarity = buildSimilarityMatrix(userTokens, originalTokens);
      const assignment = solveMaxAssignment(similarity);
      const { matches, matchedUser, matchedOrig } = extractMatches(assignment, userTokens, originalTokens);

      const extras = userTokens
        .map((token, index) => matchedUser.has(index) ? null : { userIndex: index, token })
        .filter(Boolean);

      const missing = originalTokens
        .map((token, index) => matchedOrig.has(index) ? null : { origIndex: index, token })
        .filter(Boolean);

      // Compute LIS directly from matches (by origIndex order)
      const lisIndices = computeLisWithTies(matches);
      const lisSet = new Set(lisIndices.map(idx => matches[idx]?.id).filter(Boolean));

      const movePlan = buildMovePlan({
        matches,
        lisSet,
        userTokens,
        originalTokens,
        missing
      });

      // Count only real spelling/punctuation mismatches: for words, raw diff or low score; for punct, only raw diff (not score bias < 1)
      const spellingIssues = matches.filter(m => {
        const rawDiffers = m.userToken.raw !== m.origToken.raw;
        if(m.userToken.type === 'punct'){
          return rawDiffers;
        }
        return rawDiffers || m.score < 1;
      }).length;
      const orderIssues = matches.filter(m => !lisSet.has(m.id)).length;
      const moveIssues = movePlan.mode === 'rewrite'
        ? (movePlan.rewriteGroups.length ? 1 : 0)
        : movePlan.moveBlocks.length;
      const errorCount = extras.length + missing.length + spellingIssues + moveIssues;
      const isCorrect = errorCount === 0;

      return {
        isCorrect,
        mode: movePlan.mode,
        userTokens,
        originalTokens,
        matches,
        extras,
        missing,
        movePlan,
        errorCount,
        orderIssues,
        moveIssues,
        crossedBoundaries: movePlan.crossedBoundaries || [],
        overloadedBoundaries: movePlan.overloadedBoundaries || [],
        boundaryCounts: movePlan.boundaryCounts || []
      };
    }

    const MIN_SIMILARITY_SCORE = 0.35;
    const STEM_SIMILARITY_SCORE = 0.85;
    const STRONG_ANCHOR_SCORE = 5;
    const PUNCT_MATCH_SCORE = 0.8;
    const PUNCT_MISMATCH_SCORE = 0.2;
    const TYPE_MISMATCH_SCORE = 0.05;
    const GAP_PENALTY = 1.0;

    function buildEmptyMovePlan(len){
      return {
        mode: 'arrows',
        moveBlocks: [],
        rewriteGroups: [],
        tokenMeta: Array(len).fill(null),
        gapMeta: {},
        gapsNeeded: new Set(),
        missingByPosition: {}
      };
    }

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
        '\u2013': '-',
        '\u2014': '-',
        '\u2011': '-',
        '\u2026': '...',
        '\u00ab': '\"',
        '\u00bb': '\"',
        '\u201c': '\"',
        '\u201d': '\"',
        '\u201e': '\"',
        '\u2019': '\'',
        '\u2018': '\'',
        '`': '\'',
        '\u00b4': '\''
      };
      return map[char] || char;
    }

    function simpleStem(value){
      if(!value) return '';
      const normalized = value.toLowerCase().replace(/^[^\p{L}\p{M}]+|[^\p{L}\p{M}]+$/gu, '');
      return normalized.replace(/(ene|ane|het|ers|ene|er|en|et|e)$/u, '');
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
      if(a.type !== b.type){
        return TYPE_MISMATCH_SCORE;
      }
      if(a.type === 'punct'){
        const normA = normalizePunctuation(a.raw);
        const normB = normalizePunctuation(b.raw);
        return normA === normB ? PUNCT_MATCH_SCORE : PUNCT_MISMATCH_SCORE;
      }
      if(a.norm === b.norm){
        return STRONG_ANCHOR_SCORE;
      }
      const stemA = simpleStem(a.norm);
      const stemB = simpleStem(b.norm);
      if(stemA && stemA === stemB){
        return STEM_SIMILARITY_SCORE;
      }
      const distance = levenshtein(a.norm, b.norm);
      const maxLen = Math.max(a.norm.length, b.norm.length) || 1;
      const closeness = Math.max(0, 1 - (distance / maxLen));
      if(closeness >= 0.8) return 0.6 + (closeness * 0.2);
      if(closeness >= 0.6) return 0.5 + (closeness * 0.1);
      return closeness * 0.5;
    }

    function buildSimilarityMatrix(userTokens, originalTokens){
      const matrix = [];
      const maxLen = Math.max(userTokens.length, originalTokens.length, 1);
      for(let i = 0; i < userTokens.length; i++){
        matrix[i] = [];
        for(let j = 0; j < originalTokens.length; j++){
          const base = tokenSimilarity(userTokens[i], originalTokens[j]);
          const dist = Math.abs(i - j) / maxLen;
          const proximityBonus = (1 - dist) * 0.35;
          const distancePenalty = dist * 0.25;
          const weight = base + proximityBonus - distancePenalty;
          matrix[i][j] = weight;
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

    function extractMatches(assignment, userTokens, originalTokens){
      const matches = [];
      const matchedUser = new Set();
      const matchedOrig = new Set();
      assignment.forEach((item, idx) => {
        if(!item) return;
        const { row, col, weight } = item;
        if(row >= userTokens.length || col >= originalTokens.length) return;
        if(weight < MIN_SIMILARITY_SCORE) return;
        matches.push({
          id: idx,
          userIndex: row,
          origIndex: col,
          userToken: userTokens[row],
          origToken: originalTokens[col],
          score: weight
        });
        matchedUser.add(row);
        matchedOrig.add(col);
      });
      matches.sort((a,b)=> a.userIndex - b.userIndex);
      matches.forEach((m, index)=>{ m.id = index; });
      return { matches, matchedUser, matchedOrig };
    }

    function computeLisWithTies(matches){
      if(!matches.length) return [];
      const values = matches.map(m => m.origIndex);
      const dp = [];
      const prev = Array(values.length).fill(-1);
      for(let i = 0; i < values.length; i++){
        dp[i] = { len: 1, breaks: 0, start: i };
        for(let j = 0; j < i; j++){
          if(values[j] < values[i]){
            const candidate = {
              len: dp[j].len + 1,
              breaks: dp[j].breaks + (values[i] === values[j] + 1 ? 0 : 1),
              start: dp[j].start
            };
            if(isBetterLis(candidate, dp[i])){
              dp[i] = candidate;
              prev[i] = j;
            }
          }
        }
      }
      let bestIdx = 0;
      for(let i = 1; i < dp.length; i++){
        if(isBetterLis(dp[i], dp[bestIdx])){
          bestIdx = i;
        }
      }
      const result = [];
      let k = bestIdx;
      while(k !== -1){
        result.push(k);
        k = prev[k];
      }
      return result.reverse();
    }

    function isBetterLis(a, b){
      if(!b) return true;
      if(a.len !== b.len) return a.len > b.len;
      if(a.breaks !== b.breaks) return a.breaks < b.breaks;
      return a.start < b.start;
    }

    // Monotone alignment (NeedlemanWunsch) to derive non-crossing anchors
    function monotoneAlignment(userTokens, originalTokens){
      const n = userTokens.length;
      const m = originalTokens.length;
      const dp = Array.from({length: n + 1}, () => Array(m + 1).fill(-Infinity));
      const dir = Array.from({length: n + 1}, () => Array(m + 1).fill(0)); // 0=diag,1=up,2=left
      dp[0][0] = 0;
      for(let i = 1; i <= n; i++){
        dp[i][0] = dp[i-1][0] - GAP_PENALTY;
        dir[i][0] = 1;
      }
      for(let j = 1; j <= m; j++){
        dp[0][j] = dp[0][j-1] - GAP_PENALTY;
        dir[0][j] = 2;
      }
      for(let i = 1; i <= n; i++){
        for(let j = 1; j <= m; j++){
          const sim = tokenSimilarity(userTokens[i-1], originalTokens[j-1]);
          const diag = dp[i-1][j-1] + sim;
          const up = dp[i-1][j] - GAP_PENALTY;
          const left = dp[i][j-1] - GAP_PENALTY;
          const best = Math.max(diag, up, left);
          dp[i][j] = best;
          if(best === diag){
            dir[i][j] = 0;
          } else if(best === up){
            dir[i][j] = 1;
          } else {
            dir[i][j] = 2;
          }
        }
      }
      const matches = [];
      let i = n, j = m;
      while(i > 0 && j > 0){
        const d = dir[i][j];
        if(d === 0){
          const sim = tokenSimilarity(userTokens[i-1], originalTokens[j-1]);
          if(sim >= MIN_SIMILARITY_SCORE){
            matches.push({
              userIndex: i - 1,
              origIndex: j - 1,
              score: sim
            });
          }
          i--; j--;
        } else if(d === 1){
          i--;
        } else {
          j--;
        }
      }
      return matches.reverse();
    }

    function buildGapKey(before, after){
      const left = before === undefined ? -1 : before;
      const right = after === null || after === undefined ? 'END' : after;
      return `${left}-${right}`;
    }

    function buildMovePlan({ matches, lisSet, userTokens, originalTokens, missing }){ // eslint-disable-line max-params
      const missingTokens = Array.isArray(missing) ? missing : [];
      const orderedMatches = [...matches];
      const lisMatches = orderedMatches.filter(m=>lisSet.has(m.id));
      const lisOrigSorted = lisMatches.map(m=>m.origIndex).sort((a,b)=>a-b);
      const lisOrigToUser = new Map();
      lisMatches.forEach(m=> lisOrigToUser.set(m.origIndex, m.userIndex));

      console.log('[DEBUG] LIS tokens:', lisMatches.map(m => ({
        origIndex: m.origIndex,
        userIndex: m.userIndex,
        origToken: m.origToken.raw,
        userToken: m.userToken.raw
      })));
      console.log('[DEBUG] All matches:', orderedMatches.map(m => ({
        origIndex: m.origIndex,
        userIndex: m.userIndex,
        origToken: m.origToken.raw,
        userToken: m.userToken.raw,
        inLIS: lisSet.has(m.id)
      })));

      // Create a map of ALL matched words (not just LIS) for better gap calculation
      const allOrigToUser = new Map();
      orderedMatches.forEach(m => allOrigToUser.set(m.origIndex, m.userIndex));

      console.log('[DEBUG] LIS original indices (stay in place):', lisOrigSorted);

      const metaByUser = Array(userTokens.length).fill(null);
      const gapMeta = {};
      const tokensPerGap = {}; // Track which tokens belong to each gap

      orderedMatches.forEach((m)=>{
        const inLis = lisSet.has(m.id);

        // For both gap and targetBoundary calculation, use LIS tokens (stable positions)
        // Find the nearest LIS tokens to determine where this token should go
        let prevLIS = -1;
        let nextLIS = null;
        for(const idx of lisOrigSorted){
          if(idx < m.origIndex){
            prevLIS = idx;
          } else if(idx > m.origIndex){
            nextLIS = idx;
            break;
          }
        }

        // Use LIS-based gap key so tokens between same LIS anchors share the same gap
        const gapKey = buildGapKey(prevLIS, nextLIS);

        // Calculate beforeUser and afterUser based on LIS positions (stable anchors)
        let beforeUser = -1;
        if(prevLIS !== -1){
          beforeUser = lisOrigToUser.get(prevLIS) ?? -1;
        }

        let afterUser = userTokens.length;
        if(nextLIS !== null){
          afterUser = lisOrigToUser.get(nextLIS) ?? userTokens.length;
        }

        if(!gapMeta[gapKey]){
          gapMeta[gapKey] = {
            before: prevLIS,
            after: nextLIS,
            beforeUser,
            afterUser,
            targetBoundary: beforeUser + 1
          };
          tokensPerGap[gapKey] = [];
          console.log(`[DEBUG] Created gap ${gapKey}: before=${prevLIS}, after=${nextLIS}, beforeUser=${beforeUser}, afterUser=${afterUser}, targetBoundary=${beforeUser + 1}`);
        }

        // Track which tokens go into this gap (for calculating offset)
        if(!inLis){
          tokensPerGap[gapKey].push(m.origIndex);
        }

        const hasError = m.score < 1 || m.userToken.raw !== m.origToken.raw;
        metaByUser[m.userIndex] = {
          match: m,
          inLis,
          targetGapKey: gapKey,
          targetGap: { before: prevLIS, after: nextLIS },
          hasError,
          needsMove: !inLis
        };
      });

      // Sort tokens in each gap by their original index to assign correct offsets
      Object.keys(tokensPerGap).forEach(gapKey => {
        tokensPerGap[gapKey].sort((a, b) => a - b);
      });

      const movableMatches = orderedMatches.filter(m=>!lisSet.has(m.id));
      const moveBlocks = buildMoveBlocks(movableMatches, metaByUser, gapMeta, tokensPerGap, userTokens.length);

      const boundaryCounts = Array(userTokens.length + 1).fill(0);
      moveBlocks.forEach(block=>{
        const crossed = collectCrossedBoundaries(block);
        block.crossed = crossed;
        crossed.forEach(idx=>{
          boundaryCounts[idx] = (boundaryCounts[idx] || 0) + 1;
        });
      });
      const overloadedBoundaries = new Set(boundaryCounts.map((count, idx)=> ({count, idx})).filter(item=>item.count > 1).map(item=>item.idx));
      const overloadBlocks = new Set();
      moveBlocks.forEach(block=>{
        const crossed = block.crossed || collectCrossedBoundaries(block);
        if(crossed.some(idx=>overloadedBoundaries.has(idx))){
          overloadBlocks.add(block.id);
        }
      });
      // Use rewrite when:
      // 1. A boundary is overloaded (2+ blocks through same boundary), OR
      // 2. Too many errors (>60% of tokens need correction)
      const crossingBlocks = new Set(); // crossings no longer trigger rewrite alone
      const totalTokens = userTokens.length;
      const errorTokenCount = movableMatches.length + missingTokens.length;
      const errorRate = totalTokens > 0 ? errorTokenCount / totalTokens : 0;
      const tooManyErrors = errorRate > 0.6;

      console.log(`[DEBUG] Error rate: ${errorTokenCount}/${totalTokens} = ${(errorRate * 100).toFixed(1)}%, tooManyErrors=${tooManyErrors}`);

      const mode = (overloadedBoundaries.size || tooManyErrors) ? 'rewrite' : 'arrows';
      // When tooManyErrors=true, include ALL moveBlocks in problemIds (even filtered out ones)
      // Use allBlocksBeforeFilter to get complete list of blocks
      const allBlocks = moveBlocks.allBlocksBeforeFilter || moveBlocks;
      const problemIds = tooManyErrors
        ? new Set(allBlocks.map(b => b.id))
        : new Set([...overloadBlocks]);

      console.log(`[DEBUG] Mode=${mode}, problemIds=${Array.from(problemIds).join(', ')}, overloadedBoundaries=${overloadedBoundaries.size}, allBlocks=${allBlocks.length}, filteredBlocks=${moveBlocks.length}`);
      // When tooManyErrors=true, pass allBlocks to buildRewriteGroups so it can find ALL problematic tokens
      const rewriteGroups = mode === 'rewrite' ? buildRewriteGroups({
        moveBlocks: tooManyErrors ? allBlocks : moveBlocks,
        problemIds,
        matches: orderedMatches,
        userTokens,
        originalTokens,
        metaByUser,
        missingTokens
      }) : [];

      // Create a set of token indices that are in moveBlocks
      const tokensInMoveBlocks = new Set();
      moveBlocks.forEach(block=>{
        if(block.resolvedByRewrite) return;
        block.tokens.forEach(idx=>{
          tokensInMoveBlocks.add(idx);
          if(!metaByUser[idx]) metaByUser[idx] = {};
          metaByUser[idx].moveBlockId = block.id;
          metaByUser[idx].targetGapKey = block.targetGapKey;
          metaByUser[idx].targetGap = block.targetGap;
        });
      });

      // Clear needsMove for tokens that were filtered out (not in any move block)
      metaByUser.forEach((meta, idx) => {
        if(meta && meta.needsMove && !tokensInMoveBlocks.has(idx)){
          meta.needsMove = false;
        }
      });

      const gapsNeeded = new Set();
      if(mode === 'arrows'){
        moveBlocks.forEach(block=> gapsNeeded.add(block.targetGapKey));
      }

      const matchedByOrig = new Map();
      orderedMatches.forEach(m=> matchedByOrig.set(m.origIndex, m.userIndex));
      const missingByPosition = buildMissingByPosition(missingTokens, orderedMatches, userTokens.length);

      // CRITICAL: For missing tokens, we need to find gaps based on LIS tokens only
      // This ensures missing tokens share gaps with moveBlocks correctly
      missingTokens.forEach(item=>{
        // Find where this missing token should appear in the ORIGINAL order
        // by finding its neighbors in LIS (tokens that stay in place)
        let prevOrig = -1;
        let nextOrig = null;

        // Find neighbors in LIS ONLY (tokens that stay in place)
        // If no LIS neighbors found, prev=-1 or next=null means gap extends to start/end
        for(let i = item.origIndex - 1; i >= 0; i--){
          // Check if this original position is in LIS (stays in place)
          if(lisOrigToUser.has(i)){
            prevOrig = i;
            break;
          }
        }
        for(let i = item.origIndex + 1; i < originalTokens.length; i++){
          if(lisOrigToUser.has(i)){
            nextOrig = i;
            break;
          }
        }

        // Now find where these neighbors are in the USER sequence
        let prevUser = prevOrig !== -1 ? (lisOrigToUser.get(prevOrig) ?? matchedByOrig.get(prevOrig)) : -1;
        let nextUser = nextOrig !== null ? (lisOrigToUser.get(nextOrig) ?? matchedByOrig.get(nextOrig)) : userTokens.length;

        const gapKey = buildGapKey(prevOrig, nextOrig);
        const beforeUser = prevUser;
        const afterUser = nextUser;

        // CRITICAL: Check if there's already a gap with this key (from moveBlocks)
        // If so, reuse it. Missing tokens share gaps with moveBlocks.
        if(!gapMeta[gapKey]){
          gapMeta[gapKey] = {
            before: prevOrig,
            after: nextOrig,
            beforeUser,
            afterUser,
            targetBoundary: beforeUser + 1
          };
          console.log(`[DEBUG] Created NEW gap for missing token ${item.token.raw} (orig_${item.origIndex}): gap=${gapKey}, prevOrig=${prevOrig}, nextOrig=${nextOrig}, beforeUser=${beforeUser}, afterUser=${afterUser}, targetBoundary=${beforeUser + 1}`);
        } else {
          console.log(`[DEBUG] Missing token ${item.token.raw} (orig_${item.origIndex}) SHARES gap ${gapKey} (prevOrig=${prevOrig}, nextOrig=${nextOrig}) with existing element (boundary=${gapMeta[gapKey].targetBoundary})`);
        }
        // CRITICAL: Save the correct gapKey to the missing token object
        item.gapKey = gapKey;
        gapsNeeded.add(gapKey);
      });

      const crossedBoundaries = boundaryCounts
        .map((count, idx)=>({boundary: idx, count}))
        .filter(item=>item.count > 0);

      return {
        mode,
        moveBlocks,
        rewriteGroups,
        tokenMeta: metaByUser,
        gapMeta,
        gapsNeeded,
        missingByPosition,
        missingTokens, // CRITICAL: Return missingTokens with correct gapKey
        boundaryCounts,
        crossedBoundaries,
        overloadedBoundaries: Array.from(overloadedBoundaries)
      };
    }

    function buildMoveBlocks(movableMatches, metaByUser, gapMeta, tokensPerGap, userLength){
      const blocks = [];
      let current = null;
      movableMatches.forEach(m=>{
        const meta = metaByUser[m.userIndex] || {};
        const gapKey = meta.targetGapKey || buildGapKey(-1, null);
        const gap = gapMeta[gapKey] || { before: -1, after: null, beforeUser: -1, afterUser: userLength, targetBoundary: 0 };

        // Calculate offset within the gap based on original position
        const tokensInThisGap = tokensPerGap[gapKey] || [];
        const offsetInGap = tokensInThisGap.indexOf(m.origIndex);
        const targetBoundary = (gap.beforeUser ?? -1) + 1 + offsetInGap;

        const lastOrig = current && current.origIndices.length ? current.origIndices[current.origIndices.length - 1] : -Infinity;
        const expectedNextOrig = current ? lastOrig + 1 : null;
        const adjacent = current
          && current.targetGapKey === gapKey
          && m.userIndex === current.end + 1
          && m.origIndex === expectedNextOrig; // merge only if user-adjacent AND orig-adjacent
        if(adjacent){
          current.tokens.push(m.userIndex);
          current.origIndices.push(m.origIndex);
          current.end = m.userIndex;
          current.hasError = current.hasError || !!meta.hasError;
        } else {
          if(current){
            blocks.push(current);
          }
          current = {
            id: `move-${blocks.length + 1}`,
            tokens: [m.userIndex],
            origIndices: [m.origIndex],
            start: m.userIndex,
            end: m.userIndex,
            targetGapKey: gapKey,
            targetGap: meta.targetGap || { before: -1, after: null },
            beforeUser: gap.beforeUser ?? -1,
            afterUser: gap.afterUser ?? userLength,
            targetBoundary: targetBoundary,
            hasError: !!meta.hasError,
            resolvedByRewrite: false
          };
        }
      });
      if(current){
        blocks.push(current);
      }

      console.log('[DEBUG] All moveBlocks created:', blocks.map(b => ({
        id: b.id,
        tokens: b.tokens,
        start: b.start,
        end: b.end,
        targetBoundary: b.targetBoundary,
        beforeUser: b.beforeUser,
        afterUser: b.afterUser,
        targetGapKey: b.targetGapKey
      })));

      // Filter out blocks that don't actually need to move
      // A block doesn't need to move if it's already in the target position
      const filtered = blocks.filter(block => {
        // Check if the block is already in the correct position
        // Block is at correct position if targetBoundary is between start and end+1
        const alreadyInPlace = block.targetBoundary >= block.start && block.targetBoundary <= block.end + 1;
        if(alreadyInPlace){
          console.log('[DEBUG] Filtering out block:', block.id, 'tokens:', block.tokens, 'targetBoundary:', block.targetBoundary, 'start:', block.start, 'end:', block.end);
        }
        return !alreadyInPlace;
      });
      console.log('[DEBUG] Blocks before filter:', blocks.length, 'after filter:', filtered.length);
      console.log('[DEBUG] Filtered blocks:', filtered.map(b => ({
        id: b.id,
        tokens: b.tokens,
        start: b.start,
        end: b.end,
        targetBoundary: b.targetBoundary
      })));

      // Store all blocks (before filtering) for error rate calculation
      filtered.allBlocksBeforeFilter = blocks;

      return filtered;
    }

    function collectCrossedBoundaries(block){
      const crossed = [];
      if(block.targetBoundary < block.start){
        for(let b = block.targetBoundary; b < block.start; b++){
          crossed.push(b);
        }
      } else if(block.targetBoundary > block.end + 1){
        for(let b = block.end + 1; b < block.targetBoundary; b++){
          crossed.push(b);
        }
      }
      return crossed;
    }

    function detectCrossingBlocks(blocks){
      const set = new Set();
      for(let i = 0; i < blocks.length; i++){
        for(let j = i + 1; j < blocks.length; j++){
          const a = blocks[i];
          const b = blocks[j];
          const orderInverts = (a.start < b.start && a.targetBoundary > b.targetBoundary) ||
                               (a.start > b.start && a.targetBoundary < b.targetBoundary);
          const targetInside = (a.targetBoundary > b.start && a.targetBoundary < b.end + 1) ||
                               (b.targetBoundary > a.start && b.targetBoundary < a.end + 1);
          if(orderInverts || targetInside){
            set.add(a.id);
            set.add(b.id);
          }
        }
      }
      return set;
    }

    function buildRewriteGroups({ moveBlocks, problemIds, matches, userTokens, originalTokens, metaByUser, missingTokens = [] }){ // eslint-disable-line max-params
      if(!problemIds.size || !moveBlocks.length){
        return [];
      }
      const problemBlocks = moveBlocks.filter(b=>problemIds.has(b.id));
      console.log(`[DEBUG] buildRewriteGroups: moveBlocks=${moveBlocks.length}, problemIds=${Array.from(problemIds).join(',')}, problemBlocks=${problemBlocks.length}`);
      console.log(`[DEBUG] problemBlocks origIndices:`, problemBlocks.map(b => `${b.id}:[${b.origIndices.join(',')}]`).join(' '));
      if(!problemBlocks.length){
        return [];
      }
      // Start with coverage based on sources/targets to include all crossed boundaries
      let coverUserMin = Infinity;
      let coverUserMax = -Infinity;
      problemBlocks.forEach(block=>{
        const coverStart = Math.min(block.start, block.targetBoundary);
        const coverEnd = Math.max(block.end, block.targetBoundary - 1);
        coverUserMin = Math.min(coverUserMin, coverStart);
        coverUserMax = Math.max(coverUserMax, coverEnd);
      });
      let origMin = Infinity;
      let origMax = -Infinity;
      problemBlocks.forEach(block=>{
        block.origIndices.forEach(idx=>{
          origMin = Math.min(origMin, idx);
          origMax = Math.max(origMax, idx);
        });
      });

      // CRITICAL: Include missing tokens in the initial range calculation
      // This ensures that missing tokens between problemBlocks are included in the rewrite group
      if(missingTokens && missingTokens.length > 0){
        console.log(`[DEBUG] Checking ${missingTokens.length} missing tokens for range expansion`);
        missingTokens.forEach(item => {
          // Temporarily expand range to see if this missing token is "near" the problem area
          const nearProblemArea = item.origIndex >= origMin - 1 && item.origIndex <= origMax + 1;
          if(nearProblemArea){
            console.log(`[DEBUG] Including missing token ${item.token.raw} (orig_${item.origIndex}) in rewrite range`);
            origMin = Math.min(origMin, item.origIndex);
            origMax = Math.max(origMax, item.origIndex);
          }
        });
      }

      if(!Number.isFinite(origMin) || !Number.isFinite(origMax)){
        return [];
      }
      let userMin = Number.isFinite(coverUserMin) ? coverUserMin : Infinity;
      let userMax = Number.isFinite(coverUserMax) ? coverUserMax : -Infinity;
      matches.forEach(m=>{
        if(m.origIndex >= origMin && m.origIndex <= origMax){
          userMin = Math.min(userMin, m.userIndex);
          userMax = Math.max(userMax, m.userIndex);
        }
      });
      if(!Number.isFinite(userMin)){
        userMin = 0;
        userMax = userTokens.length ? userTokens.length - 1 : 0;
      }
      matches.forEach(m=>{
        if(m.userIndex >= userMin && m.userIndex <= userMax){
          origMin = Math.min(origMin, m.origIndex);
          origMax = Math.max(origMax, m.origIndex);
        }
      });
      // Expand student span again to ensure we cover all tokens mapping to expanded orig span
      matches.forEach(m=>{
        if(m.origIndex >= origMin && m.origIndex <= origMax){
          userMin = Math.min(userMin, m.userIndex);
          userMax = Math.max(userMax, m.userIndex);
        }
      });
      const correctTokens = originalTokens.filter(t=>t.index >= origMin && t.index <= origMax);
      const correctText = correctTokens.map(t=>t.raw).join(' ');
      const group = {
        id: 'rewrite-1',
        start: userMin,
        end: userMax,
        origMin,
        origMax,
        targetGapKey: buildGapKey(origMin - 1, origMax + 1),
        correctText
      };
      console.log(`[DEBUG] Created rewrite group: origMin=${origMin}, origMax=${origMax}, userMin=${userMin}, userMax=${userMax}, correctText="${correctText}"`);
      for(let i = group.start; i <= group.end; i++){
        metaByUser[i] = metaByUser[i] || {};
        metaByUser[i].rewriteGroupId = group.id;
        metaByUser[i].targetGapKey = group.targetGapKey;
      }
      moveBlocks.forEach(block=>{
        if(problemIds.has(block.id)){
          block.resolvedByRewrite = true;
        }
      });
      return [group];
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
        const gapKey = buildGapKey(prevOrig, nextOrig);
        const pos = Math.max(0, prevUser + 1);
        if(!map[pos]){
          map[pos] = [];
        }
        map[pos].push({ token: item.token, gapKey });
      });
      Object.keys(map).forEach(key=>{
        map[key] = map[key].sort((a,b)=>a.token.index - b.token.index);
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
        success.textContent = 'Perfect! Well done!';
        wrapper.appendChild(success);
        resultEl.appendChild(wrapper);
        return;
      }

      const errorCount = comparison.errorCount || 0;
      const errorWord = errorCount === 1 ? 'error' : 'errors';
      const summary = document.createElement('div');
      summary.className = 'dictation-errors-summary';
      summary.textContent = `Found ${errorCount} ${errorWord}`;
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
      label.textContent = 'Your answer:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-user';

      const gapsNeeded = comparison.movePlan.gapsNeeded || new Set();
      const gapMeta = comparison.movePlan.gapMeta || {};
      const missingTokens = comparison.movePlan.missingTokens || [];
      const rewriteGroups = comparison.movePlan.rewriteGroups || [];

      // Filter out missing tokens that are inside rewrite groups
      // They should not be inserted separately as they're already handled in the rewrite block
      const rewriteOrigRanges = rewriteGroups.map(g => ({
        origMin: g.origMin,
        origMax: g.origMax
      }));

      const filteredMissingTokens = missingTokens.filter(item => {
        // Check if this missing token is inside any rewrite group
        const insideRewrite = rewriteOrigRanges.some(range =>
          item.origIndex >= range.origMin && item.origIndex <= range.origMax
        );
        if(insideRewrite){
          console.log(`[DEBUG] Excluding missing token ${item.token.raw} (orig_${item.origIndex}) - inside rewrite group`);
        }
        return !insideRewrite;
      });

      // Build missingByGapKey map using the CORRECT gapKey from filteredMissingTokens
      const missingByGapKey = new Map();
      filteredMissingTokens.forEach(item => {
        const gapKey = item.gapKey; // Use the corrected gapKey from planMovesAndGaps
        if(!gapKey){
          console.warn('[WARN] Missing token without gapKey:', item);
          return;
        }
        if(!missingByGapKey.has(gapKey)){
          missingByGapKey.set(gapKey, []);
        }
        missingByGapKey.get(gapKey).push(item);
      });

      // Calculate adjusted boundaries accounting for moveBlocks
      // moveBlocks will be skipped, so gaps after them need to shift left
      const moveBlockIndices = new Set();
      comparison.movePlan.moveBlocks.forEach(block => {
        for(let i = block.start; i <= block.end; i++){
          moveBlockIndices.add(i);
        }
      });

      const gapsByBoundary = new Map();
      // Use actual moveBlocks' targetBoundary instead of gapMeta baseline
      comparison.movePlan.moveBlocks.forEach(block => {
        const key = block.targetGapKey;
        if(!gapsNeeded.has(key)) return;

        let boundary = block.targetBoundary;

        // Adjust boundary: subtract number of moveBlock tokens before this boundary
        let adjustment = 0;
        for(let i = 0; i < boundary; i++){
          if(moveBlockIndices.has(i)){
            adjustment++;
          }
        }
        boundary -= adjustment;

        const clamped = Math.max(0, Math.min(boundary, comparison.userTokens.length));
        console.log(`[DEBUG] Gap ${key}: original boundary=${block.targetBoundary}, adjustment=${adjustment}, final boundary=${boundary}`);
        if(!gapsByBoundary.has(clamped)){
          gapsByBoundary.set(clamped, []);
        }
        // Store gap only once per boundary (avoid duplicates)
        if(!gapsByBoundary.get(clamped).includes(key)){
          gapsByBoundary.get(clamped).push(key);
        }
      });

      // Helper function to check if token is punctuation
      const isPunctuation = (text) => {
        return /^[.,!?;:—–\-…]$/.test(text.trim());
      };

      const insertAnchors = (boundaryIdx)=>{
        const list = gapsByBoundary.get(boundaryIdx) || [];
        console.log(`[DEBUG] insertAnchors(${boundaryIdx}): gaps=${list.length > 0 ? list.join(', ') : 'none'}`);
        list.forEach(key=>{
          // Get all missing tokens for this gap
          const missingForGap = missingByGapKey.get(key) || [];

          // Find all moveBlocks that target this gap to determine their origIndex
          const moveBlocksForGap = comparison.movePlan.moveBlocks.filter(b => b.targetGapKey === key);
          const moveBlockOrigIndices = moveBlocksForGap.flatMap(b => b.origIndices || []);

          // Find min origIndex among moveBlocks (anchor represents where they'll go)
          const minMoveBlockOrigIndex = moveBlockOrigIndices.length > 0 ? Math.min(...moveBlockOrigIndices) : Infinity;

          // Sort missing tokens by origIndex
          const sortedMissing = [...missingForGap].sort((a, b) => a.origIndex - b.origIndex);

          // Split missing tokens into BEFORE and AFTER anchor based on origIndex
          const missingBefore = sortedMissing.filter(m => m.origIndex < minMoveBlockOrigIndex);
          const missingAfter = sortedMissing.filter(m => m.origIndex >= minMoveBlockOrigIndex);

          console.log(`[DEBUG] Gap ${key} at boundary ${boundaryIdx}: moveBlock origIndices=${moveBlockOrigIndices.join(',')}, missing before=${missingBefore.map(m => `${m.token.raw}(${m.origIndex})`).join(', ')}, missing after=${missingAfter.map(m => `${m.token.raw}(${m.origIndex})`).join(', ')}`);

          // If there are no moveBlocks for this gap, we cannot determine correct position
          // These missing tokens will be shown inline with user tokens instead
          if(moveBlockOrigIndices.length === 0){
            console.log(`[DEBUG] Skipping gap ${key} - no moveBlocks to anchor missing tokens`);
            return;
          }

          // Insert missing tokens BEFORE anchor (those with origIndex < moveBlock origIndex)
          missingBefore.forEach(miss=>{
            const isPunc = isPunctuation(miss.token.raw);
            const missSpan = document.createElement('span');
            missSpan.className = isPunc ? 'dictation-missing-token dictation-missing-punctuation' : 'dictation-missing-token';
            const word = document.createElement('span');
            word.className = 'dictation-missing-word';
            word.textContent = miss.token.raw;
            missSpan.appendChild(word);
            // Only add caret if NOT punctuation
            if (!isPunc) {
              const caret = document.createElement('span');
              caret.className = 'dictation-missing-caret';
              missSpan.appendChild(caret);
            }
            line.appendChild(missSpan);
            console.log(`[DEBUG] Inserted missing token BEFORE anchor: ${miss.token.raw} (orig_${miss.origIndex})${isPunc ? ' [PUNCTUATION]' : ''}`);
          });

          // Insert anchor for move blocks
          const anchor = document.createElement('span');
          anchor.className = 'dictation-gap-anchor';
          anchor.dataset.gapAnchor = key;
          const mark = document.createElement('span');
          mark.className = 'dictation-gap-mark';
          anchor.appendChild(mark);
          line.appendChild(anchor);

          // Insert missing tokens AFTER anchor (those with origIndex >= moveBlock origIndex)
          missingAfter.forEach(miss=>{
            const isPunc = isPunctuation(miss.token.raw);
            const missSpan = document.createElement('span');
            missSpan.className = isPunc ? 'dictation-missing-token dictation-missing-punctuation' : 'dictation-missing-token';
            const word = document.createElement('span');
            word.className = 'dictation-missing-word';
            word.textContent = miss.token.raw;
            missSpan.appendChild(word);
            // Only add caret if NOT punctuation
            if (!isPunc) {
              const caret = document.createElement('span');
              caret.className = 'dictation-missing-caret';
              missSpan.appendChild(caret);
            }
            line.appendChild(missSpan);
            console.log(`[DEBUG] Inserted missing token AFTER anchor: ${miss.token.raw} (orig_${miss.origIndex})${isPunc ? ' [PUNCTUATION]' : ''}`);
          });
        });
      };

      insertAnchors(0);
      const meta = comparison.movePlan.tokenMeta || [];

      // Build mapping: for each user position, which missing tokens should come AFTER it
      // This is ONLY for gaps with no moveBlocks (otherwise they're handled by anchor)
      const gapsWithMoveBlocks = new Set();
      comparison.movePlan.moveBlocks.forEach(block => {
        gapsWithMoveBlocks.add(block.targetGapKey);
      });

      const missingAfterUserPos = new Map();
      filteredMissingTokens.forEach(item => {
        // Skip if this missing token's gap has moveBlocks (will be handled by anchor)
        if(gapsWithMoveBlocks.has(item.gapKey)){
          return;
        }

        // Find which user token this missing token should come after
        // It should go after the last user token that has origIndex < item.origIndex
        let afterUserIdx = -1;
        for(let i = 0; i < comparison.userTokens.length; i++){
          const userMeta = meta[i];
          if(userMeta && userMeta.match && userMeta.match.origIndex < item.origIndex){
            afterUserIdx = i;
          }
        }
        if(!missingAfterUserPos.has(afterUserIdx)){
          missingAfterUserPos.set(afterUserIdx, []);
        }
        missingAfterUserPos.get(afterUserIdx).push(item);
      });

      // Sort missing tokens by origIndex for each position
      missingAfterUserPos.forEach((tokens, pos) => {
        tokens.sort((a, b) => a.origIndex - b.origIndex);
      });

      let idx = 0;
      let renderedPos = 0; // Track actual rendered position (excluding moved blocks)
      while(idx < comparison.userTokens.length){
        const token = comparison.userTokens[idx];
        const metaInfo = meta[idx] || {};
        if(metaInfo.rewriteGroupId){
          const group = comparison.movePlan.rewriteGroups.find(r=>r.id === metaInfo.rewriteGroupId);
          const start = group ? group.start : idx;
          const end = group ? group.end : idx;
          const segmentTokens = comparison.userTokens.slice(start, end + 1).map(t=>t.raw).join(' ');
          const rewriteEl = document.createElement('span');
          rewriteEl.className = 'dictation-rewrite-block';
          // NOTE: Rewrite blocks don't need arrows - they show the correction inline
          // Do NOT set data-target-gap here
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
          renderedPos++; // Rewrite block counts as one position
          insertAnchors(renderedPos);
          continue;
        }
        if(metaInfo.moveBlockId){
          const block = comparison.movePlan.moveBlocks.find(b=>b.id === metaInfo.moveBlockId);
          // Skip if block was filtered out (doesn't actually need to move)
          if(!block){
            const span = createTokenSpan(token, metaInfo);
            line.appendChild(span);
            idx++;
            renderedPos++;
            insertAnchors(renderedPos);
            continue;
          }
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
          // Move blocks don't add to renderedPos - they're moved elsewhere
          continue;
        }
        const span = createTokenSpan(token, metaInfo);
        line.appendChild(span);

        // Insert missing tokens that should come AFTER this user token
        const missingAfter = missingAfterUserPos.get(idx) || [];
        missingAfter.forEach(miss => {
          const isPunc = isPunctuation(miss.token.raw);
          const missSpan = document.createElement('span');
          missSpan.className = isPunc ? 'dictation-missing-token dictation-missing-punctuation' : 'dictation-missing-token';
          const word = document.createElement('span');
          word.className = 'dictation-missing-word';
          word.textContent = miss.token.raw;
          missSpan.appendChild(word);
          // Only add caret if NOT punctuation
          if (!isPunc) {
            const caret = document.createElement('span');
            caret.className = 'dictation-missing-caret';
            missSpan.appendChild(caret);
          }
          line.appendChild(missSpan);
          console.log(`[DEBUG] Inserted missing token inline AFTER user token ${idx}: ${miss.token.raw} (orig_${miss.origIndex})${isPunc ? ' [PUNCTUATION]' : ''}`);
        });

        idx++;
        renderedPos++;
        insertAnchors(renderedPos);
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
      label.textContent = 'Correct answer:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-correct';
      comparison.originalTokens.forEach((token, idx)=>{
        const span = document.createElement('span');
        span.className = 'dictation-token dictation-token-ok';
        span.textContent = token.raw;
        line.appendChild(span);
      });

      row.appendChild(label);
      row.appendChild(line);
      return row;
    }

    function createTokenSpan(token, meta){
      const wrapper = document.createElement('span');
      wrapper.className = 'dictation-token';

      const hasError = meta && meta.hasError;
      if(hasError){
        wrapper.classList.add('dictation-token-has-correction');
        const correction = document.createElement('span');
        correction.className = 'dictation-token-correction';
        correction.textContent = meta && meta.match ? meta.match.origToken.raw : '';
        wrapper.appendChild(correction);
      }

      const inner = document.createElement('span');
      inner.className = 'dictation-token-inner';
      inner.textContent = token.raw;

      // Apply state classes to inner to keep backgrounds on the user row only
      if(meta && meta.match && meta.inLis && !hasError){
        inner.classList.add('dictation-token-ok');
      }
      if(meta && meta.needsMove){
        inner.classList.add('dictation-token-move');
      }
      if(!meta || (!meta.match && !meta.hasError && !meta.moveBlockId && !meta.rewriteGroupId)){
        inner.classList.add('dictation-token-extra');
      }

      // Colors: user text always white; corrections are red; strikethrough is red over white text
      inner.classList.add('dictation-token-user');
      if(hasError){
        inner.classList.add('dictation-token-wrong-text');
      }

      wrapper.appendChild(inner);
      return wrapper;
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
      const containerRect = container.getBoundingClientRect();
      blocks.forEach(block=>{
        const gapKey = block.dataset.targetGap;
        const target = container.querySelector(`[data-gap-anchor="${gapKey}"]`);
        if(!target){
          return;
        }
        const blockRect = block.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();

        // Start: top center of the block that needs to move
        const startX = blockRect.left + (blockRect.width / 2) - containerRect.left;
        const startY = blockRect.top - containerRect.top;

        // End: center of target anchor
        const endX = targetRect.left + (targetRect.width / 2) - containerRect.left;
        const endY = targetRect.top + (targetRect.height / 2) - containerRect.top;

        // Calculate distances
        const horizontalDist = Math.abs(endX - startX);
        const verticalDist = Math.abs(endY - startY);
        const totalDistance = Math.sqrt(horizontalDist * horizontalDist + verticalDist * verticalDist);

        // Horizontal control points direction
        const direction = Math.sign(endX - startX);

        // Check if multiline (words on different lines)
        const isMultiline = verticalDist > 30;

        let cx1, cy1, cx2, cy2, arcHeight;

        if (isMultiline) {
          // MULTILINE: Use S-curve for vertical transitions
          // Dynamic arc height based on total distance
          arcHeight = Math.max(20, totalDistance * 0.25);

          // S-curve with two bends
          cx1 = startX + direction * horizontalDist * 0.3;
          cy1 = startY - arcHeight * 0.6;
          cx2 = endX - direction * horizontalDist * 0.2;
          cy2 = endY - arcHeight * 0.8;
        } else {
          // SINGLE LINE: Shallow arc parallel to text (original behavior)
          arcHeight = 20;

          // First control point - small rise above text
          cy1 = startY - arcHeight;
          // Second control point - EXACTLY ABOVE endX at same height
          // This creates vertical 90 drop
          cy2 = startY - arcHeight;

          // First control point at 40% of the way
          cx1 = startX + direction * horizontalDist * 0.4;
          // Second control point EXACTLY ABOVE endX (same X-coordinate!)
          // This forces arrow to drop vertically at 90
          cx2 = endX;
        }

        // Draw smooth curved path
        const p = document.createElementNS(svgNS, 'path');
        const d = `M ${startX} ${startY} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${endX} ${endY}`;
        p.setAttribute('d', d);
        p.setAttribute('fill', 'none');
        p.setAttribute('stroke', '#ef4444');
        p.setAttribute('stroke-width', '2.5');
        p.setAttribute('stroke-linecap', 'round');
        svg.appendChild(p);

        // Professional arrowhead using cubic Bezier derivative at t=0.95 (closer to endpoint for precise landing)
        const t = 0.95;
        const mt = 1 - t;
        const mt2 = mt * mt;
        const mt3 = mt2 * mt;
        const t2 = t * t;
        const t3 = t2 * t;

        // Cubic Bezier derivative: B'(t) = 3(1-t)?(P1-P0) + 6(1-t)t(P2-P1) + 3t?(P3-P2)
        const dx = 3*mt2*(cx1 - startX) + 6*mt*t*(cx2 - cx1) + 3*t2*(endX - cx2);
        const dy = 3*mt2*(cy1 - startY) + 6*mt*t*(cy2 - cy1) + 3*t2*(endY - cy2);

        const mag = Math.hypot(dx, dy) || 1;
        const ux = dx / mag;
        const uy = dy / mag;

        // Larger, more visible arrowhead
        const arrowLength = 12;
        const arrowWidth = 5;

        const tipX = endX;
        const tipY = endY;
        const baseX = tipX - ux * arrowLength;
        const baseY = tipY - uy * arrowLength;

        // Perpendicular vector for arrow wings
        const perpX = -uy;
        const perpY = ux;

        const leftX = baseX + perpX * arrowWidth;
        const leftY = baseY + perpY * arrowWidth;
        const rightX = baseX - perpX * arrowWidth;
        const rightY = baseY - perpY * arrowWidth;

        const poly = document.createElementNS(svgNS, 'polygon');
        poly.setAttribute('points', `${tipX},${tipY} ${leftX},${leftY} ${rightX},${rightY}`);
        poly.setAttribute('fill', '#ef4444');
        poly.setAttribute('stroke', 'none');
        svg.appendChild(poly);
      });
      container.appendChild(svg);
    }

    function escapeHtml(str){
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }

    function renderTranscriptionHTML(text){
      if(!text) return '';
      const tokens=[];
      let buffer='';

      const pushSyllable=(trailingDot=false)=>{
        if(!buffer && !trailingDot) return;
        tokens.push(buffer + (trailingDot?'.':''));
        buffer='';
      };

      for(const ch of text){
        if(ch === '.'){
          pushSyllable(true);
          continue;
        }
        buffer+=ch;
      }
      pushSyllable(false);

      const pieces=tokens.map((t,i)=>{
        const delay=i*2000; // 2s pause between syllables
        return `<span class="transcription-piece transcription-piece--drop" style="animation-delay:${delay}ms">${escapeHtml(t)}</span>`;
      }).join('');

      return `<div class="transcription-text">${pieces}</div>`;
    }


    async function buildSlot(kind, card){
      const el=document.createElement("div");
      el.className="slot";
      const cardOrder = Array.isArray(card.order) ? card.order : [];
      if(kind==="transcription" && card.transcription){
        el.innerHTML=renderTranscriptionHTML(card.transcription);
        el.dataset.slotType = 'transcription';
        return el;
      }
      if(kind==="text" && card.text){
        const showInlineTranscription = card.transcription && !cardOrder.includes('transcription');
        const tr = showInlineTranscription ? renderTranscriptionHTML(card.transcription) : "";

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
                Check
              </button>
            </div>
            <div class="dictation-result hidden"></div>
            <div class="dictation-correct-answer hidden">
              <div class="correct-answer-label">${aiStrings.dictationCorrectAnswer || ' :'}</div>
              <div class="correct-answer-text">${card.text}</div>
            </div>
          </div>
          ${tr}
        `;

        // Get audio URL for dictation autoplay
        const dictationAudioUrl = await resolveAudioUrl(card, 'front');
        if(dictationAudioUrl){
          el.dataset.autoplay = dictationAudioUrl;
        }
        el.dataset.slotType = 'dictation';

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
        // ONLY show front audio in this slot, never include focus audio automatically
        // If user wants focus audio, they should add 'audio_text' to order explicitly
        const tracks=[];
        if(frontUrl){
          tracks.push({url:frontUrl,type:'front'});
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
      console.log('[DEBUG renderCard] card.id:', card.id, 'card.order:', JSON.stringify(card.order));
      slotContainer.innerHTML="";
      hidePlayIcons();
      audioURL=null;
      const allSlots=[];
      for(const kind of card.order){
        console.log('[DEBUG renderCard] Building slot for kind:', kind);
        const el=await buildSlot(kind,card);
        if(el) {
          console.log('[DEBUG renderCard] Slot created for kind:', kind);
          allSlots.push(el);
        }
      }
      const items=allSlots.slice(0,count);
      if(!items.length){
        const d=document.createElement("div");
        d.className="front";
        d.textContent="-";
        items.push(d);
      }
      items.forEach(x=>slotContainer.appendChild(x));

      // Trigger transcription animation only once, when it first becomes visible
      if(!card._transcriptionAnimated){
        const transEl = items.find(el => el.dataset && el.dataset.slotType === 'transcription');
        if(transEl){
          const inner = transEl.querySelector('.transcription-text');
          if(inner){
            inner.classList.add('transcription-animate');
            card._transcriptionAnimated = true;
          }
        }
      }
      // Autoplay audio: first slot when opening card, or newly revealed slot when clicking Show More
      let autoplayUrl = null;
      let autoplayRate = 1;
      if(count===1 && items[0] && items[0].dataset && items[0].dataset.autoplay){
        // First opening - play first slot's audio
        autoplayUrl = items[0].dataset.autoplay;
        // Check if first slot is dictation - play at 0.85 speed
        if(items[0].dataset.slotType === 'dictation'){
          autoplayRate = 0.85;
        }
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
          // Check if new slot is dictation - play at 0.85 speed
          if(newSlot.dataset.slotType === 'dictation'){
            autoplayRate = 0.85;
          }
        }
      }
      if(autoplayUrl){
        player.src=autoplayUrl;
        player.playbackRate=autoplayRate;
        player.currentTime=0;
        player.play().catch(()=>{});
      }
      card._availableSlots=allSlots.length;

      // Show inline field prompt only when user clicks "Show More" AFTER all slots are revealed
      const allRevealed = count >= allSlots.length;
      const promptIsVisible = slotContainer.querySelector('.inline-field-prompt');

      // If all slots are revealed and user clicked "Show More" again (prevCount === count)
      if(allRevealed && prevCount === count && currentItem && !promptIsVisible){
        showInlineFieldPrompt();
      } else if(!allRevealed || prevCount < count){
        // Hide prompt if not all slots revealed, or if we're still revealing slots
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
    function updateRevealButton(){
      const allSlotsRevealed = currentItem && currentItem.card._availableSlots && visibleSlots >= currentItem.card._availableSlots;
      const promptIsVisible = slotContainer.querySelector('.inline-field-prompt');
      const canShowPrompt = allSlotsRevealed && !promptIsVisible && currentItem && currentItem.card && currentItem.card.scope === 'private';
      const more = (currentItem && currentItem.card._availableSlots && visibleSlots < currentItem.card._availableSlots) || canShowPrompt;
      const br=$("#btnRevealNext");
      if(br){
        br.disabled=!more;
        br.classList.toggle("primary",!!more);
      }
    }
    async function showCurrent(forceRender=false, prevSlots=0){
      if(!currentItem){
        pendingStudyRender=false;
        updateRevealButton();
        setIconVisibility(false);
        hidePlayIcons();
      updateRatingActionHints(null);
      return;
      }
      if(!forceRender && activeTab!=='study'){
        pendingStudyRender=true;
        return;
      }
      pendingStudyRender=false;
      setStage(currentItem.rec.step||0);
      await renderCard(currentItem.card, visibleSlots, prevSlots);
      setIconVisibility(true);
      updateRevealButton();
      updateRatingActionHints(currentItem.rec || null);
      if(pendingShowMoreScroll){
        pendingShowMoreScroll=false;
        ensureStudyLayerVisible();
      }
      // Note: maybePromptForStage() replaced by inline prompt in renderCard
    }

    function ensureStudyLayerVisible(){
      if(activeTab!=='study') return;
      const container = root.querySelector('#slotContainer');
      if(!container) return;
      const rect = container.getBoundingClientRect();
      const metaHeight = (studyMetaPanel && !studyMetaPanel.classList.contains('hidden'))
        ? studyMetaPanel.getBoundingClientRect().height : 0;
      const bottomHeight = (studyBottomActions && !studyBottomActions.classList.contains('hidden'))
        ? studyBottomActions.getBoundingClientRect().height : 0;
      const safeGap = 14;
      const floor = window.innerHeight - metaHeight - bottomHeight - safeGap;
      const overshoot = rect.bottom - floor;
      if(overshoot > 8){
        const extraScroll = Math.max(16, metaHeight * 0.3);
        const scrollTarget = overshoot + safeGap + extraScroll;
        if('scrollBy' in window){
          try{
            window.scrollBy({top: scrollTarget, behavior: 'smooth'});
          }catch(_e){
            window.scrollBy(0, scrollTarget);
          }
        }else{
          window.scrollBy(0, scrollTarget);
        }
      }
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

    $("#btnRevealNext").addEventListener("click",()=>{
      if(!currentItem)return;
      pendingShowMoreScroll=true;
      const prevSlots=visibleSlots;
      // Only increase visibleSlots if we haven't revealed all slots yet
      if(visibleSlots < currentItem.card._availableSlots){
        visibleSlots=Math.min(currentItem.card.order.length, visibleSlots+1);
      }
      showCurrent(false, prevSlots);
    });

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

      // Playback chain: student > original > STOP (one-time playback)
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
      const pretty = (orderChosen.length ? orderChosen : DEFAULT_ORDER).map(k=>chipsMap[k]).join(' > ');
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
      const compoundEl = document.getElementById('compoundParts'); if(compoundEl) compoundEl.innerHTML = '';
      const formsPrevEl = document.getElementById('formsPreview'); if(formsPrevEl) formsPrevEl.innerHTML = '';
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
    function fmtDateTime(ts){
      if(!ts) return '-';
      const d = new Date(ts);
      const day = String(d.getDate()).padStart(2, '0');
      const month = String(d.getMonth() + 1).padStart(2, '0');
      return `${day}.${month}`;
    }

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
        del.textContent="\u{1F5D1}";
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
    function openCardsListModal(){
      listCurrentPage = 1; // Reset to page 1 when opening modal
      $("#listModal").style.display="flex";
      buildListRows();
    }

    $$("#btnList").forEach(function(_el){ _el.addEventListener("click", openCardsListModal); });
    const statCardTotal = $("#statCardTotal");
    if (statCardTotal) {
      statCardTotal.addEventListener("click", ()=>{
        debugLog('[Dashboard] Total cards stat clicked -> opening list');
        openCardsListModal();
      });
      statCardTotal.addEventListener("keydown", (e)=>{
        if(e.key==="Enter" || e.key===" "){
          e.preventDefault();
          openCardsListModal();
        }
      });
    }

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
        editor.placeholder = '[transkr?p??n]';
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
        updateRevealButton();
      });

      const btnSkip = document.createElement('button');
      btnSkip.className = 'mid';
      btnSkip.textContent = t('skip');
      btnSkip.addEventListener('click', () => {
        hideInlineFieldPrompt();
        updateRevealButton();
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
        statusIcon = '?';
      } else if (access.status === 'grace') {
        statusClass = 'access-grace';
        const daysLeft = access.days_remaining || 0;
        statusTitle = (M?.str?.mod_flashcards?.access_status_grace || 'Grace Period ({$a} days remaining)').replace('{$a}', daysLeft);
        statusDesc = M?.str?.mod_flashcards?.access_status_grace_desc || 'You can review your existing cards but cannot create new ones. Enrol in a course to restore full access.';
        statusIcon = '?';

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
        statusIcon = '?';

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

      const bottomActions = document.getElementById('bottomActions');
      const metaPanel = document.getElementById('metaPanel');
      studyBottomActions = bottomActions;
      studyMetaPanel = metaPanel;

      function refreshBottomPanelStackMetrics() {
        if (!root) {
          return;
        }
        const bottomHeight = (bottomActions && !bottomActions.classList.contains('hidden'))
          ? Math.round(bottomActions.getBoundingClientRect().height) : 0;
        root.style.setProperty('--bottom-actions-stack-offset', `${bottomHeight}px`);

        const metaHeight = (metaPanel && !metaPanel.classList.contains('hidden'))
          ? Math.round(metaPanel.getBoundingClientRect().height) : 0;
        root.style.setProperty('--meta-panel-height', `${metaHeight}px`);
      }

      function queueBottomPanelStackRefresh() {
        requestAnimationFrame(refreshBottomPanelStackMetrics);
      }

      window.addEventListener('resize', () => {
        queueBottomPanelStackRefresh();
      });
      window.addEventListener('orientationchange', () => {
        queueBottomPanelStackRefresh();
      });

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

        // Show/hide bottom action and meta panels based on active tab
        if (bottomActions) {
          if (tabName === 'study') {
            bottomActions.classList.remove('hidden');
            bottomActions.removeAttribute('hidden');
            bottomActions.style.display = '';
            if (root) root.setAttribute('data-bottom-visible', '1');
          } else {
            bottomActions.classList.add('hidden');
            bottomActions.setAttribute('hidden', 'hidden');
            bottomActions.style.display = 'none';
            if (root) root.removeAttribute('data-bottom-visible');
          }
        }
        if (metaPanel) {
          if (tabName === 'study') {
            metaPanel.classList.remove('hidden');
            metaPanel.removeAttribute('hidden');
            metaPanel.style.display = '';
          } else {
            metaPanel.classList.add('hidden');
            metaPanel.setAttribute('hidden', 'hidden');
            metaPanel.style.display = 'none';
          }
        }
        queueBottomPanelStackRefresh();

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
        const stageEmojis = ['??', '??', '??', '??', '??', '??', '??', '??', '??', '?', '??', '??'];

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
          { id: 2, threshold: 7, current: stats.currentStreak, icon: '??' },

          // Language Level Achievements (based on Active Vocabulary)
          { id: 5, threshold: 100, current: activeVocab, icon: '??' },  // A0
          { id: 6, threshold: 600, current: activeVocab, icon: '??' },  // A1
          { id: 7, threshold: 1500, current: activeVocab, icon: '??' }, // A2
          { id: 8, threshold: 2500, current: activeVocab, icon: '??' }, // B1
          { id: 9, threshold: 4500, current: activeVocab, icon: '??' }  // B2
        ];

        achievements.forEach(ach => {
          const card = $(`#achievement${ach.id}`);
          const progress = $(`#ach${ach.id}Progress`);
          if (!card || !progress) return;

          const completed = ach.current >= ach.threshold;
          if (completed) {
            card.classList.add('fc-achievement-completed');
            progress.textContent = '? Completed';
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
            en: 'English', uk: '', ru: '',
            fr: 'Francais', es: 'Espanol', pl: 'Polski',
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

    // ==========================================================================
    // ERROR CHECKING & CONSTRUCTION DETECTION
    // ==========================================================================

    /**
     * Chat Session Manager (in-memory storage with localStorage backup)
     */
    const ChatSessionManager = {
      currentSession: null,

      createSession(originalText) {
        const sessionId = 'correction_' + Date.now();
        this.currentSession = {
          id: sessionId,
          originalText: originalText,
          messages: [],
          createdAt: new Date().toISOString()
        };
        this.saveToLocalStorage();
        return this.currentSession;
      },

      addMessage(role, content) {
        if (!this.currentSession) return;
        const message = {
          id: 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
          role: role, // 'system' | 'user' | 'assistant'
          content: content,
          createdAt: new Date().toISOString()
        };
        this.currentSession.messages.push(message);
        this.saveToLocalStorage();
        return message;
      },

      getMessagesForAPI(maxMessages = 10) {
        if (!this.currentSession) return [];

        // Always include system message
        const systemMsg = {
          role: 'system',
          content: this.getSystemPrompt()
        };

        // Take last N messages (user/assistant pairs)
        const recentMessages = this.currentSession.messages.slice(-maxMessages);

        return [systemMsg, ...recentMessages];
      },

      getSystemPrompt() {
        const langMap = {
          'uk': `Ти — дуже строгий і обережний коректор норвезької мови (bokmål).

Правила:
- "Виправлено" - це ОСНОВНЕ виправлення помилок. "Більш природний варіант" - додаткове стилістичне поліпшення.
- Коли аналізуєш текст, фокусуйся на ВСІХ помилках з "Виправлено", не тільки на одній. Не ігноруй основні помилки.
- Пояснюй КОРОТКО, природною мовою — 2-3 речення максимум.
- Якщо не впевнена у граматичному правилі на 100%, прямо пиши що не впевнена.
- Не вигадуй різницю між "розмовною" та "письмовою" норвезькою, не посилайся на діалекти.
- Ніколи не називай неправильний варіант "більш природнім".`,

          'ru': `Ты — очень строгий и осторожный корректор норвежского языка (bokmål).

Правила:
- "Исправлено" - это ОСНОВНОЕ исправление ошибок. "Более естественный вариант" - дополнительное стилистическое улучшение.
- Когда анализируешь текст, фокусируйся на ВСЕХ ошибках из "Исправлено", не только на одной. Не игнорируй основные ошибки.
- Объясняй КРАТКО, естественным языком — 2-3 предложения максимум.
- Если не уверен в грамматическом правиле на 100%, прямо пиши что не уверен.
- Не выдумывай разницу между "разговорным" и "письменным" норвежским, не ссылайся на диалекты.
- Никогда не называй неправильный вариант "более естественным".`,

          'en': `You are a very strict and careful Norwegian (bokmål) corrector.

Rules:
- "Corrected" is the MAIN error correction. "More natural alternative" is additional stylistic improvement.
- When analyzing text, focus on ALL errors from "Corrected", not just one. Don't ignore main errors.
- Explain BRIEFLY, in natural language — 2-3 sentences maximum.
- If not 100% sure about a grammar rule, state that you're not sure.
- Don't invent spoken/written differences, don't refer to dialects.
- Never call an incorrect variant "more natural".`,

          'pl': `Jesteś bardzo surowym i ostrożnym korektorem norweskiego (bokmål).

Zasady:
- "Poprawiono" to GŁÓWNA korekta błędów. "Bardziej naturalny wariant" to dodatkowa poprawa stylistyczna.
- Analizując tekst, skup się na WSZYSTKICH błędach z "Poprawiono", nie tylko na jednym. Nie ignoruj głównych błędów.
- Wyjaśniaj KRÓTKO, naturalnym językiem — maksymalnie 2-3 zdania.
- Jeśli nie jesteś pewien zasady na 100%, napisz że nie jesteś pewien.
- Nie wymyślaj różnic potoczny/pisany, nie odwołuj się do dialektów.
- Nigdy nie nazywaj błędnego wariantu "bardziej naturalnym".`,

          'fr': `Tu es un correcteur très strict et prudent du norvégien (bokmål).

Règles:
- "Corrigé" est la correction d'erreurs PRINCIPALE. "Alternative plus naturelle" est une amélioration stylistique supplémentaire.
- En analysant le texte, concentre-toi sur TOUTES les erreurs de "Corrigé", pas qu'une seule. N'ignore pas les erreurs principales.
- Explique BRIÈVEMENT, en langage naturel — 2-3 phrases maximum.
- Si pas sûr à 100% d'une règle, dis-le directement.
- N'invente pas de différences parlé/écrit, ne réfère pas aux dialectes.
- Ne qualifie jamais une variante incorrecte de "plus naturelle".`,

          'es': `Eres un corrector muy estricto y cuidadoso del noruego (bokmål).

Reglas:
- "Corregido" es la corrección de errores PRINCIPAL. "Alternativa más natural" es mejora estilística adicional.
- Al analizar el texto, enfócate en TODOS los errores de "Corregido", no solo en uno. No ignores los errores principales.
- Explica BREVEMENTE, en lenguaje natural — 2-3 oraciones máximo.
- Si no estás 100% seguro de una regla, dilo directamente.
- No inventes diferencias hablado/escrito, no refieras dialectos.
- Nunca llames a una variante incorrecta "más natural".`,

          'it': `Sei un correttore molto rigoroso e attento del norvegese (bokmål).

Regole:
- "Corretto" è la correzione degli errori PRINCIPALE. "Alternativa più naturale" è miglioramento stilistico aggiuntivo.
- Analizzando il testo, concentrati su TUTTI gli errori di "Corretto", non solo su uno. Non ignorare gli errori principali.
- Spiega BREVEMENTE, in linguaggio naturale — 2-3 frasi massimo.
- Se non sei sicuro al 100% di una regola, dillo direttamente.
- Non inventare differenze parlato/scritto, non riferire dialetti.
- Non chiamare mai una variante errata "più naturale".`,

          'de': `Du bist ein sehr strenger und vorsichtiger Korrektor des Norwegischen (bokmål).

Regeln:
- "Korrigiert" ist die HAUPTFEHLERKORREKTUR. "Natürlichere Alternative" ist zusätzliche stilistische Verbesserung.
- Wenn du Text analysierst, konzentriere dich auf ALLE Fehler aus "Korrigiert", nicht nur auf einen. Ignoriere Hauptfehler nicht.
- Erkläre KURZ, in natürlicher Sprache — maximal 2-3 Sätze.
- Wenn nicht 100% sicher über eine Regel, sage es direkt.
- Erfinde keine Unterschiede gesprochen/geschrieben, verweise nicht auf Dialekte.
- Nenne niemals eine falsche Variante "natürlicher".`
        };

        const language = currentInterfaceLang || 'en';
        return langMap[language] || langMap['en'];
      },

      saveToLocalStorage() {
        if (!this.currentSession) return;
        try {
          localStorage.setItem('flashcards_chat_session', JSON.stringify(this.currentSession));
        } catch (e) {
          console.warn('Could not save chat session to localStorage:', e);
        }
      },

      loadFromLocalStorage() {
        try {
          const saved = localStorage.getItem('flashcards_chat_session');
          if (saved) {
            this.currentSession = JSON.parse(saved);
            return this.currentSession;
          }
        } catch (e) {
          console.warn('Could not load chat session from localStorage:', e);
        }
        return null;
      },

      clearSession() {
        this.currentSession = null;
        try {
          localStorage.removeItem('flashcards_chat_session');
        } catch (e) {
          // ignore
        }
      }
    };

    /**
     * Check Norwegian text for grammatical errors
     */
    async function checkTextForErrors() {
      const text = $('#uFront').value.trim();
      if (!text) {
        return;
      }

      // Use interface language for explanations, NOT learning language
      const language = currentInterfaceLang || 'en';

      // Show loading status
      const checkingText = t('checking_text') || 'Checking text...';
      setFocusStatus('loading', checkingText);
      const errorBlock = $('#errorCheckBlock');
      if (errorBlock) {
        errorBlock.style.display = 'none';
      }

      try {
        const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
        url.searchParams.set('action', 'check_text_errors');
        url.searchParams.set('sesskey', M.cfg.sesskey);

        const response = await fetch(url.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'check_text_errors',
            text: text,
            language: language
          })
        });

        const result = await response.json();

        if (result.hasErrors) {
          showErrorCheckResult(result);
        } else {
          showSuccessMessage();
          // Auto-continue with tokenization
          renderFocusChips();
        }

        setFocusStatus('', '');
      } catch (error) {
        console.error('Error checking text:', error);
        const errorMsg = t('error_checking_failed') || 'Error checking failed';
        setFocusStatus('error', errorMsg);
      }
    }

    /**
     * Highlight differences between original and corrected text
     */
    function highlightDifferences(original, corrected, errors) {
      let highlighted = corrected;

      // Sort errors by length (longest first) to avoid nested replacements
      const sortedErrors = (errors || []).sort((a, b) =>
        (b.corrected || '').length - (a.corrected || '').length
      );

      sortedErrors.forEach(err => {
        if (err.corrected && err.corrected.trim()) {
          // Wrap corrected parts in span with title showing explanation
          const explanation = err.issue || '';
          const wrappedCorrection = `<span class="text-correction" title="${explanation}">${err.corrected}</span>`;
          highlighted = highlighted.replace(err.corrected, wrappedCorrection);
        }
      });

      return highlighted;
    }

    /**
     * Show error check result with corrections
     */
    function showErrorCheckResult(result) {
      const block = $('#errorCheckBlock');
      if (!block) return;

      const errorsFoundText = t('errors_found') || 'Errors found!';
      const correctedVersionText = t('corrected_version') || 'Corrected version:';
      const applyCorrectionsText = t('apply_corrections') || 'Apply corrections';
      const keepAsIsText = t('keep_as_is') || 'Keep it as it is';
      const suggestionText = t('naturalness_suggestion') || 'More natural alternative:';

      // Get original text
      const originalText = $('#uFront').value || '';

      // Highlight corrections in the corrected text
      const highlightedText = highlightDifferences(originalText, result.correctedText, result.errors);

      // Build compact errors list
      let errorsListHtml = '';
      if (result.errors && result.errors.length > 0) {
        errorsListHtml = '<div class="error-details-list">';
        result.errors.forEach((err, idx) => {
          errorsListHtml += `
            <div class="error-detail-item">
              <span class="error-icon">❌</span>
              <span class="error-original-text">${err.original || ''}</span>
              <span class="error-arrow">→</span>
              <span class="error-icon">✅</span>
              <span class="error-corrected-text">${err.corrected || ''}</span>
            </div>
          `;
        });
        errorsListHtml += '</div>';
      }

      // Build suggestion block if present
      let suggestionHtml = '';
      if (result.suggestion && result.suggestion.trim()) {
        suggestionHtml = `
          <div class="error-check-suggestion">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <strong>💡 ${suggestionText}</strong>
              <button type="button" class="apply-suggestion-btn" data-text="${result.suggestion.replace(/"/g, '&quot;')}">${applyCorrectionsText}</button>
            </div>
            <div class="suggestion-text">${result.suggestion}</div>
          </div>
        `;
      }

      const askAiText = t('ask_ai_about_correction') || 'Ask AI';
      const sureText = t('ai_sure') || 'Are you sure?';
      const explainMoreText = t('ai_explain_more') || 'Explain in detail';
      const moreExamplesText = t('ai_more_examples') || 'Give more examples';
      const explainSimplerText = t('ai_explain_simpler') || 'Explain simpler';

      const html = `
        <div class="error-check-header">
          <strong>${errorsFoundText}</strong>
        </div>
        ${errorsListHtml}
        <div class="error-check-corrected">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <strong>${correctedVersionText}</strong>
            <button type="button" class="apply-corrected-btn" data-text="${result.correctedText.replace(/"/g, '&quot;')}">${applyCorrectionsText}</button>
          </div>
          <div class="corrected-text">${highlightedText}</div>
        </div>
        ${suggestionHtml}
        <div class="ai-question-section">
          <div style="margin-bottom: 8px; font-weight: 500; color: #475569;">💬 ${askAiText}:</div>
          <div class="ai-quick-questions" id="aiQuickBtnsContainer">
            <button type="button" class="ai-quick-btn" data-type="sure">${sureText}</button>
            <button type="button" class="ai-quick-btn" data-type="explain">${explainMoreText}</button>
            <button type="button" class="ai-quick-btn" data-type="examples">${moreExamplesText}</button>
          </div>
          <div id="aiAnswerBlock" class="ai-answer-block" style="display: none;"></div>
        </div>
        <div class="error-check-actions">
          <button type="button" id="rejectCorrectionBtn">${keepAsIsText}</button>
        </div>
      `;

      block.innerHTML = html;
      block.classList.add('error-check-visible');
      block.style.display = 'block';

      // Setup button handlers
      const applyCorrectedBtn = block.querySelector('.apply-corrected-btn');
      const applySuggestionBtn = block.querySelector('.apply-suggestion-btn');
      const rejectBtn = document.getElementById('rejectCorrectionBtn');

      // Helper function to apply text
      function applyText(text) {
        const frontInput = $('#uFront');
        if (frontInput) {
          frontInput.value = text;

          // Trigger input event to activate translation and other listeners
          const inputEvent = new Event('input', { bubbles: true });
          frontInput.dispatchEvent(inputEvent);
        }

        // Trigger translation update if available
        if (window.onFrontChange) {
          window.onFrontChange();
        }

        // Trigger tokenization
        renderFocusChips();

        // Hide error block
        block.classList.remove('error-check-visible');
        block.style.display = 'none';
      }

      // Apply corrected version button
      if (applyCorrectedBtn) {
        applyCorrectedBtn.addEventListener('click', function() {
          const text = this.getAttribute('data-text');
          applyText(text);
        });
      }

      // Apply suggestion button
      if (applySuggestionBtn) {
        applySuggestionBtn.addEventListener('click', function() {
          const text = this.getAttribute('data-text');
          applyText(text);
        });
      }

      // Keep it as it is button
      if (rejectBtn) {
        rejectBtn.addEventListener('click', function() {
          // Just hide error block and continue with tokenization
          block.classList.remove('error-check-visible');
          block.style.display = 'none';
          renderFocusChips();
        });
      }

      // AI quick question buttons
      const aiQuickBtns = block.querySelectorAll('.ai-quick-btn');
      let hasClickedOnce = false;

      aiQuickBtns.forEach(btn => {
        btn.addEventListener('click', async function() {
          const questionType = this.getAttribute('data-type');
          await askAIAboutCorrection(questionType, originalText, result);

          // After first click, add 4th button "Explain simpler"
          if (!hasClickedOnce) {
            hasClickedOnce = true;
            const container = document.getElementById('aiQuickBtnsContainer');
            if (container && !container.querySelector('[data-type="simpler"]')) {
              const simplerBtn = document.createElement('button');
              simplerBtn.type = 'button';
              simplerBtn.className = 'ai-quick-btn';
              simplerBtn.setAttribute('data-type', 'simpler');
              simplerBtn.textContent = explainSimplerText;
              simplerBtn.addEventListener('click', async function() {
                await askAIAboutCorrection('simpler', originalText, result);
              });
              container.appendChild(simplerBtn);
            }
          }
        });
      });

      // Store context for AI questions
      block.dataset.originalText = originalText;
      block.dataset.correctedText = result.correctedText;
      if (result.suggestion) {
        block.dataset.suggestion = result.suggestion;
      }
    }

    /**
     * Ask AI about the correction
     */
    async function askAIAboutCorrection(questionType, originalText, result) {
      const answerBlock = document.getElementById('aiAnswerBlock');
      if (!answerBlock) return;

      const language = currentInterfaceLang || 'en';
      const thinkingText = t('ai_thinking') || 'Thinking...';

      // Show loading
      answerBlock.style.display = 'block';
      answerBlock.innerHTML = `<div class="ai-answer-loading">${thinkingText}</div>`;

      // Initialize chat session if first request
      if (!ChatSessionManager.currentSession) {
        ChatSessionManager.createSession(originalText);

        // Add user's original text as first user message
        const checkLabels = {
          'uk': 'Перевір цей текст',
          'ru': 'Проверь этот текст',
          'en': 'Check this text',
          'pl': 'Sprawdź ten tekst',
          'fr': 'Vérifie ce texte',
          'es': 'Verifica este texto',
          'it': 'Controlla questo testo',
          'de': 'Überprüfe diesen Text'
        };
        const checkLabel = checkLabels[language] || checkLabels['en'];
        ChatSessionManager.addMessage('user', `${checkLabel}: "${originalText}"`);

        // Add initial correction as first assistant message with clear structure
        const correctedLabels = {
          'uk': 'Виправлено',
          'ru': 'Исправлено',
          'en': 'Corrected',
          'pl': 'Poprawiono',
          'fr': 'Corrigé',
          'es': 'Corregido',
          'it': 'Corretto',
          'de': 'Korrigiert'
        };
        const naturalLabels = {
          'uk': 'Більш природний варіант',
          'ru': 'Более естественный вариант',
          'en': 'More natural alternative',
          'pl': 'Bardziej naturalny wariant',
          'fr': 'Alternative plus naturelle',
          'es': 'Alternativa más natural',
          'it': 'Alternativa più naturale',
          'de': 'Natürlichere Alternative'
        };

        const correctedLabel = correctedLabels[language] || correctedLabels['en'];
        const naturalLabel = naturalLabels[language] || naturalLabels['en'];

        // Start the chat history with the corrected sentence itself,
        // without a rigid "Corrected:" prefix so the AI can answer more naturally.
        // Use correctedText (the field returned from the backend); fall back to original text if needed.
        const correctedSentence = result.correctedText || result.corrected || originalText || '';
        let correctionMsg = correctedSentence ? `"${correctedSentence}"` : '';
        if (result.suggestion) {
          correctionMsg += `\n\n${naturalLabel}: "${result.suggestion}"`;
        }
        if (result.explanation) {
          correctionMsg += `\n\n${result.explanation}`;
        }
        ChatSessionManager.addMessage('assistant', correctionMsg);
      }

      // Build prompt based on question type (strict format per spec)
      let userPrompt = '';

      // No need to prepend context - AI already has it in message history

      const prompts = {
        'uk': {
          sure: `Перепровір своє виправлення. Проаналізуй всі помилки та підтверди правильність. Відповідай коротко українською.`,
          explain: `Поясни граматичну причину виправлення.

ВАЖЛИВО: Покажи КОНТРАСТ на прикладах:
1. Як це працює в ЦЬОМУ типі речення (де була помилка)
2. Як це ВІДРІЗНЯЄТЬСЯ в інших типах речень

Приклад структури:
- У підрядних реченнях: "at det ikke er" (заперечення перед дієсловом)
- У головних реченнях: "det er ikke" (заперечення після дієслова)

Формат:
- Коротке пояснення (2-3 речення) з фокусом на КОНКРЕТНОМУ контексті
- 3-4 контрастних приклади норвезькою з перекладом українською

НЕ роби широких узагальнень типу "ikke завжди йде перед дієсловом".
Поясни КОНКРЕТНЕ правило для КОНКРЕТНОГО контексту.

Відповідай українською.`,
          examples: `Створи, будь ласка, 5–10 коротких норвезьких речень з такою ж граматичною структурою, як у виправленому варіанті. До кожного речення додай переклад українською.`,
          simpler: `Поясни цю помилку простими словами, без граматичної термінології.

Покажи приклади ПРАВИЛЬНОГО та НЕПРАВИЛЬНОГО використання:
- Неправильно: [оригінальна помилка]
- Правильно: [виправлений варіант]
- Також покажи: Як це було б інакше в іншому типі речення

Використовуй просту мову, але зберігай точність пояснення.
Відповідай українською.`
        },
        'ru': {
          sure: `Перепроверь свое исправление. Проанализируй все ошибки и подтверди правильность. Отвечай кратко по-русски.`,
          explain: `Объясни грамматическую причину исправления.

ВАЖНО: Покажи КОНТРАСТ на примерах:
1. Как это работает в ЭТОМ типе предложения (где была ошибка)
2. Как это ОТЛИЧАЕТСЯ в других типах предложений

Пример структуры:
- В придаточных предложениях: "at det ikke er" (отрицание перед глаголом)
- В главных предложениях: "det er ikke" (отрицание после глагола)

Формат:
- Краткое объяснение (2-3 предложения) с фокусом на КОНКРЕТНОМ контексте
- 3-4 контрастных примера на норвежском с переводом на русский

НЕ делай широких обобщений типу "ikke всегда идет перед глаголом".
Объясни КОНКРЕТНОЕ правило для КОНКРЕТНОГО контекста.

Отвечай по-русски.`,
          examples: `Создай, пожалуйста, 5–10 коротких норвежских предложений с такой же грамматической структурой, как в исправленном варианте. К каждому предложению добавь перевод на русский.`,
          simpler: `Объясни эту ошибку простыми словами, без грамматической терминологии.

Покажи примеры ПРАВИЛЬНОГО и НЕПРАВИЛЬНОГО использования:
- Неправильно: [оригинальная ошибка]
- Правильно: [исправленный вариант]
- Также покажи: Как это было бы иначе в другом типе предложения

Используй простой язык, но сохраняй точность объяснения.
Отвечай по-русски.`
        },
        'en': {
          sure: `You are continuing the same chat as above. The user is asking: "Are you sure your correction and explanation above are correct?" Carefully re-check the original Norwegian sentence, your corrected version and your previous explanation.

If everything is correct, answer in 1–2 short sentences like: "Yes, my correction is correct because …". Do NOT repeat the full corrected sentence and do NOT start with "Corrected:".

If you notice any mistake or a better correction, honestly admit it and give a short fix, for example: "No, I would correct it to: \"…\", because …". Keep the answer brief, as a follow-up in the same conversation. Answer in English.`,
          explain: `Explain the grammatical reason for the correction.

IMPORTANT: Show the CONTRAST with examples:
1. Show how it works in THIS type of sentence (where the error was)
2. Show how it's DIFFERENT in other types of sentences

Example structure:
- In subordinate clauses: "at det ikke er" (negation before verb)
- In main clauses: "det er ikke" (negation after verb)

Format:
- Brief explanation (2-3 sentences) focusing on the SPECIFIC context
- 3-4 contrasting Norwegian examples with English translation

Do NOT make broad generalizations like "ikke always comes before the verb".
Explain the SPECIFIC rule for the SPECIFIC context.

Answer in English.`,
          examples: `Please create 5–10 short Norwegian sentences with the same grammatical structure as in the corrected version. Add English translation to each sentence.`,
          simpler: `Explain this error in simple words, without grammatical terminology.

Show examples of RIGHT and WRONG usage:
- Wrong: [original error]
- Right: [corrected version]
- Also show: How it would be different in a different type of sentence

Use simple language but keep the explanation ACCURATE.
Answer in English.`
        },
        'pl': {
          sure: `Sprawdź ponownie swoją poprawkę. Przeanalizuj wszystkie błędy i potwierdź poprawność. Odpowiadaj krótko po polsku.`,
          explain: `Wyjaśnij powód gramatyczny poprawki.

WAŻNE: Pokaż KONTRAST na przykładach:
1. Jak to działa w TYM typie zdania (gdzie był błąd)
2. Jak to się RÓŻNI w innych typach zdań

Przykład struktury:
- W zdaniach podrzędnych: "at det ikke er" (przeczenie przed czasownikiem)
- W zdaniach głównych: "det er ikke" (przeczenie po czasowniku)

Format:
- Krótkie wyjaśnienie (2-3 zdania) skupiające się na KONKRETNYM kontekście
- 3-4 kontrastujące przykłady po norwesku z tłumaczeniem na polski

NIE rób szerokich uogólnień typu "ikke zawsze idzie przed czasownikiem".
Wyjaśnij KONKRETNĄ zasadę dla KONKRETNEGO kontekstu.

Odpowiadaj po polsku.`,
          examples: `Stwórz proszę 5–10 krótkich norweskich zdań z taką samą strukturą gramatyczną jak w poprawionej wersji. Do każdego zdania dodaj tłumaczenie na polski.`,
          simpler: `Wyjaśnij ten błąd prostymi słowami, bez terminologii gramatycznej.

Pokaż przykłady PRAWIDŁOWEGO i NIEPRAWIDŁOWEGO użycia:
- Nieprawidłowo: [oryginalny błąd]
- Prawidłowo: [poprawiona wersja]
- Pokaż też: Jak to byłoby inaczej w innym typie zdania

Używaj prostego języka, ale zachowaj dokładność wyjaśnienia.
Odpowiadaj po polsku.`
        },
        'fr': {
          sure: `Revérifie ta correction. Analyse toutes les erreurs et confirme l'exactitude. Réponds brièvement en français.`,
          explain: `Explique la raison grammaticale de la correction.

IMPORTANT : Montre le CONTRASTE avec des exemples :
1. Comment cela fonctionne dans CE type de phrase (où était l'erreur)
2. Comment c'est DIFFÉRENT dans d'autres types de phrases

Exemple de structure :
- Dans les propositions subordonnées : "at det ikke er" (négation avant le verbe)
- Dans les propositions principales : "det er ikke" (négation après le verbe)

Format :
- Explication brève (2-3 phrases) se concentrant sur le contexte SPÉCIFIQUE
- 3-4 exemples contrastés en norvégien avec traduction en français

Ne fais PAS de généralisations larges comme "ikke vient toujours avant le verbe".
Explique la règle SPÉCIFIQUE pour le contexte SPÉCIFIQUE.

Réponds en français.`,
          examples: `Crée s'il te plaît 5–10 phrases norvégiennes courtes avec la même structure grammaticale que dans la version corrigée. Ajoute une traduction en français à chaque phrase.`,
          simpler: `Explique cette erreur avec des mots simples, sans terminologie grammaticale.

Montre des exemples d'utilisation CORRECTE et INCORRECTE :
- Incorrect : [erreur originale]
- Correct : [version corrigée]
- Montre aussi : Comment ce serait différent dans un autre type de phrase

Utilise un langage simple mais garde l'explication PRÉCISE.
Réponds en français.`
        },
        'es': {
          sure: `Verifica nuevamente tu corrección. Analiza todos los errores y confirma la exactitud. Responde brevemente en español.`,
          explain: `Explica la razón gramatical de la corrección.

IMPORTANTE: Muestra el CONTRASTE con ejemplos:
1. Cómo funciona en ESTE tipo de oración (donde estaba el error)
2. Cómo es DIFERENTE en otros tipos de oraciones

Ejemplo de estructura:
- En oraciones subordinadas: "at det ikke er" (negación antes del verbo)
- En oraciones principales: "det er ikke" (negación después del verbo)

Formato:
- Explicación breve (2-3 oraciones) enfocándose en el contexto ESPECÍFICO
- 3-4 ejemplos contrastantes en noruego con traducción al español

NO hagas generalizaciones amplias como "ikke siempre va antes del verbo".
Explica la regla ESPECÍFICA para el contexto ESPECÍFICO.

Responde en español.`,
          examples: `Crea por favor 5–10 oraciones noruegas cortas con la misma estructura gramatical que en la versión corregida. Añade traducción al español a cada oración.`,
          simpler: `Explica este error con palabras simples, sin terminología gramatical.

Muestra ejemplos de uso CORRECTO e INCORRECTO:
- Incorrecto: [error original]
- Correcto: [versión corregida]
- También muestra: Cómo sería diferente en otro tipo de oración

Usa lenguaje simple pero mantén la explicación PRECISA.
Responde en español.`
        },
        'it': {
          sure: `Ricontrolla la tua correzione. Analizza tutti gli errori e conferma la correttezza. Rispondi brevemente in italiano.`,
          explain: `Spiega la ragione grammaticale della correzione.

IMPORTANTE: Mostra il CONTRASTO con esempi:
1. Come funziona in QUESTO tipo di frase (dove c'era l'errore)
2. Come è DIVERSO in altri tipi di frasi

Esempio di struttura:
- Nelle proposizioni subordinate: "at det ikke er" (negazione prima del verbo)
- Nelle proposizioni principali: "det er ikke" (negazione dopo il verbo)

Formato:
- Spiegazione breve (2-3 frasi) concentrandosi sul contesto SPECIFICO
- 3-4 esempi contrastanti in norvegese con traduzione in italiano

NON fare generalizzazioni ampie come "ikke va sempre prima del verbo".
Spiega la regola SPECIFICA per il contesto SPECIFICO.

Rispondi in italiano.`,
          examples: `Crea per favore 5–10 frasi norvegesi brevi con la stessa struttura grammaticale della versione corretta. Aggiungi traduzione in italiano a ogni frase.`,
          simpler: `Spiega questo errore con parole semplici, senza terminologia grammaticale.

Mostra esempi di uso CORRETTO e SCORRETTO:
- Scorretto: [errore originale]
- Corretto: [versione corretta]
- Mostra anche: Come sarebbe diverso in un altro tipo di frase

Usa un linguaggio semplice ma mantieni la spiegazione ACCURATA.
Rispondi in italiano.`
        },
        'de': {
          sure: `Überprüfe deine Korrektur erneut. Analysiere alle Fehler und bestätige die Richtigkeit. Antworte kurz auf Deutsch.`,
          explain: `Erkläre den grammatikalischen Grund für die Korrektur.

WICHTIG: Zeige den KONTRAST mit Beispielen:
1. Wie es in DIESEM Satztyp funktioniert (wo der Fehler war)
2. Wie es sich in anderen Satztypen UNTERSCHEIDET

Beispiel Struktur:
- In Nebensätzen: "at det ikke er" (Verneinung vor dem Verb)
- In Hauptsätzen: "det er ikke" (Verneinung nach dem Verb)

Format:
- Kurze Erklärung (2-3 Sätze) mit Fokus auf den SPEZIFISCHEN Kontext
- 3-4 kontrastierende Beispiele auf Norwegisch mit deutscher Übersetzung

Mache KEINE breiten Verallgemeinerungen wie "ikke kommt immer vor dem Verb".
Erkläre die SPEZIFISCHE Regel für den SPEZIFISCHEN Kontext.

Antworte auf Deutsch.`,
          examples: `Erstelle bitte 5–10 kurze norwegische Sätze mit derselben grammatischen Struktur wie in der korrigierten Version. Füge zu jedem Satz eine deutsche Übersetzung hinzu.`,
          simpler: `Erkläre diesen Fehler mit einfachen Worten, ohne grammatikalische Terminologie.

Zeige Beispiele für RICHTIGEN und FALSCHEN Gebrauch:
- Falsch: [ursprünglicher Fehler]
- Richtig: [korrigierte Version]
- Zeige auch: Wie es in einem anderen Satztyp anders wäre

Verwende einfache Sprache, aber halte die Erklärung GENAU.
Antworte auf Deutsch.`
        }
      };

      // Simplified follow-up behavior for the "Are you sure?" button in English.
      // The model should just re-check its own correction and explanation,
      // briefly confirm if they are correct, or correct itself if needed.
      if (prompts.en) {
        prompts.en.sure = `Please check again whether your correction and your explanation above are accurate. If they are, briefly confirm that your correction is correct and why. If you see a mistake or a better correction, adjust it and explain briefly. Answer in English.`;
      }

      const langPrompts = prompts[language] || prompts['en'];

      // Use prompts directly without context duplication - AI has it in message history
      if (questionType === 'sure') {
        userPrompt = langPrompts.sure;
      } else if (questionType === 'explain') {
        userPrompt = langPrompts.explain;
      } else if (questionType === 'examples') {
        userPrompt = langPrompts.examples;
      } else if (questionType === 'simpler') {
        userPrompt = langPrompts.simpler;
      }

      // Add user message to chat history
      ChatSessionManager.addMessage('user', userPrompt);

      // Get full message history for API
      const messages = ChatSessionManager.getMessagesForAPI();

      try {
        const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
        url.searchParams.set('action', 'ai_answer_question');
        url.searchParams.set('sesskey', M.cfg.sesskey);

        const response = await fetch(url.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'ai_answer_question',
            messages: messages,  // Send full conversation history
            language: language
          })
        });

        const data = await response.json();

        if (data.answer) {
          // Add assistant response to chat history
          ChatSessionManager.addMessage('assistant', data.answer);

          answerBlock.innerHTML = `<div class="ai-answer-content">${data.answer}</div>`;
        } else {
          const errorMsg = t('ai_chat_error') || 'The AI could not answer that question.';
          answerBlock.innerHTML = `<div class="ai-answer-error">${errorMsg}</div>`;
        }
      } catch (error) {
        console.error('Error asking AI:', error);
        const errorMsg = t('ai_chat_error') || 'The AI could not answer that question.';
        answerBlock.innerHTML = `<div class="ai-answer-error">${errorMsg}</div>`;
      }
    }

    /**
     * Show success message when no errors found
     */
    function showSuccessMessage() {
      const block = $('#errorCheckBlock');
      if (!block) return;

      const noErrorsText = t('no_errors_found') || 'No errors found!';
      block.innerHTML = `<div class="error-check-success">✓ ${noErrorsText}</div>`;
      block.classList.add('error-check-visible');
      block.style.display = 'block';

      // Auto-hide after 3 seconds
      setTimeout(() => {
        block.classList.remove('error-check-visible');
        block.style.display = 'none';
      }, 3000);
    }

    /**
     * Detect grammatical constructions in sentence
     */
    async function detectConstructions(frontText, focusWord) {
      // Use interface language for explanations, NOT learning language
      const language = currentInterfaceLang || 'en';

      try {
        const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
        url.searchParams.set('action', 'ai_detect_constructions');
        url.searchParams.set('sesskey', M.cfg.sesskey);

        const response = await fetch(url.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'ai_detect_constructions',
            frontText: frontText,
            focusWord: focusWord,
            language: language
          })
        });

        const result = await response.json();
        return result;
      } catch (error) {
        console.error('Error detecting constructions:', error);
        return {
          constructions: [],
          focusConstruction: null
        };
      }
    }

    /**
     * Highlight construction tokens with different colors
     */
    function highlightConstructionTokens(constructions) {
      if (!constructions || constructions.length === 0) {
        return;
      }

      constructions.forEach((constr, index) => {
        const colorClass = `focus-chip--construction-${(index % 5) + 1}`;

        if (constr.tokenIndices && Array.isArray(constr.tokenIndices)) {
          constr.tokenIndices.forEach(tokenIdx => {
            const chips = document.querySelectorAll(`.focus-chip[data-index="${tokenIdx}"]`);
            chips.forEach(chip => {
              chip.classList.add(colorClass);
              chip.dataset.construction = index;
            });
          });
        }
      });
    }

    /**
     * Render smart sentence analysis (don't break up constructions)
     */
    function renderSmartSentenceAnalysis(constructions, tokens, translations) {
      const html = [];
      const processed = new Set();

      tokens.forEach((token, idx) => {
        if (processed.has(idx)) {
          return;
        }

        // Check if this token is part of a construction
        const construction = constructions.find(c =>
          c.tokenIndices && c.tokenIndices.includes(idx)
        );

        if (construction) {
          // This is part of a construction - show whole construction
          const constrId = constructions.indexOf(construction) + 1;
          html.push(`
            <div class="analysis-item analysis-item--construction">
              <span class="analysis-text construction-highlight-${constrId}">
                ${construction.normalized || construction.tokens.join(' ')}
              </span>
              <span class="analysis-translation">
                ${construction.translation || ''}
              </span>
            </div>
          `);

          // Mark all tokens of this construction as processed
          if (construction.tokenIndices) {
            construction.tokenIndices.forEach(i => processed.add(i));
          }
        } else {
          // Regular word - show separately
          const translation = translations[idx] || '';
          html.push(`
            <div class="analysis-item">
              <span class="analysis-text">${token.text || token}</span>
              <span class="analysis-translation">${translation}</span>
            </div>
          `);
          processed.add(idx);
        }
      });

      const analysisList = $('#focusAnalysisList');
      if (analysisList) {
        analysisList.innerHTML = html.join('');
      }
    }

    // ==========================================================================
    // EVENT HANDLERS - SETUP
    // ==========================================================================

    /**
     * Setup event handler for check text button
     */
    const checkTextBtn = document.getElementById('checkTextBtn');
    if (checkTextBtn) {
      checkTextBtn.addEventListener('click', (e) => {
        e.preventDefault();
        checkTextForErrors();
      });
    }

  }
export { flashcardsInit };
