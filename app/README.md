# Financiera · Backoffice (PHP)

PHP 7.4 o superior (probado con 8.3 y corriendo en 8.5), con la extensión `pdo_mysql`. Sin framework ni dependencias externas: el Excel se genera con `inc\xlsx.php` (no necesita la extensión zip) y el PDF con `inc\pdf.php` (Helvetica con acentos, sin librerías).

## Estilo

Usa el sistema de estilos de **gestion_adm** (`static/css/estructura.css` y `componentes.css`): la misma paleta (`--navy #1c5a8f`, `--blue #1a7ac2`), la letra DM Sans, el encabezado azul, tarjetas, indicadores con ícono, botones de color (Excel verde, PDF rojo, Filtrar azul) y grillas con encabezado azul, ⇅ para ordenar, contador, filas por página y paginador. Primera columna «Acciones» con los botones de 28×28 de gestion_adm (ver, PDF, Excel). Si se cambia un componente allá, conviene cambiarlo también en `public\assets\app.css`.

## Estructura

```
app\
  config.example.php   plantilla de conexión → copiar como config.php
  crear_usuario.php    crea un usuario o le cambia la contraseña desde la consola (el primer administrador)
  instalar_iis.ps1/.bat  crea el sitio en IIS (correr como administrador)
  inc\                 no se publica
    bootstrap.php      conexión, formato es-AR, formularios (CSRF, mensajes, números)
    ui.php             piezas de pantalla (título, tarjetas, botones, estados, barra de filtros, paginador, solapas, CUIT, teléfonos)
    comisiones_escalas.php   escalas de comisión y excepciones: el ABM compartido por Tipos de asociado y Vendedores
    layout.php         encabezado y menú lateral;  iconos.php  los íconos de gestion_adm
    seguridad.php      sesión, login, permisos (el esquema de gestion_sindicato)
    admin.php          piezas de Administración del Sistema (árbol, usos, reglas)
    xlsx.php, pdf.php  generadores de Excel y PDF sin librerías
    documentos.php     carpeta, validación y tamaños de la documentación
    bcra.php           API de la Central de Deudores, caché e informe
    prestamos.php      controles al liquidar, simulación, número de préstamo
    solicitudes.php    solicitudes de préstamo: estados, quién ve y autoriza, documentación para aprobar
    empresa.php        datos de la empresa, logo (PNG/JPG → PDF), ícono de la app, WhatsApp
    pwa.php            lo que va en el <head> para instalar la app en el celular
    cobranzas.php      estado de cuenta, registrar pago, medios de pago
    recibos.php        recibos y su emisión desde el extracto por CUIL (cliente, deuda del período, reparto, reglas)
    inversiones.php    modalidades, monedas, cálculo y liquidación de aportes
    cotizaciones.php   API de Estadísticas Cambiarias del BCRA
    fondos.php         Tesorería: tipos de cuenta, orígenes, selector de cuentas, enlaces al comprobante
    extractos.php      extractos bancarios: los formatos del BNA y del ICBC, control de cuenta, duplicados, importación
    ordenes_pago.php   órdenes de pago, pagos emitidos y el archivo de Interbanking (registros *U* y *M*, lotes)
    talonarios.php     talonarios: las reglas de gestion_adm (rango, último emitido, serie de ARCA, lado de la cuenta)
    log_errores.php    log de errores: excepciones sin atajar, fatales, 500 y lo atajado por error_amigable() → tabla log_errores
    ayuda.php          los manuales: lectura de ayuda\*.md, sección de cada pantalla, Markdown simple, búsqueda
  ayuda\               los manuales de uso, uno por módulo (solo texto, Markdown)
  cron\
    actualizar_cotizaciones.php   lo corre la tarea programada (php CLI)
  public\              lo único que sirve IIS
    login.php, logout.php, mi_clave.php   ingreso, salida y cambio de la contraseña propia
    admin_usuarios.php, admin_roles.php, admin_permisos.php, admin_menu.php   Administración del Sistema
    admin_errores.php  Log de errores: pendientes, detalle con la traza y «marcar revisado»
    simulador.php, clientes.php, cliente.php, comisiones.php
    tipos_asociado.php   tipos de asociado (antes «productos»): límites, tasas y escalas de comisión
    productos.php        la dirección vieja: redirige a tipos_asociado.php
    organismos.php       quién paga los haberes (ANSES, IPS…): agrupa las reparticiones
    reparticiones.php    dónde cobra el asociado (ANSES MINIMA 7, IPS 123…), cada una de su organismo
    documento.php      sube, muestra, descarga y da de baja la documentación
    documentos_tipos.php  catálogo: qué documentación se pide y si es obligatoria u opcional para aprobar
    bcra.php           consulta de deudores del BCRA (cualquier CUIT)
    solicitudes.php, solicitud.php, autorizaciones.php   solicitud de préstamo, su ficha y la cola de autorización
    prestamos.php, prestamo.php, prestamo_nuevo.php   listado, ficha y liquidación (de una solicitud aprobada)
    empresa.php, logo.php   datos de la empresa y su logo
    manifest.php, icono.php, sw.js, offline.html   la app instalable (PWA)
    cobranzas.php, cobrar.php, recibo.php, lote.php   recibos emitidos, cobro, recibo y lotes CSV
    emision_recibos.php  recibos desde el extracto bancario, por CUIL (la de planes de gestion_adm)
    inversiones.php, inversion.php, inversores.php, inversor.php   aportes de capital y liquidación de intereses
    movimientos_fondos.php   Tesorería: el subdiario de cuentas de fondos (debe, haber y saldo por cuenta, Excel y PDF)
    movimientos_cuentas.php  depósitos, extracciones, transferencias propias e inversiones (alta, ficha, anulación, PDF)
    debitos_creditos.php     débitos y créditos bancarios: pendientes, cheques en circulación, marcar lo visto en el banco
    extractos_bancarios.php  importar los extractos del banco (archivos o carpeta), historial y el extracto con su saldo
    ordenes_pago.php         órdenes de pago: bandeja a pagar, emisión de a varias o de a una (con cheque), anulación del pago
    transferencias.php       transferencias masivas: generar el archivo de Interbanking, archivos generados, sacar un pago, anular
    cuentas_fondos.php       cuentas de fondos con sus chequeras, bancos y cuenta habitual por medio de cobro
    cotizaciones.php   moneda extranjera (BCRA)
    talonarios.php     talonarios de comprobantes, sus clasificaciones y los tipos de ARCA (el modelo de gestion_adm)
    vendedores.php     ABM de vendedores, zonas y sus reglas de comisión
    ayuda.php          índice de los manuales (busca en todos) y cada manual (?m=)
    assets\app.css     estilos (los de gestion_adm)
    assets\app.js      diálogo de confirmación, renglones, validación de CUIT, formato de teléfonos, menú lateral (y ☰ en el celular), árbol de permisos
    assets\pwa.js      registra el service worker, botón «Instalar app» y aviso para iPhone
    assets\tabla.js    orden por columna y paginación de las grillas del navegador
    web.config         límites de subida de IIS;  .user.ini  los de PHP
documentos\           archivos de la documentación (fuera de app\, no se publica)
ejemplos\extractos\   un extracto de muestra de cada formato para probar la importación (no va al repositorio: *.csv está en .gitignore)
sesiones\             sesiones de PHP (config: sesion_dir)
```

