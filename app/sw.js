const CACHE = "srs-cache-v3";
const ASSETS = [
  // Empty cache - Service Worker will be registered but won't cache anything
  // This is minimal config just to enable PWA install prompt
  // Manifest icons will be loaded via manifest.webmanifest reference
];

self.addEventListener("install", e=>{
  e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener("activate", e=>{
  e.waitUntil(
    caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e=>{
  const req = e.request;
  e.respondWith(
    caches.match(req).then(cached => cached || fetch(req).catch(()=> cached))
  );
});

// Push notification handler
self.addEventListener("push", e => {
  if (!e.data) return;

  let data;
  try {
    data = e.data.json();
  } catch (err) {
    data = { title: "Flashcards | ABC norsk", body: e.data.text() };
  }

  const title = data.title || "Flashcards | ABC norsk";
  const options = {
    body: data.body || "",
    icon: "/mod/flashcards/app/icons/icon-192.png",
    badge: "/mod/flashcards/app/icons/icon-192.png",
    tag: data.tag || "flashcards-notification",
    renotify: true,
    requireInteraction: false,
    data: {
      url: data.url || "/mod/flashcards/my/index.php",
      dueCount: data.dueCount || 0
    }
  };

  e.waitUntil(
    self.registration.showNotification(title, options)
  );
});

// Notification click handler - open app
self.addEventListener("notificationclick", e => {
  e.notification.close();

  const url = e.notification.data?.url || "/mod/flashcards/my/index.php";

  e.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then(windowClients => {
      // Check if app is already open
      for (const client of windowClients) {
        if (client.url.includes("/mod/flashcards/") && "focus" in client) {
          return client.focus();
        }
      }
      // Open new window
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});

// Background sync for offline reviews (future feature)
self.addEventListener("sync", e => {
  if (e.tag === "sync-reviews") {
    // TODO: Implement offline review sync
  }
});
