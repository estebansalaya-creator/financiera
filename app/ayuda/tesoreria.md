# Tesorería
<!-- orden: 6 -->

Dónde está la plata de la financiera y cuánto hay en cada lugar. Cada comprobante que mueve plata —un cobro, el pago de una orden (el desembolso de un préstamo), un aporte de un inversor, un pago a un inversor o un movimiento entre cuentas propias— deja un renglón por cada cuenta que toca en el **libro de fondos**. El saldo de cualquier cuenta sale de ahí, venga la plata de donde venga.

Nadie carga el libro a mano: se arma solo al registrar el comprobante, y si el comprobante se anula, su renglón deja de sumar (queda a la vista, tachado). En las cuentas de banco, además, cada renglón abre su **débito o crédito bancario**: lo que el banco tiene que informar en el extracto.

## Cuentas de fondos
<!-- pantallas: cuentas_fondos.php -->
<!-- resumen: Bancos, cajas, cartera, billeteras; chequeras; cuenta por medio de cobro. -->

*Tesorería* → **Cuentas de fondos**. Cada cuenta tiene un tipo y una moneda (pesos o dólares):

| Tipo | Para qué |
|---|---|
| Banco | cuentas bancarias: piden banco, número de cuenta y CBU; tienen chequeras y débitos y créditos bancarios |
| Caja | el efectivo |
| Cartera de cheques | cheques de terceros recibidos, hasta depositarlos |
| Billetera virtual | Mercado Pago u otra (CVU y alias) |
| Inversiones | plazo fijo, fondos comunes |
| Otro | lo que no entre en los anteriores |

- **Nueva cuenta** → completá los datos → **Crear cuenta**. Con el CBU, el banco se elige solo (los tres primeros números) y se controlan los dígitos verificadores.
- **Predeterminada**: la que viene elegida al desembolsar un préstamo y al pagar a un inversor. Una por moneda.
- Una cuenta con movimientos no se borra ni cambia de tipo o moneda: se **deshabilita** (deja de ofrecerse; su historia queda).
- La grilla muestra el **saldo** de cada cuenta y, en los bancos, **según el banco**: el saldo más lo que todavía no se debitó y menos lo que todavía no se acreditó (lo que debería mostrar el extracto).

### Chequeras
En una cuenta de banco, solapa **Chequeras**: el rango de cada talonario (desde / hasta), si son cheques al día o diferidos y el **último utilizado**. **Nueva chequera** abre el formulario arriba de la lista y el lápiz edita una; dos chequeras de la misma cuenta no pueden pisarse los números. Un cheque propio (de un desembolso o de un pago a un inversor) tiene que ser de una chequera de la cuenta y no se puede usar dos veces; al emitirlo, la chequera avanza sola. Una chequera con cheques emitidos no se quita: se deshabilita.

### Bancos
Solapa **Bancos**: el catálogo de entidades con su código del BCRA. **Nuevo banco** abre el formulario y el lápiz edita uno. La cruz lo desactiva (deja de ofrecerse al cargar una cuenta); el tacho solo aparece si ninguna cuenta lo usa.

### Cuenta por medio de cobro
Solapa **Cuenta por medio de cobro**: la cuenta que viene elegida al cobrar con cada medio (el efectivo a la caja, las transferencias al banco, Rapipago y Pago Fácil a la cuenta donde acreditan). El lápiz de cada medio abre el formulario para elegirla; *se elige en cada cobro* deja el medio sin cuenta fija. En cada cobro se puede cambiar.

## Órdenes de pago
<!-- pantallas: ordenes_pago.php -->
<!-- resumen: Pagar el desembolso de los préstamos: de a varias o de a una. -->

Cada préstamo liquidado deja una **orden de pago** por el neto a desembolsar, a nombre del cliente, con número de año y orden: 2026-00000001 (cada año vuelve a empezar en 1). Nace aprobada: el préstamo ya lo autorizó Gerencia en la solicitud. La plata sale del libro de fondos cuando se **emite el pago** de la orden, no al liquidar.

La bandeja muestra por defecto lo que está **a pagar**, con el CBU de cada cliente debajo de su nombre (o *sin CBU ni alias*). Arriba: el total a pagar, lo pagado hoy y las **transferencias sin archivo**.

**Pagar de a varias** (transferencia o efectivo):

