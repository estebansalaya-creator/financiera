# Contabilidad
<!-- orden: 7 -->

El plan de cuentas, los ejercicios y sus períodos. Tienen la misma estructura que el módulo contable de gestion_adm: las mismas reglas para el código de cuenta, para las cuentas que agrupan y las imputables, y para los períodos abiertos y cerrados.

Cada operación (liquidación y desembolso de un préstamo, cobranza, aporte, liquidación y pago a un inversor, movimiento entre cuentas, comisión, gasto o ingreso bancario) genera su asiento con las cuentas que se le asignan en Imputaciones contables. Los asientos se revisan mes por mes en el libro de trabajo y se confirman al libro diario.

## Plan de cuentas
<!-- pantallas: plan_cuentas.php -->
<!-- resumen: Máscara del código, rubros, agrupadoras e imputables, importar y exportar. -->

*Contabilidad* → **Plan de cuentas**.

### El código
El código sigue una **máscara**: un 9 por cada dígito y un separador entre los tramos, por ejemplo `9.99.999.9999`. Se fija una sola vez, antes de la primera cuenta. Si se importa un plan, sale de su primer código.

- El **primer dígito** es el rubro: 1 Activo, 2 Pasivo, 3 Patrimonio neto, 4 Ingresos, 5 Egresos. De ahí salen la clasificación y la naturaleza (deudora o acreedora). Para un rubro nuevo (6, por ejemplo), al cargar su cuenta de primer nivel se elige la naturaleza.
- Si el **último tramo está en cero**, la cuenta **agrupa** a otras (`1.01.001.0000`). Si no, es **imputable** (`1.01.001.0001`): solo las imputables reciben movimientos.
- El **padre** es la cuenta con el mismo código y el primer tramo de la derecha puesto en cero: `1.01.001.0001` cuelga de `1.01.001.0000`, y esa de `1.01.000.0000`. El padre tiene que existir, así que las agrupadoras se cargan primero. El **nivel** es el del padre más uno.
- **Corriente / no corriente**: las cuentas de nivel 2 llamadas ACTIVO CORRIENTE o PASIVO CORRIENTE son corrientes, y las demás de nivel 2 no. Las de más abajo heredan el valor de su cuenta de nivel 2.

### Cargar cuentas
- **Nueva cuenta**: se escriben el código y el nombre. Mientras se escribe el código, abajo dice si va a ser imputable o agrupadora.
- El **+** de una agrupadora abre el alta debajo de ella, con el código siguiente ya propuesto. Después de agregar una cuenta, la pantalla queda lista para cargar la siguiente en el mismo lugar.
- **Monetaria**: la cuenta queda expuesta a la inflación (para el ajuste por inflación).
- Una cuenta que ya tiene movimientos o cuentas abajo no cambia de código ni se elimina: se **desactiva**. Deja de ofrecerse para imputar y lo registrado no cambia.

### Importar y exportar
**Importar** lee un CSV con las columnas `codigo;nombre` y, en las cuentas de primer nivel de un rubro nuevo, `naturaleza`. Lo demás se calcula. Las cuentas que ya existen se saltean, y las que tienen un error se listan con su renglón; las demás entran igual. Con **Bajar un CSV de ejemplo** se obtiene un plan chico de una financiera, para tomar como modelo.

**Exportar a Excel** baja el plan tal como está filtrado, con las cuentas sangradas por nivel.

## Ejercicios y períodos
<!-- pantallas: ejercicios.php -->
<!-- resumen: Abrir un ejercicio con sus meses, el ejercicio de trabajo, cerrar y bloquear períodos. -->

*Contabilidad* → **Ejercicios y períodos**.

- **Nuevo ejercicio** propone el que sigue al último (o el año calendario, si no hay ninguno), con el CUIT y la razón social de la empresa. Con **Crear los períodos mensuales** se arma un período por mes. Un ejercicio puede durar hasta 18 meses y no puede cruzarse con otro.
- El **ejercicio de trabajo** es uno solo: el que se usa por defecto. Se cambia con el ✓ de la grilla.
- El ojo muestra los **períodos** del ejercicio:

| Estado | Qué significa |
|---|---|
| Abierto | admite asientos |
| Cerrado | no admite asientos; se puede **reabrir** |
| Bloqueado | no admite asientos y no se reabre (un mes ya presentado) |

- Un ejercicio sin asientos se puede editar (si cambian las fechas, los períodos se vuelven a armar) y eliminar. Con asientos, las fechas quedan fijas y no se elimina.
- El cierre del ejercicio (el asiento de refundición de resultados y el de cierre) se va a hacer desde acá cuando estén los asientos.

## Imputaciones contables
<!-- pantallas: imputaciones.php -->
<!-- resumen: Contra qué cuentas va cada operación: clases, subtipos y las cuentas de fondos y de gastos. -->

*Contabilidad* → **Imputaciones contables**. Ahí se define la cuenta del plan que corresponde a cada parte de una operación. Son las cuentas que va a usar el generador de asientos. Nada se adivina: si a una operación le falta su cuenta, su asiento sale con un aviso y no se emite.

