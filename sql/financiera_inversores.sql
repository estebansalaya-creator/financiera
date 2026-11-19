-- =====================================================================
--  FINANCIERA - Inversores de capital y liquidación de intereses
--  Correr DESPUÉS de financiera_nucleo.sql, financiera_cobranzas.sql y
--  financiera_cotizaciones.sql (usa fn_cotizacion).
--  MySQL 8.0.16+ (probado también en MariaDB 10.x). Se puede correr más
--  de una vez (las tablas no se recrean; los procedures sí).
--
--  Contenido
--    inversores                        quién pone la plata
--    inversiones                       cada aporte: modalidad, moneda,
--                                      capital, plazo y tasa o participación
--    inversiones_prestamos             a qué préstamos fondea un aporte en
--                                      participación y por cuánto
--    inversiones_liquidaciones         la liquidación al vencimiento (o
--                                      anticipada): capital, interés, renovación
--    inversiones_liquidaciones_detalle participación: préstamo por préstamo
--    v_inversiones_devengado           interés devengado a hoy de las vigentes
--    sp_inversion_calc                 motor ÚNICO del interés a una fecha
--    sp_inversion_calcular             el mismo cálculo, como consulta
--    sp_liquidar_inversion             liquida y, si se pide, renueva
--    sp_anular_liquidacion_inversion   deshace una liquidación no pagada
--
--  Modalidades
--    TASA_FIJA      interés simple pactado: capital × TNA × días / 365.
--                   ARS o USD. No depende de lo que cobre la financiera.
--    PARTICIPACION  el aporte fondea préstamos concretos (en pesos). Gana
--                   pct_participacion del INTERÉS (sin IVA ni punitorios)
--                   efectivamente cobrado de esos préstamos, en proporción
--                   a lo fondeado (monto / capital del préstamo), por los
--                   pagos con fecha posterior al inicio y hasta la fecha de
--                   liquidación. El capital se devuelve al vencimiento.
--  Los intereses se liquidan al vencimiento del aporte (o antes, si se
--  rescata: hasta esa fecha). Sin retenciones por ahora.
--  Cada liquidación guarda la cotización BCRA de su fecha (pesos por
--  unidad; 1 en pesos) para mostrar el equivalente en pesos de los aportes
--  en dólares: queda fija aunque después cambie la cotización.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inversores (
    id_inversor     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo            ENUM('F','J') NOT NULL,
    cuit            CHAR(11)     NOT NULL,
    denominacion    VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NULL,
    telefono        VARCHAR(30)  NULL,
    domicilio       VARCHAR(200) NULL,
    cbu             VARCHAR(22)  NULL,
    alias_cbu       VARCHAR(40)  NULL,
    estado          ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    observaciones   VARCHAR(255) NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    PRIMARY KEY (id_inversor),
    UNIQUE KEY uq_inversores_cuit (cuit),
    CONSTRAINT fk_inv_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inversiones (
    id_inversion        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nro_inversion       VARCHAR(20)  NULL,              -- I-000001 (se completa al dar de alta)
    id_inversor         INT UNSIGNED NOT NULL,
    modalidad           ENUM('TASA_FIJA','PARTICIPACION') NOT NULL,
    moneda              ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    capital             DECIMAL(15,2) NOT NULL,
    fecha_inicio        DATE         NOT NULL,
    fecha_vencimiento   DATE         NOT NULL,
    tna                 DECIMAL(9,6) NULL,              -- TASA_FIJA: 0.400000 = 40 %
    pct_participacion   DECIMAL(9,6) NULL,              -- PARTICIPACION: 0.500000 = 50 % del interés cobrado
    estado              ENUM('VIGENTE','LIQUIDADA','ANULADA') NOT NULL DEFAULT 'VIGENTE',
    id_inversion_origen INT UNSIGNED NULL,              -- renovación de otra
    observaciones       VARCHAR(255) NULL,
    creado_el           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por          INT UNSIGNED NULL,
    anulado_el          DATETIME     NULL,
    motivo_anulacion    VARCHAR(200) NULL,
    PRIMARY KEY (id_inversion),
    UNIQUE KEY uq_inversiones_nro (nro_inversion),
    KEY ix_inversiones_inversor (id_inversor),
    KEY ix_inversiones_vto (estado, fecha_vencimiento),
    CONSTRAINT fk_inv_inversor FOREIGN KEY (id_inversor)         REFERENCES inversores (id_inversor),
    CONSTRAINT fk_inv_origen   FOREIGN KEY (id_inversion_origen) REFERENCES inversiones (id_inversion),
    CONSTRAINT fk_inv_creado   FOREIGN KEY (creado_por)          REFERENCES usuarios (id_usuario),
    CONSTRAINT ck_inv_capital  CHECK (capital > 0),
    CONSTRAINT ck_inv_fechas   CHECK (fecha_vencimiento > fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inversiones_prestamos (
    id_inversion    INT UNSIGNED NOT NULL,
    id_prestamo     INT UNSIGNED NOT NULL,
    monto           DECIMAL(15,2) NOT NULL,             -- cuánto del préstamo fondea este aporte
    PRIMARY KEY (id_inversion, id_prestamo),
    KEY ix_invp_prestamo (id_prestamo),
    CONSTRAINT fk_invp_inversion FOREIGN KEY (id_inversion) REFERENCES inversiones (id_inversion),
    CONSTRAINT fk_invp_prestamo  FOREIGN KEY (id_prestamo)  REFERENCES prestamos (id_prestamo),
    CONSTRAINT ck_invp_monto     CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inversiones_liquidaciones (
    id_liquidacion      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_inversion        INT UNSIGNED NOT NULL,
    fecha               DATE         NOT NULL,
    dias                INT          NOT NULL,
    moneda              ENUM('ARS','USD') NOT NULL,
    capital             DECIMAL(15,2) NOT NULL,
    interes             DECIMAL(15,2) NOT NULL,
    total               DECIMAL(15,2) NOT NULL,
    anticipada          TINYINT(1)   NOT NULL DEFAULT 0, -- antes del vencimiento
    cotizacion          DECIMAL(18,8) NULL,                 -- $ por unidad a la fecha (BCRA); 1 en pesos
    renovacion          ENUM('NO','CAPITAL','TOTAL') NOT NULL DEFAULT 'NO',
    reinvertido         DECIMAL(15,2) NOT NULL DEFAULT 0,
    a_pagar             DECIMAL(15,2) NOT NULL,
    id_inversion_nueva  INT UNSIGNED NULL,
    estado              ENUM('A_PAGAR','PAGADA','ANULADA') NOT NULL DEFAULT 'A_PAGAR',
    pagado_el           DATE         NULL,
    medio               VARCHAR(20)  NULL,
    referencia          VARCHAR(100) NULL,
    creado_el           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por          INT UNSIGNED NULL,
    anulado_el          DATETIME     NULL,
    motivo_anulacion    VARCHAR(200) NULL,
    PRIMARY KEY (id_liquidacion),
    KEY ix_invl_inversion (id_inversion),
    KEY ix_invl_estado (estado, fecha),
    CONSTRAINT fk_invl_inversion FOREIGN KEY (id_inversion)       REFERENCES inversiones (id_inversion),
    CONSTRAINT fk_invl_nueva     FOREIGN KEY (id_inversion_nueva) REFERENCES inversiones (id_inversion),
    CONSTRAINT fk_invl_usuario   FOREIGN KEY (creado_por)         REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inversiones_liquidaciones_detalle (
    id_liquidacion   INT UNSIGNED NOT NULL,
    id_prestamo      INT UNSIGNED NOT NULL,
    monto_asignado   DECIMAL(15,2) NOT NULL,
    capital_prestamo DECIMAL(15,2) NOT NULL,
    interes_cobrado  DECIMAL(15,2) NOT NULL,            -- del préstamo entero, en el período
    proporcion       DECIMAL(9,6)  NOT NULL,            -- monto_asignado / capital_prestamo
    interes_inversor DECIMAL(15,2) NOT NULL,
    PRIMARY KEY (id_liquidacion, id_prestamo),
    CONSTRAINT fk_invld_liq      FOREIGN KEY (id_liquidacion) REFERENCES inversiones_liquidaciones (id_liquidacion),
    CONSTRAINT fk_invld_prestamo FOREIGN KEY (id_prestamo)    REFERENCES prestamos (id_prestamo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instalaciones anteriores: agrega la columna si falta
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inversiones_liquidaciones' AND COLUMN_NAME = 'cotizacion');
SET @ddl := IF(@falta, 'ALTER TABLE inversiones_liquidaciones ADD COLUMN cotizacion DECIMAL(18,8) NULL AFTER anticipada', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
 ('INVERSION_DIAS_ANIO',   '365', 'Base de días del año para el interés de los aportes a tasa fija'),
 ('NRO_INVERSION_PREFIJO', 'I-',  'Prefijo del número de aporte (I-000123)');

-- ---------------------------------------------------------------------
-- Interés devengado a hoy de cada aporte vigente (para listados).
-- Es el mismo cálculo que sp_inversion_calc con p_fecha = hoy (tope:
-- el vencimiento).
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_inversiones_devengado AS
SELECT i.id_inversion,
       DATEDIFF(LEAST(CURDATE(), i.fecha_vencimiento), i.fecha_inicio) AS dias,
       CASE i.modalidad
            WHEN 'TASA_FIJA' THEN
                ROUND(i.capital * i.tna * GREATEST(DATEDIFF(LEAST(CURDATE(), i.fecha_vencimiento), i.fecha_inicio), 0)
                      / COALESCE((SELECT CAST(valor AS UNSIGNED) FROM parametros WHERE clave = 'INVERSION_DIAS_ANIO'), 365), 2)
            ELSE
                COALESCE((SELECT SUM(ROUND(
                                   COALESCE((SELECT SUM(pi.importe)
                                               FROM pagos pg
                                               JOIN pagos_imputacion pi ON pi.id_pago = pg.id_pago AND pi.concepto = 'INTERES'
                                              WHERE pg.id_prestamo = ip.id_prestamo AND pg.estado = 'APLICADO'
                                                AND pg.fecha > i.fecha_inicio
                                                AND pg.fecha <= LEAST(CURDATE(), i.fecha_vencimiento)), 0)
                                   * ip.monto / p.capital * i.pct_participacion, 2))
                            FROM inversiones_prestamos ip
                            JOIN prestamos p ON p.id_prestamo = ip.id_prestamo
                           WHERE ip.id_inversion = i.id_inversion), 0)
       END AS interes_devengado,
       CASE WHEN i.modalidad = 'TASA_FIJA' THEN
                ROUND(i.capital * i.tna * DATEDIFF(i.fecha_vencimiento, i.fecha_inicio)
                      / COALESCE((SELECT CAST(valor AS UNSIGNED) FROM parametros WHERE clave = 'INVERSION_DIAS_ANIO'), 365), 2)
       END AS interes_al_vencimiento
  FROM inversiones i
 WHERE i.estado = 'VIGENTE';

DELIMITER $$

-- ---------------------------------------------------------------------
-- sp_inversion_calc
--   Motor ÚNICO. Calcula el interés de un aporte a p_fecha (tope: el
--   vencimiento) y deja:
--     tmp_inv      una fila: capital, días, interés, total
--     tmp_inv_det  participación: una fila por préstamo fondeado
--   No devuelve resultados ni escribe: lo usan sp_inversion_calcular
--   (consulta) y sp_liquidar_inversion.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_inversion_calc $$
CREATE PROCEDURE sp_inversion_calc(IN p_id_inversion INT UNSIGNED, IN p_fecha DATE)
BEGIN
    DECLARE v_mod    VARCHAR(20);
    DECLARE v_cap    DECIMAL(15,2);
    DECLARE v_ini    DATE;
    DECLARE v_vto    DATE;
    DECLARE v_tna    DECIMAL(9,6);
    DECLARE v_pct    DECIMAL(9,6);
    DECLARE v_base   INT;
    DECLARE v_dias   INT;
    DECLARE v_int    DECIMAL(15,2);

    SELECT modalidad, capital, fecha_inicio, fecha_vencimiento, tna, pct_participacion
      INTO v_mod, v_cap, v_ini, v_vto, v_tna, v_pct
      FROM inversiones WHERE id_inversion = p_id_inversion;

    IF v_mod IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Aporte inexistente';
    END IF;
    IF p_fecha IS NULL OR p_fecha < v_ini THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La fecha es anterior al inicio del aporte';
    END IF;

    SET v_base = COALESCE((SELECT CAST(valor AS UNSIGNED) FROM parametros WHERE clave = 'INVERSION_DIAS_ANIO'), 365);
    -- Después del vencimiento no corre más interés: se liquida hasta el vencimiento
    SET v_dias = DATEDIFF(LEAST(p_fecha, v_vto), v_ini);

    DROP TEMPORARY TABLE IF EXISTS tmp_inv_det;
    CREATE TEMPORARY TABLE tmp_inv_det (
        id_prestamo      INT UNSIGNED PRIMARY KEY,
        monto_asignado   DECIMAL(15,2),
        capital_prestamo DECIMAL(15,2),
        interes_cobrado  DECIMAL(15,2),
        proporcion       DECIMAL(9,6),
        interes_inversor DECIMAL(15,2)
    );

    IF v_mod = 'TASA_FIJA' THEN
        SET v_int = ROUND(v_cap * v_tna * v_dias / v_base, 2);
    ELSE
        INSERT INTO tmp_inv_det
        SELECT ip.id_prestamo, ip.monto, p.capital,
               COALESCE((SELECT SUM(pi.importe)
                           FROM pagos pg
                           JOIN pagos_imputacion pi ON pi.id_pago = pg.id_pago AND pi.concepto = 'INTERES'
                          WHERE pg.id_prestamo = ip.id_prestamo AND pg.estado = 'APLICADO'
                            AND pg.fecha > v_ini AND pg.fecha <= LEAST(p_fecha, v_vto)), 0),
               ROUND(ip.monto / p.capital, 6),
               0
          FROM inversiones_prestamos ip
          JOIN prestamos p ON p.id_prestamo = ip.id_prestamo
         WHERE ip.id_inversion = p_id_inversion;

        UPDATE tmp_inv_det
           SET interes_inversor = ROUND(interes_cobrado * monto_asignado / capital_prestamo * v_pct, 2);

        SELECT COALESCE(SUM(interes_inversor), 0) INTO v_int FROM tmp_inv_det;
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_inv;
    CREATE TEMPORARY TABLE tmp_inv AS
    SELECT p_id_inversion AS id_inversion, v_mod AS modalidad, v_cap AS capital, v_ini AS fecha_inicio,
           v_vto AS fecha_vencimiento, p_fecha AS fecha, v_dias AS dias, v_tna AS tna, v_pct AS pct_participacion,
           v_int AS interes, v_cap + v_int AS total, IF(p_fecha < v_vto, 1, 0) AS anticipada;
END $$

-- ---------------------------------------------------------------------
-- sp_inversion_calcular: consulta. Devuelve 1) el resumen y 2) el
-- detalle por préstamo (vacío en tasa fija).
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_inversion_calcular $$
CREATE PROCEDURE sp_inversion_calcular(IN p_id_inversion INT UNSIGNED, IN p_fecha DATE)
BEGIN
    CALL sp_inversion_calc(p_id_inversion, COALESCE(p_fecha, CURDATE()));
    SELECT * FROM tmp_inv;
    SELECT d.*, p.nro_prestamo, p.estado AS estado_prestamo, c.denominacion AS cliente
      FROM tmp_inv_det d
      JOIN prestamos p ON p.id_prestamo = d.id_prestamo
      JOIN clientes c ON c.id_cliente = p.id_cliente
     ORDER BY p.nro_prestamo;
END $$

-- ---------------------------------------------------------------------
-- sp_liquidar_inversion
--   Liquida un aporte vigente a p_fecha (el vencimiento, o antes si es un
--   rescate anticipado; no puede ser futura).
--   p_renovacion: NO (se paga todo), CAPITAL (se reinvierte el capital y
--   se paga el interés) o TOTAL (se reinvierte capital + interés).
--   La renovación es un aporte nuevo con las mismas condiciones, desde
--   p_fecha y por p_dias (NULL = el mismo plazo que el original); en
--   participación sigue fondeando los préstamos que sigan vigentes.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_liquidar_inversion $$
CREATE PROCEDURE sp_liquidar_inversion(
    IN  p_id_inversion INT UNSIGNED,
    IN  p_fecha        DATE,
    IN  p_renovacion   VARCHAR(10),
    IN  p_dias         INT,
    IN  p_id_usuario   INT UNSIGNED,
    OUT p_id_liq       INT UNSIGNED,
    OUT p_id_nueva     INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_cap    DECIMAL(15,2);
    DECLARE v_int    DECIMAL(15,2);
    DECLARE v_dias   INT;
    DECLARE v_antic  TINYINT;
    DECLARE v_reinv  DECIMAL(15,2) DEFAULT 0;
    DECLARE v_plazo  INT;
    DECLARE v_pref   VARCHAR(10);
    DECLARE v_msg    VARCHAR(255);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_renovacion = COALESCE(p_renovacion, 'NO');
    SET p_id_nueva = NULL;

    IF p_renovacion NOT IN ('NO', 'CAPITAL', 'TOTAL') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Renovación inválida: NO, CAPITAL o TOTAL';
    END IF;
    IF p_fecha > CURDATE() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se puede liquidar con fecha futura';
    END IF;

    START TRANSACTION;

    SELECT estado, DATEDIFF(fecha_vencimiento, fecha_inicio) INTO v_estado, v_plazo
      FROM inversiones WHERE id_inversion = p_id_inversion FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Aporte inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('El aporte no está vigente (', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    CALL sp_inversion_calc(p_id_inversion, p_fecha);
    SELECT capital, interes, dias, anticipada INTO v_cap, v_int, v_dias, v_antic FROM tmp_inv;

    IF v_dias < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La liquidación tiene que ser posterior al inicio del aporte';
    END IF;

    SET v_reinv = CASE p_renovacion WHEN 'CAPITAL' THEN v_cap WHEN 'TOTAL' THEN v_cap + v_int ELSE 0 END;
    SET p_dias = COALESCE(p_dias, v_plazo);
    IF p_renovacion <> 'NO' AND p_dias < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El plazo de la renovación tiene que ser de al menos un día';
    END IF;

    INSERT INTO inversiones_liquidaciones (id_inversion, fecha, dias, moneda, capital, interes, total, anticipada, cotizacion,
                                           renovacion, reinvertido, a_pagar, estado, medio, pagado_el, creado_por)
    SELECT p_id_inversion, p_fecha, v_dias, i.moneda, v_cap, v_int, v_cap + v_int, v_antic,
           IF(i.moneda = 'ARS', 1, fn_cotizacion(i.moneda, p_fecha)),
           p_renovacion, v_reinv, v_cap + v_int - v_reinv,
           IF(v_cap + v_int - v_reinv = 0, 'PAGADA', 'A_PAGAR'),
           IF(v_cap + v_int - v_reinv = 0, 'RENOVACION', NULL),
           IF(v_cap + v_int - v_reinv = 0, p_fecha, NULL),
           p_id_usuario
      FROM inversiones i WHERE i.id_inversion = p_id_inversion;
    SET p_id_liq = LAST_INSERT_ID();

    INSERT INTO inversiones_liquidaciones_detalle
    SELECT p_id_liq, id_prestamo, monto_asignado, capital_prestamo, interes_cobrado, proporcion, interes_inversor
      FROM tmp_inv_det;

    UPDATE inversiones SET estado = 'LIQUIDADA' WHERE id_inversion = p_id_inversion;

    IF p_renovacion <> 'NO' THEN
        SET v_pref = COALESCE((SELECT valor FROM parametros WHERE clave = 'NRO_INVERSION_PREFIJO'), 'I-');
        INSERT INTO inversiones (id_inversor, modalidad, moneda, capital, fecha_inicio, fecha_vencimiento,
                                 tna, pct_participacion, id_inversion_origen, observaciones, creado_por)
        SELECT id_inversor, modalidad, moneda, v_reinv, p_fecha, DATE_ADD(p_fecha, INTERVAL p_dias DAY),
               tna, pct_participacion, id_inversion, CONCAT('Renovación de ', nro_inversion), p_id_usuario
          FROM inversiones WHERE id_inversion = p_id_inversion;
        SET p_id_nueva = LAST_INSERT_ID();
        UPDATE inversiones SET nro_inversion = CONCAT(v_pref, LPAD(p_id_nueva, 6, '0')) WHERE id_inversion = p_id_nueva;

        -- Participación: sigue fondeando los préstamos vigentes (hasta el capital nuevo)
        INSERT INTO inversiones_prestamos (id_inversion, id_prestamo, monto)
        SELECT p_id_nueva, ip.id_prestamo, ip.monto
          FROM inversiones_prestamos ip
          JOIN prestamos p ON p.id_prestamo = ip.id_prestamo AND p.estado = 'VIGENTE'
         WHERE ip.id_inversion = p_id_inversion;

        UPDATE inversiones_liquidaciones SET id_inversion_nueva = p_id_nueva WHERE id_liquidacion = p_id_liq;
    END IF;

    COMMIT;
END $$

-- ---------------------------------------------------------------------
-- sp_anular_liquidacion_inversion
--   Deshace una liquidación que todavía no se pagó (o que fue toda
--   renovación): la liquidación queda ANULADA, el aporte vuelve a
--   VIGENTE y la renovación, si hubo, se anula. No se puede si la
--   renovación ya tiene su propia liquidación.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_anular_liquidacion_inversion $$
CREATE PROCEDURE sp_anular_liquidacion_inversion(
    IN p_id_liquidacion INT UNSIGNED,
    IN p_motivo         VARCHAR(200),
    IN p_id_usuario     INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_medio  VARCHAR(20);
    DECLARE v_inv    INT UNSIGNED;
    DECLARE v_nueva  INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR CHAR_LENGTH(TRIM(p_motivo)) < 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    START TRANSACTION;

    SELECT estado, medio, id_inversion, id_inversion_nueva INTO v_estado, v_medio, v_inv, v_nueva
      FROM inversiones_liquidaciones WHERE id_liquidacion = p_id_liquidacion FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Liquidación inexistente';
    END IF;
    IF v_estado = 'ANULADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La liquidación ya está anulada';
    END IF;
    IF v_estado = 'PAGADA' AND COALESCE(v_medio, '') <> 'RENOVACION' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La liquidación ya se pagó: no se puede anular';
    END IF;
    IF v_nueva IS NOT NULL AND EXISTS (SELECT 1 FROM inversiones_liquidaciones
                                        WHERE id_inversion = v_nueva AND estado <> 'ANULADA') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La renovación ya se liquidó: anulá primero esa liquidación';
    END IF;

    UPDATE inversiones_liquidaciones
       SET estado = 'ANULADA', anulado_el = NOW(), motivo_anulacion = p_motivo
     WHERE id_liquidacion = p_id_liquidacion;

    UPDATE inversiones SET estado = 'VIGENTE' WHERE id_inversion = v_inv;

    IF v_nueva IS NOT NULL THEN
        UPDATE inversiones
           SET estado = 'ANULADA', anulado_el = NOW(), motivo_anulacion = CONCAT('Se anuló la liquidación que la originó: ', p_motivo)
         WHERE id_inversion = v_nueva;
    END IF;

    COMMIT;
END $$

DELIMITER ;

-- Liquidaciones anteriores a la columna: la cotización de su fecha
UPDATE inversiones_liquidaciones
   SET cotizacion = IF(moneda = 'ARS', 1, fn_cotizacion(moneda, fecha))
 WHERE cotizacion IS NULL;
