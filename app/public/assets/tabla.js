/* Grillas al estilo de gestion_admTable (gestion_adm/base.html):

   - Toda table.data-table se ordena con un clic en el encabezado (salvo
     th[data-no-sort], o la tabla entera con data-sin-orden). Si la celda
     trae data-orden, se ordena por ese valor (números sin formato, fechas
     AAAA-MM-DD); si no, por el texto (dd/mm/aaaa e importes $ 1.234,56 se
     leen como fecha y número).
   - Una grilla que pagina en el servidor ordena en la base: cada
     th[data-orden="clave"] lleva a la misma URL con ?orden=clave&dir=asc|desc,
     desde la primera página (ui.php: orden_grilla / th_orden).
   - Con data-paginada, pagina en el navegador: usa el .page-size-select y el
     .filter-count de su .card, y dibuja la barra en su .pagination-bar.
   - Con data-local, la barra de filtros de su .card filtra en el navegador:
     la .filter-input busca mientras se escribe, el .filter-count dice cuántas
     quedan y .btn-exportar baja a Excel lo filtrado (todas las filas, sin la
     columna de acciones), como el «Exportar a Excel» de gestion_adm.
   - La barra de scroll horizontal también va arriba de la grilla cuando no
     entra a lo ancho.
   La fila de totales va en <tfoot>: no se ordena ni se pagina. */
