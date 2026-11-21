<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/panel.php';

// ---------------------------------------------------------------------
// Panel general: los movimientos de un período, por defecto el de hoy.
//   panel.php?desde=AAAA-MM-DD&hasta=AAAA-MM-DD
//   panel.php?rango=hoy|ayer|semana|mes|mes_ant|anio   atajos
//   &tab=inversiones                                    la otra solapa
//   &export=pdf                                         la solapa en PDF
// Las consultas están en inc/panel.php (las mismas para la pantalla y el PDF).
// La solapa Inversiones la ve quien tiene Aportes o Inversores en el menú.
// ---------------------------------------------------------------------

$rangos = panel_rangos();
$rango  = get_str('rango');
if (isset($rangos[$rango])) {
    [, $fDesde, $fHasta] = $rangos[$rango];
} else {
    $fDesde = leer_fecha(get_str('desde')) ?? date('Y-m-d');
    $fHasta = leer_fecha(get_str('hasta')) ?? $fDesde;
    if ($fHasta < $fDesde) {
        [$fDesde, $fHasta] = [$fHasta, $fDesde];
    }
    $rango = '';
    foreach ($rangos as $k => [, $rd, $rh]) {          // si las fechas coinciden con un atajo, se marca
        if ($rd === $fDesde && $rh === $fHasta) {
            $rango = $k;
            break;
        }
    }
}
$verInv  = puede('inversiones.php') || puede('inversores.php');
$tab     = get_str('tab') === 'inversiones' && $verInv ? 'inversiones' : 'creditos';
$periodo = panel_periodo_txt($fDesde, $fHasta);
$verPrestamo = puede('prestamos.php');
$verInversion = puede('inversiones.php');

