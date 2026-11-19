<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/empresa.php';

// El logo de la empresa (tabla empresa). Es público: sale también en la
// pantalla de ingreso. La URL lleva ?v=<fecha del logo>, así que el
// navegador lo guarda y lo vuelve a pedir solo cuando cambia.

$r = null;
try {
    $r = q1('SELECT logo, logo_tipo, logo_actualizado_el FROM empresa WHERE id_empresa = 1 AND logo IS NOT NULL');
} catch (Throwable $e) {
}
if (!$r || !in_array($r['logo_tipo'], ['image/png', 'image/jpeg'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sin logo.');
}
$etag = '"' . md5((string) $r['logo_actualizado_el'] . strlen((string) $r['logo'])) . '"';
header_remove('Pragma');            // los pone la sesión (seguridad_iniciar) y anularían la caché
header_remove('Expires');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $r['logo_tipo']);
header('Content-Length: ' . strlen((string) $r['logo']));
echo $r['logo'];
