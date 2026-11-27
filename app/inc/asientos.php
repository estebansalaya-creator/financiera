<?php
declare(strict_types=1);
// ---------------------------------------------------------------------
// Motor de asientos: los asientos que generan las operaciones de un
// período. El criterio del libro de trabajo de gestion_adm
// (libro_trabajo.py), con una diferencia: acá existe c_asientos_origen,
// así que lo derivado se puede grabar sin duplicar.
//
//   ctb_derivar($desde, $hasta)   arma los asientos del período (no graba)
//   ctb_generar($desde, $hasta)   graba los que están listos y no se
//                                 generaron todavía, y renumera el
//                                 ejercicio en orden de fecha
//                                 (ctb_renumerar: lo de períodos
//                                 cerrados no se toca)
//
// DE DÓNDE SALE CADA CUENTA (nada se inventa)
//   fondos            cuentas_fondos.id_cuenta_contable
//   gasto cobrado     gastos_conceptos.id_cuenta_contable
//   lo demás          c_imputaciones_config por clase (y subtipo: el código
//                     del tipo de asociado o la moneda)
//
// LAS OPERACIONES (tipo_origen en c_asientos_origen)
//   LIQUIDACION    préstamo liquidado: Deudores (capital + gastos
//                  financiados) a Préstamos a desembolsar (neto), gastos
//                  (cada uno a su cuenta) e IVA débito
//   PAGO_OP        pago de la orden del desembolso: Préstamos a
//                  desembolsar a Fondos
//   DESEMBOLSO     desembolso directo (sin orden de pago, el flujo viejo)
//   RECIBO         cobro: Fondos a Deudores (capital), Intereses,
//                  Punitorios, IVA débito y Cuota social, según la
//                  imputación real del cobro (pagos_imputacion)
//   APORTE         aporte de un inversor: Fondos a Aportes de inversores
//   LIQ_INVERSOR   liquidación de un aporte: Intereses a inversores
//                  (egreso) a Intereses a pagar (o a Aportes, si se
//                  renovó con los intereses)
//   PAGO_INVERSOR  pago de esa liquidación: Aportes (capital) e Intereses
//                  a pagar a Fondos
//   MOVIMIENTO     entre cuentas propias: Fondos destino a Fondos origen
//   COMISION       comisión devengada: Comisiones a Comisiones a pagar
//                  (una anulación o un clawback, al revés)
//   BANCARIO       gasto bancario (comisiones, impuestos, percepciones):
//                  cada concepto a su cuenta, a Fondos; un ingreso
//                  bancario, al revés (conceptos_tesoreria)
//
// CRITERIO DE INTERESES: PERCIBIDO. El interés y el punitorio se
// reconocen al cobrarlos (lo que dice pagos_imputacion). Por eso la quita
// de una cancelación anticipada no genera asiento: ese interés nunca se
// reconoció. Si el contador pide devengado, se suma el asiento mensual de
// devengamiento y la quita (clase QUITA). A confirmar con el estudio.
//
// ANULACIONES: el asiento original queda en la fecha de la operación y la
// anulación genera el contraasiento (rol ANULACION) en la fecha en que se
// anuló.
//
// MONEDA: los asientos van en pesos. Lo que está en dólares se convierte
// con la cotización del BCRA de la fecha (fn_cotizacion); la liquidación
// de un aporte usa la cotización que guardó. Sin cotización, aviso.
//
// LO QUE NO SE PUEDE IMPUTAR SE INFORMA: un renglón sin cuenta sale con su
// aviso y ese asiento no se emite (criterio de gestion_adm).
// ---------------------------------------------------------------------

require_once __DIR__ . '/contable.php';

const CTB_ORIGENES = [
    'LIQUIDACION'   => 'Liquidación de préstamo',
    'PAGO_OP'       => 'Pago de orden (desembolso)',
    'DESEMBOLSO'    => 'Desembolso',
    'RECIBO'        => 'Cobranza',
    'APORTE'        => 'Aporte de inversor',
    'LIQ_INVERSOR'  => 'Liquidación de aporte',
    'PAGO_INVERSOR' => 'Pago a inversor',
    'MOVIMIENTO'    => 'Movimiento entre cuentas',
    'COMISION'      => 'Comisión',
    'BANCARIO'      => 'Gasto o ingreso bancario',
];

/** Un asiento en armado. */
final class CtbAsiento
{
    public string $tipo;
    public int $id;
    public string $rol = 'ORIGINAL';
    public string $fecha;
    public string $descripcion;
    public string $referencia;
    /** @var array<int, array{cuenta:?int, debe:float, haber:float, detalle:string}> */
    public array $renglones = [];
    /** @var string[] */
    public array $avisos = [];
    public ?int $idAsiento = null;      // ya generado
    public ?int $numero = null;
    public ?int $idPeriodo = null;
    public string $periodoEstado = '';
    public ?string $cerradoDespues = null;

    public function __construct(string $tipo, int $id, string $fecha, string $descripcion, string $referencia = '')
    {
        $this->tipo = $tipo;
        $this->id = $id;
        $this->fecha = $fecha;
        $this->descripcion = mb_substr($descripcion, 0, 255);
        $this->referencia = mb_substr($referencia, 0, 80);
    }

