-- =====================================================================
--  FINANCIERA - Panel general
--  Correr después de financiera_seguridad.sql, con la base de la
--  financiera seleccionada. Se puede correr más de una vez.
--
--  La pantalla (panel.php) no tiene tablas propias: lee solicitudes,
--  préstamos, pagos e inversiones. Acá solo la opción de menú «Panel
--  general», primera de la raíz (para Administrador y Gerencia es la
--  pantalla de entrada), con permiso para esos dos roles. A otros roles se
--  les da en Administración › Roles.
--  La solapa Inversiones la ve quien además tiene Aportes o Inversores.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (70, NULL, 'Panel general', 'panel.php', 'grafico', 0, 1, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), url = VALUES(url), icono = VALUES(icono), nivel = VALUES(nivel);

INSERT IGNORE INTO menu_roles (id_menu, id_rol) VALUES (70, 1), (70, 2);

SELECT m.id_menu, m.nombre, m.url, GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu = 70 GROUP BY m.id_menu;
