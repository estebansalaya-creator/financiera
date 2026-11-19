-- =====================================================================
--  FINANCIERA - Datos de la empresa y logo
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_nucleo.sql y financiera_seguridad.sql, con la base de la
--  financiera seleccionada. Se puede correr más de una vez.
--
--  Contenido
--    empresa ...... una sola fila (id_empresa = 1): razón social, CUIT,
--                   condición frente al IVA, domicilio, contacto y logo
--    menú 54 ...... «Datos de la empresa», en Tablas y parámetros,
--                   para Administrador y Gerencia
--
--  Criterios
--    - El logo va en la base (MEDIUMBLOB), no en disco como los legajos:
--      es uno solo y chico (hasta 1 MB), y así sale igual en el IIS y en
--      Railway sin depender de una carpeta. La app lo sirve por logo.php.
--    - logo_pdf: el mismo logo ya preparado para incrustarlo en los PDF
--      (se calcula una vez, al subirlo). Si queda vacío, los PDF salen sin
--      logo, como antes.
--    - El logo también es el ícono de la app instalada en el celular (y el
--      ícono de la pestaña): lo sirven manifest.php e icono.php. Se puede
--      desactivar y elegir el color de fondo.
--    - WhatsApp Business: el número y el saludo sirven para los links
--      wa.me desde la app; Phone Number ID y WABA ID son de la API de
--      WhatsApp Cloud para avisos automáticos. El token de la API NO va
--      en la base: config.php ('whatsapp_token') o FIN_WA_TOKEN en Railway.
--    - Los datos salen en el encabezado de la app, en el ingreso y en
--      todos los PDF (recibos, planes de pago, liquidaciones…).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS empresa (
    id_empresa          TINYINT UNSIGNED NOT NULL,
    razon_social        VARCHAR(150) NOT NULL,
    nombre_fantasia     VARCHAR(100) NULL,           -- el nombre corto que se muestra en la app
    cuit                CHAR(11)     NULL,           -- sin guiones
    condicion_iva       VARCHAR(10)  NULL,           -- RI, MONO, EX
    ingresos_brutos     VARCHAR(30)  NULL,           -- n.º de IIBB o «Convenio Multilateral …»
    inicio_actividades  DATE         NULL,
    domicilio           VARCHAR(150) NULL,           -- calle, número, piso, oficina
    localidad           VARCHAR(80)  NULL,
    provincia           VARCHAR(40)  NULL,
    codigo_postal       VARCHAR(10)  NULL,
    telefono            VARCHAR(50)  NULL,
    email               VARCHAR(120) NULL,
    sitio_web           VARCHAR(120) NULL,
    bcra_codigo         VARCHAR(20)  NULL,           -- registro como otro proveedor no financiero de crédito, si tiene
    whatsapp_numero     VARCHAR(16)  NULL,           -- formato de teléfonos del sistema: 5491123456789
    whatsapp_mensaje    VARCHAR(500) NULL,           -- saludo con que arrancan los wa.me (admite {cliente} y {empresa})
    whatsapp_phone_id   VARCHAR(30)  NULL,           -- API de WhatsApp Cloud: Phone Number ID
    whatsapp_waba_id    VARCHAR(30)  NULL,           -- API de WhatsApp Cloud: WhatsApp Business Account ID
    logo                MEDIUMBLOB   NULL,
    logo_tipo           VARCHAR(20)  NULL,           -- image/png | image/jpeg
    logo_ancho          SMALLINT UNSIGNED NULL,      -- px
    logo_alto           SMALLINT UNSIGNED NULL,
    logo_pdf            MEDIUMBLOB   NULL,
    logo_actualizado_el DATETIME     NULL,
    -- Ícono de la app instalada (PWA), armado desde el logo por el navegador
    -- al guardar: PNG cuadrados de 32, 180, 192 y 512 px y el «maskable» de
    -- Android (512, con más margen). Vacíos: el ícono estándar.
    app_icono_usar      TINYINT(1)   NOT NULL DEFAULT 1,
    app_icono_fondo     CHAR(6)      NOT NULL DEFAULT 'ffffff', -- RGB en hexa, sin numeral
    app_icono_32        MEDIUMBLOB   NULL,
    app_icono_180       MEDIUMBLOB   NULL,
    app_icono_192       MEDIUMBLOB   NULL,
    app_icono_512       MEDIUMBLOB   NULL,
    app_icono_mask      MEDIUMBLOB   NULL,
    app_icono_actualizado_el DATETIME NULL,
    actualizado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por     INT UNSIGNED NULL,
    PRIMARY KEY (id_empresa),
    CONSTRAINT chk_empresa_unica CHECK (id_empresa = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO empresa (id_empresa, razon_social) VALUES (1, 'Financiera');

-- Opción de menú
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (54, 50, 'Datos de la empresa', 'empresa.php', 'empresa', 0, 2, 1)
ON DUPLICATE KEY UPDATE padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url),
                        icono = VALUES(icono), orden = VALUES(orden), nivel = VALUES(nivel);

INSERT IGNORE INTO menu_roles (id_menu, id_rol) VALUES (54, 1), (54, 2), (50, 1), (50, 2);

SELECT m.id_menu, m.nombre, m.url, GROUP_CONCAT(r.nombre) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu = 54 GROUP BY m.id_menu;
