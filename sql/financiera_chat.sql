-- =====================================================================
--  FINANCIERA - Mensajes internos (chat entre usuarios del sistema)
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_seguridad.sql y financiera_solicitudes.sql, con la base de
--  la financiera seleccionada. Se puede correr más de una vez.
--
--  Tres tipos de conversación, todas con la misma mecánica:
--    DIRECTA ..... entre dos personas. clave_directa = «menor-mayor» de los
--                  dos id_usuario: no puede haber dos chats entre las mismas
--                  dos personas.
--    GRUPO ....... con nombre; la arma cualquiera y elige a los integrantes.
--                  Quien la armó (o un administrador del grupo) agrega y
--                  quita gente.
--    SOLICITUD ... el hilo de una solicitud de préstamo (una por solicitud).
--                  Lo lee y escribe quien puede ver la solicitud; se avisa a
--                  quien la cargó, al vendedor, a quien la resolvió y a todo
--                  el que escribió en el hilo.
--
--  No leídos: cada participante guarda el id del último mensaje que vio
--  (ultimo_leido). La conversación guarda el id de su último mensaje: hay
--  mensajes nuevos si ultimo_mensaje_id > ultimo_leido. Sin marcar mensaje
--  por mensaje, y el contador del encabezado es una sola consulta indexada.
--
--  Adjuntos: el archivo NO va a la base. Queda en la carpeta de documentos
--  (config.php -> docs_dir), en chat\<id conversación>\<32 hex>.<ext>, y se
--  sirve por chat_adjunto.php, que controla que quien lo pide participe.
--
--  Los mensajes no se borran: el autor puede retirarlo (borrado_el) y en
--  la conversación queda «Mensaje eliminado».
--
--  Mensajes de voz: son un adjunto de audio (webm / ogg / m4a) grabado en
--  el navegador, con su duración en adjunto_duracion (los audios grabados
--  por el navegador no siempre la traen adentro).
--
--  Llamadas
--    usuarios.celular .......... el celular de cada usuario (Administración ›
--                                Usuarios, o cada uno en Mis datos), con el
--                                formato de financiera_telefonos.sql. Sin él
--                                se usa el de su vendedor. Con eso la
--                                conversación ofrece Llamar y WhatsApp.
--    chat_mensajes.llamada_url . la sala de una videollamada (Jitsi u otro
--                                servidor: config.php -> video_url). El
--                                mensaje muestra «Unirse».
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS chat_conversaciones (
    id_conversacion   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo              ENUM('DIRECTA','GRUPO','SOLICITUD') NOT NULL,
    nombre            VARCHAR(80)  NULL,                -- solo GRUPO
    clave_directa     VARCHAR(23)  NULL,                -- solo DIRECTA: '3-17'
    id_solicitud      INT UNSIGNED NULL,                -- solo SOLICITUD
    creado_por        INT UNSIGNED NULL,
    creado_el         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_mensaje_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_mensaje_el DATETIME     NULL,
    PRIMARY KEY (id_conversacion),
    UNIQUE KEY uq_chatconv_directa (clave_directa),
    UNIQUE KEY uq_chatconv_solicitud (id_solicitud),
    KEY ix_chatconv_ultimo (ultimo_mensaje_el),
    CONSTRAINT fk_chatconv_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud),
    CONSTRAINT fk_chatconv_creador   FOREIGN KEY (creado_por)   REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_participantes (
    id_conversacion   INT UNSIGNED NOT NULL,
    id_usuario        INT UNSIGNED NOT NULL,
    es_admin          TINYINT(1)   NOT NULL DEFAULT 0,  -- en GRUPO: agrega, quita y renombra
    ultimo_leido      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    silenciada        TINYINT(1)   NOT NULL DEFAULT 0,  -- no suma al contador del encabezado
    agregado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_conversacion, id_usuario),
    KEY ix_chatpart_usuario (id_usuario, id_conversacion),
    CONSTRAINT fk_chatpart_conv    FOREIGN KEY (id_conversacion) REFERENCES chat_conversaciones (id_conversacion) ON DELETE CASCADE,
    CONSTRAINT fk_chatpart_usuario FOREIGN KEY (id_usuario)      REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_mensajes (
    id_mensaje        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_conversacion   INT UNSIGNED NOT NULL,
    id_usuario        INT UNSIGNED NULL,                -- NULL = aviso del sistema («Ana agregó a Luis»)
    texto             TEXT         NULL,
    adjunto_archivo   VARCHAR(255) NULL,                -- ruta relativa a docs_dir: chat/12/ab12….pdf
    adjunto_nombre    VARCHAR(200) NULL,                -- como lo subieron
    adjunto_mime      VARCHAR(100) NULL,
    adjunto_bytes     INT UNSIGNED NULL,
    adjunto_duracion  SMALLINT UNSIGNED NULL,           -- segundos, solo mensajes de voz
    llamada_url       VARCHAR(255) NULL,                -- sala de videollamada
    creado_el         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrado_el        DATETIME     NULL,
    PRIMARY KEY (id_mensaje),
    KEY ix_chatmsj_conv (id_conversacion, id_mensaje),
    UNIQUE KEY uq_chatmsj_archivo (adjunto_archivo),
    CONSTRAINT fk_chatmsj_conv    FOREIGN KEY (id_conversacion) REFERENCES chat_conversaciones (id_conversacion),
    CONSTRAINT fk_chatmsj_usuario FOREIGN KEY (id_usuario)      REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bases creadas con la primera versión de este script: la columna de la
-- duración de los mensajes de voz (MySQL 8 no tiene ADD COLUMN IF NOT EXISTS).
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_mensajes' AND COLUMN_NAME = 'adjunto_duracion');
SET @sql := IF(@falta, 'ALTER TABLE chat_mensajes ADD COLUMN adjunto_duracion SMALLINT UNSIGNED NULL AFTER adjunto_bytes', 'DO 0');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_mensajes' AND COLUMN_NAME = 'llamada_url');
SET @sql := IF(@falta, 'ALTER TABLE chat_mensajes ADD COLUMN llamada_url VARCHAR(255) NULL AFTER adjunto_duracion', 'DO 0');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Celular de los usuarios (para Llamar y WhatsApp desde Mensajes)
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'celular');
SET @sql := IF(@falta, 'ALTER TABLE usuarios ADD COLUMN celular VARCHAR(20) NULL AFTER email', 'DO 0');
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- Menú: «Mensajes» en la raíz, después de Tablas y parámetros, para todos
-- los roles. Quien no la tenga no ve el chat ni el contador del
-- encabezado (el hilo de las solicitudes sí, porque depende de la
-- solicitud).
-- ---------------------------------------------------------------------
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (60, NULL, 'Mensajes', 'chat.php', 'mensaje', 7, 1, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), url = VALUES(url), icono = VALUES(icono),
                        orden = VALUES(orden), nivel = VALUES(nivel);

INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT 60, id_rol FROM roles;

SELECT m.id_menu, m.nombre, m.url, GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu = 60 GROUP BY m.id_menu;
