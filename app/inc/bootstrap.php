<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Configuración y conexión
// ---------------------------------------------------------------------

// En el servidor propio (IIS) la configuración está en config.php. En Railway
// no hay config.php (no va al repositorio): sale de las variables FIN_*.
$cfgFile = __DIR__ . '/../config.php';
if (is_file($cfgFile)) {
    $GLOBALS['CFG'] = require $cfgFile;
} elseif (getenv('FIN_DB_USER') !== false) {
    $env = function (string $k, $default = null) {
        $v = getenv($k);
        return $v === false || $v === '' ? $default : $v;
    };
    $GLOBALS['CFG'] = [
        'db_host' => $env('FIN_DB_HOST', '127.0.0.1'),
        'db_port' => (int) $env('FIN_DB_PORT', 3306),
        'db_name' => $env('FIN_DB_NAME', 'financiera'),
        'db_user' => $env('FIN_DB_USER'),
        'db_pass' => $env('FIN_DB_PASS', ''),
        'docs_dir' => $env('FIN_DOCS_DIR', '/data/documentos'),
        'docs_max_mb' => (int) $env('FIN_DOCS_MAX_MB', 10),
        'bcra_cache_horas' => (int) $env('FIN_BCRA_CACHE_HORAS', 24),
        'bcra_cainfo' => $env('FIN_BCRA_CAINFO', ''),
        'sesion_minutos' => (int) $env('FIN_SESION_MINUTOS', 480),
        'sesion_dir' => $env('FIN_SESION_DIR', '/data/sesiones'),
        'debug' => $env('FIN_DEBUG', '0') === '1',
        'whatsapp_token' => $env('FIN_WA_TOKEN', ''),
    ];
    unset($env);
} else {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Falta config.php (o las variables FIN_DB_* en Railway).\nCopiá config.example.php como config.php y completá los datos de MySQL.");
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

function cfg(string $clave, $default = null)
{
    return $GLOBALS['CFG'][$clave] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            cfg('db_host', '127.0.0.1'),
            (int) cfg('db_port', 3306),
            cfg('db_name', 'financiera')
        );
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // La base trabaja en hora argentina, igual que PHP: en Railway el servidor
            // está en UTC y NOW() / CURDATE() darían tres horas (o un día) de más.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'",
        ]);
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve todas las filas. */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Primera fila o null. */
function q1(string $sql, array $params = []): ?array
{
    $filas = q($sql, $params);
    return $filas[0] ?? null;
}

// ---------------------------------------------------------------------
// Formato (es-AR)
// ---------------------------------------------------------------------

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return '$ ' . number_format((float) $n, 2, ',', '.');
}

function num($n, int $dec = 0): string
{
    return number_format((float) $n, $dec, ',', '.');
}

/** 0.18 -> "18,00 %" */
function pct($fraccion, int $dec = 2): string
{
    return number_format((float) $fraccion * 100, $dec, ',', '.') . ' %';
}

function fecha(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : '';
}

/** "2026-06" -> "Junio 2026" */
function periodo_txt(string $p): string
{
    static $meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
                     'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) {
        return $p;
    }
    return ($meses[(int) $m[2]] ?? $m[2]) . ' ' . $m[1];
}

/** Parámetro GET como texto ('' si falta o viene como array). */
function get_str(string $clave): string
{
    $v = $_GET[$clave] ?? '';
    return is_string($v) ? $v : '';
}

/** URL a la página actual cambiando o quitando parámetros (null = quitar). */
function url_con(array $cambios): string
{
    $params = array_merge($_GET, $cambios);
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $base . ($params ? '?' . http_build_query($params) : '');
}

// ---------------------------------------------------------------------
// Errores
// ---------------------------------------------------------------------

/** Mensaje de error para mostrar; el detalle técnico solo con debug = true. */
function error_amigable(Throwable $e): string
{
    error_log('[financiera] ' . $e->getMessage());
    if (cfg('debug', false)) {
        return $e->getMessage();
    }
    if ($e instanceof PDOException) {
        return 'No se pudo consultar la base de datos. Revisá la conexión en config.php.';
    }
    return 'Ocurrió un error inesperado.';
}

