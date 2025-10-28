const CACHE = "srs-cache-v1";
const ASSETS = [
  "./",
  "./index.html",
  "./manifest.webmanifest"
  // иконки добавьте, если положите: "./icons/icon-192.png", "./icons/icon-512.png"
];

self.addEventListener("install", e=>{
  e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)));
});
self.addEventListener("activate", e=>{
  e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))));
});
self.addEventListener("fetch", e=>{
  const req = e.request;
  e.respondWith(
    caches.match(req).then(cached => cached || fetch(req).catch(()=> cached))
  );
});
