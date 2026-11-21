-- =====================================================================
--  FINANCIERA - Talonarios de comprobantes y tipos de comprobante de ARCA
--
--  Correr después de financiera_nucleo.sql y financiera_seguridad.sql, con
--  la base de la financiera seleccionada. MySQL 8.0.13 o superior. Se puede
--  correr más de una vez: las tablas no se recrean; el catálogo de ARCA se
--  actualiza. En DBeaver: Ejecutar script (Alt+X).
--
--  EL MODELO (el de gestion_adm, talonarios_routes.py)
--  ---------------------------------------------------------------------
--    comprobantes_tipos           tipo contable: Factura, Recibo, Nota de
--                                 Crédito… y de qué lado de la cuenta del
--                                 cliente va (D: suma a lo que debe; C: lo
--                                 baja)
--      └─> talonarios_clasificaciones   agrupa talonarios de un tipo
--                                 («Recibos de cobranza», «Facturas de
--                                 intereses»)
--            └─> talonarios       una serie: letra, punto de venta, rango
--                                 de números y el último emitido
--
--    arca_tipos_comprobante       el nomenclador de ARCA (001 Factura A,
--                                 006 Factura B, 008 Nota de Crédito B…)
--                                 con su letra, su clase y si se pide CAE
--                                 por web service (WSFEv1)
--
--  TALONARIO DE ARCA (la regla de gestion_adm)
--  ---------------------------------------------------------------------
--  La marca es el «tipo ARCA»: un talonario con tipo pide CAE por WSFEv1 y
--  su numeración es la de ARCA para ese punto de venta y tipo (el último
--  emitido es el último autorizado). Por eso:
--    - tiene que ser nuevo: uno que ya numeró comprobantes propios no pasa a
--      ARCA (los números viejos chocarían con los de ARCA);
--    - uno solo por punto de venta, tipo y ambiente (índice único);
--    - la letra es la del tipo, y la clasificación va del mismo lado de la
--      cuenta que el tipo (nota de crédito y recibo al haber; factura y
--      nota de débito al debe);
--    - una vez usado no se le cambia el punto de venta, la letra, el tipo
--      ni el ambiente, ni se le saca el tipo.
--  La emisión con CAE (WSFEv1) todavía no está en financiera: esto deja los
--  talonarios listos para cuando esté.
--
--  NUMERACIÓN PROPIA
--  ---------------------------------------------------------------------
--  sp_talonario_numero(id, @n) reserva el próximo número de un talonario
--  propio con bloqueo, dentro de la transacción que emite el comprobante:
--  dos a la vez no sacan el mismo y, si la emisión se deshace, el número
--  vuelve. El último emitido nunca retrocede desde la pantalla.
--
--  Menú 55: «Talonarios», en Tablas y parámetros (Administrador y Gerencia).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Tipos de comprobante de ARCA (el nomenclador)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS arca_tipos_comprobante (
    codigo          CHAR(3)      NOT NULL COMMENT 'Código de ARCA con ceros: 001, 006, 201',
    descripcion     VARCHAR(80)  NOT NULL COMMENT 'Descripción oficial',
    letra           CHAR(1)      NULL     COMMENT 'Clase del comprobante: A, B, C, E, M (NULL si no lleva)',
    clase           ENUM('FACTURA','NOTA_DEBITO','NOTA_CREDITO','RECIBO','OTRO') NOT NULL DEFAULT 'OTRO',
    debito_credito  CHAR(1)      NOT NULL DEFAULT 'D'
                    COMMENT 'Emitido por la financiera: D suma a lo que debe el cliente (factura, nota de débito); C lo baja (nota de crédito, recibo)',
    wsfe            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1: se pide CAE por WSFEv1 (facturas, notas y recibos A, B y C)',
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (codigo),
    CONSTRAINT ck_atc_dc CHECK (debito_credito IN ('D', 'C'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tipos de comprobante del nomenclador de ARCA';

INSERT INTO arca_tipos_comprobante (codigo, descripcion, letra, clase, debito_credito, wsfe) VALUES
 ('001', 'Factura A',                                       'A', 'FACTURA',      'D', 1),
 ('002', 'Nota de Débito A',                                'A', 'NOTA_DEBITO',  'D', 1),
 ('003', 'Nota de Crédito A',                               'A', 'NOTA_CREDITO', 'C', 1),
 ('004', 'Recibo A',                                        'A', 'RECIBO',       'C', 1),
 ('005', 'Nota de Venta al Contado A',                      'A', 'OTRO',         'D', 0),
 ('006', 'Factura B',                                       'B', 'FACTURA',      'D', 1),
 ('007', 'Nota de Débito B',                                'B', 'NOTA_DEBITO',  'D', 1),
 ('008', 'Nota de Crédito B',                               'B', 'NOTA_CREDITO', 'C', 1),
 ('009', 'Recibo B',                                        'B', 'RECIBO',       'C', 1),
 ('010', 'Nota de Venta al Contado B',                      'B', 'OTRO',         'D', 0),
 ('011', 'Factura C',                                       'C', 'FACTURA',      'D', 1),
 ('012', 'Nota de Débito C',                                'C', 'NOTA_DEBITO',  'D', 1),
 ('013', 'Nota de Crédito C',                               'C', 'NOTA_CREDITO', 'C', 1),
 ('015', 'Recibo C',                                        'C', 'RECIBO',       'C', 1),
 ('019', 'Factura de Exportación',                          'E', 'FACTURA',      'D', 0),
 ('020', 'Nota de Débito por Operaciones con el Exterior',  'E', 'NOTA_DEBITO',  'D', 0),
 ('021', 'Nota de Crédito por Operaciones con el Exterior', 'E', 'NOTA_CREDITO', 'C', 0),
 ('027', 'Liquidación Primaria de Granos',                  NULL, 'OTRO',        'D', 0),
 ('028', 'Liquidación Secundaria de Granos',                NULL, 'OTRO',        'D', 0),
 ('051', 'Factura M',                                       'M', 'FACTURA',      'D', 0),
 ('052', 'Nota de Débito M',                                'M', 'NOTA_DEBITO',  'D', 0),
 ('053', 'Nota de Crédito M',                               'M', 'NOTA_CREDITO', 'C', 0),
 ('054', 'Recibo M',                                        'M', 'RECIBO',       'C', 0),
 ('060', 'Cta. de Vta. y Líquido Producto A',               'A', 'OTRO',         'D', 0),
 ('061', 'Cta. de Vta. y Líquido Producto B',               'B', 'OTRO',         'D', 0),
 ('063', 'Liquidación A',                                   'A', 'OTRO',         'D', 0),
 ('064', 'Liquidación B',                                   'B', 'OTRO',         'D', 0),
 ('201', 'Factura de Crédito Electrónica MiPyMEs A',        'A', 'FACTURA',      'D', 0),
 ('202', 'Nota de Débito Electrónica MiPyMEs A',            'A', 'NOTA_DEBITO',  'D', 0),
 ('203', 'Nota de Crédito Electrónica MiPyMEs A',           'A', 'NOTA_CREDITO', 'C', 0),
 ('206', 'Factura de Crédito Electrónica MiPyMEs B',        'B', 'FACTURA',      'D', 0),
 ('207', 'Nota de Débito Electrónica MiPyMEs B',            'B', 'NOTA_DEBITO',  'D', 0),
 ('208', 'Nota de Crédito Electrónica MiPyMEs B',           'B', 'NOTA_CREDITO', 'C', 0),
 ('211', 'Factura de Crédito Electrónica MiPyMEs C',        'C', 'FACTURA',      'D', 0),
 ('212', 'Nota de Débito Electrónica MiPyMEs C',            'C', 'NOTA_DEBITO',  'D', 0),
 ('213', 'Nota de Crédito Electrónica MiPyMEs C',           'C', 'NOTA_CREDITO', 'C', 0)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), letra = VALUES(letra), clase = VALUES(clase),
                        debito_credito = VALUES(debito_credito), wsfe = VALUES(wsfe);

-- ---------------------------------------------------------------------
-- 2. Tipos de comprobante contables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comprobantes_tipos (
    id_comprobante_tipo INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(50)  NOT NULL,
    debito_credito      CHAR(1)      NOT NULL COMMENT 'D: suma a lo que debe el cliente; C: lo baja',
    propio              TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1: lo emite la financiera',
    creado_el           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_comprobante_tipo),
    UNIQUE KEY uq_ct_nombre (nombre),
    CONSTRAINT ck_ct_dc CHECK (debito_credito IN ('D', 'C'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tipos de comprobante contables: de qué lado de la cuenta va cada uno';

INSERT IGNORE INTO comprobantes_tipos (nombre, debito_credito, propio) VALUES
 ('Factura',         'D', 1),
 ('Nota de Débito',  'D', 1),
 ('Nota de Crédito', 'C', 1),
 ('Recibo',          'C', 1),
 ('Orden de Pago',   'C', 1),
 ('Débito interno',  'D', 1),
 ('Crédito interno', 'C', 1),
 ('Liquidación',     'D', 1);

-- ---------------------------------------------------------------------
-- 3. Clasificaciones de talonarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS talonarios_clasificaciones (
    id_clasificacion    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    descripcion         VARCHAR(150) NOT NULL,
    id_comprobante_tipo INT UNSIGNED NOT NULL,
    creado_el           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por          INT UNSIGNED NULL,
    PRIMARY KEY (id_clasificacion),
    UNIQUE KEY uq_tc_descripcion (descripcion),
    KEY ix_tc_tipo (id_comprobante_tipo),
    CONSTRAINT fk_tc_tipo FOREIGN KEY (id_comprobante_tipo) REFERENCES comprobantes_tipos (id_comprobante_tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agrupa talonarios de un mismo tipo de comprobante';

-- ---------------------------------------------------------------------
-- 4. Talonarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS talonarios (
    id_talonario      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre            VARCHAR(50)   NOT NULL,
    id_clasificacion  INT UNSIGNED  NOT NULL,
    letra             CHAR(1)       NOT NULL,
    punto_venta       INT UNSIGNED  NOT NULL,
    numero_desde      INT UNSIGNED  NOT NULL,
    numero_hasta      INT UNSIGNED  NOT NULL,
    ultimo_emitido    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Por dónde va: 0 (o desde - 1) si no emitió ninguno. No retrocede',
    habilitado        TINYINT(1)    NOT NULL DEFAULT 1,
    comentario        VARCHAR(250)  NULL,
    codigo_arca       CHAR(3)       NULL COMMENT 'Tipo ARCA: con tipo, pide CAE por WSFEv1 y la numeración es la de ARCA',
    electronico       TINYINT(1)    AS (codigo_arca IS NOT NULL) STORED COMMENT '1: pide CAE a ARCA (sale del tipo ARCA)',
    arca_concepto     TINYINT       NULL COMMENT 'WSFEv1: 1 productos, 2 servicios, 3 ambos (vacío = 2)',
    arca_ambiente     ENUM('homo', 'prod') NULL COMMENT 'Ambiente de ARCA: homologación o producción',
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por        INT UNSIGNED  NULL,
    modificado_el     DATETIME      NULL,
    modificado_por    INT UNSIGNED  NULL,
    PRIMARY KEY (id_talonario),
    UNIQUE KEY uq_talonario (id_clasificacion, nombre, letra, punto_venta, numero_desde, numero_hasta),
    -- ARCA lleva una sola numeración por punto de venta, tipo y ambiente.
    UNIQUE KEY uq_talonario_arca ((IF(codigo_arca IS NULL, NULL,
                                      CONCAT(punto_venta, '-', codigo_arca, '-', COALESCE(arca_ambiente, ''))))),
    KEY ix_talonario_serie (punto_venta, letra),
    KEY ix_talonario_arca (codigo_arca),
    CONSTRAINT fk_tal_clasificacion FOREIGN KEY (id_clasificacion) REFERENCES talonarios_clasificaciones (id_clasificacion),
    CONSTRAINT fk_tal_arca          FOREIGN KEY (codigo_arca)      REFERENCES arca_tipos_comprobante (codigo),
    CONSTRAINT ck_tal_rango    CHECK (numero_desde >= 1 AND numero_hasta >= numero_desde),
    CONSTRAINT ck_tal_ultimo   CHECK (ultimo_emitido <= numero_hasta),
    CONSTRAINT ck_tal_letra    CHECK (letra IN ('A', 'B', 'C', 'E', 'M', 'X')),
    CONSTRAINT ck_tal_pv       CHECK (punto_venta <= 99999),
    CONSTRAINT ck_tal_concepto CHECK (arca_concepto IS NULL OR arca_concepto IN (1, 2, 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Series de numeración de comprobantes';

-- ---------------------------------------------------------------------
-- 5. El próximo número de un talonario propio
-- ---------------------------------------------------------------------
DELIMITER $$

-- Reserva el próximo número con bloqueo. Sin transacción propia: la llama
-- quien emite el comprobante, dentro de la suya (el número queda tomado
-- hasta su COMMIT; si se deshace, vuelve).
DROP PROCEDURE IF EXISTS sp_talonario_numero $$
CREATE PROCEDURE sp_talonario_numero(IN p_id_talonario INT UNSIGNED, OUT p_numero INT UNSIGNED)
BEGIN
    DECLARE v_nombre VARCHAR(50) DEFAULT NULL;
    DECLARE v_hab    TINYINT;
    DECLARE v_elec   TINYINT;
    DECLARE v_desde  INT UNSIGNED;
    DECLARE v_hasta  INT UNSIGNED;
    DECLARE v_ultimo INT UNSIGNED;
    DECLARE v_msg    VARCHAR(255);

    SELECT nombre, habilitado, electronico, numero_desde, numero_hasta, ultimo_emitido
      INTO v_nombre, v_hab, v_elec, v_desde, v_hasta, v_ultimo
      FROM talonarios WHERE id_talonario = p_id_talonario FOR UPDATE;
    IF v_nombre IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El talonario no existe';
    END IF;
    IF v_hab = 0 THEN
        SET v_msg = LEFT(CONCAT('El talonario «', v_nombre, '» está deshabilitado'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF v_elec = 1 THEN
        SET v_msg = LEFT(CONCAT('El talonario «', v_nombre, '» es de ARCA: el número lo da ARCA al autorizar el comprobante'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    SET p_numero = IF(v_ultimo >= v_desde, v_ultimo + 1, v_desde);
    IF p_numero > v_hasta THEN
        SET v_msg = LEFT(CONCAT('El talonario «', v_nombre, '» está agotado: creá el siguiente'), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    UPDATE talonarios SET ultimo_emitido = p_numero WHERE id_talonario = p_id_talonario;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 6. Menú: Tablas y parámetros › Talonarios (Administrador y Gerencia)
-- ---------------------------------------------------------------------
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (55, 50, 'Talonarios', 'talonarios.php', NULL, 4, 2, 1)
ON DUPLICATE KEY UPDATE padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url), orden = VALUES(orden), nivel = VALUES(nivel);
INSERT IGNORE INTO menu_roles (id_menu, id_rol) VALUES (55, 1), (55, 2);

SELECT m.id_menu, m.nombre, m.url, m.orden FROM menu m WHERE m.padre_id = 50 ORDER BY m.orden;

-- ---------------------------------------------------------------------
-- 7. Controles (tienen que dar cero filas)
-- ---------------------------------------------------------------------
-- 7.1 Talonarios de ARCA con otra letra que la de su tipo, o de un tipo que
--     no se pide por web service.
SELECT 'talonario ARCA que no cuadra' AS control, t.id_talonario, t.nombre, t.letra, a.codigo, a.letra AS letra_tipo, a.wsfe
  FROM talonarios t JOIN arca_tipos_comprobante a ON a.codigo = t.codigo_arca
 WHERE a.letra <> t.letra OR a.wsfe = 0;

-- 7.2 Talonarios de ARCA en una clasificación del otro lado de la cuenta.
SELECT 'talonario ARCA del otro lado' AS control, t.id_talonario, t.nombre, a.debito_credito AS tipo_arca, ct.debito_credito AS clasificacion
  FROM talonarios t
  JOIN arca_tipos_comprobante a ON a.codigo = t.codigo_arca
  JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
  JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
 WHERE a.debito_credito <> ct.debito_credito;

-- 7.3 Rangos que se pisan en el mismo punto de venta, letra y clasificación.
SELECT 'rangos que se pisan' AS control, a.id_talonario, a.nombre, b.id_talonario AS otro, b.nombre AS otro_nombre
  FROM talonarios a JOIN talonarios b
    ON b.id_talonario > a.id_talonario AND b.punto_venta = a.punto_venta AND b.letra = a.letra
   AND b.id_clasificacion = a.id_clasificacion
   AND b.numero_desde <= a.numero_hasta AND b.numero_hasta >= a.numero_desde;