/**
 * Mensaje para la persona a partir de un error de la base:
 *   - SIGNAL '45000' de un procedure: su texto tal cual («No hay tasa vigente…»).
 *   - Clave duplicada (1062): el texto de $duplicados según el nombre del índice.
 *   - Clave foránea (1451/1452): que está en uso o que falta el dato relacionado.
 */
function mensaje_bd(Throwable $e, array $duplicados = []): string
{
    if ($e instanceof PDOException) {
        $m = $e->getMessage();
        if (($e->errorInfo[0] ?? '') === '45000' || preg_match('/\b1644\b/', $m)) {
            return preg_replace('/^.*?1644\s*/s', '', $m);
        }
        $cod = (int) ($e->errorInfo[1] ?? 0);
        if ($cod === 1062) {
            foreach ($duplicados as $indice => $texto) {
                if (strpos($m, $indice) !== false) {
                    return $texto;
                }
            }
            return 'Ya existe un registro con esos datos.';
        }
        if ($cod === 1451) {
            return 'No se puede borrar: está en uso.';
        }
        if ($cod === 1452) {
            return 'Falta un dato relacionado (por ejemplo, el vendedor o el producto elegido ya no existe).';
        }
    }
    return error_amigable($e);
}

// ---------------------------------------------------------------------
// Formularios
// ---------------------------------------------------------------------

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Parámetro POST como texto recortado ('' si falta o viene como array). */
function post_str(string $clave): string
{
    $v = $_POST[$clave] ?? '';
    return is_string($v) ? trim($v) : '';
}

/**
 * Número escrito a la argentina o con punto decimal: «1.234,56», «1234,56»,
 * «1234.56», «$ 1.000», «85 %». null si está vacío o no es un número.
 */
function leer_num(string $s): ?float
{
    $s = trim(str_replace(['$', '%', ' ', "\u{00A0}"], '', $s));
    if ($s === '') {
        return null;
    }
    if (strpos($s, ',') !== false) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    } elseif (substr_count($s, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $s)) {
        $s = str_replace('.', '', $s);        // «1.234.567» o «1.234»: separador de miles
    }
    return is_numeric($s) ? (float) $s : null;
}

/** 'AAAA-MM-DD' válida o null. */
function leer_fecha(string $s): ?string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $s;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

function redirigir(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

// CSRF sin sesión (doble envío): una cookie al azar que el formulario repite
// en un campo oculto. Otro sitio puede hacer que el navegador mande la cookie,
// pero no puede leerla para ponerla en el formulario. SameSite=Strict además.
function csrf_token(): string
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $c = $_COOKIE['fin_csrf'] ?? '';
    if (!is_string($c) || !preg_match('/^[a-f0-9]{32}$/', $c)) {
        $c = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('fin_csrf', $c, ['path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        }
        $_COOKIE['fin_csrf'] = $c;
    }
    return $t = $c;
}

function csrf_campo(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** Corta el pedido si el POST no trae el token correcto. */
function csrf_exigir(): void
{
    $f = $_POST['_csrf'] ?? '';
    $c = $_COOKIE['fin_csrf'] ?? '';
    if (!is_string($f) || !is_string($c) || $c === '' || !hash_equals($c, $f)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('El formulario venció o no vino de esta aplicación. Volvé a la pantalla y probá de nuevo.');
    }
}

// Mensaje para después de un redirect (guardado, borrado…): viaja en una
// cookie que se lee y se borra en el pedido siguiente.
function flash(string $tipo, string $texto): void
{
    setcookie('fin_flash', base64_encode(json_encode([$tipo, $texto])), ['path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}

$GLOBALS['FLASH'] = null;
if (isset($_COOKIE['fin_flash']) && is_string($_COOKIE['fin_flash'])) {
    $d = json_decode((string) base64_decode($_COOKIE['fin_flash'], true), true);
    if (is_array($d) && count($d) === 2 && in_array($d[0], ['ok', 'danger', 'warning', 'info'], true)) {
        $GLOBALS['FLASH'] = [$d[0], (string) $d[1]];
    }
    setcookie('fin_flash', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}
csrf_token();

// Sesión y permisos: cada pantalla web pasa por la guardia antes de hacer
// nada (login, inactividad, opción de menú). Los scripts de consola no.
require_once __DIR__ . '/seguridad.php';
if (PHP_SAPI !== 'cli') {
    seguridad_iniciar();
}