1. Tildá las órdenes (o la casilla del encabezado para todas las de la página). Abajo se ve cuántas y el total.
2. Elegí la **fecha del pago**, la **cuenta de la que sale** y el **medio**.
3. **Emitir los pagos** y confirmá. Cada orden queda *pagada* con su pago, que tiene su propio número de año: 2026-00000001, 2026-00000002…, la plata sale de la cuenta en el libro y, si es un banco, queda el débito pendiente en *Débitos y créditos bancarios*. Si alguna no se pudo pagar, el mensaje dice cuál y por qué; las demás se pagan igual.

**Pagar una sola** (también con **cheque**): el ojo abre la orden. En *Emitir el pago*: fecha, medio y cuenta; con cheque, su número (de una chequera de la cuenta); con transferencia se puede escribir **otro CBU** solo para este pago (si el cliente pidió que se le deposite en otra cuenta) y el **N° de transferencia** si se transfirió a mano desde el home banking (así no va en el archivo).

**Anular un pago** (el tacho, en *Pagos de la orden*, con el motivo): la orden vuelve a estar a pagar y el pago sale del saldo de la cuenta. No se puede si el pago ya salió en un archivo de transferencias (antes se lo saca del archivo) ni si el banco ya lo debitó. Para anular un préstamo cuyo desembolso ya se pagó, primero se anula el pago; si no se pagó, la orden se anula con el préstamo.

## Transferencias masivas
<!-- pantallas: transferencias.php -->
<!-- resumen: El archivo de Interbanking con los pagos por transferencia. -->

Los pagos emitidos por **transferencia desde una cuenta bancaria** salen en un archivo que se sube a **Interbanking** (PIL, *Transferencias › Importar › Por número de CBU*): un renglón con la cuenta que se debita y uno por transferencia, con el CBU del cliente, el importe, el número de pago y su CUIT.

1. **Generar el archivo**: elegí la cuenta que se debita (arriba se ve cuántos pagos esperan en cada una). La cuenta tiene que tener su **CBU** cargado en *Cuentas de fondos*.
2. La lista trae los pagos de esa cuenta que todavía no salieron en un archivo, todos tildados, con el **CBU destino**: el que se escribió al emitir el pago o, si no, el de la ficha del cliente. Los que no pueden ir (sin CBU, o solo con alias: Interbanking transfiere por CBU) aparecen apagados con el motivo.
3. Fecha de solicitud (hoy o después) y observación del lote. **Generar archivo Interbanking** y confirmá: el archivo se baja solo (`interbanking_AAAAMMDD_HHMMSS.txt`).

Desde ese momento esos pagos cuentan como **enviados**. El CBU que se usó queda guardado en el archivo: si el cliente cambia su CBU después, se sigue sabiendo a dónde fue la plata. Si se saca el pago del archivo (por ejemplo, porque el CBU estaba mal) y se corrige la ficha del cliente, el archivo siguiente toma el nuevo.

**Archivos generados**: cada archivo con su cuenta, cantidad e importe. Adentro se ve qué llevó y qué quedó afuera (con el motivo), el **contenido** tal como va al banco, y se puede **volver a bajar**. Si el archivo no se subió al banco:

- **Sacar un pago** (la cruz del renglón, con el motivo): el resto sigue; volvé a bajar el archivo antes de subirlo. El pago queda libre para otro archivo o para anularlo.
- **Anular el archivo** entero, con el motivo: todos sus pagos quedan libres.

Cuando el banco ya debitó alguna de sus transferencias, el archivo figura como *Enviado* y ya no se anula. Una transferencia que el banco rechazó se puede sacar igual: su pago queda libre para otro archivo o para anularlo. La que el banco debitó no se saca ni se anula: la plata salió.

## Subdiario de cuentas de fondos
<!-- pantallas: movimientos_fondos.php -->
<!-- resumen: El libro: debe, haber y saldo de cada cuenta. -->

*Tesorería* → **Subdiario de cuentas de fondos** muestra el libro del mes en curso, en orden de fecha: todo lo que entra (debe) y sale (haber) de cada cuenta.

