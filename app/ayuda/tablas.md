# Tablas y parámetros
<!-- orden: 7 -->

Los datos de la empresa, los tipos de asociado con sus tasas y sus escalas de comisión, los organismos y sus reparticiones, qué documentación se le pide al cliente, los talonarios de comprobantes y las cotizaciones del BCRA.

## Datos de la empresa
<!-- pantallas: empresa.php -->
<!-- resumen: Razón social, CUIT, domicilio, WhatsApp Business y logo. -->

Los datos de la financiera: razón social, nombre de fantasía, CUIT, condición frente al IVA, ingresos brutos, domicilio y contacto. Salen en el encabezado del sistema, en la pantalla de ingreso y arriba a la derecha de **todos los PDF** (recibos, planes de pago, liquidaciones, simulaciones). El nombre de fantasía es el que se ve en el sistema; si queda vacío, se usa la razón social.

Con **PDF de muestra** se ve cómo queda el encabezado antes de imprimir un recibo de verdad.

### Logo

PNG (mejor con fondo transparente) o JPG, hasta 1 MB y 2000 px por lado. Rinde más un logo apaisado de unos 600 px de ancho. Al elegir el archivo se ve cómo queda en el PDF (fondo blanco) y en el encabezado (fondo azul, dentro de un recuadro blanco) antes de guardar. Para sacarlo, tildá **Quitar el logo** y guardá.

### Ícono de la app

Con **Usar el logo como ícono de la app** tildado, el logo es también el ícono de la aplicación instalada en el celular y el de la pestaña del navegador. Se ve cómo queda en iPhone/PC (cuadrado) y en Android (redondo), y se puede elegir el color de fondo. Al logo se le saca solo el blanco que tenga alrededor. Si entero se ve chico (pasa con los logos apaisados), con **Recorte** se elige qué parte usar: «Desde» y «Hasta» marcan la franja, de izquierda a derecha, y esa parte se muestra entera y centrada (por ejemplo, solo el isotipo). **Logo entero** vuelve a usarlo completo. Sin logo, o destildado, queda el ícono estándar. Un celular que ya la tenía instalada toma el ícono nuevo solo en Android (puede tardar un día); en iPhone hay que volver a agregarla a la pantalla de inicio.

### WhatsApp Business

- **Número de WhatsApp**: el de atención de la financiera. Se escribe como se dice acá, con la característica y sin el 0 ni el 15 (*11 2345-6789*); el sistema lo guarda en formato internacional (+54 9 …). De otro país, con + y el código. **Probar el número** abre un chat para comprobarlo. Sale en los PDF.
- **Mensaje de saludo**: el texto con que arranca el chat al escribirle a un cliente. `{cliente}` y `{empresa}` se reemplazan solos.
- **API de WhatsApp Cloud**: el *Phone Number ID* y el *WABA ID* solo hacen falta para mandar avisos desde el sistema (recordatorios de vencimiento, recibos). El token de acceso no se carga acá: va en `config.php` (`whatsapp_token`) o en la variable `FIN_WA_TOKEN` de Railway. La pantalla muestra si está configurado.

## Tipos de asociado y tasas
<!-- pantallas: tipos_asociado.php -->
<!-- resumen: Límites de cada tipo de asociado, tasas por vigencia y escalas de comisión. -->

El tipo de asociado (NACION, REGGIO DC, HABERES ACTIVOS…, los de las planillas de comisiones) define con qué límites, qué tasa y qué comisión se presta. El cliente tiene uno habitual y cada solicitud y préstamo guarda el suyo. Sin una tasa vigente, un tipo no se puede simular ni liquidar; sin escala de comisión, sus préstamos no generan comisión. El listado avisa los dos casos.

Cada tipo de asociado tiene: plazo mínimo y máximo (cuotas), monto mínimo y máximo, IVA sobre intereses. Los gastos que se le cobran al cliente se cargan en la solapa **Gastos** de cada tipo. El listado muestra además cuántos gastos cobra, la TNA y la comisión vigentes, y cuántos clientes y préstamos tiene; se busca por código o nombre y se filtra por estado.

