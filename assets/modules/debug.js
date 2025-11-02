(function(root){
  const namespace = root.ModFlashcards || (root.ModFlashcards = {});

  function log(enabled, message){
    if(!enabled) return;
    try { console.log('[Flashcards]', message); } catch(_e){}
  }

  namespace.Debug = {
    log
  };
})(window);
