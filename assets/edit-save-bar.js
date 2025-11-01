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
      bar.style.bottom = '64px'; // stack above rating bar
      bar.setAttribute('role','toolbar');
      bar.setAttribute('aria-label','Edit actions');
      bar.innerHTML = ''+
        '<button id="saveBarUpdate" class="ok">'+(btnUpdate.textContent||'Update Previous')+'</button>'+
        '<button id="saveBarAdd" class="mid">'+(btnAdd.textContent||'Add as New')+'</button>';
      (root||document.body).appendChild(bar);
    }

    // Helper to strip any pre-existing listeners
    function reset(b){ if(!b) return null; var c=b.cloneNode(true); b.parentNode.replaceChild(c,b); return c; }
    var sbUpdate = reset(once('saveBarUpdate'));
    var sbAdd = reset(once('saveBarAdd'));

    // Bridge to original buttons
    if(sbUpdate){ sbUpdate.addEventListener('click', function(e){ try{e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}catch(_){} btnUpdate.click(); }, true); }
    if(sbAdd){ sbAdd.addEventListener('click', function(e){ try{e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}catch(_){} btnAdd.click(); }, true); }

    // Visibility: show when editing form is expanded or in edit-mode
    function refresh(){
      var grid = $('.grid', root);
      var formOpen = formWrap && !formWrap.classList.contains('card-form-collapsed');
      var editMode = grid && grid.classList.contains('edit-mode');
      var visible = !!(formOpen || editMode);
      bar.classList.toggle('hidden', !visible);
      // Mirror disabled state
      try{ sbUpdate.disabled = !!btnUpdate.disabled; }catch(_e){}
    }
    refresh();

    // Observe form or grid changes to toggle visibility
    try{ new MutationObserver(refresh).observe(formWrap, {attributes:true, attributeFilter:['class']}); }catch(_e){}
    try{ var grid=$('.grid', root); if(grid){ new MutationObserver(refresh).observe(grid, {attributes:true, attributeFilter:['class']}); } }catch(_e){}
    // Also observe button disabled state
    try{ new MutationObserver(refresh).observe(btnUpdate, {attributes:true, attributeFilter:['disabled']}); }catch(_e){}

    return true;
  }

  function boot(){ if(install()) return; var tries=0; var t=setInterval(function(){ if(install()||++tries>150){ clearInterval(t); } }, 100); }
  ready(boot);
})();

