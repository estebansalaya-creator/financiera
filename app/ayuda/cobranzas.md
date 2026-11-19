# Cobranzas
<!-- orden: 4 -->

Los cobros se registran uno por uno en *Cobrar* o en lote desde un archivo. Cada pago genera un recibo (`R-00000001`…) y se imputa solo.

## Cobrar un préstamo
<!-- pantallas: cobrar.php -->
<!-- resumen: Pago a cuenta o cancelación total, con recibo. -->

1. *Cobranzas* → **Cobrar** (o **Cobrar** en la ficha del préstamo).
2. Buscá por N° de préstamo, CUIT, DNI o nombre. Si el cliente tiene más de un préstamo vigente, elegí cuál.
3. Arriba: deuda vencida (con punitorios), próxima cuota, deuda total y **cancelación total** con lo que se ahorra el cliente.
4. *Fecha del pago*: hoy por defecto. Si la cambiás, se recalcula todo (punitorios incluidos).
5. Elegí el tipo:
   - **Pago a cuenta**: escribí el importe o usá los botones *Deuda vencida* / *Cuota N*. Puede ser parcial.
   - **Cancelación total**: el importe se completa solo con el monto exacto de ese día y no se puede cambiar.
6. Medio (efectivo, transferencia, Rapipago…) y referencia (N° de operación, cheque).
7. **Registrar pago** → confirmar. Se abre el recibo.

**Cómo se imputa:** de la cuota más vieja a la más nueva y, dentro de cada una, primero IVA de punitorios, punitorios, IVA de interés, interés y por último capital.

**Cancelación anticipada:** se cobra el capital y el interés corrido hasta ese día; el interés futuro se condona. Si el cliente ya había pagado interés de una cuota que todavía no venció, lo pagado de más se descuenta del capital (aparece en movimientos como *Interés adelantado a capital*). El préstamo queda *Cancelado*.

El sistema no deja cobrar más que la deuda total ni registrar un pago con fecha anterior a otro ya cargado del mismo préstamo.

## Recibo
<!-- pantallas: recibo.php -->
<!-- resumen: Aplicación del pago, PDF y anulación. -->

Muestra cómo se aplicó el pago a cada cuota (capital, interés y punitorios) y el saldo actual del préstamo. **Recibo PDF** para entregar.

**Anular un pago:** desde el recibo, con motivo. Solo el último pago del préstamo (para anular uno anterior, primero hay que anular los posteriores). La deuda vuelve a como estaba; los punitorios ya devengados se mantienen.

## Pagos y caja del día
<!-- pantallas: cobranzas.php -->
<!-- resumen: Lo cobrado, totales por medio y anulados. -->

*Pagos y caja* muestra los pagos de hoy: total cobrado, totales por medio (para cerrar la caja) y anulados. Filtros por fechas, medio, estado, vendedor y búsqueda (recibo, préstamo, cliente, CUIT, referencia). *Exportar a Excel* trae todo lo filtrado.

## Importar un lote
<!-- pantallas: lote.php -->
<!-- resumen: Rapipago, Pago Fácil o banco desde un CSV, con vista previa. -->

1. *Cobranzas* → **Importar lote**.
2. Elegí el archivo CSV (desde Excel: *Guardar como → CSV*), el medio y la fecha para las filas que no la traigan.
3. **Ver vista previa**: fila por fila dice *Se carga* o el motivo por el que no (préstamo inexistente o no vigente, importe inválido, repetido, ya registrado, supera la deuda…).
4. **Procesar N pagos** → confirmar. Las filas con error no se cargan; cada pago genera su recibo. Después se ve el lote completo en el listado.

Formato del archivo (primera fila con los títulos; separado por `;` o `,`):

```
prestamo;fecha;importe;referencia
P-000012;18/11/2026;45.042,16;RP-88213
P-000027;18/11/2026;30000;RP-88214
```

En lugar de `prestamo` puede ir `cuit` (CUIT, CUIL o DNI) si el cliente tiene un solo préstamo vigente. Una cancelación total no se hace por lote: se registra desde *Cobrar*.
