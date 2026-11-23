# Clientes y Consulta BCRA
<!-- orden: 2 -->

Cada cliente tiene una ficha con sus datos, su documentación, su consulta al BCRA y sus préstamos. La Consulta BCRA trae la Central de Deudores de cualquier CUIT, sea cliente o no.

## Listado de clientes
<!-- pantallas: clientes.php -->
<!-- resumen: Buscar, filtrar y abrir la ficha de un cliente. -->

El listado busca por CUIT, DNI, nombre, razón social, CBU (los 22 números) o alias y filtra por tipo, estado, comercializador, tipo de asociado, repartición y documentación (*Completa* / *Incompleta*). El lápiz abre la ficha; **Nuevo cliente** da de alta uno.

## Dar de alta un cliente
<!-- pantallas: cliente.php -->
<!-- resumen: Persona o empresa, CUIT, domicilios y teléfonos. -->

1. *Clientes* → **Nuevo cliente**.
2. Elegí **Persona física** o **Empresa**. Cambian los datos que se piden.
3. Escribí el **CUIT/CUIL**. Debajo aparece ✓ si el dígito verificador es correcto. Una persona empieza con 20, 23, 24 o 27; una empresa con 30, 33 o 34.
4. Persona: apellido, nombres y DNI (se completa solo desde el CUIL y tiene que coincidir). Conviene cargar el **ingreso mensual**: se usa para controlar la relación cuota / ingreso al dar un préstamo.
5. Empresa: razón social, tipo societario, actividad, facturación.
6. Comercializador: el que trajo al cliente. Se propone al cargarle un préstamo.
   **Tipo de asociado** (NACION, REGGIO DC, HABERES ACTIVOS…): el habitual del cliente. La solicitud lo trae elegido y se puede cambiar; cada solicitud y cada préstamo guarda el suyo, que es el que define la tasa y la comisión.
   **Repartición**: dónde cobra (ANSES MINIMA 7, IPS 123, MINISTERIO DE EDUCACION…), agrupadas por organismo. No depende del tipo de asociado. Se cargan en *Tablas y parámetros* → *Organismos* y *Reparticiones*.
7. **Datos bancarios**: el CBU o CVU (22 números) y el alias de una cuenta a nombre del cliente, que es a donde se le transfiere el préstamo. Mientras se escribe el CBU, debajo dice de qué banco es (o que es una billetera virtual, si empieza con 000) o que los dígitos verificadores no coinciden. El alias va de 6 a 20 caracteres (letras sin ñ ni acentos, números, punto o guion) y queda en mayúsculas.
8. Domicilios y teléfonos: el primero de cada lista es el principal. *Agregar domicilio* / *Agregar teléfono* suman renglones; la X los quita. Los teléfonos van con la característica (`11 2345-6789`) y quedan en el formato del sistema (`+54 9 11 2345-6789`); el tipo dice si es celular o fijo.
9. **Crear cliente**. Si el teléfono o el CBU ya los tiene otro cliente, avisa (no lo impide: puede ser un familiar).

No se pueden cargar dos clientes con el mismo CUIT: el sistema dice cuál es el que ya existe.

Al pie de la ficha están sus préstamos y el botón **Nueva solicitud**, que abre la solicitud de préstamo con el cliente ya elegido.

## Documentación del cliente
<!-- pantallas: documento.php -->
<!-- resumen: Subir, ver, dar de baja y reactivar documentos del legajo. -->

La tarjeta *Documentación* de la ficha muestra arriba los documentos **obligatorios** con su estado: verde al día, amarillo vencido, rojo falta.

1. En *Subir documentación* elegí qué documento es (los obligatorios tienen *).
2. Elegí uno o varios archivos (por ejemplo, frente y dorso del DNI). Se aceptan PDF, JPG, PNG y WEBP, hasta 10 MB cada uno.
3. Fecha de emisión y vencimiento: si no ponés vencimiento y el documento tiene vigencia (recibo de sueldo 60 días, servicio 90, balance 540), se calcula solo.
4. **Subir**. El mensaje dice qué se cargó y qué no (archivo que no es PDF ni imagen, muy pesado, o ya cargado).

En la grilla: el ojo abre el archivo, la flecha lo descarga, el tacho lo **da de baja** (no se borra; *Ver dados de baja* lo muestra y la flecha ↩ lo reactiva).

Qué documentos se piden se define en *Tablas y parámetros* → *Documentación requerida*.

## Consulta BCRA
<!-- pantallas: bcra.php -->
<!-- resumen: Central de Deudores: deuda actual, 24 meses y cheques rechazados. -->

1. *Consulta BCRA* → escribí el CUIT/CUIL → **Consultar**.
2. Si ese CUIT se consultó en las últimas 24 horas, se muestra lo guardado (el aviso lo dice). **Actualizar** fuerza una consulta nueva.
3. Si el CUIT es de un cliente, la consulta queda asociada a su ficha. En la ficha, la tarjeta *Central de Deudores (BCRA)* tiene los mismos botones.

Cada consulta se guarda completa: queda como respaldo de lo que se vio al aprobar un crédito, aunque después cambie.

### Cómo leer el informe

| Parte | Qué dice |
| --- | --- |
| Situación actual (peor) | La peor clasificación entre todas las entidades en el último mes informado |
| Deuda total informada | Suma de todas las entidades, en pesos (el BCRA informa en miles; acá ya está multiplicado) |
| Peor situación en 24 meses | La peor clasificación que tuvo en dos años |
| Cheques rechazados | Cuántos, y cuántos siguen sin pagar |
| Deuda actual | Una fila por entidad: situación, monto, días de atraso y observaciones |
| Historial de 24 meses | Grilla entidad × mes; el color es la situación y al pasar el mouse se ve el monto. *J* = proceso judicial |
| Cheques rechazados (tabla) | Fecha, causal, banco, monto, si se pagó y el estado de la multa |

**Situaciones:** 1 normal (verde) · 2 riesgo bajo (amarillo claro) · 3 riesgo medio (naranja) · 4 riesgo alto (rojo claro) · 5 irrecuperable (rojo).

### PDF y legajo

- **Generar PDF** abre el informe completo para imprimir o guardar.
- **Guardar en documentación** (desde la ficha del cliente) lo agrega al legajo como *Informe Central de Deudores (BCRA)*, con la fecha de la consulta. La misma consulta no se guarda dos veces: para un informe nuevo, primero **Actualizar**.

Si aparece *No se pudo conectar con el BCRA*, el servidor no tiene salida a internet o hay un problema de certificados: ver el manual de Administración.
