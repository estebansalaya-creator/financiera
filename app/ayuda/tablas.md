# Tablas y parámetros
<!-- orden: 7 -->

Los datos de la empresa, los productos con sus tasas, qué documentación se le pide al cliente y las cotizaciones del BCRA.

## Datos de la empresa
<!-- pantallas: empresa.php -->
<!-- resumen: Razón social, CUIT, domicilio, WhatsApp Business y logo. -->

Los datos de la financiera: razón social, nombre de fantasía, CUIT, condición frente al IVA, ingresos brutos, domicilio y contacto. Salen en el encabezado del sistema, en la pantalla de ingreso y arriba a la derecha de **todos los PDF** (recibos, planes de pago, liquidaciones, simulaciones). El nombre de fantasía es el que se ve en el sistema; si queda vacío, se usa la razón social.

Con **PDF de muestra** se ve cómo queda el encabezado antes de imprimir un recibo de verdad.

### Logo

PNG (mejor con fondo transparente) o JPG, hasta 1 MB y 2000 px por lado. Rinde más un logo apaisado de unos 600 px de ancho. Al elegir el archivo se ve cómo queda en el PDF (fondo blanco) y en el encabezado (fondo azul, dentro de un recuadro blanco) antes de guardar. Para sacarlo, tildá **Quitar el logo** y guardá.

### Ícono de la app

Con **Usar el logo como ícono de la app** tildado, el logo es también el ícono de la aplicación instalada en el celular y el de la pestaña del navegador. Se ve cómo queda en iPhone/PC (cuadrado) y en Android (redondo), y se puede elegir el color de fondo. Sin logo, o destildado, queda el ícono estándar. Un celular que ya la tenía instalada toma el ícono nuevo solo en Android (puede tardar un día); en iPhone hay que volver a agregarla a la pantalla de inicio.

### WhatsApp Business

- **Número de WhatsApp**: el de atención de la financiera. Se escribe como se dice acá, con la característica y sin el 0 ni el 15 (*11 2345-6789*); el sistema lo guarda en formato internacional (+54 9 …). De otro país, con + y el código. **Probar el número** abre un chat para comprobarlo. Sale en los PDF.
- **Mensaje de saludo**: el texto con que arranca el chat al escribirle a un cliente. `{cliente}` y `{empresa}` se reemplazan solos.
- **API de WhatsApp Cloud**: el *Phone Number ID* y el *WABA ID* solo hacen falta para mandar avisos desde el sistema (recordatorios de vencimiento, recibos). El token de acceso no se carga acá: va en `config.php` (`whatsapp_token`) o en la variable `FIN_WA_TOKEN` de Railway. La pantalla muestra si está configurado.

## Productos y tasas
<!-- pantallas: productos.php -->
<!-- resumen: Límites de cada producto y tasas por vigencia. -->

Cada línea de crédito es un producto con sus límites y sus tasas. Sin una tasa vigente, un producto no se puede simular ni liquidar: el listado de productos lo avisa.

Cada producto tiene: plazo mínimo y máximo (cuotas), monto mínimo y máximo, IVA sobre intereses y gastos de otorgamiento (un % del capital que se descuenta del desembolso).

### Tasas

Las tasas **no se editan**: cuando cambian, se carga una nueva con su fecha de vigencia y, si hace falta, su rango de plazo y monto.

- Rige la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos.
- Una tasa con fecha futura aparece como *Futura* y empieza a regir sola ese día.
- Si la punitoria queda vacía, se usa el 50 % de la TNA (parámetro `PUNITORIO_PCT_TNA`).
- Una tasa solo se puede borrar si ningún préstamo la usó.

El préstamo guarda la tasa con la que se liquidó: cambiar las tasas después no modifica los préstamos ya dados.

## Documentación requerida
<!-- pantallas: documentos_tipos.php -->
<!-- resumen: El catálogo de documentos del legajo y su vigencia. -->

El catálogo de lo que se le pide al cliente: nombre de cada documento, a quién se le pide (personas, empresas o ambos), si **para aprobar** es obligatoria u opcional, vigencia en días, orden y si está activo. Para agregar uno, completá el último renglón y **Guardar cambios**.

- **Obligatoria**: sin ese documento cargado y sin vencer, una solicitud de préstamo **no se puede aprobar**. Es también lo que la ficha del cliente marca en rojo si falta.
- **Opcional**: se pide y se ve en la solicitud, pero no frena la aprobación.

El cambio vale desde ese momento para todas las solicitudes pendientes. La vigencia calcula el vencimiento cuando al subir el archivo no se pone uno.

## Cotizaciones
<!-- pantallas: cotizaciones.php -->
<!-- resumen: Tipo de cambio oficial del BCRA, actualización y registro. -->

La tabla de cotizaciones guarda, por día y moneda, el tipo de cambio oficial del BCRA (Comunicación A 3500 para el dólar). Se usa para los equivalentes en pesos de Inversiones y queda como histórico consultable.

Una tarea programada de Windows la actualiza de lunes a viernes a las 10:00, 16:30 y 19:30. Además, al abrir *Cotizaciones*, si la última actualización tiene más de 4 horas, se intenta actualizar en el momento. Los fines de semana y feriados no hay cotización: el sistema usa la del último día hábil anterior (se indica *al dd/mm*).

- **Tarjetas** con la última cotización de cada moneda seguida (parámetro `COTIZACIONES_MONEDAS`) y la variación contra el día anterior.
- **Tabla** del período elegido (desde / hasta, moneda). *Exportar a Excel* trae lo filtrado.
- **Actualizar ahora** trae lo del día sin esperar a la tarea.
- **Cargar histórico**: carga los últimos N días (útil la primera vez o si el servidor estuvo apagado).
- **Registro**: cada actualización con fecha, quién la hizo (tarea o usuario), cuántas cotizaciones trajo y el error si lo hubo.

| Síntoma | Qué hacer |
| --- | --- |
| *Sin cotización* en inversiones | **Cargar histórico** con los días que falten |
| El registro muestra errores de conexión | El servidor no tiene salida a internet o hay un problema de certificados (manual de Administración) |
| No hay corridas de la tarea en el registro | Revisar que la tarea esté instalada y mirar `cron\cotizaciones.log` |
