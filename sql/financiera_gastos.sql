-- =====================================================================
--  FINANCIERA - Conceptos de gasto de los préstamos
--  Correr DESPUÉS de financiera_tipos_asociado.sql. MySQL 8 / MariaDB 10.x.
--  Se puede correr más de una vez.
--
--  Reemplaza el % único de gastos de otorgamiento del tipo de asociado
--  (tipos_asociado.gasto_otorg_pct) por un catálogo de conceptos:
--
--    gastos_conceptos        el catálogo (sellado, seguro, gastos admin.…):
--      nombre, IVA (lleva_iva/alicuota_iva) y los valores SUGERIDOS que se
--      proponen al asignarlo a un tipo de asociado
--    gastos_conceptos_tipos  la configuración de cada tipo de asociado: qué
--                            conceptos cobra y con qué valores (dos tipos
--                            pueden cobrar el mismo concepto distinto)
--      tipo_calculo          FIJO (importe en $) o PORCENTAJE (sobre el capital)
--      valor                 el importe, o la fracción (0.015000 = 1,5 %)
--      importe_minimo/maximo topes del importe cuando es %, opcionales
--      forma_cobro           DESCUENTO: se descuenta del desembolso
--                            FINANCIADO: se suma al capital del cuadro y se
--                                        paga en las cuotas (con interés)
--      activo                0 = el tipo deja de cobrarlo sin perder los valores
--    prestamos_gastos        lo que se cobró en cada préstamo (congelado
--                            al liquidar: cambiar un concepto no toca lo hecho)
--    prestamos.gastos_financiados  total de gastos que van en las cuotas
--
--  En el préstamo:
--    capital           el monto pedido (como siempre)
--    gastos_otorg      total descontado del desembolso (con su IVA)
--    gastos_financiados total financiado (con su IVA)
--    neto_desembolso   capital - gastos_otorg
--    cuotas            sistema francés sobre capital + gastos_financiados
--  Movimientos: DESEMBOLSO (capital), GASTO_OTORG (descontado) y
--  GASTO_FINANC (financiado).
--
--  Rutinas
--    sp_gastos_calcular   arma tmp_gastos para un tipo de asociado y un capital
--    sp_simular_prestamo  (redefinido) 3er resultado: el detalle de gastos
--    sp_liquidar_prestamo (redefinido) guarda el detalle en prestamos_gastos
--  Si se vuelve a correr financiera_tipos_asociado.sql, correr este después.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gastos_conceptos (
    id_concepto_gasto INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(30)  NOT NULL,
    nombre          VARCHAR(100) NOT NULL,
    tipo_calculo    ENUM('FIJO','PORCENTAJE') NOT NULL DEFAULT 'PORCENTAJE',
    valor           DECIMAL(15,6) NOT NULL DEFAULT 0,      -- sugerido: $ si es FIJO; fracción del capital si es %
    importe_minimo  DECIMAL(15,2) NULL,                    -- sugerido, solo para %
    importe_maximo  DECIMAL(15,2) NULL,                    -- sugerido, solo para %
    forma_cobro     ENUM('DESCUENTO','FINANCIADO') NOT NULL DEFAULT 'DESCUENTO',   -- sugerida
    lleva_iva       TINYINT(1)   NOT NULL DEFAULT 0,
    alicuota_iva    DECIMAL(6,4) NOT NULL DEFAULT 0.2100,
    orden           SMALLINT     NOT NULL DEFAULT 500,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    PRIMARY KEY (id_concepto_gasto),
    UNIQUE KEY uq_gastos_conceptos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gastos_conceptos_tipos (
    id_concepto_gasto INT UNSIGNED NOT NULL,
    id_tipo_asociado  INT UNSIGNED NOT NULL,
    tipo_calculo    ENUM('FIJO','PORCENTAJE') NOT NULL DEFAULT 'PORCENTAJE',
    valor           DECIMAL(15,6) NOT NULL DEFAULT 0,
    importe_minimo  DECIMAL(15,2) NULL,
    importe_maximo  DECIMAL(15,2) NULL,
    forma_cobro     ENUM('DESCUENTO','FINANCIADO') NOT NULL DEFAULT 'DESCUENTO',
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    modificado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    modificado_por  INT UNSIGNED NULL,
    PRIMARY KEY (id_concepto_gasto, id_tipo_asociado),
    KEY ix_gct_tipo (id_tipo_asociado),
    CONSTRAINT fk_gct_concepto FOREIGN KEY (id_concepto_gasto) REFERENCES gastos_conceptos (id_concepto_gasto) ON DELETE CASCADE,
    CONSTRAINT fk_gct_tipo     FOREIGN KEY (id_tipo_asociado)  REFERENCES tipos_asociado (id_tipo_asociado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prestamos_gastos (
    id_prestamo_gasto INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_prestamo     INT UNSIGNED NOT NULL,
    id_concepto_gasto INT UNSIGNED NULL,
    nombre          VARCHAR(100) NOT NULL,
    tipo_calculo    ENUM('FIJO','PORCENTAJE') NOT NULL,
    valor           DECIMAL(15,6) NOT NULL,
    forma_cobro     ENUM('DESCUENTO','FINANCIADO') NOT NULL,
    base            DECIMAL(15,2) NULL,                    -- capital sobre el que se aplicó el %
    importe         DECIMAL(15,2) NOT NULL,
    alicuota_iva    DECIMAL(6,4) NOT NULL DEFAULT 0,
    iva             DECIMAL(15,2) NOT NULL DEFAULT 0,
    total           DECIMAL(15,2) NOT NULL,
    PRIMARY KEY (id_prestamo_gasto),
    KEY ix_pg_prestamo (id_prestamo),
    KEY ix_pg_concepto (id_concepto_gasto),
    CONSTRAINT fk_pg_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_pg_concepto FOREIGN KEY (id_concepto_gasto) REFERENCES gastos_conceptos (id_concepto_gasto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_migrar_gastos $$
CREATE PROCEDURE tmp_migrar_gastos()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestamos' AND COLUMN_NAME = 'gastos_financiados') THEN
        ALTER TABLE prestamos ADD COLUMN gastos_financiados DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER gastos_otorg;
    END IF;

    -- Primera versión de este script: los valores estaban en el concepto y
    -- «todos_los_tipos» lo aplicaba a todos. Pasan a cada tipo de asociado.
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gastos_conceptos_tipos' AND COLUMN_NAME = 'valor') THEN
        ALTER TABLE gastos_conceptos_tipos
            ADD COLUMN tipo_calculo   ENUM('FIJO','PORCENTAJE') NOT NULL DEFAULT 'PORCENTAJE' AFTER id_tipo_asociado,
            ADD COLUMN valor          DECIMAL(15,6) NOT NULL DEFAULT 0 AFTER tipo_calculo,
            ADD COLUMN importe_minimo DECIMAL(15,2) NULL AFTER valor,
            ADD COLUMN importe_maximo DECIMAL(15,2) NULL AFTER importe_minimo,
            ADD COLUMN forma_cobro    ENUM('DESCUENTO','FINANCIADO') NOT NULL DEFAULT 'DESCUENTO' AFTER importe_maximo,
            ADD COLUMN activo         TINYINT(1) NOT NULL DEFAULT 1 AFTER forma_cobro,
            ADD COLUMN modificado_el  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER activo,
            ADD COLUMN modificado_por INT UNSIGNED NULL AFTER modificado_el;
        UPDATE gastos_conceptos_tipos gt JOIN gastos_conceptos g ON g.id_concepto_gasto = gt.id_concepto_gasto
           SET gt.tipo_calculo = g.tipo_calculo, gt.valor = g.valor, gt.importe_minimo = g.importe_minimo,
               gt.importe_maximo = g.importe_maximo, gt.forma_cobro = g.forma_cobro;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gastos_conceptos' AND COLUMN_NAME = 'todos_los_tipos') THEN
        INSERT IGNORE INTO gastos_conceptos_tipos (id_concepto_gasto, id_tipo_asociado, tipo_calculo, valor,
                                                   importe_minimo, importe_maximo, forma_cobro)
        SELECT g.id_concepto_gasto, t.id_tipo_asociado, g.tipo_calculo, g.valor, g.importe_minimo, g.importe_maximo, g.forma_cobro
          FROM gastos_conceptos g CROSS JOIN tipos_asociado t
         WHERE g.todos_los_tipos = 1;
        ALTER TABLE gastos_conceptos DROP COLUMN todos_los_tipos;
    END IF;

    -- El % único de cada tipo de asociado pasa a ser el concepto «Gastos de
    -- otorgamiento» de ese tipo, con su % (solo si el catálogo está vacío).
    IF NOT EXISTS (SELECT 1 FROM gastos_conceptos)
       AND EXISTS (SELECT 1 FROM tipos_asociado WHERE gasto_otorg_pct > 0) THEN
        INSERT INTO gastos_conceptos (codigo, nombre, tipo_calculo, valor, forma_cobro, lleva_iva, orden)
        SELECT 'GASTOS_OTORG', 'Gastos de otorgamiento', 'PORCENTAJE', MAX(gasto_otorg_pct), 'DESCUENTO', 0, 100
          FROM tipos_asociado WHERE gasto_otorg_pct > 0;
        INSERT INTO gastos_conceptos_tipos (id_concepto_gasto, id_tipo_asociado, tipo_calculo, valor, forma_cobro)
        SELECT g.id_concepto_gasto, t.id_tipo_asociado, 'PORCENTAJE', t.gasto_otorg_pct, 'DESCUENTO'
          FROM tipos_asociado t JOIN gastos_conceptos g ON g.codigo = 'GASTOS_OTORG'
         WHERE t.gasto_otorg_pct > 0;
    END IF;
    -- La columna vieja queda en cero: ya no se usa.
    UPDATE tipos_asociado SET gasto_otorg_pct = 0 WHERE gasto_otorg_pct <> 0;

    -- Menú: «Conceptos de gasto» debajo de Tipos de asociado, con sus mismos roles.
    IF NOT EXISTS (SELECT 1 FROM menu WHERE url = 'gastos_conceptos.php')
       AND EXISTS (SELECT 1 FROM menu WHERE url = 'tipos_asociado.php') THEN
        UPDATE menu m
          JOIN (SELECT padre_id, orden FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1) p
            ON p.padre_id = m.padre_id AND m.orden > p.orden
           SET m.orden = m.orden + 1;
        INSERT INTO menu (padre_id, nombre, url, icono, orden, nivel, activo)
        SELECT padre_id, 'Conceptos de gasto', 'gastos_conceptos.php', 'calculadora', orden + 1, nivel, 1
          FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1;
        INSERT IGNORE INTO menu_roles (id_menu, id_rol)
        SELECT (SELECT id_menu FROM menu WHERE url = 'gastos_conceptos.php'), mr.id_rol
          FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu AND m.url = 'tipos_asociado.php';
    END IF;
END $$

CALL tmp_migrar_gastos() $$
DROP PROCEDURE IF EXISTS tmp_migrar_gastos $$

-- ---------------------------------------------------------------------
-- sp_gastos_calcular: los gastos activos del tipo de asociado, con los
-- valores del tipo, calculados sobre el capital. Deja el detalle en tmp_gastos.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_gastos_calcular $$
CREATE PROCEDURE sp_gastos_calcular(
    IN p_id_tipo_asociado INT UNSIGNED,
    IN p_capital          DECIMAL(15,2)
)
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_gastos;
    CREATE TEMPORARY TABLE tmp_gastos (
        id_concepto_gasto INT UNSIGNED NOT NULL PRIMARY KEY,
        orden         SMALLINT      NOT NULL,
        codigo        VARCHAR(30)   NOT NULL,
        nombre        VARCHAR(100)  NOT NULL,
        tipo_calculo  VARCHAR(10)   NOT NULL,
        valor         DECIMAL(15,6) NOT NULL,
        forma_cobro   VARCHAR(10)   NOT NULL,
        base          DECIMAL(15,2) NULL,
        importe       DECIMAL(15,2) NOT NULL,
        alicuota_iva  DECIMAL(6,4)  NOT NULL,
        iva           DECIMAL(15,2) NOT NULL DEFAULT 0,
        total         DECIMAL(15,2) NOT NULL DEFAULT 0
    ) ENGINE=MEMORY;

    INSERT INTO tmp_gastos (id_concepto_gasto, orden, codigo, nombre, tipo_calculo, valor, forma_cobro,
                            base, importe, alicuota_iva)
    SELECT g.id_concepto_gasto, g.orden, g.codigo, g.nombre, gt.tipo_calculo, gt.valor, gt.forma_cobro,
           IF(gt.tipo_calculo = 'PORCENTAJE', p_capital, NULL),
           IF(gt.tipo_calculo = 'FIJO', ROUND(gt.valor, 2),
              LEAST(COALESCE(gt.importe_maximo, 999999999999.99),
                    GREATEST(COALESCE(gt.importe_minimo, 0), ROUND(p_capital * gt.valor, 2)))),
           IF(g.lleva_iva = 1, g.alicuota_iva, 0)
      FROM gastos_conceptos_tipos gt
      JOIN gastos_conceptos g ON g.id_concepto_gasto = gt.id_concepto_gasto
     WHERE gt.id_tipo_asociado = p_id_tipo_asociado
       AND gt.activo = 1 AND g.activo = 1;

    DELETE FROM tmp_gastos WHERE importe <= 0;
    UPDATE tmp_gastos SET iva = ROUND(importe * alicuota_iva, 2);
    UPDATE tmp_gastos SET total = importe + iva;
END $$

-- ---------------------------------------------------------------------
-- sp_simular_prestamo
--   1er resultado: el resumen (gastos_otorg = lo descontado,
--                  gastos_financiados, capital_financiado = el del cuadro)
--   2do: el cuadro de cuotas
--   3ro: el detalle de los gastos
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_simular_prestamo $$
CREATE PROCEDURE sp_simular_prestamo(
    IN p_id_tipo_asociado INT UNSIGNED,
    IN p_capital          DECIMAL(15,2),
    IN p_plazo            SMALLINT,
    IN p_fecha            DATE,
    IN p_primer_vto       DATE
)
BEGIN
    DECLARE v_id_tasa  INT UNSIGNED;
    DECLARE v_tna      DECIMAL(9,6);
    DECLARE v_iva      DECIMAL(6,4);
    DECLARE v_pmin, v_pmax SMALLINT;
    DECLARE v_mmin, v_mmax DECIMAL(15,2);
    DECLARE v_activo   TINYINT;
    DECLARE v_desc, v_fin DECIMAL(15,2);
    DECLARE v_cuota, v_int, v_ivat, v_total DECIMAL(15,2);

    SET p_fecha      = COALESCE(p_fecha, CURDATE());
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

    SELECT alicuota_iva, plazo_min, plazo_max, monto_min, monto_max, activo
      INTO v_iva, v_pmin, v_pmax, v_mmin, v_mmax, v_activo
      FROM tipos_asociado WHERE id_tipo_asociado = p_id_tipo_asociado;

    IF v_activo IS NULL OR v_activo = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de asociado inexistente o inactivo';
    END IF;
    IF p_plazo NOT BETWEEN v_pmin AND v_pmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plazo fuera del rango del tipo de asociado';
    END IF;
    IF p_capital NOT BETWEEN v_mmin AND v_mmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Monto fuera del rango del tipo de asociado';
    END IF;

    SET v_id_tasa = fn_tasa_vigente(p_id_tipo_asociado, p_plazo, p_capital, p_fecha);
    IF v_id_tasa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay tasa vigente para ese plazo y monto';
    END IF;
    SELECT tna INTO v_tna FROM tasas_vigencia WHERE id_tasa = v_id_tasa;

    CALL sp_gastos_calcular(p_id_tipo_asociado, p_capital);
    SELECT COALESCE(SUM(IF(forma_cobro = 'DESCUENTO', total, 0)), 0),
           COALESCE(SUM(IF(forma_cobro = 'FINANCIADO', total, 0)), 0)
      INTO v_desc, v_fin
      FROM tmp_gastos;
    IF v_desc >= p_capital THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los gastos que se descuentan igualan o superan el monto pedido';
    END IF;

    CALL sp_cuadro_frances(p_capital + v_fin, p_plazo, v_tna, v_iva, p_primer_vto);

    SELECT SUM(IF(nro_cuota = 1, importe, 0)), SUM(interes), SUM(iva_interes), SUM(importe)
      INTO v_cuota, v_int, v_ivat, v_total
      FROM tmp_cuadro;

    SELECT p_capital                                   AS capital,
           p_plazo                                     AS plazo,
           v_tna                                       AS tna,
           ROUND(v_tna / 12, 6)                        AS tem,
           ROUND(POW(1 + v_tna / 12, 12) - 1, 6)       AS tea,
           v_desc                                      AS gastos_otorg,
           v_fin                                       AS gastos_financiados,
           p_capital + v_fin                           AS capital_financiado,
           p_capital - v_desc                          AS neto_desembolso,
           v_cuota                                     AS valor_cuota,
           v_int                                       AS total_intereses,
           v_ivat                                      AS total_iva,
           v_total                                     AS total_a_pagar;

    SELECT * FROM tmp_cuadro ORDER BY nro_cuota;

    SELECT id_concepto_gasto, codigo, nombre, tipo_calculo, valor, forma_cobro, base, importe, alicuota_iva, iva, total
      FROM tmp_gastos ORDER BY orden, nombre;
END $$

-- ---------------------------------------------------------------------
-- sp_liquidar_prestamo (misma firma que en financiera_tipos_asociado.sql)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_liquidar_prestamo $$
CREATE PROCEDURE sp_liquidar_prestamo(
    IN  p_nro_prestamo     VARCHAR(20),
    IN  p_id_cliente       INT UNSIGNED,
    IN  p_id_tipo_asociado INT UNSIGNED,
    IN  p_id_vendedor      INT UNSIGNED,
    IN  p_capital          DECIMAL(15,2),
    IN  p_plazo            SMALLINT,
    IN  p_fecha            DATE,
    IN  p_primer_vto       DATE,
    IN  p_comision_pct     DECIMAL(9,6),
    IN  p_telemarketing    TINYINT,
    IN  p_id_usuario       INT UNSIGNED,
    OUT p_id_prestamo      INT UNSIGNED
)
BEGIN
    DECLARE v_id_tasa   INT UNSIGNED;
    DECLARE v_tna       DECIMAL(9,6);
    DECLARE v_punit     DECIMAL(9,6);
    DECLARE v_iva       DECIMAL(6,4);
    DECLARE v_desc, v_fin DECIMAL(15,2);
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

    SELECT alicuota_iva, plazo_min, plazo_max, monto_min, monto_max, activo
      INTO v_iva, v_pmin, v_pmax, v_mmin, v_mmax, v_activo
      FROM tipos_asociado WHERE id_tipo_asociado = p_id_tipo_asociado;

    IF v_activo IS NULL OR v_activo = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de asociado inexistente o inactivo';
    END IF;
    IF p_plazo NOT BETWEEN v_pmin AND v_pmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plazo fuera del rango del tipo de asociado';
    END IF;
    IF p_capital NOT BETWEEN v_mmin AND v_mmax THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Monto fuera del rango del tipo de asociado';
    END IF;
    IF p_comision_pct IS NOT NULL AND (p_comision_pct < 0 OR p_comision_pct > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El % de comisión manual debe estar entre 0 y 1';
    END IF;

    SET v_id_tasa = fn_tasa_vigente(p_id_tipo_asociado, p_plazo, p_capital, p_fecha);
    IF v_id_tasa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay tasa vigente para ese plazo y monto';
    END IF;

    SELECT t.tna,
           COALESCE(t.tasa_punitoria,
                    ROUND(t.tna * COALESCE((SELECT CAST(valor AS DECIMAL(9,6)) FROM parametros
                                             WHERE clave = 'PUNITORIO_PCT_TNA'), 0.5), 6))
      INTO v_tna, v_punit
      FROM tasas_vigencia t WHERE t.id_tasa = v_id_tasa;

    CALL sp_gastos_calcular(p_id_tipo_asociado, p_capital);
    SELECT COALESCE(SUM(IF(forma_cobro = 'DESCUENTO', total, 0)), 0),
           COALESCE(SUM(IF(forma_cobro = 'FINANCIADO', total, 0)), 0)
      INTO v_desc, v_fin
      FROM tmp_gastos;
    IF v_desc >= p_capital THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los gastos que se descuentan igualan o superan el monto pedido';
    END IF;

    CALL sp_cuadro_frances(p_capital + v_fin, p_plazo, v_tna, v_iva, p_primer_vto);

    SELECT SUM(IF(nro_cuota = 1, importe, 0)), SUM(interes), SUM(iva_interes)
      INTO v_cuota, v_int, v_ivat
      FROM tmp_cuadro;

    IF p_id_vendedor IS NOT NULL THEN
        IF p_comision_pct IS NOT NULL THEN
            SET v_com_pct   = p_comision_pct;
            SET v_base_tipo = 'CAPITAL';
        ELSE
            SET v_id_regla = fn_comision_regla(p_id_vendedor, p_id_tipo_asociado, p_plazo, p_capital, p_fecha, p_telemarketing);
            IF v_id_regla IS NOT NULL THEN
                SELECT IF(p_telemarketing = 1,
                          COALESCE(r.porcentaje_tm, ROUND(r.porcentaje * COALESCE(t.pct_telemarketing, 1), 6)),
                          r.porcentaje), r.base_calculo
                  INTO v_com_pct, v_base_tipo
                  FROM comision_reglas r
                  LEFT JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
                 WHERE r.id_regla = v_id_regla;
            END IF;
        END IF;

        IF v_com_pct IS NOT NULL THEN
            -- La comisión no se calcula sobre los gastos financiados.
            SET v_com_base  = IF(v_base_tipo = 'NETO_DESEMBOLSO', p_capital - v_desc, p_capital);
            SET v_com_monto = ROUND(v_com_base * v_com_pct, 2);
        END IF;
    END IF;

    START TRANSACTION;

    INSERT INTO prestamos (
        nro_prestamo, id_cliente, id_tipo_asociado, id_vendedor, id_tasa,
        fecha_liquidacion, fecha_primer_vto, capital, plazo, tna, alicuota_iva,
        tasa_punitoria, gastos_otorg, gastos_financiados, neto_desembolso, id_cuenta_fondos, medio_desembolso, referencia_desembolso,
        valor_cuota, total_intereses, total_iva,
        telemarketing, id_comision_regla, comision_pct, comision_monto, creado_por)
    VALUES (p_nro_prestamo, p_id_cliente, p_id_tipo_asociado, p_id_vendedor, v_id_tasa,
            p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
            v_punit, v_desc, v_fin, p_capital - v_desc, NULL, NULL, NULL,
            v_cuota, v_int, v_ivat,
            p_telemarketing, v_id_regla, v_com_pct, v_com_monto, p_id_usuario);

    SET p_id_prestamo = LAST_INSERT_ID();

    INSERT INTO cuotas (id_prestamo, nro_cuota, vencimiento, capital, interes,
                        iva_interes, importe, saldo_capital)
    SELECT p_id_prestamo, nro_cuota, vencimiento, capital, interes,
           iva_interes, importe, saldo_capital
      FROM tmp_cuadro;

    INSERT INTO prestamos_gastos (id_prestamo, id_concepto_gasto, nombre, tipo_calculo, valor, forma_cobro,
                                  base, importe, alicuota_iva, iva, total)
    SELECT p_id_prestamo, id_concepto_gasto, nombre, tipo_calculo, valor, forma_cobro,
           base, importe, alicuota_iva, iva, total
      FROM tmp_gastos ORDER BY orden, nombre;

    INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
    VALUES (p_id_prestamo, p_fecha, 'DESEMBOLSO', p_capital, p_nro_prestamo, p_id_usuario);

    IF v_desc > 0 THEN
        INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
        VALUES (p_id_prestamo, p_fecha, 'GASTO_OTORG', v_desc, 'Descontado del desembolso', p_id_usuario);
    END IF;
    IF v_fin > 0 THEN
        INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
        VALUES (p_id_prestamo, p_fecha, 'GASTO_FINANC', v_fin, 'Financiado en las cuotas', p_id_usuario);
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

DELIMITER ;

-- Control: los gastos de cada tipo de asociado, con sus valores.
SELECT t.codigo AS tipo, g.nombre AS concepto, gt.tipo_calculo,
       IF(gt.tipo_calculo = 'FIJO', gt.valor, ROUND(gt.valor * 100, 4)) AS valor,
       gt.importe_minimo, gt.importe_maximo, gt.forma_cobro,
       IF(g.lleva_iva, ROUND(g.alicuota_iva * 100, 2), 0) AS iva_pct, gt.activo AND g.activo AS activo
  FROM gastos_conceptos_tipos gt
  JOIN gastos_conceptos g ON g.id_concepto_gasto = gt.id_concepto_gasto
  JOIN tipos_asociado t ON t.id_tipo_asociado = gt.id_tipo_asociado
 ORDER BY t.codigo, g.orden, g.nombre;
