-- =====================================================================
--  FINANCIERA - Ficha del cliente: datos que traía Reggio + observaciones
--  Correr con la base de la financiera seleccionada. MySQL 8.
--  Se puede correr más de una vez.
--
--  clientes
--    fecha_alta        desde cuándo es cliente (en Reggio, «Fecha de inicio»).
--                      Distinta de creado_el, que es cuándo se cargó acá.
--    banco_sucursal    sucursal y número de cuenta del banco donde cobra: los
--    cuenta_bancaria   usan los archivos de débito (Nación, entre otros)
--    otro_banco        cobra en un banco distinto del habitual del tipo
--    venta_cartera     su cartera se cedió/vendió
--
--  clientes_observaciones
--    Las observaciones y el seguimiento del cliente, una por renglón, con
--    quién y cuándo la cargó. No se editan ni se borran: si algo cambió se
--    agrega otra. Opcional: motivo (seguimiento_motivos, los de Reggio),
--    préstamo al que se refiere y fecha de recordatorio; el recordatorio se
--    marca como hecho (quién y cuándo).
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS tmp_ficha_add_col;
DELIMITER $$
CREATE PROCEDURE tmp_ficha_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE ', t, ' ADD COLUMN ', c, ' ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;
CALL tmp_ficha_add_col('clientes', 'fecha_alta',      "DATE NULL COMMENT 'Desde cuándo es cliente (Reggio: fecha de inicio)'");
CALL tmp_ficha_add_col('clientes', 'banco_sucursal',  "VARCHAR(10) NULL COMMENT 'Sucursal del banco donde cobra'");
CALL tmp_ficha_add_col('clientes', 'cuenta_bancaria', "VARCHAR(20) NULL COMMENT 'Número de cuenta (archivos de débito)'");
CALL tmp_ficha_add_col('clientes', 'otro_banco',      "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cobra en otro banco'");
CALL tmp_ficha_add_col('clientes', 'venta_cartera',   "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cartera cedida/vendida'");
DROP PROCEDURE tmp_ficha_add_col;

-- Los clientes cargados antes: la fecha de alta es la de carga.
UPDATE clientes SET fecha_alta = DATE(creado_el) WHERE fecha_alta IS NULL;

-- Los motivos de seguimiento (si no corrió la migración de Reggio).
CREATE TABLE IF NOT EXISTS seguimiento_motivos (
  id_motivo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  origen_id INT UNSIGNED NULL
) COMMENT='Motivos de seguimiento de clientes';
INSERT IGNORE INTO seguimiento_motivos (nombre) VALUES
  ('Alta de prestamo'), ('Modificación de cliente'), ('Moroso'), ('Otro'), ('Promesa pago'),
  ('Refinanciacion'), ('Rellamado'), ('Renovacion');

CREATE TABLE IF NOT EXISTS clientes_observaciones (
    id_observacion   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente       INT UNSIGNED NOT NULL,
    id_prestamo      INT UNSIGNED NULL,
    id_motivo        INT UNSIGNED NULL,
    texto            TEXT         NOT NULL,
    recordatorio     DATE         NULL COMMENT 'Volver a ver el caso ese día',
    hecho_el         DATETIME     NULL COMMENT 'Recordatorio atendido',
    hecho_por        INT UNSIGNED NULL,
    creado_el        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_observacion),
    KEY ix_obs_cliente (id_cliente, creado_el),
    KEY ix_obs_recordatorio (recordatorio, hecho_el),
    CONSTRAINT fk_obs_cliente  FOREIGN KEY (id_cliente)  REFERENCES clientes (id_cliente),
    CONSTRAINT fk_obs_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_obs_motivo   FOREIGN KEY (id_motivo)   REFERENCES seguimiento_motivos (id_motivo),
    CONSTRAINT fk_obs_usuario  FOREIGN KEY (creado_por)  REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_obs_hecho    FOREIGN KEY (hecho_por)   REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Observaciones y seguimiento del cliente: quién y cuándo';

-- ---------------------------------------------------------------------
-- Contactos (modelo s_contactos de gestion_adm)
--   Una tabla para los contactos de clientes y comercializadores
--   (entidad_tipo + entidad_id), como en gestion_adm para afiliados,
--   empresas y proveedores. En un cliente persona son los referidos de
--   Reggio (familiar, amigo…); en una empresa, a quién llamar por pagos.
--   - Se editan; la baja es lógica (activo = 0).
--   - principal es excluyente por entidad (marcar uno apaga los otros).
--   - El tipo es un catálogo (contactos_tipos), no texto libre: «Familiar»
--     y «familiar » no tienen que ser dos cosas distintas.
--   - Los teléfonos se guardan normalizados (tel_normalizar), igual que los
--     del cliente.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contactos_tipos (
    id_tipo_contacto SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre           VARCHAR(60)  NOT NULL,
    descripcion      VARCHAR(200) NULL,
    orden            SMALLINT     NOT NULL DEFAULT 0,
    activo           TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_tipo_contacto),
    UNIQUE KEY uq_contactos_tipos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Tipos de contacto (vínculo o área)';
