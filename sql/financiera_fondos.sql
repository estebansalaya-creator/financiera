-- =====================================================================
--  FINANCIERA - Tesorería: cuentas de fondos, libro de movimientos de
--  fondos y débitos / créditos bancarios
--
--  Correr DESPUÉS de financiera_nucleo, financiera_cobranzas,
--  financiera_prestamos, financiera_telemarketing_comisiones,
--  financiera_inversores y financiera_tablas_temporales, con la base de la
--  financiera seleccionada. MySQL 8.0.16 o superior (CHECK e índice
--  funcional). Se puede correr más de una vez: las tablas no se recrean;
--  los procedures sí.
--
--  OJO: redefine sp_registrar_pago, sp_anular_pago, sp_liquidar_prestamo
--  y sp_anular_prestamo (les suma la cuenta de fondos y el libro). Si se
--  vuelve a correr un .sql anterior que los define (cobranzas, prestamos,
--  telemarketing_comisiones, tablas_temporales), después hay que correr
--  este de nuevo. En DBeaver: Ejecutar script (Alt+X).
--
--  EL MODELO (copiado de gestion_adm, sql/ddl_movimientos_de_fondos)
--  -----------------------------------------------------------------
--  Todo comprobante que mueve plata deja un renglón por cuenta afectada
--  en `movimientos_fondos`, con el total de esa cuenta en ese
--  comprobante. Un solo lugar donde mirar el saldo de cualquier cuenta,
--  venga la plata de donde venga.
--
--  Los renglones de una cuenta banco abren además su detalle por
--  instrumento en `debitos_creditos_bancarios`: un cheque, una
--  transferencia, un débito. Es el grano con el que informa el banco, y
--  por eso es lo que se va a conciliar contra el extracto.
--
--      movimientos_fondos              1 renglón por (comprobante, cuenta)
--        └─ debitos_creditos_bancarios 1 renglón por instrumento
--
--  LOS CINCO ORÍGENES (exactamente uno por renglón)
--  -----------------------------------------------
--    COBRANZA       pagos                      entra: cada pago de cuota,
--                                              también los de un lote
--    DESEMBOLSO     prestamos                  sale: el neto desembolsado
--    APORTE         inversiones                entra: el capital de un
--                                              aporte (las renovaciones no
--                                              mueven plata)
--    PAGO_INVERSOR  inversiones_liquidaciones  sale: lo pagado al inversor
--    MOVIMIENTO     movimientos_entre_cuentas  plata propia que cambia de
--                                              cuenta (depósito, extracción,
--                                              transferencia, inversión)
--
--  NOMBRES ADAPTADOS A LA FINANCIERA
--  ---------------------------------
--    gestion_adm (contable)          financiera
--    c_codigo_de_banco               bancos
--    c_cuentas_de_fondos             cuentas_fondos (moneda ARS/USD en
--                                    lugar de c_especies; sin plan de
--                                    cuentas ni formato de transferencias)
--    c_chequeras                     chequeras
--    c_movimiento_entre_cuentas(_det) movimientos_entre_cuentas (siempre
--                                    de una cuenta a otra: origen y
--                                    destino en la cabecera)
--    c_movimientos_de_fondos         movimientos_fondos
--    c_debitos_creditos_bancarios    debitos_creditos_bancarios
--    v_saldos_cuentas_fondos         v_saldos_cuentas_fondos
--  Además de las cinco columnas de origen (con su clave foránea), el
--  renglón tiene `tipo_origen` e `id_origen` generadas: el único UNIQUE
--  (origen, comprobante, cuenta) y los filtros salen de ahí.
--
--  SIGNO
--  -----
--  En el libro `importe_debe` es plata que ENTRA a la cuenta e
--  `importe_haber` la que SALE. En los débitos y créditos bancarios se
--  usa la óptica del banco, la del extracto: `importe_credito` entra,
--  `importe_debito` sale. Son espejo a propósito.
--
--  LA ÚNICA PUERTA DE ESCRITURA
--  ----------------------------
--  Ningún circuito escribe el libro a mano: llama al registrador que le
--  toca y éste deriva los renglones de lo que el comprobante ya guardó.
--
--      sp_fondos_cobranza(id_pago)
--      sp_fondos_desembolso(id_prestamo)
--      sp_fondos_aporte(id_inversion)
--      sp_fondos_pago_inversor(id_liquidacion)
--      sp_fondos_movimiento(id_mov_cuentas)
--      sp_fondos_anular(origen, id, motivo, usuario)
--
--  Siempre dentro de la transacción que guarda el comprobante (no abren
--  ni cierran transacciones): si el comprobante se cae, el renglón se cae
--  con él. Llamarlos dos veces no duplica nada (UNIQUE por origen y
--  cuenta, y `clave` única en el detalle bancario).
--
--  ANULACIÓN: se marca el renglón (anulado_el), no se borra ni se genera
--  un contramovimiento. Todo saldo filtra `anulado_el IS NULL`; la vista
--  lo hace sola. No se anula lo que el banco ya acreditó o debitó.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Bancos (códigos de entidad del BCRA: los 3 primeros dígitos del CBU)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bancos (
    id_banco    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(5)   NOT NULL COMMENT 'Código de entidad del BCRA (3 dígitos, los primeros del CBU)',
    nombre      VARCHAR(100) NOT NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_banco),
    UNIQUE KEY uq_bancos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Entidades financieras según el BCRA';

INSERT IGNORE INTO bancos (codigo, nombre) VALUES
 ('007', 'Banco de Galicia y Buenos Aires'),
 ('011', 'Banco de la Nación Argentina'),
 ('014', 'Banco de la Provincia de Buenos Aires'),
 ('015', 'ICBC (Industrial and Commercial Bank of China)'),
 ('017', 'BBVA Argentina'),
 ('020', 'Banco de la Provincia de Córdoba'),
 ('027', 'Banco Supervielle'),
 ('029', 'Banco de la Ciudad de Buenos Aires'),
 ('034', 'Banco Patagonia'),
 ('044', 'Banco Hipotecario'),
 ('072', 'Banco Santander Argentina'),
 ('143', 'Brubank'),
 ('191', 'Banco Credicoop Cooperativo'),
 ('285', 'Banco Macro'),
 ('299', 'Banco Comafi'),
 ('322', 'Banco Industrial'),
 ('330', 'Nuevo Banco de Santa Fe'),
 ('386', 'Nuevo Banco de Entre Ríos');

