<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Préstamos: piezas comunes del alta, el listado y la ficha.
//
// Controles al liquidar (prestamo_controles):
//   bloqueo      no se puede liquidar (cliente no activo, sin tasa…)
//   advertencia  se puede liquidar confirmándolo y anotando el motivo
//   info         se muestra, no pide nada
// Lo que dijeron queda guardado en prestamos.controles (JSON), junto con
// el motivo, para saber después con qué información se aprobó.
// ---------------------------------------------------------------------

require_once __DIR__ . '/bcra.php';

const ESTADOS_PRESTAMO = ['VIGENTE' => 'Vigente', 'CANCELADO' => 'Cancelado', 'REFINANCIADO' => 'Refinanciado',
                          'LEGALES' => 'En legales', 'ANULADO' => 'Anulado'];
const ESTADOS_PRESTAMO_TIPO = ['VIGENTE' => 'info', 'CANCELADO' => 'ok', 'REFINANCIADO' => 'neutro',
                               'LEGALES' => 'danger', 'ANULADO' => 'neutro'];
const ESTADOS_CUOTA_TIPO = ['PENDIENTE' => 'neutro', 'PARCIAL' => 'warn', 'PAGADA' => 'ok', 'REFINANCIADA' => 'neutro'];

function estado_prestamo(string $e): string
{
    return estado(ESTADOS_PRESTAMO[$e] ?? $e, ESTADOS_PRESTAMO_TIPO[$e] ?? 'neutro');
}

/** Valor de la tabla parametros (o $defecto). */
function parametro(string $clave, string $defecto = ''): string
{
    static $cache = [];
    if (!array_key_exists($clave, $cache)) {
        $f = q1('SELECT valor FROM parametros WHERE clave = ?', [$clave]);
        $cache[$clave] = $f ? (string) $f['valor'] : null;
    }
    return $cache[$clave] ?? $defecto;
}

/** Próximo número de préstamo: P-000001, P-000002… */
function prestamo_proximo_nro(int $salto = 0): string
{
    $pref = parametro('NRO_PRESTAMO_PREFIJO', 'P-');
    $f = q1("SELECT COALESCE(MAX(CAST(SUBSTRING(nro_prestamo, ?) AS UNSIGNED)), 0) AS n
               FROM prestamos WHERE nro_prestamo LIKE ? AND SUBSTRING(nro_prestamo, ?) REGEXP '^[0-9]+$'",
            [strlen($pref) + 1, addcslashes($pref, '%_\\') . '%', strlen($pref) + 1]);
    return $pref . str_pad((string) ((int) $f['n'] + 1 + $salto), 6, '0', STR_PAD_LEFT);
}

/**
 * Simula con sp_simular_prestamo (el mismo motor que la liquidación).
 * Devuelve [resumen, cuadro]. Lanza la excepción de la base si no se puede.
 */
function prestamo_simular(int $idProducto, float $capital, int $plazo, string $fecha, ?string $primerVto): array
{
    $st = db()->prepare('CALL sp_simular_prestamo(?, ?, ?, ?, ?)');
    $st->execute([$idProducto, round($capital, 2), $plazo, $fecha, $primerVto]);
    $res = $st->fetch();
    $st->nextRowset();
    $cuadro = $st->fetchAll();
    while ($st->nextRowset()) {
    }
    $st->closeCursor();
    return [$res, $cuadro];
}

/**
 * Controles antes de liquidar. Consulta el BCRA si la última consulta tiene
 * más de CONTROL_BCRA_DIAS (usa la caché de 24 h de bcra_consultar).
 * Devuelve [lista de controles, filas BCRA usadas].
 * Cada control: ['c' => código, 'n' => bloqueo|advertencia|info|ok, 't' => texto].
 */