INSERT IGNORE INTO contactos_tipos (nombre, descripcion, orden) VALUES
  ('Familiar',       'Referido de la familia del cliente',            10),
  ('Amigo/a',        'Referido conocido del cliente',                 20),
  ('Vecino/a',       'Referido del barrio',                           30),
  ('Laboral',        'Compañero de trabajo, empleador, RR.HH.',       40),
  ('Garante',        'Garante o codeudor',                            50),
  ('Administración', 'De una empresa: quien atiende pagos y cobros',  60),
  ('Otro',           NULL,                                            90);

CREATE TABLE IF NOT EXISTS contactos (
    id_contacto      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entidad_tipo     VARCHAR(20)  NOT NULL COMMENT 'cliente | comercializador',
    entidad_id       INT UNSIGNED NOT NULL,
    nombre           VARCHAR(150) NOT NULL,
    id_tipo_contacto SMALLINT UNSIGNED NULL,
    vinculo          VARCHAR(100) NULL COMMENT 'Relación o cargo: hija, encargada de pagos…',
    telefono         VARCHAR(20)  NULL,
    celular          VARCHAR(20)  NULL,
    email            VARCHAR(150) NULL,
    observaciones    VARCHAR(300) NULL,
    principal        TINYINT(1)   NOT NULL DEFAULT 0,
    activo           TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED NULL,
    actualizado_el   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por  INT UNSIGNED NULL,
    PRIMARY KEY (id_contacto),
    KEY ix_contactos_entidad (entidad_tipo, entidad_id, activo),
    CONSTRAINT fk_contactos_tipo  FOREIGN KEY (id_tipo_contacto) REFERENCES contactos_tipos (id_tipo_contacto),
    CONSTRAINT fk_contactos_crea  FOREIGN KEY (creado_por)       REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_contactos_actu  FOREIGN KEY (actualizado_por)  REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Contactos de clientes y comercializadores (como s_contactos de gestion_adm)';

-- ---------------------------------------------------------------------
-- Banco del cliente (catálogo bancos, código BCRA = 3 primeros del CBU)
-- y la opción Tesorería › Bancos (la solapa de bancos de Cuentas de fondos,
-- con «Traer del BCRA» para completar el listado de entidades).
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS tmp_ficha_banco;
DELIMITER $$
CREATE PROCEDURE tmp_ficha_banco()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'id_banco') THEN
    ALTER TABLE clientes ADD COLUMN id_banco INT UNSIGNED NULL COMMENT 'Banco donde cobra (código BCRA)' AFTER alias_cbu,
      ADD CONSTRAINT fk_clientes_banco FOREIGN KEY (id_banco) REFERENCES bancos (id_banco);
  END IF;
END$$
DELIMITER ;
CALL tmp_ficha_banco();
DROP PROCEDURE tmp_ficha_banco;
UPDATE clientes c JOIN bancos b ON b.codigo = LEFT(c.cbu, 3) SET c.id_banco = b.id_banco WHERE c.id_banco IS NULL AND LENGTH(c.cbu) = 22;

INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
SELECT 88, 80, 'Bancos', 'bancos.php', 'banco', 8, 2, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu WHERE id_menu = 88 OR url = 'bancos.php');
INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT 88, id_rol FROM menu_roles WHERE id_menu = 84;

-- ---------------------------------------------------------------------
-- Los teléfonos pasan a Contactos (la tabla telefonos queda sin uso).
--   Los del cliente: tipo «Titular» (el propio cliente); los de
--   referencia: «Otro», con el nombre que tenían en la observación.
--   El principal sigue siendo el principal.
-- ---------------------------------------------------------------------
INSERT INTO contactos_tipos (nombre, descripcion, orden)
VALUES ('Titular', 'El propio cliente o comercializador: sus teléfonos', 0)
ON DUPLICATE KEY UPDATE orden = 0;

INSERT INTO contactos (entidad_tipo, entidad_id, nombre, id_tipo_contacto, vinculo, telefono, celular, observaciones, principal, creado_el)
SELECT 'cliente', t.id_cliente,
       IF(t.tipo = 'REFERENCIA', COALESCE(NULLIF(t.observacion, ''), 'Referido'), c.denominacion),
       (SELECT id_tipo_contacto FROM contactos_tipos WHERE nombre = IF(t.tipo = 'REFERENCIA', 'Otro', 'Titular')),
       IF(t.tipo = 'LABORAL', 'Laboral', NULL),
       IF(t.numero LIKE '549%', NULL, t.numero),
       IF(t.numero LIKE '549%', t.numero, NULL),
       IF(t.tipo = 'REFERENCIA', NULL, NULLIF(t.observacion, '')),
       t.principal, c.creado_el
  FROM telefonos t JOIN clientes c ON c.id_cliente = t.id_cliente
 WHERE NOT EXISTS (SELECT 1 FROM contactos x WHERE x.entidad_tipo = 'cliente' AND x.entidad_id = t.id_cliente
                     AND (x.celular = t.numero OR x.telefono = t.numero));
