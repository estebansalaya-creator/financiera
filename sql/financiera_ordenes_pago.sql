-- =====================================================================
--  FINANCIERA - Órdenes de pago y transferencias masivas
--
--  Correr DESPUÉS de financiera_fondos.sql, financiera_datos_bancarios.sql y
--  financiera_numeradores.sql, con la base de la financiera seleccionada.
--  MySQL 8.0.19 o superior.
--  Se puede correr más de una vez: las tablas no se recrean; los procedures
--  sí. En DBeaver: Ejecutar script (Alt+X).
--
--  OJO: redefine sp_fondos_renglon, sp_liquidar_prestamo y
--  sp_anular_prestamo (los de financiera_fondos.sql). Si se vuelve a correr
--  financiera_fondos.sql, después hay que correr este de nuevo.
--
--  EL MODELO (el de gestion_adm: pago_ordenes, pago_pagos y
--  pago_transferencias_lote)
--  ---------------------------------------------------------------------
--    préstamo liquidado
--      └─> ordenes_pago            2026-00000001: al cliente, por el neto. Nace
--                                  APROBADA: el préstamo ya lo autorizó
--                                  Gerencia en la solicitud.
--            └─> pagos_emitidos    2026-00000001: cuenta, medio (transferencia,
--                                  cheque o efectivo) y CBU destino.
--                  ├─> movimientos_fondos (origen PAGO_OP)
--                  │     └─> debitos_creditos_bancarios
--                  └─> transferencias_lotes_det
--                        └─> transferencias_lotes   el archivo Interbanking
--
--  - La plata sale en el libro de Tesorería cuando se emite el pago, no al
--    liquidar. Liquidar ya no pide cuenta ni medio.
--  - Una orden tiene a lo sumo un pago vigente. Anular el pago la vuelve a
--    «a pagar» y saca su renglón del libro (no si el banco ya lo debitó).
--  - Un pago que salió en un archivo de transferencias no se anula: antes
--    se lo saca del archivo, o se anula el archivo entero, si no se mandó al
--    banco. Un archivo anulado deja de contar.
--  - El archivo no se guarda: se vuelve a armar desde el detalle del lote.
--    Lo que se guarda es el hecho de haberlo generado, con el CBU que se usó
--    en cada transferencia (si después el cliente cambia su CBU, el lote
--    sigue diciendo a dónde fue la plata).
--  - Préstamos liquidados antes de esto: su desembolso ya está en el libro
--    (origen DESEMBOLSO) y no tienen orden de pago.
--
--  NÚMEROS: año y número, 2026-00000001. Las órdenes y los pagos tienen
--  cada uno su serie, que vuelve a 1 cada año (el de la fecha de la orden
--  o del pago). Salen de sp_numerador (financiera_numeradores.sql), con
--  bloqueo dentro de la transacción: dos a la vez no sacan el mismo.
--
--  DOS A LA VEZ: cada operación bloquea primero su registro principal
--  (SELECT … FOR UPDATE) y recién después controla:
--    emitir el pago ........ la orden
--    anular el pago ........ el pago
--    anular el préstamo .... el préstamo y su orden (así no se cruza con
--                            el pago que se está emitiendo)
--    generar el archivo .... los pagos elegidos (inc\ordenes_pago.php)
--    sacar / anular ........ el archivo
--  Lo que se controla después de esperar el bloqueo se lee con FOR SHARE
--  (lo último grabado), no con la foto que la transacción tomó antes.
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
-- 1. Órdenes de pago
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ordenes_pago (
    id_orden          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nro_orden         VARCHAR(20)   NOT NULL COMMENT '2026-00000001: año y número (vuelve a 1 cada año)',
    clase             VARCHAR(20)   NOT NULL DEFAULT 'PRESTAMO' COMMENT 'PRESTAMO: el desembolso de un préstamo',
    id_prestamo       INT UNSIGNED  NULL,
    fecha             DATE          NOT NULL,
    beneficiario      VARCHAR(150)  NOT NULL COMMENT 'Al crearla (la ficha puede cambiar después)',
    cuit              CHAR(11)      NULL,
    concepto          VARCHAR(200)  NULL,
    importe           DECIMAL(15,2) NOT NULL,
    moneda            CHAR(3)       NOT NULL DEFAULT 'ARS',
    estado            ENUM('APROBADA','PAGADA','ANULADA') NOT NULL DEFAULT 'APROBADA'
                                    COMMENT 'APROBADA: a pagar',
    anulada_el        DATETIME      NULL,
    anulada_por       INT UNSIGNED  NULL,
    motivo_anulacion  VARCHAR(200)  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por        INT UNSIGNED  NULL,
    PRIMARY KEY (id_orden),
    UNIQUE KEY uq_op_nro (nro_orden),
    UNIQUE KEY uq_op_prestamo (id_prestamo),
    KEY ix_op_estado (estado, fecha),
    CONSTRAINT fk_op_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT ck_op_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Órdenes de pago (por ahora, el desembolso de cada préstamo)';

-- ---------------------------------------------------------------------
-- 2. Pagos emitidos: el pago de una orden (uno vigente por orden)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagos_emitidos (
    id_pago_emitido   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nro_pago          VARCHAR(20)   NOT NULL COMMENT '2026-00000001: año y número (vuelve a 1 cada año)',
    id_orden          INT UNSIGNED  NOT NULL,
    fecha             DATE          NOT NULL,
    importe           DECIMAL(15,2) NOT NULL,
    id_cuenta_fondos  INT UNSIGNED  NOT NULL COMMENT 'De dónde sale',
    medio             ENUM('TRANSFERENCIA','CHEQUE','EFECTIVO') NOT NULL,
    cheque_numero     BIGINT UNSIGNED NULL,
    cbu_destino       VARCHAR(22)   NULL COMMENT 'Transferencia: si se escribió al emitir. Si no, el del beneficiario al armar el archivo',
    alias_destino     VARCHAR(20)   NULL,
    referencia        VARCHAR(100)  NULL COMMENT 'N° de la transferencia hecha a mano (sin archivo)',
    estado            ENUM('EMITIDO','ANULADO') NOT NULL DEFAULT 'EMITIDO',
    anulado_el        DATETIME      NULL,
    anulado_por       INT UNSIGNED  NULL,
    motivo_anulacion  VARCHAR(200)  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por        INT UNSIGNED  NULL,
    PRIMARY KEY (id_pago_emitido),
    UNIQUE KEY uq_pe_nro (nro_pago),
    KEY ix_pe_orden (id_orden),
    KEY ix_pe_cuenta (id_cuenta_fondos, medio, estado),
    CONSTRAINT fk_pe_orden  FOREIGN KEY (id_orden)         REFERENCES ordenes_pago (id_orden),
    CONSTRAINT fk_pe_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT ck_pe_importe CHECK (importe > 0),
    CONSTRAINT ck_pe_cheque  CHECK ((medio = 'CHEQUE' AND cheque_numero IS NOT NULL) OR (medio <> 'CHEQUE' AND cheque_numero IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='El pago de una orden: cuenta, medio y destino';

-- ---------------------------------------------------------------------
-- 3. Archivos de transferencias masivas (lotes)
--    El detalle guarda también los pagos que quedaron afuera (sin CBU), con
--    el motivo: mañana alguien va a preguntar por qué no se le transfirió.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transferencias_lotes (
    id_lote           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    formato           VARCHAR(30)   NOT NULL COMMENT 'INTERBANKING: PIL, importación por CBU',
    nombre_archivo    VARCHAR(160)  NOT NULL,
    fecha_solicitud   DATE          NOT NULL COMMENT 'La del registro *U*',
    id_cuenta_fondos  INT UNSIGNED  NOT NULL COMMENT 'La cuenta que se debita',
    cbu_ordenante     CHAR(22)      NOT NULL,
    observacion       VARCHAR(61)   NULL,
    cantidad          INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Transferencias incluidas',
    importe_total     DECIMAL(15,2) NOT NULL DEFAULT 0,
    excluidas         INT UNSIGNED  NOT NULL DEFAULT 0,
    anulado_el        DATETIME      NULL COMMENT 'No se transmitió: sus pagos quedan libres',
    anulado_por       INT UNSIGNED  NULL,
    motivo_anulacion  VARCHAR(250)  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por        INT UNSIGNED  NULL,
    PRIMARY KEY (id_lote),
    KEY ix_tl_cuenta (id_cuenta_fondos, creado_el),
    CONSTRAINT fk_tl_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Archivos de transferencias masivas generados';

CREATE TABLE IF NOT EXISTS transferencias_lotes_det (
    id_det            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_lote           INT UNSIGNED  NOT NULL,
    id_pago_emitido   INT UNSIGNED  NOT NULL,
    cbu_destino       CHAR(22)      NULL COMMENT 'El que se usó: queda congelado',
    cuit              CHAR(11)      NULL,
    beneficiario      VARCHAR(150)  NULL,
    importe           DECIMAL(15,2) NOT NULL,
    incluida          TINYINT(1)    NOT NULL DEFAULT 1,
    motivo_exclusion  VARCHAR(150)  NULL,
    PRIMARY KEY (id_det),
    KEY ix_tld_lote (id_lote),
    KEY ix_tld_pago (id_pago_emitido, incluida),
    CONSTRAINT fk_tld_lote FOREIGN KEY (id_lote)         REFERENCES transferencias_lotes (id_lote),
    CONSTRAINT fk_tld_pago FOREIGN KEY (id_pago_emitido) REFERENCES pagos_emitidos (id_pago_emitido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cada pago de un archivo: incluido, o excluido con el motivo';

-- ---------------------------------------------------------------------
-- 4. El libro: un origen más, PAGO_OP (el pago de una orden)
--    Columna nueva, el origen generado que la reconoce y el control de
--    «un origen y solo uno» con ella. Se hace una sola vez.
-- ---------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_op_libro $$
CREATE PROCEDURE tmp_op_libro()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'id_pago_emitido') THEN
        ALTER TABLE movimientos_fondos
          ADD COLUMN id_pago_emitido INT UNSIGNED NULL COMMENT 'PAGO_OP (pagos_emitidos)' AFTER id_mov_cuentas;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'tipo_origen'
                      AND GENERATION_EXPRESSION LIKE '%id_pago_emitido%') THEN
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
              END) STORED,
          MODIFY id_origen INT UNSIGNED GENERATED ALWAYS AS (
              COALESCE(id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido)) STORED,
          ADD CONSTRAINT ck_mf_un_origen CHECK (
              (id_pago IS NOT NULL) + (id_prestamo IS NOT NULL) + (id_inversion IS NOT NULL)
            + (id_liquidacion_inv IS NOT NULL) + (id_mov_cuentas IS NOT NULL) + (id_pago_emitido IS NOT NULL) = 1);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND INDEX_NAME = 'ix_mf_pago_op') THEN
        ALTER TABLE movimientos_fondos ADD KEY ix_mf_pago_op (id_pago_emitido);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND CONSTRAINT_NAME = 'fk_mf_pago_op') THEN
        ALTER TABLE movimientos_fondos
          ADD CONSTRAINT fk_mf_pago_op FOREIGN KEY (id_pago_emitido) REFERENCES pagos_emitidos (id_pago_emitido);
    END IF;
END $$

DELIMITER ;

CALL tmp_op_libro();
DROP PROCEDURE IF EXISTS tmp_op_libro;

-- ---------------------------------------------------------------------
-- 5. Procedures
-- ---------------------------------------------------------------------
DELIMITER $$

-- sp_fondos_renglon: el de financiera_fondos.sql, con el origen PAGO_OP.
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

    IF p_origen NOT IN ('COBRANZA', 'DESEMBOLSO', 'APORTE', 'PAGO_INVERSOR', 'MOVIMIENTO', 'PAGO_OP') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Origen de movimiento de fondos desconocido';
    END IF;
    IF p_debe < 0 OR p_haber < 0 OR (p_debe > 0 AND p_haber > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un renglón del libro de fondos es una entrada o una salida, no las dos';
    END IF;

    IF p_cuenta IS NOT NULL AND (p_debe > 0 OR p_haber > 0) THEN
        INSERT INTO movimientos_fondos (id_cuenta_fondos, fecha, importe_debe, importe_haber, concepto,
                                        id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido)
        VALUES (p_cuenta, p_fecha, p_debe, p_haber, LEFT(p_concepto, 200),
                IF(p_origen = 'COBRANZA',      p_id, NULL),
                IF(p_origen = 'DESEMBOLSO',    p_id, NULL),
                IF(p_origen = 'APORTE',        p_id, NULL),
                IF(p_origen = 'PAGO_INVERSOR', p_id, NULL),
                IF(p_origen = 'MOVIMIENTO',    p_id, NULL),
                IF(p_origen = 'PAGO_OP',       p_id, NULL))
        ON DUPLICATE KEY UPDATE
                id_mov_fondos = LAST_INSERT_ID(id_mov_fondos),
                fecha         = p_fecha,
                importe_debe  = p_debe,
                importe_haber = p_haber,
                concepto      = LEFT(p_concepto, 200);
        SET p_id_mov = LAST_INSERT_ID();
    END IF;
END $$

-- La orden de pago del desembolso de un préstamo. Repetible: si ya tiene
-- una, no hace nada. Sin transacción propia: la llama la liquidación.
DROP PROCEDURE IF EXISTS sp_op_desde_prestamo $$
CREATE PROCEDURE sp_op_desde_prestamo(IN p_id_prestamo INT UNSIGNED, IN p_id_usuario INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_neto     DECIMAL(15,2);
    DECLARE v_nro_pre  VARCHAR(20);
    DECLARE v_cliente  VARCHAR(150);
    DECLARE v_cuit     CHAR(11);
    DECLARE v_n        INT UNSIGNED;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    IF EXISTS (SELECT 1 FROM ordenes_pago WHERE id_prestamo = p_id_prestamo) THEN
        LEAVE proc;
    END IF;
    SELECT p.fecha_liquidacion, p.neto_desembolso, p.nro_prestamo, c.denominacion, c.cuit
      INTO v_fecha, v_neto, v_nro_pre, v_cliente, v_cuit
      FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
     WHERE p.id_prestamo = p_id_prestamo;
    IF v_fecha IS NULL OR COALESCE(v_neto, 0) <= 0 THEN
        LEAVE proc;
    END IF;

    CALL sp_numerador(CONCAT('OP-', YEAR(v_fecha)), v_n);
    INSERT INTO ordenes_pago (nro_orden, clase, id_prestamo, fecha, beneficiario, cuit, concepto, importe, creado_por)
    VALUES (CONCAT(YEAR(v_fecha), '-', LPAD(v_n, 8, '0')), 'PRESTAMO', p_id_prestamo, v_fecha, LEFT(v_cliente, 150), v_cuit,
            CONCAT('Desembolso del préstamo ', v_nro_pre), v_neto, p_id_usuario);
END $$

-- PAGO_OP: sale el importe del pago de su cuenta. Repetible, como los demás
-- registradores. Sin transacción propia.
DROP PROCEDURE IF EXISTS sp_fondos_pago_op $$
CREATE PROCEDURE sp_fondos_pago_op(IN p_id_pago INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_cheque   BIGINT UNSIGNED;
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_benef    VARCHAR(150);
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT g.fecha, g.importe, g.id_cuenta_fondos, g.medio, g.cheque_numero, COALESCE(NULLIF(g.referencia, ''), g.nro_pago),
           g.estado, g.motivo_anulacion, g.anulado_por, o.beneficiario,
           CONCAT(IF(o.clase = 'PRESTAMO', CONCAT('Desembolso ', COALESCE(p.nro_prestamo, '')), 'Pago'),
                  ' · ', o.beneficiario, ' · OP ', o.nro_orden, ' · pago ', g.nro_pago)
      INTO v_fecha, v_importe, v_cuenta, v_medio, v_cheque, v_ref, v_estado, v_motivo, v_anul_por, v_benef, v_concepto
      FROM pagos_emitidos g
      JOIN ordenes_pago o ON o.id_orden = g.id_orden
      LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo
     WHERE g.id_pago_emitido = p_id_pago;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    CALL sp_fondos_renglon('PAGO_OP', p_id_pago, v_cuenta, v_fecha, 0, v_importe, v_concepto, v_mf);
    CALL sp_fondos_quitar('PAGO_OP', p_id_pago, v_cuenta, NULL);
    IF v_mf IS NOT NULL THEN
        CALL sp_fondos_banco(v_mf, v_medio, v_cheque, IF(v_medio = 'CHEQUE', NULL, v_ref), v_benef);
    END IF;
    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('PAGO_OP', p_id_pago, COALESCE(v_motivo, 'Pago anulado'), v_anul_por);
    END IF;
END $$

-- Emite el pago de una orden: la orden queda PAGADA y la plata sale en el
-- libro. Transferencia: el CBU destino es opcional (si no se escribe, al
-- armar el archivo se toma el de la ficha del beneficiario).
DROP PROCEDURE IF EXISTS sp_emitir_pago_op $$
CREATE PROCEDURE sp_emitir_pago_op(
    IN  p_id_orden   INT UNSIGNED,
    IN  p_fecha      DATE,
    IN  p_id_cuenta  INT UNSIGNED,
    IN  p_medio      VARCHAR(20),
    IN  p_cheque     BIGINT UNSIGNED,
    IN  p_cbu        VARCHAR(22),
    IN  p_alias      VARCHAR(20),
    IN  p_referencia VARCHAR(100),
    IN  p_id_usuario INT UNSIGNED,
    OUT p_id_pago    INT UNSIGNED
)
BEGIN
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_nro_op   VARCHAR(20);
    DECLARE v_fecha_op DATE;
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_tipo_cta VARCHAR(1);
    DECLARE v_n        INT UNSIGNED;
    DECLARE v_msg      VARCHAR(255);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_medio = UPPER(COALESCE(NULLIF(TRIM(p_medio), ''), 'TRANSFERENCIA'));
    SET p_cbu   = NULLIF(TRIM(p_cbu), '');
    SET p_alias = NULLIF(UPPER(TRIM(p_alias)), '');
    IF p_medio NOT IN ('TRANSFERENCIA', 'CHEQUE', 'EFECTIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago es por transferencia, cheque o efectivo';
    END IF;
    IF p_id_cuenta IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Elegí la cuenta de la que sale el pago';
    END IF;

    START TRANSACTION;

    SELECT estado, nro_orden, fecha, importe, moneda INTO v_estado, v_nro_op, v_fecha_op, v_importe, v_moneda
      FROM ordenes_pago WHERE id_orden = p_id_orden FOR UPDATE;
    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La orden de pago no existe';
    END IF;
    IF v_estado <> 'APROBADA' THEN
        SET v_msg = CONCAT('La orden ', v_nro_op, ' está ', LOWER(v_estado), ': solo se paga una orden a pagar');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_fecha < v_fecha_op THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La fecha del pago no puede ser anterior a la de la orden';
    END IF;

    SET v_msg = LEFT(fn_fondos_cuenta_error(p_id_cuenta, v_moneda), 128);
    IF v_msg IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    SELECT tipo INTO v_tipo_cta FROM cuentas_fondos WHERE id_cuenta_fondos = p_id_cuenta;
    IF p_medio = 'CHEQUE' THEN
        IF v_tipo_cta <> 'B' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un cheque sale de una cuenta bancaria';
        END IF;
        IF p_cheque IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escribí el número del cheque';
        END IF;
    ELSE
        SET p_cheque = NULL;
    END IF;
    IF p_medio = 'TRANSFERENCIA' THEN
        IF v_tipo_cta NOT IN ('B', 'V') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una transferencia sale de un banco o de una billetera virtual';
        END IF;
        IF p_cbu IS NOT NULL AND p_cbu NOT REGEXP '^[0-9]{22}$' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El CBU / CVU destino tiene 22 números';
        END IF;
    ELSE
        SET p_cbu = NULL, p_alias = NULL;
    END IF;
    IF p_medio = 'EFECTIVO' AND v_tipo_cta NOT IN ('C', 'B') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El efectivo sale de una caja (o se extrae de un banco)';
    END IF;

    CALL sp_numerador(CONCAT('PAG-', YEAR(p_fecha)), v_n);
    INSERT INTO pagos_emitidos (nro_pago, id_orden, fecha, importe, id_cuenta_fondos, medio, cheque_numero,
                                cbu_destino, alias_destino, referencia, creado_por)
    VALUES (CONCAT(YEAR(p_fecha), '-', LPAD(v_n, 8, '0')), p_id_orden, p_fecha, v_importe, p_id_cuenta, p_medio, p_cheque,
            p_cbu, p_alias, NULLIF(TRIM(p_referencia), ''), p_id_usuario);
    SET p_id_pago = LAST_INSERT_ID();

    UPDATE ordenes_pago SET estado = 'PAGADA' WHERE id_orden = p_id_orden;
    CALL sp_fondos_pago_op(p_id_pago);

    COMMIT;
END $$

-- Anula un pago: la orden vuelve a «a pagar» y el renglón sale del saldo.
-- No si salió en un archivo de transferencias (antes se lo saca del archivo
-- o se anula el archivo) ni si el banco ya lo debitó.
DROP PROCEDURE IF EXISTS sp_anular_pago_op $$
CREATE PROCEDURE sp_anular_pago_op(
    IN p_id_pago    INT UNSIGNED,
    IN p_motivo     VARCHAR(200),
    IN p_id_usuario INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_orden  INT UNSIGNED;
    DECLARE v_nro    VARCHAR(20);
    DECLARE v_lote   VARCHAR(160) DEFAULT NULL;
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

    SELECT estado, id_orden, nro_pago INTO v_estado, v_orden, v_nro
      FROM pagos_emitidos WHERE id_pago_emitido = p_id_pago FOR UPDATE;
    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago no existe';
    END IF;
    IF v_estado = 'ANULADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya estaba anulado';
    END IF;
    -- FOR SHARE: lo último grabado (un archivo que se acaba de generar con
    -- este pago), no la foto de la transacción.
    SELECT l.nombre_archivo INTO v_lote
      FROM transferencias_lotes_det d JOIN transferencias_lotes l ON l.id_lote = d.id_lote
     WHERE d.id_pago_emitido = p_id_pago AND d.incluida = 1 AND l.anulado_el IS NULL
     LIMIT 1 FOR SHARE;
    IF v_lote IS NOT NULL THEN
        SET v_msg = LEFT(CONCAT('El pago ', v_nro, ' salió en el archivo ', v_lote, ': sacalo del archivo o anulá el archivo primero'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    UPDATE pagos_emitidos
       SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = p_id_usuario, motivo_anulacion = LEFT(p_motivo, 200)
     WHERE id_pago_emitido = p_id_pago;
    UPDATE ordenes_pago SET estado = 'APROBADA' WHERE id_orden = v_orden AND estado = 'PAGADA';
    CALL sp_fondos_pago_op(p_id_pago);

    COMMIT;
END $$

-- sp_liquidar_prestamo: el de financiera_fondos.sql sin el desembolso
-- (cuenta, medio y referencia): el neto se paga con la orden de pago que
-- crea. Vuelve a los parámetros de financiera_prestamos.sql.
DROP PROCEDURE IF EXISTS sp_liquidar_prestamo $$
CREATE PROCEDURE sp_liquidar_prestamo(
    IN  p_nro_prestamo  VARCHAR(20),
    IN  p_id_cliente    INT UNSIGNED,
    IN  p_id_producto   INT UNSIGNED,
    IN  p_id_vendedor   INT UNSIGNED,
    IN  p_capital       DECIMAL(15,2),
    IN  p_plazo         SMALLINT,
    IN  p_fecha         DATE,
    IN  p_primer_vto    DATE,
    IN  p_comision_pct  DECIMAL(9,6),
    IN  p_telemarketing TINYINT,
    IN  p_id_usuario    INT UNSIGNED,
    OUT p_id_prestamo   INT UNSIGNED
)
BEGIN
    DECLARE v_id_tasa   INT UNSIGNED;
    DECLARE v_tna       DECIMAL(9,6);
    DECLARE v_punit     DECIMAL(9,6);
    DECLARE v_iva       DECIMAL(6,4);
    DECLARE v_gasto_pct DECIMAL(6,4);
    DECLARE v_gastos    DECIMAL(15,2);
    DECLARE v_pmin, v_pmax SMALLINT;
    DECLARE v_mmin, v_mmax DECIMAL(15,2);
    DECLARE v_activo    TINYINT;
    DECLARE v_estado_cli VARCHAR(20);
    DECLARE v_id_regla  INT UNSIGNED DEFAULT NULL;
    DECLARE v_com_pct   DECIMAL(9,6) DEFAULT NULL;
    DECLARE v_com_base  DECIMAL(15,2);
    DECLARE v_com_monto DECIMAL(15,2) DEFAULT NULL;
    DECLARE v_base_tipo VARCHAR(20);
    DECLARE v_cuota, v_int, v_ivat DECIMAL(15,2);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha      = COALESCE(p_fecha, CURDATE());
    SET p_telemarketing = COALESCE(p_telemarketing, 0);
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

    SELECT estado INTO v_estado_cli FROM clientes WHERE id_cliente = p_id_cliente;
    IF v_estado_cli IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente inexistente';
    END IF;
    IF v_estado_cli <> 'ACTIVO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no está activo';
    END IF;

    SELECT alicuota_iva, gasto_otorg_pct, plazo_min, plazo_max, monto_min, monto_max, activo
      INTO v_iva, v_gasto_pct, v_pmin, v_pmax, v_mmin, v_mmax, v_activo
      FROM productos WHERE id_producto = p_id_producto;

    IF v_activo IS NULL OR v_activo = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Producto inexistente o inactivo';
    END IF;
    IF p_plazo NOT BETWEEN v_pmin AND v_pmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plazo fuera del rango del producto';
    END IF;
    IF p_capital NOT BETWEEN v_mmin AND v_mmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Monto fuera del rango del producto';
    END IF;
    IF p_comision_pct IS NOT NULL AND (p_comision_pct < 0 OR p_comision_pct > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El % de comisión manual debe estar entre 0 y 1';
    END IF;

    SET v_id_tasa = fn_tasa_vigente(p_id_producto, p_plazo, p_capital, p_fecha);
    IF v_id_tasa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay tasa vigente para ese plazo y monto';
    END IF;

    SELECT t.tna,
           COALESCE(t.tasa_punitoria,
                    ROUND(t.tna * COALESCE((SELECT CAST(valor AS DECIMAL(9,6)) FROM parametros
                                             WHERE clave = 'PUNITORIO_PCT_TNA'), 0.5), 6))
      INTO v_tna, v_punit
      FROM tasas_vigencia t WHERE t.id_tasa = v_id_tasa;

    SET v_gastos = ROUND(p_capital * v_gasto_pct, 2);

    CALL sp_cuadro_frances(p_capital, p_plazo, v_tna, v_iva, p_primer_vto);

    SELECT SUM(IF(nro_cuota = 1, importe, 0)), SUM(interes), SUM(iva_interes)
      INTO v_cuota, v_int, v_ivat
      FROM tmp_cuadro;

    IF p_id_vendedor IS NOT NULL THEN
        IF p_comision_pct IS NOT NULL THEN
            SET v_com_pct   = p_comision_pct;
            SET v_base_tipo = 'CAPITAL';
        ELSE
            SET v_id_regla = fn_comision_regla(p_id_vendedor, p_id_producto, p_plazo, p_capital, p_fecha, COALESCE(p_telemarketing, 0));
            IF v_id_regla IS NOT NULL THEN
                SELECT porcentaje, base_calculo INTO v_com_pct, v_base_tipo
                  FROM comision_reglas WHERE id_regla = v_id_regla;
            END IF;
        END IF;

        IF v_com_pct IS NOT NULL THEN
            SET v_com_base  = IF(v_base_tipo = 'NETO_DESEMBOLSO', p_capital - v_gastos, p_capital);
            SET v_com_monto = ROUND(v_com_base * v_com_pct, 2);
        END IF;
    END IF;

    START TRANSACTION;

    INSERT INTO prestamos (
        nro_prestamo, id_cliente, id_producto, id_vendedor, id_tasa,
        fecha_liquidacion, fecha_primer_vto, capital, plazo, tna, alicuota_iva,
        tasa_punitoria, gastos_otorg, neto_desembolso, id_cuenta_fondos, medio_desembolso, referencia_desembolso,
        valor_cuota, total_intereses, total_iva,
        telemarketing, id_comision_regla, comision_pct, comision_monto, creado_por)
    VALUES (p_nro_prestamo, p_id_cliente, p_id_producto, p_id_vendedor, v_id_tasa,
            p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
            v_punit, v_gastos, p_capital - v_gastos, NULL, NULL, NULL,
            v_cuota, v_int, v_ivat,
            COALESCE(p_telemarketing, 0), v_id_regla, v_com_pct, v_com_monto, p_id_usuario);

    SET p_id_prestamo = LAST_INSERT_ID();

    INSERT INTO cuotas (id_prestamo, nro_cuota, vencimiento, capital, interes,
                        iva_interes, importe, saldo_capital)
    SELECT p_id_prestamo, nro_cuota, vencimiento, capital, interes,
           iva_interes, importe, saldo_capital
      FROM tmp_cuadro;

    INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
    VALUES (p_id_prestamo, p_fecha, 'DESEMBOLSO', p_capital, p_nro_prestamo, p_id_usuario);

    IF v_gastos > 0 THEN
        INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
        VALUES (p_id_prestamo, p_fecha, 'GASTO_OTORG', v_gastos, 'Descontado del desembolso', p_id_usuario);
    END IF;

    IF v_com_monto IS NOT NULL THEN
        INSERT INTO comisiones_devengadas (id_vendedor, id_prestamo, id_regla, fecha,
                                           concepto, base, porcentaje, importe)
        VALUES (p_id_vendedor, p_id_prestamo, v_id_regla, p_fecha,
                'ALTA', v_com_base, v_com_pct, v_com_monto);
    END IF;

    -- El neto no sale acá: se paga con la orden de pago (Tesorería la emite).
    CALL sp_op_desde_prestamo(p_id_prestamo, p_id_usuario);

    COMMIT;
END $$

-- sp_anular_prestamo: el de financiera_fondos.sql, más la orden de pago:
-- si el desembolso ya se pagó no se anula (primero se anula el pago); si
-- no, la orden se anula con el préstamo.
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
    DECLARE v_pago   VARCHAR(20) DEFAULT NULL;
    DECLARE v_estado_op VARCHAR(20) DEFAULT NULL;

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
    -- Si el desembolso ya se pagó, la plata salió: primero se anula ese pago.
    -- La orden se bloquea: si alguien está emitiendo su pago en este momento,
    -- se espera a que termine y se ve el estado con el que quedó.
    SELECT estado INTO v_estado_op FROM ordenes_pago WHERE id_prestamo = p_id_prestamo FOR UPDATE;
    IF v_estado_op = 'PAGADA' THEN
        SELECT g.nro_pago INTO v_pago
          FROM ordenes_pago o JOIN pagos_emitidos g ON g.id_orden = o.id_orden AND g.estado = 'EMITIDO'
         WHERE o.id_prestamo = p_id_prestamo
         LIMIT 1 FOR SHARE;
        SET v_msg = LEFT(CONCAT('El desembolso ya se pagó (pago ', COALESCE(v_pago, '?'),
                                '): anulá primero el pago en Tesorería › Órdenes de pago'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
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

    UPDATE ordenes_pago
       SET estado = 'ANULADA', anulada_el = NOW(), anulada_por = p_id_usuario,
           motivo_anulacion = LEFT(CONCAT('Préstamo anulado: ', p_motivo), 200)
     WHERE id_prestamo = p_id_prestamo AND estado <> 'ANULADA';

    -- Préstamos liquidados antes de las órdenes de pago: su desembolso está en el libro.
    CALL sp_fondos_anular('DESEMBOLSO', p_id_prestamo, p_motivo, p_id_usuario);

    COMMIT;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 5b. Numeradores: las series de cada año, desde lo que ya está grabado
--     (si este script se corre de nuevo, ninguna serie vuelve para atrás).
-- ---------------------------------------------------------------------
INSERT INTO numeradores (clave, ultimo)
SELECT x.clave, x.ultimo
  FROM (SELECT CONCAT('OP-', LEFT(nro_orden, 4)) AS clave, MAX(CAST(SUBSTRING(nro_orden, 6) AS UNSIGNED)) AS ultimo
          FROM ordenes_pago WHERE nro_orden REGEXP '^[0-9]{4}-[0-9]{8}$' GROUP BY CONCAT('OP-', LEFT(nro_orden, 4))
        UNION ALL
        SELECT CONCAT('PAG-', LEFT(nro_pago, 4)), MAX(CAST(SUBSTRING(nro_pago, 6) AS UNSIGNED))
          FROM pagos_emitidos WHERE nro_pago REGEXP '^[0-9]{4}-[0-9]{8}$' GROUP BY CONCAT('PAG-', LEFT(nro_pago, 4))) x
ON DUPLICATE KEY UPDATE ultimo = GREATEST(numeradores.ultimo, x.ultimo);

-- ---------------------------------------------------------------------
-- 6. Menú: Tesorería › Órdenes de pago y Transferencias masivas
--    (Administrador y Gerencia). Las opciones de Tesorería se reordenan:
--    primero lo de todos los días.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (86, 80, 'Órdenes de pago',        'ordenes_pago.php',   NULL, 2, 2, 1),
 (87, 80, 'Transferencias masivas', 'transferencias.php', NULL, 3, 2, 1);
UPDATE menu SET orden = 1 WHERE id_menu = 81 AND url = 'movimientos_fondos.php';
UPDATE menu SET orden = 4 WHERE id_menu = 82 AND url = 'movimientos_cuentas.php';
UPDATE menu SET orden = 5 WHERE id_menu = 83 AND url = 'debitos_creditos.php';
UPDATE menu SET orden = 6 WHERE id_menu = 85 AND url = 'extractos_bancarios.php';
UPDATE menu SET orden = 7 WHERE id_menu = 84 AND url = 'cuentas_fondos.php';
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT m.id_menu, r.id_rol FROM menu m JOIN roles r ON r.id_rol IN (1, 2)
 WHERE (m.id_menu = 86 AND m.url = 'ordenes_pago.php') OR (m.id_menu = 87 AND m.url = 'transferencias.php');

SELECT m.id_menu, m.nombre, m.url, m.orden FROM menu m WHERE m.padre_id = 80 ORDER BY m.orden;

-- ---------------------------------------------------------------------
-- 7. Controles (tienen que dar cero filas)
-- ---------------------------------------------------------------------

-- 7.1 Préstamos vigentes liquidados con órdenes de pago y sin la suya.
SELECT 'préstamo sin orden de pago' AS control, p.id_prestamo, p.nro_prestamo
  FROM prestamos p
 WHERE p.estado <> 'ANULADO' AND p.id_cuenta_fondos IS NULL AND p.neto_desembolso > 0
   AND NOT EXISTS (SELECT 1 FROM ordenes_pago o WHERE o.id_prestamo = p.id_prestamo);

-- 7.2 El estado de la orden según sus pagos.
SELECT 'orden con estado que no cuadra' AS control, o.id_orden, o.nro_orden, o.estado,
       SUM(g.estado = 'EMITIDO') AS pagos_vigentes
  FROM ordenes_pago o LEFT JOIN pagos_emitidos g ON g.id_orden = o.id_orden
 GROUP BY o.id_orden
HAVING (o.estado = 'PAGADA' AND COALESCE(SUM(g.estado = 'EMITIDO'), 0) <> 1)
    OR (o.estado <> 'PAGADA' AND COALESCE(SUM(g.estado = 'EMITIDO'), 0) <> 0);

-- 7.3 Pagos emitidos sin su renglón en el libro.
SELECT 'pago sin libro' AS control, g.id_pago_emitido, g.nro_pago
  FROM pagos_emitidos g
 WHERE NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.id_pago_emitido = g.id_pago_emitido);

-- 7.4 Números de orden o de pago con otro formato que 2026-00000001.
SELECT 'orden con otro número' AS control, id_orden AS id, nro_orden AS nro FROM ordenes_pago WHERE nro_orden NOT REGEXP '^[0-9]{4}-[0-9]{8}$'
UNION ALL
SELECT 'pago con otro número', id_pago_emitido, nro_pago FROM pagos_emitidos WHERE nro_pago NOT REGEXP '^[0-9]{4}-[0-9]{8}$';

-- 7.5 Pagos en más de un archivo vigente.
SELECT 'pago en dos archivos' AS control, d.id_pago_emitido, COUNT(*) AS archivos
  FROM transferencias_lotes_det d JOIN transferencias_lotes l ON l.id_lote = d.id_lote AND l.anulado_el IS NULL
 WHERE d.incluida = 1
 GROUP BY d.id_pago_emitido
HAVING COUNT(*) > 1;