- El lápiz abre la ficha del tipo, con cinco solapas: **Datos**, **Tasas**, **Escalas de comisión**, **Gastos** y **Excepciones**. Al crear un tipo nuevo, la ficha sigue en *Tasas* para cargar la primera.
- La cruz lo **desactiva**: deja de ofrecerse en clientes, solicitudes y préstamos; lo que ya lo tiene lo conserva. El tilde lo vuelve a activar.
- **Eliminar** (tacho) solo aparece si ningún cliente, solicitud ni préstamo lo usa; se borra con sus tasas y escalas.

### Tasas

Solapa **Tasas**. Las tasas **no se editan**: cuando cambian, se carga una nueva con **Nueva tasa** (el formulario se abre arriba de la lista), con su fecha de vigencia y, si hace falta, su rango de plazo y monto.

- Rige la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos.
- Una tasa con fecha futura aparece como *Futura* y empieza a regir sola ese día.
- Si la punitoria queda vacía, se usa el 50 % de la TNA (parámetro `PUNITORIO_PCT_TNA`).
- Una tasa solo se puede borrar si ningún préstamo la usó.

El préstamo guarda la tasa con la que se liquidó: cambiar las tasas después no modifica los préstamos ya dados.

### Escalas de comisión

Solapa **Escalas de comisión**: lo que cobra el vendedor por un préstamo de ese tipo, para todos los vendedores. Cada renglón es un tramo del monto del préstamo. **Nueva escala** abre el formulario arriba de la lista; el lápiz edita una.

| Dato | Qué es |
| --- | --- |
| Monto desde / Hasta | El tramo del monto prestado; *Hasta* vacío = sin tope |
| % comisión | El porcentaje, por ejemplo `18` |
| % con telemarketing | El que se usa si la venta vino por telemarketing; vacío = el mismo |
| Sobre | Capital del préstamo o neto desembolsado |
| Rige desde / Hasta | Vigencia; *Hasta* vacío = sin fin |
| Préstamos | Cuántos préstamos se liquidaron con esa escala |

La cruz **da de baja** una escala (deja de usarse para lo que se liquide de ahora en más) y el tilde la vuelve a activar; las dadas de baja se ven con el filtro *Estado*. El tacho solo aparece si ningún préstamo se liquidó con ella.

Por ejemplo, NACION: de $ 0 a $ 500.000 el 18 % (9 % con telemarketing) y desde $ 500.000,01 el 20 % (10 %). Dos escalas activas no pueden cubrir el mismo monto en las mismas fechas: el sistema lo avisa. Si quedan montos sin escala, la ficha dice cuáles (no generan comisión).

**Un aumento** se carga cerrando la escala vieja (*Hasta* = el día anterior) y agregando otra desde la fecha nueva, así queda el historial. El préstamo guarda su % y su monto: cambiar una escala no modifica lo ya liquidado.

La solapa **Excepciones** lista los vendedores que cobran distinto en ese tipo. Se cargan en la ficha del vendedor (*Comisiones* → *Vendedores*); el lápiz lleva ahí.

## Organismos
<!-- pantallas: organismos.php -->
<!-- resumen: Quién paga los haberes: ANSES, IPS, Pensiones graciables… Agrupan las reparticiones. -->

Los organismos que pagan los haberes del asociado (ANSES, IPS, Pensiones graciables, Ministerio de Educación, Policía, Caja de Policía…). Cada repartición es de uno, y en la ficha del cliente las reparticiones salen agrupadas por organismo.

- **Nuevo organismo** abre el formulario arriba de la lista; el lápiz lo edita.
- La lista dice cuántas reparticiones y clientes tiene cada uno; el ojo (o el número de reparticiones) abre sus reparticiones.
- La cruz lo **deshabilita**: deja de ofrecerse al cargar una repartición y las que tiene lo conservan. El tilde lo vuelve a habilitar.
- **Eliminar** (tacho) solo aparece si no tiene reparticiones.

