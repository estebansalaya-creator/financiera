<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Central de Deudores del BCRA (API pública, sin clave)
//   https://api.bcra.gob.ar/CentralDeDeudores/v1.0/...
//     Deudas/{cuit}                  último período informado
//     Deudas/Historicas/{cuit}       últimos 24 meses
//     Deudas/ChequesRechazados/{cuit}
//   Respuesta: {"status":200,"results":{...}}  o  {"status":404,"errorMessages":[...]}
//
// Cada respuesta se guarda en consultas_bcra (respaldo + caché de
// bcra_cache_horas). Los montos de deuda vienen en MILES de pesos; los de
// cheques, en pesos.
// ---------------------------------------------------------------------

const BCRA_TIPOS = [
    'DEUDAS'     => 'Deudas/',
    'HISTORICAS' => 'Deudas/Historicas/',
    'CHEQUES'    => 'Deudas/ChequesRechazados/',
];

/** Situación 1 a 5: [texto corto, texto largo, tipo de píldora] */
const BCRA_SITUACIONES = [
    1 => ['Normal', 'Normal / cumplimiento normal', 'ok'],
    2 => ['Riesgo bajo', 'Riesgo bajo / seguimiento especial', 'warn'],
    3 => ['Riesgo medio', 'Riesgo medio / con problemas', 'warn'],
    4 => ['Riesgo alto', 'Riesgo alto / alto riesgo de insolvencia', 'danger'],
    5 => ['Irrecuperable', 'Irrecuperable', 'danger'],
    6 => ['Irrecup. disp. técnica', 'Irrecuperable por disposición técnica', 'danger'],
];

function bcra_situacion_pill($s): string
{
    $s = (int) $s;
    if (!isset(BCRA_SITUACIONES[$s])) {
        return '<span class="td-muted">—</span>';
    }
    return '<span title="' . h(BCRA_SITUACIONES[$s][1]) . '">' . estado($s . ' · ' . BCRA_SITUACIONES[$s][0], BCRA_SITUACIONES[$s][2]) . '</span>';
}

/** '202410', '2024-10' o '2024-10-01' -> '2024-10' */
function bcra_periodo($p): string
{
    $d = preg_replace('/\D/', '', (string) $p);
    return strlen($d) >= 6 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) : (string) $p;
}

/** '2024-10' -> 'oct 24' */
function bcra_periodo_corto(string $p): string
{
    static $m = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return preg_match('/^(\d{4})-(\d{2})$/', $p, $x) ? ($m[(int) $x[2]] ?? $x[2]) . ' ' . substr($x[1], 2) : $p;
}

/**
 * GET a la API. Devuelve [status HTTP, cuerpo decodificado o null, error o ''].
 * Usa curl si está; si curl no tiene los certificados de CA (pasa seguido
 * en PHP para Windows sin curl.cainfo), reintenta con los streams de PHP,
 * que en Windows usan el almacén de certificados del sistema.
 */
function bcra_get(string $ruta): array
{
    $url = rtrim((string) cfg('bcra_url', 'https://api.bcra.gob.ar/CentralDeDeudores/v1.0'), '/') . '/' . $ruta;
    [$status, $json, $error] = bcra_http($url);
    if ($error !== '') {
        return [$status, $json, $error];
    }
    $status = (int) ($json['status'] ?? $status);
    if ($status !== 200 && $status !== 404) {
        $msg = implode(' ', (array) ($json['errorMessages'] ?? [])) ?: 'error HTTP ' . $status;
        return [$status, $json, 'El BCRA devolvió un error: ' . $msg];
    }
    return [$status, $json, ''];
}

/**
 * GET a una URL de las APIs públicas del BCRA. Devuelve [status HTTP,
 * JSON decodificado o null, error o '']. Lo usan la Central de Deudores y
 * las cotizaciones. Usa curl si está; si curl no tiene los certificados de
 * CA (pasa seguido en PHP para Windows sin curl.cainfo), reintenta con los
 * streams de PHP, que en Windows usan el almacén de certificados del sistema.
 */
