<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Inversores de capital: piezas comunes.
// El cálculo está en la base (financiera_inversores.sql):
//   sp_inversion_calcular   interés a una fecha (resumen + detalle)
//   sp_liquidar_inversion   liquida y renueva
//   v_inversiones_devengado devengado a hoy, para los listados
// ---------------------------------------------------------------------

require_once __DIR__ . '/prestamos.php';
require_once __DIR__ . '/cotizaciones.php';

const MONEDAS = ['ARS' => 'Pesos', 'USD' => 'Dólares'];
const MODALIDADES = ['TASA_FIJA' => 'Tasa fija', 'PARTICIPACION' => 'Participación'];
const ESTADOS_INVERSION = ['VIGENTE' => 'Vigente', 'LIQUIDADA' => 'Liquidada', 'ANULADA' => 'Anulada'];
const ESTADOS_INVERSION_TIPO = ['VIGENTE' => 'info', 'LIQUIDADA' => 'ok', 'ANULADA' => 'neutro'];
const ESTADOS_LIQ_INV = ['A_PAGAR' => 'A pagar', 'PAGADA' => 'Pagada', 'ANULADA' => 'Anulada'];
const ESTADOS_LIQ_INV_TIPO = ['A_PAGAR' => 'warn', 'PAGADA' => 'ok', 'ANULADA' => 'neutro'];
const RENOVACIONES = ['NO' => 'No se renueva: se paga todo', 'CAPITAL' => 'Se renueva el capital y se pagan los intereses',
                      'TOTAL' => 'Se renueva capital + intereses (no se paga nada)'];
const MEDIOS_PAGO_INV = ['TRANSFERENCIA' => 'Transferencia', 'EFECTIVO' => 'Efectivo', 'CHEQUE' => 'Cheque', 'OTRO' => 'Otro'];

/** Importe con su moneda: $ 1.234,56 o US$ 1.234,56 */
function dinero($n, string $moneda): string
{
    return $moneda === 'USD' ? 'US$ ' . number_format((float) $n, 2, ',', '.') : money($n);
}

/**
 * Equivalente en pesos de un importe en moneda extranjera: «≈ $ 1.234.567,89».
 * '' si ya está en pesos; «sin cotización» si no hay.
 */
function equiv_pesos($monto, string $moneda, $cotizacion): string
{
    if ($moneda === 'ARS') {
        return '';
    }
    return $cotizacion ? '≈ ' . money((float) $monto * (float) $cotizacion) : 'sin cotización';
}

/** Cotización (pesos por unidad) sin romper si falta la tabla. */
function cotizacion_segura(string $moneda, ?string $fecha = null): ?float
{
    try {
        return cotizacion($moneda, $fecha);
    } catch (Throwable $e) {
        return null;
    }
}

/** «BCRA 19/11/2026: $ 1.818,50» de la cotización usada. */
function cot_txt(string $moneda, ?float $cot, ?string $fecha): string
{
    if ($moneda === 'ARS') {
        return '';
    }
    return $cot ? 'cotización BCRA' . ($fecha ? ' del ' . fecha($fecha) : '') . ': $ ' . number_format($cot, 2, ',', '.') : 'sin cotización del BCRA cargada';
}

function estado_inversion(string $e): string
{
    return estado(ESTADOS_INVERSION[$e] ?? $e, ESTADOS_INVERSION_TIPO[$e] ?? 'neutro');
}

function estado_liq_inv(string $e): string
{
    return estado(ESTADOS_LIQ_INV[$e] ?? $e, ESTADOS_LIQ_INV_TIPO[$e] ?? 'neutro');
}

/** Condición del aporte en texto: «TNA 40,00 %» o «50,00 % del interés cobrado». */
function condicion_inversion(array $i): string
{
    return $i['modalidad'] === 'TASA_FIJA' ? 'TNA ' . pct($i['tna']) : pct($i['pct_participacion']) . ' del interés cobrado';
}

/** Interés a una fecha: [resumen, detalle por préstamo]. */
function inversion_calcular(int $id, string $fecha): array
{
    $r = sp_resultados('CALL sp_inversion_calcular(?, ?)', [$id, $fecha]);
    return [$r[0][0] ?? null, $r[1] ?? []];
}

/**
 * Préstamos vigentes en pesos con capital libre para fondear: capital menos
 * lo que ya fondean otros aportes vigentes (sin contar $sinInversion).
 */
function prestamos_para_fondear(int $sinInversion = 0): array
{
    return q("SELECT p.id_prestamo, p.nro_prestamo, p.capital, c.denominacion,
                     p.capital - COALESCE((SELECT SUM(ip.monto) FROM inversiones_prestamos ip
                                             JOIN inversiones i ON i.id_inversion = ip.id_inversion AND i.estado = 'VIGENTE'
                                            WHERE ip.id_prestamo = p.id_prestamo AND ip.id_inversion <> ?), 0) AS libre
                FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
               WHERE p.estado = 'VIGENTE'
              HAVING libre > 0
               ORDER BY p.nro_prestamo", [$sinInversion]);
}
