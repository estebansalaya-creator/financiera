<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/ayuda.php';
require_once __DIR__ . '/pwa.php';
require_once __DIR__ . '/empresa.php';

// ---------------------------------------------------------------------
// Marco de todas las pantallas: encabezado y menú lateral en árbol, como
// gestion_sindicato (templates/base.html). El menú sale de la base (menu,
// menu_roles, menu_usuarios) y muestra solo lo que el usuario puede abrir.
// La opción de la pantalla actual se marca acá, del lado del servidor, con
// sus ramas abiertas. app.js recuerda qué otras ramas dejó abiertas.
// ---------------------------------------------------------------------

/** Una lista del árbol del menú (recursiva, hasta los 4 niveles). */
function menu_html(array $nodos, ?string $activa, array &$abiertas, int $nivel = 1): string
{
    $out = '';
    foreach ($nodos as $n) {
        $ico = icono_menu($n, [1 => 18, 2 => 16][$nivel] ?? 15);
        $id  = (int) $n['id_menu'];
        if ($n['url'] !== null) {
            $act = $activa !== null && strcasecmp($n['url'], $activa) === 0;
            $out .= '<li><a href="' . h($n['url']) . '" class="mn-hoja' . ($act ? ' active' : '') . '">'
                  . $ico . '<span>' . h($n['nombre']) . '</span></a></li>';
            continue;
        }
        if (!$n['hijos']) {
            continue;                      // una rama sin nada adentro no se dibuja
        }
        $sub = menu_html($n['hijos'], $activa, $abiertas, $nivel + 1);
        $abierta = in_array($id, $abiertas, true) || strpos($sub, 'class="mn-hoja active"') !== false;
        if ($abierta && !in_array($id, $abiertas, true)) {
            $abiertas[] = $id;
        }
        $out .= '<li><a href="#" class="rama' . ($abierta ? ' abierta' : '') . '" data-menu-id="' . $id . '" aria-expanded="' . ($abierta ? 'true' : 'false') . '">'
              . '<span class="rama-nombre">' . $ico . h($n['nombre']) . '</span>'
              . '<svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></a>'
              . '<ul class="submenu" data-menu-id="' . $id . '"' . ($abierta ? ' style="display:block"' : '') . '>' . $sub . '</ul></li>';
    }
    return $out;
}

/** El cuadrado de la marca: el logo de la empresa, o el ícono si no hay. */
function marca_logo(int $tam): string
{
    $url = empresa_logo_url();
    return $url
        ? '<span class="header-logo con-logo"><img src="' . h($url) . '" alt=""></span>'
        : '<span class="header-logo">' . icono('dinero', $tam) . '</span>';
}

/**
 * El globito flotante de Mensajes (arriba del de Ayuda), con cuántas
 * conversaciones tienen algo sin leer. assets/chat.js lo actualiza cada 20
 * segundos. Sin la opción Mensajes, sin las tablas todavía o en la misma
 * pantalla Mensajes, no se dibuja.
 */
