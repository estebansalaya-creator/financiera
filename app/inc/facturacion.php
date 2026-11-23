<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Facturación electrónica de las cobranzas (WSFEv1 de ARCA). El modelo es
// el de gestion_adm (arca_facturacion.py / arca_wsfe.py), en PHP. Tablas en
// sql\financiera_facturacion.sql.
//
// QUE SE FACTURA (a confirmar con el contador: preguntas_cliente.md)
//   Una factura por recibo, con lo que aplicó a los préstamos según la
//   imputación real de cada pago (pagos_imputacion):
//     CAPITAL                   no gravado  (ImpTotConc)
//     INTERES + PUNITORIO       neto        (ImpNeto, a la alícuota del préstamo)
//     IVA_INTERES + IVA_PUNIT.  IVA         (ImpIVA)
//   No la fórmula de Reggio (IVA Formula), que da intereses e IVA negativos.
//   La cuota social no se factura por ahora.
//   Solo recibos desde la fecha del parámetro FACTURACION_DESDE: sin él no
//   se genera nada (los recibos de antes no se facturan solos).
//
// A QUIEN
//   Responsable inscripto o monotributista: comprobante A, con su CUIT.
//   Consumidor final, exento o sin condición: B; una persona (CUIT 20, 23,
//   24, 27) consumidor final va como CUIL (86); sin CUIT, con DNI (96).
//
// ANULACION
//   Recibo anulado: su factura sin CAE se anula; con CAE, se emite una
//   nota de crédito por el total que la referencia (CbtesAsoc).
//
// NUMERO Y ESTADOS
//   El número se asigna al autorizar: ARCA no acepta saltos, y una factura
//   anulada antes del CAE no tiene que dejar hueco. PENDIENTE (número 0) ->
//   ENVIANDO (número asignado, salió a ARCA) -> AUTORIZADO (CAE) o
//   RECHAZADO (motivo en arca_obs; no sale hasta «Reintentar»).
//   ENVIANDO existe porque la respuesta se puede perder después de que
//   ARCA autorizó: antes de numerar otros, se le pregunta a ARCA por esos
//   (FECompConsultar). Si lo tiene, se graba su CAE; si no, vuelve a
//   PENDIENTE, pero recién pasados unos minutos (puede seguir en proceso).
//   Un rechazo corta la tanda: los siguientes vuelven a PENDIENTE.
//   En producción, si el último número de ARCA no es el del talonario no se
//   autoriza nada (hay comprobantes que el sistema no tiene). En
//   homologación el talonario se pone a la par solo.
//
// AMBIENTE
//   El del talonario (arca_ambiente). Producción solo con
//   arca_wsfe_produccion = true en config.php (FIN_ARCA_WSFE_PRODUCCION=1).
// ---------------------------------------------------------------------

require_once __DIR__ . '/arca.php';

const FACT_URL_WSFE = ['homo' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
                       'prod' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'];
const FACT_NS       = 'http://ar.gov.afip.dif.FEV1/';
const FACT_TIPOS    = [1 => 'Factura A', 3 => 'Nota de crédito A', 6 => 'Factura B', 8 => 'Nota de crédito B'];
const FACT_FACTURA  = ['A' => 1, 'B' => 6];
const FACT_NC       = ['A' => 3, 'B' => 8];
const FACT_ESTADOS  = ['PENDIENTE' => 'Pendiente', 'ENVIANDO' => 'Enviando', 'AUTORIZADO' => 'Autorizado',
                       'RECHAZADO' => 'Rechazado', 'ANULADO' => 'Anulado'];
const FACT_ESTADOS_TIPO = ['PENDIENTE' => 'warn', 'ENVIANDO' => 'info', 'AUTORIZADO' => 'ok', 'RECHAZADO' => 'danger', 'ANULADO' => 'neutro'];
/** Condición frente al IVA del cliente -> código de ARCA (RG 5616). */
const FACT_COND_IVA = ['RI' => 1, 'EX' => 4, 'CF' => 5, 'MONO' => 6];
const FACT_CLASE_A  = [1, 6];
/** Alícuota -> Id de AlicIva. */
const FACT_ALICUOTAS = ['0.00' => 3, '10.50' => 4, '21.00' => 5, '27.00' => 6, '5.00' => 8, '2.50' => 9];
const FACT_LOTE        = 50;    // comprobantes por FECAESolicitar
const FACT_DIAS        = 10;    // servicios: ARCA acepta 10 días antes o después
const FACT_ESPERA_SEG  = 240;   // un ENVIANDO que ARCA no tiene vuelve a PENDIENTE pasado esto

function fact_instalado(): bool
{
    static $si = null;
    if ($si === null) {
        try {
            q('SELECT 1 FROM comprobantes_venta LIMIT 1');
            $si = true;
        } catch (PDOException $e) {
            $si = false;
        }
    }
    return $si;
}

/** «FB 00003-00000012» (o «FB 00003 · sin número» mientras espera CAE). */
function fact_nro(array $c): string
{
    $pre = (in_array((int) $c['cbte_tipo'], [3, 8], true) ? 'NC' : 'F') . $c['letra'] . ' ' . str_pad((string) $c['punto_venta'], 5, '0', STR_PAD_LEFT);
    return (int) $c['numero'] ? $pre . '-' . str_pad((string) $c['numero'], 8, '0', STR_PAD_LEFT) : $pre . ' · sin número';
}

/** Desde qué fecha se facturan los recibos (parámetro FACTURACION_DESDE), o null. */
function fact_desde(): ?string
{
    $f = q1("SELECT valor FROM parametros WHERE clave = 'FACTURACION_DESDE'");
    return $f && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['valor']) ? (string) $f['valor'] : null;
}

