-- =====================================================================
--  FINANCIERA - Recibos: un recibo puede cubrir varios préstamos del
--  cliente, y la emisión de recibos desde el extracto por CUIL
--
--  Correr DESPUÉS de financiera_extractos.sql, financiera_ordenes_pago.sql
--  y financiera_recibos_talonario.sql, con la base de la financiera
--  seleccionada. MySQL 8. Se puede correr más de una vez. En DBeaver:
--  Ejecutar script (Alt+X).
--
--  OJO: redefine sp_fondos_renglon (el de financiera_ordenes_pago.sql, con
--  el origen RECIBO), sp_registrar_pago (el de
--  financiera_recibos_talonario.sql) y sp_anular_pago. Si se vuelve a
--  correr alguno de esos scripts, después hay que correr este.
--
--  EL MODELO (el de la emisión de recibos de planes de gestion_adm)
--  ---------------------------------------------------------------------
--    recibos ......... el comprobante: cliente, número del talonario,
--                      fecha, importe, medio, cuenta de fondos y, si salió
--                      del extracto, el crédito del banco y el CUIL que
--                      pagó. Un recibo tiene uno o más pagos.
--    pagos ........... lo que el recibo aplica a cada préstamo (uno por
--                      préstamo), con su imputación a las cuotas.
--    Libro de fondos . un renglón por recibo (origen RECIBO), no uno por
--                      préstamo: un crédito del banco es un renglón del
--                      libro, que es lo que se concilia. Los pagos de antes
--                      quedan con su renglón COBRANZA (libro_por_pago = 1).
--
--  EMISIÓN DESDE EL EXTRACTO (Cobranzas › Emisión de recibos)
--  ---------------------------------------------------------------------
--    Los créditos del extracto bancario se identifican por el CUIL que
--    traen (o el que se les asigna a mano) y van al cliente con ese CUIT.
--    Un recibo por crédito, aplicado a las cuotas de TODOS los préstamos
--    vigentes del cliente, de la cuota que vence primero a la última, con
--    los punitorios a la fecha del crédito. Lo que supera la deuda queda
--    disponible en el crédito del extracto.
--      cobros_cuil_reglas .. un CUIL que paga por un cliente (el cónyuge,
--                            la empresa): los créditos que vengan con ese
--                            CUIL van solos a ese cliente.
--      extractos_cliente ... el cliente asignado a mano a un crédito.
--
--  PROCEDURES
--  ---------------------------------------------------------------------
--    sp_pago_aplicar      núcleo: aplica un importe a un préstamo dentro
--                         de un recibo. Sin transacción propia.
--    sp_pago_desaplicar   núcleo de la anulación de un pago. Sin
--                         transacción propia.
--    sp_registrar_pago    cobro de un préstamo (Cobrar, Importar lote):
--                         recibo con un pago. Misma firma que antes.
--    sp_emitir_recibo     recibo de un cliente sobre todos sus préstamos,
--                         desde un crédito del extracto o no.
--    sp_anular_recibo     anula el recibo entero (todos sus pagos).
--    sp_anular_pago       anula el recibo del pago (compatibilidad).
--    sp_fondos_recibo     el renglón del recibo en el libro de fondos.
--  Todos los que abren transacción corren en READ COMMITTED (ver
--  financiera_recibos_talonario.sql) y bloquean primero los préstamos, en
--  orden de id, y después el talonario: así un cobro y un recibo del
--  mismo cliente no se traban entre sí.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. Lo que tiene que estar antes
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_recibos_requisitos $$
CREATE PROCEDURE tmp_recibos_requisitos()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'id_talonario') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta financiera_recibos_talonario.sql: corrélo antes que este';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extractos_bancarios') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta financiera_extractos.sql: corrélo antes que este';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'id_pago_emitido') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta financiera_ordenes_pago.sql: corrélo antes que este';
    END IF;
END $$
DELIMITER ;
CALL tmp_recibos_requisitos();
DROP PROCEDURE IF EXISTS tmp_recibos_requisitos;

