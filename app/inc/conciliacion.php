<?php
declare(strict_types=1);
// ---------------------------------------------------------------------
// Conciliación bancaria (sql/financiera_conciliacion.sql). El circuito de
// gestion_adm (tesoreria_routes.py: conc_*), con el lado libro de la
// financiera: los débitos y créditos bancarios de cada movimiento de
// fondos.
//
//   EXTRACTO  extractos_bancarios        lo que informa el banco
//   LIBRO     debitos_creditos_bancarios lo que registró el sistema
//
// Un renglón del extracto queda resuelto cuando está en un grupo
// (conciliado contra uno o varios del libro, por el mismo importe) o
// marcado como excepción. El período se cierra con todo resuelto y, si el
// banco informa saldo, con el saldo calculado igual al del banco.
//
// SIGNO: en los dos lados un crédito (entra) es positivo y un débito
// (sale) negativo. Un grupo se arma solo si las dos sumas son iguales.
//
// CLASIFICACIÓN: las reglas (conc_reglas) reconocen el texto del extracto
// («COMISION MANT», «IMP LEY 25413») y le dan un concepto de tesorería o
// una cuenta propia de destino. Con eso la conciliación emite el
// comprobante que falta en el libro (gasto o ingreso bancario, o
// movimiento entre cuentas) y lo deja conciliado.
//
// SUGERENCIAS (en este orden; cada renglón del libro, una sola vez):
//   1. el recibo que se emitió desde ese renglón del extracto
//   2. mismo importe y misma referencia
//   3. mismo importe, a 7 días o menos (el más cercano)
// ---------------------------------------------------------------------

require_once __DIR__ . '/fondos.php';

const CONC_ESTADOS = ['abierto' => 'Abierta', 'en_proceso' => 'En proceso', 'cerrado' => 'Cerrada'];
const CONC_ESTADOS_TIPO = ['abierto' => 'info', 'en_proceso' => 'warn', 'cerrado' => 'ok'];
const CONC_PATRONES = ['contiene' => 'Contiene', 'empieza_con' => 'Empieza con', 'exacto' => 'Es igual a', 'regex' => 'Expresión regular'];
const CONC_MOVIMIENTOS = ['ambos' => 'Débitos y créditos', 'debito' => 'Solo débitos', 'credito' => 'Solo créditos'];
const CONC_RESULTADOS = ['concepto' => 'Concepto de tesorería', 'movimiento_cuentas' => 'Transferencia a otra cuenta propia', 'manual' => 'Se concilia a mano'];
const CONC_DIAS_SUGERENCIA = 7;

function conc_instalado(): bool
{
    static $ok = null;
    return $ok ??= (bool) q1("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conciliacion_libro'");
}

function conc_falta_script(Throwable $e): string
{
    return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de la conciliación: corré sql\financiera_conciliacion.sql.' : error_amigable($e);
}

function conc_estado(string $e): string
{
    return estado(CONC_ESTADOS[$e] ?? $e, CONC_ESTADOS_TIPO[$e] ?? 'neutro');
}

/** «Noviembre 2026» de un AAAAMM. */
function conc_periodo_txt(string $p): string
{
    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return ($meses[(int) substr($p, 4, 2)] ?? '?') . ' ' . substr($p, 0, 4);
}

/** El concepto del extracto sin lo que agrega la importación (« — CUIL …»), en mayúsculas. */
function conc_concepto_base(?string $c): string
{
    $c = (string) $c;
    $i = mb_strpos($c, ' — ');
    return mb_strtoupper(trim($i === false ? $c : mb_substr($c, 0, $i)));
}

// ---------------------------------------------------------------------
// Reglas
// ---------------------------------------------------------------------

function conc_reglas(bool $soloActivas = true): array
{
    return q('SELECT r.*, c.nombre AS concepto, c.tipo AS concepto_tipo, d.nombre AS destino
                FROM conc_reglas r
                LEFT JOIN conceptos_tesoreria c ON c.id_concepto_tes = r.id_concepto_tes
                LEFT JOIN cuentas_fondos d ON d.id_cuenta_fondos = r.id_cuenta_destino'
             . ($soloActivas ? ' WHERE r.activa = 1' : '') . ' ORDER BY r.orden, r.id_regla');
}

/** ¿La regla reconoce ese renglón del extracto? */
function conc_regla_coincide(array $r, ?string $concepto, bool $debito): bool
{
    if (($r['tipo_movimiento'] === 'debito' && !$debito) || ($r['tipo_movimiento'] === 'credito' && $debito)) {
        return false;
    }
    $base = conc_concepto_base($concepto);
    $p = mb_strtoupper(trim((string) $r['patron_texto']));
    switch ($r['tipo_patron']) {
        case 'contiene':
            return $p !== '' && mb_strpos($base, $p) !== false;
        case 'empieza_con':
            return $p !== '' && mb_strpos($base, $p) === 0;
        case 'exacto':
            return $base === $p;
        case 'regex':
            return @preg_match('/' . str_replace('/', '\/', (string) $r['patron_texto']) . '/iu', (string) $concepto) === 1;
    }
    return false;
}

/** La primera regla (por prioridad) que reconoce el renglón, o null. */
function conc_regla_de(array $reglas, ?string $concepto, bool $debito): ?array
{
    foreach ($reglas as $r) {
        if (conc_regla_coincide($r, $concepto, $debito)) {
            return $r;
        }
    }
    return null;
}

