<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// «Consultar ARCA» (JSON): constancia de inscripción de un CUIT/CUIL
// (o el padrón A13, si el certificado no tiene la constancia).
//   POST cuit=N, _csrf     consulta a ARCA, guarda y devuelve los datos
// La usa el botón de la ficha del cliente. La seguridad (sesión, opción de
// menú) la hace bootstrap, que con FIN_API contesta los cortes en JSON.
// ---------------------------------------------------------------------

define('FIN_API', true);

require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/arca.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(array $datos, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($datos + ['ok' => $http < 400], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!es_post()) {
    responder(['error' => 'Usá POST.'], 405);
}
$f = $_POST['_csrf'] ?? '';
$c = $_COOKIE['fin_csrf'] ?? '';
if (!is_string($f) || !is_string($c) || $c === '' || !hash_equals($c, $f)) {
    responder(['error' => 'La pantalla venció. Recargala y probá de nuevo.'], 400);
}

$cuit = cuit_limpio(post_str('cuit'));
if (!cuit_valido($cuit)) {
    responder(['error' => strlen($cuit) === 11 ? 'El CUIT/CUIL no es válido: el dígito verificador no coincide.'
                                               : 'Cargá el CUIT/CUIL completo (11 dígitos).'], 422);
}

try {
    $datos = arca_constancia($cuit);
} catch (ArcaError $e) {
    responder(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('arca.php ' . $cuit . ': ' . $e->getMessage());
    responder(['error' => (stripos($e->getMessage(), 'arca_tokens') !== false
        ? 'Faltan las tablas de ARCA: corré sql\financiera_arca.sql.'
        : 'No se pudo consultar ARCA: ' . $e->getMessage())], 500);
}

$aviso = '';
if (($datos['fuente'] ?? '') === 'padron_a13') {
    // Del A13 no se guarda: pisaría una constancia completa con menos datos.
    $aviso = 'La condición frente al IVA no vino: el certificado no tiene la Constancia de Inscripción en ARCA '
           . 'y se consultó el padrón A13 (nombre y domicilio fiscal). Cargá la condición de IVA a mano.';
} else {
    try {
        arca_constancia_guardar($datos, usuario_id());
    } catch (Throwable $e) {
        $aviso = 'La consulta salió bien pero no se pudo guardar (¿corriste sql\financiera_arca.sql?).';
    }
}
responder(['datos' => $datos, 'aviso' => $aviso]);
