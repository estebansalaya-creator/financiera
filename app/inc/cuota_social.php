<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Cuota social (la «cuota gastos» de Reggio). Tablas y reglas en
// sql\financiera_cuota_social.sql. En resumen:
//   - ASOCIADO desde el primer préstamo (o a mano) hasta la baja de cuota
//     social; genera aunque el préstamo termine.
//   - Solo si su tipo de asociado paga cuota social.
//   - Valor del tipo de asociado con vigencias: meses_inicial meses al
//     valor_inicial; después valor_mensual + valor_adicional (si tiene).
//   - Cada cuota queda con el valor del mes en que se generó.
// Pantallas: cuota_social.php (generar y ver un período), la solapa
// «Cuota social» de Tipos de asociado (valores) y la tarjeta de la ficha
// del cliente (estado de cuenta, alta y baja).
// cs_imputar() es lo que van a usar los débitos para aplicar lo cobrado.
// ---------------------------------------------------------------------

const CS_CONCEPTOS = ['INICIAL' => 'Inicial', 'MENSUAL' => 'Mensual', 'ADICIONAL' => 'Adicional', 'MANUAL' => 'Manual'];
const CS_ESTADOS   = ['PENDIENTE' => 'Pendiente', 'PARCIAL' => 'Parcial', 'PAGADA' => 'Pagada', 'ANULADA' => 'Anulada'];
const CS_MESES     = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

function cs_instalado(): bool
{
    static $si = null;
    if ($si === null) {
        try {
            q('SELECT 1 FROM cuotas_sociales LIMIT 1');
            $si = true;
        } catch (PDOException $e) {
            $si = false;
        }
    }
    return $si;
}

/** «2026-11-01» -> «noviembre 2026». */
function cs_periodo_txt(?string $p): string
{
    if (!$p) {
        return '';
    }
    return CS_MESES[(int) substr($p, 5, 2)] . ' ' . substr($p, 0, 4);
}

/** «2026-11» o «11/2026» -> «2026-11-01»; null si no se entiende. */
function cs_periodo_leer(string $s): ?string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/', $s, $m) || preg_match('/^(\d{1,2})[\/-](\d{4})$/', $s, $n)) {
        [$a, $mes] = isset($m[1]) ? [(int) $m[1], (int) $m[2]] : [(int) $n[2], (int) $n[1]];
        if ($mes >= 1 && $mes <= 12 && $a >= 2000 && $a <= 2100) {
            return sprintf('%04d-%02d-01', $a, $mes);
        }
    }
    return null;
}

function cs_importe_campo($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
}

// ── Valores por tipo de asociado ──────────────────────────────────────

