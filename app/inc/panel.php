<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Panel general: los números de un período (panel.php). Acá solo las
// consultas, para que la pantalla y el PDF muestren exactamente lo mismo.
//
//   Movimientos del período (desde / hasta, inclusive):
//     solicitudes ..... lo que pasó en el historial: cargadas, aprobadas,
//                       observadas, rechazadas, anuladas, liquidadas
//                       (cada solicitud cuenta una vez por acción)
//     préstamos ....... liquidados (fecha_liquidacion), sin los anulados
//     cobranzas ....... pagos aplicados (fecha del pago), por concepto y
//                       por medio; los anulados aparte
//     inversiones ..... aportes (fecha de inicio), liquidaciones (fecha),
//                       pagos a inversores (pagado_el) y vencimientos
//   Foto de hoy (no depende del período): solicitudes esperando
//   autorización, aprobadas sin liquidar, cartera vigente y mora, capital
//   de inversores vigente, liquidaciones a pagar.
// ---------------------------------------------------------------------

require_once __DIR__ . '/cobranzas.php';
require_once __DIR__ . '/inversiones.php';
require_once __DIR__ . '/solicitudes.php';

const PANEL_LISTA_MAX = 100;       // renglones de las listas de detalle
const PANEL_DIAS_DIARIO = 62;      // hasta cuántos días el gráfico va por día; más, por mes

const PANEL_ACCIONES_SOL = ['CREADA' => 'Cargadas', 'APROBADA' => 'Aprobadas', 'OBSERVADA' => 'Observadas',
                            'RECHAZADA' => 'Rechazadas', 'ANULADA' => 'Anuladas', 'LIQUIDADA' => 'Liquidadas'];

