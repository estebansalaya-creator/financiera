<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/inversiones.php';

// ---------------------------------------------------------------------
// Inversiones: los aportes de capital, con lo devengado a hoy, lo que
// vence pronto y las liquidaciones pendientes de pago.
// ---------------------------------------------------------------------

const FILTRO_VTO = ['VENCIDOS' => 'Vencidos sin liquidar', '7' => 'Vencen en 7 días', '30' => 'Vencen en 30 días', '90' => 'Vencen en 90 días'];

$errores = [];
$fQ      = trim(get_str('q'));
$fEstado = isset(ESTADOS_INVERSION[get_str('estado')]) ? get_str('estado') : (get_str('estado') === 'todos' ? '' : 'VIGENTE');
$fMoneda = isset(MONEDAS[get_str('moneda')]) ? get_str('moneda') : '';
$fMod    = isset(MODALIDADES[get_str('modalidad')]) ? get_str('modalidad') : '';
$fVto    = isset(FILTRO_VTO[get_str('vto')]) ? get_str('vto') : '';

$where = '1 = 1';
$params = [];
if ($fEstado !== '') {
    $where .= ' AND i.estado = ?';
    $params[] = $fEstado;
}
if ($fMoneda !== '') {
    $where .= ' AND i.moneda = ?';
    $params[] = $fMoneda;
}
if ($fMod !== '') {
    $where .= ' AND i.modalidad = ?';
    $params[] = $fMod;
}
if ($fVto === 'VENCIDOS') {
    $where .= " AND i.estado = 'VIGENTE' AND i.fecha_vencimiento <= CURDATE()";
} elseif ($fVto !== '') {
    $where .= " AND i.estado = 'VIGENTE' AND i.fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY";
    $params[] = (int) $fVto;
}
if ($fQ !== '') {
    $like = '%' . addcslashes($fQ, '%_\\') . '%';
    $where .= ' AND (i.nro_inversion LIKE ? OR v.denominacion LIKE ? OR v.cuit LIKE ?)';
    array_push($params, $like, $like, '%' . preg_replace('/\D/', '', $fQ) . '%');
}
$desde = "FROM inversiones i JOIN inversores v ON v.id_inversor = i.id_inversor
          LEFT JOIN v_inversiones_devengado d ON d.id_inversion = i.id_inversion
         WHERE $where";

$filas = $tarjetas = $aPagar = [];
try {
    $filas = q("SELECT i.*, v.denominacion, v.cuit, d.interes_devengado, d.interes_al_vencimiento,
                       DATEDIFF(i.fecha_vencimiento, CURDATE()) AS dias_al_vto
                  $desde ORDER BY i.estado = 'VIGENTE' DESC, i.fecha_vencimiento, i.id_inversion", $params);
    $tarjetas = q("SELECT i.moneda, COUNT(*) AS n, SUM(i.capital) AS capital, SUM(d.interes_devengado) AS devengado,
                          SUM(IF(i.fecha_vencimiento <= CURDATE() + INTERVAL 30 DAY, i.capital, 0)) AS vence30
                     FROM inversiones i LEFT JOIN v_inversiones_devengado d ON d.id_inversion = i.id_inversion
                    WHERE i.estado = 'VIGENTE' GROUP BY i.moneda ORDER BY i.moneda");
    $aPagar = q("SELECT l.*, i.nro_inversion, i.id_inversion, v.denominacion
                   FROM inversiones_liquidaciones l
                   JOIN inversiones i ON i.id_inversion = l.id_inversion
                   JOIN inversores v ON v.id_inversor = i.id_inversor
                  WHERE l.estado = 'A_PAGAR' ORDER BY l.fecha");

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $libro = new XlsxLibro();
        $libro->hoja('Aportes')->titulo('Aportes de inversores', count($filas) . ' aportes · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['N°', 'texto', 11], ['Inversor', 'texto', 30], ['CUIT/CUIL', 'texto', 15], ['Modalidad', 'texto', 14], ['Moneda', 'centro', 8],
                 ['Inicio', 'fecha', 12], ['Vencimiento', 'fecha', 12], ['Capital', 'moneda', 15], ['TNA', 'pct', 9], ['% participación', 'pct', 12],
                 ['Devengado a hoy', 'moneda', 15], ['Interés al vencimiento', 'moneda', 16], ['Estado', 'texto', 11]],
                array_map(function ($i) {
                    return [$i['nro_inversion'], $i['denominacion'], cuit_fmt($i['cuit']), MODALIDADES[$i['modalidad']], $i['moneda'],
                            $i['fecha_inicio'], $i['fecha_vencimiento'], $i['capital'], $i['tna'], $i['pct_participacion'],
                            $i['interes_devengado'], $i['interes_al_vencimiento'], ESTADOS_INVERSION[$i['estado']]];
                }, $filas),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:A})', 'valor' => 'Total: ' . count($filas)]]
            );
        $libro->enviar('inversiones_' . date('Ymd_Hi') . '.xlsx');
        exit;
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas: corré sql\financiera_inversores.sql.' : error_amigable($e);
}
$usd = null;
try {
    $usd = q1("SELECT fecha, cotizacion FROM cotizaciones WHERE moneda = 'USD' ORDER BY fecha DESC LIMIT 1");
} catch (Throwable $e) {
    // sin la tabla de cotizaciones no se muestra la equivalencia
}
$hayFiltros = $fQ !== '' || $fEstado !== 'VIGENTE' || $fMoneda !== '' || $fMod !== '' || $fVto !== '';

