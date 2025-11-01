// UX Improvements for Flashcards App
// Runs after main app initialization

(function(){
  console.log('[Flashcards UX] Script loaded');

  // Wait for main app to initialize
  function waitForApp(){
    const root = document.getElementById('mod_flashcards_container');
    if(!root || !document.querySelector('#btnEasy')) {
      setTimeout(waitForApp, 100);
      return;
    }
    initUX();
  }

  function initUX(){
    const root = document.getElementById('mod_flashcards_container');
    const $ = s => root.querySelector(s);

    console.log('[Flashcards UX] Initializing improvements...');
    // Detect iOS and mark root
    try{ if(/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream){ root.setAttribute('data-platform-ios','1'); } }catch(_e){}
    // Hide original rating row when bottom bar is visible
    try{
      var _style = document.createElement('style');
      _style.textContent = '#mod_flashcards_container[data-bottom-visible="1"] .row2{display:none !important}\n#mod_flashcards_container[data-platform-ios="1"] .row2{display:none !important}';
      (document.head || document.documentElement).appendChild(_style);
    }catch(_e){}
    // Feature flag: enable bottom bar when query ?uxbar=1 or localStorage flag
    try{
      var _p = new URLSearchParams(location.search).get('uxbar');
      if((_p && _p !== '0') || localStorage.getItem('srs-ux-bottom') === '1'){
        const _root = document.getElementById('mod_flashcards_container');
        if(_root) _root.setAttribute('data-ux-bottom','1');
      }
    }catch(_e){}

    

    // Enable bottom bar by default; opt-out with ?uxbar=0 or localStorage '0'
    try{
      const __root=document.getElementById('mod_flashcards_container');
      const __p=new URLSearchParams(location.search).get('uxbar');
      if(__root && !__root.hasAttribute('data-ux-bottom')){
        if(__p !== '0' && localStorage.getItem('srs-ux-bottom') !== '0'){ __root.setAttribute('data-ux-bottom','1'); }
      }
    }catch(_e){}

    // 1. Fixed bottom action bar (bindings handled in assets/flashcards.js)
    const bottomActions = $("#bottomActions");

    // 2. Collapsible form toggle
    const btnToggleForm = $("#btnToggleForm");
    const cardCreationFormWrap = $("#cardCreationFormWrap");

    console.log('[Flashcards UX] Form toggle found:', !!btnToggleForm, !!cardCreationFormWrap);

    if(btnToggleForm && cardCreationFormWrap){
      btnToggleForm.addEventListener("click", function(e){
        console.log('[Flashcards UX] Form toggle clicked');
        e.preventDefault();
        e.stopPropagation();
        
        cardCreationFormWrap.classList.toggle("card-form-collapsed");

        // Change icon when expanded
        const icon = btnToggleForm.querySelector(".icon");
        const label = btnToggleForm.querySelector("div:last-child");
        if(icon && label){
          if(cardCreationFormWrap.classList.contains("card-form-collapsed")){
            icon.textContent = "\u2795";
            label.textContent = "Add New Card";
          } else {
            icon.textContent = "\u2796";
            label.textContent = "Hide Form";
            // Scroll form into view
            setTimeout(function(){
              cardCreationFormWrap.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }, 100);
          }
        }
      });
      console.log('[Flashcards UX] Form toggle handler attached');
    }

    // 3. Update progress bar on queue changes
    const originalDue = root.querySelector("#due");
    if(originalDue){
      const observer = new MutationObserver(function(mutations){
        updateProgressBar();
        updateBottomBarPlus();
      });
      observer.observe(originalDue, {childList: true, characterData: true, subtree: true});
      console.log('[Flashcards UX] Progress observer attached');
    }

    function updateProgressBar(){
      const progressBar = $("#progressBar");
      const progressCurrent = $("#progressCurrent");
      const progressTotal = $("#progressTotal");
      const dueCount = $("#due");

      if(progressBar && progressCurrent && progressTotal && dueCount){
        const count = parseInt(dueCount.textContent) || 0;
        if(count > 0){
          progressBar.classList.remove("hidden");
          progressCurrent.textContent = count;
          const total = parseInt(progressBar.dataset.total || progressTotal.textContent) || count;
          progressTotal.textContent = total;
        } else {
          progressBar.classList.add("hidden");
        }
      }
    }

    function updateBottomBar(){
      const dueCount = $("#due");
      if(bottomActions && dueCount){
        const count = parseInt(dueCount.textContent) || 0;
        if(count > 0){
          bottomActions.classList.remove("hidden");
          if(root){ root.setAttribute('data-bottom-visible','1'); }
        } else {
          bottomActions.classList.add("hidden");
          if(root){ root.removeAttribute('data-bottom-visible'); }
        }
      }
    }

    function updateBottomBarPlus(){ updateBottomBar();
      const formWrap = document.getElementById('cardCreationFormWrap');
      const listModal = document.getElementById('listModal');
      const fieldPrompt = document.getElementById('fieldPrompt');
      const bar = document.getElementById('bottomActions');
      const overlaysOpen = !!((listModal && listModal.style.display==='flex') || (fieldPrompt && fieldPrompt.style.display==='flex') || (formWrap && !formWrap.classList.contains('card-form-collapsed')));
      if(bar){
        bar.classList.toggle('hidden', bar.classList.contains('hidden') || overlaysOpen);
        var visible = !bar.classList.contains('hidden');
        if(root){ root.toggleAttribute && root.toggleAttribute('data-bottom-visible', visible); if(!root.toggleAttribute){ if(visible){ root.setAttribute('data-bottom-visible','1'); } else { root.removeAttribute('data-bottom-visible'); } } }
        if(visible){ try{ window.dispatchEvent(new Event('resize')); }catch(_e){} }
      }
    }

    // Initial update
    updateProgressBar();
    updateBottomBarPlus();

    try{ const _wrap=document.getElementById('cardCreationFormWrap'); if(_wrap){ new MutationObserver(()=>updateBottomBarPlus()).observe(_wrap,{attributes:true, attributeFilter:['class']}); } }catch(_e){}
    try{ const _lm=document.getElementById('listModal'); if(_lm){ new MutationObserver(()=>updateBottomBarPlus()).observe(_lm,{attributes:true, attributeFilter:['style','class']}); } }catch(_e){}
    try{ const _fp=document.getElementById('fieldPrompt'); if(_fp){ new MutationObserver(()=>updateBottomBarPlus()).observe(_fp,{attributes:true, attributeFilter:['style','class']}); } }catch(_e){}

    // 4. No auto-hide for iOS hint (per UX request) – handled elsewhere

    console.log('[Flashcards UX] All improvements initialized!');

    // 5. Fullscreen toggle (native + fallback)
    const btnFullscreen = $("#btnFullscreen");
    if(btnFullscreen){
      const updateIcon = () => {
        const active = !!document.fullscreenElement || root.classList.contains('fs-active');
        btnFullscreen.textContent = active ? '?' : '?';
        btnFullscreen.title = active ? 'Exit full screen' : 'Fullscreen';
        btnFullscreen.setAttribute('aria-label', btnFullscreen.title);
      };
      const enablePseudo = () => {
        root.classList.add('fs-active');
        try { document.documentElement.style.overflow = 'hidden'; } catch(e){}
        updateIcon();
      };
      const disablePseudo = () => {
        root.classList.remove('fs-active');
        try { document.documentElement.style.overflow = ''; } catch(e){}
        updateIcon();
      };
      btnFullscreen.addEventListener('click', function(e){
        e.preventDefault(); e.stopPropagation();
        const isActive = !!document.fullscreenElement || root.classList.contains('fs-active');
        if(isActive){
          if(document.fullscreenElement){ try{ document.exitFullscreen(); }catch(e){} }
          disablePseudo();
        } else {
          // Always enable pseudo first for instant effect, then try native fullscreen
          enablePseudo();
          if(root.requestFullscreen){ try{ root.requestFullscreen(); }catch(_e){}
          }
        }
      });
      document.addEventListener('fullscreenchange', function(){
        if(document.fullscreenElement === root){ root.classList.add('fs-active'); }
        if(!document.fullscreenElement){ disablePseudo(); }
        updateIcon();
      });
      updateIcon();
    }
  }

  // Start waiting for app
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', waitForApp);
  } else {
    waitForApp();
  }
})();