function bcra_http(string $url): array
{
    $timeout = (int) cfg('bcra_timeout', 20);
    $cuerpo = null;
    $status = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'financiera-backoffice/1.0',
        ];
        if (cfg('bcra_cainfo')) {
            $opts[CURLOPT_CAINFO] = (string) cfg('bcra_cainfo');
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;   // la lista de certificados raíz de Windows
        }
        curl_setopt_array($ch, $opts);
        $txt = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $errno ? curl_error($ch) : '';
        // curl_close() no hace falta desde PHP 8 (y en 8.5 avisa que está obsoleta).
        if (!$errno) {
            $cuerpo = $txt;
        } elseif (!in_array($errno, [60, 77], true) || !extension_loaded('openssl')) {
            return [0, null, 'No se pudo conectar con el BCRA: ' . $error];
        }
    }

    if ($cuerpo === null) {
        if (!extension_loaded('openssl')) {
            return [0, null, 'PHP no tiene curl ni openssl habilitados: agregá extension=curl o extension=openssl en php.ini.'];
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
                       'header' => "Accept: application/json\r\nUser-Agent: financiera-backoffice/1.0\r\n"],
            'ssl'  => array_filter(['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => cfg('bcra_cainfo')]),
        ]);
        $txt = @file_get_contents($url, false, $ctx);
        if ($txt === false) {
            $e = error_get_last();
            return [0, null, 'No se pudo conectar con el BCRA' . ($error ? ' (' . $error . ')' : '') . ': ' . ($e['message'] ?? 'sin respuesta')
                           . '. Si es un problema de certificados, configurá bcra_cainfo en config.php con la ruta a cacert.pem.'];
        }
        $cuerpo = $txt;
        $status = 0;
        foreach ($http_response_header ?? [] as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $status = (int) $m[1];
            }
        }
    }

    $json = json_decode((string) $cuerpo, true);
    if (!is_array($json)) {
        return [$status, null, 'El BCRA respondió algo que no es JSON (HTTP ' . $status . ').'];
    }
    return [$status, $json, ''];
}

/** Resumen para las columnas de consultas_bcra. */
function bcra_resumen(string $tipo, ?array $json): array
{
    $r = ['denominacion' => null, 'periodo' => null, 'peor_situacion' => null, 'deuda_total' => null,
          'entidades' => null, 'cheques' => null, 'cheques_impagos' => null];
    $res = $json['results'] ?? null;
    if (!is_array($res)) {
        return $r;
    }
    $r['denominacion'] = isset($res['denominacion']) ? mb_substr_seguro((string) $res['denominacion'], 150) : null;
    if ($tipo === 'CHEQUES') {
        $n = 0;
        $imp = 0;
        foreach ((array) ($res['causales'] ?? []) as $c) {
            foreach ((array) ($c['entidades'] ?? []) as $e) {
                foreach ((array) ($e['detalle'] ?? []) as $d) {
                    $n++;
                    if (empty($d['fechaPago'])) {
                        $imp++;
                    }
                }
            }
        }
        $r['cheques'] = $n;
        $r['cheques_impagos'] = $imp;
        return $r;
    }
    $peor = null;
    $periodo = null;
    $total = 0.0;
    $ents = [];
    foreach ((array) ($res['periodos'] ?? []) as $p) {
        $per = bcra_periodo($p['periodo'] ?? '');
        if ($periodo === null || $per > $periodo) {
            $periodo = $per;
        }
    }
    foreach ((array) ($res['periodos'] ?? []) as $p) {
        $esUltimo = bcra_periodo($p['periodo'] ?? '') === $periodo;
        foreach ((array) ($p['entidades'] ?? []) as $e) {
            $s = (int) ($e['situacion'] ?? 0);
            if ($s > 0 && ($peor === null || $s > $peor)) {
                $peor = $s;
            }
            if ($esUltimo) {
                $total += (float) ($e['monto'] ?? 0);
                $ents[(string) ($e['entidad'] ?? '')] = true;
            }
        }
    }
    $r['periodo'] = $periodo;
    $r['peor_situacion'] = $peor;
    $r['deuda_total'] = round($total, 2);
    $r['entidades'] = count($ents);
    return $r;
}

/** Recorte a N caracteres sin depender de mbstring. */
function mb_substr_seguro(string $s, int $n): string
{
    return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : substr($s, 0, $n);
}

