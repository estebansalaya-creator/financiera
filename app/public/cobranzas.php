<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';

// ---------------------------------------------------------------------
// Cobranzas: los pagos registrados en un período
//   Por defecto, los de hoy. Filtros por fecha, medio, estado, vendedor,
//   lote y búsqueda. Totales por medio (para cerrar la caja del día) y
//   Excel con todo el filtro.
// ---------------------------------------------------------------------

$errores = [];
$fDesde  = leer_fecha(get_str('desde')) ?? date('Y-m-d');
$fHasta  = leer_fecha(get_str('hasta')) ?? $fDesde;
$fMedio  = isset(MEDIOS_PAGO[get_str('medio')]) ? get_str('medio') : '';
$fEstado = in_array(get_str('estado'), ['APLICADO', 'ANULADO'], true) ? get_str('estado') : '';
$fVend   = (int) get_str('vendedor');
$fLote   = (int) get_str('lote');
$fQ      = trim(get_str('q'));
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

$where  = 'g.fecha BETWEEN ? AND ?';
$params = [$fDesde, $fHasta];
if ($fLote > 0) {                       // un lote se ve completo, sin importar la fecha
    $where  = 'g.id_lote = ?';
    $params = [$fLote];
}
if ($fMedio !== '') {
    $where .= ' AND g.medio = ?';
    $params[] = $fMedio;
}
if ($fEstado !== '') {
    $where .= ' AND g.estado = ?';
    $params[] = $fEstado;
}
if ($fVend > 0) {
    $where .= ' AND p.id_vendedor = ?';
    $params[] = $fVend;
}
if ($fQ !== '') {
    $dig = preg_replace('/\D/', '', $fQ);
    if (strlen($dig) === 11 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
        $where .= ' AND c.cuit = ?';
        $params[] = $dig;
    } else {
        $like = '%' . addcslashes($fQ, '%_\\') . '%';
        $where .= ' AND (g.nro_recibo LIKE ? OR p.nro_prestamo LIKE ? OR c.denominacion LIKE ? OR g.referencia LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
}
$desde = "FROM pagos g
          JOIN prestamos p ON p.id_prestamo = g.id_prestamo
          JOIN clientes c ON c.id_cliente = p.id_cliente
          LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
         WHERE $where";
$columnas = 'g.id_pago, g.nro_recibo, g.fecha, g.importe, g.medio, g.referencia, g.modo, g.estado, g.id_lote, g.creado_el,
             p.id_prestamo, p.nro_prestamo, c.id_cliente, c.denominacion, c.cuit, v.nombre AS vendedor';
$hayFiltros = $fMedio !== '' || $fEstado !== '' || $fVend || $fLote || $fQ !== '';

$filas = $porMedio = $lotes = [];
$tot = ['n' => 0, 'cobrado' => 0, 'anulados' => 0, 'anulado' => 0];
try {
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
    $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(IF(g.estado = 'APLICADO', g.importe, 0)), 0) AS cobrado,
                      SUM(g.estado = 'ANULADO') AS anulados, COALESCE(SUM(IF(g.estado = 'ANULADO', g.importe, 0)), 0) AS anulado $desde", $params);
    $porMedio = q("SELECT g.medio, COUNT(*) AS n, SUM(g.importe) AS total $desde AND g.estado = 'APLICADO' GROUP BY g.medio ORDER BY total DESC", $params);
    $lotes = q("SELECT l.*, (SELECT COUNT(*) FROM pagos g WHERE g.id_lote = l.id_lote AND g.estado = 'APLICADO') AS aplicados
                  FROM lotes_cobranza l ORDER BY l.id_lote DESC LIMIT 8");

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $todas = q("SELECT $columnas $desde ORDER BY g.fecha, g.id_pago", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Cobranzas')->titulo('Cobranzas del ' . fecha($fDesde) . ($fHasta !== $fDesde ? ' al ' . fecha($fHasta) : ''),
                                          ($hayFiltros ? 'Filtrado · ' : '') . num($tot['n']) . ' pagos · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['Fecha', 'fecha', 12], ['Recibo', 'texto', 13], ['Préstamo', 'texto', 11], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15],
                 ['Vendedor', 'texto', 20], ['Medio', 'texto', 14], ['Referencia', 'texto', 20], ['Tipo', 'texto', 13],
                 ['Lote', 'entero', 7], ['Estado', 'texto', 10], ['Importe', 'moneda', 15]],
                array_map(function ($g) {
                    return [$g['fecha'], $g['nro_recibo'], $g['nro_prestamo'], $g['denominacion'], cuit_fmt($g['cuit']), $g['vendedor'],
                            MEDIOS_PAGO[$g['medio']] ?? $g['medio'], $g['referencia'], $g['modo'] === 'CANCELACION' ? 'Cancelación' : 'A cuenta',
                            $g['id_lote'], $g['estado'] === 'ANULADO' ? 'Anulado' : 'Aplicado', $g['importe']];
                }, $todas),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:A})', 'valor' => 'Total: ' . count($todas)], 11 => ['suma' => true]]
            );
        $libro->enviar('cobranzas_' . str_replace('-', '', $fDesde) . ($fHasta !== $fDesde ? '_' . str_replace('-', '', $fHasta) : '') . '.xlsx');
        exit;
    }

    $paginas = max(1, (int) ceil($tot['n'] / $porPag));
    $pagina  = min($pagina, $paginas);
    $filas = q("SELECT $columnas $desde ORDER BY g.fecha DESC, g.id_pago DESC LIMIT ? OFFSET ?", array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $paginas = 1;
    $vendedores = [];
}