layout_inicio('Inversiones', 'inversiones');
titulo('Inversiones', 'Aportes de capital de los inversores y liquidación de intereses',
       boton('Exportar a Excel', 'excel', url_con(['export' => 'xlsx']), 'planilla')
     . boton('Inversores', 'secondary', 'inversores.php', 'grupo')
     . boton('Nuevo aporte', 'primary', 'inversion.php?nueva=1', 'agregar'));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <?php foreach (['ARS', 'USD'] as $mon):
      $t = array_values(array_filter($tarjetas, function ($x) use ($mon) { return $x['moneda'] === $mon; }))[0] ?? null; ?>
    <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Capital vigente en <?= h(strtolower(MONEDAS[$mon])) ?></span>
      <strong><?= dinero($t['capital'] ?? 0, $mon) ?></strong>
      <span class="bcra-tarjeta-sub"><?= $t ? (int) $t['n'] . ' aportes · devengado ' . dinero($t['devengado'], $mon) : 'sin aportes' ?><?=
        $mon === 'USD' && $t && $usd ? '<br>≈ ' . money((float) $t['capital'] * (float) $usd['cotizacion']) . ' (BCRA ' . h(fecha($usd['fecha'])) . ': $ ' . h(number_format((float) $usd['cotizacion'], 2, ',', '.')) . ')' : '' ?></span></div>
  <?php endforeach; ?>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Vencen en 30 días</span>
    <strong><?= h(implode(' · ', array_filter(array_map(function ($t) { return (float) $t['vence30'] ? dinero($t['vence30'], $t['moneda']) : ''; }, $tarjetas))) ?: '—') ?></strong>
    <span class="bcra-tarjeta-sub"><a href="inversiones.php?vto=30">ver cuáles</a></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Liquidaciones a pagar</span><strong><?= count($aPagar) ?></strong>
    <span class="bcra-tarjeta-sub"><?= $aPagar ? h(implode(' · ', array_filter(array_map(function ($m) use ($aPagar) {
        $s = array_sum(array_map(function ($l) { return (float) $l['a_pagar']; }, array_filter($aPagar, function ($l) use ($m) { return $l['moneda'] === $m; })));
        return $s ? dinero($s, $m) : '';
    }, ['ARS', 'USD'])))) : 'ninguna' ?></span></div>
</div>