function chat_boton_flotante(): string
{
    if (!usuario_id() || !puede('chat.php') || pagina_actual() === 'chat.php') {
        return '';
    }
    try {
        $r = q1("SELECT COUNT(*) AS n, COALESCE(MAX(c.ultimo_mensaje_id), 0) AS ultimo FROM chat_participantes p
                   JOIN chat_conversaciones c ON c.id_conversacion = p.id_conversacion
                  WHERE p.id_usuario = ? AND p.silenciada = 0 AND c.ultimo_mensaje_id > p.ultimo_leido", [usuario_id()]);
        $n = (int) $r['n'];
        $ultimo = (int) $r['ultimo'];
    } catch (Throwable $e) {
        return '';
    }
    // Mensajes vuelve a esta pantalla al cerrarlo.
    $aca = basename((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $href = 'chat.php' . ($aca !== '' && url_local($aca) && strpos($aca, 'index.php') !== 0 ? '?volver=' . rawurlencode($aca) : '');
    return '<a id="btn-mensajes" href="' . h($href) . '" data-chat-contador="' . $n . '" data-chat-ultimo="' . $ultimo . '" title="Mensajes">'
         . icono('mensaje') . '<span>Mensajes</span>'
         . '<span class="chat-badge"' . ($n ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</span></a>';
}

/** $activo queda por compatibilidad: la opción activa sale de la pantalla actual. */
function layout_inicio(string $titulo, string $activo = ''): void
{
    static $dias  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    static $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                     'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $hoy = $dias[(int) date('w')] . ' ' . date('j') . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y');
    $u = usuario_actual();
    $menuError = null;
    $menu = '';
    if ($u) {
        try {
            $abiertas = [];
            $menu = menu_html(menu_arbol(), menu_url_activa(), $abiertas);
        } catch (Throwable $e) {
            // Un menú vacío se ve igual si no hay permisos que si la base no
            // respondió: el error se dice (base.html de gestion_sindicato).
            $menuError = error_amigable($e);
        }
    }
    ?><!DOCTYPE html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · <?= h(empresa_nombre()) ?></title>
<?php pwa_head(); ?>
<meta name="fin-csrf" content="<?= h(csrf_token()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../public/assets/app.css') ?: 0 ?>">
</head>
<body>
<header class="app-header">
  <?php if ($menu !== ''): ?>
  <button type="button" class="header-menu" id="btn-menu" aria-label="Abrir el menú" aria-controls="app-sidebar" aria-expanded="false"><?= icono('menu', 22) ?></button>
  <?php endif; ?>
  <a class="header-brand" href="index.php">
    <?= marca_logo(20) ?>
    <span><strong><?= h(empresa_nombre()) ?></strong><small>Backoffice de préstamos</small></span>
  </a>
  <?php if ($u): ?>
  <a href="index.php" class="header-boton" title="Volver al inicio" data-colapsar-menu><?= icono('casa', 14) ?><span class="hb-texto">Inicio</span></a>
  <?php endif; ?>
  <div class="header-spacer"></div>
  <button type="button" class="header-boton" data-instalar-app hidden title="Instalar la Financiera como app en este dispositivo"><?= icono('telefono', 14) ?><span class="hb-texto">Instalar app</span></button>
  <span class="header-fecha"><?= h(ucfirst($hoy)) ?></span>
  <?php if ($u): ?>
  <a href="mi_clave.php" class="header-user" title="<?= h($u['email'] . ($u['roles'] ? ' · ' . implode(', ', $u['roles']) : '')) ?> · Mis datos y contraseña">
    <?= icono('usuario', 16) ?><span class="hb-texto"><?= h($u['nombre']) ?></span>
  </a>
  <a href="logout.php" class="header-boton" title="Salir"><?= icono('salir', 14) ?><span class="hb-texto">Salir</span></a>
  <?php endif; ?>
</header>
<div class="app-shell">
  <?php if ($menuError): ?>
  <div class="menu-error">No se pudo cargar el menú — <?= h($menuError) ?></div>
  <?php elseif ($menu !== ''): ?>
  <div class="menu-fondo" id="menu-fondo" hidden></div>
  <aside class="app-sidebar" id="app-sidebar">
    <a href="index.php" class="sidebar-home" data-colapsar-menu><?= icono('casa', 13) ?>Inicio</a>
    <div class="menu-buscar">
      <?= icono('buscar', 13) ?>
      <input type="search" id="menu-buscar" placeholder="Buscar opción del menú…" autocomplete="off" spellcheck="false"
             aria-label="Buscar una opción del menú" aria-controls="sidebar-nav">
    </div>
    <div class="menu-buscar-vacio" id="menu-buscar-vacio" hidden>Ninguna opción coincide.</div>
    <div class="sidebar-label">Navegación</div>
    <ul class="sidebar-nav" id="sidebar-nav"><?= $menu ?></ul>
  </aside>
  <?php endif; ?>
<main class="app-main">
<?php
}

function layout_fin(): void
{
    ?>
</main>
</div>
<?= chat_boton_flotante() ?>
<?php if (usuario_id() && pagina_actual() !== 'ayuda.php'): ?>
<a id="btn-ayuda" href="<?= h(url_ayuda()) ?>" title="Ayuda de esta pantalla (F1)" aria-keyshortcuts="F1"><?= icono('info') ?><span>Ayuda</span></a>
<?php endif; ?>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../public/assets/app.js') ?: 0 ?>"></script>
<script src="assets/tabla.js"></script>
<?php if (usuario_id()): ?>
<script src="assets/chat.js?v=<?= @filemtime(__DIR__ . '/../public/assets/chat.js') ?: 0 ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
}

function alerta(string $mensaje, string $tipo = 'danger'): void
{
    $ico = ['danger' => 'error', 'ok' => 'ok', 'warning' => 'alerta'][$tipo] ?? 'info';
    echo '<div class="alert alert-' . h($tipo) . '">' . icono($ico, 16) . '<div>' . h($mensaje) . '</div></div>';
}
