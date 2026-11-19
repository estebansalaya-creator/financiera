/* Comportamientos comunes de las pantallas.
   - form[data-confirmar="texto"]: antes de mandarse pide confirmación en un
     diálogo (no el confirm() del navegador). data-confirmar-titulo y
     data-confirmar-boton cambian el título y el botón; data-peligro lo pinta
     de rojo. Los datos de la fila van en data-*, nunca dentro de un onclick.
   - button[data-agregar="id-del-template"][data-destino="id-del-contenedor"]:
     agrega un renglón; .renglon-quitar lo saca.
   - input[data-cuit]: valida el CUIT/CUIL mientras se escribe y lo dice en la
     .campo-ayuda del mismo campo. */
(function () {
  'use strict';

  // ── Diálogo de confirmación ──────────────────────────────────────────
  var modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = '<div class="modal-caja" role="dialog" aria-modal="true">' +
    '<div class="modal-titulo"></div><div class="modal-texto"></div>' +
    '<div class="modal-acciones"><button type="button" class="btn btn-secondary" data-no>Cancelar</button>' +
    '<button type="button" class="btn btn-primary" data-si>Confirmar</button></div></div>';
  document.body.appendChild(modal);
  var pendiente = null;

  function cerrar() { modal.classList.remove('abierto'); pendiente = null; }
  modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
  modal.querySelector('[data-no]').addEventListener('click', cerrar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
  modal.querySelector('[data-si]').addEventListener('click', function () {
    if (!pendiente) return;
    var form = pendiente.form, boton = pendiente.boton;
    form.setAttribute('data-confirmado', '1');
    cerrar();
    if (form.requestSubmit) form.requestSubmit(boton || undefined); else form.submit();
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-confirmar]')) return;
    if (form.getAttribute('data-confirmado')) { form.removeAttribute('data-confirmado'); return; }
    e.preventDefault();
    pendiente = { form: form, boton: e.submitter || null };
    modal.querySelector('.modal-titulo').textContent = form.getAttribute('data-confirmar-titulo') || 'Confirmar';
    modal.querySelector('.modal-texto').textContent = form.getAttribute('data-confirmar');
    var si = modal.querySelector('[data-si]');
    si.textContent = form.getAttribute('data-confirmar-boton') || 'Confirmar';
    si.className = 'btn ' + (form.hasAttribute('data-peligro') ? 'btn-danger' : 'btn-primary');
    modal.classList.add('abierto');
    si.focus();
  }, true);

  // ── Renglones ────────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var agregar = e.target.closest('[data-agregar]');
    if (agregar) {
      var tpl = document.getElementById(agregar.getAttribute('data-agregar'));
      var destino = document.getElementById(agregar.getAttribute('data-destino'));
      if (tpl && destino) {
        destino.appendChild(tpl.content.cloneNode(true));
        var primero = destino.lastElementChild && destino.lastElementChild.querySelector('input, select');
        if (primero) primero.focus();
      }
      return;
    }
    var quitar = e.target.closest('.renglon-quitar');
    if (quitar) {
      var r = quitar.closest('.renglon');
      if (r) r.remove();
    }
  });

  // ── CUIT / CUIL ──────────────────────────────────────────────────────
  function cuitValido(c) {
    if (!/^\d{11}$/.test(c)) return false;
    var p = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2], s = 0;
    for (var i = 0; i < 10; i++) s += +c[i] * p[i];
    var dv = 11 - (s % 11);
    if (dv === 11) dv = 0; else if (dv === 10) return false;
    return dv === +c[10];
  }
  function revisarCuit(inp) {
    var c = inp.value.replace(/\D/g, '');
    var ayuda = inp.closest('.campo') && inp.closest('.campo').querySelector('.campo-ayuda');
    if (!ayuda) return;
    if (!c) { ayuda.textContent = ''; ayuda.className = 'campo-ayuda'; inp.classList.remove('invalido'); return; }
    var ok = cuitValido(c);
    ayuda.textContent = c.length < 11 ? 'Faltan ' + (11 - c.length) + ' dígitos' : (ok ? '✓ CUIT válido' : '✗ El dígito verificador no coincide');
    ayuda.className = 'campo-ayuda ' + (c.length < 11 ? '' : (ok ? 'ok' : 'error'));
    inp.classList.toggle('invalido', c.length === 11 && !ok);
  }
  document.querySelectorAll('input[data-cuit]').forEach(function (inp) {
    inp.addEventListener('input', function () { revisarCuit(inp); });
    revisarCuit(inp);
  });
})();

