<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Contactos de clientes y comercializadores (tabla contactos)
//   El modelo de gestion_adm (contactos.py, s_contactos): una sola tabla
//   con entidad_tipo + entidad_id, y las fichas incluyen la misma tarjeta
//   (contactos_tarjeta) y la misma lógica (contacto_acciones).
//   - Alta, edición y baja lógica (activo = 0).
//   - Principal excluyente: marcar uno apaga los otros de la misma entidad,
//     en la misma transacción. El primero que se carga queda principal.
//   - Tipo de catálogo (contactos_tipos) y vínculo/cargo libre.
//   - Teléfonos en el formato único del sistema (tel_normalizar).
//   - Queda quién y cuándo lo cargó y lo modificó.
// Tablas: sql\financiera_clientes_ficha.sql.
// ---------------------------------------------------------------------

/** Entidades con contactos: tipo => [tabla, clave]. Se valida contra esta lista. */
const CONTACTO_ENTIDADES = [
    'cliente'         => ['clientes', 'id_cliente', 'denominacion'],
    'comercializador' => ['vendedores', 'id_vendedor', 'nombre'],
];

/** Los contactos activos de una entidad, el principal primero. */
function contactos_de(string $tipo, int $id): array
{
    return q('SELECT c.*, t.nombre AS tipo_nombre, uc.usuario AS creado_por_usuario, ua.usuario AS actualizado_por_usuario
                FROM contactos c
                LEFT JOIN contactos_tipos t ON t.id_tipo_contacto = c.id_tipo_contacto
                LEFT JOIN usuarios uc ON uc.id_usuario = c.creado_por
                LEFT JOIN usuarios ua ON ua.id_usuario = c.actualizado_por
               WHERE c.entidad_tipo = ? AND c.entidad_id = ? AND c.activo = 1
               ORDER BY c.principal DESC, t.orden, c.nombre', [$tipo, $id]);
}

/** El catálogo de tipos para el combo: id => nombre. */
function contactos_tipos(): array
{
    return array_column(q('SELECT id_tipo_contacto, nombre FROM contactos_tipos WHERE activo = 1 ORDER BY orden, nombre'),
                        'nombre', 'id_tipo_contacto');
}

/** Deja uno solo como principal (en la misma transacción que el alta o la edición). */
function contacto_marcar_principal(string $tipo, int $id, int $idContacto): void
{
    q('UPDATE contactos SET principal = 0 WHERE entidad_tipo = ? AND entidad_id = ? AND id_contacto <> ?', [$tipo, $id, $idContacto]);
}

/**
 * Atiende los POST de la tarjeta (accion = contacto_guardar | contacto_baja).
 * Devuelve null si el pedido no es de contactos; si no, la lista de errores
 * (vacía si salió bien: ya redirigió). $form queda con lo cargado para
 * volver a mostrarlo.
 */
function contacto_acciones(string $tipo, int $id, string $volver, ?array &$form): ?array
{
    $accion = post_str('accion');
    if (!in_array($accion, ['contacto_guardar', 'contacto_baja'], true)) {
        return null;
    }
    if (!isset(CONTACTO_ENTIDADES[$tipo]) || $id <= 0) {
        return ['Contacto de una entidad desconocida.'];
    }
    [$tabla, $pk, $colNombre] = CONTACTO_ENTIDADES[$tipo];
    $entidad = q1("SELECT $colNombre AS nombre FROM $tabla WHERE $pk = ?", [$id]);
    if (!$entidad) {
        return ['No se encontró a quién corresponde el contacto.'];
    }
    $idContacto = (int) post_str('id_contacto');

    if ($accion === 'contacto_baja') {
        q('UPDATE contactos SET activo = 0, actualizado_por = ? WHERE id_contacto = ? AND entidad_tipo = ? AND entidad_id = ?',
          [usuario_id(), $idContacto, $tipo, $id]);
        flash('ok', 'Contacto dado de baja.');
        redirigir($volver);
    }

    $form = ['id_contacto' => $idContacto, 'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))),
             'id_tipo_contacto' => post_str('id_tipo_contacto'), 'vinculo' => trim(post_str('vinculo')),
             'telefono' => post_str('telefono'), 'celular' => post_str('celular'), 'email' => trim(post_str('email')),
             'observaciones' => trim(post_str('observaciones')), 'principal' => post_str('principal') === '1' ? 1 : 0];
    // «Titular» es el propio cliente o comercializador: sin nombre, va el suyo.
    if ($form['nombre'] === '' && (int) $form['id_tipo_contacto']
        && (q1('SELECT nombre FROM contactos_tipos WHERE id_tipo_contacto = ?', [(int) $form['id_tipo_contacto']])['nombre'] ?? '') === 'Titular') {
        $form['nombre'] = (string) $entidad['nombre'];
    }
    $err = [];
    if ($form['nombre'] === '') {
        $err[] = 'Falta el nombre del contacto.';
    }
    $tel = $form['telefono'] === '' ? null : tel_normalizar($form['telefono'], false);
    $cel = $form['celular'] === '' ? null : tel_normalizar($form['celular'], true);
    if ($form['telefono'] !== '' && $tel === null) {
        $err[] = tel_error('El teléfono');
    }
    if ($form['celular'] !== '' && $cel === null) {
        $err[] = tel_error('El celular');
    }
    // El mail no es obligatorio, pero si está tiene que servir.
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'El email «' . $form['email'] . '» no es válido.';
    }
    if ($form['nombre'] !== '' && $tel === null && $cel === null && $form['email'] === '') {
        $err[] = 'Cargá al menos un teléfono, un celular o un email: un contacto sin dónde ubicarlo no sirve.';
    }
    if ($err) {
        return $err;
    }

    $tipoC = (int) $form['id_tipo_contacto'] ?: null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($idContacto > 0) {
            q('UPDATE contactos SET nombre = ?, id_tipo_contacto = ?, vinculo = ?, telefono = ?, celular = ?, email = ?, observaciones = ?,
                      principal = ?, actualizado_por = ?
                WHERE id_contacto = ? AND entidad_tipo = ? AND entidad_id = ? AND activo = 1',
              [mb_substr($form['nombre'], 0, 150), $tipoC, mb_substr($form['vinculo'], 0, 100) ?: null, $tel, $cel,
               mb_substr($form['email'], 0, 150) ?: null, mb_substr($form['observaciones'], 0, 300) ?: null, $form['principal'],
               usuario_id(), $idContacto, $tipo, $id]);
            $principal = (bool) $form['principal'];
        } else {
            // El primero de la entidad queda principal aunque no se haya tildado.
            $primero = !(int) q1('SELECT COUNT(*) AS n FROM contactos WHERE entidad_tipo = ? AND entidad_id = ? AND activo = 1', [$tipo, $id])['n'];
            $principal = $form['principal'] || $primero;
            q('INSERT INTO contactos (entidad_tipo, entidad_id, nombre, id_tipo_contacto, vinculo, telefono, celular, email, observaciones,
                                      principal, creado_por)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$tipo, $id, mb_substr($form['nombre'], 0, 150), $tipoC, mb_substr($form['vinculo'], 0, 100) ?: null, $tel, $cel,
               mb_substr($form['email'], 0, 150) ?: null, mb_substr($form['observaciones'], 0, 300) ?: null, $principal ? 1 : 0,
               usuario_id()]);
            $idContacto = (int) $pdo->lastInsertId();
        }
        if ($principal) {
            contacto_marcar_principal($tipo, $id, $idContacto);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [error_amigable($e)];
    }
    flash('ok', 'Contacto ' . $form['nombre'] . ($form['id_contacto'] ? ' actualizado.' : ' agregado.'));
    redirigir($volver);
    return [];
}