`config.php` e `inc\` quedan fuera de `public\`, así que IIS no los puede servir.

## Puesta en marcha en IIS

1. **PHP:** en `php.ini` tiene que estar `extension=pdo_mysql`.
2. **Usuario de MySQL para la app:** no conviene usar root. Elegí una contraseña y corré:

   ```sql
   CREATE USER 'financiera_app'@'localhost' IDENTIFIED BY 'CAMBIAR';
   GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES
      ON financiera.* TO 'financiera_app'@'localhost';
   ```

   Los procedures corren con los permisos de quien los creó (`SQL SECURITY DEFINER`), así que a la app le alcanza con `EXECUTE` para llamarlos. `CREATE TEMPORARY TABLES` queda por si alguna pantalla arma tablas temporales propias.
3. **Configuración:** copiá `config.example.php` como `config.php` y completá la contraseña.
4. **Sitio:** en el Administrador de IIS, creá un sitio o una aplicación con ruta física `D:\financiera\app\public`, y el handler de PHP que ya usás en tus otros sitios.
5. **Documentación:** corré `sql\financiera_documentos.sql` y dale al grupo de aplicaciones permiso de modificación sobre `D:\financiera\documentos` (`instalar_iis.ps1` lo hace; a mano: `icacls D:\financiera\documentos /grant "IIS AppPool\financiera:(OI)(CI)M"`). Los límites de subida están en `public\.user.ini` (12 MB por archivo, 64 MB por envío) y `web.config`.
6. **Consulta BCRA:** corré `sql\financiera_bcra.sql`. PHP tiene que tener `extension=curl` o `extension=openssl` y el servidor salida a internet hacia `api.bcra.gob.ar`. Si da error de certificado, bajá `cacert.pem` (curl.se/docs/caextract.html) y poné su ruta en `bcra_cainfo` de `config.php`.
7. **Inversiones:** corré `sql\financiera_inversores.sql`.
8. **Cotizaciones:** corré `sql\financiera_cotizaciones.sql` y después `instalar_tarea_cotizaciones.bat` (como administrador): crea la tarea programada que las actualiza de lunes a viernes a las 10:00, 16:30 y 19:30. Para cargar historia la primera vez, en la pantalla *Cotizaciones* → *Cargar histórico* (o `php app\cron\actualizar_cotizaciones.php --historico=365`).
9. **Usuarios:** corré `sql\financiera_seguridad.sql` (roles, menú y permisos), `sql\financiera_menu_iconos.sql` (los íconos en colores) y `sql\financiera_vendedores_menu.sql` (la opción Vendedores) y creá el primer administrador desde la consola:

   ```
   php D:\financiera\app\crear_usuario.php tu@correo "Tu nombre"
   ```

   Pide la contraseña (vacía = genera una y la muestra). Con `--rol=Gerencia` (u otro) crea el usuario con ese rol. Si el correo ya existe, le cambia la contraseña y lo reactiva: sirve también para destrabar a un administrador.
10. **Empresa, teléfonos y solicitudes:** corré `sql\financiera_empresa.sql` (datos de la empresa, logo, opción de menú), `sql\financiera_telefonos.sql` (celular del vendedor) y `sql\financiera_solicitudes.sql` (solicitudes, historial y las opciones Solicitudes y Autorizaciones). Después cargá los datos y el logo en *Tablas y parámetros › Datos de la empresa*.
11. **Mensajes:** corré `sql\financiera_chat.sql` (conversaciones, grupos, hilo de cada solicitud y la opción Mensajes para todos los roles). Los adjuntos quedan en `documentos\chat\<conversación>\`, con los mismos límites que la documentación de clientes. Si ya lo habías corrido, volvé a correrlo: agrega la duración de los mensajes de voz (se puede correr las veces que haga falta). Los mensajes de voz necesitan **https** (el navegador no deja usar el micrófono en `http://`, salvo en `localhost`). El mismo script agrega `usuarios.celular` (Llamar y WhatsApp desde Mensajes) y la sala de las videollamadas; el servidor de video es `video_url` de `config.php` (por defecto `https://meet.jit.si/`; en Railway, variable `FIN_VIDEO_URL`).
12. **Panel general:** corré `sql\financiera_panel.sql` (la opción de menú, primera de la raíz, para Administrador y Gerencia). La pantalla no tiene tablas propias.
13. **Tesorería:** corré `sql\financiera_fondos.sql` (cuentas de fondos, chequeras, bancos, el libro de movimientos de fondos y los débitos y créditos bancarios; la opción de menú Tesorería para Administrador y Gerencia). **Redefine** `sp_registrar_pago`, `sp_anular_pago`, `sp_liquidar_prestamo` y `sp_anular_prestamo`: si después se vuelve a correr `financiera_cobranzas.sql`, `financiera_prestamos.sql`, `financiera_telemarketing_comisiones.sql` o `financiera_tablas_temporales.sql`, hay que correr este otra vez. Crea una *Caja en pesos* para el efectivo; las demás cuentas (bancos con su CBU, billeteras, inversiones) se cargan en *Tesorería › Cuentas de fondos*. Hasta correrlo, cobrar, liquidar y los aportes siguen como antes, sin cuenta de fondos.
14. **Datos bancarios:** corré `sql\financiera_datos_bancarios.sql` (CBU / CVU y alias de clientes y vendedores; los inversores ya los tenían). Hasta correrlo, las fichas no muestran esos campos. El banco de cada CBU sale de la tabla `bancos` del paso 13.
15. **Extractos bancarios:** corré `sql\financiera_extractos.sql` (después del paso 13): las tablas de los extractos importados y su historial, y la opción *Tesorería › Extractos bancarios*. Lee los extractos de Banco Nación (clásico y Banca Empresas) y del ICBC (cuenta corriente y recaudaciones). Cada cuenta bancaria necesita su **número de cuenta** en *Cuentas de fondos*: con eso se controla que el archivo sea de esa cuenta.
16. **Numeradores:** corré `sql\financiera_numeradores.sql`: la tabla de las series de números (una por año: `OP-2026`, `PAG-2026`) y `sp_numerador`, que da el próximo número con bloqueo dentro de la transacción del comprobante (dos a la vez no sacan el mismo y no quedan huecos).
17. **Órdenes de pago y transferencias masivas:** corré `sql\financiera_ordenes_pago.sql` (después de los pasos 13, 14 y 16): órdenes de pago, pagos emitidos, archivos de transferencias y el origen *Orden de pago* en el libro de fondos; las opciones *Tesorería › Órdenes de pago* y *Transferencias masivas*. **Redefine** `sp_fondos_renglon`, `sp_liquidar_prestamo` y `sp_anular_prestamo`: si se vuelve a correr `financiera_fondos.sql`, después hay que correr este. Desde ahí, liquidar un préstamo crea su orden de pago y el desembolso sale del libro al emitir el pago; las transferencias se mandan en un archivo de Interbanking (PIL, por CBU). Las órdenes y los pagos se numeran por año: `2026-00000001`.
18. **Solicitudes, número por año y carga simultánea:** corré `sql\financiera_solicitudes_concurrencia.sql` (después del paso 16): la versión de la solicitud (una decisión sobre algo que cambió mientras se miraba no se aplica) y el número por año, `2026-00000001`. **Renumera las solicitudes que ya existen** (S-000001 → 2026-00000001, por año de carga): si no se quiere, salteá su sección 3. Desde ahí el alta y la modificación se graban en una transacción que bloquea solo al cliente: una solicitud igual a otra sin resolver del mismo cliente no se carga y las demás en curso salen como advertencia.
19. **Talonarios:** corré `sql\financiera_talonarios.sql`: el nomenclador de tipos de comprobante de ARCA, los tipos contables (al debe o al haber), las clasificaciones y los talonarios, y la opción *Tablas y parámetros › Talonarios*. Un talonario con tipo de ARCA queda marcado para pedir CAE (uno por punto de venta, tipo y ambiente); la emisión con CAE todavía no está.
20. **Recibos con número de talonario:** corré `sql\financiera_recibos_talonario.sql` (después de los pasos 13 y 19): el pago guarda su talonario y su número, y **redefine** `sp_registrar_pago` (si se vuelve a correr `financiera_fondos.sql`, después hay que correr este). El recibo sale `X 00001-00000001` del talonario de recibos habilitado (con varios, se elige al cobrar o al importar el lote); sin ninguno, `R-00000123` como antes. El cobro corre en READ COMMITTED: dos cobros a la vez ya no se traban.
21. **Recibos y emisión desde el extracto:** corré `sql\financiera_recibos.sql` (después de los pasos 15, 17 y 20): el recibo pasa a ser la cabecera (`recibos`) con un pago por préstamo, el libro de fondos lleva un renglón por recibo (origen `RECIBO`) y la opción *Cobranzas › Emisión de recibos* (hoy *Cobros bancarios*, paso 22). Los pagos que ya existen quedan con su recibo (uno por pago, con su número y su renglón de antes). **Redefine** `sp_fondos_renglon`, `sp_registrar_pago` y `sp_anular_pago`: si se vuelve a correr `financiera_ordenes_pago.sql` o `financiera_recibos_talonario.sql`, después hay que correr este.
22. **Cobros en efectivo, Recibos emitidos y Subdiario:** corré `sql\financiera_menu_recibos_subdiario.sql` (después del paso 21): *Cobranzas › Cobrar* pasa a llamarse **Cobros en efectivo**, *Emisión de recibos* pasa a llamarse **Cobros bancarios**, *Cobranzas › Pagos y caja* pasa a llamarse **Recibos emitidos** (un renglón por recibo, como en gestion_adm), Cobros bancarios lleva su ícono y queda al lado, y *Tesorería › Movimientos de fondos* pasa a llamarse **Subdiario de cuentas de fondos**. Solo nombres, orden e ícono: los permisos quedan como estaban.
23. **Tipos de asociado, reparticiones y escalas de comisión:** corré `sql\financiera_tipos_asociado.sql` (después del paso 22 y de `carga_comisiones_2026_06.sql`): `productos` pasa a llamarse `tipos_asociado` (y `id_producto` → `id_tipo_asociado` en préstamos, solicitudes, tasas, reglas y comisiones importadas), crea `organismos` (6) y `reparticiones` con las 27 de las planillas, cada una de su organismo, suma al cliente el tipo habitual y la repartición, convierte las reglas de comisión en escalas por tipo y monto con % normal y % con telemarketing (las diferencias por vendedor quedan como excepción) y **redefine** `fn_tasa_vigente`, `fn_comision_regla`, `sp_simular_prestamo` y `sp_liquidar_prestamo`. *Productos y tasas* pasa a ser **Tipos de asociado y tasas** (`tipos_asociado.php`) y se agregan **Organismos** y **Reparticiones** con los mismos permisos. Después de este no se vuelven a correr `financiera_nucleo.sql`, `financiera_fondos.sql`, `financiera_ordenes_pago.sql`, `financiera_tablas_temporales.sql` ni `financiera_telemarketing_comisiones.sql`: usan la tabla `productos`. Al final muestra las escalas, y los controles 8.2 y 8.3 tienen que dar cero filas.
24. **Conceptos de gasto:** corré `sql\financiera_gastos.sql` (después del paso 23): crea `gastos_conceptos` (catálogo), `gastos_conceptos_tipos` (los gastos de cada tipo de asociado con sus valores) y `prestamos_gastos`, suma `prestamos.gastos_financiados`, pasa el % de gastos de otorgamiento de cada tipo de asociado (si había) al concepto *Gastos de otorgamiento* de ese tipo, agrega **Conceptos de gasto** al menú debajo de Tipos de asociado (mismos roles) y **redefine** `sp_simular_prestamo` y `sp_liquidar_prestamo`. Si se vuelve a correr `financiera_tipos_asociado.sql`, después hay que correr este.
25. **Log de errores:** corré `sql\financiera_log_errores.sql` (después del paso 9): la tabla `log_errores` y la opción *Log de errores* en Administración del Sistema, para los roles que tienen Usuarios. Desde ahí se ven las excepciones sin atajar, los errores fatales, los 500 y lo que las pantallas atajan con `error_amigable()`, con usuario, URL, hora y traza (`inc\log_errores.php`). Sin la tabla todo anda igual: el error queda solo en `documentos\logs\php_errores.log`.
26. Abrí el sitio: pide ingresar y después entra a la primera pantalla del menú del usuario.

