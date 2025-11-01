// One-time lightweight iOS install guide modal
(function(){
  function init(){
    try{
      var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
      var isStandalone = ('standalone' in window.navigator) && window.navigator.standalone;
      if(!isIOS || isStandalone) return;

      // Always show lightweight banner each session until installed
      try{ localStorage.removeItem('ios-install-hint-dismissed'); }catch(_e){}
      var hint = document.getElementById('iosInstallHint');
      if(hint){
        hint.classList.remove('hidden');
        // Ensure visible even if earlier code hid it
        hint.style.display = '';
        // Intercept close to hide only for current session (do not persist dismissal)
        var closeBtn = document.getElementById('iosHintClose');
        if(closeBtn){
          closeBtn.addEventListener('click', function(ev){
            try{ ev.stopImmediatePropagation(); ev.preventDefault(); }catch(_e){}
            try{ localStorage.removeItem('ios-install-hint-dismissed'); }catch(_e){}
            hint.classList.add('hidden');
          }, true);
        }
      }

    }catch(e){
      try{ console.log('[iOS Guide] error', e); }catch(_e){}
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
