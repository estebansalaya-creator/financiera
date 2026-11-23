<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Escalas de comisión (tabla comision_reglas)
//   - Cada escala es un tramo del monto del préstamo con su % de comisión y,
//     opcional, su % cuando la operación vino por telemarketing. Si lo deja
//     vacío, con telemarketing se cobra el % de la escala por la fracción del
//     tipo de asociado (tipos_asociado.pct_telemarketing: 1 = el mismo,
//     0,5 = la mitad), que se configura en la ficha del tipo.
//   - La general de un tipo de asociado no tiene comercializador y vale para
//     todos (tipos_asociado.php).
//   - La excepción de un comercializador es la misma fila con su id_vendedor
//     (vendedores.php). Si cubre el tipo y el monto del préstamo, gana
//     sobre la general: lo decide fn_comision_regla.
//   - Al liquidar, el % queda guardado en el préstamo: cambiar una escala no
//     toca lo ya liquidado. Para un aumento se cierra la escala (vigencia
//     «hasta») y se carga otra desde la fecha nueva.
//   - Las dos pantallas las muestran igual: la lista con la barra de todas
//     las grillas y el formulario arriba (escala_form, escalas_grilla,
//     escala_acciones).
// ---------------------------------------------------------------------

const ESCALA_BASES     = ['CAPITAL' => 'Capital', 'NETO_DESEMBOLSO' => 'Neto desembolsado'];
const ESCALA_MONTO_MAX = 999999999.0;     // «sin tope»

/** Fracción guardada (0.085) -> texto del campo («8,5»). */
function escala_pct_campo($f): string
{
    if ($f === null || $f === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $f * 100, 4, ',', ''), '0'), ',');
}

/** Importe guardado -> texto del campo; el tope queda vacío («sin tope»). */
function escala_monto_campo($n, bool $hasta = false): string
{
    if ($n === null || $n === '' || ($hasta && (float) $n >= ESCALA_MONTO_MAX)) {
        return '';
    }
    return number_format((float) $n, (float) $n == floor((float) $n) ? 0 : 2, ',', '.');
}

/** «$ 0 a $ 500.000», «desde $ 500.000», «cualquier monto». */
function escala_tramo_txt($desde, $hasta): string
{
    $d = (float) $desde;
    $h = (float) $hasta;
    $m = function (float $x): string { return '$ ' . number_format($x, $x == floor($x) ? 0 : 2, ',', '.'); };
    if ($h >= ESCALA_MONTO_MAX) {
        return $d <= 0 ? 'Cualquier monto' : 'Desde ' . $m($d);
    }
    return $m($d) . ' a ' . $m($h);
}

/** El tramo dentro de una frase: «de $ 0 a $ 500.000», «desde $ 500.000», «de cualquier monto». */
function escala_tramo_frase($desde, $hasta): string
{
    $t = escala_tramo_txt($desde, $hasta);
    return strncmp($t, 'Desde', 5) === 0 ? 'desde' . substr($t, 5) : ($t === 'Cualquier monto' ? 'de cualquier monto' : 'de ' . $t);
}

/** «18 %» o «18 % · TM 9 %». */
function escala_pct_txt($pct, $pctTm): string
{
    $p = escala_pct_campo($pct) . ' %';
    return $pctTm === null || (float) $pctTm === (float) $pct ? $p . ' · TM igual' : $p . ' · TM ' . escala_pct_campo($pctTm) . ' %';
}

/**
 * El % que se cobra con telemarketing: el de la escala si lo tiene; si no,
 * el % de la escala por la fracción del tipo de asociado.
 */
function escala_tm_efectivo($pct, $pctTm, $factorTipo): float
{
    if ($pctTm !== null && $pctTm !== '') {
        return (float) $pctTm;
    }
    return round((float) $pct * (float) ($factorTipo ?? 1), 6);
}