$error = null;
$c = $i = null;
try {
    if ($tab === 'creditos') {
        $c = panel_creditos($fDesde, $fHasta);
    } else {
        $i = panel_inversiones($fDesde, $fHasta);
    }
} catch (PDOException $e) {
    $error = (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta alguna tabla o vista (' . error_amigable($e) . '). Revisá que estén corridos los scripts de sql\\.'
        : error_amigable($e);
} catch (Throwable $e) {
    $error = error_amigable($e);
}

$conceptos = CONCEPTOS_PAGO;

// =====================================================================
// PDF
// =====================================================================
if (get_str('export') === 'pdf' && $error === null) {
    try {
        require_once __DIR__ . '/../inc/pdf.php';
        $rep = new PdfReporte('Panel general · ' . ($tab === 'creditos' ? 'Créditos y cobranzas' : 'Inversiones'),
                              'Movimientos ' . $periodo . ' · generado el ' . date('d/m/Y H:i'));
        if ($tab === 'creditos') {
            $cre = $c['sol']['CREADA'] ?? ['n' => 0, 'monto' => 0];
            $rep->indicadores([
                ['Solicitudes cargadas', num($cre['n']) . ' · ' . money($cre['monto'])],
                ['Préstamos liquidados', num($c['liq']['n']) . ' · ' . money($c['liq']['capital'])],
                ['Cobrado', money($c['cob']['total'])],
                ['Cartera vigente hoy', money($c['cartera']['capital'])],
            ]);
            $rep->seccion('Solicitudes', 'movimientos del período');
            $rep->tabla([['Movimiento', 2, 'L'], ['Cantidad', 1, 'R'], ['Monto pedido', 1.6, 'R']],
                array_map(function ($k, $rot) use ($c) {
                    $r = $c['sol'][$k] ?? ['n' => 0, 'monto' => 0];
                    return [$rot, num($r['n']), money($r['monto'])];
                }, array_keys(PANEL_ACCIONES_SOL), PANEL_ACCIONES_SOL), null, 0.55);
            $a = $c['solAhora'];
            $rep->nota('Hoy: ' . num($a['pend']) . ' esperando autorización (' . money($a['pend_monto']) . '), '
                     . panel_cant($a['obs'], 'observada', 'observadas') . ' (' . money($a['obs_monto']) . '), '
                     . panel_cant($a['apr'], 'aprobada', 'aprobadas') . ' sin liquidar (' . money($a['apr_monto']) . ').'
                     . ((int) $c['espera']['n'] ? ' Autorización promedio de las resueltas en el período: ' . panel_duracion((float) $c['espera']['minutos']) . '.' : ''));

            $rep->seccion('Préstamos liquidados por tipo de asociado', panel_cant($c['liq']['n'], 'préstamo', 'préstamos'));
            $rep->tabla([['Tipo de asociado', 2.4, 'L'], ['Cantidad', 1, 'R'], ['Capital', 1.6, 'R'], ['Neto desembolsado', 1.6, 'R']],
                array_map(function ($r) { return [$r['tipo_asociado'], num($r['n']), money($r['capital']), money($r['neto'])]; }, $c['liqProd']),
                ['Total', num($c['liq']['n']), money($c['liq']['capital']), money($c['liq']['neto'])], 0.7);
            $rep->nota('Gastos de otorgamiento ' . money($c['liq']['gastos']) . ' · intereses a cobrar ' . money($c['liq']['intereses'])
                     . ' · comisiones ' . money($c['liq']['comision']) . '.');

            $rep->seccion('Cobranzas', panel_cant($c['cob']['n'], 'pago aplicado', 'pagos aplicados'));
            $filasC = [];
            $sumC = 0.0;
            foreach ($conceptos as $k => $rot) {
                $filasC[] = [$rot, money($c['cobConcepto'][$k] ?? 0)];
                $sumC += $c['cobConcepto'][$k] ?? 0;
            }
            if (abs((float) $c['cob']['total'] - $sumC) >= 0.01) {
                $filasC[] = ['Otros (créditos a capital, redondeos)', money((float) $c['cob']['total'] - $sumC)];
            }
            $rep->tabla([['Concepto', 2, 'L'], ['Importe', 1.4, 'R']], $filasC, ['Total cobrado', money($c['cob']['total'])], 0.45);
            if ($c['cobMedio']) {
                $rep->tabla([['Medio', 2, 'L'], ['Pagos', 1, 'R'], ['Importe', 1.4, 'R']],
                    array_map(function ($m) { return [MEDIOS_PAGO[$m['medio']] ?? $m['medio'], num($m['n']), money($m['total'])]; }, $c['cobMedio']),
                    null, 0.45);
            }
            if ((int) $c['cob']['anulados']) {
                $rep->nota('Pagos anulados del período: ' . num($c['cob']['anulados']) . ' (' . money($c['cob']['anulado']) . ').');
            }
            if ($c['solVend']) {
                $rep->seccion('Solicitudes por vendedor', 'cargadas en el período');
                $rep->tabla([['Vendedor', 2.4, 'L'], ['Cargadas', 1, 'R'], ['Monto pedido', 1.6, 'R'], ['Aprobadas', 1, 'R'], ['Liquidadas', 1, 'R']],
                    array_map(function ($v) { return [$v['vendedor'], num($v['n']), money($v['monto']), num($v['aprobadas']), num($v['liquidadas'])]; }, $c['solVend']),
                    null, 0.75);
            }
            if ($c['liqLista']) {
                $rep->seccion('Préstamos liquidados', count($c['liqLista']) >= PANEL_LISTA_MAX ? 'los últimos ' . PANEL_LISTA_MAX : '');
                $rep->tabla([['Nº', 1.1, 'L'], ['Fecha', 1, 'L'], ['Cliente', 3, 'L'], ['Tipo de asociado', 1.8, 'L'], ['Vendedor', 1.8, 'L'],
                             ['Capital', 1.4, 'R'], ['Neto', 1.4, 'R'], ['Cuotas', 0.8, 'R']],
                    array_map(function ($p) {
                        return [$p['nro_prestamo'], fecha($p['fecha_liquidacion']), $p['denominacion'], $p['tipo_asociado'], (string) $p['vendedor'],
                                money($p['capital']), money($p['neto_desembolso']), (int) $p['plazo'] . ' × ' . money($p['valor_cuota'])];
                    }, $c['liqLista']));
            }
        } else {
            $rep->indicadores([
                ['Aportes del período', panel_monedas($i['aportes'], 'capital')],
                ['Intereses liquidados', panel_monedas($i['liq'], 'interes')],
                ['Pagado a inversores', panel_monedas($i['pagos'], 'pagado')],
                ['Capital vigente hoy', panel_monedas($i['vigentes'], 'capital')],
            ]);
            $rep->nota('Aportes: ' . num(panel_n($i['aportes'])) . ' (' . panel_cant(panel_n($i['aportes'], 'renov_n'), 'renovación', 'renovaciones') . '). '
                     . 'Liquidaciones: ' . num(panel_n($i['liq'])) . '. Pagos: ' . num(panel_n($i['pagos'])) . '. '
                     . 'Interés devengado a hoy de lo vigente: ' . panel_monedas($i['vigentes'], 'devengado') . '. '
                     . 'Liquidaciones a pagar hoy: ' . panel_monedas($i['aPagar'], 'a_pagar') . '.');
            if ($i['aportesLista']) {
                $rep->seccion('Aportes del período');
                $rep->tabla([['Nº', 1, 'L'], ['Inversor', 3, 'L'], ['Modalidad', 1.4, 'L'], ['Capital', 1.6, 'R'], ['Inicio', 1, 'L'], ['Vence', 1, 'L'], ['Condición', 1.4, 'L']],
                    array_map(function ($a) {
                        return [(string) $a['nro_inversion'], $a['denominacion'] . ($a['id_inversion_origen'] ? ' (renovación)' : ''), MODALIDADES[$a['modalidad']] ?? $a['modalidad'],
                                dinero($a['capital'], $a['moneda']), fecha($a['fecha_inicio']), fecha($a['fecha_vencimiento']),
                                $a['modalidad'] === 'TASA_FIJA' ? 'TNA ' . pct($a['tna']) : pct($a['pct_participacion']) . ' del interés'];
                    }, $i['aportesLista']));
            }
            if ($i['liqLista']) {
                $rep->seccion('Liquidaciones del período');
                $rep->tabla([['Fecha', 1, 'L'], ['Aporte', 1, 'L'], ['Inversor', 3, 'L'], ['Capital', 1.5, 'R'], ['Interés', 1.5, 'R'], ['Reinvertido', 1.5, 'R'], ['A pagar', 1.5, 'R'], ['Estado', 1, 'L']],
                    array_map(function ($l) {
                        return [fecha($l['fecha']), (string) $l['nro_inversion'], $l['denominacion'], dinero($l['capital'], $l['moneda']), dinero($l['interes'], $l['moneda']),
                                dinero($l['reinvertido'], $l['moneda']), dinero($l['a_pagar'], $l['moneda']), ESTADOS_LIQ_INV[$l['estado']] ?? $l['estado']];
                    }, $i['liqLista']));
            }
            if ($i['vencen']) {
                $rep->seccion('Vencen en el período', 'aportes vigentes');
                $rep->tabla([['Vence', 1, 'L'], ['Aporte', 1, 'L'], ['Inversor', 3, 'L'], ['Modalidad', 1.4, 'L'], ['Capital', 1.6, 'R'], ['Interés al vencimiento', 1.8, 'R']],
                    array_map(function ($v) {
                        return [fecha($v['fecha_vencimiento']), (string) $v['nro_inversion'], $v['denominacion'], MODALIDADES[$v['modalidad']] ?? $v['modalidad'],
                                dinero($v['capital'], $v['moneda']), dinero($v['interes'], $v['moneda'])];
                    }, $i['vencen']));
            }
        }
        $rep->enviar('panel_' . $tab . '_' . $fDesde . ($fHasta !== $fDesde ? '_' . $fHasta : '') . '.pdf');
        exit;
    } catch (Throwable $e) {
        $error = error_amigable($e);
    }
}

// =====================================================================
// Pantalla
// =====================================================================
/** Tarjeta de la fila de indicadores. */
function panel_tarjeta(string $rotulo, string $valor, string $sub = '', ?string $href = null): string
{
    $tag = $href ? 'a href="' . h($href) . '"' : 'div';
    // «$ 1.000,00 · US$ 500,00» (panel_monedas): una moneda por renglón
    $partes = explode(' · ', $valor);
    $valorHtml = count($partes) > 1 && strpos($valor, '$') !== false
        ? '<span class="panel-monedas">' . implode('', array_map(function ($x) { return '<span>' . h($x) . '</span>'; }, $partes)) . '</span>'
        : h($valor);
    return '<' . $tag . ' class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">' . h($rotulo) . '</span><strong>' . $valorHtml . '</strong>'
         . '<span class="bcra-tarjeta-sub">' . h($sub) . '</span></' . ($href ? 'a' : 'div') . '>';
}

layout_inicio('Panel general');
titulo('Panel general', 'Movimientos ' . $periodo,
       boton('Generar PDF', 'pdf', url_con(['export' => 'pdf']), 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener', 'title' => 'Abre esta solapa en PDF']));
mensajes($error ? [$error] : []);
?>
<div class="card">
  <form method="get" class="filter-bar">
    <?php if ($tab === 'inversiones'): ?><input type="hidden" name="tab" value="inversiones"><?php endif; ?>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filter-input"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filter-input"></label>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Ver</button>
    <div class="panel-rangos">
      <?php foreach ($rangos as $k => [$rot]): ?>
        <a class="panel-rango<?= $rango === $k ? ' activo' : '' ?>" href="<?= h(url_con(['rango' => $k, 'desde' => null, 'hasta' => null])) ?>"><?= h($rot) ?></a>
      <?php endforeach; ?>
    </div>
  </form>
</div>

<div class="abm-tabs">
  <a class="solapa<?= $tab === 'creditos' ? ' activa' : '' ?>" href="<?= h(url_con(['tab' => null])) ?>"><?= icono('dinero') ?>Créditos y cobranzas</a>
  <?php if ($verInv): ?>
  <a class="solapa<?= $tab === 'inversiones' ? ' activa' : '' ?>" href="<?= h(url_con(['tab' => 'inversiones'])) ?>"><?= icono('grafico') ?>Inversiones</a>
  <?php endif; ?>
</div>

<?php if ($c): // ======================================== Créditos y cobranzas
    $cre = $c['sol']['CREADA'] ?? ['n' => 0, 'monto' => 0];
    $a = $c['solAhora'];
?>
<div class="bcra-tarjetas indicadores-fila">
  <?= panel_tarjeta('Solicitudes cargadas', num($cre['n']), $cre['n'] ? money($cre['monto']) . ' pedidos' : 'ninguna en el período') ?>
  <?= panel_tarjeta('Préstamos liquidados', money($c['liq']['capital']),
        (int) $c['liq']['n'] ? panel_cant($c['liq']['n'], 'préstamo', 'préstamos') . ' · neto ' . money($c['liq']['neto']) : 'ninguno en el período') ?>
  <?= panel_tarjeta('Cobrado', money($c['cob']['total']),
        panel_cant($c['cob']['n'], 'pago', 'pagos') . ((int) $c['cob']['cancelaciones'] ? ' · ' . panel_cant($c['cob']['cancelaciones'], 'cancelación', 'cancelaciones') : ''),
        puede('cobranzas.php') ? 'cobranzas.php?desde=' . $fDesde . '&hasta=' . $fHasta : null) ?>
  <?= panel_tarjeta('Cartera vigente hoy', money($c['cartera']['capital']),
        panel_cant($c['cartera']['n'], 'préstamo', 'préstamos') . ' · ' . ((int) $c['mora']['n'] ? num($c['mora']['n']) . ' en mora (' . money($c['mora']['deuda']) . ' vencido)' : 'sin mora')) ?>
</div>

<?php $grafico = panel_grafico_svg($c['serie']); if ($grafico !== ''): ?>
<div class="card panel-card-grafico">
  <?= card_head('Liquidado y cobrado ' . ($c['serie']['por_mes'] ? 'por mes' : 'por día'), 'capital de los préstamos liquidados y pagos aplicados') ?>
  <div class="bloque panel-grafico-wrap">
    <div class="panel-leyenda"><span><i class="pg-liq"></i>Liquidado</span><span><i class="pg-cob"></i>Cobrado</span></div>
    <?= $grafico ?>
    <div class="panel-tip" hidden></div>
  </div>
</div>
<?php endif; ?>

<div class="panel-grilla">
  <div class="card">
    <?= card_head('Solicitudes', 'movimientos del período', puede('autorizaciones.php') ? boton('Autorizaciones', 'ghost', 'autorizaciones.php', 'ok', false, true) : '') ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Movimiento</th><th class="th-right" data-no-sort>Cantidad</th><th class="th-right" data-no-sort>Monto pedido</th></tr></thead>
        <tbody>
        <?php foreach (PANEL_ACCIONES_SOL as $k => $rot): $r = $c['sol'][$k] ?? ['n' => 0, 'monto' => 0]; ?>
          <tr<?= $r['n'] ? '' : ' class="td-muted"' ?>><td><?= h($rot) ?></td><td class="td-right"><?= num($r['n']) ?></td><td class="td-right"><?= $r['n'] ? money($r['monto']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bloque panel-ahora">
      <div class="dato-lbl">Hoy</div>
      <ul>
        <li><strong><?= num($a['pend']) ?></strong> esperando autorización <span class="td-muted"><?= money($a['pend_monto']) ?></span></li>
        <li><strong><?= num($a['obs']) ?></strong> <?= (int) $a['obs'] === 1 ? 'observada' : 'observadas' ?>, a corregir <span class="td-muted"><?= money($a['obs_monto']) ?></span></li>
        <li><strong><?= num($a['apr']) ?></strong> <?= (int) $a['apr'] === 1 ? 'aprobada' : 'aprobadas' ?> sin liquidar <span class="td-muted"><?= money($a['apr_monto']) ?></span></li>
      </ul>
      <?php if ((int) $c['espera']['n']): ?>
        <p class="campo-ayuda">Autorización promedio <?= (int) $c['espera']['n'] === 1 ? 'de la resuelta' : 'de las ' . num($c['espera']['n']) . ' resueltas' ?> en el período: <strong><?= h(panel_duracion((float) $c['espera']['minutos'])) ?></strong> desde que se enviaron.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <?= card_head('Cobranzas', panel_cant($c['cob']['n'], 'pago aplicado', 'pagos aplicados')) ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Concepto</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
        <tbody>
        <?php $sumC = 0.0; foreach ($conceptos as $k => $rot): $v = (float) ($c['cobConcepto'][$k] ?? 0); $sumC += $v; ?>
          <tr><td><?= h($rot) ?></td><td class="td-right"><?= money($v) ?></td></tr>
        <?php endforeach; ?>
        <?php if (abs((float) $c['cob']['total'] - $sumC) >= 0.01): ?>
          <tr><td>Otros <span class="td-muted">(créditos a capital, redondeos)</span></td><td class="td-right"><?= money((float) $c['cob']['total'] - $sumC) ?></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row"><td>Total cobrado</td><td class="td-right"><?= money($c['cob']['total']) ?></td></tr></tfoot>
      </table>
    </div>
    <?php if ($c['cobMedio']): ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Medio</th><th class="th-right" data-no-sort>Pagos</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
        <tbody>
        <?php foreach ($c['cobMedio'] as $m): ?>
          <tr><td><?= h(MEDIOS_PAGO[$m['medio']] ?? $m['medio']) ?></td><td class="td-right"><?= num($m['n']) ?></td><td class="td-right"><?= money($m['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ((int) $c['cob']['anulados']): ?>
      <div class="bloque"><p class="campo-ayuda" style="margin:0">Pagos anulados del período: <?= num($c['cob']['anulados']) ?> (<?= money($c['cob']['anulado']) ?>), fuera de los totales.</p></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <?= card_head('Préstamos liquidados por tipo de asociado', panel_cant($c['liq']['n'], 'préstamo', 'préstamos')) ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Tipo de asociado</th><th class="th-right" data-no-sort>Cant.</th><th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Neto desembolsado</th></tr></thead>
        <tbody>
        <?php foreach ($c['liqProd'] as $r): ?>
          <tr><td><?= h($r['tipo_asociado']) ?></td><td class="td-right"><?= num($r['n']) ?></td><td class="td-right"><?= money($r['capital']) ?></td><td class="td-right"><?= money($r['neto']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$c['liqProd']): ?><tr><td colspan="4" class="no-data">No se liquidaron préstamos en el período.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($c['liqProd']): ?>
        <tfoot><tr class="total-row"><td>Total</td><td class="td-right"><?= num($c['liq']['n']) ?></td><td class="td-right"><?= money($c['liq']['capital']) ?></td><td class="td-right"><?= money($c['liq']['neto']) ?></td></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php if ((int) $c['liq']['n']): ?>
    <div class="datos">
      <div><div class="dato-lbl">Gastos de otorgamiento</div><div class="dato-val"><?= money($c['liq']['gastos']) ?></div></div>
      <div><div class="dato-lbl">Intereses a cobrar</div><div class="dato-val"><?= money($c['liq']['intereses']) ?></div></div>
      <div><div class="dato-lbl">Comisiones</div><div class="dato-val"><?= money($c['liq']['comision']) ?></div></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <?= card_head('Solicitudes por vendedor', 'cargadas en el período') ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Vendedor</th><th class="th-right" data-no-sort>Cargadas</th><th class="th-right" data-no-sort>Monto pedido</th>
          <th class="th-right" data-no-sort>Aprobadas</th><th class="th-right" data-no-sort>Liquidadas</th></tr></thead>
        <tbody>
        <?php foreach ($c['solVend'] as $v): ?>
          <tr><td><?= h($v['vendedor']) ?></td><td class="td-right"><?= num($v['n']) ?></td><td class="td-right"><?= money($v['monto']) ?></td>
            <td class="td-right"><?= num($v['aprobadas']) ?></td><td class="td-right"><?= num($v['liquidadas']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$c['solVend']): ?><tr><td colspan="5" class="no-data">No se cargaron solicitudes en el período.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($c['liqLista']): ?>
<div class="card">
  <?= card_head('Préstamos liquidados', count($c['liqLista']) >= PANEL_LISTA_MAX ? 'los últimos ' . PANEL_LISTA_MAX : num(count($c['liqLista'])) . ' en el período') ?>
  <div class="data-table-wrap">
    <table class="data-table compacta">
      <thead><tr><th>Nº</th><th>Fecha</th><th>Cliente</th><th>Tipo de asociado</th><th>Vendedor</th><th class="th-right">Capital</th><th class="th-right">Neto</th><th class="th-right">Cuotas</th></tr></thead>
      <tbody>
      <?php foreach ($c['liqLista'] as $p): ?>
        <tr><td class="td-mono"><?= $verPrestamo ? '<a href="prestamo.php?id=' . (int) $p['id_prestamo'] . '">' . h($p['nro_prestamo']) . '</a>' : h($p['nro_prestamo']) ?></td>
          <td data-orden="<?= h($p['fecha_liquidacion']) ?>"><?= fecha($p['fecha_liquidacion']) ?></td><td class="td-fuerte"><?= h($p['denominacion']) ?></td>
          <td><?= h($p['tipo_asociado']) ?></td><td><?= h((string) $p['vendedor']) ?></td>
          <td class="td-right" data-orden="<?= (float) $p['capital'] ?>"><?= money($p['capital']) ?></td>
          <td class="td-right" data-orden="<?= (float) $p['neto_desembolso'] ?>"><?= money($p['neto_desembolso']) ?></td>
          <td class="td-right td-nowrap"><?= (int) $p['plazo'] ?> × <?= money($p['valor_cuota']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($i): // ======================================== Inversiones
    $hayUsd = isset($i['aportes']['USD']) || isset($i['liq']['USD']) || isset($i['vigentes']['USD']) || isset($i['pagos']['USD']);
?>
<div class="bcra-tarjetas indicadores-fila">
  <?= panel_tarjeta('Aportes del período', panel_monedas($i['aportes'], 'capital'),
        panel_n($i['aportes']) ? panel_cant(panel_n($i['aportes']), 'aporte', 'aportes') . (panel_n($i['aportes'], 'renov_n') ? ' · ' . panel_cant(panel_n($i['aportes'], 'renov_n'), 'renovación', 'renovaciones') : '') : 'ninguno en el período') ?>
  <?= panel_tarjeta('Intereses liquidados', panel_monedas($i['liq'], 'interes'),
        panel_n($i['liq']) ? panel_cant(panel_n($i['liq']), 'liquidación', 'liquidaciones') . ' · capital ' . panel_monedas($i['liq'], 'capital') : 'ninguna en el período') ?>
  <?= panel_tarjeta('Pagado a inversores', panel_monedas($i['pagos'], 'pagado'),
        panel_n($i['pagos']) ? panel_cant(panel_n($i['pagos']), 'pago', 'pagos') : 'ninguno en el período') ?>
  <?= panel_tarjeta('Capital vigente hoy', panel_monedas($i['vigentes'], 'capital'),
        panel_cant(panel_n($i['vigentes']), 'aporte', 'aportes') . ' · devengado ' . panel_monedas($i['vigentes'], 'devengado'), $verInversion ? 'inversiones.php' : null) ?>
</div>
<?php if ($hayUsd): ?>
  <p class="campo-ayuda panel-nota-cot">Pesos y dólares van por separado. <?= h(ucfirst(cot_txt('USD', $i['cotUsd'], null))) ?>.</p>
<?php endif; ?>

<div class="card">
  <?= card_head('Hoy', 'no depende del período') ?>
  <div class="datos">
    <div><div class="dato-lbl">Capital vigente</div><div class="dato-val"><?= h(panel_monedas($i['vigentes'], 'capital')) ?></div>
      <div class="campo-ayuda"><?= panel_cant(panel_n($i['vigentes']), 'aporte', 'aportes') ?></div></div>
    <div><div class="dato-lbl">Interés devengado a hoy</div><div class="dato-val"><?= h(panel_monedas($i['vigentes'], 'devengado')) ?></div></div>
    <div><div class="dato-lbl">Liquidaciones a pagar</div><div class="dato-val"><?= panel_n($i['aPagar']) ? h(panel_monedas($i['aPagar'], 'a_pagar')) : 'ninguna' ?></div>
      <div class="campo-ayuda"><?= panel_n($i['aPagar']) ? panel_cant(panel_n($i['aPagar']), 'liquidación', 'liquidaciones') : '' ?></div></div>
  </div>
</div>

<div class="card">
    <?= card_head('Liquidaciones del período', 'por moneda') ?>
    <div class="data-table-wrap">
      <table class="data-table compacta" data-sin-orden>
        <thead><tr><th data-no-sort>Moneda</th><th class="th-right" data-no-sort>Cant.</th><th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Interés</th>
          <th class="th-right" data-no-sort>Reinvertido</th><th class="th-right" data-no-sort>A pagar</th></tr></thead>
        <tbody>
        <?php foreach ($i['liq'] as $m => $r): ?>
          <tr><td><?= h(MONEDAS[$m] ?? $m) ?></td><td class="td-right"><?= num($r['n']) ?></td><td class="td-right"><?= dinero($r['capital'], $m) ?></td>
            <td class="td-right"><?= dinero($r['interes'], $m) ?></td><td class="td-right"><?= dinero($r['reinvertido'], $m) ?></td><td class="td-right"><?= dinero($r['a_pagar'], $m) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$i['liq']): ?><tr><td colspan="6" class="no-data">No hubo liquidaciones en el período.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
</div>

<div class="card">
  <?= card_head('Aportes del período', count($i['aportesLista']) >= PANEL_LISTA_MAX ? 'los últimos ' . PANEL_LISTA_MAX : panel_cant(count($i['aportesLista']), 'aporte', 'aportes')) ?>
  <div class="data-table-wrap">
    <table class="data-table compacta">
      <thead><tr><th>Nº</th><th>Inversor</th><th>Modalidad</th><th class="th-right">Capital</th><th>Inicio</th><th>Vence</th><th>Condición</th></tr></thead>
      <tbody>
      <?php foreach ($i['aportesLista'] as $ap): ?>
        <tr><td class="td-mono"><?= $verInversion ? '<a href="inversion.php?id=' . (int) $ap['id_inversion'] . '">' . h((string) $ap['nro_inversion']) . '</a>' : h((string) $ap['nro_inversion']) ?></td>
          <td class="td-fuerte"><?= h($ap['denominacion']) ?><?= $ap['id_inversion_origen'] ? ' <span class="td-muted">· renovación</span>' : '' ?></td>
          <td><?= h(MODALIDADES[$ap['modalidad']] ?? $ap['modalidad']) ?></td>
          <td class="td-right"><?= dinero($ap['capital'], $ap['moneda']) ?></td>
          <td><?= fecha($ap['fecha_inicio']) ?></td><td><?= fecha($ap['fecha_vencimiento']) ?></td>
          <td><?= $ap['modalidad'] === 'TASA_FIJA' ? 'TNA ' . pct($ap['tna']) : pct($ap['pct_participacion']) . ' del interés' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$i['aportesLista']): ?><tr><td colspan="7" class="no-data">No hubo aportes en el período.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($i['liqLista']): ?>
<div class="card">
  <?= card_head('Detalle de liquidaciones', num(count($i['liqLista'])) . ' en el período') ?>
  <div class="data-table-wrap">
    <table class="data-table compacta">
      <thead><tr><th>Fecha</th><th>Aporte</th><th>Inversor</th><th class="th-right">Capital</th><th class="th-right">Interés</th><th class="th-right">Reinvertido</th><th class="th-right">A pagar</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($i['liqLista'] as $l): ?>
        <tr><td><?= fecha($l['fecha']) ?></td>
          <td class="td-mono"><?= $verInversion ? '<a href="inversion.php?id=' . (int) $l['id_inversion'] . '">' . h((string) $l['nro_inversion']) . '</a>' : h((string) $l['nro_inversion']) ?></td>
          <td class="td-fuerte"><?= h($l['denominacion']) ?><?= (int) $l['anticipada'] ? ' <span class="td-muted">· anticipada</span>' : '' ?></td>
          <td class="td-right"><?= dinero($l['capital'], $l['moneda']) ?></td><td class="td-right"><?= dinero($l['interes'], $l['moneda']) ?></td>
          <td class="td-right"><?= dinero($l['reinvertido'], $l['moneda']) ?></td><td class="td-right"><?= dinero($l['a_pagar'], $l['moneda']) ?></td>
          <td><?= estado_liq_inv($l['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <?= card_head('Vencen en el período', 'aportes vigentes, para liquidar') ?>
  <div class="data-table-wrap">
    <table class="data-table compacta">
      <thead><tr><th>Vence</th><th>Aporte</th><th>Inversor</th><th>Modalidad</th><th class="th-right">Capital</th><th class="th-right">Interés al vencimiento</th></tr></thead>
      <tbody>
      <?php foreach ($i['vencen'] as $v): ?>
        <tr><td><?= fecha($v['fecha_vencimiento']) ?></td>
          <td class="td-mono"><?= $verInversion ? '<a href="inversion.php?id=' . (int) $v['id_inversion'] . '">' . h((string) $v['nro_inversion']) . '</a>' : h((string) $v['nro_inversion']) ?></td>
          <td class="td-fuerte"><?= h($v['denominacion']) ?></td><td><?= h(MODALIDADES[$v['modalidad']] ?? $v['modalidad']) ?></td>
          <td class="td-right"><?= dinero($v['capital'], $v['moneda']) ?></td><td class="td-right"><?= dinero($v['interes'], $v['moneda']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$i['vencen']): ?><tr><td colspan="6" class="no-data">No vence ningún aporte en el período.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
// Tooltip del gráfico: al pasar por una columna (toda su altura), el día y los dos importes.
(function () {
  var wrap = document.querySelector('.panel-grafico-wrap');
  if (!wrap) return;
  var tip = wrap.querySelector('.panel-tip');
  function mostrar(r, ev) {
    tip.innerHTML = '';
    var t = document.createElement('strong'); t.textContent = r.getAttribute('data-rotulo'); tip.appendChild(t);
    [['pg-liq', 'Liquidado', 'data-liq'], ['pg-cob', 'Cobrado', 'data-cob']].forEach(function (s) {
      var f = document.createElement('div');
      var i = document.createElement('i'); i.className = s[0]; f.appendChild(i);
      f.appendChild(document.createTextNode(s[1] + ': '));
      var b = document.createElement('b'); b.textContent = r.getAttribute(s[2]); f.appendChild(b);
      tip.appendChild(f);
    });
    tip.hidden = false;
    var cw = wrap.getBoundingClientRect(), x = ev.clientX - cw.left, y = ev.clientY - cw.top;
    tip.style.left = Math.min(Math.max(8, x + 14), cw.width - tip.offsetWidth - 8) + 'px';
    tip.style.top = Math.max(8, y - tip.offsetHeight - 10) + 'px';
    wrap.querySelectorAll('.pg-hit.activa').forEach(function (o) { if (o !== r) o.classList.remove('activa'); });
    r.classList.add('activa');
  }
  wrap.querySelectorAll('.pg-hit').forEach(function (r) {
    r.addEventListener('mousemove', function (e) { mostrar(r, e); });
    r.addEventListener('mouseleave', function () { tip.hidden = true; r.classList.remove('activa'); });
    r.addEventListener('click', function (e) { mostrar(r, e); });           // en el celular, con un toque
  });
})();
</script>
<?php layout_fin(); ?>
