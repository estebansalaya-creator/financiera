-- =====================================================================
--  FINANCIERA - Documentación de clientes
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_nucleo.sql. Se puede correr más de una vez.
--
--  Contenido
--    documentos_tipos ........ catálogo: qué se pide, a quién, si es
--                              obligatorio y cuánto dura
--    clientes_documentos ..... cada archivo subido (el archivo queda en
--                              disco, acá solo los datos)
--    v_clientes_doc_checklist  por cliente y documento obligatorio:
--                              OK / VENCIDO / FALTA
--    v_clientes_doc_resumen .. por cliente: requeridos, ok, vencidos, faltan
--
--  Criterios
--    - Los archivos NO van a la base: quedan en la carpeta de documentos
--      (config.php -> docs_dir, por defecto D:\financiera\documentos),
--      fuera de la carpeta pública, con nombre al azar. La app los sirve
--      por documento.php, nunca por URL directa.
--    - No se borran: se dan de baja (baja_el / baja_motivo). Para una
--      financiera la documentación presentada es respaldo del legajo.
--    - sha256 para no cargar dos veces el mismo archivo al mismo cliente.
--    - vigencia_dias: si al subir no se indica vencimiento, se calcula
--      desde la fecha de emisión (o la de carga). Ej.: recibo de sueldo 60.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS documentos_tipos (
    id_tipo_doc     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(30)  NOT NULL,
    nombre          VARCHAR(100) NOT NULL,
    destinatario    ENUM('F','J','AMBOS') NOT NULL DEFAULT 'AMBOS',
    obligatorio     TINYINT(1)   NOT NULL DEFAULT 0,
    vigencia_dias   SMALLINT UNSIGNED NULL,          -- NULL = no vence
    orden           SMALLINT     NOT NULL DEFAULT 0,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_tipo_doc),
    UNIQUE KEY uq_doctipos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes_documentos (
    id_documento      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente        INT UNSIGNED NOT NULL,
    id_tipo_doc       SMALLINT UNSIGNED NOT NULL,
    id_prestamo       INT UNSIGNED NULL,              -- si es de una operación (pagaré, solicitud firmada)
    nombre_original   VARCHAR(200) NOT NULL,          -- como lo subieron
    archivo           VARCHAR(255) NOT NULL,          -- ruta relativa a docs_dir: clientes/12/ab12….pdf
    mime              VARCHAR(60)  NOT NULL,
    bytes             INT UNSIGNED NOT NULL,
    sha256            CHAR(64)     NOT NULL,
    fecha_emision     DATE         NULL,
    fecha_vencimiento DATE         NULL,
    observacion       VARCHAR(200) NULL,
    subido_el         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subido_por        INT UNSIGNED NULL,
    baja_el           DATETIME     NULL,
    baja_por          INT UNSIGNED NULL,
    baja_motivo       VARCHAR(200) NULL,
    PRIMARY KEY (id_documento),
    UNIQUE KEY uq_clidoc_archivo (archivo),
    KEY ix_clidoc_cliente_tipo (id_cliente, id_tipo_doc, baja_el),
    KEY ix_clidoc_sha (id_cliente, sha256),
    KEY ix_clidoc_prestamo (id_prestamo),
    KEY ix_clidoc_vto (fecha_vencimiento),
    CONSTRAINT fk_clidoc_cliente  FOREIGN KEY (id_cliente)  REFERENCES clientes (id_cliente),
    CONSTRAINT fk_clidoc_tipo     FOREIGN KEY (id_tipo_doc) REFERENCES documentos_tipos (id_tipo_doc),
    CONSTRAINT fk_clidoc_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_clidoc_subido   FOREIGN KEY (subido_por)  REFERENCES usuarios (id_usuario),
    CONSTRAINT fk_clidoc_baja     FOREIGN KEY (baja_por)    REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Por cliente y documento obligatorio que le corresponde (según sea
-- persona o empresa), el estado de lo cargado:
--   OK       hay al menos uno activo sin vencer
--   VENCIDO  hay activos, pero todos vencidos
--   FALTA    no hay ninguno activo
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_clientes_doc_checklist AS
SELECT c.id_cliente,
       t.id_tipo_doc, t.codigo, t.nombre, t.orden,
       COUNT(d.id_documento) AS cargados,
       SUM(d.id_documento IS NOT NULL
           AND (d.fecha_vencimiento IS NULL OR d.fecha_vencimiento >= CURDATE())) AS vigentes,
       MAX(d.fecha_vencimiento) AS ultimo_vencimiento,
       CASE WHEN SUM(d.id_documento IS NOT NULL
                     AND (d.fecha_vencimiento IS NULL OR d.fecha_vencimiento >= CURDATE())) > 0 THEN 'OK'
            WHEN COUNT(d.id_documento) > 0 THEN 'VENCIDO'
            ELSE 'FALTA' END AS estado
  FROM clientes c
  JOIN documentos_tipos t
    ON t.activo = 1 AND t.obligatorio = 1 AND t.destinatario IN (c.tipo, 'AMBOS')
  LEFT JOIN clientes_documentos d
    ON d.id_cliente = c.id_cliente AND d.id_tipo_doc = t.id_tipo_doc AND d.baja_el IS NULL
 GROUP BY c.id_cliente, t.id_tipo_doc, t.codigo, t.nombre, t.orden;

CREATE OR REPLACE VIEW v_clientes_doc_resumen AS
SELECT id_cliente,
       COUNT(*)                  AS requeridos,
       SUM(estado = 'OK')        AS ok,
       SUM(estado = 'VENCIDO')   AS vencidos,
       SUM(estado = 'FALTA')     AS faltan
  FROM v_clientes_doc_checklist
 GROUP BY id_cliente;

-- ---------------------------------------------------------------------
-- Catálogo inicial. Ajustar obligatorio / vigencia_dias a la política de
-- la financiera: INSERT IGNORE no pisa lo que ya se haya cambiado.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO documentos_tipos (codigo, nombre, destinatario, obligatorio, vigencia_dias, orden) VALUES
 ('DNI_FRENTE',     'DNI (frente)',                                  'F',     1, NULL, 10),
 ('DNI_DORSO',      'DNI (dorso)',                                   'F',     1, NULL, 20),
 ('RECIBO',         'Recibo de sueldo / haberes',                    'F',     1,   60, 30),
 ('SERVICIO',       'Servicio a su nombre (acredita domicilio)',     'AMBOS', 1,   90, 40),
 ('CONST_CUIL',     'Constancia de CUIL',                            'F',     0, NULL, 50),
 ('CONST_ARCA',     'Constancia de inscripción en ARCA',             'J',     1, NULL, 60),
 ('ESTATUTO',       'Estatuto / contrato social',                    'J',     1, NULL, 70),
 ('ACTA_AUT',       'Acta de designación de autoridades',            'J',     1, NULL, 80),
 ('DNI_FIRMANTE',   'DNI del firmante (frente y dorso)',             'J',     1, NULL, 90),
 ('PODER',          'Poder del firmante',                            'J',     0, NULL, 100),
 ('BALANCE',        'Último balance certificado',                    'J',     1,  540, 110),
 ('DDJJ_IMPUESTOS', 'Declaraciones juradas (IVA / IIBB)',            'J',     0,  120, 120),
 ('CBU',            'Constancia de CBU',                             'AMBOS', 1, NULL, 130),
 ('DDJJ_PEP',       'DDJJ de persona expuesta políticamente (UIF)',  'AMBOS', 1, NULL, 140),
 ('INFORME_BCRA',   'Informe Central de Deudores (BCRA)',            'AMBOS', 0, NULL, 145),
 ('SOLICITUD',      'Solicitud de crédito firmada',                  'AMBOS', 0, NULL, 150),
 ('PAGARE',         'Pagaré',                                        'AMBOS', 0, NULL, 160),
 ('OTRO',           'Otro',                                          'AMBOS', 0, NULL, 999);