-- ---------------------------------------------------------------------
-- 1. Recibos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recibos (
    id_recibo         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nro_recibo        VARCHAR(20)   NULL     COMMENT 'X 00001-00000123 (del talonario) o R-00000123',
    id_talonario      INT UNSIGNED  NULL,
    recibo_numero     INT UNSIGNED  NULL     COMMENT 'Número en su talonario',
    id_cliente        INT UNSIGNED  NOT NULL,
    fecha             DATE          NOT NULL,
    importe           DECIMAL(15,2) NOT NULL COMMENT 'La suma de sus pagos',
    medio             ENUM('EFECTIVO','TRANSFERENCIA','DEBITO','RAPIPAGO','PAGOFACIL','MERCADOPAGO','CHEQUE','OTRO') NOT NULL,
    referencia        VARCHAR(100)  NULL,
    id_cuenta_fondos  INT UNSIGNED  NULL     COMMENT 'Donde entró la plata',
    id_lote           INT UNSIGNED  NULL,
    id_extracto       INT UNSIGNED  NULL     COMMENT 'El crédito del banco del que salió (Emisión de recibos)',
    cuil_pagador      CHAR(11)      NULL     COMMENT 'El CUIL que trajo el crédito del extracto',
    libro_por_pago    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1: recibo de antes, con un renglón por pago en el libro de fondos (COBRANZA)',
    estado            ENUM('APLICADO','ANULADO') NOT NULL DEFAULT 'APLICADO',
    motivo_anulacion  VARCHAR(200)  NULL,
    anulado_por       INT UNSIGNED  NULL,
    anulado_el        DATETIME      NULL,
    creado_por        INT UNSIGNED  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_recibo),
    UNIQUE KEY uq_recibos_nro (nro_recibo),
    UNIQUE KEY uq_recibos_talonario_numero (id_talonario, recibo_numero),
    KEY ix_recibos_cliente_fecha (id_cliente, fecha),
    KEY ix_recibos_fecha (fecha),
    KEY ix_recibos_extracto (id_extracto),
    CONSTRAINT fk_rec_cliente   FOREIGN KEY (id_cliente)       REFERENCES clientes (id_cliente),
    CONSTRAINT fk_rec_talonario FOREIGN KEY (id_talonario)     REFERENCES talonarios (id_talonario),
    CONSTRAINT fk_rec_cuenta    FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT fk_rec_lote      FOREIGN KEY (id_lote)          REFERENCES lotes_cobranza (id_lote),
    CONSTRAINT fk_rec_extracto  FOREIGN KEY (id_extracto)      REFERENCES extractos_bancarios (id_extracto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Recibos de cobranza: uno o más pagos (uno por préstamo)';

-- Un CUIL que paga por un cliente: los créditos del extracto con ese CUIL
-- van solos a ese cliente.
CREATE TABLE IF NOT EXISTS cobros_cuil_reglas (
    cuil_origen  CHAR(11)     NOT NULL COMMENT 'El CUIL que trae el extracto',
    id_cliente   INT UNSIGNED NOT NULL,
    creado_por   INT UNSIGNED NULL,
    creado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cuil_origen),
    KEY ix_ccr_cliente (id_cliente),
    CONSTRAINT fk_ccr_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CUIL del extracto que paga por un cliente';

-- El cliente asignado a mano a un crédito. id_cliente NULL: se anuló la
-- regla para este crédito y vale lo que dice el extracto.
CREATE TABLE IF NOT EXISTS extractos_cliente (
    id_extracto  INT UNSIGNED NOT NULL,
    id_cliente   INT UNSIGNED NULL,
    creado_por   INT UNSIGNED NULL,
    creado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_extracto),
    KEY ix_exc_cliente (id_cliente),
    CONSTRAINT fk_exc_extracto FOREIGN KEY (id_extracto) REFERENCES extractos_bancarios (id_extracto),
    CONSTRAINT fk_exc_cliente  FOREIGN KEY (id_cliente)  REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cliente asignado a mano a un crédito del extracto';

-- ---------------------------------------------------------------------
-- 2. pagos.id_recibo. Los pagos de antes pasan a tener su recibo (uno por
--    pago, con el mismo número). El número es único en recibos: los pagos
--    de un recibo de varios préstamos lo comparten.
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_recibos_pagos $$
CREATE PROCEDURE tmp_recibos_pagos()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'id_recibo') THEN
        ALTER TABLE pagos
          ADD COLUMN id_recibo INT UNSIGNED NULL COMMENT 'El recibo del que es parte' AFTER id_prestamo,
          ADD KEY ix_pagos_recibo (id_recibo),
          ADD CONSTRAINT fk_pagos_recibo FOREIGN KEY (id_recibo) REFERENCES recibos (id_recibo);
    END IF;

    -- Los de antes: un recibo por pago. Todavía con el número único en
    -- pagos, así se cruzan por el número.
    IF EXISTS (SELECT 1 FROM pagos WHERE id_recibo IS NULL) THEN
        UPDATE pagos SET nro_recibo = CONCAT('R-', LPAD(id_pago, 8, '0')) WHERE nro_recibo IS NULL;

        INSERT INTO recibos (nro_recibo, id_talonario, recibo_numero, id_cliente, fecha, importe, medio, referencia,
                             id_cuenta_fondos, id_lote, libro_por_pago, estado, motivo_anulacion, anulado_por, anulado_el,
                             creado_por, creado_el)
        SELECT g.nro_recibo, g.id_talonario, g.recibo_numero, p.id_cliente, g.fecha, g.importe, g.medio, g.referencia,
               g.id_cuenta_fondos, g.id_lote, 1, g.estado, g.motivo_anulacion, g.anulado_por, g.anulado_el,
               g.creado_por, g.creado_el
          FROM pagos g
          JOIN prestamos p ON p.id_prestamo = g.id_prestamo
         WHERE g.id_recibo IS NULL
         ORDER BY g.id_pago;

        UPDATE pagos g
          JOIN recibos r ON r.nro_recibo = g.nro_recibo
           SET g.id_recibo = r.id_recibo
         WHERE g.id_recibo IS NULL;

        -- Los recibos sin talonario se numeran R- + id del recibo, y los de
        -- antes R- + id del pago: los ids de recibo arrancan después del
        -- último pago, así no se repite un número.
        SET @tmp_rec_ai = (SELECT COALESCE(MAX(id_pago), 0) + 1 FROM pagos);
        SET @tmp_rec_sql = CONCAT('ALTER TABLE recibos AUTO_INCREMENT = ', @tmp_rec_ai);
        PREPARE tmp_rec_st FROM @tmp_rec_sql;
        EXECUTE tmp_rec_st;
        DEALLOCATE PREPARE tmp_rec_st;
    END IF;

    -- El número deja de ser único en pagos (lo es en recibos).
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND INDEX_NAME = 'uq_pagos_recibo') THEN
        ALTER TABLE pagos DROP INDEX uq_pagos_recibo, ADD KEY ix_pagos_nro_recibo (nro_recibo);
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND INDEX_NAME = 'uq_pagos_talonario_numero') THEN
        ALTER TABLE pagos ADD KEY ix_pagos_talonario (id_talonario, recibo_numero);
        ALTER TABLE pagos DROP INDEX uq_pagos_talonario_numero;
    END IF;
