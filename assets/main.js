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

    const supportsModules = (function(){
      const s = document.createElement('script');
      return 'noModule' in s;
    })();

    if(!supportsModules){
      console.error('Flashcards requires a browser with ES module support.');
      return;
    }

    const moduleScript = document.createElement('script');
    moduleScript.type = 'module';
    moduleScript.textContent = `
import { flashcardsInit as init } from '${moduleUrl}';
const queue = window.__flashcardsInitQueue || [];
window.flashcardsInit = init;
while(queue.length){
  const args = queue.shift();
  try{
    init(...args);
  }catch(err){
    console.error('Flashcards init failed', err);
  }
}
`;
    moduleScript.addEventListener('error', err => {
      console.error('Failed to load Flashcards app module', err);
    });
    (document.head || document.documentElement).appendChild(moduleScript);
  }catch(err){
    console.error('Flashcards bootstrap failed', err);
  }
})();
