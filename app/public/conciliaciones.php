<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/conciliacion.php';

// ---------------------------------------------------------------------
// Conciliación bancaria (inc/conciliacion.php). El circuito de gestion_adm
// (/conc): una conciliación por cuenta banco y mes.
//   conciliaciones.php                 las conciliaciones (y la nueva)
//   conciliaciones.php?id=N            el detalle:
//       solapa por_conciliar           sugerencias, comprobantes a emitir y
//                                      el vínculo a mano (extracto ↔ libro)
//       solapa conciliado              los vínculos, para deshacerlos
//       solapa excepciones             lo del extracto que no se identifica
//   …&export=xlsx                      el extracto con su situación y lo
//                                      pendiente del libro
// Al abrir el detalle se aplican las reglas de Clasificación de conceptos
// a lo que todavía no estaba clasificado.
// ---------------------------------------------------------------------

$errores = [];
$id = (int) (post_str('id') ?: get_str('id'));
$solapa = in_array(get_str('solapa'), ['conciliado', 'excepciones'], true) ? get_str('solapa') : 'por_conciliar';
$k = null;

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $volver = $id ? 'conciliaciones.php?id=' . $id . (post_str('solapa') !== '' ? '&solapa=' . rawurlencode(post_str('solapa')) : '') : 'conciliaciones.php';
    $pdo = db();
    try {
        if ($accion === 'nueva') {
            $saldoTxt = trim(post_str('saldo_inicial'));
            $saldo = $saldoTxt === '' ? null : leer_num($saldoTxt);
            if ($saldoTxt !== '' && $saldo === null) {
                throw new RuntimeException('El saldo inicial no es un importe.');
            }
            $nuevo = conc_nueva((int) post_str('id_cuenta_fondos'), str_replace('-', '', post_str('periodo')), $saldo);
            flash('ok', 'Conciliación abierta.');
            redirigir('conciliaciones.php?id=' . $nuevo);
        }
        $k = $id ? conc_cargar($id) : null;
        if (!$k) {
            flash('danger', 'La conciliación ya no existe.');
            redirigir('conciliaciones.php');
        }
        $pdo->beginTransaction();
        switch ($accion) {
            case 'eliminar':
                conc_eliminar($k);
                $pdo->commit();
                flash('ok', 'Conciliación de ' . conc_periodo_txt($k['periodo']) . ' eliminada.');
                redirigir('conciliaciones.php');
                // no break
            case 'vincular':
                $ext = (array) ($_POST['ext'] ?? []);
                $lib = (array) ($_POST['lib'] ?? []);
                conc_vincular($k, $ext, $lib, 'manual', trim(post_str('observaciones')));
                $msg = 'Conciliado: ' . num(count($ext)) . (count($ext) === 1 ? ' renglón' : ' renglones') . ' del extracto con '
                     . num(count($lib)) . ' del libro.';
                break;
            case 'sugeridos':
                $ext = conc_extracto($k);
                $elegidos = array_map('strval', (array) ($_POST['sug'] ?? []));
                $n = 0;
                foreach (conc_sugerencias($k, $ext, conc_libro($k)) as $s) {
                    if ($elegidos && !in_array($s['ext'] . '-' . $s['lib'], $elegidos, true)) {
                        continue;
                    }
                    conc_vincular($k, [$s['ext']], [$s['lib']], 'sugerido', $s['motivo']);
                    $n++;
                }
                $msg = $n ? num($n) . ($n === 1 ? ' renglón conciliado' : ' renglones conciliados') . ' con las sugerencias.' : 'No había sugerencias para conciliar.';
                break;
            case 'emitir':
                $c = conc_emitir($k, array_map('strval', (array) ($_POST['claves'] ?? [])));
                $partes = [];
                foreach (['GASTO' => ['gasto bancario', 'gastos bancarios'], 'INGRESO' => ['ingreso bancario', 'ingresos bancarios'],
                          'TRANSFERENCIA' => ['transferencia', 'transferencias']] as $t => $et) {
                    if ($c[$t]) {
                        $partes[] = num($c[$t]) . ' ' . $et[$c[$t] === 1 ? 0 : 1];
                    }
                }
                $msg = $partes ? 'Comprobantes emitidos y conciliados: ' . implode(', ', $partes) . '.' : 'No había comprobantes para emitir.';
                break;
            case 'desvincular':
                $g = q1('SELECT origen FROM conciliacion_grupos WHERE id_grupo = ?', [(int) post_str('id_grupo')]);
                conc_desvincular($k, (int) post_str('id_grupo'), 'Se desvinculó en la conciliación de ' . conc_periodo_txt($k['periodo']));
                $msg = $g && $g['origen'] === 'generado' ? 'Desvinculado. El comprobante que había emitido la conciliación quedó anulado.' : 'Desvinculado: los renglones vuelven a estar por conciliar.';
                break;
            case 'excepcion':
                conc_excepcion($k, (int) post_str('id_extracto'), trim(post_str('motivo')));
                $msg = 'Renglón marcado como excepción.';
                break;
            case 'quitar_excepcion':
                conc_quitar_excepcion($k, (int) post_str('id_extracto'));
                $msg = 'El renglón vuelve a estar por conciliar.';
                break;
            case 'cerrar':
                $saldo = conc_cerrar($k);
                $msg = conc_periodo_txt($k['periodo']) . ' cerrado. Saldo final: ' . money($saldo) . '.';
                break;
            case 'reabrir':
                conc_reabrir($k);
                $msg = conc_periodo_txt($k['periodo']) . ' reabierto.';
                break;
            case 'observaciones':
                q('UPDATE conciliaciones SET observaciones = ? WHERE id_conciliacion = ?', [mb_substr(trim(post_str('observaciones')), 0, 500) ?: null, $id]);
                $msg = 'Observaciones guardadas.';
                break;
            default:
                $msg = '';
        }
        $pdo->commit();
        if ($msg !== '') {
            flash('ok', $msg);
        }
        redirigir($volver);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('warning', $e->getMessage());
        redirigir($volver . ($accion === 'nueva' ? '?nueva=1' : ''));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = conc_falta_script($e);
    }
}

