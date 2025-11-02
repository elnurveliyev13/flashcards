export function log(enabled, message){
  if(!enabled) return;
  try { console.log('[Flashcards]', message); } catch(_e){}
}

export function createLogger(enabled){
  return message => log(enabled, message);
}
