-- =====================================================================
--  FINANCIERA - Usuarios, roles, menú y permisos
--  Correr DESPUÉS de financiera_nucleo.sql. MySQL 8. Correr UNA vez
--  (los ALTER no se repiten). La carga del menú y de los roles sí se
--  puede volver a correr: usa ON DUPLICATE KEY UPDATE.
--
--  El mismo esquema que gestion_sindicato (s_usuarios, s_roles,
--  s_usuario_rol, s_menu, s_menu_rol, s_menu_usuario), con los nombres
--  de esta base:
--
--    usuarios          quién entra (correo o usuario + contraseña)
--    roles             perfiles. Administrador es el único que el
--                      sistema conoce por nombre
--    usuarios_roles    los roles de cada usuario
--    menu              el árbol del menú lateral, hasta 4 niveles
--    menu_roles        qué opciones tiene cada rol
--    menu_usuarios     opciones sueltas para una persona, además de
--                      las de sus roles (solo suman, no quitan)
--
--  EL PERMISO ES LA OPCIÓN DE MENÚ
--    No hay otra tabla de permisos que se desincronice de lo que se ve en
--    el menú: una pantalla se puede abrir si el usuario tiene la opción
--    de menú con esa URL. Las pantallas que se abren desde otra (ficha del
--    cliente, del préstamo, recibo...) toman el permiso de su listado: el
--    mapa está en app/inc/seguridad.php (PAGINAS_PERMISO).
--
--  Las ramas de nivel 1 también se asignan: el menú descarta una opción
--  cuya rama no está permitida (no la promueve a raíz). Las pantallas de
--  administración lo hacen solas al guardar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Usuarios: login propio
--    La tabla venía de financiera_nucleo.sql con un rol de texto. El rol
--    pasa a usuarios_roles (un usuario puede tener varios).
-- ---------------------------------------------------------------------

ALTER TABLE usuarios
    MODIFY usuario  VARCHAR(50)  NULL,
    MODIFY email    VARCHAR(150) NOT NULL,
    ADD COLUMN password_hash     VARCHAR(255) NULL AFTER email,
    ADD COLUMN ultimo_acceso     DATETIME     NULL AFTER activo,
    ADD COLUMN intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER ultimo_acceso,
    ADD COLUMN bloqueado_hasta   DATETIME     NULL AFTER intentos_fallidos,
    ADD UNIQUE KEY uq_usuarios_email (email),
    DROP COLUMN rol;

-- ---------------------------------------------------------------------
-- 2. Roles
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id_rol          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(50)  NOT NULL,
    descripcion     VARCHAR(250) NULL,
    prioridad       SMALLINT     NOT NULL DEFAULT 0,     -- orden de los listados (mayor primero)
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_rol),
    UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios_roles (
    id_usuario      INT UNSIGNED NOT NULL,
    id_rol          INT UNSIGNED NOT NULL,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario, id_rol),
    KEY ix_ur_rol (id_rol),
    CONSTRAINT fk_ur_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE,
    CONSTRAINT fk_ur_rol     FOREIGN KEY (id_rol)     REFERENCES roles (id_rol)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. Menú
--    url: el archivo de la pantalla (simulador.php), relativo, porque el
--    sitio puede estar publicado en una subcarpeta de IIS. NULL = rama
--    que solo agrupa. icono: nombre de un ícono de app/inc/iconos.php.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS menu (
    id_menu         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    padre_id        INT UNSIGNED NULL,
    nombre          VARCHAR(100) NOT NULL,
    url             VARCHAR(120) NULL,
    icono           VARCHAR(30)  NULL,
    orden           SMALLINT     NOT NULL DEFAULT 0,
    nivel           TINYINT      NOT NULL DEFAULT 1,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_menu),
    UNIQUE KEY uq_menu_url (url),
    KEY ix_menu_padre (padre_id, orden),
    CONSTRAINT fk_menu_padre FOREIGN KEY (padre_id) REFERENCES menu (id_menu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_roles (
    id_menu         INT UNSIGNED NOT NULL,
    id_rol          INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_menu, id_rol),
    KEY ix_mr_rol (id_rol),
    CONSTRAINT fk_mr_menu FOREIGN KEY (id_menu) REFERENCES menu (id_menu)  ON DELETE CASCADE,
    CONSTRAINT fk_mr_rol  FOREIGN KEY (id_rol)  REFERENCES roles (id_rol)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_usuarios (
    id_usuario      INT UNSIGNED NOT NULL,
    id_menu         INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_usuario, id_menu),
    KEY ix_mu_menu (id_menu),
    CONSTRAINT fk_mu_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE,
    CONSTRAINT fk_mu_menu    FOREIGN KEY (id_menu)    REFERENCES menu (id_menu)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. Roles iniciales. Se editan en Administración > Roles.
-- ---------------------------------------------------------------------

INSERT INTO roles (id_rol, nombre, descripcion, prioridad, activo) VALUES
  (1, 'Administrador', 'Acceso total, incluida la administración del sistema', 100, 1),
  (2, 'Gerencia',      'Todo lo operativo, sin la administración del sistema',  80, 1),
  (3, 'Créditos',      'Clientes, BCRA, simulación y alta de préstamos',        50, 1),
  (4, 'Cobranzas',     'Cobros, recibos, lotes y consulta de préstamos',        40, 1),
  (5, 'Inversiones',   'Inversores, aportes, liquidaciones y cotizaciones',     40, 1),
  (6, 'Comercializador', 'Simulador, clientes y consulta BCRA',                   10, 1)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion),
  prioridad = VALUES(prioridad), activo = VALUES(activo);

