<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// Mis datos: el celular propio (para que lo llamen o le escriban por
// WhatsApp desde Mensajes) y cambiar la contraseña. La abre cualquier
// usuario con sesión, sin depender de una opción de menú.

$errores = [];
$conCelular = columna_existe('usuarios', 'celular');
$celular = '';
if ($conCelular) {
    $celular = tel_fmt((string) (q1('SELECT celular FROM usuarios WHERE id_usuario = ?', [usuario_id()])['celular'] ?? ''));
}

if (es_post()) {
    csrf_exigir();
    if (post_str('accion') === 'celular' && $conCelular) {
        $celular = post_str('celular');
        $n = $celular === '' ? null : tel_normalizar($celular, true);
        if ($celular !== '' && $n === null) {
            $errores[] = tel_error('Celular');
        } else {
            q('UPDATE usuarios SET celular = ? WHERE id_usuario = ?', [$n, usuario_id()]);
            flash('ok', $n ? 'Tu celular quedó guardado: ' . tel_fmt($n) . '.' : 'Sacaste tu celular.');
            redirigir('mi_clave.php');
        }
    } else {
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
}

layout_inicio('Mis datos', '');
titulo('Mis datos', usuario_actual()['nombre'] . ' · ' . usuario_actual()['email']);
mensajes($errores);
?>
<?php if ($conCelular): ?>
<form method="post" action="mi_clave.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="celular">
  <div class="card" style="max-width:720px">
    <?= card_head('Mi celular', 'para que te llamen o te escriban por WhatsApp desde Mensajes') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:6"><label for="celular">Celular</label>
          <input id="celular" name="celular" inputmode="tel" class="mono" data-tel="movil" value="<?= h($celular) ?>" maxlength="30" placeholder="11 15 2345-6789">
          <small class="campo-ayuda">Con la característica. Vacío, no lo ven.</small></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Guardar celular', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<form method="post" action="mi_clave.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="clave">
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