(function () {
  'use strict';

  var fmt = new Intl.NumberFormat('es-AR');
  var RE_FECHA = /^(\d{2})\/(\d{2})\/(\d{4})/;
  var RE_NUM = /^-?\d+(\.\d+)?$/;
  var RE_IMPORTE = /^[-\u2212]?\s*(?:US\$|\$)?\s*[-\u2212]?\s*\d[\d.]*(?:,\d+)?(?=\s|%|$)/;

  function valor(td) {
    if (!td) return '';
    var v = td.getAttribute('data-orden');
    if (v !== null) {
      var n0 = Number(v);
      return v !== '' && !isNaN(n0) ? n0 : v.toLocaleLowerCase('es');
    }
    // Lo que acompaña al dato en segunda línea (el CUIT bajo el cliente) no cuenta.
    var c = td;
    if (td.querySelector('.segunda-linea')) {
      c = td.cloneNode(true);
      Array.prototype.forEach.call(c.querySelectorAll('.segunda-linea'), function (e) { e.parentNode.removeChild(e); });
    }
    var t = c.textContent.trim();
    var m = t.match(RE_FECHA);
    if (m) return parseInt(m[3] + m[2] + m[1], 10);
    // «$ 1.234,56», «US$ 10.000», «$ -20,50», «12,5 %», «5 de 6»: el primer
    // número, a la argentina («2026-00000001» no: es un código y va como texto).
    var n = t.match(RE_IMPORTE);
    if (n) {
      var x = parseFloat(n[0].replace(/[^\d,]/g, '').replace(',', '.'));
      if (!isNaN(x)) return /[-\u2212]/.test(n[0]) ? -x : x;
    }
    return t.toLocaleLowerCase('es');
  }

  function normalizar(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  // data-unidad="aporte|aportes": singular|plural (sin la barra, la misma siempre).
  function registros(n, unidad) {
    var u = (unidad || 'registro|registros').split('|');
    return fmt.format(n) + ' ' + (n === 1 ? u[0] : (u[1] || u[0]));
  }

  /* ── Excel de lo filtrado (exportar_grilla.php) ───────────────────────── */
  function exportar(tbl, filas, boton) {
    var ths = Array.prototype.slice.call(tbl.tHead ? tbl.tHead.rows[0].cells : []);
    var cols = [];
    ths.forEach(function (th, i) {
      if (th.classList.contains('col-acciones') || th.hasAttribute('data-no-export')) return;
      cols.push({ i: i, titulo: th.textContent.trim(), tipo: th.getAttribute('data-tipo') || (th.classList.contains('th-right') ? 'n' : '') });
    });
    var datos = filas.map(function (tr) {
      return cols.map(function (c) {
        var td = tr.cells[c.i];
        if (!td) return '';
        if (td.hasAttribute('data-valor')) return td.getAttribute('data-valor');
        var v = td.textContent.replace(/\s+/g, ' ').trim();
        return v === '—' ? '' : v;
      });
    });
    var nombre = (tbl.getAttribute('data-exportar') || (document.querySelector('.page-header h1') || {}).textContent || 'Grilla').trim();
    var meta = document.querySelector('meta[name=fin-csrf]'), campo = document.querySelector('input[name=_csrf]');
    var tok = meta ? meta.getAttribute('content') : (campo ? campo.value : '');
    boton.disabled = true;
    fetch('exportar_grilla.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': tok },
      body: JSON.stringify({ nombre: nombre, columnas: cols.map(function (c) { return { titulo: c.titulo, tipo: c.tipo }; }), filas: datos })
    }).then(function (r) {
      if (!r.ok) return r.text().then(function (t) { throw new Error(t || r.statusText); });
      return r.blob();
    }).then(function (b) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(b);
      a.download = nombre.replace(/[\\/:*?"<>|]+/g, ' ') + '.xlsx';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
    }).catch(function (e) {
      alert('No se pudo exportar a Excel: ' + e.message);
    }).then(function () { boton.disabled = false; });
  }

  function iniciar(tbl) {
    var tbody = tbl.tBodies[0];
    if (!tbody) return;
    var todas = Array.prototype.slice.call(tbody.rows).filter(function (tr) {
      return !tr.querySelector('.no-data') && !tr.classList.contains('total-row');
    });
    var filas = todas.slice();
    var card = tbl.closest('.card');
    var paginada = tbl.hasAttribute('data-paginada');
    var local = tbl.hasAttribute('data-local');
    var servidor = !!tbl.querySelector('thead th[data-orden]');
    // Una grilla quieta que no pagina ni filtra el navegador no se toca: su
    // barra de paginación y su contador son del servidor.
    if (!paginada && !local && (servidor || tbl.hasAttribute('data-sin-orden'))) return;

    var selTam = paginada && card ? card.querySelector('.page-size-select') : null;
    var contador = (paginada || local) && card ? card.querySelector('.filter-count') : null;
    var barra = paginada && card ? card.querySelector('.pagination-bar') : null;
    var buscar = local && card ? card.querySelector('.filter-bar .filter-input') : null;
    var excel = local && card ? card.querySelector('button.btn-exportar') : null;
    var unidad = tbl.getAttribute('data-unidad');
    var pagina = 1;

    function tamPagina() { return paginada && selTam ? parseInt(selTam.value, 10) || 20 : filas.length || 1; }

    function filtrar() {
      var q = buscar ? normalizar(buscar.value.trim()) : '';
      filas = todas.filter(function (tr) { return !q || normalizar(tr.textContent).indexOf(q) !== -1; });
      pagina = 1;
      dibujar();
    }

    function dibujar() {
      var total = filas.length, tam = tamPagina();
      var paginas = Math.max(1, Math.ceil(total / tam));
      if (pagina > paginas) pagina = paginas;
      var desde = (pagina - 1) * tam, hasta = Math.min(desde + tam, total);
      todas.forEach(function (tr) { tr.style.display = 'none'; });
      filas.forEach(function (tr, i) {
        tr.style.display = (i >= desde && i < hasta) ? '' : 'none';
        tbody.appendChild(tr);
      });
      var vacia = tbody.querySelector('tr.sin-resultados');
      if (!total && todas.length) {
        if (!vacia) {
          vacia = document.createElement('tr');
          vacia.className = 'sin-resultados';
          vacia.innerHTML = '<td colspan="' + (tbl.tHead ? tbl.tHead.rows[0].cells.length : 1) + '" class="no-data">Sin resultados para este filtro.</td>';
          tbody.appendChild(vacia);
        }
        vacia.style.display = '';
      } else if (vacia) {
        vacia.style.display = 'none';
      }
      if (contador) {
        contador.textContent = paginada && total > tam
          ? 'Mostrando ' + (total ? desde + 1 : 0) + '–' + hasta + ' de ' + registros(total, unidad)
          : (total !== todas.length ? fmt.format(total) + ' de ' + registros(todas.length, unidad) : registros(total, unidad));
      }
      if (excel) excel.disabled = total === 0;
      if (!barra) return;
      var info = barra.querySelector('.pagination-info');
      var ctr = barra.querySelector('.pagination-controls');
      barra.style.display = paginas > 1 ? '' : 'none';
      if (!ctr) return;
      ctr.innerHTML = '';
      function boton(txt, pag, deshab, titulo) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'page-btn'; b.title = titulo;
        b.textContent = txt; b.disabled = !!deshab;
        b.addEventListener('click', function () { pagina = pag; dibujar(); });
        ctr.appendChild(b);
      }
      // La forma unificada de gestion_adm: « ‹ Pág. 2 de 7 · 340 registros › »
      boton('«', 1, pagina === 1, 'Primera página');
      boton('‹', pagina - 1, pagina === 1, 'Página anterior');
      var inf = info || document.createElement('span');
      inf.className = 'pagination-info';
      inf.textContent = 'Pág. ' + pagina + ' de ' + paginas + '  ·  ' + registros(total, unidad);
      ctr.appendChild(inf);
      boton('›', pagina + 1, pagina === paginas, 'Página siguiente');
      boton('»', paginas, pagina === paginas, 'Última página');
    }

    // data-sin-orden: grilla quieta (la ordena el servidor, o se lee en su orden).
    var ths = tbl.tHead && !tbl.hasAttribute('data-sin-orden') && !servidor ? tbl.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(ths, function (th, col) {
      if (th.hasAttribute('data-no-sort')) return;
      th.addEventListener('click', function () {
        var asc = !th.classList.contains('sort-asc');
        Array.prototype.forEach.call(ths, function (o) { o.classList.remove('sort-asc', 'sort-desc'); });
        th.classList.add(asc ? 'sort-asc' : 'sort-desc');
        var orden = function (a, b) {
          var x = valor(a.cells[col]), y = valor(b.cells[col]);
          var r = (typeof x === 'number' && typeof y === 'number') ? x - y
                : (typeof x === 'number' ? -1 : (typeof y === 'number' ? 1 : String(x).localeCompare(String(y), 'es', { numeric: true })));
          return asc ? r : -r;
        };
        todas.sort(orden);
        filas.sort(orden);
        pagina = 1;
        dibujar();
      });
    });

    if (selTam) selTam.addEventListener('change', function () { pagina = 1; dibujar(); });
    if (buscar) {
      buscar.addEventListener('input', filtrar);
      // En una barra local, Enter no manda nada: ya filtró.
      buscar.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    }
    if (excel) excel.addEventListener('click', function (e) { e.preventDefault(); exportar(tbl, filas, excel); });
    if (buscar && buscar.value) filtrar(); else dibujar();
  }

  /* ── Orden en la base: th[data-orden] ─────────────────────────────────── */
  document.querySelectorAll('table.data-table thead th[data-orden]').forEach(function (th) {
    th.addEventListener('click', function () {
      var url = new URL(location.href);
      var clave = th.getAttribute('data-orden');
      var desc = url.searchParams.get('orden') === clave ? url.searchParams.get('dir') !== 'desc'
               : th.classList.contains('sort-asc');
      url.searchParams.set('orden', clave);
      url.searchParams.set('dir', desc ? 'desc' : 'asc');
      url.searchParams.delete('p');
      location.href = url.toString();
    });
  });

  document.querySelectorAll('table.data-table').forEach(iniciar);

  /* ── La barra de scroll también arriba (la de gestion_adm) ────────────── */
  var alto = null;
  function altoBarra() {
    if (alto !== null) return alto;
    var p = document.createElement('div');
    p.style.cssText = 'position:absolute;top:-9999px;width:100px;height:100px;overflow:scroll;';
    document.body.appendChild(p);
    alto = p.offsetHeight - p.clientHeight;
    p.parentNode.removeChild(p);
    if (!alto) alto = 12;   // barras superpuestas (macOS): hace falta dónde pasar el mouse
    return alto;
  }
  function medir(barra, wrap, relleno) {
    relleno.style.width = wrap.scrollWidth + 'px';
    barra.style.display = wrap.scrollWidth > wrap.clientWidth + 1 ? 'block' : 'none';
  }
  function montar(wrap) {
    if (wrap.getAttribute('data-scroll-arriba') === '1') return;
    wrap.setAttribute('data-scroll-arriba', '1');
    var barra = document.createElement('div'), relleno = document.createElement('div');
    barra.className = 'scroll-arriba';
    barra.style.height = altoBarra() + 'px';
    barra.setAttribute('aria-hidden', 'true');
    barra.appendChild(relleno);
    wrap.parentNode.insertBefore(barra, wrap);
    var sinc = false;
    function atar(origen, destino) {
      origen.addEventListener('scroll', function () {
        if (sinc) return;
        sinc = true; destino.scrollLeft = origen.scrollLeft; sinc = false;
      }, { passive: true });
    }
    atar(barra, wrap);
    atar(wrap, barra);
    medir(barra, wrap, relleno);
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () { medir(barra, wrap, relleno); });
      ro.observe(wrap);
      var t = wrap.querySelector('table');
      if (t) ro.observe(t);
    }
    window.addEventListener('resize', function () { medir(barra, wrap, relleno); });
  }
  document.querySelectorAll('.data-table-wrap').forEach(montar);
})();