Las cuentas salen de tres lugares:

| De dónde | Qué cuenta | Dónde se carga |
|---|---|---|
| La cuenta de fondos | caja, bancos, billeteras, cartera de cheques | *Tesorería › Cuentas de fondos*, campo **Cuenta contable** |
| El concepto de gasto | el ingreso por cada gasto que se le cobra al cliente | *Tablas y parámetros › Conceptos de gasto*, campo **Cuenta contable** |
| La clase de operación | todo lo demás | esta pantalla |

Las clases de operación son estas:

| Clase | Debe | Haber |
|---|---|---|
| Liquidación de préstamos | deudores por préstamos (el capital) | préstamos a desembolsar (el neto, hasta que se paga la orden) |
| Intereses de préstamos | — | intereses ganados |
| Punitorios | — | punitorios ganados |
| IVA débito fiscal | — | IVA de intereses, punitorios y gastos gravados |
| Quitas por cancelación anticipada | quitas y bonificaciones | — (el deudor) |
| Cuota social | — | ingreso por cuota social |
| Comisiones de comercializadores | comisiones (egreso) | comisiones a pagar |
| Aportes de inversores | — | deuda con el inversor |
| Intereses a inversores | intereses pagados | intereses a pagar al inversor |
| Cobranzas a imputar | — | lo que entra sin cliente identificado |

Un "—" quiere decir que ese lado sale de otro lugar: la cuenta de fondos del cobro o el deudor del préstamo.

- Cada clase lleva una imputación **general**. En las clases de préstamos se puede agregar otra para un **tipo de asociado** (con el **+** de la general), por ejemplo los intereses de jubilados a una cuenta propia. En las de inversores, otra para una **moneda**. La del subtipo manda sobre la general.
- Arriba aparece un aviso con lo que falta, y abajo, **Cuentas de los maestros** muestra las cuentas de fondos y los conceptos de gasto con su cuenta contable o sin ella.
- Una cuenta del plan que está asignada en alguno de estos lugares no se elimina.

## Asientos contables
<!-- pantallas: asientos.php -->

Arriba se elige el **período** (sale el mes de hoy del ejercicio de trabajo). Hay dos solapas.

### Libro de trabajo

Muestra cada operación del mes con el asiento que genera, renglón por renglón, antes de grabar nada. Cada una queda en uno de tres estados:

- **Listo**: balanceado, con todas sus cuentas y en un período abierto.
- **Con avisos**: algo falta y el asiento no se graba. El aviso dice qué: una cuenta sin asignar (en Imputaciones contables, en la cuenta de fondos o en el concepto de gasto), una cotización del dólar que no está, un período cerrado. Se corrige eso y el asiento pasa a listo solo.
- **Generado**: ya está en el diario, con su número.

**Generar N asientos** graba los que están listos. Se puede apretar las veces que haga falta: lo ya generado no se repite, y los que tenían avisos se generan cuando se corrigen.

Qué asiento genera cada operación:

| Operación | Debe | Haber |
|---|---|---|
| Liquidación de un préstamo | deudores (capital y gastos financiados) | préstamos a desembolsar (el neto), cada gasto a su cuenta, IVA |
| Pago de la orden del desembolso | préstamos a desembolsar | la cuenta de fondos |
| Cobranza | la cuenta de fondos | deudores (capital), intereses, punitorios, IVA, cuota social, según la imputación real del cobro |
| Aporte de un inversor | la cuenta de fondos | aportes de inversores |
| Liquidación de un aporte | intereses a inversores | intereses a pagar (o aportes, si se renovó con los intereses) |
| Pago a un inversor | aportes (el capital que se devuelve) e intereses a pagar | la cuenta de fondos |
| Movimiento entre cuentas | la cuenta destino | la cuenta origen |
| Comisión devengada | comisiones | comisiones a pagar |
| Gasto bancario (de la conciliación) | la cuenta de cada concepto de tesorería | la cuenta de fondos |
| Ingreso bancario | la cuenta de fondos | la cuenta de cada concepto de tesorería |

- Los intereses se reconocen **al cobrarlos** (lo percibido): por eso una quita por cancelación anticipada no genera asiento.
- Una operación anulada genera el **contraasiento** en la fecha en que se anuló; el original queda en su fecha.
- Lo que está en dólares se pasa a pesos con la cotización del BCRA del día (Tablas y parámetros › Cotizaciones). Sin cotización, el asiento queda con aviso.

### Libro diario

Los asientos grabados del período, en orden de número, con sus renglones y el total. La numeración es correlativa por ejercicio y **sigue la fecha**: si un mes se genera después que el siguiente, los números se vuelven a ordenar. Los asientos de un período cerrado no se renumeran, y en un período abierto anterior a uno cerrado no se generan asientos (hay que reabrir el cerrado).

**Anular** (con el motivo) deja el asiento a la vista, tachado del total, y devuelve su operación al libro de trabajo: sirve para corregir una cuenta mal asignada y volver a generarlo. Solo en un período abierto.

Las dos solapas se exportan a Excel.

