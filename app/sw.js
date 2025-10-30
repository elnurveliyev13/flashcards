const CACHE = "srs-cache-v3";
const ASSETS = [
  // Empty cache - Service Worker will be registered but won't cache anything
  // This is minimal config just to enable PWA install prompt
  // Manifest icons will be loaded via manifest.webmanifest reference
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
