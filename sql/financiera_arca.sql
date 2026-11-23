-- =====================================================================
--  FINANCIERA - Consulta de la constancia de inscripción de ARCA
--  Correr con la base de la financiera seleccionada. MySQL 8.
--  Se puede correr más de una vez.
--
--  El botón «Consultar ARCA» de la ficha del cliente (como en gestion_adm)
--  usa ws_sr_constancia_inscripcion (A5, getPersona_v2). Lo último que ARCA
--  informó de cada CUIT queda guardado por CUIT —no por cliente—, así sirve
--  aunque el cliente todavía no esté dado de alta (inc/arca.php).
--
--    arca_constancia              lo último consultado de cada CUIT
--    arca_constancia_impuestos    impuestos registrados (con su estado)
--    arca_constancia_actividades  actividades (orden 1 = la principal)
--    arca_tokens                  el ticket de acceso de WSAA (dura 12 h);
--                                 en la base y no en un archivo para que lo
--                                 compartan IIS y las instancias de Railway
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS arca_constancia (
    cuit                  CHAR(11)     NOT NULL,
    tipo_persona          VARCHAR(30)  NULL COMMENT 'FISICA / JURIDICA',
    razon_social          VARCHAR(200) NULL,
    apellido              VARCHAR(100) NULL,
    nombre                VARCHAR(100) NULL,
    estado_clave          VARCHAR(30)  NULL COMMENT 'ACTIVO, INACTIVO…',
    condicion_iva         VARCHAR(4)   NULL COMMENT 'RI, MONO, EX, CF (los códigos de la ficha del cliente)',
    condicion_iva_txt     VARCHAR(60)  NULL,
    categoria_monotributo VARCHAR(100) NULL,
    dom_direccion         VARCHAR(200) NULL COMMENT 'domicilio fiscal, como lo da ARCA',
    dom_localidad         VARCHAR(100) NULL,
    dom_provincia         VARCHAR(60)  NULL,
    dom_cp                VARCHAR(10)  NULL,
    errores               VARCHAR(500) NULL COMMENT 'errorConstancia de ARCA, si informó alguno',
    consultado_el         DATETIME     NOT NULL,
    consultado_por        INT UNSIGNED NULL,
    PRIMARY KEY (cuit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Última constancia de inscripción de ARCA consultada por CUIT';

CREATE TABLE IF NOT EXISTS arca_constancia_impuestos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuit          CHAR(11)     NOT NULL,
    id_impuesto   INT          NULL,
    descripcion   VARCHAR(200) NULL,
    estado        VARCHAR(10)  NULL COMMENT 'estadoImpuesto de ARCA (AC = activo)',
    periodo_desde VARCHAR(8)   NULL COMMENT 'aaaamm',
    PRIMARY KEY (id),
    KEY ix_arca_imp_cuit (cuit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS arca_constancia_actividades (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuit          CHAR(11)     NOT NULL,
    id_actividad  BIGINT       NULL,
    descripcion   VARCHAR(300) NULL,
    nomenclador   INT          NULL,
    orden         INT          NULL COMMENT '1 = actividad principal',
    periodo_desde VARCHAR(8)   NULL COMMENT 'aaaamm',
    PRIMARY KEY (id),
    KEY ix_arca_act_cuit (cuit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS arca_tokens (
    servicio  VARCHAR(60)  NOT NULL,
    token     TEXT         NOT NULL,
    sign      VARCHAR(500) NOT NULL,
    expira    DATETIME     NOT NULL COMMENT 'hora de Argentina',
    PRIMARY KEY (servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Ticket de acceso de WSAA por servicio';
