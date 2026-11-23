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
 * Devuelve [resumen, cuadro, gastos]: gastos es el detalle de los conceptos
 * de gasto (vacío si la base todavía no tiene financiera_gastos.sql).
 * Lanza la excepción de la base si no se puede.
 */
function prestamo_simular(int $idProducto, float $capital, int $plazo, string $fecha, ?string $primerVto): array
{
    $st = db()->prepare('CALL sp_simular_prestamo(?, ?, ?, ?, ?)');
    $st->execute([$idProducto, round($capital, 2), $plazo, $fecha, $primerVto]);
    $res = $st->fetch();
    $st->nextRowset();
    $cuadro = $st->fetchAll();
    $gastos = [];
    if ($st->nextRowset() && $st->columnCount() > 0) {
        $gastos = $st->fetchAll();
    }
    while ($st->nextRowset()) {
    }
    $st->closeCursor();
    $res += ['gastos_financiados' => 0, 'capital_financiado' => $res['capital'] ?? 0];
    return [$res, $cuadro, $gastos];
}

/** Cómo se calculó un gasto: «fijo» o «1,5 % s/$ 500.000,00». */
function gasto_calculo_txt(array $g): string
{
    return $g['tipo_calculo'] === 'FIJO' ? 'fijo'
         : rtrim(rtrim(number_format((float) $g['valor'] * 100, 4, ',', '.'), '0'), ',') . ' % s/' . money($g['base']);
}

/**
 * Detalle de gastos (de la simulación o de prestamos_gastos) como tabla.
 * $titulo vacío: sin encabezado de bloque.
 */
function gastos_detalle_html(array $gastos, string $titulo = 'Gastos'): string
{
    if (!$gastos) {
        return '';
    }
    $filas = '';
    $tot = ['DESCUENTO' => 0.0, 'FINANCIADO' => 0.0];
    foreach ($gastos as $g) {
        $tot[$g['forma_cobro']] = ($tot[$g['forma_cobro']] ?? 0) + (float) $g['total'];
        $calc = gasto_calculo_txt($g);
        $filas .= '<tr><td>' . h($g['nombre']) . '<span class="segunda-linea">' . h($calc) . '</span></td>'
                . '<td>' . ($g['forma_cobro'] === 'FINANCIADO' ? estado('En las cuotas', 'info') : estado('Se descuenta', 'neutro')) . '</td>'
                . '<td class="td-right">' . money($g['importe']) . '</td>'
                . '<td class="td-right">' . ((float) $g['iva'] ? money($g['iva']) : '<span class="td-muted">—</span>') . '</td>'
                . '<td class="td-right td-fuerte">' . money($g['total']) . '</td></tr>';
    }
    $pie = [];
    if ($tot['DESCUENTO'] > 0) {
        $pie[] = 'descontado del desembolso ' . money($tot['DESCUENTO']);
    }
    if ($tot['FINANCIADO'] > 0) {
        $pie[] = 'financiado en las cuotas ' . money($tot['FINANCIADO']);
    }
    return ($titulo !== '' ? '<div class="bloque-titulo">' . h($titulo) . '</div>' : '')
         . '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Concepto</th><th>Cómo se cobra</th>'
         . '<th class="th-right">Importe</th><th class="th-right">IVA</th><th class="th-right">Total</th></tr></thead><tbody>'
         . $filas . '</tbody><tfoot><tr><td colspan="5" class="td-right chico">' . h(implode(' · ', $pie)) . '</td></tr></tfoot></table></div>';
}

/** Los gastos cobrados en un préstamo ([] si no hay o falta la tabla). */
function prestamo_gastos(int $idPrestamo): array
{
    try {
        return q('SELECT * FROM prestamos_gastos WHERE id_prestamo = ? ORDER BY id_prestamo_gasto', [$idPrestamo]);
    } catch (Throwable $e) {
        return [];
    }
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

    // Cuota máxima (Parámetros del sistema; 0 = sin tope)
    $cuotaMax = (float) parametro('CUOTA_MAXIMA', '0');
    if ($cuotaMax > 0 && $valorCuota > $cuotaMax) {
        $add('CUOTA_MAXIMA', 'advertencia', 'La cuota (' . money($valorCuota) . ') supera la cuota máxima de ' . money($cuotaMax) . '.');
    }
    return [$ctl, $filas];
}

/**
 * Si un préstamo se puede refinanciar (Parámetros del sistema ›
 * Condiciones de refinanciación): la condición de su plazo o, si no está,
 * la del plazo menor más cercano. null si no hay condiciones cargadas.
 * Devuelve ['pagas', 'necesita', 'plazo_regla', 'puede'].
 */
function refinanciacion_estado(int $idPrestamo): ?array
{
    try {
        $p = q1("SELECT p.plazo, (SELECT COUNT(*) FROM cuotas c WHERE c.id_prestamo = p.id_prestamo AND c.estado = 'PAGADA') AS pagas
                   FROM prestamos p WHERE p.id_prestamo = ?", [$idPrestamo]);
        if (!$p) {
            return null;
        }
        $r = q1('SELECT plazo, cuotas_pagas FROM refinanciacion_condiciones WHERE plazo <= ? ORDER BY plazo DESC LIMIT 1', [(int) $p['plazo']]);
        if (!$r) {
            return q1('SELECT 1 AS x FROM refinanciacion_condiciones LIMIT 1')
                ? ['pagas' => (int) $p['pagas'], 'necesita' => null, 'plazo_regla' => null, 'puede' => false]
                : null;
        }
        return ['pagas' => (int) $p['pagas'], 'necesita' => (int) $r['cuotas_pagas'], 'plazo_regla' => (int) $r['plazo'],
                'puede' => (int) $p['pagas'] >= (int) $r['cuotas_pagas']];
    } catch (PDOException $e) {
        return null;                                        // sin sql\financiera_punitorios_parametros.sql
    }
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

/**
 * Como sp_resultados, para un procedure que sólo consulta (sp_estado_cuenta):
 * en su propia transacción READ COMMITTED, así lee lo confirmado sin bloquear.
 * En REPEATABLE READ, sus INSERT … SELECT a tablas temporarias ponen bloqueos
 * compartidos sobre pagos e imputaciones y se trababan con un cobro en curso.
 * Si ya hay una transacción abierta, corre dentro de ella.
 */
function sp_consulta(string $sql, array $params): array
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return sp_resultados($sql, $params);
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $r = sp_resultados($sql, $params);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
