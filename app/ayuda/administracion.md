# Administración del Sistema
<!-- orden: 8 -->

Usuarios, roles, permisos y menú, y lo que necesita saber quien mantiene el sistema: parámetros, tareas y problemas comunes.

## Cómo funcionan los permisos
<!-- resumen: El permiso es la opción del menú; roles más permisos sueltos. -->

- **El permiso es la opción del menú.** Si la tenés en el menú, podés abrir esa pantalla y las que salen de ella (por ejemplo, desde *Préstamos* se abre la ficha de cada préstamo).
- Lo que ve una persona es la **suma** de sus roles más sus **permisos sueltos**.
- Un cambio de permisos le llega en menos de un minuto, sin salir y volver a entrar.

| Rol inicial | Qué tiene |
| --- | --- |
| Administrador | Todo, incluida la Administración del Sistema |
| Gerencia | Todo lo operativo (también Vendedores, Autorizaciones y Datos de la empresa), sin la Administración del Sistema |
| Créditos | Simulador, Solicitudes (todas), Liquidar préstamo, Préstamos, Clientes, Consulta BCRA, Pagos y caja |
| Cobranzas | Préstamos, Clientes, Cobrar, Pagos y caja, Importar lote |
| Inversiones | Aportes, Inversores, Cotizaciones |
| Vendedor | Simulador, Solicitudes (solo las suyas), Clientes, Consulta BCRA |

## Usuarios
<!-- pantallas: admin_usuarios.php -->
<!-- resumen: Alta, roles, contraseña, desbloqueo y baja. -->

1. **Nuevo usuario**: nombre, correo (con eso entra), usuario (opcional) y contraseña (al menos 8 caracteres, dos veces).
2. Tildá sus **roles**.
3. **Guardar**.

**Celular**: con él, en *Mensajes* aparecen **Llamar** y **WhatsApp** en la conversación con esa persona. Cada uno lo puede cargar también en *Mis datos* (click en su nombre, arriba a la derecha). Si un vendedor no lo tiene cargado, se usa el teléfono de su ficha de vendedor.

Para cambiarle la contraseña a alguien, editalo y escribí una nueva (vacía = no se cambia). La llave de la lista desbloquea a quien se pasó de intentos.

Quien ya operó (creó clientes, liquidó, cobró…) no se elimina: se **da de baja**, y lo que hizo sigue a su nombre. Nadie se puede dar de baja a sí mismo ni sacarse el rol Administrador, y siempre tiene que quedar al menos un administrador activo.

Si un usuario es vendedor, se vincula desde *Comisiones* → *Vendedores* (campo *Usuario del sistema*).

## Roles
<!-- pantallas: admin_roles.php -->
<!-- resumen: Qué pantallas tiene cada rol. -->

Editá un rol y tildá sus pantallas en el árbol: tildar una pantalla tilda su módulo; destildar un módulo destilda todo lo de adentro. El **+** crea un rol nuevo copiando las pantallas de otro. El rol Administrador no se renombra ni se desactiva, y no puede perder Usuarios, Roles ni Menú.

## Permisos por usuario
<!-- pantallas: admin_permisos.php -->
<!-- resumen: Una pantalla suelta para una persona, sin cambiar su rol. -->

Para darle a una persona una pantalla que su rol no tiene, sin cambiar el rol: elegí el usuario, tildá la pantalla y **Guardar permisos**. Lo que ya le dan sus roles aparece tildado y gris (se cambia en Roles).

## Menú
<!-- pantallas: admin_menu.php -->
<!-- resumen: El árbol del menú, sus íconos y qué pantalla abre cada opción. -->

Arma el árbol del menú: módulos (con ícono) y pantallas, hasta 4 niveles. Una pantalla nueva la recibe el Administrador; a los demás roles se les asigna en Roles. Desactivar una opción la saca del menú y quita el permiso a su pantalla. Usuarios, Roles y Menú están protegidas.

El ícono de color se pega como SVG (los de Fluent UI, como en gestion_sindicato); si queda vacío se usa el ícono simple elegido.

## El primer administrador
<!-- resumen: Crear o destrabar un administrador desde la consola. -->

Antes de que exista alguien que pueda entrar, se crea desde la consola del servidor (en PowerShell, con `&` adelante si la ruta de PHP lleva comillas):

```
& "C:\Program Files\PHP\v8.3\php.exe" D:\financiera\app\crear_usuario.php tu@correo "Tu nombre"
```

Pide la contraseña (vacía = genera una y la muestra). El mismo comando, con un correo que ya existe, le cambia la contraseña y lo reactiva: sirve para destrabar a un administrador que se la olvidó.

## Antes de empezar a operar
<!-- resumen: Productos, vendedores, documentación y cotizaciones. -->

1. Cargar los **productos** y al menos una **tasa vigente** por producto (*Productos y tasas*). Sin tasa no se puede simular ni liquidar.
2. Cargar **vendedores** y sus **reglas de comisión** (*Comisiones* → *Vendedores*).
3. Revisar la **documentación requerida** (*Tablas y parámetros*).
4. **Cargar histórico** de cotizaciones la primera vez (*Cotizaciones*).
5. Hacer una consulta BCRA de prueba para confirmar que el servidor llega al BCRA.

