<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/empresa.php';

// Manifiesto de la app instalable (PWA), armado con los datos de la
// empresa: nombre e íconos (los del logo si se cargó, si no los estándar).
// Es público: el navegador lo pide sin sesión.

$nombre = empresa_nombre();
$corto = function_exists('mb_substr') ? mb_substr($nombre, 0, 12) : substr($nombre, 0, 12);
$icono = function (string $t, string $proposito): array {
    $lado = ICONOS_APP[$t][1];
    return ['src' => app_icono_url($t), 'sizes' => $lado . 'x' . $lado, 'type' => 'image/png', 'purpose' => $proposito];
};
$m = [
    'id' => './',
    'name' => $nombre . ' · Backoffice de préstamos',
    'short_name' => $corto,
    'description' => 'Préstamos, cobranzas, clientes y comisiones.',
    'lang' => 'es-AR',
    'start_url' => './index.php',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#f2f5fa',
    'theme_color' => '#1c5a8f',
    'icons' => [$icono('192', 'any'), $icono('512', 'any'), $icono('mask', 'maskable')],
];
header_remove('Pragma');
header_remove('Expires');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