/** El texto de lo que hace una regla: «Comisiones y gastos bancarios», «→ Nación cuenta corriente»… */
function conc_regla_txt(array $r): string
{
    if ($r['tipo_resultado'] === 'movimiento_cuentas') {
        return 'Transferencia ' . ($r['destino'] ? '· ' . $r['destino'] : 'sin cuenta destino');
    }
    if ($r['tipo_resultado'] === 'manual') {
        return 'A mano';
    }
    return (string) ($r['concepto'] ?? 'sin concepto');
}

/** Un patrón regex válido (para avisar antes de guardarlo). */
function conc_regex_valida(string $p): bool
{
    return @preg_match('/' . str_replace('/', '\/', $p) . '/iu', '') !== false;
}

// ---------------------------------------------------------------------
// La conciliación
// ---------------------------------------------------------------------

function conc_cargar(int $id): ?array
{
    return q1('SELECT k.*, c.nombre AS cuenta, c.moneda, c.tipo AS tipo_cuenta
                 FROM conciliaciones k JOIN cuentas_fondos c ON c.id_cuenta_fondos = k.id_cuenta_fondos
                WHERE k.id_conciliacion = ?', [$id]);
}

/** La última conciliación cerrada de la cuenta: de ahí sale el saldo inicial de la que sigue. */
function conc_anterior_cerrada(int $idCuenta, ?string $antesDe = null): ?array
{
    return q1("SELECT id_conciliacion, periodo, saldo_final FROM conciliaciones
                WHERE id_cuenta_fondos = ? AND estado = 'cerrado'" . ($antesDe ? ' AND periodo < ?' : '') . '
                ORDER BY periodo DESC LIMIT 1', $antesDe ? [$idCuenta, $antesDe] : [$idCuenta]);
}

/** El saldo que informa el banco en el último renglón del lapso (null si no lo informa). */
function conc_saldo_banco(int $idCuenta, string $desde, string $hasta): ?float
{
    $r = q1('SELECT saldo FROM extractos_bancarios WHERE id_cuenta_fondos = ? AND fecha BETWEEN ? AND ? AND saldo IS NOT NULL
              ORDER BY fecha DESC, id_extracto DESC LIMIT 1', [$idCuenta, $desde, $hasta]);
    return $r ? (float) $r['saldo'] : null;
}

/** Saldo inicial propuesto para la primera conciliación: el del banco al cierre del día anterior. */
function conc_saldo_sugerido(int $idCuenta, string $desde): ?float
{
    $r = q1('SELECT saldo FROM extractos_bancarios WHERE id_cuenta_fondos = ? AND fecha < ? AND saldo IS NOT NULL
              ORDER BY fecha DESC, id_extracto DESC LIMIT 1', [$idCuenta, $desde]);
    if ($r) {
        return (float) $r['saldo'];
    }
    // El primer renglón del período que trae saldo, menos lo que se movió
    // hasta él (incluido: el saldo ya lo cuenta).
    $r = q1('SELECT id_extracto, fecha, saldo FROM extractos_bancarios
              WHERE id_cuenta_fondos = ? AND fecha >= ? AND saldo IS NOT NULL ORDER BY fecha, id_extracto LIMIT 1', [$idCuenta, $desde]);
    if (!$r) {
        return null;
    }
    $antes = q1('SELECT COALESCE(SUM(COALESCE(credito, 0) - COALESCE(debito, 0)), 0) AS n FROM extractos_bancarios
                  WHERE id_cuenta_fondos = ? AND fecha >= ? AND (fecha < ? OR (fecha = ? AND id_extracto <= ?))',
                [$idCuenta, $desde, $r['fecha'], $r['fecha'], (int) $r['id_extracto']]);
    return round((float) $r['saldo'] - (float) $antes['n'], 2);
}

/** Alta: cuenta banco, período AAAAMM. El saldo inicial lo da la anterior cerrada; sin ella, $saldoManual. */
function conc_nueva(int $idCuenta, string $periodo, ?float $saldoManual): int
{
    $c = q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$idCuenta]);
    if (!$c || $c['tipo'] !== 'B') {
        throw new RuntimeException('Se concilian las cuentas banco: elegí una.');
    }
    if (!preg_match('/^(\d{4})(\d{2})$/', $periodo, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
        throw new RuntimeException('El período no es válido.');
    }
    if (q1('SELECT 1 AS x FROM conciliaciones WHERE id_cuenta_fondos = ? AND periodo = ?', [$idCuenta, $periodo])) {
        throw new RuntimeException('Ya hay una conciliación de ' . $c['nombre'] . ' para ' . conc_periodo_txt($periodo) . '.');
    }
    if ($pos = q1('SELECT periodo FROM conciliaciones WHERE id_cuenta_fondos = ? AND periodo > ? ORDER BY periodo LIMIT 1', [$idCuenta, $periodo])) {
        throw new RuntimeException('Ya está ' . conc_periodo_txt($pos['periodo']) . ': los períodos van en orden.');
    }
    if ($ab = q1("SELECT periodo FROM conciliaciones WHERE id_cuenta_fondos = ? AND estado <> 'cerrado' LIMIT 1", [$idCuenta])) {
        throw new RuntimeException('Primero cerrá ' . conc_periodo_txt($ab['periodo']) . ' de ' . $c['nombre'] . '.');
    }
    $desde = $m[1] . '-' . $m[2] . '-01';
    $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
    $ant = conc_anterior_cerrada($idCuenta);
    if ($ant) {
        $saldo = (float) $ant['saldo_final'];
    } elseif ($saldoManual === null) {
        throw new RuntimeException('Es la primera conciliación de la cuenta: falta el saldo inicial (el del banco al ' . fecha((new DateTimeImmutable($desde))->modify('-1 day')->format('Y-m-d')) . ').');
    } else {
        $saldo = $saldoManual;
    }
    q('INSERT INTO conciliaciones (id_cuenta_fondos, periodo, fecha_desde, fecha_hasta, saldo_inicial, saldo_inicial_manual,
                                   id_conc_anterior, saldo_banco, creado_por)
       VALUES (?,?,?,?,?,?,?,?,?)',
      [$idCuenta, $periodo, $desde, $hasta, round($saldo, 2), $ant ? 0 : 1, $ant['id_conciliacion'] ?? null,
       conc_saldo_banco($idCuenta, $desde, $hasta), usuario_id()]);
    return (int) db()->lastInsertId();
}

/** Solo se elimina una conciliación vacía (sin vínculos ni excepciones). */
function conc_eliminar(array $k): void
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    if (q1("SELECT 1 AS x FROM conciliacion_items WHERE id_conciliacion = ? AND estado <> 'pendiente' LIMIT 1", [(int) $k['id_conciliacion']])) {
        throw new RuntimeException('Tiene renglones conciliados o excepciones: desvinculalos primero.');
    }
    q('DELETE FROM conciliaciones WHERE id_conciliacion = ?', [(int) $k['id_conciliacion']]);
}

/**
 * Clasificación automática: los renglones del período que todavía no
 * están en la conciliación y que alguna regla reconoce quedan como
 * pendientes con su regla. Los que ya tenían regla la actualizan (si la
 * regla cambió). Devuelve cuántos tocó.
 */
function conc_clasificar_auto(array $k): int
{
    if ($k['estado'] === 'cerrado') {
        return 0;
    }
    $reglas = conc_reglas();
    $id = (int) $k['id_conciliacion'];
    $n = 0;
    foreach (q("SELECT e.id_extracto, e.concepto, e.debito, i.id_item, i.estado, i.id_regla, i.origen
                  FROM extractos_bancarios e
                  LEFT JOIN conciliacion_items i ON i.id_extracto = e.id_extracto AND i.id_conciliacion = ?
                 WHERE e.id_cuenta_fondos = ? AND e.fecha BETWEEN ? AND ?
                   AND (i.id_item IS NULL OR (i.estado = 'pendiente' AND i.origen = 'automatico'))",
               [$id, (int) $k['id_cuenta_fondos'], $k['fecha_desde'], $k['fecha_hasta']]) as $e) {
        $r = conc_regla_de($reglas, $e['concepto'], $e['debito'] !== null && (float) $e['debito'] > 0);
        if (!$e['id_item'] && $r) {
            q("INSERT INTO conciliacion_items (id_conciliacion, id_extracto, id_regla, estado, origen, creado_por)
               VALUES (?,?,?,'pendiente','automatico',?)", [$id, (int) $e['id_extracto'], (int) $r['id_regla'], usuario_id()]);
            $n++;
        } elseif ($e['id_item'] && !$r) {
            q('DELETE FROM conciliacion_items WHERE id_item = ?', [(int) $e['id_item']]);      // se quitó la regla
            $n++;
        } elseif ($e['id_item'] && (int) $e['id_regla'] !== (int) $r['id_regla']) {
            q('UPDATE conciliacion_items SET id_regla = ? WHERE id_item = ?', [(int) $r['id_regla'], (int) $e['id_item']]);
            $n++;
        }
    }
    return $n;
}

/**
 * El extracto del período con su situación: estado (sin item:
 * 'sin_clasificar'), grupo, regla y su texto. Importe con signo en 'imp'.
 */
function conc_extracto(array $k): array
{
    $reglas = [];
    foreach (conc_reglas(false) as $r) {
        $reglas[(int) $r['id_regla']] = $r;
    }
    $out = [];
    foreach (q('SELECT e.*, i.id_item, i.estado, i.id_grupo, i.id_regla, i.observaciones AS obs_item, i.origen AS origen_item
                  FROM extractos_bancarios e
                  LEFT JOIN conciliacion_items i ON i.id_extracto = e.id_extracto AND i.id_conciliacion = ?
                 WHERE e.id_cuenta_fondos = ? AND e.fecha BETWEEN ? AND ?
                 ORDER BY e.fecha, e.id_extracto', [(int) $k['id_conciliacion'], (int) $k['id_cuenta_fondos'], $k['fecha_desde'], $k['fecha_hasta']]) as $e) {
        $e['imp'] = round((float) $e['credito'] - (float) $e['debito'], 2);
        $e['estado'] = $e['estado'] ?? 'sin_clasificar';
        $e['regla'] = $e['id_regla'] ? ($reglas[(int) $e['id_regla']] ?? null) : null;
        $out[(int) $e['id_extracto']] = $e;
    }
    return $out;
}

/**
 * El libro: los débitos y créditos bancarios de la cuenta hasta el fin del
 * período que no están conciliados en otra conciliación (lo de meses
 * anteriores que el banco todavía no informó también aparece), más los
 * que están en grupos de esta. 'imp' con signo, 'id_grupo' si está
 * conciliado acá.
 */
function conc_libro(array $k): array
{
    $out = [];
    foreach (q("SELECT d.*, m.fecha, m.concepto, m.tipo_origen, m.id_origen, m.id_cuenta_fondos,
                       l.id_grupo, g.id_conciliacion AS conc_grupo
                  FROM debitos_creditos_bancarios d
                  JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                  LEFT JOIN conciliacion_libro l ON l.id_deb_cred = d.id_deb_cred
                  LEFT JOIN conciliacion_grupos g ON g.id_grupo = l.id_grupo
                 WHERE m.id_cuenta_fondos = ? AND m.fecha <= ?
                   AND ((l.id IS NULL AND d.estado <> 'ANULADO' AND m.anulado_el IS NULL) OR g.id_conciliacion = ?)
                 ORDER BY m.fecha, d.id_deb_cred", [(int) $k['id_cuenta_fondos'], $k['fecha_hasta'], (int) $k['id_conciliacion']]) as $d) {
        $d['imp'] = round((float) $d['importe_credito'] - (float) $d['importe_debito'], 2);
        $out[(int) $d['id_deb_cred']] = $d;
    }
    return $out;
}

/**
 * Vincula renglones del extracto con renglones del libro (N a N). Las dos
 * sumas con signo tienen que ser iguales. Los del libro quedan
 * «en el banco» con la fecha del extracto. Devuelve el id del grupo.
 */
function conc_vincular(array $k, array $idsExt, array $idsLib, string $origen = 'manual', string $obs = ''): int
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    $idsExt = array_values(array_unique(array_map('intval', $idsExt)));
    $idsLib = array_values(array_unique(array_map('intval', $idsLib)));
    if (!$idsExt || !$idsLib) {
        throw new RuntimeException('Elegí al menos un renglón del extracto y uno del libro.');
    }
    $id = (int) $k['id_conciliacion'];
    $mk = fn (array $a) => implode(',', array_fill(0, count($a), '?'));
    $ext = q('SELECT e.id_extracto, e.fecha, COALESCE(e.credito, 0) - COALESCE(e.debito, 0) AS imp, i.estado
                FROM extractos_bancarios e
                LEFT JOIN conciliacion_items i ON i.id_extracto = e.id_extracto AND i.id_conciliacion = ?
               WHERE e.id_extracto IN (' . $mk($idsExt) . ') AND e.id_cuenta_fondos = ? AND e.fecha BETWEEN ? AND ? FOR UPDATE',
             array_merge([$id], $idsExt, [(int) $k['id_cuenta_fondos'], $k['fecha_desde'], $k['fecha_hasta']]));
    if (count($ext) !== count($idsExt)) {
        throw new RuntimeException('Algún renglón del extracto no es de esta cuenta y período.');
    }
    foreach ($ext as $e) {
        if (in_array($e['estado'], ['conciliado', 'excepcion'], true)) {
            throw new RuntimeException('Algún renglón del extracto ya está conciliado o es una excepción.');
        }
    }
    $lib = q("SELECT d.id_deb_cred, d.importe_credito - d.importe_debito AS imp, d.estado, m.anulado_el,
                     (SELECT 1 FROM conciliacion_libro l WHERE l.id_deb_cred = d.id_deb_cred) AS tomado
                FROM debitos_creditos_bancarios d JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
               WHERE d.id_deb_cred IN (" . $mk($idsLib) . ') AND m.id_cuenta_fondos = ? AND m.fecha <= ? FOR UPDATE',
             array_merge($idsLib, [(int) $k['id_cuenta_fondos'], $k['fecha_hasta']]));
    if (count($lib) !== count($idsLib)) {
        throw new RuntimeException('Algún renglón del libro no es de esta cuenta o es posterior al período.');
    }
    foreach ($lib as $l) {
        if ($l['tomado'] || $l['estado'] === 'ANULADO' || $l['anulado_el']) {
            throw new RuntimeException('Algún renglón del libro ya está conciliado o se anuló.');
        }
    }
    $sExt = round(array_sum(array_map(fn ($e) => (float) $e['imp'], $ext)), 2);
    $sLib = round(array_sum(array_map(fn ($l) => (float) $l['imp'], $lib)), 2);
    if (abs($sExt - $sLib) >= 0.005) {
        throw new RuntimeException('No cierran los importes: el extracto suma ' . money($sExt) . ' y el libro ' . money($sLib) . '.');
    }
    q('INSERT INTO conciliacion_grupos (id_conciliacion, importe_extracto, importe_libro, origen, observaciones, creado_por)
       VALUES (?,?,?,?,?,?)', [$id, $sExt, $sLib, $origen, $obs !== '' ? mb_substr($obs, 0, 300) : null, usuario_id()]);
    $g = (int) db()->lastInsertId();
    $fBanco = max(array_column($ext, 'fecha'));
    foreach ($ext as $e) {
        q("INSERT INTO conciliacion_items (id_conciliacion, id_extracto, id_grupo, estado, origen, creado_por)
           VALUES (?,?,?,'conciliado',?,?)
           ON DUPLICATE KEY UPDATE id_grupo = VALUES(id_grupo), estado = 'conciliado'",
          [$id, (int) $e['id_extracto'], $g, $origen === 'manual' ? 'manual' : 'automatico', usuario_id()]);
    }
    foreach ($lib as $l) {
        q('INSERT INTO conciliacion_libro (id_grupo, id_deb_cred, importe) VALUES (?,?,?)', [$g, (int) $l['id_deb_cred'], (float) $l['imp']]);
        q("UPDATE debitos_creditos_bancarios SET estado = 'ACREDITADO', fecha_banco = ?, actualizado_por = ? WHERE id_deb_cred = ?",
          [$fBanco, usuario_id(), (int) $l['id_deb_cred']]);
    }
    q("UPDATE conciliaciones SET estado = 'en_proceso' WHERE id_conciliacion = ? AND estado = 'abierto'", [$id]);
    return $g;
}

/**
 * Deshace un grupo. Los renglones del libro vuelven a pendientes. Si el
 * comprobante lo emitió la conciliación (gasto o ingreso bancario, o
 * transferencia), se anula: el renglón del extracto vuelve a quedar para
 * emitirlo otra vez.
 */
function conc_desvincular(array $k, int $idGrupo, string $motivo = 'Se desvinculó en la conciliación'): void
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    $g = q1('SELECT * FROM conciliacion_grupos WHERE id_grupo = ? AND id_conciliacion = ?', [$idGrupo, (int) $k['id_conciliacion']]);
    if (!$g) {
        throw new RuntimeException('El vínculo ya no existe.');
    }
    $lib = q('SELECT l.id_deb_cred, m.tipo_origen, m.id_origen FROM conciliacion_libro l
                JOIN debitos_creditos_bancarios d ON d.id_deb_cred = l.id_deb_cred
                JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
               WHERE l.id_grupo = ?', [$idGrupo]);
    foreach ($lib as $l) {
        q("UPDATE debitos_creditos_bancarios SET estado = 'EMITIDO', fecha_banco = NULL, actualizado_por = ? WHERE id_deb_cred = ?",
          [usuario_id(), (int) $l['id_deb_cred']]);
    }
    q('DELETE FROM conciliacion_libro WHERE id_grupo = ?', [$idGrupo]);
    // Los renglones del extracto: vuelven a pendientes si tienen regla; si no, salen de la conciliación.
    q("UPDATE conciliacion_items SET estado = 'pendiente', id_grupo = NULL WHERE id_grupo = ? AND id_regla IS NOT NULL", [$idGrupo]);
    q('DELETE FROM conciliacion_items WHERE id_grupo = ?', [$idGrupo]);
    q('DELETE FROM conciliacion_grupos WHERE id_grupo = ?', [$idGrupo]);
    if ($g['origen'] === 'generado') {
        foreach ($lib as $l) {
            conc_anular_generado($l['tipo_origen'], (int) $l['id_origen'], $motivo);
        }
    }
}

/** Anula el comprobante que emitió la conciliación (sin chequear el banco: ya se desvinculó). */
function conc_anular_generado(string $tipoOrigen, int $id, string $motivo): void
{
    if ($tipoOrigen === 'BANCARIO') {
        q("UPDATE comprobantes_bancarios SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ?
            WHERE id_comp_banco = ? AND estado = 'VIGENTE'", [usuario_id(), mb_substr($motivo, 0, 200), $id]);
        fondos_call('CALL sp_fondos_bancario(?)', [$id]);
    } elseif ($tipoOrigen === 'MOVIMIENTO') {
        // La otra cuenta (si es banco) también vuelve a pendiente para poder anular.
        q("UPDATE debitos_creditos_bancarios d JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
              LEFT JOIN conciliacion_libro l ON l.id_deb_cred = d.id_deb_cred
              SET d.estado = 'EMITIDO', d.fecha_banco = NULL
            WHERE m.tipo_origen = 'MOVIMIENTO' AND m.id_origen = ? AND l.id IS NULL AND d.estado = 'ACREDITADO'", [$id]);
        if (q1("SELECT 1 AS x FROM debitos_creditos_bancarios d JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                  JOIN conciliacion_libro l ON l.id_deb_cred = d.id_deb_cred
                 WHERE m.tipo_origen = 'MOVIMIENTO' AND m.id_origen = ?", [$id])) {
            throw new RuntimeException('La transferencia también está conciliada en la otra cuenta: desvinculala allá primero.');
        }
        q("UPDATE movimientos_entre_cuentas SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ?
            WHERE id_mov_cuentas = ? AND estado = 'VIGENTE'", [usuario_id(), mb_substr($motivo, 0, 200), $id]);
        fondos_call('CALL sp_fondos_movimiento(?)', [$id]);
    }
}

/** Marca un renglón del extracto como excepción (no se puede identificar todavía, error del banco…). */
function conc_excepcion(array $k, int $idExt, string $obs): void
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    if ($obs === '') {
        throw new RuntimeException('Falta el motivo de la excepción.');
    }
    $e = q1('SELECT e.id_extracto, i.estado FROM extractos_bancarios e
               LEFT JOIN conciliacion_items i ON i.id_extracto = e.id_extracto AND i.id_conciliacion = ?
              WHERE e.id_extracto = ? AND e.id_cuenta_fondos = ? AND e.fecha BETWEEN ? AND ?',
            [(int) $k['id_conciliacion'], $idExt, (int) $k['id_cuenta_fondos'], $k['fecha_desde'], $k['fecha_hasta']]);
    if (!$e) {
        throw new RuntimeException('El renglón no es de esta conciliación.');
    }
    if ($e['estado'] === 'conciliado') {
        throw new RuntimeException('El renglón está conciliado: desvinculalo primero.');
    }
    q("INSERT INTO conciliacion_items (id_conciliacion, id_extracto, estado, origen, observaciones, creado_por)
       VALUES (?,?,'excepcion','manual',?,?)
       ON DUPLICATE KEY UPDATE estado = 'excepcion', origen = 'manual', observaciones = VALUES(observaciones)",
      [(int) $k['id_conciliacion'], $idExt, mb_substr($obs, 0, 300), usuario_id()]);
    q("UPDATE conciliaciones SET estado = 'en_proceso' WHERE id_conciliacion = ? AND estado = 'abierto'", [(int) $k['id_conciliacion']]);
}

function conc_quitar_excepcion(array $k, int $idExt): void
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    q("DELETE FROM conciliacion_items WHERE id_conciliacion = ? AND id_extracto = ? AND estado = 'excepcion'", [(int) $k['id_conciliacion'], $idExt]);
}

// ---------------------------------------------------------------------
// Sugerencias
// ---------------------------------------------------------------------

/**
 * Pares extracto ↔ libro que se pueden conciliar solos, uno a uno.
 * [['ext' => id, 'lib' => id, 'motivo' => texto]]
 */
function conc_sugerencias(array $k, array $ext, array $lib): array
{
    $libres = array_filter($lib, fn ($d) => !$d['id_grupo']);
    $pend = array_filter($ext, fn ($e) => in_array($e['estado'], ['sin_clasificar', 'pendiente'], true));
    $tomados = [];
    $out = [];
    $anotar = function (int $e, int $l, string $m) use (&$out, &$tomados, &$pend) {
        $out[] = ['ext' => $e, 'lib' => $l, 'motivo' => $m];
        $tomados[$l] = true;
        unset($pend[$e]);
    };
    // 1. El recibo emitido desde ese renglón del extracto.
    if ($pend && columna_existe('recibos', 'id_extracto')) {
        $ids = array_keys($pend);
        foreach (q("SELECT r.id_extracto, d.id_deb_cred FROM recibos r
                      JOIN movimientos_fondos m ON m.tipo_origen = 'RECIBO' AND m.id_origen = r.id_recibo
                      JOIN debitos_creditos_bancarios d ON d.id_mov_fondos = m.id_mov_fondos
                     WHERE r.id_extracto IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids) as $r) {
            $e = (int) $r['id_extracto'];
            $l = (int) $r['id_deb_cred'];
            if (isset($pend[$e], $libres[$l]) && !isset($tomados[$l]) && abs($pend[$e]['imp'] - $libres[$l]['imp']) < 0.005) {
                $anotar($e, $l, 'recibo emitido desde el extracto');
            }
        }
    }
    // 2. Mismo importe y misma referencia.
    foreach ($pend as $e) {
        $ref = trim((string) $e['referencia']);
        if ($ref === '') {
            continue;
        }
        foreach ($libres as $l) {
            if (!isset($tomados[(int) $l['id_deb_cred']]) && abs($e['imp'] - $l['imp']) < 0.005
                && trim((string) $l['nro_referencia']) !== '' && ltrim($ref, '0') === ltrim(trim((string) $l['nro_referencia']), '0')) {
                $anotar((int) $e['id_extracto'], (int) $l['id_deb_cred'], 'mismo importe y referencia');
                break;
            }
        }
    }
    // 3. Mismo importe, el más cercano en fecha (hasta 7 días). Sin regla de
    //    comprobante: lo que se va a emitir no se busca en el libro.
    foreach ($pend as $e) {
        if ($e['regla'] && (int) $e['regla']['genera_comprobante'] && $e['regla']['tipo_resultado'] !== 'manual') {
            continue;
        }
        $mejor = null;
        $dist = PHP_INT_MAX;
        foreach ($libres as $l) {
            if (isset($tomados[(int) $l['id_deb_cred']]) || abs($e['imp'] - $l['imp']) >= 0.005) {
                continue;
            }
            $d = abs((strtotime($e['fecha']) - strtotime($l['fecha'])) / 86400);
            if ($d <= CONC_DIAS_SUGERENCIA && $d < $dist) {
                $mejor = $l;
                $dist = $d;
            }
        }
        if ($mejor) {
            $anotar((int) $e['id_extracto'], (int) $mejor['id_deb_cred'], 'mismo importe' . ($dist ? ', ' . (int) $dist . ($dist == 1 ? ' día' : ' días') . ' de diferencia' : ', mismo día'));
        }
    }
    return $out;
}

// ---------------------------------------------------------------------
// Emisión de comprobantes
// ---------------------------------------------------------------------

/**
 * Lo que la conciliación puede emitir: los renglones pendientes del
 * extracto con una regla que genera comprobante, agrupados por concepto
 * (o cuenta destino), sentido y fecha (si la regla agrupa). Cada grupo:
 * clave, tipo ('concepto'|'movimiento_cuentas'), comprobante (GASTO,
 * INGRESO o TRANSFERENCIA), nombre, fecha, renglones, total (positivo),
 * aviso (si no se puede emitir).
 */
function conc_a_emitir(array $ext): array
{
    $grupos = [];
    foreach ($ext as $e) {
        $r = $e['regla'];
        if ($e['estado'] !== 'pendiente' || !$r || !(int) $r['genera_comprobante'] || !(int) $r['activa'] || $r['tipo_resultado'] === 'manual') {
            continue;
        }
        $debito = $e['imp'] < 0;
        $esMov = $r['tipo_resultado'] === 'movimiento_cuentas';
        $clave = ($esMov ? 'M' . (int) $r['id_cuenta_destino'] : 'C' . (int) $r['id_concepto_tes']) . ($debito ? 'D' : 'C')
               . ((int) $r['agrupa_por_fecha'] ? $e['fecha'] : 'E' . $e['id_extracto']);
        if (!isset($grupos[$clave])) {
            $aviso = '';
            if ($esMov && !$r['id_cuenta_destino']) {
                $aviso = 'La regla no tiene cuenta destino.';
            } elseif (!$esMov && !$r['id_concepto_tes']) {
                $aviso = 'La regla no tiene concepto.';
            }
            $grupos[$clave] = ['clave' => $clave, 'tipo' => $r['tipo_resultado'],
                               'comprobante' => $esMov ? 'TRANSFERENCIA' : ($debito ? 'GASTO' : 'INGRESO'),
                               'nombre' => $esMov ? ($debito ? '→ ' : '← ') . ($r['destino'] ?? '?') : (string) $r['concepto'],
                               'id_concepto_tes' => $r['id_concepto_tes'] ? (int) $r['id_concepto_tes'] : null,
                               'id_cuenta_destino' => $r['id_cuenta_destino'] ? (int) $r['id_cuenta_destino'] : null,
                               'fecha' => $e['fecha'], 'renglones' => [], 'total' => 0.0, 'debito' => $debito, 'aviso' => $aviso];
        }
        $grupos[$clave]['renglones'][] = $e;
        $grupos[$clave]['total'] = round($grupos[$clave]['total'] + abs($e['imp']), 2);
        $grupos[$clave]['fecha'] = max($grupos[$clave]['fecha'], $e['fecha']);
    }
    uasort($grupos, fn ($a, $b) => [$a['fecha'], $a['nombre']] <=> [$b['fecha'], $b['nombre']]);
    return $grupos;
}

/**
 * Emite los comprobantes de los grupos elegidos ($claves vacío: todos) y
 * los concilia. Devuelve [gastos, ingresos, transferencias].
 */
function conc_emitir(array $k, array $claves = []): array
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación está cerrada.');
    }
    $cant = ['GASTO' => 0, 'INGRESO' => 0, 'TRANSFERENCIA' => 0];
    $periodo = conc_periodo_txt($k['periodo']);
    foreach (conc_a_emitir(conc_extracto($k)) as $g) {
        if (($claves && !in_array($g['clave'], $claves, true)) || $g['aviso'] !== '') {
            continue;
        }
        $idsExt = array_map(fn ($e) => (int) $e['id_extracto'], $g['renglones']);
        if ($g['tipo'] === 'concepto') {
            $anio = substr($g['fecha'], 0, 4);
            $st = db()->prepare('CALL sp_numerador(?, @cb_n)');
            $st->execute(['CB-' . $anio]);
            $st->closeCursor();
            $n = (int) q1('SELECT @cb_n AS n')['n'];
            q('INSERT INTO comprobantes_bancarios (nro, tipo, id_cuenta_fondos, fecha, importe, concepto, origen, id_conciliacion, creado_por)
               VALUES (?,?,?,?,?,?,?,?,?)',
              [$anio . '-' . str_pad((string) $n, 8, '0', STR_PAD_LEFT), $g['comprobante'], (int) $k['id_cuenta_fondos'], $g['fecha'], $g['total'],
               mb_substr($g['nombre'] . ' · conciliación ' . $periodo, 0, 200), 'CONCILIACION', (int) $k['id_conciliacion'], usuario_id()]);
            $idComp = (int) db()->lastInsertId();
            foreach ($g['renglones'] as $e) {
                q('INSERT INTO comprobantes_bancarios_det (id_comp_banco, id_concepto_tes, descripcion, importe, id_extracto) VALUES (?,?,?,?,?)',
                  [$idComp, $g['id_concepto_tes'], mb_substr((string) $e['concepto'], 0, 200), abs($e['imp']), (int) $e['id_extracto']]);
            }
            fondos_call('CALL sp_fondos_bancario(?)', [$idComp]);
            $origen = ['BANCARIO', $idComp];
        } else {
            $otra = q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$g['id_cuenta_destino']]);
            $esta = q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [(int) $k['id_cuenta_fondos']]);
            [$sale, $entra] = $g['debito'] ? [$esta, $otra] : [$otra, $esta];
            q('INSERT INTO movimientos_entre_cuentas (fecha, tipo, id_cuenta_origen, id_cuenta_destino, importe, referencia, comentario, creado_por)
               VALUES (?,?,?,?,?,?,?,?)',
              [$g['fecha'], tipo_mov_cuentas($sale['tipo'], $entra['tipo']), (int) $sale['id_cuenta_fondos'], (int) $entra['id_cuenta_fondos'],
               $g['total'], mb_substr((string) ($g['renglones'][0]['referencia'] ?? ''), 0, 100) ?: null,
               mb_substr('Conciliación ' . $periodo . ' · ' . conc_concepto_base($g['renglones'][0]['concepto']), 0, 250), usuario_id()]);
            $idMov = (int) db()->lastInsertId();
            fondos_call('CALL sp_fondos_movimiento(?)', [$idMov]);
            $origen = ['MOVIMIENTO', $idMov];
        }
        $dc = q1('SELECT d.id_deb_cred FROM debitos_creditos_bancarios d JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                   WHERE m.tipo_origen = ? AND m.id_origen = ? AND m.id_cuenta_fondos = ?', [$origen[0], $origen[1], (int) $k['id_cuenta_fondos']]);
        if (!$dc) {
            throw new RuntimeException('El comprobante se emitió pero no dejó su renglón en el libro de ' . $k['cuenta'] . '.');
        }
        conc_vincular($k, $idsExt, [(int) $dc['id_deb_cred']], 'generado', $g['nombre']);
        $cant[$g['comprobante']]++;
    }
    return $cant;
}

// ---------------------------------------------------------------------
// Totales, cierre y reapertura
// ---------------------------------------------------------------------

/** Totales del extracto del período y cuánto falta. */
function conc_resumen(array $k, array $ext): array
{
    $r = ['creditos' => 0.0, 'debitos' => 0.0, 'total' => count($ext), 'conciliados' => 0, 'excepciones' => 0, 'pendientes' => 0, 'clasificados' => 0];
    foreach ($ext as $e) {
        $r['creditos'] += (float) $e['credito'];
        $r['debitos'] += (float) $e['debito'];
        if ($e['estado'] === 'conciliado') {
            $r['conciliados']++;
        } elseif ($e['estado'] === 'excepcion') {
            $r['excepciones']++;
        } else {
            $r['pendientes']++;
            if ($e['estado'] === 'pendiente') {
                $r['clasificados']++;
            }
        }
    }
    $r['creditos'] = round($r['creditos'], 2);
    $r['debitos'] = round($r['debitos'], 2);
    $r['saldo_final'] = round((float) $k['saldo_inicial'] + $r['creditos'] - $r['debitos'], 2);
    $r['saldo_banco'] = $k['estado'] === 'cerrado' ? ($k['saldo_banco'] !== null ? (float) $k['saldo_banco'] : null)
                                                  : conc_saldo_banco((int) $k['id_cuenta_fondos'], $k['fecha_desde'], $k['fecha_hasta']);
    $r['diferencia'] = $r['saldo_banco'] === null ? null : round($r['saldo_final'] - $r['saldo_banco'], 2);
    return $r;
}

function conc_cerrar(array $k): float
{
    if ($k['estado'] === 'cerrado') {
        throw new RuntimeException('La conciliación ya está cerrada.');
    }
    $ext = conc_extracto($k);
    $r = conc_resumen($k, $ext);
    if ($r['pendientes']) {
        throw new RuntimeException('Quedan ' . num($r['pendientes']) . ($r['pendientes'] === 1 ? ' renglón' : ' renglones')
            . ' del extracto sin conciliar: vinculalos, emití su comprobante o marcalos como excepción.');
    }
    if ($r['diferencia'] !== null && abs($r['diferencia']) >= 0.005) {
        throw new RuntimeException('El saldo calculado (' . money($r['saldo_final']) . ') no es el que informa el banco ('
            . money($r['saldo_banco']) . '): falta algún renglón del extracto o el saldo inicial no es el correcto.');
    }
    $id = (int) $k['id_conciliacion'];
    q('UPDATE extractos_bancarios e JOIN conciliacion_items i ON i.id_extracto = e.id_extracto AND i.id_conciliacion = ?
          SET e.conciliado = 1, e.id_conciliacion = ?', [$id, $id]);
    q("UPDATE conciliaciones SET estado = 'cerrado', total_creditos = ?, total_debitos = ?, saldo_final = ?, saldo_banco = ?,
                                 fecha_cierre = NOW(), cerrado_por = ? WHERE id_conciliacion = ?",
      [$r['creditos'], $r['debitos'], $r['saldo_final'], $r['saldo_banco'], usuario_id(), $id]);
    return $r['saldo_final'];
}

/** Se reabre solo la última de la cuenta (la que sigue tomó su saldo final). */
function conc_reabrir(array $k): void
{
    if ($k['estado'] !== 'cerrado') {
        throw new RuntimeException('La conciliación no está cerrada.');
    }
    if ($sig = q1('SELECT periodo FROM conciliaciones WHERE id_cuenta_fondos = ? AND periodo > ? ORDER BY periodo LIMIT 1',
                  [(int) $k['id_cuenta_fondos'], $k['periodo']])) {
        throw new RuntimeException('Ya está ' . conc_periodo_txt($sig['periodo']) . ', que arrancó con este saldo: eliminala o reabrila primero.');
    }
    $id = (int) $k['id_conciliacion'];
    q('UPDATE extractos_bancarios SET conciliado = 0, id_conciliacion = NULL WHERE id_conciliacion = ?', [$id]);
    q("UPDATE conciliaciones SET estado = 'en_proceso', fecha_cierre = NULL, cerrado_por = NULL WHERE id_conciliacion = ?", [$id]);
}
