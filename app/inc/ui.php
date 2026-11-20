<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Piezas de pantalla, al estilo de templates/_ui.html de gestion_adm.
// Antes de escribir HTML suelto para algo de esto, usar la pieza: así todas
// las pantallas se ven y se comportan igual.
// ---------------------------------------------------------------------

require_once __DIR__ . '/iconos.php';

/** Encabezado de pantalla: título y bajada a la izquierda, acciones a la derecha. */
function titulo(string $texto, string $bajada = '', string $acciones = '', string $migas = ''): void
{
    echo '<div class="page-header"><div>';
    if ($migas !== '') {
        echo '<div class="migas">' . $migas . '</div>';
    }
    echo '<h1>' . h($texto) . '</h1>';
    if ($bajada !== '') {
        echo '<p>' . h($bajada) . '</p>';
    }
    echo '</div>';
    if ($acciones !== '') {
        echo '<div class="page-header-acciones">' . $acciones . '</div>';
    }
    echo '</div>';
}

/** Cabecera de tarjeta. */
function card_head(string $titulo, string $nota = '', string $acciones = ''): string
{
    return '<div class="card-head"><div><span class="card-head-titulo">' . h($titulo) . '</span>'
         . ($nota !== '' ? '<span class="card-head-nota">' . h($nota) . '</span>' : '') . '</div>'
         . ($acciones !== '' ? '<div class="card-head-acciones">' . $acciones . '</div>' : '') . '</div>';
}

/** Botón con texto. tipo: primary | secondary | ghost | danger | ok | excel | pdf */
function boton(string $texto, string $tipo = 'primary', ?string $href = null, ?string $icono = null,
               bool $submit = false, bool $chico = false, array $extra = []): string
{
    $clase = 'btn btn-' . $tipo . ($chico ? ' btn-sm' : '');
    $attrs = '';
    foreach ($extra as $k => $v) {
        $attrs .= ' ' . h($k) . ($v === true ? '' : '="' . h($v) . '"');
    }
    $ico = $icono ? icono($icono) : '';
    if ($href !== null) {
        return '<a href="' . h($href) . '" class="' . $clase . '"' . $attrs . '>' . $ico . h($texto) . '</a>';
    }
    return '<button type="' . ($submit ? 'submit' : 'button') . '" class="' . $clase . '"' . $attrs . '>' . $ico . h($texto) . '</button>';
}

/** Botón de acción de grilla (28×28, solo ícono): ver, editar, borrar, pdf, planilla, descargar… */
function btn_accion(string $tipo, string $href, string $titulo, bool $nuevaPestana = false): string
{
    $icono = ['ver' => 'ver', 'editar' => 'editar', 'borrar' => 'borrar', 'pdf' => 'pdf',
              'planilla' => 'planilla', 'agregar' => 'agregar', 'descargar' => 'descargar'][$tipo] ?? 'info';
    return '<a class="btn-accion ' . h($tipo) . '" href="' . h($href) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '"'
         . ($nuevaPestana ? ' target="_blank" rel="noopener"' : '') . '>' . icono($icono) . '</a>';
}

/** Píldora de estado. tipo: ok | danger | warn | info | neutro */
function estado(string $texto, string $tipo = 'neutro'): string
{
    return '<span class="estado estado-' . h($tipo) . '">' . h($texto) . '</span>';
}