**Datos de ejemplo — solo en una base de prueba:** `sql\financiera_datos_ejemplo.sql` (después del paso 23) **borra** clientes, solicitudes, préstamos, cobranzas, movimientos de fondos, órdenes de pago, extractos e inversiones, y carga 20 clientes, 20 préstamos de 3 a 60 cuotas con sus desembolsos y cobros, recibos (uno anulado), 6 aportes de inversores y 4 solicitudes sin liquidar, todo con los procedures del sistema. No se corre en Railway ni en una base con datos reales. Crea dos procedures y tablas `tmp_ej_*` que borra al final: hace falta un usuario con permiso para crearlos (el de la app no lo tiene).

Para ver el detalle de un error de base durante el desarrollo, poné `'debug' => true` en `config.php`. En producción dejalo en `false`, porque si no los mensajes de MySQL se muestran en pantalla.

Los errores y avisos de PHP van a `documentos\logs\php_errores.log` cuando PHP no tiene `error_log` configurado: con IIS (FastCGI), lo que PHP registra sin archivo sale por stderr e IIS contesta un error 500 con ese texto en lugar de la página.

## Módulos

| Módulo | Archivo | Estado | Fuente |
|---|---|---|---|
| Simulador | `simulador.php` | Listo | `sp_simular_prestamo` (el mismo motor que la liquidación) |
| Clientes | `clientes.php`, `cliente.php`, `documento.php` | Listo | `clientes`, `clientes_personas`, `clientes_empresas`, `domicilios`, `telefonos`, `clientes_documentos` |
| Solicitudes y autorización | `solicitudes.php`, `solicitud.php`, `autorizaciones.php` | Listo | `solicitudes`, `solicitudes_historial`, `documentos_tipos.obligatorio` |
| Datos de la empresa | `empresa.php`, `logo.php`, `manifest.php`, `icono.php` | Listo | `empresa` (una fila: datos, logo, ícono de la app, WhatsApp) |
| Préstamos | `prestamos.php`, `prestamo.php`, `prestamo_nuevo.php` | Listo | `sp_simular_prestamo`, `sp_liquidar_prestamo`, `sp_estado_cuenta`, `sp_anular_prestamo` |
| Cobranzas | `cobranzas.php`, `cobrar.php`, `recibo.php`, `lote.php`, `emision_recibos.php` | Listo | `sp_estado_cuenta`, `sp_registrar_pago` (número del talonario, READ COMMITTED), `sp_emitir_recibo` (un crédito del extracto, todos los préstamos del cliente), `sp_anular_recibo`, `recibos`, `cobros_cuil_reglas`, `lotes_cobranza` |
| Consulta BCRA | `bcra.php` (y en la ficha del cliente) | Listo | API pública de la Central de Deudores → `consultas_bcra` |
| Inversiones | `inversiones.php`, `inversion.php`, `inversores.php`, `inversor.php` | Listo | `sp_inversion_calcular`, `sp_liquidar_inversion`, `sp_anular_liquidacion_inversion`, `v_inversiones_devengado` |
| Cotizaciones | `cotizaciones.php`, `cron\actualizar_cotizaciones.php` | Listo | API de Estadísticas Cambiarias del BCRA → `cotizaciones`, `fn_cotizacion` |
| Tesorería | `movimientos_fondos.php`, `ordenes_pago.php`, `transferencias.php`, `movimientos_cuentas.php`, `debitos_creditos.php`, `extractos_bancarios.php`, `cuentas_fondos.php` | Listo | El modelo de gestion_adm: `cuentas_fondos`, `chequeras`, `bancos`, `movimientos_entre_cuentas`, `movimientos_fondos` (libro) → `debitos_creditos_bancarios`, `v_saldos_cuentas_fondos`; registradores `sp_fondos_*`; extractos: `inc\extractos.php` (los formatos del BNA y del ICBC) → `extractos_importaciones`, `extractos_bancarios`; órdenes de pago: `ordenes_pago` → `pagos_emitidos` (libro, origen `PAGO_OP`) → `transferencias_lotes` (Interbanking), `sp_emitir_pago_op`, `sp_anular_pago_op` |
| Comisiones | `comisiones.php` | Listo | `comisiones_importadas` (historial de las planillas) |
| Conceptos de gasto | `gastos_conceptos.php`, solapa Gastos de `tipos_asociado.php` | Listo | `gastos_conceptos`, `gastos_conceptos_tipos` (valores por tipo), `prestamos_gastos`; `sp_gastos_calcular` |
| Tipos de asociado y tasas | `tipos_asociado.php`, `organismos.php`, `reparticiones.php` | Listo | `tipos_asociado` (antes `productos`), `tasas_vigencia`, `comision_reglas` (escalas), `organismos` → `reparticiones`; `fn_tasa_vigente`, `fn_comision_regla` |
| Talonarios | `talonarios.php` | Listo (sin emisión con CAE todavía) | El modelo de gestion_adm: `comprobantes_tipos` → `talonarios_clasificaciones` → `talonarios`; `arca_tipos_comprobante` (nomenclador); `sp_talonario_numero` reserva el próximo número propio con bloqueo |
| Usuarios, roles y menú | `login.php`, `admin_*.php` | Listo | `usuarios`, `roles`, `usuarios_roles`, `menu`, `menu_roles`, `menu_usuarios` |

