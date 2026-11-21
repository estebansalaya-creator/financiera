# Cobranzas
<!-- orden: 4 -->

Los cobros en efectivo se registran uno por uno en *Cobros en efectivo*, en lote desde un archivo o desde el **extracto del banco** (*Cobros bancarios*, por el CUIL de quien pagó). Cada cobro genera un recibo con el número del **talonario de recibos** (`X 00001-00000001`…) y se imputa solo. Un recibo puede cubrir varios préstamos del mismo cliente.

## Cobros en efectivo
<!-- pantallas: cobrar.php -->
<!-- resumen: Los préstamos con saldo, y el cobro en efectivo con recibo. -->

Solo para lo que el cliente paga **en efectivo**. Lo que entra por el banco se emite en *Cobros bancarios* (desde el extracto) y lo de Rapipago, Pago Fácil y otras recaudadoras en *Importar lote*.

1. *Cobranzas* → **Cobros en efectivo** (o **Cobrar en efectivo** en la ficha del préstamo).
2. La grilla muestra los **préstamos vigentes con saldo a pagar**: préstamo, cliente, cuotas impagas (y cuántas vencidas), próximo vencimiento, **deuda vencida**, **a vencer** y total. Lo vencido es a hoy, sin los punitorios del día: el cobro los calcula a la fecha. Se busca por préstamo, cliente, CUIT o DNI, y se filtra por deuda (con cuotas vencidas o al día) y vendedor. Cada encabezado ordena; **Exportar a Excel** trae todo lo filtrado.
3. La flecha de cada renglón **muestra el detalle de las cuotas** con saldo (vencimiento, atraso, capital, interés, punitorios y saldo); el ojo abre el préstamo para cobrar.
4. Arriba: deuda vencida (con punitorios), próxima cuota, deuda total y **cancelación total** con lo que se ahorra el cliente.
5. *Fecha del cobro*: hoy por defecto. Si la cambiás, se recalcula todo (punitorios incluidos).
6. Elegí el tipo:
   - **Pago a cuenta**: escribí el importe o usá los botones *Deuda vencida* / *Cuota N*. Puede ser parcial.
   - **Cancelación total**: el importe se completa solo con el monto exacto de ese día y no se puede cambiar.
7. La **caja** donde entra el efectivo (viene la habitual del efectivo; ver *Tesorería › Cuentas de fondos*) y, si hace falta, una observación.
8. **Recibo**: con un solo talonario de recibos habilitado se ve el próximo número y no hay nada que elegir; con más de uno, elegí el talonario.
9. **Registrar cobro en efectivo** → confirmar. Se abre el recibo. Arriba de la grilla, *Cobrado hoy en efectivo* suma los recibos del día.

**Cómo se imputa:** de la cuota más vieja a la más nueva y, dentro de cada una, primero IVA de punitorios, punitorios, IVA de interés, interés y por último capital.

**Cancelación anticipada:** se cobra el capital y el interés corrido hasta ese día; el interés futuro se condona. Si el cliente ya había pagado interés de una cuota que todavía no venció, lo pagado de más se descuenta del capital (aparece en movimientos como *Interés adelantado a capital*). El préstamo queda *Cancelado*.

El sistema no deja cobrar más que la deuda total ni registrar un pago con fecha anterior a otro ya cargado del mismo préstamo.

## Cobros bancarios
<!-- pantallas: emision_recibos.php -->
<!-- resumen: Los créditos del extracto bancario por CUIL, aplicados a las cuotas de todos los préstamos del cliente. -->

Como la emisión de recibos de planes de gestion_adm: los créditos del **extracto bancario** (importado en *Tesorería › Extractos bancarios*) se identifican por el CUIL que traen y se convierten en recibos.

1. *Cobranzas* → **Cobros bancarios**. Elegí la **cuenta bancaria** y el **período** (por defecto, el mes).
2. **Cobros identificados**: los créditos cuyo CUIL es de un cliente con préstamos vigentes. Por cada uno: lo **vencido** y lo que **vence en el período** a la fecha del crédito (con los punitorios a ese día), sumando todos los préstamos del cliente, a qué cuotas se aplica y el importe del recibo. El ojo muestra cuota por cuota cuánto se aplica.
3. Tildá los créditos, elegí el medio (y el talonario, si hay más de uno) y **Emitir recibos e imputar**.

**Cómo se aplica:** un recibo por crédito, a las cuotas de **todos** los préstamos vigentes del cliente, de la que vence primero a la última (entre préstamos) y, dentro de cada cuota, punitorios, interés y capital. El recibo tiene un renglón por préstamo y entra en el libro de fondos como un solo movimiento, por el importe del crédito. Si el crédito supera lo que debe el cliente, el recibo es por la deuda y lo que sobra queda disponible en el crédito. Con dos créditos del mismo cliente, se emiten por fecha: el segundo se aplica sobre lo que dejó el primero.

**Créditos sin cliente:** sin CUIL, con un CUIL que no es de un cliente (el cónyuge, la empresa que paga por él), de un cliente sin préstamos vigentes, o anteriores a sus préstamos. Se asignan escribiendo el CUIT/CUIL o el DNI del cliente. Si el crédito trae un CUIL, queda una **regla**: los próximos créditos con ese CUIL van solos a ese cliente (en la grilla, *regla*). Desde el lápiz de un cobro identificado se lo asigna a otro cliente, se lo vuelve al CUIL del extracto o se quita la regla. El CUIT de la financiera no cuenta como CUIL: es un depósito en ventanilla.

