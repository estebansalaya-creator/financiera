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
    ui.php             piezas de pantalla (título, tarjetas, botones, estados, paginador, CUIT, teléfonos)
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
    inversiones.php    modalidades, monedas, cálculo y liquidación de aportes
    cotizaciones.php   API de Estadísticas Cambiarias del BCRA
    ayuda.php          los manuales: lectura de ayuda\*.md, sección de cada pantalla, Markdown simple, búsqueda
  ayuda\               los manuales de uso, uno por módulo (solo texto, Markdown)
  cron\
    actualizar_cotizaciones.php   lo corre la tarea programada (php CLI)
  public\              lo único que sirve IIS
    login.php, logout.php, mi_clave.php   ingreso, salida y cambio de la contraseña propia
    admin_usuarios.php, admin_roles.php, admin_permisos.php, admin_menu.php   Administración del Sistema
    simulador.php, clientes.php, cliente.php, comisiones.php, productos.php
    documento.php      sube, muestra, descarga y da de baja la documentación
    documentos_tipos.php  catálogo: qué documentación se pide y si es obligatoria u opcional para aprobar
    bcra.php           consulta de deudores del BCRA (cualquier CUIT)
    solicitudes.php, solicitud.php, autorizaciones.php   solicitud de préstamo, su ficha y la cola de autorización
    prestamos.php, prestamo.php, prestamo_nuevo.php   listado, ficha y liquidación (de una solicitud aprobada)
    empresa.php, logo.php   datos de la empresa y su logo
    manifest.php, icono.php, sw.js, offline.html   la app instalable (PWA)
    cobranzas.php, cobrar.php, recibo.php, lote.php   pagos, cobro, recibo y lotes CSV
    inversiones.php, inversion.php, inversores.php, inversor.php   aportes de capital y liquidación de intereses
    cotizaciones.php   moneda extranjera (BCRA)
    vendedores.php     ABM de vendedores, zonas y sus reglas de comisión
    ayuda.php          índice de los manuales (busca en todos) y cada manual (?m=)
    assets\app.css     estilos (los de gestion_adm)
    assets\app.js      diálogo de confirmación, renglones, validación de CUIT, formato de teléfonos, menú lateral (y ☰ en el celular), árbol de permisos
    assets\pwa.js      registra el service worker, botón «Instalar app» y aviso para iPhone
    assets\tabla.js    orden por columna y paginación de las grillas del navegador
    web.config         límites de subida de IIS;  .user.ini  los de PHP
documentos\           archivos de la documentación (fuera de app\, no se publica)
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
12. Abrí el sitio: pide ingresar y después entra a la primera pantalla del menú del usuario.

Para ver el detalle de un error de base durante el desarrollo, poné `'debug' => true` en `config.php`. En producción dejalo en `false`, porque si no los mensajes de MySQL se muestran en pantalla.

## Módulos

