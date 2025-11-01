(function(){
  function ready(fn){ if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }
  ready(function(){
    try{
      var root = document.getElementById('mod_flashcards_container');
      if(root){
        // Ensure bottom bar feature flag is enabled even if UX script loads late
        root.setAttribute('data-ux-bottom','1');
        // Prefer the bottom bar UI; hide original row proactively as a safety net
        var s=document.createElement('style');
        s.textContent = '#mod_flashcards_container .row2{display:none !important}';
        (document.head||document.documentElement).appendChild(s);
        // If the bar exists, make sure it’s not hidden by default class
        var bar = root.querySelector('#bottomActions');
        if(bar){ bar.classList.remove('hidden'); }
      }
    }catch(_e){}
  });
})();