/* ── Teléfonos: el mismo formato en todo el sistema ─────────────────────
   input[data-tel]: al salir del campo el número queda como se guarda,
   +54 9 11 2345-6789 (celular) o +54 11 4321-0000 (fijo). Es la misma regla
   que tel_normalizar() / tel_fmt() de inc/ui.php, que es la que manda.
     data-tel="movil"  siempre celular (WhatsApp, vendedores)
     data-tel="fijo"   siempre fijo
     data-tel="tipo"   lo dice el <select> del mismo renglón (Celular / Fijo)
     data-tel=""       lo decide el 15 o el 9 con que se escribió */
(function () {
  var AREAS3 = ['220', '221', '223', '230', '236', '237', '249', '260', '261', '263', '264', '266', '280',
                '291', '294', '297', '298', '299', '336', '341', '342', '343', '345', '348', '351', '353', '358',
                '362', '364', '370', '376', '379', '380', '381', '383', '385', '387', '388'];
  function largoArea(d) { return d.indexOf('11') === 0 ? 2 : (AREAS3.indexOf(d.substr(0, 3)) !== -1 ? 3 : 4); }

  function normalizar(s, movil) {
    s = s.trim();
    if (!s) return null;
    var intl = s[0] === '+' || s.indexOf('00') === 0;
    var d = s.replace(/\D/g, '');
    if (intl && d.indexOf('00') === 0) d = d.substr(2);
    if (/^0(800|810|600|610)\d{7}$/.test(d)) return d;
    if (intl && d.indexOf('54') !== 0) return /^[1-9]\d{7,14}$/.test(d) ? '+' + d : null;
    var con9 = false;
    if (d.indexOf('54') === 0 && d.length >= 12) {
      d = d.substr(2);
      if (d[0] === '9') { d = d.substr(1); con9 = true; }
    }
    d = d.replace(/^0+/, '');
    if (d.length === 12) {
      var largos = [largoArea(d), 2, 3, 4], hecho = false;
      for (var i = 0; i < largos.length && !hecho; i++) {
        var a = largos[i];
        if (d.substr(a, 2) === '15' && (a !== 2 || d.indexOf('11') === 0)) {
          d = d.substr(0, a) + d.substr(a + 2);
          con9 = hecho = true;
        }
      }
      if (!hecho) return null;
    }
    if (!/^(11|[23]\d)\d{8}$/.test(d)) return null;
    return (movil === true || con9 ? '549' : '54') + d;
  }

  function fmt(n) {
    var m = /^0(800|810|600|610)(\d{3})(\d{4})$/.exec(n);
    if (m) return '0' + m[1] + ' ' + m[2] + ' ' + m[3];
    m = /^54(9?)((?:11|[23]\d)\d{8})$/.exec(n);
    if (!m) return n;
    var a = largoArea(m[2]), loc = m[2].substr(a), c = loc.length - 4;
    return '+54 ' + (m[1] ? '9 ' : '') + m[2].substr(0, a) + ' ' + loc.substr(0, c) + '-' + loc.substr(c);
  }

  function modo(inp) {
    var m = inp.getAttribute('data-tel');
    if (m === 'tipo') {
      var r = inp.closest('.renglon'), sel = r && r.querySelector('select');
      var v = sel ? sel.value : '';
      return v === 'CELULAR' ? true : (v === 'FIJO' ? false : null);
    }
    return m === 'movil' ? true : (m === 'fijo' ? false : null);
  }

  function aplicar(inp, crudo) {
    var libre = inp.hasAttribute('data-tel-libre');          // admite texto que no es un número (interno, dos líneas…)
    var n = crudo.trim() ? normalizar(crudo, modo(inp)) : null;
    var mal = crudo.trim() !== '' && n === null && !libre;
    inp.classList.toggle('invalido', mal);
    inp.title = mal ? 'No se entiende el número: escribilo con la característica, ej. 11 2345-6789' : '';
    if (n !== null) inp.value = fmt(n);
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.matches) return;
    if (t.matches('input[data-tel]')) {
      t.setAttribute('data-tel-crudo', t.value);              // lo que se escribió, para rehacerlo si cambia el tipo
      aplicar(t, t.value);
    } else if (t.matches('.renglon select')) {
      var inp = t.closest('.renglon').querySelector('input[data-tel="tipo"]');
      if (inp && inp.value.trim()) aplicar(inp, inp.getAttribute('data-tel-crudo') || inp.value);
    }
  });
})();