/** Última consulta válida (200/404) de un CUIT y tipo, o null. */
function bcra_ultima(string $cuit, string $tipo): ?array
{
    return q1("SELECT * FROM consultas_bcra WHERE cuit = ? AND tipo = ? AND http_status IN (200, 404)
                ORDER BY consultado_el DESC, id_consulta DESC LIMIT 1", [$cuit, $tipo]);
}

/**
 * Consulta los tres informes de un CUIT. Usa lo guardado si tiene menos de
 * bcra_cache_horas, salvo $forzar. Devuelve [tipo => fila de consultas_bcra]
 * , la lista de errores (si un informe falló, queda el último válido) y
 * cuántos informes se pidieron de verdad al BCRA (0 = todo salió de la caché).
 */
function bcra_consultar(string $cuit, ?int $idCliente, bool $forzar = false): array
{
    $horas = (float) cfg('bcra_cache_horas', 24);
    $filas = [];
    $errores = [];
    $pedidos = 0;
    $sinConexion = false;
    foreach (BCRA_TIPOS as $tipo => $ruta) {
        $ult = bcra_ultima($cuit, $tipo);
        if ($sinConexion) {                // si no hubo conexión, no se reintenta con los otros dos
            if ($ult) {
                $filas[$tipo] = $ult;
            }
            continue;
        }
        if (!$forzar && $ult && strtotime($ult['consultado_el']) > time() - $horas * 3600) {
            $filas[$tipo] = $ult;
            continue;
        }
        [$status, $json, $error] = bcra_get($ruta . $cuit);
        $pedidos++;
        $res = bcra_resumen($tipo, $status === 200 ? $json : null);
        q('INSERT INTO consultas_bcra (cuit, id_cliente, tipo, http_status, respuesta, error, denominacion, periodo,
                                       peor_situacion, deuda_total, entidades, cheques, cheques_impagos, consultado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$cuit, $idCliente, $tipo, $status ?: 0,
           $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
           $error !== '' ? mb_substr_seguro($error, 255) : null,
           $res['denominacion'], $res['periodo'], $res['peor_situacion'], $res['deuda_total'],
           $res['entidades'], $res['cheques'], $res['cheques_impagos'], usuario_id()]);
        if ($error !== '' && $status === 0) {
            $sinConexion = true;
            $errores[] = $error;
            if ($ult) {
                $filas[$tipo] = $ult;
            }
        } elseif ($error !== '') {
            $errores[] = ['DEUDAS' => 'Deuda actual', 'HISTORICAS' => 'Historial 24 meses', 'CHEQUES' => 'Cheques rechazados'][$tipo] . ': ' . $error;
            if ($ult) {
                $filas[$tipo] = $ult;      // se muestra lo último que se pudo traer
            }
        } else {
            $filas[$tipo] = bcra_ultima($cuit, $tipo);
        }
    }
    return [$filas, $errores, $pedidos];
}

/** Las tres últimas consultas guardadas, sin ir al BCRA. */
function bcra_guardadas(string $cuit): array
{
    $filas = [];
    foreach (array_keys(BCRA_TIPOS) as $tipo) {
        $u = bcra_ultima($cuit, $tipo);
        if ($u) {
            $filas[$tipo] = $u;
        }
    }
    return $filas;
}

// ---------------------------------------------------------------------
// Datos del informe (los usan la pantalla y el PDF)
// ---------------------------------------------------------------------

/** results de cada informe guardado con datos (200), o null. */
function bcra_resultados(array $filas): array
{
    $out = [];
    foreach (array_keys(BCRA_TIPOS) as $tipo) {
        $f = $filas[$tipo] ?? null;
        $d = $f && (int) $f['http_status'] === 200 && $f['respuesta'] ? json_decode($f['respuesta'], true) : null;
        $out[$tipo] = is_array($d['results'] ?? null) ? $d['results'] : null;
    }
    return $out;
}

/** true si el informe se consultó y el BCRA no tiene registros (404). */
function bcra_sin_registros(?array $fila): bool
{
    return $fila !== null && (int) $fila['http_status'] === 404;
}

/** Entidades de la deuda actual, peor situación y mayor monto primero. */
function bcra_deuda_entidades(?array $deu): array
{
    $l = [];
    foreach ((array) ($deu['periodos'] ?? []) as $p) {
        foreach ((array) ($p['entidades'] ?? []) as $e) {
            $l[] = $e;
        }
    }
    usort($l, function ($a, $b) {
        return [(int) ($b['situacion'] ?? 0), (float) ($b['monto'] ?? 0)] <=> [(int) ($a['situacion'] ?? 0), (float) ($a['monto'] ?? 0)];
    });
    return $l;
}

