/* Service worker de la Financiera (PWA).
   Solo existe para que el celular ofrezca "Instalar app". No guarda en caché
   ninguna pantalla con datos: préstamos, cobranzas y saldos se piden siempre
   al servidor. Si no hay conexión, en vez del error del navegador se muestra
   offline.html. Al cambiar este archivo, subí VERSION para renovar la caché. */
var VERSION = 'financiera-pwa-2';
var ICONO = 'icono.php?t=192';                 // el del logo de la empresa, o el estándar
var PRECACHE = ['offline.html', ICONO];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(PRECACHE); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (claves) {
    return Promise.all(claves.filter(function (k) { return k !== VERSION; })
                             .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  // Solo las navegaciones (abrir una pantalla). Formularios, PDF, Excel y
  // assets van directo al servidor, como sin service worker.
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(function () {
      return caches.match('offline.html');
    }));
    return;
  }
  // El ícono que muestra offline.html: de la red (y se renueva la copia);
  // sin conexión, la copia guardada.
  if (e.request.method === 'GET' && e.request.url.slice(-ICONO.length) === ICONO) {
    e.respondWith(fetch(e.request).then(function (r) {
      if (r.ok) {
        var copia = r.clone();
        caches.open(VERSION).then(function (c) { c.put(ICONO, copia); });
      }
      return r;
    }).catch(function () { return caches.match(ICONO); }));
  }
});
