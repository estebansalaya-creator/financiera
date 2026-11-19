# Tablas y parámetros
<!-- orden: 7 -->

Los productos con sus tasas, qué documentación se le pide al cliente y las cotizaciones del BCRA.

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

El catálogo de lo que se le pide al cliente: nombre de cada documento, a quién se le pide (personas, empresas o ambos), si es obligatorio, vigencia en días, orden y si está activo. Para agregar uno, completá el último renglón y **Guardar cambios**.

Los obligatorios son los que la ficha del cliente marca en rojo si faltan, y los que el alta de préstamo controla. La vigencia calcula el vencimiento cuando al subir el archivo no se pone uno.

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
