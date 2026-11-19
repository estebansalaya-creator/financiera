<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Vendedores: alta, edición, baja y sus reglas de comisión.
//   - El código es el que figura en las planillas de comisiones (ALONSO,
//     AMTAE…): único y en mayúsculas.
//   - Un vendedor que tiene clientes, préstamos o comisiones no se
//     elimina: se da de baja (deja de ofrecerse al cargar un cliente o un
//     préstamo, y lo que tiene sigue a su nombre).
//   - Las reglas de comisión de la solapa son las suyas (comision_reglas
//     con su id_vendedor). La comisión se congela en cada préstamo al
//     liquidarlo: cambiar una regla no toca lo ya liquidado.
// ---------------------------------------------------------------------

const VINCULOS = ['COMISIONISTA' => 'Comisionista', 'EMPLEADO' => 'Empleado'];
const BASES    = ['CAPITAL' => 'Capital', 'NETO_DESEMBOLSO' => 'Neto desembolsado'];
const TM       = ['' => 'Cualquiera', '1' => 'Sí', '0' => 'No'];

$errores = [];
$reg = null;
$reglasForm = null;
$id = (int) (es_post() ? post_str('id') : get_str('edit'));
$solapa = get_str('solapa') === 'comisiones' ? 'comisiones' : 'datos';

/** Arreglo POST como lista de textos. */
function post_lista(string $clave): array
{
    $v = $_POST[$clave] ?? [];
    return is_array($v) ? array_map(function ($x) { return is_string($x) ? trim($x) : ''; }, $v) : [];
}

