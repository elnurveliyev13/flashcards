(function(){
  try{
    const current = document.currentScript;
    const srcUrl = current ? new URL(current.src, window.location.href) : null;
    const base = srcUrl ? srcUrl.href.replace(/\/[^\/]*$/, '/') : '/mod/flashcards/assets/';
    const version = srcUrl ? (srcUrl.searchParams.get('v') || '') : '';
    const query = version ? `?v=${version}` : '';
    const moduleUrl = `${base}flashcards.js${query}`;

    const queue = window.__flashcardsInitQueue = window.__flashcardsInitQueue || [];
    if(typeof window.flashcardsInit !== 'function'){
      window.flashcardsInit = function(...args){
        queue.push(args);
      };
    }

    import(moduleUrl).then(mod => {
      if(!mod || typeof mod.flashcardsInit !== 'function'){
        throw new Error('flashcards.js did not export flashcardsInit');
      }
      window.flashcardsInit = mod.flashcardsInit;
      while(queue.length){
        const args = queue.shift();
        try{
          mod.flashcardsInit(...args);
        }catch(err){
          console.error('Flashcards init failed', err);
        }
      }
    }).catch(err => {
      console.error('Failed to load Flashcards app module', err);
    });
  }catch(err){
    console.error('Flashcards bootstrap failed', err);
  }
})();
