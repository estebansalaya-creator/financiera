/* Mensajes internos (chat_api.php). Tres piezas, cada una se arma si está
   su elemento en la página:
     [data-chat-contador]  el globito flotante de Mensajes: cada 20 s pide
                           cuántas conversaciones tienen algo sin leer.
     [data-chat-hilo]      una conversación (en chat.php, y el hilo de la
                           ficha de la solicitud con data-solicitud).
     [data-chat-app]       la pantalla Mensajes: lista + conversación.
   Las consultas automáticas llevan auto=1 (no mantienen viva la sesión) y
   se frenan con la pestaña oculta. Si la sesión se cerró, se dejan de hacer. */
(function () {
  'use strict';

  var API = 'chat_api.php';
  var csrf = (document.querySelector('meta[name="fin-csrf"]') || {}).content || '';
  var sinSesion = false;

  // ── Pedidos ──────────────────────────────────────────────────────────
  function pedir(params, post) {
    if (sinSesion) return Promise.reject(new Error('La sesión se cerró. Volvé a ingresar.'));
    var url = API, opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (post) {
      var fd = post instanceof FormData ? post : new FormData();
      if (!(post instanceof FormData)) Object.keys(post).forEach(function (k) {
        var v = post[k];
        if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v);
      });
      fd.append('_csrf', csrf);
      opt.method = 'POST';
      opt.body = fd;
    } else {
      url += '?' + new URLSearchParams(params).toString();
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { throw new Error('El servidor no respondió bien (' + r.status + ').'); })
        .then(function (d) {
          if (r.status === 401) { sinSesion = true; avisarSesion(); }
          if (!d.ok) throw new Error(d.error || 'No se pudo.');
          return d;
        });
    });
  }

  function avisarSesion() {
    document.querySelectorAll('[data-chat-form]').forEach(function (f) {
      var e = f.querySelector('.chat-error');
      if (e) { e.textContent = 'La sesión se cerró. Volvé a ingresar para seguir.'; e.hidden = false; }
    });
  }

  /** Repite fn cada ms mientras la pestaña está visible (y una vez al volver a ella). */
  function cadaTanto(fn, ms) {
    var t = null, activo = true;
    function prog() { clearTimeout(t); if (activo && !sinSesion) t = setTimeout(correr, ms); }
    function correr() { if (document.hidden) return; Promise.resolve(fn()).catch(function () {}).then(prog); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(t); correr(); } });
    prog();
    return { parar: function () { activo = false; clearTimeout(t); }, ya: function () { clearTimeout(t); correr(); } };
  }

  function el(tag, clase, texto) {
    var e = document.createElement(tag);
    if (clase) e.className = clase;
    if (texto != null) e.textContent = texto;
    return e;
  }

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  function fechaTxt(iso) {
    var hoy = new Date(), ay = new Date(Date.now() - 864e5);
    function iso8(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    if (iso === iso8(hoy)) return 'Hoy';
    if (iso === iso8(ay)) return 'Ayer';
    var p = iso.split('-');
    return (+p[2]) + ' de ' + MESES[+p[1] - 1] + (+p[0] !== hoy.getFullYear() ? ' de ' + p[0] : '');
  }

  // ── Globito flotante de Mensajes ──────────────────────────────────────────
  var contador = document.querySelector('[data-chat-contador]');
  var tituloBase = document.title;
  function ponerContador(n) {
    if (!contador) return;
    var b = contador.querySelector('.chat-badge');
    var antes = +b.textContent || 0;
    if (n > antes) {                       // llegó algo nuevo: el globito late
      contador.classList.remove('con-nuevos');
      void contador.offsetWidth;
      contador.classList.add('con-nuevos');
    }
    b.textContent = n > 99 ? '99+' : String(n);
    b.hidden = n === 0;
    contador.title = n ? (n === 1 ? 'Una conversación con mensajes nuevos' : n + ' conversaciones con mensajes nuevos') : 'Mensajes';
    document.title = (n ? '(' + n + ') ' : '') + tituloBase;
  }
  var relojContador = null;
  if (contador) {
    ponerContador(+contador.getAttribute('data-chat-contador') || 0);
    relojContador = cadaTanto(function () {
      return pedir({ accion: 'contador', auto: 1 }).then(function (d) { ponerContador(d.no_leidos); });
    }, 20000);
  }

  // ── Una conversación ─────────────────────────────────────────────────
  /*
   * opciones: { conv, solicitud, cada, alCambiar(conv), alLeer() }
   * Con solicitud y sin mensajes todavía, el primero crea el hilo.
   */
  function Hilo(raiz, op) {
    this.raiz = raiz;
    this.op = op || {};
    this.conv = op.conv || null;
    this.solicitud = op.solicitud || null;
    this.ultimo = 0;
    this.primero = 0;
    this.leidoOtro = 0;
    this.ultimaFecha = null;
    this.armar();
    this.cargar();
    var self = this;
    this.reloj = cadaTanto(function () { return self.nuevos(); }, op.cada || 4000);
  }

  Hilo.prototype.armar = function () {
    var self = this, r = this.raiz;
    r.innerHTML = '';
    this.lista = el('div', 'chat-msjs');
    this.lista.setAttribute('aria-live', 'polite');
    this.mas = el('button', 'btn btn-ghost btn-sm chat-mas', 'Ver mensajes anteriores');
    this.mas.type = 'button';
    this.mas.hidden = true;
    this.mas.addEventListener('click', function () { self.anteriores(); });
    this.vacio = el('div', 'chat-vacio', this.solicitud ? 'Todavía no hay mensajes sobre esta solicitud. Lo que escribas acá lo ven quien la cargó, el vendedor y quien la autoriza.' : 'Todavía no hay mensajes.');
    this.vacio.hidden = true;
    this.lista.appendChild(this.mas);
    this.lista.appendChild(this.vacio);
    this.cuerpo = el('div', 'chat-cuerpo');
    this.lista.appendChild(this.cuerpo);

    var f = el('form', 'chat-form');
    f.setAttribute('data-chat-form', '');
    f.innerHTML =
      '<div class="chat-error" hidden></div>' +
      '<div class="chat-archivo" hidden><span></span><button type="button" class="btn-accion borrar" title="Quitar el archivo" aria-label="Quitar el archivo">×</button></div>' +
      '<div class="chat-fila">' +
      '<label class="btn btn-ghost chat-clip" title="Adjuntar archivo (PDF, imagen, Word, Excel)"><input type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,image/*,application/pdf">' +
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg></label>' +
      '<textarea rows="1" maxlength="4000" placeholder="Escribí un mensaje…" title="Enter envía · Shift+Enter baja de renglón" aria-label="Mensaje"></textarea>' +
      '<button type="submit" class="btn btn-primary chat-enviar" title="Enviar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg><span>Enviar</span></button>' +
      '</div>';
    this.form = f;
    this.txt = f.querySelector('textarea');
    this.file = f.querySelector('input[type=file]');
    this.err = f.querySelector('.chat-error');
    this.chipArchivo = f.querySelector('.chat-archivo');

    this.txt.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); self.enviar(); }
    });
    this.txt.addEventListener('input', function () { self.alto(); });
    this.file.addEventListener('change', function () { self.mostrarArchivo(); });
    this.chipArchivo.querySelector('button').addEventListener('click', function () { self.file.value = ''; self.mostrarArchivo(); });
    f.addEventListener('submit', function (e) { e.preventDefault(); self.enviar(); });
    // Pegar una imagen (captura de pantalla) la adjunta.
    this.txt.addEventListener('paste', function (e) {
      var it = e.clipboardData && e.clipboardData.files;
      if (it && it.length && window.DataTransfer) {
        var dt = new DataTransfer();
        dt.items.add(it[0]);
        self.file.files = dt.files;
        self.mostrarArchivo();
        e.preventDefault();
      }
    });
    // Arrastrar y soltar un archivo sobre la conversación.
    ['dragover', 'drop'].forEach(function (ev) {
      r.addEventListener(ev, function (e) {
        if (!e.dataTransfer || !e.dataTransfer.types || Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') === -1) return;
        e.preventDefault();
        if (ev === 'drop' && e.dataTransfer.files.length) { self.file.files = e.dataTransfer.files; self.mostrarArchivo(); }
      });
    });

    r.appendChild(this.lista);
    r.appendChild(f);
  };

  Hilo.prototype.alto = function () {
    this.txt.style.height = 'auto';
    this.txt.style.height = Math.min(this.txt.scrollHeight, 160) + 'px';
  };

  Hilo.prototype.mostrarArchivo = function () {
    var f = this.file.files[0];
    this.chipArchivo.hidden = !f;
    if (f) this.chipArchivo.querySelector('span').textContent = '📎 ' + f.name + ' (' + (f.size < 1048576 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB') + ')';
  };

  Hilo.prototype.error = function (m) {
    this.err.textContent = m || '';
    this.err.hidden = !m;
  };

  Hilo.prototype.params = function (extra) {
    var p = this.conv ? { accion: 'mensajes', conv: this.conv } : { accion: 'mensajes', solicitud: this.solicitud };
    Object.keys(extra || {}).forEach(function (k) { p[k] = extra[k]; });
    return p;
  };

  Hilo.prototype.alFondo = function () {
    var l = this.lista;
    return l.scrollHeight - l.scrollTop - l.clientHeight < 60;
  };

  Hilo.prototype.cargar = function () {
    var self = this;
    this.cargando = true;
    return pedir(this.params({ leer: 1 })).then(function (d) {
      self.cuerpo.innerHTML = '';
      self.ultimaFecha = null;
      self.info = d.conv;
      if (d.conv) self.conv = d.conv.id;
      self.leidoOtro = d.leido_otro || 0;
      self.mas.hidden = !(d.conv && d.conv.hay_mas);
      self.agregar(d.mensajes, true);
      self.vacio.hidden = d.mensajes.length > 0;
      self.lista.scrollTop = self.lista.scrollHeight;
      if (self.op.alCambiar) self.op.alCambiar(d.conv);
      if (self.op.alLeer) self.op.alLeer();
    }).catch(function (e) { self.error(e.message); })
      .then(function () { self.cargando = false; });
  };

  Hilo.prototype.nuevos = function () {
    if (this.cargando || (!this.conv && !this.solicitud)) return;
    if (!this.conv) return this.cargar();                 // hilo de solicitud todavía vacío: puede haber empezado otro
    var self = this, abajo = this.alFondo();
    return pedir(this.params({ desde: this.ultimo, leer: document.hasFocus() ? 1 : 0, auto: 1 })).then(function (d) {
      var antes = self.leidoOtro;
      self.leidoOtro = d.leido_otro || 0;
      if (antes !== self.leidoOtro) self.marcarVistos();
      if (!d.mensajes.length) return;
      self.vacio.hidden = true;
      self.agregar(d.mensajes, false);
      if (abajo) self.lista.scrollTop = self.lista.scrollHeight;
      if (self.op.alLeer) self.op.alLeer();
    });
  };

  Hilo.prototype.anteriores = function () {
    var self = this, alto = this.lista.scrollHeight;
    this.mas.disabled = true;
    pedir(this.params({ antes: this.primero })).then(function (d) {
      var frag = document.createDocumentFragment(), fecha = null, primerViejo = self.cuerpo.firstChild;
      d.mensajes.forEach(function (m) {
        if (m.fecha !== fecha) { frag.appendChild(el('div', 'chat-dia', fechaTxt(m.fecha))); fecha = m.fecha; }
        frag.appendChild(self.burbuja(m));
      });
      // Si la tanda vieja termina el mismo día que empieza lo cargado, sobra un separador.
      if (primerViejo && primerViejo.classList.contains('chat-dia') && primerViejo.textContent === (fecha ? fechaTxt(fecha) : '')) primerViejo.remove();
      self.cuerpo.insertBefore(frag, self.cuerpo.firstChild);
      if (d.mensajes.length) self.primero = d.mensajes[0].id;
      self.mas.hidden = !(d.conv && d.conv.hay_mas);
      self.lista.scrollTop = self.lista.scrollHeight - alto;
    }).catch(function (e) { self.error(e.message); }).then(function () { self.mas.disabled = false; });
  };

  Hilo.prototype.agregar = function (msjs, inicio) {
    var self = this;
    msjs.forEach(function (m) {
      if (m.id <= self.ultimo) return;
      if (m.fecha !== self.ultimaFecha) { self.cuerpo.appendChild(el('div', 'chat-dia', fechaTxt(m.fecha))); self.ultimaFecha = m.fecha; }
      self.cuerpo.appendChild(self.burbuja(m));
      self.ultimo = m.id;
      if (!self.primero) self.primero = m.id;
    });
    if (inicio && msjs.length) this.primero = msjs[0].id;   // para «Ver mensajes anteriores»
  };

  Hilo.prototype.burbuja = function (m) {
    var self = this;
    if (m.sistema) {
      var s = el('div', 'chat-aviso', m.texto);
      s.setAttribute('data-id', m.id);
      return s;
    }
    var b = el('div', 'chat-msj' + (m.propio ? ' propio' : ''));
    b.setAttribute('data-id', m.id);
    var directa = this.info && this.info.tipo === 'DIRECTA';
    if (!m.propio && !directa) b.appendChild(el('div', 'chat-autor', m.autor || '—'));
    if (m.borrado) {
      b.appendChild(el('div', 'chat-texto chat-borrado', 'Mensaje eliminado'));
    } else {
      if (m.adjunto) {
        var a = el('a', 'chat-adjunto' + (m.adjunto.imagen ? ' imagen' : ''));
        a.href = m.adjunto.url;
        a.target = '_blank';
        a.rel = 'noopener';
        if (m.adjunto.imagen) {
          var img = el('img');
          img.src = m.adjunto.url;
          img.alt = m.adjunto.nombre;
          img.loading = 'lazy';
          a.appendChild(img);
        } else {
          a.appendChild(el('span', 'chat-adjunto-ico', '📄'));
          var t = el('span', 'chat-adjunto-txt');
          t.appendChild(el('strong', null, m.adjunto.nombre));
          t.appendChild(el('small', null, m.adjunto.peso));
          a.appendChild(t);
        }
        b.appendChild(a);
      }
      if (m.texto) b.appendChild(el('div', 'chat-texto', m.texto));
    }
    var pie = el('div', 'chat-hora', m.hora);
    if (m.propio && directa) {
      var v = el('span', 'chat-visto', '✓✓');
      v.title = 'Leído';
      v.hidden = m.id > this.leidoOtro;
      pie.appendChild(v);
    }
    if (m.propio && !m.borrado) {
      var x = el('button', 'chat-retirar', 'Eliminar');
      x.type = 'button';
      x.addEventListener('click', function () {
        if (!window.confirm('¿Eliminar este mensaje? Los demás van a ver «Mensaje eliminado».')) return;
        pedir(null, { accion: 'retirar', mensaje: m.id }).then(function () {
          m.borrado = true; m.texto = null; m.adjunto = null;
          b.replaceWith(self.burbuja(m));
        }).catch(function (e) { self.error(e.message); });
      });
      pie.appendChild(x);
    }
    b.appendChild(pie);
    return b;
  };

  Hilo.prototype.marcarVistos = function () {
    var lim = this.leidoOtro;
    this.cuerpo.querySelectorAll('.chat-msj.propio').forEach(function (b) {
      var v = b.querySelector('.chat-visto');
      if (v) v.hidden = +b.getAttribute('data-id') > lim;
    });
  };

  Hilo.prototype.enviar = function () {
    var self = this, texto = this.txt.value.trim(), f = this.file.files[0];
    if (!texto && !f) return;
    if (this.enviando) return;
    var fd = new FormData();
    fd.append('accion', 'enviar');
    if (this.conv) fd.append('conv', this.conv);
    else if (this.solicitud) fd.append('solicitud', this.solicitud);
    else if (this.op.para) fd.append('para', this.op.para);
    fd.append('texto', texto);
    if (f) fd.append('archivo', f);
    this.enviando = true;
    this.form.classList.add('enviando');
    this.error('');
    pedir(null, fd).then(function (d) {
      self.txt.value = '';
      self.file.value = '';
      self.mostrarArchivo();
      self.alto();
      var nueva = !self.conv;
      self.conv = d.conv;
      if (nueva) return self.cargar();
      return self.nuevos();
    }).then(function () {
      self.lista.scrollTop = self.lista.scrollHeight;
      if (self.op.alEnviar) self.op.alEnviar();
    }).catch(function (e) { self.error(e.message); })
      .then(function () { self.enviando = false; self.form.classList.remove('enviando'); self.txt.focus(); });
  };

  Hilo.prototype.cerrar = function () { this.reloj.parar(); };

  // Hilos sueltos (ficha de la solicitud)
  document.querySelectorAll('[data-chat-hilo]').forEach(function (r) {
    new Hilo(r, { solicitud: +r.getAttribute('data-solicitud') || null, conv: +r.getAttribute('data-conv') || null, cada: 5000,
                  alLeer: function () { if (relojContador) relojContador.ya(); } });
  });

  // ── La pantalla Mensajes ─────────────────────────────────────────────
  var app = document.querySelector('[data-chat-app]');
  if (!app) return;

  var ulConvs = app.querySelector('[data-convs]');
  var panel = app.querySelector('[data-panel]');
  var cab = app.querySelector('[data-cabecera]');
  var zona = app.querySelector('[data-zona-hilo]');
  var buscar = app.querySelector('[data-buscar]');
  var hilo = null, convs = [], usuarios = null, actual = null, yaElegida = false;

  function cargarUsuarios() {
    if (usuarios) return Promise.resolve(usuarios);
    return pedir({ accion: 'usuarios' }).then(function (d) { usuarios = d.usuarios; return usuarios; });
  }

  function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

  function dibujarLista() {
    var f = norm(buscar.value.trim());
    ulConvs.innerHTML = '';
    var vis = convs.filter(function (c) { return !f || norm(c.titulo + ' ' + c.ultimo).indexOf(f) !== -1; });
    if (!vis.length) {
      ulConvs.appendChild(el('li', 'chat-lista-vacia', convs.length ? 'Ninguna conversación coincide.' : 'Todavía no tenés conversaciones. Empezá una con «Nuevo mensaje».'));
      return;
    }
    vis.forEach(function (c) {
      var li = el('li');
      var a = el('a', 'chat-conv' + (c.id === actual ? ' activa' : '') + (c.no_leidos ? ' sin-leer' : ''));
      a.href = '?c=' + c.id;
      a.setAttribute('data-id', c.id);
      var ico = el('span', 'chat-conv-ico tipo-' + c.tipo.toLowerCase(), c.tipo === 'GRUPO' ? '👥' : (c.tipo === 'SOLICITUD' ? '📋' : (c.titulo || '?').trim().charAt(0).toUpperCase()));
      a.appendChild(ico);
      var t = el('span', 'chat-conv-txt');
      var l1 = el('span', 'chat-conv-l1');
      l1.appendChild(el('strong', null, c.titulo + (c.inactivo ? ' (de baja)' : '')));
      l1.appendChild(el('small', null, c.cuando));
      t.appendChild(l1);
      var l2 = el('span', 'chat-conv-l2');
      l2.appendChild(el('span', null, c.ultimo || ' '));
      if (c.silenciada) l2.appendChild(el('span', 'chat-silencio', '🔕'));
      if (c.no_leidos) l2.appendChild(el('span', 'chat-badge', c.no_leidos > 99 ? '99+' : String(c.no_leidos)));
      t.appendChild(l2);
      a.appendChild(t);
      a.addEventListener('click', function (e) { e.preventDefault(); abrir(c.id); });
      li.appendChild(a);
      ulConvs.appendChild(li);
    });
  }

  function cargarLista() {
    return pedir({ accion: 'lista', auto: 1 }).then(function (d) {
      convs = d.conversaciones;
      dibujarLista();
      var n = convs.filter(function (c) { return c.no_leidos && !c.silenciada; }).length;
      ponerContador(n);
    });
  }

  function boton(texto, clase, fn) {
    var b = el('button', 'btn btn-sm ' + (clase || 'btn-ghost'), texto);
    b.type = 'button';
    b.addEventListener('click', fn);
    return b;
  }

  function dibujarCabecera(info) {
    cab.innerHTML = '';
    var volver = boton('←', 'btn-ghost chat-volver', function () { cerrarPanel(); });
    volver.title = 'Volver a la lista';
    cab.appendChild(volver);
    var t = el('div', 'chat-cab-txt');
    t.appendChild(el('strong', null, info ? info.titulo : ''));
    if (info && info.tipo === 'GRUPO') t.appendChild(el('small', null, info.integrantes.map(function (i) { return i.nombre.split(' ')[0]; }).join(', ')));
    if (info && info.tipo === 'SOLICITUD') t.appendChild(el('small', null, 'Hilo de la solicitud'));
    cab.appendChild(t);
    var acc = el('div', 'chat-cab-acc');
    if (info && info.tipo === 'SOLICITUD') {
      var a = el('a', 'btn btn-sm btn-ghost', 'Ver solicitud');
      a.href = 'solicitud.php?id=' + info.solicitud;
      acc.appendChild(a);
    }
    if (info && info.tipo === 'GRUPO') acc.appendChild(boton('Integrantes', 'btn-ghost', function () { dlgIntegrantes(info); }));
    if (info && info.participa) {
      acc.appendChild(boton(info.silenciada ? '🔔 Activar avisos' : '🔕 Silenciar', 'btn-ghost', function () {
        pedir(null, { accion: 'silenciar', conv: info.id, si: info.silenciada ? 0 : 1 }).then(function () {
          info.silenciada = !info.silenciada; dibujarCabecera(info); cargarLista();
        });
      }));
    }
    if (info && info.tipo === 'GRUPO') acc.appendChild(boton('Salir', 'btn-ghost', function () {
      if (!window.confirm('¿Salir del grupo «' + info.titulo + '»? Para volver te tiene que agregar quien lo administra.')) return;
      pedir(null, { accion: 'salir', conv: info.id }).then(function () { cerrarPanel(); cargarLista(); }).catch(function (e) { alert(e.message); });
    }));
    cab.appendChild(acc);
  }

  function abrir(id, para) {
    actual = id;
    if (hilo) hilo.cerrar();
    app.classList.add('con-conversacion');
    panel.hidden = false;
    app.querySelector('[data-sin-conv]').hidden = true;
    dibujarCabecera(null);
    hilo = new Hilo(zona, {
      conv: id, para: para, cada: 3000,
      alCambiar: function (info) { if (info) { actual = info.id; dibujarCabecera(info); history.replaceState(null, '', '?c=' + info.id); } },
      alLeer: function () { cargarLista(); },
      alEnviar: function () { cargarLista(); }
    });
    dibujarLista();
    hilo.txt.focus();
  }

  function cerrarPanel() {
    if (hilo) hilo.cerrar();
    hilo = null;
    actual = null;
    app.classList.remove('con-conversacion');
    panel.hidden = true;
    app.querySelector('[data-sin-conv]').hidden = false;
    history.replaceState(null, '', 'chat.php');
    dibujarLista();
  }

  // ── Diálogos ─────────────────────────────────────────────────────────
  function dialogo(titulo, cuerpo, textoOk, alOk) {
    var d = el('dialog', 'chat-dialogo');
    var f = el('form');
    f.method = 'dialog';
    var h = el('div', 'card-head');
    h.appendChild(el('span', 'card-head-titulo', titulo));
    f.appendChild(h);
    var c = el('div', 'bloque');
    c.appendChild(cuerpo);
    f.appendChild(c);
    var err = el('div', 'chat-error');
    err.hidden = true;
    c.appendChild(err);
    var pie = el('div', 'form-acciones');
    pie.appendChild(boton('Cancelar', 'btn-secondary', function () { d.close(); }));
    if (textoOk) {
      var ok = el('button', 'btn btn-primary', textoOk);
      ok.type = 'submit';
      pie.appendChild(ok);
    }
    f.appendChild(pie);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      err.hidden = true;
      Promise.resolve(alOk()).then(function (cerrar) { if (cerrar !== false) d.close(); })
        .catch(function (x) { err.textContent = x.message; err.hidden = false; });
    });
    d.appendChild(f);
    d.addEventListener('close', function () { d.remove(); });
    document.body.appendChild(d);
    d.showModal();
    return d;
  }

  /** Lista de usuarios con buscador; multiple = casillas, si no, un click elige. */
  function selectorUsuarios(lista, multiple, alElegir, excluir) {
    var caja = el('div', 'chat-selector');
    var q = el('input');
    q.type = 'search';
    q.placeholder = 'Buscar por nombre o rol…';
    q.autocomplete = 'off';
    caja.appendChild(q);
    var ul = el('ul', 'chat-selector-lista');
    caja.appendChild(ul);
    var elegidos = {};
    function dibujar() {
      var f = norm(q.value.trim());
      ul.innerHTML = '';
      lista.filter(function (u) { return (!excluir || excluir.indexOf(u.id) === -1) && (!f || norm(u.nombre + ' ' + u.roles).indexOf(f) !== -1); })
        .forEach(function (u) {
          var li = el('li');
          var lab = el(multiple ? 'label' : 'button', 'chat-selector-op');
          if (multiple) {
            var cb = el('input');
            cb.type = 'checkbox';
            cb.checked = !!elegidos[u.id];
            cb.addEventListener('change', function () { if (cb.checked) elegidos[u.id] = true; else delete elegidos[u.id]; });
            lab.appendChild(cb);
          } else {
            lab.type = 'button';
            lab.addEventListener('click', function () { alElegir(u); });
          }
          var t = el('span');
          t.appendChild(el('strong', null, u.nombre));
          if (u.roles) t.appendChild(el('small', null, u.roles));
          lab.appendChild(t);
          li.appendChild(lab);
          ul.appendChild(li);
        });
      if (!ul.children.length) ul.appendChild(el('li', 'chat-lista-vacia', 'Nadie coincide.'));
    }
    q.addEventListener('input', dibujar);
    dibujar();
    setTimeout(function () { q.focus(); }, 30);
    caja.elegidos = function () { return Object.keys(elegidos).map(Number); };
    return caja;
  }

  function dlgNuevo() {
    cargarUsuarios().then(function (us) {
      var d = dialogo('Nuevo mensaje', selectorUsuarios(us, false, function (u) {
        d.close();
        pedir(null, { accion: 'abrir', para: u.id }).then(function (r) { abrir(r.conv); }).catch(function (e) { alert(e.message); });
      }), null, function () {});
    }).catch(function (e) { alert(e.message); });
  }

  function dlgGrupo() {
    cargarUsuarios().then(function (us) {
      var cuerpo = el('div');
      var lab = el('label', null, 'Nombre del grupo');
      var nom = el('input');
      nom.maxLength = 80;
      nom.required = true;
      nom.placeholder = 'Por ejemplo: Vendedores zona norte';
      var campo = el('div', 'campo');
      campo.appendChild(lab);
      campo.appendChild(nom);
      cuerpo.appendChild(campo);
      cuerpo.appendChild(el('div', 'dato-lbl chat-sep', 'Integrantes'));
      var sel = selectorUsuarios(us, true);
      cuerpo.appendChild(sel);
      dialogo('Nuevo grupo', cuerpo, 'Crear el grupo', function () {
        return pedir(null, { accion: 'grupo', nombre: nom.value, miembros: sel.elegidos() }).then(function (r) { cargarLista(); abrir(r.conv); });
      });
      setTimeout(function () { nom.focus(); }, 40);
    }).catch(function (e) { alert(e.message); });
  }

  function dlgIntegrantes(info) {
    var cuerpo = el('div');
    var ul = el('ul', 'chat-integrantes');
    var quitar = {};
    info.integrantes.forEach(function (i) {
      var li = el('li');
      li.appendChild(el('span', null, i.nombre + (i.admin ? ' · administra' : '') + (i.activo ? '' : ' (de baja)')));
      if (info.admin && !i.admin) {
        var b = boton('Sacar', 'btn-ghost', function () {
          quitar[i.id] = !quitar[i.id];
          li.classList.toggle('chat-quitado', !!quitar[i.id]);
          b.textContent = quitar[i.id] ? 'Dejar' : 'Sacar';
        });
        li.appendChild(b);
      }
      ul.appendChild(li);
    });
    if (!info.admin) {
      cuerpo.appendChild(ul);
      cuerpo.appendChild(el('p', 'campo-ayuda', 'Solo quien administra el grupo puede agregar o sacar gente.'));
      dialogo('Integrantes de «' + info.titulo + '»', cuerpo, null, function () {});
      return;
    }
    cargarUsuarios().then(function (us) {
      var campo = el('div', 'campo');
      campo.appendChild(el('label', null, 'Nombre del grupo'));
      var nom = el('input');
      nom.maxLength = 80;
      nom.value = info.titulo;
      campo.appendChild(nom);
      cuerpo.appendChild(campo);
      cuerpo.appendChild(el('div', 'dato-lbl chat-sep', 'Integrantes'));
      cuerpo.appendChild(ul);
      cuerpo.appendChild(el('div', 'dato-lbl chat-sep', 'Agregar'));
      var sel = selectorUsuarios(us, true, null, info.integrantes.map(function (i) { return i.id; }));
      cuerpo.appendChild(sel);
      dialogo('Grupo «' + info.titulo + '»', cuerpo, 'Guardar', function () {
        return pedir(null, { accion: 'editar', conv: info.id, nombre: nom.value, agregar: sel.elegidos(),
                             quitar: Object.keys(quitar).filter(function (k) { return quitar[k]; }) })
          .then(function () { cargarLista(); abrir(info.id); });
      });
    }).catch(function (e) { alert(e.message); });
  }

  app.querySelector('[data-nuevo]').addEventListener('click', dlgNuevo);
  app.querySelector('[data-nuevo-grupo]').addEventListener('click', dlgGrupo);
  buscar.addEventListener('input', dibujarLista);

  // La lista se actualiza sola (cada 10 s), y el globito con ella.
  if (relojContador) relojContador.parar();
  cargarLista().then(function () {
    var p = new URLSearchParams(location.search);
    var c = +p.get('c'), para = +p.get('para');
    if (c) abrir(c);
    else if (para) pedir(null, { accion: 'abrir', para: para }).then(function (r) { abrir(r.conv); }).catch(function (e) { alert(e.message); });
    yaElegida = true;
  }).catch(function (e) { ulConvs.innerHTML = ''; ulConvs.appendChild(el('li', 'chat-lista-vacia', e.message)); });
  cadaTanto(function () { return cargarLista(); }, 10000);
})();
