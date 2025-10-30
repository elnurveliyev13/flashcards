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

    // 1. Fixed bottom action bar handlers
    const btnEasyBottom = $("#btnEasyBottom");
    const btnNormalBottom = $("#btnNormalBottom");
    const btnHardBottom = $("#btnHardBottom");
    const bottomActions = $("#bottomActions");

    const btnEasy = $("#btnEasy");
    const btnNormal = $("#btnNormal");
    const btnHard = $("#btnHard");

    console.log('[Flashcards UX] Bottom buttons found:', !!btnEasyBottom, !!btnNormalBottom, !!btnHardBottom);
    console.log('[Flashcards UX] Original buttons found:', !!btnEasy, !!btnNormal, !!btnHard);

    if(btnEasyBottom && btnEasy){
      btnEasyBottom.addEventListener("click", function(e){ 
        console.log('[Flashcards UX] Easy bottom clicked');
        e.preventDefault();
        e.stopPropagation();
        btnEasy.click(); 
      });
      console.log('[Flashcards UX] Easy handler attached');
    }
    
    if(btnNormalBottom && btnNormal){
      btnNormalBottom.addEventListener("click", function(e){ 
        console.log('[Flashcards UX] Normal bottom clicked');
        e.preventDefault();
        e.stopPropagation();
        btnNormal.click(); 
      });
      console.log('[Flashcards UX] Normal handler attached');
    }
    
    if(btnHardBottom && btnHard){
      btnHardBottom.addEventListener("click", function(e){ 
        console.log('[Flashcards UX] Hard bottom clicked');
        e.preventDefault();
        e.stopPropagation();
        btnHard.click(); 
      });
      console.log('[Flashcards UX] Hard handler attached');
    }

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
            icon.textContent = "➕";
            label.textContent = "Add New Card";
          } else {
            icon.textContent = "➖";
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
    const originalDue = $("#due");
    if(originalDue){
      const observer = new MutationObserver(function(mutations){
        updateProgressBar();
        updateBottomBar();
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
          progressTotal.textContent = count;
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
        } else {
          bottomActions.classList.add("hidden");
        }
      }
    }

    // Initial update
    updateProgressBar();
    updateBottomBar();

    // 4. Auto-hide iOS hint after 10 seconds
    const iosHint = $("#iosInstallHint");
    if(iosHint && !iosHint.classList.contains("hidden")){
      setTimeout(function(){
        iosHint.classList.add("hidden");
      }, 10000);
    }

    console.log('[Flashcards UX] ✅ All improvements initialized!');
  }

  // Start waiting for app
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', waitForApp);
  } else {
    waitForApp();
  }
})();
