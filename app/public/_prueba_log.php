<?php
declare(strict_types=1);
// TEMPORAL: prueba del log de errores. Borrar.
if (($_GET['m'] ?? '') === 'api') { define('FIN_API', true); }
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$m = get_str('m');
$msg = '';
if ($m === 'atajado') {
    try { q('SELECT columna_que_no_existe FROM usuarios'); } catch (Throwable $e) { $msg = mensaje_bd($e); }
} elseif ($m === 'negocio') {
    try { throw new RuntimeException('Escribí el motivo.'); } catch (Throwable $e) { $msg = error_amigable($e); }
} elseif ($m === 'excepcion' || $m === 'api') {
    $x = null;
    strlen($x->algo);   // Error sin atajar
} elseif ($m === 'tipo') {
    function _f(int $n): int { return $n; }
    _f('abc');
} elseif ($m === 'fatal') {
    set_time_limit(1);
    while (true) { }
} elseif ($m === 'media') {
    layout_inicio('Prueba');
    echo '<p>Antes del error</p>';
    throw new LogicException('Se cortó a mitad de la página');
} elseif ($m === '500') {
    http_response_code(500);
    echo 'falló algo';
    exit;
}
layout_inicio('Prueba log');
echo '<div class="card" style="padding:20px">Resultado: ' . h($msg) . '</div>';
layout_fin();