    /** Suma un renglón. $cuenta null: falta la cuenta, $falta dice cuál. */
    public function linea(?int $cuenta, float $debe, float $haber, string $detalle, string $falta = ''): void
    {
        $debe = round($debe, 2);
        $haber = round($haber, 2);
        if (abs($debe) < 0.005 && abs($haber) < 0.005) {
            return;
        }
        if ($debe < 0) {                                   // un importe negativo va del otro lado
            $haber += -$debe;
            $debe = 0.0;
        }
        if ($haber < 0) {
            $debe += -$haber;
            $haber = 0.0;
        }
        if (!$cuenta) {
            $this->avisos[] = 'Falta la cuenta de ' . ($falta !== '' ? $falta : $detalle) . '.';
        }
        $this->renglones[] = ['cuenta' => $cuenta ?: null, 'debe' => $debe, 'haber' => $haber, 'detalle' => mb_substr($detalle, 0, 255)];
    }

    public function debe(): float
    {
        return round(array_sum(array_column($this->renglones, 'debe')), 2);
    }

    public function haber(): float
    {
        return round(array_sum(array_column($this->renglones, 'haber')), 2);
    }

    /** El contraasiento (anulación) en otra fecha. */
    public function invertido(string $fecha, string $motivo): self
    {
        $a = clone $this;
        $a->rol = 'ANULACION';
        $a->fecha = $fecha;
        $a->descripcion = mb_substr('Anulación: ' . $this->descripcion . ($motivo !== '' ? ' · ' . $motivo : ''), 0, 255);
        $a->renglones = array_map(fn ($r) => ['cuenta' => $r['cuenta'], 'debe' => $r['haber'], 'haber' => $r['debe'], 'detalle' => $r['detalle']], $this->renglones);
        $a->idAsiento = null;
        $a->numero = null;
        return $a;
    }

    /** Listo para grabar: con renglones, sin avisos y balanceado. */
    public function listo(): bool
    {
        return $this->renglones && !$this->avisos && abs($this->debe() - $this->haber()) < 0.005;
    }

    public function clave(): string
    {
        return $this->tipo . ':' . $this->id . ':' . $this->rol;
    }
}

// ---------------------------------------------------------------------
// Cuentas
// ---------------------------------------------------------------------

/** La cuenta de una imputación: [id|null, descripción de lo que falta]. */
function ctb_cta_clase(string $clase, string $lado, string $subtipo = ''): array
{
    static $cache = [];
    $k = $clase . '|' . $subtipo;
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = ctb_imputacion($clase, $subtipo);
    }
    $r = $cache[$k];
    $id = $r ? (int) ($r[$lado === 'debe' ? 'id_cuenta_debe' : 'id_cuenta_haber'] ?? 0) : 0;
    $txt = (CTB_CLASES[$clase][0] ?? $clase) . ' (' . $lado . ($subtipo !== '' ? ', ' . $subtipo : '') . ') en Imputaciones contables';
    return [$id ?: null, $txt];
}