/** El mensaje que dejó la pantalla anterior (guardado, borrado…) y los errores de esta. */
function mensajes(array $errores = []): void
{
    $iconos = ['ok' => 'ok', 'danger' => 'error', 'warning' => 'alerta', 'info' => 'info'];
    if ($GLOBALS['FLASH'] ?? null) {
        [$tipo, $texto] = $GLOBALS['FLASH'];
        echo '<div class="alert alert-' . h($tipo) . '">' . icono($iconos[$tipo] ?? 'info', 16) . '<div>' . h($texto) . '</div></div>';
    }
    if ($errores) {
        echo '<div class="alert alert-danger">' . icono('error', 16) . '<div>';
        if (count($errores) === 1) {
            echo h(reset($errores));
        } else {
            echo '<ul>';
            foreach ($errores as $e) {
                echo '<li>' . h($e) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
    }
}

/**
 * Barra de paginación del servidor, con la forma de la de gestion_adm:
 *   « ‹ Pág. 2 de 7 · 340 registros › »  y  Filas [25|50|100|200]
 * Arma los enlaces con los parámetros actuales (no se pierden los filtros).
 */
function paginador(int $pagina, int $paginas, int $total, int $porPagina, string $unidad = 'registros'): string
{
    $paginas = max(1, $paginas);
    $link = function (string $txt, int $p, bool $deshab, string $titulo) {
        if ($deshab) {
            return '<button class="page-btn" disabled aria-label="' . h($titulo) . '">' . $txt . '</button>';
        }
        return '<a class="page-btn" href="' . h(url_con(['p' => $p > 1 ? $p : null])) . '" title="' . h($titulo) . '">' . $txt . '</a>';
    };
    $out = '<div class="pagination-bar"><div class="pagination-controls">'
         . $link('«', 1, $pagina <= 1, 'Primera') . $link('‹', $pagina - 1, $pagina <= 1, 'Anterior')
         . '<span class="pagination-info">Pág. ' . $pagina . ' de ' . $paginas . '  ·  ' . num($total) . ' ' . h($unidad) . '</span>'
         . $link('›', $pagina + 1, $pagina >= $paginas, 'Siguiente') . $link('»', $paginas, $pagina >= $paginas, 'Última')
         . '</div><form method="get" class="filas-por-pagina">';
    foreach ($_GET as $k => $v) {
        if (is_string($v) && !in_array($k, ['per_page', 'p'], true)) {
            $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
    }
    $out .= '<label class="pagination-info">Filas <select name="per_page" class="page-size-select" onchange="this.form.submit()">';
    foreach ([25, 50, 100, 200] as $n) {
        $out .= '<option value="' . $n . '"' . ($n === $porPagina ? ' selected' : '') . '>' . $n . '</option>';
    }
    return $out . '</select></label></form></div>';
}

/** per_page de la URL, acotado (10 a 500, como en gestion_adm). */
function por_pagina(int $defecto = 50): int
{
    $n = (int) get_str('per_page');
    return $n > 0 ? max(10, min($n, 500)) : $defecto;
}

/** Opciones de un <select>: [valor => texto]. */
function opciones(array $lista, $elegido, string $vacio = ''): string
{
    $out = $vacio !== '' ? '<option value="">' . h($vacio) . '</option>' : '';
    foreach ($lista as $v => $t) {
        $out .= '<option value="' . h($v) . '"' . ((string) $v === (string) $elegido ? ' selected' : '') . '>' . h($t) . '</option>';
    }
    return $out;
}

// ---------------------------------------------------------------------
// CUIT / CUIL
// ---------------------------------------------------------------------

function cuit_limpio(string $s): string
{
    return preg_replace('/\D/', '', $s);
}

/** Dígito verificador (módulo 11). */
function cuit_valido(string $cuit): bool
{
    if (!preg_match('/^\d{11}$/', $cuit)) {
        return false;
    }
    $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $suma = 0;
    for ($i = 0; $i < 10; $i++) {
        $suma += (int) $cuit[$i] * $pesos[$i];
    }
    $dv = 11 - ($suma % 11);
    if ($dv === 11) {
        $dv = 0;
    } elseif ($dv === 10) {
        return false;   // con resto 1 no hay dígito posible: ARCA asigna otro prefijo (23, 33)
    }
    return $dv === (int) $cuit[10];
}

/** 20123456789 -> 20-12345678-9 */
function cuit_fmt(?string $cuit): string
{
    $c = cuit_limpio((string) $cuit);
    return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : (string) $cuit;
}

// ---------------------------------------------------------------------
// Teléfonos: un solo formato en todo el sistema
//   En la base, en formato internacional y solo dígitos:
//     celular  5491123456789  -> +54 9 11 2345-6789
//     fijo     541143210000   -> +54 11 4321-0000
//     0800     08005551234    -> 0800 555 1234
//     otro país    +59899123456   (con el + adelante, tal cual)
//   Se escribe como se dice acá: «11 15 2345-6789», «011 4321-0000»,
//   «(0351) 155-551234», «+54 9 351 555-1234». El 15 o el 9 lo hacen
//   celular; si no, lo decide el tipo (Celular / Fijo).
// ---------------------------------------------------------------------

/** Características de 3 dígitos (el resto, salvo 11, son de 4). */
const TEL_AREAS_3 = ['220', '221', '223', '230', '236', '237', '249', '260', '261', '263', '264', '266', '280',
                     '291', '294', '297', '298', '299', '336', '341', '342', '343', '345', '348', '351', '353', '358',
                     '362', '364', '370', '376', '379', '380', '381', '383', '385', '387', '388'];

function tel_largo_area(string $d): int
{
    return strncmp($d, '11', 2) === 0 ? 2 : (in_array(substr($d, 0, 3), TEL_AREAS_3, true) ? 3 : 4);
}

/**
 * Número en formato de la base, o null si no se entiende.
 * $movil: true celular, false fijo, null lo decide el 15 / el 9.
 * El 15 se saca solo cuando sobra: «11 15 2345-6789» tiene dos dígitos de
 * más y van justo después de la característica; «11 1523-4567» no.
 */
function tel_normalizar(string $s, ?bool $movil = null): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $intl = $s[0] === '+' || strncmp($s, '00', 2) === 0;
    $d = preg_replace('/\D/', '', $s);
    if ($intl && strncmp($d, '00', 2) === 0) {
        $d = substr($d, 2);
    }
    if (preg_match('/^0(800|810|600|610)\d{7}$/', $d)) {
        return $d;
    }
    if ($intl && strncmp($d, '54', 2) !== 0) {
        return preg_match('/^[1-9]\d{7,14}$/', $d) ? '+' . $d : null;   // otro país
    }
    $con9 = false;
    if (strncmp($d, '54', 2) === 0 && strlen($d) >= 12) {       // ninguna característica empieza con 54
        $d = substr($d, 2);
        if ($d[0] === '9') {
            $d = substr($d, 1);
            $con9 = true;
        }
    }
    $d = ltrim($d, '0');
    if (strlen($d) === 12) {                                    // sobran dos: el 15 después de la característica
        $hecho = false;
        foreach (array_unique([tel_largo_area($d), 2, 3, 4]) as $a) {
            if (substr($d, $a, 2) === '15' && ($a !== 2 || strncmp($d, '11', 2) === 0)) {
                $d = substr($d, 0, $a) . substr($d, $a + 2);
                $con9 = true;
                $hecho = true;
                break;
            }
        }
        if (!$hecho) {
            return null;
        }
    }
    if (!preg_match('/^(11|[23]\d)\d{8}$/', $d)) {
        return null;                                            // falta la característica o sobran números
    }
    return ($movil === true || $con9 ? '549' : '54') . $d;
}

/**
 * Para mostrar: +54 9 11 2345-6789. Solo toca lo que está en el formato de la
 * base: un número viejo o recién escrito, sin normalizar, sale tal cual.
 */
function tel_fmt(?string $n): string
{
    $n = trim((string) $n);
    if (preg_match('/^0(800|810|600|610)(\d{3})(\d{4})$/', $n, $m)) {
        return '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    if (preg_match('/^54(9?)((?:11|[23]\d)\d{8})$/', $n, $m)) {
        $a = tel_largo_area($m[2]);
        $loc = substr($m[2], $a);
        $c = strlen($loc) - 4;
        return '+54 ' . ($m[1] !== '' ? '9 ' : '') . substr($m[2], 0, $a) . ' ' . substr($loc, 0, $c) . '-' . substr($loc, $c);
    }
    return $n;
}

/** ¿Es un celular (o un número de otro país, que puede tener WhatsApp)? */
function tel_es_movil(?string $n): bool
{
    return (bool) preg_match('/^(549\d{10}|\+\d{8,15})$/', (string) $n);
}

/** Link para llamar: tel:+5491123456789 (un 0800 o uno de otro país, como está). */
function tel_link(string $n): string
{
    $d = (string) preg_replace('/[^\d+]/', '', $n);
    return 'tel:' . (preg_match('/^54\d{10,11}$/', $d) ? '+' . $d : $d);
}

/** Link para escribirle por WhatsApp (solo celulares), o null. */
function tel_whatsapp(?string $n): ?string
{
    return tel_es_movil($n) ? 'https://wa.me/' . preg_replace('/\D/', '', (string) $n) : null;
}

/** Mensaje de error para un teléfono que no se entiende. */
function tel_error(string $que): string
{
    return $que . ': no se entiende el número. Escribilo con la característica, ej.: 11 2345-6789, 351 15 555-1234, 0800 555 1234 o +598 … si es de otro país.';
}
