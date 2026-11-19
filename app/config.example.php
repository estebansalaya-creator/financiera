<?php
// Copiar este archivo como config.php (misma carpeta) y completar los datos.
// config.php queda fuera de la carpeta pública, así que IIS no lo sirve.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'financiera',
    'db_user' => 'financiera_app',
    'db_pass' => '',

    // Documentación de clientes: carpeta fuera de public\ donde quedan los
    // archivos (el grupo de aplicaciones de IIS necesita permiso de
    // modificación) y tamaño máximo por archivo en MB.
    'docs_dir'    => 'D:\\financiera\\documentos',
    'docs_max_mb' => 10,

    // Central de Deudores del BCRA (API pública, sin clave). Una consulta
    // de menos de bcra_cache_horas se reutiliza. bcra_cainfo: ruta a un
    // cacert.pem si PHP no puede validar el certificado del BCRA.
    'bcra_cache_horas' => 24,
    'bcra_cainfo'      => '',

    // Sesión: minutos sin uso hasta que se cierra sola (0 = no se cierra)
    // y carpeta donde PHP guarda las sesiones (vacía = la de php.ini). Si
    // se usa una propia, el grupo de aplicaciones de IIS necesita permiso
    // de modificación ahí (instalar_iis.ps1 lo da para D:\financiera\sesiones).
    'sesion_minutos' => 480,
    'sesion_dir'     => 'D:\\financiera\\sesiones',

    // true solo en desarrollo: muestra el detalle de los errores de base en pantalla
    'debug'   => false,
];
