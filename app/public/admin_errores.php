<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Log de errores: qué falló, en qué pantalla, a quién y cuándo
// (inc\log_errores.php). Por defecto, lo pendiente de revisar. El mismo
// error del mismo usuario dentro de 5 minutos es una sola fila con «veces».
// Lo revisado se marca (de a uno desde el detalle, o tildados) para que
// quede solo lo pendiente. La pantalla de errores de gestion_adm
// (/sistema/errores).
// ---------------------------------------------------------------------

if (es_post()) {
    csrf_exigir();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['id'] ?? [])), function ($n) { return $n > 0; }));
    $revisado = post_str('revisado') === '0' ? 0 : 1;
    $volver = post_str('volver');
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE log_errores SET revisado = ?, revisado_por = ?, revisado_el = IF(?, NOW(), NULL) WHERE id IN ($marcas)",
          array_merge([$revisado, $revisado ? (usuario_actual()['nombre'] ?? null) : null, $revisado], $ids));
        flash('ok', $revisado ? count($ids) . (count($ids) === 1 ? ' error marcado como revisado.' : ' errores marcados como revisados.')
                              : 'El error vuelve a pendientes.');
    } else {
        flash('warning', 'Tildá los errores que ya revisaste.');
    }
    redirigir(url_local($volver) ? $volver : 'admin_errores.php');
}

// ---------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------

$fQ      = trim(get_str('q'));
$fEstado = in_array(get_str('estado'), ['revisados', 'todos'], true) ? get_str('estado') : 'pendientes';
$fUsu    = (int) get_str('usuario');
$fOrigen = isset(LOGERR_ORIGENES[get_str('origen')]) ? get_str('origen') : '';
$fDesde  = leer_fecha(get_str('desde')) ?? '';
$fHasta  = leer_fecha(get_str('hasta')) ?? '';
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

$where = ['1=1'];
$par = [];
if ($fQ !== '') {
    $where[] = '(e.mensaje LIKE ? OR e.tipo LIKE ? OR e.url LIKE ? OR e.lugar LIKE ? OR e.id = ?)';
    $like = '%' . $fQ . '%';
    array_push($par, $like, $like, $like, $like, (int) ltrim($fQ, '#'));
}
if ($fUsu) {
    $where[] = 'e.id_usuario = ?';
    $par[] = $fUsu;
}
if ($fOrigen !== '') {
    $where[] = 'e.origen = ?';
    $par[] = $fOrigen;
}
if ($fDesde !== '') {
    $where[] = 'e.ultima >= ?';
    $par[] = $fDesde;
}
if ($fHasta !== '') {
    $where[] = 'e.fecha < ? + INTERVAL 1 DAY';
    $par[] = $fHasta;
}
if ($fEstado !== 'todos') {
    $where[] = 'e.revisado = ' . ($fEstado === 'revisados' ? 1 : 0);
}
$w = implode(' AND ', $where);
$hayFiltros = $fQ !== '' || $fUsu || $fOrigen !== '' || $fDesde !== '' || $fHasta !== '' || $fEstado !== 'pendientes';

