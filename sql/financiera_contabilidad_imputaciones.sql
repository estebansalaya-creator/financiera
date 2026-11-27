-- =====================================================================
--  FINANCIERA - Contabilidad: la cuenta contable de cada operación
--
--  Correr DESPUÉS de financiera_contabilidad.sql, financiera_fondos.sql y
--  financiera_gastos.sql, con la base de la financiera seleccionada.
--  MySQL 8. Se puede correr más de una vez. No tiene DELIMITER.
--
--  De dónde va a sacar cada cuenta el generador de asientos (el mismo
--  criterio que el libro de trabajo de gestion_adm: nada se inventa, todas
--  las cuentas están cargadas en algún lado):
--
--    fondos          cuentas_fondos.id_cuenta_contable     (caja, bancos…)
--    gasto cobrado   gastos_conceptos.id_cuenta_contable   (otorgamiento…)
--    lo demás        c_imputaciones_config: por clase de operación, con
--                    la cuenta del DEBE y la del HABER que correspondan
--                    (la pago_imputaciones_config de gestion_adm)
--
--  c_imputaciones_config.subtipo afina la clase: el código del tipo de
--  asociado en las de préstamos (intereses de jubilados a una cuenta y los
--  de empresas a otra, si el estudio lo pide) o la moneda en las de
--  inversores. '' es la general: se usa cuando no hay una del subtipo.
--  Las clases y qué lado lleva cada una están en app\inc\contable.php
--  (CTB_CLASES).
--
--  Un renglón sin cuenta no se manda a una cuenta cualquiera: el asiento
--  sale con el aviso y no se emite (criterio de gestion_adm).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. La cuenta contable en los maestros
-- ---------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'cuentas_fondos' AND column_name = 'id_cuenta_contable');
SET @s := IF(@x = 0, 'ALTER TABLE cuentas_fondos ADD COLUMN id_cuenta_contable INT UNSIGNED NULL COMMENT ''Cuenta del plan (caja, banco…)'',
                        ADD KEY ix_cf_cuenta_contable (id_cuenta_contable),
                        ADD CONSTRAINT fk_cf_cuenta_contable FOREIGN KEY (id_cuenta_contable) REFERENCES c_plan_de_cuentas (id_cuenta)', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @x := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'gastos_conceptos' AND column_name = 'id_cuenta_contable');
SET @s := IF(@x = 0, 'ALTER TABLE gastos_conceptos ADD COLUMN id_cuenta_contable INT UNSIGNED NULL COMMENT ''Cuenta de ingreso del gasto cobrado'',
                        ADD KEY ix_gc_cuenta_contable (id_cuenta_contable),
                        ADD CONSTRAINT fk_gc_cuenta_contable FOREIGN KEY (id_cuenta_contable) REFERENCES c_plan_de_cuentas (id_cuenta)', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. Imputaciones por clase de operación
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS c_imputaciones_config (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    clase               VARCHAR(30)  NOT NULL COMMENT 'LIQUIDACION, INTERES, PUNITORIO, IVA_DEBITO, QUITA, CUOTA_SOCIAL, COMISION, APORTE, INTERES_INVERSOR, COBRANZA_A_IMPUTAR',
    subtipo             VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'Código del tipo de asociado o moneda (vacío = general)',
    nombre              VARCHAR(100) NOT NULL,
    id_cuenta_debe      INT UNSIGNED NULL,
    id_cuenta_haber     INT UNSIGNED NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    observaciones       VARCHAR(255) NULL,
    creado_el           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_imp_clase_subtipo (clase, subtipo),
    KEY ix_imp_debe (id_cuenta_debe),
    KEY ix_imp_haber (id_cuenta_haber),
    CONSTRAINT fk_imp_debe  FOREIGN KEY (id_cuenta_debe)  REFERENCES c_plan_de_cuentas (id_cuenta),
    CONSTRAINT fk_imp_haber FOREIGN KEY (id_cuenta_haber) REFERENCES c_plan_de_cuentas (id_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cuenta del debe y del haber de cada clase de operación (pago_imputaciones_config de gestion_adm)';

-- ---------------------------------------------------------------------
-- 3. Menú: Contabilidad › Imputaciones contables (id 93), con los mismos
--    roles que Plan de cuentas.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
 (93, 90, 'Imputaciones contables', 'imputaciones.php', NULL, 3, 2, 1);

INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT 93, mr.id_rol FROM menu_roles mr
 WHERE mr.id_menu = 91 AND EXISTS (SELECT 1 FROM (SELECT id_menu, url FROM menu) m WHERE m.id_menu = 93 AND m.url = 'imputaciones.php');
