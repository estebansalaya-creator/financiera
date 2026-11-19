-- =====================================================================
--  FINANCIERA - Módulo de cobranzas
--  Correr DESPUÉS de financiera_nucleo.sql
--  MySQL 8.0.22+ (probado también en MariaDB 10.x)
--
--  Contenido
--    Tablas ....... imputacion_orden, lotes_cobranza, pagos, pagos_imputacion
--    Vistas ....... v_cuotas_saldo     saldo pendiente por cuota y concepto
--                   v_mora_prestamos   atraso y tramo de mora por préstamo
--    SPs .......... sp_calc_deuda          deuda a una fecha (motor único)
--                   sp_estado_cuenta       consulta de deuda / monto de cancelación
--                   sp_registrar_pago      cobro con imputación automática
--                   sp_anular_pago         anula el último pago de un préstamo
--                   sp_actualizar_estados  recalcula estado de cuotas y préstamo
--
--  Criterios
--    - Nada se edita a mano: lo pendiente de cada cuota sale de
--      cuotas (importes originales) + movimientos (punitorios y quitas)
--      - pagos_imputacion (de los pagos no anulados).
--    - Punitorios: se devengan día a día sobre la base pendiente de cada
--      cuota vencida, con la TNA punitoria congelada en el préstamo
--      (convención /365). Se persisten al registrar cada pago y la cuota
--      guarda hasta qué fecha se devengaron, así un pago parcial no
--      genera doble cálculo.
--    - Orden de imputación configurable en imputacion_orden; se aplica
--      cuota por cuota, de la más vieja a la más nueva.
--    - Cancelación anticipada: se cobra el interés corrido del período
--      en curso (proporcional por días) y se condona el interés futuro,
--      que queda registrado como QUITA_INTERES / QUITA_IVA.
--      Si en una cuota a vencer ya se pagó más interés (o IVA) que el
--      corrido, el excedente se acredita a capital (CREDITO_CAPITAL), de
--      la última cuota hacia atrás: pagar por adelantado no se pierde.
--    - Solo se puede anular el último pago de cada préstamo, para que los
--      punitorios devengados después sigan siendo consistentes.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
-- TABLAS
-- =====================================================================