$filas = [];
$total = 0;
$res = [];
$usuarios = [];
$error = null;
try {
    $total = (int) q1("SELECT COUNT(*) AS n FROM log_errores e WHERE $w", $par)['n'];
    $paginas = max(1, (int) ceil($total / $porPag));
    $pagina = min($pagina, $paginas);
    $filas = q("SELECT e.* FROM log_errores e WHERE $w ORDER BY e.ultima DESC, e.id DESC
                LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag), $par);
    $res = q1('SELECT SUM(revisado = 0) AS pendientes, SUM(ultima >= CURDATE()) AS hoy,
                      SUM(ultima >= CURDATE() - INTERVAL 6 DAY) AS semana,
                      COUNT(DISTINCT CASE WHEN revisado = 0 THEN firma END) AS distintos
                 FROM log_errores') ?: [];
    $usuarios = q('SELECT id_usuario, MAX(usuario) AS usuario FROM log_errores
                    WHERE id_usuario IS NOT NULL GROUP BY id_usuario ORDER BY usuario');
} catch (PDOException $e) {
    $error = (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta la tabla del log: corré sql\financiera_log_errores.sql.' : error_amigable($e);
}
$paginas = max(1, (int) ceil($total / $porPag));
$volver = basename((string) ($_SERVER['REQUEST_URI'] ?? 'admin_errores.php'));

function fh(?string $iso, string $fmt = 'd/m/Y H:i:s'): string
{
    return $iso ? date($fmt, strtotime($iso)) : '';
}

layout_inicio('Log de errores');
titulo('Log de errores', 'Lo que falló en el sistema: el error, la pantalla, quién estaba y cuándo. Lo revisado se marca para que quede solo lo pendiente.');
mensajes($error ? [$error] : []);

if (!$error):
?>
<div class="kpi-row">
  <a class="kpi-card" href="admin_errores.php">
    <div class="kpi-ico <?= (int) ($res['pendientes'] ?? 0) ? 'warn' : 'ok' ?>"><?= icono((int) ($res['pendientes'] ?? 0) ? 'alerta' : 'ok', 20) ?></div>
    <div><div class="kpi-lbl">Pendientes de revisar</div><div class="kpi-val"><?= num($res['pendientes'] ?? 0) ?></div>
      <div class="kpi-sub"><?= num($res['distintos'] ?? 0) ?> <?= (int) ($res['distintos'] ?? 0) === 1 ? 'error distinto' : 'errores distintos' ?></div></div>
  </a>
  <a class="kpi-card" href="admin_errores.php?estado=todos&amp;desde=<?= date('Y-m-d') ?>">
    <div class="kpi-ico"><?= icono('calendario', 20) ?></div>
    <div><div class="kpi-lbl">Hoy</div><div class="kpi-val"><?= num($res['hoy'] ?? 0) ?></div>
      <div class="kpi-sub">revisados o no</div></div>
  </a>
  <div class="kpi-card">
    <div class="kpi-ico"><?= icono('grafico', 20) ?></div>
    <div><div class="kpi-lbl">Últimos 7 días</div><div class="kpi-val"><?= num($res['semana'] ?? 0) ?></div>
      <div class="kpi-sub">se guardan <?= LOGERR_RETENCION_DIAS ?> días</div></div>
  </div>
</div>

<div class="card">
  <form method="get" action="admin_errores.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Error, pantalla o código</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Buscar…"></label>
    <?= filtro_select('estado', 'Estado', ['pendientes' => 'Pendientes', 'revisados' => 'Revisados', 'todos' => 'Todos'], $fEstado, '', $fEstado !== 'pendientes') ?>
    <?= filtro_select('usuario', 'Usuario', array_column($usuarios, 'usuario', 'id_usuario'), $fUsu ?: '', 'Todos', (bool) $fUsu) ?>
    <?= filtro_select('origen', 'Tipo', array_map(function ($o) { return $o[0]; }, LOGERR_ORIGENES), $fOrigen, 'Todos', $fOrigen !== '') ?>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro <?= $fDesde !== '' ? 'activo' : '' ?>"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro <?= $fHasta !== '' ? 'activo' : '' ?>"></label>
    <?= filtro_botones('admin_errores.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'errores', 'filas' => true]) ?>
  </form>
</div>

<form method="post" action="admin_errores.php" class="card" id="frm-revisar">
  <?= csrf_campo() ?>
  <input type="hidden" name="volver" value="<?= h($volver) ?>">
  <input type="hidden" name="revisado" value="1">
  <?= card_head(num($total) . ($total === 1 ? ' error' : ' errores'), '',
                $filas && $fEstado !== 'revisados' ? boton('Marcar revisados los tildados', 'ok', null, 'guardar', true, true) : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr>
        <th class="col-acciones">Acciones</th>
        <th class="col-check"><input type="checkbox" title="Tildar todos" aria-label="Tildar todos"
            onclick="var t = this; document.querySelectorAll('#frm-revisar input[name=&quot;id[]&quot;]').forEach(function (c) { c.checked = t.checked; })"></th>
        <th>Fecha y hora</th><th>Usuario</th><th>Pantalla</th><th>Error</th><th>Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($filas as $e): $o = LOGERR_ORIGENES[$e['origen']] ?? [$e['origen'], 'neutro']; $id = (int) $e['id']; ?>
        <tr>
          <td><div class="acciones">
            <button type="button" class="btn-accion" title="Ver el detalle" aria-label="Ver el detalle" onclick="verError(<?= $id ?>)"><?= icono('ver') ?></button>
          </div></td>
          <td class="col-check"><?php if (!(int) $e['revisado']): ?><input type="checkbox" name="id[]" value="<?= $id ?>" aria-label="Tildar el error #<?= $id ?>"><?php endif; ?></td>
          <td class="nowrap"><?= fh($e['ultima']) ?>
            <div class="chico muted">#<?= $id ?><?php if ((int) $e['veces'] > 1): ?>
              · <span title="El mismo error, del mismo usuario, en menos de <?= LOGERR_VENTANA_MIN ?> minutos"><?= (int) $e['veces'] ?> veces desde las <?= substr((string) $e['fecha'], 0, 10) === substr((string) $e['ultima'], 0, 10) ? fh($e['fecha'], 'H:i') : fh($e['fecha'], 'd/m H:i') ?></span><?php endif; ?></div></td>
          <td><?= $e['usuario'] !== null ? h($e['usuario']) : '<span class="td-muted">—</span>' ?></td>
          <td class="celda-texto"><span class="mono chico"><?= h($e['metodo']) ?></span> <span class="mono"><?= h(mb_strimwidth((string) $e['url'], 0, 80, '…')) ?></span></td>
          <td class="celda-texto">
            <?= estado($o[0], $o[1]) ?><?= $e['tipo'] ? ' <span class="mono chico td-fuerte">' . h($e['tipo']) . '</span>' : '' ?>
            <div><?= h(mb_strimwidth((string) $e['mensaje'], 0, 160, '…')) ?></div>
            <?php if ($e['lugar']): ?><div class="chico muted mono"><?= h($e['lugar']) ?></div><?php endif; ?>
          </td>
          <td><?php if ((int) $e['revisado']): ?><?= estado('revisado', 'ok') ?>
                <div class="chico muted"><?= h((string) $e['revisado_por']) ?><?= $e['revisado_el'] ? ' · ' . fh($e['revisado_el'], 'd/m H:i') : '' ?></div>
              <?php else: ?><?= estado('pendiente', 'warn') ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="7" class="no-data"><?= $hayFiltros ? 'No hay errores con estos filtros.' : 'No hay errores pendientes de revisar.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $total, $porPag, 'errores') ?>
</form>

<?php // El detalle de cada fila, para el diálogo: en <template> no se dibuja hasta que se abre. ?>
<?php foreach ($filas as $e): $id = (int) $e['id']; ?>
<template id="det-<?= $id ?>">
  <div class="datos">
    <div class="dato"><div class="dato-lbl">Primera vez</div><div class="dato-val"><?= fh($e['fecha']) ?></div></div>
    <div class="dato"><div class="dato-lbl">Última vez</div><div class="dato-val"><?= fh($e['ultima']) ?></div></div>
    <div class="dato"><div class="dato-lbl">Veces</div><div class="dato-val"><?= (int) $e['veces'] ?></div></div>
    <div class="dato"><div class="dato-lbl">Usuario</div><div class="dato-val"><?= h((string) ($e['usuario'] ?? '—')) ?></div></div>
    <div class="dato dato-ancho"><div class="dato-lbl">Dirección</div><div class="dato-val mono"><?= h($e['metodo'] . ' ' . $e['url']) ?></div></div>
    <?php if ($e['referer']): ?><div class="dato dato-ancho"><div class="dato-lbl">Venía de</div><div class="dato-val mono"><?= h($e['referer']) ?></div></div><?php endif; ?>
    <div class="dato dato-ancho"><div class="dato-lbl">Error</div><div class="dato-val"><?= h(($e['tipo'] ? $e['tipo'] . ': ' : '') . $e['mensaje']) ?></div></div>
    <?php if ($e['lugar']): ?><div class="dato dato-ancho"><div class="dato-lbl">Dónde</div><div class="dato-val mono"><?= h($e['lugar']) ?></div></div><?php endif; ?>
    <div class="dato dato-ancho"><div class="dato-lbl">Equipo</div><div class="chico"><?= h((string) ($e['ip'] ?? '—')) ?> · <?= h((string) ($e['navegador'] ?? '—')) ?></div></div>
  </div>
  <?php if ($e['detalle']): ?><div class="dato-lbl error-traza-lbl">Traza</div><pre class="error-traza"><?= h($e['detalle']) ?></pre><?php endif; ?>
  <form method="post" action="admin_errores.php" class="form-acciones">
    <?= csrf_campo() ?>
    <input type="hidden" name="volver" value="<?= h($volver) ?>">
    <input type="hidden" name="id[]" value="<?= $id ?>">
    <?php if (!(int) $e['revisado']): ?>
      <input type="hidden" name="revisado" value="1"><?= boton('Marcar revisado', 'ok', null, 'guardar', true) ?>
    <?php else: ?>
      <input type="hidden" name="revisado" value="0"><?= boton('Volver a pendiente', 'secondary', null, null, true) ?>
    <?php endif; ?>
  </form>
</template>
<?php endforeach; ?>

<dialog class="chat-dialogo dialogo-error" id="dlg-error">
  <div class="card-head"><div><span class="card-head-titulo">Error <span id="dlg-error-id"></span></span></div>
    <div class="card-head-acciones"><button type="button" class="btn-accion" onclick="this.closest('dialog').close()" aria-label="Cerrar" title="Cerrar"><?= icono('cerrar') ?></button></div></div>
  <div id="dlg-error-cuerpo"></div>
</dialog>
<script>
  function verError(id) {
    var d = document.getElementById('dlg-error');
    document.getElementById('dlg-error-id').textContent = '#' + id;
    var c = document.getElementById('dlg-error-cuerpo');
    c.innerHTML = '';
    c.appendChild(document.getElementById('det-' + id).content.cloneNode(true));
    d.showModal();
  }
</script>
<?php
endif;
layout_fin();