<?php if ($aPagar): ?>
<div class="card">
  <?= card_head('Liquidaciones a pagar', count($aPagar) . ' pendientes') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Fecha</th><th data-no-sort>Aporte</th><th data-no-sort>Inversor</th>
        <th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Interés</th><th class="th-right" data-no-sort>A pagar</th></tr></thead>
      <tbody>
      <?php foreach ($aPagar as $l): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('ver', 'inversion.php?id=' . $l['id_inversion'] . '#liquidaciones', 'Ver y registrar el pago') ?>
            <?= btn_accion('pdf', 'inversion.php?id=' . $l['id_inversion'] . '&liq=' . $l['id_liquidacion'] . '&export=pdf', 'Liquidación en PDF', true) ?></div></td>
          <td><?= h(fecha($l['fecha'])) ?></td>
          <td class="mono"><?= h($l['nro_inversion']) ?></td>
          <td><?= h($l['denominacion']) ?></td>
          <td class="td-right"><?= dinero($l['capital'], $l['moneda']) ?></td>
          <td class="td-right"><?= dinero($l['interes'], $l['moneda']) ?></td>
          <td class="td-right td-fuerte"><?= dinero($l['a_pagar'], $l['moneda']) ?><?=
            $l['moneda'] !== 'ARS' ? '<span class="segunda-linea">' . h(equiv_pesos($l['a_pagar'], $l['moneda'], $l['cotizacion'])) . ' al ' . h(fecha($l['fecha'])) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="N° de aporte, inversor o CUIT…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== 'VIGENTE' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <option value="todos" <?= $fEstado === '' ? 'selected' : '' ?>>Todos</option><?= opciones(ESTADOS_INVERSION, $fEstado) ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Moneda</span>
      <select name="moneda" class="filtro <?= $fMoneda !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(MONEDAS, $fMoneda, 'Todas') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Modalidad</span>
      <select name="modalidad" class="filtro <?= $fMod !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(MODALIDADES, $fMod, 'Todas') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Vencimiento</span>
      <select name="vto" class="filtro <?= $fVto !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(FILTRO_VTO, $fVto, 'Todos') ?></select></label>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Filtrar</button>
    <?php if ($hayFiltros): ?><a class="btn btn-secondary btn-sm" href="inversiones.php"><?= icono('quitar_filtro') ?>Quitar filtro</a><?php endif; ?>
    <span class="filter-count" style="margin-left:auto"><?= count($filas) ?> aportes</span>
  </form>
</div>

<div class="card">
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>N°</th><th>Inversor</th><th>Modalidad</th>
        <th>Inicio</th><th>Vencimiento</th><th class="th-right">Capital</th><th>Condición</th>
        <th class="th-right">Devengado a hoy</th><th class="th-right">Al vencimiento</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="11" class="no-data"><?= $hayFiltros ? 'Ningún aporte coincide con el filtro.' : 'Todavía no hay aportes.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $i):
          $venc = $i['estado'] === 'VIGENTE' && (int) $i['dias_al_vto'] <= 0; ?>
        <tr<?= $venc ? ' class="fila-vencida"' : ($i['estado'] === 'ANULADA' ? ' class="fila-baja"' : '') ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'inversion.php?id=' . $i['id_inversion'], 'Ver el aporte') ?>
            <?= btn_accion('pdf', 'inversion.php?id=' . $i['id_inversion'] . '&export=pdf', 'Constancia en PDF', true) ?></div></td>
          <td class="mono td-fuerte"><a href="inversion.php?id=<?= (int) $i['id_inversion'] ?>"><?= h($i['nro_inversion']) ?></a></td>
          <td><a href="inversor.php?id=<?= (int) $i['id_inversor'] ?>"><?= h($i['denominacion']) ?></a><span class="segunda-linea mono"><?= h(cuit_fmt($i['cuit'])) ?></span></td>
          <td><?= h(MODALIDADES[$i['modalidad']]) ?> · <?= h($i['moneda']) ?></td>
          <td><?= h(fecha($i['fecha_inicio'])) ?></td>
          <td><?= h(fecha($i['fecha_vencimiento'])) ?><?php if ($i['estado'] === 'VIGENTE'): ?><span class="segunda-linea"><?= $venc ? 'vencido: liquidar' : 'faltan ' . (int) $i['dias_al_vto'] . ' días' ?></span><?php endif; ?></td>
          <td class="td-right"><?= dinero($i['capital'], $i['moneda']) ?><?=
            $i['moneda'] === 'USD' && $usd ? '<span class="segunda-linea">' . h(equiv_pesos($i['capital'], 'USD', $usd['cotizacion'])) . '</span>' : '' ?></td>
          <td><?= h(condicion_inversion($i)) ?></td>
          <td class="td-right"><?= $i['estado'] === 'VIGENTE' ? dinero($i['interes_devengado'], $i['moneda'])
              . ($i['moneda'] === 'USD' && $usd ? '<span class="segunda-linea">' . h(equiv_pesos($i['interes_devengado'], 'USD', $usd['cotizacion'])) . '</span>' : '') : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= $i['interes_al_vencimiento'] !== null ? dinero($i['interes_al_vencimiento'], $i['moneda']) : '<span class="td-muted">' . ($i['estado'] === 'VIGENTE' ? 'según lo cobrado' : '—') . '</span>' ?></td>
          <td><?= estado_inversion($i['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_fin(); ?>
