-- =====================================================================
--  FINANCIERA - Facturación electrónica de las cobranzas (WSFEv1 de ARCA)
--  Requiere financiera_recibos.sql y financiera_talonarios.sql.
--  Se puede correr más de una vez. No tiene DELIMITER: va por DBeaver o CMD.
--
--  Criterio (a confirmar con el contador, preguntas_cliente.md):
--    - Una factura por recibo, con lo que el recibo aplicó a los préstamos
--      según su imputación real: capital = no gravado; interés y
--      punitorios = neto gravado, a la alícuota del préstamo; su IVA.
--      NO la fórmula de Reggio (que da intereses e IVA negativos).
--    - La cuota social no se factura (hasta que se defina si va y cómo).
--    - Receptor: consumidor final, exento o sin condición -> B con CUIL/CUIT;
--      responsable inscripto o monotributista -> A con CUIT (RG 5003).
--    - Recibo anulado: si la factura no tenía CAE se anula; si lo tenía,
--      se emite una nota de crédito por el total.
--    - El número se asigna al autorizar (ARCA no acepta saltos): mientras
--      espera CAE el comprobante tiene número 0.
--    - Estados: PENDIENTE (espera CAE), ENVIANDO (salió a ARCA, sin
--      respuesta grabada), AUTORIZADO, RECHAZADO (el motivo en arca_obs; no
--      vuelve a salir hasta Reintentar), ANULADO (se anuló antes del CAE).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS comprobantes_venta (
    id_comprobante          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_talonario            INT UNSIGNED  NOT NULL,
    cbte_tipo               SMALLINT UNSIGNED NOT NULL COMMENT 'Código de ARCA: 1 FA, 6 FB, 3 NCA, 8 NCB…',
    letra                   CHAR(1)       NOT NULL,
    punto_venta             SMALLINT UNSIGNED NOT NULL,
    numero                  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '0 hasta que ARCA lo autoriza',
    numero_unico            INT UNSIGNED  AS (NULLIF(numero, 0)) STORED,
    fecha                   DATE          NOT NULL COMMENT 'La del recibo; al autorizar, la del CAE',
    id_cliente              INT UNSIGNED  NOT NULL,
    id_recibo               INT UNSIGNED  NULL     COMMENT 'El recibo que factura (o cuya anulación acredita la NC)',
    id_asociado             INT UNSIGNED  NULL     COMMENT 'La factura que ajusta una nota de crédito',
    doc_tipo                SMALLINT UNSIGNED NOT NULL COMMENT '80 CUIT, 86 CUIL, 96 DNI',
    doc_nro                 BIGINT UNSIGNED NOT NULL,
    cond_iva_receptor       SMALLINT UNSIGNED NOT NULL COMMENT '1 RI, 4 exento, 5 consumidor final, 6 monotributo',
    importe_no_gravado      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Capital',
    importe_neto            DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Interés y punitorios',
    importe_iva             DECIMAL(15,2) NOT NULL DEFAULT 0,
    importe_exento          DECIMAL(15,2) NOT NULL DEFAULT 0,
    importe_total           DECIMAL(15,2) NOT NULL,
    servicio_desde          DATE          NULL,
    servicio_hasta          DATE          NULL,
    arca_estado             ENUM('PENDIENTE','ENVIANDO','AUTORIZADO','RECHAZADO','ANULADO') NOT NULL DEFAULT 'PENDIENTE',
    arca_ambiente           ENUM('homo','prod') NOT NULL,
    cae                     VARCHAR(20)   NULL,
    cae_vto                 DATE          NULL,
    arca_obs                VARCHAR(1000) NULL,
    arca_enviado_el         DATETIME      NULL,
    arca_autorizado_el      DATETIME      NULL,
    creado_por              INT UNSIGNED  NULL,
    creado_el               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_por             INT UNSIGNED  NULL,
    anulado_el              DATETIME      NULL,
    PRIMARY KEY (id_comprobante),
    UNIQUE KEY uq_cv_numero (id_talonario, numero_unico),
    KEY ix_cv_estado (arca_estado, id_talonario),
    KEY ix_cv_recibo (id_recibo),
    KEY ix_cv_cliente (id_cliente, fecha),
    KEY ix_cv_fecha (fecha),
    CONSTRAINT fk_cv_talonario FOREIGN KEY (id_talonario) REFERENCES talonarios (id_talonario),
    CONSTRAINT fk_cv_cliente   FOREIGN KEY (id_cliente)   REFERENCES clientes (id_cliente),
    CONSTRAINT fk_cv_recibo    FOREIGN KEY (id_recibo)    REFERENCES recibos (id_recibo),
    CONSTRAINT fk_cv_asociado  FOREIGN KEY (id_asociado)  REFERENCES comprobantes_venta (id_comprobante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Facturas y notas de crédito electrónicas de las cobranzas';

-- Lo que factura: un renglón por préstamo del recibo, con su alícuota.
CREATE TABLE IF NOT EXISTS comprobantes_venta_items (
    id_item         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_comprobante  INT UNSIGNED  NOT NULL,
    descripcion     VARCHAR(200)  NOT NULL,
    id_prestamo     INT UNSIGNED  NULL,
    id_pago         INT UNSIGNED  NULL,
    alicuota_iva    DECIMAL(5,2)  NOT NULL DEFAULT 0,
    no_gravado      DECIMAL(15,2) NOT NULL DEFAULT 0,
    neto            DECIMAL(15,2) NOT NULL DEFAULT 0,
    iva             DECIMAL(15,2) NOT NULL DEFAULT 0,
    total           DECIMAL(15,2) NOT NULL,
    PRIMARY KEY (id_item),
    KEY ix_cvi_comprobante (id_comprobante),
    CONSTRAINT fk_cvi_comprobante FOREIGN KEY (id_comprobante) REFERENCES comprobantes_venta (id_comprobante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Renglones de los comprobantes de venta';

-- Cada llamada a WSFEv1 (sin Token ni Sign), para revisar qué se mandó.
CREATE TABLE IF NOT EXISTS arca_wsfe_log (
    id_log      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ambiente    ENUM('homo','prod') NOT NULL,
    metodo      VARCHAR(40)  NOT NULL,
    pedido      MEDIUMTEXT   NULL,
    respuesta   MEDIUMTEXT   NULL,
    error       VARCHAR(1000) NULL,
    id_usuario  INT UNSIGNED NULL,
    creado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_log),
    KEY ix_awl_fecha (creado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Llamadas a la factura electrónica de ARCA';

-- La actividad de la financiera en ARCA (FEParamGetActividades), si hay que informarla.
SET @x := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'empresa' AND column_name = 'arca_actividad');
SET @s := IF(@x = 0, 'ALTER TABLE empresa ADD COLUMN arca_actividad INT UNSIGNED NULL COMMENT ''Código de actividad de ARCA para las facturas''', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Menú: Cobranzas › Facturación electrónica (mismos roles que Recibos emitidos).
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
SELECT 26, 20, 'Facturación electrónica', 'facturacion.php', 'documento', 6, 2, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu WHERE id_menu = 26 OR url = 'facturacion.php');
INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT 26, id_rol FROM menu_roles WHERE id_menu = 22;
