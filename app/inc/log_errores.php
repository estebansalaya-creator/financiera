<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Log de errores: lo que falla en una pantalla queda anotado con la fecha
// y hora, el usuario, el método y la URL, el error y la traza. Se consulta
// en Administración del Sistema › Log de errores (admin_errores.php).
// El mismo modelo que gestion_adm (log_errores.py), con la tabla
// log_errores (sql\financiera_log_errores.sql).
//
// QUÉ SE REGISTRA
//   excepcion  Una excepción que nadie atajó, o un error fatal de PHP
//              (tiempo máximo, memoria, una función que no existe). Antes
//              terminaba en el 500 pelado de IIS; ahora el usuario ve «La
//              pantalla no se pudo abrir» con el código del error.
//   respuesta  Un pedido que terminó con 500 o más sin que se anotara nada.
//   aviso      Un error que la pantalla atajó y le mostró a la persona: todo
//              lo que pasa por error_amigable() (y por mensaje_bd() cuando
//              no es una regla de la base). No se anotan las excepciones de
//              reglas de negocio: RuntimeException a secas («Escribí el
//              motivo de la anulación») y las propias de la aplicación
//              (TalonarioError, ChatError, SolicitudFrenada…). Tampoco los
//              SIGNAL de los procedures, las claves duplicadas ni las
//              foráneas: mensaje_bd() los explica sin pasar por acá. Los
//              deadlocks (1213/1205) sí, para saber cuántas veces pasa.
//
//   No se anotan los avisos de PHP (warnings, notices), los «no tenés
//   permiso» ni la sesión vencida. Tampoco lo que corre por consola (la
//   tarea de cotizaciones): no tiene URL ni usuario, sigue en su .log.
//
// REPETICIONES: el mismo error (misma firma: origen + tipo + el lugar del
// código donde saltó) del mismo usuario dentro de 5 minutos suma «veces» y
// corre «ultima» en vez de abrir otra fila. Se guarda 180 días.
//
// NUNCA ROMPE NADA: anota con una conexión propia (si la pantalla estaba en
// una transacción que se deshace, el registro no se va con ella) y todo va
// envuelto: sin la tabla o con la base caída queda solo en php_errores.log.
//
// LO QUE NO SE GUARDA: el cuerpo del formulario (contraseñas, datos de
// clientes) ni los argumentos de las funciones en la traza. De la URL se
// tapan los parámetros que parecen secretos (clave, token…).
// ---------------------------------------------------------------------

const LOGERR_VENTANA_MIN    = 5;
const LOGERR_RETENCION_DIAS = 180;
const LOGERR_ORIGENES = [
    'excepcion' => ['Excepción', 'danger'],
    'respuesta' => ['Respuesta 500', 'warn'],
    'aviso'     => ['Atajado', 'info'],
];

/** Excepciones de PHP que la aplicación usa para explicarle algo a la persona, no para fallas. */
const LOGERR_NEGOCIO = ['RuntimeException', 'InvalidArgumentException', 'DomainException',
                        'LengthException', 'RangeException', 'UnexpectedValueException'];

$GLOBALS['LOGERR_ID'] = null;       // la fila anotada en este pedido (el «código del error»)
$GLOBALS['LOGERR_VISTAS'] = [];     // las excepciones ya anotadas: una vez cada una

/** La conexión propia del log (null si la base no responde). */
function logerr_db(): ?PDO
{
    static $pdo = null, $fallo = false;
    if ($pdo === null && !$fallo) {
        try {
            $pdo = db_conectar();
        } catch (Throwable $e) {
            $fallo = true;
            error_log('[financiera] log de errores: no se pudo conectar: ' . $e->getMessage());
        }
    }
    return $pdo;
}

/** La ruta relativa a app\ (con /), o null si el archivo no es de la aplicación. */
function logerr_rel(?string $archivo): ?string
{
    if ($archivo === null || $archivo === '') {
        return null;
    }
    $raiz = str_replace('\\', '/', dirname(__DIR__)) . '/';
    $f = str_replace('\\', '/', $archivo);
    return strncasecmp($f, $raiz, strlen($raiz)) === 0 ? substr($f, strlen($raiz)) : null;
}

function logerr_funcion(?array $frame): string
{
    if (!$frame) {
        return '{main}';
    }
    return ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
}

/**
 * El renglón de código propio más profundo donde saltó: «public/cobrar.php:120 registrar_pago».
 * Se saltean los ayudantes de bootstrap.php (q, q1, db): si no, todos los
 * errores de la base tendrían el mismo lugar y la misma firma.
 */
function logerr_lugar(Throwable $e): string
{
    $tr = $e->getTrace();
    $lugares = [];
    if (!isset($tr[0]['file']) || $tr[0]['file'] !== $e->getFile() || ($tr[0]['line'] ?? 0) !== $e->getLine()) {
        $lugares[] = [$e->getFile(), $e->getLine(), $tr[0] ?? null];     // el throw, dentro de la función de $tr[0]
    }
    foreach ($tr as $i => $t) {
        if (isset($t['file'])) {
            $lugares[] = [$t['file'], (int) ($t['line'] ?? 0), $tr[$i + 1] ?? null];
        }
    }
    $primero = '';
    foreach ($lugares as [$archivo, $linea, $frame]) {
        $rel = logerr_rel($archivo);
        if ($rel === null) {
            continue;
        }
        $txt = $rel . ':' . $linea . ' ' . logerr_funcion($frame);
        if ($primero === '') {
            $primero = $txt;
        }
        if (strcasecmp($rel, 'inc/bootstrap.php') !== 0) {
            return $txt;
        }
    }
    return $primero;
}

