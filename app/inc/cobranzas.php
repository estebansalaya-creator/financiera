<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Cobranzas: piezas comunes de cobrar, recibo, listado y lotes.
// Todo el cálculo está en la base (financiera_cobranzas.sql):
//   sp_estado_cuenta   deuda a una fecha (NORMAL) o monto de cancelación
//   sp_registrar_pago  devenga punitorios, imputa y emite el recibo
//   sp_anular_pago     solo el último pago aplicado de cada préstamo
// ---------------------------------------------------------------------

require_once __DIR__ . '/prestamos.php';

const MEDIOS_PAGO = ['EFECTIVO' => 'Efectivo', 'TRANSFERENCIA' => 'Transferencia', 'DEBITO' => 'Débito',
                     'RAPIPAGO' => 'Rapipago', 'PAGOFACIL' => 'Pago Fácil', 'MERCADOPAGO' => 'Mercado Pago',
                     'CHEQUE' => 'Cheque', 'OTRO' => 'Otro'];
const CONCEPTOS_PAGO = ['CAPITAL' => 'Capital', 'INTERES' => 'Interés', 'IVA_INTERES' => 'IVA interés',
                        'PUNITORIO' => 'Punitorio', 'IVA_PUNITORIO' => 'IVA punitorio'];

/**
 * Estado de cuenta de un préstamo a una fecha.
 * Devuelve ['cuotas' => detalle por cuota, 'tot' => totales].
 */
function estado_cuenta(int $idPrestamo, string $fecha, string $modo = 'NORMAL'): array
{
    $r = sp_resultados('CALL sp_estado_cuenta(?, ?, ?)', [$idPrestamo, $fecha, $modo]);
    $tot = $r[1][0] ?? [];
    foreach (['deuda_vencida', 'deuda_a_vencer', 'total', 'interes_condonado', 'credito_interes_pagado'] as $k) {
        $tot[$k] = (float) ($tot[$k] ?? 0);
    }
    return ['cuotas' => $r[0] ?? [], 'tot' => $tot];
}

/**
 * Registra un pago con sp_registrar_pago. Devuelve el id del pago.
 * Lanza la excepción de la base (sus mensajes ya son para la persona).
 */
function registrar_pago(int $idPrestamo, string $fecha, float $importe, string $medio, ?string $referencia,
                        string $modo = 'NORMAL', ?int $idLote = null): int
{
    $st = db()->prepare('CALL sp_registrar_pago(?, ?, ?, ?, ?, ?, ?, ?, @id_pago)');
    $st->execute([$idPrestamo, $fecha, round($importe, 2), $medio, $referencia ?: null, $modo, $idLote, usuario_id()]);
    do {
        try {
            $st->fetchAll();
        } catch (PDOException $e) {
        }
    } while ($st->nextRowset());
    $st->closeCursor();
    return (int) q1('SELECT @id_pago AS id')['id'];
}

/** Préstamos que coinciden con lo buscado (N°, CUIT, DNI o nombre), los vigentes primero. */
function buscar_prestamos(string $txt, int $limite = 20): array
{
    $txt = trim($txt);
    if ($txt === '') {
        return [];
    }
    $base = "SELECT p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, p.capital, p.plazo, p.valor_cuota, p.estado,
                    c.id_cliente, c.denominacion, c.cuit, pr.nombre AS producto
               FROM prestamos p
               JOIN clientes c ON c.id_cliente = p.id_cliente
               LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
               JOIN productos pr ON pr.id_producto = p.id_producto
              WHERE ";
    $orden = " ORDER BY p.estado = 'VIGENTE' DESC, p.fecha_liquidacion DESC LIMIT " . (int) $limite;
    $d = preg_replace('/\D/', '', $txt);
    $exacto = q($base . 'p.nro_prestamo = ?' . $orden, [$txt]);
    if ($exacto) {
        return $exacto;
    }
    if (preg_match('/^[\d\s.\-]+$/', $txt) && strlen($d) === 11) {
        return q($base . 'c.cuit = ?' . $orden, [$d]);
    }
    if (preg_match('/^[\d\s.\-]+$/', $txt) && strlen($d) >= 7 && strlen($d) <= 8) {
        return q($base . 'cp.dni = ?' . $orden, [$d]);
    }
    $like = '%' . addcslashes($txt, '%_\\') . '%';
    return q($base . '(p.nro_prestamo LIKE ? OR c.denominacion LIKE ?)' . $orden, [$like, $like]);
}

/** Tipos de movimiento como se muestran. */
const TIPOS_MOVIMIENTO = ['DESEMBOLSO' => 'Desembolso', 'GASTO_OTORG' => 'Gastos de otorgamiento', 'PAGO' => 'Pago',
                          'ANULACION_PAGO' => 'Anulación de pago', 'PUNITORIO' => 'Punitorio', 'IVA_PUNITORIO' => 'IVA punitorio',
                          'QUITA_INTERES' => 'Quita de interés', 'QUITA_IVA' => 'Quita de IVA',
                          'CREDITO_CAPITAL' => 'Interés adelantado a capital', 'ANULACION' => 'Anulación', 'AJUSTE' => 'Ajuste'];

/** 1234.5 -> "1.234,50" (para poner en un campo de importe) */
function monto_campo_txt(float $n): string
{
    return number_format($n, 2, ',', '.');
}

/** Último pago aplicado del préstamo (el único que se puede anular). */
function ultimo_pago_aplicado(int $idPrestamo): ?int
{
    $f = q1("SELECT id_pago FROM pagos WHERE id_prestamo = ? AND estado = 'APLICADO' ORDER BY fecha DESC, id_pago DESC LIMIT 1", [$idPrestamo]);
    return $f ? (int) $f['id_pago'] : null;
}
