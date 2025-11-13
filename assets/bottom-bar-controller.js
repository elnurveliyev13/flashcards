// Unified Bottom Bar Controller - manages rating and edit bars
// Replaces edit-save-bar.js and bottom bar logic from flashcards-ux.js
(function(){
  'use strict';

  function BottomBarController(rootId){
    this.rootId = rootId;
    this.root = null;
    this.ratingBar = null;
    this.editBar = null;
    this.currentMode = null;
    this.state = {
      dueCount: 0,
      updateDisabled: false
    };
  }

  BottomBarController.prototype.init = function(){
    this.root = document.getElementById(this.rootId);
    if(!this.root) return false;

    // Find or create rating bar (Easy/Normal/Hard)
    this.ratingBar = document.getElementById('bottomActions');
    if(!this.ratingBar){
      console.warn('bottomActions not found - rating bar unavailable');
    }

    // Find or create edit bar (Update/Create new)
    this._createEditBar();

    // Bind events
    this._bindEvents();

    // Observe tab changes
    this._observeTabs();

    // Initial update
    this._updateVisibility();

    return true;
  };

  BottomBarController.prototype._createEditBar = function(){
    var btnUpdate = document.getElementById('btnUpdate');
    var btnAdd = document.getElementById('btnAdd');
    if(!btnUpdate || !btnAdd) return;

    // Helper encoders for safe innerHTML injection
    var escapeAttr = function(value){
      return (value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    };
    var escapeHtml = function(value){
      return (value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    };

    // Check if bar already exists
    this.editBar = document.getElementById('editActionsBar');
    if(!this.editBar){
      var updateLabel = escapeHtml(btnUpdate.textContent || 'Update');
      var updateDisabledLabel = btnUpdate.getAttribute('data-disabled-label') || '';
      var updateTitle = btnUpdate.getAttribute('title') || '';
      var addLabel = escapeHtml(btnAdd.textContent || 'Create new');
      var updateAttrs = ' data-role="update"';
      if(updateDisabledLabel){
        updateAttrs += ' data-disabled-label="' + escapeAttr(updateDisabledLabel) + '"';
      }
      if(updateTitle){
        updateAttrs += ' title="' + escapeAttr(updateTitle) + '"';
      }
      this.editBar = document.createElement('div');
      this.editBar.id = 'editActionsBar';
      this.editBar.className = 'bottom-actions hidden';
      this.editBar.style.zIndex = '1001';
      this.editBar.style.bottom = '0';
      this.editBar.setAttribute('role','toolbar');
      this.editBar.setAttribute('aria-label','Edit actions');
      this.editBar.innerHTML = ''+
        '<button id="saveBarUpdate" class="ok"' + updateAttrs + '>' + updateLabel + '</button>'+
        '<button id="saveBarAdd" class="mid">' + addLabel + '</button>';
      this.root.appendChild(this.editBar);
    }

    // Hide inline row2 to avoid duplication
    try{
      var inlineRow = btnUpdate.closest('.row2');
      if(inlineRow){ inlineRow.style.display='none'; }
    }catch(e){}

    // Bridge edit bar buttons to original buttons
    var self = this;
    var saveBarUpdate = document.getElementById('saveBarUpdate');
    var saveBarAdd = document.getElementById('saveBarAdd');

    if(saveBarUpdate){
      // Remove old listeners by cloning
      var newUpdate = saveBarUpdate.cloneNode(true);
      saveBarUpdate.parentNode.replaceChild(newUpdate, saveBarUpdate);
      newUpdate.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        btnUpdate.click();
      });
      // Sync disabled state
      this._syncDisabled(btnUpdate, newUpdate);
    }

    if(saveBarAdd){
      var newAdd = saveBarAdd.cloneNode(true);
      saveBarAdd.parentNode.replaceChild(newAdd, saveBarAdd);
      newAdd.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        btnAdd.click();
      });
    }
  };

  BottomBarController.prototype._syncDisabled = function(original, mirror){
    var sync = function(){
      try{ mirror.disabled = !!original.disabled; }catch(e){}
    };
    sync();
    try{
      new MutationObserver(sync).observe(original, {attributes:true, attributeFilter:['disabled']});
    }catch(e){}
  };

  BottomBarController.prototype._bindEvents = function(){
    // Due count tracking removed (studyDueCount element no longer exists)
  };

  BottomBarController.prototype._observeTabs = function(){
    var self = this;
    var sections = ['studySection', 'quickInputSection', 'dashboardSection'];
    sections.forEach(function(id){
      var section = document.getElementById(id);
      if(section){
        try{
          new MutationObserver(function(){
            self._updateVisibility();
          }).observe(section, {attributes:true, attributeFilter:['class']});
        }catch(e){}
      }
    });
  };

  BottomBarController.prototype._updateVisibility = function(){
    var studyActive = this._isTabActive('studySection');
    var quickInputActive = this._isTabActive('quickInputSection');
    var dashboardActive = this._isTabActive('dashboardSection');

    // Determine mode based on active tab
    var newMode = null;
    if(studyActive){
      newMode = 'rating';
    } else if(quickInputActive){
      newMode = 'edit';
    } else {
      newMode = 'hidden';
    }

    this.setMode(newMode);
  };

  BottomBarController.prototype._isTabActive = function(sectionId){
    try{
      var section = document.getElementById(sectionId);
      return section && section.classList.contains('fc-tab-active');
    }catch(e){
      return false;
    }
  };

  BottomBarController.prototype.setMode = function(mode){
    this.currentMode = mode;

    // Hide all bars first
    if(this.ratingBar){ this.ratingBar.classList.add('hidden'); }
    if(this.editBar){ this.editBar.classList.add('hidden'); }
    if(this.root){ this.root.removeAttribute('data-bottom-visible'); this.root.removeAttribute('data-edit-bar-visible'); }

    // Show appropriate bar
    if(mode === 'rating' && this.ratingBar){
      this.ratingBar.classList.remove('hidden');
      if(this.root){ this.root.setAttribute('data-bottom-visible','1'); }
      this._updateRatingButtons();
    } else if(mode === 'edit' && this.editBar){
      this.editBar.classList.remove('hidden');
      if(this.root){ this.root.setAttribute('data-edit-bar-visible','1'); }
    }
  };

  BottomBarController.prototype._updateRatingButtons = function(){
    if(!this.ratingBar) return;

    // Note: studyDueCount element removed, buttons enabled by default
    console.log('[BottomBarController] _updateRatingButtons: studyDueCount element no longer exists');

    // Disable buttons when nothing due (count determined by queue in main app)
    var buttons = ['btnEasyBottom', 'btnNormalBottom', 'btnHardBottom'];
    buttons.forEach(function(id){
      var btn = document.getElementById(id);
      if(btn){
        // Buttons will be managed by main flashcards.js logic
        console.log('[BottomBarController]', id, 'state =', btn.disabled ? 'disabled' : 'enabled');
      }
    });
  };

  // Helper to wait for DOM and retry init
  function boot(){
    function ready(fn){
      if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', fn);
      } else {
        fn();
      }
    }

    ready(function(){
      var controller = new BottomBarController('mod_flashcards_container');
      var tries = 0;
      var maxTries = 150;

      function tryInit(){
        if(controller.init()){
          // Success - expose globally for debugging
          window.flashcardsBottomBarController = controller;
          return true;
        }
        tries++;
        if(tries < maxTries){
          setTimeout(tryInit, 100);
        } else {
          console.warn('BottomBarController: failed to initialize after ' + maxTries + ' attempts');
        }
        return false;
      }

      tryInit();
    });
  }

  boot();
})();
