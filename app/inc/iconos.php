<?php
declare(strict_types=1);

// Íconos de gestion_adm (templates/_ui.html): trazo de 2px sobre una caja de
// 24 y color = currentColor, así toman el color del botón que los contiene.
// Para agregar uno, copiarlo de allá con el mismo nombre.

/** Todos los íconos: nombre => trazos. El ABM del menú los ofrece de acá. */
function iconos_lista(): array
{
    static $iconos = [
        'filtro'        => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'quitar_filtro' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/><line x1="2" y1="2" x2="22" y2="22"/>',
        'pdf'           => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
        'planilla'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/>',
        'documento'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'dinero'        => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'ok'            => '<circle cx="12" cy="12" r="10"/><polyline points="16 9 10.5 15 8 12.5"/>',
        'grafico'       => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'telefono'      => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'ver'           => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'editar'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'borrar'        => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'agregar'       => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'guardar'       => '<polyline points="20 6 9 17 4 12"/>',
        'cerrar'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'alerta'        => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'usuario'       => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
        'grupo'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'empresa'       => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
        'etiqueta'      => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'ajustes'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'volver'        => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'error'         => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'info'          => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'calendario'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'descargar'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'subir'         => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'cobranza'      => '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'escudo'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'mensaje'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'casa'          => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'salir'         => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'buscar'        => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/>',
        'flecha'        => '<polyline points="6 9 12 15 18 9"/>',
        'llave'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'calculadora'   => '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><path d="M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
        'banco'         => '<path d="M3 21h18M3 10h18L12 3 3 10z"/><path d="M6 10v11M18 10v11M12 10v11"/>',
        'menu'          => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    ];
    return $iconos;
}

function icono(string $nombre, int $tam = 14): string
{
    $iconos = iconos_lista();
    $p = $iconos[$nombre] ?? $iconos['info'];
    return '<svg class="icono" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $p . '</svg>';
}

/**
 * Un ícono en colores (SVG guardado en menu.icono_svg) con sus id renombrados.
 * Los íconos Fluent Color pintan con degradados por id, y url(#id) apunta al
 * PRIMER elemento con ese id de la página: si el mismo ícono sale dos veces y
 * el primero está en un submenú cerrado, el navegador no dibuja el segundo.
 * Es el filtro svg_unico de gestion_sindicato (app.py).
 */
function svg_unico(string $svg, ?int $tam = null): string
{
    static $n = 0;
    $n++;
    if (preg_match_all('/\bid="([^"]+)"/', $svg, $m)) {
        foreach (array_unique($m[1]) as $id) {
            $nuevo = $id . '-u' . $n;
            $svg = str_replace(['id="' . $id . '"', 'url(#' . $id . ')', 'href="#' . $id . '"'],
                               ['id="' . $nuevo . '"', 'url(#' . $nuevo . ')', 'href="#' . $nuevo . '"'], $svg);
        }
    }
    if ($tam !== null) {
        $svg = preg_replace('/^<svg([^>]*?)\swidth="\d+"\sheight="\d+"/', '<svg$1 width="' . $tam . '" height="' . $tam . '"', $svg, 1);
    }
    return $svg;
}

/** El ícono de una opción del menú: el SVG en colores si tiene, si no el de trazo por nombre. */
function icono_menu(array $m, int $tam = 18): string
{
    if (!empty($m['icono_svg'])) {
        return svg_unico((string) $m['icono_svg'], $tam);
    }
    return !empty($m['icono']) ? icono((string) $m['icono'], $tam) : '';
}

/**
 * El SVG de un ícono que se carga en el ABM del menú: se dibuja tal cual,
 * así que tiene que ser un SVG y nada más (un ícono copiado de internet puede
 * traer un onload). Devuelve el error, o null si está bien.
 */
function icono_svg_error(?string $svg): ?string
{
    if ($svg === null || $svg === '') {
        return null;
    }
    $l = strtolower($svg);
    if (strpos($l, '<svg') !== 0 || substr(rtrim($l), -6) !== '</svg>') {
        return 'El ícono tiene que ser un SVG completo, de <svg …> a </svg>.';
    }
    if (preg_match('/<\s*script|<\s*foreignobject|\son\w+\s*=|javascript:/i', $svg)) {
        return 'El ícono trae código (script, on…=, javascript:): no se guarda.';
    }
    if (strlen($svg) > 6000) {
        return 'El ícono es demasiado largo (más de 6.000 caracteres).';
    }
    return null;
}
