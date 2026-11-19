<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/bcra.php';

// ---------------------------------------------------------------------
// Consulta de deudores (BCRA): deuda actual, historial de 24 meses y
// cheques rechazados de cualquier CUIT/CUIL, sea cliente o no.
//   GET  bcra.php?cuit=N             muestra lo último guardado de ese CUIT
//   GET  bcra.php?cuit=N&export=pdf  lo mismo en PDF (otra pestaña)
//   POST bcra.php                    consulta al BCRA (usa la caché salvo «Actualizar»)
//        cliente=N                  vuelve a la ficha del cliente
//   POST accion=legajo cliente=N     guarda el PDF en la documentación del cliente
// ---------------------------------------------------------------------

if (es_post() && post_str('accion') === 'legajo') {
    csrf_exigir();
    require_once __DIR__ . '/../inc/documentos.php';
    $idCliente = (int) post_str('cliente');
    $volver = 'cliente.php?id=' . $idCliente . '&doc=1#documentos';
    try {
        $cli = q1('SELECT id_cliente, cuit, denominacion FROM clientes WHERE id_cliente = ?', [$idCliente]);
        $tipo = q1("SELECT id_tipo_doc FROM documentos_tipos WHERE codigo = 'INFORME_BCRA'");
        $filas = $cli ? bcra_guardadas($cli['cuit']) : [];
        if (!$cli || !$filas) {
            flash('danger', 'No hay una consulta al BCRA guardada para este cliente.');
            redirigir($cli ? 'cliente.php?id=' . $idCliente . '&bcra=1#bcra' : 'clientes.php');
        }
        if (!$tipo) {
            flash('danger', 'Falta el tipo de documento INFORME_BCRA: corré de nuevo sql\financiera_documentos.sql.');
            redirigir($volver);
        }
        $cuando = bcra_cuando($filas);
        $obs = 'Consulta del ' . date('d/m/Y H:i', strtotime($cuando));
        if (q1('SELECT 1 AS x FROM clientes_documentos WHERE id_cliente = ? AND id_tipo_doc = ? AND observacion = ? AND baja_el IS NULL',
               [$idCliente, $tipo['id_tipo_doc'], $obs])) {
            flash('warning', 'El informe de esa consulta (' . $obs . ') ya está en la documentación. Para uno nuevo, primero «Actualizar».');
            redirigir($volver);
        }
        $bin = bcra_informe_pdf($filas, $cli['cuit'], $cli['denominacion'])->salida();
        $carpeta = docs_dir() . DIRECTORY_SEPARATOR . 'clientes' . DIRECTORY_SEPARATOR . $idCliente;
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0770, true) && !is_dir($carpeta)) {
            flash('danger', 'No se pudo crear la carpeta ' . $carpeta . ' (permisos de la carpeta de documentos).');
            redirigir($volver);
        }
        $rel = 'clientes/' . $idCliente . '/' . bin2hex(random_bytes(16)) . '.pdf';
        $dest = $carpeta . DIRECTORY_SEPARATOR . basename($rel);
        if (@file_put_contents($dest, $bin) !== strlen($bin)) {
            flash('danger', 'No se pudo guardar el PDF en ' . $carpeta . ' (permisos de la carpeta de documentos).');
            redirigir($volver);
        }
        try {
            q('INSERT INTO clientes_documentos (id_cliente, id_tipo_doc, nombre_original, archivo, mime, bytes, sha256, fecha_emision, observacion, subido_por)
               VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$idCliente, $tipo['id_tipo_doc'], 'Informe BCRA ' . cuit_fmt($cli['cuit']) . ' ' . date('Y-m-d', strtotime($cuando)) . '.pdf',
               $rel, 'application/pdf', strlen($bin), hash('sha256', $bin), substr($cuando, 0, 10), $obs, usuario_id()]);
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        flash('ok', 'Informe del BCRA guardado en la documentación del cliente.');
    } catch (Throwable $e) {
        flash('danger', mensaje_bd($e));
    }
    redirigir($volver);
}

if (es_post()) {
    csrf_exigir();
    $cuit      = cuit_limpio(post_str('cuit'));
    $idCliente = (int) post_str('cliente') ?: null;
    $forzar    = post_str('forzar') === '1';
    $volver    = $idCliente ? 'cliente.php?id=' . $idCliente . '&bcra=1#bcra' : 'bcra.php?cuit=' . $cuit;

    if (!preg_match('/^\d{11}$/', $cuit)) {
        flash('danger', 'El CUIT/CUIL tiene que tener 11 dígitos.');
        redirigir($idCliente ? $volver : 'bcra.php');
    }
    try {
        if ($idCliente === null) {
            $c = q1('SELECT id_cliente FROM clientes WHERE cuit = ?', [$cuit]);
            $idCliente = $c ? (int) $c['id_cliente'] : null;
        }
        [$filas, $errs, $pedidos] = bcra_consultar($cuit, $idCliente, $forzar);
        if ($errs) {
            flash($filas ? 'warning' : 'danger', implode(' ', $errs) . ($filas ? ' Se muestra la última consulta que se pudo hacer.' : ''));
        } else {
            flash('ok', $pedidos ? 'Consulta al BCRA actualizada.' : 'Se muestra la consulta guardada de hace menos de '
                  . (int) cfg('bcra_cache_horas', 24) . ' horas. «Actualizar» vuelve a consultar.');
        }
    } catch (Throwable $e) {
        flash('danger', $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
            ? 'Falta crear la tabla de consultas: corré sql\financiera_bcra.sql.' : mensaje_bd($e));
    }
    redirigir($volver);
}

