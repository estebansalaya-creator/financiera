<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Observaciones y seguimiento del cliente (tabla clientes_observaciones)
//   En lugar de un campo de texto que se pisa: una observación por renglón,
//   con quién y cuándo la cargó. No se editan ni se borran (si algo cambió,
//   se agrega otra). Opcional: motivo (los de seguimiento de Reggio),
//   préstamo y fecha de recordatorio; el recordatorio se marca como hecho.
// Tablas: sql\financiera_clientes_ficha.sql.
// ---------------------------------------------------------------------

function observaciones_de(int $idCliente): array
{
    return q('SELECT o.*, m.nombre AS motivo, p.nro_prestamo, uc.usuario AS creado_por_usuario, uc.nombre AS creado_por_nombre,
                     uh.usuario AS hecho_por_usuario
                FROM clientes_observaciones o
                LEFT JOIN seguimiento_motivos m ON m.id_motivo = o.id_motivo
                LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo
                LEFT JOIN usuarios uc ON uc.id_usuario = o.creado_por
                LEFT JOIN usuarios uh ON uh.id_usuario = o.hecho_por
               WHERE o.id_cliente = ?
               ORDER BY o.creado_el DESC, o.id_observacion DESC', [$idCliente]);
}

/**
 * Atiende los POST de la tarjeta (accion = observacion_agregar | observacion_hecho).
 * null si el pedido no es de observaciones; si no, los errores (vacío: ya redirigió).
 */
function observacion_acciones(int $idCliente, string $volver, ?array &$form): ?array
{
    $accion = post_str('accion');
    if (!in_array($accion, ['observacion_agregar', 'observacion_hecho'], true)) {
        return null;
    }
    if (!q1('SELECT 1 AS x FROM clientes WHERE id_cliente = ?', [$idCliente])) {
        return ['No se encontró el cliente.'];
    }
    if ($accion === 'observacion_hecho') {
        q('UPDATE clientes_observaciones SET hecho_el = NOW(), hecho_por = ?
            WHERE id_observacion = ? AND id_cliente = ? AND recordatorio IS NOT NULL AND hecho_el IS NULL',
          [usuario_id(), (int) post_str('id_observacion'), $idCliente]);
        flash('ok', 'Recordatorio marcado como hecho.');
        redirigir($volver);
    }

    $form = ['texto' => trim(post_str('texto')), 'id_motivo' => post_str('id_motivo'),
             'id_prestamo' => post_str('id_prestamo'), 'recordatorio' => post_str('recordatorio')];
    $err = [];
    if ($form['texto'] === '') {
        $err[] = 'Escribí la observación.';
    } elseif (mb_strlen($form['texto']) > 4000) {
        $err[] = 'La observación es muy larga (hasta 4000 caracteres).';
    }
    $rec = $form['recordatorio'] === '' ? null : leer_fecha($form['recordatorio']);
    if ($form['recordatorio'] !== '' && !$rec) {
        $err[] = 'La fecha de recordatorio no es válida.';
    }
    $idPrest = (int) $form['id_prestamo'] ?: null;
    if ($idPrest && !q1('SELECT 1 AS x FROM prestamos WHERE id_prestamo = ? AND id_cliente = ?', [$idPrest, $idCliente])) {
        $err[] = 'El préstamo elegido no es de este cliente.';
    }
    if ($err) {
        return $err;
    }
    q('INSERT INTO clientes_observaciones (id_cliente, id_prestamo, id_motivo, texto, recordatorio, creado_por) VALUES (?,?,?,?,?,?)',
      [$idCliente, $idPrest, (int) $form['id_motivo'] ?: null, $form['texto'], $rec, usuario_id()]);
    flash('ok', 'Observación agregada.');
    redirigir($volver);
    return [];
}

/** La tarjeta de observaciones de la ficha: el formulario arriba y la historia abajo. */
function observaciones_tarjeta(int $idCliente, string $base, array $prestamos, ?array $form, array $errores = []): string
{
    try {
        $lista = observaciones_de($idCliente);
        $motivos = array_column(q('SELECT id_motivo, nombre FROM seguimiento_motivos WHERE activo = 1 ORDER BY nombre'), 'nombre', 'id_motivo');
    } catch (PDOException $e) {
        return '<div class="card" id="observaciones">' . card_head('Observaciones')
             . '<div class="bloque"><div class="alert alert-warning"><div>Faltan las tablas de observaciones: corré sql\financiera_clientes_ficha.sql.</div></div></div></div>';
    }
    $v = $form ?? ['texto' => '', 'id_motivo' => '', 'id_prestamo' => '', 'recordatorio' => ''];
    $prest = [];
    foreach ($prestamos as $p) {
        $prest[$p['id_prestamo']] = $p['nro_prestamo'] . ' · ' . $p['tipo_asociado'];
    }
    $hoy = date('Y-m-d');
    $pend = count(array_filter($lista, function ($o) use ($hoy) { return $o['recordatorio'] && !$o['hecho_el'] && $o['recordatorio'] <= $hoy; }));

    $out = '<div class="card" id="observaciones">'
         . card_head('Observaciones', count($lista) . ' cargada' . (count($lista) === 1 ? '' : 's')
                     . ($pend ? ' · ' . $pend . ' recordatorio' . ($pend === 1 ? '' : 's') . ' para hoy o vencido' . ($pend === 1 ? '' : 's') : ''));
    foreach ($errores as $e) {
        $out .= '<div class="bloque"><div class="alert alert-danger"><div>' . h($e) . '</div></div></div>';
    }
    $out .= '<form method="post" action="' . h($base) . '#observaciones" class="bloque">' . csrf_campo()
          . '<input type="hidden" name="accion" value="observacion_agregar">'
          . '<div class="campos">'
          . '<div class="campo" style="--span:12"><label for="obs_texto">Nueva observación <span class="req">*</span></label>'
          . '<textarea id="obs_texto" name="texto" rows="2" maxlength="4000" required placeholder="Qué pasó, qué se habló, qué quedó pendiente…">'
          . h($v['texto']) . '</textarea></div>'
          . '<div class="campo" style="--span:3"><label for="obs_motivo">Motivo</label>'
          . '<select id="obs_motivo" name="id_motivo">' . opciones($motivos, $v['id_motivo'], '—') . '</select></div>'
          . '<div class="campo" style="--span:4"><label for="obs_prest">Préstamo</label>'
          . '<select id="obs_prest" name="id_prestamo">' . opciones($prest, $v['id_prestamo'], $prest ? 'Ninguno en particular' : 'Sin préstamos') . '</select></div>'
          . '<div class="campo" style="--span:2"><label for="obs_rec">Recordatorio</label>'
          . '<input type="date" id="obs_rec" name="recordatorio" value="' . h($v['recordatorio']) . '" min="' . $hoy . '"></div>'
          . '<div class="campo campo-boton" style="--span:3">' . boton('Agregar observación', 'primary', null, 'agregar', true) . '</div>'
          . '</div><span class="campo-ayuda">Queda registrado quién la carga y cuándo. No se edita ni se borra: si algo cambió, agregá otra.</span></form>';

    $out .= '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Usuario</th><th>Observación</th>'
          . '<th>Recordatorio</th></tr></thead><tbody>';
    if (!$lista) {
        $out .= '<tr><td colspan="4" class="no-data">Sin observaciones.</td></tr>';
    }
    foreach ($lista as $o) {
        $dt = (string) $o['creado_el'];
        $rec = '';
        if ($o['recordatorio']) {
            if ($o['hecho_el']) {
                $rec = estado('Hecho', 'ok') . '<span class="segunda-linea">' . h(fecha($o['recordatorio'])) . ' · '
                     . h(fecha(substr((string) $o['hecho_el'], 0, 10))) . ($o['hecho_por_usuario'] ? ' ' . h($o['hecho_por_usuario']) : '') . '</span>';
            } else {
                $rec = estado(fecha($o['recordatorio']), $o['recordatorio'] <= $hoy ? 'warn' : 'info')
                     . ' ' . accion_post($base . '#observaciones', ['accion' => 'observacion_hecho', 'id_observacion' => (int) $o['id_observacion']],
                                         'ok', 'Marcar hecho');
            }
        }
        $out .= '<tr><td class="td-nowrap">' . h(fecha(substr($dt, 0, 10))) . '<span class="segunda-linea">' . h(substr($dt, 11, 5)) . '</span></td>'
              . '<td>' . h((string) ($o['creado_por_nombre'] ?: $o['creado_por_usuario'])) . '</td>'
              . '<td class="td-wrap">' . ($o['motivo'] || $o['nro_prestamo']
                    ? '<span class="segunda-linea" style="margin:0 0 2px">' . h(implode(' · ', array_filter([$o['motivo'], $o['nro_prestamo'] ? 'préstamo ' . $o['nro_prestamo'] : null]))) . '</span>' : '')
              . nl2br(h((string) $o['texto'])) . '</td>'
              . '<td>' . $rec . '</td></tr>';
    }
    return $out . '</tbody></table></div></div>';
}
