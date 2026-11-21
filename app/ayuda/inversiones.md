# Inversiones
<!-- orden: 6 -->

Los inversores aportan capital por un plazo y cobran intereses al vencimiento. Cada aporte es a **tasa fija** (pesos o dólares) o en **participación** sobre préstamos concretos (solo pesos).

|  | Tasa fija | Participación |
| --- | --- | --- |
| Moneda | Pesos o dólares | Pesos |
| Qué gana | TNA pactada, interés simple sobre el capital (base 365 días) | Un % del interés que efectivamente se cobre de los préstamos que financia, en proporción a lo que puso (sin IVA ni punitorios) |
| Depende de la cobranza | No | Sí |
| Capital | Se devuelve al vencimiento | Se devuelve al vencimiento |

## Inversores
<!-- pantallas: inversores.php inversor.php -->
<!-- resumen: Alta del inversor y su capital vigente. -->

*Inversiones* → **Inversores** → **Nuevo inversor**: persona o empresa, CUIT, contacto y CBU / CVU y alias (para pagarle; se validan los dígitos verificadores y la ficha del aporte dice de qué banco es la cuenta). El listado muestra el capital vigente en pesos y en dólares y el próximo vencimiento. La ficha tiene sus aportes y liquidaciones.

## Aportes
<!-- pantallas: inversiones.php -->
<!-- resumen: Cargar un aporte y seguir los vencimientos. -->

1. **Nuevo aporte** (desde Aportes o desde la ficha del inversor).
2. Inversor, modalidad y moneda.
3. Capital, fecha de inicio y plazo en días (o la fecha de vencimiento: una calcula la otra).
4. Tasa fija: la **TNA** en %. Al lado se ve el interés y el total al vencimiento.
5. Participación: el **% del interés cobrado** que le corresponde y los **préstamos que financia** con cuánto de cada uno (*Agregar préstamo*). Cada préstamo muestra cuánto capital le queda libre para financiar; la suma no puede pasar el capital del aporte.
6. La **cuenta de fondos** donde ingresa el capital (de la moneda del aporte; viene la predeterminada), cómo ingresa y la referencia.
7. **Cargar aporte**. Se numera `I-000001`… y se puede bajar la **Constancia PDF**. El capital queda en *Tesorería › Subdiario de cuentas de fondos* (las renovaciones no mueven plata).

**Seguimiento.** El listado muestra el capital vigente por moneda, lo que vence en 30 días, las liquidaciones a pagar y cada aporte con su devengado a hoy. Los vencidos sin liquidar aparecen en rojo con *vencido: liquidar*. El filtro *Vencimiento* muestra los vencidos o los que vencen en 7, 30 o 90 días.

## Liquidar y pagar un aporte
<!-- pantallas: inversion.php -->
<!-- resumen: Rescate o vencimiento, renovación, pago y anulación. -->

1. Abrí el aporte. En la tarjeta **Liquidar**, elegí la fecha (el vencimiento, u hoy si todavía no venció) → **Calcular**.
2. Revisá capital, interés y total. Antes del vencimiento es un rescate anticipado, con el interés hasta ese día. Después del vencimiento no corre más interés.
3. Elegí qué se hace:
   - **No se renueva**: se paga todo.
   - **Se renueva el capital** y se pagan los intereses.
   - **Se renueva capital + intereses**: no se paga nada.
4. Si se renueva, el plazo (viene el mismo). La renovación es un aporte nuevo con las mismas condiciones; en participación sigue financiando los préstamos que sigan vigentes.
5. **Liquidar** → confirmar. Queda en *Liquidaciones* con su **PDF**.

**Pagar al inversor.** En la liquidación *A pagar*: fecha, la cuenta de fondos de la que sale (de la moneda de la liquidación), medio y N° de transferencia (con cheque, su número) → **Pagado**. El pago queda en el libro de fondos.

Si una liquidación se hizo mal y todavía no se pagó: escribí el motivo → **Anular liquidación**. El aporte vuelve a vigente y la renovación, si hubo, se anula. Un aporte cargado por error (sin liquidar) se anula al pie de su ficha.

### Aportes en dólares

Junto a los importes en dólares se muestra el equivalente en pesos (≈ $):

- En la ficha y los listados, a la cotización BCRA de hoy.
- En la vista previa y en la liquidación, a la cotización del día de la liquidación. Esa cotización **queda guardada** en la liquidación y sale en su PDF, aunque después cambie.
- En la constancia, a la cotización del día de inicio.

Si dice *sin cotización*, falta cargar las cotizaciones de esa fecha (*Tablas y parámetros* → *Cotizaciones* → **Cargar histórico**).
