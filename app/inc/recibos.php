<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Recibos de cobranza y su emisión desde el extracto bancario por CUIL.
// El modelo de la emisión de recibos de planes de gestion_adm
// (liquidacion_cobros.py), con las tablas de sql\financiera_recibos.sql:
//
//   1. Los créditos del extracto de una cuenta bancaria en el período, con
//      lo que les queda sin recibo.
//   2. A quién son: el CUIL que trae el crédito (columna cuit, o el que
//      aparece en el concepto o la referencia), salvo que se le haya
//      asignado un cliente a mano (extractos_cliente) o que ese CUIL tenga
//      una regla (cobros_cuil_reglas: el cónyuge, la empresa que paga por
//      el cliente). El CUIT de la financiera no cuenta: es un depósito en
//      ventanilla, y el banco pone el de la cuenta, no el de quien paga.
//   3. Lo que debe el cliente a la fecha del crédito, sumando todos sus
//      préstamos vigentes: lo vencido, lo que vence en el período y lo
//      que viene después.
//   4. El recibo (sp_emitir_recibo): uno por crédito, aplicado a las
//      cuotas de todos los préstamos, de la que vence primero a la última.
//      Lo que supera la deuda queda disponible en el crédito.
// ---------------------------------------------------------------------

require_once __DIR__ . '/cobranzas.php';
require_once __DIR__ . '/fondos.php';
require_once __DIR__ . '/empresa.php';

/** ¿Ya se corrió sql\financiera_recibos.sql? */
function recibos_instalado(): bool
{
    return columna_existe('pagos', 'id_recibo');
}

/** El CUIT de la financiera, si está cargado en Datos de la empresa. */
function rec_cuit_propio(): ?string
{
    $c = cuit_limpio((string) (empresa()['cuit'] ?? ''));
    return strlen($c) === 11 ? $c : null;
}

/** El CUIL que trae un crédito del extracto: la columna cuit, o el primero válido del concepto o la referencia. Nunca el propio. */
function rec_cuil_leido(array $e, ?string $propio): ?string
{
    foreach ([(string) ($e['cuit'] ?? ''), (string) ($e['concepto'] ?? ''), (string) ($e['referencia'] ?? '')] as $t) {
        if (preg_match_all('/(?<!\d)(?:20|23|24|27|30|33|34)\d{9}(?!\d)/', $t, $m)) {
            foreach ($m[0] as $c) {
                if ($c !== $propio && cuit_valido($c)) {
                    return $c;
                }
            }
        }
    }
    return null;
}

/** Minúsculas y sin acentos, para buscar por nombre. */
function rec_normalizar(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** IN (?,?,?) para una lista. */
function rec_marcas(array $l): string
{
    return implode(',', array_fill(0, count($l), '?'));
}

/** Lo que queda sin recibo de un crédito (alias e). */
const REC_DISPONIBLE = "e.credito - COALESCE((SELECT SUM(r.importe) FROM recibos r
                                                WHERE r.id_extracto = e.id_extracto AND r.estado = 'APLICADO'), 0)";

