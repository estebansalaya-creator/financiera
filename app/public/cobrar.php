<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';

// ---------------------------------------------------------------------
// Cobrar un préstamo
//   cobrar.php?q=texto            busca por N° de préstamo, CUIT, DNI o nombre
//   cobrar.php?prestamo=N&fecha=  estado de cuenta a esa fecha y el pago
//   POST                          sp_registrar_pago y va al recibo
// Normal: puede ser parcial y se imputa de la cuota más vieja a la más
// nueva (punitorios, IVA, interés, capital). Cancelación: el importe tiene
// que ser exacto; se condona el interés futuro.
// ---------------------------------------------------------------------

$errores = [];
$q       = trim(get_str('q'));
$idP     = (int) (es_post() ? post_str('prestamo') : get_str('prestamo'));
$fecha   = leer_fecha(es_post() ? post_str('fecha') : get_str('fecha')) ?? date('Y-m-d');
$form    = ['importe' => '', 'medio' => 'EFECTIVO', 'referencia' => '', 'modo' => 'NORMAL'];

if (es_post()) {
    csrf_exigir();
    $form = ['importe' => post_str('importe'), 'medio' => post_str('medio'), 'referencia' => post_str('referencia'),
             'modo' => post_str('modo') === 'CANCELACION' ? 'CANCELACION' : 'NORMAL'];
    $imp = leer_num($form['importe']);
    if ($idP <= 0) {
        $errores[] = 'Elegí el préstamo.';
    }
    if ($imp === null || $imp <= 0) {
        $errores[] = 'Escribí el importe cobrado.';
    }
    if (!isset(MEDIOS_PAGO[$form['medio']])) {
        $errores[] = 'Elegí el medio de pago.';
    }
    if ($fecha > date('Y-m-d')) {
        $errores[] = 'La fecha del pago no puede ser futura.';
    }
    if (!$errores) {
        try {
            $idPago = registrar_pago($idP, $fecha, $imp, $form['medio'], substr($form['referencia'], 0, 100), $form['modo']);
            $rec = q1('SELECT nro_recibo FROM pagos WHERE id_pago = ?', [$idPago]);
            flash('ok', 'Pago registrado: recibo ' . ($rec['nro_recibo'] ?? $idPago) . ' por ' . money($imp) . '.');
            redirigir('recibo.php?id=' . $idPago);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
        }
    }
}

