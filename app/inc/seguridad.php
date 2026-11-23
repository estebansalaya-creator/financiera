<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Sesión, login y permisos. El mismo esquema que gestion_sindicato
// (db.py: validasesion, puede, roles_y_menu, y app.py: _permisos_al_dia):
//
//   - Los permisos de una persona son la UNIÓN de las opciones de sus
//     roles (menu_roles) y de sus opciones sueltas (menu_usuarios).
//   - EL PERMISO ES LA OPCIÓN DE MENÚ: una pantalla se abre si el usuario
//     tiene la opción con esa URL. No hay otra tabla de permisos.
//   - Las pantallas que se abren desde otra (la ficha desde el listado)
//     toman el permiso de su listado: PAGINAS_PERMISO.
//   - Roles y opciones se vuelven a leer cada minuto: un cambio de
//     permisos le llega a quien ya está adentro sin salir y entrar.
//
// bootstrap.php llama a seguridad_iniciar() en cada pedido web. Desde la
// línea de comandos (tareas programadas, scripts) no hay sesión.
// ---------------------------------------------------------------------

const ROL_ADMIN = 'Administrador';

/** Sin estas tres nadie podría volver a dar permisos ni arreglar el menú. */
const MENU_PROTEGIDO = ['admin_usuarios.php', 'admin_roles.php', 'admin_menu.php'];

/** Se abren sin sesión. */
const PAGINAS_PUBLICAS = ['login.php', 'logout.php', 'logo.php', 'manifest.php', 'icono.php'];

/** Con sesión alcanza: no dependen de una opción de menú. */
const PAGINAS_SIN_PERMISO = ['index.php', 'mi_clave.php', 'ayuda.php'];

/** Pantalla => opciones de menú que la habilitan (cualquiera alcanza). */
const PAGINAS_PERMISO = [
    'cliente.php'   => ['clientes.php'],
    'arca.php'      => ['clientes.php'],      // «Consultar ARCA» de la ficha del cliente
    'php_diag.php'  => ['admin_usuarios.php'], // diagnóstico de PHP (php.ini y extensiones)
    // Tesorería › Bancos es la solapa de bancos de Cuentas de fondos.
    'cuentas_fondos.php' => ['bancos.php'],
    'solicitud.php' => ['solicitudes.php', 'autorizaciones.php'],
    'documento.php' => ['clientes.php'],
    'prestamo.php'  => ['prestamos.php', 'cobrar.php', 'cobranzas.php', 'emision_recibos.php'],
    'recibo.php'    => ['cobranzas.php', 'cobrar.php', 'emision_recibos.php'],
    'inversion.php' => ['inversiones.php'],
    'inversor.php'  => ['inversores.php', 'inversiones.php'],
    // La dirección vieja de los tipos de asociado: solo redirige.
    'productos.php' => ['tipos_asociado.php'],
    // Mensajes: el chat con «Mensajes»; el hilo de una solicitud, con lo que abre la solicitud.
    // Cada acción vuelve a controlar lo suyo (inc/chat.php).
    'chat_api.php'     => ['chat.php', 'solicitudes.php', 'autorizaciones.php'],
    'chat_adjunto.php' => ['chat.php', 'solicitudes.php', 'autorizaciones.php'],
];

const PERMISOS_VIGENCIA = 60;     // segundos entre relecturas de roles y opciones
const LOGIN_INTENTOS    = 5;      // intentos fallidos seguidos antes de bloquear
const LOGIN_BLOQUEO_MIN = 15;     // minutos de bloqueo
const CLAVE_MIN         = 8;

// ---------------------------------------------------------------------
// Sesión
// ---------------------------------------------------------------------

