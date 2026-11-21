<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/prestamos.php';

// ---------------------------------------------------------------------
// Préstamos: listado
//   Paginado en el servidor, con filtros por estado, mora, tipo de asociado,
//   vendedor y fecha de liquidación. El Excel trae todo el filtro.
//   Saldo de capital = capital - lo imputado a capital de pagos aplicados.
//   La mora sale de v_mora_prestamos (cuotas vencidas impagas a hoy).
// ---------------------------------------------------------------------

const FILTRO_MORA = ['AL_DIA' => 'Al día', 'MORA' => 'En mora (todas)', '1-30' => '1 a 30 días', '31-60' => '31 a 60 días',
                     '61-90' => '61 a 90 días', '+90' => 'Más de 90 días'];

$errores = [];
$fQ      = trim(get_str('q'));
$fEstado = isset(ESTADOS_PRESTAMO[get_str('estado')]) ? get_str('estado') : '';
$fMora   = isset(FILTRO_MORA[get_str('mora')]) ? get_str('mora') : '';
$fTipo   = (int) get_str('tipo');
$fVend   = (int) get_str('vendedor');
$fDesde  = leer_fecha(get_str('desde')) ?? '';
$fHasta  = leer_fecha(get_str('hasta')) ?? '';
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

$where  = '1 = 1';
$params = [];
if ($fQ !== '') {
    $dig = preg_replace('/\D/', '', $fQ);
    if ($dig !== '' && strlen($dig) >= 7 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
        $where .= ' AND (c.cuit LIKE ? OR cp.dni = ?)';
        array_push($params, '%' . $dig . '%', $dig);
    } else {
        $like = '%' . addcslashes($fQ, '%_\\') . '%';
        $where .= ' AND (p.nro_prestamo LIKE ? OR c.denominacion LIKE ?)';
        array_push($params, $like, $like);
    }
}
if ($fEstado !== '') {
    $where .= ' AND p.estado = ?';
    $params[] = $fEstado;
}
if ($fMora === 'AL_DIA') {
    $where .= " AND p.estado = 'VIGENTE' AND m.id_prestamo IS NULL";
} elseif ($fMora === 'MORA') {
    $where .= ' AND m.id_prestamo IS NOT NULL';
} elseif ($fMora !== '') {
    $where .= ' AND m.tramo = ?';
    $params[] = $fMora;
}
if ($fTipo > 0) {
    $where .= ' AND p.id_tipo_asociado = ?';
    $params[] = $fTipo;
}
if ($fVend > 0) {
    $where .= ' AND p.id_vendedor = ?';
    $params[] = $fVend;
}
if ($fDesde !== '') {
    $where .= ' AND p.fecha_liquidacion >= ?';
    $params[] = $fDesde;
}
if ($fHasta !== '') {
    $where .= ' AND p.fecha_liquidacion <= ?';
    $params[] = $fHasta;
}
$desde = "FROM prestamos p
          JOIN clientes c ON c.id_cliente = p.id_cliente
          LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
          JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
          LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
          LEFT JOIN v_mora_prestamos m ON m.id_prestamo = p.id_prestamo
         WHERE $where";
$saldo = "CASE WHEN p.estado IN ('ANULADO', 'CANCELADO') THEN 0 ELSE
            p.capital - COALESCE((SELECT SUM(pi.importe) FROM pagos_imputacion pi
                                    JOIN pagos pg ON pg.id_pago = pi.id_pago AND pg.estado = 'APLICADO'
                                   WHERE pg.id_prestamo = p.id_prestamo AND pi.concepto = 'CAPITAL'), 0) END";
$columnas = "p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, p.capital, p.plazo, p.valor_cuota, p.tna, p.estado,
             p.neto_desembolso, c.id_cliente, c.denominacion, c.cuit, ta.nombre AS tipo_asociado, v.nombre AS vendedor,
             m.dias_atraso, m.tramo, m.cuotas_vencidas, m.deuda_vencida, $saldo AS saldo_capital";

$hayFiltros = $fQ !== '' || $fEstado !== '' || $fMora !== '' || $fTipo || $fVend || $fDesde !== '' || $fHasta !== '';
// Orden por encabezado (en la base): la grilla y el Excel salen igual.
$orden = orden_grilla(['nro' => 'p.nro_prestamo', 'fecha' => 'p.fecha_liquidacion', 'cliente' => 'c.denominacion', 'tipo' => 'ta.nombre',
                       'capital' => 'p.capital', 'plazo' => 'p.plazo', 'cuota' => 'p.valor_cuota', 'saldo' => 'saldo_capital',
                       'mora' => 'm.dias_atraso', 'estado' => 'p.estado'], 'fecha', 'desc', 'p.id_prestamo');
