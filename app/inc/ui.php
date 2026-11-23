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

// ---------------------------------------------------------------------
// Grillas con la forma de gestion_adm (estilos.html › «Grilla con filtros
// del servidor»): una sola tarjeta con
//   form.filter-bar    Buscar y los filtros con su rótulo, «Filtrar» y
//                      «Quitar filtro» (filtro_botones) y, a la derecha,
//                      el contador, «Exportar a Excel» y las filas por
//                      página (filtro_fin)
//   .data-table-wrap   la grilla; los encabezados con th_orden() ordenan en
//                      la base (orden_grilla). La barra de scroll de arriba
//                      la pone tabla.js cuando la grilla no entra.
//   .pagination-bar    « ‹ Pág. 2 de 7 · 340 registros › » (paginador)
// ---------------------------------------------------------------------

/**
 * Barra de paginación del servidor, la unificada de gestion_adm:
 *   « ‹ Pág. 2 de 7 · 340 registros › »
 * Las filas por página van arriba, en la barra de filtros (filtro_fin).
 * Arma los enlaces con los parámetros actuales (no se pierden los filtros
 * ni el orden). Con una sola página no se dibuja: el contador de arriba ya
 * dice cuántos son.
 */
function paginador(int $pagina, int $paginas, int $total, int $porPagina, string $unidad = 'registros'): string
{
    $paginas = max(1, $paginas);
    if ($paginas <= 1) {
        return '';
    }
    $link = function (string $txt, int $p, bool $deshab, string $titulo) {
        if ($deshab) {
            return '<button class="page-btn" disabled aria-label="' . h($titulo) . '">' . $txt . '</button>';
        }
        return '<a class="page-btn" href="' . h(url_con(['p' => $p > 1 ? $p : null])) . '" title="' . h($titulo) . '">' . $txt . '</a>';
    };
    return '<div class="pagination-bar pg-unificado"><div class="pagination-controls">'
         . $link('«', 1, $pagina <= 1, 'Primera página') . $link('‹', $pagina - 1, $pagina <= 1, 'Página anterior')
         . '<span class="pagination-info">Pág. ' . $pagina . ' de ' . $paginas . '  ·  ' . num($total) . ' ' . h($unidad) . '</span>'
         . $link('›', $pagina + 1, $pagina >= $paginas, 'Página siguiente') . $link('»', $paginas, $pagina >= $paginas, 'Última página')
         . '</div></div>';
}

/**
 * «Filtrar» y «Quitar filtro», con texto (fbar.botones(url, texto=True) de
 * gestion_adm). Quitar filtro vuelve a $limpiar y está apagado si no hay
 * nada que quitar.
 */
function filtro_botones(string $limpiar, bool $hayFiltro): string
{
    return '<button type="submit" class="btn btn-primary btn-sm btn-filtrar">' . icono('filtro') . 'Filtrar</button>'
         . ($hayFiltro
            ? '<a class="btn btn-secondary btn-sm btn-quitar-filtro" href="' . h($limpiar) . '">' . icono('quitar_filtro') . 'Quitar filtro</a>'
            : '<button type="button" class="btn btn-secondary btn-sm btn-quitar-filtro" disabled>' . icono('quitar_filtro') . 'Quitar filtro</button>');
}

/**
 * El grupo de la derecha de la barra de filtros (.filter-bar-fin):
 *   total       registros del filtro: «Mostrando 51–100 de 340 registros»
 *               (null: sin contador)
 *   pagina, por_pagina   la página que se ve (por_pagina 0: todo junto)
 *   unidad      «registros», «pagos»…
 *   excel       URL del Excel con los mismos filtros (null: sin botón; las
 *               tablas de configuración no llevan)
 *   filas       el selector de filas por página: name=per_page, manda el
 *               formulario de la barra (vuelve a la primera página)
 *   extra       HTML que va antes del contador
 */
