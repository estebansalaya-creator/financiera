<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';
require_once __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Recibos emitidos (el «Recibos emitidos» de gestion_adm): un renglón por
// recibo, con los préstamos a los que se aplicó. Se emiten en Cobrar, en
// Importar lote y en Cobros bancarios; acá se mira para atrás.
//   - Por defecto, los vigentes de todas las fechas (pagina la base).
//   - Nº de recibo exacto: busca ese y nada más, esté como esté.
//   - Anular desde la grilla pide el motivo y va a recibo.php (la misma
//     regla: cada pago tiene que ser el último de su préstamo; si no se
//     puede, la fila lo dice en vez de dejar intentar).
//   - Excel con todo el filtro, en el mismo orden que la grilla.
//   - Abajo, los totales por medio (para cerrar la caja) y los lotes.
// ---------------------------------------------------------------------

const ORIGENES_RECIBO = ['cobrar' => 'Cobro manual', 'extracto' => 'Cobro bancario', 'lote' => 'Lote importado'];

$errores = [];
$fDesde  = leer_fecha(get_str('desde')) ?? '';
$fHasta  = leer_fecha(get_str('hasta')) ?? '';
$fMedio  = isset(MEDIOS_PAGO[get_str('medio')]) ? get_str('medio') : '';
$fEstado = in_array(get_str('estado'), ['ANULADO', 'todos'], true) ? get_str('estado') : 'APLICADO';
$fOrigen = isset(ORIGENES_RECIBO[get_str('origen')]) ? get_str('origen') : '';
$fVend   = (int) get_str('vendedor');
$fLote   = (int) get_str('lote');
$fQ      = trim(get_str('q'));
$fNro    = trim(get_str('nro'));
$fImpMin = importe_filtro(get_str('imp_desde'));
$fImpMax = importe_filtro(get_str('imp_hasta'));
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

// El WHERE sin el estado: lo comparten la grilla, los indicadores (que
// cuentan vigentes y anulados por separado), los totales por medio y el Excel.
$where  = ['1=1'];
$params = [];
if ($fNro !== '') {
    // Ese recibo y nada más: los demás filtros no cuentan.
    preg_match_all('/\d+/', $fNro, $m);
    $partes = $m[0];
    if (!$partes) {
        $where[] = '1 = 0';
    } else {
        $where[] = "CAST(SUBSTRING_INDEX(r.nro_recibo, '-', -1) AS UNSIGNED) = ?";
        $params[] = (int) end($partes);
        if (count($partes) >= 2) {
            $where[] = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(r.nro_recibo, '-', 1), ' ', -1) AS UNSIGNED) = ?";
            $params[] = (int) $partes[count($partes) - 2];
        }
    }
    $fDesde = $fHasta = $fMedio = $fOrigen = $fQ = '';
    $fVend = $fLote = 0;
    $fImpMin = $fImpMax = null;
    $fEstado = 'todos';
} else {
    if ($fLote > 0) {                       // un lote se ve completo, sin importar la fecha
        $where[] = 'r.id_lote = ?';
        $params[] = $fLote;
        $fDesde = $fHasta = '';
    }
    if ($fDesde !== '') {
        $where[] = 'r.fecha >= ?';
        $params[] = $fDesde;
    }
    if ($fHasta !== '') {
        $where[] = 'r.fecha <= ?';
        $params[] = $fHasta;
    }
    if ($fMedio !== '') {
        $where[] = 'r.medio = ?';
        $params[] = $fMedio;
    }
    if ($fOrigen === 'extracto') {
        $where[] = 'r.id_extracto IS NOT NULL';
    } elseif ($fOrigen === 'lote') {
        $where[] = 'r.id_lote IS NOT NULL';
    } elseif ($fOrigen === 'cobrar') {
        $where[] = 'r.id_extracto IS NULL AND r.id_lote IS NULL';
    }
    if ($fVend > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                             WHERE g.id_recibo = r.id_recibo AND p.id_vendedor = ?)';
        $params[] = $fVend;
    }
    if ($fImpMin !== null) {
        $where[] = 'r.importe >= ?';
        $params[] = $fImpMin;
    }
    if ($fImpMax !== null) {
        $where[] = 'r.importe <= ?';
        $params[] = $fImpMax;
    }
    if ($fQ !== '') {
        $dig = preg_replace('/\D/', '', $fQ);
        if (strlen($dig) === 11 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
            $where[] = '(c.cuit = ? OR r.cuil_pagador = ?)';
            array_push($params, $dig, $dig);
        } else {
            $like = '%' . addcslashes($fQ, '%_\\') . '%';
            $where[] = '(r.nro_recibo LIKE ? OR c.denominacion LIKE ? OR r.referencia LIKE ?
                         OR EXISTS (SELECT 1 FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                                     WHERE g.id_recibo = r.id_recibo AND p.nro_prestamo LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }
    }
}
$desdeSql = 'FROM recibos r
             JOIN clientes c ON c.id_cliente = r.id_cliente
             LEFT JOIN cuentas_fondos cf ON cf.id_cuenta_fondos = r.id_cuenta_fondos
            WHERE ' . implode(' AND ', $where);
