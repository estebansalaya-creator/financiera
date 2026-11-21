<?php
declare(strict_types=1);

// Los productos pasaron a ser los tipos de asociado (sql/financiera_tipos_asociado.sql):
// la tabla `productos` ya no existe. Queda la dirección vieja para los enlaces guardados.
require __DIR__ . '/../inc/bootstrap.php';

$id = (int) get_str('id');
redirigir('tipos_asociado.php' . ($id ? '?id=' . $id : (get_str('nuevo') === '1' ? '?nuevo=1' : '')));
