<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

logout();
flash('info', 'Saliste del sistema.');
redirigir('login.php');
