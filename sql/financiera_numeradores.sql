-- =====================================================================
--  FINANCIERA - Numeradores
--
--  Correr DESPUÉS de financiera_nucleo.sql y ANTES de
--  financiera_ordenes_pago.sql, con la base de la financiera seleccionada.
--  MySQL 8. Se puede correr más de una vez. En DBeaver: Ejecutar script
--  (Alt+X).
--
--  PARA QUÉ
--  ---------------------------------------------------------------------
--  Los números correlativos (la orden de pago 2026-00000001, el pago
--  2026-00000001) salen de una fila por serie de esta tabla, que se toma
--  con bloqueo dentro de la misma transacción que guarda el comprobante:
--
--    - Dos personas a la vez no pueden sacar el mismo número: la segunda
--      espera a que la primera termine (milisegundos) y toma el siguiente.
--      Con «el máximo + 1» de la tabla del comprobante, las dos sacaban el
--      mismo y a una le fallaba la grabación.
--    - Si la transacción se deshace, el número vuelve: no quedan huecos.
--    - No se recorre la tabla del comprobante para buscar el máximo.
--
--  Una serie por año lleva el año en la clave (OP-2026, PAG-2026): el 1°
--  de enero la serie del año nuevo nace sola y arranca en 1.
--
--  sp_numerador(clave, @n)   el próximo número de la serie. No abre ni
--                            cierra transacción: quien lo llama tiene que
--                            estar dentro de una. El bloqueo de la fila dura
--                            hasta su COMMIT o ROLLBACK, así que la
--                            transacción tiene que ser corta (nada de
--                            consultas a servicios externos en el medio).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS numeradores (
    clave           VARCHAR(20)  NOT NULL COMMENT 'Serie: OP-2026, PAG-2026…',
    ultimo          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'El último número dado',
    actualizado_el  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Números correlativos por serie (y año)';

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_numerador $$
CREATE PROCEDURE sp_numerador(IN p_clave VARCHAR(20), OUT p_n INT UNSIGNED)
BEGIN
    -- Un solo INSERT … ON DUPLICATE KEY UPDATE: si la serie no existe la crea
    -- en 1 y, si existe, la incrementa. En los dos casos queda con bloqueo
    -- exclusivo hasta el final de la transacción de quien llama.
    INSERT INTO numeradores (clave, ultimo) VALUES (p_clave, 1)
        ON DUPLICATE KEY UPDATE ultimo = ultimo + 1;
    -- La propia transacción ve lo que acaba de escribir.
    SELECT ultimo INTO p_n FROM numeradores WHERE clave = p_clave;
END $$

DELIMITER ;

SELECT clave, ultimo, actualizado_el FROM numeradores ORDER BY clave;
