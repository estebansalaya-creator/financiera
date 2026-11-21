<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ordenes_pago.php';

// ---------------------------------------------------------------------
// Transferencias masivas: el archivo Interbanking (PIL, por CBU) con los
// pagos emitidos por transferencia desde una cuenta bancaria (el modelo de
// gestion_adm: transferencias_lote.py; el diseño, en inc\ordenes_pago.php).
//   transferencias.php?cuenta=N           los pagos que faltan mandar: se
//                                         tildan y se genera el archivo
//   transferencias.php?tab=archivos       los archivos generados
//   transferencias.php?lote=N             un archivo: qué llevó y qué quedó
//                                         afuera, volver a bajarlo, sacar un
//                                         pago o anularlo (si no se mandó)
//   transferencias.php?lote=N&archivo=1   la descarga
// El archivo no se guarda: se rearma desde el detalle. Lo que queda es el
// hecho de haberlo generado y el CBU que se usó en cada transferencia.
// ---------------------------------------------------------------------

$errores = [];
$hoy = date('Y-m-d');
$instalado = false;
try {
    $instalado = op_instalado() && (bool) q('SHOW TABLES LIKE \'transferencias_lotes\'');
} catch (Throwable $e) {
}
if (!$instalado) {
    $errores[] = 'Falta crear las órdenes de pago y los archivos de transferencias: corré sql\financiera_ordenes_pago.sql.';
}

function texto_error(Throwable $e): string
{
    return $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e);
}