/** La cuenta contable de una cuenta de fondos: [id|null, nombre de la cuenta de fondos, moneda]. */
function ctb_cta_fondos(int $idCuentaFondos): array
{
    static $cache = [];
    if (!isset($cache[$idCuentaFondos])) {
        $c = q1('SELECT nombre, moneda, id_cuenta_contable FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$idCuentaFondos]);
        $cache[$idCuentaFondos] = [$c && $c['id_cuenta_contable'] ? (int) $c['id_cuenta_contable'] : null, $c['nombre'] ?? '#' . $idCuentaFondos, $c['moneda'] ?? 'ARS'];
    }
    return $cache[$idCuentaFondos];
}

/** Pesos por unidad de la moneda a una fecha (1 en pesos, null si no hay cotización). */
function ctb_cotizacion(string $moneda, string $fecha): ?float
{
    if ($moneda === 'ARS' || $moneda === '') {
        return 1.0;
    }
    static $cache = [];
    $k = $moneda . $fecha;
    if (!array_key_exists($k, $cache)) {
        $r = q1('SELECT fn_cotizacion(?, ?) AS c', [$moneda, $fecha]);
        $cache[$k] = $r && $r['c'] !== null && (float) $r['c'] > 0 ? (float) $r['c'] : null;
    }
    return $cache[$k];
}

/** El código del tipo de asociado de un préstamo (el subtipo de sus imputaciones). */
function ctb_tipo_prestamo(int $idPrestamo): string
{
    static $cache = [];
    if (!isset($cache[$idPrestamo])) {
        $r = q1('SELECT t.codigo FROM prestamos p LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = p.id_tipo_asociado WHERE p.id_prestamo = ?', [$idPrestamo]);
        $cache[$idPrestamo] = (string) ($r['codigo'] ?? '');
    }
    return $cache[$idPrestamo];
}

/** Renglón de fondos (con la conversión a pesos). */
function ctb_linea_fondos(CtbAsiento $a, int $idCf, float $debe, float $haber, string $fecha, string $detalle = ''): void
{
    [$cta, $nombre, $mon] = ctb_cta_fondos($idCf);
    $cot = ctb_cotizacion($mon, $fecha);
    if ($cot === null) {
        $a->avisos[] = 'No hay cotización del ' . $mon . ' para el ' . fecha($fecha) . ' (Tablas y parámetros › Cotizaciones).';
        $cot = 0.0;
    }
    $a->linea($cta, $debe * $cot, $haber * $cot, $nombre . ($detalle !== '' ? ' · ' . $detalle : '') . ($mon !== 'ARS' ? ' (' . $mon . ' ' . num($debe + $haber, 2) . ')' : ''),
              'la cuenta de fondos «' . $nombre . '» (Tesorería › Cuentas de fondos)');
}

// ---------------------------------------------------------------------
// Las operaciones
// ---------------------------------------------------------------------

/** Préstamo liquidado (sin la anulación). */
function ctb_asiento_liquidacion(array $p): CtbAsiento
{
    $tipo = ctb_tipo_prestamo((int) $p['id_prestamo']);
    $a = new CtbAsiento('LIQUIDACION', (int) $p['id_prestamo'], $p['fecha_liquidacion'],
                        'Liquidación del préstamo ' . $p['nro_prestamo'] . ' · ' . $p['cliente'], $p['nro_prestamo']);
    [$deu, $fd] = ctb_cta_clase('LIQUIDACION', 'debe', $tipo);
    [$ades, $fa] = ctb_cta_clase('LIQUIDACION', 'haber', $tipo);
    $a->linea($deu, (float) $p['capital'] + (float) ($p['gastos_financiados'] ?? 0), 0, 'Deudores por préstamos', $fd);
    $a->linea($ades, 0, (float) $p['neto_desembolso'], 'Neto a desembolsar', $fa);
    $gastos = [];
    try {
        $gastos = q('SELECT g.nombre, g.importe, g.iva, g.forma_cobro, c.id_cuenta_contable
                       FROM prestamos_gastos g LEFT JOIN gastos_conceptos c ON c.id_concepto_gasto = g.id_concepto_gasto
                      WHERE g.id_prestamo = ?', [(int) $p['id_prestamo']]);
    } catch (Throwable $e) {
    }
    [$iva, $fi] = ctb_cta_clase('IVA_DEBITO', 'haber');
    foreach ($gastos as $g) {
        $a->linea($g['id_cuenta_contable'] ? (int) $g['id_cuenta_contable'] : null, 0, (float) $g['importe'],
                  $g['nombre'] . ($g['forma_cobro'] === 'FINANCIADO' ? ' (financiado)' : ''),
                  'el concepto de gasto «' . $g['nombre'] . '» (Conceptos de gasto)');
        $a->linea($iva, 0, (float) $g['iva'], 'IVA ' . $g['nombre'], $fi);
    }
    // Préstamos de antes de los conceptos de gasto: el descuento en un solo número.
    if (!$gastos && (float) $p['gastos_otorg'] > 0) {
        $a->linea(null, 0, (float) $p['gastos_otorg'], 'Gastos de otorgamiento', 'los gastos de otorgamiento (el préstamo no tiene el detalle por concepto)');
    }
    return $a;
}

/** Cobro: un recibo (con sus pagos y la cuota social). */
function ctb_asiento_recibo(array $r, array $movs): CtbAsiento
{
    $a = new CtbAsiento('RECIBO', (int) $r['id_recibo'], $r['fecha'], 'Cobranza ' . $r['nro_recibo'] . ' · ' . $r['cliente'], (string) $r['nro_recibo']);
    $fondos = 0.0;
    foreach ($movs as $m) {
        ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $r['fecha'], $r['medio'] ?? '');
        $fondos += (float) $m['importe_debe'] - (float) $m['importe_haber'];
    }
    // Los pagos del recibo: los aplicados, o todos si se anuló el recibo entero
    // (el asiento original es el de antes de la anulación).
    $imp = q("SELECT p.id_pago, p.id_prestamo, pr.nro_prestamo, i.concepto, SUM(i.importe) AS importe
                FROM pagos p
                JOIN prestamos pr ON pr.id_prestamo = p.id_prestamo
                JOIN pagos_imputacion i ON i.id_pago = p.id_pago
               WHERE p.id_recibo = ? AND (p.estado = 'APLICADO' OR ? = 'ANULADO')
               GROUP BY p.id_pago, p.id_prestamo, pr.nro_prestamo, i.concepto
               ORDER BY p.id_pago, FIELD(i.concepto, 'CAPITAL', 'INTERES', 'IVA_INTERES', 'PUNITORIO', 'IVA_PUNITORIO')",
             [(int) $r['id_recibo'], $r['estado']]);
    $mapa = ['CAPITAL' => ['LIQUIDACION', 'debe', 'Capital'], 'INTERES' => ['INTERES', 'haber', 'Intereses'],
             'PUNITORIO' => ['PUNITORIO', 'haber', 'Punitorios'], 'IVA_INTERES' => ['IVA_DEBITO', 'haber', 'IVA intereses'],
             'IVA_PUNITORIO' => ['IVA_DEBITO', 'haber', 'IVA punitorios']];
    $total = 0.0;
    foreach ($imp as $i) {
        $m = $mapa[$i['concepto']] ?? null;
        $tipo = in_array($i['concepto'], ['IVA_INTERES', 'IVA_PUNITORIO'], true) ? '' : ctb_tipo_prestamo((int) $i['id_prestamo']);
        [$cta, $falta] = $m ? ctb_cta_clase($m[0], $m[1], $tipo) : [null, 'el concepto ' . $i['concepto']];
        $a->linea($cta, 0, (float) $i['importe'], ($m[2] ?? $i['concepto']) . ' ' . $i['nro_prestamo'], $falta);
        $total += (float) $i['importe'];
    }
    // Cuota social cobrada en el mismo recibo.
    try {
        $cs = q('SELECT SUM(importe) AS importe FROM cuotas_sociales_pagos WHERE id_recibo = ? AND (anulado_el IS NULL OR ? = \'ANULADO\')',
                [(int) $r['id_recibo'], $r['estado']]);
        if ($cs && (float) $cs[0]['importe'] > 0) {
            [$cta, $falta] = ctb_cta_clase('CUOTA_SOCIAL', 'haber');
            $a->linea($cta, 0, (float) $cs[0]['importe'], 'Cuota social', $falta);
            $total += (float) $cs[0]['importe'];
        }
    } catch (Throwable $e) {
    }
    if (!$movs) {
        $a->avisos[] = 'El recibo no tiene movimiento de fondos.';
    } elseif (abs(round($fondos - $total, 2)) >= 0.01 && ctb_cta_fondos((int) $movs[0]['id_cuenta_fondos'])[2] === 'ARS') {
        $a->avisos[] = 'Lo cobrado (' . money($fondos) . ') no coincide con lo imputado a préstamos y cuota social (' . money($total) . ').';
    }
    return $a;
}

/** Pago de una orden (el desembolso de un préstamo) o desembolso directo. */
function ctb_asiento_pago_desembolso(string $tipoOrigen, int $idOrigen, array $m, ?array $prestamo, string $clase): CtbAsiento
{
    $nro = $prestamo['nro_prestamo'] ?? '';
    $a = new CtbAsiento($tipoOrigen, $idOrigen, $m['fecha'], ($tipoOrigen === 'PAGO_OP' ? 'Pago ' . ($m['nro_pago'] ?? '') . ' · ' : 'Desembolso · ')
                        . ($prestamo ? 'préstamo ' . $nro . ' · ' . $prestamo['cliente'] : ($m['concepto'] ?? '')), $nro ?: (string) ($m['nro_pago'] ?? ''));
    if ($clase !== 'PRESTAMO' || !$prestamo) {
        $a->avisos[] = 'Orden de pago de clase ' . $clase . ': todavía no tiene imputación contable.';
    }
    [$ades, $fa] = ctb_cta_clase('LIQUIDACION', 'haber', $prestamo ? ctb_tipo_prestamo((int) $prestamo['id_prestamo']) : '');
    $importe = (float) $m['importe_haber'] - (float) $m['importe_debe'];
    [, , $mon] = ctb_cta_fondos((int) $m['id_cuenta_fondos']);
    $cot = ctb_cotizacion($mon, $m['fecha']) ?? 0.0;
    $a->linea($ades, $importe * $cot, 0, 'Préstamo a desembolsar ' . $nro, $fa);
    ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $m['fecha']);
    return $a;
}

