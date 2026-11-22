-- =====================================================================
--  FINANCIERA - Log de errores del sistema  (admin_errores.php)
--  MySQL 8 / MariaDB 10.x. Correr después de financiera_seguridad.sql, con
--  la base de la financiera seleccionada. Se puede correr más de una vez.
--
--  Cada error que se produce en una pantalla queda anotado con la fecha y
--  hora, el usuario, el método y la URL, el error (tipo y mensaje) y la
--  traza. Lo escribe app\inc\log_errores.php (ver su encabezado): las
--  excepciones que nadie atajó y los errores fatales (la pantalla «no se
--  pudo abrir»), los pedidos que terminan en 500 y los errores que la
--  pantalla atajó con error_amigable(). El mismo modelo que gestion_adm
--  (s_log_errores).
--
--  El mismo error (misma firma) del mismo usuario dentro de 5 minutos suma
--  veces y corre ultima en lugar de abrir otra fila. Se guarda 180 días.
--
--  Sin la tabla la aplicación anda igual: el error queda solo en
--  documentos\logs\php_errores.log, como hasta ahora.
--
--  El menú se arma al iniciar sesión y se relee cada minuto: la opción
--  aparece sola (o saliendo y volviendo a entrar).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. La tabla. Sin clave foránea a usuarios: el registro queda aunque el
--    usuario se borre, y un error no impide borrar a nadie.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS log_errores (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fecha         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- la primera vez
    ultima        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- la última vez (con veces > 1)
    veces         INT UNSIGNED  NOT NULL DEFAULT 1,
    origen        ENUM('excepcion','respuesta','aviso') NOT NULL,
    status        SMALLINT      NULL,             -- el código HTTP, si hubo respuesta de error
    tipo          VARCHAR(150)  NULL,             -- la clase: PDOException, TypeError, Error fatal…
    mensaje       TEXT          NOT NULL,
    detalle       MEDIUMTEXT    NULL,             -- la traza, sin los argumentos
    lugar         VARCHAR(300)  NULL,             -- el renglón de código propio: archivo:línea función
    firma         CHAR(40)      NOT NULL,         -- sha1 de origen + tipo + lugar: «el mismo error»
    id_usuario    INT UNSIGNED  NULL,
    usuario       VARCHAR(150)  NULL,             -- el nombre, como estaba en la sesión
    metodo        VARCHAR(10)   NOT NULL,
    url           VARCHAR(1000) NOT NULL,         -- con los parámetros secretos tapados
    pantalla      VARCHAR(100)  NULL,             -- el .php
    referer       VARCHAR(500)  NULL,
    ip            VARCHAR(64)   NULL,
    navegador     VARCHAR(300)  NULL,
    revisado      TINYINT(1)    NOT NULL DEFAULT 0,
    revisado_por  VARCHAR(150)  NULL,
    revisado_el   DATETIME      NULL,
    PRIMARY KEY (id),
    KEY ix_logerr_ultima (ultima),
    KEY ix_logerr_firma (firma, id_usuario, ultima),
    KEY ix_logerr_revisado (revisado, ultima),
    KEY ix_logerr_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. La opción de menú: Administración del Sistema (200), después de Menú.
-- ---------------------------------------------------------------------

INSERT INTO menu (padre_id, nombre, url, icono, icono_svg, orden, nivel, activo)
SELECT 200, 'Log de errores', 'admin_errores.php', 'alerta',
       '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0112 12.75zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 01-1.152 6.06M12 12.75c-2.883 0-5.647.508-8.208 1.44.125 2.104.52 4.136 1.153 6.06M12 12.75a2.25 2.25 0 002.248-2.354M12 12.75a2.25 2.25 0 01-2.248-2.354M12 8.25c.995 0 1.971-.08 2.922-.236.403-.066.74-.358.795-.762a3.778 3.778 0 00-.399-2.25M12 8.25c-.995 0-1.97-.08-2.922-.236-.402-.066-.74-.358-.795-.762a3.734 3.734 0 01.4-2.253M12 8.25a2.25 2.25 0 00-2.248 2.146M12 8.25a2.25 2.25 0 012.248 2.146M8.683 5a6.032 6.032 0 01-1.155-1.002c.07-.63.27-1.222.574-1.747m.581 2.749A3.75 3.75 0 0115.318 5m0 0c.427-.283.815-.62 1.155-.999a4.471 4.471 0 00-.575-1.752M4.921 6a24.048 24.048 0 00-.392 3.314c1.668.546 3.416.914 5.223 1.082M19.08 6c.205 1.08.337 2.187.392 3.314a23.882 23.882 0 01-5.223 1.082"/></svg>',
       5, 2, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT url FROM menu) x WHERE x.url = 'admin_errores.php');

-- ---------------------------------------------------------------------
-- 3. Quién la ve: los roles que tienen Usuarios (el Administrador).
--    Para darla a alguien más, desde Permisos por usuario.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT (SELECT id_menu FROM menu WHERE url = 'admin_errores.php'), mr.id_rol
  FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu AND m.url = 'admin_usuarios.php';

-- ---------------------------------------------------------------------
-- 4. Verificación
-- ---------------------------------------------------------------------

SELECT m.id_menu, m.padre_id, m.orden, m.nombre, m.url,
       GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.url = 'admin_errores.php' GROUP BY m.id_menu;