- Arriba: los movimientos, el debe y el haber del filtro. Con una **cuenta** elegida, también el **saldo anterior** al período y el **saldo final** (anterior + debe − haber).
- Con una cuenta, la grilla abre con el *Saldo anterior* y lleva el saldo renglón por renglón (con un origen, un importe o una búsqueda no se muestra: faltarían los demás movimientos). Al pie, el total de la página.
- Filtros por fechas, origen (cobranza, desembolso, aporte, pago a inversor, orden de pago, entre cuentas), importe desde / hasta y búsqueda por concepto (recibo, préstamo, cliente, inversor). Las fechas y los importes se aplican con **Filtrar**.
- Los **anulados** se ven tachados y no suman; con *Anulados: ocultos* desaparecen.
- La tarjeta **Saldos** muestra lo que tiene cada cuenta a la fecha *hasta*.
- El ojo de cada renglón abre su comprobante (recibo, préstamo, aporte o movimiento).
- **Exportar a Excel** y **PDF** traen todo lo filtrado.

## Movimientos entre cuentas
<!-- pantallas: movimientos_cuentas.php -->
<!-- resumen: Depósitos, extracciones, transferencias propias e inversiones. -->

Plata propia que cambia de cuenta: depositar el efectivo de la caja, extraer del banco, pasar de un banco a otro o constituir un plazo fijo.

1. *Tesorería* → **Movimientos entre cuentas** → **Nuevo movimiento**.
2. Fecha, la cuenta de la que **sale** y la que **recibe** (las dos de la misma moneda), importe, referencia (N° de transferencia, de boleta de depósito) y comentario.
3. El tipo se deduce solo: con una cuenta de inversiones es una *inversión*; de banco a caja, una *extracción*; de caja o cartera a banco, un *depósito*; si no, *entre cuentas*.
4. **Registrar movimiento** → confirmar. Se abre su ficha, con los dos renglones del libro y lo que va a informar el banco, y la constancia en **PDF**.

**Anular:** desde la ficha, con el motivo (solo para errores de carga). Deja de sumar en las dos cuentas. Lo que el banco ya acreditó o debitó no se anula: primero hay que volverlo a pendiente en *Débitos y créditos bancarios*.

## Débitos y créditos bancarios
<!-- pantallas: debitos_creditos.php -->
<!-- resumen: Lo que el banco tiene que informar: cheques en circulación, pendientes. -->

Un renglón por instrumento de las cuentas de banco: cada cheque, transferencia, depósito o débito. Es el lado del sistema de la conciliación bancaria: lo que el extracto tiene que traer.

- Por defecto muestra lo **pendiente**: lo que está en el libro y todavía no se vio en el banco. Arriba: lo que falta debitar, lo que falta acreditar y los **cheques en circulación**.
- Cuando el banco lo informa, tildá los renglones, poné la **fecha en el banco** y **En el banco** (queda *Acreditado* o *Debitado*).
- **Entregado**: un cheque que ya tiene el beneficiario. **Volver a pendiente**: si se marcó por error.
- Filtros por cuenta, estado, instrumento, débitos o créditos, fechas y búsqueda (N° de cheque, referencia, beneficiario). **Exportar a Excel** trae todo lo filtrado.

## Extractos bancarios
<!-- pantallas: extractos_bancarios.php -->
<!-- resumen: Importar el archivo del banco y ver lo que informó, con su saldo. -->

Lo que informa el banco, renglón por renglón, importado del archivo que se baja del home banking. Es el lado del banco de la conciliación: el del sistema son los *Débitos y créditos bancarios*.

**Importar** (solapa *Importar*):

1. Elegí la **cuenta** del extracto. Tiene que tener cargado el **número de cuenta** en *Cuentas de fondos*: con eso se controla que el archivo sea de esa cuenta.
2. Elegí uno o varios archivos, o **una carpeta** entera (se toman los .csv y .txt; el resto se ignora). Hasta 20 archivos por vez.
3. **Importar**. El formato se reconoce solo:
   - **Banco Nación**: el extracto clásico (`Fecha Mvto;Fecha Valor;Monto;Referencia;Concepto;Saldo`) y el de Banca Empresas (`BEE_Reporte_….csv`).
   - **ICBC**: el extracto de cuenta corriente (empieza con *Movimientos de CC $ …*) y el reporte de **recaudaciones**. Este último no trae la fecha en los renglones: hay que ponérsela al principio del nombre del archivo, como `19.11.2026 …`; sin fecha no se importa.

