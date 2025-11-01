(function(){
  function enable(){
    try{
      var root = document.getElementById('mod_flashcards_container');
      if(!root) return false;
      // Ensure bottom bar feature flag is enabled even if UX script loads late
      root.setAttribute('data-ux-bottom','1');
      // Hide only the in-card rating buttons (keep edit form buttons visible)
      var s=document.getElementById('ux-boot-hide-rating');
      if(!s){
        s=document.createElement('style');
        s.id='ux-boot-hide-rating';
        s.textContent = '#mod_flashcards_container[data-ux-bottom="1"] #btnEasy,\
 #mod_flashcards_container[data-ux-bottom="1"] #btnNormal,\
 #mod_flashcards_container[data-ux-bottom="1"] #btnHard{display:none !important}';
        (document.head||document.documentElement).appendChild(s);
      }
      // If the bar exists, make sure it’s not hidden by default class
      var bar = root.querySelector('#bottomActions');
      if(bar){ bar.classList.remove('hidden'); }
      return true;
    }catch(_e){ return false; }
  }
  function boot(){
    if(enable()) return;
    var tries = 0; var t = setInterval(function(){ if(enable() || ++tries>100){ clearInterval(t); } }, 100);
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