## Parámetros y configuración
<!-- resumen: La tabla parametros, config.php y la tarea de cotizaciones. -->

Se guardan en la tabla `parametros` y se cambian por SQL (`UPDATE parametros SET valor = '...' WHERE clave = '...'`). Rigen desde el próximo uso; lo ya liquidado no cambia.

| Clave | Por defecto | Qué controla |
| --- | --- | --- |
| `PUNITORIO_PCT_TNA` | 0.5 | Punitorio como % de la TNA, si la tasa no trae uno propio |
| `CONTROL_BCRA_SITUACION_MAX` | 2 | Peor situación BCRA aceptada sin advertencia |
| `CONTROL_BCRA_DIAS` | 30 | Antigüedad máxima de la consulta BCRA al revisar un préstamo |
| `CONTROL_CUOTA_INGRESO_MAX` | 0.30 | Cuotas / ingreso mensual máximo sin advertencia |
| `NRO_PRESTAMO_PREFIJO` | P- | Prefijo del número de préstamo |
| `NRO_INVERSION_PREFIJO` | I- | Prefijo del número de aporte |
| `INVERSION_DIAS_ANIO` | 365 | Base de días para el interés de los aportes |
| `COTIZACIONES_MONEDAS` | USD,EUR,BRL,UYU,CLP,GBP,CNY,JPY | Monedas destacadas y cargadas en el histórico |
| `COTIZACIONES_HORAS` | 4 | Cada cuántas horas la pantalla vuelve a consultar al BCRA |

En `config.php`: `docs_max_mb` (tamaño máximo por archivo, 10), `bcra_cache_horas` (reuso de la consulta BCRA, 24), `bcra_cainfo` (ruta al `cacert.pem`), `sesion_minutos` (cierre por inactividad, 480), `sesion_dir` (carpeta de las sesiones) y `debug` (siempre `false` en producción).

### Tarea de cotizaciones

- **Instalar:** `D:\financiera\app\instalar_tarea_cotizaciones.bat` con *Ejecutar como administrador*. Crea la tarea (lunes a viernes 10:00, 16:30 y 19:30, como SYSTEM).
- **Verla:** Programador de tareas de Windows. El resultado de cada corrida queda en `D:\financiera\app\cron\cotizaciones.log` y en el *Registro* de la pantalla.
- **Correrla a mano:** `php D:\financiera\app\cron\actualizar_cotizaciones.php` (agregando `--historico=365` carga un año).

### Carpetas

Los documentos se guardan en `D:\financiera\documentos` y las sesiones en `D:\financiera\sesiones`, fuera del sitio. El grupo de aplicaciones de IIS necesita permiso de modificación en las dos (`instalar_iis.ps1` lo da). Hacer **backup** de `documentos` junto con la base.

## Problemas comunes
<!-- resumen: Síntomas frecuentes y qué hacer. -->

| Síntoma | Causa probable | Solución |
| --- | --- | --- |
| *No se pudo conectar con el BCRA* | Sin salida a internet hacia `api.bcra.gob.ar`, o certificado | Probar la conexión; si es error de certificado, bajar `cacert.pem` (curl.se/docs/caextract.html) y poner su ruta en `bcra_cainfo` |
| *No hay tasa vigente para ese plazo y monto* | Falta tasa, o su rango no cubre ese plazo o monto | Cargar una tasa en *Productos y tasas* |
| Al subir un archivo: no se cargó | Muy pesado o no es PDF / imagen | Achicarlo o convertirlo; los límites están en `config.php`, `.user.ini` y `web.config` |
| Al subir: error de permisos | IIS no puede escribir en `documentos` | Correr `instalar_iis.ps1` o dar el permiso con `icacls` |
| No deja ingresar a nadie y no hay error | IIS no puede escribir en `sesiones` | Ídem: permiso de modificación en la carpeta de sesiones |
| *Sin cotización* en un aporte en dólares | Falta la cotización de esa fecha | *Cotizaciones* → **Cargar histórico** |
| No deja liquidar un préstamo | Control en rojo o advertencias sin confirmar | Ver los controles; confirmar con motivo o corregir el cliente |
| Un préstamo liquidado no tiene comisión | El vendedor no tiene una regla vigente que coincida | Cargarla en *Vendedores* → *Reglas de comisión* (vale para los próximos) |
| No deja anular un pago | No es el último pago del préstamo | Anular primero los posteriores |
| No deja anular una liquidación de inversión | Ya está pagada | Solo se anulan liquidaciones *A pagar* |
| Un error de base sin detalle | `debug` en `false` | Para diagnosticar, ponerlo en `true` un momento y volver a `false` |

**¿Si cambio una tasa o una regla de comisión, cambian los préstamos ya dados?** No. Cada préstamo guarda su tasa, su cuadro y su comisión.

**¿Se borra algo?** No. Documentos, pagos, préstamos y liquidaciones se dan de baja o se anulan con motivo, y queda el registro de quién y cuándo.
