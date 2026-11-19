/* PWA: registra el service worker y maneja la instalación.
   - Android / Chrome / Edge: el botón "Instalar app" (#btn-instalar) aparece
     solo cuando el navegador ofrece instalar, y abre el diálogo nativo.
   - iPhone / iPad: Safari no permite instalar desde la página; se muestra un
     aviso con los pasos (Compartir → Agregar a inicio), que se puede cerrar.
   - Ya instalada (abierta como app): no se muestra nada. */
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  var instalada = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (instalada) return;

  var diferido = null;
  function botones() { return document.querySelectorAll('[data-instalar-app]'); }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    diferido = e;
    botones().forEach(function (b) { b.hidden = false; });
  });

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-instalar-app]');
    if (!b || !diferido) return;
    diferido.prompt();
    diferido.userChoice.finally(function () {
      diferido = null;
      botones().forEach(function (x) { x.hidden = true; });
    });
  });

  window.addEventListener('appinstalled', function () {
    botones().forEach(function (x) { x.hidden = true; });
  });

  // Aviso para iPhone/iPad (Safari no dispara beforeinstallprompt).
  var ua = navigator.userAgent;
  var esIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (!esIOS) return;
  var cerrado = false;
  try { cerrado = localStorage.getItem('fin_aviso_ios') === '1'; } catch (err) {}
  if (cerrado) return;

  document.addEventListener('DOMContentLoaded', function () {
    var d = document.createElement('div');
    d.className = 'aviso-instalar-ios';
    d.setAttribute('role', 'dialog');
    var ico = document.querySelector('link[rel="apple-touch-icon"]');
    d.innerHTML =
      '<img src="' + (ico ? ico.getAttribute('href') : 'assets/iconos/icono-192.png') + '" alt="">' +
      '<div><strong>Instalá la app en tu iPhone</strong>' +
      '<span>Tocá <b>Compartir</b> ' +
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="ícono Compartir"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>' +
      ' y después <b>Agregar a inicio</b>.</span></div>' +
      '<button type="button" aria-label="Cerrar">×</button>';
    d.querySelector('button').addEventListener('click', function () {
      d.remove();
      try { localStorage.setItem('fin_aviso_ios', '1'); } catch (err) {}
    });
    document.body.appendChild(d);
  });
})();
