-- =====================================================================
--  FINANCIERA - Solicitudes de préstamo y autorización
--  MySQL 8 (probado también en MariaDB 10.x). Correr después de
--  financiera_nucleo.sql, financiera_seguridad.sql y
--  financiera_prestamos.sql, con la base de la financiera seleccionada.
--  Se puede correr más de una vez.
--
--  Circuito
--    1. SOLICITUD ...... la carga un vendedor (ve solo las suyas) o un
--                        operador. Se simula y se corren los controles
--                        (BCRA, documentación, mora, cuota / ingreso).
--    2. AUTORIZACIÓN ... Gerencia / Administrador (los que tienen la
--                        opción «Autorizaciones»): aprueba, rechaza u
--                        observa (la devuelve con un comentario).
--    3. LIQUIDACIÓN .... un operador liquida la solicitud aprobada el día
--                        del desembolso: recién ahí nace el préstamo.
--
--  Estados
--    PENDIENTE   espera autorización          (se puede modificar o anular)
--    OBSERVADA   devuelta al que la cargó     (al guardarla vuelve a PENDIENTE)
--    APROBADA    lista para liquidar
--    RECHAZADA   final
--    LIQUIDADA   final, con su préstamo (id_prestamo)
--    ANULADA     final (la dio de baja el que la cargó o un operador)
--
--  Contenido
--    solicitudes ............ la solicitud, con lo que dio la simulación y
--                             los controles al cargarla (JSON)
--    solicitudes_historial .. cada paso: quién, cuándo y qué comentó
--    menú 5 y 6 ............. «Solicitudes» (Administrador, Gerencia,
--                             Créditos, Vendedor) y «Autorizaciones»
--                             (Administrador, Gerencia). La opción 3 pasa
--                             a llamarse «Liquidar préstamo».
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS solicitudes (
    id_solicitud    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nro_solicitud   VARCHAR(20)  NOT NULL,              -- S-000001
    id_cliente      INT UNSIGNED NOT NULL,
    id_producto     INT UNSIGNED NOT NULL,
    id_vendedor     INT UNSIGNED NULL,
    telemarketing   TINYINT(1)   NOT NULL DEFAULT 0,
    monto           DECIMAL(15,2) NOT NULL,
    plazo           SMALLINT UNSIGNED NOT NULL,
    destino         VARCHAR(150) NULL,                  -- para qué es el préstamo
    observaciones   VARCHAR(500) NULL,
    -- lo que dio la simulación al cargarla (o al modificarla)
    tna             DECIMAL(9,6)  NULL,
    valor_cuota     DECIMAL(15,2) NULL,
    neto_desembolso DECIMAL(15,2) NULL,
    total_a_pagar   DECIMAL(15,2) NULL,
    controles       TEXT         NULL,                  -- JSON, como prestamos.controles
    controles_el    DATETIME     NULL,
    estado          ENUM('PENDIENTE','OBSERVADA','APROBADA','RECHAZADA','LIQUIDADA','ANULADA') NOT NULL DEFAULT 'PENDIENTE',
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      INT UNSIGNED NULL,
    enviado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- desde cuándo espera autorización
    resuelto_el     DATETIME     NULL,
    resuelto_por    INT UNSIGNED NULL,
    resolucion      VARCHAR(500) NULL,                  -- comentario del que aprobó, rechazó u observó
    id_prestamo     INT UNSIGNED NULL,
    PRIMARY KEY (id_solicitud),
    UNIQUE KEY uq_solicitudes_nro (nro_solicitud),
    UNIQUE KEY uq_solicitudes_prestamo (id_prestamo),
    KEY ix_solicitudes_estado (estado, enviado_el),
    KEY ix_solicitudes_cliente (id_cliente),
    KEY ix_solicitudes_vendedor (id_vendedor),
    KEY ix_solicitudes_creador (creado_por),
    CONSTRAINT fk_sol_cliente  FOREIGN KEY (id_cliente)  REFERENCES clientes (id_cliente),
    CONSTRAINT fk_sol_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto),
    CONSTRAINT fk_sol_vendedor FOREIGN KEY (id_vendedor) REFERENCES vendedores (id_vendedor),
    CONSTRAINT fk_sol_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitudes_historial (
    id_historial    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_solicitud    INT UNSIGNED NOT NULL,
    fecha           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario      INT UNSIGNED NULL,
    accion          ENUM('CREADA','MODIFICADA','REENVIADA','OBSERVADA','APROBADA','RECHAZADA','ANULADA','LIQUIDADA') NOT NULL,
    comentario      VARCHAR(500) NULL,
    PRIMARY KEY (id_historial),
    KEY ix_solhist_solicitud (id_solicitud, id_historial),
    CONSTRAINT fk_solhist_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Menú, dentro de Créditos: Simulador, Solicitudes, Autorizaciones, Liquidar préstamo, Préstamos
INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (5, 1, 'Solicitudes', 'solicitudes.php', 'documento', 2, 2, 1),
       (6, 1, 'Autorizaciones', 'autorizaciones.php', 'ok', 3, 2, 1)
ON DUPLICATE KEY UPDATE padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url),
                        icono = VALUES(icono), orden = VALUES(orden), nivel = VALUES(nivel);

UPDATE menu SET nombre = 'Liquidar préstamo', orden = 4 WHERE id_menu = 3 AND url = 'prestamo_nuevo.php';
UPDATE menu SET orden = 5 WHERE id_menu = 4 AND url = 'prestamos.php';

INSERT IGNORE INTO menu_roles (id_menu, id_rol) VALUES (5, 1), (5, 2), (5, 3), (5, 6), (6, 1), (6, 2);

SELECT m.id_menu, m.nombre, m.url, m.orden, GROUP_CONCAT(r.nombre ORDER BY r.id_rol) AS roles
  FROM menu m LEFT JOIN menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.padre_id = 1 GROUP BY m.id_menu ORDER BY m.orden;
