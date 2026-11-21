<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/inversiones.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Aporte de un inversor
//   inversion.php?nueva=1[&inversor=N]   alta
//   inversion.php?id=N                   ficha (devengado, préstamos fondeados, liquidaciones)
//   inversion.php?id=N&liq_fecha=…       vista previa de la liquidación
//   inversion.php?id=N&export=pdf        constancia del aporte
//   inversion.php?id=N&liq=L&export=pdf  liquidación en PDF
//   POST accion = crear | liquidar | pagar | anular_liq | anular
// Con Tesorería, el capital del aporte entra en una cuenta de fondos y el
// pago al inversor sale de otra (de la misma moneda): los dos quedan en el
// libro de fondos (sp_fondos_aporte, sp_fondos_pago_inversor). Las
// renovaciones no mueven plata.
// ---------------------------------------------------------------------

$id      = (int) get_str('id');
$nueva   = get_str('nueva') === '1' && $id === 0;
$errores = [];
$hoy     = date('Y-m-d');

/** Lista POST como textos. */
function post_lista_txt(string $k): array
{
    $v = $_POST[$k] ?? [];
    return is_array($v) ? array_values(array_map(function ($x) { return is_string($x) ? trim($x) : ''; }, $v)) : [];
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
$f = ['inversor' => get_str('inversor'), 'modalidad' => 'TASA_FIJA', 'moneda' => 'ARS', 'capital' => '', 'fecha_inicio' => $hoy,
      'plazo' => '90', 'fecha_vencimiento' => '', 'tna' => '', 'pct' => '', 'observaciones' => '', 'asig' => [],
      'cuenta' => '', 'medio_ingreso' => 'TRANSFERENCIA', 'ref_ingreso' => ''];

// Tesorería: las cuentas de fondos habilitadas (de las dos monedas) y la predeterminada de cada moneda.
$conFondos = false;
$cuentasInv = $predet = $nombresCuenta = [];
try {
    if (fondos_instalado()) {
        $conFondos = true;
        $cuentasInv = cuentas_fondos();
        $nombresCuenta = array_column(cuentas_fondos(null, true), 'nombre', 'id_cuenta_fondos');
        foreach (array_keys(MONEDAS) as $m) {
            $predet[$m] = cuenta_predeterminada($m);
        }
    }
} catch (Throwable $e) {
    error_log('[financiera] cuentas de fondos: ' . $e->getMessage());
}
$f['cuenta'] = (string) ($predet['ARS'] ?? '');
if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'crear') {
        $f = ['inversor' => post_str('inversor'), 'modalidad' => post_str('modalidad'), 'moneda' => post_str('moneda'),
              'capital' => post_str('capital'), 'fecha_inicio' => post_str('fecha_inicio'), 'plazo' => post_str('plazo'),
              'fecha_vencimiento' => post_str('fecha_vencimiento'), 'tna' => post_str('tna'), 'pct' => post_str('pct'),
              'observaciones' => post_str('observaciones'), 'asig' => [],
              'cuenta' => post_str('cuenta'), 'medio_ingreso' => post_str('medio_ingreso'), 'ref_ingreso' => post_str('ref_ingreso')];
        $nueva = true;
        $ps = post_lista_txt('asig_prestamo');
        $ms = post_lista_txt('asig_monto');
        foreach ($ps as $k => $pid) {
            if ($pid !== '' || ($ms[$k] ?? '') !== '') {
                $f['asig'][] = ['prestamo' => (int) $pid, 'monto' => $ms[$k] ?? ''];
            }
        }
        $cap = leer_num($f['capital']);
        $ini = leer_fecha($f['fecha_inicio']);
        $vto = leer_fecha($f['fecha_vencimiento']);
        if ($vto === null && $ini !== null && ctype_digit($f['plazo']) && (int) $f['plazo'] > 0) {
            $vto = date('Y-m-d', strtotime($ini . ' +' . (int) $f['plazo'] . ' days'));
        }
        $inversor = (int) $f['inversor'] > 0 ? q1('SELECT * FROM inversores WHERE id_inversor = ?', [(int) $f['inversor']]) : null;
        if (!$inversor) {
            $errores[] = 'Elegí el inversor.';
        } elseif ($inversor['estado'] !== 'ACTIVO') {
            $errores[] = 'El inversor está inactivo.';
        }
        if (!isset(MODALIDADES[$f['modalidad']])) {
            $errores[] = 'Elegí la modalidad.';
        }
        if (!isset(MONEDAS[$f['moneda']])) {
            $errores[] = 'Elegí la moneda.';
        } elseif ($f['modalidad'] === 'PARTICIPACION' && $f['moneda'] !== 'ARS') {
            $errores[] = 'La participación es solo en pesos: los préstamos son en pesos.';
        }
        if ($cap === null || $cap <= 0) {
            $errores[] = 'Escribí el capital.';
        }
        if ($cuentasInv) {
            $ci = $cuentasInv[(int) $f['cuenta']] ?? null;
            if (!$ci) {
                $errores[] = 'Elegí la cuenta de fondos donde ingresa el capital.';
            } elseif (isset(MONEDAS[$f['moneda']]) && $ci['moneda'] !== $f['moneda']) {
                $errores[] = 'El aporte es en ' . strtolower(MONEDAS[$f['moneda']]) . ' y la cuenta ' . $ci['nombre'] . ' en ' . strtolower(MONEDAS[$ci['moneda']]) . '.';
            }
            if (!isset(MEDIOS_PAGO_INV[$f['medio_ingreso']])) {
                $errores[] = 'Elegí cómo ingresó el capital.';
            }
        }
        if ($ini === null || $ini > date('Y-m-d', strtotime('+30 days'))) {
            $errores[] = 'La fecha de inicio no es válida.';
        }
        if ($vto === null || ($ini !== null && $vto <= $ini)) {
            $errores[] = 'El vencimiento tiene que ser posterior al inicio (poné el plazo en días o la fecha).';
        }
        $tna = $pct = null;
        if ($f['modalidad'] === 'TASA_FIJA') {
            $tna = leer_num($f['tna']);
            if ($tna === null || $tna <= 0 || $tna > 1000) {
                $errores[] = 'Escribí la TNA pactada (en %).';
            }
        } elseif ($f['modalidad'] === 'PARTICIPACION') {
            $pct = leer_num($f['pct']);
            if ($pct === null || $pct <= 0 || $pct > 100) {
                $errores[] = 'Escribí qué % del interés cobrado le corresponde (1 a 100).';
            }
            if (!$f['asig']) {
                $errores[] = 'Elegí los préstamos que fondea este aporte.';
            }
            $libres = array_column(prestamos_para_fondear(), null, 'id_prestamo');
            $suma = 0.0;
            $vistos = [];
            foreach ($f['asig'] as $k => $a) {
                $m = leer_num($a['monto']);
                $r = 'Préstamo ' . ($k + 1) . ': ';
                if (!isset($libres[$a['prestamo']])) {
                    $errores[] = $r . 'no está vigente o ya está fondeado del todo.';
                } elseif (isset($vistos[$a['prestamo']])) {
                    $errores[] = $r . 'está repetido.';
                } elseif ($m === null || $m <= 0) {
                    $errores[] = $r . 'falta el monto.';
                } elseif ($m > (float) $libres[$a['prestamo']]['libre'] + 0.004) {
                    $errores[] = $r . $libres[$a['prestamo']]['nro_prestamo'] . ' tiene ' . money($libres[$a['prestamo']]['libre']) . ' libres para fondear.';
                }
                $vistos[$a['prestamo']] = true;
                $suma += (float) $m;
            }
            if ($cap !== null && $suma > $cap + 0.004) {
                $errores[] = 'Lo asignado a préstamos (' . money($suma) . ') supera el capital del aporte.';
            }
        }

        if (!$errores) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                q('INSERT INTO inversiones (id_inversor, modalidad, moneda, capital, fecha_inicio, fecha_vencimiento, tna, pct_participacion, observaciones, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [(int) $f['inversor'], $f['modalidad'], $f['moneda'], round($cap, 2), $ini, $vto,
                   $tna === null ? null : round($tna / 100, 6), $pct === null ? null : round($pct / 100, 6), $f['observaciones'] ?: null, usuario_id()]);
                $id = (int) $pdo->lastInsertId();
                if ($cuentasInv) {
                    q('UPDATE inversiones SET id_cuenta_fondos = ?, medio_ingreso = ?, referencia_ingreso = ? WHERE id_inversion = ?',
                      [(int) $f['cuenta'], $f['medio_ingreso'], $f['ref_ingreso'] !== '' ? mb_substr($f['ref_ingreso'], 0, 100) : null, $id]);
                }
                $nro = parametro('NRO_INVERSION_PREFIJO', 'I-') . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                q('UPDATE inversiones SET nro_inversion = ? WHERE id_inversion = ?', [$nro, $id]);
                foreach ($f['asig'] as $a) {
                    q('INSERT INTO inversiones_prestamos (id_inversion, id_prestamo, monto) VALUES (?,?,?)',
                      [$id, $a['prestamo'], round(leer_num($a['monto']), 2)]);
                }
                // Control final con el préstamo bloqueado: nadie lo fondeó de más mientras tanto
                if ($f['asig']) {
                    $exceso = q1("SELECT p.nro_prestamo FROM prestamos p
                                   WHERE p.id_prestamo IN (" . implode(',', array_map('intval', array_column($f['asig'], 'prestamo'))) . ")
                                     AND p.capital < (SELECT SUM(ip.monto) FROM inversiones_prestamos ip
                                                       JOIN inversiones i ON i.id_inversion = ip.id_inversion AND i.estado = 'VIGENTE'
                                                      WHERE ip.id_prestamo = p.id_prestamo)
                                   LIMIT 1 FOR UPDATE");
                    if ($exceso) {
                        throw new RuntimeException('El préstamo ' . $exceso['nro_prestamo'] . ' quedaría fondeado por más que su capital.');
                    }
                }
                if ($conFondos) {
                    fondos_call('CALL sp_fondos_aporte(?)', [$id]);      // entra el capital al libro de fondos
                }
                $pdo->commit();
                flash('ok', 'Aporte ' . $nro . ' cargado: ' . dinero($cap, $f['moneda']) . ' hasta el ' . fecha($vto) . '.');
                redirigir('inversion.php?id=' . $id);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e);
                $id = 0;
            }
        }
    } elseif ($id > 0 && $accion === 'liquidar') {
        try {
            $st = db()->prepare('CALL sp_liquidar_inversion(?, ?, ?, ?, ?, @liq, @nueva)');
            $dias = post_str('dias');
            $st->execute([$id, leer_fecha(post_str('fecha')), post_str('renovacion'), ctype_digit($dias) ? (int) $dias : null, usuario_id()]);
            while ($st->nextRowset()) {
            }
            $st->closeCursor();
            $r = q1('SELECT @liq AS liq, @nueva AS nueva');
            $nuevaInv = $r['nueva'] ? q1('SELECT nro_inversion FROM inversiones WHERE id_inversion = ?', [(int) $r['nueva']]) : null;
            flash('ok', 'Aporte liquidado.' . ($nuevaInv ? ' Se renovó como ' . $nuevaInv['nro_inversion'] . '.' : ''));
            redirigir('inversion.php?id=' . $id . '#liquidaciones');
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
        }
    } elseif ($id > 0 && $accion === 'pagar') {
        $fp = leer_fecha(post_str('fecha_pago'));
        $idLiq = (int) post_str('id_liquidacion');
        $liqP = q1('SELECT moneda FROM inversiones_liquidaciones WHERE id_liquidacion = ? AND id_inversion = ?', [$idLiq, $id]);
        $ctaP = $cuentasInv[(int) post_str('cuenta')] ?? null;
        if ($fp === null || $fp > $hoy || !isset(MEDIOS_PAGO_INV[post_str('medio')])) {
            $errores[] = 'Para registrar el pago hacen falta la fecha (no futura) y el medio.';
        } elseif ($cuentasInv && (!$ctaP || !$liqP || $ctaP['moneda'] !== $liqP['moneda'])) {
            $errores[] = 'Elegí la cuenta de fondos de la que sale el pago (de la misma moneda que la liquidación).';
        } elseif ($cuentasInv && post_str('medio') === 'CHEQUE' && !preg_match('/^\d{1,18}$/', post_str('referencia'))) {
            $errores[] = 'En un pago con cheque, la referencia es el número del cheque (solo números).';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare("UPDATE inversiones_liquidaciones SET estado = 'PAGADA', pagado_el = ?, medio = ?, referencia = ?
                                      WHERE id_liquidacion = ? AND id_inversion = ? AND estado = 'A_PAGAR'");
                $st->execute([$fp, post_str('medio'), substr(post_str('referencia'), 0, 100) ?: null, $idLiq, $id]);
                $pagada = $st->rowCount() > 0;
                if ($pagada && $conFondos) {
                    q('UPDATE inversiones_liquidaciones SET id_cuenta_fondos = ? WHERE id_liquidacion = ?', [$ctaP ? (int) $ctaP['id_cuenta_fondos'] : null, $idLiq]);
                    fondos_call('CALL sp_fondos_pago_inversor(?)', [$idLiq]);   // sale el pago del libro de fondos
                }
                $pdo->commit();
                flash($pagada ? 'ok' : 'warning', $pagada ? 'Pago al inversor registrado' . ($ctaP ? ': sale de ' . $ctaP['nombre'] : '') . '.' : 'Esa liquidación ya no estaba a pagar.');
                redirigir('inversion.php?id=' . $id . '#liquidaciones');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    } elseif ($id > 0 && $accion === 'anular_liq') {
        try {
            $st = db()->prepare('CALL sp_anular_liquidacion_inversion(?, ?, ?)');
            $st->execute([(int) post_str('id_liquidacion'), post_str('motivo'), usuario_id()]);
            while ($st->nextRowset()) {
            }
            $st->closeCursor();
            flash('ok', 'Liquidación anulada: el aporte volvió a estar vigente.');
            redirigir('inversion.php?id=' . $id);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
        }
    } elseif ($id > 0 && $accion === 'anular') {
        $mot = post_str('motivo');
        try {
            if (strlen($mot) < 5) {
                throw new RuntimeException('Indicá el motivo de la anulación.');
            }
            if (q1("SELECT 1 AS x FROM inversiones_liquidaciones WHERE id_inversion = ? AND estado <> 'ANULADA'", [$id])) {
                throw new RuntimeException('El aporte tiene una liquidación: anulala primero.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $st = $pdo->prepare("UPDATE inversiones SET estado = 'ANULADA', anulado_el = NOW(), motivo_anulacion = ? WHERE id_inversion = ? AND estado = 'VIGENTE'");
            $st->execute([$mot, $id]);
            if ($st->rowCount() && $conFondos) {
                fondos_call('CALL sp_fondos_anular(?, ?, ?, ?)', ['APORTE', $id, mb_substr($mot, 0, 200), usuario_id()]);   // el capital sale del saldo
            }
            $pdo->commit();
            flash('ok', 'Aporte anulado.');
            redirigir('inversion.php?id=' . $id);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $errores[] = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e);
        }
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$i = null;
$calc = null;
$det = [];
$liqs = [];
$preview = null;
$previewDet = [];
$fondeables = $inversores = [];
try {
    if ($nueva) {
        $inversores = q("SELECT id_inversor, denominacion, cuit FROM inversores WHERE estado = 'ACTIVO' ORDER BY denominacion");
        $fondeables = prestamos_para_fondear();
    } elseif ($id > 0) {
        $i = q1('SELECT i.*, v.denominacion, v.cuit, v.cbu, v.alias_cbu, o.nro_inversion AS nro_origen
                   FROM inversiones i JOIN inversores v ON v.id_inversor = i.id_inversor
                   LEFT JOIN inversiones o ON o.id_inversion = i.id_inversion_origen
                  WHERE i.id_inversion = ?', [$id]);
        if ($i) {
            $liqs = q('SELECT l.*, n.nro_inversion AS nro_nueva FROM inversiones_liquidaciones l
                         LEFT JOIN inversiones n ON n.id_inversion = l.id_inversion_nueva
                        WHERE l.id_inversion = ? ORDER BY l.id_liquidacion DESC', [$id]);
            $hasta = min($hoy, $i['fecha_vencimiento']);
            if ($hasta > $i['fecha_inicio']) {
                [$calc, $det] = inversion_calcular($id, $hasta);
            } else {
                [$calc, $det] = [null, q('SELECT ip.id_prestamo, ip.monto AS monto_asignado, p.capital AS capital_prestamo, 0 AS interes_cobrado,
                                                 ROUND(ip.monto / p.capital, 6) AS proporcion, 0 AS interes_inversor, p.nro_prestamo,
                                                 p.estado AS estado_prestamo, c.denominacion AS cliente
                                            FROM inversiones_prestamos ip JOIN prestamos p ON p.id_prestamo = ip.id_prestamo
                                            JOIN clientes c ON c.id_cliente = p.id_cliente WHERE ip.id_inversion = ?', [$id])];
            }
            $lf = leer_fecha(get_str('liq_fecha'));
            if ($i['estado'] === 'VIGENTE' && $lf !== null) {
                if ($lf > $hoy) {
                    $errores[] = 'No se puede liquidar con fecha futura.';
                } elseif ($lf <= $i['fecha_inicio']) {
                    $errores[] = 'La liquidación tiene que ser posterior al inicio.';
                } else {
                    [$preview, $previewDet] = inversion_calcular($id, $lf);
                }
            }
        }
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas: corré sql\financiera_inversores.sql.' : mensaje_bd($e);
}

$cotHoy = $i ? cotizacion_segura($i['moneda']) : null;
$cotHoyFecha = null;
if ($i && $i['moneda'] !== 'ARS') {
    try {
        $cotHoyFecha = q1('SELECT MAX(fecha) AS f FROM cotizaciones WHERE moneda = ? AND fecha <= CURDATE()', [$i['moneda']])['f'] ?? null;
    } catch (Throwable $e) {
    }
}
$cotPrev = $preview ? cotizacion_segura($i['moneda'], $preview['fecha']) : null;

$estimado = $i && $i['modalidad'] === 'TASA_FIJA'
    ? round((float) $i['capital'] * (float) $i['tna'] * ((strtotime($i['fecha_vencimiento']) - strtotime($i['fecha_inicio'])) / 86400) / (int) parametro('INVERSION_DIAS_ANIO', '365'), 2)
    : null;

// ---------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------
if ($i && get_str('export') === 'pdf') {
    require_once __DIR__ . '/../inc/pdf.php';
    $mon = $i['moneda'];
    $idLiq = (int) get_str('liq');
    $l = null;
    foreach ($liqs as $x) {
        if ((int) $x['id_liquidacion'] === $idLiq) {
            $l = $x;
        }
    }
    $cols = [['Préstamo', 1, 'L'], ['Tomador', 2.4, 'L'], ['Fondeado', 1.3, 'R'], ['Capital del préstamo', 1.4, 'R'],
             ['Interés cobrado', 1.3, 'R'], ['Proporción', 0.9, 'R'], ['Para el inversor', 1.3, 'R']];
    $filaDet = function ($d) {
        return [$d['nro_prestamo'] ?? '', $d['cliente'] ?? '', money($d['monto_asignado']), money($d['capital_prestamo']),
                money($d['interes_cobrado']), pct($d['proporcion']), money($d['interes_inversor'])];
    };
    if ($l) {
        $rep = new PdfReporte('Liquidación de intereses · aporte ' . $i['nro_inversion'] . ($l['estado'] === 'ANULADA' ? ' · ANULADA' : ''),
                              $i['denominacion'] . ' · CUIT/CUIL ' . cuit_fmt($i['cuit']), false);
        $rep->indicadores([['Capital', dinero($l['capital'], $mon)], ['Intereses', dinero($l['interes'], $mon)], ['A pagar', dinero($l['a_pagar'], $mon)]]);
        $rep->nota('Período: del ' . fecha($i['fecha_inicio']) . ' al ' . fecha($l['fecha']) . ' (' . (int) $l['dias'] . ' días)'
                 . ($l['anticipada'] ? ' · liquidación anticipada (vencía el ' . fecha($i['fecha_vencimiento']) . ')' : '') . '.');
        $rep->nota(MODALIDADES[$i['modalidad']] . ': ' . condicion_inversion($i) . ($i['modalidad'] === 'TASA_FIJA' ? ' · interés simple, base ' . parametro('INVERSION_DIAS_ANIO', '365') . ' días' : '') . '.');
        $rep->nota('Renovación: ' . RENOVACIONES[$l['renovacion']] . ($l['nro_nueva'] ? ' → aporte ' . $l['nro_nueva'] . ' por ' . dinero($l['reinvertido'], $mon) : '') . '.');
        if ($mon !== 'ARS') {
            $rep->nota($l['cotizacion']
                ? 'Equivalente en pesos (' . cot_txt($mon, (float) $l['cotizacion'], $l['fecha']) . '): capital ' . money((float) $l['capital'] * (float) $l['cotizacion'])
                  . ' · intereses ' . money((float) $l['interes'] * (float) $l['cotizacion']) . ' · a pagar ' . money((float) $l['a_pagar'] * (float) $l['cotizacion']) . '.'
                : 'No había cotización del BCRA cargada para la fecha de la liquidación.');
        }
        if ($l['estado'] === 'PAGADA' && $l['medio'] !== 'RENOVACION') {
            $rep->nota('Pagado el ' . fecha($l['pagado_el']) . ' por ' . strtolower(MEDIOS_PAGO_INV[$l['medio']] ?? (string) $l['medio']) . ($l['referencia'] ? ' · ' . $l['referencia'] : '') . '.');
        }
        if ($i['modalidad'] === 'PARTICIPACION') {
            $d = q('SELECT d.*, p.nro_prestamo, c.denominacion AS cliente FROM inversiones_liquidaciones_detalle d
                      JOIN prestamos p ON p.id_prestamo = d.id_prestamo JOIN clientes c ON c.id_cliente = p.id_cliente
                     WHERE d.id_liquidacion = ? ORDER BY p.nro_prestamo', [$idLiq]);
            $rep->seccion('Detalle por préstamo', 'interés sin IVA ni punitorios, cobrado en el período');
            $rep->tabla($cols, array_map($filaDet, $d), ['TOTAL', '', '', '', '', '', money($l['interes'])]);
        }
        $rep->enviar('liquidacion_' . $i['nro_inversion'] . '_' . str_replace('-', '', $l['fecha']) . '.pdf');
    } else {
        $rep = new PdfReporte('Constancia de aporte ' . $i['nro_inversion'] . ($i['estado'] === 'ANULADA' ? ' · ANULADO' : ''),
                              $i['denominacion'] . ' · CUIT/CUIL ' . cuit_fmt($i['cuit']), false);
        $rep->indicadores([['Capital', dinero($i['capital'], $mon)], ['Plazo', fecha($i['fecha_inicio']) . ' al ' . fecha($i['fecha_vencimiento'])],
                           ['Condición', condicion_inversion($i)]]);
        $rep->nota('Modalidad: ' . MODALIDADES[$i['modalidad']] . ' · moneda: ' . MONEDAS[$mon] . ' · '
                 . (int) ((strtotime($i['fecha_vencimiento']) - strtotime($i['fecha_inicio'])) / 86400) . ' días.');
        if ($mon !== 'ARS') {
            $cotIni = cotizacion_segura($mon, $i['fecha_inicio']);
            $rep->nota($cotIni ? 'Capital equivalente en pesos al inicio: ' . money((float) $i['capital'] * $cotIni) . ' (' . cot_txt($mon, $cotIni, $i['fecha_inicio']) . ').'
                               : 'No había cotización del BCRA cargada para la fecha de inicio.');
        }
        if ($estimado !== null) {
            $rep->nota('Interés al vencimiento: ' . dinero($estimado, $mon) . ' (interés simple, base ' . parametro('INVERSION_DIAS_ANIO', '365')
                     . ' días). Total a cobrar: ' . dinero((float) $i['capital'] + $estimado, $mon) . '.');
        } else {
            $rep->nota('El inversor recibe el ' . pct($i['pct_participacion']) . ' del interés (sin IVA ni punitorios) que se cobre de los préstamos fondeados, '
                     . 'en proporción a lo fondeado, hasta el vencimiento. El capital se devuelve al vencimiento.');
            $rep->seccion('Préstamos fondeados');
            $rep->tabla([['Préstamo', 1, 'L'], ['Tomador', 2.6, 'L'], ['Fondeado', 1.3, 'R'], ['Capital del préstamo', 1.4, 'R'], ['Proporción', 1, 'R']],
                        array_map(function ($d) { return [$d['nro_prestamo'], $d['cliente'], money($d['monto_asignado']), money($d['capital_prestamo']), pct($d['proporcion'])]; }, $det),
                        ['TOTAL', '', money(array_sum(array_column($det, 'monto_asignado'))), '', '']);
        }
        if ($i['observaciones']) {
            $rep->nota('Observaciones: ' . $i['observaciones']);
        }
        $rep->enviar('aporte_' . $i['nro_inversion'] . '.pdf');
    }
    exit;
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
if ($id > 0 && !$i && !$nueva) {
    http_response_code(404);
}
layout_inicio($i ? 'Aporte ' . $i['nro_inversion'] : 'Nuevo aporte', 'inversiones');

if ($nueva):
    titulo('Nuevo aporte', 'Capital de un inversor', '', '<a href="inversiones.php">Inversiones</a> / Nuevo aporte');
    mensajes($errores);
    $asig = $f['asig'] ?: [['prestamo' => 0, 'monto' => '']];
    $renglonAsig = function (array $a) use ($fondeables) {
        $o = '<div class="renglon"><div class="campo" style="--span:8"><label>Préstamo</label><select name="asig_prestamo[]"><option value="">Elegí…</option>';
        foreach ($fondeables as $p) {
            $o .= '<option value="' . (int) $p['id_prestamo'] . '"' . ((int) $a['prestamo'] === (int) $p['id_prestamo'] ? ' selected' : '') . '>'
                . h($p['nro_prestamo'] . ' · ' . $p['denominacion'] . ' · capital ' . money($p['capital']) . ' · libre ' . money($p['libre'])) . '</option>';
        }
        return $o . '</select></div><div class="campo" style="--span:3"><label>Monto que fondea</label>'
             . '<input name="asig_monto[]" value="' . h($a['monto']) . '" inputmode="decimal" class="derecha"></div>'
             . '<button type="button" class="btn-accion borrar renglon-quitar" title="Quitar" aria-label="Quitar">' . icono('cerrar') . '</button></div>';
    };
?>
<form method="post" action="inversion.php" id="frm-inv">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="crear">
  <div class="card">
    <?= card_head('Datos del aporte') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="inversor">Inversor <span class="req">*</span></label>
          <select id="inversor" name="inversor" required><option value="">Elegí…</option>
            <?php foreach ($inversores as $x): ?><option value="<?= (int) $x['id_inversor'] ?>" <?= (string) $x['id_inversor'] === (string) $f['inversor'] ? 'selected' : '' ?>><?= h($x['denominacion'] . ' · ' . cuit_fmt($x['cuit'])) ?></option><?php endforeach; ?>
          </select><span class="campo-ayuda"><a href="inversor.php">dar de alta un inversor</a></span></div>
        <div class="campo" style="--span:4"><span class="campo-label">Modalidad</span>
          <div class="radios">
            <?php foreach (MODALIDADES as $k => $t): ?><label><input type="radio" name="modalidad" value="<?= $k ?>" <?= $f['modalidad'] === $k ? 'checked' : '' ?>> <?= h($t) ?></label><?php endforeach; ?>
          </div></div>
        <div class="campo" style="--span:3"><label for="moneda">Moneda</label>
          <select id="moneda" name="moneda"><?= opciones(MONEDAS, $f['moneda']) ?></select></div>
        <div class="campo" style="--span:3"><label for="capital">Capital <span class="req">*</span></label>
          <input id="capital" name="capital" value="<?= h($f['capital']) ?>" inputmode="decimal" class="derecha" required></div>
        <div class="campo" style="--span:3"><label for="fecha_inicio">Inicio</label>
          <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= h($f['fecha_inicio']) ?>"></div>
        <div class="campo" style="--span:2"><label for="plazo">Plazo (días)</label>
          <input id="plazo" name="plazo" value="<?= h($f['plazo']) ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo" style="--span:4"><label for="fecha_vencimiento">Vencimiento</label>
          <input type="date" id="fecha_vencimiento" name="fecha_vencimiento" value="<?= h($f['fecha_vencimiento']) ?>">
          <span class="campo-ayuda">se calcula con el plazo; o elegí la fecha</span></div>
        <div class="campo solo-tf" style="--span:3"><label for="tna">TNA pactada (%) <span class="req">*</span></label>
          <input id="tna" name="tna" value="<?= h($f['tna']) ?>" inputmode="decimal" class="derecha"></div>
        <div class="campo solo-tf" style="--span:9"><span class="campo-label">Interés al vencimiento</span>
          <div class="campo-fijo" id="estimado">—</div></div>
        <div class="campo solo-pa" style="--span:3"><label for="pct">% del interés cobrado <span class="req">*</span></label>
          <input id="pct" name="pct" value="<?= h($f['pct']) ?>" inputmode="decimal" class="derecha"></div>
        <div class="campo solo-pa" style="--span:9"><span class="campo-label">&nbsp;</span>
          <span class="campo-ayuda">del interés (sin IVA ni punitorios) que se cobre de los préstamos fondeados, en proporción a lo fondeado</span></div>
        <?php if ($cuentasInv): ?>
        <?= campo_cuenta_fondos('cuenta', 'Ingresa en', $cuentasInv, $f['cuenta'], 5, 'la cuenta donde entra el capital (de la moneda del aporte)') ?>
        <div class="campo" style="--span:3"><label for="medio_ingreso">Cómo ingresa</label>
          <select id="medio_ingreso" name="medio_ingreso"><?= opciones(MEDIOS_PAGO_INV, $f['medio_ingreso']) ?></select></div>
        <div class="campo" style="--span:4"><label for="ref_ingreso">Referencia</label>
          <input id="ref_ingreso" name="ref_ingreso" value="<?= h($f['ref_ingreso']) ?>" maxlength="100" placeholder="N° de transferencia, de cheque…"></div>
        <?php endif; ?>
        <div class="campo" style="--span:12"><label for="observaciones">Observaciones</label>
          <input id="observaciones" name="observaciones" value="<?= h($f['observaciones']) ?>" maxlength="255"></div>
      </div>
    </div>
    <div class="bloque solo-pa">
      <div class="bloque-titulo">Préstamos que fondea <?= boton('Agregar préstamo', 'ghost', null, 'agregar', false, true, ['data-agregar' => 'tpl-asig', 'data-destino' => 'asigs']) ?></div>
      <?php if (!$fondeables): ?><p class="campo-ayuda">No hay préstamos vigentes con capital libre para fondear.</p><?php endif; ?>
      <div id="asigs"><?php foreach ($asig as $a) { echo $renglonAsig($a); } ?></div>
    </div>
    <div class="form-acciones">
      <?= boton('Cancelar', 'secondary', 'inversiones.php', 'volver') ?>
      <?= boton('Cargar aporte', 'primary', null, 'guardar', true) ?>
    </div>
  </div>
</form>
<template id="tpl-asig"><?= $renglonAsig(['prestamo' => 0, 'monto' => '']) ?></template>
<?php if ($cuentasInv): ?>
<script>
(function () {
  // La cuenta donde ingresa el capital es de la moneda del aporte: viene la predeterminada de esa moneda.
  var mon = document.getElementById('moneda'), cta = document.getElementById('cf-cuenta'), predet = <?= json_encode((object) $predet) ?>;
  function filtrar(cambio) {
    Array.prototype.forEach.call(cta.options, function (op) { if (op.value) op.disabled = op.getAttribute('data-moneda') !== mon.value; });
    if (cambio || (cta.value && cta.options[cta.selectedIndex].disabled)) cta.value = predet[mon.value] ? String(predet[mon.value]) : '';
  }
  mon.addEventListener('change', function () { filtrar(true); });
  filtrar(false);
})();
</script>
<?php endif; ?>
<script>
(function () {
  var form = document.getElementById('frm-inv');
  var g = function (id) { return document.getElementById(id); };
  function num(s) { s = (s || '').replace(/[$\s%]/g, ''); if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.'); return parseFloat(s); }
  function modalidad() { return form.querySelector('input[name=modalidad]:checked').value; }
  function vto() {
    var ini = g('fecha_inicio').value, d = parseInt(g('plazo').value, 10);
    if (ini && d > 0) { var x = new Date(ini + 'T12:00:00'); x.setDate(x.getDate() + d); g('fecha_vencimiento').value = x.toISOString().slice(0, 10); }
  }
  function plazo() {
    var ini = g('fecha_inicio').value, v = g('fecha_vencimiento').value;
    if (ini && v) g('plazo').value = Math.round((new Date(v + 'T12:00:00') - new Date(ini + 'T12:00:00')) / 86400000);
  }
  function aplicar() {
    var tf = modalidad() === 'TASA_FIJA';
    form.querySelectorAll('.solo-tf').forEach(function (e) { e.classList.toggle('oculto', !tf); });
    form.querySelectorAll('.solo-pa').forEach(function (e) { e.classList.toggle('oculto', tf); });
    if (!tf) g('moneda').value = 'ARS';
    g('moneda').disabled = !tf;
    estimado();
  }
  function estimado() {
    var c = num(g('capital').value), t = num(g('tna').value), d = parseInt(g('plazo').value, 10);
    var mon = g('moneda').value === 'USD' ? 'US$ ' : '$ ';
    if (c > 0 && t > 0 && d > 0) {
      var i = Math.round(c * t / 100 * d / <?= (int) parametro('INVERSION_DIAS_ANIO', '365') ?> * 100) / 100;
      var fmt = function (n) { return mon + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
      g('estimado').textContent = fmt(i) + ' de interés · total ' + fmt(c + i);
    } else { g('estimado').textContent = '—'; }
  }
  form.querySelectorAll('input[name=modalidad]').forEach(function (r) { r.addEventListener('change', aplicar); });
  g('plazo').addEventListener('input', function () { vto(); estimado(); });
  g('fecha_inicio').addEventListener('change', function () { vto(); estimado(); });
  g('fecha_vencimiento').addEventListener('change', function () { plazo(); estimado(); });
  ['capital', 'tna', 'moneda'].forEach(function (k) { g(k).addEventListener('input', estimado); g(k).addEventListener('change', estimado); });
  form.addEventListener('submit', function () { g('moneda').disabled = false; });
  if (!g('fecha_vencimiento').value) vto(); else plazo();
  aplicar();
})();
</script>
<?php
    layout_fin();
    exit;
endif;

if (!$i) {
    mensajes($errores);
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese aporte</h1>' . boton('Ir a inversiones', 'primary', 'inversiones.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

$mon = $i['moneda'];
$liqActiva = array_values(array_filter($liqs, function ($l) { return $l['estado'] !== 'ANULADA'; }))[0] ?? null;
titulo('Aporte ' . $i['nro_inversion'], $i['denominacion'] . ' · ' . MODALIDADES[$i['modalidad']] . ' · ' . MONEDAS[$mon],
       boton('Constancia PDF', 'pdf', 'inversion.php?id=' . $id . '&export=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener']),
       '<a href="inversiones.php">Inversiones</a> / <a href="inversor.php?id=' . (int) $i['id_inversor'] . '">' . h($i['denominacion']) . '</a> / ' . h($i['nro_inversion']));
mensajes($errores);
$diasTot = (int) ((strtotime($i['fecha_vencimiento']) - strtotime($i['fecha_inicio'])) / 86400);
$diasFalta = (int) floor((strtotime($i['fecha_vencimiento']) - strtotime($hoy)) / 86400);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Capital</span><strong><?= dinero($i['capital'], $mon) ?></strong>
    <span class="bcra-tarjeta-sub"><?= h(condicion_inversion($i)) ?><?= $mon !== 'ARS' ? '<br>' . h(equiv_pesos($i['capital'], $mon, $cotHoy)) . ' hoy' : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Plazo</span><strong><?= $diasTot ?> días</strong>
    <span class="bcra-tarjeta-sub"><?= h(fecha($i['fecha_inicio'])) ?> al <?= h(fecha($i['fecha_vencimiento'])) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl"><?= $i['estado'] === 'VIGENTE' ? 'Devengado a hoy' : 'Estado' ?></span>
    <?php if ($i['estado'] === 'VIGENTE'): ?><strong><?= dinero($calc['interes'] ?? 0, $mon) ?></strong>
      <span class="bcra-tarjeta-sub"><?= (int) ($calc['dias'] ?? 0) ?> días corridos<?= $mon !== 'ARS' ? ' · ' . h(equiv_pesos($calc['interes'] ?? 0, $mon, $cotHoy)) : '' ?></span>
    <?php else: ?><?= estado_inversion($i['estado']) ?><span class="bcra-tarjeta-sub"></span><?php endif; ?></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl"><?= $estimado !== null ? 'Interés al vencimiento' : 'Vencimiento' ?></span>
    <strong><?= $estimado !== null ? dinero($estimado, $mon) : h(fecha($i['fecha_vencimiento'])) ?></strong>
    <span class="bcra-tarjeta-sub"><?= $i['estado'] === 'VIGENTE' ? ($diasFalta > 0 ? 'faltan ' . $diasFalta . ' días' : 'vencido: liquidar') : '' ?><?=
      $mon !== 'ARS' && $estimado !== null ? ' · ' . h(equiv_pesos($estimado, $mon, $cotHoy)) . ' hoy' : '' ?></span></div>
</div>

<?php if ($i['estado'] === 'ANULADA'): ?><?php alerta('Aporte anulado el ' . date('d/m/Y H:i', strtotime((string) $i['anulado_el'])) . ': ' . $i['motivo_anulacion'], 'warning'); ?><?php endif; ?>

<div class="grilla-2">
  <div class="card">
    <?= card_head('Datos') ?>
    <dl class="datos-lista">
      <dt>Inversor</dt><dd><a href="inversor.php?id=<?= (int) $i['id_inversor'] ?>"><?= h($i['denominacion']) ?></a> <span class="mono"><?= h(cuit_fmt($i['cuit'])) ?></span></dd>
      <dt>Modalidad</dt><dd><?= h(MODALIDADES[$i['modalidad']]) ?> · <?= h(MONEDAS[$mon]) ?> · <?= h(condicion_inversion($i)) ?></dd>
      <dt>Estado</dt><dd><?= estado_inversion($i['estado']) ?></dd>
      <?php if ($mon !== 'ARS'): ?><dt>Cotización</dt><dd><?= h(ucfirst(cot_txt($mon, $cotHoy, $cotHoyFecha))) ?> <a href="cotizaciones.php?moneda=<?= h($mon) ?>">ver</a></dd><?php endif; ?>
      <dt>Para pagarle</dt><dd><?= datos_bancarios_html($i['cbu'], $i['alias_cbu']) ?: '<span class="td-muted">sin CBU ni alias: cargalos en la <a href="inversor.php?id=' . (int) $i['id_inversor'] . '">ficha del inversor</a></span>' ?></dd>
      <?php if (!empty($i['id_cuenta_fondos']) && isset($nombresCuenta[$i['id_cuenta_fondos']])): ?><dt>Ingresó en</dt><dd><a href="movimientos_fondos.php?cuenta=<?= (int) $i['id_cuenta_fondos'] ?>"><?= h($nombresCuenta[$i['id_cuenta_fondos']]) ?></a><?= $i['medio_ingreso'] ? ' · ' . h(strtolower(MEDIOS_PAGO_INV[$i['medio_ingreso']] ?? $i['medio_ingreso'])) : '' ?><?= $i['referencia_ingreso'] ? ' · ' . h($i['referencia_ingreso']) : '' ?></dd><?php endif; ?>
      <?php if ($i['nro_origen']): ?><dt>Renovación de</dt><dd><a href="inversion.php?id=<?= (int) $i['id_inversion_origen'] ?>"><?= h($i['nro_origen']) ?></a></dd><?php endif; ?>
      <?php if ($i['observaciones']): ?><dt>Observaciones</dt><dd><?= h($i['observaciones']) ?></dd><?php endif; ?>
      <dt>Cargado</dt><dd><?= h(date('d/m/Y H:i', strtotime($i['creado_el']))) ?></dd>
    </dl>
  </div>

  <?php if ($i['estado'] === 'VIGENTE'): ?>
  <div class="card" id="liquidar">
    <?= card_head('Liquidar', $diasFalta > 0 ? 'antes del vencimiento es un rescate anticipado' : 'el aporte ya venció') ?>
    <form method="get" action="inversion.php#liquidar" class="bloque">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="campos">
        <div class="campo" style="--span:6"><label for="liq_fecha">Fecha de liquidación</label>
          <input type="date" id="liq_fecha" name="liq_fecha" value="<?= h(get_str('liq_fecha') ?: min($hoy, $i['fecha_vencimiento'])) ?>" max="<?= $hoy ?>"></div>
        <div class="campo campo-boton" style="--span:6"><span class="campo-label">&nbsp;</span>
          <?= boton('Calcular', 'primary', null, 'filtro', true) ?></div>
      </div>
    </form>
    <?php if ($preview): ?>
      <div class="bloque">
        <div class="liq-preview">
          <div><span>Capital</span><strong><?= dinero($preview['capital'], $mon) ?></strong></div>
          <div><span>Interés (<?= (int) $preview['dias'] ?> días)</span><strong><?= dinero($preview['interes'], $mon) ?></strong></div>
          <div><span>Total</span><strong><?= dinero($preview['total'], $mon) ?></strong></div>
        </div>
        <?php if ($mon !== 'ARS'): ?>
          <p class="equiv-pesos">En pesos: capital <?= h(equiv_pesos($preview['capital'], $mon, $cotPrev)) ?> · interés <?= h(equiv_pesos($preview['interes'], $mon, $cotPrev)) ?>
            · total <strong><?= h(equiv_pesos($preview['total'], $mon, $cotPrev)) ?></strong><br><span class="campo-ayuda"><?= h(ucfirst(cot_txt($mon, $cotPrev, null))) ?> (último día hábil hasta el <?= h(fecha($preview['fecha'])) ?>); queda guardada en la liquidación.</span></p>
        <?php endif; ?>
        <?php if ((int) $preview['anticipada']): ?><p class="campo-ayuda">Liquidación anticipada: el aporte vencía el <?= h(fecha($i['fecha_vencimiento'])) ?>.</p><?php endif; ?>
      </div>
      <form method="post" action="inversion.php?id=<?= $id ?>" class="bloque" data-confirmar="Se liquida el aporte <?= h($i['nro_inversion']) ?> al <?= h(fecha($preview['fecha'])) ?>: capital <?= h(dinero($preview['capital'], $mon)) ?> + intereses <?= h(dinero($preview['interes'], $mon)) ?>." data-confirmar-titulo="Liquidar el aporte" data-confirmar-boton="Liquidar">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="liquidar">
        <input type="hidden" name="fecha" value="<?= h($preview['fecha']) ?>">
        <div class="campos">
          <div class="campo" style="--span:12"><span class="campo-label">Al liquidar</span>
            <div class="radios radios-col">
              <?php foreach (RENOVACIONES as $k => $t): ?><label><input type="radio" name="renovacion" value="<?= $k ?>" <?= $k === 'NO' ? 'checked' : '' ?>> <?= h($t) ?></label><?php endforeach; ?>
            </div></div>
          <div class="campo" style="--span:6"><label for="dias">Plazo de la renovación (días)</label>
            <input id="dias" name="dias" value="<?= $diasTot ?>" inputmode="numeric" class="derecha">
            <span class="campo-ayuda">mismas condiciones; desde el <?= h(fecha($preview['fecha'])) ?></span></div>
          <div class="campo campo-boton" style="--span:6"><span class="campo-label">&nbsp;</span>
            <?= boton('Liquidar', 'ok', null, 'guardar', true) ?></div>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <?php elseif ($liqActiva): ?>
  <div class="card">
    <?= card_head('Liquidado') ?>
    <div class="bloque">
      <div class="liq-preview">
        <div><span>Capital</span><strong><?= dinero($liqActiva['capital'], $mon) ?></strong></div>
        <div><span>Interés (<?= (int) $liqActiva['dias'] ?> días)</span><strong><?= dinero($liqActiva['interes'], $mon) ?></strong></div>
        <div><span>A pagar</span><strong><?= dinero($liqActiva['a_pagar'], $mon) ?></strong></div>
      </div>
      <?php if ($mon !== 'ARS'): ?><p class="equiv-pesos">En pesos: interés <?= h(equiv_pesos($liqActiva['interes'], $mon, $liqActiva['cotizacion'])) ?> · a pagar <strong><?= h(equiv_pesos($liqActiva['a_pagar'], $mon, $liqActiva['cotizacion'])) ?></strong>
        <br><span class="campo-ayuda"><?= h(ucfirst(cot_txt($mon, $liqActiva['cotizacion'] ? (float) $liqActiva['cotizacion'] : null, $liqActiva['fecha']))) ?></span></p><?php endif; ?>
      <?php if ($liqActiva['nro_nueva']): ?><p class="campo-ayuda">Renovado como <a href="inversion.php?id=<?= (int) $liqActiva['id_inversion_nueva'] ?>"><?= h($liqActiva['nro_nueva']) ?></a> por <?= dinero($liqActiva['reinvertido'], $mon) ?>.</p><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($i['modalidad'] === 'PARTICIPACION'): ?>
<div class="card">
  <?= card_head('Préstamos fondeados', $i['estado'] === 'VIGENTE' ? 'interés cobrado desde el inicio hasta hoy' : '') ?>
  <?= barra_local('inversion.php?id=' . $id, 'Préstamo o tomador…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="préstamo|préstamos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Préstamo</th><th>Tomador</th><th class="th-right">Fondeado</th>
        <th class="th-right">Capital del préstamo</th><th class="th-right">Proporción</th>
        <th class="th-right">Interés cobrado</th><th class="th-right">Para el inversor</th><th>Estado</th></tr></thead>
      <tbody>
      <?php $fondeados = $preview ? $previewDet : $det; ?>
      <?php if (!$fondeados): ?><tr><td colspan="9" class="no-data">No fondea ningún préstamo.</td></tr><?php endif; ?>
      <?php foreach ($fondeados as $d): ?>
        <tr><td><div class="acciones"><?= btn_accion('ver', 'prestamo.php?id=' . (int) $d['id_prestamo'], 'Ver el préstamo') ?></div></td>
          <td class="mono"><a href="prestamo.php?id=<?= (int) $d['id_prestamo'] ?>"><?= h($d['nro_prestamo']) ?></a></td><td><?= h($d['cliente']) ?></td>
          <td class="td-right" data-orden="<?= (float) $d['monto_asignado'] ?>"><?= money($d['monto_asignado']) ?></td>
          <td class="td-right" data-orden="<?= (float) $d['capital_prestamo'] ?>"><?= money($d['capital_prestamo']) ?></td>
          <td class="td-right" data-orden="<?= (float) $d['proporcion'] ?>"><?= pct($d['proporcion']) ?></td>
          <td class="td-right" data-orden="<?= (float) $d['interes_cobrado'] ?>"><?= money($d['interes_cobrado']) ?></td>
          <td class="td-right td-fuerte" data-orden="<?= (float) $d['interes_inversor'] ?>"><?= money($d['interes_inversor']) ?></td><td><?= estado_prestamo($d['estado_prestamo']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<div class="card" id="liquidaciones">
  <?= card_head('Liquidaciones', count($liqs) ? count($liqs) . ' liquidaciones' : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Fecha</th><th class="th-right" data-no-sort>Días</th>
        <th class="th-right" data-no-sort>Capital</th><th class="th-right" data-no-sort>Interés</th><th data-no-sort>Renovación</th>
        <th class="th-right" data-no-sort>A pagar</th><th data-no-sort>Estado</th></tr></thead>
      <tbody>
      <?php if (!$liqs): ?><tr><td colspan="8" class="no-data">Todavía no se liquidó.</td></tr><?php endif; ?>
      <?php foreach ($liqs as $l): ?>
        <tr<?= $l['estado'] === 'ANULADA' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= btn_accion('pdf', 'inversion.php?id=' . $id . '&liq=' . $l['id_liquidacion'] . '&export=pdf', 'Liquidación en PDF', true) ?></div></td>
          <td><?= h(fecha($l['fecha'])) ?><?= $l['anticipada'] ? '<span class="segunda-linea">anticipada</span>' : '' ?></td>
          <td class="td-right"><?= (int) $l['dias'] ?></td>
          <td class="td-right"><?= dinero($l['capital'], $mon) ?></td><td class="td-right"><?= dinero($l['interes'], $mon) ?><?=
            $mon !== 'ARS' ? '<span class="segunda-linea">' . h(equiv_pesos($l['interes'], $mon, $l['cotizacion'])) . '</span>' : '' ?></td>
          <td><?= $l['renovacion'] === 'NO' ? 'No' : ($l['renovacion'] === 'CAPITAL' ? 'Capital' : 'Capital + interés') ?>
            <?= $l['nro_nueva'] ? '<span class="segunda-linea">→ <a href="inversion.php?id=' . (int) $l['id_inversion_nueva'] . '">' . h($l['nro_nueva']) . '</a></span>' : '' ?></td>
          <td class="td-right td-fuerte"><?= dinero($l['a_pagar'], $mon) ?><?=
            $mon !== 'ARS' ? '<span class="segunda-linea">' . h(equiv_pesos($l['a_pagar'], $mon, $l['cotizacion'])) . '</span>' : '' ?></td>
          <td><?= estado_liq_inv($l['estado']) ?>
            <?php if ($l['estado'] === 'PAGADA' && $l['medio'] !== 'RENOVACION'): ?><span class="segunda-linea"><?= h(fecha($l['pagado_el'])) ?> · <?= h(MEDIOS_PAGO_INV[$l['medio']] ?? $l['medio']) ?><?= $l['referencia'] ? ' · ' . h($l['referencia']) : '' ?><?= !empty($l['id_cuenta_fondos']) && isset($nombresCuenta[$l['id_cuenta_fondos']]) ? ' · de ' . h($nombresCuenta[$l['id_cuenta_fondos']]) : '' ?></span><?php endif; ?></td>
        </tr>
        <?php if ($l['estado'] === 'A_PAGAR'): ?>
        <tr class="fila-accion"><td colspan="8">
          <form method="post" action="inversion.php?id=<?= $id ?>" class="form-fila" data-confirmar="Se registra el pago de <?= h(dinero($l['a_pagar'], $mon)) ?><?= $mon !== 'ARS' ? ' (' . h(equiv_pesos($l['a_pagar'], $mon, $l['cotizacion'])) . ')' : '' ?> a <?= h($i['denominacion']) ?>." data-confirmar-titulo="Pago al inversor" data-confirmar-boton="Registrar">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="id_liquidacion" value="<?= (int) $l['id_liquidacion'] ?>">
            <span class="form-fila-titulo">Registrar el pago:</span>
            <input type="date" name="fecha_pago" value="<?= $hoy ?>" max="<?= $hoy ?>" class="celda-input" style="width:150px">
            <?php if ($cuentasInv): ?><?= cuenta_select('cuenta', array_filter($cuentasInv, function ($c) use ($mon) { return $c['moneda'] === $mon; }),
                                                      $predet[$mon] ?? '', 'Sale de…', ['class' => 'celda-input', 'style' => 'width:220px', 'required' => true, 'title' => 'Cuenta de fondos de la que sale el pago']) ?><?php endif; ?>
            <select name="medio" class="celda-input" style="width:150px"><?= opciones(MEDIOS_PAGO_INV, 'TRANSFERENCIA') ?></select>
            <input name="referencia" class="celda-input" placeholder="N° de transferencia…" maxlength="100" style="width:220px">
            <?= boton('Pagado', 'ok', null, 'guardar', true, true) ?>
          </form>
        </td></tr>
        <?php endif; ?>
        <?php if ($l['estado'] === 'A_PAGAR' || ($l['estado'] === 'PAGADA' && $l['medio'] === 'RENOVACION')): ?>
        <tr class="fila-accion"><td colspan="8">
          <form method="post" action="inversion.php?id=<?= $id ?>" class="form-fila" data-confirmar="Se anula la liquidación del <?= h(fecha($l['fecha'])) ?>: el aporte vuelve a estar vigente<?= $l['nro_nueva'] ? ' y se anula la renovación ' . h($l['nro_nueva']) : '' ?>." data-confirmar-titulo="Anular la liquidación" data-confirmar-boton="Anular" data-peligro>
            <?= csrf_campo() ?><input type="hidden" name="accion" value="anular_liq"><input type="hidden" name="id_liquidacion" value="<?= (int) $l['id_liquidacion'] ?>">
            <span class="form-fila-titulo">¿Error?</span>
            <input name="motivo" class="celda-input" placeholder="Motivo de la anulación" maxlength="200" required minlength="5" style="width:320px">
            <?= boton('Anular liquidación', 'danger', null, 'borrar', true, true) ?>
          </form>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($i['estado'] === 'VIGENTE' && !$liqActiva): ?>
<div class="card">
  <?= card_head('Anular el aporte', 'solo para errores de carga') ?>
  <form method="post" action="inversion.php?id=<?= $id ?>" class="bloque" data-confirmar="Se anula el aporte <?= h($i['nro_inversion']) ?> de <?= h($i['denominacion']) ?>." data-confirmar-titulo="Anular el aporte" data-confirmar-boton="Anular" data-peligro>
    <?= csrf_campo() ?><input type="hidden" name="accion" value="anular">
    <div class="campos">
      <div class="campo" style="--span:9"><label for="motivo-a">Motivo <span class="req">*</span></label>
        <input id="motivo-a" name="motivo" maxlength="200" required minlength="5"></div>
      <div class="campo campo-boton" style="--span:3"><span class="campo-label">&nbsp;</span><?= boton('Anular aporte', 'danger', null, 'borrar', true) ?></div>
    </div>
  </form>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
