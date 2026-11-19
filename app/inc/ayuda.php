<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Ayuda: los manuales de uso, como en gestion_sindicato.
//
// Cada manual es un archivo app/ayuda/<clave>.md, solo texto:
//
//   # Título del manual
//   <!-- orden: 3 -->
//   Introducción (uno o más párrafos).
//
//   ## Sección                      ← una tarjeta del manual
//   <!-- pantallas: prestamos.php prestamo.php -->   las pantallas que abren
//                                                      esta sección con F1;
//                                                      la primera es «Ir a la pantalla»
//   <!-- resumen: una línea -->
//   Texto en Markdown simple: párrafos, ### subtítulos, listas (1. y -,
//   un nivel adentro con 3 espacios), tablas con |, ``` bloques ```,
//   **negrita**, *cursiva* y `código`.
//
// Una pantalla nueva lleva su sección (o se suma a la línea «pantallas»
// de una existente): ayuda_verificar() lista las que no tienen.
// ---------------------------------------------------------------------

const AYUDA_DIR = __DIR__ . '/../ayuda';

/** Pantallas que no necesitan ayuda propia (salidas, redirecciones). */
const AYUDA_SIN_SECCION = ['logout.php'];

/** Texto para comparar: minúsculas y sin acentos. */
function ayuda_normalizar(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** «Cómo se calcula la comisión» → como-se-calcula-la-comision */
function ayuda_slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', ayuda_normalizar($s)), '-');
}

/**
 * Todos los manuales, en el orden del índice:
 * clave => [clave, titulo, orden, intro (md), secciones => [id, titulo, pantallas[], resumen, md]]
 */
function ayuda_manuales(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (glob(AYUDA_DIR . '/*.md') ?: [] as $archivo) {
        $clave = basename($archivo, '.md');
        $lineas = preg_split('/\r?\n/', (string) file_get_contents($archivo));
        $m = ['clave' => $clave, 'titulo' => $clave, 'orden' => 99, 'intro' => '', 'secciones' => []];
        $actual = null;
        $buf = [];
        $cerrar = function () use (&$m, &$actual, &$buf) {
            $md = trim(implode("\n", $buf));
            if ($actual === null) {
                $m['intro'] = $md;
            } else {
                $actual['md'] = $md;
                $m['secciones'][] = $actual;
            }
            $buf = [];
        };
        $enCodigo = false;
        foreach ($lineas as $l) {
            if (strncmp($l, '```', 3) === 0) {
                $enCodigo = !$enCodigo;
            }
            if (!$enCodigo && preg_match('/^# (.+)$/', $l, $r) && $actual === null && $m['titulo'] === $clave) {
                $m['titulo'] = trim($r[1]);
                continue;
            }
            if (!$enCodigo && preg_match('/^## (.+)$/', $l, $r)) {
                $cerrar();
                $actual = ['id' => ayuda_slug($r[1]), 'titulo' => trim($r[1]), 'pantallas' => [], 'resumen' => '', 'md' => ''];
                continue;
            }
            if (!$enCodigo && preg_match('/^<!--\s*(\w+):\s*(.*?)\s*-->$/', trim($l), $r)) {
                if ($r[1] === 'orden' && $actual === null) {
                    $m['orden'] = (int) $r[2];
                } elseif ($r[1] === 'pantallas' && $actual !== null) {
                    $actual['pantallas'] = preg_split('/\s+/', $r[2], -1, PREG_SPLIT_NO_EMPTY);
                } elseif ($r[1] === 'resumen' && $actual !== null) {
                    $actual['resumen'] = $r[2];
                }
                continue;
            }
            $buf[] = $l;
        }
        $cerrar();
        $cache[$clave] = $m;
    }
    uasort($cache, function ($a, $b) {
        return [$a['orden'], $a['titulo']] <=> [$b['orden'], $b['titulo']];
    });
    return $cache;
}

/** [clave del manual, id de sección] de una pantalla, o null. */
function ayuda_de_pantalla(string $pagina): ?array
{
    foreach (ayuda_manuales() as $m) {
        foreach ($m['secciones'] as $s) {
            if (in_array($pagina, $s['pantallas'], true)) {
                return [$m['clave'], $s['id']];
            }
        }
    }
    // Una sub-pantalla toma la ayuda de la pantalla de la que sale.
    foreach (PAGINAS_PERMISO[$pagina] ?? [] as $madre) {
        $r = ayuda_de_pantalla($madre);
        if ($r) {
            return $r;
        }
    }
    return null;
}

/** La dirección de la ayuda de la pantalla actual (el botón y F1). */
function url_ayuda(?string $pagina = null): string
{
    $r = ayuda_de_pantalla($pagina ?? pagina_actual());
    return $r ? 'ayuda.php?m=' . rawurlencode($r[0]) . '#' . rawurlencode($r[1]) : 'ayuda.php';
}

