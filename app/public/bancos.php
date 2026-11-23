<?php
declare(strict_types=1);

// Tesorería › Bancos: el catálogo de entidades (código BCRA) es la solapa
// «Bancos» de Cuentas de fondos; esta opción de menú lleva directo ahí
// (seguridad.php la habilita para cuentas_fondos.php).
require __DIR__ . '/../inc/bootstrap.php';
redirigir('cuentas_fondos.php?solapa=bancos');