function fact_desde_guardar(?string $fecha): void
{
    if ($fecha === null) {
        q("DELETE FROM parametros WHERE clave = 'FACTURACION_DESDE'");
        return;
    }
    q("INSERT INTO parametros (clave, valor, descripcion) VALUES ('FACTURACION_DESDE', ?, 'Recibos desde esta fecha se facturan (factura electrónica)')
       ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [$fecha]);
}

// ── Talonarios ────────────────────────────────────────────────────────

/** Los talonarios electrónicos habilitados de factura y NC A/B, por código de ARCA. */
function fact_talonarios(): array
{
    $out = [];
    foreach (q("SELECT * FROM talonarios WHERE habilitado = 1 AND electronico = 1 AND codigo_arca IN ('001','003','006','008')
                 ORDER BY id_talonario") as $t) {
        $out[(int) $t['codigo_arca']][] = $t;
    }
    return $out;
}

/** El talonario para un tipo (1, 3, 6, 8), o el motivo por el que no hay. */
function fact_talonario_para(int $tipo, array $tals): array
{
    $l = $tals[$tipo] ?? [];
    if (!$l) {
        return [null, 'No hay un talonario electrónico habilitado de ' . FACT_TIPOS[$tipo] . ' (Tablas › Talonarios).'];
    }
    if (count($l) > 1) {
        return [null, 'Hay más de un talonario habilitado de ' . FACT_TIPOS[$tipo] . ': dejá uno solo.'];
    }
    return [$l[0], null];
}

// ── Generación (idempotente) ──────────────────────────────────────────

/** Receptor: [doc_tipo, doc_nro, cond_iva, letra, error]. */
function fact_receptor(array $cli): array
{
    $cond = FACT_COND_IVA[(string) $cli['condicion_iva']] ?? 5;
    $letra = in_array($cond, FACT_CLASE_A, true) ? 'A' : 'B';
    $cuit = preg_replace('/\D/', '', (string) $cli['cuit']);
    $dni = preg_replace('/\D/', '', (string) ($cli['dni'] ?? ''));
    if (strlen($cuit) === 11 && cuit_valido($cuit)) {
        $persona = in_array(substr($cuit, 0, 2), ['20', '23', '24', '27'], true);
        return [$persona && $cond === 5 ? 86 : 80, (int) $cuit, $cond, $letra, null];
    }
    if ($letra === 'B' && $cond === 5 && strlen($dni) >= 7 && strlen($dni) <= 8) {
        return [96, (int) $dni, $cond, $letra, null];
    }
    return [0, 0, $cond, $letra, 'El cliente no tiene un CUIT/CUIL válido' . ($letra === 'B' ? ' ni DNI' : '') . '.'];
}

/**
 * Pone la facturación al día con los recibos: crea las facturas de los
 * recibos nuevos (desde FACTURACION_DESDE), anula las de recibos anulados
 * que no tenían CAE y emite la NC de las que sí. Devuelve conteos y los
 * avisos (recibos que no se pudieron facturar).
 */
function fact_sincronizar(): array
{
    $res = ['facturas' => 0, 'anuladas' => 0, 'notas' => 0, 'avisos' => []];
    $desde = fact_desde();
    $tals = fact_talonarios();

    // 1. Recibos aplicados sin factura, con pagos de préstamos.
    if ($desde) {
        $recibos = q("SELECT r.id_recibo, r.nro_recibo, r.fecha, r.id_cliente, c.cuit, c.denominacion, c.condicion_iva, cp.dni
                        FROM recibos r
                        JOIN clientes c ON c.id_cliente = r.id_cliente
                        LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
                       WHERE r.estado = 'APLICADO' AND r.fecha >= ?
                         AND EXISTS (SELECT 1 FROM pagos g WHERE g.id_recibo = r.id_recibo AND g.estado = 'APLICADO')
                         AND NOT EXISTS (SELECT 1 FROM comprobantes_venta v WHERE v.id_recibo = r.id_recibo AND v.id_asociado IS NULL)
                       ORDER BY r.fecha, r.id_recibo
                       LIMIT 2000", [$desde]);
        foreach ($recibos as $r) {
            [$docT, $docN, $cond, $letra, $err] = fact_receptor($r);
            [$tal, $errT] = fact_talonario_para(FACT_FACTURA[$letra], $tals);
            if ($err || $errT) {
                $res['avisos'][] = 'Recibo ' . $r['nro_recibo'] . ' (' . $r['denominacion'] . '): ' . ($err ?? $errT);
                continue;
            }
            $items = q("SELECT g.id_pago, p.id_prestamo, p.nro_prestamo, p.alicuota_iva,
                               SUM(IF(i.concepto = 'CAPITAL', i.importe, 0)) AS no_gravado,
                               SUM(IF(i.concepto IN ('INTERES','PUNITORIO'), i.importe, 0)) AS neto,
                               SUM(IF(i.concepto IN ('IVA_INTERES','IVA_PUNITORIO'), i.importe, 0)) AS iva,
                               GROUP_CONCAT(DISTINCT c.nro_cuota ORDER BY c.nro_cuota) AS cuotas
                          FROM pagos g
                          JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                          JOIN pagos_imputacion i ON i.id_pago = g.id_pago
                          JOIN cuotas c ON c.id_cuota = i.id_cuota
                         WHERE g.id_recibo = ? AND g.estado = 'APLICADO'
                         GROUP BY g.id_pago, p.id_prestamo, p.nro_prestamo, p.alicuota_iva
                         ORDER BY p.id_prestamo", [(int) $r['id_recibo']]);
            $tot = ['no_gravado' => 0.0, 'neto' => 0.0, 'iva' => 0.0];
            foreach ($items as $it) {
                foreach ($tot as $k => $v) {
                    $tot[$k] = round($v + (float) $it[$k], 2);
                }
            }
            $total = round($tot['no_gravado'] + $tot['neto'] + $tot['iva'], 2);
            if ($total <= 0) {
                continue;
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                q('INSERT INTO comprobantes_venta (id_talonario, cbte_tipo, letra, punto_venta, fecha, id_cliente, id_recibo, doc_tipo, doc_nro,
                       cond_iva_receptor, importe_no_gravado, importe_neto, importe_iva, importe_total, servicio_desde, servicio_hasta,
                       arca_ambiente, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [(int) $tal['id_talonario'], FACT_FACTURA[$letra], $letra, (int) $tal['punto_venta'], $r['fecha'], (int) $r['id_cliente'],
                   (int) $r['id_recibo'], $docT, $docN, $cond, $tot['no_gravado'], $tot['neto'], $tot['iva'], $total,
                   date('Y-m-01', strtotime($r['fecha'])), date('Y-m-t', strtotime($r['fecha'])), $tal['arca_ambiente'] ?: 'homo', usuario_id()]);
                $id = (int) $pdo->lastInsertId();
                foreach ($items as $it) {
                    q('INSERT INTO comprobantes_venta_items (id_comprobante, descripcion, id_prestamo, id_pago, alicuota_iva, no_gravado, neto, iva, total)
                       VALUES (?,?,?,?,?,?,?,?,?)',
                      [$id, mb_substr('Préstamo ' . $it['nro_prestamo'] . ' · cuota' . (strpos((string) $it['cuotas'], ',') ? 's ' : ' ') . $it['cuotas'], 0, 200),
                       (int) $it['id_prestamo'], (int) $it['id_pago'], round((float) $it['alicuota_iva'] * 100, 2),   // prestamos la guarda como fracción (0.21)
                       round((float) $it['no_gravado'], 2), round((float) $it['neto'], 2), round((float) $it['iva'], 2),
                       round((float) $it['no_gravado'] + (float) $it['neto'] + (float) $it['iva'], 2)]);
                }
                $pdo->commit();
                $res['facturas']++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $res['avisos'][] = 'Recibo ' . $r['nro_recibo'] . ': ' . mensaje_bd($e);
            }
        }
    }

    // 2. Recibos anulados: la factura sin CAE se anula.
    q("UPDATE comprobantes_venta v JOIN recibos r ON r.id_recibo = v.id_recibo
          SET v.arca_estado = 'ANULADO', v.anulado_el = NOW(), v.anulado_por = ?,
              v.arca_obs = CONCAT_WS(' · ', v.arca_obs, 'Se anuló el recibo antes del CAE')
        WHERE r.estado = 'ANULADO' AND v.id_asociado IS NULL AND v.arca_estado IN ('PENDIENTE','RECHAZADO')", [usuario_id()]);
    $res['anuladas'] = (int) db()->query('SELECT ROW_COUNT()')->fetchColumn();

    // 3. Con CAE: la nota de crédito por el total.
    $conCae = q("SELECT v.* FROM comprobantes_venta v JOIN recibos r ON r.id_recibo = v.id_recibo
                  WHERE r.estado = 'ANULADO' AND v.id_asociado IS NULL AND v.arca_estado = 'AUTORIZADO'
                    AND NOT EXISTS (SELECT 1 FROM comprobantes_venta n WHERE n.id_asociado = v.id_comprobante AND n.arca_estado <> 'ANULADO')");
    foreach ($conCae as $f) {
        [$tal, $errT] = fact_talonario_para(FACT_NC[$f['letra']], $tals);
        if ($errT) {
            $res['avisos'][] = 'Nota de crédito de ' . fact_nro($f) . ': ' . $errT;
            continue;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q('INSERT INTO comprobantes_venta (id_talonario, cbte_tipo, letra, punto_venta, fecha, id_cliente, id_recibo, id_asociado, doc_tipo, doc_nro,
                   cond_iva_receptor, importe_no_gravado, importe_neto, importe_iva, importe_exento, importe_total, servicio_desde, servicio_hasta,
                   arca_ambiente, creado_por)
               SELECT ?, ?, letra, ?, CURDATE(), id_cliente, id_recibo, id_comprobante, doc_tipo, doc_nro, cond_iva_receptor,
                      importe_no_gravado, importe_neto, importe_iva, importe_exento, importe_total, servicio_desde, servicio_hasta, ?, ?
                 FROM comprobantes_venta WHERE id_comprobante = ?',
              [(int) $tal['id_talonario'], FACT_NC[$f['letra']], (int) $tal['punto_venta'], $tal['arca_ambiente'] ?: 'homo', usuario_id(), (int) $f['id_comprobante']]);
            $id = (int) $pdo->lastInsertId();
            q('INSERT INTO comprobantes_venta_items (id_comprobante, descripcion, id_prestamo, id_pago, alicuota_iva, no_gravado, neto, iva, total)
               SELECT ?, LEFT(CONCAT(\'Anulación \', descripcion), 200), id_prestamo, id_pago, alicuota_iva, no_gravado, neto, iva, total
                 FROM comprobantes_venta_items WHERE id_comprobante = ?', [$id, (int) $f['id_comprobante']]);
            $pdo->commit();
            $res['notas']++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $res['avisos'][] = 'Nota de crédito de ' . fact_nro($f) . ': ' . mensaje_bd($e);
        }
    }
    return $res;
}

// ── WSFEv1 ────────────────────────────────────────────────────────────

/** Un pedido a WSFEv1. $cuerpo: el XML de adentro del método (sin Auth). Devuelve el *Result como arreglo. */
function wsfe_llamar(string $amb, string $metodo, string $cuerpo = '', bool $conAuth = true, int $timeout = 90): array
{
    for ($intento = 1; $intento <= 2; $intento++) {
        $auth = '';
        if ($conAuth) {
            try {
                [$token, $sign] = arca_ticket('wsfe', $amb);
                $cuit = arca_credenciales($amb)['cuit'];
            } catch (ArcaError $e) {
                throw new ArcaError($e->getMessage());     // no salió nada
            }
            $auth = '<ar:Auth><ar:Token>' . htmlspecialchars($token, ENT_XML1) . '</ar:Token><ar:Sign>' . htmlspecialchars($sign, ENT_XML1)
                  . '</ar:Sign><ar:Cuit>' . $cuit . '</ar:Cuit></ar:Auth>';
        }
        $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="' . FACT_NS . '"><soapenv:Header/><soapenv:Body>'
               . '<ar:' . $metodo . '>' . $auth . $cuerpo . '</ar:' . $metodo . '></soapenv:Body></soapenv:Envelope>';
        $resp = null;
        $error = null;
        try {
            $resp = arca_soap(FACT_URL_WSFE[$amb], $sobre, FACT_NS . $metodo, $timeout);
        } catch (ArcaError $e) {
            $error = $e->getMessage();
            wsfe_log($amb, $metodo, $cuerpo, null, $error);
            throw $e;
        }
        wsfe_log($amb, $metodo, $cuerpo, $resp, null);
        if ($f = arca_fault($resp)) {
            throw new ArcaError('ARCA (' . $metodo . '): ' . $f);
        }
        $d = arca_dom($resp);
        $nodo = $d ? $d->getElementsByTagName($metodo . 'Result')->item(0) : null;
        if (!$nodo) {
            throw new ArcaError('ARCA respondió algo inesperado a ' . $metodo . '.');
        }
        $data = arca_xml_array($nodo);
        $data = is_array($data) ? $data : [];
        $codigos = array_map(function ($e) { return (int) ($e['Code'] ?? 0); }, arca_lista($data['Errors']['Err'] ?? null));
        if ($conAuth && in_array(600, $codigos, true) && $intento === 1) {
            q('DELETE FROM arca_tokens WHERE servicio = ?', ['wsfe' . ($amb === 'homo' ? '@homo' : '')]);
            continue;                                       // el ticket murió antes: otro y de nuevo
        }
        if (in_array(601, $codigos, true)) {
            throw new ArcaError('ARCA dice que el certificado no puede facturar por ese CUIT: falta delegarle el servicio «Facturación Electrónica» (wsfe). '
                              . wsfe_errores($data));
        }
        return $data;
    }
    return [];
}

function wsfe_errores(array $data): string
{
    return implode(' · ', array_map(function ($e) { return ($e['Code'] ?? '') . ': ' . ($e['Msg'] ?? ''); }, arca_lista($data['Errors']['Err'] ?? null)));
}

function wsfe_log(string $amb, string $metodo, string $pedido, ?string $resp, ?string $error): void
{
    try {
        q('INSERT INTO arca_wsfe_log (ambiente, metodo, pedido, respuesta, error, id_usuario) VALUES (?,?,?,?,?,?)',
          [$amb, $metodo, $pedido, $resp, $error !== null ? mb_substr($error, 0, 1000) : null, usuario_id()]);
    } catch (Throwable $e) {
    }
}

function wsfe_ultimo(string $amb, int $pv, int $tipo): int
{
    $d = wsfe_llamar($amb, 'FECompUltimoAutorizado', '<ar:PtoVta>' . $pv . '</ar:PtoVta><ar:CbteTipo>' . $tipo . '</ar:CbteTipo>');
    if (!empty($d['Errors'])) {
        throw new ArcaError('Último autorizado: ' . wsfe_errores($d));
    }
    return (int) ($d['CbteNro'] ?? 0);
}

/** El comprobante como lo tiene ARCA, o null si no existe. */
function wsfe_consultar(string $amb, int $pv, int $tipo, int $nro): ?array
{
    $d = wsfe_llamar($amb, 'FECompConsultar', '<ar:FeCompConsReq><ar:CbteTipo>' . $tipo . '</ar:CbteTipo><ar:CbteNro>' . $nro
                                            . '</ar:CbteNro><ar:PtoVta>' . $pv . '</ar:PtoVta></ar:FeCompConsReq>');
    $cod = array_map(function ($e) { return (int) ($e['Code'] ?? 0); }, arca_lista($d['Errors']['Err'] ?? null));
    if (in_array(602, $cod, true)) {
        return null;
    }
    if ($cod) {
        throw new ArcaError('Consulta: ' . wsfe_errores($d));
    }
    $r = $d['ResultGet'] ?? null;
    return is_array($r) ? $r : null;
}

function wsfe_dummy(string $amb): array
{
    return wsfe_llamar($amb, 'FEDummy', '', false, 20);
}

/** «20261123» -> «2026-11-23». */
function wsfe_fecha(?string $s): ?string
{
    return $s && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m) ? $m[1] . '-' . $m[2] . '-' . $m[3] : null;
}

/** La fecha a mandar: la del comprobante si ARCA la acepta; si no, la más cercana (y la nota). */
function fact_fecha_arca(string $fecha, ?string $ultimaFch, string $hoy): array
{
    $min = date('Y-m-d', strtotime($hoy . ' -' . FACT_DIAS . ' days'));
    if ($ultimaFch && $ultimaFch > $min) {
        $min = $ultimaFch;
    }
    $max = date('Y-m-d', strtotime($hoy . ' +' . FACT_DIAS . ' days'));
    $f = min(max($fecha, $min), $max);
    return [$f, $f === $fecha ? null : 'Fecha llevada del ' . fecha($fecha) . ' al ' . fecha($f) . ' (ARCA acepta hasta ' . FACT_DIAS
                                     . ' días del envío y no antes del último autorizado).'];
}

/** El FECAEDetRequest de un comprobante (sin número), o los errores por los que no sale. */
function fact_detalle(array $c, array $tal, string $fch, ?array $asoc, string $cuitEmisor): array
{
    $err = [];
    $items = q('SELECT alicuota_iva, SUM(neto) AS neto, SUM(iva) AS iva FROM comprobantes_venta_items WHERE id_comprobante = ?
                 GROUP BY alicuota_iva HAVING SUM(neto) <> 0 OR SUM(iva) <> 0', [(int) $c['id_comprobante']]);
    $suma = round((float) $c['importe_no_gravado'] + (float) $c['importe_neto'] + (float) $c['importe_iva'] + (float) $c['importe_exento'], 2);
    if ((float) $c['importe_total'] <= 0) {
        $err[] = 'el total tiene que ser mayor que cero';
    }
    if (abs($suma - (float) $c['importe_total']) > 0.004) {
        $err[] = 'los importes no suman el total';
    }
    if (in_array((int) $c['doc_tipo'], [80, 86], true) && !cuit_valido((string) $c['doc_nro'])) {
        $err[] = 'el CUIT/CUIL del receptor no es válido';
    }
    $claseA = in_array((int) $c['cond_iva_receptor'], FACT_CLASE_A, true);
    if ($c['letra'] === 'A' && !$claseA) {
        $err[] = 'un comprobante A va a un responsable inscripto o monotributista';
    }
    if ($c['letra'] === 'B' && $claseA) {
        $err[] = 'a un responsable inscripto o monotributista le corresponde un comprobante A';
    }
    $iva = '';
    foreach ($items as $it) {
        $id = FACT_ALICUOTAS[number_format((float) $it['alicuota_iva'], 2, '.', '')] ?? null;
        if (!$id) {
            $err[] = 'alícuota de IVA ' . $it['alicuota_iva'] . ' % sin código de ARCA';
            continue;
        }
        $iva .= '<ar:AlicIva><ar:Id>' . $id . '</ar:Id><ar:BaseImp>' . wsfe_n($it['neto']) . '</ar:BaseImp><ar:Importe>' . wsfe_n($it['iva']) . '</ar:Importe></ar:AlicIva>';
    }
    $sumaIva = round(array_sum(array_column($items, 'iva')), 2);
    if (abs($sumaIva - (float) $c['importe_iva']) > 0.004) {
        $err[] = 'el IVA de los renglones no es el del comprobante';
    }
    $concepto = (int) ($tal['arca_concepto'] ?: 2);
    $x = '<ar:FECAEDetRequest><ar:Concepto>' . $concepto . '</ar:Concepto><ar:DocTipo>' . (int) $c['doc_tipo'] . '</ar:DocTipo><ar:DocNro>' . $c['doc_nro'] . '</ar:DocNro>'
       . '<ar:CbteDesde>{N}</ar:CbteDesde><ar:CbteHasta>{N}</ar:CbteHasta><ar:CbteFch>' . str_replace('-', '', $fch) . '</ar:CbteFch>'
       . '<ar:ImpTotal>' . wsfe_n($c['importe_total']) . '</ar:ImpTotal><ar:ImpTotConc>' . wsfe_n($c['importe_no_gravado']) . '</ar:ImpTotConc>'
       . '<ar:ImpNeto>' . wsfe_n($c['importe_neto']) . '</ar:ImpNeto><ar:ImpOpEx>' . wsfe_n($c['importe_exento']) . '</ar:ImpOpEx>'
       . '<ar:ImpTrib>0.00</ar:ImpTrib><ar:ImpIVA>' . wsfe_n($c['importe_iva']) . '</ar:ImpIVA>';
    if ($concepto !== 1) {
        $vto = max($fch, (string) $c['fecha']);
        $x .= '<ar:FchServDesde>' . str_replace('-', '', (string) $c['servicio_desde']) . '</ar:FchServDesde><ar:FchServHasta>'
            . str_replace('-', '', (string) $c['servicio_hasta']) . '</ar:FchServHasta><ar:FchVtoPago>' . str_replace('-', '', $vto) . '</ar:FchVtoPago>';
    }
    $x .= '<ar:MonId>PES</ar:MonId><ar:MonCotiz>1</ar:MonCotiz><ar:CondicionIVAReceptorId>' . (int) $c['cond_iva_receptor'] . '</ar:CondicionIVAReceptorId>';
    if ($c['id_asociado']) {
        if (!$asoc || $asoc['arca_estado'] !== 'AUTORIZADO' || !(int) $asoc['numero']) {
            $err[] = 'la factura que anula no tiene CAE';
        } else {
            $x .= '<ar:CbtesAsoc><ar:CbteAsoc><ar:Tipo>' . (int) $asoc['cbte_tipo'] . '</ar:Tipo><ar:PtoVta>' . (int) $asoc['punto_venta'] . '</ar:PtoVta><ar:Nro>'
                . (int) $asoc['numero'] . '</ar:Nro><ar:Cuit>' . $cuitEmisor . '</ar:Cuit><ar:CbteFch>' . str_replace('-', '', (string) $asoc['fecha'])
                . '</ar:CbteFch></ar:CbteAsoc></ar:CbtesAsoc>';
        }
    }
    if ($iva !== '') {
        $x .= '<ar:Iva>' . $iva . '</ar:Iva>';
    }
    $act = (int) (q1('SELECT arca_actividad FROM empresa LIMIT 1')['arca_actividad'] ?? 0);
    if ($act) {
        $x .= '<ar:Actividades><ar:Actividad><ar:Id>' . $act . '</ar:Id></ar:Actividad></ar:Actividades>';
    }
    return [$x . '</ar:FECAEDetRequest>', $err];
}

function wsfe_n($v): string
{
    return number_format(round((float) $v, 2), 2, '.', '');
}

// ── Autorización ──────────────────────────────────────────────────────

/**
 * Pide CAE para lo pendiente, talonario por talonario, hasta $segundos.
 * Devuelve ['autorizados', 'rechazados', 'mensajes' => [...]].
 */
function fact_autorizar(int $segundos = 60, ?array $soloTalonarios = null): array
{
    $res = ['autorizados' => 0, 'rechazados' => 0, 'mensajes' => []];
    $fin = time() + $segundos;
    $tals = q("SELECT t.* FROM talonarios t
                WHERE t.electronico = 1 AND EXISTS (SELECT 1 FROM comprobantes_venta v WHERE v.id_talonario = t.id_talonario
                                                    AND v.arca_estado IN ('PENDIENTE','ENVIANDO'))
                ORDER BY FIELD(t.codigo_arca, '001', '006', '003', '008'), t.id_talonario");
    foreach ($tals as $tal) {
        if ($soloTalonarios !== null && !in_array((int) $tal['id_talonario'], $soloTalonarios, true)) {
            continue;
        }
        $amb = $tal['arca_ambiente'] === 'prod' ? 'prod' : 'homo';
        if ($amb === 'prod' && !cfg('arca_wsfe_produccion', false)) {
            $res['mensajes'][] = $tal['nombre'] . ': es de producción y este servidor no está habilitado para facturar en producción '
                               . '(arca_wsfe_produccion en config.php / FIN_ARCA_WSFE_PRODUCCION en Railway).';
            continue;
        }
        try {
            fact_autorizar_talonario($tal, $amb, $fin, $res);
        } catch (ArcaError $e) {
            $res['mensajes'][] = $tal['nombre'] . ': ' . $e->getMessage();
        }
        if (time() >= $fin) {
            $res['mensajes'][] = 'Se cortó por tiempo: volvé a autorizar para seguir.';
            break;
        }
    }
    return $res;
}

function fact_autorizar_talonario(array $tal, string $amb, int $fin, array &$res): void
{
    $idT = (int) $tal['id_talonario'];
    $pv = (int) $tal['punto_venta'];
    $tipo = (int) $tal['codigo_arca'];
    $cuitEmisor = arca_credenciales($amb)['cuit'];
    $ultimo = wsfe_ultimo($amb, $pv, $tipo);

    // 1. Los que quedaron ENVIANDO: ¿ARCA los tiene?
    foreach (q("SELECT * FROM comprobantes_venta WHERE id_talonario = ? AND arca_estado = 'ENVIANDO' ORDER BY numero", [$idT]) as $c) {
        $a = (int) $c['numero'] <= $ultimo ? wsfe_consultar($amb, $pv, $tipo, (int) $c['numero']) : null;
        if ($a && abs((float) ($a['ImpTotal'] ?? 0) - (float) $c['importe_total']) < 0.01 && (string) ($a['DocNro'] ?? '') === (string) $c['doc_nro']) {
            q("UPDATE comprobantes_venta SET arca_estado = 'AUTORIZADO', cae = ?, cae_vto = ?, fecha = COALESCE(?, fecha), arca_autorizado_el = NOW(),
                      arca_obs = CONCAT_WS(' · ', arca_obs, 'CAE recuperado de ARCA') WHERE id_comprobante = ?",
              [(string) ($a['CodAutorizacion'] ?? ''), wsfe_fecha($a['FchVto'] ?? null), wsfe_fecha($a['CbteFch'] ?? null), (int) $c['id_comprobante']]);
            $res['autorizados']++;
        } elseif ($a) {
            throw new ArcaError('ARCA tiene el número ' . $c['numero'] . ' con otros datos que ' . fact_nro($c) . ': revisalo antes de seguir.');
        } elseif (strtotime((string) $c['arca_enviado_el']) < time() - FACT_ESPERA_SEG) {
            q("UPDATE comprobantes_venta SET arca_estado = 'PENDIENTE', numero = 0, arca_enviado_el = NULL WHERE id_comprobante = ?", [(int) $c['id_comprobante']]);
        } else {
            $res['mensajes'][] = $tal['nombre'] . ': hay comprobantes enviados hace poco sin respuesta; se espera unos minutos antes de seguir.';
            return;
        }
    }

    // 2. El talonario y ARCA tienen que decir lo mismo.
    $propio = (int) (q1("SELECT MAX(numero) AS n FROM comprobantes_venta WHERE id_talonario = ? AND arca_estado = 'AUTORIZADO'", [$idT])['n'] ?? 0);
    if ($ultimo !== $propio) {
        if ($amb === 'prod') {
            throw new ArcaError('ARCA tiene como último autorizado el ' . $ultimo . ' y el sistema el ' . $propio
                              . ' (punto de venta ' . $pv . '). Hay comprobantes que el sistema no tiene: no se autoriza nada hasta aclararlo.');
        }
        $res['mensajes'][] = $tal['nombre'] . ' (homologación): ARCA va por el ' . $ultimo . '; se sigue desde ahí.';
    }
    q('UPDATE talonarios SET ultimo_emitido = ? WHERE id_talonario = ?', [$ultimo, $idT]);
    $ultimaFch = q1("SELECT MAX(fecha) AS f FROM comprobantes_venta WHERE id_talonario = ? AND arca_estado = 'AUTORIZADO'", [$idT])['f'] ?? null;

    // 3. Tandas.
    while (time() < $fin) {
        $pend = q("SELECT * FROM comprobantes_venta WHERE id_talonario = ? AND arca_estado = 'PENDIENTE' ORDER BY fecha, id_comprobante LIMIT " . FACT_LOTE, [$idT]);
        if (!$pend) {
            return;
        }
        $hoy = date('Y-m-d');
        $dets = [];
        $tanda = [];
        $n = $ultimo;
        foreach ($pend as $c) {
            $asoc = $c['id_asociado'] ? q1('SELECT * FROM comprobantes_venta WHERE id_comprobante = ?', [(int) $c['id_asociado']]) : null;
            [$fch, $nota] = fact_fecha_arca((string) $c['fecha'], $ultimaFch, $hoy);
            [$det, $err] = fact_detalle($c, $tal, $fch, $asoc, $cuitEmisor);
            if ($err) {
                if (!$tanda) {
                    q("UPDATE comprobantes_venta SET arca_estado = 'RECHAZADO', arca_obs = ? WHERE id_comprobante = ?",
                      [mb_substr('No se mandó: ' . implode('; ', $err), 0, 1000), (int) $c['id_comprobante']]);
                    $res['rechazados']++;
                    continue 2;                             // la próxima tanda sigue sin éste
                }
                break;                                      // se manda lo anterior; éste, la próxima
            }
            $n++;
            $ultimaFch = $fch;
            $dets[] = str_replace('{N}', (string) $n, $det);
            $tanda[] = ['c' => $c, 'n' => $n, 'fch' => $fch, 'nota' => $nota];
        }
        if (!$tanda) {
            return;
        }
        // Número y ENVIANDO antes de mandar.
        foreach ($tanda as $t) {
            q("UPDATE comprobantes_venta SET numero = ?, arca_estado = 'ENVIANDO', arca_enviado_el = NOW() WHERE id_comprobante = ? AND arca_estado = 'PENDIENTE'",
              [$t['n'], (int) $t['c']['id_comprobante']]);
        }
        $cuerpo = '<ar:FeCAEReq><ar:FeCabReq><ar:CantReg>' . count($dets) . '</ar:CantReg><ar:PtoVta>' . $pv . '</ar:PtoVta><ar:CbteTipo>' . $tipo
                . '</ar:CbteTipo></ar:FeCabReq><ar:FeDetReq>' . implode('', $dets) . '</ar:FeDetReq></ar:FeCAEReq>';
        try {
            $d = wsfe_llamar($amb, 'FECAESolicitar', $cuerpo);
        } catch (ArcaSinRespuesta $e) {
            $res['mensajes'][] = $tal['nombre'] . ': ' . $e->getMessage() . ' Quedan «enviando»; la próxima vez se le pregunta a ARCA.';
            return;
        } catch (ArcaError $e) {
            // No llegó a procesarse: vuelven a la cola sin número.
            foreach ($tanda as $t) {
                q("UPDATE comprobantes_venta SET numero = 0, arca_estado = 'PENDIENTE', arca_enviado_el = NULL WHERE id_comprobante = ?", [(int) $t['c']['id_comprobante']]);
            }
            throw $e;
        }
        $porNro = [];
        foreach (arca_lista($d['FeDetResp']['FECAEDetResponse'] ?? null) as $r) {
            $porNro[(int) ($r['CbteDesde'] ?? 0)] = $r;
        }
        $errCab = wsfe_errores($d);
        $corto = false;
        foreach ($tanda as $t) {
            $id = (int) $t['c']['id_comprobante'];
            $r = $porNro[$t['n']] ?? null;
            if (!$corto && $r && ($r['Resultado'] ?? '') === 'A') {
                $obs = implode(' · ', array_map(function ($o) { return ($o['Code'] ?? '') . ': ' . ($o['Msg'] ?? ''); },
                                                arca_lista($r['Observaciones']['Obs'] ?? null)));
                q("UPDATE comprobantes_venta SET arca_estado = 'AUTORIZADO', cae = ?, cae_vto = ?, fecha = ?, arca_autorizado_el = NOW(),
                          arca_obs = NULLIF(CONCAT_WS(' · ', ?, ?), '') WHERE id_comprobante = ?",
                  [(string) $r['CAE'], wsfe_fecha($r['CAEFchVto'] ?? null), wsfe_fecha($r['CbteFch'] ?? null) ?? $t['fch'],
                   $t['nota'], $obs !== '' ? $obs : null, $id]);
                q('UPDATE talonarios SET ultimo_emitido = GREATEST(ultimo_emitido, ?) WHERE id_talonario = ?', [$t['n'], $idT]);
                $ultimo = max($ultimo, $t['n']);
                $res['autorizados']++;
            } elseif (!$corto) {
                $obs = $r ? implode(' · ', array_map(function ($o) { return ($o['Code'] ?? '') . ': ' . ($o['Msg'] ?? ''); },
                                                     arca_lista($r['Observaciones']['Obs'] ?? null))) : '';
                q("UPDATE comprobantes_venta SET arca_estado = 'RECHAZADO', numero = 0, arca_enviado_el = NULL, arca_obs = ? WHERE id_comprobante = ?",
                  [mb_substr('ARCA lo rechazó: ' . (trim($obs . ' ' . $errCab) ?: 'sin motivo'), 0, 1000), $id]);
                $res['rechazados']++;
                $corto = true;
            } else {
                q("UPDATE comprobantes_venta SET arca_estado = 'PENDIENTE', numero = 0, arca_enviado_el = NULL WHERE id_comprobante = ?", [$id]);
            }
        }
        if ($corto) {
            $res['mensajes'][] = $tal['nombre'] . ': ARCA rechazó un comprobante; se cortó la tanda (mirá el motivo en «Rechazados»).';
            return;
        }
    }
}

/** Vuelve a la cola los rechazados elegidos. */
function fact_reintentar(array $ids): int
{
    if (!$ids) {
        return 0;
    }
    q("UPDATE comprobantes_venta SET arca_estado = 'PENDIENTE', numero = 0
        WHERE arca_estado = 'RECHAZADO' AND id_comprobante IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', array_map('intval', $ids));
    return (int) db()->query('SELECT ROW_COUNT()')->fetchColumn();
}
