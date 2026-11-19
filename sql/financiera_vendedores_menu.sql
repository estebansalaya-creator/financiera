-- =====================================================================
-- financiera: opción de menú «Vendedores» (ABM de vendedores y sus
-- reglas de comisión), dentro de Comisiones. Para Administrador y Gerencia.
-- Se puede volver a correr.
--   El ícono va con ~ en lugar del numeral y REPLACE lo vuelve a poner:
--   algunos clientes toman el numeral como comentario.
-- =====================================================================

INSERT INTO financiera.menu (id_menu, padre_id, nombre, url, icono, orden, nivel, activo)
VALUES (32, 30, 'Vendedores', 'vendedores.php', 'usuario', 2, 2, 1)
ON DUPLICATE KEY UPDATE padre_id = VALUES(padre_id), nombre = VALUES(nombre), url = VALUES(url),
                        orden = VALUES(orden), nivel = VALUES(nivel);

UPDATE financiera.menu SET icono_svg = REPLACE('<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none"><path fill="url(~f32g0)" d="M4.25 14A2.25 2.25 0 0 0 2 16.25v.25S2 21 8 21s6-4.5 6-4.5v-.25A2.25 2.25 0 0 0 11.75 14z"/><path fill="url(~f32g1)" d="M4.25 14A2.25 2.25 0 0 0 2 16.25v.25S2 21 8 21s6-4.5 6-4.5v-.25A2.25 2.25 0 0 0 11.75 14z"/><path fill="url(~f32g2)" fill-opacity=".75" d="M4.25 14A2.25 2.25 0 0 0 2 16.25v.25S2 21 8 21s6-4.5 6-4.5v-.25A2.25 2.25 0 0 0 11.75 14z"/><path fill="url(~f32g3)" d="M17 12a3 3 0 1 0 0-6a3 3 0 0 0 0 6"/><path fill="url(~f32g4)" d="M8 12a4 4 0 1 0 0-8a4 4 0 0 0 0 8"/><path fill="url(~f32g5)" d="M11 13a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2z"/><path fill="url(~f32g6)" d="M13.75 13a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5zm0 2.75a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5zm0 2.75a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5z"/><defs><linearGradient id="f32g0" x1="4.854" x2="7.41" y1="14.931" y2="21.927" gradientUnits="userSpaceOnUse"><stop offset=".125" stop-color="~BD96FF"/><stop offset="1" stop-color="~9C6CFE"/></linearGradient><linearGradient id="f32g1" x1="8" x2="11.607" y1="13.167" y2="24.721" gradientUnits="userSpaceOnUse"><stop stop-color="~885EDB" stop-opacity="0"/><stop offset="1" stop-color="~E362F8"/></linearGradient><linearGradient id="f32g2" x1="15" x2="9" y1="18" y2="18" gradientUnits="userSpaceOnUse"><stop stop-color="~0A1852" stop-opacity=".75"/><stop offset="1" stop-color="~0A1852" stop-opacity="0"/></linearGradient><linearGradient id="f32g3" x1="15.427" x2="18.485" y1="6.798" y2="11.68" gradientUnits="userSpaceOnUse"><stop offset=".125" stop-color="~9C6CFE"/><stop offset="1" stop-color="~7A41DC"/></linearGradient><linearGradient id="f32g4" x1="5.902" x2="9.98" y1="5.063" y2="11.574" gradientUnits="userSpaceOnUse"><stop offset=".125" stop-color="~BD96FF"/><stop offset="1" stop-color="~9C6CFE"/></linearGradient><linearGradient id="f32g5" x1="14.929" x2="19.143" y1="11" y2="21.407" gradientUnits="userSpaceOnUse"><stop stop-color="~B3E0FF"/><stop offset="1" stop-color="~8CD0FF"/></linearGradient><linearGradient id="f32g6" x1="9.5" x2="20" y1="8.1" y2="20" gradientUnits="userSpaceOnUse"><stop stop-color="~0FAFFF"/><stop offset="1" stop-color="~2764E7"/></linearGradient></defs></g></svg>', '~', CHAR(35)) WHERE id_menu = 32;

INSERT IGNORE INTO financiera.menu_roles (id_menu, id_rol) VALUES (32, 1), (32, 2), (30, 1), (30, 2);

SELECT m.id_menu, m.nombre, m.url, LENGTH(m.icono_svg) AS icono, GROUP_CONCAT(r.nombre) AS roles
  FROM financiera.menu m LEFT JOIN financiera.menu_roles mr ON mr.id_menu = m.id_menu LEFT JOIN financiera.roles r ON r.id_rol = mr.id_rol
 WHERE m.id_menu = 32 GROUP BY m.id_menu;
