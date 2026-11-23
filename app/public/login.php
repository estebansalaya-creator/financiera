<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/pwa.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Ingreso: correo (o nombre de usuario) y contraseña contra la tabla
// usuarios. Después de 5 intentos fallidos seguidos, el usuario queda
// bloqueado 15 minutos.
// ---------------------------------------------------------------------

$volver = get_str('volver');
$volver = url_local($volver) ? $volver : 'index.php';

if (validasesion()) {
    redirigir($volver);
}

$error = null;
$ident = '';
if (es_post()) {
    csrf_exigir();
    $ident = post_str('ident');
    $clave = (string) ($_POST['clave'] ?? '');
    if ($ident === '' || $clave === '') {
        $error = 'Ingresá tu correo y tu contraseña.';
    } else {
        try {
            $error = login_intentar($ident, $clave);
            if ($error === null) {
                redirigir($volver);
            }
        } catch (PDOException $e) {
            $error = (int) ($e->errorInfo[1] ?? 0) === 1146
                ? 'Faltan las tablas de usuarios: corré sql\financiera_seguridad.sql.'
                : 'Error al acceder a la base de datos: ' . error_amigable($e);
        }
    }
    http_response_code(401);
}

$sinUsuarios = false;
try {
    $sinUsuarios = !q1('SELECT 1 AS x FROM usuarios LIMIT 1');
} catch (Throwable $e) {
}
?><!DOCTYPE html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · <?= h(empresa_nombre()) ?></title>
<?php pwa_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,600;9..40,700&family=DM+Mono:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-login">
<main class="login-caja">
  <div class="login-marca">
    <?= marca_logo(22) ?>
    <div><strong><?= h(empresa_nombre()) ?></strong><small>Backoffice de préstamos</small></div>
  </div>
  <div class="card">
    <form method="post" action="login.php<?= $volver !== 'index.php' ? '?volver=' . h(rawurlencode($volver)) : '' ?>" class="login-form">
      <?= csrf_campo() ?>
      <h1>Ingresar</h1>
      <?php mensajes($error ? [$error] : []); ?>
      <?php if ($sinUsuarios): ?>
        <div class="alert alert-info"><?= icono('info', 16) ?><div>Todavía no hay usuarios. Creá el primero en el servidor con
          <code>php D:\financiera\app\crear_usuario.php tu@correo "Tu nombre"</code></div></div>
      <?php endif; ?>
      <div class="campo">
        <label for="ident">Correo o usuario</label>
        <input id="ident" name="ident" value="<?= h($ident) ?>" autocomplete="username" required autofocus maxlength="150">
      </div>
      <div class="campo">
        <label for="clave">Contraseña</label>
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>
      </div>
      <?= boton('Ingresar', 'primary', null, null, true) ?>
    </form>
  </div>
  <button type="button" class="login-instalar" data-instalar-app hidden><?= icono('telefono', 15) ?>Instalar la app en este celular</button>
  <p class="login-pie">¿Te olvidaste la contraseña? Pedile a un administrador que te cargue una nueva.</p>
</main>
<script>try { sessionStorage.removeItem('fin_pantallas'); } catch (e) {}</script>
</body>
</html>
