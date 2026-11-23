<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Piezas comunes de Administración del Sistema (admin_*.php). Portadas de
// admin.py de gestion_sindicato. Las reglas que impiden que el sistema
// quede sin forma de administrarse:
//   - Siempre queda al menos un usuario activo con el rol Administrador.
//   - El rol Administrador no se desactiva ni pierde Usuarios, Roles ni
//     Menú (MENU_PROTEGIDO).
//   - Esas tres opciones no se desactivan, no se eliminan y no cambian de URL.
// ---------------------------------------------------------------------

const MENU_NIVELES = 4;

/** Cómo se nombra cada tabla que referencia a un usuario. */
const ETIQUETAS_USOS = [
    'clientes' => 'clientes', 'clientes_documentos' => 'documentos', 'consultas_bcra' => 'consultas BCRA',
    'comisiones_liquidacion' => 'liquidaciones de comisiones', 'inversiones' => 'aportes',
    'inversiones_liquidaciones' => 'liquidaciones de inversiones', 'inversores' => 'inversores',
    'lotes_cobranza' => 'lotes de cobranza', 'movimientos' => 'movimientos', 'pagos' => 'pagos',
    'prestamos' => 'préstamos', 'vendedores' => 'comercializadores',
];

/**
 * Lista plana (menu) en el orden del árbol. Cada nodo lleva prof (0 = raíz),
 * ancestros (ids desde la raíz) y n_hijos. Lo que no cuelga de una raíz
 * (un ciclo cargado a mano) sale al final como raíz, para verlo y arreglarlo.
 */
function arbol_plano(array $items, string $pk = 'id_menu'): array
{
    $ids = array_flip(array_map('intval', array_column($items, $pk)));
    $hijos = [];
    foreach ($items as $i) {
        $p = $i['padre_id'] !== null && isset($ids[(int) $i['padre_id']]) ? (int) $i['padre_id'] : 0;
        $hijos[$p][] = $i;
    }
    $salida = [];
    $vistos = [];
    $bajar = function (int $padre, int $prof, array $anc) use (&$bajar, &$salida, &$vistos, $hijos, $pk) {
        foreach ($hijos[$padre] ?? [] as $i) {
            $id = (int) $i[$pk];
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $salida[] = $i + ['prof' => $prof, 'ancestros' => $anc, 'n_hijos' => count($hijos[$id] ?? [])];
            $bajar($id, $prof + 1, array_merge($anc, [$id]));
        }
    };
    $bajar(0, 0, []);
    foreach ($items as $i) {
        if (!isset($vistos[(int) $i[$pk]])) {
            $salida[] = $i + ['prof' => 0, 'ancestros' => [], 'n_hijos' => count($hijos[(int) $i[$pk]] ?? [])];
        }
    }
    return $salida;
}

function menu_completo(): array
{
    return q('SELECT id_menu, padre_id, nombre, url, icono, icono_svg, orden, nivel, activo FROM menu ORDER BY orden, nombre');
}

/** Los ids y todas sus ramas: una opción sin su rama no se dibuja en el menú. */
function con_ancestros(array $ids, array $porId): array
{
    $res = [];
    foreach ($ids as $i) {
        while ($i && isset($porId[$i]) && !isset($res[$i])) {
            $res[$i] = true;
            $i = $porId[$i]['padre_id'] === null ? 0 : (int) $porId[$i]['padre_id'];
        }
    }
    return array_map('intval', array_keys($res));
}

function descendientes(array $arbol, int $id): array
{
    return array_map(function ($n) { return (int) $n['id_menu']; },
                     array_values(array_filter($arbol, function ($n) use ($id) { return in_array($id, $n['ancestros'], true); })));
}

/** Niveles del subárbol que cuelga del nodo, contándolo (una hoja = 1). */
function altura(array $arbol, int $id): int
{
    $base = 0;
    $max = 0;
    foreach ($arbol as $n) {
        if ((int) $n['id_menu'] === $id) {
            $base = $n['prof'];
        }
    }
    foreach ($arbol as $n) {
        if (in_array($id, $n['ancestros'], true)) {
            $max = max($max, $n['prof'] - $base);
        }
    }
    return 1 + $max;
}

/** nivel = profundidad en el árbol: se recalcula entero (mover una rama mueve todo lo que cuelga). */
function rearmar_niveles(): void
{
    foreach (arbol_plano(menu_completo()) as $n) {
        if ((int) $n['nivel'] !== $n['prof'] + 1) {
            q('UPDATE menu SET nivel = ? WHERE id_menu = ?', [$n['prof'] + 1, (int) $n['id_menu']]);
        }
    }
}

