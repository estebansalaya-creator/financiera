<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/inversiones.php';

// ---------------------------------------------------------------------
// Inversores: listado con su capital vigente por moneda
// ---------------------------------------------------------------------

$errores = [];
$fQ = trim(get_str('q'));
$fEstado = in_array(get_str('estado'), ['ACTIVO', 'INACTIVO'], true) ? get_str('estado') : '';
$where = '1 = 1';
$params = [];
if ($fQ !== '') {
    $d = preg_replace('/\D/', '', $fQ);
    if (strlen($d) >= 7 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
        $where .= ' AND v.cuit LIKE ?';
        $params[] = '%' . $d . '%';
    } else {
        $where .= ' AND (v.denominacion LIKE ? OR v.email LIKE ?)';
        array_push($params, '%' . addcslashes($fQ, '%_\\') . '%', '%' . addcslashes($fQ, '%_\\') . '%');
    }
}
if ($fEstado !== '') {
    $where .= ' AND v.estado = ?';
    $params[] = $fEstado;
}

$filas = [];
try {
    $filas = q("SELECT v.*,
                       SUM(i.estado = 'VIGENTE') AS vigentes,
                       COALESCE(SUM(IF(i.estado = 'VIGENTE' AND i.moneda = 'ARS', i.capital, 0)), 0) AS cap_ars,
                       COALESCE(SUM(IF(i.estado = 'VIGENTE' AND i.moneda = 'USD', i.capital, 0)), 0) AS cap_usd,
                       COALESCE(SUM(IF(i.moneda = 'ARS', d.interes_devengado, 0)), 0) AS dev_ars,
                       COALESCE(SUM(IF(i.moneda = 'USD', d.interes_devengado, 0)), 0) AS dev_usd,
                       MIN(IF(i.estado = 'VIGENTE', i.fecha_vencimiento, NULL)) AS proximo_vto
                  FROM inversores v
                  LEFT JOIN inversiones i ON i.id_inversor = v.id_inversor
                  LEFT JOIN v_inversiones_devengado d ON d.id_inversion = i.id_inversion
                 WHERE $where
                 GROUP BY v.id_inversor
                 ORDER BY v.denominacion", $params);
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas: corré sql\financiera_inversores.sql.' : error_amigable($e);
}

layout_inicio('Inversores', 'inversiones');
titulo('Inversores', 'Personas y empresas que aportan capital',
       boton('Aportes', 'secondary', 'inversiones.php', 'dinero') . boton('Nuevo inversor', 'primary', 'inversor.php', 'agregar'),
       '<a href="inversiones.php">Inversiones</a> / Inversores');
mensajes($errores);
?>

<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="CUIT, nombre o email…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['ACTIVO' => 'Activos', 'INACTIVO' => 'Inactivos'], $fEstado, 'Todos') ?></select></label>
    <button type="submit" class="btn btn-primary btn-sm"><?= icono('filtro') ?>Filtrar</button>
    <span class="filter-count" style="margin-left:auto"><?= count($filas) ?> inversores</span>
  </form>
</div>

<div class="card">
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Inversor</th><th>CUIT / CUIL</th><th>Contacto</th>
        <th class="th-right">Aportes vigentes</th><th class="th-right">Capital $</th><th class="th-right">Capital US$</th>
        <th>Próximo vencimiento</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="9" class="no-data">Todavía no hay inversores. Cargá el primero con «Nuevo inversor».</td></tr><?php endif; ?>
      <?php foreach ($filas as $v): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'inversor.php?id=' . $v['id_inversor'], 'Abrir la ficha') ?>
            <?= btn_accion('agregar', 'inversion.php?nueva=1&inversor=' . $v['id_inversor'], 'Nuevo aporte') ?></div></td>
          <td class="td-fuerte"><a href="inversor.php?id=<?= (int) $v['id_inversor'] ?>"><?= h($v['denominacion']) ?></a></td>
          <td class="mono"><?= h(cuit_fmt($v['cuit'])) ?></td>
          <td><?= tel_enlace($v['telefono'] ?? null, (string) $v['denominacion']) ?><span class="segunda-linea"><?= h($v['email'] ?? '') ?></span></td>
          <td class="td-right"><?= (int) $v['vigentes'] ?: '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (float) $v['cap_ars'] ? money($v['cap_ars']) . '<span class="segunda-linea">dev. ' . money($v['dev_ars']) . '</span>' : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (float) $v['cap_usd'] ? dinero($v['cap_usd'], 'USD') . '<span class="segunda-linea">dev. ' . dinero($v['dev_usd'], 'USD') . '</span>' : '<span class="td-muted">—</span>' ?></td>
          <td><?= $v['proximo_vto'] ? h(fecha($v['proximo_vto'])) : '<span class="td-muted">—</span>' ?></td>
          <td><?= estado($v['estado'] === 'ACTIVO' ? 'Activo' : 'Inactivo', $v['estado'] === 'ACTIVO' ? 'ok' : 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_fin(); ?>