/**
 * La tarjeta de contactos de una ficha: la lista y el formulario (alta, o
 * edición si $editar es el id de un contacto). $base: la URL de la ficha.
 */
function contactos_tarjeta(string $tipo, int $id, string $base, ?array $form, int $editar = 0, array $errores = []): string
{
    try {
        $lista = contactos_de($tipo, $id);
        $tipos = contactos_tipos();
    } catch (PDOException $e) {
        return '<div class="card" id="contactos">' . card_head('Contactos')
             . '<div class="bloque"><div class="alert alert-warning"><div>Faltan las tablas de contactos: corré sql\financiera_clientes_ficha.sql.</div></div></div></div>';
    }
    $sep = strpos($base, '?') === false ? '?' : '&';
    $abierto = $form !== null || $editar > 0 || get_str('contacto') === 'nuevo';
    $v = $form;
    if ($v === null && $editar > 0) {
        foreach ($lista as $c) {
            if ((int) $c['id_contacto'] === $editar) {
                $v = ['id_contacto' => $editar, 'nombre' => $c['nombre'], 'id_tipo_contacto' => (string) $c['id_tipo_contacto'],
                      'vinculo' => (string) $c['vinculo'], 'telefono' => tel_fmt($c['telefono']), 'celular' => tel_fmt($c['celular']),
                      'email' => (string) $c['email'], 'observaciones' => (string) $c['observaciones'], 'principal' => (int) $c['principal']];
            }
        }
    }
    $v = $v ?? ['id_contacto' => 0, 'nombre' => '', 'id_tipo_contacto' => '', 'vinculo' => '', 'telefono' => '', 'celular' => '',
                'email' => '', 'observaciones' => '', 'principal' => 0];

    $out = '<div class="card" id="contactos">'
         . card_head('Contactos', $tipo === 'cliente'
                         ? 'sus teléfonos (Titular), referidos, familiares, garantes · el principal es el que se usa para llamar y WhatsApp'
                         : 'sus teléfonos (Titular) y a quién más llamar',
                     $abierto ? '' : boton('Agregar contacto', 'ghost', $base . $sep . 'contacto=nuevo#contactos', 'agregar', false, true));
    if ($errores) {
        $out .= '<div class="bloque">';
        foreach ($errores as $e) {
            $out .= '<div class="alert alert-danger"><div>' . h($e) . '</div></div>';
        }
        $out .= '</div>';
    }
    if ($abierto) {
        $out .= '<form method="post" action="' . h($base) . '#contactos" class="bloque">' . csrf_campo()
              . '<input type="hidden" name="accion" value="contacto_guardar">'
              . '<input type="hidden" name="id_contacto" value="' . (int) $v['id_contacto'] . '">'
              . '<div class="bloque-titulo">' . ((int) $v['id_contacto'] ? 'Editar contacto' : 'Nuevo contacto') . '</div>'
              . '<div class="campos">'
              . '<div class="campo" style="--span:4"><label for="ct_nombre">Nombre</label>'
              . '<input id="ct_nombre" name="nombre" value="' . h($v['nombre']) . '" maxlength="150" autocomplete="off" placeholder="vacío con tipo Titular: el del cliente"></div>'
              . '<div class="campo" style="--span:2"><label for="ct_tipo">Tipo</label>'
              . '<select id="ct_tipo" name="id_tipo_contacto">' . opciones($tipos, $v['id_tipo_contacto'], '—') . '</select></div>'
              . '<div class="campo" style="--span:3"><label for="ct_vinculo">Vínculo / cargo</label>'
              . '<input id="ct_vinculo" name="vinculo" value="' . h($v['vinculo']) . '" maxlength="100" placeholder="Hija, vecina, encargada de pagos…"></div>'
              . '<div class="campo campo-boton" style="--span:3"><label class="campo-check"><input type="checkbox" name="principal" value="1"'
              . ((int) $v['principal'] ? ' checked' : '') . '> Principal</label></div>'
              . '<div class="campo" style="--span:3"><label for="ct_cel">Celular</label>'
              . '<input id="ct_cel" name="celular" value="' . h($v['celular']) . '" maxlength="25" inputmode="tel" class="mono" data-tel="movil" placeholder="11 2345-6789"></div>'
              . '<div class="campo" style="--span:3"><label for="ct_tel">Teléfono</label>'
              . '<input id="ct_tel" name="telefono" value="' . h($v['telefono']) . '" maxlength="25" inputmode="tel" class="mono" data-tel="fijo" placeholder="011 4321-0000"></div>'
              . '<div class="campo" style="--span:6"><label for="ct_email">Email</label>'
              . '<input type="email" id="ct_email" name="email" value="' . h($v['email']) . '" maxlength="150"></div>'
              . '<div class="campo" style="--span:12"><label for="ct_obs">Observaciones</label>'
              . '<input id="ct_obs" name="observaciones" value="' . h($v['observaciones']) . '" maxlength="300" placeholder="Horario, de qué conoce al cliente…"></div>'
              . '</div><div class="form-acciones">'
              . boton('Cancelar', 'secondary', $base . '#contactos', 'cerrar')
              . boton((int) $v['id_contacto'] ? 'Guardar contacto' : 'Agregar contacto', 'primary', null, 'guardar', true)
              . '</div></form>';
    }
    $out .= '<div class="data-table-wrap"><table class="data-table"><thead><tr><th class="col-acciones">Acciones</th>'
          . '<th>Nombre</th><th>Tipo</th><th>Celular</th><th>Teléfono</th><th>Email</th><th>Cargado</th></tr></thead><tbody>';
    if (!$lista) {
        $out .= '<tr><td colspan="7" class="no-data">Sin contactos.</td></tr>';
    }
    foreach ($lista as $c) {
        $cid = (int) $c['id_contacto'];
        $quien = 'el ' . fecha(substr((string) $c['creado_el'], 0, 10)) . ($c['creado_por_usuario'] ? ' por ' . $c['creado_por_usuario'] : '');
        $mod = $c['actualizado_el'] ? 'modificado el ' . fecha(substr((string) $c['actualizado_el'], 0, 10))
             . ($c['actualizado_por_usuario'] ? ' por ' . $c['actualizado_por_usuario'] : '') : '';
        $out .= '<tr><td><div class="acciones">'
              . btn_accion('editar', $base . $sep . 'contacto=' . $cid . '#contactos', 'Editar')
              . accion_post($base . '#contactos', ['accion' => 'contacto_baja', 'id_contacto' => $cid], 'borrar', 'Dar de baja',
                            'Se da de baja el contacto ' . $c['nombre'] . '.', 'borrar')
              . '</div></td>'
              . '<td class="td-fuerte">' . h($c['nombre']) . ((int) $c['principal'] ? ' ' . estado('Principal', 'info') : '')
              . ($c['observaciones'] ? '<span class="segunda-linea">' . h($c['observaciones']) . '</span>' : '') . '</td>'
              . '<td>' . h((string) $c['tipo_nombre']) . ($c['vinculo'] ? '<span class="segunda-linea">' . h($c['vinculo']) . '</span>' : '') . '</td>'
              . '<td class="mono">' . h(tel_fmt($c['celular'])) . '</td>'
              . '<td class="mono">' . h(tel_fmt($c['telefono'])) . '</td>'
              . '<td>' . ($c['email'] ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '') . '</td>'
              . '<td class="td-muted">' . h($quien) . ($mod ? '<span class="segunda-linea">' . h($mod) . '</span>' : '') . '</td></tr>';
    }
    return $out . '</tbody></table></div></div>';
}
