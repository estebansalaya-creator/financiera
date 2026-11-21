-- =====================================================================
--  FINANCIERA - Extractos bancarios
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_fondos.sql, con la base de la financiera seleccionada.
--  Se puede correr más de una vez.
--
--  El modelo de gestion_adm (c_extractos_bancarios y extractos_bancarios.py):
--  lo que el banco informa, renglón por renglón, importado de su archivo.
--  Es el lado banco de la conciliación; el lado libro son los
--  debitos_creditos_bancarios que deja cada movimiento de fondos.
--
--  Contenido
--    extractos_importaciones .. un renglón por archivo importado: cuenta,
--                               formato, cuántos entraron y cuántos ya
--                               estaban, o por qué no se importó. Una
--                               importación se deshace entera.
--    extractos_bancarios ...... un renglón por movimiento del banco:
--                               débito o crédito (siempre positivos),
--                               referencia, concepto, CUIT de la
--                               contraparte y el saldo cuando el banco lo
--                               informa (BNA: uno por día; ICBC: todos).
--    Menú ..................... Tesorería › Extractos bancarios (85), para
--                               Administrador y Gerencia.
--
--  Formatos que lee la aplicación (inc\extractos.php): BNA clásico, BNA
--  Banca Empresas (BEE_Reporte_*.csv), ICBC cuenta corriente e ICBC
--  reporte de recaudaciones.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS extractos_importaciones (
    id_importacion    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cuenta_fondos  INT UNSIGNED  NOT NULL,
    archivo           VARCHAR(255)  NOT NULL COMMENT 'Nombre del archivo, como llegó',
    formato           VARCHAR(20)   NULL     COMMENT 'BNA_CLASICO, BNA_EMPRESAS, ICBC_CC o ICBC_REC (NULL: no se reconoció)',
    resultado         ENUM('OK','ERROR') NOT NULL,
    motivo            VARCHAR(255)  NULL     COMMENT 'Por qué no se importó, o el aviso (el BNA clásico no trae la cuenta)',
    renglones         INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Movimientos leídos del archivo',
    insertados        INT UNSIGNED  NOT NULL DEFAULT 0,
    repetidos         INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Ya estaban cargados en la cuenta',
    total_debitos     DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'De lo insertado',
    total_creditos    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'De lo insertado',
    fecha_desde       DATE          NULL     COMMENT 'Período que cubre el archivo',
    fecha_hasta       DATE          NULL,
    importado_el      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    importado_por     INT UNSIGNED  NULL,
    deshecha_el       DATETIME      NULL     COMMENT 'Se borraron sus movimientos',
    deshecha_por      INT UNSIGNED  NULL,
    PRIMARY KEY (id_importacion),
    KEY idx_extimp_cuenta (id_cuenta_fondos, importado_el),
    CONSTRAINT fk_extimp_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Archivos de extractos importados';

CREATE TABLE IF NOT EXISTS extractos_bancarios (
    id_extracto       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cuenta_fondos  INT UNSIGNED  NOT NULL,
    id_importacion    INT UNSIGNED  NULL     COMMENT 'NULL: cargado a mano',
    fecha             DATE          NOT NULL COMMENT 'Fecha del movimiento (contable)',
    fecha_valor       DATE          NULL,
    referencia        VARCHAR(40)   NULL     COMMENT 'N° de operación, de comprobante o de cheque',
    debito            DECIMAL(15,2) NULL     COMMENT 'Sale de la cuenta (positivo)',
    credito           DECIMAL(15,2) NULL     COMMENT 'Entra a la cuenta (positivo)',
    saldo             DECIMAL(15,2) NULL     COMMENT 'Saldo que informa el banco en ese renglón',
    concepto          VARCHAR(200)  NULL,
    cuit              CHAR(11)      NULL     COMMENT 'De la contraparte, sacado del texto',
    origen            VARCHAR(10)   NOT NULL COMMENT 'BNA, ICBC o MANUAL',
    conciliado        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Lo marca la conciliación',
    id_conciliacion   INT UNSIGNED  NULL     COMMENT 'La conciliación que lo levantó',
    importado_el      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_extracto),
    KEY idx_ext_cuenta_fecha (id_cuenta_fondos, fecha),
    KEY idx_ext_importacion (id_importacion),
    KEY idx_ext_cuit (cuit),
    CONSTRAINT fk_ext_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT fk_ext_importacion FOREIGN KEY (id_importacion) REFERENCES extractos_importaciones (id_importacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Movimientos informados por el banco';

-- ---------------------------------------------------------------------
-- Menú: Tesorería › Extractos bancarios (Cuentas de fondos pasa al final)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (85, 80, 'Extractos bancarios', 'extractos_bancarios.php', NULL, 4, 2, 1);
UPDATE menu SET orden = 5 WHERE id_menu = 84 AND url = 'cuentas_fondos.php' AND orden = 4;
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT m.id_menu, r.id_rol FROM menu m JOIN roles r ON r.id_rol IN (1, 2)
 WHERE m.id_menu = 85 AND m.url = 'extractos_bancarios.php';

-- Control
SELECT m.id_menu, m.nombre, m.url, m.orden FROM menu m WHERE m.padre_id = 80 ORDER BY m.orden;
