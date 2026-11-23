<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/facturacion.php';

// ---------------------------------------------------------------------
// Cobranzas › Facturación electrónica: las facturas (y notas de crédito)
// de los recibos y su CAE de ARCA. Reglas en inc\facturacion.php.
//   GET  estado, desde, hasta, q       la grilla (al entrar se generan las
//                                      facturas de los recibos nuevos)
//   POST accion=autorizar              pide CAE para lo pendiente
//   POST accion=reintentar  sel[]      rechazados de vuelta a la cola
//   POST accion=guardar_config         desde qué fecha se factura, actividad
//   POST accion=probar                 FEDummy y último autorizado por talonario
// ---------------------------------------------------------------------

$errores = [];
$avisos = [];
$prueba = null;
$instalado = fact_instalado();
$fEstado = array_key_exists(get_str('estado'), FACT_ESTADOS) || get_str('estado') === 'todos' ? get_str('estado') : '';
$fDesde = get_str('desde') !== '' ? get_str('desde') : date('Y-m-01');
$fHasta = get_str('hasta') !== '' ? get_str('hasta') : date('Y-m-t');
$q = trim(get_str('q'));
$url = 'facturacion.php?' . http_build_query(array_filter(['estado' => $fEstado, 'desde' => $fDesde, 'hasta' => $fHasta, 'q' => $q]));

