-- =====================================================================
--  FINANCIERA - Consultas a la Central de Deudores del BCRA
--  MySQL 8 (probado también en MariaDB 10.x). Se puede correr más de una vez.
--
--  La app consulta la API pública del BCRA (sin clave):
--    Deudas/{cuit}               situación del último período informado
--    Deudas/Historicas/{cuit}    últimos 24 meses
--    Deudas/ChequesRechazados/{cuit}
--  y guarda cada respuesta completa (JSON) acá:
--    - respaldo de lo que se vio al aprobar un crédito (la API solo da
--      el presente; dentro de un año no se puede volver a ver);
--    - caché: dentro de las bcra_cache_horas (config.php, 24 por defecto)
--      se muestra lo guardado en vez de volver a pedirlo.
--  Montos de deuda en MILES de pesos, como los informa el BCRA.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS consultas_bcra (
    id_consulta     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuit            CHAR(11)     NOT NULL,
    id_cliente      INT UNSIGNED NULL,               -- NULL: consulta de alguien que todavía no es cliente
    tipo            ENUM('DEUDAS','HISTORICAS','CHEQUES') NOT NULL,
    consultado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consultado_por  INT UNSIGNED NULL,
    http_status     SMALLINT     NOT NULL,           -- 200 con datos, 404 sin registros, otro = error
    respuesta       MEDIUMTEXT   NULL,               -- JSON tal cual lo devolvió el BCRA
    error           VARCHAR(255) NULL,               -- si no hubo respuesta válida
    denominacion    VARCHAR(150) NULL,
    periodo         CHAR(7)      NULL,               -- AAAA-MM más reciente informado
    peor_situacion  TINYINT UNSIGNED NULL,           -- DEUDAS / HISTORICAS: 1 a 5
    deuda_total     DECIMAL(15,2) NULL,              -- DEUDAS: miles de $
    entidades       SMALLINT UNSIGNED NULL,
    cheques         SMALLINT UNSIGNED NULL,          -- CHEQUES: cantidad de rechazos
    cheques_impagos SMALLINT UNSIGNED NULL,          -- CHEQUES: sin fecha de pago
    PRIMARY KEY (id_consulta),
    KEY ix_bcra_cuit_tipo (cuit, tipo, consultado_el),
    KEY ix_bcra_cliente (id_cliente, consultado_el),
    CONSTRAINT fk_bcra_cliente FOREIGN KEY (id_cliente)     REFERENCES clientes (id_cliente),
    CONSTRAINT fk_bcra_usuario FOREIGN KEY (consultado_por) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Última consulta válida (200 o 404) de cada CUIT y tipo.
CREATE OR REPLACE VIEW v_bcra_ultima AS
SELECT b.*
  FROM consultas_bcra b
 WHERE b.http_status IN (200, 404)
   AND b.id_consulta = (SELECT b2.id_consulta FROM consultas_bcra b2
                         WHERE b2.cuit = b.cuit AND b2.tipo = b.tipo AND b2.http_status IN (200, 404)
                         ORDER BY b2.consultado_el DESC, b2.id_consulta DESC LIMIT 1);
