-- =====================================================================
--  FINANCIERA - Punitorios configurables y parámetros de Reggio
--  (Parámetros del Sistema de Reggio: punitorio fijo, cuota máxima y
--  condiciones de refinanciación). Correr desde CMD (tiene DELIMITER):
--    mysql -u root -p --default-character-set=utf8mb4 financiera < financiera_punitorios_parametros.sql
--  Se puede correr más de una vez.
--
--  PUNITORIO por tipo de asociado (tipos_asociado.punitorio_modo):
--    TASA  como hasta ahora: la tasa punitoria del préstamo (la de la
--          solapa Tasas o, si no tiene, TNA x PUNITORIO_PCT_TNA), por día
--          de atraso sobre PUNITORIO_BASE.
--    FIJO  como Reggio: punitorio_fijo por cada mes o fracción de atraso
--          de cada cuota impaga, IVA incluido (Reggio: Nación, Cofincred,
--          Staff, Supervielle y Patagonia 458; Neuquén 1.148).
--    En los dos casos, después de DIAS_GRACIA_MORA días del vencimiento.
--
--  REFINANCIACION (refinanciacion_condiciones): cuántas cuotas pagas
--  necesita un préstamo, según su plazo, para poder refinanciarse.
--  CUOTA_MAXIMA (parametros): tope del valor de la cuota; 0 = sin tope.
--  Pasarse es una advertencia en los controles de la solicitud.
-- =====================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_punitorios $$
CREATE PROCEDURE tmp_punitorios()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'tipos_asociado' AND column_name = 'punitorio_modo') THEN
        ALTER TABLE tipos_asociado
            ADD COLUMN punitorio_modo ENUM('TASA','FIJO') NOT NULL DEFAULT 'TASA'
                COMMENT 'TASA: tasa punitoria por día. FIJO: punitorio_fijo por mes o fracción de atraso',
            ADD COLUMN punitorio_fijo DECIMAL(15,2) NULL COMMENT 'Por mes o fracción de atraso de cada cuota, IVA incluido';
        -- Los valores de Reggio (Parámetros del Sistema).
        UPDATE tipos_asociado
           SET punitorio_modo = 'FIJO',
               punitorio_fijo = IF(nombre LIKE 'NEUQUEN%', 1148, 458)
         WHERE nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%'
            OR nombre LIKE 'SUPERVIELLE%' OR nombre LIKE 'PATAGONIA%' OR nombre LIKE 'NEUQUEN%';
    END IF;
END $$
CALL tmp_punitorios() $$
DROP PROCEDURE IF EXISTS tmp_punitorios $$

DELIMITER ;

CREATE TABLE IF NOT EXISTS refinanciacion_condiciones (
    plazo          SMALLINT UNSIGNED NOT NULL COMMENT 'Cuotas del préstamo original',
    cuotas_pagas   SMALLINT UNSIGNED NOT NULL COMMENT 'Cuotas pagas que necesita para refinanciar',
    modificado_por INT UNSIGNED NULL,
    modificado_el  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (plazo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Cuotas pagas necesarias para refinanciar, por plazo';

INSERT IGNORE INTO refinanciacion_condiciones (plazo, cuotas_pagas) VALUES
  (6, 4), (9, 6), (12, 9), (13, 9), (15, 12), (18, 12), (24, 21);

-- Reggio tiene 3.000 como «Valor cuota máximo para crédito»; con las cuotas
-- de hoy parece un valor viejo: queda en 0 (sin tope) hasta confirmarlo.
INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
  ('CUOTA_MAXIMA', '0', 'Valor máximo de la cuota de un préstamo (0 = sin tope): pasarse es una advertencia en los controles');

-- Menú: Tablas y parámetros › Parámetros del sistema.
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
SELECT 56, 50, 'Parámetros del sistema', 'parametros.php', 'ajustes', 9, 2, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu WHERE id_menu = 56 OR url = 'parametros.php');
INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT 56, id_rol FROM menu_roles WHERE id_menu = 51;

DELIMITER $$

-- ---------------------------------------------------------------------
-- sp_calc_deuda: la de financiera_cobranzas.sql, con el punitorio fijo.
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
    DECLARE v_pun_modo  VARCHAR(10);
    DECLARE v_pun_fijo  DECIMAL(15,2);

    -- El punitorio es el del tipo de asociado: por tasa (la del préstamo) o
    -- un monto fijo por mes o fracción de atraso de cada cuota, IVA incluido.
    SELECT p.tasa_punitoria, p.alicuota_iva, p.fecha_liquidacion,
           COALESCE(t.punitorio_modo, 'TASA'), t.punitorio_fijo
      INTO v_tasa_pun, v_alic, v_fliq, v_pun_modo, v_pun_fijo
      FROM prestamos p
      LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = p.id_tipo_asociado
     WHERE p.id_prestamo = p_id_prestamo;

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
        cred_cap      DECIMAL(15,2) DEFAULT 0,  -- parte del crédito que baja el capital de esta cuota
        punit_dev     DECIMAL(15,2) DEFAULT 0,  -- punitorio + IVA ya devengado (monto fijo)
        punit_tot     DECIMAL(15,2) DEFAULT 0   -- punitorio + IVA nuevo (monto fijo)
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

    IF v_pun_modo = 'FIJO' THEN
    -- Monto fijo: v_pun_fijo por cada mes o fracción desde el vencimiento,
    -- menos lo que ya se devengó; se separa el IVA que trae incluido.
    UPDATE tmp_cs t
       SET t.punit_dev = COALESCE((SELECT SUM(mv.importe) FROM movimientos mv
                                    WHERE mv.id_cuota = t.id_cuota AND mv.tipo IN ('PUNITORIO', 'IVA_PUNITORIO')), 0);
    UPDATE tmp_cs t
       SET t.devenga = 1,
           t.punit_tot = GREATEST(0, ROUND(COALESCE(v_pun_fijo, 0)
                 * (TIMESTAMPDIFF(MONTH, t.vencimiento, p_fecha)
                    + IF(DATE_ADD(t.vencimiento, INTERVAL TIMESTAMPDIFF(MONTH, t.vencimiento, p_fecha) MONTH) < p_fecha, 1, 0)), 2)
                 - t.punit_dev)
     WHERE t.vencimiento < p_fecha
       AND DATEDIFF(p_fecha, t.vencimiento) > v_gracia
       AND t.capital_pend + t.interes_pend + t.iva_pend > 0;
    UPDATE tmp_cs
       SET punit_nuevo = ROUND(punit_tot / (1 + v_alic), 2),
           ivap_nuevo  = punit_tot - ROUND(punit_tot / (1 + v_alic), 2)
     WHERE punit_tot > 0;
    ELSE
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
    END IF;

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

DELIMITER ;