### Usuarios, roles y menú

El mismo esquema que gestion_sindicato:

- **El permiso es la opción de menú.** Una pantalla se abre si el usuario tiene la opción del menú con esa URL. No hay otra tabla de permisos que se desincronice de lo que se ve.
- Lo que tiene una persona es la **suma** de las opciones de sus roles (`menu_roles`) y de sus opciones sueltas (`menu_usuarios`). Una opción suelta solo agrega.
- Las pantallas que se abren desde otra toman el permiso de su listado (`PAGINAS_PERMISO` en `inc\seguridad.php`): la ficha del cliente con Clientes, la del préstamo con Préstamos, Cobrar o Pagos, el recibo con Cobrar o Pagos, la ficha del aporte con Aportes, la del inversor con Inversores o Aportes; `productos.php` (la dirección vieja) con Tipos de asociado. Una pantalla nueva que no esté en el menú ni en ese mapa no se puede abrir.
- Roles y opciones se vuelven a leer cada minuto: un cambio de permisos le llega a quien ya está adentro, sin salir y entrar. Si lo dan de baja, lo saca.
- Login con correo (o usuario) y contraseña (`password_hash`). 5 intentos fallidos seguidos bloquean 15 minutos; un administrador lo desbloquea desde Usuarios. La sesión se cierra sola tras `sesion_minutos` sin uso (480).
- Roles iniciales: Administrador, Gerencia, Créditos, Cobranzas, Inversiones y Vendedor. Se editan, se crean y se copian en **Roles**. Administrador es el único que el sistema reconoce por nombre.
- Reglas para no quedar afuera: siempre hay al menos un administrador activo; nadie se da de baja ni se saca el rol Administrador a sí mismo; Administrador no se desactiva ni pierde Usuarios, Roles y Menú, y esas tres opciones no se desactivan, no se eliminan ni cambian de URL.
- Un usuario que ya operó (préstamos, pagos, clientes…) no se elimina, se da de baja: dónde figura sale de las claves foráneas de la base.
- Las operaciones guardan quién las hizo: `creado_por`, `anulado_por`, `subido_por`, `baja_por`, `consultado_por` y el `p_id_usuario` de los procedures.
- **Íconos en colores**, como gestion_sindicato: Fluent UI System Color Icons (los mismos SVG), uno por opción en todos los niveles, en `menu.icono_svg` (`sql\financiera_menu_iconos.sql`). Pintan con degradados por `id`, así que cada vez que se dibujan se les renombran los `id` (`svg_unico` en `inc\iconos.php`): el mismo ícono puede salir dos veces en una página. En el ABM del menú se pega el SVG (sin `script`, `on…=` ni `javascript:`, hasta 6.000 caracteres); sin SVG se usa el ícono simple de `iconos.php`.
- **Menú** arma el árbol (hasta 4 niveles): ramas con ícono y pantallas. Una opción nueva la recibe el Administrador; a los demás roles se les asigna en Roles. Si la URL no corresponde a un archivo de `public\`, la lista lo marca *no existe*.

### Tipos de asociado y tasas

- El tipo de asociado (NACION, REGGIO DC, HABERES ACTIVOS…, los de las planillas de comisiones) reemplaza a lo que se había cargado como «productos» (`sql\financiera_tipos_asociado.sql`: renombra la tabla y las columnas `id_producto` → `id_tipo_asociado`). El cliente tiene uno habitual (`clientes.id_tipo_asociado`); la solicitud lo trae y se puede cambiar, y la solicitud y el préstamo guardan el suyo.
- Configuración de cada tipo: plazo y monto mínimo y máximo e IVA sobre intereses. Sistema francés. Los gastos se cargan en **Conceptos de gasto** (`gastos_conceptos.php`, ver abajo); `tipos_asociado.gasto_otorg_pct` ya no se usa (queda en cero).
- **Conceptos de gasto** (`sql\financiera_gastos.sql`): `gastos_conceptos` es el catálogo (nombre, IVA y valores sugeridos, en `gastos_conceptos.php`); `gastos_conceptos_tipos` es lo que cobra cada tipo de asociado con sus propios valores (solapa *Gastos* de `tipos_asociado.php`, con *Copiar de otro tipo*): importe fijo o % del capital (con mínimo y máximo opcionales), `DESCUENTO` del desembolso o `FINANCIADO` en las cuotas (se suma al capital del cuadro francés). `sp_gastos_calcular` arma `tmp_gastos` para el tipo; `sp_simular_prestamo` lo devuelve como tercer resultado y `sp_liquidar_prestamo` lo congela en `prestamos_gastos`. En el préstamo, `gastos_otorg` es lo descontado, `gastos_financiados` lo financiado (movimiento `GASTO_FINANC`), `neto_desembolso = capital - gastos_otorg` y la suma del capital de las cuotas es `capital + gastos_financiados`.
- La ficha del tipo (`tipos_asociado.php?id=N&solapa=datos|tasas|escalas|gastos|excepciones`) tiene cinco solapas; un tipo nuevo sigue en *Tasas* con el formulario de la primera abierto. Un tipo en uso se desactiva; sin clientes, solicitudes ni préstamos se puede eliminar (con sus tasas y escalas).
- **Las tasas no se editan**: se carga una nueva con su fecha de vigencia y su rango de plazo y monto. Rige la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos. Una tasa se puede borrar solo si ningún préstamo la usó.
- La punitoria vacía toma el parámetro general `PUNITORIO_PCT_TNA` (50 % de la TNA).
- Sin tasa vigente, un tipo no se puede simular ni liquidar: el listado lo avisa.
- **Escalas de comisión** (`comision_reglas` sin vendedor, `inc\comisiones_escalas.php`): por tipo de asociado y tramo del monto del préstamo, cada una con su % y, opcional, su % con telemarketing (`porcentaje_tm`). Si queda NULL, con telemarketing se cobra el % por la fracción del tipo de asociado (`tipos_asociado.pct_telemarketing`, configurable en la ficha del tipo: 1 = el mismo, 0,5 = la mitad), base y vigencia. Dos escalas activas no pueden cubrir el mismo monto en las mismas fechas; la ficha avisa los montos sin escala. La **excepción** de un vendedor es la misma fila con su `id_vendedor` y gana sobre la general (`fn_comision_regla` ordena primero las del vendedor). `sp_liquidar_prestamo` toma el % de telemarketing si la venta vino por ahí, y la liquidación muestra antes la comisión que va a tocar (`comision_estimada`).
- Las 36 reglas de junio de 2026 se convirtieron sin borrar nada: la general de cada tipo es el par de % (normal y telemarketing) de la mayoría de las operaciones; las de los vendedores iguales quedaron inactivas y las distintas, como excepción. Las 180 comisiones importadas de junio dan el mismo % recalculadas con las escalas (control 8.3 del script).
- **Organismos** (`organismos.php`) y **Reparticiones** (`reparticiones.php`): las 27 reparticiones de las planillas, cada una de su organismo (`reparticiones.id_organismo`: ANSES, IPS, Pensiones graciables, Ministerio de Educación, Policía, Caja de Policía). Grillas con el formato de todas: formulario arriba (alta y edición), lista con buscador, filtros, contador y filas por página; deshabilitar en vez de eliminar lo que está en uso. La repartición se elige en la ficha del cliente (`clientes.id_reparticion`) y no depende del tipo: la misma aparece con varios tipos. `comisiones_importadas.id_reparticion` las vincula con el historial.

### Simulador

- Tipo de asociado, monto, cuotas, fecha de liquidación y primer vencimiento (vacío: un mes después). Valida con los mismos límites del tipo que la liquidación.
- Muestra primera cuota, total a pagar, neto a recibir, TNA, TEM, TEA y **CFT con IVA** (TIR de lo que se recibe contra lo que se paga, en períodos mensuales: aproximado al CFT del BCRA, que cuenta días).
- **Generar PDF** para entregar al cliente y **Exportar a Excel** con el cuadro.

### Clientes

- Listado paginado en el servidor (25 a 200 por página) con búsqueda por CUIT, DNI, nombre o razón social y filtros por tipo, estado y vendedor. **Exportar a Excel** trae todo el resultado del filtro.
- Ficha de persona física o empresa en el mismo formulario. El CUIT/CUIL se valida (dígito verificador y prefijo de persona o empresa) y el DNI tiene que coincidir con el CUIL. Domicilios y teléfonos en renglones (el primero es el principal). Avisa si el teléfono ya lo tiene otro cliente.
- Muestra los préstamos del cliente.
- **Documentación** (en la ficha, una vez creado el cliente):
  - Lista de obligatorios según sea persona o empresa, cada uno *al día*, *vencido* o *falta*. El catálogo está en `documentos_tipos` (qué se pide, a quién, si es obligatorio y cuántos días dura); se cambia desde la base.
  - Subir uno o varios archivos de un mismo documento (frente y dorso): PDF, JPG, PNG o WEBP, hasta 10 MB cada uno (`docs_max_mb`). El tipo se reconoce por el contenido, no por la extensión. No deja cargar dos veces el mismo archivo al mismo cliente.
  - Si no se pone vencimiento y el documento tiene vigencia (recibo 60 días, servicio 90, balance 540), se calcula desde la emisión o desde hoy.
  - Ver en otra pestaña, descargar y **dar de baja** (no se borra: el archivo se conserva y se puede reactivar).
  - Los archivos quedan en `D:\financiera\documentos\clientes\<n° de cliente>\` con nombre al azar; solo se ven a través de `documento.php`.
- **Qué se pide** (`documentos_tipos.php`, botón en la tarjeta): nombre, a quién (personas, empresas o ambos), obligatorio, vigencia en días, orden y activo. Lo ya usado no se borra, se desactiva.
- El listado muestra el estado de la documentación y filtra por *Completa* / *Incompleta*; el Excel trae la columna.

### Consulta BCRA

- Los tres informes públicos de la Central de Deudores: **deuda actual** (entidad, situación 1 a 5, monto, días de atraso, observaciones), **historial de 24 meses** (grilla entidad × mes coloreada por situación; el monto se ve al pasar el mouse) y **cheques rechazados** (causal, monto, si se pagó, multa).
- Tarjetas arriba: peor situación actual, deuda total, peor situación en 24 meses y cheques sin pagar.
- En la ficha del cliente (tarjeta *Central de Deudores*) y en **Consulta BCRA** del menú, para cualquier CUIT aunque todavía no sea cliente (si después se da de alta, la consulta ya queda asociada por CUIT).
- Cada respuesta se guarda completa en `consultas_bcra`: queda como respaldo de lo que se vio al aprobar, y durante 24 horas (`bcra_cache_horas`) se muestra lo guardado en vez de volver a consultar. **Actualizar** fuerza la consulta.
- El BCRA informa la deuda en miles de pesos; la pantalla la muestra en pesos.
- **Generar PDF** del informe (tarjetas, deuda actual, grilla de 24 meses en colores y cheques), desde la ficha o desde Consulta BCRA. **Guardar en documentación** lo agrega al legajo del cliente como «Informe Central de Deudores (BCRA)», con la fecha de la consulta; la misma consulta no se guarda dos veces.

### Solicitudes y autorización

Circuito: **solicitud** → **autorización** → **liquidación**. El préstamo nace recién al liquidar.

- **Solicitud** (`solicitud.php`): cliente, tipo de asociado (el habitual del cliente), monto, cuotas, vendedor, destino y observaciones. *Revisar* simula a hoy y corre los controles; *Enviar a autorización* la deja `PENDIENTE` con número de año y orden, `2026-00000001`… Un control que bloquea impide cargarla; las advertencias no.
- **Quién ve qué**: con «Autorizaciones» o «Préstamos» en el menú, todas; el resto (rol Vendedor), las que cargó y las de su vendedor (`vendedores.id_usuario`). Un vendedor carga siempre a su nombre.
- **Autorización** (`autorizaciones.php`, Gerencia y Administrador): aprobar, observar (vuelve al que la cargó; al corregirla vuelve a `PENDIENTE`) o rechazar. Observar y rechazar piden comentario; aprobar con advertencias también.
- **Documentación**: `documentos_tipos.obligatorio` dice si un documento es obligatorio u opcional **para aprobar**. Sin todos los obligatorios cargados y sin vencer no se aprueba (se mira en el momento). Los opcionales se muestran y no frenan.
- **Estados**: `PENDIENTE`, `OBSERVADA`, `APROBADA`, `RECHAZADA`, `LIQUIDADA`, `ANULADA`. Cada cambio es un `UPDATE … WHERE estado = el esperado` (si dos resuelven a la vez, gana uno) y deja un renglón en `solicitudes_historial`.
- **Liquidar** (`prestamo_nuevo.php?solicitud=N`): los datos vienen de la solicitud y no se cambian; se elige fecha, primer vencimiento y comisión manual. Las advertencias no se vuelven a confirmar: el motivo guardado en el préstamo es la aprobación. La solicitud se toma (`LIQUIDADA`) antes de llamar a `sp_liquidar_prestamo` y se devuelve a `APROBADA` si la liquidación falla.
- **No hay liquidación directa**: sin `?solicitud=N` de una aprobada, `prestamo_nuevo.php` lleva a las solicitudes aprobadas, sea quien sea el usuario.

### Datos de la empresa, logo y app instalable

- **Datos** (`empresa.php`, tabla `empresa`, una fila): razón social, fantasía, CUIT, IVA, IIBB, domicilio, contacto. Salen en el encabezado, en el ingreso y en todos los PDF (`PdfReporte` los toma solo).
- **Logo**: PNG o JPG hasta 1 MB, guardado en la base (sale igual en IIS y en Railway). Para los PDF se prepara al subirlo (`logo_preparar`: el PNG con transparencia se separa en color + máscara, sin GD). Lo sirve `logo.php`.
- **App instalable (PWA)**: `manifest.php` arma el manifiesto con el nombre y los íconos; `sw.js` no guarda pantallas con datos (solo `offline.html` y el ícono). Con logo cargado, el navegador arma los íconos (32, 180, 192, 512 y maskable) al guardar y los sirve `icono.php`. Solo se ofrece instalar por HTTPS.
- **WhatsApp Business**: número, saludo (`{cliente}`, `{empresa}`) y Phone Number ID / WABA ID de la API de WhatsApp Cloud. El token va en `config.php` (`whatsapp_token`) o `FIN_WA_TOKEN`. Por ahora solo se usan el número y el saludo (links `wa.me`); no hay envío automático.
- **Teléfonos**: un solo formato en todo el sistema (`tel_normalizar` / `tel_fmt` en `ui.php`, y lo mismo en `app.js`): `5491123456789` celular, `541143210000` fijo, `+…` otro país.
- **Hora**: la conexión fija `time_zone = '-03:00'`, para que `NOW()` y `CURDATE()` den hora argentina también en Railway (UTC).

### Préstamos

- **Liquidar préstamo** (solo desde una solicitud aprobada): cliente, tipo de asociado, monto, cuotas, fecha de liquidación, primer vencimiento, vendedor (por defecto el del cliente) y comisión manual opcional.
- **Revisar** muestra el cuadro de cuotas (el mismo motor que el simulador), cuota, neto a desembolsar, TNA, TEA y CFT, y corre los **controles**:
  - *Bloquean*: cliente inactivo o bloqueado (y lo que valida el procedure: tipo de asociado, rango de monto y plazo, tasa vigente).
  - *Advierten*: situación en el BCRA peor que `CONTROL_BCRA_SITUACION_MAX` (2), cheques rechazados sin pagar, documentación obligatoria incompleta o vencida, préstamos en mora, cuotas vigentes más la nueva por encima de `CONTROL_CUOTA_INGRESO_MAX` (30 %) del ingreso.
  - *Informan*: peor situación de los últimos 24 meses, préstamos vigentes al día, cheques ya pagados.
  - Si la consulta al BCRA tiene más de `CONTROL_BCRA_DIAS` (30), la hace en el momento.
- **Liquidar**: las advertencias ya las aceptó quien aprobó la solicitud. Vuelve a simular y controlar en el servidor; si se cambió algún dato después de revisar, pide revisar de nuevo. Numera `P-000001`, `P-000002`… (`NRO_PRESTAMO_PREFIJO`). Los controles y el motivo quedan guardados en el préstamo.
- **Listado**: tarjetas (cantidad, capital, saldo de capital, en mora), filtros por estado, mora (al día, en mora, tramos 1-30 / 31-60 / 61-90 / +90), tipo de asociado, vendedor y fechas; Excel con todo el filtro.
- **Ficha**: datos y tasas, controles al liquidar, cuotas con lo pagado y lo pendiente (las vencidas en rojo), deuda vencida a hoy con punitorios y monto para cancelar hoy (`sp_estado_cuenta`), pagos, comisión del vendedor y cuenta corriente. **Plan de pagos en PDF** para el cliente.
- **Anular** (solo sin pagos aplicados, con motivo): deja el saldo en cero y revierte la comisión del vendedor (`sp_anular_prestamo`).

### Cobranzas

- **Cobros en efectivo** (`cobrar.php`; menú Cobranzas, o el botón *Cobrar en efectivo* del préstamo): una grilla con los préstamos vigentes con saldo a pagar (cuotas impagas y vencidas, próximo vencimiento, deuda vencida —a hoy, sin los punitorios del día— y a vencer), paginada y ordenada en la base, con Excel; cada renglón despliega sus cuotas. Solo efectivo: el medio es siempre `EFECTIVO` y entra en una caja en pesos (lo del banco va por Emisión de recibos y lo de las recaudadoras por Importar lote). Se busca por N° de préstamo, CUIT, DNI o nombre. Muestra el estado de cuenta a la fecha del pago (cuotas con saldo, días de atraso, punitorios), la deuda vencida, la próxima cuota, la deuda total y el **monto para cancelar** con el interés futuro que se ahorra. Cambiar la fecha recalcula todo.
- **Pago a cuenta**: puede ser parcial; se imputa de la cuota más vieja a la más nueva y, dentro de cada una, según `imputacion_orden` (IVA punitorio, punitorio, IVA interés, interés, capital). Atajos para cargar la deuda vencida o la próxima cuota. No puede superar la deuda total.
- **Cancelación total**: el importe es el exacto que calcula la base para ese día; se condona el interés futuro y el préstamo queda *Cancelado*. Si el cliente ya había pagado interés de una cuota a vencer por encima de lo corrido a ese día, el excedente (con su IVA) se descuenta del capital (movimiento *Interés adelantado a capital*); al anular el pago se revierte.
- Confirmación antes de registrar; después se va al **recibo** con la imputación por cuota y el **PDF** para entregar. El número sale del **talonario de recibos** (`X 00001-00000001`), reservado con `sp_talonario_numero` dentro de la transacción del cobro: sin huecos ni repetidos con cobros simultáneos; un recibo anulado conserva el suyo. Con varios talonarios de recibos habilitados se elige uno; sin ninguno, `R-` + id del pago.
- **Anular un pago**: desde el recibo, con motivo, solo el último pago aplicado del préstamo (`sp_anular_pago`). Los punitorios ya devengados se mantienen.
- **Recibos emitidos** (`cobranzas.php`, el «Recibos emitidos» de gestion_adm): un renglón por recibo con sus préstamos, el origen (cobro manual, lote o cobro bancario) y la cuenta; por defecto los vigentes de todas las fechas, paginados y ordenados en la base. Filtros por fechas, medio, origen, vendedor, importe, estado, búsqueda y **Nº de recibo exacto** (ese y nada más). El tacho anula el recibo con motivo (`recibo.php`, `sp_anular_recibo`) y vuelve a la grilla; si un préstamo tiene un pago posterior, el tacho sale apagado con el motivo. Abajo, totales por medio para cerrar la caja y los últimos lotes; Excel.
- **Cobros bancarios** (`emision_recibos.php`, `inc/recibos.php`; antes «Emisión de recibos»): la emisión de recibos desde el extracto, como la de los recibos de planes de gestion_adm. Los créditos del extracto bancario de una cuenta y un período, identificados por el CUIL que traen (o asignados a mano, con regla para el CUIL: `extractos_cliente`, `cobros_cuil_reglas`), con lo vencido y lo que vence en el período a la fecha del crédito, sumando todos los préstamos del cliente. `sp_emitir_recibo` bloquea el crédito y los préstamos del cliente, reparte el importe de la cuota que vence primero a la última entre préstamos y aplica un pago por préstamo (`sp_pago_aplicar`); lo que supera la deuda queda disponible en el crédito. Abajo, los créditos sin cliente y los deudores del período sin cobro.
- **Importar lote** (CSV de Rapipago, Pago Fácil, banco o Mercado Pago): columnas `prestamo` (o `cuit`/DNI si el cliente tiene un solo préstamo vigente), `importe`, `fecha`, `referencia`. Vista previa fila por fila: préstamo inexistente o no vigente, importe inválido, fecha anterior a otro pago, repetido en el archivo o ya registrado, o que supera la deuda. Al procesar se vuelve a validar el archivo guardado, se crea el lote y cada pago genera su recibo. El archivo queda en `documentos\lotes\`.

### Inversiones

- **Inversores** (`inversores.php`): persona o empresa, CUIT, contacto y CBU/alias para pagarle; capital vigente por moneda y próximo vencimiento.
- **Aportes** (`inversion.php?nueva=1`): en pesos o dólares, con plazo en días o fecha de vencimiento, en una de dos modalidades:
  - **Tasa fija**: TNA pactada, interés simple sobre el capital (base `INVERSION_DIAS_ANIO`, 365). Muestra el interés al vencimiento mientras se carga.
  - **Participación** (solo pesos): el aporte fondea préstamos vigentes concretos (monto por préstamo, sin pasar el capital libre de cada uno) y gana un % del **interés cobrado** de esos préstamos (sin IVA ni punitorios), en proporción a lo fondeado, por los pagos posteriores al inicio y hasta la liquidación. El capital se devuelve al vencimiento.
- **Listado** (`inversiones.php`): capital vigente en pesos y en dólares (con su equivalente en pesos a la cotización BCRA), lo que vence en 30 días, liquidaciones a pagar, filtros (estado, moneda, modalidad, vencidos / vencen en 7, 30, 90 días) y Excel. Los vencidos sin liquidar se marcan en rojo.
- **Ficha del aporte**: devengado a hoy, interés al vencimiento (tasa fija), préstamos fondeados con su interés cobrado, **constancia en PDF**.
- **Liquidar** al vencimiento (o antes: rescate anticipado, con el interés hasta ese día; después del vencimiento no corre más interés). Vista previa y, al confirmar: se paga todo, se **renueva el capital** y se pagan los intereses, o se **renueva capital + intereses**; la renovación es un aporte nuevo con las mismas condiciones y el plazo que se elija (en participación sigue fondeando los préstamos que sigan vigentes). **PDF de la liquidación**.
- **Pago al inversor**: fecha, medio y referencia; queda *Pagada*. Una liquidación no pagada (o toda renovación) se puede **anular**: el aporte vuelve a vigente y la renovación se anula.
- Sin retenciones por ahora.

### Cotizaciones

- Fuente: API pública de **Estadísticas Cambiarias del BCRA** (sin clave). Se guardan todas las monedas de cada día hábil: pesos por unidad y unidades por dólar.
- **Automático**: la tarea programada (`instalar_tarea_cotizaciones.bat`) corre `cron\actualizar_cotizaciones.php` de lunes a viernes 10:00, 16:30 y 19:30 y trae los días que falten; la salida queda en `cron\cotizaciones.log` y cada corrida en `cotizaciones_log`. Además, si la última actualización tiene más de `COTIZACIONES_HORAS` (4), la pantalla consulta al abrirse.
- **Pantalla**: tarjetas de las monedas destacadas (`COTIZACIONES_MONEDAS`) con la variación del día, evolución de una moneda (gráfico y tabla de 30 días a 2 años), todas las monedas de una fecha (feriados y fines de semana muestran el día hábil anterior), estado de la actualización, **Actualizar ahora**, **Cargar histórico** y Excel.
- En la base, `fn_cotizacion('USD', fecha)` devuelve la cotización de esa fecha o del día hábil anterior, para usar en otros cálculos.

### Comisiones

- **Resumen por vendedor** del período: registros, monto, comisión, % promedio y una observación cuando hay más de un % aplicado (como la hoja "Resumen" de la macro).
- **Subtotales por % aplicado**, del período completo o de un vendedor.
- **Detalle por vendedor** con cada operación, el % aplicado y si fue por telemarketing.
- **Filtros en pantalla:** período, vendedor, tipo de asociado, telemarketing (SI/NO), % de comisión y búsqueda por apellido, nombre o repartición. Se combinan entre sí, se aplican a las tarjetas, al resumen, a los subtotales y al detalle, y se mantienen al entrar al detalle de un vendedor. "Limpiar filtros" deja solo el período y el vendedor.
- **Exportar Excel** (.xlsx) de lo que se está viendo, con los mismos filtros de la pantalla (quedan anotados debajo del título):
  - Hojas: *Resumen* (solo con "Todos"), *Detalle* (una fila por operación, con columna Vendedor) y *Subtotales por %*.
  - Autofiltro en cada columna y encabezado fijo.
  - Los totales usan `SUBTOTAL()`, así que al filtrar en Excel (por vendedor, tipo de asociado, telemarketing, %) se recalculan solos.
  - Montos en `$`, % y fechas con formato; la hoja entra a lo ancho al imprimir.
- **Generar PDF**: abre en otra pestaña lo que se está viendo (resumen o detalle del vendedor), con los mismos filtros, tarjetas de totales y tablas con el formato de los listados de gestion_adm; desde el visor se guarda o se imprime. En el resumen, cada vendedor tiene además sus botones de PDF y Excel.

Por ahora lee solo el historial importado. Cuando haya préstamos liquidados en el sistema, se suma `comisiones_devengadas` a la misma pantalla.

### Vendedores

`vendedores.php` (Comisiones › Vendedores, opción 32, Administrador y Gerencia; `sql\financiera_vendedores_menu.sql`).

- **Ficha**: código (el de las planillas de comisiones, único, mayúsculas), nombre, CUIT (opcional, validado y único), vínculo (comisionista / empleado), zona (se elige o se crea escribiéndola), supervisor (otro vendedor activo) y usuario del sistema (uno por vendedor).
- **Baja lógica**: sin `activo` deja de ofrecerse al cargar clientes y préstamos; lo que tiene sigue a su nombre. **Eliminar** solo si no tiene clientes, préstamos, comisiones (devengadas, importadas, liquidaciones) ni supervisados: borra también sus excepciones.
- **Solapa Excepciones de comisión**: las `comision_reglas` con su `id_vendedor`. «Nueva excepción» (`&escala=nueva`, con `&tipo=N` desde *Lo que cobra hoy*) y el lápiz (`&escala=ID`) abren el formulario arriba de la grilla (tipo de asociado, monto desde-hasta, %, % con telemarketing, base, vigencia); la cruz la da de baja, el tilde la reactiva y el tacho aparece solo si ningún préstamo la usó. El filtro *Estado* trae las dadas de baja. El mismo ABM (`escala_acciones`, `escala_form`, `escalas_grilla` de `inc\comisiones_escalas.php`) es el de las escalas generales en la ficha del tipo. El % se escribe en porcentaje (2,5) y se guarda como fracción (0.025). Cada renglón muestra cuántos préstamos se liquidaron con esa excepción. Debajo, *Lo que cobra hoy*: la escala general de cada tipo y la del vendedor. La comisión queda congelada en el préstamo al liquidar: cambiar una excepción no toca lo liquidado.
- La lista muestra cuántas excepciones le rigen hoy.

## Ayuda

Como en gestion_sindicato: el botón **Ayuda** de la barra de arriba (al lado de **Mensajes**: los dos van ahí y no flotando, para no tapar la pantalla) y la tecla **F1** abren la sección del manual de la pantalla actual; `ayuda.php` es el índice, con un buscador sobre todos los manuales (cada palabra tiene que estar, sin acentos).

- **Los manuales son texto**: `app\ayuda\<clave>.md`, uno por módulo del menú. `# Título`, `<!-- orden: N -->`, una introducción y una sección `## …` por pantalla o tema, con `<!-- pantallas: a.php b.php -->` (qué pantallas abren esa sección con F1; la primera que el usuario tenga da «Ir a la pantalla») y `<!-- resumen: … -->`. Adentro: párrafos, `###`, listas `1.` y `-` (un nivel adentro con 3 espacios), tablas con `|`, bloques ` ``` `, `**negrita**`, `*cursiva*` y `` `código` ``.
- Una sub-pantalla (ficha de préstamo, recibo…) sin sección propia toma la de la pantalla de la que sale (`PAGINAS_PERMISO`).
- **Una pantalla nueva lleva su sección.** `php -r "require 'inc/bootstrap.php'; require 'inc/ayuda.php'; print_r(ayuda_verificar());"` lista las pantallas sin ayuda y las secciones que nombran pantallas que no existen.
- La ve cualquiera con sesión (`PAGINAS_SIN_PERMISO`); los manuales explican también lo que la persona no tiene, pero «Ir a la pantalla» sale solo si la tiene.
- Al cambiar una regla de negocio, cambiar también el manual.