| Módulo | Archivo | Estado | Fuente |
|---|---|---|---|
| Simulador | `simulador.php` | Listo | `sp_simular_prestamo` (el mismo motor que la liquidación) |
| Clientes | `clientes.php`, `cliente.php`, `documento.php` | Listo | `clientes`, `clientes_personas`, `clientes_empresas`, `domicilios`, `telefonos`, `clientes_documentos` |
| Solicitudes y autorización | `solicitudes.php`, `solicitud.php`, `autorizaciones.php` | Listo | `solicitudes`, `solicitudes_historial`, `documentos_tipos.obligatorio` |
| Datos de la empresa | `empresa.php`, `logo.php`, `manifest.php`, `icono.php` | Listo | `empresa` (una fila: datos, logo, ícono de la app, WhatsApp) |
| Préstamos | `prestamos.php`, `prestamo.php`, `prestamo_nuevo.php` | Listo | `sp_simular_prestamo`, `sp_liquidar_prestamo`, `sp_estado_cuenta`, `sp_anular_prestamo` |
| Cobranzas | `cobranzas.php`, `cobrar.php`, `recibo.php`, `lote.php` | Listo | `sp_estado_cuenta`, `sp_registrar_pago`, `sp_anular_pago`, `lotes_cobranza` |
| Consulta BCRA | `bcra.php` (y en la ficha del cliente) | Listo | API pública de la Central de Deudores → `consultas_bcra` |
| Inversiones | `inversiones.php`, `inversion.php`, `inversores.php`, `inversor.php` | Listo | `sp_inversion_calcular`, `sp_liquidar_inversion`, `sp_anular_liquidacion_inversion`, `v_inversiones_devengado` |
| Cotizaciones | `cotizaciones.php`, `cron\actualizar_cotizaciones.php` | Listo | API de Estadísticas Cambiarias del BCRA → `cotizaciones`, `fn_cotizacion` |
| Comisiones | `comisiones.php` | Listo | `comisiones_importadas` (historial de las planillas) |
| Productos y tasas | `productos.php` | Listo | `productos`, `tasas_vigencia` |
| Usuarios, roles y menú | `login.php`, `admin_*.php` | Listo | `usuarios`, `roles`, `usuarios_roles`, `menu`, `menu_roles`, `menu_usuarios` |

### Usuarios, roles y menú

El mismo esquema que gestion_sindicato:

