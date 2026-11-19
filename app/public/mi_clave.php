<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// Cambiar la contraseña propia. La abre cualquier usuario con sesión,
// sin depender de una opción de menú.

$errores = [];
if (es_post()) {
    csrf_exigir();
    $actual = (string) ($_POST['actual'] ?? '');
    $nueva  = (string) ($_POST['nueva'] ?? '');
    $nueva2 = (string) ($_POST['nueva2'] ?? '');
    $u = q1('SELECT password_hash FROM usuarios WHERE id_usuario = ?', [usuario_id()]);
    if (!$u || !password_verify($actual, (string) $u['password_hash'])) {
        $errores[] = 'La contraseña actual no es correcta.';
    }
    if (strlen($nueva) < CLAVE_MIN) {
        $errores[] = 'La nueva contraseña tiene que tener al menos ' . CLAVE_MIN . ' caracteres.';
    } elseif ($nueva !== $nueva2) {
        $errores[] = 'Las dos contraseñas nuevas no coinciden.';
    } elseif ($nueva === $actual) {
        $errores[] = 'La nueva contraseña es igual a la actual.';
    }
    if (!$errores) {
        q('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?', [password_hash($nueva, PASSWORD_DEFAULT), usuario_id()]);
        flash('ok', 'Tu contraseña quedó cambiada.');
        redirigir('mi_clave.php');
    }
}

layout_inicio('Mi contraseña', '');
titulo('Mi contraseña', usuario_actual()['nombre'] . ' · ' . usuario_actual()['email']);
mensajes($errores);
?>
<form method="post" action="mi_clave.php" autocomplete="off">
  <?= csrf_campo() ?>
  <div class="card" style="max-width:720px">
    <?= card_head('Cambiar la contraseña') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:12"><label for="actual">Contraseña actual</label>
          <input id="actual" name="actual" type="password" autocomplete="current-password" required></div>
        <div class="campo" style="--span:6"><label for="nueva">Nueva contraseña</label>
          <input id="nueva" name="nueva" type="password" autocomplete="new-password" minlength="<?= CLAVE_MIN ?>" required>
          <small class="campo-ayuda">Al menos <?= CLAVE_MIN ?> caracteres.</small></div>
        <div class="campo" style="--span:6"><label for="nueva2">Repetir la nueva</label>
          <input id="nueva2" name="nueva2" type="password" autocomplete="new-password" minlength="<?= CLAVE_MIN ?>" required></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Cambiar contraseña', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php
layout_fin();