CREATE TABLE IF NOT EXISTS imputacion_orden (
    concepto        VARCHAR(20)  NOT NULL,
    orden           TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (concepto),
    UNIQUE KEY uq_imputacion_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO imputacion_orden (concepto, orden) VALUES
 ('IVA_PUNITORIO', 1),
 ('PUNITORIO',     2),
 ('IVA_INTERES',   3),
 ('INTERES',       4),
 ('CAPITAL',       5);

-- Lote de cobranza: un archivo de Rapipago / Pago Fácil / banco / MercadoPago
-- o una rendición de caja. Los pagos del lote apuntan acá.
CREATE TABLE IF NOT EXISTS lotes_cobranza (
    id_lote         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medio           VARCHAR(20)  NOT NULL,
    fecha           DATE         NOT NULL,
    archivo         VARCHAR(200) NULL,
    registros       INT UNSIGNED NOT NULL DEFAULT 0,
    total           DECIMAL(15,2) NOT NULL DEFAULT 0,
    estado          ENUM('ABIERTO','PROCESADO','ANULADO') NOT NULL DEFAULT 'ABIERTO',
    creado_por      INT UNSIGNED NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_lote),
    KEY ix_lotes_fecha (fecha),
    CONSTRAINT fk_lote_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos (
    id_pago         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_prestamo     INT UNSIGNED NOT NULL,
    nro_recibo      VARCHAR(20)  NULL,
    fecha           DATE         NOT NULL,
    importe         DECIMAL(15,2) NOT NULL,
    medio           ENUM('EFECTIVO','TRANSFERENCIA','DEBITO','RAPIPAGO','PAGOFACIL','MERCADOPAGO','CHEQUE','OTRO') NOT NULL,
    referencia      VARCHAR(100) NULL,                     -- nro de operación, código de barras, etc.
    modo            ENUM('NORMAL','CANCELACION') NOT NULL DEFAULT 'NORMAL',
    id_lote         INT UNSIGNED NULL,
    estado          ENUM('APLICADO','ANULADO') NOT NULL DEFAULT 'APLICADO',
    motivo_anulacion VARCHAR(200) NULL,
    creado_por      INT UNSIGNED NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_por     INT UNSIGNED NULL,
    anulado_el      DATETIME     NULL,
    PRIMARY KEY (id_pago),
    UNIQUE KEY uq_pagos_recibo (nro_recibo),
    KEY ix_pagos_prestamo_fecha (id_prestamo, fecha),
    KEY ix_pagos_lote (id_lote),
    KEY ix_pagos_fecha (fecha),
    CONSTRAINT fk_pag_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_pag_lote     FOREIGN KEY (id_lote)     REFERENCES lotes_cobranza (id_lote),
    CONSTRAINT fk_pag_creado   FOREIGN KEY (creado_por)  REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_pag_anulado  FOREIGN KEY (anulado_por) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cómo se repartió cada pago entre cuotas y conceptos
CREATE TABLE IF NOT EXISTS pagos_imputacion (
    id_imputacion   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pago         INT UNSIGNED NOT NULL,
    id_cuota        INT UNSIGNED NOT NULL,
    concepto        VARCHAR(20)  NOT NULL,                 -- CAPITAL, INTERES, IVA_INTERES, PUNITORIO, IVA_PUNITORIO
    importe         DECIMAL(15,2) NOT NULL,
    PRIMARY KEY (id_imputacion),
    KEY ix_imp_pago (id_pago),
    KEY ix_imp_cuota (id_cuota, concepto),
    CONSTRAINT fk_imp_pago     FOREIGN KEY (id_pago)  REFERENCES pagos (id_pago),
    CONSTRAINT fk_imp_cuota    FOREIGN KEY (id_cuota) REFERENCES cuotas (id_cuota),
    CONSTRAINT fk_imp_concepto FOREIGN KEY (concepto) REFERENCES imputacion_orden (concepto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE movimientos
    ADD CONSTRAINT fk_mov_pago FOREIGN KEY (id_pago) REFERENCES pagos (id_pago);

-- =====================================================================
-- VISTAS
-- =====================================================================

-- Saldo pendiente de cada cuota por concepto.
-- Las quitas se guardan en negativo, por eso se suman.
CREATE OR REPLACE VIEW v_cuotas_saldo AS
SELECT c.id_cuota, c.id_prestamo, c.nro_cuota, c.vencimiento,
       c.capital, c.interes, c.iva_interes, c.punitorio_hasta, c.estado,
       c.capital     + COALESCE(m.cred_cap, 0)  - COALESCE(i.capital, 0)    AS capital_pend,
       c.interes     + COALESCE(m.quita_int, 0) - COALESCE(i.interes, 0)    AS interes_pend,
       c.iva_interes + COALESCE(m.quita_iva, 0) - COALESCE(i.iva, 0)        AS iva_pend,
       COALESCE(m.punitorio, 0) - COALESCE(i.punitorio, 0)                  AS punit_pend,
       COALESCE(m.iva_punit, 0) - COALESCE(i.iva_punit, 0)                  AS ivap_pend,
       COALESCE(i.total, 0)                                                 AS total_imputado
  FROM cuotas c
  LEFT JOIN (SELECT pi.id_cuota,
                    SUM(IF(pi.concepto = 'CAPITAL',       pi.importe, 0)) AS capital,
                    SUM(IF(pi.concepto = 'INTERES',       pi.importe, 0)) AS interes,
                    SUM(IF(pi.concepto = 'IVA_INTERES',   pi.importe, 0)) AS iva,
                    SUM(IF(pi.concepto = 'PUNITORIO',     pi.importe, 0)) AS punitorio,
                    SUM(IF(pi.concepto = 'IVA_PUNITORIO', pi.importe, 0)) AS iva_punit,
                    SUM(pi.importe)                                       AS total
               FROM pagos_imputacion pi
               JOIN pagos p ON p.id_pago = pi.id_pago AND p.estado = 'APLICADO'
              GROUP BY pi.id_cuota) i ON i.id_cuota = c.id_cuota
  LEFT JOIN (SELECT mv.id_cuota,
                    SUM(IF(mv.tipo = 'PUNITORIO',     mv.importe, 0)) AS punitorio,
                    SUM(IF(mv.tipo = 'IVA_PUNITORIO', mv.importe, 0)) AS iva_punit,
                    SUM(IF(mv.tipo = 'QUITA_INTERES', mv.importe, 0)) AS quita_int,
                    SUM(IF(mv.tipo = 'QUITA_IVA',     mv.importe, 0)) AS quita_iva,
                    SUM(IF(mv.tipo = 'CREDITO_CAPITAL', mv.importe, 0)) AS cred_cap
               FROM movimientos mv
              WHERE mv.id_cuota IS NOT NULL
              GROUP BY mv.id_cuota) m ON m.id_cuota = c.id_cuota;

-- Préstamos vigentes con cuotas vencidas impagas, a la fecha de hoy.
-- La deuda vencida no incluye punitorios todavía no devengados.
CREATE OR REPLACE VIEW v_mora_prestamos AS
SELECT p.id_prestamo, p.nro_prestamo, p.id_cliente, cl.denominacion AS cliente,
       p.id_vendedor, MIN(s.vencimiento)                    AS primer_vto_impago,
       DATEDIFF(CURDATE(), MIN(s.vencimiento))              AS dias_atraso,
       CASE WHEN DATEDIFF(CURDATE(), MIN(s.vencimiento)) <= 30 THEN '1-30'
            WHEN DATEDIFF(CURDATE(), MIN(s.vencimiento)) <= 60 THEN '31-60'
            WHEN DATEDIFF(CURDATE(), MIN(s.vencimiento)) <= 90 THEN '61-90'
            ELSE '+90' END                                  AS tramo,
       COUNT(*)                                             AS cuotas_vencidas,
       SUM(s.capital_pend + s.interes_pend + s.iva_pend)    AS deuda_vencida,
       SUM(s.punit_pend + s.ivap_pend)                      AS punitorios_devengados
  FROM prestamos p
  JOIN clientes cl       ON cl.id_cliente = p.id_cliente
  JOIN v_cuotas_saldo s  ON s.id_prestamo = p.id_prestamo
 WHERE p.estado = 'VIGENTE'
   AND s.vencimiento < CURDATE()
   AND s.capital_pend + s.interes_pend + s.iva_pend + s.punit_pend + s.ivap_pend > 0
 GROUP BY p.id_prestamo, p.nro_prestamo, p.id_cliente, cl.denominacion, p.id_vendedor;

-- =====================================================================
-- STORED PROCEDURES
-- =====================================================================

DELIMITER $$

-- ---------------------------------------------------------------------
-- sp_calc_deuda
--   Motor ÚNICO de deuda. Calcula lo que debe un préstamo a p_fecha y
--   deja dos tablas temporales:
--     tmp_cs     una fila por cuota con saldos, punitorio nuevo y quitas
--     tmp_deuda  una fila por cuota y concepto con lo pendiente, en el
--                orden de imputación
--   No escribe nada: sp_registrar_pago es quien persiste.
--   p_modo: NORMAL o CANCELACION (aplica condonación de interés futuro).
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_calc_deuda $$
CREATE PROCEDURE sp_calc_deuda(
    IN p_id_prestamo INT UNSIGNED,
    IN p_fecha       DATE,
    IN p_modo        VARCHAR(20)
)
BEGIN
    DECLARE v_tasa_pun  DECIMAL(9,6);
    DECLARE v_alic      DECIMAL(6,4);
    DECLARE v_fliq      DATE;
    DECLARE v_gracia    INT;
    DECLARE v_base      VARCHAR(20);
    DECLARE v_cred      DECIMAL(15,2) DEFAULT 0;
    DECLARE v_id        INT UNSIGNED;
    DECLARE v_disp      DECIMAL(15,2);
    DECLARE v_apl       DECIMAL(15,2);

    SELECT tasa_punitoria, alicuota_iva, fecha_liquidacion
      INTO v_tasa_pun, v_alic, v_fliq
      FROM prestamos WHERE id_prestamo = p_id_prestamo;

    IF v_fliq IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF p_modo NOT IN ('NORMAL', 'CANCELACION') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo inválido: NORMAL o CANCELACION';
    END IF;

    SET v_gracia = COALESCE((SELECT CAST(valor AS SIGNED) FROM parametros WHERE clave = 'DIAS_GRACIA_MORA'), 0);
    SET v_base   = COALESCE((SELECT valor FROM parametros WHERE clave = 'PUNITORIO_BASE'), 'CAPITAL');

    DROP TEMPORARY TABLE IF EXISTS tmp_cs;
    CREATE TEMPORARY TABLE tmp_cs (
        id_cuota      INT UNSIGNED PRIMARY KEY,
        nro_cuota     SMALLINT UNSIGNED,
        vencimiento   DATE,
        vto_anterior  DATE,
        interes       DECIMAL(15,2),
        capital_pend  DECIMAL(15,2),
        interes_pend  DECIMAL(15,2),
        iva_pend      DECIMAL(15,2),
        punit_pend    DECIMAL(15,2),
        ivap_pend     DECIMAL(15,2),
        devenga       TINYINT DEFAULT 0,        -- 1 = corre punitorio hasta p_fecha
        punit_nuevo   DECIMAL(15,2) DEFAULT 0,
        ivap_nuevo    DECIMAL(15,2) DEFAULT 0,
        quita_int     DECIMAL(15,2) DEFAULT 0,  -- positivo = lo que se condona
        quita_iva     DECIMAL(15,2) DEFAULT 0,
        int_pagado    DECIMAL(15,2) DEFAULT 0,  -- interés ya cobrado de la cuota
        iva_pagado    DECIMAL(15,2) DEFAULT 0,  -- IVA del interés ya cobrado
        credito       DECIMAL(15,2) DEFAULT 0,  -- interés + IVA cobrado de más (cancelación)
        cred_cap      DECIMAL(15,2) DEFAULT 0   -- parte del crédito que baja el capital de esta cuota
    );

    INSERT INTO tmp_cs (id_cuota, nro_cuota, vencimiento, vto_anterior, interes,
                        capital_pend, interes_pend, iva_pend, punit_pend, ivap_pend,
                        int_pagado, iva_pagado)
    SELECT s.id_cuota, s.nro_cuota, s.vencimiento,
           COALESCE((SELECT c2.vencimiento FROM cuotas c2
                      WHERE c2.id_prestamo = s.id_prestamo
                        AND c2.nro_cuota   = s.nro_cuota - 1), v_fliq),
           s.interes, s.capital_pend, s.interes_pend, s.iva_pend, s.punit_pend, s.ivap_pend,
           COALESCE((SELECT SUM(pi.importe) FROM pagos_imputacion pi
                       JOIN pagos pg ON pg.id_pago = pi.id_pago AND pg.estado = 'APLICADO'
                      WHERE pi.id_cuota = s.id_cuota AND pi.concepto = 'INTERES'), 0),
           COALESCE((SELECT SUM(pi.importe) FROM pagos_imputacion pi
                       JOIN pagos pg ON pg.id_pago = pi.id_pago AND pg.estado = 'APLICADO'
                      WHERE pi.id_cuota = s.id_cuota AND pi.concepto = 'IVA_INTERES'), 0)
      FROM v_cuotas_saldo s
     WHERE s.id_prestamo = p_id_prestamo;

    -- Punitorios nuevos: cuotas vencidas, fuera del período de gracia,
    -- desde el último devengamiento (o el vencimiento) hasta p_fecha.
    UPDATE tmp_cs t
      JOIN cuotas c ON c.id_cuota = t.id_cuota
       SET t.devenga = 1,
           t.punit_nuevo = ROUND(
               IF(v_base = 'CUOTA', t.capital_pend + t.interes_pend + t.iva_pend, t.capital_pend)
               * v_tasa_pun / 365
               * GREATEST(DATEDIFF(p_fecha, GREATEST(t.vencimiento, COALESCE(c.punitorio_hasta, t.vencimiento))), 0)
           , 2)
     WHERE t.vencimiento < p_fecha
       AND DATEDIFF(p_fecha, t.vencimiento) > v_gracia
       AND t.capital_pend + t.interes_pend + t.iva_pend > 0;

    UPDATE tmp_cs SET ivap_nuevo = ROUND(punit_nuevo * v_alic, 2) WHERE punit_nuevo > 0;

    -- Cancelación anticipada: en las cuotas a vencer se cobra solo el
    -- interés corrido (proporción de días del período ya transcurrida)
    -- y se condona el resto, con su IVA. Lo que ya se pagó de interés o
    -- IVA por encima del corrido se acredita a capital.
    IF p_modo = 'CANCELACION' THEN
        -- interes pasa a ser el interés corrido de la cuota (solo en las a vencer)
        UPDATE tmp_cs
           SET interes = ROUND(interes * GREATEST(0, LEAST(1,
                   DATEDIFF(p_fecha, vto_anterior) / NULLIF(DATEDIFF(vencimiento, vto_anterior), 0))), 2)
         WHERE vencimiento > p_fecha;

        UPDATE tmp_cs
           SET quita_int = interes_pend - LEAST(interes_pend, GREATEST(0, interes - int_pagado)),
               quita_iva = iva_pend     - LEAST(iva_pend,     GREATEST(0, ROUND(interes * v_alic, 2) - iva_pagado)),
               credito   = GREATEST(0, int_pagado - interes)
                         + GREATEST(0, iva_pagado - ROUND(interes * v_alic, 2))
         WHERE vencimiento > p_fecha;

        -- El crédito baja el capital pendiente, de la última cuota hacia atrás
        SELECT COALESCE(SUM(credito), 0) INTO v_cred FROM tmp_cs;
        WHILE v_cred > 0 DO
            SET v_id = NULL;
            SELECT id_cuota, capital_pend - cred_cap INTO v_id, v_disp
              FROM tmp_cs WHERE capital_pend - cred_cap > 0
             ORDER BY nro_cuota DESC LIMIT 1;
            IF v_id IS NULL THEN
                SET v_cred = 0;            -- no queda capital: el excedente no se aplica
            ELSE
                SET v_apl = LEAST(v_cred, v_disp);
                UPDATE tmp_cs SET cred_cap = cred_cap + v_apl WHERE id_cuota = v_id;
                SET v_cred = v_cred - v_apl;
            END IF;
        END WHILE;
    END IF;

    -- Deuda por cuota y concepto, en orden de imputación
    DROP TEMPORARY TABLE IF EXISTS tmp_deuda;
    CREATE TEMPORARY TABLE tmp_deuda (
        id_cuota    INT UNSIGNED,
        nro_cuota   SMALLINT UNSIGNED,
        vencimiento DATE,
        concepto    VARCHAR(20),
        orden       TINYINT UNSIGNED,
        pendiente   DECIMAL(15,2),
        PRIMARY KEY (id_cuota, concepto)
    );

    INSERT INTO tmp_deuda
    SELECT t.id_cuota, t.nro_cuota, t.vencimiento, o.concepto, o.orden,
           CASE o.concepto
                WHEN 'CAPITAL'       THEN t.capital_pend - t.cred_cap
                WHEN 'INTERES'       THEN t.interes_pend - t.quita_int
                WHEN 'IVA_INTERES'   THEN t.iva_pend     - t.quita_iva
                WHEN 'PUNITORIO'     THEN t.punit_pend   + t.punit_nuevo
                WHEN 'IVA_PUNITORIO' THEN t.ivap_pend    + t.ivap_nuevo
           END
      FROM tmp_cs t
     CROSS JOIN imputacion_orden o;

    DELETE FROM tmp_deuda WHERE pendiente <= 0;
END $$

-- ---------------------------------------------------------------------
-- sp_estado_cuenta
--   Consulta (no escribe). Devuelve:
--     1) detalle por cuota con lo pendiente a p_fecha
--     2) totales; con p_modo = CANCELACION, el total es el monto exacto
--        para cancelar el préstamo ese día
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_estado_cuenta $$
CREATE PROCEDURE sp_estado_cuenta(
    IN p_id_prestamo INT UNSIGNED,
    IN p_fecha       DATE,
    IN p_modo        VARCHAR(20)
)
BEGIN
    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_modo  = COALESCE(p_modo, 'NORMAL');

    CALL sp_calc_deuda(p_id_prestamo, p_fecha, p_modo);

    SELECT nro_cuota, vencimiento,
           GREATEST(DATEDIFF(p_fecha, vencimiento), 0)                       AS dias_atraso,
           SUM(IF(concepto = 'CAPITAL',       pendiente, 0))                 AS capital,
           SUM(IF(concepto = 'INTERES',       pendiente, 0))                 AS interes,
           SUM(IF(concepto = 'IVA_INTERES',   pendiente, 0))                 AS iva_interes,
           SUM(IF(concepto = 'PUNITORIO',     pendiente, 0))                 AS punitorio,
           SUM(IF(concepto = 'IVA_PUNITORIO', pendiente, 0))                 AS iva_punitorio,
           SUM(pendiente)                                                    AS total
      FROM tmp_deuda
     GROUP BY nro_cuota, vencimiento
     ORDER BY nro_cuota;

    SELECT p_fecha                                                     AS fecha,
           p_modo                                                      AS modo,
           SUM(IF(vencimiento <= p_fecha, pendiente, 0))               AS deuda_vencida,
           SUM(IF(vencimiento >  p_fecha, pendiente, 0))               AS deuda_a_vencer,
           SUM(pendiente)                                              AS total,
           (SELECT SUM(quita_int + quita_iva) FROM tmp_cs)             AS interes_condonado,
           (SELECT SUM(cred_cap) FROM tmp_cs)                          AS credito_interes_pagado
      FROM tmp_deuda;
END $$

-- ---------------------------------------------------------------------
-- sp_actualizar_estados: estado de cada cuota y del préstamo
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_actualizar_estados $$
CREATE PROCEDURE sp_actualizar_estados(IN p_id_prestamo INT UNSIGNED)
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_est;
    CREATE TEMPORARY TABLE tmp_est AS
    SELECT id_cuota,
           capital_pend + interes_pend + iva_pend + punit_pend + ivap_pend AS pend,
           total_imputado
      FROM v_cuotas_saldo
     WHERE id_prestamo = p_id_prestamo;

    UPDATE cuotas c
      JOIN tmp_est t ON t.id_cuota = c.id_cuota
       SET c.estado = CASE WHEN t.pend <= 0          THEN 'PAGADA'
                           WHEN t.total_imputado > 0 THEN 'PARCIAL'
                           ELSE 'PENDIENTE' END
     WHERE c.estado <> 'REFINANCIADA';

    UPDATE prestamos p
       SET p.estado = CASE
               WHEN NOT EXISTS (SELECT 1 FROM cuotas c
                                 WHERE c.id_prestamo = p.id_prestamo
                                   AND c.estado NOT IN ('PAGADA', 'REFINANCIADA'))
               THEN 'CANCELADO'
               ELSE 'VIGENTE' END
     WHERE p.id_prestamo = p_id_prestamo
       AND p.estado IN ('VIGENTE', 'CANCELADO');
END $$

-- ---------------------------------------------------------------------
-- sp_registrar_pago
--   Registra un cobro e imputa automáticamente:
--     1) devenga y persiste los punitorios hasta la fecha del pago
--     2) en CANCELACION, persiste la condonación del interés futuro
--     3) reparte el importe cuota por cuota (más vieja primero) y,
--        dentro de cada cuota, según imputacion_orden
--   NORMAL: puede ser parcial; no puede superar la deuda total.
--   CANCELACION: el importe debe ser exactamente el monto de cancelación
--   (consultarlo antes con sp_estado_cuenta(..., 'CANCELACION')).
--   Devuelve el detalle de la imputación.
-- ---------------------------------------------------------------------
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

    START TRANSACTION;

    -- Bloquea el préstamo: dos cobros simultáneos del mismo préstamo
    -- quedan en fila y no se pisan.
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

    -- Pago y recibo
    INSERT INTO pagos (id_prestamo, fecha, importe, medio, referencia, modo, id_lote, creado_por)
    VALUES (p_id_prestamo, p_fecha, p_importe, p_medio, p_referencia, p_modo, p_id_lote, p_id_usuario);
    SET p_id_pago = LAST_INSERT_ID();

    UPDATE pagos SET nro_recibo = CONCAT('R-', LPAD(p_id_pago, 8, '0'))
     WHERE id_pago = p_id_pago;

    -- 1) Punitorios devengados hasta la fecha del pago
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

    -- 2) Condonación de interés futuro (se guarda en negativo)
    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_INTERES', -quita_int,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_int > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_IVA', -quita_iva,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_iva > 0;

    -- Interés pagado por adelantado que se acredita a capital (se guarda en negativo)
    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'CREDITO_CAPITAL', -cred_cap,
           CONCAT('Interés pagado por adelantado, a capital cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE cred_cap > 0;

    -- 3) Imputación
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

    -- Movimiento del pago (haber)
    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (p_id_prestamo, p_id_pago, p_fecha, 'PAGO', -p_importe,
            CONCAT(p_medio, IF(p_referencia IS NULL, '', CONCAT(' ', p_referencia))), p_id_usuario);

    CALL sp_actualizar_estados(p_id_prestamo);

    COMMIT;

    -- Detalle para el recibo
    SELECT pg.nro_recibo, c.nro_cuota, c.vencimiento, pi.concepto, pi.importe
      FROM pagos_imputacion pi
      JOIN pagos  pg ON pg.id_pago  = pi.id_pago
      JOIN cuotas c  ON c.id_cuota  = pi.id_cuota
      JOIN imputacion_orden o ON o.concepto = pi.concepto
     WHERE pi.id_pago = p_id_pago
     ORDER BY c.nro_cuota, o.orden;