END $$
DELIMITER ;
CALL tmp_recibos_pagos();
DROP PROCEDURE IF EXISTS tmp_recibos_pagos;

-- ---------------------------------------------------------------------
-- 3. Libro de fondos: el origen RECIBO (un renglón por recibo)
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_recibos_libro $$
CREATE PROCEDURE tmp_recibos_libro()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'id_recibo') THEN
        ALTER TABLE movimientos_fondos
          ADD COLUMN id_recibo INT UNSIGNED NULL COMMENT 'RECIBO (recibos)' AFTER id_pago_emitido;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'tipo_origen'
                      AND GENERATION_EXPRESSION LIKE '%id_recibo%') THEN
        IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND CONSTRAINT_NAME = 'ck_mf_un_origen') THEN
            ALTER TABLE movimientos_fondos DROP CHECK ck_mf_un_origen;
        END IF;
        ALTER TABLE movimientos_fondos
          MODIFY tipo_origen VARCHAR(20) GENERATED ALWAYS AS (
              CASE
                WHEN id_pago            IS NOT NULL THEN 'COBRANZA'
                WHEN id_prestamo        IS NOT NULL THEN 'DESEMBOLSO'
                WHEN id_inversion       IS NOT NULL THEN 'APORTE'
                WHEN id_liquidacion_inv IS NOT NULL THEN 'PAGO_INVERSOR'
                WHEN id_mov_cuentas     IS NOT NULL THEN 'MOVIMIENTO'
                WHEN id_pago_emitido    IS NOT NULL THEN 'PAGO_OP'
                WHEN id_recibo          IS NOT NULL THEN 'RECIBO'
              END) STORED,
          MODIFY id_origen INT UNSIGNED GENERATED ALWAYS AS (
              COALESCE(id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido, id_recibo)) STORED,
          ADD CONSTRAINT ck_mf_un_origen CHECK (
              (id_pago IS NOT NULL) + (id_prestamo IS NOT NULL) + (id_inversion IS NOT NULL)
            + (id_liquidacion_inv IS NOT NULL) + (id_mov_cuentas IS NOT NULL) + (id_pago_emitido IS NOT NULL)
            + (id_recibo IS NOT NULL) = 1);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND INDEX_NAME = 'ix_mf_recibo') THEN
        ALTER TABLE movimientos_fondos ADD KEY ix_mf_recibo (id_recibo);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND CONSTRAINT_NAME = 'fk_mf_recibo') THEN
        ALTER TABLE movimientos_fondos
          ADD CONSTRAINT fk_mf_recibo FOREIGN KEY (id_recibo) REFERENCES recibos (id_recibo);
    END IF;
