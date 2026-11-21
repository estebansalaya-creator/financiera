<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Órdenes de pago, pagos emitidos y archivos de transferencias masivas.
// El modelo de gestion_adm (pagos_routes.py, transferencias_lote.py) y su
// diseño de Interbanking (pago_transferencias_sueldos.py). Las tablas y los
// procedures, en sql\financiera_ordenes_pago.sql.
//
//   préstamo liquidado -> orden de pago (A pagar) -> pago emitido (cuenta,
//   medio, CBU) -> libro de fondos -> archivo Interbanking (lote)
//
// De dónde sale el CBU de una transferencia: el que se escribió al emitir el
// pago, y si no se escribió, el de la ficha del cliente al armar el archivo.
// En el archivo queda congelado (el detalle del lote): si el cliente cambia
// su CBU después, el lote sigue diciendo a dónde fue la plata. En el pago no
// se escribe: si se lo saca del archivo porque el CBU estaba mal y se corrige
// la ficha, el archivo siguiente tiene que tomar el nuevo.
//
// Interbanking — PIL, importación «Por Número de CBU» (Diseño de Registros
// para importar Transferencias, v1.5). Registros de 240 posiciones con CRLF:
//   *U*  uno: la cuenta a debitar (CBU ordenante), fecha de solicitud,
//        consolidado S/N y la observación del lote.
//   *M*  uno por transferencia: CBU destino, importe 9(15)v99 (centavos
//        implícitos: $ 150,25 -> 00000000000015025), observación (el número
//        de pago) y, en la variante de proveedores, el CUIT.
// Alfanuméricos con espacios a la derecha, sin acentos; numéricos con ceros
// a la izquierda. Solo CBU / CVU de 22 números: con alias no se puede.
// ---------------------------------------------------------------------

require_once __DIR__ . '/fondos.php';

const OP_ESTADOS      = ['APROBADA' => 'A pagar', 'PAGADA' => 'Pagada', 'ANULADA' => 'Anulada'];
const OP_ESTADOS_TIPO = ['APROBADA' => 'warn', 'PAGADA' => 'ok', 'ANULADA' => 'neutro'];
const OP_CLASES       = ['PRESTAMO' => 'Desembolso de préstamo'];
const OP_MEDIOS       = ['TRANSFERENCIA' => 'Transferencia', 'CHEQUE' => 'Cheque', 'EFECTIVO' => 'Efectivo'];
const IB_LARGO        = 240;

/** ¿Ya se corrió sql\financiera_ordenes_pago.sql? */
function op_instalado(): bool
{
    return columna_existe('movimientos_fondos', 'id_pago_emitido');
}

function op_estado(string $e): string
{
    return estado(OP_ESTADOS[$e] ?? $e, OP_ESTADOS_TIPO[$e] ?? 'neutro');
}

/** Cuentas en pesos de las que puede salir un pago: bancos, billeteras y cajas. */
function op_cuentas_pago(): array
{
    return array_filter(cuentas_fondos('ARS'), function ($c) { return in_array($c['tipo'], ['B', 'V', 'C'], true); });
}

/** Una línea con cómo se pagó: «Transferencia desde Nación cuenta corriente», «Cheque N° 30000002…». */
function op_pago_txt(array $g): string
{
    $m = OP_MEDIOS[$g['medio']] ?? $g['medio'];
    if ($g['medio'] === 'CHEQUE') {
        $m .= ' N° ' . $g['cheque_numero'];
    }
    return $m . ' desde ' . ($g['cuenta'] ?? '');
}

/**
 * Los pagos que salieron en un archivo vigente: id_pago_emitido => [id_lote, nombre_archivo].
 * Un pago excluido del archivo, o de un archivo anulado, no cuenta.
 */
