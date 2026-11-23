# Créditos
<!-- orden: 3 -->

El simulador, la solicitud de préstamo, su autorización, la liquidación, el listado y la ficha de cada préstamo.

Un préstamo sigue tres pasos: **solicitud** (la carga el comercializador o un operador) → **autorización** (Gerencia la aprueba, la observa o la rechaza) → **liquidación** (un operador liquida la aprobada el día del desembolso; ahí nace el préstamo).

## Simulador
<!-- pantallas: simulador.php -->
<!-- resumen: Cuota, total, neto, tasas y CFT antes de dar el préstamo. -->

1. Elegí el tipo de asociado, monto y cantidad de cuotas.
2. Fecha de liquidación (hoy por defecto) y primer vencimiento (vacío = un mes después).
3. **Simular**. Muestra la primera cuota, el total a pagar, el neto que recibe el cliente, TNA, TEM, TEA y el CFT con IVA, y el cuadro de cuotas.
4. **Generar PDF** para entregarle al cliente o **Exportar a Excel** con el cuadro.

El simulador usa el mismo cálculo que la liquidación: lo que se simula es lo que se liquida. Todos los préstamos son sistema francés, cuotas mensuales. El CFT es una aproximación mensual al del BCRA, que cuenta días.

Si un tipo de asociado no tiene una tasa vigente para ese plazo y monto, no se puede simular: se carga en *Tablas y parámetros* → *Tipos de asociado y tasas*.

## Solicitudes
<!-- pantallas: solicitudes.php -->
<!-- resumen: Cargar una solicitud, seguirla, corregir las observadas. -->

*Créditos* → **Solicitudes**. Un comercializador ve las que cargó y las de su comercializador; Créditos y Gerencia ven todas. Las tarjetas de arriba filtran: esperan autorización, observadas, aprobadas sin liquidar y liquidadas.

**Cargar una solicitud** (también desde la ficha del cliente, *Nueva solicitud*):

1. Cliente: nombre (o parte, al menos 3 letras), CUIT, CUIL o DNI y **Buscar** (o Enter). Si hay una sola coincidencia queda elegido; si hay varias, aparece la lista y se hace click en el que es. Por nombre, un comercializador encuentra los clientes de su cartera (los suyos, los que cargó y los que ya tienen una solicitud suya); con el CUIT, CUIL o DNI, cualquiera. Si es nuevo, primero se da de alta en *Clientes*.
2. Tipo de asociado (viene el habitual del cliente y se puede cambiar), monto que pide y cuotas. Comercializador (un comercializador carga siempre a su nombre), **Telemarketing** si la venta vino por ahí, destino del préstamo y observaciones para quien autoriza.
3. **Revisar**: muestra la cuota estimada (liquidando hoy), los controles y la documentación.
4. **Enviar a autorización**. Queda *Pendiente* con su número, de año y orden: `2026-00000001` (cada año vuelve a empezar en 1). Con advertencias se puede enviar igual: quien autoriza las ve. Con un control en rojo, no.

Si cambiás un dato después de revisar, pide revisar de nuevo.

**Otra solicitud del mismo cliente**. Si el cliente ya tiene una solicitud **igual** (mismo tipo de asociado, monto y cuotas) que todavía espera decisión, no se carga de nuevo: el control en rojo dice cuál es, cuándo se cargó y quién la cargó. Pasa, por ejemplo, si dos comercializadores, o el comercializador y telemarketing, cargan la misma operación al mismo tiempo. Si tiene **otras** solicitudes en curso (pendientes, observadas o aprobadas sin liquidar), sale una advertencia con cada una y con cuánto sumarían las cuotas sobre el ingreso si se dieran todas.

| Estado | Qué significa | Qué se puede hacer |
| --- | --- | --- |
| Pendiente | Espera autorización | Modificarla, anularla |
| Observada | Volvió con un comentario | **Corregir y reenviar** (vuelve a Pendiente), anularla |
| Aprobada | Lista para liquidar | Liquidarla, anularla |
| Rechazada | No se da | Nada: si corresponde, se carga otra |
| Liquidada | Ya es un préstamo | Ver el préstamo |
| Anulada | Se dio de baja | Nada |

El botón **Instructivo** baja un PDF para repartir a los comercializadores: cómo entrar desde el celular, cómo cargar una solicitud y qué documentación pedirle al cliente. La lista de documentación sale de lo configurado en ese momento, así que si cambia alcanza con volver a bajarlo.

Abajo de cada solicitud está el **historial**: quién hizo cada paso, cuándo y qué comentó.

### Documentación

La solicitud lista cada documento que le corresponde al cliente y dice si es **obligatorio** u **opcional** y si está cargado, vencido o falta. La solicitud se puede enviar sin la documentación, pero **no se puede aprobar** hasta que todo lo obligatorio esté cargado y sin vencer. Se sube en la ficha del cliente. Qué es obligatorio se define en *Tablas y parámetros* → *Documentación requerida*.

## Autorizaciones
<!-- pantallas: autorizaciones.php -->
<!-- resumen: La cola de solicitudes: aprobar, observar o rechazar. -->