function filtro_fin(array $o): string
{
    $out = '<div class="filter-bar-fin">' . ($o['extra'] ?? '');
    if (isset($o['total'])) {
        $total = (int) $o['total'];
        $unidad = (string) ($o['unidad'] ?? 'registros');
        $por = (int) ($o['por_pagina'] ?? 0);
        $pag = max(1, (int) ($o['pagina'] ?? 1));
        if ($por > 0 && $total > $por) {
            $desde = ($pag - 1) * $por + 1;
            $hasta = min($pag * $por, $total);
            $txt = 'Mostrando ' . num($desde) . '–' . num($hasta) . ' de ' . num($total) . ' ' . $unidad;
        } else {
            $txt = num($total) . ' ' . ($total === 1 ? unidad_singular($unidad) : $unidad);
        }
        $out .= '<span class="filter-count">' . h($txt) . '</span>';
    }
    if (!empty($o['excel'])) {
        $out .= '<a class="btn btn-excel btn-sm btn-exportar" href="' . h($o['excel']) . '">' . icono('planilla') . 'Exportar a Excel</a>';
    }
    if (!empty($o['filas'])) {
        $actual = (int) ($o['por_pagina'] ?? 50);
        $out .= '<select name="per_page" class="page-size-select" aria-label="Filas por página" onchange="this.form.submit()">';
        foreach ([25, 50, 100, 200] as $n) {
            $out .= '<option value="' . $n . '"' . ($n === $actual ? ' selected' : '') . '>' . $n . ' por pág.</option>';
        }
        $out .= '</select>';
    }
    return $out . '</div>';
}

/**
 * El grupo de la derecha de una barra cuya grilla trae todas las filas y la
 * pagina el navegador (tabla.js, data-paginada): el contador lo escribe el
 * JS, el selector de filas no manda el formulario. $excel:
 *   null     sin botón (tablas de configuración)
 *   ''       Excel de lo que está filtrado en la página (data-local:
 *            exportar_grilla.php)
 *   'url'    Excel del servidor con los mismos filtros
 */
function filtro_fin_local(?string $excel = '', string $extra = ''): string
{
    $out = '<div class="filter-bar-fin">' . $extra . '<span class="filter-count"></span>';
    if ($excel === '') {
        $out .= '<button type="button" class="btn btn-excel btn-sm btn-exportar">' . icono('planilla') . 'Exportar a Excel</button>';
    } elseif ($excel !== null) {
        $out .= '<a class="btn btn-excel btn-sm" href="' . h($excel) . '">' . icono('planilla') . 'Exportar a Excel</a>';
    }
    $out .= '<select class="page-size-select" aria-label="Filas por página">';
    foreach ([25, 50, 100, 200] as $n) {
        $out .= '<option value="' . $n . '"' . ($n === 25 ? ' selected' : '') . '>' . $n . ' por pág.</option>';
    }
    return $out . '</select></div>';
}

/** La barra de paginación que dibuja tabla.js (data-paginada), debajo de la grilla. */
function paginacion_local(): string
{
    return '<div class="pagination-bar pg-unificado"><div class="pagination-controls"></div></div>';
}

/**
 * La barra completa de una grilla que trae todas las filas (table
 * data-local data-paginada, con paginacion_local() debajo): el buscador
 * que filtra mientras se escribe, los filtros que van a la base ($filtros,
 * con filtro_select; si hay, Filtrar y Quitar filtro), el contador, el
 * Excel ($excel, como filtro_fin_local: null en las tablas de
 * configuración) y las filas por página.
 *   $url      la pantalla, con lo que tiene que conservar al filtrar
 *             (?id=7&solapa=tasas): va en ocultos, porque un GET pierde la
 *             consulta del action. Es también la de Quitar filtro.
 */
