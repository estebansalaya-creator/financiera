<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/admin.php';

// ---------------------------------------------------------------------
// Menú: el árbol del menú lateral. Una opción es una rama (agrupa, sin
// URL) o una pantalla (con URL). Hasta 4 niveles.
//   - Una pantalla no puede tener opciones debajo, y una rama con opciones
//     no lleva URL.
//   - Dos opciones no pueden abrir la misma pantalla: no se distinguirían
//     al dar permisos.
//   - Desactivar una opción la saca del menú y quita el permiso a su
//     pantalla (y a todo lo que cuelga de ella).
//   - Usuarios, Roles y Menú no se desactivan, no se eliminan y no
//     cambian de URL.
// ---------------------------------------------------------------------

$errores = [];
$item = null;

/** Si la URL tiene una pantalla en esta aplicación. */
function pantalla_existe(string $url): bool
{
    $archivo = explode('?', $url)[0];
    return preg_match('/^[a-z0-9_]+\.php$/i', $archivo) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $archivo);
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $mid = (int) post_str('id');
    $arbol = arbol_plano(menu_completo());
    $porId = [];
    foreach ($arbol as $n) {
        $porId[(int) $n['id_menu']] = $n;
    }
    $actual = $mid ? ($porId[$mid] ?? null) : null;
    if ($mid && !$actual) {
        flash('danger', 'La opción ya no existe.');
        redirigir('admin_menu.php');
    }
    $protegida = $actual && in_array($actual['url'], MENU_PROTEGIDO, true);

    if (in_array($accion, ['baja', 'reactivar', 'borrar'], true) && $actual) {
        if ($accion === 'reactivar') {
            q('UPDATE menu SET activo = 1 WHERE id_menu = ?', [$mid]);
            flash('ok', '«' . $actual['nombre'] . '» reactivada.');
        } elseif ($protegida) {
            flash('danger', '«' . $actual['nombre'] . '» no se desactiva ni se elimina: sin esa pantalla nadie podría volver a dar permisos.');
        } elseif ($accion === 'baja') {
            q('UPDATE menu SET activo = 0 WHERE id_menu = ?', [$mid]);
            flash('ok', '«' . $actual['nombre'] . '» desactivada: deja de verse en el menú y de dar permiso a su pantalla'
                  . ($actual['n_hijos'] ? ', y con ella todo lo que cuelga de su rama.' : '.'));
        } elseif ($actual['n_hijos']) {
            flash('danger', '«' . $actual['nombre'] . '» tiene ' . $actual['n_hijos'] . ' opciones debajo. Movelas o eliminalas antes.');
        } else {
            q('DELETE FROM menu WHERE id_menu = ?', [$mid]);
            flash('ok', '«' . $actual['nombre'] . '» eliminada, y quitada de los roles y usuarios que la tenían.');
        }
        sesion_refrescar();
        redirigir('admin_menu.php');
    }

    if ($accion === 'guardar') {
        $item = ['id_menu' => $mid ?: null,
                 'padre_id' => (int) post_str('padre_id') ?: null,
                 'nombre' => mb_substr((string) preg_replace('/\s+/', ' ', post_str('nombre')), 0, 100),
                 'url' => strtolower(str_replace(' ', '', post_str('url'))) ?: null,
                 'icono' => post_str('icono') ?: null,
                 'icono_svg' => trim((string) ($_POST['icono_svg'] ?? '')) ?: null,
                 'orden' => (int) post_str('orden'),
                 'activo' => post_str('activo') === '1' ? 1 : 0];
        if ($item['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if ($item['url'] !== null) {
            if (!preg_match('/^[a-z0-9_]+\.php(\?[a-z0-9_=&%.-]*)?$/', $item['url'])) {
                $errores[] = '«' . $item['url'] . '» no es una pantalla: es el nombre del archivo, como «prestamos.php». Dejala vacía si la opción solo agrupa otras.';
            } else {
                foreach ($arbol as $n) {
                    if ($n['url'] === $item['url'] && (int) $n['id_menu'] !== $mid) {
                        $errores[] = 'La pantalla ' . $item['url'] . ' ya es de «' . $n['nombre'] . '»: dos opciones con la misma pantalla no se distinguen al dar permisos.';
                    }
                }
            }
        }
        if ($item['icono'] !== null && !isset(iconos_lista()[$item['icono']])) {
            $errores[] = 'Ese ícono no existe.';
        }
        if ($e = icono_svg_error($item['icono_svg'])) {
            $errores[] = $e;
        }
        $padre = $item['padre_id'] ? ($porId[$item['padre_id']] ?? null) : null;
        if ($item['padre_id'] && !$padre) {
            $errores[] = 'La rama elegida ya no existe.';
        } elseif ($padre && $mid && ((int) $padre['id_menu'] === $mid || in_array((int) $padre['id_menu'], descendientes($arbol, $mid), true))) {
            $errores[] = 'Una opción no puede ir dentro de sí misma ni de una de las suyas.';
        } elseif ($padre && $padre['url'] !== null) {
            $errores[] = '«' . $padre['nombre'] . '» abre una pantalla: una pantalla no puede tener opciones debajo.';
        } else {
            $prof = $padre ? $padre['prof'] + 1 : 0;
            $alto = $mid ? altura($arbol, $mid) : 1;
            if ($prof + $alto > MENU_NIVELES) {
                $errores[] = 'El menú tiene hasta ' . MENU_NIVELES . ' niveles y así quedarían ' . ($prof + $alto) . '.';
            }
        }
        if ($actual && $actual['n_hijos'] && $item['url'] !== null) {
            $errores[] = 'Tiene opciones debajo: es una rama y no lleva pantalla.';
        }
        if ($protegida) {
            if ($item['url'] !== $actual['url']) {
                $errores[] = 'La pantalla de «' . $actual['nombre'] . '» no se cambia: sin ella nadie podría volver a dar permisos.';
            }
            if (!$item['activo']) {
                $errores[] = '«' . $actual['nombre'] . '» no se desactiva.';
            }
        }

        if (!$errores) {
            try {
                if ($mid) {
                    q('UPDATE menu SET padre_id = ?, nombre = ?, url = ?, icono = ?, icono_svg = ?, orden = ?, activo = ? WHERE id_menu = ?',
                      [$item['padre_id'], $item['nombre'], $item['url'], $item['icono'], $item['icono_svg'], $item['orden'], $item['activo'], $mid]);
                } else {
                    q('INSERT INTO menu (padre_id, nombre, url, icono, icono_svg, orden, activo, nivel) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                      [$item['padre_id'], $item['nombre'], $item['url'], $item['icono'], $item['icono_svg'], $item['orden'], $item['activo']]);
                    $mid = (int) db()->lastInsertId();
                    // Lo nuevo lo tiene el Administrador desde el primer momento (con su rama).
                    $porId2 = [];
                    foreach (menu_completo() as $m) {
                        $porId2[(int) $m['id_menu']] = $m;
                    }
                    $admin = q1('SELECT id_rol FROM roles WHERE nombre = ?', [ROL_ADMIN]);
                    if ($admin) {
                        foreach (con_ancestros([$mid], $porId2) as $m) {
                            q('INSERT IGNORE INTO menu_roles (id_menu, id_rol) VALUES (?, ?)', [$m, (int) $admin['id_rol']]);
                        }
                    }
                }
                rearmar_niveles();
                sesion_refrescar();
                flash('ok', '«' . $item['nombre'] . '» ' . ($item['id_menu'] ? 'guardada.' : 'agregada. La tiene el rol Administrador: asignásela a los demás roles en Roles.'));
                redirigir('admin_menu.php#m' . $mid);
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

$arbol = arbol_plano(menu_completo());
$rolesDe = array_column(q("SELECT mr.id_menu, GROUP_CONCAT(r.nombre ORDER BY r.prioridad DESC SEPARATOR ', ') AS roles
                             FROM menu_roles mr JOIN roles r ON r.id_rol = mr.id_rol GROUP BY mr.id_menu"), 'roles', 'id_menu');
$extrasDe = array_column(q('SELECT id_menu, COUNT(*) AS n FROM menu_usuarios GROUP BY id_menu'), 'n', 'id_menu');

$editId = (int) get_str('edit');
if ($editId && $item === null) {
    foreach ($arbol as $n) {
        if ((int) $n['id_menu'] === $editId) {
            $item = $n;
        }
    }
    if (!$item) {
        flash('danger', 'La opción no existe.');
        redirigir('admin_menu.php');
    }
}
if ($item === null && (get_str('nuevo') !== '' || get_str('padre') !== '')) {
    $padre = (int) get_str('padre') ?: null;
    $hermanos = array_map(function ($n) { return (int) $n['orden']; },
                          array_filter($arbol, function ($n) use ($padre) { return ($n['padre_id'] === null ? null : (int) $n['padre_id']) === $padre; }));
    $item = ['id_menu' => null, 'padre_id' => $padre, 'nombre' => '', 'url' => null, 'icono' => null, 'icono_svg' => null,
             'orden' => $hermanos ? max($hermanos) + 1 : 1, 'activo' => 1];
}
$excluir = $item && $item['id_menu'] ? array_merge([(int) $item['id_menu']], descendientes($arbol, (int) $item['id_menu'])) : [];
$ramas = array_filter($arbol, function ($n) use ($excluir) {
    return $n['url'] === null && !in_array((int) $n['id_menu'], $excluir, true) && $n['prof'] < MENU_NIVELES - 1;
});
$protegidaItem = $item && $item['id_menu'] && in_array($item['url'], MENU_PROTEGIDO, true);

layout_inicio('Menú');
titulo('Menú', 'Las ramas y pantallas del menú lateral. Qué ve cada uno se decide en Roles y en Permisos por usuario.',
       $item ? '' : boton('Nueva rama', 'primary', 'admin_menu.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($item):
    $iconos = array_keys(iconos_lista());
?>
<form method="post" action="admin_menu.php">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $item['id_menu'] ?>">
  <div class="card">
    <?= card_head($item['id_menu'] ? 'Editar «' . $item['nombre'] . '»' : 'Nueva opción', '', boton('Cancelar', 'secondary', 'admin_menu.php', null, false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($item['nombre']) ?>" maxlength="100" required></div>
        <div class="campo" style="--span:4"><label for="padre_id">Dentro de</label>
          <select id="padre_id" name="padre_id">
            <option value="">— Raíz del menú —</option>
            <?php foreach ($ramas as $n): ?>
              <option value="<?= (int) $n['id_menu'] ?>" <?= (int) $n['id_menu'] === (int) $item['padre_id'] ? 'selected' : '' ?>><?= str_repeat('  ', $n['prof']) . h($n['nombre']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="campo" style="--span:4"><label for="url">Pantalla</label>
          <input id="url" name="url" class="mono" value="<?= h((string) $item['url']) ?>" maxlength="120" placeholder="vacía = rama que agrupa" <?= $protegidaItem ? 'readonly' : '' ?>>
          <small class="campo-ayuda">El archivo, como prestamos.php. <?= $protegidaItem ? 'Esta no se cambia.' : '' ?></small></div>
        <div class="campo" style="--span:9"><label for="icono_svg">Ícono en colores (SVG)</label>
          <textarea id="icono_svg" name="icono_svg" class="mono" rows="3" maxlength="6000" placeholder="<svg …>…</svg>" data-icono-svg="icono-svg-vista"><?= h((string) ($item['icono_svg'] ?? '')) ?></textarea>
          <small class="campo-ayuda">Los del menú son Fluent UI System Color Icons, 18 × 18: se pega el SVG entero. Vacío = el ícono simple de al lado.</small></div>
        <div class="campo" style="--span:1"><label>Vista</label>
          <span class="icono-vista" id="icono-svg-vista"><?= !empty($item['icono_svg']) ? svg_unico((string) $item['icono_svg'], 24) : '' ?></span></div>
        <div class="campo" style="--span:2"><label for="icono">Ícono simple</label>
          <select id="icono" name="icono" data-icono-vista="icono-vista">
            <option value="">Sin ícono</option>
            <?php foreach ($iconos as $ic): ?><option value="<?= h($ic) ?>" <?= $ic === $item['icono'] ? 'selected' : '' ?>><?= h($ic) ?></option><?php endforeach; ?>
          </select>
          <span class="icono-vista" id="icono-vista"><?php foreach ($iconos as $ic): ?><span data-ic="<?= h($ic) ?>"<?= $ic === $item['icono'] ? '' : ' hidden' ?>><?= icono($ic, 20) ?></span><?php endforeach; ?></span></div>
        <div class="campo" style="--span:2"><label for="orden">Orden</label>
          <input id="orden" name="orden" value="<?= (int) $item['orden'] ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $item['activo'] ? 'checked' : '' ?> <?= $protegidaItem ? 'disabled' : '' ?>> Activa</label>
          <?php if ($protegidaItem): ?><input type="hidden" name="activo" value="1"><?php endif; ?></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Árbol del menú', count($arbol) . ' opciones · hasta ' . MENU_NIVELES . ' niveles') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Opción</th><th data-no-sort>Pantalla</th>
        <th data-no-sort>Roles</th><th class="th-right" data-no-sort>Sueltos</th><th class="th-right" data-no-sort>Orden</th><th data-no-sort>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($arbol as $n):
          $id = (int) $n['id_menu'];
          $prot = in_array($n['url'], MENU_PROTEGIDO, true); ?>
        <tr id="m<?= $id ?>" class="<?= (int) $n['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'admin_menu.php?edit=' . $id, 'Editar') ?>
            <?php if ($n['url'] === null && $n['prof'] < MENU_NIVELES - 1): ?>
              <?= btn_accion('agregar', 'admin_menu.php?padre=' . $id, 'Agregar una opción adentro') ?>
            <?php endif; ?>
            <?php if (!$prot): ?>
              <form method="post" action="admin_menu.php" class="form-en-linea"<?= (int) $n['activo'] ? ' data-confirmar="«' . h($n['nombre']) . '» deja de verse en el menú y de dar permiso' . ($n['n_hijos'] ? ', con todo lo que cuelga de ella' : '') . '." data-confirmar-titulo="Desactivar" data-confirmar-boton="Desactivar"' : '' ?>>
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="<?= (int) $n['activo'] ? 'baja' : 'reactivar' ?>">
                <button class="btn-accion" title="<?= (int) $n['activo'] ? 'Desactivar' : 'Reactivar' ?>"><?= icono((int) $n['activo'] ? 'cerrar' : 'ok') ?></button></form>
              <?php if (!$n['n_hijos']): ?>
                <form method="post" action="admin_menu.php" class="form-en-linea" data-confirmar="Se elimina «<?= h($n['nombre']) ?>» y se quita de los roles y usuarios que la tenían." data-confirmar-titulo="Eliminar opción" data-confirmar-boton="Eliminar" data-peligro>
                  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="borrar">
                  <button class="btn-accion borrar" title="Eliminar"><?= icono('borrar') ?></button></form>
              <?php endif; ?>
            <?php endif; ?>
          </div></td>
          <td><span class="arbol-celda" style="--prof:<?= $n['prof'] ?>"><?= icono_menu($n, 18) ?>
            <span class="<?= $n['url'] === null ? 'td-fuerte' : '' ?>"><?= h($n['nombre']) ?></span>
            <?= $prot ? ' <span class="chip">protegida</span>' : '' ?></span></td>
          <td class="td-mono"><?php if ($n['url'] === null): ?><span class="td-muted">rama</span>
            <?php else: ?><?= h($n['url']) ?><?= pantalla_existe($n['url']) ? '' : ' ' . estado('no existe', 'danger') ?><?php endif; ?></td>
          <td class="td-wrap <?= isset($rolesDe[$id]) ? '' : 'td-muted' ?>"><?= h($rolesDe[$id] ?? 'nadie') ?></td>
          <td class="td-right <?= isset($extrasDe[$id]) ? '' : 'td-muted' ?>"><?= (int) ($extrasDe[$id] ?? 0) ?></td>
          <td class="td-right td-muted"><?= (int) $n['orden'] ?></td>
          <td><?= (int) $n['activo'] ? estado('Activa', 'ok') : estado('Inactiva', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
layout_fin();
