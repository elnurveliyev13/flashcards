// One-time lightweight iOS install guide modal
(function(){
  // Proactively suppress the legacy banner to avoid flicker
  try{
    var style = document.createElement('style');
    style.textContent = '#iosInstallHint{display:none !important}';
    (document.head || document.documentElement).appendChild(style);
  }catch(_e){}

  function init(){
    try{
      var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
      var isStandalone = ('standalone' in window.navigator) && window.navigator.standalone;
      var key = 'ios-install-guide-seen';
      if(!isIOS || isStandalone) return;

      // Ensure legacy banner stays hidden to avoid header clutter
      try{
        var legacy = document.getElementById('iosInstallHint');
        if(legacy) legacy.classList.add('hidden');
      }catch(_e){}

      if(localStorage.getItem(key) === '1') return;

      // Build modal on demand (reuses .modal styles from template)
      var root = document.getElementById('mod_flashcards_container') || document.body;
      var modal = document.createElement('div');
      modal.id = 'iosInstallModal';
      modal.className = 'modal';
      modal.setAttribute('role','dialog');
      modal.setAttribute('aria-modal','true');
      modal.setAttribute('aria-labelledby','iosInstallModalTitle');
      modal.style.display = 'none';

      var gs = function(key){
        try{ return (window.M && M.util && M.util.get_string) ? M.util.get_string(key, 'mod_flashcards') : null; }catch(_e){ return null; }
      };
      var box = document.createElement('div');
      box.className = 'box';
      var tTitle = gs('ios_install_title') || 'iOS users: Install this app';
      var tS1 = gs('ios_install_step1') || 'Tap the';
      var tS2 = gs('ios_install_step2') || 'button, then select';
      var tShare = gs('ios_share_button') || 'Share';
      var tAdd = gs('ios_add_to_home') || 'Add to Home Screen';
      var tClose = gs('close') || 'Close';
      box.innerHTML = ''+
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;">'+
          '<h3 id="iosInstallModalTitle" style="margin:0;font-size:16px;line-height:1.2;">'+tTitle+'</h3>'+
          '<button id="iosInstallModalClose" class="iconbtn" title="'+tClose+'" aria-label="'+tClose+'">&times;</button>'+
        '</div>'+
        '<div style="font-size:14px;color:#cbd5e1">'+
          '<span>'+tS1+'</span> '+
          '<span class="guide-step">'+tShare+'</span> '+
          '<span>'+tS2+'</span> '+
          '<span class="guide-step">'+tAdd+'</span>'+
        '</div>';
      modal.appendChild(box);

      modal.addEventListener('click', function(e){ if(e.target === modal){ modal.style.display='none'; }});
      root.appendChild(modal);

      // Close button hookup
      setTimeout(function(){
        var closeBtn = document.getElementById('iosInstallModalClose');
        if(closeBtn){ closeBtn.addEventListener('click', function(){ modal.style.display='none'; }); }
      }, 0);

      // Show once with a tiny delay, then mark seen so it never auto-appears again
      setTimeout(function(){ modal.style.display='flex'; }, 500);
      localStorage.setItem(key, '1');
      
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
