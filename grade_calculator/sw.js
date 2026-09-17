const CACHE = 'gradecalc-v1';
const ASSETS = ['/', '/grade_calculator/', '/grade_calculator/index.php',
  '/grade_calculator/assets/style.css', '/grade_calculator/assets/app.js'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(()=>{})));
  self.skipWaiting();
});
self.addEventListener('activate', e => e.waitUntil(clients.claim()));
self.addEventListener('fetch', e => {
  if(e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request).then(r => {
      const clone = r.clone();
      caches.open(CACHE).then(c => c.put(e.request, clone)).catch(()=>{});
      return r;
    }).catch(() => caches.match(e.request))
  );
});