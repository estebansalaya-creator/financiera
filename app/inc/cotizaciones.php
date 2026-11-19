<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Cotizaciones de moneda extranjera desde la API pública de
// Estadísticas Cambiarias del BCRA.
//   /Cotizaciones?fecha=aaaa-mm-dd            todas las monedas de un día
//   /Cotizaciones/{moneda}?fechadesde&hasta   serie de una moneda
// tipoCotizacion = pesos por unidad; tipoPase = unidades por dólar.
// ---------------------------------------------------------------------

require_once __DIR__ . '/bcra.php';

function cotiz_url(string $ruta): string
{
    return rtrim((string) cfg('cotizaciones_url', 'https://api.bcra.gob.ar/estadisticascambiarias/v1.0'), '/') . '/' . $ruta;
}

/** Monedas destacadas (parámetro COTIZACIONES_MONEDAS). */
function cotiz_monedas(): array
{
    $p = q1("SELECT valor FROM parametros WHERE clave = 'COTIZACIONES_MONEDAS'");
    $l = array_filter(array_map('trim', explode(',', strtoupper($p['valor'] ?? 'USD,EUR,BRL'))), function ($m) {
        return preg_match('/^[A-Z]{3}$/', $m);
    });
    return array_values($l) ?: ['USD'];
}

/** Guarda el detalle de un día. Devuelve cuántas monedas se guardaron. */
function cotiz_guardar(string $fecha, array $detalle): int
{
    $n = 0;
    $st = db()->prepare('INSERT INTO cotizaciones (fecha, moneda, descripcion, cotizacion, pase, fuente) VALUES (?,?,?,?,?,\'BCRA\')
                         ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), cotizacion = VALUES(cotizacion), pase = VALUES(pase), fuente = \'BCRA\'');
    foreach ($detalle as $d) {
        $mon = strtoupper((string) ($d['codigoMoneda'] ?? ''));
        $cot = (float) ($d['tipoCotizacion'] ?? 0);
        if (!preg_match('/^[A-Z]{3}$/', $mon) || $mon === 'ARS' || $cot <= 0) {
            continue;                    // ARS y monedas sin cotización no se guardan
        }
        $st->execute([$fecha, $mon, mb_substr_seguro(trim((string) ($d['descripcion'] ?? '')), 80), $cot, $d['tipoPase'] ?? null]);
        $n++;
    }
    return $n;
}

function cotiz_log(string $origen, ?string $desde, ?string $hasta, int $registros, bool $ok, string $mensaje): void
{
    try {
        q('INSERT INTO cotizaciones_log (origen, desde, hasta, registros, ok, mensaje) VALUES (?,?,?,?,?,?)',
          [$origen, $desde, $hasta, $registros, $ok ? 1 : 0, mb_substr_seguro($mensaje, 255)]);
    } catch (Throwable $e) {
        error_log('[financiera] cotizaciones_log: ' . $e->getMessage());
    }
}

/**
 * Trae del BCRA los días hábiles desde $desde hasta hoy (todas las monedas)
 * y los guarda. Para no pegarle de más, como máximo 15 días por corrida;
 * para más, cotiz_historico(). Devuelve [registros, error o ''].
 */
function cotiz_actualizar(string $origen = 'PANTALLA', ?string $desde = null): array
{
    $hoy = date('Y-m-d');
    if ($desde === null) {
        $u = q1('SELECT MAX(fecha) AS f FROM cotizaciones');
        $desde = $u['f'] ? date('Y-m-d', strtotime($u['f'] . ' +1 day')) : date('Y-m-d', strtotime('-7 days'));
        $desde = max($desde, date('Y-m-d', strtotime('-15 days')));
    }
    $total = 0;
    $error = '';
    $dias = 0;
    for ($f = $desde; $f <= $hoy && $dias < 16; $f = date('Y-m-d', strtotime($f . ' +1 day')), $dias++) {
        if ((int) date('N', strtotime($f)) >= 6) {
            continue;                    // sábado y domingo no hay
        }
        [$st, $json, $err] = bcra_http(cotiz_url('Cotizaciones?fecha=' . $f));
        if ($err !== '' || (int) ($json['status'] ?? $st) !== 200) {
            $error = $err !== '' ? $err : 'El BCRA devolvió: ' . implode(' ', (array) ($json['errorMessages'] ?? ['error ' . $st]));
            break;
        }
        $res = $json['results'] ?? [];
        if (!empty($res['fecha']) && !empty($res['detalle'])) {
            $total += cotiz_guardar(substr((string) $res['fecha'], 0, 10), (array) $res['detalle']);
        }
    }
    // Lo último publicado (por si hoy todavía no está y ayer fue feriado)
    if ($error === '' && $total === 0) {
        [$st, $json, $err] = bcra_http(cotiz_url('Cotizaciones'));
        if ($err === '' && !empty($json['results']['fecha'])) {
            $total += cotiz_guardar(substr((string) $json['results']['fecha'], 0, 10), (array) ($json['results']['detalle'] ?? []));
        }
    }
    cotiz_log($origen, $desde, $hoy, $total, $error === '', $error !== '' ? $error : 'OK');
    return [$total, $error];
}

/** Serie histórica de las monedas destacadas (hasta 1000 días por moneda). */
function cotiz_historico(string $desde, string $hasta): array
{
    $total = 0;
    $errores = [];
    foreach (cotiz_monedas() as $mon) {
        $offset = 0;
        do {
            [$st, $json, $err] = bcra_http(cotiz_url('Cotizaciones/' . $mon . '?fechadesde=' . $desde . '&fechahasta=' . $hasta
                                                    . '&limit=1000&offset=' . $offset));
            if ($err !== '' || (int) ($json['status'] ?? $st) !== 200) {
                $errores[] = $mon . ': ' . ($err !== '' ? $err : implode(' ', (array) ($json['errorMessages'] ?? ['error ' . $st])));
                break;
            }
            $res = (array) ($json['results'] ?? []);
            foreach ($res as $dia) {
                if (!empty($dia['fecha'])) {
                    $total += cotiz_guardar(substr((string) $dia['fecha'], 0, 10), (array) ($dia['detalle'] ?? []));
                }
            }
            $offset += 1000;
        } while (count($res) >= 1000 && $offset < 5000);
    }
    $err = implode(' · ', $errores);
    cotiz_log('HISTORICO', $desde, $hasta, $total, $err === '', $err !== '' ? $err : 'OK');
    return [$total, $err];
}

/** Última actualización correcta (fecha y hora) o null. */
function cotiz_ultima_actualizacion(): ?string
{
    $f = q1('SELECT MAX(ejecutado_el) AS f FROM cotizaciones_log WHERE ok = 1');
    return $f['f'] ?? null;
}

/** Pesos por unidad a una fecha (o el último día hábil anterior). */
function cotizacion(string $moneda, ?string $fecha = null): ?float
{
    if ($moneda === 'ARS') {
        return 1.0;
    }
    $f = q1('SELECT cotizacion FROM cotizaciones WHERE moneda = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1', [$moneda, $fecha ?? date('Y-m-d')]);
    return $f ? (float) $f['cotizacion'] : null;
}