/** La traza, sin los argumentos (pueden traer contraseñas o datos de clientes). */
function logerr_traza(Throwable $e): string
{
    $out = '';
    for ($n = 0; $e !== null && $n < 5; $e = $e->getPrevious(), $n++) {
        $out .= ($n ? "\nCausado por: " : '') . get_class($e) . ': ' . $e->getMessage() . "\n"
              . '  en ' . (logerr_rel($e->getFile()) ?? $e->getFile()) . ':' . $e->getLine() . "\n";
        foreach ($e->getTrace() as $i => $t) {
            $donde = isset($t['file']) ? (logerr_rel($t['file']) ?? $t['file']) . '(' . ($t['line'] ?? '?') . ')' : '[interno]';
            $out .= '#' . $i . ' ' . $donde . ': ' . logerr_funcion($t) . "()\n";
        }
    }
    return $out;
}

/** La URL pedida, con los parámetros que parecen secretos tapados. */
function logerr_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    [$ruta, $consulta] = array_pad(explode('?', $uri, 2), 2, '');
    if ($consulta === '') {
        return mb_substr($ruta, 0, 1000);
    }
    $partes = [];
    foreach (explode('&', $consulta) as $par) {
        [$k, $v] = array_pad(explode('=', $par, 2), 2, '');
        $partes[] = preg_match('/pass|clave|token|secret|key|pwd|contrase/i', urldecode($k)) ? $k . '=***' : $par;
    }
    return mb_substr($ruta . '?' . implode('&', $partes), 0, 1000);
}

/** ¿Es una validación o una regla de negocio, y no una falla? */
function logerr_es_negocio(Throwable $e): bool
{
    if (in_array(get_class($e), LOGERR_NEGOCIO, true)) {
        return true;
    }
    // Las propias de la aplicación (TalonarioError, ChatError…) también.
    try {
        return logerr_rel((string) (new ReflectionClass($e))->getFileName()) !== null;
    } catch (Throwable $x) {
        return false;
    }
}

/**
 * Anota un error del pedido en curso. Devuelve el id de la fila (el código
 * que se le muestra a la persona) o null. Nunca lanza. Se puede llamar a
 * mano desde una pantalla:
 *     } catch (Throwable $e) { log_error_registrar('aviso', $e); … }
 * $extra: tipo, lugar y detalle cuando no hay excepción (los errores fatales).
 */
function log_error_registrar(string $origen, ?Throwable $e = null, ?string $mensaje = null,
                             ?int $status = null, array $extra = []): ?int
{
    if (PHP_SAPI === 'cli') {
        return null;
    }
    try {
        if ($e !== null) {
            $clave = spl_object_id($e);
            if (isset($GLOBALS['LOGERR_VISTAS'][$clave])) {
                return $GLOBALS['LOGERR_VISTAS'][$clave];
            }
        }
        $tipo    = $e ? get_class($e) : (string) ($extra['tipo'] ?? '');
        $lugar   = $e ? logerr_lugar($e) : (string) ($extra['lugar'] ?? '');
        $detalle = $e ? logerr_traza($e) : (string) ($extra['detalle'] ?? '');
        $mensaje = trim((string) ($mensaje ?? ($e ? $e->getMessage() : ''))) ?: '(sin mensaje)';
        $pantalla = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $firma = sha1(implode('|', [$origen, $tipo, $lugar !== '' ? $lugar : $pantalla,
                                    $lugar !== '' ? '' : mb_substr($mensaje, 0, 120)]));
        $idUsuario = !empty($_SESSION['fin_uid']) ? (int) $_SESSION['fin_uid'] : null;
        $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
        $url = logerr_url();

        $pdo = logerr_db();
        if ($pdo === null) {
            return null;
        }
        $st = $pdo->prepare('SELECT id FROM log_errores
                              WHERE firma = ? AND id_usuario <=> ? AND ultima >= NOW() - INTERVAL ' . LOGERR_VENTANA_MIN . ' MINUTE
                              ORDER BY id DESC LIMIT 1');
        $st->execute([$firma, $idUsuario]);
        $previa = $st->fetchColumn();
        if ($previa) {
            $pdo->prepare('UPDATE log_errores SET veces = veces + 1, ultima = NOW(), url = ? WHERE id = ?')
                ->execute([$url, (int) $previa]);
            $id = (int) $previa;
        } else {
            $fila = [
                'origen'     => $origen,
                'status'     => $status,
                'tipo'       => mb_substr($tipo, 0, 150) ?: null,
                'mensaje'    => mb_substr($mensaje, 0, 4000),
                'detalle'    => mb_substr($detalle, 0, 60000) ?: null,
                'lugar'      => mb_substr($lugar, 0, 300) ?: null,
                'firma'      => $firma,
                'id_usuario' => $idUsuario,
                'usuario'    => mb_substr((string) ($_SESSION['fin_nombre'] ?? ''), 0, 150) ?: null,
                'metodo'     => substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 10),
                'url'        => $url,
                'pantalla'   => mb_substr($pantalla, 0, 100) ?: null,
                'referer'    => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500) ?: null,
                'ip'         => substr($ip, 0, 64) ?: null,
                'navegador'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300) ?: null,
            ];
            $pdo->prepare('INSERT INTO log_errores (' . implode(', ', array_keys($fila)) . ') VALUES ('
                          . implode(', ', array_fill(0, count($fila), '?')) . ')')
                ->execute(array_values($fila));
            $id = (int) $pdo->lastInsertId();
        }
        if (random_int(1, 500) === 1) {
            $pdo->exec('DELETE FROM log_errores WHERE ultima < NOW() - INTERVAL ' . LOGERR_RETENCION_DIAS . ' DAY LIMIT 5000');
        }
        if ($e !== null) {
            $GLOBALS['LOGERR_VISTAS'][spl_object_id($e)] = $id;
        }
        return $GLOBALS['LOGERR_ID'] = $id;
    } catch (Throwable $x) {
        error_log('[financiera] No se pudo anotar el error en log_errores: ' . get_class($x) . ': ' . $x->getMessage());
        return null;
    }
}