/** Pantallas sin sección de ayuda y secciones que nombran pantallas que no existen. */
function ayuda_verificar(): array
{
    $publicas = array_map('basename', glob(__DIR__ . '/../public/*.php') ?: []);
    $problemas = [];
    $ids = [];
    foreach (ayuda_manuales() as $m) {
        foreach ($m['secciones'] as $s) {
            if (isset($ids[$m['clave'] . '#' . $s['id']])) {
                $problemas[] = $m['clave'] . ': sección repetida «' . $s['titulo'] . '»';
            }
            $ids[$m['clave'] . '#' . $s['id']] = true;
            foreach ($s['pantallas'] as $p) {
                if (!in_array($p, $publicas, true)) {
                    $problemas[] = $m['clave'] . ' / ' . $s['titulo'] . ': la pantalla ' . $p . ' no existe';
                }
            }
        }
    }
    foreach ($publicas as $p) {
        if (!in_array($p, AYUDA_SIN_SECCION, true) && !ayuda_de_pantalla($p)) {
            $problemas[] = $p . ' no tiene sección de ayuda';
        }
    }
    return $problemas;
}

/** Secciones que tienen todas las palabras buscadas (en título, resumen o texto). */
function ayuda_buscar(string $q, ?string $soloManual = null): array
{
    $palabras = preg_split('/\s+/', ayuda_normalizar(trim($q)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$palabras) {
        return [];
    }
    $out = [];
    foreach (ayuda_manuales() as $m) {
        if ($soloManual !== null && $m['clave'] !== $soloManual) {
            continue;
        }
        foreach ($m['secciones'] as $s) {
            $texto = ayuda_normalizar($s['titulo'] . ' ' . $s['resumen'] . ' ' . $s['md']);
            $titulo = ayuda_normalizar($s['titulo'] . ' ' . $s['resumen']);
            $ok = true;
            $peso = 0;
            foreach ($palabras as $w) {
                if (strpos($texto, $w) === false) {
                    $ok = false;
                    break;
                }
                $peso += strpos($titulo, $w) !== false ? 10 : 1;
            }
            if ($ok) {
                $out[] = ['manual' => $m, 'seccion' => $s, 'peso' => $peso];
            }
        }
    }
    usort($out, function ($a, $b) { return $b['peso'] <=> $a['peso']; });
    return $out;
}

// ---------------------------------------------------------------------
// Markdown simple → HTML (todo el texto pasa por h()).
// ---------------------------------------------------------------------

function ayuda_inline(string $s): string
{
    $partes = preg_split('/(`[^`]+`)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($partes as $i => $p) {
        if ($i % 2 === 1) {
            $out .= '<code>' . h(substr($p, 1, -1)) . '</code>';
            continue;
        }
        $p = h($p);
        $p = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $p);
        $p = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $p);
        $out .= $p;
    }
    return $out;
}

function ayuda_html(string $md): string
{
    $lineas = preg_split('/\r?\n/', $md);
    $n = count($lineas);
    $html = '';
    $i = 0;
    while ($i < $n) {
        $l = $lineas[$i];
        if (trim($l) === '') {
            $i++;
            continue;
        }
        if (strncmp($l, '```', 3) === 0) {
            $code = [];
            for ($i++; $i < $n && strncmp($lineas[$i], '```', 3) !== 0; $i++) {
                $code[] = $lineas[$i];
            }
            $i++;
            $html .= '<pre class="manual-codigo"><code>' . h(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (preg_match('/^### (.+)$/', $l, $r)) {
            $html .= '<h3>' . ayuda_inline($r[1]) . '</h3>';
            $i++;
            continue;
        }
        if ($l[0] === '|') {
            $filas = [];
            for (; $i < $n && isset($lineas[$i][0]) && $lineas[$i][0] === '|'; $i++) {
                $filas[] = array_map('trim', explode('|', trim(trim($lineas[$i]), '|')));
            }
            $html .= '<div class="manual-tabla"><table class="data-table" data-sin-orden><thead><tr>';
            foreach ($filas[0] as $c) {
                $html .= '<th>' . ayuda_inline($c) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach (array_slice($filas, 1) as $f) {
                if (preg_match('/^[-: ]+$/', implode('', $f))) {
                    continue;
                }
                $html .= '<tr>';
                foreach ($f as $c) {
                    $html .= '<td>' . ayuda_inline($c) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            continue;
        }
        if (preg_match('/^(\d+\.|-) /', $l, $r)) {
            $ordenada = $r[1] !== '-';
            $html .= $ordenada ? '<ol class="manual-pasos">' : '<ul class="manual-lista">';
            while ($i < $n && preg_match($ordenada ? '/^\d+\. (.*)$/' : '/^- (.*)$/', $lineas[$i], $r)) {
                $item = ayuda_inline($r[1]);
                $i++;
                $sub = [];
                while ($i < $n && preg_match('/^ {2,}(?:-|\d+\.) (.*)$/', $lineas[$i], $s)) {
                    $sub[] = '<li>' . ayuda_inline($s[1]) . '</li>';
                    $i++;
                }
                $html .= '<li>' . $item . ($sub ? '<ul class="manual-lista">' . implode('', $sub) . '</ul>' : '') . '</li>';
            }
            $html .= $ordenada ? '</ol>' : '</ul>';
            continue;
        }
        $par = [];
        while ($i < $n && trim($lineas[$i]) !== '' && !preg_match('/^(```|### |\||\d+\. |- )/', $lineas[$i])) {
            $par[] = trim($lineas[$i]);
            $i++;
        }
        $html .= '<p>' . ayuda_inline(implode(' ', $par)) . '</p>';
    }
    return $html;
}
