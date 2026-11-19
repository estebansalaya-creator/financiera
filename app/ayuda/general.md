# Uso del sistema
<!-- orden: 1 -->

Cómo se entra, cómo se mueve uno por el menú y cómo se leen las pantallas. Vale para todos los módulos.

## Ingresar y salir
<!-- pantallas: login.php mi_clave.php index.php -->
<!-- resumen: Correo y contraseña, bloqueo por intentos, cambio de contraseña. -->

El backoffice se usa desde el navegador, en la red interna. Cada persona entra con su usuario y ve solo las pantallas que le corresponden.

1. Escribí tu **correo** (o tu nombre de usuario, si te dieron uno) y tu **contraseña**.
2. **Ingresar**. Entrás a la primera pantalla de tu menú.

- Con 5 contraseñas equivocadas seguidas, el usuario queda bloqueado 15 minutos. Un administrador lo puede desbloquear antes.
- La sesión se cierra sola después de 8 horas sin uso.
- Para cambiar tu contraseña, hacé click en tu nombre (arriba a la derecha): pide la actual y la nueva dos veces (al menos 8 caracteres).
- **Salir** cierra la sesión. En una computadora compartida, salí siempre.

Si abrís una pantalla que no tenés, el sistema te lleva a tu menú con el aviso *No tenés permiso para esa pantalla*.

## El menú
<!-- resumen: Módulos, buscador de opciones e Inicio. -->

El menú de la izquierda está armado por módulos (Créditos, Clientes, Cobranzas…). Un click en un módulo lo abre o lo cierra; la opción en la que estás queda marcada.

- **Buscar opción del menú**: escribí parte del nombre (por ejemplo, *lote*) y quedan solo las opciones que coinciden. Con las flechas elegís y Enter abre; Escape limpia.
- **Inicio** (arriba) lleva a la primera pantalla de tu menú.
- Solo aparecen las pantallas que tenés permitidas. Si te falta una, pedísela a un administrador.

## Cómo se leen las pantallas
<!-- resumen: Tarjetas, filtros, grillas, botones, estados y mensajes. -->

| Elemento | Qué hace |
| --- | --- |
| Migas (texto chico arriba del título) | Llevan a la pantalla anterior: *Préstamos / PEREZ JUAN / P-000002* |
| Tarjetas (recuadros blancos con números) | Totales de lo que se ve; algunas son enlaces |
| Barra de filtros | Se combinan entre sí; *Quitar filtro* vuelve a todo |
| Grilla (encabezado azul) | Primera columna: botones de acción; ⇅ ordena por esa columna |
| Botón verde *Exportar a Excel* | Baja todo lo filtrado, no solo la página |
| Botón rojo *PDF* | Abre en otra pestaña; desde ahí se guarda o se imprime |
| Píldoras de color | Estado: verde bien, amarillo atención, rojo problema, gris inactivo |

**Botones de acción de la grilla** (cuadraditos): ojo verde = ver, lápiz azul = editar, documento rojo = PDF, flecha = descargar, cruz o tacho rojo = dar de baja o borrar, tilde verde = reactivar.

**Confirmaciones.** Todo lo que no se puede deshacer fácil (liquidar, anular, registrar un pago, dar de baja) abre un cuadro que repite lo que se va a hacer. Leelo antes de tocar *Confirmar*.

**Mensajes.** Después de guardar aparece un aviso arriba: verde = hecho, amarillo = hecho con observaciones, rojo = no se hizo y por qué. Recargar la página no vuelve a guardar.

**Montos y fechas.** Los importes se escriben como en Argentina (`1.234,56`) o con punto decimal (`1234.56`); el sistema entiende los dos. Las fechas se ven `dd/mm/aaaa`.

**Teléfonos.** En todo el sistema (clientes, vendedores, inversores, empresa) se guardan con el mismo formato: `+54 9 11 2345-6789` un celular, `+54 11 4321-0000` un fijo. Se escriben como se dicen, siempre **con la característica**: `11 2345-6789`, `011 15 2345-6789`, `(0351) 155-551234`. Al salir del campo el número se acomoda solo; si queda en rojo, falta la característica o sobran números. El 15 lo hace celular; donde hay *Tipo*, lo dice el tipo (Celular / Fijo). De otro país, con `+` y el código (`+598 99 123 456`). En las grillas, un celular es un enlace que abre WhatsApp con el saludo cargado en *Datos de la empresa*.

**En el celular.** El menú se abre con el botón ☰ de arriba a la izquierda. Entrando por la dirección `https://` se puede instalar como aplicación: en Android, botón **Instalar app**; en iPhone, *Compartir* → *Agregar a inicio*. El ícono es el logo cargado en *Datos de la empresa*.

## La ayuda
<!-- pantallas: ayuda.php -->
<!-- resumen: El botón Ayuda, la tecla F1 y el buscador de los manuales. -->

- El botón **Ayuda** (abajo a la derecha) y la tecla **F1** abren el manual en la sección de la pantalla donde estás.
- El índice de la ayuda busca en todos los manuales: escribí una o varias palabras (sin importar los acentos).
- **Ir a la pantalla** aparece solo si tenés esa pantalla en tu menú.
