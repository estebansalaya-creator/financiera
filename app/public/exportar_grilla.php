<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/xlsx.php';

// ---------------------------------------------------------------------
// Excel de una grilla que filtra en el navegador (tabla.js, data-local):
// el «Exportar a Excel» de gestion_adm (POST /exportar/excel). Llegan las
// columnas y las filas que se están viendo filtradas (todas las páginas,
// sin la columna de acciones) y vuelve el .xlsx. No lee la base: arma el
// archivo con lo que la persona ya tiene en pantalla.
//   POST JSON {nombre, columnas: [{titulo, tipo}], filas: [[…], …]}
//        tipo «n»: número (importe, cantidad o porcentaje); si no, texto
//        o fecha (dd/mm/aaaa)
// ---------------------------------------------------------------------

if (!es_post()) {
    http_response_code(405);
    exit;
}
$tok = $_SERVER['HTTP_X_CSRF'] ?? '';
$cookie = $_COOKIE['fin_csrf'] ?? '';
if (!is_string($tok) || !is_string($cookie) || $cookie === '' || !hash_equals($cookie, $tok)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('El pedido venció: volvé a la pantalla y probá de nuevo.');
}

$d = json_decode((string) file_get_contents('php://input'), true);
$cols = is_array($d['columnas'] ?? null) ? array_slice($d['columnas'], 0, 60) : [];
$filas = is_array($d['filas'] ?? null) ? array_slice($d['filas'], 0, 50000) : [];
$nombre = trim(preg_replace('/\s+/', ' ', (string) ($d['nombre'] ?? 'Grilla')));
if (!$cols) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No llegaron columnas.');
}

/** «$ 1.234,56» / «12,5 %» / «1.234» → número; null si no es un número. */
function num_ar(string $s): ?float
{
    $t = str_replace(['$', '%', ' ', "\u{a0}", 'US'], '', $s);
    $t = str_replace('.', '', $t);
    $t = str_replace(',', '.', $t);
    return $t !== '' && is_numeric($t) ? (float) $t : null;
}

// El tipo de cada columna: los números según lo que traen (con decimales o
// «$», importe; con «%», porcentaje; si no, entero); las fechas dd/mm/aaaa.
$defs = [];
foreach ($cols as $i => $c) {
    $titulo = (string) ($c['titulo'] ?? '');
    $valores = array_values(array_filter(array_map(function ($f) use ($i) { return trim((string) ($f[$i] ?? '')); }, $filas), 'strlen'));
    $tipo = 'texto';
    if (($c['tipo'] ?? '') === 'n') {
        $esPct = $valores && count(array_filter($valores, function ($v) { return strpos($v, '%') !== false; })) === count($valores);
        $conDec = (bool) array_filter($valores, function ($v) { return strpos($v, ',') !== false || strpos($v, '$') !== false; });
        $tipo = $esPct ? 'pct' : ($conDec ? 'moneda' : 'entero');
    } elseif ($valores && count(array_filter($valores, function ($v) { return preg_match('#^\d{2}/\d{2}/\d{4}$#', $v); })) === count($valores)) {
        $tipo = 'fecha';
    }
    $largo = max(array_merge([mb_strlen($titulo)], array_map('mb_strlen', array_slice($valores, 0, 500))));
    $defs[] = [$titulo, $tipo, max(8, min(50, $largo + 2))];
}

$datos = [];
foreach ($filas as $f) {
    $r = [];
    foreach ($defs as $i => [, $tipo]) {
        $v = trim((string) ($f[$i] ?? ''));
        if ($v === '') {
            $r[] = null;
        } elseif ($tipo === 'fecha') {
            $r[] = preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m) ? "$m[3]-$m[2]-$m[1]" : null;
        } elseif ($tipo === 'pct') {
            $n = num_ar($v);
            $r[] = $n === null ? null : $n / 100;
        } elseif ($tipo === 'moneda' || $tipo === 'entero') {
            $r[] = num_ar($v);
        } else {
            $r[] = $v;
        }
    }
    $datos[] = $r;
}

$libro = new XlsxLibro();
$libro->hoja($nombre !== '' ? $nombre : 'Grilla')
      ->titulo($nombre, num(count($datos)) . ' renglones · generado el ' . date('d/m/Y H:i'))
      ->tabla($defs, $datos);
$libro->enviar(trim(preg_replace('/[^\w\-]+/u', '_', mb_strtolower($nombre)), '_') . '.xlsx');