/** Rangos rápidos: clave => [rótulo, desde, hasta]. */
function panel_rangos(): array
{
    $hoy = date('Y-m-d');
    $lunes = date('Y-m-d', strtotime('monday this week'));
    return [
        'hoy'     => ['Hoy', $hoy, $hoy],
        'ayer'    => ['Ayer', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        'semana'  => ['Esta semana', $lunes, $hoy],
        'mes'     => ['Este mes', date('Y-m-01'), $hoy],
        'mes_ant' => ['Mes anterior', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'anio'    => ['Este año', date('Y-01-01'), $hoy],
    ];
}

/** «del 20/11/2026» o «del 01/11/2026 al 20/11/2026». */
function panel_periodo_txt(string $d, string $h): string
{
    return $d === $h ? 'del ' . fecha($d) : 'del ' . fecha($d) . ' al ' . fecha($h);
}

/** Lo de la solapa Créditos y cobranzas. */
function panel_creditos(string $d, string $h): array
{
    $fin = date('Y-m-d', strtotime($h . ' +1 day'));          // para comparar DATETIME: < fin

    $sol = [];
    foreach (q('SELECT h.accion, COUNT(*) AS n, COALESCE(SUM(s.monto), 0) AS monto
                  FROM (SELECT DISTINCT id_solicitud, accion FROM solicitudes_historial WHERE fecha >= ? AND fecha < ?) h
                  JOIN solicitudes s ON s.id_solicitud = h.id_solicitud
                 GROUP BY h.accion', [$d, $fin]) as $r) {
        $sol[$r['accion']] = ['n' => (int) $r['n'], 'monto' => (float) $r['monto']];
    }
    $solAhora = q1("SELECT COALESCE(SUM(estado = 'PENDIENTE'), 0) AS pend, COALESCE(SUM(IF(estado = 'PENDIENTE', monto, 0)), 0) AS pend_monto,
                           COALESCE(SUM(estado = 'OBSERVADA'), 0) AS obs, COALESCE(SUM(IF(estado = 'OBSERVADA', monto, 0)), 0) AS obs_monto,
                           COALESCE(SUM(estado = 'APROBADA'), 0) AS apr, COALESCE(SUM(IF(estado = 'APROBADA', monto, 0)), 0) AS apr_monto
                      FROM solicitudes");
    $espera = q1('SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(MINUTE, enviado_el, resuelto_el)) AS minutos
                    FROM solicitudes WHERE resuelto_el >= ? AND resuelto_el < ?', [$d, $fin]);
    $solVend = q("SELECT COALESCE(v.nombre, 'Sin vendedor') AS vendedor, COUNT(*) AS n, SUM(s.monto) AS monto,
                         SUM(s.estado IN ('APROBADA', 'LIQUIDADA')) AS aprobadas, SUM(s.estado = 'LIQUIDADA') AS liquidadas
                    FROM solicitudes s LEFT JOIN vendedores v ON v.id_vendedor = s.id_vendedor
                   WHERE s.creado_el >= ? AND s.creado_el < ?
                   GROUP BY s.id_vendedor, v.nombre ORDER BY monto DESC LIMIT 15", [$d, $fin]);

    $liq = q1("SELECT COUNT(*) AS n, COALESCE(SUM(capital), 0) AS capital, COALESCE(SUM(neto_desembolso), 0) AS neto,
                      COALESCE(SUM(gastos_otorg), 0) AS gastos, COALESCE(SUM(total_intereses), 0) AS intereses,
                      COALESCE(SUM(comision_monto), 0) AS comision
                 FROM prestamos WHERE fecha_liquidacion BETWEEN ? AND ? AND estado <> 'ANULADO'", [$d, $h]);
    $liqProd = q("SELECT ta.nombre AS tipo_asociado, COUNT(*) AS n, SUM(p.capital) AS capital, SUM(p.neto_desembolso) AS neto
                    FROM prestamos p JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
                   WHERE p.fecha_liquidacion BETWEEN ? AND ? AND p.estado <> 'ANULADO'
                   GROUP BY ta.id_tipo_asociado, ta.nombre ORDER BY capital DESC", [$d, $h]);
    $liqLista = q("SELECT p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, c.denominacion, ta.nombre AS tipo_asociado,
                          v.nombre AS vendedor, p.capital, p.neto_desembolso, p.plazo, p.valor_cuota
                     FROM prestamos p
                     JOIN clientes c ON c.id_cliente = p.id_cliente
                     JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
                     LEFT JOIN vendedores v ON v.id_vendedor = p.id_vendedor
                    WHERE p.fecha_liquidacion BETWEEN ? AND ? AND p.estado <> 'ANULADO'
                    ORDER BY p.fecha_liquidacion DESC, p.id_prestamo DESC LIMIT " . PANEL_LISTA_MAX, [$d, $h]);

    $cob = q1("SELECT COALESCE(SUM(estado = 'APLICADO'), 0) AS n, COALESCE(SUM(IF(estado = 'APLICADO', importe, 0)), 0) AS total,
                      COALESCE(SUM(estado = 'APLICADO' AND modo = 'CANCELACION'), 0) AS cancelaciones,
                      COALESCE(SUM(estado = 'ANULADO'), 0) AS anulados, COALESCE(SUM(IF(estado = 'ANULADO', importe, 0)), 0) AS anulado
                 FROM pagos WHERE fecha BETWEEN ? AND ?", [$d, $h]);
    $cobConcepto = [];
    foreach (q("SELECT i.concepto, SUM(i.importe) AS importe FROM pagos g JOIN pagos_imputacion i ON i.id_pago = g.id_pago
                 WHERE g.fecha BETWEEN ? AND ? AND g.estado = 'APLICADO' GROUP BY i.concepto", [$d, $h]) as $r) {
        $cobConcepto[$r['concepto']] = (float) $r['importe'];
    }
    $cobMedio = q("SELECT medio, COUNT(*) AS n, SUM(importe) AS total FROM pagos
                    WHERE fecha BETWEEN ? AND ? AND estado = 'APLICADO' GROUP BY medio ORDER BY total DESC", [$d, $h]);

    // Foto de hoy: cartera vigente (capital que falta cobrar) y mora.
    $cartera = q1("SELECT COUNT(DISTINCT p.id_prestamo) AS n, COALESCE(SUM(s.capital_pend), 0) AS capital
                     FROM prestamos p JOIN v_cuotas_saldo s ON s.id_prestamo = p.id_prestamo WHERE p.estado = 'VIGENTE'");
    $mora = q1('SELECT COUNT(*) AS n, COALESCE(SUM(deuda_vencida), 0) AS deuda FROM v_mora_prestamos WHERE dias_atraso > 0');

    return compact('sol', 'solAhora', 'espera', 'solVend', 'liq', 'liqProd', 'liqLista', 'cob', 'cobConcepto', 'cobMedio',
                   'cartera', 'mora') + ['serie' => panel_serie($d, $h)];
}

/**
 * Liquidado (capital) y cobrado por día, o por mes si el período es largo:
 * [['clave' => '2026-11-20', 'rotulo' => '20/11', 'liquidado' => x, 'cobrado' => y], …]
 */
function panel_serie(string $d, string $h): array
{
    $dias = (int) ((strtotime($h) - strtotime($d)) / 86400) + 1;
    $porMes = $dias > PANEL_DIAS_DIARIO;
    $grupo = $porMes ? "DATE_FORMAT(%s, '%%Y-%%m')" : '%s';
    $liq = array_column(q('SELECT ' . sprintf($grupo, 'fecha_liquidacion') . " AS k, SUM(capital) AS v FROM prestamos
                             WHERE fecha_liquidacion BETWEEN ? AND ? AND estado <> 'ANULADO' GROUP BY k", [$d, $h]), 'v', 'k');
    $cob = array_column(q('SELECT ' . sprintf($grupo, 'fecha') . " AS k, SUM(importe) AS v FROM pagos
                             WHERE fecha BETWEEN ? AND ? AND estado = 'APLICADO' GROUP BY k", [$d, $h]), 'v', 'k');
    static $meses = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $out = [];
    for ($t = strtotime($porMes ? date('Y-m-01', strtotime($d)) : $d); $t <= strtotime($h); $t = strtotime($porMes ? '+1 month' : '+1 day', $t)) {
        $k = date($porMes ? 'Y-m' : 'Y-m-d', $t);
        $out[] = ['clave' => $k,
                  'rotulo' => $porMes ? $meses[(int) date('n', $t)] . ' ' . date('y', $t) : date('d/m', $t),
                  'liquidado' => (float) ($liq[$k] ?? 0), 'cobrado' => (float) ($cob[$k] ?? 0)];
    }
    return ['por_mes' => $porMes, 'puntos' => $out];
}

/** Lo de la solapa Inversiones. Los importes van por moneda: ['ARS' => …, 'USD' => …]. */
function panel_inversiones(string $d, string $h): array
{
    $porMoneda = function (array $filas): array {
        $out = [];
        foreach ($filas as $f) {
            $out[$f['moneda']] = $f;
        }
        return $out;
    };
    $aportes = q("SELECT moneda, COUNT(*) AS n, SUM(capital) AS capital,
                         SUM(id_inversion_origen IS NOT NULL) AS renov_n, COALESCE(SUM(IF(id_inversion_origen IS NOT NULL, capital, 0)), 0) AS renov_capital
                    FROM inversiones WHERE fecha_inicio BETWEEN ? AND ? AND estado <> 'ANULADA' GROUP BY moneda", [$d, $h]);
    $liq = q("SELECT moneda, COUNT(*) AS n, SUM(capital) AS capital, SUM(interes) AS interes, SUM(total) AS total,
                     SUM(reinvertido) AS reinvertido, SUM(a_pagar) AS a_pagar
                FROM inversiones_liquidaciones WHERE fecha BETWEEN ? AND ? AND estado <> 'ANULADA' GROUP BY moneda", [$d, $h]);
    $pagos = q("SELECT moneda, COUNT(*) AS n, SUM(a_pagar) AS pagado FROM inversiones_liquidaciones
                 WHERE pagado_el BETWEEN ? AND ? AND estado = 'PAGADA' GROUP BY moneda", [$d, $h]);
    $vigentes = q("SELECT i.moneda, COUNT(*) AS n, SUM(i.capital) AS capital, COALESCE(SUM(dv.interes_devengado), 0) AS devengado
                     FROM inversiones i LEFT JOIN v_inversiones_devengado dv ON dv.id_inversion = i.id_inversion
                    WHERE i.estado = 'VIGENTE' GROUP BY i.moneda");
    $aPagar = q("SELECT moneda, COUNT(*) AS n, SUM(a_pagar) AS a_pagar FROM inversiones_liquidaciones WHERE estado = 'A_PAGAR' GROUP BY moneda");

    $aportesLista = q("SELECT i.id_inversion, i.nro_inversion, i.fecha_inicio, i.fecha_vencimiento, i.modalidad, i.moneda, i.capital,
                              i.tna, i.pct_participacion, i.id_inversion_origen, v.denominacion
                         FROM inversiones i JOIN inversores v ON v.id_inversor = i.id_inversor
                        WHERE i.fecha_inicio BETWEEN ? AND ? AND i.estado <> 'ANULADA'
                        ORDER BY i.fecha_inicio DESC, i.id_inversion DESC LIMIT " . PANEL_LISTA_MAX, [$d, $h]);
    $liqLista = q("SELECT l.id_liquidacion, l.id_inversion, l.fecha, l.moneda, l.capital, l.interes, l.reinvertido, l.a_pagar,
                          l.estado, l.anticipada, i.nro_inversion, v.denominacion
                     FROM inversiones_liquidaciones l
                     JOIN inversiones i ON i.id_inversion = l.id_inversion
                     JOIN inversores v ON v.id_inversor = i.id_inversor
                    WHERE l.fecha BETWEEN ? AND ? AND l.estado <> 'ANULADA'
                    ORDER BY l.fecha DESC, l.id_liquidacion DESC LIMIT " . PANEL_LISTA_MAX, [$d, $h]);
    $vencen = q("SELECT i.id_inversion, i.nro_inversion, i.fecha_vencimiento, i.modalidad, i.moneda, i.capital, v.denominacion,
                        COALESCE(dv.interes_al_vencimiento, 0) AS interes
                   FROM inversiones i JOIN inversores v ON v.id_inversor = i.id_inversor
                   LEFT JOIN v_inversiones_devengado dv ON dv.id_inversion = i.id_inversion
                  WHERE i.fecha_vencimiento BETWEEN ? AND ? AND i.estado = 'VIGENTE'
                  ORDER BY i.fecha_vencimiento, i.id_inversion LIMIT " . PANEL_LISTA_MAX, [$d, $h]);

    return ['aportes' => $porMoneda($aportes), 'liq' => $porMoneda($liq), 'pagos' => $porMoneda($pagos),
            'vigentes' => $porMoneda($vigentes), 'aPagar' => $porMoneda($aPagar),
            'aportesLista' => $aportesLista, 'liqLista' => $liqLista, 'vencen' => $vencen,
            'cotUsd' => cotizacion_segura('USD')];
}

/** «$ 1.000,00 · US$ 500,00» de un campo, por moneda; «—» si no hay nada. */
function panel_monedas(array $porMoneda, string $campo): string
{
    $partes = [];
    foreach (['ARS', 'USD'] as $m) {
        if (isset($porMoneda[$m]) && (float) $porMoneda[$m][$campo] != 0.0) {
            $partes[] = dinero($porMoneda[$m][$campo], $m);
        }
    }
    return $partes ? implode(' · ', $partes) : '—';
}

/** «1 pago», «3 pagos». */
function panel_cant($n, string $uno, string $varios): string
{
    return num((int) $n) . ' ' . ((int) $n === 1 ? $uno : $varios);
}

/** Suma de la cantidad (n) de todas las monedas. */
function panel_n(array $porMoneda, string $campo = 'n'): int
{
    return (int) array_sum(array_map(function ($f) use ($campo) { return (int) $f[$campo]; }, $porMoneda));
}

/** «2 h 15 min», «1 día 3 h», «25 min». */
function panel_duracion(?float $min): string
{
    if ($min === null) {
        return '—';
    }
    $min = (int) round($min);
    if ($min < 60) {
        return $min . ' min';
    }
    $h = intdiv($min, 60);
    if ($h < 24) {
        return $h . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '');
    }
    $d = intdiv($h, 24);
    return $d . ($d === 1 ? ' día' : ' días') . ($h % 24 ? ' ' . ($h % 24) . ' h' : '');
}

/**
 * Gráfico de barras agrupadas (SVG): liquidado y cobrado por día o por mes.
 * Dos series, una escala ($). Cada columna tiene una zona de hover del alto
 * entero con el detalle (data-*), que muestra el tooltip de panel.php.
 */
function panel_grafico_svg(array $serie): string
{
    $pts = $serie['puntos'];
    $n = count($pts);
    if ($n < 2) {
        return '';
    }
    $W = 1000;
    $H = 240;
    $izq = 64;
    $der = 10;
    $arr = 12;
    $abj = 28;
    $max = 0.0;
    foreach ($pts as $p) {
        $max = max($max, $p['liquidado'], $p['cobrado']);
    }
    // Escala «redonda»: 1, 2, 2.5 o 5 por potencia de 10, en 4 divisiones
    $paso = 1.0;
    if ($max > 0) {
        $bruto = $max / 4;
        $pot = pow(10, floor(log10($bruto)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pot >= $bruto) {
                $paso = $m * $pot;
                break;
            }
        }
    }
    $tope = $paso * 4;
    $altoUtil = $H - $arr - $abj;
    $anchoCol = ($W - $izq - $der) / $n;
    $barra = max(2.0, min(26.0, ($anchoCol - 6) / 2 - 1));
    $y = function (float $v) use ($tope, $altoUtil, $arr, $H, $abj) { return $H - $abj - ($tope > 0 ? $v / $tope * $altoUtil : 0); };
    $corto = function (float $v): string {
        if ($v >= 1e9) {
            return '$ ' . number_format($v / 1e9, $v >= 1e10 ? 0 : 1, ',', '.') . ' mil M';
        }
        if ($v >= 1e6) {
            return '$ ' . number_format($v / 1e6, $v >= 1e7 ? 0 : 1, ',', '.') . ' M';
        }
        if ($v >= 1e3) {
            return '$ ' . number_format($v / 1e3, 0, ',', '.') . ' mil';
        }
        return '$ ' . number_format($v, 0, ',', '.');
    };
    // Barra con la punta de datos redondeada (4px arriba) y la base recta sobre el eje
    $rect = function (float $x, float $v, string $clase) use ($y, $barra, $H, $abj): string {
        if ($v <= 0) {
            return '';
        }
        $top = $y($v);
        $base = $H - $abj;
        $alto = $base - $top;
        $r = min(4.0, $barra / 2, $alto);
        return sprintf('<path class="%s" d="M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z"/>',
            $clase, $x, $base, $top + $r, $x, $top, $x + $r, $top, $x + $barra - $r, $x + $barra, $top, $x + $barra, $top + $r, $base);
    };

    $svg = '<svg class="panel-grafico" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Liquidado y cobrado ' . ($serie['por_mes'] ? 'por mes' : 'por día') . '">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $paso * $i;
        $yy = $y($v);
        $svg .= sprintf('<line class="pg-grilla%s" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $i === 0 ? ' base' : '', $izq, $W - $der, $yy, $yy);
        $svg .= sprintf('<text class="pg-eje" x="%d" y="%.1f" text-anchor="end">%s</text>', $izq - 8, $yy + 4, h($i === 0 ? '0' : $corto($v)));
    }
    $cadaRotulo = (int) ceil($n / 16);                         // que no se pisen los rótulos
    foreach ($pts as $i => $p) {
        $x0 = $izq + $i * $anchoCol;
        $xm = $x0 + $anchoCol / 2;
        $svg .= $rect($xm - $barra - 1, $p['liquidado'], 'pg-liq');   // 2 px de aire entre las dos barras
        $svg .= $rect($xm + 1, $p['cobrado'], 'pg-cob');
        if ($i % $cadaRotulo === 0) {
            $svg .= sprintf('<text class="pg-eje" x="%.1f" y="%d" text-anchor="middle">%s</text>', $xm, $H - 8, h($p['rotulo']));
        }
        $svg .= sprintf('<rect class="pg-hit" x="%.1f" y="%d" width="%.1f" height="%d" data-rotulo="%s" data-liq="%s" data-cob="%s"/>',
            $x0, $arr, $anchoCol, $altoUtil, h($p['rotulo']), h(money($p['liquidado'])), h(money($p['cobrado'])));
    }
    return $svg . '</svg>';
}
