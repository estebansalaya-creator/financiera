-- ---------------------------------------------------------------------
-- Contabilidad: asientos de las operaciones (app/inc/asientos.php,
-- app/public/asientos.php). Va después de financiera_contabilidad.sql y
-- financiera_contabilidad_imputaciones.sql.
--
-- Las tablas ya están (c_asientos, c_asiento_detalle, c_asientos_origen):
-- este script solo suma la opción del menú, con los mismos roles que el
-- Plan de cuentas. Se puede correr más de una vez.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (94, 90, 'Asientos contables', 'asientos.php', NULL, 4, 2, 1);

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT 94, mr.id_rol FROM menu_roles mr
 WHERE mr.id_menu = 91 AND EXISTS (SELECT 1 FROM (SELECT id_menu, url FROM menu) m WHERE m.id_menu = 94 AND m.url = 'asientos.php');
