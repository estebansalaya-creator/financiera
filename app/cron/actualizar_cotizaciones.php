<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Actualiza las cotizaciones desde la API del BCRA. Lo corre la tarea
// programada de Windows (instalar_tarea_cotizaciones.ps1).
//
//   php actualizar_cotizaciones.php                 días hábiles que falten hasta hoy
//   php actualizar_cotizaciones.php --historico=365 serie de las monedas destacadas
//
// Sale con código 0 si anduvo y 1 si no (la tarea lo muestra como error).
// Cada corrida queda en cotizaciones_log.
// ---------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/cotizaciones.php';

$hist = 0;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--historico=(\d+)$/', $a, $m)) {
        $hist = min(3650, (int) $m[1]);
    }
}

try {
    if ($hist > 0) {
        [$n, $err] = cotiz_historico(date('Y-m-d', strtotime('-' . $hist . ' days')), date('Y-m-d'));
    } else {
        [$n, $err] = cotiz_actualizar('TAREA');
    }
} catch (Throwable $e) {
    $n = 0;
    $err = $e->getMessage();
}

echo date('Y-m-d H:i:s') . ' · ' . $n . ' cotizaciones guardadas' . ($err !== '' ? ' · ERROR: ' . $err : '') . PHP_EOL;
exit($err !== '' ? 1 : 0);