/** Celda «% con telemarketing»: el propio de la escala, o el que sale del tipo (en gris). */
function escala_tm_celda($pct, $pctTm, $factorTipo): string
{
    $tm = escala_tm_efectivo($pct, $pctTm, $factorTipo);
    if ($pctTm !== null && $pctTm !== '') {
        return h(escala_pct_campo($tm)) . ' %';
    }
    return '<span class="td-muted" title="Lo fija el tipo de asociado">' . h(escala_pct_campo($tm)) . ' % · del tipo</span>';
}

/** Una fila de la base con la forma del formulario (todo texto). */
function escala_a_form(array $r): array
{
    return ['id_regla' => (int) $r['id_regla'], 'id_tipo_asociado' => (string) ($r['id_tipo_asociado'] ?? ''),
            'monto_desde' => escala_monto_campo($r['monto_desde']), 'monto_hasta' => escala_monto_campo($r['monto_hasta'], true),
            'porcentaje' => escala_pct_campo($r['porcentaje']), 'porcentaje_tm' => escala_pct_campo($r['porcentaje_tm']),
            'base_calculo' => (string) $r['base_calculo'], 'vigencia_desde' => (string) $r['vigencia_desde'],
            'vigencia_hasta' => (string) ($r['vigencia_hasta'] ?? ''), 'activo' => (int) $r['activo'],
            'usos' => (int) ($r['usos'] ?? 0)];
}

/**
 * Las escalas de un tipo de asociado (las generales) o de un comercializador (sus
 * excepciones), con cuántos préstamos se liquidaron con cada una.
 */
function escalas_de(?int $idTipo, ?int $idVendedor, bool $conInactivas = true): array
{
    $w = $idVendedor ? 'r.id_vendedor = ?' : 'r.id_vendedor IS NULL AND r.id_tipo_asociado = ?';
    return q("SELECT r.*, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre, t.pct_telemarketing,
                     (SELECT COUNT(*) FROM prestamos p WHERE p.id_comision_regla = r.id_regla) AS usos
                FROM comision_reglas r
                LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
               WHERE $w" . ($conInactivas ? '' : ' AND r.activo = 1') . "
               ORDER BY r.activo DESC, (r.vigencia_hasta IS NOT NULL AND r.vigencia_hasta < CURDATE()), t.nombre,
                        r.monto_desde, r.vigencia_desde DESC, r.id_regla",
             [$idVendedor ?: $idTipo]);
}

