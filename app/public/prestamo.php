<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';

// ---------------------------------------------------------------------
// Ficha del préstamo
//   prestamo.php?id=N              ficha
//   prestamo.php?id=N&export=pdf   plan de pagos para el cliente
//   POST accion=anular             sp_anular_prestamo (sin pagos aplicados)
// Lo pendiente de cada cuota sale de v_cuotas_saldo; la deuda a hoy, con
// punitorios, de sp_estado_cuenta (el mismo motor que cobra).
// ---------------------------------------------------------------------

$id = (int) get_str('id');
$errores = [];

if (es_post() && post_str('accion') === 'anular') {
    csrf_exigir();
    try {
        $st = db()->prepare('CALL sp_anular_prestamo(?, ?, ?)');
        $st->execute([$id, post_str('motivo'), usuario_id()]);
        while ($st->nextRowset()) {
        }
        $st->closeCursor();
        flash('ok', 'Préstamo anulado. La comisión del vendedor se revirtió.');
        redirigir('prestamo.php?id=' . $id);
    } catch (Throwable $e) {
        $errores[] = mensaje_bd($e);
    }
}

$p = null;
$cuotas = $pagos = $movs = $comisiones = $gastosPre = [];
$hoy = null;
$cancelacion = null;
try {
    $p = q1('SELECT p.*, c.denominacion, c.cuit, c.tipo AS cliente_tipo, ta.nombre AS tipo_asociado, ta.codigo AS tipo_asociado_codigo,
                    v.nombre AS vendedor, v.codigo AS vendedor_codigo, r.descripcion AS regla
               FROM prestamos p
               JOIN clientes c ON c.id_cliente = p.id_cliente
               JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
               LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
               LEFT JOIN comision_reglas r ON r.id_regla = p.id_comision_regla
              WHERE p.id_prestamo = ?', [$id]);
    if ($p) {
        $cuotas = q('SELECT * FROM v_cuotas_saldo WHERE id_prestamo = ? ORDER BY nro_cuota', [$id]);
        $pagos  = q('SELECT * FROM pagos WHERE id_prestamo = ? ORDER BY fecha DESC, id_pago DESC', [$id]);
        $movs   = q('SELECT * FROM movimientos WHERE id_prestamo = ? ORDER BY fecha, id_movimiento', [$id]);
        $gastosPre = prestamo_gastos($id);
        $comisiones = q('SELECT d.*, l.estado AS liq_estado FROM comisiones_devengadas d
                           LEFT JOIN comisiones_liquidacion l ON l.id_liquidacion = d.id_liquidacion
                          WHERE d.id_prestamo = ? ORDER BY d.fecha, d.id_devengada', [$id]);
        if ($p['estado'] === 'VIGENTE') {
            $r = sp_consulta('CALL sp_estado_cuenta(?, CURDATE(), ?)', [$id, 'NORMAL']);
            $hoy = $r[1][0] ?? null;
            $r = sp_consulta('CALL sp_estado_cuenta(?, CURDATE(), ?)', [$id, 'CANCELACION']);
            $cancelacion = $r[1][0] ?? null;
        }
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

$pend = function (array $c): float {
    return (float) $c['capital_pend'] + (float) $c['interes_pend'] + (float) $c['iva_pend'] + (float) $c['punit_pend'] + (float) $c['ivap_pend'];
};
$saldoCap  = array_sum(array_map(function ($c) { return (float) $c['capital_pend']; }, $cuotas));
$pagado    = array_sum(array_map(function ($c) { return (float) $c['total_imputado']; }, $cuotas));
$vencidas  = array_filter($cuotas, function ($c) use ($pend) { return $c['vencimiento'] < date('Y-m-d') && $pend($c) > 0.004; });
$diasAtraso = $vencidas ? (int) floor((time() - strtotime(min(array_column($vencidas, 'vencimiento')))) / 86400) : 0;
$pagosAplicados = array_filter($pagos, function ($x) { return $x['estado'] === 'APLICADO'; });
$ctl = $p && $p['controles'] ? (json_decode($p['controles'], true) ?: []) : [];

// ---------------------------------------------------------------------
// PDF: plan de pagos
// ---------------------------------------------------------------------
if ($p && get_str('export') === 'pdf') {
    require_once __DIR__ . '/../inc/pdf.php';
    $rep = new PdfReporte('Préstamo ' . $p['nro_prestamo'] . ' · plan de pagos',
                          $p['denominacion'] . ' · CUIT/CUIL ' . cuit_fmt($p['cuit']) . ' · ' . $p['tipo_asociado'], false);
    $rep->indicadores([
        ['Capital', money($p['capital'])],
        ['Cuotas', (int) $p['plazo'] . ' de ' . money($p['valor_cuota'])],
        ['Neto desembolsado', money($p['neto_desembolso'])],
    ]);
    $tea = pow(1 + (float) $p['tna'] / 12, 12) - 1;
    $rep->nota('Liquidado el ' . fecha($p['fecha_liquidacion']) . ' · primer vencimiento ' . fecha($p['fecha_primer_vto'])
             . ' · TNA ' . pct($p['tna']) . ' · TEA ' . pct($tea) . ' · IVA sobre intereses ' . pct($p['alicuota_iva'], 0)
             . ' · gastos descontados ' . money($p['gastos_otorg'])
             . ((float) ($p['gastos_financiados'] ?? 0) > 0 ? ' · gastos financiados en las cuotas ' . money($p['gastos_financiados']) : ''));
    if ($gastosPre) {
        $rep->seccion('Gastos', 'congelados al liquidar');
        $rep->tabla(
            [['Concepto', 3.2, 'L'], ['Cálculo', 2.2, 'L'], ['Cómo se cobró', 2.2, 'L'], ['Importe', 1.5, 'R'], ['IVA', 1.2, 'R'], ['Total', 1.5, 'R']],
            array_map(function ($g) {
                return [$g['nombre'], gasto_calculo_txt($g), $g['forma_cobro'] === 'FINANCIADO' ? 'En las cuotas' : 'Descontado',
                        money($g['importe']), money($g['iva']), ['t' => money($g['total']), 'negrita' => true]];
            }, $gastosPre),
            ['Total', '', '', '', '', money(array_sum(array_column($gastosPre, 'total')))]
        );
    }
    $rep->seccion('Cuadro de cuotas', 'sistema francés');
    $rep->tabla(
        [['N°', 0.5, 'R'], ['Vencimiento', 1.1, 'L'], ['Capital', 1.2, 'R'], ['Interés', 1.2, 'R'], ['IVA', 1, 'R'],
         ['Cuota', 1.3, 'R'], ['Saldo de capital', 1.3, 'R'], ['Estado', 1, 'L']],
        array_map(function ($c) {
            $e = $c['estado'];
            return [(int) $c['nro_cuota'], fecha($c['vencimiento']), money($c['capital']), money($c['interes']), money($c['iva_interes']),
                    money((float) $c['capital'] + (float) $c['interes'] + (float) $c['iva_interes']),
                    money($c['saldo_capital'] ?? 0), ['t' => ucfirst(strtolower($e)), 'color' => $e === 'PAGADA' ? PdfReporte::OK : PdfReporte::TEXTO]];
        }, q('SELECT c.*, s.estado FROM cuotas c JOIN v_cuotas_saldo s ON s.id_cuota = c.id_cuota WHERE c.id_prestamo = ? ORDER BY c.nro_cuota', [$id])),
        ['', 'TOTAL', money($p['capital']), money($p['total_intereses']), money($p['total_iva']),
         money((float) $p['capital'] + (float) $p['total_intereses'] + (float) $p['total_iva']), '', '']
    );
    if ($p['estado'] === 'VIGENTE' && $hoy) {
        $rep->seccion('Situación al ' . date('d/m/Y'));
        $rep->tabla([['Concepto', 3, 'L'], ['Importe', 1.4, 'R']], [
            ['Deuda vencida (con punitorios)', money($hoy['deuda_vencida'] ?? 0)],
            ['Deuda a vencer', money($hoy['deuda_a_vencer'] ?? 0)],
            ['Monto para cancelar hoy', money($cancelacion['total'] ?? 0)],
        ], null, 0.6);
    }
    $rep->nota('Los punitorios se calculan sobre las cuotas vencidas a una TNA de ' . pct($p['tasa_punitoria']) . ' más IVA.');
    $rep->enviar('prestamo_' . $p['nro_prestamo'] . '.pdf');
    exit;
}

if (!$p && !$errores) {
    http_response_code(404);
}
layout_inicio($p ? 'Préstamo ' . $p['nro_prestamo'] : 'Préstamo', 'prestamos');

if (!$p) {
    mensajes($errores);
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese préstamo</h1><p>Revisá el número o buscalo en el listado.</p>'
       . boton('Ir a préstamos', 'primary', 'prestamos.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

titulo('Préstamo ' . $p['nro_prestamo'],
       $p['denominacion'] . ' · ' . cuit_fmt($p['cuit']) . ' · ' . $p['tipo_asociado'],
       boton('Plan de pagos PDF', 'pdf', 'prestamo.php?id=' . $id . '&export=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener'])
       . ($p['estado'] === 'VIGENTE' ? boton('Cobrar en efectivo', 'ok', 'cobrar.php?prestamo=' . $id, 'dinero') : ''),
       '<a href="prestamos.php">Préstamos</a> / <a href="cliente.php?id=' . (int) $p['id_cliente'] . '">' . h($p['denominacion']) . '</a> / ' . h($p['nro_prestamo']));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Estado</span><?= estado_prestamo($p['estado']) ?>
    <span class="bcra-tarjeta-sub"><?= $p['estado'] === 'VIGENTE' ? ($vencidas ? count($vencidas) . ' cuota' . (count($vencidas) === 1 ? '' : 's') . ' vencida' . (count($vencidas) === 1 ? '' : 's') . ' · ' . $diasAtraso . ' días' : 'al día') : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Capital</span><strong><?= money($p['capital']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= (int) $p['plazo'] ?> cuotas de <?= money($p['valor_cuota']) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo de capital</span><strong><?= money($p['estado'] === 'ANULADO' ? 0 : $saldoCap) ?></strong>
    <span class="bcra-tarjeta-sub">cobrado <?= money($pagado) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl"><?= $hoy ? 'Deuda vencida hoy' : 'Deuda' ?></span>
    <strong><?= $hoy ? money($hoy['deuda_vencida'] ?? 0) : '—' ?></strong>
    <span class="bcra-tarjeta-sub"><?= $cancelacion ? 'cancelación hoy ' . money($cancelacion['total'] ?? 0) : '' ?></span></div>
</div>

<?php if ($p['estado'] === 'ANULADO'): ?>
  <?php alerta('Anulado el ' . date('d/m/Y H:i', strtotime((string) $p['anulado_el'])) . ': ' . $p['motivo_anulacion'], 'warning'); ?>
<?php endif; ?>

<div class="grilla-2">
  <div class="card">
    <?= card_head('Datos') ?>
    <dl class="datos-lista">
      <dt>Cliente</dt><dd><a href="cliente.php?id=<?= (int) $p['id_cliente'] ?>"><?= h($p['denominacion']) ?></a> <span class="mono"><?= h(cuit_fmt($p['cuit'])) ?></span></dd>
      <dt>Tipo de asociado</dt><dd><?= h($p['tipo_asociado']) ?></dd>
      <dt>Liquidación</dt><dd><?= h(fecha($p['fecha_liquidacion'])) ?> · primer vencimiento <?= h(fecha($p['fecha_primer_vto'])) ?></dd>
      <dt>Tasas</dt><dd>TNA <?= pct($p['tna']) ?> · TEA <?= pct(pow(1 + (float) $p['tna'] / 12, 12) - 1) ?> · punitoria <?= pct($p['tasa_punitoria']) ?> · IVA <?= pct($p['alicuota_iva'], 0) ?></dd>
      <?php $ctaDes = !empty($p['id_cuenta_fondos']) ? q1('SELECT id_cuenta_fondos, nombre FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [(int) $p['id_cuenta_fondos']]) : null; ?>
      <dt>Desembolso</dt><dd><?= money($p['neto_desembolso']) ?> (gastos descontados <?= money($p['gastos_otorg']) ?>)<?php if ($ctaDes): ?>
        <span class="td-muted">· <?= h(strtolower(['TRANSFERENCIA' => 'Transferencia', 'CHEQUE' => 'Cheque', 'EFECTIVO' => 'Efectivo'][$p['medio_desembolso']] ?? (string) $p['medio_desembolso'])) ?>
        <?= $p['referencia_desembolso'] ? ($p['medio_desembolso'] === 'CHEQUE' ? 'N° ' : '') . h($p['referencia_desembolso']) . ' ' : '' ?>desde
        <?= puede('movimientos_fondos.php') ? '<a href="movimientos_fondos.php?cuenta=' . (int) $ctaDes['id_cuenta_fondos'] . '">' . h($ctaDes['nombre']) . '</a>' : h($ctaDes['nombre']) ?></span><?php endif; ?>
        <?php
          // Con órdenes de pago: la orden del desembolso y, si se pagó, cómo.
          $opDes = null;
          try {
              $opDes = q1("SELECT o.id_orden, o.nro_orden, o.estado, g.nro_pago, g.fecha, g.medio, g.cheque_numero, c.nombre AS cuenta
                             FROM ordenes_pago o
                             LEFT JOIN pagos_emitidos g ON g.id_orden = o.id_orden AND g.estado = 'EMITIDO'
                             LEFT JOIN cuentas_fondos c ON c.id_cuenta_fondos = g.id_cuenta_fondos
                            WHERE o.id_prestamo = ?", [(int) $p['id_prestamo']]);
          } catch (Throwable $e) {
          }
          if ($opDes):
            $opTxt = ['APROBADA' => 'a pagar', 'PAGADA' => 'pagada', 'ANULADA' => 'anulada'][$opDes['estado']] ?? strtolower($opDes['estado']);
        ?>
        <span class="segunda-linea">Orden de pago <?= puede('ordenes_pago.php') ? '<a href="ordenes_pago.php?id=' . (int) $opDes['id_orden'] . '">' . h($opDes['nro_orden']) . '</a>' : h($opDes['nro_orden']) ?>
          · <?= h($opTxt) ?><?= $opDes['nro_pago'] ? ' con el pago ' . h($opDes['nro_pago']) . ' del ' . h(fecha($opDes['fecha'])) . ', '
              . h(strtolower(['TRANSFERENCIA' => 'Transferencia', 'CHEQUE' => 'Cheque N° ' . $opDes['cheque_numero'], 'EFECTIVO' => 'Efectivo'][$opDes['medio']] ?? $opDes['medio'])) . ' desde ' . h($opDes['cuenta']) : '' ?></span>
        <?php endif; ?></dd>
      <?php if ($gastosPre): ?>
      <dt>Gastos</dt><dd><?php foreach ($gastosPre as $g): ?><?= h($g['nombre']) ?> <?= money($g['total']) ?>
          <span class="td-muted">(<?= h(gasto_calculo_txt($g)) ?><?= (float) $g['iva'] ? ', con IVA' : '' ?> · <?= $g['forma_cobro'] === 'FINANCIADO' ? 'en las cuotas' : 'descontado' ?>)</span><br><?php endforeach; ?></dd>
      <?php endif; ?>
      <?php if ((float) ($p['gastos_financiados'] ?? 0) > 0): ?>
      <dt>Capital de las cuotas</dt><dd><?= money((float) $p['capital'] + (float) $p['gastos_financiados']) ?>
        <span class="td-muted">(<?= money($p['capital']) ?> + gastos financiados <?= money($p['gastos_financiados']) ?>)</span></dd>
      <?php endif; ?>
      <dt>Total a pagar</dt><dd><?= money((float) $p['capital'] + (float) ($p['gastos_financiados'] ?? 0) + (float) $p['total_intereses'] + (float) $p['total_iva']) ?>
        <span class="td-muted">(intereses <?= money($p['total_intereses']) ?> · IVA <?= money($p['total_iva']) ?>)</span></dd>
      <dt>Vendedor</dt><dd><?= $p['vendedor'] ? h($p['vendedor']) : '<span class="td-muted">sin vendedor</span>' ?></dd>
      <dt>Comisión</dt><dd><?= $p['comision_monto'] !== null
          ? money($p['comision_monto']) . ' (' . pct($p['comision_pct']) . ($p['regla'] ? ' · ' . h($p['regla']) : ' · manual') . ')'
          : '<span class="td-muted">—</span>' ?></dd>
      <dt>Cargado</dt><dd><?= h(date('d/m/Y H:i', strtotime($p['creado_el']))) ?></dd>
    </dl>
  </div>

  <div class="card">
    <?= card_head('Controles al liquidar', $ctl ? '' : 'no se registraron') ?>
    <div class="bloque">
      <?php if ($ctl): ?>
        <?= controles_html($ctl) ?>
        <?php if ($p['controles_motivo']): ?>
          <p class="controles-motivo"><strong>Se liquidó igual:</strong> <?= h($p['controles_motivo']) ?></p>
        <?php endif; ?>
      <?php else: ?>
        <div class="vacio">Este préstamo se cargó sin los controles (por ejemplo, migrado del sistema anterior).</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <?= card_head('Cuotas', $p['estado'] === 'VIGENTE' ? 'lo pendiente incluye los punitorios ya registrados' : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr>
        <th class="th-right" data-no-sort>N°</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Capital</th>
        <th class="th-right" data-no-sort>Interés</th><th class="th-right" data-no-sort>IVA</th><th class="th-right" data-no-sort>Cuota</th>
        <th class="th-right" data-no-sort>Pagado</th><th class="th-right" data-no-sort>Pendiente</th><th data-no-sort>Estado</th>
      </tr></thead>
      <tbody>
      <?php $tC = $tI = $tV = $tP = $tPe = 0;
        foreach ($cuotas as $c):
          $imp = (float) $c['capital'] + (float) $c['interes'] + (float) $c['iva_interes'];
          $pe = $p['estado'] === 'ANULADO' ? 0 : $pend($c);
          $vencida = $p['estado'] === 'VIGENTE' && $c['vencimiento'] < date('Y-m-d') && $pe > 0.004;
          $tC += (float) $c['capital']; $tI += (float) $c['interes']; $tV += (float) $c['iva_interes']; $tP += (float) $c['total_imputado']; $tPe += $pe;
      ?>
        <tr<?= $vencida ? ' class="fila-vencida"' : '' ?>>
          <td class="td-right"><?= (int) $c['nro_cuota'] ?></td>
          <td><?= h(fecha($c['vencimiento'])) ?></td>
          <td class="td-right"><?= money($c['capital']) ?></td>
          <td class="td-right"><?= money($c['interes']) ?></td>
          <td class="td-right"><?= money($c['iva_interes']) ?></td>
          <td class="td-right td-fuerte"><?= money($imp) ?></td>
          <td class="td-right"><?= (float) $c['total_imputado'] > 0 ? money($c['total_imputado']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= $pe > 0.004 ? money($pe) : '<span class="td-muted">—</span>' ?></td>
          <td><?= $vencida ? estado('Vencida', 'danger') : estado(ucfirst(strtolower($c['estado'])), ESTADOS_CUOTA_TIPO[$c['estado']] ?? 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right"><?= money($tC) ?></td><td class="td-right"><?= money($tI) ?></td>
        <td class="td-right"><?= money($tV) ?></td><td class="td-right"><?= money($tC + $tI + $tV) ?></td>
        <td class="td-right"><?= money($tP) ?></td><td class="td-right"><?= money($tPe) ?></td><td></td></tr></tfoot>
    </table>
  </div>
</div>

<div class="grilla-2">
  <div class="card">
    <?= card_head('Pagos') ?>
    <?= barra_local('prestamo.php?id=' . $id, 'Recibo o medio…') ?>
    <div class="data-table-wrap">
      <table class="data-table" data-local data-paginada data-unidad="pago|pagos">
        <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Fecha</th><th>Recibo</th><th>Medio</th><th class="th-right">Importe</th><th>Estado</th></tr></thead>
        <tbody>
        <?php if (!$pagos): ?><tr><td colspan="6" class="no-data">Sin pagos todavía.</td></tr><?php endif; ?>
        <?php foreach ($pagos as $g): ?>
          <tr<?= $g['estado'] === 'ANULADO' ? ' class="fila-baja"' : '' ?>>
            <td><div class="acciones"><?= btn_accion('ver', 'recibo.php?id=' . (int) $g['id_pago'], 'Ver el recibo') ?></div></td>
            <td data-orden="<?= h((string) $g['fecha']) ?>"><?= h(fecha($g['fecha'])) ?></td><td class="mono"><a href="recibo.php?id=<?= (int) $g['id_pago'] ?>"><?= h($g['nro_recibo'] ?? '') ?></a></td>
            <td><?= h(ucfirst(strtolower($g['medio']))) ?><?= $g['modo'] === 'CANCELACION' ? ' · cancelación' : '' ?></td>
            <td class="td-right" data-orden="<?= (float) $g['importe'] ?>"><?= money($g['importe']) ?></td>
            <td><?= $g['estado'] === 'ANULADO' ? estado('Anulado', 'neutro') : estado('Aplicado', 'ok') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion_local() ?>
  </div>

  <div class="card">
    <?= card_head('Comisión del vendedor') ?>
    <div class="data-table-wrap">
      <table class="data-table" data-sin-orden>
        <thead><tr><th data-no-sort>Fecha</th><th data-no-sort>Concepto</th><th class="th-right" data-no-sort>Base</th><th class="th-right" data-no-sort>%</th>
          <th class="th-right" data-no-sort>Importe</th><th data-no-sort>Liquidación</th></tr></thead>
        <tbody>
        <?php if (!$comisiones): ?><tr><td colspan="6" class="no-data">Sin comisión.</td></tr><?php endif; ?>
        <?php foreach ($comisiones as $d): ?>
          <tr><td><?= h(fecha($d['fecha'])) ?></td><td><?= h(ucfirst(strtolower($d['concepto']))) ?></td>
            <td class="td-right"><?= money($d['base']) ?></td><td class="td-right"><?= pct($d['porcentaje']) ?></td>
            <td class="td-right"><?= money($d['importe']) ?></td>
            <td><?= $d['id_liquidacion'] ? estado('N° ' . (int) $d['id_liquidacion'] . ' · ' . strtolower((string) $d['liq_estado']), 'info') : '<span class="td-muted">a liquidar</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <?= card_head('Movimientos', 'cuenta corriente del préstamo') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th data-no-sort>Fecha</th><th data-no-sort>Tipo</th><th data-no-sort>Referencia</th>
        <th class="th-right" data-no-sort>Debe</th><th class="th-right" data-no-sort>Haber</th><th class="th-right" data-no-sort>Saldo</th></tr></thead>
      <tbody>
      <?php $s = 0.0; foreach ($movs as $m): $s += (float) $m['importe']; ?>
        <tr><td><?= h(fecha($m['fecha'])) ?></td><td><?= h(TIPOS_MOVIMIENTO[$m['tipo']] ?? ucfirst(strtolower(str_replace('_', ' ', $m['tipo'])))) ?></td>
          <td class="td-wrap"><?= h($m['referencia'] ?? '') ?></td>
          <td class="td-right"><?= (float) $m['importe'] > 0 ? money($m['importe']) : '' ?></td>
          <td class="td-right"><?= (float) $m['importe'] < 0 ? money(-(float) $m['importe']) : '' ?></td>
          <td class="td-right"><?= money($s) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($p['estado'] === 'VIGENTE'): ?>
<div class="card">
  <?= card_head('Anular el préstamo', $pagosAplicados ? 'tiene pagos: no se puede anular' : 'solo para errores de carga') ?>
  <?php if (!$pagosAplicados): ?>
    <form method="post" action="prestamo.php?id=<?= $id ?>" class="bloque" data-confirmar="Se anula el préstamo <?= h($p['nro_prestamo']) ?> de <?= h($p['denominacion']) ?>. El saldo queda en cero y se revierte la comisión. No se puede deshacer."
          data-confirmar-titulo="Anular el préstamo" data-confirmar-boton="Anular" data-peligro>
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="anular">
      <div class="campos">
        <div class="campo" style="--span:9"><label for="motivo">Motivo <span class="req">*</span></label>
          <input id="motivo" name="motivo" maxlength="200" required minlength="5" placeholder="Ej.: se cargó con el monto equivocado"></div>
        <div class="campo campo-boton" style="--span:3"><span class="campo-label">&nbsp;</span>
          <?= boton('Anular préstamo', 'danger', null, 'borrar', true) ?></div>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