/** Dónde figura un vendedor (lo que impide eliminarlo). */
function usos_vendedor(int $id): array
{
    $u = q1('SELECT (SELECT COUNT(*) FROM clientes WHERE id_vendedor = ?) AS clientes,
                    (SELECT COUNT(*) FROM prestamos WHERE id_vendedor = ?) AS prestamos,
                    (SELECT COUNT(*) FROM comisiones_devengadas WHERE id_vendedor = ?) AS devengadas,
                    (SELECT COUNT(*) FROM comisiones_importadas WHERE id_vendedor = ?) AS importadas,
                    (SELECT COUNT(*) FROM comisiones_liquidacion WHERE id_vendedor = ?) AS liquidaciones,
                    (SELECT COUNT(*) FROM vendedores WHERE id_supervisor = ?) AS supervisados',
            [$id, $id, $id, $id, $id, $id]);
    $nombres = ['clientes' => 'clientes', 'prestamos' => 'préstamos', 'devengadas' => 'comisiones devengadas',
                'importadas' => 'renglones de planillas de comisiones', 'liquidaciones' => 'liquidaciones de comisiones',
                'supervisados' => 'vendedores que supervisa'];
    $out = [];
    foreach ($nombres as $k => $n) {
        if ((int) $u[$k]) {
            $out[] = num($u[$k]) . ' ' . $n;
        }
    }
    return $out;
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $actual = $id ? q1('SELECT * FROM vendedores WHERE id_vendedor = ?', [$id]) : null;
    if ($id && !$actual) {
        flash('danger', 'El vendedor ya no existe.');
        redirigir('vendedores.php');
    }

    if ($accion === 'baja' && $actual) {
        q('UPDATE vendedores SET activo = 0, fecha_baja = COALESCE(fecha_baja, CURDATE()) WHERE id_vendedor = ?', [$id]);
        flash('ok', '«' . $actual['nombre'] . '» dado de baja: ya no se ofrece al cargar clientes ni préstamos. Lo que tiene sigue a su nombre.');
        redirigir('vendedores.php');
    }
    if ($accion === 'reactivar' && $actual) {
        q('UPDATE vendedores SET activo = 1, fecha_baja = NULL WHERE id_vendedor = ?', [$id]);
        flash('ok', '«' . $actual['nombre'] . '» reactivado.');
        redirigir('vendedores.php');
    }
    if ($accion === 'borrar' && $actual) {
        $usos = usos_vendedor($id);
        if ($usos) {
            flash('danger', 'No se puede eliminar a «' . $actual['nombre'] . '»: tiene ' . implode(', ', $usos) . '. Dalo de baja.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            q('DELETE FROM comision_reglas WHERE id_vendedor = ?', [$id]);
            q('DELETE FROM vendedores WHERE id_vendedor = ?', [$id]);
            $pdo->commit();
            flash('ok', '«' . $actual['nombre'] . '» eliminado, con sus reglas de comisión.');
        }
        redirigir('vendedores.php');
    }

    if ($accion === 'guardar') {
        $reg = ['id_vendedor' => $id ?: null,
                'codigo' => strtoupper(preg_replace('/\s+/', '_', post_str('codigo'))),
                'nombre' => mb_strtoupper(preg_replace('/\s+/', ' ', post_str('nombre')), 'UTF-8'),
                'cuit' => cuit_limpio(post_str('cuit')),
                'tipo_vinculo' => post_str('tipo_vinculo'),
                'id_zona' => (int) post_str('id_zona') ?: null,
                'zona_nueva' => mb_substr(preg_replace('/\s+/', ' ', post_str('zona_nueva')), 0, 80),
                'id_supervisor' => (int) post_str('id_supervisor') ?: null,
                'id_usuario' => (int) post_str('id_usuario') ?: null,
                'fecha_alta' => leer_fecha(post_str('fecha_alta')),
                'activo' => post_str('activo') === '1' ? 1 : 0];
        if (!preg_match('/^[A-Z0-9_.-]{2,20}$/', $reg['codigo'])) {
            $errores[] = 'El código va sin espacios, de 2 a 20 letras o números (es el que figura en las planillas de comisiones).';
        } elseif ($o = q1('SELECT nombre FROM vendedores WHERE codigo = ? AND id_vendedor <> ?', [$reg['codigo'], $id])) {
            $errores[] = 'El código ' . $reg['codigo'] . ' ya es de «' . $o['nombre'] . '».';
        }
        if ($reg['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if ($reg['cuit'] !== '' && !cuit_valido($reg['cuit'])) {
            $errores[] = 'El CUIT/CUIL ' . cuit_fmt($reg['cuit']) . ' no es válido (dígito verificador).';
        } elseif ($reg['cuit'] !== '' && ($o = q1('SELECT nombre FROM vendedores WHERE cuit = ? AND id_vendedor <> ?', [$reg['cuit'], $id]))) {
            $errores[] = 'El CUIT ' . cuit_fmt($reg['cuit']) . ' ya es de «' . $o['nombre'] . '».';
        }
        if (!isset(VINCULOS[$reg['tipo_vinculo']])) {
            $errores[] = 'Elegí el vínculo.';
        }
        if (!$reg['fecha_alta']) {
            $errores[] = 'La fecha de alta es obligatoria.';
        }
        if ($reg['id_supervisor'] && $reg['id_supervisor'] === $id) {
            $errores[] = 'Un vendedor no puede ser su propio supervisor.';
        }
        if ($reg['id_usuario'] && ($o = q1('SELECT nombre FROM vendedores WHERE id_usuario = ? AND id_vendedor <> ?', [$reg['id_usuario'], $id]))) {
            $errores[] = 'Ese usuario ya está vinculado a «' . $o['nombre'] . '».';
        }

        if (!$errores) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                if ($reg['zona_nueva'] !== '') {
                    $z = q1('SELECT id_zona FROM zonas WHERE nombre = ?', [$reg['zona_nueva']]);
                    if ($z) {
                        $reg['id_zona'] = (int) $z['id_zona'];
                    } else {
                        q('INSERT INTO zonas (nombre, activo) VALUES (?, 1)', [$reg['zona_nueva']]);
                        $reg['id_zona'] = (int) $pdo->lastInsertId();
                    }
                }
                $datos = [$reg['codigo'], $reg['nombre'], $reg['cuit'] ?: null, $reg['tipo_vinculo'], $reg['id_zona'],
                          $reg['id_supervisor'], $reg['id_usuario'], $reg['fecha_alta'], $reg['activo']];
                if ($id) {
                    q('UPDATE vendedores SET codigo = ?, nombre = ?, cuit = ?, tipo_vinculo = ?, id_zona = ?, id_supervisor = ?,
                              id_usuario = ?, fecha_alta = ?, activo = ?,
                              fecha_baja = IF(? = 1, NULL, COALESCE(fecha_baja, CURDATE()))
                        WHERE id_vendedor = ?', array_merge($datos, [$reg['activo'], $id]));
                } else {
                    q('INSERT INTO vendedores (codigo, nombre, cuit, tipo_vinculo, id_zona, id_supervisor, id_usuario, fecha_alta, activo)
                       VALUES (?,?,?,?,?,?,?,?,?)', $datos);
                    $id = (int) $pdo->lastInsertId();
                }
                $pdo->commit();
                flash('ok', '«' . $reg['nombre'] . '» ' . ($reg['id_vendedor'] ? 'guardado.' : 'dado de alta. Cargale sus reglas de comisión.'));
                redirigir('vendedores.php?edit=' . $id . ($reg['id_vendedor'] ? '' : '&solapa=comisiones'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    }

    if ($accion === 'reglas' && $actual) {
        $solapa = 'comisiones';
        $ids = post_lista('id_regla');
        $prod = post_lista('id_producto');
        $tm = post_lista('telemarketing');
        $pdesde = post_lista('plazo_desde');
        $phasta = post_lista('plazo_hasta');
        $pct = post_lista('porcentaje');
        $base = post_lista('base_calculo');
        $prio = post_lista('prioridad');
        $vdesde = post_lista('vigencia_desde');
        $vhasta = post_lista('vigencia_hasta');
        $act = array_map('strval', array_keys((array) ($_POST['activo_regla'] ?? [])));
        $productos = array_column(q('SELECT id_producto, codigo FROM productos'), 'codigo', 'id_producto');

        $filas = [];
        foreach ($pct as $k => $p) {
            $rid = (int) ($ids[$k] ?? 0);
            if ($rid === 0 && $p === '') {
                continue;                                // renglón nuevo vacío
            }
            $f = ['id_regla' => $rid, 'id_producto' => (int) ($prod[$k] ?? 0) ?: null,
                  'telemarketing' => ($tm[$k] ?? '') === '' ? null : (int) $tm[$k],
                  'plazo_desde' => $pdesde[$k] ?? '1', 'plazo_hasta' => $phasta[$k] ?? '999',
                  'porcentaje' => $p, 'base_calculo' => $base[$k] ?? 'CAPITAL', 'prioridad' => $prio[$k] ?? '20',
                  'vigencia_desde' => $vdesde[$k] ?? '', 'vigencia_hasta' => $vhasta[$k] ?? '',
                  'activo' => $rid === 0 || in_array((string) $k, $act, true) ? 1 : 0];
            $r = 'Renglón ' . ($k + 1) . ': ';
            $n = leer_num($f['porcentaje']);
            if ($n === null || $n < 0 || $n > 100) {
                $errores[] = $r . 'el % de comisión va de 0 a 100.';
            }
            $f['pct_num'] = $n;
            if ($f['id_producto'] && !isset($productos[$f['id_producto']])) {
                $errores[] = $r . 'el producto no existe.';
            }
            if (!ctype_digit($f['plazo_desde'] ?: '1') || !ctype_digit($f['plazo_hasta'] ?: '999') || (int) ($f['plazo_desde'] ?: 1) > (int) ($f['plazo_hasta'] ?: 999)) {
                $errores[] = $r . 'el rango de cuotas no es válido.';
            }
            if (!isset(BASES[$f['base_calculo']])) {
                $errores[] = $r . 'elegí la base.';
            }
            if (!preg_match('/^-?\d{1,4}$/', $f['prioridad'] ?: '0')) {
                $errores[] = $r . 'la prioridad es un número.';
            }
            $f['vd'] = leer_fecha($f['vigencia_desde']);
            $f['vh'] = $f['vigencia_hasta'] === '' ? null : leer_fecha($f['vigencia_hasta']);
            if (!$f['vd']) {
                $errores[] = $r . 'falta la fecha desde la que rige.';
            }
            if ($f['vigencia_hasta'] !== '' && (!$f['vh'] || ($f['vd'] && $f['vh'] < $f['vd']))) {
                $errores[] = $r . 'la vigencia «hasta» no puede ser anterior a «desde».';
            }
            $filas[] = $f;
        }
        $reglasForm = $filas;

        if (!$errores) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                foreach ($filas as $f) {
                    $desc = mb_substr($actual['codigo'] . ' - ' . ($f['id_producto'] ? $productos[$f['id_producto']] : 'TODOS')
                          . ' - TM ' . ($f['telemarketing'] === null ? 'CUALQUIERA' : ($f['telemarketing'] ? 'SI' : 'NO')), 0, 120);
                    $v = [$desc, $f['id_producto'], $f['telemarketing'], (int) ($f['plazo_desde'] ?: 1), (int) ($f['plazo_hasta'] ?: 999),
                          round($f['pct_num'] / 100, 6), $f['base_calculo'], (int) ($f['prioridad'] ?: 0), $f['vd'], $f['vh'], $f['activo']];
                    if ($f['id_regla']) {
                        q('UPDATE comision_reglas SET descripcion = ?, id_producto = ?, telemarketing = ?, plazo_desde = ?, plazo_hasta = ?,
                                  porcentaje = ?, base_calculo = ?, prioridad = ?, vigencia_desde = ?, vigencia_hasta = ?, activo = ?
                            WHERE id_regla = ? AND id_vendedor = ?', array_merge($v, [$f['id_regla'], $id]));
                    } else {
                        q('INSERT INTO comision_reglas (descripcion, id_producto, telemarketing, plazo_desde, plazo_hasta, porcentaje,
                                                        base_calculo, prioridad, vigencia_desde, vigencia_hasta, activo, id_vendedor)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($v, [$id]));
                    }
                }
                $pdo->commit();
                flash('ok', 'Reglas de comisión de «' . $actual['nombre'] . '» guardadas. Rigen para los préstamos que se liquiden de ahora en más.');
                redirigir('vendedores.php?edit=' . $id . '&solapa=comisiones');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------

if ($id && $reg === null) {
    $reg = q1('SELECT * FROM vendedores WHERE id_vendedor = ?', [$id]);
    if (!$reg) {
        flash('danger', 'El vendedor no existe.');
        redirigir('vendedores.php');
    }
    $reg['zona_nueva'] = '';
} elseif ($reg === null && get_str('nuevo') !== '') {
    $reg = ['id_vendedor' => null, 'codigo' => '', 'nombre' => '', 'cuit' => '', 'tipo_vinculo' => 'COMISIONISTA',
            'id_zona' => null, 'zona_nueva' => '', 'id_supervisor' => null, 'id_usuario' => null,
            'fecha_alta' => date('Y-m-d'), 'activo' => 1];
}

$zonas = array_column(q('SELECT id_zona, nombre FROM zonas WHERE activo = 1 ORDER BY nombre'), 'nombre', 'id_zona');
$supervisores = array_column(q('SELECT id_vendedor, CONCAT(nombre, " (", codigo, ")") AS n FROM vendedores
                                 WHERE activo = 1 AND id_vendedor <> ? ORDER BY nombre', [(int) $id]), 'n', 'id_vendedor');
$usuarios = array_column(q('SELECT id_usuario, CONCAT(nombre, " · ", email) AS n FROM usuarios WHERE activo = 1 ORDER BY nombre'), 'n', 'id_usuario');
$productos = array_column(q('SELECT id_producto, CONCAT(codigo, " · ", nombre) AS n FROM productos ORDER BY codigo'), 'n', 'id_producto');

$reglas = [];
if ($reg && $reg['id_vendedor']) {
    $reglas = q('SELECT r.*, (SELECT COUNT(*) FROM comisiones_devengadas d WHERE d.id_regla = r.id_regla) AS usos
                   FROM comision_reglas r WHERE r.id_vendedor = ?
                  ORDER BY r.activo DESC, (r.id_producto IS NULL), r.id_producto, r.telemarketing, r.vigencia_desde', [(int) $reg['id_vendedor']]);
    if ($reglasForm !== null) {
        $usosR = array_column($reglas, 'usos', 'id_regla');
        $reglas = array_map(function ($f) use ($usosR) {
            return $f + ['usos' => $usosR[$f['id_regla']] ?? 0];
        }, $reglasForm);
    }
}

$fEstado = get_str('estado') === '' ? '1' : get_str('estado');
$fQ = get_str('q');
$where = [];
$par = [];
if ($fEstado === '1' || $fEstado === '0') {
    $where[] = 'v.activo = ?';
    $par[] = (int) $fEstado;
}
if ($fQ !== '') {
    $where[] = '(v.codigo LIKE ? OR v.nombre LIKE ? OR v.cuit LIKE ?)';
    array_push($par, '%' . $fQ . '%', '%' . $fQ . '%', '%' . cuit_limpio($fQ) . '%');
}
$lista = q('SELECT v.*, z.nombre AS zona, s.nombre AS supervisor, u.nombre AS usuario,
                   (SELECT COUNT(*) FROM clientes c WHERE c.id_vendedor = v.id_vendedor) AS clientes,
                   (SELECT COUNT(*) FROM prestamos p WHERE p.id_vendedor = v.id_vendedor AND p.estado IN ("VIGENTE", "MORA")) AS prestamos,
                   (SELECT COUNT(*) FROM comision_reglas r WHERE r.id_vendedor = v.id_vendedor AND r.activo = 1
                       AND r.vigencia_desde <= CURDATE() AND (r.vigencia_hasta IS NULL OR r.vigencia_hasta >= CURDATE())) AS reglas
              FROM vendedores v
              LEFT JOIN zonas z ON z.id_zona = v.id_zona
              LEFT JOIN vendedores s ON s.id_vendedor = v.id_supervisor
              LEFT JOIN usuarios u ON u.id_usuario = v.id_usuario'
          . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY v.activo DESC, v.nombre', $par);

/** Botón de una acción de la grilla por POST, con confirmación opcional. */
function accion_vendedor(int $id, string $accion, string $icono, string $titulo, string $confirmar = '', string $clase = ''): string
{
    return '<form method="post" action="vendedores.php" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo)
             . '" data-confirmar-boton="' . h($titulo) . '"' . ($clase === 'borrar' ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="' . h($accion) . '">'
         . '<button class="btn-accion ' . h($clase) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

/** Un renglón editable de regla de comisión. */
function renglon_regla(array $r, int $k, array $productos): string
{
    $nueva = (int) ($r['id_regla'] ?? 0) === 0;
    $pct = $r['pct_num'] ?? null;
    $pctTxt = isset($r['porcentaje']) && !isset($r['pct_num']) && $r['porcentaje'] !== ''
        ? num((float) $r['porcentaje'] * 100, 2) : ($pct !== null ? num($pct, 2) : ($r['porcentaje'] ?? ''));
    if (isset($r['pct_num']) && $r['pct_num'] === null) {
        $pctTxt = $r['porcentaje'];
    }
    $tm = $r['telemarketing'] ?? null;
    $tmVal = $tm === null || $tm === '' ? '' : (string) (int) $tm;
    $fd = $r['vd'] ?? ($r['vigencia_desde'] ?? '');
    $fh = $r['vh'] ?? ($r['vigencia_hasta'] ?? '');
    return '<tr' . (!$nueva && !(int) $r['activo'] ? ' class="fila-baja"' : '') . '>'
        . '<td><input type="hidden" name="id_regla[]" value="' . (int) ($r['id_regla'] ?? 0) . '">'
        . '<select name="id_producto[]" class="celda-input">' . opciones($productos, $r['id_producto'] ?? '', 'Todos los productos') . '</select></td>'
        . '<td><select name="telemarketing[]" class="celda-input corto">' . opciones(TM, $tmVal) . '</select></td>'
        . '<td><input name="plazo_desde[]" value="' . h((string) ($r['plazo_desde'] ?? '1')) . '" class="celda-input derecha" style="width:56px" inputmode="numeric">'
        . ' a <input name="plazo_hasta[]" value="' . h((string) ($r['plazo_hasta'] ?? '999')) . '" class="celda-input derecha" style="width:56px" inputmode="numeric"></td>'
        . '<td><input name="porcentaje[]" value="' . h((string) $pctTxt) . '" class="celda-input derecha" style="width:80px" inputmode="decimal" placeholder="%"></td>'
        . '<td><select name="base_calculo[]" class="celda-input">' . opciones(BASES, $r['base_calculo'] ?? 'CAPITAL') . '</select></td>'
        . '<td><input name="prioridad[]" value="' . h((string) ($r['prioridad'] ?? '20')) . '" class="celda-input derecha" style="width:60px" inputmode="numeric"></td>'
        . '<td><input type="date" name="vigencia_desde[]" value="' . h((string) $fd) . '" class="celda-input"></td>'
        . '<td><input type="date" name="vigencia_hasta[]" value="' . h((string) $fh) . '" class="celda-input"></td>'
        . '<td class="td-center">' . ($nueva ? '<span class="td-muted">nueva</span>'
              : '<input type="checkbox" name="activo_regla[' . $k . ']" value="1"' . ((int) $r['activo'] ? ' checked' : '') . '>') . '</td>'
        . '<td class="td-right">' . ($nueva ? '' : ((int) ($r['usos'] ?? 0) ?: '<span class="td-muted">—</span>')) . '</td>'
        . '</tr>';
}

layout_inicio('Vendedores');
titulo('Vendedores', 'Quiénes venden, con qué vínculo y zona, y cuánto cobran de comisión por cada producto.',
       $reg ? '' : boton('Nuevo vendedor', 'primary', 'vendedores.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($reg):
    $r = $reg;
    $esNuevo = !$r['id_vendedor'];
?>
<?php if (!$esNuevo): ?>
<div class="abm-tabs">
  <a class="solapa <?= $solapa === 'datos' ? 'activa' : '' ?>" href="vendedores.php?edit=<?= (int) $r['id_vendedor'] ?>">Datos</a>
  <a class="solapa <?= $solapa === 'comisiones' ? 'activa' : '' ?>" href="vendedores.php?edit=<?= (int) $r['id_vendedor'] ?>&amp;solapa=comisiones">Reglas de comisión <span class="conteo"><?= count(array_filter($reglas, function ($x) { return (int) ($x['id_regla'] ?? 0) > 0; })) ?></span></a>
</div>
<?php endif; ?>

<?php if ($esNuevo || $solapa === 'datos'): ?>
<form method="post" action="vendedores.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $r['id_vendedor'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo vendedor' : $r['nombre'], $esNuevo ? '' : 'Código ' . $r['codigo'], boton('Volver', 'secondary', 'vendedores.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2"><label for="codigo">Código <span class="req">*</span></label>
          <input id="codigo" name="codigo" class="mono" value="<?= h($r['codigo']) ?>" maxlength="20" required>
          <small class="campo-ayuda">El de las planillas.</small></div>
        <div class="campo" style="--span:4"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($r['nombre']) ?>" maxlength="120" required></div>
        <div class="campo" style="--span:3"><label for="cuit">CUIT / CUIL</label>
          <input id="cuit" name="cuit" class="mono" value="<?= h(cuit_fmt((string) $r['cuit'])) ?>" maxlength="13" data-cuit>
          <small class="campo-ayuda"></small></div>
        <div class="campo" style="--span:3"><label for="tipo_vinculo">Vínculo</label>
          <select id="tipo_vinculo" name="tipo_vinculo"><?= opciones(VINCULOS, $r['tipo_vinculo']) ?></select></div>
        <div class="campo" style="--span:3"><label for="id_zona">Zona</label>
          <select id="id_zona" name="id_zona"><?= opciones($zonas, $r['id_zona'] ?? '', 'Sin zona') ?></select></div>
        <div class="campo" style="--span:3"><label for="zona_nueva">o zona nueva</label>
          <input id="zona_nueva" name="zona_nueva" value="<?= h($r['zona_nueva'] ?? '') ?>" maxlength="80" placeholder="se crea al guardar"></div>
        <div class="campo" style="--span:3"><label for="id_supervisor">Supervisor</label>
          <select id="id_supervisor" name="id_supervisor"><?= opciones($supervisores, $r['id_supervisor'] ?? '', 'Sin supervisor') ?></select></div>
        <div class="campo" style="--span:3"><label for="id_usuario">Usuario del sistema</label>
          <select id="id_usuario" name="id_usuario"><?= opciones($usuarios, $r['id_usuario'] ?? '', 'Sin usuario') ?></select>
          <small class="campo-ayuda">Si el vendedor entra al sistema.</small></div>
        <div class="campo" style="--span:3"><label for="fecha_alta">Alta <span class="req">*</span></label>
          <input type="date" id="fecha_alta" name="fecha_alta" value="<?= h((string) $r['fecha_alta']) ?>" required></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $r['activo'] ? 'checked' : '' ?>> Activo</label>
          <?php if (!$esNuevo && !(int) $r['activo'] && !empty($r['fecha_baja'])): ?><small class="campo-ayuda">De baja desde el <?= fecha($r['fecha_baja']) ?>.</small><?php endif; ?></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Dar de alta' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php else: ?>
<form method="post" action="vendedores.php?edit=<?= (int) $r['id_vendedor'] ?>&amp;solapa=comisiones">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="reglas">
  <input type="hidden" name="id" value="<?= (int) $r['id_vendedor'] ?>">
  <div class="card">
    <?= card_head('Reglas de comisión de ' . $r['nombre'], count($reglas) . (count($reglas) === 1 ? ' regla' : ' reglas'), boton('Volver', 'secondary', 'vendedores.php', 'volver', false, true)) ?>
    <div class="bloque">
      <p class="nota-arbol">Al liquidar un préstamo se usa la regla activa y vigente que coincida con el producto, el telemarketing, las cuotas y el monto; si hay varias, la de mayor prioridad. El % queda guardado en el préstamo: cambiar una regla no modifica lo ya liquidado. Para un aumento conviene cerrar la regla (vigencia hasta) y cargar otra desde la fecha nueva. Una regla sin producto vale para todos.</p>
    </div>
    <div class="data-table-wrap">
      <table class="data-table tabla-edicion" data-sin-orden>
        <thead><tr><th data-no-sort>Producto</th><th data-no-sort>Telemarketing</th><th data-no-sort>Cuotas</th><th class="th-right" data-no-sort>% comisión</th>
          <th data-no-sort>Base</th><th class="th-right" data-no-sort>Prioridad</th><th data-no-sort>Rige desde</th><th data-no-sort>Hasta</th>
          <th class="th-center" data-no-sort>Activa</th><th class="th-right" data-no-sort>Préstamos</th></tr></thead>
        <tbody>
          <?php foreach ($reglas as $k => $x): ?><?= renglon_regla($x, $k, $productos) ?><?php endforeach; ?>
          <?= renglon_regla(['id_regla' => 0, 'vigencia_desde' => date('Y-m-d'), 'prioridad' => 20, 'activo' => 1], count($reglas), $productos) ?>
        </tbody>
      </table>
    </div>
    <div class="form-acciones"><?= boton('Guardar reglas', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>

<div class="card">
  <?= card_head('Vendedores', num(count($lista)) . ' en la lista') ?>
  <form method="get" action="vendedores.php" class="filter-bar">
    <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input" placeholder="Código, nombre o CUIT…">
    <select name="estado" class="filtro" onchange="this.form.submit()">
      <?= opciones(['1' => 'Activos', '0' => 'Dados de baja', 'todos' => 'Todos'], $fEstado) ?>
    </select>
    <?= boton('Filtrar', 'secondary', null, 'filtro', true, true) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Código</th><th>Nombre</th><th>CUIT</th><th>Vínculo</th><th>Zona</th>
        <th>Supervisor</th><th>Usuario</th><th class="th-right">Clientes</th><th class="th-right">Préstamos vigentes</th><th class="th-right">Reglas vigentes</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $v): $vid = (int) $v['id_vendedor']; ?>
        <tr class="<?= (int) $v['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'vendedores.php?edit=' . $vid, 'Editar') ?>
            <?= btn_accion('ver', 'vendedores.php?edit=' . $vid . '&solapa=comisiones', 'Reglas de comisión') ?>
            <?= (int) $v['activo']
                  ? accion_vendedor($vid, 'baja', 'cerrar', 'Dar de baja', '«' . $v['nombre'] . '» deja de ofrecerse al cargar clientes y préstamos. Lo que tiene sigue a su nombre.', 'borrar')
                  : accion_vendedor($vid, 'reactivar', 'ok', 'Reactivar') ?>
            <?= !(int) $v['clientes'] && !(int) $v['prestamos'] && !usos_vendedor($vid)
                  ? accion_vendedor($vid, 'borrar', 'borrar', 'Eliminar', 'Se elimina «' . $v['nombre'] . '» con sus reglas de comisión. No tiene clientes, préstamos ni comisiones.', 'borrar') : '' ?>
          </div></td>
          <td class="td-mono td-fuerte"><?= h($v['codigo']) ?></td>
          <td><?= h($v['nombre']) ?></td>
          <td class="td-mono"><?= $v['cuit'] ? h(cuit_fmt($v['cuit'])) : '<span class="td-muted">—</span>' ?></td>
          <td><?= h(VINCULOS[$v['tipo_vinculo']] ?? $v['tipo_vinculo']) ?></td>
          <td><?= h((string) $v['zona']) ?: '<span class="td-muted">—</span>' ?></td>
          <td><?= h((string) $v['supervisor']) ?: '<span class="td-muted">—</span>' ?></td>
          <td><?= h((string) $v['usuario']) ?: '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= num($v['clientes']) ?></td>
          <td class="td-right"><?= num($v['prestamos']) ?></td>
          <td class="td-right"><?= (int) $v['reglas'] ? num($v['reglas']) : estado('sin reglas', 'warn') ?></td>
          <td><?= (int) $v['activo'] ? estado('Activo', 'ok') : estado('Baja', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="12" class="no-data">No hay vendedores con esos filtros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
layout_fin();
