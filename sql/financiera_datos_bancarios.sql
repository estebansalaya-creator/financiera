-- =====================================================================
--  FINANCIERA - Datos bancarios de clientes, vendedores e inversores
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_nucleo.sql y financiera_inversores.sql, con la base de la
--  financiera seleccionada. Se puede correr más de una vez.
--
--  Contenido
--    clientes.cbu / alias_cbu ...... a dónde se le transfiere el préstamo
--    vendedores.cbu / alias_cbu .... a dónde se le pagan las comisiones
--    inversores.cbu / alias_cbu .... ya estaban (financiera_inversores.sql)
--
--  Formato (lo valida la aplicación: cbu_valido() y alias_cbu_valido() en
--  inc\ui.php; los campos se dibujan con campos_datos_bancarios())
--    CBU / CVU  22 números, sin espacios: los dos dígitos verificadores
--               tienen que coincidir. Un CVU (billetera virtual) empieza
--               con 000.
--    Alias      de 6 a 20 caracteres: letras sin ñ ni acentos, números,
--               punto y guion. Se guarda en mayúsculas.
--  El banco no se guarda: sale de los tres primeros números del CBU
--  (tabla bancos, de financiera_fondos.sql; sin ella la ficha no lo dice).
--
--  Hasta correrlo, las fichas de clientes y vendedores no muestran los
--  campos y todo lo demás sigue andando.
-- =====================================================================

SET NAMES utf8mb4;

-- clientes ------------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'cbu');
SET @ddl := IF(@falta, 'ALTER TABLE clientes ADD COLUMN cbu VARCHAR(22) NULL COMMENT ''CBU o CVU para transferirle el préstamo'' AFTER email', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'alias_cbu');
SET @ddl := IF(@falta, 'ALTER TABLE clientes ADD COLUMN alias_cbu VARCHAR(20) NULL COMMENT ''Alias del CBU / CVU, en mayúsculas'' AFTER cbu', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Para avisar si otro cliente tiene el mismo CBU y para buscar por CBU.
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND INDEX_NAME = 'idx_clientes_cbu');
SET @ddl := IF(@falta, 'ALTER TABLE clientes ADD INDEX idx_clientes_cbu (cbu)', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

-- vendedores ----------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedores' AND COLUMN_NAME = 'cbu');
SET @ddl := IF(@falta, 'ALTER TABLE vendedores ADD COLUMN cbu VARCHAR(22) NULL COMMENT ''CBU o CVU para pagarle las comisiones'' AFTER cuit', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedores' AND COLUMN_NAME = 'alias_cbu');
SET @ddl := IF(@falta, 'ALTER TABLE vendedores ADD COLUMN alias_cbu VARCHAR(20) NULL COMMENT ''Alias del CBU / CVU, en mayúsculas'' AFTER cbu', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Control -------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('clientes', 'vendedores', 'inversores')
   AND COLUMN_NAME IN ('cbu', 'alias_cbu')
 ORDER BY TABLE_NAME, ORDINAL_POSITION;
