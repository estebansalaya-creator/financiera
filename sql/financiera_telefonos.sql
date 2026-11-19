-- =====================================================================
--  FINANCIERA - Teléfonos con un solo formato
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_nucleo.sql, con la base de la financiera seleccionada.
--  Se puede correr más de una vez.
--
--  Contenido
--    vendedores.telefono ..... el celular del vendedor (columna nueva)
--
--  Formato (lo arma la aplicación: tel_normalizar() en inc\ui.php)
--    Todos los teléfonos del sistema -clientes (telefonos.numero),
--    vendedores, inversores y empresa- se guardan igual, solo dígitos:
--      celular     5491123456789   se muestra  +54 9 11 2345-6789
--      fijo        541143210000    se muestra  +54 11 4321-0000
--      0800/0810   08005551234     se muestra  0800 555 1234
--      otro país   +59899123456    (con el + adelante)
--    Es el formato que piden wa.me y la API de WhatsApp Cloud, así que un
--    celular guardado sirve tal cual para mandarle un mensaje.
--    Se escriben como se dicen acá: «11 15 2345-6789», «011 4321-0000»,
--    «(0351) 155-551234», «+54 9 351 555-1234». El 15 o el 9 lo hacen
--    celular; si no, lo dice el tipo (Celular / Fijo).
--
--  Datos anteriores
--    Un teléfono cargado antes de este cambio queda como estaba y se
--    muestra tal cual. Toma el formato la próxima vez que se guarde la
--    ficha (si le falta la característica, la ficha la pide).
-- =====================================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedores' AND COLUMN_NAME = 'telefono');
SET @ddl := IF(@falta, 'ALTER TABLE vendedores ADD COLUMN telefono VARCHAR(20) NULL AFTER cuit', 'SELECT 1');
PREPARE st FROM @ddl;
EXECUTE st;
DEALLOCATE PREPARE st;

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedores' AND COLUMN_NAME = 'telefono';