function bcra_deuda_obs(array $e): string
{
    static $txt = ['refinanciaciones' => 'Refinanciaciones', 'recategorizacionOblig' => 'Recategorización obligatoria',
                   'situacionJuridica' => 'Situación jurídica', 'irrecDisposicionTecnica' => 'Irrecup. por disposición técnica',
                   'enRevision' => 'En revisión', 'procesoJud' => 'Proceso judicial'];
    $obs = [];
    foreach ($txt as $k => $t) {
        if (!empty($e[$k])) {
            $obs[] = $t;
        }
    }
    return implode(' · ', $obs);
}

/**
 * Historial: [periodos del más nuevo al más viejo, grilla entidad => periodo => celda, total por mes].
 * Celda: ['s' => situación, 'm' => monto (miles), 'rev' => bool, 'jud' => bool].
 */
function bcra_historial(?array $his): array
{
    $periodos = [];
    $grilla = [];
    $totMes = [];
    foreach ((array) ($his['periodos'] ?? []) as $p) {
        $per = bcra_periodo($p['periodo'] ?? '');
        $periodos[$per] = true;
        foreach ((array) ($p['entidades'] ?? []) as $e) {
            $ent = (string) ($e['entidad'] ?? '');
            $prev = $grilla[$ent][$per] ?? null;
            $m = (float) ($e['monto'] ?? 0);
            $grilla[$ent][$per] = ['s' => max((int) ($e['situacion'] ?? 0), $prev['s'] ?? 0), 'm' => $m + ($prev['m'] ?? 0),
                                   'rev' => !empty($e['enRevision']) || !empty($prev['rev']),
                                   'jud' => !empty($e['procesoJud']) || !empty($prev['jud'])];
            $totMes[$per] = ($totMes[$per] ?? 0) + $m;
        }
    }
    krsort($periodos);
    uksort($grilla, function ($a, $b) use ($grilla) {
        return max(array_column($grilla[$b], 's')) <=> max(array_column($grilla[$a], 's')) ?: strcmp($a, $b);
    });
    return [array_keys($periodos), $grilla, $totMes];
}

/** Cheques rechazados en una lista, el más reciente primero (con _causal y _entidad). */
function bcra_cheques(?array $che): array
{
    $l = [];
    foreach ((array) ($che['causales'] ?? []) as $c) {
        foreach ((array) ($c['entidades'] ?? []) as $e) {
            foreach ((array) ($e['detalle'] ?? []) as $d) {
                $l[] = $d + ['_causal' => (string) ($c['causal'] ?? ''), '_entidad' => (string) ($e['entidad'] ?? '')];
            }
        }
    }
    usort($l, function ($a, $b) { return strcmp((string) ($b['fechaRechazo'] ?? ''), (string) ($a['fechaRechazo'] ?? '')); });
    return $l;
}

function bcra_cheque_obs(array $d): string
{
    $obs = [];
    if (!empty($d['denomJuridica'])) {
        $obs[] = 'Cuenta de ' . $d['denomJuridica'];
    }
    if (!empty($d['enRevision'])) {
        $obs[] = 'En revisión';
    }
    if (!empty($d['procesoJud'])) {
        $obs[] = 'Proceso judicial';
    }
    return implode(' · ', $obs);
}

function bcra_cheque_multa(array $d): string
{
    if (!empty($d['fechaPagoMulta'])) {
        return 'Pagada el ' . fecha($d['fechaPagoMulta']);
    }
    return !empty($d['estadoMulta']) ? ucfirst(strtolower((string) $d['estadoMulta'])) : '—';
}

/** Fecha y hora de la consulta más vieja de las tres (la que se muestra). */
function bcra_cuando(array $filas): string
{
    $c = '';
    foreach ($filas as $f) {
        if ($c === '' || $f['consultado_el'] < $c) {
            $c = $f['consultado_el'];
        }
    }
    return $c;
}

// ---------------------------------------------------------------------
// Informe en pantalla
// ---------------------------------------------------------------------