function cs_valores(int $idTipo): array
{
    return q('SELECT v.*, u.usuario AS creado_por_usuario FROM cuota_social_valores v
                LEFT JOIN usuarios u ON u.id_usuario = v.creado_por
               WHERE v.id_tipo_asociado = ? ORDER BY v.vigencia_desde DESC', [$idTipo]);
}

/** El valor que rige para un tipo en un período (null si no tiene). */
function cs_valor_vigente(int $idTipo, string $periodo): ?array
{
    return q1('SELECT * FROM cuota_social_valores WHERE id_tipo_asociado = ? AND vigencia_desde <= ?
                ORDER BY vigencia_desde DESC LIMIT 1', [$idTipo, $periodo]);
}

/** «Inicial $ 13.800 × 12 meses, después $ 14.120 + adicional $ 14.180». */
function cs_valor_txt(?array $v): string
{
    if (!$v) {
        return 'sin valor';
    }
    $m = function ($x) { return '$ ' . number_format((float) $x, 2, ',', '.'); };
    $base = $m($v['valor_mensual']) . ((float) $v['valor_adicional'] > 0 ? ' + adicional ' . $m($v['valor_adicional']) : '');
    return (int) $v['meses_inicial'] > 0 && (float) $v['valor_inicial'] > 0
        ? 'Inicial ' . $m($v['valor_inicial']) . ' × ' . (int) $v['meses_inicial'] . ' meses, después ' . $base
        : $base;
}

/** POST de la solapa (cs_valor_guardar | cs_valor_borrar | cs_copiar). null si no es de acá. */
function cs_valores_acciones(int $idTipo, ?array &$form): ?array
{
    $accion = post_str('accion');
    if (!in_array($accion, ['cs_valor_guardar', 'cs_valor_borrar', 'cs_copiar', 'cs_paga'], true)) {
        return null;
    }
    $volver = 'tipos_asociado.php?id=' . $idTipo . '&solapa=cuota';
    if ($accion === 'cs_paga') {
        $si = post_str('paga') === '1' ? 1 : 0;
        q('UPDATE tipos_asociado SET paga_cuota_social = ? WHERE id_tipo_asociado = ?', [$si, $idTipo]);
        flash('ok', $si ? 'Este tipo paga cuota social.' : 'Este tipo deja de pagar cuota social: no se generan más cuotas para sus asociados (lo ya generado queda).');
        redirigir($volver);
    }
    if ($accion === 'cs_valor_borrar') {
        $v = q1('SELECT * FROM cuota_social_valores WHERE id_valor = ? AND id_tipo_asociado = ?', [(int) post_str('id_valor'), $idTipo]);
        if ($v && q1('SELECT 1 AS x FROM cuotas_sociales WHERE id_tipo_asociado = ? AND periodo >= ? LIMIT 1', [$idTipo, $v['vigencia_desde']])) {
            return ['Ese valor ya se usó para generar cuotas: no se borra. Para cambiarlo, cargá uno nuevo desde el período siguiente.'];
        }
        q('DELETE FROM cuota_social_valores WHERE id_valor = ? AND id_tipo_asociado = ?', [(int) post_str('id_valor'), $idTipo]);
        flash('ok', 'Valor eliminado.');
        redirigir($volver);
    }
    if ($accion === 'cs_copiar') {
        $origen = (int) post_str('origen');
        $n = 0;
        foreach (q('SELECT * FROM cuota_social_valores WHERE id_tipo_asociado = ?', [$origen]) as $v) {
            q('INSERT IGNORE INTO cuota_social_valores (id_tipo_asociado, vigencia_desde, valor_inicial, meses_inicial, valor_mensual, valor_adicional, creado_por)
               VALUES (?,?,?,?,?,?,?)', [$idTipo, $v['vigencia_desde'], $v['valor_inicial'], $v['meses_inicial'], $v['valor_mensual'], $v['valor_adicional'], usuario_id()]);
            $n += (int) db()->query('SELECT ROW_COUNT()')->fetchColumn();
        }
        flash($n ? 'ok' : 'warning', $n ? 'Se copiaron ' . $n . ' valor' . ($n === 1 ? '' : 'es') . '.' : 'No había valores para copiar (o ya estaban esas vigencias).');
        redirigir($volver);
    }

    $form = ['vigencia' => post_str('vigencia'), 'valor_inicial' => post_str('valor_inicial'), 'meses_inicial' => post_str('meses_inicial'),
             'valor_mensual' => post_str('valor_mensual'), 'valor_adicional' => post_str('valor_adicional')];
    $err = [];
    $vig = cs_periodo_leer($form['vigencia']);
    $vi = $form['valor_inicial'] === '' ? null : leer_num($form['valor_inicial']);
    $mi = $form['meses_inicial'] === '' ? 0 : leer_num($form['meses_inicial']);
    $vm = leer_num($form['valor_mensual']);
    $va = $form['valor_adicional'] === '' ? null : leer_num($form['valor_adicional']);
    if (!$vig) {
        $err[] = 'Elegí desde qué mes rige.';
    }
    if ($vm === null || $vm < 0) {
        $err[] = 'El valor mensual es obligatorio (0 si en ese período no se cobra).';
    }
    if ($form['valor_inicial'] !== '' && ($vi === null || $vi < 0)) {
        $err[] = 'El valor inicial no es un importe válido.';
    }
    if ($mi === null || $mi < 0 || $mi > 120 || floor($mi) != $mi) {
        $err[] = 'Los meses con valor inicial van de 0 a 120.';
    }
    if ($mi > 0 && !$vi) {
        $err[] = 'Con meses iniciales, cargá el valor inicial.';
    }
    if ($form['valor_adicional'] !== '' && ($va === null || $va < 0)) {
        $err[] = 'El valor adicional no es un importe válido.';
    }
    if (!$err && $vig && q1('SELECT 1 AS x FROM cuotas_sociales WHERE id_tipo_asociado = ? AND periodo >= ? LIMIT 1', [$idTipo, $vig])) {
        $err[] = 'Desde ' . cs_periodo_txt($vig) . ' ya hay cuotas generadas para este tipo: el valor nuevo tiene que regir desde un mes sin generar.';
    }
    if ($err) {
        return $err;
    }
    q('INSERT INTO cuota_social_valores (id_tipo_asociado, vigencia_desde, valor_inicial, meses_inicial, valor_mensual, valor_adicional, creado_por)
       VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE valor_inicial = VALUES(valor_inicial), meses_inicial = VALUES(meses_inicial),
                               valor_mensual = VALUES(valor_mensual), valor_adicional = VALUES(valor_adicional), creado_por = VALUES(creado_por)',
      [$idTipo, $vig, $vi ? round($vi, 2) : null, (int) $mi, round($vm, 2), $va ? round($va, 2) : null, usuario_id()]);
    flash('ok', 'Valor de cuota social desde ' . cs_periodo_txt($vig) . ' guardado.');
    redirigir($volver);
    return [];
}

/** La solapa «Cuota social» de un tipo de asociado. */
function cs_valores_tarjeta(int $idTipo, array $tipo, ?array $form, array $errores): string
{
    if (!cs_instalado()) {
        return '<div class="card">' . card_head('Cuota social') . '<div class="bloque"><div class="alert alert-warning"><div>Falta correr sql\financiera_cuota_social.sql.</div></div></div></div>';
    }
    $valores = cs_valores($idTipo);
    $base = 'tipos_asociado.php?id=' . $idTipo . '&solapa=cuota';
    $v = $form ?? ['vigencia' => date('Y-m', strtotime('first day of next month')), 'valor_inicial' => '', 'meses_inicial' => '0',
                   'valor_mensual' => '', 'valor_adicional' => ''];
    if ($form === null && $valores) {      // propone el último, para cambiar solo lo que sube
        $u = $valores[0];
        $v = ['vigencia' => $v['vigencia'], 'valor_inicial' => cs_importe_campo($u['valor_inicial']), 'meses_inicial' => (string) $u['meses_inicial'],
              'valor_mensual' => cs_importe_campo($u['valor_mensual']), 'valor_adicional' => cs_importe_campo($u['valor_adicional'])];
    }
    $otros = array_column(q('SELECT DISTINCT t.id_tipo_asociado, t.nombre FROM cuota_social_valores v JOIN tipos_asociado t ON t.id_tipo_asociado = v.id_tipo_asociado
                               WHERE t.id_tipo_asociado <> ? ORDER BY t.nombre', [$idTipo]), 'nombre', 'id_tipo_asociado');

    $paga = (int) ($tipo['paga_cuota_social'] ?? 0);
    $out = '<div class="card">' . card_head('Cuota social', $paga
            ? 'vigente hoy: ' . cs_valor_txt(cs_valor_vigente($idTipo, date('Y-m-01')))
            : 'este tipo NO paga cuota social: estos valores no se usan',
            '<form method="post" action="' . h($base) . '" class="form-en-linea" data-confirmar="'
            . ($paga ? 'Sus asociados dejan de generar cuota social desde el próximo período.' : 'Sus asociados empiezan a generar cuota social.')
            . '" data-confirmar-titulo="Cuota social" data-confirmar-boton="' . ($paga ? 'Deja de pagar' : 'Paga cuota social') . '">'
            . csrf_campo() . '<input type="hidden" name="accion" value="cs_paga"><input type="hidden" name="paga" value="' . ($paga ? '0' : '1') . '">'
            . boton($paga ? 'Deja de pagar cuota social' : 'Paga cuota social', $paga ? 'secondary' : 'ok', null, $paga ? null : 'ok', true, true)
            . '</form>');
    foreach ($errores as $e) {
        $out .= '<div class="bloque"><div class="alert alert-danger"><div>' . h($e) . '</div></div></div>';
    }
    $out .= '<form method="post" action="' . h($base) . '" class="bloque">' . csrf_campo()
          . '<input type="hidden" name="accion" value="cs_valor_guardar">'
          . '<div class="bloque-titulo">Nuevo valor</div><div class="campos">'
          . '<div class="campo" style="--span:2"><label for="cs_vig">Rige desde <span class="req">*</span></label>'
          . '<input type="month" id="cs_vig" name="vigencia" value="' . h($v['vigencia']) . '" required></div>'
          . '<div class="campo" style="--span:2"><label for="cs_vi">Valor inicial</label>'
          . '<input id="cs_vi" name="valor_inicial" value="' . h($v['valor_inicial']) . '" inputmode="decimal" class="derecha" placeholder="sin inicial"></div>'
          . '<div class="campo" style="--span:2"><label for="cs_mi">Meses inicial</label>'
          . '<input id="cs_mi" name="meses_inicial" value="' . h($v['meses_inicial']) . '" inputmode="numeric" class="derecha"></div>'
          . '<div class="campo" style="--span:2"><label for="cs_vm">Valor mensual <span class="req">*</span></label>'
          . '<input id="cs_vm" name="valor_mensual" value="' . h($v['valor_mensual']) . '" inputmode="decimal" class="derecha" required></div>'
          . '<div class="campo" style="--span:2"><label for="cs_va">Adicional</label>'
          . '<input id="cs_va" name="valor_adicional" value="' . h($v['valor_adicional']) . '" inputmode="decimal" class="derecha" placeholder="sin adicional"></div>'
          . '<div class="campo campo-boton" style="--span:2">' . boton('Guardar', 'primary', null, 'guardar', true) . '</div>'
          . '</div><span class="campo-ayuda">Los primeros «meses inicial» de cada asociado se cobra el valor inicial; después, el mensual y, si hay, el adicional (dos cargos en el mes). '
          . 'Para un aumento, cargá un valor nuevo desde el mes que corresponda: lo ya generado no cambia.</span></form>';
    if ($otros) {
        $out .= '<form method="post" action="' . h($base) . '" class="bloque">' . csrf_campo() . '<input type="hidden" name="accion" value="cs_copiar">'
              . '<div class="campos"><div class="campo" style="--span:6"><label for="cs_origen">Copiar los valores de otro tipo</label>'
              . '<select id="cs_origen" name="origen">' . opciones($otros, '', 'Elegí el tipo…') . '</select></div>'
              . '<div class="campo campo-boton" style="--span:2">' . boton('Copiar', 'ghost', null, 'descargar', true) . '</div></div></form>';
    }
    $out .= '<div class="data-table-wrap"><table class="data-table"><thead><tr><th class="col-acciones">Acciones</th><th>Rige desde</th>'
          . '<th class="th-right">Inicial</th><th class="th-right">Meses</th><th class="th-right">Mensual</th><th class="th-right">Adicional</th><th>Cargado</th></tr></thead><tbody>';
    if (!$valores) {
        $out .= '<tr><td colspan="7" class="no-data">Sin valores: este tipo no genera cuota social.</td></tr>';
    }
    foreach ($valores as $x) {
        $out .= '<tr><td><div class="acciones">'
              . accion_post($base, ['accion' => 'cs_valor_borrar', 'id_valor' => (int) $x['id_valor']], 'borrar', 'Eliminar',
                            'Se elimina el valor desde ' . cs_periodo_txt($x['vigencia_desde']) . '.', 'borrar')
              . '</div></td><td class="td-fuerte">' . h(cs_periodo_txt($x['vigencia_desde'])) . '</td>'
              . '<td class="td-right">' . ((float) $x['valor_inicial'] > 0 ? money($x['valor_inicial']) : '<span class="td-muted">—</span>') . '</td>'
              . '<td class="td-right">' . ((int) $x['meses_inicial'] ?: '<span class="td-muted">—</span>') . '</td>'
              . '<td class="td-right td-fuerte">' . money($x['valor_mensual']) . '</td>'
              . '<td class="td-right">' . ((float) $x['valor_adicional'] > 0 ? money($x['valor_adicional']) : '<span class="td-muted">—</span>') . '</td>'
              . '<td class="td-muted">' . h(fecha(substr((string) $x['creado_el'], 0, 10))) . ($x['creado_por_usuario'] ? ' · ' . h($x['creado_por_usuario']) : '') . '</td></tr>';
    }
    return $out . '</tbody></table></div></div>';
}

// ── Generación del período ─────────────────────────────────────────────

/**
 * Genera (o simula) las cuotas de un período. Antes da de alta como
 * asociados a los clientes con préstamo que todavía no lo eran (desde el
 * mes de su primer préstamo). Devuelve conteos e importes por concepto,
 * los asociados sin valor para su tipo y los que ya estaban generados.
 */
function cs_generar(string $periodo, bool $simular): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$simular) {
            q("INSERT IGNORE INTO cuota_social_asociados (id_cliente, desde, origen, creado_por)
               SELECT p.id_cliente, DATE_FORMAT(MIN(p.fecha_liquidacion), '%Y-%m-01'), 'PRESTAMO', ?
                 FROM prestamos p WHERE p.estado <> 'ANULADO' GROUP BY p.id_cliente", [usuario_id()]);
        }
        q('DROP TEMPORARY TABLE IF EXISTS tmp_cs_gen');
        // Los asociados del período: los registrados y, al simular, los que se darían de alta.
        q("CREATE TEMPORARY TABLE tmp_cs_gen AS
           SELECT a.id_cliente, c.id_tipo_asociado, a.desde,
                  TIMESTAMPDIFF(MONTH, a.desde, ?) + 1 AS mes_n,
                  (SELECT v.id_valor FROM cuota_social_valores v WHERE v.id_tipo_asociado = c.id_tipo_asociado AND v.vigencia_desde <= ?
                    ORDER BY v.vigencia_desde DESC LIMIT 1) AS id_valor
             FROM (SELECT id_cliente, desde, baja_desde FROM cuota_social_asociados
                   UNION ALL
                   SELECT p.id_cliente, DATE_FORMAT(MIN(p.fecha_liquidacion), '%Y-%m-01'), NULL FROM prestamos p
                    WHERE p.estado <> 'ANULADO' AND ? = 1
                      AND NOT EXISTS (SELECT 1 FROM cuota_social_asociados x WHERE x.id_cliente = p.id_cliente)
                    GROUP BY p.id_cliente) a
             JOIN clientes c ON c.id_cliente = a.id_cliente AND c.estado <> 'INACTIVO'
             JOIN tipos_asociado t ON t.id_tipo_asociado = c.id_tipo_asociado AND t.paga_cuota_social = 1
            WHERE a.desde <= ? AND (a.baja_desde IS NULL OR a.baja_desde > ?)",
          [$periodo, $periodo, $simular ? 1 : 0, $periodo, $periodo]);

        // Lo que corresponde a cada uno: INICIAL, o MENSUAL (+ ADICIONAL).
        $sel = "SELECT g.id_cliente, g.id_tipo_asociado, x.concepto,
                       CASE x.concepto WHEN 'INICIAL' THEN v.valor_inicial WHEN 'MENSUAL' THEN v.valor_mensual ELSE v.valor_adicional END AS importe
                  FROM tmp_cs_gen g
                  JOIN cuota_social_valores v ON v.id_valor = g.id_valor
                  JOIN (SELECT 'INICIAL' AS concepto UNION ALL SELECT 'MENSUAL' UNION ALL SELECT 'ADICIONAL') x
                 WHERE (x.concepto = 'INICIAL'   AND g.mes_n <= v.meses_inicial AND v.valor_inicial > 0)
                    OR (x.concepto = 'MENSUAL'   AND g.mes_n >  v.meses_inicial AND v.valor_mensual > 0)
                    OR (x.concepto = 'ADICIONAL' AND g.mes_n >  v.meses_inicial AND v.valor_adicional > 0)";
        $res = q("SELECT s.concepto, COUNT(*) AS n, SUM(s.importe) AS importe,
                         SUM(EXISTS (SELECT 1 FROM cuotas_sociales y WHERE y.id_cliente = s.id_cliente AND y.periodo = ? AND y.concepto = s.concepto)) AS ya
                    FROM ($sel) s GROUP BY s.concepto", [$periodo]);
        $sinValor = (int) q1('SELECT COUNT(*) AS n FROM tmp_cs_gen WHERE id_valor IS NULL')['n'];
        $asociados = (int) q1('SELECT COUNT(*) AS n FROM tmp_cs_gen')['n'];
        $nuevas = 0;
        if (!$simular) {
            q("INSERT IGNORE INTO cuotas_sociales (id_cliente, periodo, concepto, id_tipo_asociado, importe, generado_por)
               SELECT s.id_cliente, ?, s.concepto, s.id_tipo_asociado, s.importe, ? FROM ($sel) s", [$periodo, usuario_id()]);
            $nuevas = (int) $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        }
        q('DROP TEMPORARY TABLE IF EXISTS tmp_cs_gen');
        $simular ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['conceptos' => $res, 'sin_valor' => $sinValor, 'asociados' => $asociados, 'nuevas' => $nuevas];
}

// ── Pagos ──────────────────────────────────────────────────────────────

/** Recalcula pagado y estado de una cuota a partir de sus pagos vigentes. */
function cs_recalcular(int $idCuota): void
{
    q("UPDATE cuotas_sociales c
          SET c.pagado = (SELECT COALESCE(SUM(p.importe), 0) FROM cuotas_sociales_pagos p WHERE p.id_cuota_social = c.id_cuota_social AND p.anulado_el IS NULL),
              c.estado = IF(c.estado = 'ANULADA', 'ANULADA',
                         CASE WHEN c.pagado >= c.importe THEN 'PAGADA' WHEN c.pagado > 0 THEN 'PARCIAL' ELSE 'PENDIENTE' END)
        WHERE c.id_cuota_social = ?", [$idCuota]);
}

/**
 * Aplica un importe cobrado a las cuotas pendientes del cliente, de la más
 * vieja a la más nueva (o solo a $soloCuota). Devuelve [lo aplicado, el
 * sobrante, ids de los pagos]. La transacción la maneja quien llama.
 */
function cs_imputar(int $idCliente, float $importe, string $fecha, string $medio = 'DEBITO', ?string $referencia = null,
                    ?int $idLote = null, ?int $soloCuota = null): array
{
    $resto = round($importe, 2);
    $ids = [];
    $cuotas = q("SELECT id_cuota_social, importe - pagado AS debe FROM cuotas_sociales
                  WHERE id_cliente = ? AND estado IN ('PENDIENTE','PARCIAL')" . ($soloCuota ? ' AND id_cuota_social = ' . (int) $soloCuota : '') . "
                  ORDER BY periodo, FIELD(concepto, 'INICIAL', 'MENSUAL', 'ADICIONAL', 'MANUAL'), id_cuota_social", [$idCliente]);
    foreach ($cuotas as $c) {
        if ($resto <= 0) {
            break;
        }
        $aplica = min($resto, round((float) $c['debe'], 2));
        if ($aplica <= 0) {
            continue;
        }
        q('INSERT INTO cuotas_sociales_pagos (id_cuota_social, fecha, importe, motivo, medio, referencia, id_lote, creado_por)
           VALUES (?,?,?,?,?,?,?,?)', [(int) $c['id_cuota_social'], $fecha, $aplica, 'PAGO', $medio, $referencia, $idLote, usuario_id()]);
        $ids[] = (int) db()->lastInsertId();
        cs_recalcular((int) $c['id_cuota_social']);
        $resto = round($resto - $aplica, 2);
    }
    return [round($importe - $resto, 2), $resto, $ids];
}

// ── Asociado: alta, baja, anulaciones (ficha del cliente) ─────────────

function cs_asociado(int $idCliente): ?array
{
    return q1('SELECT a.*, u.usuario AS baja_por_usuario FROM cuota_social_asociados a
                 LEFT JOIN usuarios u ON u.id_usuario = a.baja_por WHERE a.id_cliente = ?', [$idCliente]);
}

/** POST de la tarjeta del cliente (cs_alta | cs_baja | cs_reactivar | cs_anular | cs_pago_anular). null si no es de acá. */
function cs_cliente_acciones(int $idCliente, string $volver): ?array
{
    $accion = post_str('accion');
    if (!in_array($accion, ['cs_alta', 'cs_baja', 'cs_reactivar', 'cs_anular', 'cs_pago_anular'], true)) {
        return null;
    }
    $motivo = trim(post_str('motivo'));
    switch ($accion) {
        case 'cs_alta':
            $desde = cs_periodo_leer(post_str('desde'));
            if (!$desde) {
                return ['Elegí desde qué mes es asociado.'];
            }
            q("INSERT INTO cuota_social_asociados (id_cliente, desde, origen, creado_por) VALUES (?,?, 'MANUAL', ?)
               ON DUPLICATE KEY UPDATE desde = VALUES(desde)", [$idCliente, $desde, usuario_id()]);
            flash('ok', 'Asociado desde ' . cs_periodo_txt($desde) . '.');
            break;
        case 'cs_baja':
            $desde = cs_periodo_leer(post_str('desde'));
            if (!$desde || $motivo === '') {
                return ['Para la baja de cuota social hacen falta el mes desde el que no paga y el motivo.'];
            }
            q('UPDATE cuota_social_asociados SET baja_desde = ?, baja_motivo = ?, baja_por = ?, baja_el = NOW() WHERE id_cliente = ?',
              [$desde, mb_substr($motivo, 0, 200), usuario_id(), $idCliente]);
            $pend = (int) q1("SELECT COUNT(*) AS n FROM cuotas_sociales WHERE id_cliente = ? AND periodo >= ? AND estado IN ('PENDIENTE','PARCIAL')", [$idCliente, $desde])['n'];
            flash($pend ? 'warning' : 'ok', 'Baja de cuota social desde ' . cs_periodo_txt($desde) . '.'
                  . ($pend ? ' Ojo: quedan ' . $pend . ' cuota' . ($pend === 1 ? '' : 's') . ' ya generada' . ($pend === 1 ? '' : 's') . ' desde ese mes; si no corresponden, anulalas.' : ''));
            break;
        case 'cs_reactivar':
            q('UPDATE cuota_social_asociados SET baja_desde = NULL, baja_motivo = NULL, baja_por = NULL, baja_el = NULL WHERE id_cliente = ?', [$idCliente]);
            flash('ok', 'El cliente vuelve a pagar cuota social.');
            break;
        case 'cs_anular':
            if ($motivo === '') {
                return ['Para anular una cuota escribí el motivo.'];
            }
            $id = (int) post_str('id_cuota_social');
            if (q1("SELECT 1 AS x FROM cuotas_sociales_pagos WHERE id_cuota_social = ? AND anulado_el IS NULL", [$id])) {
                return ['La cuota tiene pagos: primero anulá los pagos.'];
            }
            q("UPDATE cuotas_sociales SET estado = 'ANULADA', anulada_el = NOW(), anulada_por = ?, motivo_anulacion = ?
                WHERE id_cuota_social = ? AND id_cliente = ? AND estado <> 'ANULADA'", [usuario_id(), mb_substr($motivo, 0, 200), $id, $idCliente]);
            flash('ok', 'Cuota anulada.');
            break;
        case 'cs_pago_anular':
            if ($motivo === '') {
                return ['Para anular un pago escribí el motivo.'];
            }
            $p = q1('SELECT p.* FROM cuotas_sociales_pagos p JOIN cuotas_sociales c ON c.id_cuota_social = p.id_cuota_social
                      WHERE p.id_pago_cs = ? AND c.id_cliente = ? AND p.anulado_el IS NULL', [(int) post_str('id_pago_cs'), $idCliente]);
            if ($p && !empty($p['id_recibo'])) {
                return ['Ese pago es parte de un recibo: se anula anulando el recibo.'];
            }
            if ($p) {
                q('UPDATE cuotas_sociales_pagos SET anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ? WHERE id_pago_cs = ?',
                  [usuario_id(), mb_substr($motivo, 0, 200), (int) $p['id_pago_cs']]);
                cs_recalcular((int) $p['id_cuota_social']);
                flash('ok', 'Pago anulado.');
            }
            break;
    }
    redirigir($volver);
    return [];
}

/** La tarjeta «Cuota social» de la ficha del cliente. */
function cs_tarjeta_cliente(int $idCliente, array $cliente, array $errores = []): string
{
    if (!cs_instalado()) {
        return '';
    }
    $base = 'cliente.php?id=' . $idCliente;
    $a = cs_asociado($idCliente);
    $paga = (int) (q1('SELECT paga_cuota_social FROM tipos_asociado WHERE id_tipo_asociado = ?', [(int) $cliente['id_tipo_asociado']])['paga_cuota_social'] ?? 0);
    $cuotas = q('SELECT * FROM cuotas_sociales WHERE id_cliente = ? ORDER BY periodo DESC, FIELD(concepto, \'INICIAL\', \'MENSUAL\', \'ADICIONAL\', \'MANUAL\')', [$idCliente]);
    $pagos = [];
    $conRecibo = columna_existe('cuotas_sociales_pagos', 'id_recibo');
    foreach (q('SELECT p.*, u.usuario' . ($conRecibo ? ', r.nro_recibo' : '') . ' FROM cuotas_sociales_pagos p JOIN cuotas_sociales c ON c.id_cuota_social = p.id_cuota_social
                 LEFT JOIN usuarios u ON u.id_usuario = p.creado_por' . ($conRecibo ? ' LEFT JOIN recibos r ON r.id_recibo = p.id_recibo' : '') . '
                WHERE c.id_cliente = ? ORDER BY p.fecha, p.id_pago_cs', [$idCliente]) as $p) {
        $pagos[(int) $p['id_cuota_social']][] = $p;
    }
    $deuda = 0.0;
    foreach ($cuotas as $c) {
        if (in_array($c['estado'], ['PENDIENTE', 'PARCIAL'], true)) {
            $deuda += (float) $c['importe'] - (float) $c['pagado'];
        }
    }
    $mesProx = date('Y-m', strtotime('first day of next month'));
    if (!$a) {
        $nota = $paga ? 'no es asociado todavía: lo pasa a ser con su primer préstamo' : 'su tipo de asociado no paga cuota social';
    } elseif ($a['baja_desde']) {
        $nota = 'asociado desde ' . cs_periodo_txt($a['desde']) . ' · BAJA desde ' . cs_periodo_txt($a['baja_desde']);
    } else {
        $nota = 'asociado desde ' . cs_periodo_txt($a['desde']) . ($paga ? '' : ' · su tipo de asociado no paga cuota social');
    }
    $nota .= $cuotas ? ' · debe ' . money($deuda) : '';

    $out = '<div class="card" id="cuota-social">' . card_head('Cuota social', $nota);
    foreach ($errores as $e) {
        $out .= '<div class="bloque"><div class="alert alert-danger"><div>' . h($e) . '</div></div></div>';
    }
    // Alta / baja
    $out .= '<div class="bloque">';
    if (!$a) {
        $out .= '<form method="post" action="' . h($base) . '#cuota-social" class="campos">' . csrf_campo() . '<input type="hidden" name="accion" value="cs_alta">'
              . '<div class="campo" style="--span:3"><label for="cs_desde">Asociado desde</label><input type="month" id="cs_desde" name="desde" value="' . date('Y-m') . '" required></div>'
              . '<div class="campo campo-boton" style="--span:3">' . boton('Dar de alta', 'ghost', null, 'agregar', true) . '</div></form>';
    } elseif (!$a['baja_desde']) {
        $out .= '<form method="post" action="' . h($base) . '#cuota-social" class="campos" data-confirmar="El cliente deja de generar cuota social desde el mes elegido." data-confirmar-titulo="Baja de cuota social" data-confirmar-boton="Dar de baja">'
              . csrf_campo() . '<input type="hidden" name="accion" value="cs_baja">'
              . '<div class="campo" style="--span:3"><label for="cs_bdesde">Baja desde</label><input type="month" id="cs_bdesde" name="desde" value="' . $mesProx . '" required></div>'
              . '<div class="campo" style="--span:6"><label for="cs_bmot">Motivo</label><input id="cs_bmot" name="motivo" maxlength="200" required placeholder="Lo pidió el asociado, canceló todo…"></div>'
              . '<div class="campo campo-boton" style="--span:3">' . boton('Baja de cuota social', 'danger', null, 'cerrar', true) . '</div></form>';
    } else {
        $out .= '<p class="campo-ayuda" style="margin:0 0 8px">Baja: ' . h((string) $a['baja_motivo']) . ($a['baja_por_usuario'] ? ' · ' . h($a['baja_por_usuario']) : '')
              . ($a['baja_el'] ? ' · ' . h(fecha(substr((string) $a['baja_el'], 0, 10))) : '') . '</p>'
              . accion_post($base . '#cuota-social', ['accion' => 'cs_reactivar'], 'ok', 'Reactivar cuota social', 'El cliente vuelve a generar cuota social desde el próximo período que se genere.');
    }
    $out .= '</div>';

    $out .= '<div class="data-table-wrap"><table class="data-table" data-local data-paginada data-unidad="cuota|cuotas"><thead><tr><th class="col-acciones" data-no-sort>Acciones</th>'
          . '<th>Período</th><th>Concepto</th><th class="th-right">Importe</th><th class="th-right">Pagado</th><th class="th-right">Debe</th><th>Estado</th><th>Pagos</th></tr></thead><tbody>';
    if (!$cuotas) {
        $out .= '<tr><td colspan="8" class="no-data">Sin cuotas generadas.</td></tr>';
    }
    $colEst = ['PENDIENTE' => 'warn', 'PARCIAL' => 'info', 'PAGADA' => 'ok', 'ANULADA' => 'neutro'];
    foreach ($cuotas as $c) {
        $cid = (int) $c['id_cuota_social'];
        $debe = in_array($c['estado'], ['PENDIENTE', 'PARCIAL'], true) ? (float) $c['importe'] - (float) $c['pagado'] : 0;
        $pp = '';
        foreach ($pagos[$cid] ?? [] as $p) {
            $anul = $p['anulado_el'] !== null;
            $pp .= '<div' . ($anul ? ' class="td-muted" style="text-decoration:line-through"' : '') . '>' . h(fecha($p['fecha'])) . ' · ' . money($p['importe'])
                 . ' · ' . h(strtolower((string) $p['medio'])) . ($p['usuario'] ? ' · ' . h($p['usuario']) : '')
                 . (!empty($p['id_recibo']) ? ' · <a href="recibo.php?r=' . (int) $p['id_recibo'] . '">' . h((string) ($p['nro_recibo'] ?? 'recibo')) . '</a>' : '')
                 . (!$anul && empty($p['id_recibo']) ? ' ' . accion_post_motivo($base . '#cuota-social', ['accion' => 'cs_pago_anular', 'id_pago_cs' => (int) $p['id_pago_cs']], 'Anular pago') : '')
                 . '</div>';
        }
        $out .= '<tr class="' . ($c['estado'] === 'ANULADA' ? 'fila-baja' : '') . '"><td><div class="acciones">'
              . ($c['estado'] !== 'ANULADA' && !($pagos[$cid] ?? []) ? accion_post_motivo($base . '#cuota-social', ['accion' => 'cs_anular', 'id_cuota_social' => $cid], 'Anular cuota') : '')
              . '</div></td>'
              . '<td data-orden="' . h($c['periodo']) . '">' . h(cs_periodo_txt($c['periodo'])) . '</td>'
              . '<td>' . h(CS_CONCEPTOS[$c['concepto']] ?? $c['concepto']) . '</td>'
              . '<td class="td-right">' . money($c['importe']) . '</td>'
              . '<td class="td-right">' . money($c['pagado']) . '</td>'
              . '<td class="td-right td-fuerte">' . ($debe > 0 ? money($debe) : '<span class="td-muted">—</span>') . '</td>'
              . '<td>' . estado(CS_ESTADOS[$c['estado']], $colEst[$c['estado']]) . ($c['motivo_anulacion'] ? '<span class="segunda-linea">' . h($c['motivo_anulacion']) . '</span>' : '') . '</td>'
              . '<td class="td-wrap">' . $pp . '</td></tr>';
    }
    return $out . '</tbody></table></div>' . paginacion_local() . '</div>';
}

/** Un botón de acción que pide el motivo antes de mandar el POST. */
function accion_post_motivo(string $url, array $campos, string $titulo): string
{
    $ocultos = '';
    foreach ($campos as $k => $v) {
        $ocultos .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return '<form method="post" action="' . h($url) . '" class="form-en-linea" onsubmit="var m = prompt(\'' . h($titulo) . ': ¿por qué?\'); if (!m) return false; this.motivo.value = m;">'
         . csrf_campo() . $ocultos . '<input type="hidden" name="motivo" value="">'
         . '<button class="btn-accion borrar" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono('cerrar') . '</button></form>';
}