$filas = [];
$tot = ['n' => 0, 'capital' => 0, 'saldo' => 0, 'mora_n' => 0, 'mora_deuda' => 0];
try {
    $tipos  = q('SELECT id_tipo_asociado, nombre FROM tipos_asociado ORDER BY nombre');
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
    $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(p.capital), 0) AS capital, COALESCE(SUM($saldo), 0) AS saldo,
                      COUNT(m.id_prestamo) AS mora_n, COALESCE(SUM(m.deuda_vencida), 0) AS mora_deuda $desde", $params);

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $todas = q("SELECT $columnas $desde ORDER BY {$orden['sql']}", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Préstamos')->titulo('Préstamos', ($hayFiltros ? 'Filtrado · ' : '') . num($tot['n']) . ' préstamos · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['N°', 'texto', 11], ['Liquidación', 'fecha', 12], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15],
                 ['Tipo de asociado', 'texto', 20], ['Vendedor', 'texto', 20], ['Capital', 'moneda', 15], ['Cuotas', 'entero', 8],
                 ['Cuota', 'moneda', 14], ['TNA', 'pct', 9], ['Saldo de capital', 'moneda', 15],
                 ['Días de atraso', 'entero', 10], ['Deuda vencida', 'moneda', 15], ['Estado', 'texto', 12]],
                array_map(function ($p) {
                    return [$p['nro_prestamo'], $p['fecha_liquidacion'], $p['denominacion'], cuit_fmt($p['cuit']), $p['tipo_asociado'],
                            $p['vendedor'], $p['capital'], $p['plazo'], $p['valor_cuota'], $p['tna'], $p['saldo_capital'],
                            $p['dias_atraso'], $p['deuda_vencida'], ESTADOS_PRESTAMO[$p['estado']] ?? $p['estado']];
                }, $todas),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:A})', 'valor' => 'Total: ' . count($todas)],
                 6 => ['suma' => true], 10 => ['suma' => true], 12 => ['suma' => true]]
            );
        $libro->enviar('prestamos_' . date('Ymd_Hi') . '.xlsx');
        exit;
    }

    $paginas = max(1, (int) ceil($tot['n'] / $porPag));
    $pagina  = min($pagina, $paginas);
    $filas = q("SELECT $columnas $desde ORDER BY {$orden['sql']} LIMIT ? OFFSET ?",
               array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $paginas = 1;
    $tipos = $vendedores = [];
}

layout_inicio('Préstamos', 'prestamos');
titulo('Préstamos', 'Liquidados, con su saldo y su mora a hoy',
       (puede('solicitudes.php') ? boton('Nueva solicitud', 'primary', 'solicitud.php', 'agregar') : ''));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Préstamos</span><strong><?= num($tot['n']) ?></strong><span class="bcra-tarjeta-sub"><?= $hayFiltros ? 'con el filtro' : 'todos' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Capital prestado</span><strong><?= money($tot['capital']) ?></strong><span class="bcra-tarjeta-sub"></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo de capital</span><strong><?= money($tot['saldo']) ?></strong><span class="bcra-tarjeta-sub">lo que falta cobrar de capital</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">En mora</span><strong><?= num($tot['mora_n']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= (int) $tot['mora_n'] ? 'deuda vencida ' . money($tot['mora_deuda']) : 'ninguno' ?></span></div>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="N°, cliente, CUIT o DNI…" maxlength="60"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_PRESTAMO, $fEstado, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Mora</span>
      <select name="mora" class="filtro <?= $fMora !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(FILTRO_MORA, $fMora, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Tipo de asociado</span>
      <select name="tipo" class="filtro <?= $fTipo ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($tipos, 'nombre', 'id_tipo_asociado'), $fTipo ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro <?= $fDesde !== '' ? 'activo' : '' ?>"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro <?= $fHasta !== '' ? 'activo' : '' ?>"></label>
    <?= filtro_botones('prestamos.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'préstamos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <?= th_orden('N°', 'nro', $orden) ?><?= th_orden('Liquidación', 'fecha', $orden) ?><?= th_orden('Cliente', 'cliente', $orden) ?><?= th_orden('Tipo de asociado', 'tipo', $orden) ?>
          <?= th_orden('Capital', 'capital', $orden, 'th-right') ?><?= th_orden('Cuotas', 'plazo', $orden, 'th-right') ?><?= th_orden('Cuota', 'cuota', $orden, 'th-right') ?>
          <?= th_orden('Saldo de capital', 'saldo', $orden, 'th-right') ?><?= th_orden('Mora', 'mora', $orden) ?><?= th_orden('Estado', 'estado', $orden) ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="11" class="no-data"><?= $hayFiltros ? 'Ningún préstamo coincide con el filtro.' : 'Todavía no hay préstamos. Cargá una solicitud; cuando se aprueba, se liquida.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $p): ?>
        <tr>
          <td><div class="acciones">
            <?= btn_accion('ver', 'prestamo.php?id=' . $p['id_prestamo'], 'Abrir el préstamo') ?>
            <?= btn_accion('pdf', 'prestamo.php?id=' . $p['id_prestamo'] . '&export=pdf', 'Plan de pagos en PDF', true) ?>
          </div></td>
          <td class="mono td-fuerte"><a href="prestamo.php?id=<?= (int) $p['id_prestamo'] ?>"><?= h($p['nro_prestamo']) ?></a></td>
          <td><?= h(fecha($p['fecha_liquidacion'])) ?></td>
          <td><a href="cliente.php?id=<?= (int) $p['id_cliente'] ?>"><?= h($p['denominacion']) ?></a>
            <span class="segunda-linea mono"><?= h(cuit_fmt($p['cuit'])) ?><?= $p['vendedor'] ? ' · ' . h($p['vendedor']) : '' ?></span></td>
          <td><?= h($p['tipo_asociado']) ?></td>
          <td class="td-right"><?= money($p['capital']) ?></td>
          <td class="td-right"><?= (int) $p['plazo'] ?></td>
          <td class="td-right"><?= money($p['valor_cuota']) ?></td>
          <td class="td-right"><?= money($p['saldo_capital']) ?></td>
          <td><?= $p['dias_atraso'] !== null
                ? estado((int) $p['dias_atraso'] . ' días · ' . money($p['deuda_vencida']), (int) $p['dias_atraso'] > 30 ? 'danger' : 'warn')
                : ($p['estado'] === 'VIGENTE' ? '<span class="td-muted">al día</span>' : '<span class="td-muted">—</span>') ?></td>
          <td><?= estado_prestamo($p['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'préstamos') ?>
</div>

<?php layout_fin(); ?>