-- ---------------------------------------------------------------------
-- 2. Cuentas de fondos
--    tipo  B banco · C caja · R cartera de cheques (valores a depositar)
--          V billetera virtual (Mercado Pago) · I inversiones (plazo fijo,
--          FCI) · O otro
--    Solo las B abren el detalle de débitos y créditos bancarios.
--    `predeterminada`: la que viene elegida para desembolsar y pagar; como
--    mucho una por moneda (índice funcional).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cuentas_fondos (
    id_cuenta_fondos INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo             ENUM('B','C','R','V','I','O') NOT NULL,
    nombre           VARCHAR(100) NOT NULL,
    moneda           ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    id_banco         INT UNSIGNED NULL,
    numero_cuenta    VARCHAR(30)  NULL,
    cbu              VARCHAR(22)  NULL,
    alias            VARCHAR(50)  NULL,
    predeterminada   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'La que viene elegida para desembolsos y pagos. Una por moneda.',
    habilitada       TINYINT(1)   NOT NULL DEFAULT 1,
    comentario       VARCHAR(250) NULL,
    creado_el        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por       INT UNSIGNED NULL,
    PRIMARY KEY (id_cuenta_fondos),
    UNIQUE KEY uq_cf_nombre (nombre),
    UNIQUE KEY uq_cf_cbu (cbu),
    UNIQUE KEY uq_cf_predeterminada ((IF(predeterminada = 1, moneda, NULL))),
    KEY ix_cf_banco (id_banco),
    CONSTRAINT fk_cf_banco FOREIGN KEY (id_banco) REFERENCES bancos (id_banco),
    CONSTRAINT ck_cf_cbu CHECK (cbu IS NULL OR cbu REGEXP '^[0-9]{22}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cuentas de fondos propias: bancos, cajas, cartera de cheques, billeteras e inversiones';

-- Una caja para empezar (solo si no hay ninguna cuenta). El efectivo cobrado va ahí.
INSERT INTO cuentas_fondos (tipo, nombre, moneda, comentario)
SELECT 'C', 'Caja en pesos', 'ARS', 'Creada al instalar Tesorería: el efectivo cobrado entra acá.'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cuentas_fondos);

-- ---------------------------------------------------------------------
-- 3. Cuenta habitual de cada medio de cobro
--    La que viene elegida al cobrar con ese medio (y la que usa
--    sp_registrar_pago si no le pasan cuenta).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cuentas_fondos_medios (
    medio            VARCHAR(20)  NOT NULL COMMENT 'Los de pagos.medio: EFECTIVO, TRANSFERENCIA, DEBITO, RAPIPAGO…',
    id_cuenta_fondos INT UNSIGNED NOT NULL,
    PRIMARY KEY (medio),
    KEY ix_cfm_cuenta (id_cuenta_fondos),
    CONSTRAINT fk_cfm_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cuenta de fondos habitual de cada medio de cobro';

INSERT IGNORE INTO cuentas_fondos_medios (medio, id_cuenta_fondos)
SELECT 'EFECTIVO', id_cuenta_fondos FROM cuentas_fondos
 WHERE tipo = 'C' AND moneda = 'ARS' AND habilitada = 1 ORDER BY id_cuenta_fondos LIMIT 1;

-- ---------------------------------------------------------------------
-- 4. Chequeras (talonarios de cheques propios de una cuenta banco)
--    Para controlar que un cheque emitido sea de la chequera y que su
--    número no se repita. En pantalla se pide el último utilizado; acá se
--    guarda el próximo.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chequeras (
    id_chequera      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cuenta_fondos INT UNSIGNED NOT NULL,
    descripcion      VARCHAR(100) NULL,
    tipo_cheque      ENUM('AL_DIA','DIFERIDO') NOT NULL DEFAULT 'AL_DIA',
    numero_desde     BIGINT UNSIGNED NOT NULL,
    numero_hasta     BIGINT UNSIGNED NOT NULL,
    proximo_numero   BIGINT UNSIGNED NOT NULL,
    habilitada       TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_chequera),
    KEY ix_chq_cuenta (id_cuenta_fondos),
    CONSTRAINT fk_chq_cuenta FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT ck_chq_rango CHECK (numero_hasta >= numero_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Talonarios de cheques por cuenta bancaria';

-- ---------------------------------------------------------------------
-- 5. Movimientos entre cuentas propias
--    No hay tercero: la plata cambia de cuenta. El tipo se deduce de las
--    cuentas (movimientos_cuentas.php): con una de inversiones es
--    INVERSION; de banco a caja, EXTRACCION; de caja o cartera a banco,
--    DEPOSITO; lo demás, ENTRE_CUENTAS. Mismas monedas en las dos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_entre_cuentas (
    id_mov_cuentas    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fecha             DATE         NOT NULL,
    tipo              ENUM('ENTRE_CUENTAS','DEPOSITO','EXTRACCION','INVERSION') NOT NULL,
    id_cuenta_origen  INT UNSIGNED NOT NULL COMMENT 'La que entrega (sale la plata)',
    id_cuenta_destino INT UNSIGNED NOT NULL COMMENT 'La que recibe (entra la plata)',
    importe           DECIMAL(15,2) NOT NULL,
    referencia        VARCHAR(100) NULL COMMENT 'N° de transferencia, de boleta de depósito…',
    comentario        VARCHAR(250) NULL,
    estado            ENUM('VIGENTE','ANULADO') NOT NULL DEFAULT 'VIGENTE',
    creado_el         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por        INT UNSIGNED NULL,
    anulado_el        DATETIME     NULL,
    anulado_por       INT UNSIGNED NULL,
    motivo_anulacion  VARCHAR(200) NULL,
    PRIMARY KEY (id_mov_cuentas),
    KEY ix_mec_fecha (fecha),
    KEY ix_mec_origen (id_cuenta_origen),
    KEY ix_mec_destino (id_cuenta_destino),
    CONSTRAINT fk_mec_origen  FOREIGN KEY (id_cuenta_origen)  REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT fk_mec_destino FOREIGN KEY (id_cuenta_destino) REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT ck_mec_cuentas CHECK (id_cuenta_origen <> id_cuenta_destino),
    CONSTRAINT ck_mec_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Movimientos entre cuentas de fondos propias: depósitos, extracciones, transferencias e inversiones';

-- ---------------------------------------------------------------------
-- 6. La cuenta de fondos en cada comprobante que mueve plata
-- ---------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_agregar_columna $$
CREATE PROCEDURE tmp_agregar_columna(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla AND COLUMN_NAME = p_columna) THEN
        SET @ddl = CONCAT('ALTER TABLE ', p_tabla, ' ADD COLUMN ', p_columna, ' ', p_def);
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END $$

DROP PROCEDURE IF EXISTS tmp_agregar_fk $$
CREATE PROCEDURE tmp_agregar_fk(IN p_tabla VARCHAR(64), IN p_nombre VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla AND CONSTRAINT_NAME = p_nombre) THEN
        SET @ddl = CONCAT('ALTER TABLE ', p_tabla, ' ADD CONSTRAINT ', p_nombre, ' ', p_def);
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END $$

DELIMITER ;

CALL tmp_agregar_columna('pagos',          'id_cuenta_fondos', 'INT UNSIGNED NULL COMMENT ''Cuenta de fondos donde entró el pago'' AFTER referencia');
CALL tmp_agregar_columna('lotes_cobranza', 'id_cuenta_fondos', 'INT UNSIGNED NULL COMMENT ''Cuenta donde acredita la recaudadora'' AFTER medio');
CALL tmp_agregar_columna('prestamos',      'id_cuenta_fondos', 'INT UNSIGNED NULL COMMENT ''Cuenta de la que salió el desembolso'' AFTER neto_desembolso');
CALL tmp_agregar_columna('prestamos',      'medio_desembolso', 'VARCHAR(20) NULL COMMENT ''TRANSFERENCIA, CHEQUE o EFECTIVO'' AFTER id_cuenta_fondos');
CALL tmp_agregar_columna('prestamos',      'referencia_desembolso', 'VARCHAR(100) NULL COMMENT ''N° de transferencia o de cheque'' AFTER medio_desembolso');
CALL tmp_agregar_columna('inversiones',    'id_cuenta_fondos', 'INT UNSIGNED NULL COMMENT ''Cuenta donde ingresó el capital'' AFTER capital');
CALL tmp_agregar_columna('inversiones',    'medio_ingreso',    'VARCHAR(20) NULL COMMENT ''TRANSFERENCIA, EFECTIVO, CHEQUE u OTRO'' AFTER id_cuenta_fondos');
CALL tmp_agregar_columna('inversiones',    'referencia_ingreso', 'VARCHAR(100) NULL AFTER medio_ingreso');
CALL tmp_agregar_columna('inversiones_liquidaciones', 'id_cuenta_fondos', 'INT UNSIGNED NULL COMMENT ''Cuenta de la que salió el pago al inversor'' AFTER medio');

CALL tmp_agregar_fk('pagos',          'fk_pagos_cuenta_fondos',  'FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)');
CALL tmp_agregar_fk('lotes_cobranza', 'fk_lotes_cuenta_fondos',  'FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)');
CALL tmp_agregar_fk('prestamos',      'fk_prest_cuenta_fondos',  'FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)');
CALL tmp_agregar_fk('inversiones',    'fk_inv_cuenta_fondos',    'FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)');
CALL tmp_agregar_fk('inversiones_liquidaciones', 'fk_invliq_cuenta_fondos', 'FOREIGN KEY (id_cuenta_fondos) REFERENCES cuentas_fondos (id_cuenta_fondos)');

DROP PROCEDURE IF EXISTS tmp_agregar_columna;
DROP PROCEDURE IF EXISTS tmp_agregar_fk;

-- ---------------------------------------------------------------------
-- 7. Movimientos de fondos — el libro único de tesorería
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_fondos (
    id_mov_fondos      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cuenta_fondos   INT UNSIGNED  NOT NULL,
    fecha              DATE          NOT NULL COMMENT 'Fecha valor del movimiento',
    importe_debe       DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Entra plata a la cuenta',
    importe_haber      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Sale plata de la cuenta',
    concepto           VARCHAR(200)  NULL,

    -- Origen: uno y solo uno.
    id_pago            INT UNSIGNED  NULL COMMENT 'COBRANZA',
    id_prestamo        INT UNSIGNED  NULL COMMENT 'DESEMBOLSO',
    id_inversion       INT UNSIGNED  NULL COMMENT 'APORTE',
    id_liquidacion_inv INT UNSIGNED  NULL COMMENT 'PAGO_INVERSOR (inversiones_liquidaciones)',
    id_mov_cuentas     INT UNSIGNED  NULL COMMENT 'MOVIMIENTO',

    tipo_origen VARCHAR(20) GENERATED ALWAYS AS (
        CASE
          WHEN id_pago            IS NOT NULL THEN 'COBRANZA'
          WHEN id_prestamo        IS NOT NULL THEN 'DESEMBOLSO'
          WHEN id_inversion       IS NOT NULL THEN 'APORTE'
          WHEN id_liquidacion_inv IS NOT NULL THEN 'PAGO_INVERSOR'
          WHEN id_mov_cuentas     IS NOT NULL THEN 'MOVIMIENTO'
        END) STORED,
    id_origen INT UNSIGNED GENERATED ALWAYS AS (
        COALESCE(id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas)) STORED,

    anulado_el         DATETIME      NULL,
    anulado_por        INT UNSIGNED  NULL,
    motivo_anulacion   VARCHAR(200)  NULL,
    creado_el          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id_mov_fondos),
    -- Un comprobante no tiene dos renglones para la misma cuenta: es lo que
    -- hace seguro volver a correr un registrador.
    UNIQUE KEY uq_mf_origen_cuenta (tipo_origen, id_origen, id_cuenta_fondos),
    KEY ix_mf_cuenta_fecha (id_cuenta_fondos, fecha),
    KEY ix_mf_fecha (fecha),
    KEY ix_mf_anulado (anulado_el),
    KEY ix_mf_pago (id_pago),
    KEY ix_mf_prestamo (id_prestamo),
    KEY ix_mf_inversion (id_inversion),
    KEY ix_mf_liq (id_liquidacion_inv),
    KEY ix_mf_mov (id_mov_cuentas),
    CONSTRAINT fk_mf_cuenta    FOREIGN KEY (id_cuenta_fondos)   REFERENCES cuentas_fondos (id_cuenta_fondos),
    CONSTRAINT fk_mf_pago      FOREIGN KEY (id_pago)            REFERENCES pagos (id_pago),
    CONSTRAINT fk_mf_prestamo  FOREIGN KEY (id_prestamo)        REFERENCES prestamos (id_prestamo),
    CONSTRAINT fk_mf_inversion FOREIGN KEY (id_inversion)       REFERENCES inversiones (id_inversion),
    CONSTRAINT fk_mf_liq       FOREIGN KEY (id_liquidacion_inv) REFERENCES inversiones_liquidaciones (id_liquidacion),
    CONSTRAINT fk_mf_mov       FOREIGN KEY (id_mov_cuentas)     REFERENCES movimientos_entre_cuentas (id_mov_cuentas),
    CONSTRAINT ck_mf_un_origen CHECK (
        (id_pago IS NOT NULL) + (id_prestamo IS NOT NULL) + (id_inversion IS NOT NULL)
      + (id_liquidacion_inv IS NOT NULL) + (id_mov_cuentas IS NOT NULL) = 1),
    CONSTRAINT ck_mf_un_lado CHECK (
        (importe_debe > 0 AND importe_haber = 0) OR (importe_haber > 0 AND importe_debe = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Libro único de tesorería: un renglón por comprobante y cuenta, con el total de esa cuenta en ese comprobante';

-- ---------------------------------------------------------------------
-- 8. Débitos y créditos bancarios — el lado libro de la conciliación
--    Uno por instrumento de los renglones de cuentas banco. Óptica del
--    banco: importe_credito entra, importe_debito sale.
--    `clave` hace repetible al registrador: MF-<id del renglón>.
--    `estado` es el ciclo del instrumento: EMITIDO (en el libro, falta
--    verlo en el banco), ENTREGADO (cheque en manos del beneficiario),
--    ACREDITADO (el banco lo acreditó o lo debitó: `fecha_banco`),
--    RECHAZADO, ANULADO (se anuló el comprobante). La diferencia entre el
--    saldo del libro y el del banco son los EMITIDO / ENTREGADO.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS debitos_creditos_bancarios (
    id_deb_cred       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_mov_fondos     INT UNSIGNED  NOT NULL,
    clave             VARCHAR(40)   NOT NULL,
    tipo_instrumento  ENUM('CHEQUE','TRANSFERENCIA','DEPOSITO','DEBITO_AUTOMATICO','EFECTIVO','OTRO')
                                    NOT NULL DEFAULT 'TRANSFERENCIA',
    importe_debito    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'El banco debita: sale plata',
    importe_credito   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'El banco acredita: entra plata',
    cheque_numero     BIGINT UNSIGNED NULL,
    id_chequera       INT UNSIGNED  NULL,
    fecha_emision     DATE          NULL,
    fecha_vencimiento DATE          NULL COMMENT 'Cheque diferido: desde cuándo se puede cobrar',
    a_la_orden        VARCHAR(200)  NULL,
    nro_referencia    VARCHAR(100)  NULL,
    estado            ENUM('EMITIDO','ENTREGADO','ACREDITADO','RECHAZADO','ANULADO') NOT NULL DEFAULT 'EMITIDO',
    fecha_banco       DATE          NULL COMMENT 'Cuándo lo acreditó o debitó el banco',
    actualizado_por   INT UNSIGNED  NULL,
    creado_el         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_deb_cred),
    UNIQUE KEY uq_dcb_clave (clave),
    -- Un mismo número de cheque no se emite dos veces en la chequera.
    UNIQUE KEY uq_dcb_cheque (id_chequera, cheque_numero),
    KEY ix_dcb_mov (id_mov_fondos),
    KEY ix_dcb_estado (estado),
    KEY ix_dcb_ref (nro_referencia),
    CONSTRAINT fk_dcb_mov      FOREIGN KEY (id_mov_fondos) REFERENCES movimientos_fondos (id_mov_fondos) ON DELETE CASCADE,
    CONSTRAINT fk_dcb_chequera FOREIGN KEY (id_chequera)   REFERENCES chequeras (id_chequera),
    CONSTRAINT ck_dcb_un_lado CHECK (
        (importe_debito > 0 AND importe_credito = 0) OR (importe_credito > 0 AND importe_debito = 0)),
    -- Si es cheque tiene que decir cuál; si no lo es, no puede traer número.
    CONSTRAINT ck_dcb_cheque CHECK (
        (tipo_instrumento =  'CHEQUE' AND cheque_numero IS NOT NULL) OR
        (tipo_instrumento <> 'CHEQUE' AND cheque_numero IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Detalle por instrumento de los movimientos de cuentas banco: el lado libro de la conciliación';

-- ---------------------------------------------------------------------
-- 9. Saldos por cuenta
--    Para que ninguna consulta tenga que acordarse del filtro de anulados.
--    saldo_banco_estimado = lo que debería mostrar el banco: el saldo del
--    libro más lo que todavía no debitó y menos lo que todavía no acreditó.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_saldos_cuentas_fondos AS
SELECT c.id_cuenta_fondos, c.nombre, c.tipo, c.moneda, c.habilitada,
       COALESCE(m.entradas, 0)                            AS entradas,
       COALESCE(m.salidas, 0)                             AS salidas,
       COALESCE(m.entradas, 0) - COALESCE(m.salidas, 0)   AS saldo,
       m.ultimo_movimiento,
       COALESCE(b.debitos_pendientes, 0)                  AS debitos_pendientes,
       COALESCE(b.creditos_pendientes, 0)                 AS creditos_pendientes,
       COALESCE(m.entradas, 0) - COALESCE(m.salidas, 0)
         + COALESCE(b.debitos_pendientes, 0) - COALESCE(b.creditos_pendientes, 0) AS saldo_banco_estimado
  FROM cuentas_fondos c
  LEFT JOIN (SELECT id_cuenta_fondos, SUM(importe_debe) AS entradas, SUM(importe_haber) AS salidas,
                    MAX(fecha) AS ultimo_movimiento
               FROM movimientos_fondos
              WHERE anulado_el IS NULL
              GROUP BY id_cuenta_fondos) m ON m.id_cuenta_fondos = c.id_cuenta_fondos
  LEFT JOIN (SELECT mf.id_cuenta_fondos, SUM(d.importe_debito) AS debitos_pendientes,
                    SUM(d.importe_credito) AS creditos_pendientes
               FROM debitos_creditos_bancarios d
               JOIN movimientos_fondos mf ON mf.id_mov_fondos = d.id_mov_fondos AND mf.anulado_el IS NULL
              WHERE d.estado IN ('EMITIDO', 'ENTREGADO')
              GROUP BY mf.id_cuenta_fondos) b ON b.id_cuenta_fondos = c.id_cuenta_fondos;

-- ---------------------------------------------------------------------
-- 10. Registradores
-- ---------------------------------------------------------------------
DELIMITER $$

-- ¿Se puede usar la cuenta para un movimiento en esa moneda? NULL si sí;
-- si no, el motivo para la persona. Una cuenta NULL está bien (sin libro).
DROP FUNCTION IF EXISTS fn_fondos_cuenta_error $$
CREATE FUNCTION fn_fondos_cuenta_error(p_cuenta INT UNSIGNED, p_moneda VARCHAR(3))
RETURNS VARCHAR(255)
READS SQL DATA
BEGIN
    DECLARE v_nombre VARCHAR(100);
    DECLARE v_moneda VARCHAR(3);
    DECLARE v_hab    TINYINT;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nombre = NULL;

    IF p_cuenta IS NULL THEN
        RETURN NULL;
    END IF;
    SELECT nombre, moneda, habilitada INTO v_nombre, v_moneda, v_hab
      FROM cuentas_fondos WHERE id_cuenta_fondos = p_cuenta;
    IF v_nombre IS NULL THEN
        RETURN 'La cuenta de fondos elegida no existe';
    END IF;
    IF v_hab = 0 THEN
        RETURN CONCAT('La cuenta ', v_nombre, ' está deshabilitada');
    END IF;
    IF v_moneda <> p_moneda THEN
        RETURN CONCAT('La cuenta ', v_nombre, ' es en ', IF(v_moneda = 'USD', 'dólares', 'pesos'),
                      ' y el movimiento es en ', IF(p_moneda = 'USD', 'dólares', 'pesos'));
    END IF;
    RETURN NULL;
END $$

-- Deja en el libro el renglón (origen, comprobante, cuenta) con estos
-- importes. Devuelve su id (NULL si el importe es cero: no es un
-- movimiento de fondos y no se registra).
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

    IF p_origen NOT IN ('COBRANZA', 'DESEMBOLSO', 'APORTE', 'PAGO_INVERSOR', 'MOVIMIENTO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Origen de movimiento de fondos desconocido';
    END IF;
    IF p_debe < 0 OR p_haber < 0 OR (p_debe > 0 AND p_haber > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un renglón del libro de fondos es una entrada o una salida, no las dos';
    END IF;

    IF p_cuenta IS NOT NULL AND (p_debe > 0 OR p_haber > 0) THEN
        INSERT INTO movimientos_fondos (id_cuenta_fondos, fecha, importe_debe, importe_haber, concepto,
                                        id_pago, id_prestamo, id_inversion, id_liquidacion_inv, id_mov_cuentas)
        VALUES (p_cuenta, p_fecha, p_debe, p_haber, LEFT(p_concepto, 200),
                IF(p_origen = 'COBRANZA',      p_id, NULL),
                IF(p_origen = 'DESEMBOLSO',    p_id, NULL),
                IF(p_origen = 'APORTE',        p_id, NULL),
                IF(p_origen = 'PAGO_INVERSOR', p_id, NULL),
                IF(p_origen = 'MOVIMIENTO',    p_id, NULL))
        ON DUPLICATE KEY UPDATE
                id_mov_fondos = LAST_INSERT_ID(id_mov_fondos),
                fecha         = p_fecha,
                importe_debe  = p_debe,
                importe_haber = p_haber,
                concepto      = LEFT(p_concepto, 200);
        SET p_id_mov = LAST_INSERT_ID();
    END IF;
END $$

-- Borra los renglones del comprobante en cuentas que dejó de tocar
-- (NULL en las dos = todos).
DROP PROCEDURE IF EXISTS sp_fondos_quitar $$
CREATE PROCEDURE sp_fondos_quitar(IN p_origen VARCHAR(20), IN p_id INT UNSIGNED,
                                  IN p_queda1 INT UNSIGNED, IN p_queda2 INT UNSIGNED)
BEGIN
    DELETE FROM movimientos_fondos
     WHERE tipo_origen = p_origen AND id_origen = p_id
       AND NOT (id_cuenta_fondos <=> p_queda1)
       AND NOT (id_cuenta_fondos <=> p_queda2);
END $$

-- Abre el detalle bancario de un renglón, si su cuenta es un banco. Un
-- renglón = un instrumento (los circuitos de la financiera pagan y cobran
-- con uno solo; la tabla admite varios por renglón).
-- Un cheque propio tiene que ser de una chequera de la cuenta y no
-- haberse usado; al usarlo, la chequera avanza su próximo número.
DROP PROCEDURE IF EXISTS sp_fondos_banco $$
CREATE PROCEDURE sp_fondos_banco(
    IN p_id_mov     INT UNSIGNED,
    IN p_tipo       VARCHAR(20),
    IN p_cheque     BIGINT UNSIGNED,
    IN p_referencia VARCHAR(100),
    IN p_a_la_orden VARCHAR(200)
)
BEGIN
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_nombre   VARCHAR(100);
    DECLARE v_tipo_cta VARCHAR(1);
    DECLARE v_fecha    DATE;
    DECLARE v_debe     DECIMAL(15,2);
    DECLARE v_haber    DECIMAL(15,2);
    DECLARE v_chequera INT UNSIGNED DEFAULT NULL;
    DECLARE v_otro     INT UNSIGNED DEFAULT NULL;
    DECLARE v_clave    VARCHAR(40);
    DECLARE v_msg      VARCHAR(255);
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT m.id_cuenta_fondos, c.nombre, c.tipo, m.fecha, m.importe_debe, m.importe_haber
      INTO v_cuenta, v_nombre, v_tipo_cta, v_fecha, v_debe, v_haber
      FROM movimientos_fondos m
      JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos
     WHERE m.id_mov_fondos = p_id_mov;

    SET v_clave = CONCAT('MF-', p_id_mov);

    IF v_tipo_cta IS NULL OR v_tipo_cta <> 'B' THEN
        -- Caja, cartera, billetera…: no hay banco que informe. Si antes era
        -- un banco (se cambió la cuenta), se va el detalle viejo.
        DELETE FROM debitos_creditos_bancarios WHERE id_mov_fondos = p_id_mov;
    ELSE
        IF p_tipo NOT IN ('CHEQUE', 'TRANSFERENCIA', 'DEPOSITO', 'DEBITO_AUTOMATICO', 'EFECTIVO', 'OTRO') THEN
            SET p_tipo = 'OTRO';
        END IF;

        IF p_tipo = 'CHEQUE' THEN
            IF p_cheque IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falta el número de cheque';
            END IF;
            IF v_haber <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un cheque propio es una salida de la cuenta (un cheque recibido se deposita)';
            END IF;
            SET v_nada = 0;
            SELECT id_chequera INTO v_chequera
              FROM chequeras
             WHERE id_cuenta_fondos = v_cuenta AND p_cheque BETWEEN numero_desde AND numero_hasta
             ORDER BY habilitada DESC, id_chequera
             LIMIT 1;
            IF v_chequera IS NULL THEN
                SET v_msg = LEFT(CONCAT('El cheque N° ', p_cheque, ' no es de ninguna chequera de ', v_nombre,
                                        ': cargala en Cuentas de fondos'), 128);
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
            END IF;
            SET v_nada = 0;
            SELECT id_deb_cred INTO v_otro
              FROM debitos_creditos_bancarios
             WHERE id_chequera = v_chequera AND cheque_numero = p_cheque AND clave <> v_clave
             LIMIT 1;
            IF v_otro IS NOT NULL THEN
                SET v_msg = LEFT(CONCAT('El cheque N° ', p_cheque, ' de ', v_nombre, ' ya se usó'), 128);
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
            END IF;
            UPDATE chequeras SET proximo_numero = GREATEST(proximo_numero, p_cheque + 1)
             WHERE id_chequera = v_chequera;
        ELSE
            SET p_cheque = NULL;
        END IF;

        INSERT INTO debitos_creditos_bancarios (id_mov_fondos, clave, tipo_instrumento, importe_debito, importe_credito,
                                                cheque_numero, id_chequera, fecha_emision, a_la_orden, nro_referencia)
        VALUES (p_id_mov, v_clave, p_tipo, v_haber, v_debe, p_cheque, v_chequera, v_fecha,
                LEFT(p_a_la_orden, 200), LEFT(p_referencia, 100))
        ON DUPLICATE KEY UPDATE
                tipo_instrumento = p_tipo,
                importe_debito   = v_haber,
                importe_credito  = v_debe,
                cheque_numero    = p_cheque,
                id_chequera      = v_chequera,
                fecha_emision    = v_fecha,
                a_la_orden       = LEFT(p_a_la_orden, 200),
                nro_referencia   = LEFT(p_referencia, 100);
    END IF;
END $$

-- Saca el comprobante del saldo sin borrarlo. Lo que el banco ya acreditó
-- o debitó no se anula: la plata se movió de verdad.
DROP PROCEDURE IF EXISTS sp_fondos_anular $$
CREATE PROCEDURE sp_fondos_anular(IN p_origen VARCHAR(20), IN p_id INT UNSIGNED,
                                  IN p_motivo VARCHAR(255), IN p_id_usuario INT UNSIGNED)
BEGIN
    IF EXISTS (SELECT 1 FROM debitos_creditos_bancarios d
                 JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                WHERE m.tipo_origen = p_origen AND m.id_origen = p_id
                  AND m.anulado_el IS NULL AND d.estado = 'ACREDITADO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El banco ya acreditó o debitó este movimiento: si fue un error, volvelo a pendiente en Débitos y créditos bancarios';
    END IF;

    UPDATE movimientos_fondos
       SET anulado_el       = COALESCE(anulado_el, NOW()),
           anulado_por      = COALESCE(anulado_por, p_id_usuario),
           motivo_anulacion = COALESCE(motivo_anulacion, LEFT(p_motivo, 200))
     WHERE tipo_origen = p_origen AND id_origen = p_id;

    UPDATE debitos_creditos_bancarios d
      JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
       SET d.estado = 'ANULADO'
     WHERE m.tipo_origen = p_origen AND m.id_origen = p_id;
END $$

-- ---- Un registrador por circuito --------------------------------------

-- COBRANZA: entra el importe del pago a su cuenta. Sin cuenta, el pago
-- no mueve el libro (pagos de antes de Tesorería).
DROP PROCEDURE IF EXISTS sp_fondos_cobranza $$
CREATE PROCEDURE sp_fondos_cobranza(IN p_id_pago INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT g.fecha, g.importe, g.medio, g.referencia, g.estado, g.id_cuenta_fondos, g.motivo_anulacion, g.anulado_por,
           CONCAT('Cobranza ', COALESCE(g.nro_recibo, g.id_pago), ' · ', p.nro_prestamo, ' · ', c.denominacion)
      INTO v_fecha, v_importe, v_medio, v_ref, v_estado, v_cuenta, v_motivo, v_anul_por, v_concepto
      FROM pagos g
      JOIN prestamos p ON p.id_prestamo = g.id_prestamo
      JOIN clientes c  ON c.id_cliente  = p.id_cliente
     WHERE g.id_pago = p_id_pago;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    IF v_cuenta IS NOT NULL THEN
        SELECT moneda INTO v_moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_cuenta;
        IF v_moneda <> 'ARS' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las cobranzas son en pesos: elegí una cuenta de fondos en pesos';
        END IF;
        CALL sp_fondos_renglon('COBRANZA', p_id_pago, v_cuenta, v_fecha, v_importe, 0, v_concepto, v_mf);
    END IF;
    CALL sp_fondos_quitar('COBRANZA', p_id_pago, IF(v_mf IS NULL, NULL, v_cuenta), NULL);

    IF v_mf IS NOT NULL THEN
        CALL sp_fondos_banco(v_mf,
             CASE v_medio WHEN 'DEBITO'   THEN 'DEBITO_AUTOMATICO'
                          WHEN 'EFECTIVO' THEN 'DEPOSITO'
                          WHEN 'CHEQUE'   THEN 'DEPOSITO'      -- el cheque del cliente se deposita
                          WHEN 'OTRO'     THEN 'OTRO'
                          ELSE 'TRANSFERENCIA' END,           -- transferencia, Rapipago, Pago Fácil, Mercado Pago
             NULL, v_ref, NULL);
    END IF;

    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('COBRANZA', p_id_pago, COALESCE(v_motivo, 'Pago anulado'), v_anul_por);
    END IF;
END $$

-- DESEMBOLSO: sale el neto desembolsado de la cuenta del préstamo.
DROP PROCEDURE IF EXISTS sp_fondos_desembolso $$
CREATE PROCEDURE sp_fondos_desembolso(IN p_id_prestamo INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_neto     DECIMAL(15,2);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_cliente  VARCHAR(200);
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_cheque   BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT p.fecha_liquidacion, p.neto_desembolso, p.id_cuenta_fondos, COALESCE(p.medio_desembolso, 'TRANSFERENCIA'),
           p.referencia_desembolso, p.estado, p.motivo_anulacion, p.anulado_por, c.denominacion,
           CONCAT('Desembolso ', p.nro_prestamo, ' · ', c.denominacion)
      INTO v_fecha, v_neto, v_cuenta, v_medio, v_ref, v_estado, v_motivo, v_anul_por, v_cliente, v_concepto
      FROM prestamos p
      JOIN clientes c ON c.id_cliente = p.id_cliente
     WHERE p.id_prestamo = p_id_prestamo;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    IF v_cuenta IS NOT NULL THEN
        SELECT moneda INTO v_moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_cuenta;
        IF v_moneda <> 'ARS' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los préstamos son en pesos: el desembolso sale de una cuenta en pesos';
        END IF;
        CALL sp_fondos_renglon('DESEMBOLSO', p_id_prestamo, v_cuenta, v_fecha, 0, v_neto, v_concepto, v_mf);
    END IF;
    CALL sp_fondos_quitar('DESEMBOLSO', p_id_prestamo, IF(v_mf IS NULL, NULL, v_cuenta), NULL);

    IF v_mf IS NOT NULL THEN
        IF v_medio = 'CHEQUE' THEN
            IF COALESCE(v_ref, '') NOT REGEXP '^[0-9]{1,18}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escribí el número del cheque del desembolso (solo números)';
            END IF;
            SET v_cheque = CAST(v_ref AS UNSIGNED);
        END IF;
        CALL sp_fondos_banco(v_mf,
             CASE v_medio WHEN 'CHEQUE' THEN 'CHEQUE' WHEN 'EFECTIVO' THEN 'EFECTIVO' ELSE 'TRANSFERENCIA' END,
             v_cheque, IF(v_medio = 'CHEQUE', NULL, v_ref), v_cliente);
    END IF;

    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('DESEMBOLSO', p_id_prestamo, COALESCE(v_motivo, 'Préstamo anulado'), v_anul_por);
    END IF;
END $$

-- APORTE: entra el capital del aporte. Una renovación (id_inversion_origen)
-- no mueve plata: el capital ya estaba.
DROP PROCEDURE IF EXISTS sp_fondos_aporte $$
CREATE PROCEDURE sp_fondos_aporte(IN p_id_inversion INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_capital  DECIMAL(15,2);
    DECLARE v_mon_inv  VARCHAR(3);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_origen   INT UNSIGNED;
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_msg      VARCHAR(255);
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT i.fecha_inicio, i.capital, i.moneda, i.id_cuenta_fondos, COALESCE(i.medio_ingreso, 'TRANSFERENCIA'),
           i.referencia_ingreso, i.estado, i.motivo_anulacion, i.id_inversion_origen,
           CONCAT('Aporte ', COALESCE(i.nro_inversion, i.id_inversion), ' · ', v.denominacion)
      INTO v_fecha, v_capital, v_mon_inv, v_cuenta, v_medio, v_ref, v_estado, v_motivo, v_origen, v_concepto
      FROM inversiones i
      JOIN inversores v ON v.id_inversor = i.id_inversor
     WHERE i.id_inversion = p_id_inversion;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    IF v_cuenta IS NOT NULL AND v_origen IS NULL THEN
        SELECT moneda INTO v_moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_cuenta;
        IF v_moneda <> v_mon_inv THEN
            SET v_msg = CONCAT('El aporte es en ', IF(v_mon_inv = 'USD', 'dólares', 'pesos'),
                               ': elegí una cuenta de fondos en ', IF(v_mon_inv = 'USD', 'dólares', 'pesos'));
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
        END IF;
        CALL sp_fondos_renglon('APORTE', p_id_inversion, v_cuenta, v_fecha, v_capital, 0, v_concepto, v_mf);
    END IF;
    CALL sp_fondos_quitar('APORTE', p_id_inversion, IF(v_mf IS NULL, NULL, v_cuenta), NULL);

    IF v_mf IS NOT NULL THEN
        CALL sp_fondos_banco(v_mf,
             CASE v_medio WHEN 'EFECTIVO' THEN 'DEPOSITO' WHEN 'CHEQUE' THEN 'DEPOSITO' WHEN 'OTRO' THEN 'OTRO'
                          ELSE 'TRANSFERENCIA' END,
             NULL, v_ref, NULL);
    END IF;

    IF v_estado = 'ANULADA' THEN
        CALL sp_fondos_anular('APORTE', p_id_inversion, COALESCE(v_motivo, 'Aporte anulado'), NULL);
    END IF;
END $$

-- PAGO_INVERSOR: sale lo pagado al inversor, cuando la liquidación se
-- paga (no las que se renovaron enteras: no hubo pago).
DROP PROCEDURE IF EXISTS sp_fondos_pago_inversor $$
CREATE PROCEDURE sp_fondos_pago_inversor(IN p_id_liquidacion INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_pagar    DECIMAL(15,2);
    DECLARE v_mon_liq  VARCHAR(3);
    DECLARE v_cuenta   INT UNSIGNED;
    DECLARE v_medio    VARCHAR(20);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_inversor VARCHAR(200);
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_moneda   VARCHAR(3);
    DECLARE v_msg      VARCHAR(255);
    DECLARE v_cheque   BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_existe   TINYINT DEFAULT 0;
    DECLARE v_mf       INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT 1, l.pagado_el, l.a_pagar, l.moneda, l.id_cuenta_fondos, l.medio, l.referencia, l.estado, l.motivo_anulacion,
           v.denominacion, CONCAT('Pago a inversor · aporte ', COALESCE(i.nro_inversion, i.id_inversion), ' · ', v.denominacion)
      INTO v_existe, v_fecha, v_pagar, v_mon_liq, v_cuenta, v_medio, v_ref, v_estado, v_motivo, v_inversor, v_concepto
      FROM inversiones_liquidaciones l
      JOIN inversiones i ON i.id_inversion = l.id_inversion
      JOIN inversores v  ON v.id_inversor  = i.id_inversor
     WHERE l.id_liquidacion = p_id_liquidacion;
    IF v_existe = 0 THEN
        LEAVE proc;
    END IF;

    IF v_estado = 'ANULADA' THEN
        CALL sp_fondos_anular('PAGO_INVERSOR', p_id_liquidacion, COALESCE(v_motivo, 'Liquidación anulada'), NULL);
        LEAVE proc;
    END IF;

    IF v_estado = 'PAGADA' AND COALESCE(v_medio, '') <> 'RENOVACION' AND v_cuenta IS NOT NULL AND v_pagar > 0 THEN
        SELECT moneda INTO v_moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_cuenta;
        IF v_moneda <> v_mon_liq THEN
            SET v_msg = CONCAT('La liquidación es en ', IF(v_mon_liq = 'USD', 'dólares', 'pesos'),
                               ': el pago sale de una cuenta en ', IF(v_mon_liq = 'USD', 'dólares', 'pesos'));
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
        END IF;
        CALL sp_fondos_renglon('PAGO_INVERSOR', p_id_liquidacion, v_cuenta, COALESCE(v_fecha, CURDATE()), 0, v_pagar, v_concepto, v_mf);
    END IF;
    CALL sp_fondos_quitar('PAGO_INVERSOR', p_id_liquidacion, IF(v_mf IS NULL, NULL, v_cuenta), NULL);

    IF v_mf IS NOT NULL THEN
        IF v_medio = 'CHEQUE' THEN
            IF COALESCE(v_ref, '') NOT REGEXP '^[0-9]{1,18}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'En un pago con cheque, la referencia es el número del cheque (solo números)';
            END IF;
            SET v_cheque = CAST(v_ref AS UNSIGNED);
        END IF;
        CALL sp_fondos_banco(v_mf,
             CASE v_medio WHEN 'CHEQUE' THEN 'CHEQUE' WHEN 'EFECTIVO' THEN 'EFECTIVO' WHEN 'OTRO' THEN 'OTRO'
                          ELSE 'TRANSFERENCIA' END,
             v_cheque, IF(v_medio = 'CHEQUE', NULL, v_ref), v_inversor);
    END IF;
END $$

-- MOVIMIENTO: la cuenta de origen al haber, la de destino al debe.
DROP PROCEDURE IF EXISTS sp_fondos_movimiento $$
CREATE PROCEDURE sp_fondos_movimiento(IN p_id_mov_cuentas INT UNSIGNED)
proc: BEGIN
    DECLARE v_fecha    DATE;
    DECLARE v_tipo     VARCHAR(20);
    DECLARE v_orig     INT UNSIGNED;
    DECLARE v_dest     INT UNSIGNED;
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_ref      VARCHAR(100);
    DECLARE v_coment   VARCHAR(250);
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_motivo   VARCHAR(200);
    DECLARE v_anul_por INT UNSIGNED;
    DECLARE v_concepto VARCHAR(255);
    DECLARE v_mf1      INT UNSIGNED DEFAULT NULL;
    DECLARE v_mf2      INT UNSIGNED DEFAULT NULL;
    DECLARE v_nada     TINYINT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_nada = 1;

    SELECT fecha, tipo, id_cuenta_origen, id_cuenta_destino, importe, referencia, comentario, estado,
           motivo_anulacion, anulado_por
      INTO v_fecha, v_tipo, v_orig, v_dest, v_importe, v_ref, v_coment, v_estado, v_motivo, v_anul_por
      FROM movimientos_entre_cuentas WHERE id_mov_cuentas = p_id_mov_cuentas;
    IF v_fecha IS NULL THEN
        LEAVE proc;
    END IF;

    IF (SELECT moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_orig)
       <> (SELECT moneda FROM cuentas_fondos WHERE id_cuenta_fondos = v_dest) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las dos cuentas tienen que ser de la misma moneda';
    END IF;

    SET v_concepto = CONCAT(CASE v_tipo WHEN 'DEPOSITO' THEN 'Depósito bancario' WHEN 'EXTRACCION' THEN 'Extracción bancaria'
                                        WHEN 'INVERSION' THEN 'Inversión' ELSE 'Movimiento entre cuentas' END,
                            ' N° ', p_id_mov_cuentas,
                            ' · ', (SELECT nombre FROM cuentas_fondos WHERE id_cuenta_fondos = v_orig),
                            ' → ', (SELECT nombre FROM cuentas_fondos WHERE id_cuenta_fondos = v_dest),
                            IF(v_coment IS NULL OR v_coment = '', '', CONCAT(' · ', v_coment)));

    CALL sp_fondos_renglon('MOVIMIENTO', p_id_mov_cuentas, v_orig, v_fecha, 0, v_importe, v_concepto, v_mf1);
    CALL sp_fondos_renglon('MOVIMIENTO', p_id_mov_cuentas, v_dest, v_fecha, v_importe, 0, v_concepto, v_mf2);
    CALL sp_fondos_quitar('MOVIMIENTO', p_id_mov_cuentas, v_orig, v_dest);

    CALL sp_fondos_banco(v_mf1, IF(v_tipo = 'EXTRACCION', 'EFECTIVO', 'TRANSFERENCIA'), NULL, v_ref, NULL);
    CALL sp_fondos_banco(v_mf2, IF(v_tipo = 'DEPOSITO', 'DEPOSITO', 'TRANSFERENCIA'), NULL, v_ref, NULL);

    IF v_estado = 'ANULADO' THEN
        CALL sp_fondos_anular('MOVIMIENTO', p_id_mov_cuentas, COALESCE(v_motivo, 'Movimiento anulado'), v_anul_por);
    END IF;
END $$

-- ---------------------------------------------------------------------
-- 11. Los circuitos que ya existían, enganchados al libro
-- ---------------------------------------------------------------------

-- sp_registrar_pago: igual que en financiera_cobranzas.sql, más
--   p_id_cuenta  cuenta de fondos donde entra el pago. NULL = la habitual
--                del medio (cuentas_fondos_medios); si no hay, el pago se
--                registra sin libro.
DROP PROCEDURE IF EXISTS sp_registrar_pago $$
CREATE PROCEDURE sp_registrar_pago(
    IN  p_id_prestamo INT UNSIGNED,
    IN  p_fecha       DATE,
    IN  p_importe     DECIMAL(15,2),
    IN  p_medio       VARCHAR(20),
    IN  p_referencia  VARCHAR(100),
    IN  p_modo        VARCHAR(20),
    IN  p_id_lote     INT UNSIGNED,
    IN  p_id_usuario  INT UNSIGNED,
    IN  p_id_cuenta   INT UNSIGNED,
    OUT p_id_pago     INT UNSIGNED
)
BEGIN
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_fliq     DATE;
    DECLARE v_total    DECIMAL(15,2);
    DECLARE v_resto    DECIMAL(15,2);
    DECLARE v_aplicar  DECIMAL(15,2);
    DECLARE v_id_cuota INT UNSIGNED;
    DECLARE v_concepto VARCHAR(20);
    DECLARE v_pend     DECIMAL(15,2);
    DECLARE v_fin      TINYINT DEFAULT 0;
    DECLARE v_msg      VARCHAR(255);

    DECLARE cur CURSOR FOR
        SELECT id_cuota, concepto, pendiente
          FROM tmp_deuda
         ORDER BY nro_cuota, orden;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha = COALESCE(p_fecha, CURDATE());
    SET p_modo  = COALESCE(p_modo, 'NORMAL');

    IF p_importe IS NULL OR p_importe <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El importe debe ser mayor a cero';
    END IF;

    SET p_id_cuenta = COALESCE(p_id_cuenta, (SELECT id_cuenta_fondos FROM cuentas_fondos_medios WHERE medio = p_medio));
    SET v_msg = LEFT(fn_fondos_cuenta_error(p_id_cuenta, 'ARS'), 128);
    IF v_msg IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    START TRANSACTION;

    SELECT estado, fecha_liquidacion INTO v_estado, v_fliq
      FROM prestamos WHERE id_prestamo = p_id_prestamo
       FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('El préstamo no está vigente (', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_fecha < v_fliq THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La fecha del pago es anterior a la liquidación';
    END IF;
    IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = p_id_prestamo
                AND estado = 'APLICADO' AND fecha > p_fecha) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hay pagos con fecha posterior: no se puede imputar con fecha anterior';
    END IF;

    CALL sp_calc_deuda(p_id_prestamo, p_fecha, p_modo);

    SELECT COALESCE(SUM(pendiente), 0) INTO v_total FROM tmp_deuda;

    IF p_modo = 'NORMAL' AND p_importe > v_total THEN
        SET v_msg = CONCAT('El importe supera la deuda total (', v_total,
                           '). Para cancelar, usar modo CANCELACION');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_modo = 'CANCELACION' AND p_importe <> v_total THEN
        SET v_msg = CONCAT('El monto de cancelación al ', DATE_FORMAT(p_fecha, '%d/%m/%Y'),
                           ' es ', v_total, '. Se informó ', p_importe);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    INSERT INTO pagos (id_prestamo, fecha, importe, medio, referencia, id_cuenta_fondos, modo, id_lote, creado_por)
    VALUES (p_id_prestamo, p_fecha, p_importe, p_medio, p_referencia, p_id_cuenta, p_modo, p_id_lote, p_id_usuario);
    SET p_id_pago = LAST_INSERT_ID();

    UPDATE pagos SET nro_recibo = CONCAT('R-', LPAD(p_id_pago, 8, '0'))
     WHERE id_pago = p_id_pago;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'PUNITORIO', punit_nuevo,
           CONCAT('Punitorio cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE punit_nuevo > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'IVA_PUNITORIO', ivap_nuevo,
           CONCAT('IVA punitorio cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE ivap_nuevo > 0;

    UPDATE cuotas c
      JOIN tmp_cs t ON t.id_cuota = c.id_cuota
       SET c.punitorio_hasta = p_fecha
     WHERE t.devenga = 1;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_INTERES', -quita_int,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_int > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'QUITA_IVA', -quita_iva,
           CONCAT('Cancelación anticipada cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE quita_iva > 0;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT p_id_prestamo, id_cuota, p_id_pago, p_fecha, 'CREDITO_CAPITAL', -cred_cap,
           CONCAT('Interés pagado por adelantado, a capital cuota ', nro_cuota), p_id_usuario
      FROM tmp_cs WHERE cred_cap > 0;

    SET v_resto = p_importe;
    OPEN cur;
    lazo: LOOP
        FETCH cur INTO v_id_cuota, v_concepto, v_pend;
        IF v_fin = 1 OR v_resto <= 0 THEN LEAVE lazo; END IF;

        SET v_aplicar = LEAST(v_resto, v_pend);
        INSERT INTO pagos_imputacion (id_pago, id_cuota, concepto, importe)
        VALUES (p_id_pago, v_id_cuota, v_concepto, v_aplicar);
        SET v_resto = v_resto - v_aplicar;
    END LOOP;
    CLOSE cur;

    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (p_id_prestamo, p_id_pago, p_fecha, 'PAGO', -p_importe,
            CONCAT(p_medio, IF(p_referencia IS NULL, '', CONCAT(' ', p_referencia))), p_id_usuario);

    CALL sp_actualizar_estados(p_id_prestamo);

    CALL sp_fondos_cobranza(p_id_pago);

    COMMIT;

    SELECT pg.nro_recibo, c.nro_cuota, c.vencimiento, pi.concepto, pi.importe
      FROM pagos_imputacion pi
      JOIN pagos  pg ON pg.id_pago  = pi.id_pago
      JOIN cuotas c  ON c.id_cuota  = pi.id_cuota
      JOIN imputacion_orden o ON o.concepto = pi.concepto
     WHERE pi.id_pago = p_id_pago
     ORDER BY c.nro_cuota, o.orden;
END $$

-- sp_anular_pago: igual que en financiera_cobranzas.sql, más la anulación
-- del renglón en el libro de fondos.
DROP PROCEDURE IF EXISTS sp_anular_pago $$
CREATE PROCEDURE sp_anular_pago(
    IN p_id_pago    INT UNSIGNED,
    IN p_motivo     VARCHAR(200),
    IN p_id_usuario INT UNSIGNED
)
BEGIN
    DECLARE v_prestamo INT UNSIGNED;
    DECLARE v_estado   VARCHAR(20);
    DECLARE v_importe  DECIMAL(15,2);
    DECLARE v_fecha    DATE;
    DECLARE v_ultimo   INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR TRIM(p_motivo) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    START TRANSACTION;

    SELECT id_prestamo, estado, importe, fecha
      INTO v_prestamo, v_estado, v_importe, v_fecha
      FROM pagos WHERE id_pago = p_id_pago;

    IF v_prestamo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pago inexistente';
    END IF;

    SELECT id_prestamo INTO v_prestamo
      FROM prestamos WHERE id_prestamo = v_prestamo FOR UPDATE;

    IF v_estado <> 'APLICADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya está anulado';
    END IF;

    SELECT id_pago INTO v_ultimo
      FROM pagos
     WHERE id_prestamo = v_prestamo AND estado = 'APLICADO'
     ORDER BY fecha DESC, id_pago DESC
     LIMIT 1;

    IF v_ultimo <> p_id_pago THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular el último pago del préstamo';
    END IF;

    UPDATE pagos
       SET estado = 'ANULADO', motivo_anulacion = p_motivo,
           anulado_por = p_id_usuario, anulado_el = NOW()
     WHERE id_pago = p_id_pago;

    INSERT INTO movimientos (id_prestamo, id_cuota, id_pago, fecha, tipo, importe, referencia, creado_por)
    SELECT id_prestamo, id_cuota, id_pago, v_fecha, tipo, -importe,
           'Reversa por anulación de pago', p_id_usuario
      FROM movimientos
     WHERE id_pago = p_id_pago AND tipo IN ('QUITA_INTERES', 'QUITA_IVA', 'CREDITO_CAPITAL');

    INSERT INTO movimientos (id_prestamo, id_pago, fecha, tipo, importe, referencia, creado_por)
    VALUES (v_prestamo, p_id_pago, v_fecha, 'ANULACION_PAGO', v_importe, p_motivo, p_id_usuario);

    CALL sp_actualizar_estados(v_prestamo);

    CALL sp_fondos_anular('COBRANZA', p_id_pago, p_motivo, p_id_usuario);

    COMMIT;
END $$

-- sp_liquidar_prestamo: igual que en financiera_tablas_temporales.sql (la
-- versión que lee tmp_cuadro una sola vez por consulta, la que anda en
-- Railway), más el desembolso:
--   p_id_cuenta        cuenta de la que sale el neto (NULL = la
--                      predeterminada en pesos; si no hay, sin libro)
--   p_medio_desembolso TRANSFERENCIA (por defecto), CHEQUE o EFECTIVO
--   p_ref_desembolso   N° de transferencia, o el N° del cheque (de una
--                      chequera de la cuenta)
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
    IN  p_telemarketing TINYINT,
    IN  p_id_usuario    INT UNSIGNED,
    IN  p_id_cuenta     INT UNSIGNED,
    IN  p_medio_desembolso VARCHAR(20),
    IN  p_ref_desembolso   VARCHAR(100),
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
    DECLARE v_cuota, v_int, v_ivat DECIMAL(15,2);
    DECLARE v_msg       VARCHAR(255);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_fecha      = COALESCE(p_fecha, CURDATE());
    SET p_telemarketing = COALESCE(p_telemarketing, 0);
    SET p_primer_vto = COALESCE(p_primer_vto, DATE_ADD(p_fecha, INTERVAL 1 MONTH));

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

    -- El desembolso
    SET p_medio_desembolso = COALESCE(NULLIF(p_medio_desembolso, ''), 'TRANSFERENCIA');
    IF p_medio_desembolso NOT IN ('TRANSFERENCIA', 'CHEQUE', 'EFECTIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El desembolso es por transferencia, cheque o efectivo';
    END IF;
    SET p_id_cuenta = COALESCE(p_id_cuenta, (SELECT id_cuenta_fondos FROM cuentas_fondos
                                               WHERE predeterminada = 1 AND moneda = 'ARS' AND habilitada = 1 LIMIT 1));
    SET v_msg = LEFT(fn_fondos_cuenta_error(p_id_cuenta, 'ARS'), 128);
    IF v_msg IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF p_medio_desembolso = 'CHEQUE' THEN
        IF COALESCE((SELECT tipo FROM cuentas_fondos WHERE id_cuenta_fondos = p_id_cuenta), '') <> 'B' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un desembolso con cheque sale de una cuenta bancaria';
        END IF;
        IF COALESCE(p_ref_desembolso, '') NOT REGEXP '^[0-9]{1,18}$' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escribí el número del cheque del desembolso (solo números)';
        END IF;
    END IF;

    SET v_id_tasa = fn_tasa_vigente(p_id_producto, p_plazo, p_capital, p_fecha);
    IF v_id_tasa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay tasa vigente para ese plazo y monto';
    END IF;

    SELECT t.tna,
           COALESCE(t.tasa_punitoria,
                    ROUND(t.tna * COALESCE((SELECT CAST(valor AS DECIMAL(9,6)) FROM parametros
                                             WHERE clave = 'PUNITORIO_PCT_TNA'), 0.5), 6))
      INTO v_tna, v_punit
      FROM tasas_vigencia t WHERE t.id_tasa = v_id_tasa;

    SET v_gastos = ROUND(p_capital * v_gasto_pct, 2);

    CALL sp_cuadro_frances(p_capital, p_plazo, v_tna, v_iva, p_primer_vto);

    SELECT SUM(IF(nro_cuota = 1, importe, 0)), SUM(interes), SUM(iva_interes)
      INTO v_cuota, v_int, v_ivat
      FROM tmp_cuadro;

    IF p_id_vendedor IS NOT NULL THEN
        IF p_comision_pct IS NOT NULL THEN
            SET v_com_pct   = p_comision_pct;
            SET v_base_tipo = 'CAPITAL';
        ELSE
            SET v_id_regla = fn_comision_regla(p_id_vendedor, p_id_producto, p_plazo, p_capital, p_fecha, COALESCE(p_telemarketing, 0));
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
        tasa_punitoria, gastos_otorg, neto_desembolso, id_cuenta_fondos, medio_desembolso, referencia_desembolso,
        valor_cuota, total_intereses, total_iva,
        telemarketing, id_comision_regla, comision_pct, comision_monto, creado_por)
    VALUES (p_nro_prestamo, p_id_cliente, p_id_producto, p_id_vendedor, v_id_tasa,
            p_fecha, p_primer_vto, p_capital, p_plazo, v_tna, v_iva,
            v_punit, v_gastos, p_capital - v_gastos, p_id_cuenta, p_medio_desembolso, NULLIF(TRIM(p_ref_desembolso), ''),
            v_cuota, v_int, v_ivat,
            COALESCE(p_telemarketing, 0), v_id_regla, v_com_pct, v_com_monto, p_id_usuario);

    SET p_id_prestamo = LAST_INSERT_ID();

    INSERT INTO cuotas (id_prestamo, nro_cuota, vencimiento, capital, interes,
                        iva_interes, importe, saldo_capital)
    SELECT p_id_prestamo, nro_cuota, vencimiento, capital, interes,
           iva_interes, importe, saldo_capital
      FROM tmp_cuadro;

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

    CALL sp_fondos_desembolso(p_id_prestamo);

    COMMIT;
END $$

-- sp_anular_prestamo: igual que en financiera_prestamos.sql, más la
-- anulación del desembolso en el libro de fondos.
DROP PROCEDURE IF EXISTS sp_anular_prestamo $$
CREATE PROCEDURE sp_anular_prestamo(
    IN p_id_prestamo INT UNSIGNED,
    IN p_motivo      VARCHAR(200),
    IN p_id_usuario  INT UNSIGNED
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_nro    VARCHAR(20);
    DECLARE v_saldo  DECIMAL(15,2);
    DECLARE v_msg    VARCHAR(255);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_motivo IS NULL OR CHAR_LENGTH(TRIM(p_motivo)) < 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indicar el motivo de la anulación';
    END IF;

    START TRANSACTION;

    SELECT estado, nro_prestamo INTO v_estado, v_nro
      FROM prestamos WHERE id_prestamo = p_id_prestamo FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Préstamo inexistente';
    END IF;
    IF v_estado <> 'VIGENTE' THEN
        SET v_msg = CONCAT('Solo se anula un préstamo vigente (este está ', v_estado, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    IF EXISTS (SELECT 1 FROM pagos WHERE id_prestamo = p_id_prestamo AND estado = 'APLICADO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El préstamo tiene pagos aplicados: anulá primero los pagos';
    END IF;

    UPDATE prestamos
       SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = p_id_usuario, motivo_anulacion = p_motivo
     WHERE id_prestamo = p_id_prestamo;

    SELECT COALESCE(SUM(importe), 0) INTO v_saldo FROM movimientos WHERE id_prestamo = p_id_prestamo;
    IF v_saldo <> 0 THEN
        INSERT INTO movimientos (id_prestamo, fecha, tipo, importe, referencia, creado_por)
        VALUES (p_id_prestamo, CURDATE(), 'ANULACION', -v_saldo, LEFT(CONCAT('Anulación: ', p_motivo), 100), p_id_usuario);
    END IF;

    INSERT INTO comisiones_devengadas (id_vendedor, id_prestamo, id_regla, fecha, concepto, base, porcentaje, importe)
    SELECT id_vendedor, id_prestamo, MAX(id_regla), CURDATE(), 'ANULACION', -SUM(base), MAX(porcentaje), -SUM(importe)
      FROM comisiones_devengadas
     WHERE id_prestamo = p_id_prestamo
     GROUP BY id_vendedor, id_prestamo
    HAVING SUM(importe) <> 0;

    CALL sp_fondos_anular('DESEMBOLSO', p_id_prestamo, p_motivo, p_id_usuario);

    COMMIT;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 12. Menú: Tesorería, después de Inversiones (Administrador y Gerencia;
--     a otros roles se les da en Administración › Roles).
--     Ids 80 a 84: el 60 es Mensajes y el 70 el Panel general. INSERT
--     IGNORE y permisos solo si la opción es la nuestra: un id que ya use
--     otra opción no se pisa (la consulta del final lo muestra).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (80, NULL, 'Tesorería',                    NULL,                      'banco', 5, 1, 1),
 (81, 80,   'Movimientos de fondos',        'movimientos_fondos.php',  NULL,    1, 2, 1),
 (82, 80,   'Movimientos entre cuentas',    'movimientos_cuentas.php', NULL,    2, 2, 1),
 (83, 80,   'Débitos y créditos bancarios', 'debitos_creditos.php',    NULL,    3, 2, 1),
 (84, 80,   'Cuentas de fondos',            'cuentas_fondos.php',      NULL,    4, 2, 1);

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT m.id_menu, r.id_rol FROM menu m JOIN roles r ON r.id_rol IN (1, 2)
 WHERE (m.id_menu = 80 AND m.nombre = 'Tesorería')
    OR (m.padre_id = 80 AND m.url IN ('movimientos_fondos.php', 'movimientos_cuentas.php', 'debitos_creditos.php', 'cuentas_fondos.php'));

SELECT m.id_menu, m.padre_id, m.nombre, m.url, GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu BETWEEN 80 AND 84 GROUP BY m.id_menu ORDER BY m.id_menu;

-- ---------------------------------------------------------------------
-- 13. Controles (tienen que dar cero filas)
-- ---------------------------------------------------------------------

-- 13.1 Los instrumentos suman lo mismo que su renglón del libro.
SELECT 'instrumentos que no suman su renglón' AS control, m.id_mov_fondos, m.fecha, m.importe_debe, m.importe_haber,
       SUM(d.importe_debito) AS inst_debito, SUM(d.importe_credito) AS inst_credito
  FROM movimientos_fondos m
  JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos AND c.tipo = 'B'
  LEFT JOIN debitos_creditos_bancarios d ON d.id_mov_fondos = m.id_mov_fondos AND d.estado <> 'ANULADO'
 WHERE m.anulado_el IS NULL
 GROUP BY m.id_mov_fondos
HAVING ABS(COALESCE(SUM(d.importe_debito), 0)  - m.importe_haber) > 0.01
    OR ABS(COALESCE(SUM(d.importe_credito), 0) - m.importe_debe)  > 0.01;

-- 13.2 Cada movimiento entre cuentas suma lo mismo al debe que al haber.
SELECT 'movimientos entre cuentas desbalanceados' AS control, id_mov_cuentas,
       SUM(importe_debe) AS debe, SUM(importe_haber) AS haber
  FROM movimientos_fondos
 WHERE id_mov_cuentas IS NOT NULL AND anulado_el IS NULL
 GROUP BY id_mov_cuentas
HAVING ABS(SUM(importe_debe) - SUM(importe_haber)) > 0.01;

-- 13.3 Comprobantes con cuenta y sin renglón en el libro.
SELECT 'comprobantes con cuenta y sin libro' AS control, x.origen, x.id
  FROM (SELECT 'COBRANZA' AS origen, g.id_pago AS id FROM pagos g
         WHERE g.id_cuenta_fondos IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.id_pago = g.id_pago)
        UNION ALL
        SELECT 'DESEMBOLSO', p.id_prestamo FROM prestamos p
         WHERE p.id_cuenta_fondos IS NOT NULL AND p.neto_desembolso > 0
           AND NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.id_prestamo = p.id_prestamo)
        UNION ALL
        SELECT 'APORTE', i.id_inversion FROM inversiones i
         WHERE i.id_cuenta_fondos IS NOT NULL AND i.id_inversion_origen IS NULL
           AND NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.id_inversion = i.id_inversion)
        UNION ALL
        SELECT 'PAGO_INVERSOR', l.id_liquidacion FROM inversiones_liquidaciones l
         WHERE l.id_cuenta_fondos IS NOT NULL AND l.estado = 'PAGADA' AND l.a_pagar > 0
           AND NOT EXISTS (SELECT 1 FROM movimientos_fondos m WHERE m.id_liquidacion_inv = l.id_liquidacion)) x;

SELECT id_cuenta_fondos, nombre, tipo, moneda, saldo FROM v_saldos_cuentas_fondos ORDER BY tipo, nombre;