END $$
DELIMITER ;
CALL tmp_recibos_libro();
DROP PROCEDURE IF EXISTS tmp_recibos_libro;

-- ---------------------------------------------------------------------
-- 4. Procedures
-- ---------------------------------------------------------------------
DELIMITER $$

-- sp_fondos_renglon: el de financiera_ordenes_pago.sql, con el origen RECIBO.
DROP PROCEDURE IF EXISTS sp_fondos_renglon $$
CREATE PROCEDURE sp_fondos_renglon(
    IN  p_origen   VARCHAR(20),
    IN  p_id       INT UNSIGNED,
    IN  p_cuenta   INT UNSIGNED,
    IN  p_fecha    DATE,
    IN  p_debe     DECIMAL(15,2),
    IN  p_haber    DECIMAL(15,2),
    IN  p_concepto VARCHAR(255),
    OUT p_id_mov   INT UNSIGNED
)
BEGIN
    SET p_id_mov = NULL;
    SET p_debe  = ROUND(COALESCE(p_debe, 0), 2);
    SET p_haber = ROUND(COALESCE(p_haber, 0), 2);

    IF p_origen NOT IN ('COBRANZA', 'DESEMBOLSO', 'APORTE', 'PAGO_INVERSOR', 'MOVIMIENTO', 'PAGO_OP', 'RECIBO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Origen de movimiento de fondos desconocido';
    END IF;
    IF p_debe < 0 OR p_haber < 0 OR (p_debe > 0 AND p_haber > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un renglón del libro de fondos es una entrada o una salida, no las dos';
    END IF;

    IF p_cuenta IS NOT NULL AND (p_debe > 0 OR p_haber > 0) THEN
        INSERT INTO movimientos_fondos (id_cuenta_fondos, fecha, importe_debe, importe_haber, concepto,
                                        id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido,
                                        id_recibo)
        VALUES (p_cuenta, p_fecha, p_debe, p_haber, LEFT(p_concepto, 200),
                IF(p_origen = 'COBRANZA',      p_id, NULL),
                IF(p_origen = 'DESEMBOLSO',    p_id, NULL),
                IF(p_origen = 'APORTE',        p_id, NULL),
                IF(p_origen = 'PAGO_INVERSOR', p_id, NULL),
                IF(p_origen = 'MOVIMIENTO',    p_id, NULL),
                IF(p_origen = 'PAGO_OP',       p_id, NULL),
                IF(p_origen = 'RECIBO',        p_id, NULL))
        ON DUPLICATE KEY UPDATE
                id_mov_fondos = LAST_INSERT_ID(id_mov_fondos),
                fecha         = p_fecha,
                importe_debe  = p_debe,
                importe_haber = p_haber,
                concepto      = LEFT(p_concepto, 200);
        SET p_id_mov = LAST_INSERT_ID();
    END IF;
END $$

-- RECIBO: entra el importe del recibo a su cuenta, en un solo renglón
-- aunque cubra varios préstamos. Los recibos de antes (libro_por_pago)
-- tienen un renglón por pago: esos los lleva sp_pago_desaplicar.
-- Sin transacción propia.
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
                                           WHERE g.id_recibo = r.id_recibo)), ''))
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
-- sp_pago_aplicar: aplica un importe a un préstamo, como parte del
-- recibo p_id_recibo (que ya existe y tiene su número). Devenga los
-- punitorios a la fecha, imputa de la cuota más vieja a la más nueva y
-- actualiza los estados. No toca el libro de fondos (es del recibo).
-- Sin transacción propia: la abre quien llama, con el préstamo bloqueado.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_pago_aplicar $$
CREATE PROCEDURE sp_pago_aplicar(
    IN  p_id_prestamo INT UNSIGNED,
    IN  p_fecha       DATE,
    IN  p_importe     DECIMAL(15,2),
    IN  p_medio       VARCHAR(20),
    IN  p_referencia  VARCHAR(100),
    IN  p_modo        VARCHAR(20),
    IN  p_id_lote     INT UNSIGNED,
    IN  p_id_usuario  INT UNSIGNED,
    IN  p_id_cuenta   INT UNSIGNED,
    IN  p_id_recibo   INT UNSIGNED,
    OUT p_id_pago     INT UNSIGNED
)
BEGIN
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_fliq     DATE;
    DECLARE v_nro      VARCHAR(20);
    DECLARE v_total    DECIMAL(15,2);
    DECLARE v_resto    DECIMAL(15,2);
    DECLARE v_aplicar  DECIMAL(15,2);
    DECLARE v_id_cuota INT UNSIGNED;
    DECLARE v_concepto VARCHAR(20);
    DECLARE v_pend     DECIMAL(15,2);
    DECLARE v_fin      TINYINT DEFAULT 0;
    DECLARE v_msg      VARCHAR(255);

    DECLARE cur CURSOR FOR
        SELECT id_cuota, concepto, pendiente
          FROM tmp_deuda
         ORDER BY nro_cuota, orden;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_modo  = COALESCE(p_modo, 'NORMAL');

    IF p_importe IS NULL OR p_importe <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El importe debe ser mayor a cero';
    END IF;

    SELECT estado, fecha_liquidacion, nro_prestamo INTO v_estado, v_fliq, v_nro
      FROM prestamos WHERE id_prestamo = p_id_prestamo
       FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('El préstamo ', v_nro, ' no está vigente (', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_fecha < v_fliq THEN
        SET v_msg = CONCAT('La fecha del pago es anterior a la liquidación del préstamo ', v_nro);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = p_id_prestamo
                AND estado = 'APLICADO' AND fecha > p_fecha) THEN
        SET v_msg = LEFT(CONCAT('El préstamo ', v_nro, ' tiene pagos con fecha posterior: no se puede imputar con fecha anterior'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
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

    -- El pago lleva el número de su recibo (lo comparte con los otros
    -- préstamos del mismo recibo).
    INSERT INTO pagos (id_prestamo, id_recibo, nro_recibo, id_talonario, recibo_numero, fecha, importe, medio, referencia,
                       id_cuenta_fondos, modo, id_lote, creado_por)
    SELECT p_id_prestamo, r.id_recibo, r.nro_recibo, r.id_talonario, r.recibo_numero, p_fecha, p_importe, p_medio, p_referencia,
           p_id_cuenta, p_modo, p_id_lote, p_id_usuario
      FROM recibos r WHERE r.id_recibo = p_id_recibo;
    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recibo inexistente';
    END IF;
    SET p_id_pago = LAST_INSERT_ID();

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
    SET v_fin = 0;
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
END $$

-- ---------------------------------------------------------------------
-- sp_pago_desaplicar: anula un pago (lo que aplicó a su préstamo). Tiene
-- que ser el último pago aplicado del préstamo. Si el pago es de antes
-- (renglón COBRANZA en el libro), anula ese renglón. Sin transacción
-- propia: la abre sp_anular_recibo, con el préstamo bloqueado.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_pago_desaplicar $$
CREATE PROCEDURE sp_pago_desaplicar(
    IN p_id_pago    INT UNSIGNED,
    IN p_motivo     VARCHAR(200),
    IN p_id_usuario INT UNSIGNED
)
BEGIN
    DECLARE v_prestamo INT UNSIGNED;
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_fecha    DATE;
    DECLARE v_ultimo   INT UNSIGNED;
    DECLARE v_nro      VARCHAR(20);
    DECLARE v_otro     VARCHAR(20);
    DECLARE v_msg      VARCHAR(255);

    SELECT id_prestamo, estado, importe, fecha
      INTO v_prestamo, v_estado, v_importe, v_fecha
      FROM pagos WHERE id_pago = p_id_pago;

    IF v_prestamo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pago inexistente';
    END IF;

    SELECT nro_prestamo INTO v_nro
      FROM prestamos WHERE id_prestamo = v_prestamo FOR UPDATE;

    IF v_estado <> 'APLICADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya está anulado';
    END IF;

    SELECT id_pago, nro_recibo INTO v_ultimo, v_otro
      FROM pagos
     WHERE id_prestamo = v_prestamo AND estado = 'APLICADO'
     ORDER BY fecha DESC, id_pago DESC
     LIMIT 1;

    IF v_ultimo <> p_id_pago THEN
        SET v_msg = LEFT(CONCAT('El préstamo ', v_nro, ' tiene un pago posterior (recibo ', COALESCE(v_otro, v_ultimo),
                                '): hay que anular ese primero'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    UPDATE pagos
       SET estado = 'ANULADO', motivo_anulacion = p_motivo,
           anulado_por = p_id_usuario, anulado_el = NOW()
     WHERE id_pago = p_id_pago;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT id_prestamo, id_cuota, id_pago, v_fecha, tipo, -importe,
           'Reversa por anulación de pago', p_id_usuario
      FROM movimientos
     WHERE id_pago = p_id_pago AND tipo IN ('QUITA_INTERES', 'QUITA_IVA', 'CREDITO_CAPITAL');

    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (v_prestamo, p_id_pago, v_fecha, 'ANULACION_PAGO', v_importe, p_motivo, p_id_usuario);

    CALL sp_actualizar_estados(v_prestamo);

    -- Los pagos de antes tienen su propio renglón en el libro.
    CALL sp_fondos_anular('COBRANZA', p_id_pago, p_motivo, p_id_usuario);
END $$

-- ---------------------------------------------------------------------
-- sp_registrar_pago: el cobro de un préstamo (Cobrar, Importar lote).
-- Misma firma que en financiera_recibos_talonario.sql: ahora arma un
-- recibo con un pago, y el libro de fondos lleva el renglón del recibo.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_registrar_pago $$
CREATE PROCEDURE sp_registrar_pago(
    IN  p_id_prestamo  INT UNSIGNED,
    IN  p_fecha        DATE,
    IN  p_importe      DECIMAL(15,2),
    IN  p_medio        VARCHAR(20),
    IN  p_referencia   VARCHAR(100),
    IN  p_modo         VARCHAR(20),
    IN  p_id_lote      INT UNSIGNED,
    IN  p_id_usuario   INT UNSIGNED,
    IN  p_id_cuenta    INT UNSIGNED,
    IN  p_id_talonario INT UNSIGNED,
    OUT p_id_pago      INT UNSIGNED
)
BEGIN
    DECLARE v_cliente  INT UNSIGNED;
    DECLARE v_id_rec   INT UNSIGNED;
    DECLARE v_tal_n    INT DEFAULT 0;
    DECLARE v_tal_ok   INT DEFAULT 0;
    DECLARE v_numero   INT UNSIGNED;
    DECLARE v_msg      VARCHAR(255);

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
    -- recibos habilitado. Sin ninguno, el recibo sale R-00000123.
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

    -- Primero el préstamo y después el talonario (el mismo orden que la
    -- emisión de recibos).
    SELECT id_cliente INTO v_cliente
      FROM prestamos WHERE id_prestamo = p_id_prestamo
       FOR UPDATE;
    IF v_cliente IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;

    INSERT INTO recibos (id_cliente, fecha, importe, medio, referencia, id_cuenta_fondos, id_lote, creado_por)
    VALUES (v_cliente, p_fecha, p_importe, p_medio, p_referencia, p_id_cuenta, p_id_lote, p_id_usuario);
    SET v_id_rec = LAST_INSERT_ID();

    -- El número del recibo: el próximo del talonario, reservado con bloqueo
    -- en esta transacción. Un recibo anulado conserva su número.
    IF p_id_talonario IS NULL THEN
        UPDATE recibos SET nro_recibo = CONCAT('R-', LPAD(v_id_rec, 8, '0')) WHERE id_recibo = v_id_rec;
    ELSE
        CALL sp_talonario_numero(p_id_talonario, v_numero);
        UPDATE recibos r
          JOIN talonarios t ON t.id_talonario = p_id_talonario
           SET r.id_talonario  = p_id_talonario,
               r.recibo_numero = v_numero,
               r.nro_recibo    = CONCAT(t.letra, ' ', LPAD(t.punto_venta, 5, '0'), '-', LPAD(v_numero, 8, '0'))
         WHERE r.id_recibo = v_id_rec;
    END IF;

    CALL sp_pago_aplicar(p_id_prestamo, p_fecha, p_importe, p_medio, p_referencia, p_modo, p_id_lote,
                         p_id_usuario, p_id_cuenta, v_id_rec, p_id_pago);

    CALL sp_fondos_recibo(v_id_rec);

    COMMIT;

    SELECT pg.nro_recibo, c.nro_cuota, c.vencimiento, pi.concepto, pi.importe
      FROM pagos_imputacion pi
      JOIN pagos  pg ON pg.id_pago  = pi.id_pago
      JOIN cuotas c  ON c.id_cuota  = pi.id_cuota
      JOIN imputacion_orden o ON o.concepto = pi.concepto
     WHERE pi.id_pago = p_id_pago
     ORDER BY c.nro_cuota, o.orden;
END $$

-- ---------------------------------------------------------------------
-- sp_emitir_recibo: un recibo del cliente aplicado a las cuotas de todos
-- sus préstamos vigentes, de la que vence primero a la última (entre
-- préstamos), con los punitorios a p_fecha.
--   p_id_extracto  el crédito del banco del que sale (o NULL). Con
--                  crédito: el recibo es por lo que se debe, hasta lo
--                  que queda disponible del crédito, y lo que sobra queda
--                  disponible. Sin crédito, más que la deuda es error.
--   p_id_cuenta    NULL: la del crédito del extracto.
--   p_id_talonario como en sp_registrar_pago.
-- Los préstamos liquidados después de p_fecha no entran.
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
    DECLARE v_prest    INT UNSIGNED;
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
        SELECT id_prestamo, pendiente
          FROM tmp_rec_deuda
         ORDER BY vencimiento, id_prestamo, nro_cuota, orden;
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

    -- Los préstamos vigentes del cliente, bloqueados en orden de id.
    SELECT COUNT(*) INTO v_n
      FROM prestamos
     WHERE id_cliente = p_id_cliente AND estado = 'VIGENTE'
       FOR UPDATE;
    IF v_n = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no tiene préstamos vigentes';
    END IF;

    -- La deuda de cada uno a la fecha (con los punitorios devengados a ese
    -- día), en una sola tabla para repartir el importe.
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_deuda;
    CREATE TEMPORARY TABLE tmp_rec_deuda (
        id_prestamo INT UNSIGNED,
        id_cuota    INT UNSIGNED,
        nro_cuota   SMALLINT UNSIGNED,
        vencimiento DATE,
        concepto    VARCHAR(20),
        orden       TINYINT UNSIGNED,
        pendiente   DECIMAL(15,2),
        PRIMARY KEY (id_cuota, concepto)
    );
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_monto;
    CREATE TEMPORARY TABLE tmp_rec_monto (
        id_prestamo INT UNSIGNED PRIMARY KEY,
        monto       DECIMAL(15,2)
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
        INSERT INTO tmp_rec_deuda
        SELECT v_prest, id_cuota, nro_cuota, vencimiento, concepto, orden, pendiente
          FROM tmp_deuda WHERE pendiente > 0;
    END LOOP cada_prestamo;

    SELECT COALESCE(SUM(pendiente), 0) INTO v_total FROM tmp_rec_deuda;
    IF v_total <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no debe nada a esa fecha';
    END IF;
    IF p_id_extracto IS NOT NULL THEN
        SET p_importe = LEAST(p_importe, v_total);
    ELSEIF p_importe > v_total THEN
        SET v_msg = CONCAT('El importe supera la deuda de los préstamos del cliente (', v_total, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    -- El reparto: de la cuota que vence primero a la última, entre todos
    -- los préstamos. Cada préstamo recibe un tramo de su propia lista, así
    -- que su imputación (de la cuota más vieja a la más nueva) es la misma.
    SET v_resto = p_importe;
    SET v_fin = 0;
    OPEN cur;
    reparto: LOOP
        FETCH cur INTO v_prest, v_pend;
        IF v_fin = 1 OR v_resto <= 0 THEN LEAVE reparto; END IF;
        SET v_aplicar = LEAST(v_resto, v_pend);
        INSERT INTO tmp_rec_monto (id_prestamo, monto) VALUES (v_prest, v_aplicar)
            ON DUPLICATE KEY UPDATE monto = monto + v_aplicar;
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

    CALL sp_fondos_recibo(p_id_recibo);

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS tmp_rec_deuda;
    DROP TEMPORARY TABLE IF EXISTS tmp_rec_monto;

    SELECT r.id_recibo, r.nro_recibo, r.importe, g.id_pago, p.nro_prestamo, g.importe AS aplicado
      FROM recibos r
      JOIN pagos g ON g.id_recibo = r.id_recibo
      JOIN prestamos p ON p.id_prestamo = g.id_prestamo
     WHERE r.id_recibo = p_id_recibo
     ORDER BY p.id_prestamo;
END $$

-- ---------------------------------------------------------------------
-- sp_anular_recibo: anula el recibo entero. Cada pago tiene que ser el
-- último aplicado de su préstamo. La deuda vuelve a como estaba (los
-- punitorios ya devengados se mantienen), el renglón del libro se anula
-- y, si salió de un crédito del extracto, el crédito vuelve a quedar
-- disponible. El número no se vuelve a usar.
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

    UPDATE recibos
       SET estado = 'ANULADO', motivo_anulacion = p_motivo,
           anulado_por = p_id_usuario, anulado_el = NOW()
     WHERE id_recibo = p_id_recibo;

    CALL sp_fondos_recibo(p_id_recibo);

    COMMIT;
END $$

-- sp_anular_pago: como antes, para quien lo llame por el pago. Anula el
-- recibo del pago entero (con los demás préstamos que cubra).
DROP PROCEDURE IF EXISTS sp_anular_pago $$
CREATE PROCEDURE sp_anular_pago(
    IN p_id_pago    INT UNSIGNED,
    IN p_motivo     VARCHAR(200),
    IN p_id_usuario INT UNSIGNED
)
BEGIN
    DECLARE v_recibo INT UNSIGNED;

    SELECT id_recibo INTO v_recibo FROM pagos WHERE id_pago = p_id_pago;
    IF v_recibo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pago inexistente';
    END IF;
    CALL sp_anular_recibo(v_recibo, p_motivo, p_id_usuario);
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 5. Menú: Cobranzas › Emisión de recibos (los mismos que Cobrar)
-- ---------------------------------------------------------------------
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (24, 20, 'Emisión de recibos', 'emision_recibos.php', NULL, 4, 2, 1)
ON DUPLICATE KEY UPDATE padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url), orden = VALUES(orden), nivel = VALUES(nivel);
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT 24, id_rol FROM menu_roles WHERE id_menu = 21;

-- ---------------------------------------------------------------------
-- 6. Controles (tienen que dar cero filas)
-- ---------------------------------------------------------------------
-- 6.1 Pagos sin recibo
SELECT 'pago sin recibo' AS control, id_pago FROM pagos WHERE id_recibo IS NULL;

-- 6.2 Recibos cuyo importe no es la suma de sus pagos
SELECT 'recibo que no suma sus pagos' AS control, r.id_recibo, r.nro_recibo, r.importe, SUM(g.importe) AS pagos
  FROM recibos r JOIN pagos g ON g.id_recibo = r.id_recibo
 GROUP BY r.id_recibo, r.nro_recibo, r.importe
HAVING r.importe <> SUM(g.importe);

-- 6.3 Pagos con otro número o estado que su recibo
SELECT 'pago distinto de su recibo' AS control, g.id_pago, g.nro_recibo, r.nro_recibo AS del_recibo, g.estado, r.estado AS estado_recibo
  FROM pagos g JOIN recibos r ON r.id_recibo = g.id_recibo
 WHERE NOT (g.nro_recibo <=> r.nro_recibo) OR g.estado <> r.estado;

-- 6.4 Créditos del extracto con recibos por más de lo que acreditó el banco
SELECT 'crédito cubierto de más' AS control, e.id_extracto, e.credito, SUM(r.importe) AS recibos
  FROM extractos_bancarios e JOIN recibos r ON r.id_extracto = e.id_extracto AND r.estado = 'APLICADO'
 GROUP BY e.id_extracto, e.credito
HAVING SUM(r.importe) > e.credito;

-- 6.5 Recibos nuevos con cuenta y sin su renglón en el libro
SELECT 'recibo sin renglón en el libro' AS control, r.id_recibo, r.nro_recibo
  FROM recibos r
 WHERE r.libro_por_pago = 0 AND r.id_cuenta_fondos IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.tipo_origen = 'RECIBO' AND m.id_origen = r.id_recibo);