/** HTML del informe (tarjetas + deuda actual + historial + cheques). */
function bcra_informe_html(array $filas): string
{
    ['DEUDAS' => $deu, 'HISTORICAS' => $his, 'CHEQUES' => $che] = bcra_resultados($filas);
    $fD = $filas['DEUDAS'] ?? null;
    $fH = $filas['HISTORICAS'] ?? null;
    $fC = $filas['CHEQUES'] ?? null;
    $nada = '<span class="td-muted">—</span>';

    // ── Tarjetas ──
    $o = '<div class="bcra-tarjetas">';
    $o .= '<div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Situación actual (peor)</span>'
        . ($fD && $fD['peor_situacion'] ? bcra_situacion_pill($fD['peor_situacion']) : (bcra_sin_registros($fD) ? estado('Sin deudas informadas', 'ok') : $nada))
        . '<span class="bcra-tarjeta-sub">' . ($fD && $fD['periodo'] ? 'período ' . h(periodo_txt($fD['periodo'])) : '') . '</span></div>';
    $o .= '<div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Deuda total informada</span>'
        . '<strong>' . ($fD && $fD['deuda_total'] !== null ? money((float) $fD['deuda_total'] * 1000) : (bcra_sin_registros($fD) ? money(0) : '—')) . '</strong>'
        . '<span class="bcra-tarjeta-sub">' . ($fD && $fD['entidades'] ? (int) $fD['entidades'] . ((int) $fD['entidades'] === 1 ? ' entidad' : ' entidades') : '') . '</span></div>';
    $o .= '<div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Peor situación en 24 meses</span>'
        . ($fH && $fH['peor_situacion'] ? bcra_situacion_pill($fH['peor_situacion']) : (bcra_sin_registros($fH) ? estado('Sin historial', 'neutro') : $nada))
        . '<span class="bcra-tarjeta-sub"></span></div>';
    $o .= '<div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cheques rechazados</span>';
    if (bcra_sin_registros($fC)) {
        $o .= estado('Sin cheques rechazados', 'ok') . '<span class="bcra-tarjeta-sub"></span>';
    } elseif ($fC && $fC['cheques'] !== null) {
        $o .= '<strong>' . (int) $fC['cheques'] . '</strong><span class="bcra-tarjeta-sub">'
            . ((int) $fC['cheques_impagos'] ? estado((int) $fC['cheques_impagos'] . ' sin pagar', 'danger') : 'todos pagados') . '</span>';
    } else {
        $o .= $nada . '<span class="bcra-tarjeta-sub"></span>';
    }
    $o .= '</div></div>';
    $o .= '<p class="bcra-nota">Consultado el ' . h(date('d/m/Y H:i', strtotime(bcra_cuando($filas)))) . ' · fuente: API pública de la Central de Deudores del BCRA'
        . ($deu && !empty($deu['denominacion']) ? ' · informado como <strong>' . h($deu['denominacion']) . '</strong>' : '') . '</p>';

    // ── Deuda actual ──
    $ents = bcra_deuda_entidades($deu);
    $o .= '<div class="bloque-titulo bcra-titulo">Deuda actual' . ($fD && $fD['periodo'] ? ' · ' . h(periodo_txt($fD['periodo'])) : '') . '</div>';
    $o .= '<div class="data-table-wrap"><table class="data-table" data-sin-orden><thead><tr>'
        . '<th data-no-sort>Entidad</th><th data-no-sort>Situación</th><th class="th-right" data-no-sort>Monto</th>'
        . '<th class="th-right" data-no-sort>Días de atraso</th><th data-no-sort>En sit. 1 desde</th><th data-no-sort>Observaciones</th></tr></thead><tbody>';
    if (!$ents) {
        $o .= '<tr><td colspan="6" class="no-data">' . ($fD ? (bcra_sin_registros($fD) ? 'El BCRA no tiene deudas informadas para este CUIT.' : 'Sin datos.') : 'Todavía no se consultó.') . '</td></tr>';
    }
    foreach ($ents as $e) {
        $obs = bcra_deuda_obs($e);
        $o .= '<tr><td class="td-fuerte">' . h($e['entidad'] ?? '') . '</td>'
            . '<td>' . bcra_situacion_pill($e['situacion'] ?? null) . '</td>'
            . '<td class="td-right">' . money((float) ($e['monto'] ?? 0) * 1000) . '</td>'
            . '<td class="td-right">' . ((int) ($e['diasAtrasoPago'] ?? 0) ?: $nada) . '</td>'
            . '<td>' . h(fecha($e['fechaSit1'] ?? null)) . '</td>'
            . '<td class="td-wrap">' . ($obs !== '' ? h($obs) : $nada) . '</td></tr>';
    }
    $o .= '</tbody>';
    if ($ents) {
        $tot = array_sum(array_map(function ($e) { return (float) ($e['monto'] ?? 0); }, $ents));
        $o .= '<tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right">' . money($tot * 1000) . '</td><td colspan="3"></td></tr></tfoot>';
    }
    $o .= '</table></div><p class="bcra-nota">El BCRA informa los montos en miles de pesos; acá se muestran en pesos.</p>';

    // ── Historial 24 meses: entidad × mes, color por situación ──
    [$periodos, $grilla, $totMes] = bcra_historial($his);
    $o .= '<div class="bloque-titulo bcra-titulo">Historial de 24 meses</div>';
    if (!$periodos) {
        $o .= '<div class="vacio">' . ($fH ? (bcra_sin_registros($fH) ? 'El BCRA no tiene historial para este CUIT.' : 'Sin datos.') : 'Todavía no se consultó.') . '</div>';
    } else {
        $o .= '<div class="data-table-wrap"><table class="bcra-historial"><thead><tr><th class="bcra-ent">Entidad</th>';
        foreach ($periodos as $per) {
            $o .= '<th>' . h(bcra_periodo_corto($per)) . '</th>';
        }
        $o .= '</tr></thead><tbody>';
        foreach ($grilla as $ent => $meses) {
            $o .= '<tr><td class="bcra-ent" title="' . h($ent) . '">' . h($ent) . '</td>';
            foreach ($periodos as $per) {
                $c = $meses[$per] ?? null;
                if (!$c) {
                    $o .= '<td class="sit-0" title="Sin deuda informada">·</td>';
                    continue;
                }
                $tit = periodo_txt($per) . ' · ' . (BCRA_SITUACIONES[$c['s']][1] ?? 'Situación ' . $c['s']) . ' · ' . money($c['m'] * 1000)
                     . ($c['rev'] ? ' · en revisión' : '') . ($c['jud'] ? ' · proceso judicial' : '');
                $o .= '<td class="sit-' . (int) min($c['s'], 6) . '" title="' . h($tit) . '">' . (int) $c['s'] . ($c['jud'] ? '<sup>J</sup>' : '') . '</td>';
            }
            $o .= '</tr>';
        }
        $o .= '<tr class="bcra-total"><td class="bcra-ent">Deuda total (miles $)</td>';
        foreach ($periodos as $per) {
            $o .= '<td title="' . h(money(($totMes[$per] ?? 0) * 1000)) . '">' . h(num($totMes[$per] ?? 0)) . '</td>';
        }
        $o .= '</tr></tbody></table></div>';
        $o .= '<div class="bcra-leyenda">';
        foreach ([1, 2, 3, 4, 5] as $s) {
            $o .= '<span><i class="sit-' . $s . '">' . $s . '</i>' . h(BCRA_SITUACIONES[$s][0]) . '</span>';
        }
        $o .= '<span><i class="sit-0">·</i>Sin deuda</span><span><sup>J</sup> proceso judicial</span><span>Pasá el mouse sobre un mes para ver el monto.</span></div>';
    }

    // ── Cheques rechazados ──
    $cheques = bcra_cheques($che);
    $o .= '<div class="bloque-titulo bcra-titulo">Cheques rechazados</div>';
    $o .= '<div class="data-table-wrap"><table class="data-table" data-sin-orden><thead><tr>'
        . '<th data-no-sort>Rechazo</th><th data-no-sort>Causal</th><th data-no-sort title="El BCRA identifica al banco con un número">Entidad (cód.)</th><th data-no-sort>N° cheque</th>'
        . '<th class="th-right" data-no-sort>Monto</th><th data-no-sort>Pago del cheque</th><th data-no-sort>Multa</th><th data-no-sort>Observaciones</th></tr></thead><tbody>';
    if (!$cheques) {
        $o .= '<tr><td colspan="8" class="no-data">' . ($fC ? (bcra_sin_registros($fC) ? 'El BCRA no tiene cheques rechazados para este CUIT.' : 'Sin datos.') : 'Todavía no se consultó.') . '</td></tr>';
    }
    $totChe = 0.0;
    foreach ($cheques as $d) {
        $totChe += (float) ($d['monto'] ?? 0);
        $obs = bcra_cheque_obs($d);
        $o .= '<tr><td>' . h(fecha($d['fechaRechazo'] ?? null)) . '</td>'
            . '<td class="td-wrap">' . h($d['_causal']) . '</td>'
            . '<td class="mono">' . h($d['_entidad']) . '</td>'
            . '<td class="mono">' . h((string) ($d['nroCheque'] ?? '')) . '</td>'
            . '<td class="td-right">' . money((float) ($d['monto'] ?? 0)) . '</td>'
            . '<td>' . (!empty($d['fechaPago']) ? estado('Pagado el ' . fecha($d['fechaPago']), 'ok') : estado('Sin pagar', 'danger')) . '</td>'
            . '<td>' . h(bcra_cheque_multa($d)) . '</td>'
            . '<td class="td-wrap">' . ($obs !== '' ? h($obs) : $nada) . '</td></tr>';
    }
    $o .= '</tbody>';
    if ($cheques) {
        $o .= '<tfoot><tr class="total-row"><td colspan="4">TOTAL · ' . count($cheques) . ' cheques</td><td class="td-right">' . money($totChe) . '</td><td colspan="3"></td></tr></tfoot>';
    }
    $o .= '</table></div>';
    return $o;
}

