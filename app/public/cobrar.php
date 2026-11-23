<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Cobros en efectivo
//   cobrar.php                    la grilla de los préstamos vigentes con
//                                 saldo a pagar (cuotas, deuda vencida y a
//                                 vencer), paginada y ordenada en la base;
//                                 cada renglón despliega sus cuotas
//   cobrar.php?q=texto            la grilla filtrada (préstamo, cliente,
//                                 CUIT o DNI)
//   cobrar.php?prestamo=N&fecha=  estado de cuenta a esa fecha y el cobro
//   POST                          sp_registrar_pago (EFECTIVO) y va al recibo
// Solo efectivo: lo que entra por banco se emite en Cobros bancarios (del
// extracto) y lo de las recaudadoras en Importar lote. El cobro entra en
// una caja en pesos (viene la habitual del efectivo: Cuentas de fondos ›
// Cuenta por medio de cobro).
// Normal: puede ser parcial y se imputa de la cuota más vieja a la más
// nueva (punitorios, IVA, interés, capital). Cancelación: el importe tiene
// que ser exacto; se condona el interés futuro.
// El recibo sale con el próximo número del talonario de recibos: el único
// habilitado o el que se elige, si hay varios (Tablas › Talonarios).
// ---------------------------------------------------------------------

const MEDIO_COBRO = 'EFECTIVO';

$errores = [];
$idP     = (int) (es_post() ? post_str('prestamo') : get_str('prestamo'));
$fecha   = leer_fecha(es_post() ? post_str('fecha') : get_str('fecha')) ?? date('Y-m-d');
$form    = ['importe' => '', 'referencia' => '', 'modo' => 'NORMAL', 'cuenta' => '', 'talonario' => ''];

// Los talonarios de recibos; con uno solo, ése.
$tals = [];
try {
    $tals = talonarios_recibo();
} catch (Throwable $e) {
    error_log('[financiera] talonarios de recibos: ' . $e->getMessage());
}
$form['talonario'] = count($tals) === 1 ? (string) array_key_first($tals) : '';

// Tesorería: el efectivo entra en una caja en pesos; viene la habitual del efectivo.
$conFondos = false;
$cajas = [];
try {
    if (fondos_instalado()) {
        $conFondos = true;
        $cajas = array_filter(cuentas_fondos('ARS'), function ($c) { return $c['tipo'] === 'C'; });
        $habitual = cuenta_por_medio()[MEDIO_COBRO] ?? 0;
        $form['cuenta'] = (string) (isset($cajas[$habitual]) ? $habitual : (count($cajas) === 1 ? array_key_first($cajas) : ''));
    }
} catch (Throwable $e) {
    error_log('[financiera] cuentas de fondos: ' . $e->getMessage());
}

if (es_post()) {
    csrf_exigir();
    $form = ['importe' => post_str('importe'), 'referencia' => post_str('referencia'),
             'modo' => post_str('modo') === 'CANCELACION' ? 'CANCELACION' : 'NORMAL', 'cuenta' => post_str('cuenta'),
             'talonario' => post_str('talonario')];
    $imp = leer_num($form['importe']);
    if ($idP <= 0) {
        $errores[] = 'Elegí el préstamo.';
    }
    if ($imp === null || $imp <= 0) {
        $errores[] = 'Escribí el importe cobrado.';
    }
    if ($fecha > date('Y-m-d')) {
        $errores[] = 'La fecha del cobro no puede ser futura.';
    }
    if ($conFondos && !$cajas) {
        $errores[] = 'No hay ninguna caja en pesos habilitada: el efectivo tiene que entrar en una (Tesorería › Cuentas de fondos).';
    } elseif ($conFondos && !isset($cajas[(int) $form['cuenta']])) {
        $errores[] = 'Elegí la caja donde entra el efectivo.';
    }
    $idTal = talonario_recibo_post($tals, $form['talonario'], $errores);
    if (!$errores) {
        try {
            $idPago = registrar_pago($idP, $fecha, $imp, MEDIO_COBRO, substr($form['referencia'], 0, 100), $form['modo'], null,
                                     $conFondos ? (int) $form['cuenta'] : null, $idTal);
            $rec = q1('SELECT nro_recibo FROM pagos WHERE id_pago = ?', [$idPago]);
            flash('ok', 'Cobro en efectivo registrado: recibo ' . ($rec['nro_recibo'] ?? $idPago) . ' por ' . money($imp) . '.');
            redirigir('recibo.php?id=' . $idPago);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
        }
    }
}

