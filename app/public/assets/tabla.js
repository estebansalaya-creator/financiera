/* Grillas al estilo de gestion_admTable (gestion_adm/base.html), en chico:
   - Toda table.data-table se ordena con un clic en el encabezado (salvo
     th[data-no-sort]). Si la celda trae data-orden, se ordena por ese valor
     (números sin formato, fechas AAAA-MM-DD); si no, por el texto.
   - Con data-paginada, pagina: usa el .page-size-select y el .filter-count
     de su .card, y dibuja «‹ 1 2 3 ›» y «Pág. 1 de 4 · 70 registros» en su
     .pagination-bar.
   La fila de totales va en <tfoot>: no se ordena ni se pagina.
   Sin data-paginada no toca la barra de paginación ni el contador de la
   tarjeta (son del servidor); con data-sin-orden además no ordena. */
(function () {
  'use strict';

  var fmt = new Intl.NumberFormat('es-AR');

  function valor(td) {
    if (!td) return '';
    var v = td.getAttribute('data-orden');
    if (v === null) v = td.textContent.trim();
    var n = Number(v);
    return v !== '' && !isNaN(n) ? n : v.toLocaleLowerCase('es');
  }

  function iniciar(tbl) {
    var tbody = tbl.tBodies[0];
    if (!tbody) return;
    var filas = Array.prototype.slice.call(tbody.rows).filter(function (tr) { return !tr.querySelector('.no-data'); });
    var card = tbl.closest('.card');
    var paginada = tbl.hasAttribute('data-paginada');
    // Una grilla quieta que no pagina el navegador (la pagina el servidor) no se toca:
    // su barra de paginación y su contador son del servidor.
    if (!paginada && tbl.hasAttribute('data-sin-orden')) return;
    var selTam = paginada && card ? card.querySelector('.page-size-select') : null;
    var contador = paginada && card ? card.querySelector('.filter-count') : null;
    var barra = paginada && card ? card.querySelector('.pagination-bar') : null;
    var pagina = 1;

    function tamPagina() { return paginada && selTam ? parseInt(selTam.value, 10) || 20 : filas.length || 1; }

    function dibujar() {
      var total = filas.length, tam = tamPagina();
      var paginas = Math.max(1, Math.ceil(total / tam));
      if (pagina > paginas) pagina = paginas;
      var desde = (pagina - 1) * tam, hasta = Math.min(desde + tam, total);
      filas.forEach(function (tr, i) {
        tr.style.display = (i >= desde && i < hasta) ? '' : 'none';
        tbody.appendChild(tr);
      });
      if (contador) contador.textContent = 'Mostrando ' + (total ? desde + 1 : 0) + '–' + hasta + ' de ' + fmt.format(total) + ' registros';
      if (!barra) return;
      var info = barra.querySelector('.pagination-info');
      var ctr = barra.querySelector('.pagination-controls');
      if (info) info.textContent = 'Pág. ' + pagina + ' de ' + paginas + '  ·  ' + fmt.format(total) + (total === 1 ? ' registro' : ' registros');
      if (!ctr) return;
      ctr.innerHTML = '';
      barra.style.display = paginas > 1 ? '' : 'none';
      function boton(txt, pag, deshab, activo) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'page-btn' + (activo ? ' active' : '');
        b.textContent = txt; b.disabled = !!deshab;
        b.addEventListener('click', function () { pagina = pag; dibujar(); });
        ctr.appendChild(b);
      }
      boton('‹', pagina - 1, pagina === 1);
      var ultimo = 0;
      for (var p = 1; p <= paginas; p++) {
        if (p === 1 || p === paginas || Math.abs(p - pagina) <= 2) {
          if (ultimo && p - ultimo > 1) {
            var s = document.createElement('span'); s.className = 'page-sep'; s.textContent = '…'; ctr.appendChild(s);
          }
          boton(String(p), p, false, p === pagina);
          ultimo = p;
        }
      }
      boton('›', pagina + 1, pagina === paginas);
    }

    // data-sin-orden: grilla quieta (la ordena o pagina el servidor, o se lee en su orden).
    var ths = tbl.tHead && !tbl.hasAttribute('data-sin-orden') ? tbl.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(ths, function (th, col) {
      if (th.hasAttribute('data-no-sort')) return;
      th.addEventListener('click', function () {
        var asc = !th.classList.contains('sort-asc');
        Array.prototype.forEach.call(ths, function (o) { o.classList.remove('sort-asc', 'sort-desc'); });
        th.classList.add(asc ? 'sort-asc' : 'sort-desc');
        filas.sort(function (a, b) {
          var x = valor(a.cells[col]), y = valor(b.cells[col]);
          var r = (typeof x === 'number' && typeof y === 'number') ? x - y : String(x).localeCompare(String(y), 'es', { numeric: true });
          return asc ? r : -r;
        });
        pagina = 1;
        dibujar();
      });
    });

    if (selTam) selTam.addEventListener('change', function () { pagina = 1; dibujar(); });
    dibujar();
  }

  document.querySelectorAll('table.data-table').forEach(iniciar);
})();
