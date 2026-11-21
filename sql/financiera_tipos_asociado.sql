-- =====================================================================
--  FINANCIERA - Tipos de asociado (antes «productos»), reparticiones y
--  escalas de comisión
--
--  Correr DESPUÉS de financiera_ordenes_pago.sql,
--  financiera_telemarketing_comisiones.sql y carga_comisiones_2026_06.sql,
--  con la base de la financiera seleccionada. MySQL 8. Se puede correr más
--  de una vez. En DBeaver: Ejecutar script (Alt+X).
--
--  POR QUÉ
--  ---------------------------------------------------------------------
--  Lo que se cargó como «productos» (NACION, REGGIO DC, HABERES ACTIVOS…)
--  son los tipos de asociado de las planillas de comisiones. El tipo de
--  asociado define la tasa, los plazos y los montos del préstamo, y la
--  comisión del vendedor. La repartición (ANSES MINIMA 7, IPS 123,
--  MINISTERIO DE EDUCACION…) es de la persona y no depende del tipo: en
--  las planillas de junio la misma repartición aparece con varios tipos
--  (PENSION GRACIABLE 0-1 con NACION, NACION AE, REGGIO DC y OTROS BANCOS).
--
--    tipos_asociado ...... la ex tabla productos (y la columna
--                          id_producto pasa a id_tipo_asociado en
--                          prestamos, solicitudes, tasas_vigencia,
--                          comision_reglas y comisiones_importadas)
--    organismos .......... quién paga los haberes (ANSES, IPS, Pensiones
--                          graciables…): agrupa las reparticiones
--    reparticiones ....... las 27 de las planillas, cada una con su organismo
--    clientes ............ id_tipo_asociado (el habitual) e
--                          id_reparticion. La solicitud y el préstamo
--                          guardan su propio tipo: la misma persona puede
--                          sacar un préstamo de otro tipo.
--    comision_reglas ..... escalas: por tipo de asociado y monto del
--                          préstamo, con % normal y % con telemarketing
--                          (porcentaje_tm). Sin vendedor vale para todos;
--                          con vendedor, es una excepción suya y gana.
--
--  LAS REGLAS DE JUNIO
--  ---------------------------------------------------------------------
--  Las 36 reglas de carga_comisiones_2026_06.sql (una por vendedor, tipo
--  y telemarketing) se convierten sin borrar ninguna:
--    1. La de cada vendedor y tipo pasa a tener los dos % (el normal y el
--       de telemarketing). Si en junio no hubo telemarketing, el de
--       telemarketing es la mitad, salvo los tipos AE (10 % siempre).
--    2. Por tipo se crea la escala general con el par de % más usado en
--       junio (por cantidad de operaciones).
--    3. Las del vendedor iguales a la general se desactivan; las que
--       difieren quedan como excepción (ARROYO 20 % en NACION, ALMIRON 25 %
--       en REGGIO DC…).
--  Todas arrancan con una sola escala (de $ 0 a $ 999.999.999): en junio
--  el % no cambió con el monto. Las escalas se cargan en
--  Tablas y parámetros › Tipos de asociado y tasas (tipos_asociado.php,
--  antes productos.php); las excepciones, en la ficha del vendedor. Los
--  organismos y las reparticiones, en sus pantallas (organismos.php y
--  reparticiones.php).
--
--  OJO: redefine fn_tasa_vigente, fn_comision_regla, sp_simular_prestamo y
--  sp_liquidar_prestamo. financiera_nucleo.sql, financiera_fondos.sql,
--  financiera_ordenes_pago.sql, financiera_tablas_temporales.sql y
--  financiera_telemarketing_comisiones.sql los definen con la tabla
--  productos, que ya no existe: no se vuelven a correr después de este.
-- =====================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_migrar_tipos_asociado $$
CREATE PROCEDURE tmp_migrar_tipos_asociado()
BEGIN
    DECLARE v_db VARCHAR(64) DEFAULT DATABASE();

    -- 1. productos -> tipos_asociado ------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'productos')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'tipos_asociado') THEN
        RENAME TABLE productos TO tipos_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'tipos_asociado' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE tipos_asociado RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'prestamos' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE prestamos RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE solicitudes RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'tasas_vigencia' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE tasas_vigencia RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'comision_reglas' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE comision_reglas RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'comisiones_importadas' AND COLUMN_NAME = 'id_producto') THEN
        ALTER TABLE comisiones_importadas RENAME COLUMN id_producto TO id_tipo_asociado;
    END IF;

    -- 2. organismos y reparticiones ---------------------------------------
    CREATE TABLE IF NOT EXISTS organismos (
        id_organismo INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nombre       VARCHAR(60)  NOT NULL,
        activo       TINYINT(1)   NOT NULL DEFAULT 1,
        creado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_organismo),
        UNIQUE KEY uq_organismos_nombre (nombre)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci
      COMMENT = 'Quién paga los haberes: agrupa las reparticiones (ANSES, IPS…)';

    INSERT IGNORE INTO organismos (nombre) VALUES
        ('ANSES'), ('Pensiones graciables'), ('IPS'), ('Ministerio de Educación'), ('Policía'), ('Caja de Policía');

    CREATE TABLE IF NOT EXISTS reparticiones (
        id_reparticion INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nombre         VARCHAR(80)  NOT NULL,
        id_organismo   INT UNSIGNED NULL COMMENT 'Para agrupar',
        activo         TINYINT(1)   NOT NULL DEFAULT 1,
        creado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_reparticion),
        UNIQUE KEY uq_reparticiones_nombre (nombre),
        KEY ix_reparticiones_organismo (id_organismo),
        CONSTRAINT fk_reparticiones_organismo FOREIGN KEY (id_organismo) REFERENCES organismos (id_organismo)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci
      COMMENT = 'Dónde cobra el asociado (de las planillas de comisiones)';

    -- La primera versión de este script guardaba el organismo como texto.
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'reparticiones' AND COLUMN_NAME = 'organismo') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'reparticiones' AND COLUMN_NAME = 'id_organismo') THEN
            ALTER TABLE reparticiones
                ADD COLUMN id_organismo INT UNSIGNED NULL COMMENT 'Para agrupar' AFTER nombre,
                ADD KEY ix_reparticiones_organismo (id_organismo),
                ADD CONSTRAINT fk_reparticiones_organismo FOREIGN KEY (id_organismo) REFERENCES organismos (id_organismo);
        END IF;
        INSERT IGNORE INTO organismos (nombre)
        SELECT DISTINCT TRIM(organismo) FROM reparticiones WHERE COALESCE(TRIM(organismo), '') <> '';
        UPDATE reparticiones r JOIN organismos o ON o.nombre = TRIM(r.organismo)
           SET r.id_organismo = o.id_organismo
         WHERE r.id_organismo IS NULL;
        ALTER TABLE reparticiones DROP COLUMN organismo;
    END IF;

    INSERT IGNORE INTO reparticiones (nombre, id_organismo)
    SELECT x.nombre, o.id_organismo
      FROM (SELECT 'ACTIVOS ANSES' AS nombre, 'ANSES' AS organismo
            UNION ALL SELECT 'ANSES MAXIMA 0-1', 'ANSES' UNION ALL SELECT 'ANSES MAXIMA 2-3', 'ANSES'
            UNION ALL SELECT 'ANSES MAXIMA 4-5', 'ANSES' UNION ALL SELECT 'ANSES MAXIMA 6-7', 'ANSES'
            UNION ALL SELECT 'ANSES MAXIMA 8-9', 'ANSES'
            UNION ALL SELECT 'ANSES MINIMA 0', 'ANSES' UNION ALL SELECT 'ANSES MINIMA 1', 'ANSES'
            UNION ALL SELECT 'ANSES MINIMA 2', 'ANSES' UNION ALL SELECT 'ANSES MINIMA 3', 'ANSES'
            UNION ALL SELECT 'ANSES MINIMA 4', 'ANSES' UNION ALL SELECT 'ANSES MINIMA 5', 'ANSES'
            UNION ALL SELECT 'ANSES MINIMA 6', 'ANSES' UNION ALL SELECT 'ANSES MINIMA 7', 'ANSES'
            UNION ALL SELECT 'ANSES MINIMA 8', 'ANSES' UNION ALL SELECT 'ANSES MINIMA 9', 'ANSES'
            UNION ALL SELECT 'PENSION GRACIABLE 0-1', 'Pensiones graciables' UNION ALL SELECT 'PENSION GRACIABLE 2-3', 'Pensiones graciables'
            UNION ALL SELECT 'PENSION GRACIABLE 4-5', 'Pensiones graciables' UNION ALL SELECT 'PENSION GRACIABLE 6-7', 'Pensiones graciables'
            UNION ALL SELECT 'PENSION GRACIABLE 8-9', 'Pensiones graciables'
            UNION ALL SELECT 'IPS 123', 'IPS' UNION ALL SELECT 'IPS 456', 'IPS' UNION ALL SELECT 'IPS 789', 'IPS'
            UNION ALL SELECT 'MINISTERIO DE EDUCACION', 'Ministerio de Educación'
            UNION ALL SELECT 'POLICIA', 'Policía' UNION ALL SELECT 'CAJA POLICIA', 'Caja de Policía') x
      JOIN organismos o ON o.nombre = x.organismo;

    -- 3. el tipo habitual y la repartición del cliente ------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'id_tipo_asociado') THEN
        ALTER TABLE clientes
            ADD COLUMN id_tipo_asociado INT UNSIGNED NULL COMMENT 'El habitual: la solicitud lo trae y se puede cambiar' AFTER condicion_iva,
            ADD COLUMN id_reparticion INT UNSIGNED NULL COMMENT 'Dónde cobra' AFTER id_tipo_asociado,
            ADD CONSTRAINT fk_clientes_tipo FOREIGN KEY (id_tipo_asociado) REFERENCES tipos_asociado (id_tipo_asociado),
            ADD CONSTRAINT fk_clientes_reparticion FOREIGN KEY (id_reparticion) REFERENCES reparticiones (id_reparticion);
    END IF;

    -- 4. la repartición de las comisiones importadas ---------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'comisiones_importadas' AND COLUMN_NAME = 'id_reparticion') THEN
        ALTER TABLE comisiones_importadas
            ADD COLUMN id_reparticion INT UNSIGNED NULL AFTER reparticion,
            ADD CONSTRAINT fk_comimp_reparticion FOREIGN KEY (id_reparticion) REFERENCES reparticiones (id_reparticion);
    END IF;
    UPDATE comisiones_importadas ci
      JOIN reparticiones r ON r.nombre = UPPER(TRIM(REGEXP_REPLACE(ci.reparticion, '\\s+', ' ')))
       SET ci.id_reparticion = r.id_reparticion
     WHERE ci.id_reparticion IS NULL;

    -- 5. escalas de comisión ----------------------------------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'comision_reglas' AND COLUMN_NAME = 'porcentaje_tm') THEN
        ALTER TABLE comision_reglas
            ADD COLUMN porcentaje_tm DECIMAL(9,6) NULL COMMENT 'El % con telemarketing (NULL: el mismo)' AFTER porcentaje;
    END IF;

    -- Una sola vez: si todavía no hay escalas generales con % de telemarketing.
    IF NOT EXISTS (SELECT 1 FROM comision_reglas WHERE id_vendedor IS NULL AND porcentaje_tm IS NOT NULL) THEN
        -- 5.1 La de cada vendedor y tipo sin telemarketing toma el % de la
        --     de telemarketing (o la mitad, salvo AE).
        DROP TEMPORARY TABLE IF EXISTS tmp_reglas_tm;
        CREATE TEMPORARY TABLE tmp_reglas_tm AS
            SELECT id_regla, id_vendedor, id_tipo_asociado, porcentaje
              FROM comision_reglas WHERE activo = 1 AND telemarketing = 1 AND id_vendedor IS NOT NULL;
        UPDATE comision_reglas r
          JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
          LEFT JOIN tmp_reglas_tm m ON m.id_vendedor = r.id_vendedor AND m.id_tipo_asociado = r.id_tipo_asociado
           SET r.porcentaje_tm = COALESCE(m.porcentaje, IF(t.codigo LIKE '%\_AE', r.porcentaje, ROUND(r.porcentaje / 2, 6))),
               r.telemarketing = NULL
         WHERE r.activo = 1 AND r.telemarketing = 0 AND r.id_vendedor IS NOT NULL;
        -- Las de telemarketing que tenían su par sin telemarketing quedan absorbidas.
        UPDATE comision_reglas r
          JOIN comision_reglas n ON n.id_vendedor = r.id_vendedor AND n.id_tipo_asociado = r.id_tipo_asociado
                                AND n.activo = 1 AND n.telemarketing IS NULL AND n.porcentaje_tm IS NOT NULL
           SET r.activo = 0
         WHERE r.activo = 1 AND r.telemarketing = 1;
        -- Las de telemarketing solas: el % normal es el doble, salvo AE.
        UPDATE comision_reglas r
          JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
           SET r.porcentaje_tm = r.porcentaje,
               r.porcentaje = IF(t.codigo LIKE '%\_AE', r.porcentaje, LEAST(ROUND(r.porcentaje * 2, 6), 1)),
               r.telemarketing = NULL
         WHERE r.activo = 1 AND r.telemarketing = 1 AND r.id_vendedor IS NOT NULL;
        DROP TEMPORARY TABLE IF EXISTS tmp_reglas_tm;

        -- 5.2 La escala general de cada tipo: el par de % más usado en junio.
        DROP TEMPORARY TABLE IF EXISTS tmp_generales;
        CREATE TEMPORARY TABLE tmp_generales AS
            SELECT id_tipo_asociado, porcentaje, porcentaje_tm, desde
              FROM (SELECT r.id_tipo_asociado, r.porcentaje, r.porcentaje_tm, MIN(r.vigencia_desde) AS desde,
                           ROW_NUMBER() OVER (PARTITION BY r.id_tipo_asociado
                                              ORDER BY SUM(COALESCE(c.operaciones, 0)) DESC,
                                                       COUNT(*) DESC, r.porcentaje) AS orden
                      FROM comision_reglas r
                      LEFT JOIN (SELECT id_vendedor, id_tipo_asociado, COUNT(*) AS operaciones
                                   FROM comisiones_importadas GROUP BY id_vendedor, id_tipo_asociado) c
                             ON c.id_vendedor = r.id_vendedor AND c.id_tipo_asociado = r.id_tipo_asociado
                     WHERE r.activo = 1 AND r.id_vendedor IS NOT NULL AND r.id_tipo_asociado IS NOT NULL
                     GROUP BY r.id_tipo_asociado, r.porcentaje, r.porcentaje_tm) x
             WHERE orden = 1;
        INSERT INTO comision_reglas (descripcion, id_tipo_asociado, id_vendedor, telemarketing, plazo_desde, plazo_hasta,
                                     monto_desde, monto_hasta, porcentaje, porcentaje_tm, base_calculo, momento, prioridad,
                                     vigencia_desde, activo)
        SELECT LEFT(CONCAT(t.codigo, ' - todos los vendedores'), 120), g.id_tipo_asociado, NULL, NULL, 1, 999,
               0, 999999999, g.porcentaje, g.porcentaje_tm, 'CAPITAL', 'LIQUIDACION', 0, g.desde, 1
          FROM tmp_generales g JOIN tipos_asociado t ON t.id_tipo_asociado = g.id_tipo_asociado;

        -- 5.3 Las del vendedor iguales a la general se desactivan; las demás
        --     son excepciones.
        UPDATE comision_reglas r
          JOIN tmp_generales g ON g.id_tipo_asociado = r.id_tipo_asociado
                              AND g.porcentaje = r.porcentaje AND g.porcentaje_tm <=> r.porcentaje_tm
           SET r.activo = 0
         WHERE r.activo = 1 AND r.id_vendedor IS NOT NULL;
        UPDATE comision_reglas r
          JOIN vendedores v ON v.id_vendedor = r.id_vendedor
          JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
           SET r.descripcion = LEFT(CONCAT(t.codigo, ' - ', v.codigo, ' (excepción)'), 120), r.prioridad = 10
         WHERE r.activo = 1 AND r.id_vendedor IS NOT NULL;
        DROP TEMPORARY TABLE IF EXISTS tmp_generales;
    END IF;

    -- 6. menú ---------------------------------------------------------------
    -- La pantalla pasa a llamarse tipos_asociado.php.
    UPDATE menu SET nombre = 'Tipos de asociado y tasas', url = 'tipos_asociado.php' WHERE url = 'productos.php';
    IF NOT EXISTS (SELECT 1 FROM menu WHERE url = 'reparticiones.php')
       AND EXISTS (SELECT 1 FROM menu WHERE url = 'tipos_asociado.php') THEN
        -- Justo debajo de Tipos de asociado: corre una posición lo que sigue.
        UPDATE menu m
          JOIN (SELECT padre_id, orden FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1) p
            ON p.padre_id = m.padre_id AND m.orden > p.orden
           SET m.orden = m.orden + 1;
        INSERT INTO menu (padre_id, nombre, url, icono, orden, nivel, activo)
        SELECT padre_id, 'Reparticiones', 'reparticiones.php', 'banco', orden + 1, nivel, 1
          FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1;
        -- Los mismos roles que ven Tipos de asociado.
        INSERT IGNORE INTO menu_roles (id_menu, id_rol)
        SELECT (SELECT id_menu FROM menu WHERE url = 'reparticiones.php'), mr.id_rol
          FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu AND m.url = 'tipos_asociado.php';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM menu WHERE url = 'organismos.php')
       AND EXISTS (SELECT 1 FROM menu WHERE url = 'tipos_asociado.php') THEN
        -- Entre Tipos de asociado y Reparticiones.
        UPDATE menu m
          JOIN (SELECT padre_id, orden FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1) p
            ON p.padre_id = m.padre_id AND m.orden > p.orden
           SET m.orden = m.orden + 1;
        INSERT INTO menu (padre_id, nombre, url, icono, orden, nivel, activo)
        SELECT padre_id, 'Organismos', 'organismos.php', 'banco', orden + 1, nivel, 1
          FROM menu WHERE url = 'tipos_asociado.php' LIMIT 1;
        INSERT IGNORE INTO menu_roles (id_menu, id_rol)
        SELECT (SELECT id_menu FROM menu WHERE url = 'organismos.php'), mr.id_rol
          FROM menu_roles mr JOIN menu m ON m.id_menu = mr.id_menu AND m.url = 'tipos_asociado.php';
        UPDATE menu SET icono = 'etiqueta' WHERE url = 'reparticiones.php' AND icono = 'banco' AND icono_svg IS NULL;
    END IF;
