-- =====================================================================
--  FINANCIERA - El recibo de cobranza toma el número del talonario
--
--  Correr DESPUÉS de financiera_fondos.sql y financiera_talonarios.sql, con
--  la base de la financiera seleccionada. MySQL 8. Se puede correr más de una
--  vez. En DBeaver: Ejecutar script (Alt+X).
--
--  OJO: redefine sp_registrar_pago (el de financiera_fondos.sql, con un
--  parámetro más: el talonario). Si se vuelve a correr financiera_fondos.sql,
--  después hay que correr este de nuevo.
--
--  QUÉ CAMBIA
--  ---------------------------------------------------------------------
--  - El recibo de un cobro sale con el número del talonario de recibos:
--    X 00001-00000001 (letra, punto de venta y número), en lugar de
--    R-00000123 (el id del pago).
--  - El número se reserva con sp_talonario_numero dentro de la transacción
--    del cobro: con el talonario bloqueado, dos cobros a la vez no sacan el
--    mismo número y, si el cobro falla, el número vuelve (no quedan huecos).
--    Un recibo anulado conserva su número: no se vuelve a usar.
--  - Qué talonario: el que se elige al cobrar o al importar el lote. Si no se
--    elige, el único talonario de recibos habilitado (de una clasificación de
--    tipo Recibo, con numeración propia). Con más de uno hay que elegir. Sin
--    ninguno, el recibo sale R-00000123, como antes: los recibos viejos
--    quedan con su número.
--  - pagos guarda el talonario y el número (id_talonario, recibo_numero),
--    únicos por talonario.
--  - El cobro corre en READ COMMITTED. Con el préstamo bloqueado al empezar,
--    lo que lee después es lo último confirmado y no bloquea pagos de otros
--    préstamos: en REPEATABLE READ, dos cobros a la vez (de préstamos
--    distintos) se trababan entre sí y uno fallaba con «Deadlock» (1213).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. El talonario y el número en el pago
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_pagos_talonario $$
CREATE PROCEDURE tmp_pagos_talonario()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.ROUTINES
                    WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_talonario_numero') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta financiera_talonarios.sql: corrélo antes que este';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'id_talonario') THEN
        ALTER TABLE pagos
          ADD COLUMN id_talonario  INT UNSIGNED NULL COMMENT 'Talonario del recibo (NULL: recibo R- de antes)' AFTER nro_recibo,
          ADD COLUMN recibo_numero INT UNSIGNED NULL COMMENT 'Número del recibo en su talonario' AFTER id_talonario,
          ADD UNIQUE KEY uq_pagos_talonario_numero (id_talonario, recibo_numero),
          ADD CONSTRAINT fk_pagos_talonario FOREIGN KEY (id_talonario) REFERENCES talonarios (id_talonario);
    END IF;
END $$
DELIMITER ;
CALL tmp_pagos_talonario();
DROP PROCEDURE IF EXISTS tmp_pagos_talonario;