*Créditos* → **Autorizaciones** (Gerencia y Administrador). Muestra las solicitudes pendientes, la más vieja arriba, con el monto, la cuota, qué dijeron los controles y si la documentación obligatoria está completa. **Revisar** abre la solicitud; ahí se elige:

- **Aprobar**: queda lista para liquidar. Si hay advertencias, hay que escribir por qué se aprueba igual; queda guardado en el préstamo. No se puede aprobar si falta documentación obligatoria, si hay un control en rojo o si el cliente no está activo.
- **Observar**: vuelve al que la cargó con tu comentario (qué corregir o qué documentación falta). Cuando la reenvía, vuelve a la cola.
- **Rechazar**: es definitivo; pide el motivo.

Si mientras la mirabas alguien la modificó o la volvió a controlar, la decisión no se aplica y avisa que **cambió mientras la mirabas**: la pantalla ya muestra cómo quedó; revisala y decidí de nuevo. Así nunca se aprueba un monto o un plazo que no se vio.

**Volver a controlar** actualiza la cuota y los controles a hoy (por ejemplo, después de que el cliente regularizó algo).

Debajo están las aprobadas que falta liquidar y las últimas resueltas.

## Liquidar préstamo
<!-- pantallas: prestamo_nuevo.php -->
<!-- resumen: Liquidar una solicitud aprobada: fecha, revisar y liquidar. -->

Una solicitud **aprobada** se liquida desde su ficha o desde el listado (botón **Liquidar**). Cliente, tipo de asociado, monto, cuotas y comercializador vienen de la solicitud y no se cambian. *Revisar* muestra, además del cuadro, la comisión que va a cobrar el comercializador y si sale de la escala del tipo, de una excepción suya o de la comisión manual.

1. Fecha de liquidación (hoy por defecto) y primer vencimiento (vacío = un mes después). *Comisión manual* solo para operaciones negociadas; vacío = según las reglas de comisión del comercializador.
2. **Revisar**. Aparecen los controles a hoy y el cuadro de cuotas con cuota, neto a desembolsar, total a pagar, TNA, TEA y CFT.
3. **Desembolso**: el neto se paga con una **orden de pago** a nombre del cliente, que nace al liquidar. Debajo se ve la cuenta del cliente (su CBU y alias); si no los tiene cargados, avisa en rojo: cargalos en su ficha para que se le pueda transferir.
4. **Liquidar**. La solicitud pasa a *Liquidada* y queda unida a su préstamo, y la orden de pago queda *a pagar* en *Tesorería › Órdenes de pago*. La plata sale de la cuenta cuando Tesorería emite el pago; en la ficha del préstamo, junto al desembolso, se ve la orden y si ya se pagó.

Las advertencias ya las aceptó quien aprobó: no se vuelven a pedir. Un control en rojo (cliente bloqueado) sí impide liquidar.

**Solo se liquidan solicitudes aprobadas.** No hay liquidación directa, para nadie: la opción *Liquidar préstamo* del menú muestra las solicitudes aprobadas que esperan, y desde ahí se elige cuál liquidar.

### Los controles

| Color | Qué significa | Ejemplos |
| --- | --- | --- |
| Rojo | No se puede cargar, aprobar ni liquidar | Cliente inactivo o bloqueado |
| Amarillo | Se puede aprobar escribiendo el motivo | BCRA en situación 3 o peor, cheques sin pagar, documentación incompleta o vencida, préstamos en mora, cuotas por encima del 30 % del ingreso |
| Azul | Informa, no pide nada | Peor situación de los últimos 24 meses, préstamos vigentes al día |
| Verde | En orden | Sin deudas en el BCRA, documentación completa |

Si la última consulta al BCRA del cliente tiene más de 30 días, al revisar se consulta sola.

### Al liquidar

**Liquidar**. Se asigna el número (`P-000001`, `P-000002`…), se generan las cuotas y se devenga la comisión del comercializador.

Si cambiás algún dato después de revisar, el sistema pide revisar de nuevo antes de liquidar.

## Listado de préstamos
<!-- pantallas: prestamos.php -->
<!-- resumen: Tarjetas, filtros por estado y mora, exportación. -->

Tarjetas con cantidad, capital prestado, saldo de capital y préstamos en mora. Filtros por estado, mora (al día, en mora, 1-30, 31-60, 61-90, más de 90 días), tipo de asociado, comercializador y fechas de liquidación. *Exportar a Excel* trae todo lo filtrado.

## Ficha del préstamo
<!-- pantallas: prestamo.php -->
<!-- resumen: Saldo, cuotas, pagos, comisión, plan de pagos y anulación. -->

- Estado, capital, saldo de capital y deuda vencida hoy (con punitorios) y monto para cancelar hoy.
- Datos y tasas, y los controles con los que se aprobó.
- Cuotas con lo pagado y lo pendiente; las vencidas en rojo.
- Pagos, comisión del comercializador y movimientos (cuenta corriente).
- **Plan de pagos PDF** para el cliente y **Cobrar en efectivo** (va a Cobranzas › Cobros en efectivo).

### Anular un préstamo

Solo para errores de carga y solo si no tiene pagos. Al pie de la ficha: escribí el motivo → **Anular préstamo**. El saldo queda en cero y se revierte la comisión del comercializador (si ya se le había liquidado, se descuenta en la próxima). No se puede deshacer.
