-- =====================================================================
-- financiera: procedimientos que usaban una tabla temporal más de una vez
-- en la misma consulta. MySQL no lo permite en todas las versiones
-- («Can't reopen table: tmp_cuadro»): en Railway falla y en el servidor
-- local no. Se leen primero los totales a variables y la consulta final
-- toca la tabla temporal una sola vez. Mismo resultado que antes.
--   sp_simular_prestamo   (tmp_cuadro)
--   sp_liquidar_prestamo  (tmp_cuadro)
--   sp_estado_cuenta      (tmp_cs)
-- Se puede volver a correr. En DBeaver: Ejecutar script (Alt+X).
-- =====================================================================

USE financiera;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_simular_prestamo $$
CREATE PROCEDURE sp_simular_prestamo(
    IN p_id_producto INT UNSIGNED,
    IN p_capital     DECIMAL(15,2),
    IN p_plazo       SMALLINT,
    IN p_fecha       DATE,
    IN p_primer_vto  DATE
)
BEGIN
    DECLARE v_id_tasa  INT UNSIGNED;
    DECLARE v_tna      DECIMAL(9,6);
    DECLARE v_iva      DECIMAL(6,4);
    DECLARE v_gasto    DECIMAL(6,4);
    DECLARE v_pmin, v_pmax SMALLINT;
    DECLARE v_mmin, v_mmax DECIMAL(15,2);
    DECLARE v_activo   TINYINT;
    DECLARE v_cuota, v_int, v_ivat, v_total DECIMAL(15,2);

    SET p_fecha      = COALESCE(p_fecha, CURDATE());
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

    SELECT alicuota_iva, gasto_otorg_pct, plazo_min, plazo_max, monto_min, monto_max, activo
      INTO v_iva, v_gasto, v_pmin, v_pmax, v_mmin, v_mmax, v_activo
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

    SET v_id_tasa = fn_tasa_vigente(p_id_producto, p_plazo, p_capital, p_fecha);
    IF v_id_tasa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay tasa vigente para ese plazo y monto';
    END IF;
    SELECT tna INTO v_tna FROM tasas_vigencia WHERE id_tasa = v_id_tasa;

    CALL sp_cuadro_frances(p_capital, p_plazo, v_tna, v_iva, p_primer_vto);

    SELECT SUM(IF(nro_cuota = 1, importe, 0)), SUM(interes), SUM(iva_interes), SUM(importe)
      INTO v_cuota, v_int, v_ivat, v_total
      FROM tmp_cuadro;

    SELECT p_capital                                   AS capital,
           p_plazo                                     AS plazo,
           v_tna                                       AS tna,
           ROUND(v_tna / 12, 6)                        AS tem,
           ROUND(POW(1 + v_tna / 12, 12) - 1, 6)       AS tea,
           ROUND(p_capital * v_gasto, 2)               AS gastos_otorg,
           p_capital - ROUND(p_capital * v_gasto, 2)   AS neto_desembolso,
           v_cuota                                     AS valor_cuota,
           v_int                                       AS total_intereses,
           v_ivat                                      AS total_iva,
           v_total                                     AS total_a_pagar;

    SELECT * FROM tmp_cuadro ORDER BY nro_cuota;
END $$

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
        tasa_punitoria, gastos_otorg, neto_desembolso, valor_cuota, total_intereses, total_iva,
        telemarketing, id_comision_regla, comision_pct, comision_monto, creado_por)
    VALUES (p_nro_prestamo, p_id_cliente, p_id_producto, p_id_vendedor, v_id_tasa,
            p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
            v_punit, v_gastos, p_capital - v_gastos, v_cuota, v_int, v_ivat,
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

    COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_estado_cuenta $$
CREATE PROCEDURE sp_estado_cuenta(
    IN p_id_prestamo INT UNSIGNED,
    IN p_fecha       DATE,
    IN p_modo        VARCHAR(20)
)
BEGIN
    DECLARE v_condonado, v_credito DECIMAL(15,2);

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_modo  = COALESCE(p_modo, 'NORMAL');

    CALL sp_calc_deuda(p_id_prestamo, p_fecha, p_modo);

    SELECT SUM(quita_int + quita_iva), SUM(cred_cap)
      INTO v_condonado, v_credito
      FROM tmp_cs;

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
           v_condonado                                                 AS interes_condonado,
           v_credito                                                   AS credito_interes_pagado
      FROM tmp_deuda;
END $$

DELIMITER ;