/** Ids de los ítems del POST (name="x[]"). */
function post_ids(string $nombre): array
{
    $v = $_POST[$nombre] ?? [];
    return is_array($v) ? array_values(array_unique(array_map('intval', array_filter($v, 'ctype_digit')))) : [];
}

/**
 * Dónde figura cada usuario: [id => [[cuántos, qué], ...]]. Se leen las
 * claves foráneas de la base (no una lista a mano que se desactualiza). Las
 * que borran en cascada (roles, permisos sueltos) no cuentan.
 */
function usos_usuarios(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $res = array_fill_keys($ids, []);
    if (!$ids) {
        return $res;
    }
    $refs = [];
    foreach (q("SELECT k.TABLE_NAME AS tabla, k.COLUMN_NAME AS columna
                  FROM information_schema.KEY_COLUMN_USAGE k
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'usuarios'
                   AND rc.DELETE_RULE <> 'CASCADE' AND k.TABLE_NAME <> 'usuarios'
                 ORDER BY k.TABLE_NAME, k.COLUMN_NAME") as $f) {
        $refs[$f['tabla']][] = $f['columna'];
    }
    if (!$refs) {
        return $res;
    }
    $partes = [];
    foreach ($refs as $t => $cols) {
        $cond = implode(' OR ', array_map(function ($c) { return "x.`$c` = u.id_usuario"; }, $cols));
        $partes[] = "(SELECT COUNT(*) FROM `$t` x WHERE $cond) AS `$t`";
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    foreach (q('SELECT u.id_usuario, ' . implode(', ', $partes) . " FROM usuarios u WHERE u.id_usuario IN ($marcas)", $ids) as $f) {
        foreach ($refs as $t => $_) {
            if ((int) $f[$t]) {
                $res[(int) $f['id_usuario']][] = [(int) $f[$t], ETIQUETAS_USOS[$t] ?? $t];
            }
        }
    }
    return $res;
}

function texto_usos(array $usos): string
{
    return implode(', ', array_map(function ($u) { return num($u[0]) . ' ' . $u[1]; }, $usos));
}

function es_admin(int $idUsuario): bool
{
    return (bool) q1('SELECT 1 AS x FROM usuarios_roles ur JOIN roles r ON r.id_rol = ur.id_rol
                       WHERE ur.id_usuario = ? AND r.nombre = ?', [$idUsuario, ROL_ADMIN]);
}

/** Usuarios activos con el rol Administrador, sin contar a $idUsuario. */
function otros_admins(int $idUsuario): int
{
    return (int) q1('SELECT COUNT(DISTINCT u.id_usuario) AS n FROM usuarios u
                       JOIN usuarios_roles ur ON ur.id_usuario = u.id_usuario
                       JOIN roles r ON r.id_rol = ur.id_rol AND r.nombre = ? AND r.activo = 1
                      WHERE u.activo = 1 AND u.id_usuario <> ?', [ROL_ADMIN, $idUsuario])['n'];
}

/**
 * Árbol del menú con casillas, para Roles y Permisos por usuario.
 *   $marcadas  ids tildados
 *   $fijas     [id => motivo] tildados y sin poder tocarse (lo que da el rol, lo protegido)
 * app.js tilda las ramas al tildar una opción y destilda lo de adentro al
 * destildar una rama.
 */
function arbol_casillas(array $arbol, array $marcadas, array $fijas = [], string $nombre = 'menu_ids'): string
{
    $out = '<div class="arbol-casillas" data-arbol-casillas>';
    foreach ($arbol as $n) {
        $id = (int) $n['id_menu'];
        $fija = isset($fijas[$id]);
        $marc = $fija || in_array($id, $marcadas, true);
        $out .= '<label class="arbol-nodo' . ($n['url'] === null ? ' es-rama' : '') . (!(int) $n['activo'] ? ' inactivo' : '')
              . '" style="--prof:' . $n['prof'] . '">'
              . '<input type="checkbox" name="' . h($nombre) . '[]" value="' . $id . '" data-id="' . $id . '" data-padre="'
              . ($n['padre_id'] === null ? '' : (int) $n['padre_id']) . '"' . ($marc ? ' checked' : '') . ($fija ? ' disabled' : '') . '>'
              . icono_menu($n, 16)
              . '<span class="arbol-nombre">' . h($n['nombre']) . '</span>'
              . ($n['url'] !== null ? '<span class="arbol-url">' . h($n['url']) . '</span>' : '')
              . (!(int) $n['activo'] ? estado('inactiva', 'neutro') : '')
              . ($fija ? '<span class="arbol-motivo">' . h($fijas[$id]) . '</span>' : '')
              . '</label>';
        if ($fija) {
            $out .= '<input type="hidden" name="' . h($nombre) . '[]" value="' . $id . '">';
        }
    }
    return $out . '</div>';
}