/* ── Menú en el celular: botón ☰ ──────────────────────────────────────
   En pantallas chicas el menú lateral es un panel que se abre con el botón
   del encabezado. Se cierra tocando el fondo, con Esc, al elegir una
   pantalla o al volver a tocar ☰. Las ramas se abren sin cerrar el panel. */
(function () {
  var btn = document.getElementById('btn-menu');
  var fondo = document.getElementById('menu-fondo');
  var panel = document.getElementById('app-sidebar');
  if (!btn || !panel) return;
  function poner(si) {
    document.body.classList.toggle('menu-abierto', si);
    btn.setAttribute('aria-expanded', si ? 'true' : 'false');
    btn.setAttribute('aria-label', si ? 'Cerrar el menú' : 'Abrir el menú');
    if (fondo) fondo.hidden = !si;
    if (si) {
      var act = panel.querySelector('a.mn-hoja.active');
      if (act) act.scrollIntoView({ block: 'center' });
    }
  }
  btn.addEventListener('click', function () { poner(!document.body.classList.contains('menu-abierto')); });
  if (fondo) fondo.addEventListener('click', function () { poner(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('menu-abierto')) poner(false);
  });
  panel.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (a && !a.classList.contains('rama')) poner(false);
  });
  // Al agrandar la ventana (o girar la tablet) vuelve a ser menú fijo.
  window.matchMedia('(min-width: 901px)').addEventListener('change', function (m) { if (m.matches) poner(false); });
  // Volver con "atrás" desde otra pantalla no debe encontrar el panel abierto.
  window.addEventListener('pageshow', function () { poner(false); });
})();

/* ── Menú lateral (el de gestion_sindicato) ──────────────────────────────
   - Click en una rama: la abre o la cierra. Qué ramas quedaron abiertas se
     recuerda en el navegador (localStorage), así el menú no se cierra solo
     al pasar de una pantalla a otra.
   - La rama de la pantalla actual ya viene abierta del servidor.
   - «Inicio» cierra todo.
   - El buscador filtra el árbol sin ir al servidor: cada palabra se busca
     en el nombre de la opción y en el de sus ramas («cobranzas lote»).
     Flechas para elegir, Enter para ir, Esc limpia. */
