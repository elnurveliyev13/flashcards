// UX Improvements for Flashcards App
// Runs after main app initialization

(function(){

  // Wait for main app to initialize
  function waitForApp(){
    const root = document.getElementById('mod_flashcards_container');
    if(!root || !document.querySelector('#btnEasyBottom')) {
      setTimeout(waitForApp, 100);
      return;
    }
    initUX();
  }

  function initUX(){
    const root = document.getElementById('mod_flashcards_container');
    const $ = s => root.querySelector(s);

    // Detect iOS and mark root
    try{ if(/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream){ root.setAttribute('data-platform-ios','1'); } }catch(_e){}
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
      if(__root){
        if(__p !== '0' && localStorage.getItem('srs-ux-bottom') !== '0'){ __root.setAttribute('data-ux-bottom','1'); }
        else if(!__root.hasAttribute('data-ux-bottom')){ __root.setAttribute('data-ux-bottom','1'); }
      }
    }catch(_e){}

    // 1. Collapsible form toggle
    const btnToggleForm = $("#btnToggleForm");
    const cardCreationFormWrap = $("#cardCreationFormWrap");


    if(btnToggleForm && cardCreationFormWrap){
      btnToggleForm.addEventListener("click", function(e){
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
    }

    // 2. Update progress bar on queue changes
    const originalDue = root.querySelector("#due");
    if(originalDue){
      const observer = new MutationObserver(function(mutations){
        updateProgressBar();
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
          const total = parseInt(progressBar.dataset.total || progressTotal.textContent) || count;
          progressTotal.textContent = total;
        } else {
          progressBar.classList.add("hidden");
        }
      }
    }

    // Initial update
    updateProgressBar();

    // 3. No auto-hide for iOS hint (per UX request) – handled elsewhere


    // 4. Fullscreen toggle (native + fallback)
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
