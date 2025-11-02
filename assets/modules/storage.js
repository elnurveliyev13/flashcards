(function(root){
  const namespace = root.ModFlashcards || (root.ModFlashcards = {});

  function openDB(){
    return new Promise((resolve, reject)=>{
      const request = indexedDB.open('srs-media', 1);
      request.onupgradeneeded = event => {
        try {
          event.target.result.createObjectStore('files');
        } catch(_e){}
      };
      request.onsuccess = event => resolve(event.target.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function idbPut(key, blob){
    const db = await openDB();
    return new Promise((resolve, reject)=>{
      const tx = db.transaction('files', 'readwrite');
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
      tx.objectStore('files').put(blob, key);
    });
  }

  async function idbGet(key){
    const db = await openDB();
    return new Promise((resolve, reject)=>{
      const tx = db.transaction('files', 'readonly');
      const request = tx.objectStore('files').get(key);
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  }

  async function urlFor(key){
    const blob = await idbGet(key);
    return blob ? URL.createObjectURL(blob) : null;
  }

  namespace.Storage = {
    openDB,
    idbPut,
    idbGet,
    urlFor
  };
})(window);