END $$

CALL tmp_migrar_tipos_asociado() $$
DROP PROCEDURE IF EXISTS tmp_migrar_tipos_asociado $$

-- ---------------------------------------------------------------------
-- 7. Las rutinas que usaban productos
-- ---------------------------------------------------------------------

DROP FUNCTION IF EXISTS fn_tasa_vigente $$
CREATE FUNCTION fn_tasa_vigente(
    p_id_tipo_asociado INT UNSIGNED,
    p_plazo            SMALLINT,
    p_monto            DECIMAL(15,2),
    p_fecha            DATE
) RETURNS INT UNSIGNED
READS SQL DATA
BEGIN
    DECLARE v_id INT UNSIGNED DEFAULT NULL;

    SELECT t.id_tasa INTO v_id
      FROM tasas_vigencia t
     WHERE t.id_tipo_asociado = p_id_tipo_asociado
       AND t.vigencia_desde <= p_fecha
       AND p_plazo BETWEEN t.plazo_desde AND t.plazo_hasta
       AND p_monto BETWEEN t.monto_desde AND t.monto_hasta
     ORDER BY t.vigencia_desde DESC, t.id_tasa DESC
     LIMIT 1;

    RETURN v_id;
END $$

-- La escala de comisión que corresponde: la del vendedor si tiene una
-- (excepción), si no la general del tipo de asociado; dentro de ellas, la
-- del tramo de monto del préstamo. p_telemarketing ya no elige la regla:
-- elige, en el préstamo, entre porcentaje y porcentaje_tm (se mantiene por
-- las reglas viejas que todavía lo tengan).
DROP FUNCTION IF EXISTS fn_comision_regla $$
CREATE FUNCTION fn_comision_regla(
    p_id_vendedor      INT UNSIGNED,
    p_id_tipo_asociado INT UNSIGNED,
    p_plazo            SMALLINT,
    p_monto            DECIMAL(15,2),
    p_fecha            DATE,
    p_telemarketing    TINYINT
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
       AND (r.id_tipo_asociado IS NULL OR r.id_tipo_asociado = p_id_tipo_asociado)
       AND (r.id_vendedor      IS NULL OR r.id_vendedor      = p_id_vendedor)
       AND (r.id_zona          IS NULL OR r.id_zona          = v_zona)
       AND (r.telemarketing    IS NULL OR r.telemarketing    = COALESCE(p_telemarketing, 0))
       AND p_plazo BETWEEN r.plazo_desde AND r.plazo_hasta
       AND p_monto BETWEEN r.monto_desde AND r.monto_hasta
     ORDER BY (r.id_vendedor IS NOT NULL) DESC, (r.id_tipo_asociado IS NOT NULL) DESC,
              r.prioridad DESC, r.vigencia_desde DESC, r.id_regla DESC
     LIMIT 1;

    RETURN v_id;
END $$

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
    DECLARE v_gasto    DECIMAL(6,4);
    DECLARE v_pmin, v_pmax SMALLINT;
    DECLARE v_mmin, v_mmax DECIMAL(15,2);
    DECLARE v_activo   TINYINT;
    DECLARE v_cuota, v_int, v_ivat, v_total DECIMAL(15,2);

    SET p_fecha      = COALESCE(p_fecha, CURDATE());
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

    SELECT alicuota_iva, gasto_otorg_pct, plazo_min, plazo_max, monto_min, monto_max, activo
      INTO v_iva, v_gasto, v_pmin, v_pmax, v_mmin, v_mmax, v_activo
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
            -- La escala del tipo de asociado y el monto (o la excepción del
            -- vendedor); con telemarketing, su % de telemarketing.
            SET v_id_regla = fn_comision_regla(p_id_vendedor, p_id_tipo_asociado, p_plazo, p_capital, p_fecha, p_telemarketing);
            IF v_id_regla IS NOT NULL THEN
                SELECT IF(p_telemarketing = 1, COALESCE(porcentaje_tm, porcentaje), porcentaje), base_calculo
                  INTO v_com_pct, v_base_tipo
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
        nro_prestamo, id_cliente, id_tipo_asociado, id_vendedor, id_tasa,
        fecha_liquidacion, fecha_primer_vto, capital, plazo, tna, alicuota_iva,
        tasa_punitoria, gastos_otorg, neto_desembolso, id_cuenta_fondos, medio_desembolso, referencia_desembolso,
        valor_cuota, total_intereses, total_iva,
        telemarketing, id_comision_regla, comision_pct, comision_monto, creado_por)
    VALUES (p_nro_prestamo, p_id_cliente, p_id_tipo_asociado, p_id_vendedor, v_id_tasa,
            p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
            v_punit, v_gastos, p_capital - v_gastos, NULL, NULL, NULL,
            v_cuota, v_int, v_ivat,
            p_telemarketing, v_id_regla, v_com_pct, v_com_monto, p_id_usuario);

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