$wEstado = $fEstado === 'todos' ? '' : ' AND r.estado = ' . ($fEstado === 'ANULADO' ? "'ANULADO'" : "'APLICADO'");
$hayFiltros = $fNro !== '' || $fQ !== '' || $fDesde !== '' || $fHasta !== '' || $fMedio !== '' || $fOrigen !== '' || $fVend
           || $fLote || $fImpMin !== null || $fImpMax !== null || $fEstado !== 'APLICADO';
$orden = orden_grilla(['fecha' => 'r.fecha', 'recibo' => 'r.nro_recibo', 'cliente' => 'c.denominacion', 'medio' => 'CAST(r.medio AS CHAR)',
                       'importe' => 'r.importe', 'estado' => 'r.estado'], 'fecha', 'desc', 'r.id_recibo');

// Lo de cada recibo que no está en la cabecera: sus préstamos y comercializadores,
// y si alguno de sus préstamos tiene un pago posterior (entonces no se anula).
$columnas = "r.id_recibo, r.nro_recibo, r.fecha, r.importe, r.medio, r.referencia, r.estado, r.id_lote, r.id_extracto,
             r.cuil_pagador, r.motivo_anulacion, r.anulado_el, c.id_cliente, c.denominacion, c.cuit, cf.nombre AS cuenta,
             CONCAT_WS(', ', (SELECT GROUP_CONCAT(p.nro_prestamo ORDER BY p.id_prestamo SEPARATOR ', ')
                                FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo WHERE g.id_recibo = r.id_recibo),
                       " . (columna_existe('cuotas_sociales_pagos', 'id_recibo')
                            ? "IF(EXISTS (SELECT 1 FROM cuotas_sociales_pagos s WHERE s.id_recibo = r.id_recibo), 'Cuota social', NULL)" : 'NULL') . ") AS prestamos,
             (SELECT MIN(g.id_prestamo) FROM pagos g WHERE g.id_recibo = r.id_recibo) AS id_prestamo,
             (SELECT COUNT(*) FROM pagos g WHERE g.id_recibo = r.id_recibo) AS n_prestamos,
             (SELECT SUM(g.modo = 'CANCELACION') FROM pagos g WHERE g.id_recibo = r.id_recibo) AS cancelaciones,
             (SELECT GROUP_CONCAT(DISTINCT v.nombre ORDER BY v.nombre SEPARATOR ', ')
                FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo JOIN vendedores v ON v.id_vendedor = p.id_vendedor
               WHERE g.id_recibo = r.id_recibo) AS vendedor,
             IF(r.estado <> 'APLICADO', NULL,
                (SELECT GROUP_CONCAT(p.nro_prestamo SEPARATOR ', ')
                   FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                  WHERE g.id_recibo = r.id_recibo
                    AND EXISTS (SELECT 1 FROM pagos x WHERE x.id_prestamo = g.id_prestamo AND x.estado = 'APLICADO'
                                   AND (x.fecha > g.fecha OR (x.fecha = g.fecha AND x.id_pago > g.id_pago))))) AS con_posterior";

