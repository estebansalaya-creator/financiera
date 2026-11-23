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

    // WhatsApp Business (API de WhatsApp Cloud, Meta): token de acceso
    // permanente del usuario del sistema. El número, el Phone Number ID y el
    // WABA ID se cargan en Tablas y parámetros › Datos de la empresa; el
    // token va acá (en Railway: variable FIN_WA_TOKEN) porque es una clave.
    'whatsapp_token' => '',

    // Videollamadas de Mensajes: servidor Jitsi donde se arma la sala (con la
    // barra al final). En meet.jit.si quien abre la sala se identifica con
    // Google, GitHub o Facebook; los demás entran sin cuenta. Si tienen un
    // Jitsi propio, va acá (en Railway: variable FIN_VIDEO_URL).
    'video_url' => 'https://meet.jit.si/',

    // true solo en desarrollo: muestra el detalle de los errores de base en pantalla
    'debug'   => false,

    // ARCA («Consultar ARCA» en la ficha del cliente: constancia de inscripción,
    // ws_sr_constancia_inscripcion). El certificado tiene que estar habilitado
    // para ese servicio en el Administrador de Relaciones de ARCA. En Railway:
    // FIN_ARCA_CERT_B64 / FIN_ARCA_KEY_B64 (el .crt y el .key en base64) y FIN_ARCA_CUIT.
    'arca_cert'   => 'C:\\web\\certs\\certificado.crt',
    'arca_key'    => 'C:\\web\\certs\\clave_privada.key',
    'arca_cuit'   => '20211076004',     // CUIT dueño del certificado
    'arca_cainfo' => '',                // cacert.pem si PHP no valida HTTPS (vacío: usa bcra_cainfo)
    // Factura electrónica (inc\facturacion.php). Homologación: el certificado
    // del autoservicio de homologación de ARCA (sin esto usa el de arriba).
    'arca_cert_homo' => '',
    'arca_key_homo'  => '',
    'arca_cuit_homo' => '',
    // Producción: sin esto en true, los talonarios de producción no se autorizan
    // (un IIS de prueba no puede emitir una factura real por olvido).
    'arca_wsfe_produccion' => false,
];
