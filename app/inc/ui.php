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