(function () {
  var nav = document.getElementById('sidebar-nav');
  if (!nav) return;
  var CLAVE = 'fin_menu_abierto';

  function leer() { try { return JSON.parse(localStorage.getItem(CLAVE) || '[]'); } catch (e) { return []; } }
  function guardar(ids) { try { localStorage.setItem(CLAVE, JSON.stringify(ids)); } catch (e) {} }
  function abrir(a, si) {
    var ul = a.nextElementSibling;
    if (!ul) return;
    ul.style.display = si ? 'block' : 'none';
    a.classList.toggle('abierta', si);
    a.setAttribute('aria-expanded', si ? 'true' : 'false');
  }

  // Restaurar lo que estaba abierto (sumado a la rama de la pantalla actual).
  var abiertas = leer();
  nav.querySelectorAll('a.rama').forEach(function (a) {
    var id = a.getAttribute('data-menu-id');
    if (abiertas.indexOf(id) !== -1) abrir(a, true);
    else if (a.classList.contains('abierta') && abiertas.indexOf(id) === -1) abiertas.push(id);
  });
  guardar(abiertas);

  nav.addEventListener('click', function (e) {
    var a = e.target.closest('a.rama');
    if (!a) return;
    e.preventDefault();
    var id = a.getAttribute('data-menu-id');
    var si = !a.classList.contains('abierta');
    abrir(a, si);
    var ids = leer().filter(function (x) { return x !== id; });
    if (si) ids.push(id);
    guardar(ids);
  });

  document.querySelectorAll('[data-colapsar-menu]').forEach(function (el) {
    el.addEventListener('click', function () { try { localStorage.removeItem(CLAVE); } catch (e) {} });
  });

  // Buscador de opciones
  var input = document.getElementById('menu-buscar');
  var vacio = document.getElementById('menu-buscar-vacio');
  if (!input) return;
  var hojas = Array.prototype.slice.call(nav.querySelectorAll('a.mn-hoja'));
  var subs = Array.prototype.slice.call(nav.querySelectorAll('ul.submenu'));
  var antes = null, visibles = [], sel = -1;

  function norm(s) {
    return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
  }
  hojas.forEach(function (a) {
    var partes = [a.textContent], ul = a.parentElement.parentElement;
    while (ul && ul.classList.contains('submenu')) {
      if (ul.previousElementSibling) partes.unshift(ul.previousElementSibling.textContent);
      ul = ul.parentElement.parentElement;
    }
    a.setAttribute('data-buscar', norm(partes.join(' ')));
  });
  function marcar(i) {
    if (sel >= 0 && visibles[sel]) visibles[sel].classList.remove('mn-sel');
    sel = i;
    if (sel >= 0 && visibles[sel]) { visibles[sel].classList.add('mn-sel'); visibles[sel].scrollIntoView({ block: 'nearest' }); }
  }
  function filtrar() {
    var pal = norm(input.value).split(' ').filter(Boolean);
    marcar(-1);
    visibles = [];
    if (!pal.length) {
      if (antes) {
        nav.querySelectorAll('li').forEach(function (li) { li.style.display = ''; });
        subs.forEach(function (ul, i) { abrir(ul.previousElementSibling, antes[i]); });
        antes = null;
      }
      vacio.hidden = true;
      return;
    }
    if (!antes) antes = subs.map(function (ul) { return ul.style.display === 'block'; });
    nav.querySelectorAll('li').forEach(function (li) { li.style.display = 'none'; });
    subs.forEach(function (ul) { abrir(ul.previousElementSibling, false); });
    hojas.forEach(function (a) {
      var t = a.getAttribute('data-buscar');
      if (!pal.every(function (p) { return t.indexOf(p) !== -1; })) return;
      visibles.push(a);
      var li = a.parentElement;
      while (li && li.tagName === 'LI') {
        li.style.display = '';
        var ul = li.parentElement;
        if (!ul.classList.contains('submenu')) break;
        abrir(ul.previousElementSibling, true);
        li = ul.parentElement;
      }
    });
    vacio.hidden = visibles.length > 0;
    if (visibles.length) marcar(0);
  }
  input.addEventListener('input', filtrar);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!visibles.length) return;
      e.preventDefault();
      var n = visibles.length;
      marcar(((sel < 0 ? -1 : sel) + (e.key === 'ArrowDown' ? 1 : -1) + n) % n);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (sel >= 0 && visibles[sel]) location.href = visibles[sel].href;
    } else if (e.key === 'Escape') {
      e.stopPropagation();
      if (input.value) { e.preventDefault(); input.value = ''; filtrar(); } else input.blur();
    }
  });
})();