$encontrados = [];
$p = null;
$ec = $canc = null;
try {
    if ($idP <= 0 && $q !== '') {
        $encontrados = buscar_prestamos($q);
        $vig = array_values(array_filter($encontrados, function ($x) { return $x['estado'] === 'VIGENTE'; }));
        if (count($vig) === 1) {
            $idP = (int) $vig[0]['id_prestamo'];
        } elseif (!$encontrados) {
            $errores[] = 'No se encontró ningún préstamo con «' . $q . '».';
        }
    }
    if ($idP > 0) {
        $p = q1('SELECT p.*, c.denominacion, c.cuit, pr.nombre AS producto
                   FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
                   JOIN productos pr ON pr.id_producto = p.id_producto WHERE p.id_prestamo = ?', [$idP]);
        if ($p && $p['estado'] === 'VIGENTE') {
            if ($fecha < $p['fecha_liquidacion']) {
                $fecha = $p['fecha_liquidacion'];
            }
            $ec   = estado_cuenta($idP, $fecha, 'NORMAL');
            $canc = estado_cuenta($idP, $fecha, 'CANCELACION');
        }
    }
} catch (Throwable $e) {
    $errores[] = mensaje_bd($e);
}

// Próxima cuota: la primera con saldo
$proxima = null;
foreach ($ec['cuotas'] ?? [] as $c) {
    if ((float) $c['total'] > 0) {
        $proxima = $c;
        break;
    }
}
$ultimoPago = $p ? q1("SELECT fecha FROM pagos WHERE id_prestamo = ? AND estado = 'APLICADO' ORDER BY fecha DESC LIMIT 1", [$idP]) : null;

layout_inicio('Cobrar', 'cobranzas');
titulo('Cobrar', $p ? $p['denominacion'] . ' · préstamo ' . $p['nro_prestamo'] : 'Buscá el préstamo por número, CUIT, DNI o nombre',
       boton('Importar lote', 'secondary', 'lote.php', 'planilla') . boton('Cobranzas', 'secondary', 'cobranzas.php', 'dinero'),
       '<a href="cobranzas.php">Cobranzas</a> / Cobrar');
mensajes($errores);
?>

<div class="card">
  <form method="get" action="cobrar.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Préstamo</span>
      <input type="search" name="q" value="<?= h($q !== '' ? $q : ($p['nro_prestamo'] ?? '')) ?>" class="filter-input" placeholder="N° de préstamo, CUIT, DNI o nombre…" <?= $p ? '' : 'autofocus' ?>></label>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Buscar</button>
  </form>
</div>

<?php if (!$p && $encontrados): ?>
<div class="card">
  <?= card_head('Elegí el préstamo', count($encontrados) . ' encontrados') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>N°</th><th data-no-sort>Cliente</th><th data-no-sort>Producto</th>
        <th data-no-sort>Liquidación</th><th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Cuota</th><th data-no-sort>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($encontrados as $x): ?>
        <tr>
          <td><div class="acciones"><?php if ($x['estado'] === 'VIGENTE'): ?>
            <a class="btn btn-ok btn-sm" href="cobrar.php?prestamo=<?= (int) $x['id_prestamo'] ?>"><?= icono('dinero') ?>Cobrar</a>
          <?php else: ?><?= btn_accion('ver', 'prestamo.php?id=' . $x['id_prestamo'], 'Ver el préstamo') ?><?php endif; ?></div></td>
          <td class="mono"><?= h($x['nro_prestamo']) ?></td>
          <td><?= h($x['denominacion']) ?><span class="segunda-linea mono"><?= h(cuit_fmt($x['cuit'])) ?></span></td>
          <td><?= h($x['producto']) ?></td><td><?= h(fecha($x['fecha_liquidacion'])) ?></td>
          <td class="td-right"><?= money($x['capital']) ?></td><td class="td-right"><?= money($x['valor_cuota']) ?></td>
          <td><?= estado_prestamo($x['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($p && $p['estado'] !== 'VIGENTE'): ?>
  <?php alerta('El préstamo ' . $p['nro_prestamo'] . ' está ' . strtolower(ESTADOS_PRESTAMO[$p['estado']] ?? $p['estado']) . ': no admite pagos.', 'warning'); ?>
<?php endif; ?>

<?php if ($p && $ec): ?>
<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Deuda vencida</span><strong><?= money($ec['tot']['deuda_vencida']) ?></strong>
    <span class="bcra-tarjeta-sub">con punitorios al <?= h(fecha($fecha)) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Próxima cuota</span><strong><?= $proxima ? money($proxima['total']) : '—' ?></strong>
    <span class="bcra-tarjeta-sub"><?= $proxima ? 'cuota ' . (int) $proxima['nro_cuota'] . ' · vence ' . fecha($proxima['vencimiento']) : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Deuda total</span><strong><?= money($ec['tot']['total']) ?></strong>
    <span class="bcra-tarjeta-sub">vencida + a vencer</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cancelación total</span><strong><?= money($canc['tot']['total']) ?></strong>
    <span class="bcra-tarjeta-sub">ahorra <?= money($canc['tot']['interes_condonado']) ?> de interés futuro<?=
      $canc['tot']['credito_interes_pagado'] > 0 ? ' y descuenta ' . money($canc['tot']['credito_interes_pagado']) . ' de interés pagado por adelantado' : '' ?></span></div>
</div>

<div class="columnas-2-1">
  <div class="card">
    <?= card_head('Estado de cuenta al ' . fecha($fecha), $p['nro_prestamo'] . ' · ' . $p['producto'],
          btn_accion('ver', 'prestamo.php?id=' . $idP, 'Ver el préstamo')) ?>
    <div class="data-table-wrap">
      <table class="data-table" data-sin-orden>
        <thead><tr><th class="th-right" data-no-sort>Cuota</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Atraso</th>
          <th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Interés + IVA</th>
          <th class="th-right" data-no-sort>Punitorios + IVA</th><th class="th-right" data-no-sort>Total</th></tr></thead>
        <tbody>
        <?php foreach ($ec['cuotas'] as $c): $venc = (int) $c['dias_atraso'] > 0; ?>
          <tr<?= $venc ? ' class="fila-vencida"' : '' ?>>
            <td class="td-right"><?= (int) $c['nro_cuota'] ?></td><td><?= h(fecha($c['vencimiento'])) ?></td>
            <td class="td-right"><?= $venc ? (int) $c['dias_atraso'] . ' días' : '<span class="td-muted">—</span>' ?></td>
            <td class="td-right"><?= money($c['capital']) ?></td>
            <td class="td-right"><?= money((float) $c['interes'] + (float) $c['iva_interes']) ?></td>
            <td class="td-right"><?= (float) $c['punitorio'] + (float) $c['iva_punitorio'] > 0 ? money((float) $c['punitorio'] + (float) $c['iva_punitorio']) : '<span class="td-muted">—</span>' ?></td>
            <td class="td-right td-fuerte"><?= money($c['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ec['cuotas']): ?><tr><td colspan="7" class="no-data">No debe nada.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row"><td colspan="6">TOTAL</td><td class="td-right"><?= money($ec['tot']['total']) ?></td></tr></tfoot>
      </table>
    </div>
  </div>

  <div class="card">
    <?= card_head('Registrar pago') ?>
    <form method="post" action="cobrar.php" id="frm-pago" data-confirmar="" data-confirmar-titulo="Registrar el pago" data-confirmar-boton="Registrar">
      <?= csrf_campo() ?>
      <input type="hidden" name="prestamo" value="<?= $idP ?>">
      <div class="bloque">
        <div class="campos">
          <div class="campo" style="--span:12">
            <span class="campo-label">Tipo de pago</span>
            <div class="radios">
              <label><input type="radio" name="modo" value="NORMAL" <?= $form['modo'] === 'NORMAL' ? 'checked' : '' ?>> Pago a cuenta</label>
              <label><input type="radio" name="modo" value="CANCELACION" <?= $form['modo'] === 'CANCELACION' ? 'checked' : '' ?>> Cancelación total</label>
            </div>
          </div>
          <div class="campo" style="--span:6">
            <label for="fecha">Fecha del pago</label>
            <input type="date" id="fecha" name="fecha" value="<?= h($fecha) ?>" max="<?= date('Y-m-d') ?>"
                   min="<?= h($ultimoPago['fecha'] ?? $p['fecha_liquidacion']) ?>">
            <span class="campo-ayuda">al cambiarla se recalcula la deuda</span>
          </div>
          <div class="campo" style="--span:6">
            <label for="importe">Importe <span class="req">*</span></label>
            <input id="importe" name="importe" value="<?= h($form['importe']) ?>" inputmode="decimal" class="derecha" required placeholder="0,00">
            <span class="campo-ayuda" id="importe-ayuda"></span>
          </div>
          <div class="campo" style="--span:12">
            <div class="atajos">
              <?php if ($ec['tot']['deuda_vencida'] > 0): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-importe="<?= h(monto_campo_txt($ec['tot']['deuda_vencida'])) ?>">Deuda vencida <?= money($ec['tot']['deuda_vencida']) ?></button>
              <?php endif; ?>
              <?php if ($proxima): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-importe="<?= h(monto_campo_txt((float) $proxima['total'])) ?>">Cuota <?= (int) $proxima['nro_cuota'] ?> · <?= money($proxima['total']) ?></button>
              <?php endif; ?>
            </div>
          </div>
          <div class="campo" style="--span:6">
            <label for="medio">Medio</label>
            <select id="medio" name="medio"><?= opciones(MEDIOS_PAGO, $form['medio']) ?></select>
          </div>
          <div class="campo" style="--span:6">
            <label for="referencia">Referencia</label>
            <input id="referencia" name="referencia" value="<?= h($form['referencia']) ?>" maxlength="100" placeholder="N° de operación, cheque…">
          </div>
        </div>
      </div>
      <div class="form-acciones">
        <?= boton('Registrar pago', 'ok', null, 'guardar', true) ?>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('frm-pago'), imp = document.getElementById('importe'),
      ayuda = document.getElementById('importe-ayuda'), fecha = document.getElementById('fecha');
  var cancelacion = <?= json_encode(monto_campo_txt($canc['tot']['total'])) ?>, total = <?= json_encode($ec['tot']['total']) ?>;
  function modo() { return form.querySelector('input[name=modo]:checked').value; }
  function aplicar() {
    var c = modo() === 'CANCELACION';
    if (c) imp.value = cancelacion;
    imp.readOnly = c;
    ayuda.textContent = c ? 'monto exacto para cancelar el ' + fecha.value.split('-').reverse().join('/') : 'puede ser parcial; se imputa a la cuota más vieja';
  }
  form.querySelectorAll('input[name=modo]').forEach(function (r) { r.addEventListener('change', function () { aplicar(); texto(); }); });
  form.querySelectorAll('[data-importe]').forEach(function (b) {
    b.addEventListener('click', function () {
      form.querySelector('input[value=NORMAL]').checked = true; aplicar(); imp.value = b.getAttribute('data-importe'); imp.focus();
    });
  });
  fecha.addEventListener('change', function () {
    location.href = 'cobrar.php?prestamo=<?= $idP ?>&fecha=' + encodeURIComponent(fecha.value);
  });
  // El diálogo de confirmación (app.js) muestra lo que se va a registrar
  function texto() {
    var m = document.getElementById('medio');
    form.setAttribute('data-confirmar', (modo() === 'CANCELACION' ? 'Cancelación total' : 'Pago') + ' de $ ' + (imp.value || '0')
      + ' en ' + m.options[m.selectedIndex].text.toLowerCase() + ', con fecha ' + fecha.value.split('-').reverse().join('/') + '.');
  }
  form.addEventListener('input', texto);
  form.addEventListener('change', texto);
  form.querySelectorAll('[data-importe]').forEach(function (b) { b.addEventListener('click', texto); });
  aplicar();
  texto();
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