if ($instalado && es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        if ($accion === 'guardar_config') {
            $d = post_str('facturacion_desde');
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $errores[] = 'La fecha desde la que se factura no es válida.';
            } else {
                fact_desde_guardar($d !== '' ? $d : null);
                $act = preg_replace('/\D/', '', post_str('arca_actividad'));
                q('UPDATE empresa SET arca_actividad = ?', [$act !== '' ? (int) $act : null]);
                flash('ok', $d !== '' ? 'Se facturan los recibos desde el ' . fecha($d) . '.' : 'Sin fecha: no se generan facturas nuevas.');
                redirigir($url);
            }
        } elseif ($accion === 'autorizar') {
            @set_time_limit(180);
            $s = fact_sincronizar();
            $r = fact_autorizar(90);
            $txt = 'Autorizados: ' . $r['autorizados'] . ($r['rechazados'] ? ' · rechazados: ' . $r['rechazados'] : '')
                 . ($r['mensajes'] ? '. ' . implode(' ', $r['mensajes']) : '.');
            flash($r['rechazados'] || $r['mensajes'] ? 'warning' : 'ok', $txt);
            redirigir($url);
        } elseif ($accion === 'reintentar') {
            $n = fact_reintentar(array_map('intval', (array) ($_POST['sel'] ?? [])));
            flash('ok', $n . ' comprobante' . ($n === 1 ? '' : 's') . ' de vuelta a la cola.');
            redirigir($url);
        } elseif ($accion === 'probar') {
            $prueba = [];
            $ambs = array_unique(array_map(function ($t) { return $t['arca_ambiente'] === 'prod' ? 'prod' : 'homo'; },
                                           array_merge(...array_values(fact_talonarios() ?: [[]]))));
            foreach ($ambs as $amb) {
                try {
                    $d = wsfe_dummy($amb);
                    $prueba[] = [$amb, 'Servidores de ARCA: aplicación ' . ($d['AppServer'] ?? '?') . ', base ' . ($d['DbServer'] ?? '?') . ', autenticación ' . ($d['AuthServer'] ?? '?'), true];
                } catch (Throwable $e) {
                    $prueba[] = [$amb, $e->getMessage(), false];
                }
            }
            foreach (fact_talonarios() as $lista) {
                foreach ($lista as $t) {
                    $amb = $t['arca_ambiente'] === 'prod' ? 'prod' : 'homo';
                    try {
                        $u = wsfe_ultimo($amb, (int) $t['punto_venta'], (int) $t['codigo_arca']);
                        $prueba[] = [$amb, $t['nombre'] . ': ARCA va por el ' . $u . ' (el sistema, ' . (int) $t['ultimo_emitido'] . ').', true];
                    } catch (Throwable $e) {
                        $prueba[] = [$amb, $t['nombre'] . ': ' . $e->getMessage(), false];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$sync = null;
$tals = [];
$resumen = [];
$filas = [];
$desdeFact = null;
$actividad = null;
if ($instalado) {
    try {
        $desdeFact = fact_desde();
        $actividad = q1('SELECT arca_actividad FROM empresa LIMIT 1')['arca_actividad'] ?? null;
        if (!es_post()) {
            $sync = fact_sincronizar();
            $avisos = $sync['avisos'];
        }
        $tals = fact_talonarios();
        foreach (q("SELECT arca_estado, COUNT(*) AS n, SUM(IF(cbte_tipo IN (3, 8), -importe_total, importe_total)) AS total
                      FROM comprobantes_venta GROUP BY arca_estado") as $r) {
            $resumen[$r['arca_estado']] = $r;
        }
        if ($fEstado === '') {         // lo que hay que mirar, de cualquier fecha, y lo del período
            $w = ["(v.arca_estado IN ('PENDIENTE','ENVIANDO','RECHAZADO') OR v.fecha BETWEEN ? AND ?)"];
            $p = [$fDesde, $fHasta];
        } else {
            $w = ['v.fecha BETWEEN ? AND ?'];
            $p = [$fDesde, $fHasta];
            if ($fEstado !== 'todos') {
                $w[] = 'v.arca_estado = ?';
                $p[] = $fEstado;
            }
        }
        if ($q !== '') {
            $dig = preg_replace('/\D/', '', $q);
            $w[] = '(c.denominacion LIKE ? OR r.nro_recibo LIKE ?' . ($dig !== '' ? ' OR c.cuit LIKE ? OR v.cae LIKE ?' : '') . ')';
            $p[] = '%' . $q . '%';
            $p[] = '%' . $q . '%';
            if ($dig !== '') {
                $p[] = '%' . $dig . '%';
                $p[] = '%' . $dig . '%';
            }
        }
        $filas = q('SELECT v.*, c.denominacion, c.cuit, r.nro_recibo, r.estado AS recibo_estado, t.nombre AS talonario
                      FROM comprobantes_venta v
                      JOIN clientes c ON c.id_cliente = v.id_cliente
                      JOIN talonarios t ON t.id_talonario = v.id_talonario
                      LEFT JOIN recibos r ON r.id_recibo = v.id_recibo
                     WHERE ' . implode(' AND ', $w) . '
                     ORDER BY FIELD(v.arca_estado, \'RECHAZADO\', \'ENVIANDO\', \'PENDIENTE\', \'AUTORIZADO\', \'ANULADO\'), v.fecha DESC, v.id_comprobante DESC
                     LIMIT 3000', $p);
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

layout_inicio('Facturación electrónica', 'cobranzas');
titulo('Facturación electrónica', 'Las facturas de los recibos y su CAE de ARCA', '',
       '<a href="cobranzas.php">Cobranzas</a> / Facturación electrónica');
mensajes($errores);

if (!$instalado): ?>
<div class="alert alert-warning"><div>Falta correr sql\financiera_facturacion.sql.</div></div>
<?php layout_fin(); return; endif;

$n = function (string $e) use ($resumen) { return (int) ($resumen[$e]['n'] ?? 0); };
$hayTal = (bool) array_filter($tals);
?>

<?php if ($prueba !== null): ?>
<div class="card">
  <?= card_head('Prueba de conexión con ARCA') ?>
  <div class="bloque">
  <?php if (!$prueba): ?><p class="td-muted">No hay talonarios electrónicos habilitados para probar.</p><?php endif; ?>
  <?php foreach ($prueba as [$amb, $txt, $ok]): ?>
    <div class="alert alert-<?= $ok ? 'ok' : 'danger' ?>"><div><strong><?= $amb === 'prod' ? 'Producción' : 'Homologación' ?>:</strong> <?= h($txt) ?></div></div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($avisos): ?>
<div class="alert alert-warning"><div><strong><?= num(count($avisos)) ?> recibo<?= count($avisos) === 1 ? '' : 's' ?> sin facturar:</strong>
  <?= h(implode(' · ', array_slice($avisos, 0, 8))) ?><?= count($avisos) > 8 ? ' y ' . num(count($avisos) - 8) . ' más.' : '' ?></div></div>
<?php endif; ?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Esperan CAE</span><strong><?= num($n('PENDIENTE') + $n('ENVIANDO')) ?></strong>
    <span class="bcra-tarjeta-sub"><?= money((float) ($resumen['PENDIENTE']['total'] ?? 0) + (float) ($resumen['ENVIANDO']['total'] ?? 0)) ?><?= $n('ENVIANDO') ? ' · ' . $n('ENVIANDO') . ' enviando' : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Rechazados</span><strong><?= num($n('RECHAZADO')) ?></strong>
    <span class="bcra-tarjeta-sub">no salen hasta «Reintentar»</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Autorizados</span><strong><?= num($n('AUTORIZADO')) ?></strong>
    <span class="bcra-tarjeta-sub"><?= money((float) ($resumen['AUTORIZADO']['total'] ?? 0)) ?> (neto de notas de crédito)</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Se factura desde</span><strong><?= $desdeFact ? h(fecha($desdeFact)) : '—' ?></strong>
    <span class="bcra-tarjeta-sub"><?= $desdeFact ? 'los recibos de esa fecha en adelante' : 'sin fecha no se genera ninguna factura' ?></span></div>
</div>

<div class="card">
  <?= card_head('Configuración', $hayTal ? '' : 'falta un talonario electrónico de factura (Tablas › Talonarios)',
                accion_post($url, ['accion' => 'probar'], 'info', 'Probar la conexión con ARCA')) ?>
  <form method="post" action="<?= h($url) ?>" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar_config">
    <div class="campos">
      <div class="campo" style="--span:3"><label for="facturacion_desde">Facturar los recibos desde</label>
        <input type="date" id="facturacion_desde" name="facturacion_desde" value="<?= h((string) $desdeFact) ?>"></div>
      <div class="campo" style="--span:3"><label for="arca_actividad">Actividad en ARCA</label>
        <input id="arca_actividad" name="arca_actividad" value="<?= h((string) $actividad) ?>" inputmode="numeric" placeholder="649220, si hay que informarla"></div>
      <div class="campo campo-boton" style="--span:2"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
    </div>
    <span class="campo-ayuda">Talonarios:
      <?php $l = [];
            foreach ([1, 6, 3, 8] as $tp) {
                foreach ($tals[$tp] ?? [] as $t) {
                    $l[] = FACT_TIPOS[$tp] . ' → ' . $t['nombre'] . ' (punto de venta ' . (int) $t['punto_venta'] . ', ' . ($t['arca_ambiente'] === 'prod' ? 'producción' : 'homologación') . ')';
                }
                if (empty($tals[$tp])) {
                    $l[] = FACT_TIPOS[$tp] . ': sin talonario';
                }
            }
            echo h(implode(' · ', $l)); ?>.
      Se factura lo que el recibo aplicó a los préstamos: capital no gravado, interés y punitorios gravados con su IVA. La cuota social no se factura.</span>
  </form>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= filtro_select('estado', 'Estado', ['' => 'Para revisar y del período'] + FACT_ESTADOS + ['todos' => 'Todos'], $fEstado, '', $fEstado !== '') ?>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span><input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span><input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($q) ?>" class="filter-input <?= $q !== '' ? 'activo' : '' ?>" placeholder="Cliente, CUIT, recibo o CAE…"></label>
    <?= filtro_botones('facturacion.php', $q !== '' || $fEstado !== '' || $fDesde !== date('Y-m-01') || $fHasta !== date('Y-m-t')) ?>
  </form>
</div>

<form method="post" action="<?= h($url) ?>">
<?= csrf_campo() ?>
<div class="card">
  <?= card_head('Comprobantes', num(count($filas)) . ' en la lista',
                ($n('PENDIENTE') + $n('ENVIANDO') ? boton('Autorizar ' . num($n('PENDIENTE') + $n('ENVIANDO')) . ' en ARCA', 'primary', null, 'ok', true, false,
                                                         ['name' => 'accion', 'value' => 'autorizar', 'formnovalidate' => true]) : '')
              . ($n('RECHAZADO') ? ' ' . boton('Reintentar los tildados', 'ghost', null, 'flecha', true, false, ['name' => 'accion', 'value' => 'reintentar']) : '')) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="comprobante|comprobantes">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Recibo</th>
        <th class="th-right">No gravado</th><th class="th-right">Neto</th><th class="th-right">IVA</th><th class="th-right">Total</th><th>Estado</th><th>CAE</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="11" class="no-data">No hay comprobantes<?= $desdeFact ? '' : ': falta la fecha desde la que se factura' ?>.</td></tr><?php endif; ?>
      <?php foreach ($filas as $f): $nc = in_array((int) $f['cbte_tipo'], [3, 8], true); ?>
        <tr class="<?= $f['arca_estado'] === 'ANULADO' ? 'fila-baja' : '' ?>">
          <td><div class="acciones">
            <?php if ($f['arca_estado'] === 'RECHAZADO'): ?><input type="checkbox" name="sel[]" value="<?= (int) $f['id_comprobante'] ?>" aria-label="Reintentar"><?php endif; ?>
            <?= $f['id_recibo'] ? btn_accion('ver', 'recibo.php?r=' . (int) $f['id_recibo'], 'Ver el recibo') : '' ?>
          </div></td>
          <td class="mono td-fuerte"><?= h(fact_nro($f)) ?><span class="segunda-linea"><?= h(FACT_TIPOS[(int) $f['cbte_tipo']] ?? '') ?><?= $f['arca_ambiente'] === 'homo' ? ' · homologación' : '' ?></span></td>
          <td data-orden="<?= h($f['fecha']) ?>"><?= h(fecha($f['fecha'])) ?></td>
          <td class="td-wrap"><?= h($f['denominacion']) ?><span class="segunda-linea"><?= h(cuit_fmt((string) $f['cuit'])) ?></span></td>
          <td class="mono"><?= h((string) $f['nro_recibo']) ?><?= $f['recibo_estado'] === 'ANULADO' ? '<span class="segunda-linea">anulado</span>' : '' ?></td>
          <td class="td-right"><?= money(($nc ? -1 : 1) * (float) $f['importe_no_gravado']) ?></td>
          <td class="td-right"><?= money(($nc ? -1 : 1) * (float) $f['importe_neto']) ?></td>
          <td class="td-right"><?= money(($nc ? -1 : 1) * (float) $f['importe_iva']) ?></td>
          <td class="td-right td-fuerte"><?= money(($nc ? -1 : 1) * (float) $f['importe_total']) ?></td>
          <td class="td-wrap"><?= estado(FACT_ESTADOS[$f['arca_estado']], FACT_ESTADOS_TIPO[$f['arca_estado']]) ?>
            <?= $f['arca_obs'] ? '<span class="segunda-linea">' . h($f['arca_obs']) . '</span>' : '' ?></td>
          <td class="mono"><?= $f['cae'] ? h($f['cae']) . '<span class="segunda-linea">vence ' . h(fecha((string) $f['cae_vto'])) . '</span>' : '<span class="td-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
</form>
<?php layout_fin(); ?>
