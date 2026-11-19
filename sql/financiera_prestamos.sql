-- =====================================================================
--  FINANCIERA - Préstamos: controles al liquidar y anulación
--  Correr DESPUÉS de financiera_nucleo.sql y financiera_cobranzas.sql.
--  MySQL 8 (probado también en MariaDB 10.x). Se puede correr más de una vez.
--
--  Contenido
--    prestamos (columnas nuevas)
--      controles         JSON con lo que dijeron los controles al liquidar
--                        (BCRA, cheques, documentación, mora, cuota/ingreso)
--      controles_motivo  por qué se liquidó igual, si hubo advertencias
--      anulado_el / anulado_por / motivo_anulacion
--    sp_anular_prestamo  anula un préstamo sin pagos (error de carga):
--                        revierte el saldo en movimientos y la comisión
--    parámetros de los controles (CONTROL_*)
-- =====================================================================

SET NAMES utf8mb4;

DELIMITER $$

-- Agrega una columna solo si no existe (MySQL 8 no tiene ADD COLUMN IF NOT EXISTS)
DROP PROCEDURE IF EXISTS tmp_agregar_columna $$
CREATE PROCEDURE tmp_agregar_columna(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla AND COLUMN_NAME = p_columna) THEN
        SET @ddl = CONCAT('ALTER TABLE ', p_tabla, ' ADD COLUMN ', p_columna, ' ', p_def);
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END $$

DELIMITER ;

CALL tmp_agregar_columna('prestamos', 'controles',        'TEXT NULL');
CALL tmp_agregar_columna('prestamos', 'controles_motivo', 'VARCHAR(255) NULL');
CALL tmp_agregar_columna('prestamos', 'anulado_el',       'DATETIME NULL');
CALL tmp_agregar_columna('prestamos', 'anulado_por',      'INT UNSIGNED NULL');
CALL tmp_agregar_columna('prestamos', 'motivo_anulacion', 'VARCHAR(200) NULL');
DROP PROCEDURE IF EXISTS tmp_agregar_columna;

-- ---------------------------------------------------------------------
-- Parámetros de los controles al liquidar. Una advertencia no impide
-- liquidar: pide confirmarlo y anotar el motivo, que queda en el préstamo.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
 ('CONTROL_BCRA_SITUACION_MAX', '2',    'Advierte si la peor situación actual en el BCRA es mayor a este valor (1 a 5)'),
 ('CONTROL_BCRA_DIAS',          '30',   'Antigüedad máxima de la consulta al BCRA para liquidar (si es más vieja, se consulta de nuevo)'),
 ('CONTROL_CUOTA_INGRESO_MAX',  '0.30', 'Advierte si las cuotas vigentes más la nueva superan esta fracción del ingreso mensual (0.30 = 30 %)'),
 ('NRO_PRESTAMO_PREFIJO',       'P-',   'Prefijo del número de préstamo (P-000123)');

DELIMITER $$

-- ---------------------------------------------------------------------
-- sp_anular_prestamo
--   Para errores de carga: el préstamo no tiene que tener pagos aplicados.
--   - estado ANULADO, con quién, cuándo y por qué
--   - movimiento ANULACION que deja el saldo del préstamo en cero
--   - comisión: devengada negativa (concepto ANULACION) por lo devengado;
--     si ya estaba liquidada al vendedor, se descuenta en la próxima
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_anular_prestamo $$
CREATE PROCEDURE sp_anular_prestamo(
    IN p_id_prestamo INT UNSIGNED,
    IN p_motivo      VARCHAR(200),
    IN p_id_usuario  INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_nro    VARCHAR(20);
    DECLARE v_saldo  DECIMAL(15,2);
    DECLARE v_msg    VARCHAR(255);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR CHAR_LENGTH(TRIM(p_motivo)) < 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    START TRANSACTION;

    SELECT estado, nro_prestamo INTO v_estado, v_nro
      FROM prestamos WHERE id_prestamo = p_id_prestamo FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('Solo se anula un préstamo vigente (este está ', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = p_id_prestamo AND estado = 'APLICADO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El préstamo tiene pagos aplicados: anulá primero los pagos';
    END IF;

    UPDATE prestamos
       SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = p_id_usuario, motivo_anulacion = p_motivo
     WHERE id_prestamo = p_id_prestamo;

    SELECT COALESCE(SUM(importe), 0) INTO v_saldo FROM movimientos WHERE id_prestamo = p_id_prestamo;
    IF v_saldo <> 0 THEN
        INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
        VALUES (p_id_prestamo, CURDATE(), 'ANULACION', -v_saldo, LEFT(CONCAT('Anulación: ', p_motivo), 100), p_id_usuario);
    END IF;

    INSERT INTO comisiones_devengadas (id_vendedor, id_prestamo, id_regla, fecha, concepto, base, porcentaje, importe)
    SELECT id_vendedor, id_prestamo, MAX(id_regla), CURDATE(), 'ANULACION', -SUM(base), MAX(porcentaje), -SUM(importe)
      FROM comisiones_devengadas
     WHERE id_prestamo = p_id_prestamo
     GROUP BY id_vendedor, id_prestamo
    HAVING SUM(importe) <> 0;

    COMMIT;
END $$

DELIMITER ;