/** De dónde salió el recibo, en palabras. */
function origen_recibo(array $r): string
{
    if ($r['id_extracto']) {
        return 'Cobro bancario';
    }
    return $r['id_lote'] ? 'Lote N° ' . (int) $r['id_lote'] : 'Cobro manual';
}

$filas = $porMedio = $lotes = $vendedores = [];
$res = ['n' => 0, 'importe' => 0, 'clientes' => 0, 'anulados' => 0, 'anulado' => 0, 'banco' => 0, 'banco_importe' => 0];
$total = 0;
$paginas = 1;
try {
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
    $res = q1("SELECT COALESCE(SUM(r.estado = 'APLICADO'), 0) AS n,
                      COALESCE(SUM(IF(r.estado = 'APLICADO', r.importe, 0)), 0) AS importe,
                      COUNT(DISTINCT IF(r.estado = 'APLICADO', r.id_cliente, NULL)) AS clientes,
                      COALESCE(SUM(r.estado = 'ANULADO'), 0) AS anulados,
                      COALESCE(SUM(IF(r.estado = 'ANULADO', r.importe, 0)), 0) AS anulado,
                      COALESCE(SUM(r.estado = 'APLICADO' AND r.id_extracto IS NOT NULL), 0) AS banco,
                      COALESCE(SUM(IF(r.estado = 'APLICADO' AND r.id_extracto IS NOT NULL, r.importe, 0)), 0) AS banco_importe
                 $desdeSql", $params) ?: $res;
    $total = (int) q1("SELECT COUNT(*) AS n $desdeSql $wEstado", $params)['n'];
    $porMedio = q("SELECT r.medio, COUNT(*) AS n, SUM(r.importe) AS total $desdeSql AND r.estado = 'APLICADO'
                    GROUP BY r.medio ORDER BY total DESC", $params);
    $lotes = q("SELECT l.*, (SELECT COUNT(*) FROM recibos x WHERE x.id_lote = l.id_lote AND x.estado = 'APLICADO') AS aplicados
                  FROM lotes_cobranza l ORDER BY l.id_lote DESC LIMIT 8");

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $todas = q("SELECT $columnas $desdeSql $wEstado ORDER BY {$orden['sql']}", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Recibos')->titulo('Recibos emitidos' . ($fDesde !== '' || $fHasta !== '' ? ' · ' . ($fDesde !== '' ? 'del ' . fecha($fDesde) : 'hasta') . ($fHasta !== '' ? ' al ' . fecha($fHasta) : '') : ''),
                                        ($hayFiltros ? 'Filtrado · ' : '') . num($total) . ' recibos · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['Fecha', 'fecha', 12], ['Recibo', 'texto', 17], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15], ['Préstamos', 'texto', 22],
                 ['Comercializador', 'texto', 20], ['Medio', 'texto', 14], ['Referencia', 'texto', 20], ['Origen', 'texto', 18], ['Cuenta', 'texto', 22],
                 ['Estado', 'texto', 10], ['Motivo de anulación', 'texto', 30], ['Importe', 'moneda', 15]],
                array_map(function ($r) {
                    return [$r['fecha'], $r['nro_recibo'], $r['denominacion'], cuit_fmt($r['cuit']), (string) $r['prestamos'], (string) $r['vendedor'],
                            MEDIOS_PAGO[$r['medio']] ?? $r['medio'], (string) $r['referencia'], origen_recibo($r), (string) $r['cuenta'],
                            $r['estado'] === 'ANULADO' ? 'Anulado' : 'Vigente', (string) $r['motivo_anulacion'], (float) $r['importe']];
                }, $todas),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:A})', 'valor' => 'Total: ' . count($todas)], 12 => ['suma' => true]]
            );
        $libro->enviar('recibos_emitidos_' . date('Ymd') . '.xlsx');
        exit;
    }

    $paginas = max(1, (int) ceil($total / $porPag));
    $pagina  = min($pagina, $paginas);
    $filas = q("SELECT $columnas $desdeSql $wEstado ORDER BY {$orden['sql']} LIMIT ? OFFSET ?", array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

$volver = 'cobranzas.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$anulaRecibo = puede_abrir('recibo.php');

layout_inicio('Recibos emitidos', 'cobranzas');
titulo('Recibos emitidos', $fLote ? 'Lote N° ' . $fLote : 'Los recibos de cobranza de cada cliente, con los préstamos a los que se aplicaron. Se emiten en Cobros en efectivo, Cobros bancarios e Importar lote.',
       (puede('emision_recibos.php') ? boton('Cobros bancarios', 'secondary', 'emision_recibos.php', 'banco') : '')
     . (puede('cobrar.php') ? boton('Cobros en efectivo', 'ok', 'cobrar.php', 'dinero') : ''),
       '<a href="cobranzas.php">Cobranzas</a> / Recibos emitidos');
mensajes($errores);
?>

<div class="kpi-row">
  <div class="kpi-card"><div class="kpi-ico"><?= icono('documento', 20) ?></div>
    <div><div class="kpi-lbl">Recibos</div><div class="kpi-val"><?= num($res['n']) ?></div>
      <div class="kpi-sub">vigentes, con los filtros aplicados · <?= num($res['clientes']) ?> <?= (int) $res['clientes'] === 1 ? 'cliente' : 'clientes' ?></div></div></div>
  <div class="kpi-card"><div class="kpi-ico ok"><?= icono('dinero', 20) ?></div>
    <div><div class="kpi-lbl">Importe cobrado</div><div class="kpi-val monto"><?= money($res['importe']) ?></div>
      <div class="kpi-sub"><?= $porMedio ? h(implode(' · ', array_map(function ($m) { return (MEDIOS_PAGO[$m['medio']] ?? $m['medio']) . ' ' . money($m['total']); }, array_slice($porMedio, 0, 2)))) : 'sin cobros' ?></div></div></div>
  <div class="kpi-card"><div class="kpi-ico"><?= icono('banco', 20) ?></div>
    <div><div class="kpi-lbl">Del extracto bancario</div><div class="kpi-val monto"><?= money($res['banco_importe']) ?></div>
      <div class="kpi-sub"><?= num($res['banco']) ?> emitidos en Cobros bancarios</div></div></div>
  <div class="kpi-card"><div class="kpi-ico danger"><?= icono('borrar', 20) ?></div>
    <div><div class="kpi-lbl">Anulados</div><div class="kpi-val"><?= num($res['anulados']) ?></div>
      <div class="kpi-sub"><?= (int) $res['anulados'] ? money($res['anulado']) . ' · no suman' : 'ninguno' ?></div></div></div>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <?php if ($fLote): ?><input type="hidden" name="lote" value="<?= $fLote ?>"><?php endif; ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Cliente, CUIT, recibo, préstamo o referencia</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Buscar…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Nº recibo exacto</span>
      <input type="text" name="nro" value="<?= h($fNro) ?>" size="15" class="filtro mono <?= $fNro !== '' ? 'activo' : '' ?>" placeholder="00001-00000123"
             title="Busca ese recibo y nada más: los demás filtros no cuentan"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Medio</span>
      <select name="medio" class="filtro <?= $fMedio !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(MEDIOS_PAGO, $fMedio, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Origen</span>
      <select name="origen" class="filtro <?= $fOrigen !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ORIGENES_RECIBO, $fOrigen, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Comercializador</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Importe desde</span>
      <input type="text" name="imp_desde" value="<?= h(get_str('imp_desde')) ?>" size="10" class="filtro" inputmode="decimal" placeholder="0,00"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Importe hasta</span>
      <input type="text" name="imp_hasta" value="<?= h(get_str('imp_hasta')) ?>" size="10" class="filtro" inputmode="decimal" placeholder="0,00"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== 'APLICADO' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['APLICADO' => 'Vigentes', 'ANULADO' => 'Anulados', 'todos' => 'Todos'], $fEstado) ?></select></label>
    <?= filtro_botones('cobranzas.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'recibos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><?= th_orden('Fecha', 'fecha', $orden) ?><?= th_orden('Recibo', 'recibo', $orden) ?>
        <?= th_orden('Cliente', 'cliente', $orden) ?><th data-no-sort>Préstamos</th><?= th_orden('Medio', 'medio', $orden) ?>
        <?= th_orden('Estado', 'estado', $orden) ?><?= th_orden('Importe', 'importe', $orden, 'th-right') ?></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="8" class="no-data"><?= $hayFiltros ? 'No hay recibos con estos filtros.' : 'Todavía no se emitió ningún recibo.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $r): $anulado = $r['estado'] === 'ANULADO'; ?>
        <tr<?= $anulado ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'recibo.php?r=' . (int) $r['id_recibo'], 'Ver el recibo') ?>
            <?= btn_accion('pdf', 'recibo.php?r=' . (int) $r['id_recibo'] . '&export=pdf', 'Recibo en PDF', true) ?>
            <?php if (!$anulado && $anulaRecibo): ?>
              <?php if ($r['con_posterior']): ?>
                <span class="btn-accion bloqueado" title="No se puede anular: <?= h(strpos((string) $r['con_posterior'], ',') !== false ? 'los préstamos ' : 'el préstamo ') . h($r['con_posterior']) ?> tiene un pago posterior. Primero hay que anular ese."><?= icono('borrar') ?></span>
              <?php else: ?>
                <button type="button" class="btn-accion borrar" title="Anular el recibo" aria-label="Anular el recibo"
                        data-anular="<?= (int) $r['id_recibo'] ?>" data-nro="<?= h($r['nro_recibo']) ?>" data-importe="<?= h(money($r['importe'])) ?>"
                        data-prestamos="<?= (int) $r['n_prestamos'] ?>"><?= icono('borrar') ?></button>
              <?php endif; ?>
            <?php endif; ?></div></td>
          <td><?= h(fecha($r['fecha'])) ?></td>
          <td class="mono td-fuerte"><a href="recibo.php?r=<?= (int) $r['id_recibo'] ?>"><?= h($r['nro_recibo']) ?></a>
            <span class="segunda-linea"><?= h(origen_recibo($r)) ?></span></td>
          <td class="td-wrap"><?= h($r['denominacion']) ?><span class="segunda-linea mono"><?= h(cuit_fmt($r['cuit'])) ?><?=
              $r['cuil_pagador'] && $r['cuil_pagador'] !== $r['cuit'] ? ' · pagó ' . h(cuit_fmt($r['cuil_pagador'])) : '' ?><?= $r['vendedor'] ? ' · ' . h($r['vendedor']) : '' ?></span></td>
          <td class="td-wrap"><?php if ((int) $r['n_prestamos'] === 1): ?><a href="prestamo.php?id=<?= (int) $r['id_prestamo'] ?>"><?= h($r['prestamos']) ?></a><?php else: ?><?= h((string) $r['prestamos']) ?><?php endif; ?>
            <?= (int) $r['cancelaciones'] ? '<span class="segunda-linea">cancelación total</span>' : '' ?></td>
          <td><?= h(MEDIOS_PAGO[$r['medio']] ?? $r['medio']) ?><span class="segunda-linea"><?= h(implode(' · ', array_filter([(string) $r['referencia'], (string) $r['cuenta']]))) ?></span></td>
          <td><?= $anulado ? estado('Anulado', 'danger') . '<span class="segunda-linea td-wrap">' . h(date('d/m/Y', strtotime((string) $r['anulado_el']))) . ': ' . h((string) $r['motivo_anulacion']) . '</span>' : estado('Vigente', 'ok') ?></td>
          <td class="td-right td-fuerte"><?= money($r['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $total, $porPag, 'recibos') ?>
</div>

<div class="card">
  <?= card_head('Por medio', 'recibos vigentes del filtro, para cerrar la caja') ?>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th>Medio</th><th class="th-right">Recibos</th><th class="th-right">Total</th></tr></thead>
      <tbody>
      <?php if (!$porMedio): ?><tr><td colspan="3" class="no-data">—</td></tr><?php endif; ?>
      <?php foreach ($porMedio as $m): ?>
        <tr><td><a href="<?= h(url_con(['medio' => $m['medio'], 'p' => null])) ?>"><?= h(MEDIOS_PAGO[$m['medio']] ?? $m['medio']) ?></a></td>
          <td class="td-right"><?= num($m['n']) ?></td><td class="td-right"><?= money($m['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($porMedio): ?>
      <tfoot><tr class="total-row"><td>TOTAL</td><td class="td-right"><?= num(array_sum(array_column($porMedio, 'n'))) ?></td>
        <td class="td-right"><?= money($res['importe']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <?= card_head('Últimos lotes', 'los ocho últimos importados', puede('lote.php') ? boton('Importar', 'ghost', 'lote.php', 'agregar', false, true) : '') ?>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Lote</th><th>Fecha</th><th>Archivo</th><th>Medio</th><th class="th-right">Recibos</th><th class="th-right">Total</th></tr></thead>
      <tbody>
      <?php if (!$lotes): ?><tr><td colspan="7" class="no-data">Todavía no se importó ninguno.</td></tr><?php endif; ?>
      <?php foreach ($lotes as $l): ?>
        <tr><td><div class="acciones"><?= btn_accion('ver', 'cobranzas.php?lote=' . (int) $l['id_lote'] . '&estado=todos', 'Ver los recibos del lote') ?></div></td>
          <td data-orden="<?= (int) $l['id_lote'] ?>"><a href="cobranzas.php?lote=<?= (int) $l['id_lote'] ?>&amp;estado=todos">N° <?= (int) $l['id_lote'] ?></a></td>
          <td data-orden="<?= h($l['fecha']) ?>"><?= h(fecha($l['fecha'])) ?></td>
          <td class="td-wrap"><?= h((string) $l['archivo']) ?></td>
          <td><?= h(MEDIOS_PAGO[$l['medio']] ?? $l['medio']) ?></td>
          <td class="td-right"><?= num($l['aplicados']) ?></td><td class="td-right"><?= money($l['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($anulaRecibo): ?>
<div class="modal" id="modal-anular">
  <form method="post" action="recibo.php" class="modal-caja">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="anular">
    <input type="hidden" name="volver" value="<?= h($volver) ?>">
    <div class="modal-titulo">Anular el recibo <span data-nro></span></div>
    <div class="modal-texto">Se anula por <span data-importe></span><span data-varios></span> y la deuda vuelve a como estaba. Los punitorios que ya se habían devengado se mantienen y el número no se vuelve a usar.</div>
    <div class="campo"><label for="motivo">Motivo <span class="req">*</span></label>
      <input id="motivo" name="motivo" maxlength="200" required minlength="5" placeholder="Ej.: se cargó al cliente equivocado"></div>
    <div class="modal-acciones"><button type="button" class="btn btn-secondary" data-cerrar>Cancelar</button>
      <button type="submit" class="btn btn-danger">Anular el recibo</button></div>
  </form>
</div>
<script>
(function () {
  var modal = document.getElementById('modal-anular'), form = modal.querySelector('form');
  document.querySelectorAll('[data-anular]').forEach(function (b) {
    b.addEventListener('click', function () {
      form.action = 'recibo.php?r=' + encodeURIComponent(b.getAttribute('data-anular'));
      modal.querySelector('[data-nro]').textContent = b.getAttribute('data-nro');
      modal.querySelector('[data-importe]').textContent = b.getAttribute('data-importe');
      var n = parseInt(b.getAttribute('data-prestamos'), 10) || 1;
      modal.querySelector('[data-varios]').textContent = n > 1 ? ', con lo que aplicó a los ' + n + ' préstamos,' : '';
      form.reset();
      modal.classList.add('abierto');
      modal.querySelector('#motivo').focus();
    });
  });
  modal.querySelector('[data-cerrar]').addEventListener('click', function () { modal.classList.remove('abierto'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('abierto'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.classList.remove('abierto'); });
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
