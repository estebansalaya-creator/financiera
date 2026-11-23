-- =====================================================================
--  FINANCIERA - Cuota social en los recibos (Cobros bancarios)
--  Requiere financiera_recibos.sql y financiera_cuota_social.sql.
--  Correr desde CMD (tiene DELIMITER), se puede correr más de una vez:
--    mysql -u root -p --default-character-set=utf8mb4 financiera < financiera_cuota_social_recibos.sql
--
--  La cuota social se cobra con los créditos del banco, como las cuotas de
--  los préstamos: el recibo de un crédito (sp_emitir_recibo) reparte el
--  importe entre las cuotas de los préstamos vigentes Y las cuotas
--  sociales pendientes, de la más vieja a la más nueva (la cuota social de
--  un mes vence el día 1). Un cliente sin préstamos vigentes que debe
--  cuota social también tiene recibo.
--    - Lo cobrado de cuota social queda en cuotas_sociales_pagos con el
--      id_recibo; el recibo entra entero a la cuenta (sp_fondos_recibo).
--    - Anular el recibo (sp_anular_recibo) anula también esos pagos.
-- =====================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_cs_recibos $$
CREATE PROCEDURE tmp_cs_recibos()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'cuotas_sociales_pagos' AND column_name = 'id_recibo') THEN
        ALTER TABLE cuotas_sociales_pagos
            ADD COLUMN id_recibo INT UNSIGNED NULL COMMENT 'El recibo que lo cobró' AFTER id_lote,
            ADD KEY ix_csp_recibo (id_recibo),
            ADD CONSTRAINT fk_csp_recibo FOREIGN KEY (id_recibo) REFERENCES recibos (id_recibo);
    END IF;
END $$
CALL tmp_cs_recibos() $$
DROP PROCEDURE IF EXISTS tmp_cs_recibos $$

-- Pagado y estado de una cuota social, a partir de sus pagos vigentes.
-- Sin transacción propia.
DROP PROCEDURE IF EXISTS sp_cs_recalcular $$
CREATE PROCEDURE sp_cs_recalcular(IN p_id_cuota INT UNSIGNED)
BEGIN
    UPDATE cuotas_sociales c
       SET c.pagado = (SELECT COALESCE(SUM(p.importe), 0) FROM cuotas_sociales_pagos p
                        WHERE p.id_cuota_social = c.id_cuota_social AND p.anulado_el IS NULL),
           c.estado = IF(c.estado = 'ANULADA', 'ANULADA',
                      CASE WHEN c.pagado >= c.importe THEN 'PAGADA' WHEN c.pagado > 0 THEN 'PARCIAL' ELSE 'PENDIENTE' END)
     WHERE c.id_cuota_social = p_id_cuota;
END $$

