# Créditos
<!-- orden: 3 -->

El simulador, el alta de préstamos en dos pasos (revisar y liquidar), el listado y la ficha de cada préstamo.

## Simulador
<!-- pantallas: simulador.php -->
<!-- resumen: Cuota, total, neto, tasas y CFT antes de dar el préstamo. -->

1. Elegí producto, monto y cantidad de cuotas.
2. Fecha de liquidación (hoy por defecto) y primer vencimiento (vacío = un mes después).
3. **Simular**. Muestra la primera cuota, el total a pagar, el neto que recibe el cliente, TNA, TEM, TEA y el CFT con IVA, y el cuadro de cuotas.
4. **Generar PDF** para entregarle al cliente o **Exportar a Excel** con el cuadro.

El simulador usa el mismo cálculo que la liquidación: lo que se simula es lo que se liquida. Todos los préstamos son sistema francés, cuotas mensuales. El CFT es una aproximación mensual al del BCRA, que cuenta días.

Si un producto no tiene una tasa vigente para ese plazo y monto, no se puede simular: se carga en *Tablas y parámetros* → *Productos y tasas*.

## Nuevo préstamo
<!-- pantallas: prestamo_nuevo.php -->
<!-- resumen: Revisar (cuadro y controles) y liquidar. -->

Un préstamo se da de alta en dos pasos: **Revisar** (cuadro y controles) y **Liquidar**. Lo que se revisa es exactamente lo que se liquida.

1. *Créditos* → **Nuevo préstamo** (o desde la ficha del cliente).
2. Cliente: escribí CUIT, CUIL o DNI y **Buscar**. Si es nuevo, primero hay que darlo de alta.
3. Producto (debajo muestra el rango de cuotas y montos), monto, cuotas, fecha de liquidación y primer vencimiento.
4. Vendedor: viene el del cliente; se puede cambiar. Tildá **Telemarketing** si la venta vino por ahí: cambia la regla de comisión que se aplica. *Comisión manual* solo para operaciones negociadas; vacío = según las reglas de comisión del vendedor.
5. **Revisar**. Aparecen los controles y el cuadro de cuotas con cuota, neto a desembolsar, total a pagar, TNA, TEA y CFT.

### Los controles

| Color | Qué significa | Ejemplos |
| --- | --- | --- |
| Rojo | No se puede liquidar | Cliente inactivo o bloqueado |
| Amarillo | Se puede liquidar confirmando y escribiendo el motivo | BCRA en situación 3 o peor, cheques sin pagar, documentación incompleta o vencida, préstamos en mora, cuotas por encima del 30 % del ingreso |
| Azul | Informa, no pide nada | Peor situación de los últimos 24 meses, préstamos vigentes al día |
| Verde | En orden | Sin deudas en el BCRA, documentación completa |

Si la última consulta al BCRA del cliente tiene más de 30 días, al revisar se consulta sola.

### Liquidar

1. Si hay advertencias: tildá *Revisé las advertencias y el préstamo se liquida igual* y escribí el motivo (por lo menos 10 letras). Queda guardado en el préstamo.
2. **Liquidar**. Se asigna el número (`P-000001`, `P-000002`…), se generan las cuotas y se devenga la comisión del vendedor.

Si cambiás algún dato después de revisar, el sistema pide revisar de nuevo antes de liquidar.

## Listado de préstamos
<!-- pantallas: prestamos.php -->
<!-- resumen: Tarjetas, filtros por estado y mora, exportación. -->

Tarjetas con cantidad, capital prestado, saldo de capital y préstamos en mora. Filtros por estado, mora (al día, en mora, 1-30, 31-60, 61-90, más de 90 días), producto, vendedor y fechas de liquidación. *Exportar a Excel* trae todo lo filtrado.

## Ficha del préstamo
<!-- pantallas: prestamo.php -->
<!-- resumen: Saldo, cuotas, pagos, comisión, plan de pagos y anulación. -->

- Estado, capital, saldo de capital y deuda vencida hoy (con punitorios) y monto para cancelar hoy.
- Datos y tasas, y los controles con los que se aprobó.
- Cuotas con lo pagado y lo pendiente; las vencidas en rojo.
- Pagos, comisión del vendedor y movimientos (cuenta corriente).
- **Plan de pagos PDF** para el cliente y **Cobrar** (va a Cobranzas).

### Anular un préstamo

Solo para errores de carga y solo si no tiene pagos. Al pie de la ficha: escribí el motivo → **Anular préstamo**. El saldo queda en cero y se revierte la comisión del vendedor (si ya se le había liquidado, se descuenta en la próxima). No se puede deshacer.
