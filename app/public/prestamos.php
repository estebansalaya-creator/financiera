<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/prestamos.php';

// ---------------------------------------------------------------------
// Préstamos: listado
//   Paginado en el servidor, con filtros por estado, mora, producto,
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
$fProd   = (int) get_str('producto');
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
if ($fProd > 0) {
    $where .= ' AND p.id_producto = ?';
    $params[] = $fProd;
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
          JOIN productos pr ON pr.id_producto = p.id_producto
          LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
          LEFT JOIN v_mora_prestamos m ON m.id_prestamo = p.id_prestamo
         WHERE $where";
$saldo = "CASE WHEN p.estado IN ('ANULADO', 'CANCELADO') THEN 0 ELSE
            p.capital - COALESCE((SELECT SUM(pi.importe) FROM pagos_imputacion pi
                                    JOIN pagos pg ON pg.id_pago = pi.id_pago AND pg.estado = 'APLICADO'
                                   WHERE pg.id_prestamo = p.id_prestamo AND pi.concepto = 'CAPITAL'), 0) END";
$columnas = "p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, p.capital, p.plazo, p.valor_cuota, p.tna, p.estado,
             p.neto_desembolso, c.id_cliente, c.denominacion, c.cuit, pr.nombre AS producto, v.nombre AS vendedor,
             m.dias_atraso, m.tramo, m.cuotas_vencidas, m.deuda_vencida, $saldo AS saldo_capital";

$hayFiltros = $fQ !== '' || $fEstado !== '' || $fMora !== '' || $fProd || $fVend || $fDesde !== '' || $fHasta !== '';
$filas = [];
$tot = ['n' => 0, 'capital' => 0, 'saldo' => 0, 'mora_n' => 0, 'mora_deuda' => 0];
try {
    $productos  = q('SELECT id_producto, nombre FROM productos ORDER BY nombre');
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
    $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(p.capital), 0) AS capital, COALESCE(SUM($saldo), 0) AS saldo,
                      COUNT(m.id_prestamo) AS mora_n, COALESCE(SUM(m.deuda_vencida), 0) AS mora_deuda $desde", $params);

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $todas = q("SELECT $columnas $desde ORDER BY p.fecha_liquidacion DESC, p.id_prestamo DESC", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Préstamos')->titulo('Préstamos', ($hayFiltros ? 'Filtrado · ' : '') . num($tot['n']) . ' préstamos · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['N°', 'texto', 11], ['Liquidación', 'fecha', 12], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15],
                 ['Producto', 'texto', 20], ['Vendedor', 'texto', 20], ['Capital', 'moneda', 15], ['Cuotas', 'entero', 8],
                 ['Cuota', 'moneda', 14], ['TNA', 'pct', 9], ['Saldo de capital', 'moneda', 15],
                 ['Días de atraso', 'entero', 10], ['Deuda vencida', 'moneda', 15], ['Estado', 'texto', 12]],
                array_map(function ($p) {
                    return [$p['nro_prestamo'], $p['fecha_liquidacion'], $p['denominacion'], cuit_fmt($p['cuit']), $p['producto'],
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
    $filas = q("SELECT $columnas $desde ORDER BY p.fecha_liquidacion DESC, p.id_prestamo DESC LIMIT ? OFFSET ?",
               array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $paginas = 1;
    $productos = $vendedores = [];
}

layout_inicio('Préstamos', 'prestamos');
titulo('Préstamos', 'Liquidados, con su saldo y su mora a hoy',
       boton('Exportar a Excel', 'excel', url_con(['export' => 'xlsx', 'p' => null]), 'planilla')
     . boton('Nuevo préstamo', 'primary', 'prestamo_nuevo.php', 'agregar'));
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
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="N°, cliente, CUIT o DNI…" maxlength="60"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_PRESTAMO, $fEstado, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Mora</span>
      <select name="mora" class="filtro <?= $fMora !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(FILTRO_MORA, $fMora, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Producto</span>
      <select name="producto" class="filtro <?= $fProd ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($productos, 'nombre', 'id_producto'), $fProd ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filter-input <?= $fDesde !== '' ? 'activo' : '' ?>"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filter-input <?= $fHasta !== '' ? 'activo' : '' ?>"></label>
    <?php if ($porPag !== 50): ?><input type="hidden" name="per_page" value="<?= $porPag ?>"><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Filtrar</button>
    <?php if ($hayFiltros): ?>
      <a class="btn btn-secondary btn-sm" href="prestamos.php"><?= icono('quitar_filtro') ?>Quitar filtro</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <th data-no-sort>N°</th><th data-no-sort>Liquidación</th><th data-no-sort>Cliente</th><th data-no-sort>Producto</th>
          <th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Cuotas</th><th class="th-right" data-no-sort>Cuota</th>
          <th class="th-right" data-no-sort>Saldo de capital</th><th data-no-sort>Mora</th><th data-no-sort>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="11" class="no-data"><?= $hayFiltros ? 'Ningún préstamo coincide con el filtro.' : 'Todavía no hay préstamos. Liquidá el primero con «Nuevo préstamo».' ?></td></tr>
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
          <td><?= h($p['producto']) ?></td>
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
