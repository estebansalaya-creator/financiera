-- =====================================================================
--  FINANCIERA - Cuota social (la «cuota gastos» de Reggio)
--  Correr con la base de la financiera seleccionada. MySQL 8.
--  Se puede correr más de una vez.
--
--  Cómo funciona (igual que Reggio):
--    - Un cliente pasa a ser ASOCIADO con su primer préstamo (o a mano) y
--      genera cuota social todos los meses, aunque el préstamo termine,
--      hasta que se le da la BAJA de cuota social.
--    - Solo si su tipo de asociado paga cuota social
--      (tipos_asociado.paga_cuota_social).
--    - El valor es del tipo de asociado, con vigencias: los primeros
--      meses_inicial meses se cobra valor_inicial; después valor_mensual y,
--      si el tipo lo tiene, además valor_adicional (dos cargos en el mes).
--    - Cada cuota queda con el valor del mes en que se generó.
--
--    cuota_social_valores    valores por tipo de asociado y vigencia
--    cuota_social_asociados  alta (desde) y baja de cada asociado
--    cuotas_sociales         un renglón por asociado, período y concepto
--    cuotas_sociales_pagos   lo pagado (débito, efectivo…), con reversiones
--                            y reintegros; anular no borra
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cuota_social_valores (
    id_valor          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    id_tipo_asociado  INT UNSIGNED      NOT NULL,
    vigencia_desde    DATE              NOT NULL COMMENT 'Primer período (día 1) en que rige',
    valor_inicial     DECIMAL(15,2)     NULL     COMMENT 'Los primeros meses (Reggio: «Inicial»)',
    meses_inicial     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    valor_mensual     DECIMAL(15,2)     NOT NULL,
    valor_adicional   DECIMAL(15,2)     NULL     COMMENT 'Segundo cargo del mes, después de los iniciales',
    creado_por        INT UNSIGNED      NULL,
    creado_el         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_valor),
    UNIQUE KEY uq_csv_tipo_vigencia (id_tipo_asociado, vigencia_desde),
    CONSTRAINT fk_csv_tipo FOREIGN KEY (id_tipo_asociado) REFERENCES tipos_asociado (id_tipo_asociado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Valores de la cuota social por tipo de asociado';

CREATE TABLE IF NOT EXISTS cuota_social_asociados (
    id_cliente   INT UNSIGNED NOT NULL,
    desde        DATE         NOT NULL COMMENT 'Primer período que paga (día 1)',
    origen       ENUM('PRESTAMO','MANUAL','MIGRACION') NOT NULL DEFAULT 'PRESTAMO',
    baja_desde   DATE         NULL     COMMENT 'Desde este período ya no genera',
    baja_motivo  VARCHAR(200) NULL,
    baja_por     INT UNSIGNED NULL,
    baja_el      DATETIME     NULL,
    creado_por   INT UNSIGNED NULL,
    creado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_cliente),
    CONSTRAINT fk_csa_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Asociados que pagan cuota social: alta y baja';

CREATE TABLE IF NOT EXISTS cuotas_sociales (
    id_cuota_social   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cliente        INT UNSIGNED  NOT NULL,
    periodo           DATE          NOT NULL COMMENT 'Día 1 del mes',
    concepto          ENUM('INICIAL','MENSUAL','ADICIONAL','MANUAL') NOT NULL,
    id_tipo_asociado  INT UNSIGNED  NULL     COMMENT 'El del cliente al generarla',
    importe           DECIMAL(15,2) NOT NULL,
    pagado            DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Suma de sus pagos vigentes',
    estado            ENUM('PENDIENTE','PARCIAL','PAGADA','ANULADA') NOT NULL DEFAULT 'PENDIENTE',
    generado_el       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generado_por      INT UNSIGNED  NULL,
    anulada_el        DATETIME      NULL,
    anulada_por       INT UNSIGNED  NULL,
    motivo_anulacion  VARCHAR(200)  NULL,
    PRIMARY KEY (id_cuota_social),
    UNIQUE KEY uq_cs_cliente_periodo_concepto (id_cliente, periodo, concepto),
    KEY ix_cs_periodo (periodo, estado),
    CONSTRAINT fk_cs_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente),
    CONSTRAINT fk_cs_tipo    FOREIGN KEY (id_tipo_asociado) REFERENCES tipos_asociado (id_tipo_asociado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Cuotas sociales generadas, una por asociado, período y concepto';

CREATE TABLE IF NOT EXISTS cuotas_sociales_pagos (
    id_pago_cs        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cuota_social   INT UNSIGNED  NOT NULL,
    fecha             DATE          NOT NULL,
    importe           DECIMAL(15,2) NOT NULL COMMENT 'Negativo en una reversión o un reintegro',
    motivo            ENUM('PAGO','REVERSION','REINTEGRO') NOT NULL DEFAULT 'PAGO',
    medio             VARCHAR(20)   NOT NULL DEFAULT 'DEBITO',
    referencia        VARCHAR(100)  NULL,
    id_lote           INT UNSIGNED  NULL,
    creado_por        INT UNSIGNED  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_el        DATETIME      NULL,
    anulado_por       INT UNSIGNED  NULL,
    motivo_anulacion  VARCHAR(200)  NULL,
    PRIMARY KEY (id_pago_cs),
    KEY ix_csp_cuota (id_cuota_social),
    KEY ix_csp_fecha (fecha),
    CONSTRAINT fk_csp_cuota FOREIGN KEY (id_cuota_social) REFERENCES cuotas_sociales (id_cuota_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Pagos de cuota social';

-- Menú: Cobranzas › Cuota social (mismos roles que Cobros en efectivo).
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
SELECT 25, 20, 'Cuota social', 'cuota_social.php', 'grupo', 5, 2, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu WHERE id_menu = 25 OR url = 'cuota_social.php');
INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT 25, id_rol FROM menu_roles WHERE id_menu = 21;

-- Valores iniciales, tomados de los parámetros de Reggio (nov-2026). Revisarlos
-- en Tipos de asociado › Cuota social. Los tipos sin valor no generan.
--   Nación, Cofincred y Staff: Inicial 13.800 los primeros 12 meses; después
--                              14.120 + adicional 14.180
--   Neuquén 1.345 · Patagonia y Supervielle 795
INSERT IGNORE INTO cuota_social_valores (id_tipo_asociado, vigencia_desde, valor_inicial, meses_inicial, valor_mensual, valor_adicional)
SELECT id_tipo_asociado, '2026-11-01',
       CASE WHEN nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%' THEN 13800 END,
       CASE WHEN nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%' THEN 12 ELSE 0 END,
       CASE WHEN nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%' THEN 14120
            WHEN nombre LIKE 'NEUQUEN%' THEN 1345
            WHEN nombre LIKE 'PATAGONIA%' OR nombre LIKE 'SUPERVIELLE%' THEN 795 END,
       CASE WHEN nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%' THEN 14180 END
  FROM tipos_asociado
 WHERE paga_cuota_social = 1
   AND (nombre LIKE 'NACION%' OR nombre LIKE 'COFINCRED%' OR nombre LIKE '%STAFF%'
        OR nombre LIKE 'NEUQUEN%' OR nombre LIKE 'PATAGONIA%' OR nombre LIKE 'SUPERVIELLE%');
