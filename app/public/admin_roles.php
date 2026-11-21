<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/admin.php';

// ---------------------------------------------------------------------
// Roles: nombre, descripción, si está activo y qué opciones del menú da.
// Administrador es el único que el sistema conoce por nombre: no se
// renombra, no se desactiva, no se borra y no pierde Usuarios, Roles ni
// Menú. Los demás se pueden crear, renombrar y eliminar (si nadie los tiene).
// ---------------------------------------------------------------------

$errores = [];
$rol = null;
$marcadas = null;

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $rid = (int) post_str('id');
    $actual = $rid ? q1('SELECT * FROM roles WHERE id_rol = ?', [$rid]) : null;
    if ($rid && !$actual) {
        flash('danger', 'El rol ya no existe.');
        redirigir('admin_roles.php');
    }

    if ($accion === 'borrar' && $actual) {
        $n = (int) q1('SELECT COUNT(*) AS n FROM usuarios_roles WHERE id_rol = ?', [$rid])['n'];
        if ($actual['nombre'] === ROL_ADMIN) {
            flash('danger', 'El rol Administrador no se elimina.');
        } elseif ($n) {
            flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: lo tienen ' . $n . ' usuario(s). Sacáselo antes o desactivalo.');
        } else {
            q('DELETE FROM roles WHERE id_rol = ?', [$rid]);
            flash('ok', 'Rol «' . $actual['nombre'] . '» eliminado.');
        }
        redirigir('admin_roles.php');
    }

    if ($accion === 'guardar') {
        $porId = [];
        foreach (menu_completo() as $m) {
            $porId[(int) $m['id_menu']] = $m;
        }
        $marcadas = con_ancestros(array_values(array_intersect(post_ids('menu_ids'), array_keys($porId))), $porId);
        $rol = ['id_rol' => $rid ?: null,
                'nombre' => $actual && $actual['nombre'] === ROL_ADMIN ? ROL_ADMIN : mb_substr((string) preg_replace('/\s+/', ' ', post_str('nombre')), 0, 50),
                'descripcion' => mb_substr(post_str('descripcion'), 0, 250),
                'prioridad' => (int) post_str('prioridad'),
                'activo' => post_str('activo') === '1' ? 1 : 0];

        if ($rol['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        } elseif ($o = q1('SELECT id_rol FROM roles WHERE nombre = ? AND id_rol <> ?', [$rol['nombre'], $rid])) {
            $errores[] = 'Ya hay un rol «' . $rol['nombre'] . '».';
        } elseif (!$actual && strcasecmp($rol['nombre'], ROL_ADMIN) === 0) {
            $errores[] = 'Ya existe el rol Administrador.';
        }
        if (!preg_match('/^-?\d{1,4}$/', post_str('prioridad') ?: '0')) {
            $errores[] = 'La prioridad es un número (los de mayor prioridad van primero).';
        }
        if ($rol['nombre'] === ROL_ADMIN) {
            if (!$rol['activo']) {
                $errores[] = 'El rol Administrador no se desactiva: el sistema quedaría sin nadie que pueda administrarlo.';
            }
            $faltan = [];
            foreach ($porId as $id => $m) {
                if (in_array($m['url'], MENU_PROTEGIDO, true) && !in_array($id, $marcadas, true)) {
                    $faltan[] = $m['nombre'];
                }
            }
            if ($faltan) {
                $errores[] = 'El rol Administrador tiene que conservar ' . implode(', ', $faltan) . ': sin esas pantallas nadie podría volver a dar permisos.';
            }
        }

        if (!$errores) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                if ($rid) {
                    q('UPDATE roles SET nombre = ?, descripcion = ?, prioridad = ?, activo = ? WHERE id_rol = ?',
                      [$rol['nombre'], $rol['descripcion'] ?: null, $rol['prioridad'], $rol['activo'], $rid]);
                } else {
                    q('INSERT INTO roles (nombre, descripcion, prioridad, activo) VALUES (?, ?, ?, ?)',
                      [$rol['nombre'], $rol['descripcion'] ?: null, $rol['prioridad'], $rol['activo']]);
                    $rid = (int) $pdo->lastInsertId();
                }
                q('DELETE FROM menu_roles WHERE id_rol = ?', [$rid]);
                foreach ($marcadas as $m) {
                    q('INSERT INTO menu_roles (id_menu, id_rol) VALUES (?, ?)', [$m, $rid]);
                }
                $pdo->commit();
                sesion_refrescar();
                $pantallas = count(array_filter($marcadas, function ($i) use ($porId) { return $porId[$i]['url'] !== null; }));
                flash('ok', 'Rol «' . $rol['nombre'] . '» guardado con ' . $pantallas . ' pantallas. Quienes lo tienen ven el cambio en menos de un minuto.');
                redirigir('admin_roles.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

$editId = (int) get_str('edit');
if ($editId && $rol === null) {
    $rol = q1('SELECT * FROM roles WHERE id_rol = ?', [$editId]);
    if (!$rol) {
        flash('danger', 'El rol no existe.');
        redirigir('admin_roles.php');
    }
} elseif ($rol === null && get_str('nuevo') !== '') {
    $rol = ['id_rol' => null, 'nombre' => '', 'descripcion' => '', 'prioridad' => 20, 'activo' => 1];
    $base = (int) get_str('copiar');
    if ($base) {
        $marcadas = array_map('intval', array_column(q('SELECT id_menu FROM menu_roles WHERE id_rol = ?', [$base]), 'id_menu'));
    }
}
if ($rol && $marcadas === null) {
    $marcadas = $rol['id_rol'] ? array_map('intval', array_column(q('SELECT id_menu FROM menu_roles WHERE id_rol = ?', [(int) $rol['id_rol']]), 'id_menu')) : [];
}

$lista = q('SELECT r.*,
                   (SELECT COUNT(*) FROM usuarios_roles ur JOIN usuarios u ON u.id_usuario = ur.id_usuario
                     WHERE ur.id_rol = r.id_rol AND u.activo = 1) AS usuarios,
                   (SELECT COUNT(*) FROM usuarios_roles ur WHERE ur.id_rol = r.id_rol) AS usuarios_todos,
                   (SELECT COUNT(*) FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu
                     WHERE mr.id_rol = r.id_rol AND m.activo = 1 AND m.url IS NOT NULL) AS pantallas,
                   (SELECT GROUP_CONCAT(m.nombre ORDER BY m.orden SEPARATOR \', \') FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu
                     WHERE mr.id_rol = r.id_rol AND m.activo = 1 AND m.padre_id IS NULL) AS modulos
              FROM roles r ORDER BY r.prioridad DESC, r.nombre');

$arbol = $rol ? arbol_plano(menu_completo()) : [];
$fijas = [];
if ($rol && $rol['nombre'] === ROL_ADMIN) {
    foreach ($arbol as $n) {
        if (in_array($n['url'], MENU_PROTEGIDO, true)) {
            $fijas[(int) $n['id_menu']] = 'no se quita';
        }
    }
}

layout_inicio('Roles');
titulo('Roles', 'Qué pantallas da cada rol. Una persona ve la suma de sus roles más sus permisos sueltos.',
       $rol ? '' : boton('Nuevo rol', 'primary', 'admin_roles.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($rol):
    $esAdmin = $rol['nombre'] === ROL_ADMIN && $rol['id_rol'];
?>
<form method="post" action="admin_roles.php">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $rol['id_rol'] ?>">
  <div class="card">
    <?= card_head($rol['id_rol'] ? 'Rol ' . $rol['nombre'] : 'Nuevo rol', '', boton('Volver', 'secondary', 'admin_roles.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:3"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($rol['nombre']) ?>" maxlength="50" required <?= $esAdmin ? 'readonly' : '' ?>>
          <?php if ($esAdmin): ?><small class="campo-ayuda">Administrador no se renombra: el sistema lo reconoce por el nombre.</small><?php endif; ?></div>
        <div class="campo" style="--span:6"><label for="descripcion">Descripción</label>
          <input id="descripcion" name="descripcion" value="<?= h((string) $rol['descripcion']) ?>" maxlength="250"></div>
        <div class="campo" style="--span:1"><label for="prioridad">Prioridad</label>
          <input id="prioridad" name="prioridad" value="<?= (int) $rol['prioridad'] ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $rol['activo'] ? 'checked' : '' ?> <?= $esAdmin ? 'disabled' : '' ?>> Activo</label>
          <?php if ($esAdmin): ?><input type="hidden" name="activo" value="1"><?php endif; ?></div>
      </div>
    </div>
    <div class="bloque">
      <div class="bloque-titulo">Pantallas del rol</div>
      <p class="nota-arbol">Tildar una pantalla tilda su rama. Destildar una rama destilda todo lo de adentro.</p>
      <?= arbol_casillas($arbol, $marcadas, $fijas) ?>
    </div>
    <div class="form-acciones"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Roles') ?>
  <?= barra_local('admin_roles.php', 'Rol, descripción o módulo…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="rol|roles">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Rol</th><th>Descripción</th><th>Módulos</th>
        <th class="th-right">Pantallas</th><th class="th-right">Usuarios activos</th><th class="th-right">Prioridad</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $r): $id = (int) $r['id_rol']; ?>
        <tr class="<?= (int) $r['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'admin_roles.php?edit=' . $id, 'Editar') ?>
            <?= btn_accion('agregar', 'admin_roles.php?nuevo=1&copiar=' . $id, 'Nuevo rol a partir de este') ?>
            <?php if ($r['nombre'] !== ROL_ADMIN && !(int) $r['usuarios_todos']): ?>
              <form method="post" action="admin_roles.php" class="form-en-linea" data-confirmar="Se elimina el rol «<?= h($r['nombre']) ?>». Nadie lo tiene." data-confirmar-titulo="Eliminar rol" data-confirmar-boton="Eliminar" data-peligro>
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="borrar">
                <button class="btn-accion borrar" title="Eliminar" aria-label="Eliminar"><?= icono('borrar') ?></button></form>
            <?php endif; ?>
          </div></td>
          <td class="td-fuerte"><?= h($r['nombre']) ?></td>
          <td class="td-wrap"><?= h((string) $r['descripcion']) ?></td>
          <td class="td-wrap td-muted"><?= h((string) $r['modulos']) ?: '—' ?></td>
          <td class="td-right"><?= (int) $r['pantallas'] ?></td>
          <td class="td-right"><?= (int) $r['usuarios'] ?></td>
          <td class="td-right td-muted"><?= (int) $r['prioridad'] ?></td>
          <td><?= (int) $r['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="8" class="no-data">Todavía no hay roles.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
layout_fin();
