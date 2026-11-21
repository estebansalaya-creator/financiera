<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/admin.php';

// ---------------------------------------------------------------------
// Usuarios: alta, edición, roles, contraseña, baja y eliminación.
//   - Uno que ya operó (préstamos, pagos, clientes…) no se elimina: se da
//     de baja. Dónde figura sale de las claves foráneas de la base.
//   - Nadie se da de baja a sí mismo ni se saca el rol Administrador.
//   - Siempre queda al menos un administrador activo.
// ---------------------------------------------------------------------

$yo = (int) usuario_id();
$errores = [];
$reg = null;

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $uid = (int) post_str('id');

    if (in_array($accion, ['baja', 'reactivar', 'borrar', 'desbloquear'], true) && $uid) {
        $u = q1('SELECT id_usuario, nombre FROM usuarios WHERE id_usuario = ?', [$uid]);
        if (!$u) {
            flash('danger', 'El usuario ya no existe.');
        } elseif ($accion === 'reactivar') {
            q('UPDATE usuarios SET activo = 1 WHERE id_usuario = ?', [$uid]);
            flash('ok', '«' . $u['nombre'] . '» reactivado: ya puede volver a entrar.');
        } elseif ($accion === 'desbloquear') {
            q('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?', [$uid]);
            flash('ok', '«' . $u['nombre'] . '» desbloqueado.');
        } elseif ($uid === $yo) {
            flash('danger', 'No podés dar de baja ni eliminar tu propio usuario: quedarías afuera sin poder volver a entrar.');
        } elseif (es_admin($uid) && !otros_admins($uid)) {
            flash('danger', 'Es el único administrador activo: el sistema quedaría sin nadie que pueda administrarlo. Dale el rol a otra persona antes.');
        } elseif ($accion === 'baja') {
            q('UPDATE usuarios SET activo = 0 WHERE id_usuario = ?', [$uid]);
            flash('ok', '«' . $u['nombre'] . '» dado de baja: no puede iniciar sesión y lo que hizo sigue a su nombre.');
        } else {
            $usos = usos_usuarios([$uid])[$uid];
            if ($usos) {
                flash('danger', 'No se puede eliminar a «' . $u['nombre'] . '»: figura en ' . texto_usos($usos)
                      . '. Borrarlo dejaría esos registros sin saber quién los hizo. Dalo de baja.');
            } else {
                q('DELETE FROM usuarios WHERE id_usuario = ?', [$uid]);     // roles y permisos se van solos (CASCADE)
                flash('ok', '«' . $u['nombre'] . '» eliminado.');
            }
        }
        redirigir('admin_usuarios.php');
    }

    if ($accion === 'guardar') {
        $reg = ['id_usuario' => $uid ?: null,
                'nombre'  => mb_substr((string) preg_replace('/\s+/', ' ', post_str('nombre')), 0, 120),
                'email'   => mb_substr(strtolower(str_replace(' ', '', post_str('email'))), 0, 150),
                'usuario' => mb_substr(strtolower(str_replace(' ', '', post_str('usuario'))), 0, 50),
                'activo'  => post_str('activo') === '1' ? 1 : 0,
                'celular' => post_str('celular'),
                'rol_ids' => post_ids('rol_ids')];
        $clave  = (string) ($_POST['clave'] ?? '');
        $clave2 = (string) ($_POST['clave2'] ?? '');

        if ($reg['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if ($reg['email'] === '') {
            $errores[] = 'El correo es obligatorio: es con lo que la persona entra.';
        } elseif (!filter_var($reg['email'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = '«' . $reg['email'] . '» no es un correo válido.';
        } elseif ($o = q1('SELECT nombre FROM usuarios WHERE email = ? AND id_usuario <> ?', [$reg['email'], $uid])) {
            $errores[] = 'El correo ' . $reg['email'] . ' ya es de «' . $o['nombre'] . '».';
        }
        if ($reg['usuario'] !== '') {
            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $reg['usuario'])) {
                $errores[] = 'El usuario va sin espacios: letras, números, punto o guion (de 3 a 50).';
            } elseif ($o = q1('SELECT nombre FROM usuarios WHERE usuario = ? AND id_usuario <> ?', [$reg['usuario'], $uid])) {
                $errores[] = 'El usuario «' . $reg['usuario'] . '» ya es de «' . $o['nombre'] . '».';
            }
        }
        // Celular (para Llamar y WhatsApp desde Mensajes): mismo formato que el resto de los teléfonos.
        $celular = null;
        if ($reg['celular'] !== '') {
            $celular = tel_normalizar($reg['celular'], true);
            if ($celular === null) {
                $errores[] = tel_error('Celular');
            }
        }
        if (!$uid && $clave === '') {
            $errores[] = 'La contraseña es obligatoria en el alta: no hay otra forma de entrar.';
        }
        if ($clave !== '' || $clave2 !== '') {
            if ($clave !== $clave2) {
                $errores[] = 'Las dos contraseñas no coinciden.';
            } elseif (strlen($clave) < CLAVE_MIN) {
                $errores[] = 'La contraseña tiene que tener al menos ' . CLAVE_MIN . ' caracteres.';
            }
        }

        // Solo se eligen roles activos; los inactivos que ya tenga se conservan.
        $activos = array_column(q('SELECT id_rol, nombre FROM roles WHERE activo = 1'), 'nombre', 'id_rol');
        $reg['rol_ids'] = array_values(array_intersect($reg['rol_ids'], array_map('intval', array_keys($activos))));
        $nombres = array_map(function ($i) use ($activos) { return $activos[$i]; }, $reg['rol_ids']);

        if ($uid && $uid === $yo) {
            if (!$reg['activo']) {
                $errores[] = 'No podés darte de baja a vos mismo.';
            }
            if (es_admin($uid) && !in_array(ROL_ADMIN, $nombres, true)) {
                $errores[] = 'No podés quitarte el rol Administrador: perderías el acceso a esta pantalla. Que lo haga otro administrador.';
            }
        }
        if ($uid && !$errores && es_admin($uid) && !($reg['activo'] && in_array(ROL_ADMIN, $nombres, true)) && !otros_admins($uid)) {
            $errores[] = 'Es el único administrador activo: el sistema quedaría sin nadie que pueda administrarlo. Dale el rol a otra persona antes.';
        }

        if (!$errores) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                if ($uid) {
                    q('UPDATE usuarios SET nombre = ?, email = ?, usuario = ?, activo = ? WHERE id_usuario = ?',
                      [$reg['nombre'], $reg['email'], $reg['usuario'] ?: null, $reg['activo'], $uid]);
                } else {
                    q('INSERT INTO usuarios (nombre, email, usuario, activo) VALUES (?, ?, ?, ?)',
                      [$reg['nombre'], $reg['email'], $reg['usuario'] ?: null, $reg['activo']]);
                    $uid = (int) $pdo->lastInsertId();
                }
                if (columna_existe('usuarios', 'celular')) {
                    q('UPDATE usuarios SET celular = ? WHERE id_usuario = ?', [$celular, $uid]);
                }
                if ($clave !== '') {
                    q('UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?',
                      [password_hash($clave, PASSWORD_DEFAULT), $uid]);
                }
                q('DELETE ur FROM usuarios_roles ur JOIN roles r ON r.id_rol = ur.id_rol WHERE ur.id_usuario = ? AND r.activo = 1', [$uid]);
                foreach ($reg['rol_ids'] as $rid) {
                    q('INSERT INTO usuarios_roles (id_usuario, id_rol) VALUES (?, ?)', [$uid, $rid]);
                }
                $pdo->commit();
                if ($uid === $yo) {
                    sesion_refrescar();
                }
                flash('ok', '«' . $reg['nombre'] . '» ' . ($reg['id_usuario'] ? 'actualizado.' : 'dado de alta.')
                      . ($clave !== '' && $reg['id_usuario'] ? ' La contraseña quedó cambiada.' : '')
                      . ($reg['id_usuario'] && $uid !== $yo ? ' Si cambiaron sus roles, los ve en menos de un minuto.' : ''));
                redirigir('admin_usuarios.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------

$editId = (int) get_str('edit');
if ($editId && $reg === null) {
    $reg = q1('SELECT id_usuario, nombre, email, usuario, activo, '
            . (columna_existe('usuarios', 'celular') ? 'celular' : 'NULL AS celular') . ' FROM usuarios WHERE id_usuario = ?', [$editId]);
    if ($reg) {
        $reg['celular'] = tel_fmt($reg['celular']);
    }
    if (!$reg) {
        flash('danger', 'El usuario no existe.');
        redirigir('admin_usuarios.php');
    }
    $reg['rol_ids'] = array_map('intval', array_column(q('SELECT id_rol FROM usuarios_roles WHERE id_usuario = ?', [$editId]), 'id_rol'));
} elseif ($reg === null && get_str('nuevo') !== '') {
    $reg = ['id_usuario' => null, 'nombre' => '', 'email' => '', 'usuario' => '', 'activo' => 1, 'celular' => '', 'rol_ids' => []];
}
if ($reg && $reg['id_usuario']) {
    $reg += q1('SELECT ultimo_acceso, creado_el, (password_hash IS NOT NULL) AS tiene_clave,
                       (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado
                  FROM usuarios WHERE id_usuario = ?', [(int) $reg['id_usuario']]) ?: [];
    // Los roles inactivos que tenga se muestran tildados (y se conservan al guardar).
    $reg['rol_ids'] = array_values(array_unique(array_merge($reg['rol_ids'], array_map('intval', array_column(
        q('SELECT ur.id_rol FROM usuarios_roles ur JOIN roles r ON r.id_rol = ur.id_rol WHERE ur.id_usuario = ? AND r.activo = 0',
          [(int) $reg['id_usuario']]), 'id_rol')))));
}

$fEstado = get_str('estado') === '' ? '1' : get_str('estado');
$fRol = (int) get_str('rol');
$where = [];
$par = [];
if ($fEstado === '1' || $fEstado === '0') {
    $where[] = 'u.activo = ?';
    $par[] = (int) $fEstado;
}
if ($fRol) {
    $where[] = 'EXISTS (SELECT 1 FROM usuarios_roles x WHERE x.id_usuario = u.id_usuario AND x.id_rol = ?)';
    $par[] = $fRol;
}
$lista = q("SELECT u.id_usuario, u.nombre, u.email, u.usuario, u.activo, u.ultimo_acceso, u.creado_el,
                   (u.password_hash IS NOT NULL) AS tiene_clave,
                   (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado,
                   (SELECT GROUP_CONCAT(r.nombre ORDER BY r.prioridad DESC SEPARATOR ', ')
                      FROM usuarios_roles ur JOIN roles r ON r.id_rol = ur.id_rol WHERE ur.id_usuario = u.id_usuario) AS roles,
                   (SELECT COUNT(*) FROM menu_usuarios mu WHERE mu.id_usuario = u.id_usuario) AS extras
              FROM usuarios u" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.activo DESC, u.nombre', $par);
$usos = usos_usuarios(array_column($lista, 'id_usuario'));
$roles = q('SELECT id_rol, nombre, descripcion, activo FROM roles ORDER BY prioridad DESC, nombre');

layout_inicio('Usuarios');
titulo('Usuarios', 'Quiénes entran al sistema y con qué roles. Lo que ve cada uno sale de sus roles y de sus permisos sueltos.',
       $reg ? '' : boton('Nuevo usuario', 'primary', 'admin_usuarios.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($reg):
    $r = $reg;
    $esYo = $r['id_usuario'] && (int) $r['id_usuario'] === $yo;
    $acciones = ($r['id_usuario'] ? boton('Permisos sueltos', 'secondary', 'admin_permisos.php?usuario=' . (int) $r['id_usuario'], 'llave', false, true) : '')
              . boton('Volver', 'secondary', 'admin_usuarios.php', 'volver', false, true);
?>
<form method="post" action="admin_usuarios.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $r['id_usuario'] ?>">
  <div class="card">
    <?= card_head($r['id_usuario'] ? 'Editar usuario' : 'Nuevo usuario',
                  $r['id_usuario'] ? 'Último ingreso: ' . (!empty($r['ultimo_acceso']) ? date('d/m/Y H:i', strtotime($r['ultimo_acceso'])) : 'nunca') : '',
                  $acciones) ?>
    <div class="bloque">
      <?php if ($r['id_usuario'] && empty($r['tiene_clave'])) {
          alerta('No tiene contraseña: no puede entrar hasta que se le cargue una.', 'warning');
      } ?>
      <?php if (!empty($r['bloqueado'])) {
          alerta('Está bloqueado por intentos fallidos. Se desbloquea solo en unos minutos, con el botón de la llave en la lista o al cargarle una contraseña nueva.', 'warning');
      } ?>
      <div class="campos">
        <div class="campo" style="--span:4"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($r['nombre']) ?>" maxlength="120" required></div>
        <div class="campo" style="--span:4"><label for="email">Correo <span class="req">*</span></label>
          <input id="email" name="email" type="email" class="mono" value="<?= h($r['email']) ?>" maxlength="150" required>
          <small class="campo-ayuda">Es con lo que entra.</small></div>
        <div class="campo" style="--span:2"><label for="usuario">Usuario</label>
          <input id="usuario" name="usuario" class="mono" value="<?= h((string) $r['usuario']) ?>" maxlength="50" placeholder="opcional">
          <small class="campo-ayuda">Para entrar sin el correo.</small></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $r['activo'] ? 'checked' : '' ?> <?= $esYo ? 'disabled' : '' ?>> Activo</label>
          <?php if ($esYo): ?><input type="hidden" name="activo" value="1"><?php endif; ?>
        </div>
        <div class="campo" style="--span:4"><label for="clave">Contraseña<?= $r['id_usuario'] ? '' : ' <span class="req">*</span>' ?></label>
          <input id="clave" name="clave" type="password" autocomplete="new-password" minlength="<?= CLAVE_MIN ?>" <?= $r['id_usuario'] ? '' : 'required' ?>>
          <small class="campo-ayuda"><?= $r['id_usuario'] ? 'Vacía, no se cambia. ' : '' ?>Al menos <?= CLAVE_MIN ?> caracteres.</small></div>
        <div class="campo" style="--span:4"><label for="clave2">Repetir contraseña</label>
          <input id="clave2" name="clave2" type="password" autocomplete="new-password" minlength="<?= CLAVE_MIN ?>" <?= $r['id_usuario'] ? '' : 'required' ?>></div>
        <?php if (columna_existe('usuarios', 'celular')): ?>
        <div class="campo" style="--span:4"><label for="celular">Celular</label>
          <input id="celular" name="celular" inputmode="tel" class="mono" data-tel="movil" value="<?= h((string) ($r['celular'] ?? '')) ?>" maxlength="30" placeholder="11 15 2345-6789">
          <small class="campo-ayuda">Para llamarlo o escribirle por WhatsApp desde Mensajes.</small></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="bloque">
      <div class="bloque-titulo">Roles</div>
      <div class="lista-roles">
        <?php foreach ($roles as $ro): ?>
          <label class="campo-check rol-opcion<?= (int) $ro['activo'] ? '' : ' inactivo' ?>">
            <input type="checkbox" name="rol_ids[]" value="<?= (int) $ro['id_rol'] ?>" <?= in_array((int) $ro['id_rol'], $r['rol_ids'], true) ? 'checked' : '' ?> <?= (int) $ro['activo'] ? '' : 'disabled' ?>>
            <span><strong><?= h($ro['nombre']) ?></strong><?= (int) $ro['activo'] ? '' : ' ' . estado('inactivo') ?>
              <small><?= h((string) $ro['descripcion']) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Usuarios') ?>
  <form method="get" action="admin_usuarios.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" class="filter-input" placeholder="Nombre, correo o usuario…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '1' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <?= opciones(['1' => 'Activos', '0' => 'Dados de baja', 'todos' => 'Todos'], $fEstado) ?>
      </select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Rol</span>
      <select name="rol" class="filtro <?= $fRol ? 'activo' : '' ?>" onchange="this.form.submit()">
        <?= opciones(array_column($roles, 'nombre', 'id_rol'), $fRol ?: '', 'Todos los roles') ?>
      </select></label>
    <?= filtro_botones('admin_usuarios.php', $fEstado !== '1' || $fRol) ?>
    <?= filtro_fin_local(null) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="usuario|usuarios">
      <thead><tr>
        <th class="col-acciones" data-no-sort>Acciones</th><th>Nombre</th><th>Correo</th><th>Usuario</th><th>Roles</th>
        <th class="th-right">Permisos sueltos</th><th>Último ingreso</th><th>Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lista as $u): $id = (int) $u['id_usuario']; ?>
        <tr class="<?= (int) $u['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'admin_usuarios.php?edit=' . $id, 'Editar') ?>
            <?= (int) $u['bloqueado'] ? accion_post('admin_usuarios.php', ['id' => $id, 'accion' => 'desbloquear'], 'llave', 'Desbloquear') : '' ?>
            <?php if ($id !== $yo): ?>
              <?= (int) $u['activo']
                    ? accion_post('admin_usuarios.php', ['id' => $id, 'accion' => 'baja'], 'cerrar', 'Dar de baja', '«' . $u['nombre'] . '» no va a poder entrar. Lo que hizo queda a su nombre.', 'borrar')
                    : accion_post('admin_usuarios.php', ['id' => $id, 'accion' => 'reactivar'], 'ok', 'Reactivar') ?>
              <?= !$usos[$id] ? accion_post('admin_usuarios.php', ['id' => $id, 'accion' => 'borrar'], 'borrar', 'Eliminar', 'Se elimina «' . $u['nombre'] . '» (' . $u['email'] . '). No figura en ningún registro.', 'borrar') : '' ?>
            <?php endif; ?>
          </div></td>
          <td class="td-fuerte"><?= h($u['nombre']) ?><?= $id === $yo ? ' <span class="chip">vos</span>' : '' ?></td>
          <td class="td-mono"><?= h($u['email']) ?></td>
          <td class="td-mono"><?= $u['usuario'] !== null ? h($u['usuario']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= $u['roles'] ? h($u['roles']) : estado('sin rol', 'warn') ?></td>
          <td class="td-right <?= (int) $u['extras'] ? '' : 'td-muted' ?>"><?= (int) $u['extras'] ?></td>
          <td class="td-muted"><?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'nunca' ?></td>
          <td><?= (int) $u['activo'] ? estado('Activo', 'ok') : estado('Baja', 'neutro') ?>
              <?= (int) $u['tiene_clave'] ? '' : estado('sin contraseña', 'warn') ?>
              <?= (int) $u['bloqueado'] ? estado('bloqueado', 'danger') : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="8" class="no-data">No hay usuarios con esos filtros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
layout_fin();