function prestamo_controles(array $cliente, float $valorCuota, bool $consultarBcra = true): array
{
    $ctl = [];
    $add = function (string $c, string $n, string $t) use (&$ctl) {
        $ctl[] = ['c' => $c, 'n' => $n, 't' => $t];
    };
    $id = (int) $cliente['id_cliente'];

    // Cliente
    if ($cliente['estado'] !== 'ACTIVO') {
        $add('CLIENTE', 'bloqueo', 'El cliente está ' . strtolower($cliente['estado']) . ': no se le puede liquidar.');
    }

    // BCRA
    $filas = [];
    $diasMax = (int) parametro('CONTROL_BCRA_DIAS', '30');
    $sitMax  = (int) parametro('CONTROL_BCRA_SITUACION_MAX', '2');
    try {
        $filas = bcra_guardadas($cliente['cuit']);
        $vieja = !$filas || count($filas) < 3
              || strtotime(bcra_cuando($filas)) < time() - $diasMax * 86400;
        if ($vieja && $consultarBcra) {
            [$filas, $errs] = bcra_consultar($cliente['cuit'], $id, false);
            if ($errs) {
                $add('BCRA_ERROR', 'advertencia', 'No se pudo consultar el BCRA: ' . implode(' ', $errs)
                     . ($filas ? ' Se usa la consulta del ' . date('d/m/Y', strtotime(bcra_cuando($filas))) . '.' : ''));
            }
        } elseif ($vieja) {
            $add('BCRA_VIEJA', 'advertencia', 'La última consulta al BCRA tiene más de ' . $diasMax . ' días.');
        }
    } catch (Throwable $e) {
        $add('BCRA_ERROR', 'advertencia', 'No se pudo consultar el BCRA: ' . error_amigable($e));
    }
    $fD = $filas['DEUDAS'] ?? null;
    $fH = $filas['HISTORICAS'] ?? null;
    $fC = $filas['CHEQUES'] ?? null;
    if ($fD) {
        $cuando = ' (consulta del ' . date('d/m/Y', strtotime($fD['consultado_el'])) . ')';
        if (bcra_sin_registros($fD)) {
            $add('BCRA_SIT', 'ok', 'BCRA: sin deudas informadas' . $cuando . '.');
        } elseif ((int) $fD['peor_situacion'] > $sitMax) {
            $peores = [];
            foreach (bcra_deuda_entidades(bcra_resultados($filas)['DEUDAS']) as $e) {
                if ((int) ($e['situacion'] ?? 0) > $sitMax) {
                    $peores[] = $e['entidad'] . ' (' . (int) $e['situacion'] . ')';
                }
            }
            $add('BCRA_SIT', 'advertencia', 'BCRA: situación ' . (int) $fD['peor_situacion'] . ' · '
                 . BCRA_SITUACIONES[(int) $fD['peor_situacion']][0] . ' en ' . implode(', ', $peores) . $cuando . '.');
        } else {
            $add('BCRA_SIT', 'ok', 'BCRA: situación ' . (int) $fD['peor_situacion'] . ' · ' . BCRA_SITUACIONES[(int) $fD['peor_situacion']][0]
                 . ', deuda ' . money((float) $fD['deuda_total'] * 1000) . $cuando . '.');
        }
    }
    if ($fH && $fH['peor_situacion'] && (int) $fH['peor_situacion'] > $sitMax) {
        $add('BCRA_HIST', 'info', 'BCRA: en los últimos 24 meses llegó a situación ' . (int) $fH['peor_situacion']
             . ' · ' . BCRA_SITUACIONES[(int) $fH['peor_situacion']][0] . '.');
    }
    if ($fC) {
        if ((int) $fC['cheques_impagos'] > 0) {
            $add('CHEQUES', 'advertencia', 'Tiene ' . (int) $fC['cheques_impagos'] . ' cheque' . ((int) $fC['cheques_impagos'] === 1 ? '' : 's')
                 . ' rechazado' . ((int) $fC['cheques_impagos'] === 1 ? '' : 's') . ' sin pagar.');
        } elseif ((int) $fC['cheques'] > 0) {
            $add('CHEQUES', 'info', 'Tuvo ' . (int) $fC['cheques'] . ' cheques rechazados, todos pagados.');
        } else {
            $add('CHEQUES', 'ok', 'Sin cheques rechazados.');
        }
    }

    // Documentación obligatoria
    try {
        $falta = q("SELECT nombre, estado FROM v_clientes_doc_checklist WHERE id_cliente = ? AND estado <> 'OK' ORDER BY orden", [$id]);
        if ($falta) {
            $add('DOC', 'advertencia', 'Documentación incompleta: ' . implode(', ', array_map(function ($f) {
                return $f['nombre'] . ($f['estado'] === 'VENCIDO' ? ' (vencido)' : '');
            }, $falta)) . '.');
        } else {
            $add('DOC', 'ok', 'Documentación obligatoria completa.');
        }
    } catch (Throwable $e) {
        // sin el módulo de documentación no se controla
    }

    // Mora y préstamos vigentes
    $mora = q('SELECT nro_prestamo, dias_atraso, deuda_vencida FROM v_mora_prestamos WHERE id_cliente = ? ORDER BY dias_atraso DESC', [$id]);
    if ($mora) {
        $add('MORA', 'advertencia', 'Tiene préstamos en mora: ' . implode(', ', array_map(function ($m) {
            return $m['nro_prestamo'] . ' (' . (int) $m['dias_atraso'] . ' días, ' . money($m['deuda_vencida']) . ')';
        }, $mora)) . '.');
    }
    $vig = q1("SELECT COUNT(*) AS n, COALESCE(SUM(valor_cuota), 0) AS cuotas FROM prestamos WHERE id_cliente = ? AND estado = 'VIGENTE'", [$id]);
    if ((int) $vig['n'] > 0 && !$mora) {
        $add('VIGENTES', 'info', 'Tiene ' . (int) $vig['n'] . ' préstamo' . ((int) $vig['n'] === 1 ? '' : 's') . ' vigente'
             . ((int) $vig['n'] === 1 ? '' : 's') . ' al día (cuotas por ' . money($vig['cuotas']) . ').');
    }

    // Cuota / ingreso (personas con ingreso cargado)
    $ingreso = (float) ($cliente['ingreso_mensual'] ?? 0);
    $max = (float) parametro('CONTROL_CUOTA_INGRESO_MAX', '0.30');
    if ($cliente['tipo'] === 'F') {
        if ($ingreso > 0) {
            $rel = ((float) $vig['cuotas'] + $valorCuota) / $ingreso;
            $txt = 'Cuotas ' . money((float) $vig['cuotas'] + $valorCuota) . ' = ' . pct($rel, 1) . ' del ingreso (' . money($ingreso) . ')';
            $add('CUOTA_INGRESO', $rel > $max ? 'advertencia' : 'ok', $txt . ($rel > $max ? ', más del ' . pct($max, 0) . ' permitido.' : '.'));
        } else {
            $add('CUOTA_INGRESO', 'info', 'No tiene cargado el ingreso mensual: no se controla la relación cuota / ingreso.');
        }
    }
    return [$ctl, $filas];
}