## Formularios

- Cada formulario lleva un token contra envíos desde otros sitios (cookie + campo oculto).
- Después de guardar se vuelve a la ficha con un mensaje (patrón POST → redirect → GET): recargar la página no vuelve a guardar.
- Confirmaciones en un diálogo propio (`data-confirmar` en el `<form>`), no el `confirm()` del navegador.

## Grillas

La forma de las grillas de gestion_adm. Las piezas están en `inc\ui.php` y `public\assets\tabla.js`; una pantalla nueva las usa en lugar de escribir su barra a mano.

- **Bloques uno debajo del otro.** Una grilla con datos va a todo el ancho: no se pone al lado de otra tarjeta. `app.css` igual apila las `.columnas-2-1`, `.grilla-2` y `.panel-grilla` que tengan una `.data-table` adentro; las que son sólo datos o formularios siguen en paralelo.
- **Una tarjeta**: la barra de filtros (`form.filter-bar`) arriba de la grilla, con Buscar, los filtros con su rótulo, `filtro_botones()` («Filtrar» y «Quitar filtro», apagado si no hay nada que quitar) y a la derecha el contador, «Exportar a Excel» y las filas por página. Combos y casillas aplican solos; las fechas (clase `filtro`) esperan a «Filtrar». El Excel ya no va en el encabezado de la página.
- **Pagina en el servidor** (`data-sin-paginar`): `filtro_fin()` con el total, la página y la URL del Excel; `orden_ocultos()` en el formulario; cada encabezado con `th_orden()` lleva a `?orden=clave&dir=asc|desc` desde la primera página, validado contra la lista de `orden_grilla()` (nunca una columna cualquiera desde la URL). La grilla y el Excel salen con el mismo `ORDER BY`. Debajo, `paginador()`: `« ‹ Pág. 2 de 7 · 340 registros › »`.
- **Trae todas las filas**: la tabla con `data-paginada` (pagina el navegador, 25 por página) y `data-unidad="singular|plural"`; `filtro_fin_local()` en la barra y `paginacion_local()` debajo. Con `data-local` además la búsqueda filtra mientras se escribe y «Exportar a Excel» baja lo filtrado (`exportar_grilla.php`). Las tablas de configuración (cuentas, vendedores, usuarios) van sin Excel: `filtro_fin_local(null)`.
- **La barra entera en una línea de PHP**: `barra_local($url, $placeholder, $filtros, $hayFiltro, $excel)` arma Buscar (filtra en el navegador), los filtros que van a la base (`filtro_select()`: aplica solo al elegir), «Filtrar» / «Quitar filtro» si hay filtros, el contador, el Excel (`''` = lo filtrado en el navegador, una URL = el del servidor, `null` = sin Excel, para las tablas de configuración y donde el encabezado ya lo tiene) y las filas por página. Los parámetros de `$url` viajan como ocultos (`cliente.php?id=5`). Con la grilla `data-local data-paginada data-unidad="…"` y `paginacion_local()` debajo, es todo. Una celda que no se ordena bien por su texto lleva `data-orden` (la fecha AAAA-MM-DD, el importe sin formato).
- **ABM**: el formulario va en su tarjeta **arriba** de la lista y sólo cuando se edita o se carga (`?edit=N`, `?nuevo=1`, o al volver con un error), con «Volver»; el botón «Nuevo …» va en el título o en el encabezado de la tarjeta. Primera columna «Acciones»: `btn_accion('editar', …)`, y lo que cambia datos por POST con `accion_post($url, ['accion' => …, 'id' => …], $icono, $titulo, $confirmar, 'borrar')` (el token y la confirmación los pone solo). Lo que está en uso se deshabilita o se da de baja (cruz / tilde); el tacho sale sólo si nada lo usa. Sin grillas editables con un «renglón nuevo» al final.
- **Solapas**: `solapas(['clave' => [texto, url, conteo|null], …], $activa)`, enlaces con `?solapa=` (la ficha del tipo de asociado, la del vendedor, Cuentas de fondos, Talonarios).
- `data-sin-orden`: grilla quieta (un saldo acumulado, una serie por fecha, renglones con casillas o importes que se cargan, renglones con un formulario adentro). Así quedan a propósito: las cuotas y los movimientos de un préstamo, las liquidaciones de un aporte, el cuadro de cuotas del simulador y la liquidación, la vista previa de un lote, el árbol del menú y el panel. Las tarjetas chicas de resumen con totales (por medio, saldos, los últimos lotes) ordenan pero no llevan barra. Primera columna «Acciones» o la casilla; importes a la derecha; el dato que acompaña a otro, en segunda línea de la misma celda.
- La fila de totales va en `<tfoot>` con `total-row`; el «Saldo anterior» que abre una cuenta, `pg-fija`.

