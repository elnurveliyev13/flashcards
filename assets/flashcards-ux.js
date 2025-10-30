// UX Improvements for Flashcards App
// This file extends the main flashcards.js with better UI/UX

(function(){
  // Wait for flashcardsInit to be ready
  const originalInit = window.flashcardsInit;
  if(!originalInit) {
    console.error('[Flashcards UX] flashcardsInit not found');
    return;
  }

  window.flashcardsInit = function(rootid, baseurl, cmid, instanceid, sesskey, globalMode){
    // Call original init first
    originalInit(rootid, baseurl, cmid, instanceid, sesskey, globalMode);

    const root = document.getElementById(rootid);
    if(!root) return;
    const $ = s => root.querySelector(s);

    console.log('[Flashcards UX] Initializing improvements...');

    // Wait for DOM to be ready
    setTimeout(function(){
      // 1. Fixed bottom action bar handlers
      const btnEasyBottom = $("#btnEasyBottom");
      const btnNormalBottom = $("#btnNormalBottom");
      const btnHardBottom = $("#btnHardBottom");
      const bottomActions = $("#bottomActions");

      const btnEasy = $("#btnEasy");
      const btnNormal = $("#btnNormal");
      const btnHard = $("#btnHard");

      if(btnEasyBottom && btnEasy){
        btnEasyBottom.addEventListener("click", function(){ btnEasy.click(); });
      }
      if(btnNormalBottom && btnNormal){
        btnNormalBottom.addEventListener("click", function(){ btnNormal.click(); });
      }
      if(btnHardBottom && btnHard){
        btnHardBottom.addEventListener("click", function(){ btnHard.click(); });
      }

      // 2. Collapsible form toggle
      const btnToggleForm = $("#btnToggleForm");
      const cardCreationFormWrap = $("#cardCreationFormWrap");

      if(btnToggleForm && cardCreationFormWrap){
        btnToggleForm.addEventListener("click", function(){
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
            }
          }
        });
      }

      // 3. Update progress bar on queue changes
      const originalDue = $("#due");
      if(originalDue){
        const observer = new MutationObserver(function(mutations){
          updateProgressBar();
          updateBottomBar();
        });
        observer.observe(originalDue, {childList: true, characterData: true, subtree: true});
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

      console.log('[Flashcards UX] Improvements ready!');
    }, 500);
  };
})();
