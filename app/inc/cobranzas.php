<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Cobranzas: piezas comunes de cobrar, recibo, listado y lotes.
// Todo el cálculo está en la base (financiera_cobranzas.sql):
//   sp_estado_cuenta   deuda a una fecha (NORMAL) o monto de cancelación
//   sp_registrar_pago  devenga punitorios, imputa y emite el recibo, con
//                      el número del talonario de recibos
//                      (financiera_recibos_talonario.sql)
//   sp_anular_pago     solo el último pago aplicado de cada préstamo
// ---------------------------------------------------------------------

require_once __DIR__ . '/prestamos.php';
require_once __DIR__ . '/talonarios.php';

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
    $r = sp_consulta('CALL sp_estado_cuenta(?, ?, ?)', [$idPrestamo, $fecha, $modo]);
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
                        string $modo = 'NORMAL', ?int $idLote = null, ?int $idCuenta = null, ?int $idTalonario = null): int
{
    // Con Tesorería (financiera_fondos.sql) el pago entra al libro de fondos en
    // $idCuenta; sin cuenta, en la habitual del medio. Con talonarios
    // (financiera_recibos_talonario.sql), el recibo sale con el próximo número
    // de $idTalonario; sin talonario, del único de recibos habilitado. Antes de
    // correr esos .sql, el procedure es el de siempre.
    if (recibos_con_talonario()) {
        $st = db()->prepare('CALL sp_registrar_pago(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @id_pago)');
        $st->execute([$idPrestamo, $fecha, round($importe, 2), $medio, $referencia ?: null, $modo, $idLote, usuario_id(),
                      $idCuenta ?: null, $idTalonario ?: null]);
    } elseif (columna_existe('pagos', 'id_cuenta_fondos')) {
        $st = db()->prepare('CALL sp_registrar_pago(?, ?, ?, ?, ?, ?, ?, ?, ?, @id_pago)');
        $st->execute([$idPrestamo, $fecha, round($importe, 2), $medio, $referencia ?: null, $modo, $idLote, usuario_id(), $idCuenta ?: null]);
    } else {
        $st = db()->prepare('CALL sp_registrar_pago(?, ?, ?, ?, ?, ?, ?, ?, @id_pago)');
        $st->execute([$idPrestamo, $fecha, round($importe, 2), $medio, $referencia ?: null, $modo, $idLote, usuario_id()]);
    }
    do {
        try {
            $st->fetchAll();
        } catch (PDOException $e) {
        }
    } while ($st->nextRowset());
    $st->closeCursor();
    return (int) q1('SELECT @id_pago AS id')['id'];
}

/** ¿El recibo toma el número de un talonario? (financiera_recibos_talonario.sql) */
function recibos_con_talonario(): bool
{
    return columna_existe('pagos', 'id_talonario');
}

/**
 * Talonarios con los que se emite un recibo de cobranza: habilitados, de una
 * clasificación de tipo Recibo y con numeración propia (los de ARCA, no).
 * [id => fila con 'proximo' («X 00001-00000124») y 'agotado'].
 */
function talonarios_recibo(): array
{
    if (!recibos_con_talonario()) {
        return [];
    }
    $out = [];
    foreach (q("SELECT t.id_talonario, t.nombre, t.letra, t.punto_venta, t.numero_desde, t.numero_hasta, t.ultimo_emitido
                  FROM talonarios t
                  JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
                  JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
                 WHERE ct.nombre = 'Recibo' AND t.habilitado = 1 AND t.electronico = 0
                 ORDER BY t.nombre, t.punto_venta, t.letra") as $t) {
        $n = tal_proximo($t);
        $t['agotado'] = $n > (int) $t['numero_hasta'];
        $t['proximo'] = tal_formato($t['letra'], (int) $t['punto_venta'], $n);
        $out[(int) $t['id_talonario']] = $t;
    }
    return $out;
}

/**
 * El talonario del recibo en un formulario: con uno solo, ése (no hay nada
 * que elegir: se muestra el próximo número); con varios, un combo. Sin talonarios no dibuja nada: el
 * recibo sale R-00000123.
 */
function campo_talonario_recibo(array $tals, string $elegido, int $span = 12): string
{
    if (!$tals) {
        return '';
    }
    // El combo va a lo ancho: cada opción lleva el nombre y el próximo número.
    $html = '<div class="campo" style="--span:' . (count($tals) === 1 ? $span : 12) . '"><label for="talonario">Recibo</label>';
    if (count($tals) === 1) {
        $t = reset($tals);
        $html .= '<input type="hidden" name="talonario" value="' . (int) $t['id_talonario'] . '">'
               . '<input id="talonario" value="' . h($t['agotado'] ? 'agotado' : $t['proximo']) . '" class="mono" readonly>'
               . '<span class="campo-ayuda' . ($t['agotado'] ? ' error' : '') . '">' . h($t['nombre'])
               . ($t['agotado'] ? ': está agotado, cargá uno nuevo en Talonarios' : ' · el próximo número') . '</span>';
    } else {
        $html .= '<select id="talonario" name="talonario" required><option value="">Elegí el talonario…</option>';
        foreach ($tals as $id => $t) {
            $html .= '<option value="' . $id . '"' . ((string) $id === $elegido ? ' selected' : '') . ($t['agotado'] ? ' disabled' : '') . '>'
                   . h($t['nombre'] . ' · ' . ($t['agotado'] ? 'agotado' : 'próximo ' . $t['proximo'])) . '</option>';
        }
        $html .= '</select><span class="campo-ayuda">hay más de un talonario de recibos habilitado</span>';
    }
    return $html . '</div>';
}

/** El talonario elegido en el POST, o el error. Sin talonarios: null y sin error. */
function talonario_recibo_post(array $tals, string $elegido, array &$errores): ?int
{
    if (!$tals) {
        return null;
    }
    $id = (int) $elegido;
    if (!isset($tals[$id])) {
        $errores[] = 'Elegí el talonario del recibo.';
        return null;
    }
    if ($tals[$id]['agotado']) {
        $errores[] = 'El talonario «' . $tals[$id]['nombre'] . '» está agotado: cargá uno nuevo en Talonarios.';
        return null;
    }
    return $id;
}

/** Préstamos que coinciden con lo buscado (N°, CUIT, DNI o nombre), los vigentes primero. */
function buscar_prestamos(string $txt, int $limite = 20): array
{
    $txt = trim($txt);
    if ($txt === '') {
        return [];
    }
    $base = "SELECT p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, p.capital, p.plazo, p.valor_cuota, p.estado,
                    c.id_cliente, c.denominacion, c.cuit, ta.nombre AS tipo_asociado
               FROM prestamos p
               JOIN clientes c ON c.id_cliente = p.id_cliente
               LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
               JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
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