/** Cuántos préstamos se liquidaron con una escala (lo que impide eliminarla). */
function escala_usos(int $idRegla): int
{
    return (int) q1('SELECT (SELECT COUNT(*) FROM prestamos WHERE id_comision_regla = ?)
                          + (SELECT COUNT(*) FROM comisiones_devengadas WHERE id_regla = ?) AS n', [$idRegla, $idRegla])['n'];
}

/**
 * Las acciones de las escalas de una pantalla (la ficha del tipo de
 * asociado o la del comercializador). $idTipo: el de las generales; $idVendedor:
 * el de las excepciones (el tipo sale del formulario). $volver: la lista.
 * Devuelve los errores (si los hay, la pantalla vuelve a mostrar el
 * formulario con lo cargado en $form) o redirige.
 */
function escala_acciones(string $accion, ?int $idTipo, ?int $idVendedor, string $volver, ?array &$form): array
{
    $idRegla = (int) post_str('id_regla');
    $actual = $idRegla ? q1('SELECT * FROM comision_reglas WHERE id_regla = ? AND id_vendedor <=> ?', [$idRegla, $idVendedor]) : null;
    if ($idRegla && (!$actual || ($idTipo && (int) $actual['id_tipo_asociado'] !== $idTipo))) {
        flash('danger', 'La escala ya no existe.');
        redirigir($volver);
    }
    $quien = $idVendedor ? 'La excepción' : 'La escala';

    if ($accion === 'escala_borrar' && $actual) {
        if (escala_usos($idRegla)) {
            flash('danger', 'No se puede eliminar: se liquidaron préstamos con ella. Dala de baja o cerrala con la vigencia «hasta».');
        } else {
            q('DELETE FROM comision_reglas WHERE id_regla = ?', [$idRegla]);
            flash('ok', $quien . ' ' . escala_tramo_frase($actual['monto_desde'], $actual['monto_hasta']) . ' se eliminó.');
        }
        redirigir($volver);
    }
    if (in_array($accion, ['escala_baja', 'escala_activar'], true) && $actual) {
        if ($accion === 'escala_activar' && ($choque = escala_choque($actual, $idRegla))) {
            flash('danger', 'No se puede activar: ' . $choque);
        } else {
            q('UPDATE comision_reglas SET activo = ? WHERE id_regla = ?', [$accion === 'escala_activar' ? 1 : 0, $idRegla]);
            flash('ok', $quien . ' ' . escala_tramo_frase($actual['monto_desde'], $actual['monto_hasta'])
                  . ($accion === 'escala_activar' ? ' quedó activa.' : ' quedó de baja: no se usa para los préstamos que se liquiden de ahora en más.'));
        }
        redirigir($volver);
    }
    if ($accion !== 'escala_guardar') {
        return [];
    }

    $form = ['id_regla' => $idRegla, 'id_tipo_asociado' => $idVendedor ? post_str('id_tipo_asociado') : (string) $idTipo,
             'monto_desde' => post_str('monto_desde'), 'monto_hasta' => post_str('monto_hasta'),
             'porcentaje' => post_str('porcentaje'), 'porcentaje_tm' => post_str('porcentaje_tm'),
             'base_calculo' => post_str('base_calculo'), 'vigencia_desde' => post_str('vigencia_desde'),
             'vigencia_hasta' => post_str('vigencia_hasta'), 'activo' => post_str('activo') === '1' ? 1 : 0];
    $err = [];
    $tipo = q1('SELECT id_tipo_asociado, codigo FROM tipos_asociado WHERE id_tipo_asociado = ?', [(int) $form['id_tipo_asociado']]);
    if (!$tipo) {
        $err[] = 'Elegí el tipo de asociado.';
    }
    $md = $form['monto_desde'] === '' ? 0.0 : leer_num($form['monto_desde']);
    $mh = $form['monto_hasta'] === '' ? ESCALA_MONTO_MAX : leer_num($form['monto_hasta']);
    if ($md === null || $md < 0) {
        $err[] = 'El monto «desde» no es un importe válido.';
    } elseif ($mh === null || $mh < $md || $mh > ESCALA_MONTO_MAX) {
        $err[] = 'El monto «hasta» no puede ser menor que el «desde» (vacío: sin tope).';
    }
    $p = leer_num($form['porcentaje']);
    if ($p === null || $p < 0 || $p > 100) {
        $err[] = 'El % de comisión va de 0 a 100.';
    }
    $ptm = $form['porcentaje_tm'] === '' ? null : leer_num($form['porcentaje_tm']);
    if ($form['porcentaje_tm'] !== '' && ($ptm === null || $ptm < 0 || $ptm > 100)) {
        $err[] = 'El % con telemarketing va de 0 a 100 (vacío: lo fija el tipo de asociado).';
    }
    if (!isset(ESCALA_BASES[$form['base_calculo']])) {
        $err[] = 'Elegí sobre qué se calcula.';
    }
    $vd = leer_fecha($form['vigencia_desde']);
    $vh = $form['vigencia_hasta'] === '' ? null : leer_fecha($form['vigencia_hasta']);
    if (!$vd) {
        $err[] = 'Falta la fecha desde la que rige.';
    } elseif ($form['vigencia_hasta'] !== '' && (!$vh || $vh < $vd)) {
        $err[] = 'La vigencia «hasta» no puede ser anterior a «desde».';
    }
    if (!$err && $form['activo']) {
        $choque = escala_choque(['id_vendedor' => $idVendedor, 'id_tipo_asociado' => (int) $tipo['id_tipo_asociado'],
                                 'monto_desde' => $md, 'monto_hasta' => $mh, 'vigencia_desde' => $vd, 'vigencia_hasta' => $vh], $idRegla);
        if ($choque) {
            $err[] = ucfirst($choque);
        }
    }
    if ($err) {
        return $err;
    }

    $codVend = $idVendedor ? (string) (q1('SELECT codigo FROM vendedores WHERE id_vendedor = ?', [$idVendedor])['codigo'] ?? '') : '';
    $desc = mb_substr($tipo['codigo'] . ' - ' . ($idVendedor ? $codVend . ' (excepción)' : 'todos los comercializadores') . ' - ' . escala_tramo_txt($md, $mh), 0, 120);
    $v = [$desc, (int) $tipo['id_tipo_asociado'], round($md, 2), round($mh, 2), round($p / 100, 6),
          $ptm === null ? null : round($ptm / 100, 6), $form['base_calculo'], $vd, $vh, $form['activo']];
    if ($idRegla) {
        q('UPDATE comision_reglas SET descripcion = ?, id_tipo_asociado = ?, monto_desde = ?, monto_hasta = ?, porcentaje = ?,
                  porcentaje_tm = ?, base_calculo = ?, vigencia_desde = ?, vigencia_hasta = ?, activo = ?, telemarketing = NULL
            WHERE id_regla = ?', array_merge($v, [$idRegla]));
    } else {
        q('INSERT INTO comision_reglas (descripcion, id_tipo_asociado, monto_desde, monto_hasta, porcentaje, porcentaje_tm,
                                        base_calculo, vigencia_desde, vigencia_hasta, activo, id_vendedor, telemarketing,
                                        plazo_desde, plazo_hasta, momento, prioridad)
           VALUES (?,?,?,?,?,?,?,?,?,?,?, NULL, 1, 999, \'LIQUIDACION\', ?)', array_merge($v, [$idVendedor, $idVendedor ? 10 : 0]));
    }
    flash('ok', $quien . ' ' . escala_tramo_frase($md, $mh) . ' (' . $tipo['codigo'] . ') ' . ($idRegla ? 'se guardó.' : 'se agregó.')
          . ' Rige para los préstamos que se liquiden de ahora en más.');
    redirigir($volver);
    return [];
}

/**
 * Otra escala activa del mismo alcance (general o del mismo comercializador, mismo
 * tipo) que cubre los mismos montos en las mismas fechas: no se sabría cuál
 * usar. Devuelve el texto del choque o null.
 */
function escala_choque(array $e, int $excepto): ?string
{
    $o = q1("SELECT monto_desde, monto_hasta, vigencia_desde, vigencia_hasta FROM comision_reglas
              WHERE activo = 1 AND id_regla <> ? AND id_vendedor <=> ? AND id_tipo_asociado = ?
                AND monto_desde <= ? AND ? <= monto_hasta
                AND vigencia_desde <= COALESCE(?, '9999-12-31') AND ? <= COALESCE(vigencia_hasta, '9999-12-31')
              LIMIT 1",
            [$excepto, $e['id_vendedor'] ?: null, $e['id_tipo_asociado'], $e['monto_hasta'], $e['monto_desde'],
             $e['vigencia_hasta'] ?: null, $e['vigencia_desde']]);
    if (!$o) {
        return null;
    }
    return 'ya hay una activa ' . escala_tramo_frase($o['monto_desde'], $o['monto_hasta']) . ' vigente desde el ' . fecha($o['vigencia_desde'])
         . ($o['vigencia_hasta'] ? ' hasta el ' . fecha($o['vigencia_hasta']) : '') . '. Para que una reemplace a la otra, cerrá la anterior con la vigencia «hasta»;'
         . ' para tramos, que el «desde» de una sea mayor que el «hasta» de la otra.';
}

/**
 * El formulario de una escala (alta o edición), arriba de la lista.
 * $tipos (id => nombre) solo en las excepciones de un comercializador.
 */
function escala_form(array $v, string $accionUrl, string $volver, ?array $tipos, string $titulo): string
{
    $nueva = !(int) $v['id_regla'];
    $usos = $nueva ? 0 : escala_usos((int) $v['id_regla']);
    $out = '<form method="post" action="' . h($accionUrl) . '" autocomplete="off">' . csrf_campo()
         . '<input type="hidden" name="accion" value="escala_guardar"><input type="hidden" name="id_regla" value="' . (int) $v['id_regla'] . '">'
         . '<div class="card">' . card_head($titulo, $usos ? 'se liquidaron ' . num($usos) . ($usos === 1 ? ' préstamo' : ' préstamos') . ' con ella: para un cambio de %, mejor cerrala y cargá otra' : '',
                                              boton('Volver', 'secondary', $volver, 'volver', false, true))
         . '<div class="bloque"><div class="campos">';
    if ($tipos !== null) {
        $out .= '<div class="campo" style="--span:4"><label for="esc_tipo">Tipo de asociado <span class="req">*</span></label>'
              . '<select id="esc_tipo" name="id_tipo_asociado" required>' . opciones($tipos, $v['id_tipo_asociado'] ?? '', 'Elegí…') . '</select></div>';
    }
    $out .= '<div class="campo" style="--span:2"><label for="esc_md">Monto desde</label>'
          . '<input id="esc_md" name="monto_desde" value="' . h((string) $v['monto_desde']) . '" inputmode="decimal" class="derecha" placeholder="0"></div>'
          . '<div class="campo" style="--span:2"><label for="esc_mh">Monto hasta</label>'
          . '<input id="esc_mh" name="monto_hasta" value="' . h((string) $v['monto_hasta']) . '" inputmode="decimal" class="derecha" placeholder="sin tope"></div>'
          . '<div class="campo" style="--span:2"><label for="esc_p">% comisión <span class="req">*</span></label>'
          . '<input id="esc_p" name="porcentaje" value="' . h((string) $v['porcentaje']) . '" inputmode="decimal" class="derecha" required placeholder="18"></div>'
          . '<div class="campo" style="--span:2"><label for="esc_ptm">% con telemarketing</label>'
          . '<input id="esc_ptm" name="porcentaje_tm" value="' . h((string) $v['porcentaje_tm']) . '" inputmode="decimal" class="derecha" placeholder="del tipo"></div>'
          . '<div class="campo" style="--span:2"><label for="esc_base">Sobre</label>'
          . '<select id="esc_base" name="base_calculo">' . opciones(ESCALA_BASES, $v['base_calculo'] ?: 'CAPITAL') . '</select></div>'
          . '<div class="campo" style="--span:2"><label for="esc_vd">Rige desde <span class="req">*</span></label>'
          . '<input type="date" id="esc_vd" name="vigencia_desde" value="' . h((string) $v['vigencia_desde']) . '" required></div>'
          . '<div class="campo" style="--span:2"><label for="esc_vh">Hasta</label>'
          . '<input type="date" id="esc_vh" name="vigencia_hasta" value="' . h((string) $v['vigencia_hasta']) . '"></div>'
          . '<div class="campo campo-boton" style="--span:2"><label class="campo-check"><input type="checkbox" name="activo" value="1"'
          . ((int) $v['activo'] ? ' checked' : '') . '> Activa</label></div>'
          . '</div><p class="campo-ayuda" style="margin:8px 0 0">El monto es el capital del préstamo; «hasta» vacío es sin tope. '
          . 'Con telemarketing se usa ese %; vacío, el mismo. El % queda guardado en cada préstamo al liquidarlo.</p></div>'
          . '<div class="form-acciones">' . boton($nueva ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) . '</div></div></form>';
    return $out;
}

/**
 * La lista de escalas con la barra de todas las grillas. $base: la URL de
 * la lista (para editar se le suma &escala=N); $conTipo: la columna del
 * tipo de asociado (en las excepciones de un comercializador).
 */
function escalas_grilla(array $filas, string $base, bool $conTipo, string $unidad, string $vacio, string $filtros = '', bool $hayFiltro = false): string
{
    $hoy = date('Y-m-d');
    $out = barra_local($base, $conTipo ? 'Tipo de asociado o monto…' : 'Monto o vigencia…', $filtros, $hayFiltro)
         . '<div class="data-table-wrap"><table class="data-table" data-local data-paginada data-unidad="' . h($unidad) . '">'
         . '<thead><tr><th class="col-acciones" data-no-sort>Acciones</th>' . ($conTipo ? '<th>Tipo de asociado</th>' : '')
         . '<th class="th-right">Desde</th><th class="th-right">Hasta</th><th class="th-right">% comisión</th><th class="th-right">% con telemarketing</th>'
         . '<th>Sobre</th><th>Vigencia</th><th class="th-right">Préstamos</th><th>Estado</th></tr></thead><tbody>';
    foreach ($filas as $e) {
        $rid = (int) $e['id_regla'];
        $usos = (int) $e['usos'];
        $vencida = $e['vigencia_hasta'] !== null && $e['vigencia_hasta'] < $hoy;
        $futura = $e['vigencia_desde'] > $hoy;
        $est = !(int) $e['activo'] ? estado('De baja', 'neutro') : ($vencida ? estado('Vencida', 'neutro') : ($futura ? estado('Futura', 'info') : estado('Vigente', 'ok')));
        $tramo = escala_tramo_frase($e['monto_desde'], $e['monto_hasta']);
        $out .= '<tr class="' . ((int) $e['activo'] && !$vencida ? '' : 'fila-baja') . '"><td><div class="acciones">'
              . btn_accion('editar', $base . '&escala=' . $rid, 'Editar')
              . ((int) $e['activo']
                  ? accion_post($base, ['accion' => 'escala_baja', 'id_regla' => $rid], 'cerrar', 'Dar de baja',
                                'La escala ' . $tramo . ' deja de usarse para los préstamos que se liquiden de ahora en más. Lo liquidado no cambia.', 'borrar')
                  : accion_post($base, ['accion' => 'escala_activar', 'id_regla' => $rid], 'ok', 'Activar'))
              . (!$usos ? accion_post($base, ['accion' => 'escala_borrar', 'id_regla' => $rid], 'borrar', 'Eliminar',
                                     'Se elimina la escala ' . $tramo . '. Ningún préstamo se liquidó con ella.', 'borrar') : '')
              . '</div></td>'
              . ($conTipo ? '<td class="td-fuerte">' . h((string) $e['tipo_nombre']) . '</td>' : '')
              . '<td class="td-right" data-orden="' . h($e['monto_desde']) . '">' . money($e['monto_desde']) . '</td>'
              . '<td class="td-right" data-orden="' . h($e['monto_hasta']) . '">' . ((float) $e['monto_hasta'] >= ESCALA_MONTO_MAX ? '<span class="td-muted">sin tope</span>' : money($e['monto_hasta'])) . '</td>'
              . '<td class="td-right td-fuerte" data-orden="' . h($e['porcentaje']) . '">' . h(escala_pct_campo($e['porcentaje'])) . ' %</td>'
              . '<td class="td-right" data-orden="' . h(escala_tm_efectivo($e['porcentaje'], $e['porcentaje_tm'], $e['pct_telemarketing'] ?? 1)) . '">'
              . escala_tm_celda($e['porcentaje'], $e['porcentaje_tm'], $e['pct_telemarketing'] ?? 1) . '</td>'
              . '<td>' . h(ESCALA_BASES[$e['base_calculo']] ?? $e['base_calculo']) . '</td>'
              . '<td data-orden="' . h($e['vigencia_desde']) . '">desde el ' . h(fecha($e['vigencia_desde']))
              . ($e['vigencia_hasta'] ? '<span class="segunda-linea">hasta el ' . h(fecha($e['vigencia_hasta'])) . '</span>' : '') . '</td>'
              . '<td class="td-right" data-orden="' . $usos . '">' . ($usos ? num($usos) : '<span class="td-muted">—</span>') . '</td>'
              . '<td>' . $est . '</td></tr>';
    }
    if (!$filas) {
        $out .= '<tr><td colspan="' . ($conTipo ? 10 : 9) . '" class="no-data">' . h($vacio) . '</td></tr>';
    }
    return $out . '</tbody></table></div>' . paginacion_local();
}

/** Los valores del formulario de una escala: la de la base, o una nueva. */
function escala_valores(?array $r, ?int $idTipo = null): array
{
    if ($r) {
        return escala_a_form($r);
    }
    return ['id_regla' => 0, 'id_tipo_asociado' => $idTipo ? (string) $idTipo : '', 'monto_desde' => '', 'monto_hasta' => '',
            'porcentaje' => '', 'porcentaje_tm' => '', 'base_calculo' => 'CAPITAL', 'vigencia_desde' => date('Y-m-d'),
            'vigencia_hasta' => '', 'activo' => 1, 'usos' => 0];
}

/**
 * Los montos del tipo de asociado que hoy no tienen escala general: un
 * préstamo por esos montos no genera comisión (salvo una excepción).
 * Devuelve los huecos como [desde, hasta].
 */
function escalas_huecos(array $escalas, float $min, float $max, ?string $fecha = null): array
{
    $fecha = $fecha ?? date('Y-m-d');
    $tramos = [];
    foreach ($escalas as $e) {
        if ((int) $e['activo'] && $e['vigencia_desde'] <= $fecha && ($e['vigencia_hasta'] === null || $e['vigencia_hasta'] >= $fecha)) {
            $tramos[] = [(float) $e['monto_desde'], (float) $e['monto_hasta']];
        }
    }
    usort($tramos, function ($a, $b) { return $a[0] <=> $b[0]; });
    $huecos = [];
    $cubierto = $min;                                   // el primer monto que falta cubrir
    foreach ($tramos as [$d, $h]) {
        if ($h < $cubierto) {
            continue;
        }
        if ($d > $cubierto + 0.001) {
            $huecos[] = [$cubierto, round($d - 0.01, 2)];
        }
        $cubierto = max($cubierto, round($h + 0.01, 2));
        if ($cubierto > $max) {
            break;
        }
    }
    if ($cubierto <= $max) {
        $huecos[] = [$cubierto, $max];
    }
    return $huecos;
}

/**
 * La comisión que tocaría al liquidar (el mismo cálculo que
 * sp_liquidar_prestamo): ['pct', 'monto', 'origen', 'id_regla'] o null si
 * no hay comercializador. origen: «manual», «excepción», «escala» o «sin escala».
 */
function comision_estimada(?int $idVendedor, int $idTipo, int $plazo, float $capital, float $gastos, string $fecha,
                           bool $telemarketing, ?float $manualPct = null): ?array
{
    if (!$idVendedor) {
        return null;
    }
    if ($manualPct !== null) {
        return ['pct' => $manualPct, 'monto' => round($capital * $manualPct, 2), 'origen' => 'manual', 'id_regla' => null];
    }
    $r = q1('SELECT r.*, t.pct_telemarketing FROM comision_reglas r
               LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
              WHERE r.id_regla = fn_comision_regla(?, ?, ?, ?, ?, ?)',
            [$idVendedor, $idTipo, $plazo, $capital, $fecha, $telemarketing ? 1 : 0]);
    if (!$r) {
        return ['pct' => null, 'monto' => null, 'origen' => 'sin escala', 'id_regla' => null];
    }
    $pct = $telemarketing ? escala_tm_efectivo($r['porcentaje'], $r['porcentaje_tm'], $r['pct_telemarketing'] ?? 1) : (float) $r['porcentaje'];
    $base = $r['base_calculo'] === 'NETO_DESEMBOLSO' ? $capital - $gastos : $capital;
    return ['pct' => $pct, 'monto' => round($base * $pct, 2), 'origen' => $r['id_vendedor'] ? 'excepción' : 'escala',
            'id_regla' => (int) $r['id_regla']];
}