/** Aporte de un inversor. */
function ctb_asiento_aporte(array $m): CtbAsiento
{
    $a = new CtbAsiento('APORTE', (int) $m['id_inversion'], $m['fecha'], 'Aporte ' . $m['nro_inversion'] . ' · ' . $m['inversor'], (string) $m['nro_inversion']);
    ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $m['fecha']);
    [$cta, $falta] = ctb_cta_clase('APORTE', 'haber', $m['moneda']);
    $cot = ctb_cotizacion($m['moneda'], $m['fecha']) ?? 0.0;
    $a->linea($cta, 0, ((float) $m['importe_debe'] - (float) $m['importe_haber']) * $cot, 'Aportes de inversores ' . $m['moneda'], $falta);
    return $a;
}

/** Liquidación de un aporte: el interés ganado por el inversor. */
function ctb_asiento_liq_inversor(array $l): CtbAsiento
{
    $a = new CtbAsiento('LIQ_INVERSOR', (int) $l['id_liquidacion'], $l['fecha'],
                        'Liquidación del aporte ' . $l['nro_inversion'] . ' · ' . $l['inversor'], (string) $l['nro_inversion']);
    $cot = (float) ($l['cotizacion'] ?: (ctb_cotizacion($l['moneda'], $l['fecha']) ?? 0));
    if ($cot <= 0) {
        $a->avisos[] = 'No hay cotización del ' . $l['moneda'] . ' para el ' . fecha($l['fecha']) . '.';
    }
    $int = (float) $l['interes'] * $cot;
    [$eg, $fe] = ctb_cta_clase('INTERES_INVERSOR', 'debe', $l['moneda']);
    $a->linea($eg, $int, 0, 'Intereses a inversores', $fe);
    // Renovación con los intereses: pasan a ser capital del aporte nuevo.
    [$pas, $fp] = $l['renovacion'] === 'TOTAL' ? ctb_cta_clase('APORTE', 'haber', $l['moneda']) : ctb_cta_clase('INTERES_INVERSOR', 'haber', $l['moneda']);
    $a->linea($pas, 0, $int, $l['renovacion'] === 'TOTAL' ? 'Intereses capitalizados (renovación)' : 'Intereses a pagar', $fp);
    return $a;
}