-- ---------------------------------------------------------------------
-- RECIBO en el libro de fondos: el mismo de financiera_recibos.sql, con
-- «cuota social» en el concepto cuando la cubre.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fondos_recibo $$
CREATE PROCEDURE sp_fondos_recibo(IN p_id_recibo INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_por_pago TINYINT;
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT r.fecha, r.importe, r.medio, r.referencia, r.estado, r.id_cuenta_fondos, r.motivo_anulacion, r.anulado_por,
           r.libro_por_pago,
           CONCAT('Cobranza ', COALESCE(r.nro_recibo, r.id_recibo), ' · ', c.denominacion,
                  COALESCE(CONCAT(' · ', (SELECT GROUP_CONCAT(p.nro_prestamo ORDER BY p.id_prestamo SEPARATOR ', ')
                                            FROM pagos g JOIN prestamos p ON p.id_prestamo = g.id_prestamo
                                           WHERE g.id_recibo = r.id_recibo)), ''),
                  IF(EXISTS (SELECT 1 FROM cuotas_sociales_pagos s WHERE s.id_recibo = r.id_recibo), ' · cuota social', ''))
      INTO v_fecha, v_importe, v_medio, v_ref, v_estado, v_cuenta, v_motivo, v_anul_por, v_por_pago, v_concepto
      FROM recibos r
      JOIN clientes c ON c.id_cliente = r.id_cliente
     WHERE r.id_recibo = p_id_recibo;
    IF v_fecha IS NULL OR v_por_pago = 1 THEN
        LEAVE proc;
    END IF;

    IF v_cuenta IS NOT NULL THEN
        SELECT moneda INTO v_moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_cuenta;
        IF v_moneda <> 'ARS' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las cobranzas son en pesos: elegí una cuenta de fondos en pesos';
        END IF;
        CALL sp_fondos_renglon('RECIBO', p_id_recibo, v_cuenta, v_fecha, v_importe, 0, v_concepto, v_mf);
    END IF;
    CALL sp_fondos_quitar('RECIBO', p_id_recibo, IF(v_mf IS NULL, NULL, v_cuenta), NULL);

    IF v_mf IS NOT NULL THEN
        CALL sp_fondos_banco(v_mf,
             CASE v_medio WHEN 'DEBITO'   THEN 'DEBITO_AUTOMATICO'
                          WHEN 'EFECTIVO' THEN 'DEPOSITO'
                          WHEN 'CHEQUE'   THEN 'DEPOSITO'
                          WHEN 'OTRO'     THEN 'OTRO'
                          ELSE 'TRANSFERENCIA' END,
             NULL, v_ref, NULL);
    END IF;

    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('RECIBO', p_id_recibo, COALESCE(v_motivo, 'Recibo anulado'), v_anul_por);
    END IF;
END $$

-- ---------------------------------------------------------------------
-- sp_emitir_recibo: el de financiera_recibos.sql, sumando la cuota social.
-- La deuda es la de los préstamos vigentes (con los punitorios a la fecha)
-- más las cuotas sociales pendientes, repartida por vencimiento.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_emitir_recibo $$
CREATE PROCEDURE sp_emitir_recibo(
    IN  p_id_cliente   INT UNSIGNED,
    IN  p_fecha        DATE,
    IN  p_importe      DECIMAL(15,2),
    IN  p_medio        VARCHAR(20),
    IN  p_referencia   VARCHAR(100),
    IN  p_id_cuenta    INT UNSIGNED,
    IN  p_id_talonario INT UNSIGNED,
    IN  p_id_extracto  INT UNSIGNED,
    IN  p_cuil_pagador CHAR(11),
    IN  p_id_usuario   INT UNSIGNED,
    OUT p_id_recibo    INT UNSIGNED
)
BEGIN
    DECLARE v_credito  DECIMAL(15,2);
    DECLARE v_disp     DECIMAL(15,2);
    DECLARE v_conc     TINYINT;
    DECLARE v_cta_ext  INT UNSIGNED;
    DECLARE v_n        INT DEFAULT 0;
    DECLARE v_ncs      INT DEFAULT 0;
    DECLARE v_prest    INT UNSIGNED;
    DECLARE v_cs       INT UNSIGNED;
    DECLARE v_nro      VARCHAR(20);
    DECLARE v_total    DECIMAL(15,2);
    DECLARE v_resto    DECIMAL(15,2);
    DECLARE v_aplicar  DECIMAL(15,2);
    DECLARE v_pend     DECIMAL(15,2);
    DECLARE v_monto    DECIMAL(15,2);
    DECLARE v_id_pago  INT UNSIGNED;
    DECLARE v_tal_n    INT DEFAULT 0;
    DECLARE v_tal_ok   INT DEFAULT 0;
    DECLARE v_numero   INT UNSIGNED;
    DECLARE v_fin      TINYINT DEFAULT 0;
    DECLARE v_msg      VARCHAR(255);

    DECLARE cur CURSOR FOR
        SELECT id_prestamo, id_cuota_social, pendiente
          FROM tmp_rec_deuda
         ORDER BY vencimiento, id_cuota_social IS NULL, id_prestamo, nro_cuota, orden, id_cuota_social;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_medio = COALESCE(p_medio, 'TRANSFERENCIA');
    SET p_id_recibo = NULL;

    IF p_importe IS NULL OR p_importe <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El importe debe ser mayor a cero';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM clientes WHERE id_cliente = p_id_cliente) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente inexistente';
    END IF;
    IF p_id_extracto IS NOT NULL THEN
        SET p_id_cuenta = COALESCE(p_id_cuenta, (SELECT id_cuenta_fondos FROM extractos_bancarios WHERE id_extracto = p_id_extracto));
    END IF;

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

    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
    START TRANSACTION;

    -- El crédito del extracto, bloqueado: dos emisiones del mismo crédito
    -- se esperan, y la segunda ve lo que ya cubrió la primera.
    IF p_id_extracto IS NOT NULL THEN
        SELECT credito, conciliado, id_cuenta_fondos INTO v_credito, v_conc, v_cta_ext
          FROM extractos_bancarios WHERE id_extracto = p_id_extracto
           FOR UPDATE;
        IF v_credito IS NULL OR v_credito <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El movimiento del extracto no existe o no es un crédito';
        END IF;
        IF v_conc = 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El crédito del extracto ya está conciliado';
        END IF;
        IF NOT (v_cta_ext <=> p_id_cuenta) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El recibo de un crédito del extracto entra en la cuenta de ese extracto';
        END IF;
        SELECT v_credito - COALESCE(SUM(importe), 0) INTO v_disp
          FROM recibos WHERE id_extracto = p_id_extracto AND estado = 'APLICADO';
        IF v_disp < 0.01 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El crédito del extracto ya tiene su recibo';
        END IF;
        SET p_importe = LEAST(p_importe, v_disp);
    END IF;

    -- Los préstamos vigentes del cliente (en orden de id) y sus cuotas
    -- sociales pendientes, bloqueados.
    SELECT COUNT(*) INTO v_n
      FROM prestamos
     WHERE id_cliente = p_id_cliente AND estado = 'VIGENTE'
       FOR UPDATE;
    SELECT COUNT(*) INTO v_ncs
      FROM cuotas_sociales
     WHERE id_cliente = p_id_cliente AND estado IN ('PENDIENTE', 'PARCIAL')
       FOR UPDATE;
    IF v_n = 0 AND v_ncs = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no tiene préstamos vigentes ni cuota social pendiente';
    END IF;

    -- La deuda a la fecha en una sola tabla para repartir el importe.
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_deuda;
    CREATE TEMPORARY TABLE tmp_rec_deuda (
        id_prestamo     INT UNSIGNED NULL,
        id_cuota        INT UNSIGNED NULL,
        id_cuota_social INT UNSIGNED NULL,
        nro_cuota       SMALLINT UNSIGNED,
        vencimiento     DATE,
        concepto        VARCHAR(20),
        orden           TINYINT UNSIGNED,
        pendiente       DECIMAL(15,2),
        KEY (vencimiento)
    );
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_monto;
    CREATE TEMPORARY TABLE tmp_rec_monto (
        id_prestamo INT UNSIGNED PRIMARY KEY,
        monto       DECIMAL(15,2)
    );
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_cs;
    CREATE TEMPORARY TABLE tmp_rec_cs (
        id_cuota_social INT UNSIGNED PRIMARY KEY,
        monto           DECIMAL(15,2)
    );

    SET v_prest = 0;
    cada_prestamo: LOOP
        SET v_n = NULL;
        SELECT MIN(id_prestamo) INTO v_n
          FROM prestamos
         WHERE id_cliente = p_id_cliente AND estado = 'VIGENTE' AND id_prestamo > v_prest
           AND fecha_liquidacion <= p_fecha;
        IF v_n IS NULL THEN LEAVE cada_prestamo; END IF;
        SET v_prest = v_n;

        IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = v_prest AND estado = 'APLICADO' AND fecha > p_fecha) THEN
            SELECT nro_prestamo INTO v_nro FROM prestamos WHERE id_prestamo = v_prest;
            SET v_msg = LEFT(CONCAT('El préstamo ', v_nro, ' tiene pagos con fecha posterior al ',
                                    DATE_FORMAT(p_fecha, '%d/%m/%Y'), ': no se puede imputar con esa fecha'), 128);
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
        END IF;

        CALL sp_calc_deuda(v_prest, p_fecha, 'NORMAL');
        INSERT INTO tmp_rec_deuda (id_prestamo, id_cuota, nro_cuota, vencimiento, concepto, orden, pendiente)
        SELECT v_prest, id_cuota, nro_cuota, vencimiento, concepto, orden, pendiente
          FROM tmp_deuda WHERE pendiente > 0;
    END LOOP cada_prestamo;

    -- La cuota social: vence el día 1 de su mes.
    INSERT INTO tmp_rec_deuda (id_cuota_social, nro_cuota, vencimiento, concepto, orden, pendiente)
    SELECT id_cuota_social, 0, periodo, concepto, FIELD(concepto, 'INICIAL', 'MENSUAL', 'ADICIONAL', 'MANUAL'), importe - pagado
      FROM cuotas_sociales
     WHERE id_cliente = p_id_cliente AND estado IN ('PENDIENTE', 'PARCIAL') AND importe - pagado > 0;

    SELECT COALESCE(SUM(pendiente), 0) INTO v_total FROM tmp_rec_deuda;
    IF v_total <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no debe nada a esa fecha';
    END IF;
    IF p_id_extracto IS NOT NULL THEN
        SET p_importe = LEAST(p_importe, v_total);
    ELSEIF p_importe > v_total THEN
        SET v_msg = CONCAT('El importe supera la deuda del cliente (', v_total, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    -- El reparto: de lo que vence primero a lo último, entre los préstamos
    -- y la cuota social (el mismo día, primero la cuota social). Cada
    -- préstamo recibe un tramo de su propia lista, así que su imputación
    -- (de la cuota más vieja a la más nueva) es la misma.
    SET v_resto = p_importe;
    SET v_fin = 0;
    OPEN cur;
    reparto: LOOP
        FETCH cur INTO v_prest, v_cs, v_pend;
        IF v_fin = 1 OR v_resto <= 0 THEN LEAVE reparto; END IF;
        SET v_aplicar = LEAST(v_resto, v_pend);
        IF v_cs IS NOT NULL THEN
            INSERT INTO tmp_rec_cs (id_cuota_social, monto) VALUES (v_cs, v_aplicar)
                ON DUPLICATE KEY UPDATE monto = monto + v_aplicar;
        ELSE
            INSERT INTO tmp_rec_monto (id_prestamo, monto) VALUES (v_prest, v_aplicar)
                ON DUPLICATE KEY UPDATE monto = monto + v_aplicar;
        END IF;
        SET v_resto = v_resto - v_aplicar;
    END LOOP;
    CLOSE cur;

    INSERT INTO recibos (id_cliente, fecha, importe, medio, referencia, id_cuenta_fondos, id_extracto, cuil_pagador, creado_por)
    VALUES (p_id_cliente, p_fecha, p_importe, p_medio, p_referencia, p_id_cuenta, p_id_extracto, p_cuil_pagador, p_id_usuario);
    SET p_id_recibo = LAST_INSERT_ID();

    IF p_id_talonario IS NULL THEN
        UPDATE recibos SET nro_recibo = CONCAT('R-', LPAD(p_id_recibo, 8, '0')) WHERE id_recibo = p_id_recibo;
    ELSE
        CALL sp_talonario_numero(p_id_talonario, v_numero);
        UPDATE recibos r
          JOIN talonarios t ON t.id_talonario = p_id_talonario
           SET r.id_talonario  = p_id_talonario,
               r.recibo_numero = v_numero,
               r.nro_recibo    = CONCAT(t.letra, ' ', LPAD(t.punto_venta, 5, '0'), '-', LPAD(v_numero, 8, '0'))
         WHERE r.id_recibo = p_id_recibo;
    END IF;

    -- Un pago por préstamo, en orden de id.
    SET v_prest = 0;
    cada_pago: LOOP
        SET v_n = NULL;
        SELECT MIN(id_prestamo) INTO v_n FROM tmp_rec_monto WHERE id_prestamo > v_prest AND monto > 0;
        IF v_n IS NULL THEN LEAVE cada_pago; END IF;
        SET v_prest = v_n;
        SELECT monto INTO v_monto FROM tmp_rec_monto WHERE id_prestamo = v_prest;
        CALL sp_pago_aplicar(v_prest, p_fecha, v_monto, p_medio, p_referencia, 'NORMAL', NULL,
                             p_id_usuario, p_id_cuenta, p_id_recibo, v_id_pago);
    END LOOP cada_pago;

    -- Un pago por cuota social.
    INSERT INTO cuotas_sociales_pagos (id_cuota_social, fecha, importe, motivo, medio, referencia, id_recibo, creado_por)
    SELECT id_cuota_social, p_fecha, monto, 'PAGO', p_medio, p_referencia, p_id_recibo, p_id_usuario
      FROM tmp_rec_cs WHERE monto > 0;
    SET v_cs = 0;
    cada_cs: LOOP
        SET v_n = NULL;
        SELECT MIN(id_cuota_social) INTO v_n FROM tmp_rec_cs WHERE id_cuota_social > v_cs;
        IF v_n IS NULL THEN LEAVE cada_cs; END IF;
        SET v_cs = v_n;
        CALL sp_cs_recalcular(v_cs);
    END LOOP cada_cs;

    CALL sp_fondos_recibo(p_id_recibo);

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS tmp_rec_deuda;
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_monto;
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_cs;

    SELECT r.id_recibo, r.nro_recibo, r.importe, g.id_pago, p.nro_prestamo, g.importe AS aplicado
      FROM recibos r
      JOIN pagos g ON g.id_recibo = r.id_recibo
      JOIN prestamos p ON p.id_prestamo = g.id_prestamo
     WHERE r.id_recibo = p_id_recibo
    UNION ALL
    SELECT r.id_recibo, r.nro_recibo, r.importe, NULL, 'Cuota social', SUM(s.importe)
      FROM recibos r
      JOIN cuotas_sociales_pagos s ON s.id_recibo = r.id_recibo
     WHERE r.id_recibo = p_id_recibo
     GROUP BY r.id_recibo, r.nro_recibo, r.importe;
END $$

-- ---------------------------------------------------------------------
-- sp_anular_recibo: el de financiera_recibos.sql; también anula lo que
-- cobró de cuota social.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_anular_recibo $$
CREATE PROCEDURE sp_anular_recibo(
    IN p_id_recibo  INT UNSIGNED,
    IN p_motivo     VARCHAR(200),
    IN p_id_usuario INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_n      INT;
    DECLARE v_pago   INT UNSIGNED;
    DECLARE v_sig    INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR TRIM(p_motivo) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
    START TRANSACTION;

    -- Primero los préstamos (en orden de id) y después el recibo.
    SELECT COUNT(*) INTO v_n
      FROM prestamos
     WHERE id_prestamo IN (SELECT id_prestamo FROM pagos WHERE id_recibo = p_id_recibo)
       FOR UPDATE;

    SELECT estado INTO v_estado FROM recibos WHERE id_recibo = p_id_recibo FOR UPDATE;
    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recibo inexistente';
    END IF;
    IF v_estado <> 'APLICADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El recibo ya está anulado';
    END IF;

    SET v_pago = 0;
    cada_pago: LOOP
        SET v_sig = NULL;
        SELECT MIN(id_pago) INTO v_sig
          FROM pagos WHERE id_recibo = p_id_recibo AND estado = 'APLICADO' AND id_pago > v_pago;
        IF v_sig IS NULL THEN LEAVE cada_pago; END IF;
        SET v_pago = v_sig;
        CALL sp_pago_desaplicar(v_pago, p_motivo, p_id_usuario);
    END LOOP cada_pago;

    -- La cuota social que había cobrado.
    UPDATE cuotas_sociales_pagos
       SET anulado_el = NOW(), anulado_por = p_id_usuario, motivo_anulacion = LEFT(CONCAT('Recibo anulado: ', p_motivo), 200)
     WHERE id_recibo = p_id_recibo AND anulado_el IS NULL;
    SET v_pago = 0;
    cada_cs: LOOP
        SET v_sig = NULL;
        SELECT MIN(id_cuota_social) INTO v_sig
          FROM cuotas_sociales_pagos WHERE id_recibo = p_id_recibo AND id_cuota_social > v_pago;
        IF v_sig IS NULL THEN LEAVE cada_cs; END IF;
        SET v_pago = v_sig;
        CALL sp_cs_recalcular(v_pago);
    END LOOP cada_cs;

    UPDATE recibos
       SET estado = 'ANULADO', motivo_anulacion = p_motivo,
           anulado_por = p_id_usuario, anulado_el = NOW()
     WHERE id_recibo = p_id_recibo;

    CALL sp_fondos_recibo(p_id_recibo);

    COMMIT;
END $$

DELIMITER ;