// ---------------------------------------------------------------------
// Un préstamo: su estado de cuenta y el cobro
// ---------------------------------------------------------------------
$p = null;
$ec = $canc = null;
try {
    if ($idP > 0) {
        $p = q1('SELECT p.*, c.denominacion, c.cuit, ta.nombre AS tipo_asociado
                   FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
                   JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado WHERE p.id_prestamo = ?', [$idP]);
        if (!$p) {
            $errores[] = 'No existe ese préstamo.';
        } elseif ($p['estado'] === 'VIGENTE') {
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

// ---------------------------------------------------------------------
// La grilla: préstamos vigentes con saldo a pagar
// ---------------------------------------------------------------------
$q      = trim(get_str('q'));
$fDeuda = in_array(get_str('deuda'), ['vencida', 'al_dia'], true) ? get_str('deuda') : '';
$fVend  = (int) get_str('vendedor');
$porPag = por_pagina(50);
$pagina = max(1, (int) get_str('p'));
$hayFiltros = $q !== '' || $fDeuda !== '' || $fVend > 0;
$orden = orden_grilla(['prestamo' => 't.nro_prestamo', 'cliente' => 't.denominacion', 'cuotas' => 't.impagas', 'proximo' => 't.proximo_vto',
                       'vencido' => 't.vencido', 'a_vencer' => 't.a_vencer', 'total' => 't.vencido + t.a_vencer'],
                      'vencido', 'desc', 't.denominacion ASC');
$filas = $cuotas = $vendedores = [];
$tot = ['n' => 0, 'vencido' => 0, 'a_vencer' => 0, 'con_vencido' => 0];
$hoyEfectivo = ['n' => 0, 'importe' => 0];
$total = 0;
$paginas = 1;
if (!$p) {
    $w = ["p.estado = 'VIGENTE'"];
    $par = [];
    if ($q !== '') {
        $dig = preg_replace('/\D/', '', $q);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if ($dig !== '' && strlen($dig) >= 7 && preg_match('/^[\d\s.\-]+$/', $q)) {
            $w[] = '(c.cuit LIKE ? OR EXISTS (SELECT 1 FROM clientes_personas cp WHERE cp.id_cliente = c.id_cliente AND cp.dni = ?))';  // CUIT/CUIL, o DNI
            array_push($par, '%' . $dig . '%', $dig);
        } else {
            $w[] = '(p.nro_prestamo LIKE ? OR c.denominacion LIKE ?)';
            array_push($par, $like, $like);
        }
    }
    if ($fVend > 0) {
        $w[] = 'p.id_vendedor = ?';
        $par[] = $fVend;
    }
    $saldo = 's.capital_pend + s.interes_pend + s.iva_pend + s.punit_pend + s.ivap_pend';
    // Lo vencido es a hoy, sin los punitorios que todavía no se devengaron:
    // el cobro los calcula a la fecha (como Cobros bancarios).
    $sub = "SELECT p.id_prestamo, p.nro_prestamo, p.plazo, p.valor_cuota, ta.nombre AS tipo_asociado,
                   c.id_cliente, c.denominacion, c.cuit, v.nombre AS vendedor,
                   SUM(s.estado = 'PAGADA') AS pagadas,
                   SUM(($saldo) > 0) AS impagas,
                   SUM(($saldo) > 0 AND s.vencimiento < CURDATE()) AS vencidas,
                   SUM(IF(s.vencimiento < CURDATE(), $saldo, 0)) AS vencido,
                   SUM(IF(s.vencimiento >= CURDATE(), $saldo, 0)) AS a_vencer,
                   MIN(IF(($saldo) > 0, s.vencimiento, NULL)) AS proximo_vto
              FROM prestamos p
              JOIN clientes c ON c.id_cliente = p.id_cliente
              JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
              LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
              JOIN v_cuotas_saldo s ON s.id_prestamo = p.id_prestamo
             WHERE " . implode(' AND ', $w) . "
             GROUP BY p.id_prestamo";
    $tw = ['t.vencido + t.a_vencer > 0.004'];
    if ($fDeuda === 'vencida') {
        $tw[] = 't.vencido > 0.004';
    } elseif ($fDeuda === 'al_dia') {
        $tw[] = 't.vencido <= 0.004';
    }
    $desdeSql = "FROM ($sub) t WHERE " . implode(' AND ', $tw);
    try {
        $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
        $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(t.vencido), 0) AS vencido, COALESCE(SUM(t.a_vencer), 0) AS a_vencer,
                          COALESCE(SUM(t.vencido > 0.004), 0) AS con_vencido $desdeSql", $par) ?: $tot;
        $total = (int) $tot['n'];
        $hoyEfectivo = q1("SELECT COUNT(*) AS n, COALESCE(SUM(importe), 0) AS importe FROM recibos
                            WHERE fecha = CURDATE() AND medio = 'EFECTIVO' AND estado = 'APLICADO'") ?: $hoyEfectivo;

        if (get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $todas = q("SELECT t.* $desdeSql ORDER BY {$orden['sql']}", $par);
            $libro = new XlsxLibro();
            $libro->hoja('Préstamos con saldo')->titulo('Préstamos con saldo a pagar al ' . date('d/m/Y'),
                    ($hayFiltros ? 'Filtrado · ' : '') . num($total) . ' préstamos · lo vencido sin los punitorios del día · generado el ' . date('d/m/Y H:i'))
                ->tabla([['Préstamo', 'texto', 12], ['Tipo de asociado', 'texto', 20], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15], ['Comercializador', 'texto', 20],
                         ['Cuotas', 'entero', 8], ['Pagadas', 'entero', 9], ['Impagas', 'entero', 9], ['Vencidas', 'entero', 9],
                         ['Próximo vencimiento', 'fecha', 13], ['Deuda vencida', 'moneda', 15], ['A vencer', 'moneda', 15], ['Total', 'moneda', 15]],
                        array_map(function ($r) {
                            return [$r['nro_prestamo'], $r['tipo_asociado'], $r['denominacion'], cuit_fmt($r['cuit']), (string) $r['vendedor'],
                                    (int) $r['plazo'], (int) $r['pagadas'], (int) $r['impagas'], (int) $r['vencidas'], $r['proximo_vto'],
                                    (float) $r['vencido'], (float) $r['a_vencer'], (float) $r['vencido'] + (float) $r['a_vencer']];
                        }, $todas),
                        [0 => ['texto' => 'TOTAL'], 10 => ['suma' => true], 11 => ['suma' => true], 12 => ['suma' => true]]);
            $libro->enviar('prestamos_con_saldo_' . date('Ymd') . '.xlsx');
            exit;
        }

        $paginas = max(1, (int) ceil($total / $porPag));
        $pagina  = min($pagina, $paginas);
        $filas = q("SELECT t.* $desdeSql ORDER BY {$orden['sql']} LIMIT ? OFFSET ?", array_merge($par, [$porPag, ($pagina - 1) * $porPag]));
        // El detalle de cuotas de los préstamos de la página, de una vez.
        if ($filas) {
            $ids = array_map('intval', array_column($filas, 'id_prestamo'));
            foreach (q("SELECT s.id_prestamo, s.nro_cuota, s.vencimiento, s.estado,
                               s.capital_pend AS capital, s.interes_pend + s.iva_pend AS interes, s.punit_pend + s.ivap_pend AS punitorio,
                               $saldo AS saldo, GREATEST(DATEDIFF(CURDATE(), s.vencimiento), 0) AS atraso
                          FROM v_cuotas_saldo s
                         WHERE s.id_prestamo IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND ($saldo) > 0
                         ORDER BY s.id_prestamo, s.nro_cuota", $ids) as $c) {
                $cuotas[(int) $c['id_prestamo']][] = $c;
            }
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

layout_inicio('Cobros en efectivo', 'cobranzas');
titulo('Cobros en efectivo', $p ? $p['denominacion'] . ' · préstamo ' . $p['nro_prestamo']
                                : 'Los préstamos con saldo a pagar. Elegí uno para registrar lo que el cliente paga en efectivo.',
       ($p ? boton('Volver a la lista', 'secondary', 'cobrar.php', 'volver') : '')
     . (puede('lote.php') ? boton('Importar lote', 'secondary', 'lote.php', 'planilla') : '')
     . (puede('cobranzas.php') ? boton('Recibos emitidos', 'secondary', 'cobranzas.php', 'documento') : ''),
       '<a href="cobranzas.php">Cobranzas</a> / ' . ($p ? '<a href="cobrar.php">Cobros en efectivo</a> / ' . h($p['nro_prestamo']) : 'Cobros en efectivo'));
mensajes($errores);
?>

<?php if (!$p): ?>
<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Préstamos con saldo</span><strong><?= num($total) ?></strong>
    <span class="bcra-tarjeta-sub"><?= $hayFiltros ? 'con los filtros aplicados' : 'vigentes, con algo a pagar' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Deuda vencida</span><strong class="<?= $tot['vencido'] > 0 ? 'importe-neg' : '' ?>"><?= money($tot['vencido']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($tot['con_vencido']) ?> <?= (int) $tot['con_vencido'] === 1 ? 'préstamo' : 'préstamos' ?> con cuotas vencidas · sin los punitorios del día</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">A vencer</span><strong><?= money($tot['a_vencer']) ?></strong>
    <span class="bcra-tarjeta-sub">las cuotas que vencen de hoy en adelante</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cobrado hoy en efectivo</span><strong><?= money($hoyEfectivo['importe']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($hoyEfectivo['n']) ?> <?= (int) $hoyEfectivo['n'] === 1 ? 'recibo' : 'recibos' ?><?=
      puede('cobranzas.php') && (int) $hoyEfectivo['n'] ? ' · <a href="cobranzas.php?medio=EFECTIVO&amp;desde=' . date('Y-m-d') . '&amp;hasta=' . date('Y-m-d') . '">ver</a>' : '' ?></span></div>
</div>

<div class="card">
  <form method="get" action="cobrar.php" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Préstamo, cliente, CUIT o DNI</span>
      <input type="search" name="q" value="<?= h($q) ?>" class="filter-input <?= $q !== '' ? 'activo' : '' ?>" placeholder="Buscar…" autofocus></label>
    <label class="filtro-campo"><span class="filtro-lbl">Deuda</span>
      <select name="deuda" class="filtro <?= $fDeuda !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['vencida' => 'Con cuotas vencidas', 'al_dia' => 'Al día'], $fDeuda, 'Toda') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Comercializador</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <?= filtro_botones('cobrar.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'préstamos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><?= th_orden('Préstamo', 'prestamo', $orden) ?><?= th_orden('Cliente', 'cliente', $orden) ?>
        <?= th_orden('Cuotas', 'cuotas', $orden, 'th-right') ?><?= th_orden('Próximo vencimiento', 'proximo', $orden) ?>
        <?= th_orden('Deuda vencida', 'vencido', $orden, 'th-right') ?><?= th_orden('A vencer', 'a_vencer', $orden, 'th-right') ?><?= th_orden('Total', 'total', $orden, 'th-right') ?></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="8" class="no-data"><?= $hayFiltros ? 'Ningún préstamo con saldo coincide con el filtro.' : 'No hay préstamos vigentes con saldo a pagar.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $r): $id = (int) $r['id_prestamo']; $venc = (float) $r['vencido'] > 0.004; ?>
        <tr>
          <td><div class="acciones">
            <?= btn_accion('ver', 'cobrar.php?prestamo=' . $id, 'Ver la deuda y cobrar en efectivo') ?>
            <button type="button" class="btn-accion detalle" data-detalle="cuotas-<?= $id ?>" aria-expanded="false"
                    title="Mostrar el detalle de las cuotas" aria-label="Mostrar el detalle de las cuotas"><?= icono('flecha') ?></button>
          </div></td>
          <td class="mono td-fuerte"><a href="cobrar.php?prestamo=<?= $id ?>"><?= h($r['nro_prestamo']) ?></a><span class="segunda-linea"><?= h($r['tipo_asociado']) ?></span></td>
          <td class="td-wrap"><?= h($r['denominacion']) ?><span class="segunda-linea mono"><?= h(cuit_fmt($r['cuit'])) ?><?= $r['vendedor'] ? ' · ' . h($r['vendedor']) : '' ?></span></td>
          <td class="td-right"><?= num($r['impagas']) ?> de <?= num($r['plazo']) ?>
            <span class="segunda-linea"><?= (int) $r['vencidas'] ? '<span class="texto-error">' . num($r['vencidas']) . ((int) $r['vencidas'] === 1 ? ' vencida' : ' vencidas') . '</span>' : 'ninguna vencida' ?></span></td>
          <td><?= $r['proximo_vto'] ? h(fecha($r['proximo_vto'])) : '—' ?><span class="segunda-linea">cuota de <?= money($r['valor_cuota']) ?></span></td>
          <td class="td-right<?= $venc ? ' importe-neg td-fuerte' : '' ?>"><?= $venc ? money($r['vencido']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (float) $r['a_vencer'] > 0.004 ? money($r['a_vencer']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right td-fuerte"><?= money((float) $r['vencido'] + (float) $r['a_vencer']) ?></td>
        </tr>
        <tr class="fila-detalle oculto" id="cuotas-<?= $id ?>"><td></td><td colspan="7">
          <table class="sub-tabla">
            <thead><tr><th class="th-right">Cuota</th><th>Vencimiento</th><th class="th-right">Atraso</th><th class="th-right">Capital</th>
              <th class="th-right">Interés + IVA</th><th class="th-right">Punitorios + IVA</th><th class="th-right">Saldo</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($cuotas[$id] ?? [] as $c): $at = (int) $c['atraso']; ?>
              <tr><td class="td-right"><?= (int) $c['nro_cuota'] ?> de <?= (int) $r['plazo'] ?></td><td><?= h(fecha($c['vencimiento'])) ?></td>
                <td class="td-right"><?= $at > 0 ? '<span class="texto-error">' . $at . ($at === 1 ? ' día' : ' días') . '</span>' : '<span class="td-muted">—</span>' ?></td>
                <td class="td-right"><?= money($c['capital']) ?></td><td class="td-right"><?= money($c['interes']) ?></td>
                <td class="td-right"><?= (float) $c['punitorio'] > 0 ? money($c['punitorio']) : '<span class="td-muted">—</span>' ?></td>
                <td class="td-right td-fuerte"><?= money($c['saldo']) ?></td>
                <td><?= $at > 0 ? estado('Vencida', 'danger') : ($c['estado'] === 'PARCIAL' ? estado('Pago parcial', 'warn') : estado('A vencer', 'neutro')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="6">Saldo del préstamo (sin los punitorios del día)</td><td class="td-right"><?= money((float) $r['vencido'] + (float) $r['a_vencer']) ?></td><td></td></tr></tfoot>
          </table>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($filas): ?>
      <tfoot><tr class="total-row"><td colspan="5">TOTAL DEL FILTRO · <?= num($total) ?> <?= $total === 1 ? 'préstamo' : 'préstamos' ?></td>
        <td class="td-right"><?= money($tot['vencido']) ?></td><td class="td-right"><?= money($tot['a_vencer']) ?></td>
        <td class="td-right"><?= money((float) $tot['vencido'] + (float) $tot['a_vencer']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $total, $porPag, 'préstamos') ?>
</div>
<script>
(function () {
  document.querySelectorAll('[data-detalle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var fila = document.getElementById(b.getAttribute('data-detalle'));
      if (!fila) return;
      var abierta = fila.classList.toggle('oculto') === false;
      b.classList.toggle('abierto', abierta);
      b.setAttribute('aria-expanded', abierta ? 'true' : 'false');
      b.title = abierta ? 'Ocultar el detalle de las cuotas' : 'Mostrar el detalle de las cuotas';
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($p && $p['estado'] !== 'VIGENTE'): ?>
  <?php alerta('El préstamo ' . $p['nro_prestamo'] . ' está ' . strtolower(ESTADOS_PRESTAMO[$p['estado']] ?? $p['estado']) . ': no admite pagos.', 'warning'); ?>
<?php endif; ?>

<?php if ($p && $ec && $canc): ?>
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

<div class="card">
  <?= card_head('Registrar el cobro en efectivo', 'genera el recibo y entra en la caja') ?>
  <form method="post" action="cobrar.php" id="frm-pago" data-confirmar="" data-confirmar-titulo="Registrar el cobro en efectivo" data-confirmar-boton="Registrar">
    <?= csrf_campo() ?>
    <input type="hidden" name="prestamo" value="<?= $idP ?>">
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4">
          <span class="campo-label">Tipo de pago</span>
          <div class="radios">
            <label><input type="radio" name="modo" value="NORMAL" <?= $form['modo'] === 'NORMAL' ? 'checked' : '' ?>> Pago a cuenta</label>
            <label><input type="radio" name="modo" value="CANCELACION" <?= $form['modo'] === 'CANCELACION' ? 'checked' : '' ?>> Cancelación total</label>
          </div>
        </div>
        <div class="campo" style="--span:3">
          <label for="fecha">Fecha del cobro</label>
          <input type="date" id="fecha" name="fecha" value="<?= h($fecha) ?>" max="<?= date('Y-m-d') ?>"
                 min="<?= h($ultimoPago['fecha'] ?? $p['fecha_liquidacion']) ?>">
          <span class="campo-ayuda">al cambiarla se recalcula la deuda</span>
        </div>
        <div class="campo" style="--span:5">
          <label for="importe">Importe en efectivo <span class="req">*</span></label>
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
        <?php if ($conFondos): ?>
        <?= campo_cuenta_fondos('cuenta', 'Entra en la caja', $cajas, $form['cuenta'], 4, $cajas ? '' : 'No hay ninguna caja en pesos habilitada.') ?>
        <?php endif; ?>
        <div class="campo" style="--span:<?= $conFondos ? 4 : 8 ?>">
          <label for="referencia">Observación</label>
          <input id="referencia" name="referencia" value="<?= h($form['referencia']) ?>" maxlength="100" placeholder="Quién trajo el pago, comprobante…">
        </div>
        <?= campo_talonario_recibo($tals, $form['talonario'], 4) ?>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Registrar cobro en efectivo', 'ok', null, 'guardar', true) ?>
    </div>
  </form>
</div>

<div class="card">
  <?= card_head('Estado de cuenta al ' . fecha($fecha), $p['nro_prestamo'] . ' · ' . $p['tipo_asociado'],
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

<script>
(function () {
  var form = document.getElementById('frm-pago'), imp = document.getElementById('importe'),
      ayuda = document.getElementById('importe-ayuda'), fecha = document.getElementById('fecha');
  var cancelacion = <?= json_encode(monto_campo_txt($canc['tot']['total'])) ?>;
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
  var cta = document.getElementById('cf-cuenta'), tal = document.getElementById('talonario');
  // El diálogo de confirmación (app.js) muestra lo que se va a registrar
  function texto() {
    form.setAttribute('data-confirmar', (modo() === 'CANCELACION' ? 'Cancelación total' : 'Cobro') + ' de $ ' + (imp.value || '0')
      + ' en efectivo, con fecha ' + fecha.value.split('-').reverse().join('/')
      + (cta && cta.value ? ', que entra en ' + cta.options[cta.selectedIndex].text : '')
      + (tal && tal.tagName === 'SELECT' && tal.value ? ', con recibo de ' + tal.options[tal.selectedIndex].text.split(' · ')[0] : '') + '.');
  }
  form.addEventListener('input', texto);
  form.addEventListener('change', texto);
  form.querySelectorAll('[data-importe]').forEach(function (b) { b.addEventListener('click', texto); });
  aplicar();
  texto();
  imp.focus();
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
