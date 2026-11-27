-- =====================================================================
--  FINANCIERA - Conciliación bancaria
--
--  Correr DESPUÉS de financiera_recibos.sql, financiera_extractos.sql y
--  financiera_contabilidad_asientos.sql (pasos 26 a 28), con la base de la
--  financiera seleccionada. MySQL 8. Se puede correr más de una vez: las
--  tablas no se recrean; los procedures sí. En DBeaver: Ejecutar script
--  (Alt+X), porque tiene DELIMITER.
--
--  OJO: redefine sp_fondos_renglon (le suma el origen BANCARIO). Si se
--  vuelve a correr financiera_recibos.sql u ordenes_pago.sql, después hay
--  que correr este de nuevo.
--
--  EL MODELO (el de gestion_adm: conciliacion_header, conciliacion_items,
--  conciliacion_grupos, conciliacion_comprobantes y conc_reglas)
--  -----------------------------------------------------------------
--  Se concilia una cuenta banco por período. De un lado, el extracto
--  (extractos_bancarios: lo que informa el banco). Del otro, el libro: los
--  débitos y créditos bancarios que deja cada movimiento de fondos
--  (debitos_creditos_bancarios, uno por instrumento).
--
--    conciliaciones ............ una por cuenta y período (AAAAMM): saldo
--                                inicial (el final de la anterior cerrada,
--                                o a mano la primera vez), totales del
--                                extracto, saldo informado por el banco
--    conciliacion_grupos ....... un vínculo N a N: renglones del extracto
--                                contra renglones del libro, por el mismo
--                                importe
--    conciliacion_items ........ un renglón del extracto dentro de la
--                                conciliación: clasificado por una regla
--                                (pendiente), conciliado (en un grupo) o
--                                excepción (con su observación)
--    conciliacion_libro ........ el lado libro de cada grupo: un débito o
--                                crédito bancario va en un solo grupo
--
--  CLASIFICACIÓN DE CONCEPTOS (conc_reglas)
--  ----------------------------------------
--  Un texto del extracto («COMISION MANTENIMIENTO», «IMP LEY 25413») se
--  asocia a un concepto de tesorería, o a una transferencia a otra cuenta
--  propia. Con la regla, la conciliación los reconoce sola y emite el
--  comprobante que falta en el libro.
--
--  EMISIÓN DE COMPROBANTES
--  -----------------------
--  Lo que el banco cobra o acredita por su cuenta (comisiones, impuestos,
--  percepciones, intereses) no tiene comprobante en el libro hasta que se
--  ve en el extracto. Se emite desde la conciliación:
--    comprobantes_bancarios ...... gasto o ingreso bancario (la OP de
--                                  concepto pagada / la OI bancaria de
--                                  gestion_adm, en uno), con su detalle
--                                  por concepto y por renglón del extracto.
--                                  Deja su renglón en movimientos_fondos
--                                  (origen BANCARIO) y queda conciliado.
--    movimientos_entre_cuentas ... una transferencia a otra cuenta propia.
--  conceptos_tesoreria es el catálogo (los c_concepto_de_gastos y
--  c_motivos_de_ingreso de gestion_adm), cada uno con su cuenta contable.
--
--  Menú: Tesorería › Conciliación bancaria (89), Clasificación de
--  conceptos (78) y Conceptos de tesorería (77), para los mismos roles
--  que Extractos bancarios.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Conceptos de tesorería: gastos e ingresos bancarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conceptos_tesoreria (
    id_concepto_tes    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tipo               ENUM('GASTO','INGRESO') NOT NULL COMMENT 'GASTO sale de la cuenta, INGRESO entra',
    codigo             VARCHAR(20)   NOT NULL,
    nombre             VARCHAR(100)  NOT NULL,
    id_cuenta_contable INT UNSIGNED  NULL     COMMENT 'Contra qué cuenta va en el asiento',
    activo             TINYINT(1)    NOT NULL DEFAULT 1,
    orden              SMALLINT      NOT NULL DEFAULT 0,
    creado_el          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por         INT UNSIGNED  NULL,
    PRIMARY KEY (id_concepto_tes),
    UNIQUE KEY uq_ctes_codigo (codigo),
    CONSTRAINT fk_ctes_cuenta FOREIGN KEY (id_cuenta_contable) REFERENCES c_plan_de_cuentas (id_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Conceptos de los gastos e ingresos bancarios (comisiones, impuestos, intereses)';

INSERT IGNORE INTO conceptos_tesoreria (tipo, codigo, nombre, orden) VALUES
 ('GASTO',   'COMISION',  'Comisiones y gastos bancarios',             10),
 ('GASTO',   'IVA',       'IVA crédito fiscal (de gastos bancarios)',  20),
 ('GASTO',   'IDC',       'Impuesto a los débitos y créditos',         30),
 ('GASTO',   'PERC_IVA',  'Percepción de IVA',                         40),
 ('GASTO',   'SIRCREB',   'Percepción de Ingresos Brutos (SIRCREB)',   50),
 ('GASTO',   'INT_BANCO', 'Intereses bancarios (descubierto)',         60),
 ('INGRESO', 'INT_GANADO','Intereses ganados',                         70),
 ('INGRESO', 'REINTEGRO', 'Reintegros y devoluciones bancarias',       80);

-- ---------------------------------------------------------------------
-- 2. Comprobantes bancarios (gasto o ingreso) y su detalle
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comprobantes_bancarios (
    id_comp_banco    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nro              VARCHAR(20)   NOT NULL COMMENT '2026-00000001: año y número (vuelve a 1 cada año)',
    tipo             ENUM('GASTO','INGRESO') NOT NULL,
    id_cuenta_fondos INT UNSIGNED  NOT NULL,
    fecha            DATE          NOT NULL,
    importe          DECIMAL(15,2) NOT NULL,
    concepto         VARCHAR(200)  NOT NULL,
    origen           ENUM('CONCILIACION','MANUAL') NOT NULL DEFAULT 'CONCILIACION',
    id_conciliacion  INT UNSIGNED  NULL,
    estado           ENUM('VIGENTE','ANULADO') NOT NULL DEFAULT 'VIGENTE',
    anulado_el       DATETIME      NULL,
    anulado_por      INT UNSIGNED  NULL,
    motivo_anulacion VARCHAR(200)  NULL,
    creado_el        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED  NULL,
    PRIMARY KEY (id_comp_banco),
    UNIQUE KEY uq_cb_nro (nro),
    KEY ix_cb_cuenta_fecha (id_cuenta_fondos, fecha),
    CONSTRAINT fk_cb_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT ck_cb_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Gastos e ingresos bancarios: lo que el banco cobra o acredita por su cuenta';

CREATE TABLE IF NOT EXISTS comprobantes_bancarios_det (
    id_det          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_comp_banco   INT UNSIGNED  NOT NULL,
    id_concepto_tes INT UNSIGNED  NOT NULL,
    descripcion     VARCHAR(200)  NULL,
    importe         DECIMAL(15,2) NOT NULL,
    id_extracto     INT UNSIGNED  NULL COMMENT 'El renglón del extracto que lo originó',
    PRIMARY KEY (id_det),
    KEY ix_cbd_comp (id_comp_banco),
    KEY ix_cbd_extracto (id_extracto),
    CONSTRAINT fk_cbd_comp FOREIGN KEY (id_comp_banco) REFERENCES comprobantes_bancarios (id_comp_banco) ON DELETE CASCADE,
    CONSTRAINT fk_cbd_concepto FOREIGN KEY (id_concepto_tes) REFERENCES conceptos_tesoreria (id_concepto_tes),
    CONSTRAINT fk_cbd_extracto FOREIGN KEY (id_extracto) REFERENCES extractos_bancarios (id_extracto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Detalle de un comprobante bancario: concepto e importe';

-- ---------------------------------------------------------------------
-- 3. Libro de fondos: el origen BANCARIO
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS tmp_conc_libro $$
CREATE PROCEDURE tmp_conc_libro()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'id_comp_banco') THEN
        ALTER TABLE movimientos_fondos
          ADD COLUMN id_comp_banco INT UNSIGNED NULL COMMENT 'BANCARIO (comprobantes_bancarios)' AFTER id_recibo;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND COLUMN_NAME = 'tipo_origen'
                      AND GENERATION_EXPRESSION LIKE '%id_comp_banco%') THEN
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
                WHEN id_comp_banco      IS NOT NULL THEN 'BANCARIO'
              END) STORED,
          MODIFY id_origen INT UNSIGNED GENERATED ALWAYS AS (
              COALESCE(id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido, id_recibo,
                       id_comp_banco)) STORED,
          ADD CONSTRAINT ck_mf_un_origen CHECK (
              (id_pago IS NOT NULL) + (id_prestamo IS NOT NULL) + (id_inversion IS NOT NULL)
            + (id_liquidacion_inv IS NOT NULL) + (id_mov_cuentas IS NOT NULL) + (id_pago_emitido IS NOT NULL)
            + (id_recibo IS NOT NULL) + (id_comp_banco IS NOT NULL) = 1);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND INDEX_NAME = 'ix_mf_comp_banco') THEN
        ALTER TABLE movimientos_fondos ADD KEY ix_mf_comp_banco (id_comp_banco);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_fondos' AND CONSTRAINT_NAME = 'fk_mf_comp_banco') THEN
        ALTER TABLE movimientos_fondos
          ADD CONSTRAINT fk_mf_comp_banco FOREIGN KEY (id_comp_banco) REFERENCES comprobantes_bancarios (id_comp_banco);
    END IF;
END $$
DELIMITER ;
CALL tmp_conc_libro();
DROP PROCEDURE IF EXISTS tmp_conc_libro;

-- ---------------------------------------------------------------------
-- 4. Conciliaciones
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conc_reglas (
    id_regla           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre             VARCHAR(100)  NOT NULL,
    patron_texto       VARCHAR(200)  NOT NULL COMMENT 'Texto a buscar en el concepto del extracto',
    tipo_patron        ENUM('contiene','empieza_con','exacto','regex') NOT NULL DEFAULT 'contiene',
    tipo_movimiento    ENUM('debito','credito','ambos') NOT NULL DEFAULT 'ambos',
    tipo_resultado     ENUM('concepto','movimiento_cuentas','manual') NOT NULL DEFAULT 'concepto'
                       COMMENT 'concepto: gasto o ingreso bancario, movimiento_cuentas: transferencia a otra cuenta propia, manual: se reconoce pero se concilia a mano',
    id_concepto_tes    INT UNSIGNED  NULL,
    id_cuenta_destino  INT UNSIGNED  NULL     COMMENT 'La otra cuenta propia (movimiento_cuentas)',
    genera_comprobante TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1: la conciliación emite el comprobante que falta en el libro',
    agrupa_por_fecha   TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1: un comprobante por concepto y día, 0: uno por renglón del extracto',
    orden              SMALLINT      NOT NULL DEFAULT 0 COMMENT 'Prioridad: menor primero',
    activa             TINYINT(1)    NOT NULL DEFAULT 1 COMMENT 'Quitar la clasificación la desactiva: lo conciliado guarda su regla',
    creado_el          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por         INT UNSIGNED  NULL,
    PRIMARY KEY (id_regla),
    KEY ix_creg_activa (activa, orden),
    CONSTRAINT fk_creg_concepto FOREIGN KEY (id_concepto_tes) REFERENCES conceptos_tesoreria (id_concepto_tes),
    CONSTRAINT fk_creg_destino FOREIGN KEY (id_cuenta_destino) REFERENCES cuentas_fondos (id_cuenta_fondos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Clasificación de conceptos del extracto';

CREATE TABLE IF NOT EXISTS conciliaciones (
    id_conciliacion      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cuenta_fondos     INT UNSIGNED  NOT NULL,
    periodo              CHAR(6)       NOT NULL COMMENT 'AAAAMM',
    fecha_desde          DATE          NOT NULL,
    fecha_hasta          DATE          NOT NULL,
    saldo_inicial        DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'El final de la anterior cerrada, o a mano la primera vez',
    saldo_inicial_manual TINYINT(1)    NOT NULL DEFAULT 0,
    id_conc_anterior     INT UNSIGNED  NULL,
    total_creditos       DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Del extracto, al cerrar',
    total_debitos        DECIMAL(15,2) NOT NULL DEFAULT 0,
    saldo_final          DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'saldo_inicial + créditos - débitos',
    saldo_banco          DECIMAL(15,2) NULL     COMMENT 'El que informa el banco en el último renglón del período',
    estado               ENUM('abierto','en_proceso','cerrado') NOT NULL DEFAULT 'abierto',
    fecha_cierre         DATETIME      NULL,
    cerrado_por          INT UNSIGNED  NULL,
    observaciones        VARCHAR(500)  NULL,
    creado_el            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por           INT UNSIGNED  NULL,
    PRIMARY KEY (id_conciliacion),
    UNIQUE KEY uq_conc_cuenta_periodo (id_cuenta_fondos, periodo),
    CONSTRAINT fk_conc_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT fk_conc_anterior FOREIGN KEY (id_conc_anterior) REFERENCES conciliaciones (id_conciliacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Conciliación bancaria: una por cuenta y período';

CREATE TABLE IF NOT EXISTS conciliacion_grupos (
    id_grupo         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_conciliacion  INT UNSIGNED  NOT NULL,
    importe_extracto DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Con signo: crédito positivo, débito negativo',
    importe_libro    DECIMAL(15,2) NOT NULL DEFAULT 0,
    origen           ENUM('manual','sugerido','generado') NOT NULL DEFAULT 'manual'
                     COMMENT 'generado: el comprobante lo emitió la conciliación',
    observaciones    VARCHAR(300)  NULL,
    creado_el        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED  NULL,
    PRIMARY KEY (id_grupo),
    KEY ix_cgr_conc (id_conciliacion),
    CONSTRAINT fk_cgr_conc FOREIGN KEY (id_conciliacion) REFERENCES conciliaciones (id_conciliacion) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Vínculo N a N entre renglones del extracto y del libro';

CREATE TABLE IF NOT EXISTS conciliacion_items (
    id_item          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_conciliacion  INT UNSIGNED  NOT NULL,
    id_extracto      INT UNSIGNED  NOT NULL,
    id_grupo         INT UNSIGNED  NULL,
    id_regla         INT UNSIGNED  NULL     COMMENT 'Con qué regla se clasificó (NULL: a mano)',
    estado           ENUM('pendiente','conciliado','excepcion') NOT NULL DEFAULT 'pendiente'
                     COMMENT 'pendiente: clasificado, falta el comprobante o el vínculo',
    origen           ENUM('automatico','manual') NOT NULL DEFAULT 'automatico',
    observaciones    VARCHAR(300)  NULL,
    creado_el        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED  NULL,
    PRIMARY KEY (id_item),
    UNIQUE KEY uq_citem_extracto (id_conciliacion, id_extracto),
    KEY ix_citem_grupo (id_grupo),
    CONSTRAINT fk_citem_conc FOREIGN KEY (id_conciliacion) REFERENCES conciliaciones (id_conciliacion) ON DELETE CASCADE,
    CONSTRAINT fk_citem_extracto FOREIGN KEY (id_extracto) REFERENCES extractos_bancarios (id_extracto) ON DELETE CASCADE,
    CONSTRAINT fk_citem_grupo FOREIGN KEY (id_grupo) REFERENCES conciliacion_grupos (id_grupo) ON DELETE SET NULL,
    CONSTRAINT fk_citem_regla FOREIGN KEY (id_regla) REFERENCES conc_reglas (id_regla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Renglones del extracto dentro de una conciliación';

CREATE TABLE IF NOT EXISTS conciliacion_libro (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_grupo         INT UNSIGNED  NOT NULL,
    id_deb_cred      INT UNSIGNED  NOT NULL,
    importe          DECIMAL(15,2) NOT NULL COMMENT 'Con signo: crédito positivo, débito negativo',
    PRIMARY KEY (id),
    UNIQUE KEY uq_clib_debcred (id_deb_cred),
    KEY ix_clib_grupo (id_grupo),
    CONSTRAINT fk_clib_grupo FOREIGN KEY (id_grupo) REFERENCES conciliacion_grupos (id_grupo) ON DELETE CASCADE,
    CONSTRAINT fk_clib_debcred FOREIGN KEY (id_deb_cred) REFERENCES debitos_creditos_bancarios (id_deb_cred)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Lado libro de cada grupo: un débito o crédito bancario va en un solo grupo';

-- ---------------------------------------------------------------------
-- 5. Procedures
-- ---------------------------------------------------------------------
DELIMITER $$

-- sp_fondos_renglon: el de financiera_recibos.sql, con el origen BANCARIO.
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

    IF p_origen NOT IN ('COBRANZA', 'DESEMBOLSO', 'APORTE', 'PAGO_INVERSOR', 'MOVIMIENTO', 'PAGO_OP', 'RECIBO', 'BANCARIO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Origen de movimiento de fondos desconocido';
    END IF;
    IF p_debe < 0 OR p_haber < 0 OR (p_debe > 0 AND p_haber > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un renglón del libro de fondos es una entrada o una salida, no las dos';
    END IF;

    IF p_cuenta IS NOT NULL AND (p_debe > 0 OR p_haber > 0) THEN
        INSERT INTO movimientos_fondos (id_cuenta_fondos, fecha, importe_debe, importe_haber, concepto,
                                        id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas, id_pago_emitido,
                                        id_recibo, id_comp_banco)
        VALUES (p_cuenta, p_fecha, p_debe, p_haber, LEFT(p_concepto, 200),
                IF(p_origen = 'COBRANZA',      p_id, NULL),
                IF(p_origen = 'DESEMBOLSO',    p_id, NULL),
                IF(p_origen = 'APORTE',        p_id, NULL),
                IF(p_origen = 'PAGO_INVERSOR', p_id, NULL),
                IF(p_origen = 'MOVIMIENTO',    p_id, NULL),
                IF(p_origen = 'PAGO_OP',       p_id, NULL),
                IF(p_origen = 'RECIBO',        p_id, NULL),
                IF(p_origen = 'BANCARIO',      p_id, NULL))
        ON DUPLICATE KEY UPDATE
                id_mov_fondos = LAST_INSERT_ID(id_mov_fondos),
                fecha         = p_fecha,
                importe_debe  = p_debe,
                importe_haber = p_haber,
                concepto      = LEFT(p_concepto, 200);
        SET p_id_mov = LAST_INSERT_ID();
    END IF;
END $$

-- BANCARIO: el gasto sale de la cuenta, el ingreso entra. En el detalle
-- bancario es un débito automático (el banco lo hizo solo) o un crédito.
-- Sin transacción propia.
DROP PROCEDURE IF EXISTS sp_fondos_bancario $$
CREATE PROCEDURE sp_fondos_bancario(IN p_id INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_tipo     VARCHAR(10);
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_estado   VARCHAR(10);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT fecha, tipo, importe, id_cuenta_fondos, estado, motivo_anulacion, anulado_por,
           CONCAT(IF(tipo = 'GASTO', 'Gasto bancario ', 'Ingreso bancario '), nro, ' · ', concepto)
      INTO v_fecha, v_tipo, v_importe, v_cuenta, v_estado, v_motivo, v_anul_por, v_concepto
      FROM comprobantes_bancarios WHERE id_comp_banco = p_id;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    CALL sp_fondos_renglon('BANCARIO', p_id, v_cuenta, v_fecha,
                           IF(v_tipo = 'INGRESO', v_importe, 0), IF(v_tipo = 'GASTO', v_importe, 0), v_concepto, v_mf);
    CALL sp_fondos_quitar('BANCARIO', p_id, v_cuenta, NULL);
    IF v_mf IS NOT NULL THEN
        CALL sp_fondos_banco(v_mf, IF(v_tipo = 'GASTO', 'DEBITO_AUTOMATICO', 'OTRO'), NULL, NULL, NULL);
    END IF;
    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('BANCARIO', p_id, COALESCE(v_motivo, 'Comprobante anulado'), v_anul_por);
    END IF;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 6. Menú: Tesorería › Conciliación bancaria, Clasificación de conceptos
--    y Conceptos de tesorería (los roles de Extractos bancarios)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (89, 80, 'Conciliación bancaria',      'conciliaciones.php',      'banco', 7, 2, 1),
 (78, 80, 'Clasificación de conceptos', 'conc_clasificar.php',     'etiqueta', 8, 2, 1),
 (77, 80, 'Conceptos de tesorería',     'conceptos_tesoreria.php', 'ajustes', 11, 2, 1);
UPDATE menu SET orden = 9  WHERE id_menu = 84 AND url = 'cuentas_fondos.php';
UPDATE menu SET orden = 10 WHERE id_menu = 88 AND url = 'bancos.php';

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT m.id_menu, mr.id_rol
  FROM menu_roles mr
  JOIN (SELECT 89 AS id_menu UNION ALL SELECT 78 UNION ALL SELECT 77) m
 WHERE mr.id_menu = 85;