function barra_local(string $url, string $buscar, string $filtros = '', bool $hayFiltro = false, ?string $excel = null): string
{
    [$pagina, $consulta] = array_pad(explode('?', $url, 2), 2, '');
    parse_str($consulta, $fijos);
    $ocultos = '';
    foreach ($fijos as $k => $v) {
        if (is_string($v)) {
            $ocultos .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
    }
    return '<form method="get" action="' . h($pagina) . '" class="filter-bar">' . $ocultos
         . '<label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>'
         . '<input type="search" class="filter-input" placeholder="' . h($buscar) . '"></label>'
         . $filtros
         . ($filtros !== '' ? filtro_botones($url, $hayFiltro) : '')
         . filtro_fin_local($excel) . '</form>';
}

/** Un combo de la barra de filtros que va a la base: aplica solo al elegir (criterio de _filtros.html). */
function filtro_select(string $nombre, string $etiqueta, array $lista, $elegido, string $vacio = '', bool $activo = false): string
{
    return '<label class="filtro-campo"><span class="filtro-lbl">' . h($etiqueta) . '</span>'
         . '<select name="' . h($nombre) . '" class="filtro' . ($activo ? ' activo' : '') . '" onchange="this.form.submit()">'
         . opciones($lista, $elegido, $vacio) . '</select></label>';
}

/**
 * Un botón de la columna Acciones que manda un POST (deshabilitar,
 * eliminar…), con su confirmación. $campos: los ocultos (accion, id…);
 * el token CSRF lo pone solo. $clase 'borrar' lo pinta de rojo y pide la
 * confirmación como peligrosa.
 */
function accion_post(string $url, array $campos, string $icono, string $titulo, string $confirmar = '', string $clase = ''): string
{
    $ocultos = '';
    foreach ($campos as $k => $v) {
        $ocultos .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return '<form method="post" action="' . h($url) . '" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo)
             . '" data-confirmar-boton="' . h($titulo) . '"' . ($clase === 'borrar' ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . $ocultos
         . '<button class="btn-accion ' . h($clase) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

/** Las solapas de una ficha (enlaces): $solapas = clave => [texto, url, conteo|null]. */
function solapas(array $solapas, string $activa): string
{
    $out = '<div class="abm-tabs">';
    foreach ($solapas as $k => $s) {
        $out .= '<a class="solapa' . ($k === $activa ? ' activa' : '') . '" href="' . h($s[1]) . '">' . h($s[0])
              . (isset($s[2]) && $s[2] !== null ? ' <span class="conteo">' . h((string) $s[2]) . '</span>' : '') . '</a>';
    }
    return $out . '</div>';
}

/**
 * Un importe tipeado en un filtro: «1.234,56», «40.000», «1234.5» o «1234,5».
 * Con coma, la coma es la de los decimales; sin coma, los puntos que separan
 * grupos de tres son de miles (40.000 son cuarenta mil). null si está vacío
 * o no se entiende.
 */
function importe_filtro(string $s): ?float
{
    $s = str_replace([' ', '$'], '', trim($s));
    if ($s === '') {
        return null;
    }
    if (strpos($s, ',') !== false) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

/** «1 registro», «1 orden»: el singular de la unidad de un contador. */
function unidad_singular(string $u): string
{
    $irregulares = ['órdenes' => 'orden', 'renglones' => 'renglón', 'solicitudes' => 'solicitud', 'inversores' => 'inversor',
                    'vendedores' => 'vendedor', 'comercializadores' => 'comercializador', 'importaciones' => 'importación', 'cotizaciones' => 'cotización'];
    return $irregulares[$u] ?? (substr($u, -1) === 's' ? substr($u, 0, -1) : $u);
}

/**
 * El orden de una grilla del servidor: ?orden=clave&dir=asc|desc, validado
 * contra $columnas (clave => expresión SQL). Nunca una columna cualquiera
 * desde la URL. Los vacíos van al final en los dos sentidos.
 * Un ENUM se ordena por su posición en la definición: los estados quedan en
 * el orden del circuito (pendiente, aprobada, liquidada…), que es lo que se
 * busca; un medio o un tipo va con CAST(col AS CHAR), en orden alfabético.
 * Devuelve ['clave', 'dir', 'sql'] (sql: lo que va después de ORDER BY).
 */
function orden_grilla(array $columnas, string $defecto, string $dirDefecto = 'asc', string $desempate = ''): array
{
    $clave = get_str('orden');
    $dir = strtolower(get_str('dir')) === 'desc' ? 'desc' : 'asc';
    if (!isset($columnas[$clave])) {
        $clave = $defecto;
        $dir = $dirDefecto;
    }
    $expr = $columnas[$clave];
    $sql = "($expr) IS NULL, $expr " . strtoupper($dir);
    if ($desempate !== '') {
        // Sin sentido propio, el desempate va en el mismo que la columna.
        $sql .= ', ' . $desempate . (preg_match('/\s(ASC|DESC)$/i', $desempate) ? '' : ' ' . strtoupper($dir));
    }
    return ['clave' => $clave, 'dir' => $dir, 'sql' => $sql];
}

/** Encabezado que ordena en la base (tabla.js lo lleva a ?orden=clave&dir=…). */
function th_orden(string $texto, string $clave, array $orden, string $clase = ''): string
{
    $c = trim($clase . ($orden['clave'] === $clave ? ' sort-' . $orden['dir'] : ''));
    return '<th data-orden="' . h($clave) . '"' . ($c !== '' ? ' class="' . h($c) . '"' : '') . '>' . h($texto) . '</th>';
}

/** El orden actual como ocultos del formulario de filtros: filtrar no lo pierde. */
function orden_ocultos(): string
{
    $out = '';
    foreach (['orden', 'dir'] as $k) {
        if (get_str($k) !== '') {
            $out .= '<input type="hidden" name="' . $k . '" value="' . h(get_str($k)) . '">';
        }
    }
    return $out;
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
// CBU / CVU y alias: a dónde se le transfiere a alguien
//   CBU  22 números: entidad (3) + sucursal (4) + verificador, y cuenta (13)
//        + verificador. Los tres primeros son el código BCRA del banco
//        (tabla bancos, de financiera_fondos.sql). El banco no se guarda:
//        sale del CBU.
//   CVU  lo mismo para una billetera virtual: empieza con 000.
//   Alias de 6 a 20 caracteres: letras sin ñ ni acentos, números, punto y
//        guion. No distingue mayúsculas: se guarda en mayúsculas.
// Los campos se dibujan con campos_datos_bancarios(); app.js los valida
// mientras se escriben (input[data-cbu], input[data-alias-cbu]).
// ---------------------------------------------------------------------

function cbu_limpio(string $s): string
{
    return preg_replace('/\D/', '', $s);
}

/** Los dos dígitos verificadores: del primer bloque (8) y del segundo (14). */
function cbu_valido(string $cbu): bool
{
    if (!preg_match('/^\d{22}$/', $cbu)) {
        return false;
    }
    $dv = function (string $bloque, array $pesos): int {
        $s = 0;
        foreach ($pesos as $i => $p) {
            $s += (int) $bloque[$i] * $p;
        }
        return (10 - $s % 10) % 10;
    };
    return $dv(substr($cbu, 0, 7), [7, 1, 3, 9, 7, 1, 3]) === (int) $cbu[7]
        && $dv(substr($cbu, 8, 13), [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3]) === (int) $cbu[21];
}

/** 0110599520000001234567 -> 01105995 20000001234567 (para leerlo) */
function cbu_fmt(?string $cbu): string
{
    $c = (string) $cbu;
    return strlen($c) === 22 ? substr($c, 0, 8) . ' ' . substr($c, 8) : $c;
}

function alias_cbu_limpio(string $s): string
{
    return strtoupper(preg_replace('/\s+/', '', $s));
}

function alias_cbu_valido(string $alias): bool
{
    return (bool) preg_match('/^[A-Z0-9.\-]{6,20}$/', $alias);
}

/** Código BCRA => nombre del banco. Vacío si todavía no se corrió financiera_fondos.sql. */
function cbu_bancos(): array
{
    static $bancos = null;
    if ($bancos === null) {
        try {
            $bancos = array_column(q('SELECT codigo, nombre FROM bancos'), 'nombre', 'codigo');
        } catch (Throwable $e) {
            $bancos = [];
        }
    }
    return $bancos;
}

/** De quién es la cuenta según el CBU: el banco, «billetera virtual» si es un CVU, o '' si no se sabe. */
function cbu_banco(?string $cbu): string
{
    $c = (string) $cbu;
    if (strlen($c) !== 22) {
        return '';
    }
    return substr($c, 0, 3) === '000' ? 'billetera virtual' : (cbu_bancos()[substr($c, 0, 3)] ?? '');
}

/** Errores del CBU / CVU y el alias de un formulario (ya pasados por cbu_limpio y alias_cbu_limpio). */
function datos_bancarios_errores(string $cbu, string $alias): array
{
    $e = [];
    if ($cbu !== '' && strlen($cbu) !== 22) {
        $e[] = 'El CBU / CVU tiene 22 números y se escribieron ' . strlen($cbu) . '.';
    } elseif ($cbu !== '' && !cbu_valido($cbu)) {
        $e[] = 'El CBU / CVU ' . cbu_fmt($cbu) . ' no es válido: los dígitos verificadores no coinciden.';
    }
    if ($alias !== '' && !alias_cbu_valido($alias)) {
        $e[] = 'El alias va de 6 a 20 caracteres, sin espacios: letras sin ñ ni acentos, números, punto o guion.';
    }
    return $e;
}

/** «CBU 01105995 20000001234567 · Banco de la Nación Argentina · alias NOMBRE.APELLIDO» (HTML), o '' si no hay ninguno. */
function datos_bancarios_html(?string $cbu, ?string $alias): string
{
    $p = [];
    $cbu = (string) $cbu;
    if ($cbu !== '') {
        $p[] = (substr($cbu, 0, 3) === '000' ? 'CVU' : 'CBU') . ' <span class="mono">' . h(cbu_fmt($cbu)) . '</span>';
        if (($b = cbu_banco($cbu)) !== '') {
            $p[] = h($b);
        }
    }
    if ((string) $alias !== '') {
        $p[] = 'alias <span class="mono">' . h($alias) . '</span>';
    }
    return implode(' · ', $p);
}

/**
 * Los campos CBU / CVU (name="cbu") y alias (name="alias_cbu") de una ficha.
 * $ayuda va debajo del CBU mientras está vacío (para qué es); escrito, dice
 * de qué banco es o qué tiene mal.
 */
function campos_datos_bancarios(?string $cbu, ?string $alias, string $ayuda = '', int $spanCbu = 5, int $spanAlias = 3): string
{
    $bancos = cbu_bancos();
    return '<div class="campo" style="--span:' . $spanCbu . '"><label for="cbu">CBU / CVU</label>'
        . '<input id="cbu" name="cbu" value="' . h(cbu_fmt($cbu)) . '" class="mono" maxlength="26" inputmode="numeric" autocomplete="off"'
        . ' placeholder="22 números" data-cbu' . ($bancos ? ' data-bancos="' . h(json_encode($bancos, JSON_UNESCAPED_UNICODE)) . '"' : '') . '>'
        . '<span class="campo-ayuda" data-base="' . h($ayuda) . '">' . h($ayuda) . '</span></div>'
        . '<div class="campo" style="--span:' . $spanAlias . '"><label for="alias_cbu">Alias</label>'
        . '<input id="alias_cbu" name="alias_cbu" value="' . h((string) $alias) . '" class="mono" maxlength="20" autocomplete="off"'
        . ' placeholder="NOMBRE.APELLIDO" data-alias-cbu>'
        . '<span class="campo-ayuda"></span></div>';
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