function op_transferidos(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $filas = q('SELECT d.id_pago_emitido, d.cbu_destino, l.id_lote, l.nombre_archivo
                  FROM transferencias_lotes_det d JOIN transferencias_lotes l ON l.id_lote = d.id_lote
                 WHERE d.incluida = 1 AND l.anulado_el IS NULL
                   AND d.id_pago_emitido IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    $out = [];
    foreach ($filas as $f) {
        $out[(int) $f['id_pago_emitido']] = $f;
    }
    return $out;
}

/** ¿El banco ya debitó el pago? (su débito bancario está «En el banco»): la plata salió. */
function op_debitado(int $idPago): bool
{
    return (bool) q1("SELECT 1 AS si FROM debitos_creditos_bancarios d
                        JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                       WHERE m.id_pago_emitido = ? AND d.estado = 'ACREDITADO' LIMIT 1", [$idPago]);
}

// ---------------------------------------------------------------------
// Interbanking
// ---------------------------------------------------------------------

class TransferenciaError extends RuntimeException
{
}

/** Alfanumérico del diseño: mayúsculas, sin acentos ni signos, espacios a la derecha. */
function ib_alfa($valor, int $largo): string
{
    $t = strtr((string) $valor, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o',
                                 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i',
                                 'ö' => 'o', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
                                 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', 'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O',
                                 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C']);
    $t = preg_replace('#[/\\\\.,;:_-]+#', ' ', strtoupper($t));   // un separador es un espacio: 20/11/2026 -> 20 11 2026
    $t = preg_replace('/[^A-Z0-9 ]/', '', $t);                     // el resto se va: D'AMATO -> DAMATO
    $t = trim(preg_replace('/ {2,}/', ' ', $t));
    return str_pad(substr($t, 0, $largo), $largo);
}

/** Importe en centavos implícitos, ceros a la izquierda. */
function ib_importe($importe, int $largo = 17): string
{
    $cent = (int) round(round((float) $importe, 2) * 100);
    if ($cent <= 0) {
        throw new TransferenciaError('el importe tiene que ser positivo');
    }
    $t = (string) $cent;
    if (strlen($t) > $largo) {
        throw new TransferenciaError('el importe no entra en ' . $largo . ' posiciones');
    }
    return str_pad($t, $largo, '0', STR_PAD_LEFT);
}

/** Registro *U*: el lote, con la cuenta a debitar. $fechaSolicitud AAAA-MM-DD. */
function ib_registro_u(string $cbuOrdenante, string $fechaSolicitud, string $observacion, string $consolidado = 'N', ?string $fechaArchivo = null): string
{
    $cbu = preg_replace('/\D/', '', $cbuOrdenante);
    if (strlen($cbu) !== 22) {
        throw new TransferenciaError('el CBU de la cuenta que se debita no tiene 22 números');
    }
    $consolidado = strtoupper(substr($consolidado, 0, 1)) === 'S' ? 'S' : 'N';
    $linea = '*U*'                                                   // 1-3
           . $cbu                                                    // 4-25
           . 'D'                                                     // 26: se debita la propia
           . str_replace('-', '', $fechaSolicitud)                   // 27-34 AAAAMMDD
           . $consolidado                                            // 35
           . ib_alfa($observacion, 61)                               // 36-96
           . '000'                                                   // 97-99
           . '00'                                                    // 100-101 cuenta Datanet
           . date('m/d/y', strtotime($fechaArchivo ?? date('Y-m-d'))) // 102-109 MM/DD/AA (sí: mes/día/año)
           . str_repeat(' ', 8)                                      // 110-117 secuencia (opcional)
           . str_repeat(' ', 123);                                   // 118-240
    if (strlen($linea) !== IB_LARGO) {
        throw new TransferenciaError('el registro *U* quedó de ' . strlen($linea) . ' posiciones');
    }
    return $linea;
}

/** Registro *M* de transferencia a terceros (la variante de proveedores: con el CUIT). */
function ib_registro_m(string $cbuDestino, $importe, string $observacion, ?string $cuit): string
{
    $cbu = preg_replace('/\D/', '', $cbuDestino);
    if (strlen($cbu) !== 22) {
        throw new TransferenciaError('el CBU destino no tiene 22 números');
    }
    $linea = '*M*'                                            // 1-3
           . $cbu                                             // 4-25
           . ib_importe($importe)                             // 26-42
           . ib_alfa($observacion, 60)                        // 43-102
           . str_repeat(' ', 2)                               // 103-104 documento a cancelar (opcional)
           . str_repeat(' ', 12)                              // 105-116 número de documento
           . str_repeat(' ', 2)                               // 117-118 tipo de orden de pago
           . str_repeat(' ', 12)                              // 119-130 número de orden de pago
           . str_repeat(' ', 12)                              // 131-142 código de cliente
           . str_repeat(' ', 2)                               // 143-144 tipo de retención
           . str_repeat('0', 12)                              // 145-156 total retención 9(10)v99
           . str_repeat(' ', 12)                              // 157-168 número de nota de crédito
           . str_repeat('0', 10)                              // 169-178 importe nota de crédito 9(08)v99
           . ib_alfa(preg_replace('/\D/', '', (string) $cuit), 11)  // 179-189 CUIT (opcional)
           . str_repeat(' ', 51);                             // 190-240
    if (strlen($linea) !== IB_LARGO) {
        throw new TransferenciaError('el registro *M* quedó de ' . strlen($linea) . ' posiciones');
    }
    return $linea;
}

/**
 * El archivo: *U* + un *M* por transferencia. Cada transferencia:
 * ['cbu', 'importe', 'nro_pago', 'cuit']. Devuelve ['contenido', 'registros', 'total'].
 * Una transferencia que no entra en el diseño corta todo (TransferenciaError):
 * las que no tienen CBU ya quedaron afuera antes, con su motivo.
 */
function ib_archivo(string $cbuOrdenante, string $fechaSolicitud, string $observacion, array $transferencias, ?string $fechaArchivo = null): array
{
    $lineas = [ib_registro_u($cbuOrdenante, $fechaSolicitud, $observacion, 'N', $fechaArchivo)];
    $total = 0.0;
    foreach ($transferencias as $t) {
        try {
            $lineas[] = ib_registro_m($t['cbu'], $t['importe'], $t['nro_pago'], $t['cuit'] ?? null);
        } catch (TransferenciaError $e) {
            throw new TransferenciaError($t['nro_pago'] . ': ' . $e->getMessage());
        }
        $total += (float) $t['importe'];
    }
    return ['contenido' => implode("\r\n", $lineas) . "\r\n", 'registros' => count($transferencias), 'total' => round($total, 2)];
}

/**
 * Pagos por transferencia de una cuenta que todavía no salieron en un
 * archivo vigente, con el CBU que se va a usar y, si no se puede, el motivo.
 */
function op_pendientes_de_archivo(int $idCuenta, array $soloIds = []): array
{
    $w = '';
    $p = [$idCuenta];
    if ($soloIds) {
        $w = ' AND g.id_pago_emitido IN (' . implode(',', array_fill(0, count($soloIds), '?')) . ')';
        $p = array_merge($p, array_map('intval', $soloIds));
    }
    $filas = q("SELECT g.*, o.nro_orden, o.beneficiario, o.cuit, o.id_prestamo, p.nro_prestamo,
                       c.cbu AS cbu_ficha, c.alias_cbu AS alias_ficha
                  FROM pagos_emitidos g
                  JOIN ordenes_pago o ON o.id_orden = g.id_orden
                  LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo
                  LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                 WHERE g.id_cuenta_fondos = ? AND g.medio = 'TRANSFERENCIA' AND g.estado = 'EMITIDO'
                   AND NOT EXISTS (SELECT 1 FROM transferencias_lotes_det d
                                     JOIN transferencias_lotes l ON l.id_lote = d.id_lote AND l.anulado_el IS NULL
                                    WHERE d.id_pago_emitido = g.id_pago_emitido AND d.incluida = 1)$w
                 ORDER BY g.fecha, g.id_pago_emitido", $p);
    foreach ($filas as &$f) {
        $propio = (string) $f['cbu_destino'];
        $f['cbu_usar'] = $propio !== '' ? $propio : (string) $f['cbu_ficha'];
        $f['cbu_origen'] = $propio !== '' ? 'escrito al emitir el pago' : 'de la ficha del cliente';
        $f['motivo'] = null;
        if ($f['cbu_usar'] === '') {
            $f['motivo'] = ($f['alias_destino'] || $f['alias_ficha'])
                ? 'tiene alias y no CBU: Interbanking transfiere por CBU'
                : 'el cliente no tiene CBU cargado';
        } elseif (!cbu_valido($f['cbu_usar'])) {
            $f['motivo'] = 'el CBU ' . $f['cbu_usar'] . ' (' . $f['cbu_origen'] . ') no es válido';
        }
    }
    unset($f);
    return $filas;
}

/**
 * Genera el archivo de una cuenta con los pagos elegidos: deja el lote con
 * todos sus renglones (los excluidos, con el motivo) y congela el CBU usado
 * en cada pago. Devuelve el id del lote. RuntimeException con el texto para
 * la persona si no se puede.
 */
function op_generar_lote(array $cuenta, array $idsPago, string $fechaSolicitud, string $observacion): int
{
    $cbuOrd = (string) $cuenta['cbu'];
    if ($cuenta['tipo'] !== 'B' || !cbu_valido($cbuOrd)) {
        throw new RuntimeException($cuenta['nombre'] . ' no tiene un CBU válido: cargalo en Cuentas de fondos (es la cuenta que se debita en el archivo).');
    }
    $idsPago = array_values(array_unique(array_filter(array_map('intval', $idsPago))));
    if (!$idsPago) {
        throw new RuntimeException('Tildá los pagos que van en el archivo.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Dos a la vez: lo primero es bloquear los pagos elegidos (en orden de
        // id, así dos archivos no se bloquean cruzados). Si otro está armando
        // un archivo con alguno de ellos, o anulándolo, se espera a que
        // termine. Recién después se lee qué está pendiente: esa lectura ya ve
        // lo que el otro grabó, y un pago no queda en dos archivos.
        q('SELECT id_pago_emitido FROM pagos_emitidos WHERE id_pago_emitido IN (' . implode(',', array_fill(0, count($idsPago), '?')) . ')
            ORDER BY id_pago_emitido FOR UPDATE', $idsPago);
        $filas = op_pendientes_de_archivo((int) $cuenta['id_cuenta_fondos'], $idsPago);
        if (!$filas) {
            throw new RuntimeException('Ninguno de los pagos elegidos está pendiente de archivo: ya salieron en otro o se anularon.');
        }
        $incluir = array_values(array_filter($filas, function ($f) { return $f['motivo'] === null; }));
        if (!$incluir) {
            throw new RuntimeException('Ninguno de los pagos elegidos tiene un CBU válido: ' . $filas[0]['motivo'] . '.');
        }
        $arch = ib_archivo($cbuOrd, $fechaSolicitud, $observacion, array_map(function ($f) {
            return ['cbu' => $f['cbu_usar'], 'importe' => $f['importe'], 'nro_pago' => $f['nro_pago'], 'cuit' => $f['cuit']];
        }, $incluir));

        $nombre = 'interbanking_' . date('Ymd_His') . '.txt';
        q('INSERT INTO transferencias_lotes (formato, nombre_archivo, fecha_solicitud, id_cuenta_fondos, cbu_ordenante, observacion,
                                             cantidad, importe_total, excluidas, creado_por)
           VALUES (\'INTERBANKING\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$nombre, $fechaSolicitud, (int) $cuenta['id_cuenta_fondos'], $cbuOrd, trim(ib_alfa($observacion, 61)) ?: null,
           $arch['registros'], $arch['total'], count($filas) - count($incluir), usuario_id()]);
        $idLote = (int) $pdo->lastInsertId();
        // Con el número de archivo: dos generados en el mismo segundo no se llaman igual.
        q('UPDATE transferencias_lotes SET nombre_archivo = ? WHERE id_lote = ?',
          ['interbanking_' . date('Ymd_His') . '_' . str_pad((string) $idLote, 4, '0', STR_PAD_LEFT) . '.txt', $idLote]);
        foreach ($filas as $f) {
            q('INSERT INTO transferencias_lotes_det (id_lote, id_pago_emitido, cbu_destino, cuit, beneficiario, importe, incluida, motivo_exclusion)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
              [$idLote, (int) $f['id_pago_emitido'], $f['motivo'] === null ? $f['cbu_usar'] : null, $f['cuit'], mb_substr((string) $f['beneficiario'], 0, 150),
               $f['importe'], $f['motivo'] === null ? 1 : 0, $f['motivo'] === null ? null : mb_substr($f['motivo'], 0, 150)]);
        }
        $pdo->commit();
        return $idLote;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** El contenido de un archivo ya generado, rearmado desde su detalle (lo incluido que sigue incluido). */
function op_contenido_lote(array $lote): array
{
    $det = q('SELECT d.cbu_destino AS cbu, d.importe, d.cuit, g.nro_pago
                FROM transferencias_lotes_det d JOIN pagos_emitidos g ON g.id_pago_emitido = d.id_pago_emitido
               WHERE d.id_lote = ? AND d.incluida = 1 ORDER BY d.id_det', [(int) $lote['id_lote']]);
    return ib_archivo($lote['cbu_ordenante'], $lote['fecha_solicitud'], (string) $lote['observacion'], $det, substr($lote['creado_el'], 0, 10));
}

/** Recalcula cantidad, importe y excluidas desde el detalle (no se suman y restan a mano). */
function op_recalcular_lote(int $idLote): array
{
    $t = q1('SELECT COALESCE(SUM(incluida = 1), 0) AS cantidad, COALESCE(SUM(IF(incluida = 1, importe, 0)), 0) AS importe,
                    COALESCE(SUM(incluida = 0), 0) AS excluidas
               FROM transferencias_lotes_det WHERE id_lote = ?', [$idLote]);
    q('UPDATE transferencias_lotes SET cantidad = ?, importe_total = ?, excluidas = ? WHERE id_lote = ?',
      [(int) $t['cantidad'], $t['importe'], (int) $t['excluidas'], $idLote]);
    return $t;
}

/**
 * Saca un pago de un archivo que no se mandó (el resto sigue). Si era el
 * último, el archivo se anula solo. No si el banco ya lo debitó.
 */
function op_excluir_de_lote(int $idLote, int $idPago, string $motivo): array
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5) {
        throw new RuntimeException('Escribí por qué se saca el pago del archivo.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lote = q1('SELECT * FROM transferencias_lotes WHERE id_lote = ? FOR UPDATE', [$idLote]);
        if (!$lote || $lote['anulado_el'] !== null) {
            throw new RuntimeException('El archivo no existe o ya está anulado.');
        }
        $det = q1('SELECT d.id_det, g.nro_pago FROM transferencias_lotes_det d JOIN pagos_emitidos g ON g.id_pago_emitido = d.id_pago_emitido
                    WHERE d.id_lote = ? AND d.id_pago_emitido = ? AND d.incluida = 1 FOR UPDATE', [$idLote, $idPago]);
        if (!$det) {
            throw new RuntimeException('Ese pago no está incluido en el archivo.');
        }
        if (op_debitado($idPago)) {
            throw new RuntimeException('El banco ya debitó el pago ' . $det['nro_pago'] . ': la transferencia salió.');
        }
        $texto = 'Sacado el ' . date('d/m/Y') . ' por ' . ((usuario_actual()['nombre'] ?? '') ?: 'usuario ' . usuario_id()) . ': ' . $motivo;
        q('UPDATE transferencias_lotes_det SET incluida = 0, motivo_exclusion = ? WHERE id_det = ?', [mb_substr($texto, 0, 150), (int) $det['id_det']]);
        $t = op_recalcular_lote($idLote);
        $anulado = (int) $t['cantidad'] === 0;
        if ($anulado) {
            q('UPDATE transferencias_lotes SET anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ? WHERE id_lote = ?',
              [usuario_id(), 'Se sacaron todos sus pagos', $idLote]);
        }
        $pdo->commit();
        return ['nro_pago' => $det['nro_pago'], 'quedan' => (int) $t['cantidad'], 'anulado' => $anulado];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Anula un archivo que no se mandó al banco: sus pagos quedan libres (para otro archivo o para anularlos). */
function op_anular_lote(int $idLote, string $motivo): int
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5) {
        throw new RuntimeException('Escribí por qué se anula el archivo.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lote = q1('SELECT * FROM transferencias_lotes WHERE id_lote = ? FOR UPDATE', [$idLote]);
        if (!$lote || $lote['anulado_el'] !== null) {
            throw new RuntimeException('El archivo no existe o ya estaba anulado.');
        }
        $incl = q('SELECT d.id_pago_emitido, g.nro_pago FROM transferencias_lotes_det d JOIN pagos_emitidos g ON g.id_pago_emitido = d.id_pago_emitido
                    WHERE d.id_lote = ? AND d.incluida = 1', [$idLote]);
        $debitados = array_values(array_filter($incl, function ($r) { return op_debitado((int) $r['id_pago_emitido']); }));
        if ($debitados) {
            throw new RuntimeException('No se puede anular: el banco ya debitó ' . (count($debitados) === 1 ? 'el pago ' : 'los pagos ')
                . implode(', ', array_slice(array_column($debitados, 'nro_pago'), 0, 5))
                . (count($debitados) > 5 ? ' y otros' : '') . '. El archivo se mandó.');
        }
        q('UPDATE transferencias_lotes SET anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ? WHERE id_lote = ?',
          [usuario_id(), mb_substr($motivo . ' (' . ((usuario_actual()['nombre'] ?? '') ?: 'usuario ' . usuario_id()) . ')', 0, 250), $idLote]);
        $pdo->commit();
        return count($incl);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
