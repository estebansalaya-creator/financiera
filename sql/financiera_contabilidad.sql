-- =====================================================================
--  FINANCIERA - Contabilidad: plan de cuentas, ejercicios, períodos y
--  asientos, con la estructura de gestion_adm
--
--  Correr DESPUÉS de financiera_nucleo.sql y financiera_seguridad.sql, con
--  la base de la financiera seleccionada. MySQL 8 (probado también en
--  MariaDB 10.11). Se puede correr más de una vez: las tablas no se
--  recrean y el menú no se duplica.
--
--  EL MODELO (copiado de gestion_adm, base «contable»)
--  ---------------------------------------------------
--    c_clasificacion_plan_cuentas   el primer dígito del código: 1 ACTIVO,
--                                   2 PASIVO, 3 PATRIMONIO NETO, 4 INGRESOS,
--                                   5 EGRESOS (naturaleza y tipo)
--    c_plan_de_cuentas              las cuentas: código con la máscara
--                                   (CONTABLE_MASCARA en parametros), padre,
--                                   nivel, imputable, corriente, monetaria
--    c_ejercicios / c_periodos      el ejercicio y sus meses (abierto,
--                                   cerrado, bloqueado)
--    c_asientos / c_asiento_detalle el libro diario
--    c_asientos_origen              LO QUE A GESTION_ADM LE FALTA: ata cada
--                                   asiento a la operación que lo generó
--                                   (desembolso, cobranza, aporte…). Con eso
--                                   el generador puede volver a correr sin
--                                   duplicar nada, y una anulación sabe qué
--                                   asiento revertir.
--    c_libro_mayor (vista)          los renglones de los asientos confirmados
--
--  Las mismas reglas de gestion_adm (contable.py):
--    - el código respeta la máscara ('9' = dígito, lo demás separador);
--    - una cuenta es imputable si el ÚLTIMO tramo del código no es cero
--      (las agrupadoras lo tienen en cero);
--    - la clasificación sale del primer dígito;
--    - corriente: las de nivel 2 por el nombre (ACTIVO / PASIVO CORRIENTE),
--      las de abajo lo heredan;
--    - una cuenta con movimientos no se elimina.
--  Diferencia a propósito: el nivel sale del árbol (el del padre + 1) y el
--  padre lo busca la pantalla por el código, en vez de contar tramos
--  distintos de cero (con un cero en el medio, 4.1.0.01.050 daba nivel 2).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Clasificación (primer dígito del código)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS c_clasificacion_plan_cuentas (
    codigo          SMALLINT UNSIGNED NOT NULL COMMENT 'Primer dígito del código de cuenta',
    clasificacion   VARCHAR(25)  NOT NULL,
    naturaleza      ENUM('deudora','acreedora') NOT NULL,
    tipo            ENUM('activo','pasivo','patrimonio','ingreso','egreso') NOT NULL,
    PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rubros de primer nivel del plan de cuentas';

INSERT IGNORE INTO c_clasificacion_plan_cuentas (codigo, clasificacion, naturaleza, tipo) VALUES
 (1, 'ACTIVO',          'deudora',   'activo'),
 (2, 'PASIVO',          'acreedora', 'pasivo'),
 (3, 'PATRIMONIO NETO', 'acreedora', 'patrimonio'),
 (4, 'INGRESOS',        'acreedora', 'ingreso'),
 (5, 'EGRESOS',         'deudora',   'egreso');

-- ---------------------------------------------------------------------
-- 2. Plan de cuentas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS c_plan_de_cuentas (
    id_cuenta           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_clasificacion    SMALLINT UNSIGNED NOT NULL COMMENT 'c_clasificacion_plan_cuentas.codigo (primer dígito)',
    codigo              VARCHAR(20)  NOT NULL,
    nombre              VARCHAR(150) NOT NULL,
    id_cuenta_padre     INT UNSIGNED NULL,
    nivel               TINYINT UNSIGNED NOT NULL DEFAULT 1,
    permite_movimiento  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = imputable (último tramo distinto de cero)',
    activa              TINYINT(1)   NOT NULL DEFAULT 1,
    es_monetario        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = monetaria (expuesta a inflación)',
    corriente           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = corriente, 0 = no corriente',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    creado_por          INT UNSIGNED NULL,
    PRIMARY KEY (id_cuenta),
    UNIQUE KEY uq_pc_codigo (codigo),
    KEY ix_pc_padre (id_cuenta_padre),
    KEY ix_pc_clasificacion (id_clasificacion),
    CONSTRAINT fk_pc_clasificacion FOREIGN KEY (id_clasificacion) REFERENCES c_clasificacion_plan_cuentas (codigo)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pc_padre FOREIGN KEY (id_cuenta_padre) REFERENCES c_plan_de_cuentas (id_cuenta)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Plan de cuentas (estructura de gestion_adm)';

-- La máscara del código: '9' = dígito, cualquier otro carácter = separador.
-- Vacía hasta que se carga la primera cuenta o se importa un plan (se toma
-- del primer código, como en gestion_adm), o se fija en la pantalla.
INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
 ('CONTABLE_MASCARA', '', 'Máscara del código de cuenta contable: 9 = dígito, lo demás separador (ej. 9.9.99.999)');

-- ---------------------------------------------------------------------
-- 3. Ejercicios y períodos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS c_ejercicios (
    id_ejercicio        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(100) NOT NULL,
    cuit                CHAR(11)     NULL,
    razon_social        VARCHAR(150) NULL,
    fecha_inicio        DATE         NOT NULL,
    fecha_fin           DATE         NOT NULL,
    estado              ENUM('abierto','cerrado','bloqueado') NOT NULL DEFAULT 'abierto',
    activo              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'El ejercicio de trabajo (uno solo)',
    resultado_ejercicio DECIMAL(18,2) NULL,
    id_asiento_apertura INT UNSIGNED NULL,
    id_asiento_cierre   INT UNSIGNED NULL,
    observaciones       TEXT         NULL,
    creado_por          INT UNSIGNED NULL,
    cerrado_por         INT UNSIGNED NULL,
    fecha_cierre_real   DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_ejercicio),
    UNIQUE KEY uq_ej_inicio (fecha_inicio),
    CONSTRAINT ck_ej_fechas CHECK (fecha_fin > fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Ejercicios contables';

CREATE TABLE IF NOT EXISTS c_periodos (
    id_periodo      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_ejercicio    INT UNSIGNED NOT NULL,
    nombre          VARCHAR(60)  NOT NULL,
    fecha_inicio    DATE         NOT NULL,
    fecha_fin       DATE         NOT NULL,
    estado          ENUM('abierto','cerrado','bloqueado') NOT NULL DEFAULT 'abierto'
                    COMMENT 'abierto admite asientos, cerrado no y se reabre, bloqueado no y no se reabre',
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_periodo),
    UNIQUE KEY uq_per_inicio (fecha_inicio),
    KEY ix_per_ejercicio (id_ejercicio, fecha_inicio),
    CONSTRAINT fk_per_ejercicio FOREIGN KEY (id_ejercicio) REFERENCES c_ejercicios (id_ejercicio) ON DELETE CASCADE,
    CONSTRAINT ck_per_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Períodos (meses) de cada ejercicio';

-- ---------------------------------------------------------------------
-- 4. Asientos (libro diario)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS c_asientos (
    id_asiento          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_periodo          INT UNSIGNED NOT NULL,
    numero              INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Número dentro del ejercicio',
    fecha               DATE         NOT NULL,
    descripcion         VARCHAR(255) NOT NULL,
    tipo                ENUM('apertura','operacion','ajuste','cierre','rectificacion') NOT NULL DEFAULT 'operacion',
    referencia          VARCHAR(80)  NULL     COMMENT 'Comprobante que lo origina, como texto (n.º de préstamo, recibo…)',
    estado              ENUM('borrador','confirmado','anulado') NOT NULL DEFAULT 'borrador',
    creado_por          INT UNSIGNED NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_anulacion     DATETIME     NULL,
    motivo_anulacion    VARCHAR(250) NULL,
    anulado_por         INT UNSIGNED NULL,
    PRIMARY KEY (id_asiento),
    KEY ix_asi_periodo (id_periodo, numero),
    KEY ix_asi_fecha (fecha),
    CONSTRAINT fk_asi_periodo FOREIGN KEY (id_periodo) REFERENCES c_periodos (id_periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Libro diario: cabecera del asiento';

CREATE TABLE IF NOT EXISTS c_asiento_detalle (
    id_detalle      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_asiento      INT UNSIGNED NOT NULL,
    id_cuenta       INT UNSIGNED NOT NULL,
    debe            DECIMAL(18,2) NOT NULL DEFAULT 0,
    haber           DECIMAL(18,2) NOT NULL DEFAULT 0,
    descripcion     VARCHAR(255) NULL,
    PRIMARY KEY (id_detalle),
    KEY ix_ad_asiento (id_asiento),
    KEY ix_ad_cuenta (id_cuenta),
    CONSTRAINT fk_ad_asiento FOREIGN KEY (id_asiento) REFERENCES c_asientos (id_asiento) ON DELETE CASCADE,
    CONSTRAINT fk_ad_cuenta  FOREIGN KEY (id_cuenta)  REFERENCES c_plan_de_cuentas (id_cuenta),
    CONSTRAINT ck_ad_lado CHECK (debe >= 0 AND haber >= 0 AND (debe = 0 OR haber = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Libro diario: renglones del asiento';

-- El vínculo entre el asiento y la operación que lo generó. Una operación
-- tiene a lo sumo un asiento ORIGINAL y uno de ANULACION (el contraasiento).
-- tipo_origen: los de movimientos_fondos (COBRANZA, DESEMBOLSO, APORTE,
-- PAGO_INVERSOR, MOVIMIENTO, ORDEN_PAGO…) y los que no mueven fondos
-- (DEVENGAMIENTO, COMISION, FACTURA…). Lo completa el generador.
CREATE TABLE IF NOT EXISTS c_asientos_origen (
    tipo_origen     VARCHAR(20)  NOT NULL,
    id_origen       INT UNSIGNED NOT NULL,
    rol             ENUM('ORIGINAL','ANULACION') NOT NULL DEFAULT 'ORIGINAL',
    id_asiento      INT UNSIGNED NOT NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tipo_origen, id_origen, rol),
    UNIQUE KEY uq_ao_asiento (id_asiento),
    CONSTRAINT fk_ao_asiento FOREIGN KEY (id_asiento) REFERENCES c_asientos (id_asiento) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Qué operación generó cada asiento (el adm_asienref que gestion_adm no tiene)';

-- Los renglones de los asientos confirmados, con su cuenta y período.
CREATE OR REPLACE VIEW c_libro_mayor AS
SELECT d.id_cuenta, pc.codigo, pc.nombre AS cuenta, cl.naturaleza,
       a.id_periodo, p.nombre AS periodo, p.id_ejercicio,
       a.id_asiento, a.numero AS nro_asiento, a.fecha, a.descripcion AS concepto_asiento,
       d.descripcion AS detalle, d.debe, d.haber
  FROM c_asiento_detalle d
  JOIN c_asientos a                    ON a.id_asiento = d.id_asiento AND a.estado = 'confirmado'
  JOIN c_periodos p                    ON p.id_periodo = a.id_periodo
  JOIN c_plan_de_cuentas pc            ON pc.id_cuenta = d.id_cuenta
  JOIN c_clasificacion_plan_cuentas cl ON cl.codigo = pc.id_clasificacion;

-- ---------------------------------------------------------------------
-- 5. Menú: Contabilidad, después de Tesorería (Administrador y Gerencia;
--    a otros, desde Administración › Permisos por usuario).
--    Ids 90 a 92. INSERT IGNORE y permisos solo si la opción es la
--    nuestra: un id que ya use otra opción no se pisa (la consulta del
--    final lo muestra).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (90, NULL, 'Contabilidad',         NULL,              'calculadora', 6, 1, 1),
 (91, 90,   'Plan de cuentas',      'plan_cuentas.php', NULL,         1, 2, 1),
 (92, 90,   'Ejercicios y períodos','ejercicios.php',   NULL,         2, 2, 1);

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT m.id_menu, r.id_rol FROM menu m JOIN roles r ON r.id_rol IN (1, 2)
 WHERE (m.id_menu = 90 AND m.nombre = 'Contabilidad')
    OR (m.padre_id = 90 AND m.url IN ('plan_cuentas.php', 'ejercicios.php'));

SELECT m.id_menu, m.padre_id, m.nombre, m.url, GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu BETWEEN 90 AND 92 GROUP BY m.id_menu, m.padre_id, m.nombre, m.url ORDER BY m.id_menu;
