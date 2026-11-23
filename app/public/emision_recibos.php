<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/recibos.php';

// ---------------------------------------------------------------------
// Cobros bancarios (Cobranzas; antes «Emisión de recibos»): la emisión de
// recibos desde el extracto, como la de los recibos de planes de
// gestion_adm. Los créditos del extracto bancario de una cuenta, en el
// período, identificados por el CUIL que traen, con lo que debe cada
// cliente (vencido y lo que vence en el período, sumando sus préstamos),
// y el recibo de cada crédito aplicado a las cuotas de todos sus
// préstamos (inc\recibos.php, sp_emitir_recibo).
//   GET  cuenta, desde, hasta, q   la grilla
//   POST accion=emitir             un recibo por cada crédito tildado
//   POST accion=asignar            el cliente de un crédito, a mano (con
//                                  regla para el CUIL que trae)
//   POST accion=quitar_regla       da de baja la regla de un CUIL
// ---------------------------------------------------------------------

$errores = [];
$resultados = [];
$val = function (string $k): string { return es_post() ? post_str($k) : get_str($k); };

$instalado = false;
$cuentas = [];
try {
    $instalado = recibos_instalado();
    if ($instalado) {
        $cuentas = array_column(rec_cuentas(), null, 'id_cuenta_fondos');
    } else {
        $errores[] = 'Falta crear los recibos: corré sql\financiera_recibos.sql.';
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

$desde = leer_fecha($val('desde')) ?? date('Y-m-01');
$hasta = leer_fecha($val('hasta')) ?? date('Y-m-t');
if ($hasta < $desde) {
    [$desde, $hasta] = [$hasta, $desde];
}
$q = trim($val('q'));
$cuenta = (int) $val('cuenta');
if (!isset($cuentas[$cuenta])) {
    // La primera habilitada con créditos sin recibo.
    $cuenta = 0;
    foreach ($cuentas as $id => $c) {
        if ((int) $c['habilitada'] && (int) $c['pendientes'] > 0) {
            $cuenta = (int) $id;
            break;
        }
    }
    if (!$cuenta && $cuentas) {
        $cuenta = (int) array_key_first($cuentas);
    }
}

$tals = [];
try {
    $tals = talonarios_recibo();
} catch (Throwable $e) {
    error_log('[financiera] talonarios de recibos: ' . $e->getMessage());
}
$talonario = $val('talonario') !== '' ? $val('talonario') : (count($tals) === 1 ? (string) array_key_first($tals) : '');
$medio = isset(MEDIOS_PAGO[$val('medio')]) ? $val('medio') : 'TRANSFERENCIA';
$filtros = array_filter(['cuenta' => $cuenta ?: null, 'desde' => $desde, 'hasta' => $hasta, 'q' => $q !== '' ? $q : null]);
$volver = 'emision_recibos.php?' . http_build_query($filtros);

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post() && $instalado) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        if ($accion === 'emitir') {
            $sel = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['sel'] ?? [])))));
            $idTal = talonario_recibo_post($tals, $talonario, $errores);
            if (!$sel) {
                $errores[] = 'Tildá al menos un crédito.';
            }
            if (!$cuenta) {
                $errores[] = 'Elegí la cuenta bancaria.';
            }
            if (!$errores) {
                // Por fecha: el recibo de un crédito se aplica sobre lo que
                // dejó el anterior del mismo cliente.
                $orden = q('SELECT id_extracto FROM extractos_bancarios WHERE id_extracto IN (' . rec_marcas($sel) . ') ORDER BY fecha, id_extracto', $sel);
                foreach ($orden as $o) {
                    $resultados[] = rec_emitir((int) $o['id_extracto'], $cuenta, $idTal, $medio);
                }
            }
        } elseif ($accion === 'asignar') {
            [$ok, $msg] = rec_asignar((int) post_str('id_extracto'), $cuenta, post_str('cliente'));
            flash($ok ? 'ok' : 'warning', $msg);
            redirigir($volver);
        } elseif ($accion === 'quitar_regla') {
            [$ok, $msg] = rec_quitar_regla(post_str('cuil'));
            flash($ok ? 'ok' : 'warning', $msg);
            redirigir($volver);
        }
    } catch (Throwable $e) {
        $errores[] = mensaje_bd($e);
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$datos = ['identificados' => [], 'sin_cliente' => [], 'stats' => ['creditos' => 0, 'disponible' => 0, 'ident' => 0, 'ident_importe' => 0,
          'ident_disponible' => 0, 'sin' => 0, 'sin_importe' => 0]];
$deud = ['filas' => [], 'n' => 0, 'vencido' => 0, 'periodo' => 0];
$porPag = por_pagina(50);
$pagina = max(1, (int) get_str('p'));
if ($instalado && $cuenta) {
    try {
        if ($resultados) {
            $cuentas = array_column(rec_cuentas(), null, 'id_cuenta_fondos');   // los pendientes, después de emitir
        }
        $datos = rec_creditos($cuenta, $desde, $hasta, $q);
        $conCredito = array_values(array_unique(array_map(function ($f) { return (int) $f['cliente']['id_cliente']; }, $datos['identificados'])));
        $deud = rec_deudores($hasta, $conCredito, $q, $pagina, $porPag);
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}
$st = $datos['stats'];
$verCli = puede_abrir('cliente.php');
$verPre = puede_abrir('prestamo.php');
$emitidos = array_filter($resultados, function ($r) { return $r['ok']; });

layout_inicio('Cobros bancarios', 'cobranzas');
titulo('Cobros bancarios', 'Los créditos del extracto bancario identificados por CUIL, aplicados a las cuotas de los préstamos del cliente',
       puede('extractos_bancarios.php') ? boton('Importar extracto', 'secondary', 'extractos_bancarios.php?tab=importar', 'subir') : '',
       '<a href="cobranzas.php">Cobranzas</a> / Cobros bancarios');
mensajes($errores);
?>

<?php if ($resultados): ?>
<div class="card">
  <?= card_head('Resultado de la emisión', num(count($emitidos)) . ' de ' . num(count($resultados)) . ' recibos emitidos') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Estado</th><th data-no-sort>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($resultados as $r): ?>
        <tr><td><div class="acciones"><?php if ($r['ok']): ?><?= btn_accion('ver', 'recibo.php?r=' . (int) $r['id_recibo'], 'Ver el recibo') ?><?= btn_accion('pdf', 'recibo.php?r=' . (int) $r['id_recibo'] . '&export=pdf', 'Recibo en PDF', true) ?><?php endif; ?></div></td>
          <td><?= $r['ok'] ? estado('Emitido', 'ok') : estado('No se emitió', 'danger') ?></td>
          <td class="td-wrap"><?= h($r['texto']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($instalado): ?>
<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta bancaria</span>
      <select name="cuenta" class="filtro" onchange="this.form.submit()">
        <?php if (!$cuentas): ?><option value="">No hay cuentas bancarias en pesos</option><?php endif; ?>
        <?php foreach ($cuentas as $id => $c): ?>
          <option value="<?= (int) $id ?>" <?= (int) $id === $cuenta ? 'selected' : '' ?>><?= h($c['nombre'] . ((int) $c['pendientes'] ? ' · ' . $c['pendientes'] . ' sin recibo' : '') . ((int) $c['habilitada'] ? '' : ' · deshabilitada')) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span><input type="date" name="desde" value="<?= h($desde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span><input type="date" name="hasta" value="<?= h($hasta) ?>" class="filtro"></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($q) ?>" class="filter-input <?= $q !== '' ? 'activo' : '' ?>" placeholder="CUIL, CUIT o nombre…"></label>
    <?= filtro_botones('emision_recibos.php?cuenta=' . (int) $cuenta, $q !== '' || $desde !== date('Y-m-01') || $hasta !== date('Y-m-t')) ?>
  </form>
</div>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Créditos sin recibo</span><strong><?= money($st['disponible']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($st['creditos']) ?> créditos del <?= h(fecha($desde)) ?> al <?= h(fecha($hasta)) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Identificados</span><strong><?= money($st['ident_importe']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($st['ident']) ?> créditos de clientes · a aplicar<?= $st['ident_disponible'] - $st['ident_importe'] >= 0.01 ? ' (sobran ' . money($st['ident_disponible'] - $st['ident_importe']) . ')' : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Sin cliente</span><strong><?= money($st['sin_importe']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($st['sin']) ?> créditos para asignar a mano</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Deudores sin cobro</span><strong><?= money($deud['vencido'] + $deud['periodo']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($deud['n']) ?> clientes · vencido o que vence hasta el <?= h(fecha($hasta)) ?></span></div>
</div>

<?php $ident = $datos['identificados']; ?>
<div class="card" id="identificados">
  <?= card_head('Cobros identificados', 'el CUIL del crédito es de un cliente con préstamos vigentes: lo que debe es a la fecha del crédito, con punitorios') ?>
  <form method="post" action="emision_recibos.php" id="frm-emitir" data-confirmar="" data-confirmar-titulo="Emitir los recibos" data-confirmar-boton="Emitir">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="emitir">
    <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php endforeach; ?>
    <div class="data-table-wrap">
      <table class="data-table emision" data-sin-orden data-sin-paginar>
        <thead><tr>
          <th class="col-acciones" data-no-sort><?php if ($ident): ?><input type="checkbox" id="sel-todos" title="Tildar todos" aria-label="Tildar todos"><?php else: ?>Acciones<?php endif; ?></th>
          <th data-no-sort>Crédito</th><th data-no-sort>Cliente</th>
          <th class="th-right" data-no-sort>Vencido</th><th class="th-right" data-no-sort>Vence en el período</th>
          <th data-no-sort>Aplica a</th><th class="th-right" data-no-sort>Recibo</th></tr></thead>
        <tbody>
        <?php if (!$ident): ?><tr><td colspan="7" class="no-data"><?= $st['creditos'] ? 'Ningún crédito del período es de un cliente con préstamos vigentes.' : 'No hay créditos sin recibo en el período.' ?></td></tr><?php endif; ?>
        <?php foreach ($ident as $f): $d = $f['deuda']; $id = $f['id_extracto']; $bloq = $d['bloqueo'] !== null; ?>
          <tr class="<?= $bloq ? 'td-muted' : '' ?>">
            <td><div class="acciones">
              <input type="checkbox" name="sel[]" value="<?= $id ?>" class="sel-cred" data-importe="<?= h((string) $f['importe']) ?>" aria-label="Elegir" <?= $bloq ? 'disabled' : '' ?>>
              <button type="button" class="btn-accion ver" data-detalle="det-<?= $id ?>" title="Ver las cuotas y cómo se aplica" aria-label="Ver las cuotas"><?= icono('ver') ?></button>
              <button type="button" class="btn-accion editar" data-detalle="asig-<?= $id ?>" title="Asignar a otro cliente" aria-label="Asignar a otro cliente"><?= icono('editar') ?></button>
            </div></td>
            <td class="mono"><?= h(fecha($f['fecha'])) ?> · <?= money($f['credito']) ?>
              <span class="segunda-linea"><?= $f['referencia'] ? 'ref. ' . h($f['referencia']) : 'sin referencia' ?><?= $f['disponible'] + 0.004 < $f['credito'] ? ' · quedan ' . money($f['disponible']) : '' ?></span></td>
            <td class="td-wrap"><?= $verCli ? '<a href="cliente.php?id=' . (int) $f['cliente']['id_cliente'] . '">' . h($f['cliente']['denominacion']) . '</a>' : h($f['cliente']['denominacion']) ?>
              <span class="segunda-linea"><?= h(cuit_fmt($f['cliente']['cuit'])) ?> · <?= $d['prestamos'] ? num($d['prestamos']) . ($d['prestamos'] === 1 ? ' préstamo' : ' préstamos') : 'sin préstamos' ?><?= $d['cs'] > 0 ? ' · cuota social ' . money($d['cs']) : '' ?><?php
                if ($f['leido'] && $f['leido'] !== $f['cliente']['cuit']): ?> · pagó <?= h(cuit_fmt($f['leido'])) ?><?php endif;
                if ($f['como'] === 'regla'): ?> <span class="chip" title="Por la regla de ese CUIL">regla</span><?php elseif ($f['como'] === 'manual'): ?> <span class="chip" title="Asignado a mano">a mano</span><?php endif; ?></span></td>
            <td class="td-right"><?= $d['vencido'] > 0 ? money($d['vencido']) : '<span class="td-muted">—</span>' ?></td>
            <td class="td-right"><?= $d['periodo'] > 0 ? money($d['periodo']) : '<span class="td-muted">—</span>' ?></td>
            <td class="td-wrap"><?php if ($bloq): ?><span class="texto-error"><?= h($d['bloqueo']) ?></span><?php else: ?><?= h(rec_aplicacion_txt($f['aplicacion']['cuotas'])) ?>
              <?php $adelanta = array_sum(array_map(function ($c) { return $c['tramo'] === 'despues' ? $c['aplica'] : 0; }, $f['aplicacion']['cuotas'])); ?>
              <span class="segunda-linea"><?= $adelanta > 0 ? 'adelanta ' . money($adelanta) . ' de cuotas que vencen después del período' : 'cuotas vencidas o del período' ?><?= $f['repetido'] ? ' · el cliente tiene otro crédito en la lista' : '' ?></span><?php endif; ?></td>
            <td class="td-right td-fuerte"><?= money($f['importe']) ?><?php if ($f['sobra'] >= 0.01): ?><span class="segunda-linea">sobran <?= money($f['sobra']) ?></span><?php endif; ?></td>
          </tr>
          <tr class="fila-detalle oculto" id="det-<?= $id ?>"><td></td><td colspan="6">
            <table class="sub-tabla">
              <thead><tr><th>Préstamo</th><th class="th-right">Cuota</th><th>Vence</th><th class="th-right">Días de atraso</th><th class="th-right">Punitorios</th><th class="th-right">Saldo</th><th class="th-right">Aplica</th></tr></thead>
              <tbody>
              <?php
                // Las cuotas vencidas, las del período y las que toca el recibo; las demás, en un renglón.
                $resto = ['n' => 0, 'total' => 0.0, 'hasta' => null];
                foreach ($f['aplicacion']['cuotas'] as $c):
                  if ($c['tramo'] === 'despues' && $c['aplica'] <= 0) {
                      $resto['n']++;
                      $resto['total'] += $c['total'];
                      $resto['hasta'] = $c['vencimiento'];
                      continue;
                  } ?>
                <tr class="<?= $c['aplica'] <= 0 ? 'td-muted' : '' ?>"><?php if (isset($c['id_cuota_social'])): ?><td>Cuota social</td><td class="td-right"><?= h(CS_CONCEPTOS[$c['concepto']] ?? $c['concepto']) ?></td><?php else: ?><td><?= $verPre ? '<a href="prestamo.php?id=' . (int) $c['id_prestamo'] . '">' . h($c['nro_prestamo']) . '</a>' : h($c['nro_prestamo']) ?></td><td class="td-right"><?= (int) $c['nro_cuota'] ?></td><?php endif; ?>
                  <td><?= h(fecha($c['vencimiento'])) ?> <span class="td-muted"><?= ['vencido' => '· vencida', 'periodo' => '· del período', 'despues' => ''][$c['tramo']] ?></span></td>
                  <td class="td-right"><?= $c['dias_atraso'] ?: '—' ?></td><td class="td-right"><?= $c['punitorio'] > 0 ? money($c['punitorio']) : '—' ?></td>
                  <td class="td-right"><?= money($c['total']) ?></td><td class="td-right td-fuerte"><?= $c['aplica'] > 0 ? money($c['aplica']) : '—' ?></td></tr>
              <?php endforeach; ?>
              <?php if ($resto['n']): ?>
                <tr class="td-muted"><td colspan="5"><?= num($resto['n']) ?> cuotas más, que vencen hasta el <?= h(fecha($resto['hasta'])) ?></td><td class="td-right"><?= money($resto['total']) ?></td><td class="td-right">—</td></tr>
              <?php endif; ?>
              </tbody>
              <tfoot><tr><td colspan="5">Deuda total a la fecha del crédito<?= $d['despues'] > 0 ? ' (después del período: ' . money($d['despues']) . ')' : '' ?></td>
                <td class="td-right"><?= money($d['total']) ?></td><td class="td-right"><?= money($f['importe']) ?></td></tr></tfoot>
            </table>
          </td></tr>
          <tr class="fila-detalle oculto" id="asig-<?= $id ?>"><td></td><td colspan="6">
            <div class="asignar-linea">
              <label>Asignar este crédito a <input name="cliente" form="fa-<?= $id ?>" class="filter-input" placeholder="CUIT/CUIL o DNI del cliente" inputmode="numeric" required></label>
              <button type="submit" form="fa-<?= $id ?>" class="btn btn-primary btn-sm"><?= icono('guardar') ?>Asignar</button>
              <?php if ($f['como'] === 'manual' || $f['como'] === 'regla'): ?>
                <button type="submit" form="fa-<?= $id ?>" name="cliente" value="" formnovalidate class="btn btn-secondary btn-sm" title="Vuelve al CUIL que trae el extracto">Usar el CUIL del extracto</button>
              <?php endif; ?>
              <?php if ($f['como'] === 'regla'): ?>
                <button type="submit" form="fr-<?= $id ?>" class="btn btn-danger btn-sm" title="Los créditos de ese CUIL vuelven a resolverse por el extracto">Quitar la regla de <?= h(cuit_fmt((string) $f['leido'])) ?></button>
              <?php endif; ?>
              <span class="campo-ayuda"><?= $f['leido'] ? 'el CUIL ' . h(cuit_fmt($f['leido'])) . ' del extracto queda asociado al cliente elegido (regla para los próximos créditos)' : 'el extracto no trae CUIL: vale solo para este crédito' ?></span>
            </div>
          </td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($ident): ?>
        <tfoot><tr class="total-row"><td colspan="6">TOTAL · <?= num(count($ident)) ?> créditos</td><td class="td-right"><?= money($st['ident_importe']) ?></td></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php if ($ident): ?>
    <div class="barra-seleccion">
      <span class="barra-seleccion-txt"><span id="n-sel">0</span> créditos · <span id="t-sel">$ 0,00</span></span>
      <label class="filtro-campo"><span class="filtro-lbl">Medio</span>
        <select name="medio" class="filtro" id="sel-medio"><?= opciones(MEDIOS_PAGO, $medio) ?></select></label>
      <?php if (count($tals) > 1): ?>
        <label class="filtro-campo"><span class="filtro-lbl">Talonario</span>
          <select name="talonario" class="filtro" id="sel-tal" required><option value="">Elegí…</option>
            <?php foreach ($tals as $tid => $t): ?><option value="<?= (int) $tid ?>" <?= (string) $tid === $talonario ? 'selected' : '' ?> <?= $t['agotado'] ? 'disabled' : '' ?>><?= h($t['nombre'] . ' · ' . ($t['agotado'] ? 'agotado' : 'desde ' . $t['proximo'])) ?></option><?php endforeach; ?>
          </select></label>
      <?php elseif ($tals): $t = reset($tals); ?>
        <input type="hidden" name="talonario" value="<?= (int) $t['id_talonario'] ?>">
        <span class="barra-seleccion-txt" style="margin-right:0">Recibos desde <strong class="mono"><?= h($t['proximo']) ?></strong></span>
      <?php endif; ?>
      <button type="submit" class="btn btn-ok btn-sm" id="btn-emitir" disabled><?= icono('documento') ?>Emitir recibos e imputar</button>
    </div>
    <?php endif; ?>
  </form>
  <?php foreach ($ident as $f): ?>
    <form method="post" action="emision_recibos.php" id="fa-<?= $f['id_extracto'] ?>" class="oculto"><?= csrf_campo() ?>
      <input type="hidden" name="accion" value="asignar"><input type="hidden" name="id_extracto" value="<?= $f['id_extracto'] ?>">
      <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php endforeach; ?></form>
    <?php if ($f['como'] === 'regla'): ?>
    <form method="post" action="emision_recibos.php" id="fr-<?= $f['id_extracto'] ?>" class="oculto" data-confirmar="Los créditos que vengan con el CUIL <?= h(cuit_fmt((string) $f['leido'])) ?> vuelven a resolverse por el extracto." data-confirmar-titulo="Quitar la regla" data-confirmar-boton="Quitar"><?= csrf_campo() ?>
      <input type="hidden" name="accion" value="quitar_regla"><input type="hidden" name="cuil" value="<?= h((string) $f['leido']) ?>">
      <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php endforeach; ?></form>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php $sin = $datos['sin_cliente']; ?>
<div class="card" id="sin-cliente">
  <?= card_head('Créditos sin cliente', 'sin CUIL, con un CUIL que no es de un cliente, o de un cliente sin préstamos vigentes: se asignan a mano') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th data-no-sort>Crédito</th><th data-no-sort>Concepto</th><th data-no-sort>Por qué</th><th class="th-right" data-no-sort>Sin recibo</th><th data-no-sort>Asignar a</th></tr></thead>
      <tbody>
      <?php if (!$sin): ?><tr><td colspan="5" class="no-data">Todos los créditos del período son de clientes.</td></tr><?php endif; ?>
      <?php foreach ($sin as $f): $id = $f['id_extracto']; ?>
        <tr>
          <td class="mono"><?= h(fecha($f['fecha'])) ?><span class="segunda-linea"><?= $f['referencia'] ? 'ref. ' . h($f['referencia']) : 'sin referencia' ?></span></td>
          <td class="td-wrap col-motivo"><?= h((string) $f['concepto']) ?></td>
          <td class="td-wrap"><?= h($f['motivo']) ?><?php if ($f['como'] === 'regla' || $f['como'] === 'manual'): ?> <span class="chip"><?= $f['como'] === 'regla' ? 'regla' : 'a mano' ?></span><?php endif; ?></td>
          <td class="td-right"><?= money($f['disponible']) ?><?= $f['disponible'] + 0.004 < $f['credito'] ? '<span class="segunda-linea">de ' . money($f['credito']) . '</span>' : '' ?></td>
          <td><form method="post" action="emision_recibos.php" class="form-en-linea asignar-linea"><?= csrf_campo() ?>
              <input type="hidden" name="accion" value="asignar"><input type="hidden" name="id_extracto" value="<?= $id ?>">
              <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php endforeach; ?>
              <input name="cliente" class="filter-input" placeholder="CUIT/CUIL o DNI" inputmode="numeric" required aria-label="CUIT/CUIL o DNI del cliente">
              <button type="submit" class="btn btn-primary btn-sm" title="<?= $f['leido'] ? 'El CUIL ' . h(cuit_fmt($f['leido'])) . ' queda asociado al cliente (regla)' : 'Solo este crédito' ?>">Asignar</button>
            </form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="deudores">
  <?= card_head('Deudores sin cobro en el período', 'clientes con cuotas vencidas o que vencen hasta el ' . fecha($hasta) . ' y sin un crédito identificado arriba (vencido a hoy, sin los punitorios que se devengan al cobrar)') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Cliente</th><th class="th-right" data-no-sort>Préstamos</th><th data-no-sort>Primer vencimiento impago</th>
        <th class="th-right" data-no-sort>Vencido</th><th class="th-right" data-no-sort>Vence en el período</th><th class="th-right" data-no-sort>Total</th></tr></thead>
      <tbody>
      <?php if (!$deud['filas']): ?><tr><td colspan="7" class="no-data">Nadie más debe en el período.</td></tr><?php endif; ?>
      <?php foreach ($deud['filas'] as $r): ?>
        <tr><td><div class="acciones"><?= puede('cobrar.php') ? btn_accion('ver', 'cobrar.php?q=' . urlencode($r['cuit']), 'Sus préstamos en Cobros en efectivo') : '' ?></div></td>
          <td class="td-wrap"><?= $verCli ? '<a href="cliente.php?id=' . (int) $r['id_cliente'] . '">' . h($r['denominacion']) . '</a>' : h($r['denominacion']) ?><span class="segunda-linea"><?= h(cuit_fmt($r['cuit'])) ?></span></td>
          <td class="td-right"><?= num($r['prestamos']) ?></td>
          <td><?= h(fecha($r['primer_vto'])) ?></td>
          <td class="td-right"><?= (float) $r['vencido'] > 0 ? money($r['vencido']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (float) $r['periodo'] > 0 ? money($r['periodo']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right td-fuerte"><?= money((float) $r['vencido'] + (float) $r['periodo']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($deud['filas']): ?>
      <tfoot><tr class="total-row"><td colspan="4">TOTAL · <?= num($deud['n']) ?> clientes</td><td class="td-right"><?= money($deud['vencido']) ?></td>
        <td class="td-right"><?= money($deud['periodo']) ?></td><td class="td-right"><?= money($deud['vencido'] + $deud['periodo']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($deud['n'] > $porPag): ?><?= paginador($pagina, (int) ceil($deud['n'] / $porPag), $deud['n'], $porPag, 'clientes') ?><?php endif; ?>
</div>

<script>
(function () {
  var form = document.getElementById('frm-emitir'), todos = document.getElementById('sel-todos'),
      n = document.getElementById('n-sel'), t = document.getElementById('t-sel'), boton = document.getElementById('btn-emitir'),
      casillas = Array.prototype.slice.call(document.querySelectorAll('.sel-cred:not([disabled])')), tal = document.getElementById('sel-tal');
  function pesos(v) { return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function contar() {
    var c = 0, s = 0;
    casillas.forEach(function (x) { if (x.checked) { c++; s += parseFloat(x.getAttribute('data-importe')); } });
    if (n) n.textContent = c;
    if (t) t.textContent = pesos(s);
    if (boton) boton.disabled = c === 0;
    if (form) {
      form.setAttribute('data-confirmar', 'Se ' + (c === 1 ? 'emite 1 recibo' : 'emiten ' + c + ' recibos') + ' por ' + pesos(s)
        + ', uno por crédito, aplicados a las cuotas de los préstamos de cada cliente (de la que vence primero a la última)'
        + (tal && tal.value ? ', con el talonario ' + tal.options[tal.selectedIndex].text.split(' · ')[0] : '') + '.');
    }
  }
  if (todos) todos.addEventListener('change', function () { casillas.forEach(function (x) { x.checked = todos.checked; }); contar(); });
  casillas.forEach(function (x) { x.addEventListener('change', contar); });
  if (tal) tal.addEventListener('change', contar);
  document.querySelectorAll('[data-detalle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var fila = document.getElementById(b.getAttribute('data-detalle'));
      if (!fila) return;
      fila.classList.toggle('oculto');
      var campo = fila.querySelector('input[name=cliente]');
      if (campo && !fila.classList.contains('oculto')) campo.focus();
    });
  });
  contar();
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