## Reparticiones
<!-- pantallas: reparticiones.php -->
<!-- resumen: Dónde cobra el asociado, cada una de su organismo. -->

Las reparticiones de las planillas de comisiones (ANSES MINIMA 0 a 9, ANSES MAXIMA 0-1…, PENSION GRACIABLE, IPS 123, 456 y 789, MINISTERIO DE EDUCACION, POLICIA, CAJA POLICIA…). Se eligen en la ficha del cliente y no dependen del tipo de asociado: la misma repartición aparece con varios tipos.

- **Nueva repartición** abre el formulario arriba de la lista: nombre (queda en mayúsculas), **organismo** y si está habilitada. El lápiz la edita.
- La lista se filtra por organismo y por estado, y busca por nombre. *Clientes* dice cuántos la tienen y abre el listado de clientes filtrado; *En planillas*, en cuántos renglones de las planillas de comisiones figura.
- La cruz la **deshabilita**: deja de ofrecerse en la ficha del cliente; los clientes que la tienen la conservan.
- **Eliminar** (tacho) solo aparece si ningún cliente la tiene y no figura en las planillas.

### Gastos del tipo de asociado

Qué gastos se le cobran al cliente al liquidar un préstamo de este tipo, y con qué valores. Dos tipos pueden cobrar el mismo concepto con distinto % o importe.

- **Agregar gasto**: se elige un concepto del catálogo y propone sus valores sugeridos, que se pueden cambiar.
- **Se calcula como**: *importe fijo* ($ por préstamo) o *% del capital* (sobre el monto pedido), con importe mínimo y máximo opcionales.
- **Cómo se cobra**: *se descuenta del desembolso* (el cliente recibe el monto menos el gasto; las cuotas no cambian) o *se financia en las cuotas* (se suma al capital del cuadro y se paga en las cuotas, con interés).
- **Copiar de otro tipo**: trae los gastos de otro tipo con sus valores; después se cambian los que sean distintos. Los que el tipo ya tenía pueden quedar como estaban o reemplazarse.
- La cruz deja de cobrarlo sin perder los valores; el tacho lo quita del tipo.
- Los cambios valen para lo que se simule y liquide desde ese momento: el préstamo guarda los gastos con los que se liquidó.
- La comisión del vendedor no se calcula sobre los gastos financiados; con base *neto desembolsado*, se restan los descontados.

## Conceptos de gasto
<!-- pantallas: gastos_conceptos.php -->
<!-- resumen: El catálogo de gastos de los préstamos: nombre, IVA y valores sugeridos. -->

El catálogo de gastos que se le pueden cobrar al cliente (sellado, seguro, gastos administrativos…). Cada concepto tiene su nombre, si lleva IVA (y la alícuota) y los **valores sugeridos**, que se proponen al agregarlo a un tipo de asociado. Qué tipos lo cobran, y con qué valor cada uno, se carga en la solapa *Gastos* del tipo; la lista lo muestra en *Lo cobran*.

- Desactivar un concepto deja de cobrarlo en todos los tipos (sus valores quedan guardados).
- **Eliminar** solo aparece si nunca se cobró en un préstamo; lo quita también de los tipos.

## Documentación requerida
<!-- pantallas: documentos_tipos.php -->
<!-- resumen: El catálogo de documentos del legajo y su vigencia. -->

El catálogo de lo que se le pide al cliente: nombre de cada documento, a quién se le pide (personas, empresas o ambos), si **para aprobar** es obligatoria u opcional, vigencia en días, orden y si está activo.

- **Nuevo documento** abre el formulario arriba de la lista; el lápiz edita uno.
- La lista busca por nombre y se filtra por destinatario, por obligatorio u opcional y por estado.
- La cruz lo **desactiva**: deja de pedirse; lo cargado en los legajos queda. El tilde lo vuelve a activar.
- **Eliminar** (tacho) solo aparece si ningún cliente lo tiene cargado.