## Pendiente transversal

- **Acciones sensibles dentro de una pantalla** (anular un préstamo o un pago, anular una liquidación): hoy las puede hacer quien abre la pantalla. Si hace falta restringirlas, se agregan como opciones del menú propias.

## Repositorio

`D:\financiera\subir_github.bat` hace todo (copia del de gestion_sindicato):

- **La primera vez** crea el repositorio en la carpeta: pide la dirección del repositorio de GitHub (crearlo antes, **privado** y vacío) y, si git no los tiene, tu nombre y correo. Sube con el mensaje «primera versión».
- **Después**: `git add -A`, muestra lo que va, pide el mensaje (o lo toma de `mensaje_commit.txt`, que después borra), pregunta, commit, `pull --rebase` y `push`.
- **No suben** (`.gitignore`): `app\config.php` (la contraseña de la base; el .bat se niega a seguir si no está ignorado), `documentos\`, `sesiones\`, los `.log`, las cargas de datos reales `sql\carga_*.sql`, certificados, planillas y `Claude outputs\`. Sí sube `config.example.php`.

## Railway

El repositorio se despliega en Railway con el `Dockerfile` de la raíz (Apache + PHP 8.3, `app\public` como raíz del sitio); Railway lo usa solo al ver el archivo. En IIS no interviene.

- **Sin `config.php`**: en Railway la configuración sale de variables. Obligatorias: `FIN_DB_HOST`, `FIN_DB_PORT`, `FIN_DB_USER`, `FIN_DB_PASS` (y `FIN_DB_NAME` si la base no se llama `financiera`). Con el MySQL de Railway en el mismo proyecto: `FIN_DB_HOST=${{MySQL.MYSQLHOST}}`, `FIN_DB_PORT=${{MySQL.MYSQLPORT}}`. Opcionales: `FIN_DOCS_DIR` (por defecto `/data/documentos`), `FIN_SESION_DIR` (`/data/sesiones`), `FIN_DOCS_MAX_MB`, `FIN_SESION_MINUTOS`, `FIN_BCRA_CACHE_HORAS`, `FIN_DEBUG=1`.
- **WhatsApp**: `FIN_WA_TOKEN`, el token de la API de WhatsApp Cloud (opcional).
- **App en el celular**: por la dirección `https://` de Railway se puede instalar (PWA). Después de cada cambio de base hay que correr ahí los `sql\` nuevos.
- **Volumen en `/data`**: la documentación de los clientes y las sesiones. Sin volumen se pierden en cada despliegue.
- **La base**: la app de Railway no llega al MySQL de este servidor. Hay que restaurar `financiera` (con sus procedimientos: `mysqldump --routines --triggers`) en el MySQL de Railway, o apuntar las variables a una base que se pueda alcanzar desde internet.
- La tarea de cotizaciones corre en este servidor y actualiza la base local; si la base viva pasa a ser la de Railway, la tarea tiene que apuntar a ella.
- HTTPS: Railway termina el certificado y avisa con `X-Forwarded-Proto`; Apache lo pasa a PHP, así la cookie de sesión va `Secure`.
