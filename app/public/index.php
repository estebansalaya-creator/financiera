<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// Entrada: la primera pantalla del menú del usuario.
$destino = primera_pantalla();
if ($destino !== null) {
    redirigir($destino);
}

layout_inicio('Sin pantallas', '');
titulo('Tu usuario todavía no tiene pantallas', 'Pedile a un administrador que te asigne un rol.');
mensajes();
layout_fin();
