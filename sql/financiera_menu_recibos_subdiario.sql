-- =====================================================================
--  FINANCIERA - Menú: Cobros en efectivo, Cobros bancarios, Recibos
--  emitidos y Subdiario de cuentas de fondos
--
--  Correr DESPUÉS de financiera_recibos.sql (que crea la opción 24,
--  Emisión de recibos), con la base de la financiera seleccionada.
--  MySQL 8. Se puede correr más de una vez. En DBeaver: Ejecutar script
--  (Alt+X).
--
--    Cobranzas
--      Cobros en efectivo ....... 1   (antes «Cobrar»: la grilla de los
--                                      préstamos con saldo, y el cobro solo
--                                      en efectivo)
--      Cobros bancarios ......... 2   (antes «Emisión de recibos»: los
--                                      recibos del extracto; ahora con ícono)
--      Recibos emitidos ......... 3   (antes «Pagos y caja»: cobranzas.php
--                                      muestra un renglón por recibo, como
--                                      Recibos emitidos de gestion_adm)
--      Importar lote ............ 4
--    Tesorería
--      Subdiario de cuentas de fondos (antes «Movimientos de fondos»: es el
--      mismo libro, con el nombre y la forma del de gestion_adm)
--
--  Solo cambia nombres, orden e ícono: los permisos (menu_roles,
--  menu_usuarios) quedan como estaban.
-- =====================================================================

UPDATE menu SET nombre = 'Cobros en efectivo', orden = 1 WHERE url = 'cobrar.php';

UPDATE menu
   SET nombre = 'Cobros bancarios',
       orden = 2,
       icono_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none"><path fill="url(#f24g0)" d="M6.75 2h10.5A1.75 1.75 0 0 1 19 3.75V21l-1.75-1.25L15.5 21l-1.75-1.25L12 21l-1.75-1.25L8.5 21l-1.75-1.25L5 21V3.75A1.75 1.75 0 0 1 6.75 2"/><path fill="url(#f24g1)" d="M8.25 6h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1 0-1.5m0 3h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1 0-1.5m0 3h4.5a.75.75 0 0 1 0 1.5h-4.5a.75.75 0 0 1 0-1.5"/><circle cx="17.5" cy="17.5" r="5.5" fill="url(#f24g2)"/><path fill="url(#f24g3)" d="M14.75 17.5a.6.6 0 0 1 .6-.6h3.2l-1.08-1.08a.6.6 0 0 1 .85-.85l2.1 2.1a.6.6 0 0 1 0 .86l-2.1 2.1a.6.6 0 0 1-.85-.85l1.08-1.08h-3.2a.6.6 0 0 1-.6-.6"/><defs><linearGradient id="f24g0" x1="15.2" x2="16.822" y1="2" y2="18.87" gradientUnits="userSpaceOnUse"><stop stop-color="#6ce0ff"/><stop offset="1" stop-color="#4894fe"/></linearGradient><linearGradient id="f24g1" x1="16" x2="8.196" y1="14" y2="5" gradientUnits="userSpaceOnUse"><stop stop-color="#9deaff"/><stop offset="1" stop-color="#fff"/></linearGradient><linearGradient id="f24g2" x1="12.393" x2="19.984" y1="14.063" y2="21.95" gradientUnits="userSpaceOnUse"><stop stop-color="#52d17c"/><stop offset="1" stop-color="#22918b"/></linearGradient><linearGradient id="f24g3" x1="15.313" x2="17.46" y1="15.714" y2="20.296" gradientUnits="userSpaceOnUse"><stop stop-color="#fff"/><stop offset="1" stop-color="#e3ffd9"/></linearGradient></defs></g></svg>'
 WHERE url = 'emision_recibos.php';

UPDATE menu SET nombre = 'Recibos emitidos', orden = 3 WHERE url = 'cobranzas.php';

UPDATE menu SET orden = 4 WHERE url = 'lote.php';

UPDATE menu SET nombre = 'Subdiario de cuentas de fondos' WHERE url = 'movimientos_fondos.php';

-- Control: Cobranzas en orden, con el ícono de Cobros bancarios, y el
-- subdiario con su nombre nuevo.
SELECT m.orden, m.nombre, m.url, m.icono_svg IS NOT NULL AS con_icono
  FROM menu m
 WHERE m.url IN ('cobrar.php', 'emision_recibos.php', 'cobranzas.php', 'lote.php', 'movimientos_fondos.php')
 ORDER BY m.padre_id, m.orden;
