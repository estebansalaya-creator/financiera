<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/recibos.php';

// ---------------------------------------------------------------------
// Recibo de cobranza. Un recibo cubre uno o más préstamos del cliente (la
// Cobros bancarios aplica un crédito del banco a todos sus préstamos):
// un pago por préstamo, cada uno con su imputación a las cuotas.
//   recibo.php?r=N              el recibo
//   recibo.php?id=N             el recibo del pago N (los enlaces de antes)
//   &export=pdf                 PDF para entregar al cliente
//   POST accion=anular          sp_anular_recibo: el recibo entero. Cada
//                               pago tiene que ser el último de su préstamo.
//                               Con volver=cobranzas.php…, vuelve ahí.
// ---------------------------------------------------------------------

$errores = [];
$idRec = (int) get_str('r');
try {
    if (!$idRec && get_str('id') !== '') {
        $idRec = recibo_de_pago((int) get_str('id')) ?? 0;
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

if (es_post() && post_str('accion') === 'anular' && $idRec) {
    csrf_exigir();
    try {
        $st = db()->prepare('CALL sp_anular_recibo(?, ?, ?)');
        $st->execute([$idRec, post_str('motivo'), usuario_id()]);
        while ($st->nextRowset()) {
        }
        $st->closeCursor();
        flash('ok', 'Recibo anulado. La deuda que cubría volvió a como estaba.');
        // Desde la grilla de Recibos emitidos se vuelve a la grilla, con sus filtros.
        $volver = post_str('volver');
        redirigir(preg_match('/^cobranzas\.php(\?|$)/', $volver) ? $volver : 'recibo.php?r=' . $idRec);
    } catch (Throwable $e) {
        $errores[] = mensaje_bd($e);
    }
}

$g = null;
$pagos = [];
$cuotaSoc = [];
try {
    if ($idRec) {
        $g = recibo_leer($idRec);
        if ($g) {
            $pagos = recibo_pagos($idRec);
            $cuotaSoc = recibo_cuota_social($idRec);
        }
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

// Se anula entero: cada pago tiene que ser el último aplicado de su préstamo.
$anulable = false;
$porQueNo = '';
if ($g && $g['estado'] === 'APLICADO') {
    $anulable = $pagos || $cuotaSoc;
    foreach ($pagos as $p) {
        if ((int) $p['ultimo_del_prestamo'] !== (int) $p['id_pago']) {
            $anulable = false;
            $porQueNo = 'El préstamo ' . $p['nro_prestamo'] . ' tiene un pago posterior: para anular este recibo, primero hay que anular ese.';
            break;
        }
    }
}
$saldoTotal = array_sum(array_map(function ($p) { return (float) ($p['saldo']['deuda'] ?? 0); }, $pagos));
$nros = implode(', ', array_column($pagos, 'nro_prestamo'));
$csTotal = round(array_sum(array_map(function ($s) { return (float) $s['importe']; }, $cuotaSoc)), 2);
// «préstamos P-1, P-2 · cuota social»: lo que cubre el recibo.
$cubre = ($pagos ? (count($pagos) === 1 ? 'préstamo ' : 'préstamos ') . $nros : '') . ($cuotaSoc ? ($pagos ? ' · ' : '') . 'cuota social' : '');
$origen = '';
if ($g) {
    if ($g['id_extracto']) {
        $origen = 'Crédito del banco del ' . fecha($g['ext_fecha']) . ($g['ext_referencia'] ? ' (ref. ' . $g['ext_referencia'] . ')' : '')
                . ($g['cuil_pagador'] && $g['cuil_pagador'] !== $g['cuit'] ? ' · pagó ' . cuit_fmt($g['cuil_pagador']) : '');
    } elseif ($g['id_lote']) {
        $origen = 'Lote N° ' . (int) $g['id_lote'] . ($g['lote_archivo'] ? ' · ' . $g['lote_archivo'] : '');
    } else {
        $origen = 'Cargado el ' . date('d/m/Y H:i', strtotime((string) $g['creado_el']));
    }
}

// ---------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------
if ($g && get_str('export') === 'pdf') {
    require_once __DIR__ . '/../inc/pdf.php';
    $rep = new PdfReporte('Recibo ' . $g['nro_recibo'] . ($g['estado'] === 'ANULADO' ? ' · ANULADO' : ''),
                          $g['denominacion'] . ' · CUIT/CUIL ' . cuit_fmt($g['cuit']) . ' · ' . $cubre, false);
    $rep->indicadores([
        ['Importe', money($g['importe'])],
        ['Fecha', fecha($g['fecha'])],
        ['Medio', MEDIOS_PAGO[$g['medio']] ?? $g['medio']],
    ]);
    if (($g['talonario_letra'] ?? '') === 'X') {
        $rep->nota('Documento no válido como factura.');
    }
    if ($g['referencia']) {
        $rep->nota('Referencia: ' . $g['referencia']);
    }
    foreach ($pagos as $p) {
        $rep->seccion('Préstamo ' . $p['nro_prestamo'] . ' · ' . $p['tipo_asociado'], 'aplicado ' . money($p['importe'])
                      . ($p['modo'] === 'CANCELACION' ? ' · cancelación total anticipada' : ''));
        $rep->tabla(
            [['Cuota', 0.7, 'R'], ['Vencimiento', 1.2, 'L'], ['Capital', 1.3, 'R'], ['Interés + IVA', 1.3, 'R'], ['Punitorios + IVA', 1.3, 'R'], ['Total', 1.3, 'R']],
            array_map(function ($r) use ($p) {
                return [(int) $r['nro_cuota'] . ' de ' . (int) $p['plazo'], fecha($r['vencimiento']), money($r['capital']), money($r['interes']),
                        money($r['punitorio']), money($r['total'])];
            }, $p['cuotas']),
            ['', 'TOTAL', money(array_sum(array_column($p['cuotas'], 'capital'))), money(array_sum(array_column($p['cuotas'], 'interes'))),
             money(array_sum(array_column($p['cuotas'], 'punitorio'))), money($p['importe'])]
        );
        if ($g['estado'] !== 'ANULADO' && $p['saldo']) {
            $rep->nota('Saldo actual del préstamo ' . $p['nro_prestamo'] . ' al ' . date('d/m/Y') . ': ' . money($p['saldo']['deuda'])
                       . ' (capital ' . money($p['saldo']['capital']) . '; sin punitorios a devengar).');
        }
    }
    if ($cuotaSoc) {
        $rep->seccion('Cuota social', 'aplicado ' . money($csTotal));
        $rep->tabla(
            [['Período', 1.6, 'L'], ['Concepto', 1.4, 'L'], ['Cuota', 1.3, 'R'], ['Aplicado', 1.3, 'R']],
            array_map(function ($s) {
                return [cs_periodo_txt($s['periodo']), CS_CONCEPTOS[$s['concepto']] ?? $s['concepto'], money($s['cuota']), money($s['importe'])];
            }, $cuotaSoc),
            ['', 'TOTAL', '', money($csTotal)]
        );
    }
    if ($g['estado'] === 'ANULADO') {
        $rep->nota('Recibo ANULADO el ' . date('d/m/Y H:i', strtotime((string) $g['anulado_el'])) . ': ' . $g['motivo_anulacion']);
    }
    $rep->enviar('recibo_' . preg_replace('/[^\w\-]+/', '_', (string) $g['nro_recibo']) . '.pdf');
    exit;
}

if (!$g && !$errores) {
    http_response_code(404);
}

layout_inicio($g ? 'Recibo ' . $g['nro_recibo'] : 'Recibo', 'cobranzas');
if (!$g) {
    mensajes($errores);
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese recibo</h1>' . boton('Ir a Recibos emitidos', 'primary', 'cobranzas.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

$verPrestamo = puede_abrir('prestamo.php');
titulo('Recibo ' . $g['nro_recibo'],
       $g['denominacion'] . ' · ' . $cubre . ($g['talonario'] ? ' · talonario ' . $g['talonario'] : ''),
       boton('Recibo PDF', 'pdf', 'recibo.php?r=' . $idRec . '&export=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener'])
     . (puede('cobrar.php') ? boton('Otro cobro en efectivo', 'primary', 'cobrar.php', 'dinero') : ''),
       '<a href="cobranzas.php">Recibos emitidos</a>' . ($g['id_extracto'] && puede('emision_recibos.php') ? ' / <a href="emision_recibos.php?cuenta=' . (int) $g['id_cuenta_fondos'] . '">Cobros bancarios</a>' : '')
     . ' / ' . h($g['nro_recibo']));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Importe</span><strong><?= money($g['importe']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= !$pagos ? 'cuota social' : (count($pagos) === 1 ? ($pagos[0]['modo'] === 'CANCELACION' ? 'cancelación total' : 'pago a cuenta') : 'aplicado a ' . count($pagos) . ' préstamos') . ($cuotaSoc ? ' y cuota social' : '') ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Fecha y medio</span><strong><?= h(fecha($g['fecha'])) ?></strong>
    <span class="bcra-tarjeta-sub"><?= h(MEDIOS_PAGO[$g['medio']] ?? $g['medio']) ?><?= $g['referencia'] ? ' · ' . h($g['referencia']) : '' ?><?= $g['cuenta'] ? ' · entró en ' . h($g['cuenta']) : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Estado</span><?= $g['estado'] === 'ANULADO' ? estado('Anulado', 'danger') : estado('Aplicado', 'ok') ?>
    <span class="bcra-tarjeta-sub"><?= h($origen) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo actual <?= count($pagos) === 1 ? 'del préstamo' : 'de los préstamos' ?></span><strong><?= money($saldoTotal) ?></strong>
    <span class="bcra-tarjeta-sub"><?php if (count($pagos) === 1 && $pagos[0]['saldo']): ?><?= (int) $pagos[0]['saldo']['pagadas'] ?> de <?= (int) $pagos[0]['plazo'] ?> cuotas pagadas<?php endif; ?></span></div>
</div>

<?php if ($g['estado'] === 'ANULADO'): ?>
  <?php alerta('Anulado el ' . date('d/m/Y H:i', strtotime((string) $g['anulado_el'])) . ': ' . $g['motivo_anulacion']
               . ($g['id_extracto'] ? ' El crédito del banco volvió a quedar para emitir.' : ''), 'warning'); ?>
<?php endif; ?>

<?php foreach ($pagos as $p): ?>
<div class="card">
  <?= card_head('Préstamo ' . $p['nro_prestamo'],
                $p['tipo_asociado'] . ' · aplicado ' . money($p['importe']) . ($p['modo'] === 'CANCELACION' ? ' · cancelación total' : '')
                . ($p['saldo'] ? ' · saldo actual ' . money($p['saldo']['deuda']) : ''),
                $verPrestamo ? btn_accion('ver', 'prestamo.php?id=' . (int) $p['id_prestamo'], 'Ver el préstamo') : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="th-right" data-no-sort>Cuota</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Capital</th>
        <th class="th-right" data-no-sort>Interés + IVA</th><th class="th-right" data-no-sort>Punitorios + IVA</th><th class="th-right" data-no-sort>Total</th></tr></thead>
      <tbody>
      <?php foreach ($p['cuotas'] as $r): ?>
        <tr><td class="td-right"><?= (int) $r['nro_cuota'] ?> de <?= (int) $p['plazo'] ?></td><td><?= h(fecha($r['vencimiento'])) ?></td>
          <td class="td-right"><?= money($r['capital']) ?></td><td class="td-right"><?= money($r['interes']) ?></td>
          <td class="td-right"><?= (float) $r['punitorio'] > 0 ? money($r['punitorio']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right td-fuerte"><?= money($r['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right"><?= money(array_sum(array_column($p['cuotas'], 'capital'))) ?></td>
        <td class="td-right"><?= money(array_sum(array_column($p['cuotas'], 'interes'))) ?></td><td class="td-right"><?= money(array_sum(array_column($p['cuotas'], 'punitorio'))) ?></td>
        <td class="td-right"><?= money($p['importe']) ?></td></tr></tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php if ($cuotaSoc): ?>
<div class="card">
  <?= card_head('Cuota social', 'aplicado ' . money($csTotal),
                puede_abrir('cliente.php') ? btn_accion('ver', 'cliente.php?id=' . (int) $g['id_cliente'] . '#cuota-social', 'Ver la cuenta del asociado') : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th data-no-sort>Período</th><th data-no-sort>Concepto</th><th class="th-right" data-no-sort>Cuota</th><th class="th-right" data-no-sort>Aplicado</th><th data-no-sort>Estado actual</th></tr></thead>
      <tbody>
      <?php foreach ($cuotaSoc as $s): ?>
        <tr><td><?= h(cs_periodo_txt($s['periodo'])) ?></td><td><?= h(CS_CONCEPTOS[$s['concepto']] ?? $s['concepto']) ?></td>
          <td class="td-right"><?= money($s['cuota']) ?></td><td class="td-right td-fuerte"><?= money($s['importe']) ?></td>
          <td><?= h(CS_ESTADOS[$s['estado']] ?? $s['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="3">TOTAL</td><td class="td-right"><?= money($csTotal) ?></td><td></td></tr></tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../inc/facturacion.php';
$factRec = fact_instalado() ? q('SELECT * FROM comprobantes_venta WHERE id_recibo = ? ORDER BY id_comprobante', [$idRec]) : [];
if ($factRec): ?>
<div class="card">
  <?= card_head('Factura electrónica', '', puede('facturacion.php') ? btn_accion('ver', 'facturacion.php?estado=todos&q=' . rawurlencode((string) $g['nro_recibo']), 'Ver en Facturación electrónica') : '') ?>
  <div class="bloque">
  <?php foreach ($factRec as $fc): ?>
    <div><strong class="mono"><?= h(fact_nro($fc)) ?></strong> · <?= h(FACT_TIPOS[(int) $fc['cbte_tipo']] ?? '') ?> · <?= money($fc['importe_total']) ?> ·
      <?= estado(FACT_ESTADOS[$fc['arca_estado']], FACT_ESTADOS_TIPO[$fc['arca_estado']]) ?><?= $fc['cae'] ? ' · CAE <span class="mono">' . h($fc['cae']) . '</span>' : '' ?>
      <?= $fc['arca_obs'] ? '<span class="segunda-linea">' . h($fc['arca_obs']) . '</span>' : '' ?></div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($g['estado'] === 'APLICADO'): ?>
<div class="card">
  <?= card_head('Anular el recibo', $anulable ? 'vuelve la deuda ' . ($pagos ? (count($pagos) === 1 ? 'del préstamo' : 'de los ' . count($pagos) . ' préstamos') . ($cuotaSoc ? ' y de la cuota social' : '') : 'de la cuota social') . ' a como estaba' : $porQueNo) ?>
  <?php if ($anulable): ?>
    <form method="post" action="recibo.php?r=<?= $idRec ?>" class="bloque" data-confirmar="Se anula el recibo <?= h($g['nro_recibo']) ?> por <?= h(money($g['importe'])) ?><?= count($pagos) > 1 ? ', con lo que aplicó a los ' . count($pagos) . ' préstamos' : '' ?>. Los punitorios que ya se habían devengado se mantienen y el número no se vuelve a usar."
          data-confirmar-titulo="Anular el recibo" data-confirmar-boton="Anular" data-peligro>
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="anular">
      <div class="campos">
        <div class="campo" style="--span:9"><label for="motivo">Motivo <span class="req">*</span></label>
          <input id="motivo" name="motivo" maxlength="200" required placeholder="Ej.: se cargó al cliente equivocado"></div>
        <div class="campo campo-boton" style="--span:3"><span class="campo-label">&nbsp;</span>
          <?= boton('Anular recibo', 'danger', null, 'borrar', true) ?></div>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