DELIMITER ;

-- ---------------------------------------------------------------------
-- 8. Controles
-- ---------------------------------------------------------------------
-- 8.1 Las escalas activas por tipo de asociado (general y excepciones).
SELECT t.codigo AS tipo, COALESCE(v.codigo, '(todos)') AS vendedor, r.monto_desde, r.monto_hasta,
       ROUND(r.porcentaje * 100, 2) AS pct, ROUND(r.porcentaje_tm * 100, 2) AS pct_tm, r.vigencia_desde
  FROM comision_reglas r
  JOIN tipos_asociado t ON t.id_tipo_asociado = r.id_tipo_asociado
  LEFT JOIN vendedores v ON v.id_vendedor = r.id_vendedor
 WHERE r.activo = 1
 ORDER BY t.codigo, r.id_vendedor IS NOT NULL, v.codigo, r.monto_desde;

-- 8.2 (tiene que dar cero filas) Comisiones importadas con una repartición
--     que no está en la tabla, y reparticiones sin organismo.
SELECT 'repartición sin cargar' AS control, reparticion, COUNT(*) AS filas
  FROM comisiones_importadas WHERE id_reparticion IS NULL AND COALESCE(TRIM(reparticion), '') <> ''
 GROUP BY reparticion
UNION ALL
SELECT 'repartición sin organismo', nombre, 1 FROM reparticiones WHERE id_organismo IS NULL;

-- 8.3 (tiene que dar cero filas) Lo de junio, recalculado con las escalas:
--     operaciones cuyo % no es el de la escala que les toca hoy.
SELECT 'comisión distinta de la escala' AS control, ci.archivo, ci.fila_origen, v.codigo AS vendedor, ci.tipo_asociado,
       ci.telemarketing, ci.monto, ROUND(ci.porcentaje * 100, 2) AS pct_junio,
       ROUND(IF(ci.telemarketing = 1, COALESCE(r.porcentaje_tm, r.porcentaje), r.porcentaje) * 100, 2) AS pct_escala
  FROM comisiones_importadas ci
  JOIN vendedores v ON v.id_vendedor = ci.id_vendedor
  LEFT JOIN comision_reglas r ON r.id_regla = fn_comision_regla(ci.id_vendedor, ci.id_tipo_asociado, 1, ci.monto, ci.fecha, ci.telemarketing)
 WHERE r.id_regla IS NULL
    OR ABS(IF(ci.telemarketing = 1, COALESCE(r.porcentaje_tm, r.porcentaje), r.porcentaje) - ci.porcentaje) > 0.0001;