/** Un error que la pantalla atajó (error_amigable): se anota salvo que sea una regla de negocio. */
function log_error_aviso(Throwable $e): ?int
{
    return logerr_es_negocio($e) ? null : log_error_registrar('aviso', $e);
}

// ---------------------------------------------------------------------
// Los ganchos
// ---------------------------------------------------------------------

/** Una excepción que nadie atajó. */
function logerr_al_excepcion(Throwable $e): void
{
    error_log('[financiera] Sin atajar: ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $id = log_error_registrar('excepcion', $e, null, 500);
    logerr_pantalla_500($id, $e->getMessage());
}

/** Al terminar el pedido: los errores fatales y los 500 que no se anotaron. */
function logerr_al_terminar(): void
{
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $rel = logerr_rel($err['file']) ?? $err['file'];
        $id = log_error_registrar('excepcion', null, $err['message'], 500, [
            'tipo' => 'Error fatal', 'lugar' => $rel . ':' . $err['line'],
            'detalle' => 'Error fatal: ' . $err['message'] . "\n  en " . $rel . ':' . $err['line'] . "\n",
        ]);
        logerr_pantalla_500($id, $err['message']);
        return;
    }
    $codigo = http_response_code();
    if (is_int($codigo) && $codigo >= 500 && $GLOBALS['LOGERR_ID'] === null) {
        log_error_registrar('respuesta', null, 'La pantalla terminó con un error ' . $codigo . ' sin decir cuál.', $codigo);
    }
}

/** «La pantalla no se pudo abrir», con el código del error. JSON si el pedido era de la API. */
function logerr_pantalla_500(?int $id, string $mensaje): void
{
    $codigo = $id ? 'Código del error: #' . $id : '';
    $detalle = cfg('debug', false) ? $mensaje : '';
    $esApi = defined('FIN_API') || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    if (headers_sent()) {
        // La página ya empezó a dibujarse: el aviso va donde se cortó.
        echo '<div class="alert alert-danger" style="margin:16px 0">Ocurrió un error y la pantalla quedó incompleta.'
           . ($codigo !== '' ? ' <strong>' . h($codigo) . '</strong>' : '') . ($detalle !== '' ? '<br><small>' . h($detalle) . '</small>' : '') . '</div>';
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Cache-Control: no-store');
    if ($esApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Ocurrió un error inesperado.' . ($id ? ' (' . $codigo . ')' : ''),
                          'codigo' => $id], JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    $css = @filemtime(__DIR__ . '/../public/assets/app.css') ?: 0;
    echo '<!DOCTYPE html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>La pantalla no se pudo abrir</title><link rel="stylesheet" href="assets/app.css?v=' . $css . '"></head>'
       . '<body class="pagina-error"><div class="card error-500"><h1>La pantalla no se pudo abrir</h1>'
       . '<p>Ocurrió un error inesperado. Ya quedó anotado: si se repite, avisale al administrador'
       . ($id ? ' con el código.' : '.') . '</p>'
       . ($codigo !== '' ? '<p class="error-500-codigo">' . h($codigo) . '</p>' : '')
       . ($detalle !== '' ? '<pre class="error-traza">' . h($detalle) . '</pre>' : '')
       . '<p><a class="btn btn-primary" href="index.php">Volver al inicio</a> '
       . '<a class="btn btn-secondary" href="javascript:history.back()">Volver atrás</a></p></div></body></html>';
}

/** Engancha el registro. Una vez por pedido, desde bootstrap.php (solo en la web). */
function log_errores_instalar(): void
{
    set_exception_handler('logerr_al_excepcion');
    register_shutdown_function('logerr_al_terminar');
}