$cuit    = cuit_limpio(get_str('cuit'));
$errores = [];

if (get_str('export') === 'pdf' && $cuit !== '') {
    try {
        $filas = bcra_guardadas($cuit);
        $cli = q1('SELECT denominacion FROM clientes WHERE cuit = ?', [$cuit]);
        if ($filas) {
            bcra_informe_pdf($filas, $cuit, $cli['denominacion'] ?? null)
                ->enviar('bcra_' . $cuit . '_' . date('Ymd', strtotime(bcra_cuando($filas))) . '.pdf');
            exit;
        }
        $errores[] = 'No hay una consulta guardada de ese CUIT para pasar a PDF.';
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}
$filas   = [];
$cliente = null;
$recientes = [];
try {
    if ($cuit !== '') {
        $filas   = bcra_guardadas($cuit);
        $cliente = q1('SELECT id_cliente, denominacion FROM clientes WHERE cuit = ?', [$cuit]);
    }
    $recientes = q("SELECT b.cuit, MAX(b.consultado_el) AS consultado_el, MAX(b.denominacion) AS denominacion,
                           MAX(CASE WHEN b.tipo = 'DEUDAS' THEN b.peor_situacion END) AS situacion,
                           MAX(CASE WHEN b.tipo = 'CHEQUES' THEN b.cheques_impagos END) AS impagos,
                           MAX(c.id_cliente) AS id_cliente
                      FROM v_bcra_ultima b LEFT JOIN clientes c ON c.cuit = b.cuit
                     GROUP BY b.cuit ORDER BY consultado_el DESC LIMIT 15");
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear la tabla de consultas: corré sql\financiera_bcra.sql.' : error_amigable($e);
}

layout_inicio('Consulta BCRA', 'bcra');
titulo('Consulta de deudores', 'Central de Deudores del BCRA: deuda actual, historial de 24 meses y cheques rechazados');
mensajes($errores);
?>

<div class="card">
  <form method="post" action="bcra.php" class="filter-bar">
    <?= csrf_campo() ?>
    <label class="filtro-campo"><span class="filtro-lbl">CUIT / CUIL / CDI</span>
      <input name="cuit" value="<?= h(cuit_fmt($cuit)) ?>" class="filter-input mono" maxlength="13" placeholder="20-12345678-9" required autofocus></label>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Consultar</button>
    <?php if ($filas): ?>
      <button type="submit" name="forzar" value="1" class="btn btn-secondary btn-sm" title="Vuelve a consultar aunque haya una consulta reciente">Actualizar</button>
    <?php endif; ?>
    <?php if ($cliente): ?>
      <span class="filter-count" style="margin-left:auto">Cliente: <a href="cliente.php?id=<?= (int) $cliente['id_cliente'] ?>#bcra"><?= h($cliente['denominacion']) ?></a></span>
    <?php endif; ?>
  </form>
</div>

<?php if ($cuit !== ''): ?>
<div class="card">
  <?= card_head(cuit_fmt($cuit), $filas ? '' : 'sin consultas guardadas',
        $filas ? boton('Generar PDF', 'pdf', 'bcra.php?cuit=' . $cuit . '&export=pdf', 'pdf', false, true, ['target' => '_blank', 'rel' => 'noopener']) : '') ?>
  <div class="bloque bcra">
    <?php if ($filas): ?>
      <?= bcra_informe_html($filas) ?>
    <?php else: ?>
      <div class="vacio">Todavía no se consultó este CUIT. Tocá «Consultar».</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <?= card_head('Últimas consultas') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>CUIT / CUIL</th><th data-no-sort>Denominación</th>
        <th data-no-sort>Situación actual</th><th data-no-sort>Cheques sin pagar</th><th data-no-sort>Consultado</th></tr></thead>
      <tbody>
      <?php if (!$recientes): ?><tr><td colspan="6" class="no-data">Todavía no hay consultas.</td></tr><?php endif; ?>
      <?php foreach ($recientes as $r): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('ver', 'bcra.php?cuit=' . $r['cuit'], 'Ver el informe') ?></div></td>
          <td class="mono"><?= h(cuit_fmt($r['cuit'])) ?></td>
          <td><?= h($r['denominacion'] ?? '') ?><?= $r['id_cliente'] ? ' <a class="chip" href="cliente.php?id=' . (int) $r['id_cliente'] . '#bcra">cliente</a>' : '' ?></td>
          <td><?= $r['situacion'] ? bcra_situacion_pill($r['situacion']) : '<span class="td-muted">sin deudas</span>' ?></td>
          <td><?= (int) $r['impagos'] ? estado((string) (int) $r['impagos'], 'danger') : '<span class="td-muted">—</span>' ?></td>
          <td><?= h(date('d/m/Y H:i', strtotime($r['consultado_el']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_fin(); ?>
