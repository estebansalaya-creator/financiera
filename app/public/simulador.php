<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Simulador de préstamos
//   Llama a sp_simular_prestamo (el mismo motor que la liquidación: lo que
//   se simula es lo que se liquida). Devuelve el resumen y el cuadro.
//   El CFT se calcula acá: la tasa mensual que iguala lo que el cliente
//   recibe (neto de gastos) con las cuotas que paga (con IVA), en períodos
//   mensuales iguales; es una aproximación al CFT del BCRA, que cuenta días.
//   ?export=pdf  la simulación para entregar al cliente
//   ?export=xlsx el cuadro en Excel
// ---------------------------------------------------------------------

$errores  = [];
$res      = null;
$cuadro   = [];
$tipo = null;

$fTipo   = (int) get_str('tipo');
$fMonto  = get_str('monto');
$fPlazo  = get_str('plazo');
$fFecha  = get_str('fecha') ?: date('Y-m-d');
$fPrimer = get_str('primer_vto');
$simular = get_str('tipo') !== '';

/** TIR mensual de: +neto en t0 y −cuota_i en cada mes (bisección, sin depender de una semilla). */
function tir_mensual(float $neto, array $cuotas): ?float
{
    if ($neto <= 0 || !$cuotas) {
        return null;
    }
    $vpn = function (float $i) use ($neto, $cuotas) {
        $s = $neto;
        foreach ($cuotas as $k => $c) {
            $s -= $c / pow(1 + $i, $k + 1);
        }
        return $s;
    };
    $lo = 0.0;
    $hi = 1.0;
    if ($vpn($lo) > 0) {
        return 0.0;           // se devuelve menos de lo que se recibe
    }
    for ($n = 0; $n < 200 && $vpn($hi) < 0; $n++) {
        $hi *= 2;
    }
    for ($n = 0; $n < 200; $n++) {
        $mid = ($lo + $hi) / 2;
        if ($vpn($mid) < 0) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return ($lo + $hi) / 2;
}

try {
    $tipos = q(
        "SELECT p.*, EXISTS(SELECT 1 FROM tasas_vigencia t WHERE t.id_tipo_asociado = p.id_tipo_asociado
                             AND t.vigencia_desde <= CURDATE()) AS con_tasa
           FROM tipos_asociado p
          WHERE p.activo = 1
          ORDER BY p.nombre"
    );
    foreach ($tipos as $p) {
        if ((int) $p['id_tipo_asociado'] === $fTipo) {
            $tipo = $p;
        }
    }

    if ($simular) {
        $monto  = leer_num($fMonto);
        $plazo  = leer_num($fPlazo);
        $fecha  = leer_fecha($fFecha);
        $primer = $fPrimer === '' ? null : leer_fecha($fPrimer);
        if (!$tipo) {
            $errores[] = 'Elegí el tipo de asociado.';
        }
        if ($monto === null || $monto <= 0) {
            $errores[] = 'Escribí el monto a prestar.';
        }
        if ($plazo === null || $plazo < 1 || floor($plazo) != $plazo) {
            $errores[] = 'El plazo va en cuotas enteras.';
        }
        if ($fecha === null) {
            $errores[] = 'La fecha de liquidación no es válida.';
        }
        if ($fPrimer !== '' && ($primer === null || ($fecha !== null && $primer <= $fecha))) {
            $errores[] = 'El primer vencimiento tiene que ser posterior a la fecha de liquidación.';
        }
        if (!$errores) {
            try {
                $st = db()->prepare('CALL sp_simular_prestamo(?, ?, ?, ?, ?)');
                $st->execute([$fTipo, round($monto, 2), (int) $plazo, $fecha, $primer]);
                $res = $st->fetch();
                $st->nextRowset();
                $cuadro = $st->fetchAll();
                while ($st->nextRowset()) {
                }
                $st->closeCursor();
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
                $res = null;
            }
        }
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $tipos = [];
}

$cft = null;
if ($res && $cuadro) {
    $i = tir_mensual((float) $res['neto_desembolso'], array_map(function ($c) { return (float) $c['importe']; }, $cuadro));
    if ($i !== null) {
        $cft = ['tem' => $i, 'tna' => $i * 12, 'tea' => pow(1 + $i, 12) - 1];
    }
}

// ---------------------------------------------------------------------
// Exportaciones
// ---------------------------------------------------------------------
$exp = get_str('export');
if ($res && in_array($exp, ['pdf', 'xlsx'], true)) {
    $nombreArchivo = 'simulacion_' . strtolower($tipo['codigo']) . '_' . (int) $res['plazo'] . 'c_' . date('Ymd');
    $lineas = [
        ['Tipo de asociado', $tipo['nombre']],
        ['Monto solicitado', money($res['capital'])],
        ['Plazo', (int) $res['plazo'] . ' cuotas mensuales'],
        ['Valor de la cuota (1ra)', money($res['valor_cuota'])],
        ['Gastos de otorgamiento', money($res['gastos_otorg'])],
        ['Neto a recibir', money($res['neto_desembolso'])],
        ['Total de intereses', money($res['total_intereses'])],
        ['IVA sobre intereses', money($res['total_iva'])],
        ['Total a pagar', money($res['total_a_pagar'])],
        ['TNA', pct($res['tna'])],
        ['TEM', pct($res['tem'], 3)],
        ['TEA', pct($res['tea'])],
    ];
    if ($cft) {
        $lineas[] = ['CFT TEA con IVA (aprox.)', pct($cft['tea'])];
    }

    if ($exp === 'pdf') {
        require_once __DIR__ . '/../inc/pdf.php';
        $rep = new PdfReporte('Simulación de préstamo',
                              $tipo['nombre'] . ' · liquidación ' . fecha($fFecha) . ' · ' . (int) $res['plazo'] . ' cuotas', false);
        $rep->indicadores([
            ['Monto solicitado', money($res['capital'])],
            ['Primera cuota', money($res['valor_cuota'])],
            ['Total a pagar', money($res['total_a_pagar'])],
        ]);
        $rep->indicadores([
            ['Neto a recibir', money($res['neto_desembolso'])],
            ['TNA / TEA', pct($res['tna']) . ' / ' . pct($res['tea'])],
            ['CFT TEA c/IVA', $cft ? pct($cft['tea']) : '—'],
        ]);
        $rep->seccion('Cuadro de cuotas', 'sistema francés · IVA ' . pct($tipo['alicuota_iva'], 0) . ' sobre intereses');
        $rep->tabla(
            [['N°', 0.6, 'R'], ['Vencimiento', 1.4, 'L'], ['Capital', 1.6, 'R'], ['Interés', 1.5, 'R'],
             ['IVA', 1.3, 'R'], ['Cuota', 1.6, 'R'], ['Saldo de capital', 1.8, 'R']],
            array_map(function ($c) {
                return [(string) $c['nro_cuota'], fecha($c['vencimiento']), money($c['capital']), money($c['interes']),
                        money($c['iva_interes']), ['t' => money($c['importe']), 'negrita' => true], money($c['saldo_capital'])];
            }, $cuadro),
            ['', 'Total', money($res['capital']), money($res['total_intereses']), money($res['total_iva']),
             money($res['total_a_pagar']), '']
        );
        $rep->nota('Simulación sin valor contractual: las condiciones finales dependen de la aprobación crediticia y de la tasa vigente al liquidar.');
        $rep->nota('Gastos de otorgamiento (' . pct($tipo['gasto_otorg_pct']) . ' del capital) descontados del desembolso. '
                   . 'CFT aproximado en períodos mensuales iguales.');
        $rep->enviar($nombreArchivo . '.pdf');
        exit;
    }

    require_once __DIR__ . '/../inc/xlsx.php';
    $libro = new XlsxLibro();
    $libro->hoja('Cuadro de cuotas')->titulo('Simulación de préstamo · ' . $tipo['nombre'],
        money($res['capital']) . ' en ' . (int) $res['plazo'] . ' cuotas · TNA ' . pct($res['tna']) . ' · liquidación ' . fecha($fFecha))
        ->tabla(
            [['N°', 'entero', 6], ['Vencimiento', 'fecha', 13], ['Capital', 'moneda', 16], ['Interés', 'moneda', 15],
             ['IVA', 'moneda', 14], ['Cuota', 'moneda', 16], ['Saldo de capital', 'moneda', 18]],
            array_map(function ($c) {
                return [$c['nro_cuota'], $c['vencimiento'], $c['capital'], $c['interes'], $c['iva_interes'], $c['importe'], $c['saldo_capital']];
            }, $cuadro),
            [1 => ['texto' => 'Total'], 2 => ['suma' => true], 3 => ['suma' => true], 4 => ['suma' => true], 5 => ['suma' => true]]
        );
    $libro->hoja('Resumen')->titulo('Simulación de préstamo', 'Generada el ' . date('d/m/Y H:i'))
        ->tabla([['Concepto', 'texto', 28], ['Valor', 'texto', 30]], $lineas);
    $libro->enviar($nombreArchivo . '.xlsx');
    exit;
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
layout_inicio('Simulador', 'simulador');

$acciones = '';
if ($res) {
    $acciones = boton('Exportar a Excel', 'excel', url_con(['export' => 'xlsx']), 'planilla')
              . boton('Generar PDF', 'pdf', url_con(['export' => 'pdf']), 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener']);
}
titulo('Simulador de préstamos', 'Cuota, total a pagar y cuadro de cuotas con la tasa vigente del tipo de asociado', $acciones);
mensajes($errores);

$sinTasa = array_filter($tipos, function ($p) { return !$p['con_tasa']; });
if ($tipos && count($sinTasa) === count($tipos)) {
    alerta('Ningún tipo de asociado activo tiene tasa vigente: cargalas en Tipos de asociado y tasas para poder simular.', 'warning');
}
?>

<div class="card">
  <form method="get" action="simulador.php">
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4">
          <label for="tipo">Tipo de asociado <span class="req">*</span></label>
          <select id="tipo" name="tipo" required>
            <option value="">Elegí…</option>
            <?php foreach ($tipos as $p): ?>
              <option value="<?= (int) $p['id_tipo_asociado'] ?>" <?= (int) $p['id_tipo_asociado'] === $fTipo ? 'selected' : '' ?>
                      data-plazo="<?= h($p['plazo_min'] . ' a ' . $p['plazo_max'] . ' cuotas') ?>"
                      data-monto="<?= h(money($p['monto_min']) . ' a ' . money($p['monto_max'])) ?>">
                <?= h($p['nombre']) ?><?= $p['con_tasa'] ? '' : ' (sin tasa vigente)' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda" id="ayuda-tipo"></span>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto">Monto <span class="req">*</span></label>
          <input id="monto" name="monto" value="<?= h($fMonto) ?>" inputmode="decimal" class="derecha" required placeholder="500.000">
          <span class="campo-ayuda" id="ayuda-monto"></span>
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo">Cuotas <span class="req">*</span></label>
          <input id="plazo" name="plazo" value="<?= h($fPlazo) ?>" inputmode="numeric" class="derecha" required placeholder="12">
          <span class="campo-ayuda" id="ayuda-plazo"></span>
        </div>
        <div class="campo" style="--span:2">
          <label for="fecha">Liquidación</label>
          <input type="date" id="fecha" name="fecha" value="<?= h($fFecha) ?>">
        </div>
        <div class="campo" style="--span:2">
          <label for="primer_vto">Primer vencimiento</label>
          <input type="date" id="primer_vto" name="primer_vto" value="<?= h($fPrimer) ?>">
          <span class="campo-ayuda">vacío: un mes después</span>
        </div>
      </div>
    </div>
    <div class="form-acciones">
      <?php if ($simular): ?><?= boton('Limpiar', 'secondary', 'simulador.php') ?><?php endif; ?>
      <?= boton('Simular', 'primary', null, 'grafico', true) ?>
    </div>
  </form>
</div>

<?php if ($res): ?>
  <div class="kpi-row">
    <div class="kpi-card"><div class="kpi-ico"><?= icono('dinero', 20) ?></div>
      <div><div class="kpi-lbl">Primera cuota</div><div class="kpi-val monto"><?= money($res['valor_cuota']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico"><?= icono('documento', 20) ?></div>
      <div><div class="kpi-lbl">Total a pagar</div><div class="kpi-val monto"><?= money($res['total_a_pagar']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico ok"><?= icono('dinero', 20) ?></div>
      <div><div class="kpi-lbl">Neto a recibir</div><div class="kpi-val monto"><?= money($res['neto_desembolso']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico"><?= icono('grafico', 20) ?></div>
      <div><div class="kpi-lbl">TNA · TEA</div><div class="kpi-val monto"><?= pct($res['tna']) ?> · <?= pct($res['tea']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico warn"><?= icono('grafico', 20) ?></div>
      <div><div class="kpi-lbl">CFT TEA con IVA</div><div class="kpi-val monto"><?= $cft ? pct($cft['tea']) : '—' ?></div></div></div>
  </div>

  <div class="card">
    <?= card_head('Resumen') ?>
    <div class="datos">
      <div><div class="dato-lbl">Monto solicitado</div><div class="dato-val"><?= money($res['capital']) ?></div></div>
      <div><div class="dato-lbl">Gastos de otorgamiento</div><div class="dato-val"><?= money($res['gastos_otorg']) ?></div></div>
      <div><div class="dato-lbl">Neto a recibir</div><div class="dato-val"><?= money($res['neto_desembolso']) ?></div></div>
      <div><div class="dato-lbl">Cuotas</div><div class="dato-val"><?= (int) $res['plazo'] ?> mensuales</div></div>
      <div><div class="dato-lbl">Total de intereses</div><div class="dato-val"><?= money($res['total_intereses']) ?></div></div>
      <div><div class="dato-lbl">IVA s/ intereses</div><div class="dato-val"><?= money($res['total_iva']) ?></div></div>
      <div><div class="dato-lbl">TNA</div><div class="dato-val"><?= pct($res['tna']) ?></div></div>
      <div><div class="dato-lbl">TEM</div><div class="dato-val"><?= pct($res['tem'], 3) ?></div></div>
      <div><div class="dato-lbl">TEA</div><div class="dato-val"><?= pct($res['tea']) ?></div></div>
      <div><div class="dato-lbl">CFT TEA c/IVA</div><div class="dato-val"><?= $cft ? pct($cft['tea']) : '—' ?></div></div>
    </div>
    <div class="bloque" style="border-top:1px solid var(--border)">
      <p class="chico muted">Cada cuota incluye el IVA sobre sus intereses: como el interés baja mes a mes, la cuota total también baja un poco. Los gastos se descuentan del desembolso.
        El CFT es aproximado (períodos mensuales iguales). Simulación sin valor contractual.</p>
    </div>
  </div>
  <div class="card">
    <?= card_head('Cuadro de cuotas', 'sistema francés · ' . (int) $res['plazo'] . ' cuotas mensuales') ?>
    <div class="data-table-wrap">
      <table class="data-table" data-sin-orden>
        <thead>
          <tr>
            <th class="th-right" data-no-sort>N°</th>
            <th data-no-sort>Vencimiento</th>
            <th class="th-right" data-no-sort>Capital</th>
            <th class="th-right" data-no-sort>Interés</th>
            <th class="th-right" data-no-sort>IVA</th>
            <th class="th-right" data-no-sort>Cuota</th>
            <th class="th-right" data-no-sort>Saldo de capital</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($cuadro as $c): ?>
          <tr>
            <td class="td-right"><?= (int) $c['nro_cuota'] ?></td>
            <td><?= h(fecha($c['vencimiento'])) ?></td>
            <td class="td-right"><?= money($c['capital']) ?></td>
            <td class="td-right"><?= money($c['interes']) ?></td>
            <td class="td-right"><?= money($c['iva_interes']) ?></td>
            <td class="td-right td-fuerte"><?= money($c['importe']) ?></td>
            <td class="td-right td-muted"><?= money($c['saldo_capital']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td></td>
            <td>Total</td>
            <td class="td-right"><?= money($res['capital']) ?></td>
            <td class="td-right"><?= money($res['total_intereses']) ?></td>
            <td class="td-right"><?= money($res['total_iva']) ?></td>
            <td class="td-right"><?= money($res['total_a_pagar']) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

<?php endif; ?>

<script>
(function () {
  var sel = document.getElementById('tipo');
  function ayuda() {
    var o = sel.options[sel.selectedIndex];
    document.getElementById('ayuda-plazo').textContent = o && o.dataset.plazo ? o.dataset.plazo : '';
    document.getElementById('ayuda-monto').textContent = o && o.dataset.monto ? o.dataset.monto : '';
  }
  sel.addEventListener('change', ayuda);
  ayuda();
})();
</script>

<?php layout_fin(); ?>