- **El permiso es la opción de menú.** Una pantalla se abre si el usuario tiene la opción del menú con esa URL. No hay otra tabla de permisos que se desincronice de lo que se ve.
- Lo que tiene una persona es la **suma** de las opciones de sus roles (`menu_roles`) y de sus opciones sueltas (`menu_usuarios`). Una opción suelta solo agrega.
- Las pantallas que se abren desde otra toman el permiso de su listado (`PAGINAS_PERMISO` en `inc\seguridad.php`): la ficha del cliente con Clientes, la del préstamo con Préstamos, Cobrar o Pagos, el recibo con Cobrar o Pagos, la ficha del aporte con Aportes, la del inversor con Inversores o Aportes. Una pantalla nueva que no esté en el menú ni en ese mapa no se puede abrir.
- Roles y opciones se vuelven a leer cada minuto: un cambio de permisos le llega a quien ya está adentro, sin salir y entrar. Si lo dan de baja, lo saca.
- Login con correo (o usuario) y contraseña (`password_hash`). 5 intentos fallidos seguidos bloquean 15 minutos; un administrador lo desbloquea desde Usuarios. La sesión se cierra sola tras `sesion_minutos` sin uso (480).
- Roles iniciales: Administrador, Gerencia, Créditos, Cobranzas, Inversiones y Vendedor. Se editan, se crean y se copian en **Roles**. Administrador es el único que el sistema reconoce por nombre.
- Reglas para no quedar afuera: siempre hay al menos un administrador activo; nadie se da de baja ni se saca el rol Administrador a sí mismo; Administrador no se desactiva ni pierde Usuarios, Roles y Menú, y esas tres opciones no se desactivan, no se eliminan ni cambian de URL.
- Un usuario que ya operó (préstamos, pagos, clientes…) no se elimina, se da de baja: dónde figura sale de las claves foráneas de la base.
- Las operaciones guardan quién las hizo: `creado_por`, `anulado_por`, `subido_por`, `baja_por`, `consultado_por` y el `p_id_usuario` de los procedures.
- **Íconos en colores**, como gestion_sindicato: Fluent UI System Color Icons (los mismos SVG), uno por opción en todos los niveles, en `menu.icono_svg` (`sql\financiera_menu_iconos.sql`). Pintan con degradados por `id`, así que cada vez que se dibujan se les renombran los `id` (`svg_unico` en `inc\iconos.php`): el mismo ícono puede salir dos veces en una página. En el ABM del menú se pega el SVG (sin `script`, `on…=` ni `javascript:`, hasta 6.000 caracteres); sin SVG se usa el ícono simple de `iconos.php`.
- **Menú** arma el árbol (hasta 4 niveles): ramas con ícono y pantallas. Una opción nueva la recibe el Administrador; a los demás roles se les asigna en Roles. Si la URL no corresponde a un archivo de `public\`, la lista lo marca *no existe*.

### Productos y tasas

- Configuración de cada línea: plazo y monto mínimo y máximo, IVA sobre intereses y gastos de otorgamiento (se descuentan del desembolso). Sistema francés.
- **Las tasas no se editan**: se carga una nueva con su fecha de vigencia y su rango de plazo y monto. Rige la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos. Una tasa se puede borrar solo si ningún préstamo la usó.
- La punitoria vacía toma el parámetro general `PUNITORIO_PCT_TNA` (50 % de la TNA).
- Sin tasa vigente, un producto no se puede simular ni liquidar: el listado lo avisa.

### Simulador

- Producto, monto, cuotas, fecha de liquidación y primer vencimiento (vacío: un mes después). Valida con los mismos límites del producto que la liquidación.
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

- **Solicitud** (`solicitud.php`): cliente, producto, monto, cuotas, vendedor, destino y observaciones. *Revisar* simula a hoy y corre los controles; *Enviar a autorización* la deja `PENDIENTE` con número `S-000001`… Un control que bloquea impide cargarla; las advertencias no.
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

- **Liquidar préstamo** (solo desde una solicitud aprobada): cliente, producto, monto, cuotas, fecha de liquidación, primer vencimiento, vendedor (por defecto el del cliente) y comisión manual opcional.
- **Revisar** muestra el cuadro de cuotas (el mismo motor que el simulador), cuota, neto a desembolsar, TNA, TEA y CFT, y corre los **controles**:
  - *Bloquean*: cliente inactivo o bloqueado (y lo que valida el procedure: producto, rango de monto y plazo, tasa vigente).
  - *Advierten*: situación en el BCRA peor que `CONTROL_BCRA_SITUACION_MAX` (2), cheques rechazados sin pagar, documentación obligatoria incompleta o vencida, préstamos en mora, cuotas vigentes más la nueva por encima de `CONTROL_CUOTA_INGRESO_MAX` (30 %) del ingreso.
  - *Informan*: peor situación de los últimos 24 meses, préstamos vigentes al día, cheques ya pagados.
  - Si la consulta al BCRA tiene más de `CONTROL_BCRA_DIAS` (30), la hace en el momento.
- **Liquidar**: las advertencias ya las aceptó quien aprobó la solicitud. Vuelve a simular y controlar en el servidor; si se cambió algún dato después de revisar, pide revisar de nuevo. Numera `P-000001`, `P-000002`… (`NRO_PRESTAMO_PREFIJO`). Los controles y el motivo quedan guardados en el préstamo.
- **Listado**: tarjetas (cantidad, capital, saldo de capital, en mora), filtros por estado, mora (al día, en mora, tramos 1-30 / 31-60 / 61-90 / +90), producto, vendedor y fechas; Excel con todo el filtro.
- **Ficha**: datos y tasas, controles al liquidar, cuotas con lo pagado y lo pendiente (las vencidas en rojo), deuda vencida a hoy con punitorios y monto para cancelar hoy (`sp_estado_cuenta`), pagos, comisión del vendedor y cuenta corriente. **Plan de pagos en PDF** para el cliente.
- **Anular** (solo sin pagos aplicados, con motivo): deja el saldo en cero y revierte la comisión del vendedor (`sp_anular_prestamo`).

### Cobranzas

- **Cobrar** (menú Cobranzas, o el botón *Cobrar* del préstamo): se busca por N° de préstamo, CUIT, DNI o nombre. Muestra el estado de cuenta a la fecha del pago (cuotas con saldo, días de atraso, punitorios), la deuda vencida, la próxima cuota, la deuda total y el **monto para cancelar** con el interés futuro que se ahorra. Cambiar la fecha recalcula todo.
- **Pago a cuenta**: puede ser parcial; se imputa de la cuota más vieja a la más nueva y, dentro de cada una, según `imputacion_orden` (IVA punitorio, punitorio, IVA interés, interés, capital). Atajos para cargar la deuda vencida o la próxima cuota. No puede superar la deuda total.
- **Cancelación total**: el importe es el exacto que calcula la base para ese día; se condona el interés futuro y el préstamo queda *Cancelado*. Si el cliente ya había pagado interés de una cuota a vencer por encima de lo corrido a ese día, el excedente (con su IVA) se descuenta del capital (movimiento *Interés adelantado a capital*); al anular el pago se revierte.
- Confirmación antes de registrar; después se va al **recibo** (`R-00000001`…) con la imputación por cuota y el **PDF** para entregar.
- **Anular un pago**: desde el recibo, con motivo, solo el último pago aplicado del préstamo (`sp_anular_pago`). Los punitorios ya devengados se mantienen.
- **Listado**: los pagos del día por defecto o de un período; filtros por medio, estado, vendedor, lote y búsqueda (recibo, préstamo, cliente, CUIT, referencia); totales por medio para cerrar la caja; Excel.
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
- **Baja lógica**: sin `activo` deja de ofrecerse al cargar clientes y préstamos; lo que tiene sigue a su nombre. **Eliminar** solo si no tiene clientes, préstamos, comisiones (devengadas, importadas, liquidaciones) ni supervisados: borra también sus reglas.
- **Solapa Reglas de comisión**: las `comision_reglas` con su `id_vendedor`, editables en grilla (producto o todos, telemarketing sí / no / cualquiera, cuotas desde-hasta, %, base, prioridad, vigencia, activa) y un renglón nuevo. El % se escribe en porcentaje (2,5) y se guarda como fracción (0.025). Cada renglón muestra cuántos préstamos devengaron con esa regla. La comisión queda congelada en el préstamo al liquidar: cambiar una regla no toca lo liquidado; un aumento se carga cerrando la vigencia y abriendo otra regla.
- La lista avisa *sin reglas* cuando el vendedor no tiene ninguna vigente hoy.

## Ayuda

Como en gestion_sindicato: el botón **Ayuda** flotante (abajo a la derecha) y la tecla **F1** abren la sección del manual de la pantalla actual; `ayuda.php` es el índice, con un buscador sobre todos los manuales (cada palabra tiene que estar, sin acentos).

- **Los manuales son texto**: `app\ayuda\<clave>.md`, uno por módulo del menú. `# Título`, `<!-- orden: N -->`, una introducción y una sección `## …` por pantalla o tema, con `<!-- pantallas: a.php b.php -->` (qué pantallas abren esa sección con F1; la primera que el usuario tenga da «Ir a la pantalla») y `<!-- resumen: … -->`. Adentro: párrafos, `###`, listas `1.` y `-` (un nivel adentro con 3 espacios), tablas con `|`, bloques ` ``` `, `**negrita**`, `*cursiva*` y `` `código` ``.
- Una sub-pantalla (ficha de préstamo, recibo…) sin sección propia toma la de la pantalla de la que sale (`PAGINAS_PERMISO`).
- **Una pantalla nueva lleva su sección.** `php -r "require 'inc/bootstrap.php'; require 'inc/ayuda.php'; print_r(ayuda_verificar());"` lista las pantallas sin ayuda y las secciones que nombran pantallas que no existen.
- La ve cualquiera con sesión (`PAGINAS_SIN_PERMISO`); los manuales explican también lo que la persona no tiene, pero «Ir a la pantalla» sale solo si la tiene.
- Al cambiar una regla de negocio, cambiar también el manual.

## Formularios

- Cada formulario lleva un token contra envíos desde otros sitios (cookie + campo oculto).
- Después de guardar se vuelve a la ficha con un mensaje (patrón POST → redirect → GET): recargar la página no vuelve a guardar.
- Confirmaciones en un diálogo propio (`data-confirmar` en el `<form>`), no el `confirm()` del navegador.

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
