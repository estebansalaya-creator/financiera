-- =====================================================================
--  FINANCIERA - Solicitudes: número por año y carga simultánea
--
--  Correr DESPUÉS de financiera_solicitudes.sql y financiera_numeradores.sql,
--  con la base de la financiera seleccionada. MySQL 8. Se puede correr más
--  de una vez. En DBeaver: Ejecutar script (Alt+X).
--
--  1. solicitudes.version: sube con cada cambio de la solicitud. Quien
--     autoriza decide sobre la versión que vio: si mientras la miraba la
--     modificaron o la volvieron a controlar, la decisión no se aplica y se
--     le pide que la revise de nuevo (antes se aprobaba lo nuevo sin verlo).
--  2. Número por año, como las órdenes de pago: 2026-00000001, que vuelve a
--     1 cada año (el de la carga). Sale de sp_numerador (serie SOL-2026),
--     con bloqueo dentro de la transacción del alta: dos a la vez no sacan
--     el mismo y no quedan huecos.
--  3. Las solicitudes que ya existen pasan al número nuevo, por año de carga
--     y en el orden en que se cargaron (S-000001 -> 2026-00000001). El texto
--     «Solicitud S-…» guardado en los préstamos liquidados se actualiza.
--     Si no querés renumerar las que ya existen, no corras la sección 3: las
--     nuevas igual salen con el número por año.
--  4. El numerador de cada año arranca después del último número que ya
--     está grabado.
--
--  En la aplicación (solicitud.php), el alta y la modificación se graban en
--  una transacción que bloquea solo la fila del cliente: dos solicitudes del
--  mismo cliente se graban de a una (la segunda ve a la primera) y las de
--  clientes distintos no se esperan entre sí.
-- =====================================================================

SET NAMES utf8mb4;

-- Los números salen de sp_numerador: sin financiera_numeradores.sql no se sigue.
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_requiere_numeradores $$
CREATE PROCEDURE tmp_requiere_numeradores()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.ROUTINES
                    WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_numerador') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta financiera_numeradores.sql: corrélo antes que este';
    END IF;
END $$
DELIMITER ;
CALL tmp_requiere_numeradores();
DROP PROCEDURE IF EXISTS tmp_requiere_numeradores;

-- ---------------------------------------------------------------------
-- 1. La versión de la solicitud
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_sol_version $$
CREATE PROCEDURE tmp_sol_version()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'version') THEN
        ALTER TABLE solicitudes
          ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0
              COMMENT 'Sube con cada cambio: una decisión sobre una versión vieja no se aplica'
              AFTER estado;
    END IF;
END $$
DELIMITER ;
CALL tmp_sol_version();
DROP PROCEDURE IF EXISTS tmp_sol_version;

-- ---------------------------------------------------------------------
-- 3. Las que ya existen, al número por año (S-000001 -> 2026-00000001).
--    Si ya hay números nuevos en un año, las convertidas siguen después.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_sol_nros;
CREATE TEMPORARY TABLE tmp_sol_nros (
    id_solicitud INT UNSIGNED NOT NULL PRIMARY KEY,
    viejo        VARCHAR(20)  NOT NULL,
    nuevo        VARCHAR(20)  NOT NULL
);
INSERT INTO tmp_sol_nros (id_solicitud, viejo, nuevo)
SELECT v.id_solicitud, v.nro_solicitud,
       CONCAT(v.anio, '-', LPAD(COALESCE(b.base, 0) + v.n, 8, '0'))
  FROM (SELECT id_solicitud, nro_solicitud, YEAR(creado_el) AS anio,
               ROW_NUMBER() OVER (PARTITION BY YEAR(creado_el) ORDER BY creado_el, id_solicitud) AS n
          FROM solicitudes
         WHERE nro_solicitud NOT REGEXP '^[0-9]{4}-[0-9]{8}$') v
  LEFT JOIN (SELECT CAST(LEFT(nro_solicitud, 4) AS UNSIGNED) AS anio,
                    MAX(CAST(SUBSTRING(nro_solicitud, 6) AS UNSIGNED)) AS base
               FROM solicitudes
              WHERE nro_solicitud REGEXP '^[0-9]{4}-[0-9]{8}$'
              GROUP BY CAST(LEFT(nro_solicitud, 4) AS UNSIGNED)) b ON b.anio = v.anio;

SELECT viejo AS 'número anterior', nuevo AS 'número nuevo' FROM tmp_sol_nros ORDER BY nuevo;

UPDATE solicitudes s JOIN tmp_sol_nros t ON t.id_solicitud = s.id_solicitud
   SET s.nro_solicitud = t.nuevo;
-- El motivo guardado en el préstamo dice «Solicitud S-000002 aprobada por …».
UPDATE prestamos p
  JOIN solicitudes s ON s.id_prestamo = p.id_prestamo
  JOIN tmp_sol_nros t ON t.id_solicitud = s.id_solicitud
   SET p.controles_motivo = REPLACE(p.controles_motivo, CONCAT('Solicitud ', t.viejo, ' '), CONCAT('Solicitud ', t.nuevo, ' '))
 WHERE p.controles_motivo LIKE CONCAT('%Solicitud ', t.viejo, ' %');
DROP TEMPORARY TABLE IF EXISTS tmp_sol_nros;

-- ---------------------------------------------------------------------
-- 4. El numerador de cada año, desde lo que ya está grabado (si este
--    script se corre de nuevo, ninguna serie vuelve para atrás).
-- ---------------------------------------------------------------------
INSERT INTO numeradores (clave, ultimo)
SELECT x.clave, x.ultimo
  FROM (SELECT CONCAT('SOL-', LEFT(nro_solicitud, 4)) AS clave, MAX(CAST(SUBSTRING(nro_solicitud, 6) AS UNSIGNED)) AS ultimo
          FROM solicitudes WHERE nro_solicitud REGEXP '^[0-9]{4}-[0-9]{8}$'
         GROUP BY CONCAT('SOL-', LEFT(nro_solicitud, 4))) x
ON DUPLICATE KEY UPDATE ultimo = GREATEST(numeradores.ultimo, x.ultimo);

SELECT clave, ultimo FROM numeradores WHERE clave LIKE 'SOL-%' ORDER BY clave;

-- ---------------------------------------------------------------------
-- 5. Control (tiene que dar cero filas): números con otro formato.
-- ---------------------------------------------------------------------
SELECT 'solicitud con otro número' AS control, id_solicitud, nro_solicitud
  FROM solicitudes WHERE nro_solicitud NOT REGEXP '^[0-9]{4}-[0-9]{8}$';
