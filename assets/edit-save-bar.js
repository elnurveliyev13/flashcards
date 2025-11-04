// Floating save bar for the editor: mirrors Update Previous / Add as New
(function(){
  function once(id){ return document.getElementById(id); }
  function ready(fn){ if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }

  function findRoot(){ return document.getElementById('mod_flashcards_container'); }
  function $(s, root){ return (root||document).querySelector(s); }

  function install(){
    var root = findRoot(); if(!root) return false;
    var formWrap = once('cardCreationFormWrap');
    var btnUpdate = once('btnUpdate');
    var btnAdd = once('btnAdd');
    if(!formWrap || !btnUpdate || !btnAdd) return false;

    // Create bar if not present
    var bar = once('editActionsBar');
    if(!bar){
      bar = document.createElement('div');
      bar.id = 'editActionsBar';
      bar.className = 'bottom-actions hidden';
      bar.style.zIndex = '1001';
      bar.style.bottom = '0'; // pin to true bottom (rating bar hidden while editing)
      bar.setAttribute('role','toolbar');
      bar.setAttribute('aria-label','Edit actions');
      bar.innerHTML = ''+
        '<button id="saveBarUpdate" class="ok">'+(btnUpdate.textContent||'Update')+'</button>'+
        '<button id="saveBarAdd" class="mid">'+(btnAdd.textContent||'Create new')+'</button>';
      (root||document.body).appendChild(bar);
    }

    // Helper to strip any pre-existing listeners
    // Also hide inline row to avoid duplication
    try{ var inlineRow = btnUpdate && btnUpdate.closest('.row2'); if(inlineRow){ inlineRow.style.display='none'; } }catch(_e){}
    function reset(b){ if(!b) return null; var c=b.cloneNode(true); b.parentNode.replaceChild(c,b); return c; }
    var sbUpdate = reset(once('saveBarUpdate'));
    var sbAdd = reset(once('saveBarAdd'));

    // Bridge to original buttons
    if(sbUpdate){ sbUpdate.addEventListener('click', function(e){ try{e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}catch(_){} btnUpdate.click(); }, true); }
    if(sbAdd){ sbAdd.addEventListener('click', function(e){ try{e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}catch(_){} btnAdd.click(); }, true); }

    // Visibility: show on Quick Input tab (always, regardless of form state)
    function refresh(){
      var isQuickInputActive = (function(){ try{ var q=document.getElementById('quickInputSection'); return q && q.classList.contains('fc-tab-active'); }catch(e){ return false; } })();
      var visible = isQuickInputActive;
      bar.classList.toggle('hidden', !visible);
      // Show bar attribute for coordination
      if(root){ if(visible){ root.setAttribute('data-edit-bar-visible','1'); } else { root.removeAttribute('data-edit-bar-visible'); } }
      // Mirror disabled state
      try{ sbUpdate.disabled = !!btnUpdate.disabled; }catch(_e){}
    }
    refresh();

    // Observe form or grid changes to toggle visibility
    try{ new MutationObserver(refresh).observe(formWrap, {attributes:true, attributeFilter:['class']}); }catch(_e){}
    try{ var grid=$('.grid', root); if(grid){ new MutationObserver(refresh).observe(grid, {attributes:true, attributeFilter:['class']}); } }catch(_e){}
    // Also observe button disabled state
    try{ new MutationObserver(refresh).observe(btnUpdate, {attributes:true, attributeFilter:['disabled']}); }catch(_e){}
    try{ var qis=document.getElementById('quickInputSection'); if(qis){ new MutationObserver(refresh).observe(qis, {attributes:true, attributeFilter:['class']}); } }catch(_e){}

    return true;
  }

  function boot(){ if(install()) return; var tries=0; var t=setInterval(function(){ if(install()||++tries>150){ clearInterval(t); } }, 100); }
  ready(boot);
})();