-- ---------------------------------------------------------------------
-- 5. Menú. Ids fijos con huecos entre ramas, para agregar opciones sin
--    renumerar. El orden es dentro de cada rama.
-- ---------------------------------------------------------------------

INSERT INTO menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo) VALUES
  (  1, NULL, 'Créditos',                   NULL, 'dinero',    1, 1, 1),
  (  2,    1, 'Simulador',                  'simulador.php',      NULL, 1, 2, 1),
  (  3,    1, 'Nuevo préstamo',             'prestamo_nuevo.php', NULL, 2, 2, 1),
  (  4,    1, 'Préstamos',                  'prestamos.php',      NULL, 3, 2, 1),

  ( 10, NULL, 'Clientes',                   NULL, 'grupo',     2, 1, 1),
  ( 11,   10, 'Clientes',                   'clientes.php',       NULL, 1, 2, 1),
  ( 12,   10, 'Consulta BCRA',              'bcra.php',           NULL, 2, 2, 1),

  ( 20, NULL, 'Cobranzas',                  NULL, 'cobranza',  3, 1, 1),
  ( 21,   20, 'Cobrar',                     'cobrar.php',         NULL, 1, 2, 1),
  ( 22,   20, 'Pagos y caja',               'cobranzas.php',      NULL, 2, 2, 1),
  ( 23,   20, 'Importar lote',              'lote.php',           NULL, 3, 2, 1),

  ( 30, NULL, 'Comisiones',                 NULL, 'etiqueta',  4, 1, 1),
  ( 31,   30, 'Comisiones de comercializadores', 'comisiones.php',     NULL, 1, 2, 1),

  ( 40, NULL, 'Inversiones',                NULL, 'grafico',   5, 1, 1),
  ( 41,   40, 'Aportes',                    'inversiones.php',    NULL, 1, 2, 1),
  ( 42,   40, 'Inversores',                 'inversores.php',     NULL, 2, 2, 1),

  ( 50, NULL, 'Tablas y parámetros',        NULL, 'ajustes',   6, 1, 1),
  ( 51,   50, 'Productos y tasas',          'productos.php',      NULL, 1, 2, 1),
  ( 52,   50, 'Documentación requerida',    'documentos_tipos.php', NULL, 2, 2, 1),
  ( 53,   50, 'Cotizaciones',               'cotizaciones.php',   NULL, 3, 2, 1),

  (200, NULL, 'Administración del Sistema', NULL, 'escudo',  200, 1, 1),
  (201,  200, 'Usuarios',                   'admin_usuarios.php', NULL, 1, 2, 1),
  (202,  200, 'Roles',                      'admin_roles.php',    NULL, 2, 2, 1),
  (203,  200, 'Permisos por usuario',       'admin_permisos.php', NULL, 3, 2, 1),
  (204,  200, 'Menú',                       'admin_menu.php',     NULL, 4, 2, 1)
ON DUPLICATE KEY UPDATE
  padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url),
  icono = VALUES(icono), orden = VALUES(orden), nivel = VALUES(nivel), activo = VALUES(activo);

-- ---------------------------------------------------------------------
-- 6. Opciones de cada rol (con sus ramas)
-- ---------------------------------------------------------------------

-- Administrador: todo.
INSERT IGNORE INTO menu_roles (id_menu, id_rol) SELECT id_menu, 1 FROM menu;

-- Gerencia: todo menos la administración del sistema.
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT id_menu, 2 FROM menu WHERE id_menu <> 200 AND COALESCE(padre_id, 0) <> 200;

-- Créditos: alta y consulta de préstamos, clientes, BCRA, y ver los pagos.
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT id_menu, 3 FROM menu WHERE id_menu IN (1, 2, 3, 4, 10, 11, 12, 20, 22);

-- Cobranzas: cobrar, caja y lotes, con consulta de préstamos y clientes.
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT id_menu, 4 FROM menu WHERE id_menu IN (1, 4, 10, 11, 20, 21, 22, 23);

-- Inversiones: inversores, aportes y cotizaciones.
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT id_menu, 5 FROM menu WHERE id_menu IN (40, 41, 42, 50, 53);

-- Vendedor: simulador, clientes y BCRA. No liquida ni cobra.
INSERT IGNORE INTO menu_roles (id_menu, id_rol)
SELECT id_menu, 6 FROM menu WHERE id_menu IN (1, 2, 10, 11, 12);

-- ---------------------------------------------------------------------
-- 7. Verificación
-- ---------------------------------------------------------------------

SELECT r.nombre AS rol, COUNT(*) AS opciones
  FROM menu_roles mr JOIN roles r ON r.id_rol = mr.id_rol
 GROUP BY r.id_rol, r.nombre ORDER BY MAX(r.prioridad) DESC;

-- Ningún rol puede tener una opción sin su rama: si devuelve filas, esas
-- opciones no se dibujan.
SELECT r.nombre AS rol, m.id_menu, m.nombre AS opcion_huerfana
  FROM menu_roles mr
  JOIN menu m  ON m.id_menu = mr.id_menu
  JOIN roles r ON r.id_rol = mr.id_rol
 WHERE m.padre_id IS NOT NULL
   AND m.padre_id NOT IN (SELECT x.id_menu FROM menu_roles x WHERE x.id_rol = mr.id_rol);