**Deudores sin cobro:** los clientes con cuotas vencidas o que vencen en el período y sin un crédito identificado arriba, para ver quién no pagó. Lo vencido es a hoy, sin los punitorios que se devengan al cobrar.

| Qué pasa | Por qué |
| --- | --- |
| El crédito está gris con «tiene un pago … posterior al crédito» | Un préstamo del cliente ya tiene un cobro con fecha posterior: el recibo no puede imputarse con una fecha anterior |
| «El crédito del extracto ya tiene su recibo» | Otra persona lo emitió al mismo tiempo: no se emite dos veces |
| «Los préstamos … se liquidaron después del crédito» | El crédito es anterior a los préstamos: no es el pago de una cuota |

## Recibo
<!-- pantallas: recibo.php -->
<!-- resumen: Aplicación del pago, PDF y anulación. -->

Muestra cómo se aplicó el recibo a las cuotas de cada préstamo (capital, interés y punitorios) y su saldo actual. **Recibo PDF** para entregar. Si salió del extracto, dice de qué crédito y qué CUIL pagó.

**Anular un recibo:** desde el recibo o desde el tacho de *Recibos emitidos*, con motivo. Se anula **entero** (todos sus préstamos), y cada uno de sus pagos tiene que ser el último del préstamo (para anular uno anterior, primero hay que anular los posteriores). La deuda vuelve a como estaba; los punitorios ya devengados se mantienen. El recibo anulado conserva su número: no se vuelve a usar. Si salió de un crédito del extracto, el crédito vuelve a quedar para emitir.

**El número del recibo** sale del talonario de recibos (*Tablas y parámetros › Talonarios*: un talonario habilitado, con numeración propia, de una clasificación de tipo Recibo). Se toma al grabar el pago: dos cobros al mismo tiempo nunca sacan el mismo número y, si el cobro no se graba, el número no se pierde. Un recibo letra X dice en el PDF «Documento no válido como factura». Sin ningún talonario de recibos, el recibo sale `R-00000123`, como los de antes.

| Qué pasa | Por qué |
| --- | --- |
| «Elegí el talonario del recibo» | Hay más de un talonario de recibos habilitado |
| «El talonario … está agotado» | Llegó al último número de su rango: cargá uno nuevo en *Talonarios* |

## Recibos emitidos
<!-- pantallas: cobranzas.php -->
<!-- resumen: Un renglón por recibo: lo cobrado, por medio, y la anulación. -->

*Cobranzas* → **Recibos emitidos** muestra un renglón por recibo, con los préstamos a los que se aplicó, de dónde salió (*Cobros en efectivo* —cobro manual—, un *lote* o *Cobros bancarios*) y la cuenta donde entró la plata. Es la consulta: para emitir están *Cobros en efectivo*, *Cobros bancarios* e *Importar lote*.

- Arriba: los recibos vigentes del filtro, el importe cobrado, lo que salió del extracto bancario y los anulados (que no suman).
- Filtros: búsqueda (cliente, CUIT, recibo, préstamo o referencia), fechas, medio, origen, vendedor, importe desde / hasta y estado (por defecto, los **vigentes**). Las fechas y los importes se aplican con **Filtrar**.
- **Nº recibo exacto** (`00001-00000123`, o solo el número) busca ese recibo y nada más, esté como esté: los demás filtros no cuentan.
- Cada encabezado ordena la grilla; **Exportar a Excel** trae todo lo filtrado, en el mismo orden.
- El tacho **anula** el recibo pidiendo el motivo, y la grilla vuelve con los mismos filtros. Si alguno de sus préstamos tiene un pago posterior, el tacho aparece apagado y al pasar el mouse dice cuál: primero hay que anular ese.
- Abajo, **Por medio** (para cerrar la caja: elegí las fechas del día) y los **últimos lotes** importados.

## Importar un lote
<!-- pantallas: lote.php -->
<!-- resumen: Rapipago, Pago Fácil o banco desde un CSV, con vista previa. -->

1. *Cobranzas* → **Importar lote**.
2. Elegí el archivo CSV (desde Excel: *Guardar como → CSV*), el medio, la fecha para las filas que no la traigan, la **cuenta de fondos** donde acredita la recaudadora (cada pago entra al libro de fondos en esa cuenta) y, si hay más de uno, el **talonario** de los recibos.
3. **Ver vista previa**: fila por fila dice *Se carga* o el motivo por el que no (préstamo inexistente o no vigente, importe inválido, repetido, ya registrado, supera la deuda…).
4. **Procesar N pagos** → confirmar. Las filas con error no se cargan; cada pago genera su recibo, con números seguidos del talonario en el orden de carga (por fecha). Después se ve el lote completo en el listado.

Formato del archivo (primera fila con los títulos; separado por `;` o `,`):

```
prestamo;fecha;importe;referencia
P-000012;18/11/2026;45.042,16;RP-88213
P-000027;18/11/2026;30000;RP-88214
```

En lugar de `prestamo` puede ir `cuit` (CUIT, CUIL o DNI) si el cliente tiene un solo préstamo vigente. Una cancelación total no se hace por lote: se registra desde *Cobros en efectivo*.
