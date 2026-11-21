<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Débitos y créditos bancarios: el detalle por instrumento de lo que se
// movió en las cuentas banco (un cheque, una transferencia, un débito).
// Es el lado libro de la conciliación: lo que el banco tiene que informar
// en el extracto. Por defecto, lo pendiente: lo que está en el libro y
// todavía no se vio en el banco (cheques en circulación incluidos).
//   - Se marcan de a varios: «En el banco» con la fecha en que el banco lo
//     acreditó o debitó, «Entregado» (un cheque que ya tiene el
//     beneficiario) y «Volver a pendiente» si fue un error.
//   - Los anulados (se anuló el comprobante) no se tocan.
//   - Lo que ya está en el banco no se puede anular desde su circuito:
//     primero se vuelve a pendiente.
// ---------------------------------------------------------------------

const ESTADOS_FILTRO = ['PENDIENTE' => 'Pendientes', 'ACREDITADO' => 'En el banco', 'ANULADO' => 'Anulados', 'todos' => 'Todos'];

$errores = [];
$hoy = date('Y-m-d');

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $sel = array_values(array_filter(array_map('intval', (array) ($_POST['sel'] ?? [])), function ($x) { return $x > 0; }));
    $volver = (string) ($_POST['volver'] ?? '');
    $volver = preg_match('#^debitos_creditos\.php(\?[^\s]*)?$#', $volver) ? $volver : 'debitos_creditos.php';
    if (!$sel) {
        flash('warning', 'Tildá al menos un renglón.');
        redirigir($volver);
    }
    $marcas = implode(',', array_fill(0, count($sel), '?'));
    try {
        if ($accion === 'banco') {
            $fb = leer_fecha(post_str('fecha_banco'));
            if ($fb === null || $fb > $hoy) {
                flash('warning', 'Escribí la fecha en que el banco lo acreditó o debitó (no puede ser futura).');
                redirigir($volver);
            }
            $st = db()->prepare("UPDATE debitos_creditos_bancarios SET estado = 'ACREDITADO', fecha_banco = ?, actualizado_por = ?
                                  WHERE id_deb_cred IN ($marcas) AND estado IN ('EMITIDO', 'ENTREGADO')");
            $st->execute(array_merge([$fb, usuario_id()], $sel));
            $n = $st->rowCount();
            flash($n ? 'ok' : 'warning', $n ? num($n) . ($n === 1 ? ' renglón marcado' : ' renglones marcados') . ' como vistos en el banco el ' . fecha($fb) . '.'
                                            : 'Ninguno de los elegidos estaba pendiente.');
        } elseif ($accion === 'entregado') {
            $st = db()->prepare("UPDATE debitos_creditos_bancarios SET estado = 'ENTREGADO', actualizado_por = ?
                                  WHERE id_deb_cred IN ($marcas) AND estado = 'EMITIDO' AND tipo_instrumento = 'CHEQUE'");
            $st->execute(array_merge([usuario_id()], $sel));
            $n = $st->rowCount();
            flash($n ? 'ok' : 'warning', $n ? num($n) . ($n === 1 ? ' cheque marcado' : ' cheques marcados') . ' como entregados.' : 'Solo se entregan cheques todavía pendientes.');
        } elseif ($accion === 'pendiente') {
            $st = db()->prepare("UPDATE debitos_creditos_bancarios SET estado = 'EMITIDO', fecha_banco = NULL, actualizado_por = ?
                                  WHERE id_deb_cred IN ($marcas) AND estado IN ('ACREDITADO', 'ENTREGADO')");
            $st->execute(array_merge([usuario_id()], $sel));
            $n = $st->rowCount();
            flash($n ? 'ok' : 'warning', $n ? num($n) . ($n === 1 ? ' renglón volvió' : ' renglones volvieron') . ' a pendiente.' : 'Ninguno de los elegidos estaba en el banco o entregado.');
        }
    } catch (Throwable $e) {
        flash('danger', mensaje_bd($e));
    }
    redirigir($volver);
}

$bancos = [];
try {
    $bancos = array_filter(cuentas_fondos(null, true), function ($c) { return $c['tipo'] === 'B'; });
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de Tesorería: corré sql\financiera_fondos.sql.' : error_amigable($e);
}
$fCuenta = (int) get_str('cuenta');
if (!isset($bancos[$fCuenta])) {
    $fCuenta = 0;
}
$fEstado = isset(ESTADOS_FILTRO[get_str('estado')]) ? get_str('estado') : 'PENDIENTE';
$fInst   = isset(INSTRUMENTOS[get_str('instrumento')]) ? get_str('instrumento') : '';
$fLado   = in_array(get_str('lado'), ['debito', 'credito'], true) ? get_str('lado') : '';
$fDesde  = leer_fecha(get_str('desde')) ?? '';
$fHasta  = leer_fecha(get_str('hasta')) ?? '';
$fQ      = trim(get_str('q'));
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

$where = ['1=1'];
$params = [];
if ($fCuenta) {
    $where[] = 'm.id_cuenta_fondos = ?';
    $params[] = $fCuenta;
}
if ($fEstado === 'PENDIENTE') {
    $where[] = "d.estado IN ('EMITIDO', 'ENTREGADO')";
} elseif ($fEstado === 'ACREDITADO') {
    $where[] = "d.estado = 'ACREDITADO'";
} elseif ($fEstado === 'ANULADO') {
    $where[] = "d.estado IN ('ANULADO', 'RECHAZADO')";
}
if ($fInst !== '') {
    $where[] = 'd.tipo_instrumento = ?';
    $params[] = $fInst;
}
if ($fLado === 'debito') {
    $where[] = 'd.importe_debito > 0';
} elseif ($fLado === 'credito') {
    $where[] = 'd.importe_credito > 0';
}
if ($fDesde !== '') {
    $where[] = 'd.fecha_emision >= ?';
    $params[] = $fDesde;
}
if ($fHasta !== '') {
    $where[] = 'd.fecha_emision <= ?';
    $params[] = $fHasta;
}
if ($fQ !== '') {
    $like = '%' . addcslashes($fQ, '%_\\') . '%';
    $where[] = '(CAST(d.cheque_numero AS CHAR) = ? OR d.nro_referencia LIKE ? OR d.a_la_orden LIKE ? OR m.concepto LIKE ?)';
    array_push($params, preg_replace('/\D/', '', $fQ) ?: '-', $like, $like, $like);
}
$desdeSql = 'FROM debitos_creditos_bancarios d
             JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
             JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos
             LEFT JOIN chequeras q ON q.id_chequera = d.id_chequera
             LEFT JOIN inversiones_liquidaciones l ON l.id_liquidacion = m.id_liquidacion_inv
            WHERE ' . implode(' AND ', $where);
$columnas = 'd.*, m.concepto, m.tipo_origen, m.id_origen, m.id_cuenta_fondos, c.nombre AS cuenta, c.moneda,
             q.tipo_cheque, l.id_inversion AS id_inversion_liq';
$hayFiltros = $fCuenta || $fInst !== '' || $fLado !== '' || $fDesde !== '' || $fHasta !== '' || $fQ !== '';
// Orden por encabezado (en la base): la grilla y el Excel salen igual.
$orden = orden_grilla(['fecha' => 'd.fecha_emision', 'cuenta' => 'c.nombre', 'instrumento' => 'CAST(d.tipo_instrumento AS CHAR)', 'concepto' => 'm.concepto',
                       'debito' => 'd.importe_debito', 'credito' => 'd.importe_credito', 'estado' => 'd.estado'], 'fecha', 'asc', 'd.id_deb_cred');

$filas = [];
$tot = ['n' => 0, 'debitos' => 0, 'creditos' => 0];
$pend = ['debitos' => 0, 'n_deb' => 0, 'creditos' => 0, 'n_cred' => 0, 'cheques' => 0, 'n_chq' => 0, 'vencidos' => 0];
$paginas = 1;
try {
    if ($bancos) {
        $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(d.importe_debito), 0) AS debitos, COALESCE(SUM(d.importe_credito), 0) AS creditos $desdeSql", $params);
        // Lo pendiente de la cuenta (o de todas), sin los demás filtros: es la foto de lo que falta ver en el banco.
        $pend = q1("SELECT COALESCE(SUM(d.importe_debito), 0) AS debitos, SUM(d.importe_debito > 0) AS n_deb,
                           COALESCE(SUM(d.importe_credito), 0) AS creditos, SUM(d.importe_credito > 0) AS n_cred,
                           COALESCE(SUM(IF(d.tipo_instrumento = 'CHEQUE', d.importe_debito, 0)), 0) AS cheques,
                           SUM(d.tipo_instrumento = 'CHEQUE') AS n_chq,
                           SUM(d.fecha_emision < CURDATE() - INTERVAL 30 DAY) AS vencidos
                      FROM debitos_creditos_bancarios d
                      JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos AND m.anulado_el IS NULL
                     WHERE d.estado IN ('EMITIDO', 'ENTREGADO')" . ($fCuenta ? ' AND m.id_cuenta_fondos = ?' : ''), $fCuenta ? [$fCuenta] : []) ?: $pend;

        if (get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $todas = q("SELECT $columnas $desdeSql ORDER BY {$orden['sql']}", $params);
            $libro = new XlsxLibro();
            $libro->hoja('Débitos y créditos')->titulo('Débitos y créditos bancarios' . ($fCuenta ? ' · ' . $bancos[$fCuenta]['nombre'] : ''),
                                                       ESTADOS_FILTRO[$fEstado] . ' · ' . num($tot['n']) . ' renglones · generado el ' . date('d/m/Y H:i'))
                ->tabla([['Fecha', 'fecha', 12], ['Cuenta', 'texto', 24], ['Instrumento', 'texto', 16], ['Cheque N°', 'entero', 12], ['Referencia', 'texto', 18],
                         ['Concepto', 'texto', 50], ['A la orden', 'texto', 26], ['Débito', 'moneda', 15], ['Crédito', 'moneda', 15],
                         ['Estado', 'texto', 12], ['En el banco', 'fecha', 12]],
                        array_map(function ($d) {
                            $deb = (float) $d['importe_debito'] > 0;
                            $est = ['EMITIDO' => $deb ? 'A debitar' : 'A acreditar', 'ENTREGADO' => 'Entregado', 'ACREDITADO' => $deb ? 'Debitado' : 'Acreditado',
                                    'RECHAZADO' => 'Rechazado', 'ANULADO' => 'Anulado'][$d['estado']] ?? $d['estado'];
                            return [$d['fecha_emision'], $d['cuenta'], INSTRUMENTOS[$d['tipo_instrumento']] ?? $d['tipo_instrumento'], $d['cheque_numero'],
                                    $d['nro_referencia'], $d['concepto'], $d['a_la_orden'], (float) $d['importe_debito'], (float) $d['importe_credito'], $est, $d['fecha_banco']];
                        }, $todas),
                        [0 => ['texto' => 'TOTAL'], 7 => ['suma' => true], 8 => ['suma' => true]]);
            $libro->enviar('debitos_creditos_bancarios_' . date('Ymd') . '.xlsx');
            exit;
        }

        $paginas = max(1, (int) ceil($tot['n'] / $porPag));
        $pagina = min($pagina, $paginas);
        $filas = q("SELECT $columnas $desdeSql ORDER BY {$orden['sql']} LIMIT ? OFFSET ?",
                   array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}
$monFiltro = $fCuenta ? $bancos[$fCuenta]['moneda'] : 'ARS';
$volver = 'debitos_creditos.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

layout_inicio('Débitos y créditos bancarios');
titulo('Débitos y créditos bancarios', ($fCuenta ? $bancos[$fCuenta]['nombre'] . ' · ' : '') . 'Lo que el banco tiene que informar: un renglón por cheque, transferencia o débito',
       '', '<a href="movimientos_fondos.php">Tesorería</a> / Débitos y créditos bancarios');
mensajes($errores);
if (!$bancos && !$errores) {
    alerta('Todavía no hay cuentas de tipo Banco. ' . (puede('cuentas_fondos.php') ? 'Creá una en Tesorería › Cuentas de fondos.' : ''), 'info');
}
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Falta debitar</span><strong><?= dinero($pend['debitos'], $monFiltro) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($pend['n_deb']) ?> renglones en el libro que el banco todavía no debitó</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Falta acreditar</span><strong><?= dinero($pend['creditos'], $monFiltro) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($pend['n_cred']) ?> renglones que todavía no se vieron en el banco</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cheques en circulación</span><strong><?= dinero($pend['cheques'], $monFiltro) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($pend['n_chq']) ?> cheques sin debitar</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Pendientes de más de 30 días</span><strong><?= num($pend['vencidos']) ?></strong>
    <span class="bcra-tarjeta-sub">revisar si el banco ya los informó</span></div>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $bancos, $fCuenta ?: '', 'Todos los bancos', ['class' => 'filtro' . ($fCuenta ? ' activo' : ''), 'onchange' => 'this.form.submit()']) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== 'PENDIENTE' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_FILTRO, $fEstado) ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Instrumento</span>
      <select name="instrumento" class="filtro <?= $fInst !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(INSTRUMENTOS, $fInst, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Lado</span>
      <select name="lado" class="filtro <?= $fLado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['debito' => 'Débitos (sale)', 'credito' => 'Créditos (entra)'], $fLado, 'Los dos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="N° de cheque, referencia, beneficiario, concepto…"></label>
    <?= filtro_botones('debitos_creditos.php', $hayFiltros || $fEstado !== 'PENDIENTE') ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'renglones',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
<form method="post" action="debitos_creditos.php" id="frm-dcb">
  <?= csrf_campo() ?>
  <input type="hidden" name="volver" value="<?= h($volver) ?>">
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort><input type="checkbox" id="sel-todos" title="Tildar todos los de la página" aria-label="Tildar todos"></th>
        <?= th_orden('Fecha', 'fecha', $orden) ?><?= $fCuenta ? '' : th_orden('Cuenta', 'cuenta', $orden) ?><?= th_orden('Instrumento', 'instrumento', $orden) ?><?= th_orden('Concepto', 'concepto', $orden) ?>
        <?= th_orden('Débito', 'debito', $orden, 'th-right') ?><?= th_orden('Crédito', 'credito', $orden, 'th-right') ?><?= th_orden('Estado', 'estado', $orden) ?></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="<?= $fCuenta ? 7 : 8 ?>" class="no-data"><?= $fEstado === 'PENDIENTE' && !$hayFiltros ? 'No hay nada pendiente: todo lo del libro ya se vio en el banco.' : 'No hay renglones con este filtro.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $d): $url = origen_fondos_url($d); $tocable = !in_array($d['estado'], ['ANULADO', 'RECHAZADO'], true); ?>
        <tr<?= $tocable ? '' : ' class="fila-baja"' ?>>
          <td><div class="acciones"><?php if ($tocable): ?><input type="checkbox" name="sel[]" value="<?= (int) $d['id_deb_cred'] ?>" class="sel-dcb" aria-label="Elegir"><?php endif; ?>
            <?= $url ? btn_accion('ver', $url, 'Ver el comprobante') : '' ?></div></td>
          <td><?= h(fecha($d['fecha_emision'])) ?></td>
          <?= $fCuenta ? '' : '<td>' . h($d['cuenta']) . '</td>' ?>
          <td><?= h(INSTRUMENTOS[$d['tipo_instrumento']] ?? $d['tipo_instrumento']) ?>
            <?php $det = $d['tipo_instrumento'] === 'CHEQUE' ? 'N° ' . $d['cheque_numero'] . ($d['tipo_cheque'] === 'DIFERIDO' ? ' · diferido' : '') : (string) $d['nro_referencia']; ?>
            <?= $det !== '' ? '<span class="segunda-linea mono">' . h($det) . '</span>' : '' ?></td>
          <td class="td-wrap"><?= $url ? '<a href="' . h($url) . '">' . h($d['concepto']) . '</a>' : h($d['concepto']) ?>
            <?= $d['a_la_orden'] ? '<span class="segunda-linea">a la orden de ' . h($d['a_la_orden']) . '</span>' : '' ?></td>
          <td class="td-right"><?= (float) $d['importe_debito'] ? dinero($d['importe_debito'], $d['moneda']) : '' ?></td>
          <td class="td-right"><?= (float) $d['importe_credito'] ? dinero($d['importe_credito'], $d['moneda']) : '' ?></td>
          <td><?= estado_deb_cred($d) ?><?= $d['fecha_banco'] ? '<span class="segunda-linea">el ' . h(fecha($d['fecha_banco'])) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($filas && $fCuenta): ?>
      <tfoot><tr class="total-row"><td colspan="4">TOTAL DEL FILTRO · <?= num($tot['n']) ?> renglones</td>
        <td class="td-right"><?= dinero($tot['debitos'], $monFiltro) ?></td><td class="td-right"><?= dinero($tot['creditos'], $monFiltro) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($filas): ?>
  <div class="barra-seleccion">
    <span class="barra-seleccion-txt"><span id="n-sel">0</span> elegidos</span>
    <label class="filtro-campo"><span class="filtro-lbl">Fecha en el banco</span>
      <input type="date" name="fecha_banco" value="<?= $hoy ?>" max="<?= $hoy ?>" class="filter-input"></label>
    <button type="submit" name="accion" value="banco" class="btn btn-ok btn-sm"><?= icono('ok') ?>En el banco</button>
    <button type="submit" name="accion" value="entregado" class="btn btn-secondary btn-sm" title="Un cheque que ya tiene el beneficiario">Entregado</button>
    <button type="submit" name="accion" value="pendiente" class="btn btn-ghost btn-sm"><?= icono('volver') ?>Volver a pendiente</button>
  </div>
  <?php endif; ?>
</form>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'renglones') ?>
</div>
<script>
(function () {
  var todos = document.getElementById('sel-todos'), n = document.getElementById('n-sel');
  var casillas = document.querySelectorAll('.sel-dcb');
  function contar() { if (n) n.textContent = document.querySelectorAll('.sel-dcb:checked').length; }
  if (todos) todos.addEventListener('change', function () { casillas.forEach(function (c) { c.checked = todos.checked; }); contar(); });
  casillas.forEach(function (c) { c.addEventListener('change', contar); });
})();
</script>

<?php layout_fin(); ?>