-- ---------------------------------------------------------------------
-- 2. sp_registrar_pago con el talonario
--   p_id_talonario  el talonario de recibos. NULL = el único habilitado
--                   (con más de uno, error: hay que elegir); sin ninguno,
--                   el recibo sale R- como antes.
-- ---------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS sp_registrar_pago $$
CREATE PROCEDURE sp_registrar_pago(
    IN  p_id_prestamo INT UNSIGNED,
    IN  p_fecha       DATE,
    IN  p_importe     DECIMAL(15,2),
    IN  p_medio       VARCHAR(20),
    IN  p_referencia  VARCHAR(100),
    IN  p_modo        VARCHAR(20),
    IN  p_id_lote     INT UNSIGNED,
    IN  p_id_usuario  INT UNSIGNED,
    IN  p_id_cuenta   INT UNSIGNED,
    IN  p_id_talonario INT UNSIGNED,
    OUT p_id_pago     INT UNSIGNED
)
BEGIN
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_fliq     DATE;
    DECLARE v_total    DECIMAL(15,2);
    DECLARE v_resto    DECIMAL(15,2);
    DECLARE v_aplicar  DECIMAL(15,2);
    DECLARE v_id_cuota INT UNSIGNED;
    DECLARE v_concepto VARCHAR(20);
    DECLARE v_pend     DECIMAL(15,2);
    DECLARE v_fin      TINYINT DEFAULT 0;
    DECLARE v_msg      VARCHAR(255);
    DECLARE v_tal_n    INT DEFAULT 0;
    DECLARE v_tal_ok   INT DEFAULT 0;
    DECLARE v_numero   INT UNSIGNED;

    DECLARE cur CURSOR FOR
        SELECT id_cuota, concepto, pendiente
          FROM tmp_deuda
         ORDER BY nro_cuota, orden;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_modo  = COALESCE(p_modo, 'NORMAL');

    IF p_importe IS NULL OR p_importe <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El importe debe ser mayor a cero';
    END IF;

    SET p_id_cuenta = COALESCE(p_id_cuenta, (SELECT id_cuenta_fondos FROM cuentas_fondos_medios WHERE medio = p_medio));

    -- El talonario del recibo: el elegido o, si no se eligió, el único de
    -- recibos habilitado. Sin ninguno, el recibo sale R-00000123 (como antes).
    IF p_id_talonario IS NULL THEN
        SELECT COUNT(*), MIN(t.id_talonario) INTO v_tal_n, p_id_talonario
          FROM talonarios t
          JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
          JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
         WHERE ct.nombre = 'Recibo' AND t.habilitado = 1 AND t.electronico = 0;
        IF v_tal_n > 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hay más de un talonario de recibos habilitado: elegí con cuál se emite el recibo';
        END IF;
    ELSE
        SELECT COUNT(*) INTO v_tal_ok
          FROM talonarios t
          JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
          JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
         WHERE t.id_talonario = p_id_talonario AND ct.nombre = 'Recibo' AND t.electronico = 0;
        IF v_tal_ok = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El talonario elegido no es de recibos con numeración propia';
        END IF;
    END IF;
    SET v_msg = LEFT(fn_fondos_cuenta_error(p_id_cuenta, 'ARS'), 128);
    IF v_msg IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    -- READ COMMITTED: el préstamo se bloquea primero y lo que se lee después
    -- es lo confirmado, sin bloquear. En REPEATABLE READ, los INSERT … SELECT
    -- de sp_calc_deuda ponen bloqueos compartidos sobre pagos e imputaciones
    -- (también de otros préstamos) y dos cobros a la vez se trababan (1213).
    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
    START TRANSACTION;

    SELECT estado, fecha_liquidacion INTO v_estado, v_fliq
      FROM prestamos WHERE id_prestamo = p_id_prestamo
       FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('El préstamo no está vigente (', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_fecha < v_fliq THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La fecha del pago es anterior a la liquidación';
    END IF;
    IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = p_id_prestamo
                AND estado = 'APLICADO' AND fecha > p_fecha) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hay pagos con fecha posterior: no se puede imputar con fecha anterior';
    END IF;

    CALL sp_calc_deuda(p_id_prestamo, p_fecha, p_modo);

    SELECT COALESCE(SUM(pendiente), 0) INTO v_total FROM tmp_deuda;

    IF p_modo = 'NORMAL' AND p_importe > v_total THEN
        SET v_msg = CONCAT('El importe supera la deuda total (', v_total,
                           '). Para cancelar, usar modo CANCELACION');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_modo = 'CANCELACION' AND p_importe <> v_total THEN
        SET v_msg = CONCAT('El monto de cancelación al ', DATE_FORMAT(p_fecha, '%d/%m/%Y'),
                           ' es ', v_total, '. Se informó ', p_importe);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    INSERT INTO pagos (id_prestamo, fecha, importe, medio, referencia, id_cuenta_fondos, modo, id_lote, creado_por)
    VALUES (p_id_prestamo, p_fecha, p_importe, p_medio, p_referencia, p_id_cuenta, p_modo, p_id_lote, p_id_usuario);
    SET p_id_pago = LAST_INSERT_ID();

    -- El número del recibo: el próximo del talonario, reservado con bloqueo en
    -- esta transacción (dos cobros a la vez no sacan el mismo; si algo falla,
    -- el número vuelve). Un recibo anulado conserva su número.
    IF p_id_talonario IS NULL THEN
        UPDATE pagos SET nro_recibo = CONCAT('R-', LPAD(p_id_pago, 8, '0'))
         WHERE id_pago = p_id_pago;
    ELSE
        CALL sp_talonario_numero(p_id_talonario, v_numero);
        UPDATE pagos g
          JOIN talonarios t ON t.id_talonario = p_id_talonario
           SET g.id_talonario  = p_id_talonario,
               g.recibo_numero = v_numero,
               g.nro_recibo    = CONCAT(t.letra, ' ', LPAD(t.punto_venta, 5, '0'), '-', LPAD(v_numero, 8, '0'))
         WHERE g.id_pago = p_id_pago;
    END IF;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'PUNITORIO', punit_nuevo,
           CONCAT('Punitorio cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE punit_nuevo > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'IVA_PUNITORIO', ivap_nuevo,
           CONCAT('IVA punitorio cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE ivap_nuevo > 0;

    UPDATE cuotas c
      JOIN tmp_cs t ON t.id_cuota = c.id_cuota
       SET c.punitorio_hasta = p_fecha
     WHERE t.devenga = 1;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_INTERES', -quita_int,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_int > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_IVA', -quita_iva,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_iva > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'CREDITO_CAPITAL', -cred_cap,
           CONCAT('Interés pagado por adelantado, a capital cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE cred_cap > 0;

    SET v_resto = p_importe;
    OPEN cur;
    lazo: LOOP
        FETCH cur INTO v_id_cuota, v_concepto, v_pend;
        IF v_fin = 1 OR v_resto <= 0 THEN LEAVE lazo; END IF;

        SET v_aplicar = LEAST(v_resto, v_pend);
        INSERT INTO pagos_imputacion (id_pago, id_cuota, concepto, importe)
        VALUES (p_id_pago, v_id_cuota, v_concepto, v_aplicar);
        SET v_resto = v_resto - v_aplicar;
    END LOOP;
    CLOSE cur;

    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (p_id_prestamo, p_id_pago, p_fecha, 'PAGO', -p_importe,
            CONCAT(p_medio, IF(p_referencia IS NULL, '', CONCAT(' ', p_referencia))), p_id_usuario);

    CALL sp_actualizar_estados(p_id_prestamo);

    CALL sp_fondos_cobranza(p_id_pago);

    COMMIT;

    SELECT pg.nro_recibo, c.nro_cuota, c.vencimiento, pi.concepto, pi.importe
      FROM pagos_imputacion pi
      JOIN pagos  pg ON pg.id_pago  = pi.id_pago
      JOIN cuotas c  ON c.id_cuota  = pi.id_cuota
      JOIN imputacion_orden o ON o.concepto = pi.concepto
     WHERE pi.id_pago = p_id_pago
     ORDER BY c.nro_cuota, o.orden;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 3. Control (tiene que dar cero filas): recibos de talonario con un
--    número fuera de su rango o con otro formato que el del talonario.
-- ---------------------------------------------------------------------
SELECT 'recibo que no cuadra con su talonario' AS control, g.id_pago, g.nro_recibo, t.nombre
  FROM pagos g JOIN talonarios t ON t.id_talonario = g.id_talonario
 WHERE g.recibo_numero NOT BETWEEN t.numero_desde AND t.numero_hasta
    OR g.recibo_numero > t.ultimo_emitido
    OR g.nro_recibo <> CONCAT(t.letra, ' ', LPAD(t.punto_venta, 5, '0'), '-', LPAD(g.recibo_numero, 8, '0'));