/** Pago de una liquidación de aporte. */
function ctb_asiento_pago_inversor(array $m, array $l): CtbAsiento
{
    $a = new CtbAsiento('PAGO_INVERSOR', (int) $l['id_liquidacion'], $m['fecha'], 'Pago al inversor · ' . $l['nro_inversion'] . ' · ' . $l['inversor'], (string) $l['nro_inversion']);
    $cot = (float) ($l['cotizacion'] ?: (ctb_cotizacion($l['moneda'], $m['fecha']) ?? 0));
    $capPagado = max(0.0, (float) $l['capital'] - (float) $l['reinvertido']);
    $intPagado = max(0.0, (float) $l['a_pagar'] - $capPagado);
    [$cap, $fc] = ctb_cta_clase('APORTE', 'haber', $l['moneda']);
    [$int, $fi] = ctb_cta_clase('INTERES_INVERSOR', 'haber', $l['moneda']);
    $a->linea($cap, $capPagado * $cot, 0, 'Devolución de capital', $fc);
    $a->linea($int, $intPagado * $cot, 0, 'Intereses pagados', $fi);
    ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $m['fecha']);
    if ($l['moneda'] !== 'ARS' && abs($a->debe() - $a->haber()) >= 0.01) {
        $a->avisos[] = 'La diferencia de cambio entre la liquidación y el pago todavía no tiene cuenta.';
    }
    return $a;
}

/** Gasto o ingreso bancario (comprobantes_bancarios): cada concepto contra la cuenta de fondos. */
function ctb_asiento_bancario(array $m): CtbAsiento
{
    $c = q1('SELECT * FROM comprobantes_bancarios WHERE id_comp_banco = ?', [(int) $m['id_comp_banco']]);
    $gasto = ($c['tipo'] ?? 'GASTO') === 'GASTO';
    $a = new CtbAsiento('BANCARIO', (int) $m['id_comp_banco'], $m['fecha'],
                        ($gasto ? 'Gasto bancario ' : 'Ingreso bancario ') . ($c['nro'] ?? '') . ' · ' . ($c['concepto'] ?? ''), (string) ($c['nro'] ?? ''));
    foreach (q('SELECT t.nombre, t.id_cuenta_contable, SUM(d.importe) AS importe FROM comprobantes_bancarios_det d
                  JOIN conceptos_tesoreria t ON t.id_concepto_tes = d.id_concepto_tes
                 WHERE d.id_comp_banco = ? GROUP BY t.id_concepto_tes, t.nombre, t.id_cuenta_contable', [(int) $m['id_comp_banco']]) as $d) {
        $a->linea($d['id_cuenta_contable'] ? (int) $d['id_cuenta_contable'] : null, $gasto ? (float) $d['importe'] : 0, $gasto ? 0 : (float) $d['importe'],
                  $d['nombre'], 'el concepto de tesorería «' . $d['nombre'] . '» (Tesorería › Conceptos de tesorería)');
    }
    ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $m['fecha']);
    return $a;
}

/** Movimiento entre cuentas propias. */
function ctb_asiento_movimiento(array $mc, array $movs): CtbAsiento
{
    $a = new CtbAsiento('MOVIMIENTO', (int) $mc['id_mov_cuentas'], $mc['fecha'],
                        (TIPOS_MOV_CTB[$mc['tipo']] ?? 'Movimiento entre cuentas') . ($mc['referencia'] ? ' · ' . $mc['referencia'] : ''), (string) ($mc['referencia'] ?? ''));
    foreach ($movs as $m) {
        ctb_linea_fondos($a, (int) $m['id_cuenta_fondos'], (float) $m['importe_debe'], (float) $m['importe_haber'], $mc['fecha']);
    }
    return $a;
}
const TIPOS_MOV_CTB = ['ENTRE_CUENTAS' => 'Transferencia entre cuentas', 'DEPOSITO' => 'Depósito bancario',
                       'EXTRACCION' => 'Extracción bancaria', 'INVERSION' => 'Constitución de inversión', 'RESCATE' => 'Rescate de inversión'];

/** Comisión devengada (o su reversión). */
function ctb_asiento_comision(array $c): CtbAsiento
{
    $a = new CtbAsiento('COMISION', (int) $c['id_devengada'], $c['fecha'],
                        'Comisión ' . mb_strtolower($c['concepto']) . ' · ' . $c['vendedor'] . ' · préstamo ' . $c['nro_prestamo'], (string) $c['nro_prestamo']);
    [$eg, $fe] = ctb_cta_clase('COMISION', 'debe');
    [$pas, $fp] = ctb_cta_clase('COMISION', 'haber');
    $imp = (float) $c['importe'];
    $a->linea($eg, $imp, 0, 'Comisiones a comercializadores', $fe);
    $a->linea($pas, 0, $imp, 'Comisiones a pagar · ' . $c['vendedor'], $fp);
    return $a;
}

// ---------------------------------------------------------------------
// Derivar el período
// ---------------------------------------------------------------------

/**
 * Los asientos de las operaciones entre $desde y $hasta (y las
 * anulaciones de ese lapso), en orden de fecha. Marca los ya generados.
 * @return CtbAsiento[]
 */
