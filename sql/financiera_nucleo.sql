-- =====================================================================
--  FINANCIERA - Núcleo del sistema de préstamos
--  MySQL 8 (probado también en MariaDB 10.x)
--
--  Contenido
--    1. Sistema ............ usuarios, parametros
--    2. Red comercial ...... zonas, vendedores
--    3. Clientes ........... clientes, clientes_personas, clientes_empresas,
--                            domicilios, telefonos
--    4. Productos .......... productos, tasas_vigencia
--    5. Préstamos .......... prestamos, cuotas, movimientos
--    6. Comisiones ......... comision_reglas, comisiones_devengadas,
--                            comisiones_liquidacion, comisiones_liquidacion_detalle
--    7. Stored procedures
--         sp_cuadro_frances            (motor de cálculo, único)
--         sp_simular_prestamo          (simulador: devuelve el cuadro)
--         sp_liquidar_prestamo         (alta del préstamo + cuotas + comisión)
--         fn_comision_regla            (resuelve la regla de comisión aplicable)
--         sp_comisiones_liquidar       (genera la liquidación de un período)
--         sp_comisiones_resumen        (detalle, subtotales por % y totales)
--
--  Criterios
--    - Importes DECIMAL(15,2); tasas DECIMAL(9,6) expresadas como fracción
--      (0.850000 = 85 % TNA).
--    - El cuadro de cuotas se calcula en UN solo SP: el simulador y la
--      liquidación usan el mismo, así nunca difieren.
--    - La comisión se congela en el préstamo: % y monto aplicados quedan
--      guardados. Si cambian las reglas, lo ya devengado no se toca.
--    - Campos origen_sistema / origen_id en clientes y préstamos para
--      la migración desde el sistema actual.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. SISTEMA
-- =====================================================================

CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario         VARCHAR(50)  NOT NULL,
    nombre          VARCHAR(120) NOT NULL,
    email           VARCHAR(150) NULL,
    rol             VARCHAR(30)  NOT NULL,   -- ADMIN, GERENCIA, ANALISTA, LIQUIDACION, COBRANZAS, ADMINISTRACION, SUPERVISOR, VENDEDOR
    limite_aprobacion DECIMAL(15,2) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario),
    UNIQUE KEY uq_usuarios_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parametros (
    clave           VARCHAR(60)  NOT NULL,
    valor           VARCHAR(255) NOT NULL,
    descripcion     VARCHAR(255) NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 2. RED COMERCIAL
-- =====================================================================

CREATE TABLE IF NOT EXISTS zonas (
    id_zona         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(80)  NOT NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_zona),
    UNIQUE KEY uq_zonas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendedores (
    id_vendedor     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario      INT UNSIGNED NULL,               -- usuario de la app, si tiene
    codigo          VARCHAR(20)  NOT NULL,           -- código interno / legajo
    nombre          VARCHAR(120) NOT NULL,
    cuit            CHAR(11)     NULL,
    id_zona         INT UNSIGNED NULL,
    id_supervisor   INT UNSIGNED NULL,               -- otro vendedor con rol supervisor
    tipo_vinculo    ENUM('EMPLEADO','COMISIONISTA') NOT NULL DEFAULT 'COMISIONISTA',
    fecha_alta      DATE         NOT NULL,
    fecha_baja      DATE         NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_vendedor),
    UNIQUE KEY uq_vendedores_codigo (codigo),
    KEY ix_vendedores_zona (id_zona),
    CONSTRAINT fk_vend_usuario    FOREIGN KEY (id_usuario)    REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_vend_zona       FOREIGN KEY (id_zona)       REFERENCES zonas (id_zona),
    CONSTRAINT fk_vend_supervisor FOREIGN KEY (id_supervisor) REFERENCES vendedores (id_vendedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 3. CLIENTES
-- =====================================================================

CREATE TABLE IF NOT EXISTS clientes (
    id_cliente      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo            ENUM('F','J') NOT NULL,          -- F = persona física, J = jurídica
    cuit            CHAR(11)     NOT NULL,           -- CUIT/CUIL sin guiones
    denominacion    VARCHAR(150) NOT NULL,           -- apellido y nombre o razón social
    email           VARCHAR(150) NULL,
    condicion_iva   VARCHAR(30)  NULL,               -- CF, RI, MONO, EX
    id_vendedor     INT UNSIGNED NULL,               -- vendedor que lo trae / cartera
    estado          ENUM('ACTIVO','INACTIVO','BLOQUEADO') NOT NULL DEFAULT 'ACTIVO',
    origen_sistema  VARCHAR(30)  NULL,               -- migración: sistema de origen
    origen_id       VARCHAR(50)  NULL,               -- migración: id en el sistema de origen
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    PRIMARY KEY (id_cliente),
    UNIQUE KEY uq_clientes_cuit (cuit),
    UNIQUE KEY uq_clientes_origen (origen_sistema, origen_id),
    KEY ix_clientes_vendedor (id_vendedor),
    CONSTRAINT fk_cli_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_cli_usuario  FOREIGN KEY (creado_por)  REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes_personas (
    id_cliente      INT UNSIGNED NOT NULL,
    apellido        VARCHAR(80)  NOT NULL,
    nombres         VARCHAR(80)  NOT NULL,
    dni             VARCHAR(10)  NOT NULL,
    fecha_nac       DATE         NULL,
    sexo            CHAR(1)      NULL,
    estado_civil    VARCHAR(20)  NULL,
    ocupacion       VARCHAR(80)  NULL,
    empleador       VARCHAR(120) NULL,
    ingreso_mensual DECIMAL(15,2) NULL,
    PRIMARY KEY (id_cliente),
    KEY ix_personas_dni (dni),
    CONSTRAINT fk_per_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes_empresas (
    id_cliente      INT UNSIGNED NOT NULL,
    razon_social    VARCHAR(150) NOT NULL,
    nombre_fantasia VARCHAR(150) NULL,
    tipo_societario VARCHAR(20)  NULL,               -- SA, SRL, SAS, etc.
    actividad       VARCHAR(150) NULL,
    fecha_inicio    DATE         NULL,
    facturacion_anual DECIMAL(15,2) NULL,
    PRIMARY KEY (id_cliente),
    CONSTRAINT fk_emp_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS domicilios (
    id_domicilio    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente      INT UNSIGNED NOT NULL,
    tipo            ENUM('REAL','LEGAL','LABORAL','COMERCIAL') NOT NULL DEFAULT 'REAL',
    calle           VARCHAR(120) NOT NULL,
    numero          VARCHAR(10)  NULL,
    piso_depto      VARCHAR(20)  NULL,
    localidad       VARCHAR(80)  NOT NULL,
    provincia       VARCHAR(40)  NOT NULL,
    cp              VARCHAR(10)  NULL,
    principal       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id_domicilio),
    KEY ix_dom_cliente (id_cliente),
    CONSTRAINT fk_dom_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telefonos (
    id_telefono     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente      INT UNSIGNED NOT NULL,
    tipo            ENUM('CELULAR','FIJO','LABORAL','REFERENCIA') NOT NULL DEFAULT 'CELULAR',
    numero          VARCHAR(20)  NOT NULL,
    observacion     VARCHAR(100) NULL,
    principal       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id_telefono),
    KEY ix_tel_cliente (id_cliente),
    KEY ix_tel_numero (numero),                      -- control de duplicados entre clientes
    CONSTRAINT fk_tel_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 4. PRODUCTOS
-- =====================================================================

CREATE TABLE IF NOT EXISTS productos (
    id_producto     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(20)  NOT NULL,
    nombre          VARCHAR(100) NOT NULL,
    destinatario    ENUM('F','J','AMBOS') NOT NULL DEFAULT 'AMBOS',
    sistema_amort   ENUM('FRANCES','ALEMAN','DIRECTO') NOT NULL DEFAULT 'FRANCES',
    plazo_min       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    plazo_max       SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    monto_min       DECIMAL(15,2) NOT NULL DEFAULT 0,
    monto_max       DECIMAL(15,2) NOT NULL DEFAULT 999999999,
    alicuota_iva    DECIMAL(6,4) NOT NULL DEFAULT 0.2100,  -- IVA sobre intereses
    gasto_otorg_pct DECIMAL(6,4) NOT NULL DEFAULT 0,       -- % sobre capital, se descuenta del desembolso
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_producto),
    UNIQUE KEY uq_productos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tasas versionadas: la vigente es la de vigencia_desde más reciente <= fecha
-- que cubra el plazo y el monto pedidos.
CREATE TABLE IF NOT EXISTS tasas_vigencia (
    id_tasa         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_producto     INT UNSIGNED NOT NULL,
    vigencia_desde  DATE         NOT NULL,
    plazo_desde     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    plazo_hasta     SMALLINT UNSIGNED NOT NULL DEFAULT 999,
    monto_desde     DECIMAL(15,2) NOT NULL DEFAULT 0,
    monto_hasta     DECIMAL(15,2) NOT NULL DEFAULT 999999999,
    tna             DECIMAL(9,6) NOT NULL,                 -- 0.850000 = 85 %
    tasa_punitoria  DECIMAL(9,6) NULL,                     -- TNA punitoria adicional
    creado_por      INT UNSIGNED NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_tasa),
    KEY ix_tasas_busqueda (id_producto, vigencia_desde),
    CONSTRAINT fk_tasa_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 5. PRÉSTAMOS
-- =====================================================================

CREATE TABLE IF NOT EXISTS prestamos (
    id_prestamo     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nro_prestamo    VARCHAR(20)  NOT NULL,
    id_cliente      INT UNSIGNED NOT NULL,
    id_producto     INT UNSIGNED NOT NULL,
    id_vendedor     INT UNSIGNED NULL,
    id_tasa         INT UNSIGNED NULL,                     -- tasa usada (trazabilidad)
    fecha_liquidacion DATE       NOT NULL,
    fecha_primer_vto  DATE       NOT NULL,
    capital         DECIMAL(15,2) NOT NULL,
    plazo           SMALLINT UNSIGNED NOT NULL,            -- cantidad de cuotas mensuales
    tna             DECIMAL(9,6) NOT NULL,                 -- congelada al liquidar
    alicuota_iva    DECIMAL(6,4) NOT NULL,
    tasa_punitoria  DECIMAL(9,6) NOT NULL DEFAULT 0,       -- TNA punitoria, congelada al liquidar
    gastos_otorg    DECIMAL(15,2) NOT NULL DEFAULT 0,
    neto_desembolso DECIMAL(15,2) NOT NULL,
    valor_cuota     DECIMAL(15,2) NOT NULL,                -- cuota total (capital + interés + IVA)
    total_intereses DECIMAL(15,2) NOT NULL,
    total_iva       DECIMAL(15,2) NOT NULL,
    estado          ENUM('VIGENTE','CANCELADO','REFINANCIADO','LEGALES','ANULADO') NOT NULL DEFAULT 'VIGENTE',
    -- comisión congelada al liquidar
    id_comision_regla INT UNSIGNED NULL,
    comision_pct    DECIMAL(9,6) NULL,
    comision_monto  DECIMAL(15,2) NULL,
    -- migración
    origen_sistema  VARCHAR(30)  NULL,
    origen_id       VARCHAR(50)  NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    PRIMARY KEY (id_prestamo),
    UNIQUE KEY uq_prestamos_nro (nro_prestamo),
    UNIQUE KEY uq_prestamos_origen (origen_sistema, origen_id),
    KEY ix_prestamos_cliente (id_cliente),
    KEY ix_prestamos_vendedor_fecha (id_vendedor, fecha_liquidacion),
    KEY ix_prestamos_estado (estado),
    CONSTRAINT fk_pre_cliente  FOREIGN KEY (id_cliente)  REFERENCES clientes (id_cliente),
    CONSTRAINT fk_pre_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto),
    CONSTRAINT fk_pre_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_pre_tasa     FOREIGN KEY (id_tasa)     REFERENCES tasas_vigencia (id_tasa),
    CONSTRAINT fk_pre_usuario  FOREIGN KEY (creado_por)  REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Importes ORIGINALES de cada cuota. Lo pagado y el saldo salen de movimientos.
CREATE TABLE IF NOT EXISTS cuotas (
    id_cuota        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_prestamo     INT UNSIGNED NOT NULL,
    nro_cuota       SMALLINT UNSIGNED NOT NULL,
    vencimiento     DATE         NOT NULL,
    capital         DECIMAL(15,2) NOT NULL,
    interes         DECIMAL(15,2) NOT NULL,
    iva_interes     DECIMAL(15,2) NOT NULL,
    importe         DECIMAL(15,2) NOT NULL,                -- capital + interés + IVA
    saldo_capital   DECIMAL(15,2) NOT NULL,                -- saldo de capital después de esta cuota
    punitorio_hasta DATE         NULL,                     -- hasta qué fecha se devengaron punitorios
    estado          ENUM('PENDIENTE','PARCIAL','PAGADA','REFINANCIADA') NOT NULL DEFAULT 'PENDIENTE',
    PRIMARY KEY (id_cuota),
    UNIQUE KEY uq_cuotas_prestamo_nro (id_prestamo, nro_cuota),
    KEY ix_cuotas_vto_estado (vencimiento, estado),
    CONSTRAINT fk_cuo_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Libro de movimientos del préstamo. Debe positivo, haber negativo.
CREATE TABLE IF NOT EXISTS movimientos (
    id_movimiento   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_prestamo     INT UNSIGNED NOT NULL,
    id_cuota        INT UNSIGNED NULL,
    id_pago         INT UNSIGNED NULL,       -- pago que originó el movimiento (FK en el script de cobranzas)
    fecha           DATE         NOT NULL,
    tipo            VARCHAR(30)  NOT NULL,   -- DESEMBOLSO, GASTO_OTORG, PAGO, ANULACION_PAGO, PUNITORIO, IVA_PUNITORIO, QUITA_INTERES, QUITA_IVA, AJUSTE
    importe         DECIMAL(15,2) NOT NULL,
    referencia      VARCHAR(100) NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    PRIMARY KEY (id_movimiento),
    KEY ix_mov_prestamo_fecha (id_prestamo, fecha),
    KEY ix_mov_cuota (id_cuota),
    KEY ix_mov_pago (id_pago),
    CONSTRAINT fk_mov_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_mov_cuota    FOREIGN KEY (id_cuota)    REFERENCES cuotas (id_cuota),
    CONSTRAINT fk_mov_usuario  FOREIGN KEY (creado_por)  REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 6. COMISIONES
-- =====================================================================

-- Reglas de comisión. Se elige la que cumpla todas las condiciones y tenga
-- mayor prioridad (a igual prioridad, la de vigencia más reciente).
-- Un NULL en id_producto / id_vendedor / id_zona significa "cualquiera".
--   Ej.: general 3 % (prioridad 0); producto PERSONAL 4 % (10);
--        vendedor X en PERSONAL 5 % (20).
CREATE TABLE IF NOT EXISTS comision_reglas (
    id_regla        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    descripcion     VARCHAR(120) NOT NULL,
    id_producto     INT UNSIGNED NULL,
    id_vendedor     INT UNSIGNED NULL,
    id_zona         INT UNSIGNED NULL,
    plazo_desde     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    plazo_hasta     SMALLINT UNSIGNED NOT NULL DEFAULT 999,
    monto_desde     DECIMAL(15,2) NOT NULL DEFAULT 0,
    monto_hasta     DECIMAL(15,2) NOT NULL DEFAULT 999999999,
    porcentaje      DECIMAL(9,6) NOT NULL,                 -- 0.040000 = 4 %
    base_calculo    ENUM('CAPITAL','NETO_DESEMBOLSO') NOT NULL DEFAULT 'CAPITAL',
    momento         ENUM('LIQUIDACION','COBRANZA') NOT NULL DEFAULT 'LIQUIDACION',
    prioridad       SMALLINT     NOT NULL DEFAULT 0,
    vigencia_desde  DATE         NOT NULL,
    vigencia_hasta  DATE         NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_regla),
    KEY ix_reglas_vig (vigencia_desde, vigencia_hasta),
    CONSTRAINT fk_reg_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto),
    CONSTRAINT fk_reg_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_reg_zona     FOREIGN KEY (id_zona)     REFERENCES zonas (id_zona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada comisión ganada (positiva) o revertida (negativa, clawback/anulación).
CREATE TABLE IF NOT EXISTS comisiones_devengadas (
    id_devengada    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_vendedor     INT UNSIGNED NOT NULL,
    id_prestamo     INT UNSIGNED NOT NULL,
    id_regla        INT UNSIGNED NULL,                     -- NULL si el % fue manual
    fecha           DATE         NOT NULL,
    concepto        ENUM('ALTA','CLAWBACK','ANULACION','AJUSTE') NOT NULL DEFAULT 'ALTA',
    base            DECIMAL(15,2) NOT NULL,
    porcentaje      DECIMAL(9,6) NOT NULL,
    importe         DECIMAL(15,2) NOT NULL,
    id_liquidacion  INT UNSIGNED NULL,                     -- se completa al liquidar
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_devengada),
    KEY ix_dev_vendedor_fecha (id_vendedor, fecha),
    KEY ix_dev_liquidacion (id_liquidacion),
    KEY ix_dev_prestamo (id_prestamo),
    CONSTRAINT fk_dev_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_dev_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_dev_regla    FOREIGN KEY (id_regla)    REFERENCES comision_reglas (id_regla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cabecera de liquidación: una por vendedor y período.
-- Reemplaza la "hoja por archivo" de la macro actual; generado_por / generado_el
-- reemplazan Autor / Guardado el.
CREATE TABLE IF NOT EXISTS comisiones_liquidacion (
    id_liquidacion  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_vendedor     INT UNSIGNED NOT NULL,
    periodo_desde   DATE         NOT NULL,
    periodo_hasta   DATE         NOT NULL,
    registros       INT UNSIGNED NOT NULL DEFAULT 0,
    total_base      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_comision  DECIMAL(15,2) NOT NULL DEFAULT 0,
    estado          ENUM('BORRADOR','APROBADA','PAGADA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
    generado_por    INT UNSIGNED NULL,
    generado_el     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobado_por    INT UNSIGNED NULL,
    aprobado_el     DATETIME     NULL,
    PRIMARY KEY (id_liquidacion),
    KEY ix_liq_vendedor_periodo (id_vendedor, periodo_desde),
    CONSTRAINT fk_liq_vendedor FOREIGN KEY (id_vendedor)  REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_liq_generado FOREIGN KEY (generado_por) REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_liq_aprobado FOREIGN KEY (aprobado_por) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subtotales por % aplicado dentro de cada liquidación
-- (el bloque "SUBTOTALES POR % DE COMISION APLICADO" de la macro).
CREATE TABLE IF NOT EXISTS comisiones_liquidacion_detalle (
    id_liquidacion  INT UNSIGNED NOT NULL,
    porcentaje      DECIMAL(9,6) NOT NULL,
    registros       INT UNSIGNED NOT NULL,
    base            DECIMAL(15,2) NOT NULL,
    comision        DECIMAL(15,2) NOT NULL,
    PRIMARY KEY (id_liquidacion, porcentaje),
    CONSTRAINT fk_liqd_liq FOREIGN KEY (id_liquidacion) REFERENCES comisiones_liquidacion (id_liquidacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE comisiones_devengadas
    ADD CONSTRAINT fk_dev_liquidacion FOREIGN KEY (id_liquidacion)
        REFERENCES comisiones_liquidacion (id_liquidacion);

ALTER TABLE prestamos
    ADD CONSTRAINT fk_pre_regla FOREIGN KEY (id_comision_regla)
        REFERENCES comision_reglas (id_regla);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 7. STORED PROCEDURES
-- =====================================================================

DELIMITER $$

-- ---------------------------------------------------------------------
-- sp_cuadro_frances
--   Motor ÚNICO de cálculo. Deja el cuadro en la tabla temporal tmp_cuadro.
--   Tasa del período = TNA / 12 (convención 30/360, mensual).
--   Cada concepto se redondea a 2 decimales y la última cuota absorbe la
--   diferencia de capital, así el saldo cierra exacto en 0.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_cuadro_frances $$
CREATE PROCEDURE sp_cuadro_frances(
    IN p_capital     DECIMAL(15,2),
    IN p_plazo       SMALLINT,
    IN p_tna         DECIMAL(9,6),
    IN p_alic_iva    DECIMAL(6,4),
    IN p_primer_vto  DATE
)
BEGIN
    DECLARE v_i       DOUBLE;
    DECLARE v_cuota   DECIMAL(15,2);
    DECLARE v_saldo   DECIMAL(15,2);
    DECLARE v_interes DECIMAL(15,2);
    DECLARE v_capital DECIMAL(15,2);
    DECLARE v_iva     DECIMAL(15,2);
    DECLARE n         SMALLINT DEFAULT 1;

    IF p_capital IS NULL OR p_capital <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El capital debe ser mayor a cero';
    END IF;
    IF p_plazo IS NULL OR p_plazo < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El plazo debe ser de al menos 1 cuota';
    END IF;
    IF p_tna IS NULL OR p_tna < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La TNA no puede ser negativa';
    END IF;
    IF p_primer_vto IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta la fecha del primer vencimiento';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_cuadro;
    CREATE TEMPORARY TABLE tmp_cuadro (
        nro_cuota     SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        vencimiento   DATE          NOT NULL,
        capital       DECIMAL(15,2) NOT NULL,
        interes       DECIMAL(15,2) NOT NULL,
        iva_interes   DECIMAL(15,2) NOT NULL,
        importe       DECIMAL(15,2) NOT NULL,
        saldo_capital DECIMAL(15,2) NOT NULL
    ) ENGINE=MEMORY;

    SET v_i = p_tna / 12;

    -- Cuota pura (capital + interés, sin IVA)
    IF v_i = 0 THEN
        SET v_cuota = ROUND(p_capital / p_plazo, 2);
    ELSE
        SET v_cuota = ROUND(p_capital * v_i / (1 - POW(1 + v_i, -p_plazo)), 2);
    END IF;

    SET v_saldo = p_capital;

    WHILE n <= p_plazo DO
        SET v_interes = ROUND(v_saldo * v_i, 2);

        IF n = p_plazo THEN
            SET v_capital = v_saldo;                    -- cierra el saldo exacto
        ELSE
            SET v_capital = v_cuota - v_interes;
        END IF;

        SET v_iva   = ROUND(v_interes * p_alic_iva, 2);
        SET v_saldo = v_saldo - v_capital;

        INSERT INTO tmp_cuadro
        VALUES (n,
                DATE_ADD(p_primer_vto, INTERVAL (n - 1) MONTH),
                v_capital, v_interes, v_iva,
                v_capital + v_interes + v_iva,
                v_saldo);

        SET n = n + 1;
    END WHILE;
END $$

-- ---------------------------------------------------------------------
-- fn_tasa_vigente: id de la tasa aplicable a producto/plazo/monto/fecha
-- ---------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_tasa_vigente $$
CREATE FUNCTION fn_tasa_vigente(
    p_id_producto INT UNSIGNED,
    p_plazo       SMALLINT,
    p_monto       DECIMAL(15,2),
    p_fecha       DATE
) RETURNS INT UNSIGNED
READS SQL DATA
BEGIN
    DECLARE v_id INT UNSIGNED DEFAULT NULL;

    SELECT t.id_tasa INTO v_id
      FROM tasas_vigencia t
     WHERE t.id_producto    = p_id_producto
       AND t.vigencia_desde <= p_fecha
       AND p_plazo BETWEEN t.plazo_desde AND t.plazo_hasta
       AND p_monto BETWEEN t.monto_desde AND t.monto_hasta
     ORDER BY t.vigencia_desde DESC, t.id_tasa DESC
     LIMIT 1;

    RETURN v_id;
END $$

-- ---------------------------------------------------------------------
-- sp_simular_prestamo
--   Simulador (backoffice y app). Valida producto, busca la tasa vigente
--   y devuelve dos resultsets: el resumen y el cuadro de cuotas.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_simular_prestamo $$
CREATE PROCEDURE sp_simular_prestamo(
    IN p_id_producto INT UNSIGNED,
    IN p_capital     DECIMAL(15,2),
    IN p_plazo       SMALLINT,
    IN p_fecha       DATE,          -- fecha de liquidación prevista (NULL = hoy)
    IN p_primer_vto  DATE           -- NULL = un mes después de p_fecha
)
BEGIN
    DECLARE v_id_tasa  INT UNSIGNED;
    DECLARE v_tna      DECIMAL(9,6);
    DECLARE v_iva      DECIMAL(6,4);
    DECLARE v_gasto    DECIMAL(6,4);
    DECLARE v_pmin, v_pmax SMALLINT;
    DECLARE v_mmin, v_mmax DECIMAL(15,2);
    DECLARE v_activo   TINYINT;

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

    -- Resumen
    SELECT p_capital                                   AS capital,
           p_plazo                                     AS plazo,
           v_tna                                       AS tna,
           ROUND(v_tna / 12, 6)                        AS tem,
           ROUND(POW(1 + v_tna / 12, 12) - 1, 6)       AS tea,
           ROUND(p_capital * v_gasto, 2)               AS gastos_otorg,
           p_capital - ROUND(p_capital * v_gasto, 2)   AS neto_desembolso,
           (SELECT importe FROM tmp_cuadro WHERE nro_cuota = 1) AS valor_cuota,
           (SELECT SUM(interes)     FROM tmp_cuadro)   AS total_intereses,
           (SELECT SUM(iva_interes) FROM tmp_cuadro)   AS total_iva,
           (SELECT SUM(importe)     FROM tmp_cuadro)   AS total_a_pagar;

    -- Cuadro
    SELECT * FROM tmp_cuadro ORDER BY nro_cuota;
END $$

-- ---------------------------------------------------------------------
-- fn_comision_regla: regla aplicable a un préstamo en una fecha
-- ---------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_comision_regla $$
CREATE FUNCTION fn_comision_regla(
    p_id_vendedor INT UNSIGNED,
    p_id_producto INT UNSIGNED,
    p_plazo       SMALLINT,
    p_monto       DECIMAL(15,2),
    p_fecha       DATE
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
       AND (r.id_producto IS NULL OR r.id_producto = p_id_producto)
       AND (r.id_vendedor IS NULL OR r.id_vendedor = p_id_vendedor)
       AND (r.id_zona     IS NULL OR r.id_zona     = v_zona)
       AND p_plazo BETWEEN r.plazo_desde AND r.plazo_hasta
       AND p_monto BETWEEN r.monto_desde AND r.monto_hasta
     ORDER BY r.prioridad DESC, r.vigencia_desde DESC, r.id_regla DESC
     LIMIT 1;

    RETURN v_id;
END $$

-- ---------------------------------------------------------------------
-- sp_liquidar_prestamo
--   Da de alta el préstamo con su cuadro de cuotas, registra los
--   movimientos de desembolso y devenga la comisión del vendedor.
--   p_comision_pct: NULL = se toma de las reglas; un valor = % manual
--   (operación negociada), queda guardado sin regla asociada.
-- ---------------------------------------------------------------------
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
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

    -- Validaciones
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
    -- Punitoria: la de la tasa, o un % de la TNA según parámetro (por defecto 50 %)
    SELECT t.tna,
           COALESCE(t.tasa_punitoria,
                    ROUND(t.tna * COALESCE((SELECT CAST(valor AS DECIMAL(9,6)) FROM parametros
                                             WHERE clave = 'PUNITORIO_PCT_TNA'), 0.5), 6))
      INTO v_tna, v_punit
      FROM tasas_vigencia t WHERE t.id_tasa = v_id_tasa;

    SET v_gastos = ROUND(p_capital * v_gasto_pct, 2);

    -- Cuadro (mismo motor que el simulador). Se calcula antes de la
    -- transacción porque crea una tabla temporal.
    CALL sp_cuadro_frances(p_capital, p_plazo, v_tna, v_iva, p_primer_vto);

    -- Comisión
    IF p_id_vendedor IS NOT NULL THEN
        IF p_comision_pct IS NOT NULL THEN
            SET v_com_pct   = p_comision_pct;
            SET v_base_tipo = 'CAPITAL';
        ELSE
            SET v_id_regla = fn_comision_regla(p_id_vendedor, p_id_producto, p_plazo, p_capital, p_fecha);
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
        id_comision_regla, comision_pct, comision_monto, creado_por)
    SELECT p_nro_prestamo, p_id_cliente, p_id_producto, p_id_vendedor, v_id_tasa,
           p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
           v_punit, v_gastos, p_capital - v_gastos,
           (SELECT importe FROM tmp_cuadro WHERE nro_cuota = 1),
           (SELECT SUM(interes) FROM tmp_cuadro),
           (SELECT SUM(iva_interes) FROM tmp_cuadro),
           v_id_regla, v_com_pct, v_com_monto, p_id_usuario;

    SET p_id_prestamo = LAST_INSERT_ID();

    INSERT INTO cuotas (id_prestamo, nro_cuota, vencimiento, capital, interes,
                        iva_interes, importe, saldo_capital)
    SELECT p_id_prestamo, nro_cuota, vencimiento, capital, interes,
           iva_interes, importe, saldo_capital
      FROM tmp_cuadro;

    -- Movimientos: el préstamo nace debiendo el capital; los gastos se
    -- descuentan del desembolso (no se suman a la deuda).
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

-- ---------------------------------------------------------------------
-- sp_comisiones_liquidar
--   Genera la liquidación del período para un vendedor (o para todos si
--   p_id_vendedor es NULL). Toma las comisiones devengadas sin liquidar
--   con fecha dentro del período, incluidas las negativas (clawback).
--   Arma la cabecera, los subtotales por % y marca las devengadas.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_comisiones_liquidar $$
CREATE PROCEDURE sp_comisiones_liquidar(
    IN p_desde       DATE,
    IN p_hasta       DATE,
    IN p_id_vendedor INT UNSIGNED,
    IN p_id_usuario  INT UNSIGNED
)
BEGIN
    DECLARE v_fin  TINYINT DEFAULT 0;
    DECLARE v_vend INT UNSIGNED;
    DECLARE v_liq  INT UNSIGNED;

    DECLARE cur CURSOR FOR
        SELECT DISTINCT d.id_vendedor
          FROM comisiones_devengadas d
         WHERE d.id_liquidacion IS NULL
           AND d.fecha BETWEEN p_desde AND p_hasta
           AND (p_id_vendedor IS NULL OR d.id_vendedor = p_id_vendedor)
         ORDER BY d.id_vendedor;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_desde IS NULL OR p_hasta IS NULL OR p_hasta < p_desde THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Período inválido';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_liq_generadas;
    CREATE TEMPORARY TABLE tmp_liq_generadas (id_liquidacion INT UNSIGNED PRIMARY KEY);

    START TRANSACTION;

    OPEN cur;
    lazo: LOOP
        FETCH cur INTO v_vend;
        IF v_fin = 1 THEN LEAVE lazo; END IF;

        INSERT INTO comisiones_liquidacion (id_vendedor, periodo_desde, periodo_hasta, generado_por)
        VALUES (v_vend, p_desde, p_hasta, p_id_usuario);
        SET v_liq = LAST_INSERT_ID();

        UPDATE comisiones_devengadas
           SET id_liquidacion = v_liq
         WHERE id_liquidacion IS NULL
           AND id_vendedor = v_vend
           AND fecha BETWEEN p_desde AND p_hasta;

        INSERT INTO comisiones_liquidacion_detalle (id_liquidacion, porcentaje, registros, base, comision)
        SELECT v_liq, porcentaje, COUNT(*), SUM(base), SUM(importe)
          FROM comisiones_devengadas
         WHERE id_liquidacion = v_liq
         GROUP BY porcentaje;

        UPDATE comisiones_liquidacion l
          JOIN (SELECT COUNT(*) AS regs, SUM(base) AS b, SUM(importe) AS c
                  FROM comisiones_devengadas WHERE id_liquidacion = v_liq) t
           SET l.registros = t.regs, l.total_base = t.b, l.total_comision = t.c
         WHERE l.id_liquidacion = v_liq;

        INSERT INTO tmp_liq_generadas VALUES (v_liq);
    END LOOP;
    CLOSE cur;

    COMMIT;

    -- Devuelve lo generado
    SELECT l.id_liquidacion, v.codigo, v.nombre AS vendedor, l.periodo_desde, l.periodo_hasta,
           l.registros, l.total_base, l.total_comision,
           ROUND(l.total_comision / NULLIF(l.total_base, 0), 6) AS pct_promedio
      FROM comisiones_liquidacion l
      JOIN tmp_liq_generadas g ON g.id_liquidacion = l.id_liquidacion
      JOIN vendedores v        ON v.id_vendedor    = l.id_vendedor
     ORDER BY v.codigo;
END $$

-- ---------------------------------------------------------------------
-- sp_comisiones_resumen
--   Equivalente a la hoja "Resumen" de la macro, pero desde la base:
--   1) un renglón por liquidación del período con totales y % promedio
--      ponderado, y observación si hay más de un % aplicado
--   2) subtotales por % aplicado de cada liquidación
--   3) total general
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_comisiones_resumen $$
CREATE PROCEDURE sp_comisiones_resumen(
    IN p_desde DATE,
    IN p_hasta DATE
)
BEGIN
    -- 1) Renglón por liquidación
    SELECT l.id_liquidacion, v.codigo, v.nombre AS vendedor,
           l.registros, l.total_base AS total_monto, l.total_comision,
           ROUND(l.total_comision / NULLIF(l.total_base, 0), 6) AS pct_promedio,
           CASE WHEN COUNT(d.porcentaje) > 1
                THEN CONCAT(COUNT(d.porcentaje), ' porcentajes distintos: ',
                            GROUP_CONCAT(CONCAT(FORMAT(d.porcentaje * 100, 2), '%')
                                         ORDER BY d.porcentaje SEPARATOR ' / '))
                ELSE '' END AS observacion,
           l.estado, u.nombre AS generado_por, l.generado_el
      FROM comisiones_liquidacion l
      JOIN vendedores v ON v.id_vendedor = l.id_vendedor
      LEFT JOIN comisiones_liquidacion_detalle d ON d.id_liquidacion = l.id_liquidacion
      LEFT JOIN usuarios u ON u.id_usuario = l.generado_por
     WHERE l.periodo_desde >= p_desde AND l.periodo_hasta <= p_hasta
       AND l.estado <> 'ANULADA'
     GROUP BY l.id_liquidacion, v.codigo, v.nombre, l.registros, l.total_base,
              l.total_comision, l.estado, u.nombre, l.generado_el
     ORDER BY v.codigo;

    -- 2) Subtotales por % aplicado (todas las liquidaciones del período)
    SELECT d.porcentaje, SUM(d.registros) AS registros,
           SUM(d.base) AS monto, SUM(d.comision) AS comision
      FROM comisiones_liquidacion_detalle d
      JOIN comisiones_liquidacion l ON l.id_liquidacion = d.id_liquidacion
     WHERE l.periodo_desde >= p_desde AND l.periodo_hasta <= p_hasta
       AND l.estado <> 'ANULADA'
     GROUP BY d.porcentaje
     ORDER BY d.porcentaje;

    -- 3) Total general
    SELECT COUNT(*) AS liquidaciones, SUM(l.registros) AS registros,
           SUM(l.total_base) AS total_monto, SUM(l.total_comision) AS total_comision,
           ROUND(SUM(l.total_comision) / NULLIF(SUM(l.total_base), 0), 6) AS pct_promedio
      FROM comisiones_liquidacion l
     WHERE l.periodo_desde >= p_desde AND l.periodo_hasta <= p_hasta
       AND l.estado <> 'ANULADA';
END $$

DELIMITER ;

-- =====================================================================
-- Parámetros iniciales
-- =====================================================================
INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
 ('DIAS_GRACIA_MORA',      '0',  'Días después del vencimiento antes de calcular punitorios'),
 ('CLAWBACK_CUOTAS',       '3',  'Si el préstamo cae en mora antes de esta cuota, se revierte la comisión'),
 ('CONVENCION_TASA',       '30/360', 'Tasa del período = TNA / 12'),
 ('PUNITORIO_PCT_TNA',     '0.5', 'Si la tasa no define punitoria: punitoria = TNA x este valor'),
 ('PUNITORIO_BASE',        'CAPITAL', 'Base del punitorio: CAPITAL (capital impago de la cuota) o CUOTA (capital + interés + IVA impagos)');
