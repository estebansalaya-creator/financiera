-- =====================================================================
--  FINANCIERA - Cotizaciones de moneda extranjera
--  MySQL 8 (probado también en MariaDB 10.x). Se puede correr más de una vez.
--
--  Fuente: API pública de Estadísticas Cambiarias del BCRA (sin clave)
--    https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones?fecha=
--    https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones/{moneda}?fechadesde=&fechahasta=
--  Se actualiza sola: la tarea programada de Windows corre
--  app\cron\actualizar_cotizaciones.php en los días hábiles, y la pantalla
--  Cotizaciones la actualiza si la última consulta tiene más de unas horas.
--
--  cotizacion  pesos por unidad de la moneda (tipoCotizacion del BCRA)
--  pase        unidades de la moneda por dólar (tipoPase del BCRA)
--  Solo hay cotizaciones de días hábiles: para un feriado o fin de semana
--  se usa la del último día hábil anterior (fn_cotizacion).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cotizaciones (
    fecha           DATE         NOT NULL,
    moneda          CHAR(3)      NOT NULL,
    descripcion     VARCHAR(80)  NULL,
    cotizacion      DECIMAL(18,8) NOT NULL,           -- $ por unidad
    pase            DECIMAL(18,8) NULL,               -- unidades por US$
    fuente          VARCHAR(20)  NOT NULL DEFAULT 'BCRA',
    actualizado_el  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (fecha, moneda),
    KEY ix_cotiz_moneda_fecha (moneda, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada corrida de la actualización (para saber si la tarea anda)
CREATE TABLE IF NOT EXISTS cotizaciones_log (
    id_log          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ejecutado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    origen          VARCHAR(20)  NOT NULL,            -- TAREA, PANTALLA, HISTORICO
    desde           DATE         NULL,
    hasta           DATE         NULL,
    registros       INT UNSIGNED NOT NULL DEFAULT 0,
    ok              TINYINT(1)   NOT NULL,
    mensaje         VARCHAR(255) NULL,
    PRIMARY KEY (id_log),
    KEY ix_cotlog_fecha (ejecutado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
 ('COTIZACIONES_MONEDAS', 'USD,EUR,BRL,UYU,CLP,GBP,CNY,JPY', 'Monedas que se muestran destacadas y se cargan en el histórico'),
 ('COTIZACIONES_HORAS',   '4', 'La pantalla vuelve a consultar al BCRA si la última actualización tiene más de estas horas');

DELIMITER $$

-- ---------------------------------------------------------------------
-- fn_cotizacion: pesos por unidad de p_moneda a p_fecha (o la del último
-- día hábil anterior). ARS = 1. NULL si no hay ninguna anterior.
-- ---------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_cotizacion $$
CREATE FUNCTION fn_cotizacion(p_moneda CHAR(3), p_fecha DATE) RETURNS DECIMAL(18,8)
READS SQL DATA
BEGIN
    DECLARE v DECIMAL(18,8) DEFAULT NULL;
    IF p_moneda = 'ARS' THEN
        RETURN 1;
    END IF;
    SELECT cotizacion INTO v
      FROM cotizaciones
     WHERE moneda = p_moneda AND fecha <= p_fecha
     ORDER BY fecha DESC
     LIMIT 1;
    RETURN v;
END $$

DELIMITER ;
