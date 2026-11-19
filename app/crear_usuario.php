<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Crea un usuario desde la consola, o le cambia la contraseña si ya
// existe. Sirve para el primer administrador (antes de que haya alguien
// que pueda entrar a Administración > Usuarios) y para destrabar a un
// administrador que se olvidó la contraseña.
//
//   php crear_usuario.php correo@dominio "Nombre Apellido"
//   php crear_usuario.php correo@dominio "Nombre Apellido" --rol=Gerencia
//
// Pide la contraseña. Vacía = genera una al azar y la muestra.
// Sin --rol, le da el rol Administrador.
// ---------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/inc/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return strpos($a, '--') !== 0; }));
$rol  = ROL_ADMIN;
foreach ($argv as $a) {
    if (strpos($a, '--rol=') === 0) {
        $rol = substr($a, 6);
    }
}
$email  = strtolower(trim($args[0] ?? ''));
$nombre = trim($args[1] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $nombre === '') {
    fwrite(STDERR, "Uso: php crear_usuario.php correo@dominio \"Nombre Apellido\" [--rol=Administrador]\n");
    exit(1);
}

try {
    $r = q1('SELECT id_rol, nombre FROM roles WHERE nombre = ?', [$rol]);
    if (!$r) {
        $lista = implode(', ', array_column(q('SELECT nombre FROM roles ORDER BY prioridad DESC'), 'nombre'));
        fwrite(STDERR, "No existe el rol «{$rol}». Roles: {$lista}\n");
        exit(1);
    }

    echo "Contraseña (vacía = generar una; se ve al escribir): ";
    $clave = trim((string) fgets(STDIN));
    $generada = false;
    if ($clave === '') {
        $abc = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 12; $i++) {
            $clave .= $abc[random_int(0, strlen($abc) - 1)];
        }
        $generada = true;
    } elseif (strlen($clave) < CLAVE_MIN) {
        fwrite(STDERR, 'La contraseña tiene que tener al menos ' . CLAVE_MIN . " caracteres.\n");
        exit(1);
    }

    $u = q1('SELECT id_usuario FROM usuarios WHERE email = ?', [$email]);
    $hash = password_hash($clave, PASSWORD_DEFAULT);
    if ($u) {
        $id = (int) $u['id_usuario'];
        q('UPDATE usuarios SET nombre = ?, password_hash = ?, activo = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?',
          [$nombre, $hash, $id]);
        echo "Usuario existente: contraseña cambiada y reactivado.\n";
    } else {
        q('INSERT INTO usuarios (nombre, email, password_hash, activo) VALUES (?, ?, ?, 1)', [$nombre, $email, $hash]);
        $id = (int) db()->lastInsertId();
        echo "Usuario creado.\n";
    }
    q('INSERT IGNORE INTO usuarios_roles (id_usuario, id_rol) VALUES (?, ?)', [$id, (int) $r['id_rol']]);
    echo "Rol: {$r['nombre']}\n";
    if ($generada) {
        echo "Contraseña: {$clave}\n(cambiala después desde «Mi contraseña», arriba a la derecha)\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