// La descarga: el archivo rearmado desde su detalle.
if ($instalado && get_str('archivo') === '1' && (int) get_str('lote') > 0) {
    $lote = q1('SELECT * FROM transferencias_lotes WHERE id_lote = ?', [(int) get_str('lote')]);
    if ($lote) {
        try {
            $a = op_contenido_lote($lote);
            header('Content-Type: text/plain; charset=us-ascii');
            header('Content-Disposition: attachment; filename="' . $lote['nombre_archivo'] . '"');
            header('Content-Length: ' . strlen($a['contenido']));
            echo $a['contenido'];
            exit;
        } catch (Throwable $e) {
            flash('danger', 'No se pudo armar el archivo: ' . texto_error($e));
            redirigir('transferencias.php?lote=' . (int) $lote['id_lote']);
        }
    }
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post() && $instalado) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'generar') {
        $idCuenta = (int) post_str('id_cuenta');
        $cuenta = cuentas_fondos('ARS')[$idCuenta] ?? null;
        $sel = array_values(array_filter(array_map('intval', (array) ($_POST['sel'] ?? []))));
        $fecha = leer_fecha(post_str('fecha_solicitud')) ?? '';
        $obs = trim(post_str('observacion'));
        if (!$cuenta) {
            flash('warning', 'Elegí la cuenta que se debita.');
        } elseif (!$sel) {
            flash('warning', 'Tildá los pagos que van en el archivo.');
        } elseif ($fecha === '' || $fecha < $hoy) {
            flash('warning', 'La fecha de solicitud es hoy o una fecha futura.');
        } else {
            try {
                $idLote = op_generar_lote($cuenta, $sel, $fecha, $obs !== '' ? $obs : 'DESEMBOLSOS ' . date('d/m/Y', strtotime($fecha)));
                $l = q1('SELECT * FROM transferencias_lotes WHERE id_lote = ?', [$idLote]);
                flash((int) $l['excluidas'] ? 'warning' : 'ok', 'Archivo ' . $l['nombre_archivo'] . ' generado: ' . num($l['cantidad'])
                    . ((int) $l['cantidad'] === 1 ? ' transferencia' : ' transferencias') . ' por ' . money($l['importe_total'])
                    . ((int) $l['excluidas'] ? '. ' . num($l['excluidas']) . ((int) $l['excluidas'] === 1 ? ' pago quedó afuera' : ' pagos quedaron afuera') . ' (abajo, el motivo)' : '')
                    . '. Subilo en Interbanking: Transferencias › Importar › Por número de CBU.');
                redirigir('transferencias.php?lote=' . $idLote . '&bajar=1');
            } catch (Throwable $e) {
                flash('danger', texto_error($e));
            }
        }
        redirigir('transferencias.php?cuenta=' . $idCuenta);
    }

    if ($accion === 'excluir') {
        $idLote = (int) post_str('id_lote');
        try {
            $r = op_excluir_de_lote($idLote, (int) post_str('id_pago'), post_str('motivo'));
            flash('ok', 'El pago ' . $r['nro_pago'] . ' salió del archivo' . ($r['anulado'] ? ', que quedó vacío y se anuló.' : ': quedan ' . num($r['quedan']) . ' transferencias. Volvé a bajar el archivo antes de subirlo.')
                . ' El pago queda libre para otro archivo o para anularlo.');
        } catch (Throwable $e) {
            flash('danger', texto_error($e));
        }
        redirigir('transferencias.php?lote=' . $idLote);
    }

    if ($accion === 'anular') {
        $idLote = (int) post_str('id_lote');
        try {
            $n = op_anular_lote($idLote, post_str('motivo'));
            flash('ok', 'Archivo anulado: sus ' . num($n) . ' pagos quedan libres para otro archivo o para anularlos.');
        } catch (Throwable $e) {
            flash('danger', texto_error($e));
        }
        redirigir('transferencias.php?lote=' . $idLote);
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$tab = get_str('tab') === 'archivos' ? 'archivos' : 'generar';
$idLote = (int) get_str('lote');
$bancos = [];
$pendientes = [];
$porCuenta = [];
$lote = null;
$det = [];
$lotes = [];
$totLotes = 0;
$pagina = max(1, (int) get_str('p'));
$paginas = 1;
if ($instalado) {
    try {
        $bancos = array_filter(cuentas_fondos('ARS'), function ($c) { return $c['tipo'] === 'B'; });
        // Cuántos pagos faltan mandar por cuenta (para elegir la cuenta y para las solapas).
        foreach (q("SELECT g.id_cuenta_fondos, COUNT(*) AS n, SUM(g.importe) AS importe
                      FROM pagos_emitidos g
                     WHERE g.medio = 'TRANSFERENCIA' AND g.estado = 'EMITIDO' AND COALESCE(g.referencia, '') = ''
                       AND NOT EXISTS (SELECT 1 FROM transferencias_lotes_det d JOIN transferencias_lotes l ON l.id_lote = d.id_lote AND l.anulado_el IS NULL
                                        WHERE d.id_pago_emitido = g.id_pago_emitido AND d.incluida = 1)
                     GROUP BY g.id_cuenta_fondos") as $r) {
            $porCuenta[(int) $r['id_cuenta_fondos']] = $r;
        }
        if ($idLote > 0) {
            $lote = q1('SELECT l.*, c.nombre AS cuenta, u.nombre AS usuario, ua.nombre AS anulado_por_nombre
                          FROM transferencias_lotes l
                          JOIN cuentas_fondos c ON c.id_cuenta_fondos = l.id_cuenta_fondos
                          LEFT JOIN usuarios u ON u.id_usuario = l.creado_por
                          LEFT JOIN usuarios ua ON ua.id_usuario = l.anulado_por
                         WHERE l.id_lote = ?', [$idLote]);
            if ($lote) {
                // debitado_el: el banco ya debitó esa transferencia (su débito está «En el banco»): el archivo se mandó.
                $det = q('SELECT d.*, g.nro_pago, g.fecha AS fecha_pago, g.estado AS estado_pago, o.id_orden, o.nro_orden,
                                 (SELECT MAX(COALESCE(b.fecha_banco, DATE(b.creado_el)))
                                    FROM movimientos_fondos m JOIN debitos_creditos_bancarios b ON b.id_mov_fondos = m.id_mov_fondos
                                   WHERE m.id_pago_emitido = d.id_pago_emitido AND b.estado = \'ACREDITADO\') AS debitado_el
                            FROM transferencias_lotes_det d
                            JOIN pagos_emitidos g ON g.id_pago_emitido = d.id_pago_emitido
                            JOIN ordenes_pago o ON o.id_orden = g.id_orden
                           WHERE d.id_lote = ? ORDER BY d.incluida DESC, d.id_det', [$idLote]);
            }
        } elseif ($tab === 'archivos') {
            $totLotes = (int) q1('SELECT COUNT(*) AS n FROM transferencias_lotes')['n'];
            $paginas = max(1, (int) ceil($totLotes / 25));
            $pagina = min($pagina, $paginas);
            $lotes = q('SELECT l.*, c.nombre AS cuenta, u.nombre AS usuario FROM transferencias_lotes l
                          JOIN cuentas_fondos c ON c.id_cuenta_fondos = l.id_cuenta_fondos
                          LEFT JOIN usuarios u ON u.id_usuario = l.creado_por
                         ORDER BY l.creado_el DESC, l.id_lote DESC LIMIT 25 OFFSET ' . (($pagina - 1) * 25));
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}
$fCuenta = (int) get_str('cuenta');
if (!isset($bancos[$fCuenta])) {
    $fCuenta = 0;
    foreach ($porCuenta as $idc => $r) {                 // la cuenta con más pagos pendientes
        if (isset($bancos[$idc]) && (!$fCuenta || $r['n'] > $porCuenta[$fCuenta]['n'])) {
            $fCuenta = $idc;
        }
    }
    if (!$fCuenta && $bancos) {
        $fCuenta = (int) (cuenta_predeterminada('ARS') ?? 0);
        if (!isset($bancos[$fCuenta])) {
            $fCuenta = (int) array_key_first($bancos);
        }
    }
}
$cuenta = $bancos[$fCuenta] ?? null;
if ($instalado && !$lote && $tab === 'generar' && $cuenta) {
    try {
        $pendientes = array_values(array_filter(op_pendientes_de_archivo($fCuenta), function ($f) { return (string) $f['referencia'] === ''; }));
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

layout_inicio('Transferencias masivas');
if ($lote) {
    titulo('Archivo ' . $lote['nombre_archivo'], $lote['cuenta'] . ' · ' . num($lote['cantidad']) . ' transferencias · ' . money($lote['importe_total']),
           $lote['anulado_el'] === null && (int) $lote['cantidad'] > 0 ? boton('Bajar el archivo', 'primary', 'transferencias.php?lote=' . (int) $lote['id_lote'] . '&archivo=1', 'descargar') : '',
           '<a href="movimientos_fondos.php">Tesorería</a> / <a href="transferencias.php?tab=archivos">Transferencias masivas</a> / ' . h($lote['nombre_archivo']));
} else {
    titulo('Transferencias masivas', 'El archivo de Interbanking con los pagos emitidos por transferencia', '',
           '<a href="movimientos_fondos.php">Tesorería</a> / Transferencias masivas');
}
mensajes($errores);
?>

<?php if ($lote):
    $vivo = $lote['anulado_el'] === null;
    $incl = array_values(array_filter($det, function ($d) { return (int) $d['incluida'] === 1; }));
    $debitadas = array_values(array_filter($incl, function ($d) { return $d['debitado_el'] !== null; }));
    try {
        $contenido = $vivo && $incl ? op_contenido_lote($lote)['contenido'] : '';
    } catch (Throwable $e) {
        $contenido = '';
        alerta('No se pudo armar el archivo: ' . texto_error($e));
    }
?>
<?php if (!$vivo): ?><?php alerta('Archivo anulado el ' . date('d/m/Y H:i', strtotime($lote['anulado_el'])) . ': ' . $lote['motivo_anulacion'] . '. Sus pagos quedaron libres.', 'warning'); ?><?php endif; ?>
<div class="grilla-2">
  <div class="card">
    <?= card_head('Archivo', '', !$vivo ? estado('Anulado', 'neutro') : ($debitadas ? estado('Enviado', 'ok') : estado('Generado', 'ok'))) ?>
    <dl class="datos-lista">
      <dt>Formato</dt><dd>Interbanking · PIL, importación por CBU (registros de 240 posiciones)</dd>
      <dt>Cuenta que se debita</dt><dd><?= h($lote['cuenta']) ?> · CBU <span class="mono"><?= h(cbu_fmt($lote['cbu_ordenante'])) ?></span></dd>
      <dt>Fecha de solicitud</dt><dd><?= h(fecha($lote['fecha_solicitud'])) ?></dd>
      <dt>Observación</dt><dd><?= h((string) $lote['observacion']) ?></dd>
      <dt>Transferencias</dt><dd><strong><?= num($lote['cantidad']) ?></strong> por <strong><?= money($lote['importe_total']) ?></strong><?= (int) $lote['excluidas'] ? ' · ' . num($lote['excluidas']) . ' afuera' : '' ?></dd>
      <dt>Generado</dt><dd><?= h(date('d/m/Y H:i', strtotime($lote['creado_el']))) ?><?= $lote['usuario'] ? ' por ' . h($lote['usuario']) : '' ?></dd>
    </dl>
  </div>
  <?php if ($vivo && $debitadas): ?>
  <div class="card">
    <?= card_head('El archivo se mandó') ?>
    <div class="bloque">
      <p class="nota-arbol">El banco ya debitó <?= count($debitadas) === 1 ? 'una de sus transferencias' : num(count($debitadas)) . ' de sus transferencias' ?>
        (<?= count($debitadas) === 1 ? 'el pago ' : 'los pagos ' ?><?= h(implode(', ', array_column($debitadas, 'nro_pago'))) ?>): ya no se puede anular el archivo.
        Si rechazó alguna, sacala del archivo con la cruz de su renglón: el pago queda libre para otro archivo o para anularlo.</p>
    </div>
  </div>
  <?php elseif ($vivo): ?>
  <form method="post" action="transferencias.php" class="card"
        data-confirmar="El archivo deja de contar como enviado y sus pagos quedan libres (para otro archivo o para anularlos). Solo si NO se subió a Interbanking." data-confirmar-titulo="Anular el archivo" data-confirmar-boton="Anular" data-peligro>
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="anular">
    <input type="hidden" name="id_lote" value="<?= (int) $lote['id_lote'] ?>">
    <?= card_head('Anular el archivo', 'si no se subió al banco') ?>
    <div class="bloque">
      <p class="nota-arbol">Se generó y no se mandó, el banco lo rechazó o se decidió pagar distinto. Si el banco ya debitó alguna de sus transferencias, no se puede: el archivo se mandó.</p>
      <div class="campos"><div class="campo" style="--span:12"><label for="motivo-lote">Motivo <span class="req">*</span></label>
        <input id="motivo-lote" name="motivo" maxlength="200" required minlength="5" placeholder="Por qué no se manda"></div></div>
    </div>
    <div class="form-acciones"><?= boton('Anular el archivo', 'danger', null, 'borrar', true) ?></div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <?= card_head('Lo que lleva el archivo', num(count($incl)) . ' incluidas' . (count($det) > count($incl) ? ' · ' . num(count($det) - count($incl)) . ' afuera' : '')) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Pago</th><th data-no-sort>Beneficiario</th><th data-no-sort>CBU destino</th>
        <th data-no-sort>En el archivo</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
      <tbody>
      <?php foreach ($det as $d): $in = (int) $d['incluida'] === 1; $deb = $in && $d['debitado_el'] !== null; ?>
        <tr<?= $in ? '' : ' class="fila-baja"' ?>>
          <td><div class="acciones">
            <?= btn_accion('ver', 'ordenes_pago.php?id=' . (int) $d['id_orden'], 'Ver la orden') ?>
            <?php if ($deb && $vivo): ?>
              <span class="btn-accion bloqueado" title="El banco ya la debitó: no se puede sacar del archivo"><?= icono('cerrar') ?></span>
            <?php elseif ($in && $vivo): ?>
              <button type="button" class="btn-accion borrar" title="Sacar del archivo" aria-label="Sacar del archivo"
                      data-excluir="<?= (int) $d['id_pago_emitido'] ?>" data-nro="<?= h($d['nro_pago']) ?>"><?= icono('cerrar') ?></button>
            <?php endif; ?>
          </div></td>
          <td class="mono td-fuerte"><?= h($d['nro_pago']) ?><span class="segunda-linea">OP <?= h($d['nro_orden']) ?> · <?= h(fecha($d['fecha_pago'])) ?></span></td>
          <td class="td-wrap"><?= h((string) $d['beneficiario']) ?><?= $d['cuit'] ? '<span class="segunda-linea">' . h(cuit_fmt($d['cuit'])) . '</span>' : '' ?></td>
          <td><?= $d['cbu_destino'] ? datos_bancarios_html($d['cbu_destino'], null) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-wrap"><?= $deb ? estado('Debitada', 'ok') . '<span class="segunda-linea">el ' . h(fecha($d['debitado_el'])) . '</span>'
                                  : ($in ? estado('Incluida', 'ok') : estado('Afuera', 'warn') . '<span class="segunda-linea">' . h((string) $d['motivo_exclusion']) . '</span>') ?></td>
          <td class="td-right"><?= money($d['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="5">TOTAL DEL ARCHIVO</td><td class="td-right"><?= money($lote['importe_total']) ?></td></tr></tfoot>
    </table>
  </div>
</div>

<?php if ($contenido !== ''): ?>
<details class="card plegable">
  <summary class="card-head"><span class="card-head-titulo">Contenido del archivo</span><span class="card-head-nota">como va al banco: <?= num(substr_count($contenido, "\n")) ?> registros de 240 posiciones</span></summary>
  <pre class="archivo-texto"><?= h($contenido) ?></pre>
</details>
<?php endif; ?>

<div class="modal" id="modal-excluir">
  <form method="post" action="transferencias.php" class="modal-caja">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="excluir">
    <input type="hidden" name="id_lote" value="<?= (int) $lote['id_lote'] ?>">
    <input type="hidden" name="id_pago" value="">
    <div class="modal-titulo">Sacar del archivo el pago <span data-nro></span></div>
    <div class="modal-texto">El resto sigue en el archivo: volvé a bajarlo antes de subirlo. El pago queda libre para otro archivo o para anularlo. Si el banco ya lo debitó, no se puede.</div>
    <div class="campo"><label for="motivo-excluir">Motivo <span class="req">*</span></label>
      <input id="motivo-excluir" name="motivo" maxlength="120" required minlength="5" placeholder="Por qué no va"></div>
    <div class="modal-acciones"><button type="button" class="btn btn-secondary" data-cerrar>Cancelar</button>
      <button type="submit" class="btn btn-danger">Sacar del archivo</button></div>
  </form>
</div>
<script>
(function () {
  var modal = document.getElementById('modal-excluir');
  document.querySelectorAll('[data-excluir]').forEach(function (b) {
    b.addEventListener('click', function () {
      modal.querySelector('input[name=id_pago]').value = b.getAttribute('data-excluir');
      modal.querySelector('[data-nro]').textContent = b.getAttribute('data-nro');
      modal.classList.add('abierto');
      modal.querySelector('#motivo-excluir').focus();
    });
  });
  modal.querySelector('[data-cerrar]').addEventListener('click', function () { modal.classList.remove('abierto'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('abierto'); });
  <?php if (get_str('bajar') === '1' && $vivo && $contenido !== ''): ?>
  // Recién generado: se baja solo (una vez).
  setTimeout(function () { window.location.href = 'transferencias.php?lote=<?= (int) $lote['id_lote'] ?>&archivo=1'; }, 400);
  if (history.replaceState) history.replaceState(null, '', 'transferencias.php?lote=<?= (int) $lote['id_lote'] ?>');
  <?php endif; ?>
})();
</script>

<?php elseif ($instalado): ?>
<div class="abm-tabs">
  <a class="solapa <?= $tab === 'generar' ? 'activa' : '' ?>" href="transferencias.php">Generar el archivo
    <?php $nPend = array_sum(array_map(function ($r) { return (int) $r['n']; }, array_intersect_key($porCuenta, $bancos))); ?>
    <?= $nPend ? '<span class="conteo">' . num($nPend) . '</span>' : '' ?></a>
  <a class="solapa <?= $tab === 'archivos' ? 'activa' : '' ?>" href="transferencias.php?tab=archivos">Archivos generados</a>
</div>

<?php if ($tab === 'generar'): ?>
<?php if (!$bancos): ?>
  <?php alerta('No hay cuentas bancarias en pesos. ' . (puede('cuentas_fondos.php') ? 'Cargá una en Tesorería › Cuentas de fondos, con su CBU.' : ''), 'info'); ?>
<?php else: ?>
<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta que se debita</span>
      <?= cuenta_select('cuenta', $bancos, $fCuenta, '', ['class' => 'filtro', 'onchange' => 'this.form.submit()']) ?></label>
    <span class="filtro-info"><?php foreach ($bancos as $idc => $b): if (isset($porCuenta[$idc])): ?>
      <a class="chip<?= $idc === $fCuenta ? ' activo' : '' ?>" href="transferencias.php?cuenta=<?= (int) $idc ?>"><?= h($b['nombre']) ?>: <?= num($porCuenta[$idc]['n']) ?> · <?= money($porCuenta[$idc]['importe']) ?></a>
    <?php endif; endforeach; ?></span>
  </form>
  <?php if ($cuenta && !cbu_valido((string) $cuenta['cbu'])): ?>
    <div class="bloque"><?php alerta($cuenta['nombre'] . ' no tiene un CBU válido. El archivo lleva el CBU de la cuenta que se debita: cargalo en Cuentas de fondos.', 'warning'); ?></div>
  <?php endif; ?>
</div>

<div class="card">
<form method="post" action="transferencias.php" id="frm-lote" data-confirmar="" data-confirmar-titulo="Generar el archivo" data-confirmar-boton="Generar">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="generar">
  <input type="hidden" name="id_cuenta" value="<?= (int) $fCuenta ?>">
  <?= card_head('Pagos por transferencia sin archivo', $cuenta ? 'desde ' . $cuenta['nombre'] . ($cuenta['cbu'] ? ' · CBU ' . cbu_fmt($cuenta['cbu']) : '') : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort><input type="checkbox" id="sel-todos" title="Tildar todos" aria-label="Tildar todos" checked></th>
        <th data-no-sort>Pago</th><th data-no-sort>Beneficiario</th><th data-no-sort>CBU destino</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
      <tbody>
      <?php if (!$pendientes): ?><tr><td colspan="5" class="no-data">No hay pagos por transferencia de esta cuenta esperando el archivo. Se emiten en Órdenes de pago.</td></tr><?php endif; ?>
      <?php foreach ($pendientes as $f): $ok = $f['motivo'] === null; ?>
        <tr<?= $ok ? '' : ' class="fila-baja"' ?>>
          <td><div class="acciones">
            <?php if ($ok): ?><input type="checkbox" name="sel[]" value="<?= (int) $f['id_pago_emitido'] ?>" class="sel-tr" data-importe="<?= h($f['importe']) ?>" checked aria-label="Elegir"><?php endif; ?>
            <?= btn_accion('ver', 'ordenes_pago.php?id=' . (int) $f['id_orden'], 'Ver la orden') ?></div></td>
          <td class="mono td-fuerte"><?= h($f['nro_pago']) ?><span class="segunda-linea">OP <?= h($f['nro_orden']) ?> · <?= h(fecha($f['fecha'])) ?></span></td>
          <td class="td-wrap"><?= h($f['beneficiario']) ?><span class="segunda-linea"><?= $f['cuit'] ? h(cuit_fmt($f['cuit'])) : '' ?><?= $f['nro_prestamo'] ? ' · ' . h($f['nro_prestamo']) : '' ?></span></td>
          <td class="td-wrap"><?php if ($ok): ?><?= datos_bancarios_html($f['cbu_usar'], null) ?><span class="segunda-linea"><?= h($f['cbu_origen']) ?></span>
              <?php else: ?><?= estado('No puede ir', 'warn') ?><span class="segunda-linea"><?= h($f['motivo']) ?></span><?php endif; ?></td>
          <td class="td-right"><?= money($f['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pendientes): ?>
  <div class="barra-seleccion">
    <span class="barra-seleccion-txt"><span id="n-sel">0</span> transferencias · <span id="t-sel">$ 0,00</span></span>
    <label class="filtro-campo"><span class="filtro-lbl">Fecha de solicitud</span>
      <input type="date" name="fecha_solicitud" value="<?= $hoy ?>" min="<?= $hoy ?>" class="filter-input" required></label>
    <label class="filtro-campo"><span class="filtro-lbl">Observación del lote</span>
      <input name="observacion" value="<?= h('DESEMBOLSOS ' . date('d/m/Y')) ?>" maxlength="61" class="filter-input"></label>
    <button type="submit" class="btn btn-primary btn-sm" id="btn-generar" <?= $cuenta && cbu_valido((string) $cuenta['cbu']) ? '' : 'disabled' ?>><?= icono('descargar') ?>Generar archivo Interbanking</button>
  </div>
  <?php endif; ?>
</form>
</div>
<script>
(function () {
  var todos = document.getElementById('sel-todos'), n = document.getElementById('n-sel'), t = document.getElementById('t-sel'),
      boton = document.getElementById('btn-generar'), form = document.getElementById('frm-lote'), casillas = document.querySelectorAll('.sel-tr'),
      habil = boton && !boton.disabled;
  function contar() {
    var c = 0, s = 0;
    casillas.forEach(function (x) { if (x.checked) { c++; s += parseFloat(x.getAttribute('data-importe')); } });
    var txt = '$ ' + s.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (n) n.textContent = c;
    if (t) t.textContent = txt;
    if (boton) boton.disabled = !habil || c === 0;
    form.setAttribute('data-confirmar', 'Se genera el archivo de Interbanking con ' + c + (c === 1 ? ' transferencia' : ' transferencias') + ' por ' + txt
      + '. Desde ese momento esos pagos cuentan como enviados: para anular uno, antes hay que sacarlo del archivo.');
  }
  if (todos) todos.addEventListener('change', function () { casillas.forEach(function (x) { x.checked = todos.checked; }); contar(); });
  casillas.forEach(function (x) { x.addEventListener('change', contar); });
  contar();
})();
</script>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <?= card_head('Archivos generados', num($totLotes) . ($totLotes === 1 ? ' archivo' : ' archivos')) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Generado</th><th data-no-sort>Archivo</th><th data-no-sort>Estado</th>
        <th class="th-right" data-no-sort>Transferencias</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
      <tbody>
      <?php if (!$lotes): ?><tr><td colspan="6" class="no-data">Todavía no se generó ningún archivo.</td></tr><?php endif; ?>
      <?php foreach ($lotes as $l): $vivo = $l['anulado_el'] === null; ?>
        <tr<?= $vivo ? '' : ' class="fila-baja"' ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'transferencias.php?lote=' . (int) $l['id_lote'], 'Ver el archivo') ?>
            <?= $vivo && (int) $l['cantidad'] ? btn_accion('descargar', 'transferencias.php?lote=' . (int) $l['id_lote'] . '&archivo=1', 'Bajar el archivo') : '' ?></div></td>
          <td><?= h(date('d/m/Y H:i', strtotime($l['creado_el']))) ?><span class="segunda-linea"><?= h((string) $l['usuario']) ?></span></td>
          <td class="col-archivo"><span class="archivo-nombre"><?= h($l['nombre_archivo']) ?></span><span class="segunda-linea"><?= h($l['cuenta']) ?> · solicitud <?= h(fecha($l['fecha_solicitud'])) ?></span></td>
          <td class="col-motivo"><?= $vivo ? estado('Generado', 'ok') : estado('Anulado', 'neutro') . '<span class="segunda-linea">' . h((string) $l['motivo_anulacion']) . '</span>' ?></td>
          <td class="td-right"><?= num($l['cantidad']) ?><?= (int) $l['excluidas'] ? '<span class="segunda-linea">' . num($l['excluidas']) . ' afuera</span>' : '' ?></td>
          <td class="td-right"><?= money($l['importe_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $totLotes, 25, 'archivos') ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php layout_fin(); ?>