function sesion_abrir(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = (string) cfg('sesion_dir', '');
    if ($dir !== '' && is_dir($dir)) {
        session_save_path($dir);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('fin_sesion');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                               'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

/** Cierra la escritura de la sesión: así dos pestañas no se esperan una a la otra. */
function sesion_soltar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function validasesion(): bool
{
    return !empty($_SESSION['fin_uid']);
}

/** id, nombre y email del usuario logueado, o null. */
function usuario_actual(): ?array
{
    if (!validasesion()) {
        return null;
    }
    return ['id' => (int) $_SESSION['fin_uid'], 'nombre' => (string) $_SESSION['fin_nombre'],
            'email' => (string) $_SESSION['fin_email'], 'roles' => $_SESSION['fin_roles'] ?? []];
}

/** El id para creado_por, anulado_por, p_id_usuario… (null fuera de una sesión). */
function usuario_id(): ?int
{
    return validasesion() ? (int) $_SESSION['fin_uid'] : null;
}

function tiene_rol(string $rol): bool
{
    return in_array($rol, $_SESSION['fin_roles'] ?? [], true);
}

// ---------------------------------------------------------------------
// Roles y opciones
// ---------------------------------------------------------------------

/** [roles (nombres), ids de menú permitidos] del usuario. */
function roles_y_menu(int $idUsuario): array
{
    $roles = array_column(q('SELECT r.nombre FROM usuarios_roles ur
                               JOIN roles r ON r.id_rol = ur.id_rol AND r.activo = 1
                              WHERE ur.id_usuario = ? ORDER BY r.prioridad DESC, r.nombre', [$idUsuario]), 'nombre');
    $ids = array_map('intval', array_column(q(
        'SELECT m.id_menu FROM menu m
          WHERE m.activo = 1
            AND (m.id_menu IN (SELECT mr.id_menu FROM menu_roles mr
                                 JOIN usuarios_roles ur ON ur.id_rol = mr.id_rol
                                 JOIN roles r ON r.id_rol = ur.id_rol AND r.activo = 1
                                WHERE ur.id_usuario = ?)
                 OR m.id_menu IN (SELECT mu.id_menu FROM menu_usuarios mu WHERE mu.id_usuario = ?))',
        [$idUsuario, $idUsuario]), 'id_menu'));
    return [$roles, $ids];
}

/** Vuelve a leer roles y opciones de la sesión (después de cambiar permisos, o cada minuto). */
function sesion_refrescar(): void
{
    $uid = usuario_id();
    if (!$uid) {
        return;
    }
    $u = q1('SELECT nombre, email, activo FROM usuarios WHERE id_usuario = ?', [$uid]);
    sesion_abrir();
    if (!$u || !(int) $u['activo']) {
        // Lo dieron de baja mientras estaba adentro: afuera en el próximo pedido.
        $_SESSION = [];
        return;
    }
    [$roles, $ids] = roles_y_menu($uid);
    $_SESSION['fin_nombre']      = $u['nombre'];
    $_SESSION['fin_email']       = $u['email'];
    $_SESSION['fin_roles']       = $roles;
    $_SESSION['fin_menu_ids']    = $ids;
    $_SESSION['fin_permisos_ts'] = time();
    $GLOBALS['__menu_items'] = null;
}

/** Las opciones activas que el usuario tiene, en el orden del árbol (una consulta por pedido). */
function menu_items(): array
{
    if (isset($GLOBALS['__menu_items'])) {
        return $GLOBALS['__menu_items'];
    }
    $ids = $_SESSION['fin_menu_ids'] ?? [];
    if (!validasesion() || !$ids) {
        return $GLOBALS['__menu_items'] = [];
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    return $GLOBALS['__menu_items'] = q(
        "SELECT id_menu, padre_id, nombre, url, icono, icono_svg, orden, nivel FROM menu
          WHERE activo = 1 AND id_menu IN ($marcas) ORDER BY orden, nombre", array_values($ids));
}

/** Si el usuario tiene la opción de menú con esa URL. */
function puede(string $url): bool
{
    foreach (menu_items() as $m) {
        if ($m['url'] !== null && strcasecmp($m['url'], $url) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * La lista plana en árbol para el menú lateral. Una opción cuya rama no
 * está permitida se descarta, no se sube a la raíz: un hijo suelto se vería
 * como un módulo entero (app._armar_arbol de gestion_sindicato).
 */
function menu_arbol(): array
{
    $hijos = [];
    foreach (menu_items() as $i) {
        $hijos[$i['padre_id'] === null ? 0 : (int) $i['padre_id']][] = $i;
    }
    $armar = function (int $padre) use (&$armar, $hijos): array {
        $out = [];
        foreach ($hijos[$padre] ?? [] as $i) {
            $i['hijos'] = $armar((int) $i['id_menu']);
            $out[] = $i;
        }
        return $out;
    };
    return $armar(0);
}

/** La primera pantalla del menú del usuario, en el orden del árbol. */
function primera_pantalla(): ?string
{
    $buscar = function (array $nodos) use (&$buscar) {
        foreach ($nodos as $n) {
            if ($n['url'] !== null) {
                return $n['url'];
            }
            $u = $buscar($n['hijos']);
            if ($u !== null) {
                return $u;
            }
        }
        return null;
    };
    return $buscar(menu_arbol());
}

function pagina_actual(): string
{
    return strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
}

/** La opción de menú que corresponde a la pantalla actual (para marcarla en el menú). */
function menu_url_activa(): ?string
{
    $p = pagina_actual();
    if (puede($p)) {
        return $p;
    }
    foreach (PAGINAS_PERMISO[$p] ?? [] as $u) {
        if (puede($u)) {
            return $u;
        }
    }
    return null;
}

/**
 * Pedidos de la API (chat_api.php define FIN_API): sin sesión, vencida o sin
 * permiso se contesta JSON con el código, en vez de redirigir a una pantalla.
 */
function seguridad_cortar_api(int $http, string $mensaje): void
{
    if (!defined('FIN_API')) {
        return;
    }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode(['ok' => false, 'error' => $mensaje, 'sesion' => $http === 401 ? false : null], JSON_UNESCAPED_UNICODE));
}

/** Una URL de esta aplicación a la que se puede volver después del login. */
function url_local(string $u): bool
{
    return (bool) preg_match('/^[a-z0-9_]+\.php(\?[^\s<>"\']*)?$/i', $u);
}

// ---------------------------------------------------------------------
// Guardia de cada pedido
// ---------------------------------------------------------------------

function seguridad_iniciar(): void
{
    sesion_abrir();
    $p = pagina_actual();
    if (in_array($p, PAGINAS_PUBLICAS, true)) {
        return;                      // login y logout manejan la sesión ellos mismos
    }

    if (!validasesion()) {
        seguridad_cortar_api(401, 'La sesión se cerró. Volvé a ingresar.');
        $volver = basename((string) ($_SERVER['REQUEST_URI'] ?? ''));
        redirigir('login.php' . ($volver !== '' && url_local($volver) && $volver !== 'index.php' ? '?volver=' . rawurlencode($volver) : ''));
    }

    // Inactividad. Las consultas automáticas (FIN_SIN_ACTIVIDAD: el contador
    // de mensajes, los mensajes nuevos) no cuentan como uso: si no, una
    // pestaña abierta mantendría la sesión viva para siempre.
    $minutos = (int) cfg('sesion_minutos', 480);
    if ($minutos > 0 && time() - (int) ($_SESSION['fin_ultimo'] ?? 0) > $minutos * 60) {
        $_SESSION = [];
        session_destroy();
        seguridad_cortar_api(401, 'La sesión se cerró por inactividad. Volvé a ingresar.');
        flash('info', 'La sesión se cerró por inactividad. Volvé a ingresar.');
        redirigir('login.php');
    }
    if (!defined('FIN_SIN_ACTIVIDAD')) {
        $_SESSION['fin_ultimo'] = time();
    }

    try {
        if (time() - (int) ($_SESSION['fin_permisos_ts'] ?? 0) >= PERMISOS_VIGENCIA) {
            sesion_refrescar();
            if (!validasesion()) {
                session_destroy();
                seguridad_cortar_api(401, 'Tu usuario fue dado de baja.');
                flash('warning', 'Tu usuario fue dado de baja.');
                redirigir('login.php');
            }
        }
        $permitida = in_array($p, PAGINAS_SIN_PERMISO, true) || puede($p);
        foreach (PAGINAS_PERMISO[$p] ?? [] as $u) {
            $permitida = $permitida || puede($u);
        }
    } catch (PDOException $e) {
        sesion_soltar();
        error_seguridad($e);
    }
    sesion_soltar();

    if (!$permitida) {
        seguridad_cortar_api(403, 'No tenés permiso para esto.');
        $destino = primera_pantalla();
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $destino !== null && $destino !== $p) {
            flash('warning', 'No tenés permiso para esa pantalla. Si la necesitás, pedíselo a un administrador.');
            redirigir($destino);
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('No tenés permiso para esta pantalla.');
    }
}

/** La base no tiene las tablas de seguridad, o no responde. */
function error_seguridad(Throwable $e): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146) {
        exit("Faltan las tablas de usuarios y permisos.\nCorré sql\\financiera_seguridad.sql y creá el primer usuario con\n  php app\\crear_usuario.php tu@correo \"Tu nombre\"");
    }
    exit('Error al acceder a la base de datos: ' . error_amigable($e));
}

// ---------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------

/**
 * Valida correo (o usuario) y contraseña. Devuelve null si entró, o el
 * mensaje a mostrar. Un solo mensaje para usuario inexistente, inactivo y
 * contraseña equivocada: distinguirlos le confirma a cualquiera qué
 * correos están dados de alta.
 */
function login_intentar(string $ident, string $clave): ?string
{
    $ident = strtolower(trim($ident));
    $u = q1('SELECT id_usuario, nombre, email, password_hash, activo, intentos_fallidos, bloqueado_hasta,
                    (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado,
                    CEIL(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) / 60) AS faltan
               FROM usuarios WHERE LOWER(email) = ? OR LOWER(usuario) = ? LIMIT 1', [$ident, $ident]);

    if ($u && (int) $u['bloqueado']) {
        return 'Demasiados intentos fallidos. Probá de nuevo en ' . max(1, (int) $u['faltan']) . ' minutos.';
    }
    // Con usuario inexistente igual se verifica algo: así no se distingue por el tiempo de respuesta.
    $ok = password_verify($clave, (string) ($u['password_hash'] ?? password_hash('-', PASSWORD_DEFAULT)));

    if (!$u || !$ok || !(int) $u['activo'] || $u['password_hash'] === null) {
        if ($u && $u['password_hash'] !== null) {
            $n = (int) $u['intentos_fallidos'] + 1;
            // La hora del bloqueo la pone MySQL: se compara con su NOW(), no con el reloj de PHP.
            q('UPDATE usuarios SET intentos_fallidos = ?,
                      bloqueado_hasta = IF(?, NOW() + INTERVAL ' . LOGIN_BLOQUEO_MIN . ' MINUTE, NULL)
                WHERE id_usuario = ?',
              [$n >= LOGIN_INTENTOS ? 0 : $n, $n >= LOGIN_INTENTOS ? 1 : 0, (int) $u['id_usuario']]);
            if ($n >= LOGIN_INTENTOS) {
                return 'Demasiados intentos fallidos. Probá de nuevo en ' . LOGIN_BLOQUEO_MIN . ' minutos.';
            }
        }
        return 'Correo o contraseña incorrectos.';
    }

    if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?', [password_hash($clave, PASSWORD_DEFAULT), (int) $u['id_usuario']]);
    }
    q('UPDATE usuarios SET ultimo_acceso = NOW(), intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?',
      [(int) $u['id_usuario']]);

    sesion_abrir();
    session_regenerate_id(true);
    $_SESSION = ['fin_uid' => (int) $u['id_usuario'], 'fin_nombre' => $u['nombre'], 'fin_email' => $u['email'],
                 'fin_ultimo' => time()];
    sesion_refrescar();
    return null;
}

function logout(): void
{
    sesion_abrir();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}
