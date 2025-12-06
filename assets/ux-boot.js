(function(){
  function enable(){
    try{
      var root = document.getElementById('mod_flashcards_container');
      if(!root) return false;
      // Ensure theme-color and dark page background to avoid white bars in PWA/browsers
      try{
        if(!document.querySelector('meta[name="theme-color"]')){
          var tc=document.createElement('meta'); tc.name='theme-color'; tc.content='#0f172a'; document.head.appendChild(tc);
        }
        if(!document.getElementById('ux-global-bg')){
          var gb=document.createElement('style'); gb.id='ux-global-bg'; gb.textContent='html,body{background:#0f172a !important;min-height:100svh;}'; document.head.appendChild(gb);
        }
      }catch(_e){}
      // Ensure bottom bar feature flag is enabled even if UX script loads late
      root.setAttribute('data-ux-bottom','1');
      // Hide only the in-card rating buttons (keep edit form buttons visible)
      // If the bar exists, make sure it’s not hidden by default class
      ['#bottomActions', '#metaPanel'].forEach(function(selector){
        var element = root.querySelector(selector);
        if(element){
          element.classList.remove('hidden');
          element.style.display='';
        }
      });
      return true;
    }catch(_e){ return false; }
  }
  function boot(){
    if(enable()) return;
    var tries = 0; var t = setInterval(function(){ if(enable() || ++tries>100){ clearInterval(t); } }, 100);
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