END $$

-- ---------------------------------------------------------------------
-- sp_anular_pago
--   Anula un pago. Solo el último pago aplicado del préstamo.
--   Los punitorios devengados hasta esa fecha se mantienen (eran deuda
--   a esa fecha); la condonación de una cancelación se revierte.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_anular_pago $$
CREATE PROCEDURE sp_anular_pago(
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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR TRIM(p_motivo) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    START TRANSACTION;

    SELECT id_prestamo, estado, importe, fecha
      INTO v_prestamo, v_estado, v_importe, v_fecha
      FROM pagos WHERE id_pago = p_id_pago;

    IF v_prestamo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pago inexistente';
    END IF;

    SELECT id_prestamo INTO v_prestamo
      FROM prestamos WHERE id_prestamo = v_prestamo FOR UPDATE;

    IF v_estado <> 'APLICADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya está anulado';
    END IF;

    SELECT id_pago INTO v_ultimo
      FROM pagos
     WHERE id_prestamo = v_prestamo AND estado = 'APLICADO'
     ORDER BY fecha DESC, id_pago DESC
     LIMIT 1;

    IF v_ultimo <> p_id_pago THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular el último pago del préstamo';
    END IF;

    UPDATE pagos
       SET estado = 'ANULADO', motivo_anulacion = p_motivo,
           anulado_por = p_id_usuario, anulado_el = NOW()
     WHERE id_pago = p_id_pago;

    -- Revierte la condonación (si fue una cancelación)
    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT id_prestamo, id_cuota, id_pago, v_fecha, tipo, -importe,
           'Reversa por anulación de pago', p_id_usuario
      FROM movimientos
     WHERE id_pago = p_id_pago AND tipo IN ('QUITA_INTERES', 'QUITA_IVA', 'CREDITO_CAPITAL');

    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (v_prestamo, p_id_pago, v_fecha, 'ANULACION_PAGO', v_importe, p_motivo, p_id_usuario);

    CALL sp_actualizar_estados(v_prestamo);

    COMMIT;
END $$

DELIMITER ;