- **Obligatoria**: sin ese documento cargado y sin vencer, una solicitud de préstamo **no se puede aprobar**. Es también lo que la ficha del cliente marca en rojo si falta.
- **Opcional**: se pide y se ve en la solicitud, pero no frena la aprobación.

El cambio vale desde ese momento para todas las solicitudes pendientes. La vigencia calcula el vencimiento cuando al subir el archivo no se pone uno.

## Talonarios
<!-- pantallas: talonarios.php -->
<!-- resumen: La numeración de cada comprobante: serie, rango, por dónde va y si lo autoriza ARCA. -->

Cada **talonario** es una serie de comprobantes: su letra, su punto de venta, el rango de números habilitado y el **último emitido** (por dónde va). Se ordenan en tres niveles, como en gestion_adm:

- **Tipo de comprobante**: Factura, Nota de Débito, Nota de Crédito, Recibo… Dice de qué lado de la cuenta del cliente va: **al debe** suma a lo que debe (factura, nota de débito); **al haber** lo baja (nota de crédito, recibo). Se consultan en la solapa *Tipos de comprobante*.
- **Clasificación**: agrupa talonarios de un tipo («Recibos de cobranza», «Facturas de intereses»). Se cargan en la solapa *Clasificaciones* con **Nueva clasificación** (el lápiz edita una); una con talonarios no se borra.
- **Talonario**: la serie concreta. Se carga con **Nuevo talonario**: clasificación, nombre, letra, punto de venta, número desde y hasta, y el último emitido (0 si no se emitió ninguno). Abajo del formulario se ve el próximo número, por ejemplo `X 00001-00000001`.

**Talonario de ARCA**. Si se elige un **tipo de ARCA** (Factura B, Nota de Crédito A…), el talonario pide CAE por web service y su numeración es la de ARCA. Hay que elegir el **ambiente** (homologación para pruebas, producción para los comprobantes de verdad) y, si hace falta, el concepto (por defecto, servicios). Las reglas:

- La **letra** es la del tipo: se pone sola al elegirlo. La empresa responsable inscripta emite A y B; la monotributista, C.
- La **clasificación** tiene que ir del mismo lado que el tipo: una nota de crédito o un recibo en una clasificación al haber; una factura o una nota de débito, al debe. Si no coincide, avisa en rojo.
- Uno solo por **punto de venta, tipo y ambiente**: ARCA lleva una sola numeración por cada uno.
- Tiene que ser **nuevo**: uno que ya emitió con numeración propia no pasa a ARCA (sus números chocarían con los de ARCA).
- Ya usado, no se le cambia el punto de venta, la letra, el tipo ni el ambiente, ni se le saca el tipo.

Sin tipo de ARCA, el talonario numera solo (recibos internos, comprobantes X). Los **recibos de cobranza** salen de un talonario así: habilitado y de una clasificación de tipo Recibo (ver *Cobranzas*). Si hay uno solo, se usa ese; con varios, se elige al cobrar.

Un talonario que ya emitió tiene la **serie fija**: no se le cambia la letra, el punto de venta ni el número desde (están impresos en los comprobantes, y los próximos podrían chocar con los de otro talonario). Para otra serie, otro talonario.

| Qué pasa | Por qué |
| --- | --- |
| «El rango se pisa con el talonario…» | Otro talonario de la misma clasificación, punto de venta y letra ya tiene esos números |
| «El último emitido no puede volver…» | Los comprobantes con esos números ya existen: la numeración solo avanza |
| «Ya emitió comprobantes: no se le puede cambiar…» | La serie de un talonario usado queda fija: para otra, creá un talonario nuevo |
| No aparece el tacho | El talonario ya emitió: se **deshabilita** (la cruz) para que no se ofrezca más; lo emitido queda |
| La barra de uso está en naranja | Le queda menos del 10 % del rango: conviene crear el siguiente antes de que se corte la emisión |

Hoy numeran los **recibos de cobranza**. La emisión de comprobantes con CAE todavía no está: los talonarios de ARCA quedan listos para cuando esté.

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
