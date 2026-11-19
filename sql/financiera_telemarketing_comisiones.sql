-- =====================================================================
--  FINANCIERA - Telemarketing en comisiones + importación histórica
--  Correr DESPUÉS de financiera_nucleo.sql y financiera_cobranzas.sql
--  MySQL 8
--
--  Por qué
--    En las liquidaciones reales (jun-2026) el % de comisión depende del
--    vendedor, del tipo de asociado (producto) y de si la operación vino
--    por telemarketing. Ej.: NACION 18 %, con telemarketing 9 % para
--    algunos vendedores y 18 % para otros.
--
--  Contenido
--    - prestamos.telemarketing          (dato de la operación, congelado)
--    - comision_reglas.telemarketing    (NULL = cualquiera, 0 = NO, 1 = SI)
--    - fn_comision_regla                (nuevo parámetro p_telemarketing)
--    - sp_liquidar_prestamo             (nuevo parámetro p_telemarketing,
--                                        después de p_comision_pct)
--    - comisiones_importadas            (detalle de las planillas de
--                                        liquidación del sistema actual)
--    - v_comisiones_importadas_resumen  (equivalente a la hoja "Resumen"
--                                        de ModuloResumen.bas)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE prestamos
    ADD COLUMN telemarketing TINYINT(1) NOT NULL DEFAULT 0 AFTER id_tasa;

ALTER TABLE comision_reglas
    ADD COLUMN telemarketing TINYINT(1) NULL AFTER id_zona;   -- NULL = cualquiera

-- Detalle de las planillas de liquidación de comisiones del sistema actual.
-- Una fila por renglón de la planilla (sin la fila "Total monto:").
-- Las filas cargadas de más en el origen no se importan.
CREATE TABLE IF NOT EXISTS comisiones_importadas (
    id_importada    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo         CHAR(7)      NOT NULL,               -- AAAA-MM
    archivo         VARCHAR(150) NOT NULL,
    fila_origen     INT UNSIGNED NOT NULL,               -- fila en la planilla
    id_vendedor     INT UNSIGNED NOT NULL,
    id_producto     INT UNSIGNED NOT NULL,
    fecha           DATE         NOT NULL,               -- "Fecha inicio"
    tipo_asociado   VARCHAR(60)  NOT NULL,
    apellido        VARCHAR(80)  NOT NULL,
    nombre          VARCHAR(80)  NOT NULL,
    monto           DECIMAL(15,2) NOT NULL,
    reparticion     VARCHAR(80)  NULL,
    comision        DECIMAL(15,2) NOT NULL,
    telemarketing   TINYINT(1)   NOT NULL,
    porcentaje      DECIMAL(9,6) AS (ROUND(comision / NULLIF(monto, 0), 6)) STORED,
    importado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_importada),
    UNIQUE KEY uq_imp_origen (archivo, fila_origen),
    KEY ix_imp_vendedor_periodo (id_vendedor, periodo),
    CONSTRAINT fk_impc_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_impc_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un renglón por vendedor y período, como la hoja "Resumen" de la macro.
CREATE OR REPLACE VIEW v_comisiones_importadas_resumen AS
SELECT i.periodo, v.codigo, v.nombre AS vendedor,
       COUNT(*)                                        AS registros,
       SUM(i.monto)                                    AS total_monto,
       SUM(i.comision)                                 AS total_comision,
       ROUND(SUM(i.comision) / NULLIF(SUM(i.monto), 0), 6) AS pct_promedio,
       COUNT(DISTINCT i.porcentaje)                    AS porcentajes_distintos,
       GROUP_CONCAT(DISTINCT CONCAT(FORMAT(i.porcentaje * 100, 2), '%')
                    ORDER BY i.porcentaje SEPARATOR ' / ') AS porcentajes
  FROM comisiones_importadas i
  JOIN vendedores v ON v.id_vendedor = i.id_vendedor
 GROUP BY i.periodo, v.codigo, v.nombre;

DELIMITER $$

DROP FUNCTION IF EXISTS fn_comision_regla $$
CREATE FUNCTION fn_comision_regla(
    p_id_vendedor   INT UNSIGNED,
    p_id_producto   INT UNSIGNED,
    p_plazo         SMALLINT,
    p_monto         DECIMAL(15,2),
    p_fecha         DATE,
    p_telemarketing TINYINT
) RETURNS INT UNSIGNED
READS SQL DATA
BEGIN
    DECLARE v_id   INT UNSIGNED DEFAULT NULL;
    DECLARE v_zona INT UNSIGNED DEFAULT NULL;

    SELECT id_zona INTO v_zona FROM vendedores WHERE id_vendedor = p_id_vendedor;

    SELECT r.id_regla INTO v_id
      FROM comision_reglas r
     WHERE r.activo = 1
       AND r.vigencia_desde <= p_fecha
       AND (r.vigencia_hasta IS NULL OR r.vigencia_hasta >= p_fecha)
       AND (r.id_producto   IS NULL OR r.id_producto   = p_id_producto)
       AND (r.id_vendedor   IS NULL OR r.id_vendedor   = p_id_vendedor)
       AND (r.id_zona       IS NULL OR r.id_zona       = v_zona)
       AND (r.telemarketing IS NULL OR r.telemarketing = COALESCE(p_telemarketing, 0))
       AND p_plazo BETWEEN r.plazo_desde AND r.plazo_hasta
       AND p_monto BETWEEN r.monto_desde AND r.monto_hasta
     ORDER BY r.prioridad DESC, r.vigencia_desde DESC, r.id_regla DESC
     LIMIT 1;

    RETURN v_id;
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
    SELECT p_nro_prestamo, p_id_cliente, p_id_producto, p_id_vendedor, v_id_tasa,
           p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
           v_punit, v_gastos, p_capital - v_gastos,
           (SELECT importe FROM tmp_cuadro WHERE nro_cuota = 1),
           (SELECT SUM(interes) FROM tmp_cuadro),
           (SELECT SUM(iva_interes) FROM tmp_cuadro),
           COALESCE(p_telemarketing, 0), v_id_regla, v_com_pct, v_com_monto, p_id_usuario;

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

DELIMITER ;