// ---------------------------------------------------------------------
// Informe en PDF (para entregar o guardar en el legajo del cliente)
// ---------------------------------------------------------------------

/** Colores de cada situación en el PDF: [fondo, texto] (los de la pantalla). */
const BCRA_PDF_COLORES = [
    0 => ['#f1f4f9', '#b7c1d3'], 1 => ['#d6f0de', '#166534'], 2 => ['#fdf0c4', '#8a6000'], 3 => ['#fbd38d', '#7c4a03'],
    4 => ['#f8b4ae', '#8f1d14'], 5 => ['#c0392b', '#ffffff'], 6 => ['#c0392b', '#ffffff'],
];

/** Situación como celda de la tabla del PDF. */
function bcra_pdf_situacion($s): array
{
    $s = (int) $s;
    if (!isset(BCRA_SITUACIONES[$s])) {
        return ['t' => '—'];
    }
    return ['t' => $s . ' · ' . BCRA_SITUACIONES[$s][0], 'negrita' => true,
            'color' => $s === 1 ? PdfReporte::OK : ($s >= 4 ? PdfReporte::PELIGRO : '#8a6000')];
}

/** Arma el PDF del informe. $quien: denominación del cliente (o null). */
function bcra_informe_pdf(array $filas, string $cuit, ?string $quien = null): PdfReporte
{
    require_once __DIR__ . '/pdf.php';
    ['DEUDAS' => $deu, 'HISTORICAS' => $his, 'CHEQUES' => $che] = bcra_resultados($filas);
    $fD = $filas['DEUDAS'] ?? null;
    $fH = $filas['HISTORICAS'] ?? null;
    $fC = $filas['CHEQUES'] ?? null;
    $nombre = $quien ?: ($deu['denominacion'] ?? $his['denominacion'] ?? $che['denominacion'] ?? '');

    $rep = new PdfReporte('Central de Deudores · ' . cuit_fmt($cuit) . ($nombre !== '' ? ' · ' . $nombre : ''),
        'Consultado el ' . date('d/m/Y H:i', strtotime(bcra_cuando($filas))) . ' · fuente: API pública de la Central de Deudores del BCRA'
        . (!empty($deu['denominacion']) && $quien && $deu['denominacion'] !== $quien ? ' · informado como ' . $deu['denominacion'] : ''));

    $sit = function (?array $f) {
        if ($f && $f['peor_situacion']) {
            return $f['peor_situacion'] . ' · ' . BCRA_SITUACIONES[(int) $f['peor_situacion']][0];
        }
        return bcra_sin_registros($f) ? 'Sin registros' : '—';
    };
    $rep->indicadores([
        ['Situación actual (peor)', $sit($fD)],
        ['Deuda total informada', $fD && $fD['deuda_total'] !== null ? money((float) $fD['deuda_total'] * 1000) : (bcra_sin_registros($fD) ? money(0) : '—')],
        ['Peor situación en 24 meses', $sit($fH)],
        ['Cheques rechazados', $fC && $fC['cheques'] !== null ? $fC['cheques'] . ((int) $fC['cheques_impagos'] ? ' · ' . $fC['cheques_impagos'] . ' sin pagar' : ' · todos pagados')
                                 : (bcra_sin_registros($fC) ? 'Ninguno' : '—')],
    ]);

    // Deuda actual
    $ents = bcra_deuda_entidades($deu);
    $rep->seccion('Deuda actual', ($fD && $fD['periodo'] ? periodo_txt($fD['periodo']) . ' · ' : '') . 'montos en pesos (el BCRA informa en miles)');
    $tot = array_sum(array_map(function ($e) { return (float) ($e['monto'] ?? 0); }, $ents));
    $rep->tabla(
        [['Entidad', 3.2, 'L'], ['Situación', 1.6, 'L'], ['Monto', 1.5, 'R'], ['Días de atraso', 1.1, 'R'], ['En sit. 1 desde', 1.2, 'L'], ['Observaciones', 3, 'L']],
        array_map(function ($e) {
            return [$e['entidad'] ?? '', bcra_pdf_situacion($e['situacion'] ?? null), money((float) ($e['monto'] ?? 0) * 1000),
                    (int) ($e['diasAtrasoPago'] ?? 0) ?: '—', fecha($e['fechaSit1'] ?? null), bcra_deuda_obs($e)];
        }, $ents),
        $ents ? ['TOTAL', '', money($tot * 1000), '', '', ''] : null
    );

    // Historial
    [$periodos, $grilla, $totMes] = bcra_historial($his);
    $rep->seccion('Historial de 24 meses', 'situación por entidad y mes');
    if (!$periodos) {
        $rep->nota(bcra_sin_registros($fH) ? 'El BCRA no tiene historial para este CUIT.' : 'Sin datos.');
    } else {
        $filasG = [];
        foreach ($grilla as $ent => $meses) {
            $celdas = [];
            foreach ($periodos as $per) {
                $c = $meses[$per] ?? null;
                [$fondo, $color] = BCRA_PDF_COLORES[$c ? min((int) $c['s'], 6) : 0];
                $celdas[] = $c ? ['t' => $c['s'] . ($c['jud'] ? 'J' : ''), 'fondo' => $fondo, 'color' => $color, 'negrita' => true]
                               : ['t' => '', 'fondo' => $fondo];
            }
            $filasG[] = [$ent, $celdas];
        }
        $filasG[] = ['Deuda total (miles $)', array_map(function ($per) use ($totMes) {
            return ['t' => num($totMes[$per] ?? 0), 'fondo' => '#ffffff', 'color' => PdfReporte::TENUE];
        }, $periodos)];
        $rep->grilla('Entidad', array_map('bcra_periodo_corto', $periodos), $filasG);
        $ley = [];
        foreach ([1, 2, 3, 4, 5] as $s) {
            $ley[] = [BCRA_PDF_COLORES[$s][0], BCRA_PDF_COLORES[$s][1], (string) $s, BCRA_SITUACIONES[$s][0]];
        }
        $ley[] = [BCRA_PDF_COLORES[0][0], BCRA_PDF_COLORES[0][1], '', 'Sin deuda'];
        $ley[] = ['#ffffff', PdfReporte::TEXTO, 'J', 'Proceso judicial'];
        $rep->leyenda($ley);
    }

    // Cheques
    $cheques = bcra_cheques($che);
    $rep->seccion('Cheques rechazados', $cheques ? count($cheques) . ' cheques' : '');
    $totChe = array_sum(array_map(function ($d) { return (float) ($d['monto'] ?? 0); }, $cheques));
    $rep->tabla(
        [['Rechazo', 1, 'L'], ['Causal', 2.2, 'L'], ['Entidad', 0.8, 'L'], ['N° cheque', 1.1, 'L'], ['Monto', 1.4, 'R'],
         ['Pago del cheque', 1.5, 'L'], ['Multa', 1.4, 'L'], ['Observaciones', 2.4, 'L']],
        array_map(function ($d) {
            return [fecha($d['fechaRechazo'] ?? null), $d['_causal'], $d['_entidad'], (string) ($d['nroCheque'] ?? ''),
                    money((float) ($d['monto'] ?? 0)),
                    !empty($d['fechaPago']) ? ['t' => 'Pagado el ' . fecha($d['fechaPago']), 'color' => PdfReporte::OK]
                                            : ['t' => 'Sin pagar', 'color' => PdfReporte::PELIGRO, 'negrita' => true],
                    bcra_cheque_multa($d), bcra_cheque_obs($d)];
        }, $cheques),
        $cheques ? ['TOTAL · ' . count($cheques) . ' cheques', '', '', '', money($totChe), '', '', ''] : null
    );
    if (!$cheques && bcra_sin_registros($fC)) {
        $rep->nota('El BCRA no tiene cheques rechazados para este CUIT.');
    }
    $rep->nota('Situación: 1 normal · 2 riesgo bajo / seguimiento especial · 3 riesgo medio / con problemas · 4 riesgo alto · 5 irrecuperable.');
    return $rep;
}