Qué controla:

- Si el archivo es de **otro banco** o de **otra cuenta**, no se importa nada y dice de qué cuenta es. El Nación clásico no trae el número de cuenta: entra con un aviso.
- Lo que **ya estaba cargado no se repite** (mismo número de operación, fecha e importe). Se puede reimportar cualquier período, en cualquier orden: el mismo mes bajado dos veces, o el archivo del día y después el del mes, cargan solo lo que falta. Si el banco informa dos movimientos iguales el mismo día, entran los dos.
- Cada archivo queda en el historial de **Importaciones**: cuántos movimientos nuevos trajo, cuántos ya estaban, el período, y por qué no se importó si falló. La flecha ↩ **deshace** una importación entera (borra los movimientos que entraron con ella), mientras ninguno esté conciliado. Si otro archivo traía los mismos movimientos, volvé a importarlo y entran los que falten.

**Extracto** (solapa *Extracto*): los movimientos de una cuenta en un período (por defecto, el mes del último movimiento importado), con el débito o el crédito, la referencia y el **saldo que informa el banco**. El Nación lo da una vez por día: se ve en el último movimiento del día. Si el concepto trae un CUIT de un cliente o de un inversor, debajo aparece su nombre con el enlace a la ficha.

Arriba: el **saldo del banco** (el último que informó), el **saldo del libro** a esa misma fecha (*Subdiario de cuentas de fondos*, sin anulados) y la **diferencia**, que es lo que explica la conciliación: gastos e impuestos del banco que todavía no están en el libro, y lo que el libro tiene y el banco todavía no vio (un cheque en circulación, una transferencia del día).

El tacho borra un movimiento del extracto (no si está conciliado o es una excepción en una conciliación). **Exportar a Excel** trae todo lo filtrado.

## Conciliación bancaria
<!-- pantallas: conciliaciones.php -->
<!-- resumen: El extracto de cada cuenta banco contra el libro, mes por mes. -->

Se concilia una **cuenta banco por mes**. De un lado, el extracto que se importó en *Extractos bancarios*; del otro, el libro: los *Débitos y créditos bancarios* que dejó cada comprobante. Cada renglón del extracto tiene que quedar **conciliado** (unido a uno o varios del libro por el mismo importe) o marcado como **excepción**.

**Nueva conciliación**: la cuenta y el mes (se propone el que sigue). El **saldo inicial** es el saldo final de la conciliación anterior cerrada; la primera vez se carga a mano (se propone el que dice el extracto al cierre del mes anterior). Los meses van en orden y uno a la vez: para abrir el siguiente hay que cerrar el anterior. Lo del libro anterior a la primera conciliación de la cuenta ya está en el saldo inicial y no aparece.

Arriba, los números del mes: saldo inicial, créditos y débitos del extracto, el **saldo final** calculado y si coincide con el que **informa el banco** en el último renglón, y cuánto falta conciliar.

Solapa **Por conciliar**:

- **Sugerencias**: los renglones que se pueden unir solos, uno con uno: el cobro que se emitió desde ese renglón del extracto (*Emisión de recibos*), los de mismo importe y misma referencia, y los de mismo importe a 7 días o menos (el más cercano). Se tildan o destildan y **Conciliar las elegidas**.
- **Comprobantes a emitir**: lo que el banco cobró o acreditó por su cuenta (comisiones, IVA, impuesto a los débitos y créditos, percepciones, intereses) y que el libro todavía no tiene. Sale de la *Clasificación de conceptos*. **Emitir los elegidos** registra el gasto o ingreso bancario (o la transferencia a otra cuenta propia), con su renglón en el libro, y lo deja conciliado. Si la regla agrupa por día, va un comprobante por concepto y día.
- **Extracto por conciliar** y **Libro por conciliar**: para unir a mano. Se tildan renglones de los dos lados; abajo se ve lo que suma cada lado y la diferencia. **Conciliar lo elegido** se habilita cuando la diferencia es cero. Sirve para un renglón contra varios (una transferencia que paga varias órdenes) o varios contra uno. Lo del libro que el banco todavía no informó (una transferencia del último día, un cheque en circulación) queda en la lista para el mes siguiente.
- El ⚠ de un renglón del extracto lo marca como **excepción**, con el motivo: algo que no está en el libro y no se va a registrar este mes (un error del banco reclamado, un crédito sin identificar).

