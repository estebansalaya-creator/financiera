# Comisiones y vendedores
<!-- orden: 5 -->

Quiénes venden, qué porcentaje cobra cada uno por producto, y cuánto le corresponde por período.

## Vendedores
<!-- pantallas: vendedores.php -->
<!-- resumen: Alta, edición, baja y reactivación de vendedores, zonas y supervisores. -->

*Comisiones* → **Vendedores**. La lista muestra cada vendedor con su vínculo, zona, supervisor, cuántos clientes y préstamos vigentes tiene y cuántas reglas de comisión le rigen hoy (*sin reglas* en amarillo: si liquida un préstamo, no cobra comisión salvo que se cargue una manual).

1. **Nuevo vendedor**.
2. **Código**: el que figura en las planillas de comisiones (ALONSO, AMTAE…). Sin espacios y único; se guarda en mayúsculas.
3. **Nombre**, **CUIT/CUIL** (opcional; si se carga, se valida el dígito y no se puede repetir), **vínculo** (comisionista o empleado) y **celular** (con la característica, sin 0 ni 15; en la lista es un enlace a WhatsApp).
4. **Zona**: elegí una o escribí una nueva en *o zona nueva*; se crea al guardar.
5. **Supervisor** (otro vendedor activo) y **Usuario del sistema**, si el vendedor entra al backoffice. Un usuario se vincula a un solo vendedor.
6. **Dar de alta**. Te lleva a la solapa *Reglas de comisión* para cargarle sus porcentajes.

**Baja y reactivación.** La cruz roja de la lista lo da de baja: deja de ofrecerse al cargar clientes y préstamos, y todo lo que tiene sigue a su nombre. El tilde verde lo reactiva. También se puede destildar *Activo* en la ficha.

**Eliminar** (tacho) solo aparece si el vendedor no tiene clientes, préstamos, comisiones ni vendedores a cargo: es para un alta hecha por error. Se borra con sus reglas.

## Reglas de comisión de un vendedor
<!-- resumen: Porcentaje por producto y telemarketing, base, prioridad y vigencia. -->

En la ficha del vendedor, solapa **Reglas de comisión** (o el ojo de la lista). Cada renglón es una regla:

| Columna | Qué es |
| --- | --- |
| Producto | El producto al que se aplica; *Todos los productos* vale para cualquiera |
| Telemarketing | *Sí*: solo ventas por telemarketing; *No*: solo las que no; *Cualquiera*: las dos |
| Cuotas | Rango de cuotas del préstamo (1 a 999 = cualquiera) |
| % comisión | El porcentaje, por ejemplo `2,5` |
| Base | Capital del préstamo o neto desembolsado |
| Prioridad | Si dos reglas coinciden, gana la de número mayor |
| Rige desde / Hasta | Vigencia; *Hasta* vacío = sin fin |
| Activa | Destildada, la regla no se usa |
| Préstamos | Cuántos préstamos ya devengaron comisión con esa regla |

Para agregar una regla, completá el último renglón (*nueva*) y **Guardar reglas**. Se guardan todas juntas.

**Un aumento** conviene cargarlo cerrando la regla vieja (*Hasta* = el día anterior) y agregando otra desde la fecha nueva, así queda el historial. Cambiar el % de una regla no modifica los préstamos ya liquidados: cada uno guardó su % y su monto.

## Cómo se calcula la comisión de un préstamo
<!-- resumen: Qué regla gana y qué pasa con lo ya liquidado. -->

Al liquidar, el sistema busca la **regla de comisión** que corresponde y guarda en el préstamo el % y el monto. Si después cambian las reglas, lo ya devengado no se toca.

- Una regla puede ser general o para un producto, un vendedor, una zona, un rango de plazo o de monto, y con o sin telemarketing.
- Si varias aplican, gana la de mayor prioridad (a igual prioridad, la de vigencia más reciente). Ejemplo: general 3 %; producto PERSONAL 4 %; vendedor X en PERSONAL 5 %.
- La base es el capital o el neto desembolsado, según la regla.
- La *comisión manual* del alta de préstamo reemplaza a la regla (sobre el capital).
- Si el préstamo se anula, la comisión se revierte.

## Comisiones de vendedores
<!-- pantallas: comisiones.php -->
<!-- resumen: Resumen por vendedor, subtotales por %, detalle, Excel y PDF. -->

Muestra, por período, cuánto le corresponde a cada vendedor: el historial importado de las planillas del sistema anterior y las comisiones de los préstamos liquidados en este sistema.

1. Elegí el **período** (mes) y, si querés, un **vendedor**.
2. Con *Todos*: el **resumen por vendedor** (registros, monto, comisión, % promedio). La observación avisa cuando a un vendedor se le aplicó más de un %.
3. **Subtotales por %**: cuánto se pagó a cada porcentaje.
4. El ojo de un vendedor abre su **detalle**: cada operación con fecha, tipo de asociado, apellido y nombre, repartición, monto, % y si fue por telemarketing.

**Filtros:** tipo de asociado, telemarketing (SI / NO), % de comisión y búsqueda por apellido, nombre o repartición. Se combinan, se aplican a tarjetas, resumen, subtotales y detalle, y se mantienen al entrar al detalle de un vendedor. *Limpiar filtros* deja solo período y vendedor.

**Exportar:**

- **Exportar Excel**: lo que se está viendo, con los mismos filtros (quedan anotados debajo del título). Hojas *Resumen*, *Detalle* y *Subtotales por %*.
- **Generar PDF**: abre lo que se está viendo para guardar o imprimir.
- En el resumen, cada vendedor tiene sus propios botones de PDF y Excel, para mandárselo a cada uno.