/* ── Árbol de permisos (Roles, Permisos por usuario) ─────────────────────
   Tildar una opción tilda sus ramas (una pantalla sin su rama no se ve en
   el menú). Destildar una rama destilda todo lo de adentro. */
(function () {
  document.querySelectorAll('[data-arbol-casillas]').forEach(function (arbol) {
    var porId = {};
    arbol.querySelectorAll('input[type=checkbox][data-id]').forEach(function (c) { porId[c.getAttribute('data-id')] = c; });
    arbol.addEventListener('change', function (e) {
      var c = e.target;
      if (!c.matches('input[type=checkbox][data-id]')) return;
      if (c.checked) {
        var p = porId[c.getAttribute('data-padre')];
        while (p) { if (!p.disabled) p.checked = true; p = porId[p.getAttribute('data-padre')]; }
      } else {
        var id = c.getAttribute('data-id');
        var bajar = function (pid) {
          Object.keys(porId).forEach(function (k) {
            var h = porId[k];
            if (h.getAttribute('data-padre') === pid) { if (!h.disabled) h.checked = false; bajar(k); }
          });
        };
        bajar(id);
      }
    });
  });

  // Vista del ícono elegido en el ABM del menú
  document.querySelectorAll('select[data-icono-vista]').forEach(function (sel) {
    var vista = document.getElementById(sel.getAttribute('data-icono-vista'));
    if (!vista) return;
    sel.addEventListener('change', function () {
      vista.querySelectorAll('[data-ic]').forEach(function (s) { s.hidden = s.getAttribute('data-ic') !== sel.value; });
    });
  });
})();

/* Vista del ícono en colores mientras se pega el SVG (ABM del menú). Solo se
   dibuja un SVG sin código; el servidor lo vuelve a validar al guardar. */
(function () {
  document.querySelectorAll('textarea[data-icono-svg]').forEach(function (ta) {
    var vista = document.getElementById(ta.getAttribute('data-icono-svg'));
    if (!vista) return;
    ta.addEventListener('input', function () {
      var v = ta.value.trim();
      var ok = /^<svg[\s\S]*<\/svg>$/i.test(v) && !/<\s*script|<\s*foreignobject|\son\w+\s*=|javascript:/i.test(v);
      vista.innerHTML = ok ? v.replace(/^<svg([^>]*?)\swidth="\d+"\sheight="\d+"/, '<svg$1 width="24" height="24"') : '';
    });
  });
})();

/* Ayuda: F1 abre la ayuda de la pantalla (el botón flotante sabe cuál es).
   En un manual, el índice marca la sección que se está leyendo. */
(function () {
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'F1') return;
    var btn = document.getElementById('btn-ayuda');
    e.preventDefault();
    window.location.href = btn ? btn.getAttribute('href') : 'ayuda.php';
  });
  var enlaces = document.querySelectorAll('.manual-indice a[data-seccion]');
  if (!enlaces.length || !('IntersectionObserver' in window)) return;
  var porId = {};
  enlaces.forEach(function (a) { porId[a.getAttribute('data-seccion')] = a; });
  var obs = new IntersectionObserver(function (entradas) {
    entradas.forEach(function (en) {
      if (!en.isIntersecting) return;
      enlaces.forEach(function (a) { a.classList.remove('activa'); });
      var a = porId[en.target.id];
      if (a) a.classList.add('activa');
    });
  }, { rootMargin: '-20% 0px -70% 0px' });
  document.querySelectorAll('.manual-sec').forEach(function (s) { obs.observe(s); });
  if (location.hash) {
    var sec = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (sec) sec.classList.add('destacada');
  }
})();
