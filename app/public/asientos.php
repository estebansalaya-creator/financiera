<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/asientos.php';

// ---------------------------------------------------------------------
// Asientos contables de un período (inc/asientos.php)
//   asientos.php?periodo=N                    libro de trabajo: los
//                                             asientos que generan las
//                                             operaciones del mes
//   asientos.php?periodo=N&solapa=diario      libro diario: lo grabado
//   …&export=xlsx                             la solapa en Excel
// «Generar» graba los que están listos (balanceados, con todas sus
// cuentas, en un período abierto) y no se generaron todavía: se puede
// apretar las veces que haga falta, lo generado no se repite. Anular un
// asiento lo deja a la vista sin sumar y suelta su operación, para
// volver a generarlo después de corregir las cuentas.
// ---------------------------------------------------------------------

$errores = [];
$solapa = get_str('solapa') === 'diario' ? 'diario' : 'trabajo';
$verFiltro = get_str('ver');                 // trabajo: pendientes | avisos | generados
$idPeriodo = (int) (post_str('periodo') ?: get_str('periodo'));

$periodos = [];
$periodo = null;
try {
    $periodos = q("SELECT p.id_periodo, p.nombre, p.fecha_inicio, p.fecha_fin, p.estado, e.nombre AS ejercicio, e.activo
                     FROM c_periodos p JOIN c_ejercicios e ON e.id_ejercicio = p.id_ejercicio
                    ORDER BY p.fecha_inicio DESC");
    // Sin período elegido: el de hoy en el ejercicio de trabajo, o el último.
    if (!$idPeriodo) {
        $hoy = date('Y-m-d');
        foreach ($periodos as $p) {
            if ((int) $p['activo'] && $hoy >= $p['fecha_inicio'] && $hoy <= $p['fecha_fin']) {
                $idPeriodo = (int) $p['id_periodo'];
            }
        }
        if (!$idPeriodo && $periodos) {
            $idPeriodo = (int) $periodos[0]['id_periodo'];
        }
    }
    foreach ($periodos as $p) {
        if ((int) $p['id_periodo'] === $idPeriodo) {
            $periodo = $p;
        }
    }
} catch (Throwable $e) {
    $errores[] = ctb_falta_script($e);
}
$base = 'asientos.php?periodo=' . $idPeriodo;

if (es_post() && $periodo) {
    csrf_exigir();
    try {
        if (post_str('accion') === 'generar') {
            [$gen, $salt] = ctb_generar($periodo['fecha_inicio'], $periodo['fecha_fin']);
            flash($gen ? 'ok' : 'warning', ($gen ? num($gen) . ($gen === 1 ? ' asiento generado' : ' asientos generados') : 'No había asientos listos para generar')
                  . ($salt ? '. ' . num($salt) . ($salt === 1 ? ' quedó' : ' quedaron') . ' sin generar por sus avisos.' : '.'));
            redirigir($base . ($gen ? '&solapa=diario' : ''));
        }
        if (post_str('accion') === 'anular') {
            $motivo = trim(post_str('motivo'));
            if ($motivo === '') {
                flash('warning', 'Falta el motivo de la anulación.');
            } else {
                ctb_anular_asiento((int) post_str('id_asiento'), $motivo);
                flash('ok', 'Asiento anulado. Su operación vuelve al libro de trabajo para generarlo otra vez.');
            }
            redirigir($base . '&solapa=diario');
        }
    } catch (RuntimeException $e) {
        flash('warning', $e->getMessage());
        redirigir($base . '&solapa=' . $solapa);
    } catch (Throwable $e) {
        $errores[] = ctb_falta_script($e);
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$cuentas = [];
$trabajo = [];
$diario = [];
$detalle = [];
$cuenta = ['listos' => 0, 'avisos' => 0, 'generados' => 0, 'debe' => 0.0];
if ($periodo) {
    try {
        foreach (q('SELECT id_cuenta, codigo, nombre FROM c_plan_de_cuentas') as $c) {
            $cuentas[(int) $c['id_cuenta']] = $c;
        }
        $trabajo = ctb_derivar($periodo['fecha_inicio'], $periodo['fecha_fin']);
        foreach ($trabajo as $a) {
            if ($a->idAsiento) {
                $cuenta['generados']++;
            } elseif ($a->listo()) {
                $cuenta['listos']++;
                $cuenta['debe'] += $a->debe();
            } else {
                $cuenta['avisos']++;
            }
        }
        if ($verFiltro !== '') {
            $trabajo = array_values(array_filter($trabajo, fn (CtbAsiento $a) =>
                $verFiltro === 'generados' ? (bool) $a->idAsiento
                : ($verFiltro === 'avisos' ? !$a->idAsiento && !$a->listo() : !$a->idAsiento && $a->listo())));
        }
        $diario = q("SELECT a.*, o.tipo_origen, o.rol, u.nombre AS usuario,
                            (SELECT SUM(d.debe) FROM c_asiento_detalle d WHERE d.id_asiento = a.id_asiento) AS debe,
                            (SELECT SUM(d.haber) FROM c_asiento_detalle d WHERE d.id_asiento = a.id_asiento) AS haber
                       FROM c_asientos a
                       LEFT JOIN c_asientos_origen o ON o.id_asiento = a.id_asiento
                       LEFT JOIN usuarios u ON u.id_usuario = a.creado_por
                      WHERE a.id_periodo = ? ORDER BY a.numero, a.id_asiento", [$idPeriodo]);
        foreach (q('SELECT d.* FROM c_asiento_detalle d JOIN c_asientos a ON a.id_asiento = d.id_asiento
                     WHERE a.id_periodo = ? ORDER BY d.id_detalle', [$idPeriodo]) as $d) {
            $detalle[(int) $d['id_asiento']][] = $d;
        }
    } catch (Throwable $e) {
        $errores[] = ctb_falta_script($e);
    }
}

$ctaTxt = fn (?int $id) => $id && isset($cuentas[$id]) ? $cuentas[$id]['codigo'] . ' ' . $cuentas[$id]['nombre'] : '';
$origenTxt = fn (string $t, string $rol) => (CTB_ORIGENES[$t] ?? $t) . ($rol === 'ANULACION' ? ' (anulación)' : '');

// Excel de la solapa
if ($periodo && get_str('export') === 'xlsx' && !$errores) {
    require_once __DIR__ . '/../inc/xlsx.php';
    $libro = new XlsxLibro();
    $filas = [];
    if ($solapa === 'diario') {
        foreach ($diario as $a) {
            foreach ($detalle[(int) $a['id_asiento']] ?? [] as $d) {
                $c = $cuentas[(int) $d['id_cuenta']] ?? ['codigo' => '', 'nombre' => ''];
                $filas[] = [(int) $a['numero'], $a['fecha'], $a['descripcion'], $a['estado'], $c['codigo'], $c['nombre'],
                            (string) $d['descripcion'], (float) $d['debe'], (float) $d['haber']];
            }
        }
        $libro->hoja('Libro diario')->titulo('Libro diario · ' . $periodo['nombre'], $periodo['ejercicio'] . ' · generado el ' . date('d/m/Y H:i'))
              ->tabla([['N.º', 'entero', 7], ['Fecha', 'fecha', 11], ['Asiento', 'texto', 50], ['Estado', 'texto', 11], ['Código', 'texto', 12],
                       ['Cuenta', 'texto', 34], ['Detalle', 'texto', 40], ['Debe', 'moneda', 15], ['Haber', 'moneda', 15]], $filas,
                      [7 => ['suma' => true], 8 => ['suma' => true]]);
    } else {
        foreach ($trabajo as $a) {
            $est = $a->idAsiento ? 'Generado N.º ' . $a->numero : ($a->listo() ? 'Listo' : 'Con avisos');
            foreach ($a->renglones as $r) {
                $c = $r['cuenta'] ? ($cuentas[$r['cuenta']] ?? null) : null;
                $filas[] = [$a->fecha, $origenTxt($a->tipo, $a->rol), $a->descripcion, $est, $c['codigo'] ?? '', $c['nombre'] ?? 'SIN CUENTA',
                            $r['detalle'], $r['debe'], $r['haber'], implode(' ', $a->avisos)];
            }
            if (!$a->renglones) {
                $filas[] = [$a->fecha, $origenTxt($a->tipo, $a->rol), $a->descripcion, $est, '', '', '', 0, 0, implode(' ', $a->avisos)];
            }
        }
        $libro->hoja('Libro de trabajo')->titulo('Libro de trabajo · ' . $periodo['nombre'], $periodo['ejercicio'] . ' · generado el ' . date('d/m/Y H:i'))
              ->tabla([['Fecha', 'fecha', 11], ['Origen', 'texto', 24], ['Asiento', 'texto', 50], ['Estado', 'texto', 14], ['Código', 'texto', 12],
                       ['Cuenta', 'texto', 34], ['Detalle', 'texto', 40], ['Debe', 'moneda', 15], ['Haber', 'moneda', 15], ['Avisos', 'texto', 50]], $filas,
                      [7 => ['suma' => true], 8 => ['suma' => true]]);
    }
    $libro->enviar(($solapa === 'diario' ? 'libro_diario_' : 'libro_trabajo_') . substr($periodo['fecha_inicio'], 0, 7) . '.xlsx');
    exit;
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
layout_inicio('Asientos contables');
titulo('Asientos contables', 'Los asientos que generan las operaciones del período: se revisan en el libro de trabajo y se confirman al diario',
       '', 'Contabilidad / Asientos');
mensajes($errores);

if (!$periodos):
?>
<div class="alert alert-warning"><?= icono('alerta', 16) ?><div>No hay períodos contables. Abrí un ejercicio en
  <a href="ejercicios.php">Ejercicios y períodos</a>.</div></div>
<?php
    layout_fin();
    return;
endif;

$listaPer = [];
foreach ($periodos as $p) {
    $listaPer[(int) $p['id_periodo']] = $p['nombre'] . ' · ' . $p['ejercicio'] . ($p['estado'] !== 'abierto' ? ' (' . $p['estado'] . ')' : '');
}
$abierto = $periodo && $periodo['estado'] === 'abierto';
?>
<div class="card">
  <form method="get" action="asientos.php" class="filter-bar">
    <input type="hidden" name="solapa" value="<?= h($solapa) ?>">
    <?= filtro_select('periodo', 'Período', $listaPer, $idPeriodo) ?>
    <?php if ($solapa === 'trabajo'): ?>
      <?= filtro_select('ver', 'Mostrar', ['pendientes' => 'Listos para generar', 'avisos' => 'Con avisos', 'generados' => 'Ya generados'],
                        $verFiltro, 'Todos', $verFiltro !== '') ?>
    <?php endif; ?>
    <div class="filter-bar-fin">
      <span class="td-muted"><?= $periodo ? fecha($periodo['fecha_inicio']) . ' al ' . fecha($periodo['fecha_fin']) . ' · ' . estado(CTB_ESTADOS[$periodo['estado']] ?? $periodo['estado'],
            $abierto ? 'ok' : 'neutro') : '' ?></span>
      <a class="btn btn-excel btn-sm" href="<?= h(url_con(['export' => 'xlsx'])) ?>"><?= icono('planilla') ?>Exportar a Excel</a>
    </div>
  </form>
</div>

<?= solapas(['trabajo' => ['Libro de trabajo', $base, $cuenta['listos'] + $cuenta['avisos']],
             'diario'  => ['Libro diario', $base . '&solapa=diario', count($diario)]], $solapa) ?>

<?php if ($solapa === 'trabajo'): ?>
<div class="kpi-row">
  <a class="kpi-card<?= $verFiltro === 'pendientes' ? ' activa' : '' ?>" href="<?= h($base . '&ver=pendientes') ?>">
    <div class="kpi-ico ok"><?= icono('ok', 20) ?></div>
    <div><div class="kpi-lbl">Listos para generar</div><div class="kpi-val"><?= num($cuenta['listos']) ?></div>
      <div class="kpi-sub"><?= money($cuenta['debe']) ?></div></div>
  </a>
  <a class="kpi-card<?= $verFiltro === 'avisos' ? ' activa' : '' ?>" href="<?= h($base . '&ver=avisos') ?>">
    <div class="kpi-ico <?= $cuenta['avisos'] ? 'warn' : 'ok' ?>"><?= icono($cuenta['avisos'] ? 'alerta' : 'ok', 20) ?></div>
    <div><div class="kpi-lbl">Con avisos</div><div class="kpi-val"><?= num($cuenta['avisos']) ?></div>
      <div class="kpi-sub">no se generan hasta corregirlos</div></div>
  </a>
  <a class="kpi-card<?= $verFiltro === 'generados' ? ' activa' : '' ?>" href="<?= h($base . '&ver=generados') ?>">
    <div class="kpi-ico"><?= icono('calculadora', 20) ?></div>
    <div><div class="kpi-lbl">Ya generados</div><div class="kpi-val"><?= num($cuenta['generados']) ?></div>
      <div class="kpi-sub">en el libro diario</div></div>
  </a>
</div>

<div class="card">
  <?= card_head('Libro de trabajo', 'cada operación con el asiento que genera',
                $cuenta['listos'] && $abierto
                  ? '<form method="post" action="asientos.php" class="form-en-linea" data-proceso="Generando asientos…" data-confirmar="Se graban en el libro diario '
                    . h(num($cuenta['listos'])) . ' asientos de ' . h($periodo['nombre']) . '. Los que tienen avisos quedan para después." data-confirmar-titulo="Generar asientos" data-confirmar-boton="Generar">'
                    . csrf_campo() . '<input type="hidden" name="accion" value="generar"><input type="hidden" name="periodo" value="' . $idPeriodo . '">'
                    . boton('Generar ' . num($cuenta['listos']) . ($cuenta['listos'] === 1 ? ' asiento' : ' asientos'), 'primary', null, 'ok', true, true) . '</form>'
                  : '') ?>
  <?php if (!$abierto): ?>
    <div class="alert alert-info"><?= icono('info', 16) ?><div>El período está <?= h(mb_strtolower(CTB_ESTADOS[$periodo['estado']] ?? $periodo['estado'])) ?>: no admite asientos nuevos.</div></div>
  <?php endif; ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th>Fecha</th><th>Operación</th><th>Estado</th>
        <th class="th-right">Debe</th><th class="th-right">Haber</th></tr></thead>
      <tbody>
      <?php if (!$trabajo): ?>
        <tr><td colspan="6" class="td-muted">No hay operaciones <?= $verFiltro !== '' ? 'con ese filtro ' : '' ?>en el período.</td></tr>
      <?php endif; ?>
      <?php foreach ($trabajo as $a):
          $est = $a->idAsiento ? estado('N.º ' . $a->numero, 'info') : ($a->listo() ? estado('listo', 'ok') : estado('con avisos', 'warn')); ?>
        <tr>
          <td><div class="acciones"><?= $a->idAsiento ? btn_accion('ver', $base . '&solapa=diario#a' . $a->idAsiento, 'Ver en el libro diario') : '' ?></div></td>
          <td class="td-nowrap"><?= fecha($a->fecha) ?></td>
          <td class="td-wrap"><span class="td-fuerte"><?= h($a->descripcion) ?></span>
            <span class="segunda-linea"><?= h($origenTxt($a->tipo, $a->rol)) ?></span></td>
          <td><?= $est ?></td>
          <td class="td-right"><?= money($a->debe()) ?></td>
          <td class="td-right"><?= money($a->haber()) ?></td>
        </tr>
        <tr class="fila-detalle"><td></td><td colspan="5">
          <?php if ($a->avisos && !$a->idAsiento): ?>
            <div class="alert alert-warning"><?= icono('alerta', 16) ?><div><?= implode('<br>', array_map('h', $a->avisos)) ?></div></div>
          <?php endif; ?>
          <?php if ($a->renglones): ?>
          <table class="sub-tabla">
            <thead><tr><th>Cuenta</th><th>Detalle</th><th class="th-right">Debe</th><th class="th-right">Haber</th></tr></thead>
            <tbody>
            <?php foreach ($a->renglones as $r): ?>
              <tr><td><?= $r['cuenta'] ? '<span class="td-mono">' . h($cuentas[$r['cuenta']]['codigo'] ?? '') . '</span> ' . h($cuentas[$r['cuenta']]['nombre'] ?? '#' . $r['cuenta'])
                                       : '<span class="texto-error">sin cuenta</span>' ?></td>
                <td><?= h($r['detalle']) ?></td>
                <td class="td-right"><?= $r['debe'] ? money($r['debe']) : '' ?></td>
                <td class="td-right"><?= $r['haber'] ? money($r['haber']) : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="card">
  <?= card_head('Libro diario', $periodo ? $periodo['nombre'] . ' · los asientos grabados, en orden de número' : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th class="th-right">N.º</th><th>Fecha</th><th>Asiento</th><th>Estado</th>
        <th class="th-right">Debe</th><th class="th-right">Haber</th></tr></thead>
      <tbody>
      <?php if (!$diario): ?>
        <tr><td colspan="7" class="td-muted">Todavía no hay asientos en el período. Se generan desde el libro de trabajo.</td></tr>
      <?php endif; ?>
      <?php $tot = [0.0, 0.0];
      foreach ($diario as $a):
          $aid = (int) $a['id_asiento'];
          $anul = $a['estado'] === 'anulado';
          if (!$anul) {
              $tot[0] += (float) $a['debe'];
              $tot[1] += (float) $a['haber'];
          } ?>
        <tr id="a<?= $aid ?>"<?= $anul ? ' class="td-muted"' : '' ?>>
          <td><div class="acciones"><?= !$anul && $abierto ? accion_post_motivo($base . '&solapa=diario', ['accion' => 'anular', 'id_asiento' => $aid, 'periodo' => $idPeriodo], 'Anular asiento') : '' ?></div></td>
          <td class="td-right td-fuerte"><?= (int) $a['numero'] ?></td>
          <td class="td-nowrap"><?= fecha($a['fecha']) ?></td>
          <td class="td-wrap"><span class="td-fuerte"><?= h($a['descripcion']) ?></span>
            <span class="segunda-linea"><?= h($a['tipo_origen'] ? $origenTxt($a['tipo_origen'], (string) $a['rol']) : ucfirst($a['tipo'])) ?>
              <?= $a['usuario'] ? ' · ' . h($a['usuario']) : '' ?></span></td>
          <td><?= $anul ? estado('anulado', 'danger') . ($a['motivo_anulacion'] ? '<span class="segunda-linea">' . h($a['motivo_anulacion']) . '</span>' : '')
                        : estado($a['estado'], $a['estado'] === 'confirmado' ? 'ok' : 'neutro') ?></td>
          <td class="td-right"><?= money((float) $a['debe']) ?></td>
          <td class="td-right"><?= money((float) $a['haber']) ?></td>
        </tr>
        <tr class="fila-detalle"><td></td><td colspan="6">
          <table class="sub-tabla">
            <thead><tr><th>Cuenta</th><th>Detalle</th><th class="th-right">Debe</th><th class="th-right">Haber</th></tr></thead>
            <tbody>
            <?php foreach ($detalle[$aid] ?? [] as $d): ?>
              <tr><td><span class="td-mono"><?= h($cuentas[(int) $d['id_cuenta']]['codigo'] ?? '') ?></span> <?= h($cuentas[(int) $d['id_cuenta']]['nombre'] ?? '#' . $d['id_cuenta']) ?></td>
                <td><?= h((string) $d['descripcion']) ?></td>
                <td class="td-right"><?= (float) $d['debe'] ? money((float) $d['debe']) : '' ?></td>
                <td class="td-right"><?= (float) $d['haber'] ? money((float) $d['haber']) : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($diario): ?>
      <tfoot><tr class="total-row"><td colspan="5">Total del período (sin anulados)</td>
        <td class="td-right"><?= money($tot[0]) ?></td><td class="td-right"><?= money($tot[1]) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif;

layout_fin();
