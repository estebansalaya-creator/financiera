<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cuota_social.php';

// ---------------------------------------------------------------------
// Cobranzas › Cuota social: generar las cuotas de un período y verlas.
// Reglas en inc\cuota_social.php.
//   GET  periodo (AAAA-MM), estado, q    el período y su grilla
//   POST accion=generar                  genera el período (antes se ve
//                                        la simulación: cuántas e importe)
// Generar dos veces el mismo período no duplica: solo agrega lo que falte
// (asociados nuevos, un valor cargado después).
// ---------------------------------------------------------------------

$errores = [];
$val = function (string $k): string { return es_post() ? post_str($k) : get_str($k); };
$periodo = cs_periodo_leer($val('periodo')) ?? date('Y-m-01');
$fEstado = array_key_exists(get_str('estado'), CS_ESTADOS) ? get_str('estado') : '';
$q = trim(get_str('q'));
$url = 'cuota_social.php?periodo=' . substr($periodo, 0, 7);
$instalado = cs_instalado();

if ($instalado && es_post() && post_str('accion') === 'generar') {
    csrf_exigir();
    try {
        if ($periodo > date('Y-m-01', strtotime('first day of next month'))) {
            $errores[] = 'Se puede generar hasta el mes que viene.';
        } else {
            $r = cs_generar($periodo, false);
            flash($r['nuevas'] ? 'ok' : 'info', $r['nuevas']
                ? 'Cuota social de ' . cs_periodo_txt($periodo) . ': se generaron ' . num($r['nuevas']) . ' cuotas.'
                : 'Cuota social de ' . cs_periodo_txt($periodo) . ': ya estaba todo generado.');
            redirigir($url);
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$sim = null;
$resumen = [];
$filas = [];
$tope = 5000;
if ($instalado) {
    try {
        $sim = cs_generar($periodo, true);
        $resumen = q("SELECT concepto, estado, COUNT(*) AS n, SUM(importe) AS importe, SUM(pagado) AS pagado
                        FROM cuotas_sociales WHERE periodo = ? GROUP BY concepto, estado", [$periodo]);
        $w = ['s.periodo = ?'];
        $p = [$periodo];
        if ($fEstado !== '') {
            $w[] = 's.estado = ?';
            $p[] = $fEstado;
        }
        if ($q !== '') {
            $w[] = '(c.denominacion LIKE ? OR c.cuit LIKE ?)';
            $p[] = '%' . $q . '%';
            $p[] = '%' . preg_replace('/\D/', '', $q) . '%';
        }
        $filas = q('SELECT s.*, c.denominacion, c.cuit, t.nombre AS tipo
                      FROM cuotas_sociales s JOIN clientes c ON c.id_cliente = s.id_cliente
                      LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = s.id_tipo_asociado
                     WHERE ' . implode(' AND ', $w) . '
                     ORDER BY c.denominacion, FIELD(s.concepto, \'INICIAL\', \'MENSUAL\', \'ADICIONAL\', \'MANUAL\')
                     LIMIT ' . ($tope + 1), $p);
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

layout_inicio('Cuota social', 'cobranzas');
titulo('Cuota social', 'La cuota mensual de los asociados: desde su primer préstamo hasta la baja de cuota social',
       '', '<a href="cobranzas.php">Cobranzas</a> / Cuota social');
mensajes($errores);

if (!$instalado): ?>
<div class="alert alert-warning"><div>Falta correr sql\financiera_cuota_social.sql.</div></div>
<?php layout_fin(); return; endif;

$tot = ['n' => 0, 'importe' => 0.0, 'pagado' => 0.0];
$porConcepto = [];
foreach ($resumen as $r) {
    if ($r['estado'] === 'ANULADA') {
        continue;
    }
    $porConcepto[$r['concepto']]['n'] = ($porConcepto[$r['concepto']]['n'] ?? 0) + (int) $r['n'];
    $porConcepto[$r['concepto']]['importe'] = ($porConcepto[$r['concepto']]['importe'] ?? 0) + (float) $r['importe'];
    $porConcepto[$r['concepto']]['pagado'] = ($porConcepto[$r['concepto']]['pagado'] ?? 0) + (float) $r['pagado'];
    $tot['n'] += (int) $r['n'];
    $tot['importe'] += (float) $r['importe'];
    $tot['pagado'] += (float) $r['pagado'];
}
$faltan = 0;
$faltaImporte = 0.0;
foreach ($sim['conceptos'] ?? [] as $c) {
    $faltan += (int) $c['n'] - (int) $c['ya'];
}
?>
<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Período</span>
      <input type="month" name="periodo" value="<?= h(substr($periodo, 0, 7)) ?>" class="filtro" onchange="this.form.submit()"></label>
    <?= filtro_select('estado', 'Estado', CS_ESTADOS, $fEstado, 'Todos', $fEstado !== '') ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($q) ?>" class="filter-input <?= $q !== '' ? 'activo' : '' ?>" placeholder="Nombre o CUIT…"></label>
    <?= filtro_botones($url, $q !== '' || $fEstado !== '') ?>
  </form>
</div>

<div class="card">
  <?= card_head('Generación de ' . cs_periodo_txt($periodo),
                num((int) ($sim['asociados'] ?? 0)) . ' asociados que pagan en el período'
                . ((int) ($sim['sin_valor'] ?? 0) ? ' · ' . num((int) $sim['sin_valor']) . ' sin valor para su tipo (no generan)' : '')) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th>Concepto</th><th class="th-right">Corresponden</th><th class="th-right">Importe</th><th class="th-right">Ya generadas</th><th class="th-right">Faltan</th></tr></thead>
      <tbody>
      <?php if (!($sim['conceptos'] ?? [])): ?>
        <tr><td colspan="5" class="no-data">Nadie genera cuota social en este período (revisá los valores en Tipos de asociado › Cuota social).</td></tr>
      <?php endif; ?>
      <?php foreach ($sim['conceptos'] ?? [] as $c): ?>
        <tr><td class="td-fuerte"><?= h(CS_CONCEPTOS[$c['concepto']] ?? $c['concepto']) ?></td>
          <td class="td-right"><?= num((int) $c['n']) ?></td>
          <td class="td-right"><?= money($c['importe']) ?></td>
          <td class="td-right"><?= num((int) $c['ya']) ?></td>
          <td class="td-right td-fuerte"><?= num((int) $c['n'] - (int) $c['ya']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($faltan > 0): ?>
  <form method="post" action="<?= h($url) ?>" class="bloque" data-confirmar="Se generan <?= num($faltan) ?> cuotas de <?= h(cs_periodo_txt($periodo)) ?>." data-confirmar-titulo="Generar cuota social" data-confirmar-boton="Generar">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="generar"><input type="hidden" name="periodo" value="<?= h(substr($periodo, 0, 7)) ?>">
    <?= boton('Generar ' . num($faltan) . ' cuotas', 'primary', null, 'agregar', true) ?>
    <span class="campo-ayuda">Los clientes con préstamo que todavía no son asociados se dan de alta desde el mes de su primer préstamo.</span>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <?= card_head('Cuotas de ' . cs_periodo_txt($periodo), $tot['n']
      ? num($tot['n']) . ' cuotas · ' . money($tot['importe']) . ' · cobrado ' . money($tot['pagado']) . ' · falta ' . money($tot['importe'] - $tot['pagado'])
      : 'sin generar') ?>
  <?php if (count($filas) > $tope): ?>
    <div class="bloque"><div class="alert alert-info"><div>Se muestran las primeras <?= num($tope) ?>: usá el buscador o el estado para acotar.</div></div></div>
  <?php array_pop($filas); endif; ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="cuota|cuotas">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Asociado</th><th>CUIT</th><th>Tipo</th><th>Concepto</th>
        <th class="th-right">Importe</th><th class="th-right">Pagado</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="8" class="no-data">No hay cuotas.</td></tr><?php endif; ?>
      <?php $colEst = ['PENDIENTE' => 'warn', 'PARCIAL' => 'info', 'PAGADA' => 'ok', 'ANULADA' => 'neutro'];
            foreach ($filas as $f): ?>
        <tr class="<?= $f['estado'] === 'ANULADA' ? 'fila-baja' : '' ?>">
          <td><div class="acciones"><?= btn_accion('ver', 'cliente.php?id=' . (int) $f['id_cliente'] . '#cuota-social', 'Ver la cuenta del asociado') ?></div></td>
          <td class="td-fuerte"><?= h($f['denominacion']) ?></td>
          <td class="mono"><?= h(cuit_fmt((string) $f['cuit'])) ?></td>
          <td><?= h((string) $f['tipo']) ?></td>
          <td><?= h(CS_CONCEPTOS[$f['concepto']] ?? $f['concepto']) ?></td>
          <td class="td-right"><?= money($f['importe']) ?></td>
          <td class="td-right"><?= money($f['pagado']) ?></td>
          <td><?= estado(CS_ESTADOS[$f['estado']], $colEst[$f['estado']]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php layout_fin(); ?>
