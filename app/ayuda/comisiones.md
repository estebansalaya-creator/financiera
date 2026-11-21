# Comisiones y vendedores
<!-- orden: 5 -->

Quiénes venden, cuándo cobran distinto que la escala del tipo de asociado, y cuánto le corresponde a cada uno por período.

## Vendedores
<!-- pantallas: vendedores.php -->
<!-- resumen: Alta, edición, baja y reactivación de vendedores, zonas y supervisores. -->

*Comisiones* → **Vendedores**. La lista muestra cada vendedor con su vínculo, zona, supervisor, cuántos clientes y préstamos vigentes tiene y cuántas excepciones de comisión le rigen hoy (— si cobra todo por las escalas generales).

1. **Nuevo vendedor**.
2. **Código**: el que figura en las planillas de comisiones (ALONSO, AMTAE…). Sin espacios y único; se guarda en mayúsculas.
3. **Nombre**, **CUIT/CUIL** (opcional; si se carga, se valida el dígito y no se puede repetir), **vínculo** (comisionista o empleado) y **celular** (con la característica, sin 0 ni 15; en la lista es un enlace a WhatsApp).
4. **Zona**: elegí una o escribí una nueva en *o zona nueva*; se crea al guardar.
5. **Supervisor** (otro vendedor activo) y **Usuario del sistema**, si el vendedor entra al backoffice. Un usuario se vincula a un solo vendedor.
6. **Datos bancarios**: CBU o CVU y alias, para pagarle las comisiones. Se validan igual que en el cliente. En la lista van debajo del CUIT, y en *Comisiones* debajo del nombre de cada vendedor (el Excel del resumen los trae en dos columnas, para armar las transferencias).
7. **Dar de alta**. Desde ese momento cobra por las escalas de cada tipo de asociado; si tiene un % distinto, se carga en la solapa *Excepciones de comisión*.

**Baja y reactivación.** La cruz roja de la lista lo da de baja: deja de ofrecerse al cargar clientes y préstamos, y todo lo que tiene sigue a su nombre. El tilde verde lo reactiva. También se puede destildar *Activo* en la ficha.

**Eliminar** (tacho) solo aparece si el vendedor no tiene clientes, préstamos, comisiones ni vendedores a cargo: es para un alta hecha por error. Se borra con sus excepciones.

## Excepciones de comisión de un vendedor
<!-- resumen: Cuando un vendedor cobra distinto que la escala del tipo de asociado. -->

La comisión sale de las **escalas de cada tipo de asociado** (*Tablas y parámetros* → *Tipos de asociado y tasas*), las mismas para todos los vendedores. En la ficha del vendedor, solapa **Excepciones de comisión** (o el ojo de la lista), se cargan solo los casos en que cobra distinto. **Nueva excepción** abre el formulario arriba de la lista; el lápiz edita una. Cada excepción tiene:

| Dato | Qué es |
| --- | --- |
| Tipo de asociado | El tipo al que se aplica |
| Monto desde / Hasta | El tramo del monto prestado; *Hasta* vacío = sin tope |
| % comisión / % con telemarketing | Los porcentajes; el de telemarketing vacío = el mismo |
| Sobre | Capital del préstamo o neto desembolsado |
| Rige desde / Hasta | Vigencia; *Hasta* vacío = sin fin |
| Préstamos | Cuántos préstamos se liquidaron con esa excepción |

La cruz **da de baja** una excepción (desde ese momento el vendedor cobra por la escala general en ese tramo) y el tilde la vuelve a activar. La lista muestra las activas: el filtro *Estado* trae las dadas de baja o todas. El tacho solo aparece si ningún préstamo se liquidó con ella.

Debajo, **Lo que cobra hoy** muestra, por tipo de asociado, la escala general y la del vendedor si tiene una excepción. Desde cada renglón, el ojo abre las escalas del tipo y el **+** carga una excepción en ese tipo. Una excepción que cubre solo un tramo de montos vale para ese tramo; el resto sigue por la general.

## Cómo se calcula la comisión de un préstamo
<!-- resumen: Qué escala se usa y qué pasa con lo ya liquidado. -->

Al liquidar, el sistema busca la escala que corresponde y guarda en el préstamo el % y el monto. Si después cambian las escalas, lo ya devengado no se toca. La pantalla de liquidación muestra antes, en *Comisión del vendedor*, cuánto va a cobrar y de dónde sale.

1. Si el vendedor tiene una **excepción** activa y vigente para el tipo de asociado del préstamo y su monto, se usa esa.
2. Si no, la **escala general** del tipo de asociado para ese monto.
3. Si el préstamo vino por **telemarketing**, se usa el % de telemarketing de esa escala.
4. Si ninguna escala cubre el monto, el préstamo no genera comisión.

- La base es el capital o el neto desembolsado, según la escala.
- La *comisión manual* del alta de préstamo reemplaza a la escala (sobre el capital).
- Si el préstamo se anula, la comisión se revierte.

Las escalas de junio de 2026 se armaron con las planillas de comisiones: para cada tipo, el % que se usó en la mayoría de las operaciones; los vendedores que cobraron otro % (ARROYO en NACION y OTROS BANCOS, ALMIRON en NACION y REGGIO DC) quedaron como excepción.

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
