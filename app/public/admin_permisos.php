<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/admin.php';

// ---------------------------------------------------------------------
// Permisos por usuario: opciones sueltas para una persona, además de las
// de sus roles (menu_usuarios). Lo que dan los roles se ve tildado y sin
// poder tocarse: para quitarlo hay que cambiar el rol, porque el permiso
// es la suma. Una opción suelta solo agrega, no quita.
// ---------------------------------------------------------------------

$fuente = es_post() ? 'post' : 'get';
$uid = (int) ($fuente === 'post' ? post_str('usuario') : get_str('usuario'));

$usuarios = q("SELECT u.id_usuario, u.nombre, u.email, u.activo,
                      (SELECT GROUP_CONCAT(r.nombre ORDER BY r.prioridad DESC SEPARATOR ', ')
                         FROM usuarios_roles ur JOIN roles r ON r.id_rol = ur.id_rol WHERE ur.id_usuario = u.id_usuario) AS roles
                 FROM usuarios u ORDER BY u.activo DESC, u.nombre");
$usuario = null;
foreach ($usuarios as $u) {
    if ((int) $u['id_usuario'] === $uid) {
        $usuario = $u;
    }
}

$menu = menu_completo();
$porId = [];
foreach ($menu as $m) {
    $porId[(int) $m['id_menu']] = $m;
}
$porRol = [];
if ($usuario) {
    foreach (q('SELECT mr.id_menu, r.nombre FROM menu_roles mr
                  JOIN usuarios_roles ur ON ur.id_rol = mr.id_rol
                  JOIN roles r ON r.id_rol = mr.id_rol AND r.activo = 1
                 WHERE ur.id_usuario = ? ORDER BY r.prioridad DESC', [$uid]) as $f) {
        $porRol[(int) $f['id_menu']][] = $f['nombre'];
    }
}

if (es_post()) {
    csrf_exigir();
    if (!$usuario) {
        flash('danger', 'Elegí un usuario.');
        redirigir('admin_permisos.php');
    }
    $nuevas = array_values(array_diff(
        con_ancestros(array_values(array_intersect(post_ids('menu_ids'), array_keys($porId))), $porId),
        array_keys($porRol)));
    $pdo = db();
    $pdo->beginTransaction();
    q('DELETE FROM menu_usuarios WHERE id_usuario = ?', [$uid]);
    foreach ($nuevas as $m) {
        q('INSERT INTO menu_usuarios (id_usuario, id_menu) VALUES (?, ?)', [$uid, $m]);
    }
    $pdo->commit();
    if ($uid === usuario_id()) {
        sesion_refrescar();
        flash('ok', 'Tus permisos quedaron guardados: ' . count($nuevas) . ' opciones además de las de tus roles.');
    } else {
        flash('ok', 'Permisos de «' . $usuario['nombre'] . '» guardados: ' . count($nuevas) . ' opciones además de las de sus roles. Los ve en menos de un minuto.');
    }
    redirigir('admin_permisos.php?usuario=' . $uid);
}

$extras = $usuario ? array_map('intval', array_column(q('SELECT id_menu FROM menu_usuarios WHERE id_usuario = ?', [$uid]), 'id_menu')) : [];
$fijas = [];
foreach ($porRol as $id => $roles) {
    $fijas[$id] = 'por ' . implode(', ', array_unique($roles));
}

layout_inicio('Permisos por usuario');
titulo('Permisos por usuario', 'Pantallas sueltas para una persona, además de las que le dan sus roles.');
mensajes();
?>
<div class="card">
  <form method="get" action="admin_permisos.php" class="filter-bar">
    <label class="form-fila-titulo" for="usuario">Usuario</label>
    <select id="usuario" name="usuario" class="filtro" onchange="this.form.submit()">
      <option value="">Elegí un usuario…</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= (int) $u['id_usuario'] ?>" <?= (int) $u['id_usuario'] === $uid ? 'selected' : '' ?>>
          <?= h($u['nombre'] . ' · ' . ($u['roles'] ?: 'sin rol') . ((int) $u['activo'] ? '' : ' · baja')) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($usuario): ?><?= boton('Editar usuario', 'secondary', 'admin_usuarios.php?edit=' . $uid, 'editar', false, true) ?><?php endif; ?>
  </form>
</div>

<?php if ($usuario): ?>
<form method="post" action="admin_permisos.php">
  <?= csrf_campo() ?>
  <input type="hidden" name="usuario" value="<?= $uid ?>">
  <div class="card">
    <?= card_head($usuario['nombre'], 'Roles: ' . ($usuario['roles'] ?: 'ninguno') . ' · ' . count($extras) . ' opciones sueltas') ?>
    <div class="bloque">
      <p class="nota-arbol">Lo que está tildado y gris viene de sus roles: se cambia en <a href="admin_roles.php">Roles</a>. Tildá lo que le quieras sumar a esta persona.</p>
      <?= arbol_casillas(arbol_plano($menu), $extras, $fijas) ?>
    </div>
    <div class="form-acciones"><?= boton('Guardar permisos', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif;
layout_fin();