/** Las cuentas bancarias en pesos, con cuántos créditos tienen algo sin recibo. */
function rec_cuentas(): array
{
    return q("SELECT c.id_cuenta_fondos, c.nombre, c.habilitada,
                     (SELECT COUNT(*) FROM extractos_bancarios e
                       WHERE e.id_cuenta_fondos = c.id_cuenta_fondos AND e.credito > 0 AND e.conciliado = 0
                         AND " . REC_DISPONIBLE . " >= 0.01) AS pendientes
                FROM cuentas_fondos c
               WHERE c.tipo = 'B' AND c.moneda = 'ARS'
               ORDER BY c.habilitada DESC, c.nombre");
}

/**
 * A quién va cada crédito. $movs: filas con id_extracto, cuit, concepto,
 * referencia. Devuelve id_extracto => ['leido', 'id_cliente', 'como']:
 *   como = manual (asignado a mano) | regla (por la regla del CUIL) |
 *          extracto (el CUIL es el del cliente) | anulada (se sacó la regla
 *          para este crédito y vale el extracto) | '' (no se sabe)
 */
function rec_resolver(array $movs): array
{
    if (!$movs) {
        return [];
    }
    $propio = rec_cuit_propio();
    $ids = array_map(function ($m) { return (int) $m['id_extracto']; }, $movs);
    $manual = [];
    foreach (q('SELECT id_extracto, id_cliente FROM extractos_cliente WHERE id_extracto IN (' . rec_marcas($ids) . ')', $ids) as $a) {
        $manual[(int) $a['id_extracto']] = $a['id_cliente'] !== null ? (int) $a['id_cliente'] : null;
    }
    $leidos = [];
    foreach ($movs as $m) {
        $leidos[(int) $m['id_extracto']] = rec_cuil_leido($m, $propio);
    }
    $cuils = array_values(array_unique(array_filter($leidos)));
    $reglas = $porCuit = [];
    if ($cuils) {
        foreach (q('SELECT cuil_origen, id_cliente FROM cobros_cuil_reglas WHERE cuil_origen IN (' . rec_marcas($cuils) . ')', $cuils) as $r) {
            $reglas[$r['cuil_origen']] = (int) $r['id_cliente'];
        }
        foreach (q('SELECT id_cliente, cuit FROM clientes WHERE cuit IN (' . rec_marcas($cuils) . ')', $cuils) as $c) {
            $porCuit[$c['cuit']] = (int) $c['id_cliente'];
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $leido = $leidos[$id];
        if (array_key_exists($id, $manual) && $manual[$id] !== null) {
            $out[$id] = ['leido' => $leido, 'id_cliente' => $manual[$id], 'como' => 'manual'];
        } elseif (array_key_exists($id, $manual)) {
            $out[$id] = ['leido' => $leido, 'id_cliente' => $leido ? ($porCuit[$leido] ?? null) : null, 'como' => 'anulada'];
        } elseif ($leido && isset($reglas[$leido])) {
            $out[$id] = ['leido' => $leido, 'id_cliente' => $reglas[$leido], 'como' => 'regla'];
        } else {
            $out[$id] = ['leido' => $leido, 'id_cliente' => $leido ? ($porCuit[$leido] ?? null) : null, 'como' => $leido && isset($porCuit[$leido]) ? 'extracto' : ''];
        }
    }
    return $out;
}

/**
 * Lo que debe un cliente a una fecha, sumando sus préstamos vigentes
 * (liquidados hasta esa fecha), con los punitorios a ese día.
 *   vencido   cuotas con vencimiento hasta $fecha
 *   periodo   las que vencen después de $fecha y hasta $hasta
 *   despues   las que vencen después de $hasta
 *   cuotas    cada cuota con saldo, ordenadas como se aplica el recibo
 *   bloqueo   un préstamo con pagos posteriores a $fecha (no se puede
 *             imputar con esa fecha)
 */
function rec_deuda_cliente(array $prestamos, string $fecha, string $hasta): array
{
    static $cache = [];
    $d = ['vencido' => 0.0, 'periodo' => 0.0, 'despues' => 0.0, 'total' => 0.0, 'cuotas' => [], 'bloqueo' => null, 'prestamos' => 0];
    foreach ($prestamos as $p) {
        if ($p['fecha_liquidacion'] > $fecha) {
            continue;
        }
        $d['prestamos']++;
        if ($p['ultimo_pago'] !== null && $p['ultimo_pago'] > $fecha && $d['bloqueo'] === null) {
            $d['bloqueo'] = 'El préstamo ' . $p['nro_prestamo'] . ' tiene un pago del ' . fecha($p['ultimo_pago'])
                          . ', posterior al crédito: no se puede imputar con esa fecha.';
        }
        $k = $p['id_prestamo'] . '|' . $fecha;
        if (!isset($cache[$k])) {
            $cache[$k] = estado_cuenta((int) $p['id_prestamo'], $fecha, 'NORMAL')['cuotas'];
        }
        foreach ($cache[$k] as $c) {
            $t = round((float) $c['total'], 2);
            if ($t <= 0) {
                continue;
            }
            $tramo = $c['vencimiento'] <= $fecha ? 'vencido' : ($c['vencimiento'] <= $hasta ? 'periodo' : 'despues');
            $d[$tramo] += $t;
            $d['total'] += $t;
            $d['cuotas'][] = ['id_prestamo' => (int) $p['id_prestamo'], 'nro_prestamo' => $p['nro_prestamo'], 'nro_cuota' => (int) $c['nro_cuota'],
                              'vencimiento' => $c['vencimiento'], 'dias_atraso' => (int) $c['dias_atraso'], 'total' => $t,
                              'punitorio' => round((float) $c['punitorio'] + (float) $c['iva_punitorio'], 2), 'tramo' => $tramo];
        }
    }
    usort($d['cuotas'], function ($a, $b) {
        return [$a['vencimiento'], $a['id_prestamo'], $a['nro_cuota']] <=> [$b['vencimiento'], $b['id_prestamo'], $b['nro_cuota']];
    });
    foreach (['vencido', 'periodo', 'despues', 'total'] as $x) {
        $d[$x] = round($d[$x], 2);
    }
    return $d;
}

/** Cómo se aplicaría $importe a las cuotas (el mismo orden que sp_emitir_recibo): las cuotas con 'aplica' y lo que sobra. */
function rec_aplicacion(array $cuotas, float $importe): array
{
    $resto = round($importe, 2);
    foreach ($cuotas as &$c) {
        $c['aplica'] = round(min($resto, $c['total']), 2);
        $resto = round($resto - $c['aplica'], 2);
    }
    unset($c);
    return ['cuotas' => $cuotas, 'sobra' => max(0.0, $resto)];
}

/** «P-000001 c.1 · P-000002 c.1 (parcial)…»: lo que cubre el recibo, en una línea. */
function rec_aplicacion_txt(array $cuotas, int $max = 3): string
{
    $partes = [];
    foreach ($cuotas as $c) {
        if ($c['aplica'] <= 0) {
            continue;
        }
        $partes[] = $c['nro_prestamo'] . ' c.' . $c['nro_cuota'] . ($c['aplica'] + 0.004 < $c['total'] ? ' (parcial)' : '');
    }
    if (count($partes) > $max) {
        return implode(' · ', array_slice($partes, 0, $max)) . ' y ' . (count($partes) - $max) . ' más';
    }
    return implode(' · ', $partes);
}

/**
 * Los créditos de la cuenta en el período que tienen algo sin recibo,
 * repartidos en identificados (cliente con préstamos vigentes) y sin
 * cliente. $q filtra por CUIL (dígitos) o por nombre.
 */
function rec_creditos(int $cuenta, string $desde, string $hasta, string $q = ''): array
{
    $movs = q('SELECT e.id_extracto, e.fecha, e.referencia, e.concepto, e.cuit, e.credito, ' . REC_DISPONIBLE . ' AS disponible
                 FROM extractos_bancarios e
                WHERE e.id_cuenta_fondos = ? AND e.credito > 0 AND e.conciliado = 0 AND e.fecha BETWEEN ? AND ?
               HAVING disponible >= 0.01
                ORDER BY e.fecha, e.id_extracto', [$cuenta, $desde, $hasta]);
    $res = rec_resolver($movs);

    $idsCli = array_values(array_unique(array_filter(array_column($res, 'id_cliente'))));
    $clientes = $prestamos = [];
    if ($idsCli) {
        foreach (q('SELECT id_cliente, cuit, denominacion FROM clientes WHERE id_cliente IN (' . rec_marcas($idsCli) . ')', $idsCli) as $c) {
            $clientes[(int) $c['id_cliente']] = $c;
        }
        foreach (q("SELECT p.id_prestamo, p.id_cliente, p.nro_prestamo, p.fecha_liquidacion,
                           (SELECT MAX(g.fecha) FROM pagos g WHERE g.id_prestamo = p.id_prestamo AND g.estado = 'APLICADO') AS ultimo_pago
                      FROM prestamos p
                     WHERE p.estado = 'VIGENTE' AND p.id_cliente IN (" . rec_marcas($idsCli) . ')
                     ORDER BY p.id_prestamo', $idsCli) as $p) {
            $prestamos[(int) $p['id_cliente']][] = $p;
        }
    }
    $cuitsLeidos = array_values(array_unique(array_filter(array_column($res, 'leido'))));
    $inversores = $cuitsLeidos
        ? array_column(q('SELECT cuit, denominacion FROM inversores WHERE cuit IN (' . rec_marcas($cuitsLeidos) . ')', $cuitsLeidos), 'denominacion', 'cuit')
        : [];

    $qDig = preg_replace('/\D/', '', $q);
    $qTxt = rec_normalizar($q);
    $ident = $sinCli = [];
    foreach ($movs as $m) {
        $id = (int) $m['id_extracto'];
        $r = $res[$id];
        $cli = $r['id_cliente'] !== null ? ($clientes[$r['id_cliente']] ?? null) : null;
        $fila = ['id_extracto' => $id, 'fecha' => $m['fecha'], 'referencia' => $m['referencia'], 'concepto' => $m['concepto'],
                 'credito' => (float) $m['credito'], 'disponible' => round((float) $m['disponible'], 2),
                 'leido' => $r['leido'], 'como' => $r['como'], 'cliente' => $cli];

        if ($q !== '') {
            $texto = $cli ? $cli['cuit'] . ' ' . $cli['denominacion'] : '';
            $texto .= ' ' . $r['leido'] . ' ' . $m['concepto'] . ' ' . $m['referencia'];
            if ($qDig !== '' ? strpos(preg_replace('/\D/', '', $texto), $qDig) === false : strpos(rec_normalizar($texto), $qTxt) === false) {
                continue;
            }
        }

        if (!$cli || empty($prestamos[(int) $cli['id_cliente']])) {
            if ($cli) {
                $fila['motivo'] = 'El cliente ' . $cli['denominacion'] . ' no tiene préstamos vigentes.';
            } elseif ($r['leido'] && isset($inversores[$r['leido']])) {
                $fila['motivo'] = 'Es de un inversor (' . $inversores[$r['leido']] . '), no de un cliente.';
            } elseif ($r['leido']) {
                $fila['motivo'] = 'El CUIL ' . cuit_fmt($r['leido']) . ' no es de ningún cliente.';
            } else {
                $fila['motivo'] = 'El extracto no trae CUIL.';
            }
            $sinCli[] = $fila;
            continue;
        }

        $d = rec_deuda_cliente($prestamos[(int) $cli['id_cliente']], $m['fecha'], $hasta);
        if ($d['prestamos'] === 0) {
            // Los préstamos se liquidaron después del crédito: no es su cuota.
            $fila['motivo'] = 'Los préstamos vigentes de ' . $cli['denominacion'] . ' se liquidaron después del crédito.';
            $sinCli[] = $fila;
            continue;
        }
        $fila['deuda'] = $d;
        $fila['importe'] = round(min($fila['disponible'], $d['total']), 2);
        $fila['aplicacion'] = rec_aplicacion($d['cuotas'], $fila['importe']);
        $fila['sobra'] = round($fila['disponible'] - $fila['importe'], 2);
        $ident[] = $fila;
    }

    // Un cliente con dos créditos: el segundo se aplica sobre lo que deja el
    // primero (se emiten por fecha). La vista de cada uno es contra la deuda
    // de hoy, así que se avisa.
    $veces = array_count_values(array_map(function ($f) { return (int) $f['cliente']['id_cliente']; }, $ident));
    foreach ($ident as &$f) {
        $f['repetido'] = $veces[(int) $f['cliente']['id_cliente']] > 1;
    }
    unset($f);

    $suma = function (array $l, string $k) { return round(array_sum(array_column($l, $k)), 2); };
    return [
        'identificados' => $ident,
        'sin_cliente'   => $sinCli,
        'stats' => [
            'creditos' => count($movs), 'disponible' => $suma($movs, 'disponible'),
            'ident' => count($ident), 'ident_importe' => $suma($ident, 'importe'), 'ident_disponible' => $suma($ident, 'disponible'),
            'sin' => count($sinCli), 'sin_importe' => $suma($sinCli, 'disponible'),
        ],
    ];
}

/**
 * Clientes con deuda vencida o que vence hasta $hasta y sin un crédito
 * identificado en la lista. Lo vencido es a hoy, sin los punitorios que
 * todavía no se devengaron (los suma el recibo cuando se cobra).
 */
function rec_deudores(string $hasta, array $excluir, string $q, int $pagina, int $porPagina): array
{
    $w = ["p.estado = 'VIGENTE'", 's.vencimiento <= ?'];
    $par = [$hasta];
    if ($excluir) {
        $w[] = 'p.id_cliente NOT IN (' . rec_marcas($excluir) . ')';
        $par = array_merge($par, $excluir);
    }
    if ($q !== '') {
        $dig = preg_replace('/\D/', '', $q);
        if ($dig !== '' && strlen($dig) >= 5) {
            $w[] = 'c.cuit LIKE ?';
            $par[] = '%' . $dig . '%';
        } else {
            $w[] = 'c.denominacion LIKE ?';
            $par[] = '%' . $q . '%';
        }
    }
    $saldo = 's.capital_pend + s.interes_pend + s.iva_pend + s.punit_pend + s.ivap_pend';
    $base = "FROM prestamos p
             JOIN clientes c ON c.id_cliente = p.id_cliente
             JOIN v_cuotas_saldo s ON s.id_prestamo = p.id_prestamo
            WHERE " . implode(' AND ', $w) . " AND $saldo > 0
            GROUP BY p.id_cliente, c.denominacion, c.cuit";
    $sel = "SELECT p.id_cliente, c.denominacion, c.cuit, COUNT(DISTINCT p.id_prestamo) AS prestamos,
                   SUM(IF(s.vencimiento < CURDATE(), $saldo, 0)) AS vencido,
                   SUM(IF(s.vencimiento >= CURDATE(), $saldo, 0)) AS periodo,
                   MIN(s.vencimiento) AS primer_vto";
    $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(vencido), 0) AS vencido, COALESCE(SUM(periodo), 0) AS periodo FROM ($sel $base) x", $par);
    $filas = q("$sel $base ORDER BY vencido DESC, periodo DESC, c.denominacion LIMIT ? OFFSET ?",
               array_merge($par, [$porPagina, ($pagina - 1) * $porPagina]));
    return ['filas' => $filas, 'n' => (int) $tot['n'], 'vencido' => (float) $tot['vencido'], 'periodo' => (float) $tot['periodo']];
}

/**
 * Emite el recibo de un crédito del extracto. Vuelve a resolver el cliente
 * (lo que dice la pantalla puede haber cambiado) y deja que la base
 * controle lo demás con el crédito y los préstamos bloqueados.
 * Devuelve ['ok' => bool, 'texto' => …, 'id_recibo' => …].
 */
function rec_emitir(int $idExtracto, int $cuenta, ?int $talonario, string $medio): array
{
    $m = q1('SELECT e.id_extracto, e.fecha, e.referencia, e.concepto, e.cuit, e.credito, ' . REC_DISPONIBLE . ' AS disponible
               FROM extractos_bancarios e WHERE e.id_extracto = ? AND e.id_cuenta_fondos = ?', [$idExtracto, $cuenta]);
    if (!$m) {
        return ['ok' => false, 'texto' => 'El movimiento ya no está en el extracto de esta cuenta.', 'id_recibo' => null];
    }
    $r = rec_resolver([$m])[$idExtracto];
    if ($r['id_cliente'] === null) {
        return ['ok' => false, 'texto' => 'El crédito del ' . fecha($m['fecha']) . ' por ' . money($m['credito']) . ' ya no tiene cliente.', 'id_recibo' => null];
    }
    try {
        $st = db()->prepare('CALL sp_emitir_recibo(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @id_recibo)');
        $st->execute([$r['id_cliente'], $m['fecha'], round((float) $m['disponible'], 2), $medio,
                      $m['referencia'] !== null ? substr((string) $m['referencia'], 0, 100) : null, $cuenta, $talonario, $idExtracto,
                      $r['leido'], usuario_id()]);
        do {
            try {
                $st->fetchAll();
            } catch (PDOException $e) {
            }
        } while ($st->nextRowset());
        $st->closeCursor();
        $id = (int) q1('SELECT @id_recibo AS id')['id'];
        $rec = q1('SELECT r.nro_recibo, r.importe, c.denominacion FROM recibos r JOIN clientes c ON c.id_cliente = r.id_cliente WHERE r.id_recibo = ?', [$id]);
        return ['ok' => true, 'id_recibo' => $id,
                'texto' => 'Recibo ' . $rec['nro_recibo'] . ' · ' . $rec['denominacion'] . ' · ' . money($rec['importe'])];
    } catch (Throwable $e) {
        return ['ok' => false, 'id_recibo' => null,
                'texto' => 'Crédito del ' . fecha($m['fecha']) . ' por ' . money($m['credito']) . ': ' . mensaje_bd($e)];
    }
}

/**
 * Asigna un crédito a un cliente, o lo devuelve al CUIL del extracto
 * ($txt vacío). Con el CUIL del extracto legible y distinto del del
 * cliente, guarda la regla: los créditos que vengan con ese CUIL van solos
 * a ese cliente. Devuelve [ok, mensaje].
 */
function rec_asignar(int $idExtracto, int $cuenta, string $txt): array
{
    $m = q1('SELECT e.id_extracto, e.cuit, e.concepto, e.referencia, e.conciliado, ' . REC_DISPONIBLE . ' AS disponible
               FROM extractos_bancarios e WHERE e.id_extracto = ? AND e.id_cuenta_fondos = ? AND e.credito > 0', [$idExtracto, $cuenta]);
    if (!$m) {
        return [false, 'El crédito no está en el extracto de esta cuenta.'];
    }
    if ((int) $m['conciliado'] === 1 || (float) $m['disponible'] < 0.01) {
        return [false, 'El crédito ya tiene su recibo o está conciliado: no se reasigna.'];
    }
    $leido = rec_cuil_leido($m, rec_cuit_propio());
    $dig = preg_replace('/\D/', '', $txt);

    if ($dig === '') {
        q('INSERT INTO extractos_cliente (id_extracto, id_cliente, creado_por) VALUES (?, NULL, ?)
           ON DUPLICATE KEY UPDATE id_cliente = NULL, creado_por = VALUES(creado_por), creado_el = NOW()', [$idExtracto, usuario_id()]);
        $msg = 'El crédito vuelve al CUIL del extracto' . ($leido ? ' (' . cuit_fmt($leido) . ')' : '') . '.';
        if ($leido && ($rg = q1('SELECT c.denominacion FROM cobros_cuil_reglas r JOIN clientes c ON c.id_cliente = r.id_cliente WHERE r.cuil_origen = ?', [$leido]))) {
            $msg .= ' La regla que manda ese CUIL a ' . $rg['denominacion'] . ' sigue para los demás créditos.';
        }
        return [true, $msg];
    }

    if (strlen($dig) === 11) {
        $cands = q('SELECT id_cliente, cuit, denominacion FROM clientes WHERE cuit = ?', [$dig]);
    } elseif (strlen($dig) >= 7 && strlen($dig) <= 8) {
        $cands = q('SELECT id_cliente, cuit, denominacion FROM clientes WHERE SUBSTRING(cuit, 3, 8) = ?', [str_pad($dig, 8, '0', STR_PAD_LEFT)]);
    } else {
        return [false, '«' . $txt . '» no es un CUIT/CUIL (11 dígitos) ni un DNI.'];
    }
    if (!$cands) {
        return [false, 'No hay ningún cliente con ' . (strlen($dig) === 11 ? 'CUIT/CUIL ' . cuit_fmt($dig) : 'DNI ' . $dig) . '.'];
    }
    if (count($cands) > 1) {
        return [false, 'Hay más de un cliente con DNI ' . $dig . ': escribí el CUIT/CUIL completo.'];
    }
    $c = $cands[0];
    $vig = (int) q1("SELECT COUNT(*) AS n FROM prestamos WHERE id_cliente = ? AND estado = 'VIGENTE'", [$c['id_cliente']])['n'];
    if ($vig === 0) {
        return [false, $c['denominacion'] . ' no tiene préstamos vigentes: no hay contra qué aplicar el recibo.'];
    }

    q('INSERT INTO extractos_cliente (id_extracto, id_cliente, creado_por) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE id_cliente = VALUES(id_cliente), creado_por = VALUES(creado_por), creado_el = NOW()',
      [$idExtracto, $c['id_cliente'], usuario_id()]);
    $msg = 'Crédito asignado a ' . $c['denominacion'] . ' (' . cuit_fmt($c['cuit']) . ').';
    if ($leido && $leido !== $c['cuit']) {
        q('INSERT INTO cobros_cuil_reglas (cuil_origen, id_cliente, creado_por) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE id_cliente = VALUES(id_cliente), creado_por = VALUES(creado_por), creado_el = NOW()',
          [$leido, $c['id_cliente'], usuario_id()]);
        $otros = (int) q1('SELECT COUNT(*) AS n FROM extractos_bancarios e
                            WHERE e.credito > 0 AND e.conciliado = 0 AND e.id_extracto <> ? AND ' . REC_DISPONIBLE . ' >= 0.01
                              AND (e.cuit = ? OR e.concepto LIKE ? OR e.referencia LIKE ?)',
                          [$idExtracto, $leido, '%' . $leido . '%', '%' . $leido . '%'])['n'];
        $msg .= ' El CUIL ' . cuit_fmt($leido) . ' del extracto queda asociado a este cliente'
              . ($otros ? ' y alcanza a ' . $otros . ' crédito(s) más sin recibo.' : ' para los próximos créditos.');
    }
    return [true, $msg];
}

/** Da de baja la regla de un CUIL: sus créditos vuelven a resolverse por el extracto. */
function rec_quitar_regla(string $cuil): array
{
    $dig = preg_replace('/\D/', '', $cuil);
    $st = db()->prepare('DELETE FROM cobros_cuil_reglas WHERE cuil_origen = ?');
    $st->execute([$dig]);
    return $st->rowCount()
        ? [true, 'Se quitó la regla del CUIL ' . cuit_fmt($dig) . ': sus créditos vuelven a resolverse por el extracto.']
        : [false, 'No había ninguna regla para el CUIL ' . cuit_fmt($dig) . '.'];
}

// ---------------------------------------------------------------------
// Un recibo: cabecera, pagos (uno por préstamo) e imputación
// ---------------------------------------------------------------------

/** El recibo con su cliente y, si salió del extracto, el crédito. null si no existe. */
function recibo_leer(int $idRecibo): ?array
{
    return q1('SELECT r.*, c.denominacion, c.cuit, cf.nombre AS cuenta, l.archivo AS lote_archivo,
                      e.fecha AS ext_fecha, e.referencia AS ext_referencia, e.credito AS ext_credito, e.concepto AS ext_concepto,
                      t.nombre AS talonario, t.letra AS talonario_letra
                 FROM recibos r
                 JOIN clientes c ON c.id_cliente = r.id_cliente
                 LEFT JOIN cuentas_fondos cf ON cf.id_cuenta_fondos = r.id_cuenta_fondos
                 LEFT JOIN lotes_cobranza l ON l.id_lote = r.id_lote
                 LEFT JOIN extractos_bancarios e ON e.id_extracto = r.id_extracto
                 LEFT JOIN talonarios t ON t.id_talonario = r.id_talonario
                WHERE r.id_recibo = ?', [$idRecibo]);
}

/** Los pagos del recibo (uno por préstamo), con su imputación por cuota y si cada uno es el último de su préstamo. */
function recibo_pagos(int $idRecibo): array
{
    $pagos = q("SELECT g.id_pago, g.id_prestamo, g.importe, g.modo, g.estado, p.nro_prestamo, p.plazo, ta.nombre AS tipo_asociado,
                       (SELECT x.id_pago FROM pagos x WHERE x.id_prestamo = g.id_prestamo AND x.estado = 'APLICADO'
                         ORDER BY x.fecha DESC, x.id_pago DESC LIMIT 1) AS ultimo_del_prestamo
                  FROM pagos g
                  JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                  JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
                 WHERE g.id_recibo = ?
                 ORDER BY p.id_prestamo", [$idRecibo]);
    foreach ($pagos as &$g) {
        $g['cuotas'] = q("SELECT c.nro_cuota, c.vencimiento,
                                 SUM(IF(pi.concepto = 'CAPITAL', pi.importe, 0)) AS capital,
                                 SUM(IF(pi.concepto IN ('INTERES', 'IVA_INTERES'), pi.importe, 0)) AS interes,
                                 SUM(IF(pi.concepto IN ('PUNITORIO', 'IVA_PUNITORIO'), pi.importe, 0)) AS punitorio,
                                 SUM(pi.importe) AS total
                            FROM pagos_imputacion pi JOIN cuotas c ON c.id_cuota = pi.id_cuota
                           WHERE pi.id_pago = ? GROUP BY c.nro_cuota, c.vencimiento ORDER BY c.nro_cuota", [$g['id_pago']]);
        $g['saldo'] = q1("SELECT SUM(capital_pend + interes_pend + iva_pend + punit_pend + ivap_pend) AS deuda,
                                 SUM(capital_pend) AS capital, SUM(estado = 'PAGADA') AS pagadas
                            FROM v_cuotas_saldo WHERE id_prestamo = ?", [$g['id_prestamo']]);
    }
    unset($g);
    return $pagos;
}

/** El recibo de un pago (los enlaces viejos van por el pago). */
function recibo_de_pago(int $idPago): ?int
{
    $r = q1('SELECT id_recibo FROM pagos WHERE id_pago = ?', [$idPago]);
    return $r && $r['id_recibo'] !== null ? (int) $r['id_recibo'] : null;
}