Solapa **Conciliado**: cada vínculo con sus dos lados. **Desvincular** devuelve los renglones a *Por conciliar*; si el comprobante lo había emitido la conciliación, lo **anula** (y se puede volver a emitir).

**Cerrar el período**: con todo el extracto conciliado o en excepción y, si el banco informa saldo, con el saldo calculado igual al del banco. Al cerrar, los renglones del extracto quedan marcados como conciliados (ya no se borran) y el saldo final pasa a la conciliación del mes siguiente. Se puede **reabrir** la última de la cuenta.

**Exportar a Excel**: el extracto del mes con la situación de cada renglón, y lo pendiente del libro.

## Clasificación de conceptos
<!-- pantallas: conc_clasificar.php -->
<!-- resumen: Qué es cada texto del extracto: gasto o ingreso bancario, transferencia o a mano. -->

Los textos del extracto de una cuenta, sin repetir (*COMISION MANTENIMIENTO CUENTA*, *IMPUESTO LEY 25413 S/DEBITOS*…), primero los que faltan clasificar. Para cada uno se elige qué es:

- un **concepto de tesorería** (un gasto o un ingreso bancario): la conciliación emite el comprobante;
- una **transferencia a otra cuenta propia**: la conciliación registra el movimiento entre cuentas;
- **se concilia a mano**: se reconoce (por ejemplo, las transferencias entre cuentas que ya están en el libro) pero no se emite nada.

**Un comprobante por día** junta en un solo comprobante los renglones del mismo concepto del mismo día; destildado, va uno por renglón. **Emite el comprobante** destildado deja el renglón reconocido pero sin emitir.

**Guardar** crea la regla (el texto «contiene»). La ✕ la quita: el texto vuelve a *sin clasificar* y lo ya conciliado no cambia. Si un texto lo reconoce otra regla (por ejemplo *IMPUESTO LEY 25413* reconoce los de débitos y de créditos), se ve cuál y se edita en la solapa **Reglas**.

Solapa **Reglas**: todas, en el orden en que se prueban (gana la primera). Ahí se cargan las que necesitan otra comparación: *empieza con*, *es igual a* o una *expresión regular*, y solo para débitos o solo para créditos. **Probar un texto** dice qué regla lo reconoce.

## Conceptos de tesorería
<!-- pantallas: conceptos_tesoreria.php -->
<!-- resumen: Los gastos e ingresos bancarios, con su cuenta contable. -->

El catálogo de lo que el banco cobra o acredita por su cuenta: comisiones y gastos bancarios, IVA crédito fiscal, impuesto a los débitos y créditos, percepciones de IVA y de Ingresos Brutos (SIRCREB), intereses. Cada uno es un **gasto** o un **ingreso** y lleva su **cuenta contable**: contra ella va el asiento del comprobante bancario (*Contabilidad › Asientos contables*). Un concepto con reglas o comprobantes no se elimina: se desactiva. Son distintos de los *Conceptos de gasto* de *Tablas y parámetros*, que son lo que se le cobra al cliente al liquidar un préstamo.

## De dónde sale cada renglón
<!-- resumen: Qué comprobante mueve qué cuenta. -->

| Comprobante | Cuenta | En el libro | En el banco |
|---|---|---|---|
| Cobro de una cuota (*Cobros en efectivo*, *Importar lote*) | la elegida al cobrar | entra el importe | transferencia, débito automático o depósito |
| Pago de una orden de pago (el desembolso de un préstamo) | la elegida al emitir el pago | sale el neto | transferencia (después, en el archivo de Interbanking), cheque (de una chequera) o efectivo |
| Aporte de un inversor | la elegida al cargarlo (de su moneda) | entra el capital | transferencia o depósito |
| Pago a un inversor | la elegida al registrar el pago | sale lo pagado | transferencia, cheque o efectivo |
| Movimiento entre cuentas | las dos | sale de una, entra en la otra | según el tipo |
| Gasto o ingreso bancario (lo emite la *Conciliación bancaria*) | la del extracto | sale el gasto, entra el ingreso | débito automático o crédito |

Las renovaciones de un aporte no mueven plata. Un pago anulado, un préstamo anulado o un aporte anulado sacan su renglón del saldo.