// =====================================================================
// Detalle
// =====================================================================
if ($id && !$errores) {
    try {
        $k = conc_cargar($id);
        if (!$k) {
            flash('danger', 'La conciliación no existe.');
            redirigir('conciliaciones.php');
        }
        conc_clasificar_auto($k);
        $ext = conc_extracto($k);
        $lib = conc_libro($k);
        $corte = q1('SELECT MIN(fecha_desde) AS f FROM conciliaciones WHERE id_cuenta_fondos = ?', [(int) $k['id_cuenta_fondos']])['f'];
        $lib = array_filter($lib, fn ($d) => $d['id_grupo'] || $d['fecha'] >= $corte);     // lo anterior está en el saldo inicial
        $res = conc_resumen($k, $ext);
        $abierta = $k['estado'] !== 'cerrado';
        $sug = $abierta ? conc_sugerencias($k, $ext, $lib) : [];
        $emitir = $abierta ? conc_a_emitir($ext) : [];
        $grupos = [];
        foreach (q('SELECT g.*, u.nombre AS usuario FROM conciliacion_grupos g LEFT JOIN usuarios u ON u.id_usuario = g.creado_por
                     WHERE g.id_conciliacion = ? ORDER BY g.id_grupo', [$id]) as $g) {
            $g['ext'] = [];
            $g['lib'] = [];
            $grupos[(int) $g['id_grupo']] = $g;
        }
        foreach ($ext as $e) {
            if ($e['id_grupo'] && isset($grupos[(int) $e['id_grupo']])) {
                $grupos[(int) $e['id_grupo']]['ext'][] = $e;
            }
        }
        foreach ($lib as $d) {
            if ($d['id_grupo'] && isset($grupos[(int) $d['id_grupo']])) {
                $grupos[(int) $d['id_grupo']]['lib'][] = $d;
            }
        }
        $extPend = array_filter($ext, fn ($e) => in_array($e['estado'], ['sin_clasificar', 'pendiente'], true));
        $libPend = array_filter($lib, fn ($d) => !$d['id_grupo']);
        $excep = array_filter($ext, fn ($e) => $e['estado'] === 'excepcion');
        $pendCred = round(array_sum(array_map(fn ($d) => max(0, $d['imp']), $libPend)), 2);
        $pendDeb = round(array_sum(array_map(fn ($d) => max(0, -$d['imp']), $libPend)), 2);

        if (get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $libro = new XlsxLibro();
            $estTxt = ['sin_clasificar' => 'Sin conciliar', 'pendiente' => 'Clasificado, sin conciliar', 'conciliado' => 'Conciliado', 'excepcion' => 'Excepción'];
            $libro->hoja('Extracto')->titulo('Conciliación ' . $k['cuenta'] . ' · ' . conc_periodo_txt($k['periodo']),
                    'Saldo inicial ' . money((float) $k['saldo_inicial']) . ' · saldo final ' . money($res['saldo_final'])
                    . ($res['saldo_banco'] !== null ? ' · banco ' . money($res['saldo_banco']) : '') . ' · generado el ' . date('d/m/Y H:i'))
                  ->tabla([['Fecha', 'fecha', 11], ['Referencia', 'texto', 14], ['Concepto', 'texto', 50], ['Débito', 'moneda', 15], ['Crédito', 'moneda', 15],
                           ['Saldo', 'moneda', 16], ['Situación', 'texto', 22], ['Clasificación', 'texto', 30], ['Observaciones', 'texto', 40]],
                          array_map(fn ($e) => [$e['fecha'], $e['referencia'], $e['concepto'], $e['debito'] !== null ? (float) $e['debito'] : null,
                                                $e['credito'] !== null ? (float) $e['credito'] : null, $e['saldo'] !== null ? (float) $e['saldo'] : null,
                                                $estTxt[$e['estado']] ?? $e['estado'], $e['regla'] ? conc_regla_txt($e['regla']) : '', (string) $e['obs_item']], array_values($ext)),
                          [3 => ['suma' => true], 4 => ['suma' => true]]);
            $libro->hoja('Libro pendiente')->titulo('Libro: lo que el banco todavía no informó', $k['cuenta'] . ' · al ' . fecha($k['fecha_hasta']))
                  ->tabla([['Fecha', 'fecha', 11], ['Origen', 'texto', 18], ['Concepto', 'texto', 60], ['Instrumento', 'texto', 16], ['Referencia', 'texto', 16],
                           ['Débito', 'moneda', 15], ['Crédito', 'moneda', 15]],
                          array_map(fn ($d) => [$d['fecha'], ORIGENES_FONDOS[$d['tipo_origen']] ?? ($d['tipo_origen'] === 'BANCARIO' ? 'Gasto/ingreso bancario' : $d['tipo_origen']),
                                                $d['concepto'], INSTRUMENTOS[$d['tipo_instrumento']] ?? $d['tipo_instrumento'], $d['nro_referencia'],
                                                (float) $d['importe_debito'] ?: null, (float) $d['importe_credito'] ?: null], array_values($libPend)),
                          [5 => ['suma' => true], 6 => ['suma' => true]]);
            $libro->enviar('conciliacion_' . preg_replace('/\W+/', '_', strtolower($k['cuenta'])) . '_' . $k['periodo'] . '.xlsx');
            exit;
        }
    } catch (Throwable $e) {
        $errores[] = conc_falta_script($e);
        $k = null;
    }
}

if ($k):
    $base = 'conciliaciones.php?id=' . $id;
    $listoCerrar = $abierta && !$res['pendientes'] && ($res['diferencia'] === null || abs($res['diferencia']) < 0.005);
    $acciones = boton('Volver', 'secondary', 'conciliaciones.php?cuenta=' . (int) $k['id_cuenta_fondos'], 'volver')
              . boton('Clasificación de conceptos', 'secondary', 'conc_clasificar.php?cuenta=' . (int) $k['id_cuenta_fondos'] . '&desde=' . $k['fecha_desde'] . '&hasta=' . $k['fecha_hasta'], 'etiqueta')
              . '<a class="btn btn-excel" href="' . h($base . '&export=xlsx') . '">' . icono('planilla') . 'Exportar a Excel</a>';
    if ($abierta) {
        $acciones .= '<form method="post" action="conciliaciones.php" class="form-en-linea" data-confirmar="Se cierra ' . h(conc_periodo_txt($k['periodo']))
                   . ' con saldo final ' . h(money($res['saldo_final'])) . '. La conciliación del mes siguiente arranca con ese saldo." data-confirmar-titulo="Cerrar la conciliación" data-confirmar-boton="Cerrar">'
                   . csrf_campo() . '<input type="hidden" name="accion" value="cerrar"><input type="hidden" name="id" value="' . $id . '">'
                   . boton('Cerrar el período', $listoCerrar ? 'primary' : 'secondary', null, 'ok', true, false,
                           $listoCerrar ? [] : ['title' => 'Falta conciliar o el saldo no coincide con el del banco']) . '</form>';
    } else {
        $acciones .= '<form method="post" action="conciliaciones.php" class="form-en-linea" data-confirmar="Se reabre ' . h(conc_periodo_txt($k['periodo']))
                   . '. Los renglones del extracto dejan de figurar como conciliados hasta que se vuelva a cerrar." data-confirmar-titulo="Reabrir" data-confirmar-boton="Reabrir">'
                   . csrf_campo() . '<input type="hidden" name="accion" value="reabrir"><input type="hidden" name="id" value="' . $id . '">'
                   . boton('Reabrir', 'secondary', null, 'editar', true) . '</form>';
    }
    layout_inicio('Conciliación ' . $k['cuenta'] . ' ' . conc_periodo_txt($k['periodo']));
    titulo('Conciliación · ' . $k['cuenta'] . ' · ' . conc_periodo_txt($k['periodo']),
           fecha($k['fecha_desde']) . ' al ' . fecha($k['fecha_hasta']) . ' · ' . CONC_ESTADOS[$k['estado']]
           . ($k['estado'] === 'cerrado' && $k['fecha_cierre'] ? ' el ' . fecha(substr($k['fecha_cierre'], 0, 10)) : ''),
           $acciones, '<a href="conciliaciones.php">Conciliación bancaria</a> / ' . h($k['cuenta']));
    mensajes($errores);
    $dif = $res['diferencia'];
?>
<div class="kpi-row">
  <div class="kpi-card">
    <div class="kpi-ico"><?= icono('banco', 20) ?></div>
    <div><div class="kpi-lbl">Saldo inicial</div><div class="kpi-val monto"><?= money((float) $k['saldo_inicial']) ?></div>
      <div class="kpi-sub"><?= (int) $k['saldo_inicial_manual'] ? 'cargado a mano (primer período)' : 'el final del período anterior' ?></div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-ico"><?= icono('dinero', 20) ?></div>
    <div><div class="kpi-lbl">Extracto del período</div><div class="kpi-val monto">+ <?= money($res['creditos']) ?></div>
      <div class="kpi-sub">− <?= money($res['debitos']) ?> en débitos · <?= num($res['total']) ?> renglones</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-ico <?= $dif === null ? '' : (abs($dif) < 0.005 ? 'ok' : 'danger') ?>"><?= icono($dif === null || abs($dif) < 0.005 ? 'calculadora' : 'alerta', 20) ?></div>
    <div><div class="kpi-lbl">Saldo final</div><div class="kpi-val monto"><?= money($res['saldo_final']) ?></div>
      <div class="kpi-sub"><?= $res['saldo_banco'] === null ? 'el banco no informa saldo'
            : (abs($dif) < 0.005 ? 'igual al que informa el banco' : '<span class="texto-error">banco ' . money($res['saldo_banco']) . ' · diferencia ' . money($dif) . '</span>') ?></div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-ico <?= $res['pendientes'] ? 'warn' : 'ok' ?>"><?= icono($res['pendientes'] ? 'alerta' : 'ok', 20) ?></div>
    <div><div class="kpi-lbl">Conciliados</div><div class="kpi-val"><?= num($res['conciliados']) ?> <span class="kpi-sub">de <?= num($res['total']) ?></span></div>
      <div class="kpi-sub"><?= $res['pendientes'] ? num($res['pendientes']) . ' por conciliar' : 'nada por conciliar' ?><?= $res['excepciones'] ? ' · ' . num($res['excepciones']) . ' excepciones' : '' ?></div></div>
  </div>
</div>

<?= solapas(['por_conciliar' => ['Por conciliar', $base, $res['pendientes'] ?: null],
             'conciliado'    => ['Conciliado', $base . '&solapa=conciliado', count($grupos)],
             'excepciones'   => ['Excepciones', $base . '&solapa=excepciones', count($excep) ?: null]], $solapa) ?>

<?php if ($solapa === 'por_conciliar'): ?>

  <?php if ($sug): ?>
  <form method="post" action="conciliaciones.php">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="sugeridos"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="card">
      <?= card_head('Sugerencias', num(count($sug)) . (count($sug) === 1 ? ' renglón que coincide' : ' renglones que coinciden') . ' con el libro',
                    boton('Conciliar las elegidas', 'primary', null, 'ok', true, true)) ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden data-sin-paginar>
          <thead><tr><th class="col-acciones"><input type="checkbox" checked onclick="document.querySelectorAll('.chk-sug').forEach(c => c.checked = this.checked)" aria-label="Todas"></th>
            <th>Extracto</th><th>Libro</th><th>Por qué</th><th class="th-right">Importe</th></tr></thead>
          <tbody>
          <?php foreach ($sug as $s): $e = $ext[$s['ext']]; $d = $lib[$s['lib']]; ?>
            <tr>
              <td><input type="checkbox" name="sug[]" value="<?= $s['ext'] . '-' . $s['lib'] ?>" class="chk-sug" checked aria-label="Elegir"></td>
              <td class="td-wrap"><?= fecha($e['fecha']) ?> · <?= h(conc_concepto_base($e['concepto'])) ?><span class="segunda-linea mono"><?= h((string) $e['referencia']) ?></span></td>
              <td class="td-wrap"><?= fecha($d['fecha']) ?> · <?= h(mb_strimwidth((string) $d['concepto'], 0, 80, '…')) ?></td>
              <td class="td-muted"><?= h($s['motivo']) ?></td>
              <td class="td-right"><?= importe_saldo($e['imp']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($emitir): ?>
  <form method="post" action="conciliaciones.php" data-proceso="Emitiendo comprobantes…">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="emitir"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="card">
      <?= card_head('Comprobantes a emitir', 'lo que el banco cobró o acreditó por su cuenta, según la Clasificación de conceptos',
                    boton('Emitir los elegidos', 'primary', null, 'agregar', true, true)) ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden data-sin-paginar>
          <thead><tr><th class="col-acciones"><input type="checkbox" checked onclick="document.querySelectorAll('.chk-emi').forEach(c => c.checked = this.checked)" aria-label="Todos"></th>
            <th>Fecha</th><th>Comprobante</th><th>Concepto o cuenta</th><th class="th-right">Renglones</th><th class="th-right">Importe</th></tr></thead>
          <tbody>
          <?php foreach ($emitir as $g): ?>
            <tr>
              <td><?php if ($g['aviso'] === ''): ?><input type="checkbox" name="claves[]" value="<?= h($g['clave']) ?>" class="chk-emi" checked aria-label="Elegir"><?php endif; ?></td>
              <td><?= fecha($g['fecha']) ?></td>
              <td><?= estado(['GASTO' => 'Gasto bancario', 'INGRESO' => 'Ingreso bancario', 'TRANSFERENCIA' => 'Transferencia'][$g['comprobante']],
                             ['GASTO' => 'warn', 'INGRESO' => 'ok', 'TRANSFERENCIA' => 'info'][$g['comprobante']]) ?></td>
              <td class="td-wrap"><span class="td-fuerte"><?= h($g['nombre']) ?></span>
                <span class="segunda-linea"><?= h(implode(' · ', array_unique(array_map(fn ($e) => conc_concepto_base($e['concepto']), $g['renglones'])))) ?></span>
                <?= $g['aviso'] !== '' ? '<span class="segunda-linea texto-error">' . h($g['aviso']) . '</span>' : '' ?></td>
              <td class="td-right"><?= num(count($g['renglones'])) ?></td>
              <td class="td-right"><?= money($g['total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <form method="post" action="conciliaciones.php" id="frm-vincular">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="vincular"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="card">
      <?= card_head('Extracto por conciliar', $extPend ? 'elegí renglones de acá y del libro que sumen lo mismo' : 'todo el extracto está conciliado o es excepción') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden data-sin-paginar>
          <thead><tr><th class="col-acciones">Acciones</th><th>Fecha</th><th>Concepto</th><th>Clasificación</th>
            <th class="th-right">Débito</th><th class="th-right">Crédito</th></tr></thead>
          <tbody>
          <?php if (!$extPend): ?><tr><td colspan="6" class="no-data">Nada por conciliar en el extracto.</td></tr><?php endif; ?>
          <?php foreach ($extPend as $e): ?>
            <tr>
              <td><div class="acciones">
                <?php if ($abierta): ?>
                <input type="checkbox" name="ext[]" value="<?= (int) $e['id_extracto'] ?>" class="chk-ext" data-imp="<?= h((string) $e['imp']) ?>" aria-label="Elegir">
                <button type="button" class="btn-accion" title="Marcar como excepción" aria-label="Marcar como excepción" onclick="concExcepcion(<?= (int) $e['id_extracto'] ?>)"><?= icono('alerta') ?></button>
                <?php endif; ?>
              </div></td>
              <td class="td-nowrap"><?= fecha($e['fecha']) ?></td>
              <td class="td-wrap"><?= h((string) $e['concepto']) ?><span class="segunda-linea mono"><?= h((string) $e['referencia']) ?></span></td>
              <td><?= $e['regla'] ? estado(conc_regla_txt($e['regla']), $e['regla']['tipo_resultado'] === 'manual' ? 'neutro' : 'info')
                                    . ((int) $e['regla']['genera_comprobante'] && $e['regla']['tipo_resultado'] !== 'manual' ? '<span class="segunda-linea">se emite arriba</span>' : '')
                                  : '<span class="td-muted">sin clasificar</span>' ?></td>
              <td class="td-right"><?= $e['debito'] !== null ? money((float) $e['debito']) : '' ?></td>
              <td class="td-right"><?= $e['credito'] !== null ? money((float) $e['credito']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <?= card_head('Libro por conciliar', $libPend ? 'débitos y créditos de ' . $k['cuenta'] . ' que el banco todavía no informó · créditos ' . money($pendCred) . ' · débitos ' . money($pendDeb)
                                                   : 'todo lo del libro está conciliado') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden data-sin-paginar>
          <thead><tr><th class="col-acciones">Acciones</th><th>Fecha</th><th>Movimiento</th><th>Instrumento</th>
            <th class="th-right">Débito</th><th class="th-right">Crédito</th></tr></thead>
          <tbody>
          <?php if (!$libPend): ?><tr><td colspan="6" class="no-data">Nada pendiente en el libro.</td></tr><?php endif; ?>
          <?php foreach ($libPend as $d): $url = origen_fondos_url($d + ['id_inversion_liq' => null]); ?>
            <tr>
              <td><div class="acciones"><?php if ($abierta): ?><input type="checkbox" name="lib[]" value="<?= (int) $d['id_deb_cred'] ?>" class="chk-lib" data-imp="<?= h((string) $d['imp']) ?>" aria-label="Elegir"><?php endif; ?>
                <?= $url ? btn_accion('ver', $url, 'Ver el comprobante', true) : '' ?></div></td>
              <td class="td-nowrap"><?= fecha($d['fecha']) ?><?= $d['fecha'] < $k['fecha_desde'] ? '<span class="segunda-linea texto-aviso">de un período anterior</span>' : '' ?></td>
              <td class="td-wrap"><?= h((string) $d['concepto']) ?></td>
              <td><?= h(INSTRUMENTOS[$d['tipo_instrumento']] ?? $d['tipo_instrumento']) ?><?= $d['nro_referencia'] ? '<span class="segunda-linea mono">' . h($d['nro_referencia']) . '</span>' : '' ?></td>
              <td class="td-right"><?= (float) $d['importe_debito'] ? money((float) $d['importe_debito']) : '' ?></td>
              <td class="td-right"><?= (float) $d['importe_credito'] ? money((float) $d['importe_credito']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($abierta && $extPend && $libPend): ?>
      <div class="barra-seleccion fija">
        <span class="barra-seleccion-txt">Extracto <strong id="s-ext">$ 0,00</strong> · Libro <strong id="s-lib">$ 0,00</strong> · Diferencia <strong id="s-dif">$ 0,00</strong></span>
        <label class="filtro-campo"><span class="filtro-lbl">Observación</span>
          <input name="observaciones" maxlength="300" class="filter-input" placeholder="opcional"></label>
        <button type="submit" class="btn btn-primary btn-sm" id="btn-vincular" disabled><?= icono('ok') ?>Conciliar lo elegido</button>
      </div>
      <?php endif; ?>
    </div>
  </form>

  <form method="post" action="conciliaciones.php" id="frm-excepcion" hidden>
    <?= csrf_campo() ?><input type="hidden" name="accion" value="excepcion"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="id_extracto"><input type="hidden" name="motivo">
  </form>
  <script>
  (function () {
    var fmt = new Intl.NumberFormat('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var pesos = function (n) { return (n < 0 ? '−$ ' : '$ ') + fmt.format(Math.abs(n)); };
    var suma = function (sel) { var t = 0; document.querySelectorAll(sel + ':checked').forEach(function (c) { t += parseFloat(c.dataset.imp); }); return Math.round(t * 100) / 100; };
    var act = function () {
      var e = suma('.chk-ext'), l = suma('.chk-lib'), d = Math.round((e - l) * 100) / 100, b = document.getElementById('btn-vincular');
      if (!b) { return; }
      document.getElementById('s-ext').textContent = pesos(e);
      document.getElementById('s-lib').textContent = pesos(l);
      document.getElementById('s-dif').textContent = pesos(d);
      document.getElementById('s-dif').className = d === 0 ? '' : 'texto-error';
      b.disabled = !(document.querySelector('.chk-ext:checked') && document.querySelector('.chk-lib:checked') && d === 0);
    };
    document.querySelectorAll('.chk-ext, .chk-lib').forEach(function (c) {
      c.addEventListener('change', function () { c.closest('tr').classList.toggle('fila-elegida', c.checked); act(); });
    });
    window.concExcepcion = function (idExt) {
      var m = prompt('Excepción: ¿por qué no se concilia este renglón? (por ejemplo, «error del banco, reclamado» o «sin identificar»)');
      if (!m) { return; }
      var f = document.getElementById('frm-excepcion');
      f.id_extracto.value = idExt; f.motivo.value = m; f.submit();
    };
  })();
  </script>

<?php elseif ($solapa === 'conciliado'): ?>
<div class="card">
  <?= card_head('Conciliado', 'cada vínculo une renglones del extracto y del libro por el mismo importe') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th>Extracto</th><th>Libro</th><th>Cómo</th><th class="th-right">Importe</th></tr></thead>
      <tbody>
      <?php if (!$grupos): ?><tr><td colspan="5" class="no-data">Todavía no hay nada conciliado.</td></tr><?php endif; ?>
      <?php foreach ($grupos as $g): ?>
        <tr>
          <td><div class="acciones"><?= $abierta ? accion_post('conciliaciones.php', ['accion' => 'desvincular', 'id' => $id, 'id_grupo' => (int) $g['id_grupo'], 'solapa' => 'conciliado'],
                'cerrar', 'Desvincular', $g['origen'] === 'generado' ? 'Se desvincula y se ANULA el comprobante que emitió la conciliación. El renglón del extracto vuelve a quedar para emitirlo.'
                                                                     : 'Los renglones vuelven a estar por conciliar.', 'borrar') : '' ?></div></td>
          <td class="td-wrap"><?php foreach ($g['ext'] as $e): ?><div><?= fecha($e['fecha']) ?> · <?= h(conc_concepto_base($e['concepto'])) ?> <span class="td-muted"><?= money($e['imp']) ?></span></div><?php endforeach; ?></td>
          <td class="td-wrap"><?php foreach ($g['lib'] as $d): $url = origen_fondos_url($d + ['id_inversion_liq' => null]); ?>
            <div><?= fecha($d['fecha']) ?> · <?= $url ? '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h(mb_strimwidth((string) $d['concepto'], 0, 70, '…')) . '</a>' : h(mb_strimwidth((string) $d['concepto'], 0, 70, '…')) ?>
              <span class="td-muted"><?= money($d['imp']) ?></span></div><?php endforeach; ?></td>
          <td><?= estado(['manual' => 'A mano', 'sugerido' => 'Sugerido', 'generado' => 'Comprobante emitido'][$g['origen']], $g['origen'] === 'generado' ? 'info' : 'neutro') ?>
            <span class="segunda-linea"><?= h(trim(($g['observaciones'] ?? '') . ($g['usuario'] ? ' · ' . $g['usuario'] : ''), ' ·')) ?></span></td>
          <td class="td-right"><?= importe_saldo((float) $g['importe_extracto']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="card">
  <?= card_head('Excepciones', 'renglones del extracto que no están en el libro y no se van a registrar en este período') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th>Fecha</th><th>Concepto</th><th>Motivo</th><th class="th-right">Importe</th></tr></thead>
      <tbody>
      <?php if (!$excep): ?><tr><td colspan="5" class="no-data">No hay excepciones.</td></tr><?php endif; ?>
      <?php foreach ($excep as $e): ?>
        <tr>
          <td><div class="acciones"><?= $abierta ? accion_post('conciliaciones.php', ['accion' => 'quitar_excepcion', 'id' => $id, 'id_extracto' => (int) $e['id_extracto'], 'solapa' => 'excepciones'],
                                                              'cerrar', 'Quitar la excepción') : '' ?></div></td>
          <td><?= fecha($e['fecha']) ?></td>
          <td class="td-wrap"><?= h((string) $e['concepto']) ?><span class="segunda-linea mono"><?= h((string) $e['referencia']) ?></span></td>
          <td class="td-wrap"><?= h((string) $e['obs_item']) ?></td>
          <td class="td-right"><?= importe_saldo($e['imp']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <?= card_head('Observaciones') ?>
  <form method="post" action="conciliaciones.php" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="observaciones"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="solapa" value="<?= h($solapa) ?>">
    <div class="campos"><div class="campo" style="--span:10"><textarea name="observaciones" rows="2" maxlength="500"><?= h((string) $k['observaciones']) ?></textarea></div>
      <div class="campo campo-boton" style="--span:2"><?= boton('Guardar', 'secondary', null, 'guardar', true, true) ?></div></div>
  </form>
</div>
<?php
    layout_fin();
    return;
endif;

// =====================================================================
// Lista y alta
// =====================================================================
$fCuenta = (int) get_str('cuenta');
$fEstado = isset(CONC_ESTADOS[get_str('estado')]) ? get_str('estado') : '';
$lista = [];
$bancos = [];
$propuesta = [];
try {
    $bancos = array_filter(cuentas_fondos(), fn ($c) => $c['tipo'] === 'B');
    $w = [];
    $p = [];
    if ($fCuenta) {
        $w[] = 'k.id_cuenta_fondos = ?';
        $p[] = $fCuenta;
    }
    if ($fEstado !== '') {
        $w[] = 'k.estado = ?';
        $p[] = $fEstado;
    }
    $lista = q('SELECT k.*, c.nombre AS cuenta,
                       (SELECT COUNT(*) FROM extractos_bancarios e WHERE e.id_cuenta_fondos = k.id_cuenta_fondos AND e.fecha BETWEEN k.fecha_desde AND k.fecha_hasta) AS renglones,
                       (SELECT COUNT(*) FROM conciliacion_items i WHERE i.id_conciliacion = k.id_conciliacion AND i.estado IN (\'conciliado\', \'excepcion\')) AS resueltos,
                       (SELECT COALESCE(SUM(e.credito), 0) FROM extractos_bancarios e WHERE e.id_cuenta_fondos = k.id_cuenta_fondos AND e.fecha BETWEEN k.fecha_desde AND k.fecha_hasta) AS cred,
                       (SELECT COALESCE(SUM(e.debito), 0) FROM extractos_bancarios e WHERE e.id_cuenta_fondos = k.id_cuenta_fondos AND e.fecha BETWEEN k.fecha_desde AND k.fecha_hasta) AS deb
                  FROM conciliaciones k JOIN cuentas_fondos c ON c.id_cuenta_fondos = k.id_cuenta_fondos'
               . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.nombre, k.periodo DESC', $p);
    // Lo que se propone para la nueva, por cuenta: el mes que sigue y de dónde sale el saldo.
    foreach ($bancos as $b) {
        $cid = (int) $b['id_cuenta_fondos'];
        $ult = q1('SELECT periodo, estado FROM conciliaciones WHERE id_cuenta_fondos = ? ORDER BY periodo DESC LIMIT 1', [$cid]);
        if ($ult) {
            $sig = (new DateTimeImmutable(substr($ult['periodo'], 0, 4) . '-' . substr($ult['periodo'], 4, 2) . '-01'))->modify('+1 month');
        } else {
            $pri = q1('SELECT MIN(fecha) AS f FROM extractos_bancarios WHERE id_cuenta_fondos = ?', [$cid]);
            $sig = new DateTimeImmutable(($pri['f'] ? substr($pri['f'], 0, 7) : date('Y-m')) . '-01');
        }
        $ant = conc_anterior_cerrada($cid);
        $propuesta[$cid] = ['periodo' => $sig->format('Y-m'), 'abierta' => $ult && $ult['estado'] !== 'cerrado' ? conc_periodo_txt($ult['periodo']) : '',
                            'anterior' => $ant ? conc_periodo_txt($ant['periodo']) : '', 'saldo' => $ant ? (float) $ant['saldo_final'] : conc_saldo_sugerido($cid, $sig->format('Y-m-d'))];
    }
} catch (Throwable $e) {
    $errores[] = conc_falta_script($e);
}

layout_inicio('Conciliación bancaria');
titulo('Conciliación bancaria', 'El extracto de cada cuenta banco contra el libro, mes por mes',
       boton('Clasificación de conceptos', 'secondary', 'conc_clasificar.php', 'etiqueta') . (get_str('nueva') === '' ? boton('Nueva conciliación', 'primary', 'conciliaciones.php?nueva=1', 'agregar') : ''),
       'Tesorería / Conciliación bancaria');
mensajes($errores);

if (get_str('nueva') !== '' && $bancos):
    $cSel = $fCuenta ?: (int) reset($bancos)['id_cuenta_fondos'];
?>
<form method="post" action="conciliaciones.php" autocomplete="off" id="frm-nueva">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="nueva">
  <div class="card">
    <?= card_head('Nueva conciliación', 'una por cuenta y mes, en orden', boton('Volver', 'secondary', 'conciliaciones.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="id_cuenta_fondos">Cuenta banco <span class="req">*</span></label>
          <select id="id_cuenta_fondos" name="id_cuenta_fondos" required>
          <?php foreach ($bancos as $b): $pr = $propuesta[(int) $b['id_cuenta_fondos']]; ?>
            <option value="<?= (int) $b['id_cuenta_fondos'] ?>" <?= (int) $b['id_cuenta_fondos'] === $cSel ? 'selected' : '' ?>
                    data-periodo="<?= h($pr['periodo']) ?>" data-anterior="<?= h($pr['anterior']) ?>" data-abierta="<?= h($pr['abierta']) ?>"
                    data-saldo="<?= $pr['saldo'] === null ? '' : h(number_format($pr['saldo'], 2, ',', '.')) ?>"><?= h($b['nombre'] . ' (' . $b['moneda'] . ')') ?></option>
          <?php endforeach; ?>
          </select></div>
        <div class="campo" style="--span:2"><label for="periodo">Mes <span class="req">*</span></label>
          <input type="month" id="periodo" name="periodo" required></div>
        <div class="campo" style="--span:3"><label for="saldo_inicial">Saldo inicial</label>
          <input id="saldo_inicial" name="saldo_inicial" inputmode="decimal" class="derecha">
          <span class="campo-ayuda" id="saldo-ayuda"></span></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Abrir la conciliación', 'primary', null, 'ok', true) ?></div>
  </div>
</form>
<script>
(function () {
  var s = document.getElementById('id_cuenta_fondos'), per = document.getElementById('periodo'), saldo = document.getElementById('saldo_inicial'),
      ayuda = document.getElementById('saldo-ayuda');
  var act = function () {
    var o = s.options[s.selectedIndex].dataset;
    per.value = o.periodo;
    if (o.anterior) {
      saldo.value = o.saldo; saldo.readOnly = true;
      ayuda.textContent = 'el saldo final de ' + o.anterior + ' (cerrada)';
    } else {
      saldo.value = o.saldo; saldo.readOnly = false;
      ayuda.textContent = 'primera conciliación de la cuenta: el saldo del banco al cierre del mes anterior' + (o.saldo ? ' (propuesto con el extracto)' : '');
    }
    if (o.abierta) { ayuda.textContent = 'Primero hay que cerrar ' + o.abierta + '.'; }
  };
  s.addEventListener('change', act);
  act();
})();
</script>
<?php endif; ?>

<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $bancos, $fCuenta ?: '', 'Todas', ['class' => 'filtro' . ($fCuenta ? ' activo' : ''), 'onchange' => 'this.form.submit()']) ?></label>
    <?= filtro_select('estado', 'Estado', CONC_ESTADOS, $fEstado, 'Todas', $fEstado !== '') ?>
    <?= filtro_fin(['total' => count($lista), 'unidad' => 'conciliaciones']) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th>Cuenta</th><th>Mes</th><th class="th-right">Saldo inicial</th>
        <th class="th-right">Créditos</th><th class="th-right">Débitos</th><th class="th-right">Saldo final</th><th>Avance</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$lista): ?><tr><td colspan="9" class="no-data">Todavía no hay conciliaciones<?= $bancos ? '. Abrí la primera con «Nueva conciliación».' : ': hace falta una cuenta banco.' ?></td></tr><?php endif; ?>
      <?php foreach ($lista as $c): $cid = (int) $c['id_conciliacion'];
          $sf = (float) $c['saldo_inicial'] + (float) $c['cred'] - (float) $c['deb']; ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('ver', 'conciliaciones.php?id=' . $cid, 'Abrir') ?>
            <?= $c['estado'] !== 'cerrado' && !(int) $c['resueltos'] ? accion_post('conciliaciones.php', ['accion' => 'eliminar', 'id' => $cid], 'borrar', 'Eliminar',
                  'Se elimina la conciliación de ' . conc_periodo_txt($c['periodo']) . '. No tiene nada conciliado.', 'borrar') : '' ?></div></td>
          <td class="td-fuerte"><a href="conciliaciones.php?id=<?= $cid ?>"><?= h($c['cuenta']) ?></a></td>
          <td><?= h(conc_periodo_txt($c['periodo'])) ?></td>
          <td class="td-right"><?= money((float) $c['saldo_inicial']) ?></td>
          <td class="td-right"><?= money((float) $c['cred']) ?></td>
          <td class="td-right"><?= money((float) $c['deb']) ?></td>
          <td class="td-right td-fuerte"><?= money($c['estado'] === 'cerrado' ? (float) $c['saldo_final'] : $sf) ?></td>
          <td><?= num($c['resueltos']) ?> de <?= num($c['renglones']) ?><?php if ((int) $c['renglones']): ?>
            <span class="segunda-linea"><?= num(round(100 * (int) $c['resueltos'] / (int) $c['renglones'])) ?> %</span><?php endif; ?></td>
          <td><?= conc_estado($c['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_fin(); ?>
