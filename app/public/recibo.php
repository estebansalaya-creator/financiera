<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';

// ---------------------------------------------------------------------
// Recibo de un pago
//   recibo.php?id=N              en pantalla
//   recibo.php?id=N&export=pdf   PDF para entregar al cliente
//   POST accion=anular           sp_anular_pago (solo el último aplicado)
// ---------------------------------------------------------------------

$id = (int) get_str('id');
$errores = [];

if (es_post() && post_str('accion') === 'anular') {
    csrf_exigir();
    try {
        $st = db()->prepare('CALL sp_anular_pago(?, ?, ?)');
        $st->execute([$id, post_str('motivo'), usuario_id()]);
        while ($st->nextRowset()) {
        }
        $st->closeCursor();
        flash('ok', 'Pago anulado. La deuda del préstamo volvió a como estaba.');
        redirigir('recibo.php?id=' . $id);
    } catch (Throwable $e) {
        $errores[] = mensaje_bd($e);
    }
}

$g = null;
$imp = [];
try {
    $g = q1('SELECT g.*, p.nro_prestamo, p.id_prestamo, p.plazo, p.id_cliente, c.denominacion, c.cuit, pr.nombre AS producto, l.archivo AS lote_archivo
               FROM pagos g
               JOIN prestamos p ON p.id_prestamo = g.id_prestamo
               JOIN clientes c ON c.id_cliente = p.id_cliente
               JOIN productos pr ON pr.id_producto = p.id_producto
               LEFT JOIN lotes_cobranza l ON l.id_lote = g.id_lote
              WHERE g.id_pago = ?', [$id]);
    if ($g) {
        $imp = q("SELECT c.nro_cuota, c.vencimiento,
                         SUM(IF(pi.concepto = 'CAPITAL', pi.importe, 0)) AS capital,
                         SUM(IF(pi.concepto IN ('INTERES', 'IVA_INTERES'), pi.importe, 0)) AS interes,
                         SUM(IF(pi.concepto IN ('PUNITORIO', 'IVA_PUNITORIO'), pi.importe, 0)) AS punitorio,
                         SUM(pi.importe) AS total
                    FROM pagos_imputacion pi JOIN cuotas c ON c.id_cuota = pi.id_cuota
                   WHERE pi.id_pago = ? GROUP BY c.nro_cuota, c.vencimiento ORDER BY c.nro_cuota", [$id]);
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

$saldo = null;
$anulable = false;
if ($g) {
    try {
        $saldo = q1("SELECT SUM(capital_pend + interes_pend + iva_pend + punit_pend + ivap_pend) AS deuda,
                            SUM(capital_pend) AS capital, SUM(estado = 'PAGADA') AS pagadas
                       FROM v_cuotas_saldo WHERE id_prestamo = ?", [$g['id_prestamo']]);
        $anulable = $g['estado'] === 'APLICADO' && ultimo_pago_aplicado((int) $g['id_prestamo']) === $id;
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

// ---------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------
if ($g && get_str('export') === 'pdf') {
    require_once __DIR__ . '/../inc/pdf.php';
    $rep = new PdfReporte('Recibo ' . $g['nro_recibo'] . ($g['estado'] === 'ANULADO' ? ' · ANULADO' : ''),
                          $g['denominacion'] . ' · CUIT/CUIL ' . cuit_fmt($g['cuit']) . ' · préstamo ' . $g['nro_prestamo'] . ' · ' . $g['producto'], false);
    $rep->indicadores([
        ['Importe', money($g['importe'])],
        ['Fecha', fecha($g['fecha'])],
        ['Medio', MEDIOS_PAGO[$g['medio']] ?? $g['medio']],
    ]);
    if ($g['modo'] === 'CANCELACION') {
        $rep->nota('Cancelación total anticipada del préstamo.');
    }
    if ($g['referencia']) {
        $rep->nota('Referencia: ' . $g['referencia']);
    }
    $rep->seccion('Imputación');
    $rep->tabla(
        [['Cuota', 0.7, 'R'], ['Vencimiento', 1.2, 'L'], ['Capital', 1.3, 'R'], ['Interés + IVA', 1.3, 'R'], ['Punitorios + IVA', 1.3, 'R'], ['Total', 1.3, 'R']],
        array_map(function ($r) use ($g) {
            return [(int) $r['nro_cuota'] . ' de ' . (int) $g['plazo'], fecha($r['vencimiento']), money($r['capital']), money($r['interes']),
                    money($r['punitorio']), money($r['total'])];
        }, $imp),
        ['', 'TOTAL', money(array_sum(array_column($imp, 'capital'))), money(array_sum(array_column($imp, 'interes'))),
         money(array_sum(array_column($imp, 'punitorio'))), money($g['importe'])]
    );
    if ($g['estado'] === 'ANULADO') {
        $rep->nota('Pago ANULADO el ' . date('d/m/Y H:i', strtotime((string) $g['anulado_el'])) . ': ' . $g['motivo_anulacion']);
    } elseif ($saldo) {
        $rep->nota('Saldo actual del préstamo al ' . date('d/m/Y') . ': ' . money($saldo['deuda']) . ' (capital ' . money($saldo['capital']) . '; sin punitorios a devengar).');
    }
    $rep->enviar('recibo_' . $g['nro_recibo'] . '.pdf');
    exit;
}

if (!$g && !$errores) {
    http_response_code(404);
}
layout_inicio($g ? 'Recibo ' . $g['nro_recibo'] : 'Recibo', 'cobranzas');
if (!$g) {
    mensajes($errores);
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese pago</h1>' . boton('Ir a cobranzas', 'primary', 'cobranzas.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

titulo('Recibo ' . $g['nro_recibo'], $g['denominacion'] . ' · préstamo ' . $g['nro_prestamo'],
       boton('Recibo PDF', 'pdf', 'recibo.php?id=' . $id . '&export=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener'])
     . boton('Cobrar otro', 'primary', 'cobrar.php', 'dinero'),
       '<a href="cobranzas.php">Cobranzas</a> / <a href="prestamo.php?id=' . (int) $g['id_prestamo'] . '">' . h($g['nro_prestamo']) . '</a> / ' . h($g['nro_recibo']));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Importe</span><strong><?= money($g['importe']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= $g['modo'] === 'CANCELACION' ? 'cancelación total' : 'pago a cuenta' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Fecha y medio</span><strong><?= h(fecha($g['fecha'])) ?></strong>
    <span class="bcra-tarjeta-sub"><?= h(MEDIOS_PAGO[$g['medio']] ?? $g['medio']) ?><?= $g['referencia'] ? ' · ' . h($g['referencia']) : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Estado</span><?= $g['estado'] === 'ANULADO' ? estado('Anulado', 'danger') : estado('Aplicado', 'ok') ?>
    <span class="bcra-tarjeta-sub"><?= $g['id_lote'] ? 'lote N° ' . (int) $g['id_lote'] . ($g['lote_archivo'] ? ' · ' . h($g['lote_archivo']) : '') : 'cargado el ' . h(date('d/m/Y H:i', strtotime($g['creado_el']))) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo actual del préstamo</span><strong><?= $saldo ? money($saldo['deuda']) : '—' ?></strong>
    <span class="bcra-tarjeta-sub"><?= $saldo ? (int) $saldo['pagadas'] . ' de ' . (int) $g['plazo'] . ' cuotas pagadas' : '' ?></span></div>
</div>

<?php if ($g['estado'] === 'ANULADO'): ?>
  <?php alerta('Anulado el ' . date('d/m/Y H:i', strtotime((string) $g['anulado_el'])) . ': ' . $g['motivo_anulacion'], 'warning'); ?>
<?php endif; ?>

<div class="card">
  <?= card_head('Imputación', 'cómo se aplicó el pago a las cuotas') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="th-right" data-no-sort>Cuota</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Capital</th>
        <th class="th-right" data-no-sort>Interés + IVA</th><th class="th-right" data-no-sort>Punitorios + IVA</th><th class="th-right" data-no-sort>Total</th></tr></thead>
      <tbody>
      <?php foreach ($imp as $r): ?>
        <tr><td class="td-right"><?= (int) $r['nro_cuota'] ?> de <?= (int) $g['plazo'] ?></td><td><?= h(fecha($r['vencimiento'])) ?></td>
          <td class="td-right"><?= money($r['capital']) ?></td><td class="td-right"><?= money($r['interes']) ?></td>
          <td class="td-right"><?= (float) $r['punitorio'] > 0 ? money($r['punitorio']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right td-fuerte"><?= money($r['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right"><?= money(array_sum(array_column($imp, 'capital'))) ?></td>
        <td class="td-right"><?= money(array_sum(array_column($imp, 'interes'))) ?></td><td class="td-right"><?= money(array_sum(array_column($imp, 'punitorio'))) ?></td>
        <td class="td-right"><?= money($g['importe']) ?></td></tr></tfoot>
    </table>
  </div>
</div>

<?php if ($g['estado'] === 'APLICADO'): ?>
<div class="card">
  <?= card_head('Anular el pago', $anulable ? 'vuelve la deuda a como estaba' : 'solo se puede anular el último pago del préstamo') ?>
  <?php if ($anulable): ?>
    <form method="post" action="recibo.php?id=<?= $id ?>" class="bloque" data-confirmar="Se anula el recibo <?= h($g['nro_recibo']) ?> por <?= h(money($g['importe'])) ?>. Los punitorios que ya se habían devengado se mantienen."
          data-confirmar-titulo="Anular el pago" data-confirmar-boton="Anular" data-peligro>
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="anular">
      <div class="campos">
        <div class="campo" style="--span:9"><label for="motivo">Motivo <span class="req">*</span></label>
          <input id="motivo" name="motivo" maxlength="200" required placeholder="Ej.: se cargó en el préstamo equivocado"></div>
        <div class="campo campo-boton" style="--span:3"><span class="campo-label">&nbsp;</span>
          <?= boton('Anular pago', 'danger', null, 'borrar', true) ?></div>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