function ctb_derivar(string $desde, string $hasta): array
{
    $out = [];
    $hasta1 = (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d');
    $enRango = fn (?string $f) => $f !== null && substr($f, 0, 10) >= $desde && substr($f, 0, 10) <= $hasta;

    // Préstamos liquidados y anulados
    foreach (q("SELECT p.*, c.denominacion AS cliente FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
                 WHERE (p.fecha_liquidacion BETWEEN ? AND ?) OR (p.anulado_el >= ? AND p.anulado_el < ?)",
               [$desde, $hasta, $desde, $hasta1]) as $p) {
        $a = ctb_asiento_liquidacion($p);
        if ($enRango($p['fecha_liquidacion'])) {
            $out[] = $a;
        }
        if ($p['anulado_el'] && $enRango($p['anulado_el'])) {
            $out[] = $a->invertido(substr($p['anulado_el'], 0, 10), (string) $p['motivo_anulacion']);
        }
    }

    // Movimientos de fondos del período (o anulados en el período)
    $movs = q("SELECT m.* FROM movimientos_fondos m
                WHERE (m.fecha BETWEEN ? AND ?) OR (m.anulado_el >= ? AND m.anulado_el < ?)
                ORDER BY m.fecha, m.id_mov_fondos", [$desde, $hasta, $desde, $hasta1]);
    $grupos = [];
    foreach ($movs as $m) {
        switch ($m['tipo_origen']) {
            case 'RECIBO':
                $grupos['RECIBO'][(int) $m['id_recibo']][] = $m;
                break;
            case 'MOVIMIENTO':
                $grupos['MOVIMIENTO'][(int) $m['id_mov_cuentas']][] = $m;
                break;
            default:
                $grupos[$m['tipo_origen']][(int) $m['id_mov_fondos']][] = $m;
        }
    }
    $agregar = function (CtbAsiento $a, array $m) use (&$out, $enRango) {
        if ($enRango($m['fecha'])) {
            $out[] = $a;
        }
        if ($m['anulado_el'] && $enRango($m['anulado_el'])) {
            $out[] = $a->invertido(substr($m['anulado_el'], 0, 10), (string) $m['motivo_anulacion']);
        }
    };

    foreach ($grupos['RECIBO'] ?? [] as $idRecibo => $ms) {
        $r = q1('SELECT r.*, c.denominacion AS cliente FROM recibos r LEFT JOIN clientes c ON c.id_cliente = r.id_cliente WHERE r.id_recibo = ?', [$idRecibo]);
        if ($r) {
            // Todos los renglones del recibo (no solo los del período) para el asiento entero.
            $todos = q('SELECT * FROM movimientos_fondos WHERE id_recibo = ?', [$idRecibo]);
            $agregar(ctb_asiento_recibo($r, $todos), array_merge($ms[0], ['fecha' => $r['fecha']]));
        }
    }
    foreach ($grupos['PAGO_OP'] ?? [] as $ms) {
        $m = $ms[0];
        $pe = q1("SELECT pe.nro_pago, o.clase, o.id_prestamo FROM pagos_emitidos pe JOIN ordenes_pago o ON o.id_orden = pe.id_orden
                   WHERE pe.id_pago_emitido = ?", [(int) $m['id_pago_emitido']]);
        $pr = $pe && $pe['id_prestamo'] ? q1('SELECT p.id_prestamo, p.nro_prestamo, c.denominacion AS cliente FROM prestamos p
                                              JOIN clientes c ON c.id_cliente = p.id_cliente WHERE p.id_prestamo = ?', [(int) $pe['id_prestamo']]) : null;
        $agregar(ctb_asiento_pago_desembolso('PAGO_OP', (int) $m['id_pago_emitido'], $m + ['nro_pago' => $pe['nro_pago'] ?? ''], $pr, (string) ($pe['clase'] ?? '?')), $m);
    }
    foreach ($grupos['DESEMBOLSO'] ?? [] as $ms) {
        $m = $ms[0];
        $pr = q1('SELECT p.id_prestamo, p.nro_prestamo, c.denominacion AS cliente FROM prestamos p
                   JOIN clientes c ON c.id_cliente = p.id_cliente WHERE p.id_prestamo = ?', [(int) $m['id_prestamo']]);
        $agregar(ctb_asiento_pago_desembolso('DESEMBOLSO', (int) $m['id_prestamo'], $m, $pr, 'PRESTAMO'), $m);
    }
    foreach ($grupos['APORTE'] ?? [] as $ms) {
        $m = $ms[0];
        $i = q1('SELECT i.nro_inversion, i.moneda, v.denominacion AS inversor FROM inversiones i JOIN inversores v ON v.id_inversor = i.id_inversor
                  WHERE i.id_inversion = ?', [(int) $m['id_inversion']]);
        $agregar(ctb_asiento_aporte($m + ($i ?: ['nro_inversion' => '', 'moneda' => 'ARS', 'inversor' => ''])), $m);
    }
    foreach ($grupos['PAGO_INVERSOR'] ?? [] as $ms) {
        $m = $ms[0];
        $l = q1('SELECT l.*, i.nro_inversion, v.denominacion AS inversor FROM inversiones_liquidaciones l
                   JOIN inversiones i ON i.id_inversion = l.id_inversion JOIN inversores v ON v.id_inversor = i.id_inversor
                  WHERE l.id_liquidacion = ?', [(int) $m['id_liquidacion_inv']]);
        if ($l) {
            $agregar(ctb_asiento_pago_inversor($m, $l), $m);
        }
    }
    foreach ($grupos['BANCARIO'] ?? [] as $ms) {
        $agregar(ctb_asiento_bancario($ms[0]), $ms[0]);
    }
    foreach ($grupos['MOVIMIENTO'] ?? [] as $idMov => $ms) {
        $mc = q1('SELECT * FROM movimientos_entre_cuentas WHERE id_mov_cuentas = ?', [$idMov]);
        if ($mc) {
            $todos = q('SELECT * FROM movimientos_fondos WHERE id_mov_cuentas = ?', [$idMov]);
            $agregar(ctb_asiento_movimiento($mc, $todos), array_merge($ms[0], ['fecha' => $mc['fecha']]));
        }
    }
    foreach (array_diff(array_keys($grupos), ['RECIBO', 'PAGO_OP', 'DESEMBOLSO', 'APORTE', 'PAGO_INVERSOR', 'MOVIMIENTO', 'BANCARIO']) as $otro) {
        foreach ($grupos[$otro] as $ms) {
            $a = new CtbAsiento((string) $otro, (int) $ms[0]['id_mov_fondos'], $ms[0]['fecha'], (string) ($ms[0]['concepto'] ?? $otro));
            $a->avisos[] = 'Movimiento de fondos de origen ' . $otro . ': todavía no tiene asiento.';
            $out[] = $a;
        }
    }

    // Liquidaciones de aportes (el interés), con su anulación
    foreach (q("SELECT l.*, i.nro_inversion, v.denominacion AS inversor FROM inversiones_liquidaciones l
                  JOIN inversiones i ON i.id_inversion = l.id_inversion JOIN inversores v ON v.id_inversor = i.id_inversor
                 WHERE (l.fecha BETWEEN ? AND ?) OR (l.anulado_el >= ? AND l.anulado_el < ?)", [$desde, $hasta, $desde, $hasta1]) as $l) {
        $a = ctb_asiento_liq_inversor($l);
        if ($enRango($l['fecha'])) {
            $out[] = $a;
        }
        if ($l['anulado_el'] && $enRango($l['anulado_el'])) {
            $out[] = $a->invertido(substr($l['anulado_el'], 0, 10), (string) $l['motivo_anulacion']);
        }
    }

    // Comisiones devengadas (las anulaciones y los clawback ya vienen en negativo)
    try {
        foreach (q("SELECT d.*, v.nombre AS vendedor, p.nro_prestamo FROM comisiones_devengadas d
                      JOIN vendedores v ON v.id_vendedor = d.id_vendedor JOIN prestamos p ON p.id_prestamo = d.id_prestamo
                     WHERE d.fecha BETWEEN ? AND ?", [$desde, $hasta]) as $c) {
            $out[] = ctb_asiento_comision($c);
        }
    } catch (Throwable $e) {
    }

    // Lo ya generado y el período de cada uno
    $gen = [];
    foreach (q('SELECT o.tipo_origen, o.id_origen, o.rol, a.id_asiento, a.numero FROM c_asientos_origen o
                  JOIN c_asientos a ON a.id_asiento = o.id_asiento') as $g) {
        $gen[$g['tipo_origen'] . ':' . $g['id_origen'] . ':' . $g['rol']] = $g;
    }
    // Un período abierto antes de uno cerrado del mismo ejercicio no recibe
    // asientos: el número que les tocaría ya quedó fijo en el cerrado.
    $periodos = q("SELECT p.id_periodo, p.fecha_inicio, p.fecha_fin, p.estado,
                          (SELECT MIN(x.fecha_inicio) FROM c_periodos x WHERE x.id_ejercicio = p.id_ejercicio
                              AND x.fecha_inicio > p.fecha_inicio AND x.estado <> 'abierto') AS cerrado_despues
                     FROM c_periodos p ORDER BY p.fecha_inicio");
    foreach ($out as $a) {
        if (isset($gen[$a->clave()])) {
            $a->idAsiento = (int) $gen[$a->clave()]['id_asiento'];
            $a->numero = (int) $gen[$a->clave()]['numero'];
        }
        foreach ($periodos as $p) {
            if ($a->fecha >= $p['fecha_inicio'] && $a->fecha <= $p['fecha_fin']) {
                $a->idPeriodo = (int) $p['id_periodo'];
                $a->periodoEstado = $p['estado'];
                $a->cerradoDespues = $p['cerrado_despues'];
            }
        }
        if (!$a->idAsiento) {
            if (!$a->idPeriodo) {
                $a->avisos[] = 'El ' . fecha($a->fecha) . ' no está en ningún período contable (Ejercicios y períodos).';
            } elseif ($a->periodoEstado !== 'abierto') {
                $a->avisos[] = 'El período del ' . fecha($a->fecha) . ' está ' . $a->periodoEstado . '.';
            } elseif ($a->cerradoDespues) {
                $a->avisos[] = 'Hay un período posterior cerrado (desde el ' . fecha($a->cerradoDespues) . '): reabrilo para generar este asiento.';
            }
            if ($a->renglones && abs($a->debe() - $a->haber()) >= 0.005) {
                $a->avisos[] = 'No balancea: debe ' . money($a->debe()) . ', haber ' . money($a->haber()) . '.';
            }
        }
    }
    usort($out, fn ($x, $y) => [$x->fecha, $x->rol === 'ANULACION', $x->tipo, $x->id] <=> [$y->fecha, $y->rol === 'ANULACION', $y->tipo, $y->id]);
    return $out;
}

/**
 * Graba los asientos listos que no se generaron todavía. Numeración
 * correlativa por ejercicio. Devuelve [generados, salteados con aviso].
 */
function ctb_generar(string $desde, string $hasta): array
{
    $asientos = ctb_derivar($desde, $hasta);
    $gen = 0;
    $salt = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($asientos as $a) {
            if ($a->idAsiento) {
                continue;
            }
            if (!$a->listo()) {
                $salt++;
                continue;
            }
            $ej = q1('SELECT id_ejercicio FROM c_periodos WHERE id_periodo = ? FOR UPDATE', [$a->idPeriodo]);
            $num = (int) q1('SELECT COALESCE(MAX(a.numero), 0) + 1 AS n FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo
                              WHERE p.id_ejercicio = ?', [(int) $ej['id_ejercicio']])['n'];
            q("INSERT INTO c_asientos (id_periodo, numero, fecha, descripcion, tipo, referencia, estado, creado_por)
               VALUES (?, ?, ?, ?, 'operacion', ?, 'confirmado', ?)", [$a->idPeriodo, $num, $a->fecha, $a->descripcion, $a->referencia ?: null, usuario_id()]);
            $id = (int) $pdo->lastInsertId();
            foreach ($a->renglones as $r) {
                q('INSERT INTO c_asiento_detalle (id_asiento, id_cuenta, debe, haber, descripcion) VALUES (?,?,?,?,?)',
                  [$id, $r['cuenta'], $r['debe'], $r['haber'], $r['detalle']]);
            }
            q('INSERT INTO c_asientos_origen (tipo_origen, id_origen, rol, id_asiento) VALUES (?,?,?,?)', [$a->tipo, $a->id, $a->rol, $id]);
            $gen++;
        }
        if ($gen) {
            foreach (array_unique(array_filter(array_map(fn ($a) => $a->idPeriodo, $asientos))) as $idp) {
                $ej = q1('SELECT id_ejercicio FROM c_periodos WHERE id_periodo = ?', [$idp]);
                ctb_renumerar((int) $ej['id_ejercicio']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$gen, $salt];
}

/**
 * Numera los asientos del ejercicio en orden de fecha (y de alta, en el
 * mismo día), para que el diario se lea correlativo aunque los meses se
 * hayan generado en otro orden. Lo de los períodos cerrados o bloqueados
 * no se toca: se numera solo lo que viene después del último cerrado,
 * a continuación de su último número.
 */
function ctb_renumerar(int $idEjercicio): void
{
    $cerr = q1("SELECT MAX(fecha_fin) AS fin FROM c_periodos WHERE id_ejercicio = ? AND estado <> 'abierto'", [$idEjercicio]);
    $desde = $cerr['fin'] ?? null;
    $base = $desde ? (int) q1('SELECT COALESCE(MAX(a.numero), 0) AS n FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo
                                WHERE p.id_ejercicio = ? AND p.fecha_fin <= ?', [$idEjercicio, $desde])['n'] : 0;
    $ids = q("SELECT a.id_asiento FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo
               WHERE p.id_ejercicio = ? AND p.estado = 'abierto'" . ($desde ? ' AND p.fecha_inicio > ?' : '') . "
               ORDER BY a.fecha, a.id_asiento", $desde ? [$idEjercicio, $desde] : [$idEjercicio]);
    $n = $base;
    foreach ($ids as $r) {
        q('UPDATE c_asientos SET numero = ? WHERE id_asiento = ?', [++$n, (int) $r['id_asiento']]);
    }
}

/**
 * Anula un asiento generado: queda anulado (a la vista, sin sumar en el
 * mayor) y se suelta de su operación, así se puede volver a generar con
 * las cuentas corregidas. Solo en un período abierto.
 */
function ctb_anular_asiento(int $id, string $motivo): void
{
    $a = q1('SELECT a.*, p.estado AS periodo_estado FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo WHERE a.id_asiento = ?', [$id]);
    if (!$a) {
        throw new RuntimeException('El asiento no existe.');
    }
    if ($a['estado'] === 'anulado') {
        throw new RuntimeException('El asiento ya está anulado.');
    }
    if ($a['periodo_estado'] !== 'abierto') {
        throw new RuntimeException('El período del asiento está ' . $a['periodo_estado'] . ': reabrilo para anularlo.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("UPDATE c_asientos SET estado = 'anulado', fecha_anulacion = NOW(), motivo_anulacion = ?, anulado_por = ? WHERE id_asiento = ?",
          [mb_substr($motivo, 0, 250), usuario_id(), $id]);
        q('DELETE FROM c_asientos_origen WHERE id_asiento = ?', [$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