/** Corre un SP de consulta y devuelve todos sus resultsets. */
function sp_resultados(string $sql, array $params): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $out = [];
    do {
        try {
            $out[] = $st->fetchAll();
        } catch (PDOException $e) {
            $out[] = [];
        }
    } while ($st->nextRowset());
    $st->closeCursor();
    return $out;
}

/** Hay algún control de ese nivel. */
function controles_hay(array $ctl, string $nivel): bool
{
    foreach ($ctl as $c) {
        if ($c['n'] === $nivel) {
            return true;
        }
    }
    return false;
}

/** Lista de controles en HTML. */
function controles_html(array $ctl): string
{
    $orden = ['bloqueo' => 0, 'advertencia' => 1, 'info' => 2, 'ok' => 3];
    usort($ctl, function ($a, $b) use ($orden) { return ($orden[$a['n']] ?? 9) <=> ($orden[$b['n']] ?? 9); });
    $ico = ['bloqueo' => ['error', 'danger'], 'advertencia' => ['alerta', 'warn'], 'info' => ['info', 'info'], 'ok' => ['ok', 'ok']];
    $o = '<ul class="controles">';
    foreach ($ctl as $c) {
        [$i, $t] = $ico[$c['n']] ?? ['info', 'info'];
        $o .= '<li class="control-' . h($t) . '">' . icono($i, 15) . '<span>' . h($c['t']) . '</span></li>';
    }
    return $o . '</ul>';
}

/** Tasa mensual (TIR) de: +neto en t0 y −cuota_i cada mes. Para el CFT aproximado. */
function prestamo_tir_mensual(float $neto, array $cuotas): ?float
{
    if ($neto <= 0 || !$cuotas) {
        return null;
    }
    $vpn = function (float $i) use ($neto, $cuotas) {
        $s = $neto;
        foreach ($cuotas as $k => $c) {
            $s -= $c / pow(1 + $i, $k + 1);
        }
        return $s;
    };
    $lo = 0.0;
    $hi = 1.0;
    if ($vpn($lo) > 0) {
        return 0.0;
    }
    for ($n = 0; $n < 200 && $vpn($hi) < 0; $n++) {
        $hi *= 2;
    }
    for ($n = 0; $n < 200; $n++) {
        $mid = ($lo + $hi) / 2;
        if ($vpn($mid) < 0) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return ($lo + $hi) / 2;
}
