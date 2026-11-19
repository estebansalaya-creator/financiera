<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/empresa.php';

// Un ícono de la app armado desde el logo (?t=32|180|192|512|mask). Si no
// hay, el estándar de assets/iconos. Público: lo pide el sistema operativo
// al instalar la app, sin sesión. La URL lleva ?v=, así que se guarda en caché.

$t = (string) ($_GET['t'] ?? '192');
if (!isset(ICONOS_APP[$t])) {
    $t = '192';
}
[$col, , $archivo] = ICONOS_APP[$t];
$bin = null;
if (app_icono_propio()) {
    try {
        $r = q1('SELECT ' . $col . ' AS png FROM empresa WHERE id_empresa = 1');
        $bin = $r && $r['png'] !== null ? (string) $r['png'] : null;
    } catch (Throwable $e) {
    }
}
if ($bin === null) {
    $bin = (string) file_get_contents(__DIR__ . '/assets/iconos/' . $archivo);
}
$etag = '"' . md5($bin) . '"';
header_remove('Pragma');
header_remove('Expires');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . strlen($bin));
echo $bin;