layout_inicio('Cobranzas', 'cobranzas');
titulo('Cobranzas', $fLote ? 'Lote N° ' . $fLote : ('Pagos del ' . fecha($fDesde) . ($fHasta !== $fDesde ? ' al ' . fecha($fHasta) : '')),
       boton('Exportar a Excel', 'excel', url_con(['export' => 'xlsx', 'p' => null]), 'planilla')
     . boton('Importar lote', 'secondary', 'lote.php', 'planilla')
     . boton('Cobrar', 'ok', 'cobrar.php', 'dinero'));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cobrado</span><strong><?= money($tot['cobrado']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num((int) $tot['n'] - (int) $tot['anulados']) ?> pagos aplicados</span></div>
  <?php foreach (array_slice($porMedio, 0, 2) as $m): ?>
    <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl"><?= h(MEDIOS_PAGO[$m['medio']] ?? $m['medio']) ?></span><strong><?= money($m['total']) ?></strong>
      <span class="bcra-tarjeta-sub"><?= num($m['n']) ?> pagos</span></div>
  <?php endforeach; ?>
  <?php for ($i = count(array_slice($porMedio, 0, 2)); $i < 2; $i++): ?>
    <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">&nbsp;</span><strong>—</strong><span class="bcra-tarjeta-sub"></span></div>
  <?php endfor; ?>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Anulados</span><strong><?= num($tot['anulados']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= (int) $tot['anulados'] ? money($tot['anulado']) : 'ninguno' ?></span></div>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filter-input"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filter-input"></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Recibo, préstamo, cliente, CUIT, referencia…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Medio</span>
      <select name="medio" class="filtro <?= $fMedio !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(MEDIOS_PAGO, $fMedio, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['APLICADO' => 'Aplicados', 'ANULADO' => 'Anulados'], $fEstado, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <?php if ($fLote): ?><input type="hidden" name="lote" value="<?= $fLote ?>"><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Filtrar</button>
    <?php if ($hayFiltros || $fDesde !== date('Y-m-d') || $fHasta !== date('Y-m-d')): ?>
      <a class="btn btn-secondary btn-sm" href="cobranzas.php"><?= icono('quitar_filtro') ?>Hoy, sin filtros</a>
    <?php endif; ?>
  </form>
</div>

<div class="columnas-2-1">
  <div class="card">
    <div class="data-table-wrap">
      <table class="data-table" data-sin-orden>
        <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Fecha</th><th data-no-sort>Recibo</th><th data-no-sort>Cliente</th>
          <th data-no-sort>Medio</th><th class="th-right" data-no-sort>Importe</th><th data-no-sort>Estado</th></tr></thead>
        <tbody>
        <?php if (!$filas): ?><tr><td colspan="7" class="no-data">No hay pagos <?= $hayFiltros ? 'con este filtro' : 'en el período' ?>.</td></tr><?php endif; ?>
        <?php foreach ($filas as $g): ?>
          <tr<?= $g['estado'] === 'ANULADO' ? ' class="fila-baja"' : '' ?>>
            <td><div class="acciones"><?= btn_accion('ver', 'recibo.php?id=' . $g['id_pago'], 'Ver el recibo') ?>
              <?= btn_accion('pdf', 'recibo.php?id=' . $g['id_pago'] . '&export=pdf', 'Recibo en PDF', true) ?></div></td>
            <td><?= h(fecha($g['fecha'])) ?></td>
            <td class="mono"><a href="recibo.php?id=<?= (int) $g['id_pago'] ?>"><?= h($g['nro_recibo']) ?></a>
              <span class="segunda-linea">préstamo <a href="prestamo.php?id=<?= (int) $g['id_prestamo'] ?>"><?= h($g['nro_prestamo']) ?></a></span></td>
            <td><?= h($g['denominacion']) ?><span class="segunda-linea mono"><?= h(cuit_fmt($g['cuit'])) ?><?= $g['vendedor'] ? ' · ' . h($g['vendedor']) : '' ?></span></td>
            <td><?= h(MEDIOS_PAGO[$g['medio']] ?? $g['medio']) ?><span class="segunda-linea"><?= h(implode(' · ', array_filter([$g['modo'] === 'CANCELACION' ? 'cancelación' : '', (string) $g['referencia'], $g['id_lote'] ? 'lote ' . (int) $g['id_lote'] : '']))) ?></span></td>
            <td class="td-right td-fuerte"><?= money($g['importe']) ?></td>
            <td><?= $g['estado'] === 'ANULADO' ? estado('Anulado', 'neutro') : estado('Aplicado', 'ok') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'pagos') ?>
  </div>

  <div>
    <div class="card">
      <?= card_head('Por medio', 'pagos aplicados') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden>
          <thead><tr><th data-no-sort>Medio</th><th class="th-right" data-no-sort>Pagos</th><th class="th-right" data-no-sort>Total</th></tr></thead>
          <tbody>
          <?php if (!$porMedio): ?><tr><td colspan="3" class="no-data">—</td></tr><?php endif; ?>
          <?php foreach ($porMedio as $m): ?>
            <tr><td><a href="<?= h(url_con(['medio' => $m['medio'], 'p' => null])) ?>"><?= h(MEDIOS_PAGO[$m['medio']] ?? $m['medio']) ?></a></td>
              <td class="td-right"><?= num($m['n']) ?></td><td class="td-right"><?= money($m['total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <?php if ($porMedio): ?>
          <tfoot><tr class="total-row"><td>TOTAL</td><td class="td-right"><?= num(array_sum(array_column($porMedio, 'n'))) ?></td>
            <td class="td-right"><?= money($tot['cobrado']) ?></td></tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <div class="card">
      <?= card_head('Últimos lotes', '', boton('Importar', 'ghost', 'lote.php', 'agregar', false, true)) ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden>
          <thead><tr><th data-no-sort>Lote</th><th data-no-sort>Medio</th><th class="th-right" data-no-sort>Pagos</th><th class="th-right" data-no-sort>Total</th></tr></thead>
          <tbody>
          <?php if (!$lotes): ?><tr><td colspan="4" class="no-data">Todavía no se importó ninguno.</td></tr><?php endif; ?>
          <?php foreach ($lotes as $l): ?>
            <tr><td><a href="cobranzas.php?lote=<?= (int) $l['id_lote'] ?>">N° <?= (int) $l['id_lote'] ?></a><span class="segunda-linea"><?= h(fecha($l['fecha'])) ?><?= $l['archivo'] ? ' · ' . h($l['archivo']) : '' ?></span></td>
              <td><?= h(MEDIOS_PAGO[$l['medio']] ?? $l['medio']) ?></td>
              <td class="td-right"><?= num($l['aplicados']) ?></td><td class="td-right"><?= money($l['total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php layout_fin(); ?>
