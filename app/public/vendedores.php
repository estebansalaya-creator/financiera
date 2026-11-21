<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/comisiones_escalas.php';

// ---------------------------------------------------------------------
// Vendedores: alta, edición, baja y sus excepciones de comisión.
//   - El código es el que figura en las planillas de comisiones (ALONSO,
//     AMTAE…): único y en mayúsculas.
//   - Un vendedor que tiene clientes, préstamos o comisiones no se
//     elimina: se da de baja (deja de ofrecerse al cargar un cliente o un
//     préstamo, y lo que tiene sigue a su nombre).
//   - La comisión sale de la escala del tipo de asociado, la misma para
//     todos (tipos_asociado.php). La solapa de comisiones tiene las
//     excepciones del vendedor (comision_reglas con su id_vendedor): si una
//     cubre el tipo y el monto del préstamo, gana sobre la general. La
//     comisión se congela en cada préstamo al liquidarlo: cambiar una
//     escala no toca lo ya liquidado (inc/comisiones_escalas.php).
// ---------------------------------------------------------------------

const VINCULOS = ['COMISIONISTA' => 'Comisionista', 'EMPLEADO' => 'Empleado'];

$errores = [];
$reg = null;

// El celular del vendedor llega con sql\financiera_telefonos.sql: hasta que se
// corra, el campo no se muestra y todo lo demás sigue andando.
$conTel = false;
try {
    $conTel = (bool) q("SHOW COLUMNS FROM vendedores LIKE 'telefono'");
} catch (Throwable $e) {
}
// CBU y alias, con sql\financiera_datos_bancarios.sql (mismo criterio que el celular).
$conBanco = columna_existe('vendedores', 'cbu');
$id = (int) (post_str('id') ?: get_str('edit'));          // las acciones de las excepciones vienen en la URL
$solapa = get_str('solapa') === 'comisiones' ? 'comisiones' : 'datos';
$fExc = in_array(get_str('exc'), ['0', 'todas'], true) ? get_str('exc') : '1';   // las excepciones: activas, de baja o todas
$escalaForm = null;

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
            flash('ok', '«' . $actual['nombre'] . '» eliminado, con sus excepciones de comisión.');
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
                'telefono' => post_str('telefono'),
                'cbu' => cbu_limpio(post_str('cbu')),
                'alias_cbu' => alias_cbu_limpio(post_str('alias_cbu')),
                'activo' => post_str('activo') === '1' ? 1 : 0];
        if ($conBanco) {
            $errores = array_merge($errores, datos_bancarios_errores($reg['cbu'], $reg['alias_cbu']));
        }
        $tel = null;
        if ($conTel && $reg['telefono'] !== '') {
            $tel = tel_normalizar($reg['telefono'], true);
            if ($tel === null) {
                $errores[] = tel_error('Celular');
            }
        }
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
                if ($conTel) {
                    q('UPDATE vendedores SET telefono = ? WHERE id_vendedor = ?', [$tel, $id]);
                }
                if ($conBanco) {
                    q('UPDATE vendedores SET cbu = ?, alias_cbu = ? WHERE id_vendedor = ?', [$reg['cbu'] ?: null, $reg['alias_cbu'] ?: null, $id]);
                }
                $pdo->commit();
                flash('ok', '«' . $reg['nombre'] . '» ' . ($reg['id_vendedor'] ? 'guardado.'
                      : 'dado de alta. Cobra por las escalas de cada tipo de asociado; si tiene un % distinto, cargá la excepción en «Excepciones de comisión».'));
                redirigir('vendedores.php?edit=' . $id);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    }

    if (strncmp($accion, 'escala_', 7) === 0 && $actual) {
        $solapa = 'comisiones';
        try {
            $errores = escala_acciones($accion, null, $id, 'vendedores.php?edit=' . $id . '&solapa=comisiones', $escalaForm);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
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
            'fecha_alta' => date('Y-m-d'), 'telefono' => '', 'cbu' => '', 'alias_cbu' => '', 'activo' => 1];
}

$zonas = array_column(q('SELECT id_zona, nombre FROM zonas WHERE activo = 1 ORDER BY nombre'), 'nombre', 'id_zona');
$supervisores = array_column(q('SELECT id_vendedor, CONCAT(nombre, " (", codigo, ")") AS n FROM vendedores
                                 WHERE activo = 1 AND id_vendedor <> ? ORDER BY nombre', [(int) $id]), 'n', 'id_vendedor');
$usuarios = array_column(q('SELECT id_usuario, CONCAT(nombre, " · ", email) AS n FROM usuarios WHERE activo = 1 ORDER BY nombre'), 'n', 'id_usuario');
$tiposAsoc = array_column(q('SELECT id_tipo_asociado, nombre FROM tipos_asociado WHERE activo = 1 ORDER BY nombre'), 'nombre', 'id_tipo_asociado');

$reglas = [];
$nActivas = 0;
$cobra = [];          // lo que cobra hoy, por tipo de asociado
if ($reg && $reg['id_vendedor']) {
    $vid = (int) $reg['id_vendedor'];
    $todas = escalas_de(null, $vid);
    $nActivas = count(array_filter($todas, function ($x) { return (int) $x['activo']; }));
    $reglas = array_values(array_filter($todas, function ($x) use ($fExc) { return $fExc === 'todas' || (int) $x['activo'] === (int) $fExc; }));
    // Un tipo dado de baja que figura en una excepción también se puede elegir.
    foreach ($todas as $x) {
        if ($x['id_tipo_asociado'] && !isset($tiposAsoc[$x['id_tipo_asociado']])) {
            $tiposAsoc[$x['id_tipo_asociado']] = $x['tipo_nombre'] . ' (inactivo)';
        }
    }
    $cobra = q("SELECT t.id_tipo_asociado, t.nombre,
                       g.n AS g_n, g.porcentaje AS g_pct, g.porcentaje_tm AS g_tm, g.md AS g_md, g.mh AS g_mh,
                       x.n AS x_n, x.porcentaje AS x_pct, x.porcentaje_tm AS x_tm, x.md AS x_md, x.mh AS x_mh
                  FROM tipos_asociado t
                  LEFT JOIN (SELECT id_tipo_asociado, COUNT(*) AS n, MIN(porcentaje) AS porcentaje, MIN(porcentaje_tm) AS porcentaje_tm,
                                    MIN(monto_desde) AS md, MAX(monto_hasta) AS mh
                               FROM comision_reglas WHERE id_vendedor IS NULL AND activo = 1
                                AND vigencia_desde <= CURDATE() AND (vigencia_hasta IS NULL OR vigencia_hasta >= CURDATE())
                              GROUP BY id_tipo_asociado) g ON g.id_tipo_asociado = t.id_tipo_asociado
                  LEFT JOIN (SELECT id_tipo_asociado, COUNT(*) AS n, MIN(porcentaje) AS porcentaje, MIN(porcentaje_tm) AS porcentaje_tm,
                                    MIN(monto_desde) AS md, MAX(monto_hasta) AS mh
                               FROM comision_reglas WHERE id_vendedor = ? AND activo = 1
                                AND vigencia_desde <= CURDATE() AND (vigencia_hasta IS NULL OR vigencia_hasta >= CURDATE())
                              GROUP BY id_tipo_asociado) x ON x.id_tipo_asociado = t.id_tipo_asociado
                 WHERE t.activo = 1 ORDER BY t.nombre", [$vid]);
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
    $where[] = '(v.codigo LIKE ? OR v.nombre LIKE ? OR v.cuit LIKE ?' . ($conBanco ? ' OR v.cbu = ? OR v.alias_cbu LIKE ?' : '') . ')';
    array_push($par, '%' . $fQ . '%', '%' . $fQ . '%', '%' . cuit_limpio($fQ) . '%');
    if ($conBanco) {
        array_push($par, cbu_limpio($fQ), '%' . $fQ . '%');
    }
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

layout_inicio('Vendedores');
titulo('Vendedores', 'Quiénes venden, con qué vínculo y zona, y sus excepciones a las escalas de comisión.',
       $reg ? '' : boton('Nuevo vendedor', 'primary', 'vendedores.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($reg):
    $r = $reg;
    $esNuevo = !$r['id_vendedor'];
?>
<?php if (!$esNuevo): ?>
<div class="abm-tabs">
  <a class="solapa <?= $solapa === 'datos' ? 'activa' : '' ?>" href="vendedores.php?edit=<?= (int) $r['id_vendedor'] ?>">Datos</a>
  <a class="solapa <?= $solapa === 'comisiones' ? 'activa' : '' ?>" href="vendedores.php?edit=<?= (int) $r['id_vendedor'] ?>&amp;solapa=comisiones">Excepciones de comisión <span class="conteo"><?= $nActivas ?></span></a>
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
        <?php if ($conTel): ?>
        <div class="campo" style="--span:3"><label for="telefono">Celular</label>
          <input id="telefono" name="telefono" type="tel" class="mono" value="<?= h(tel_fmt((string) ($r['telefono'] ?? ''))) ?>" maxlength="25" data-tel="movil" placeholder="11 2345-6789">
          <small class="campo-ayuda">Con la característica, sin 0 ni 15.</small></div>
        <?php endif; ?>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $r['activo'] ? 'checked' : '' ?>> Activo</label>
          <?php if (!$esNuevo && !(int) $r['activo'] && !empty($r['fecha_baja'])): ?><small class="campo-ayuda">De baja desde el <?= fecha($r['fecha_baja']) ?>.</small><?php endif; ?></div>
      </div>
    </div>
    <?php if ($conBanco): ?>
    <div class="bloque">
      <div class="bloque-titulo">Datos bancarios</div>
      <div class="campos">
        <?= campos_datos_bancarios($r['cbu'] ?? '', $r['alias_cbu'] ?? '', 'para pagarle las comisiones') ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="form-acciones"><?= boton($esNuevo ? 'Dar de alta' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php else: ?>
<?php
    $baseExc = 'vendedores.php?edit=' . (int) $r['id_vendedor'] . '&solapa=comisiones';
    $editar = get_str('escala');
    if ($escalaForm !== null || $editar !== '') {
        $fila = $editar !== '' && $editar !== 'nueva'
            ? q1('SELECT * FROM comision_reglas WHERE id_regla = ? AND id_vendedor = ?', [(int) $editar, (int) $r['id_vendedor']]) : null;
        $vals = $escalaForm ?? escala_valores($fila, (int) get_str('tipo') ?: null);
        echo escala_form($vals, $baseExc . ($editar !== '' ? '&escala=' . rawurlencode($editar) : ''), $baseExc, $tiposAsoc,
                         (int) $vals['id_regla'] ? 'Editar la excepción' : 'Nueva excepción de comisión');
    }
?>
<div class="card">
  <?= card_head('Excepciones de comisión de ' . $r['nombre'], 'cuando cobra distinto que la escala del tipo de asociado',
                boton('Nueva excepción', 'primary', $baseExc . '&escala=nueva', 'agregar', false, true)) ?>
  <div class="bloque">
    <p class="nota-arbol">La comisión sale de la escala de cada tipo de asociado (<?= puede('tipos_asociado.php') ? '<a href="tipos_asociado.php">Tipos de asociado y tasas</a>' : 'Tipos de asociado y tasas' ?>), la misma para todos los vendedores. Acá se cargan solo los casos en que este vendedor cobra distinto: si una excepción activa y vigente cubre el tipo y el monto del préstamo, se usa la suya; si no, la general. Con telemarketing se usa el % de telemarketing (vacío: el mismo). El % queda guardado en el préstamo: cambiar una excepción no modifica lo ya liquidado.</p>
  </div>
  <?= escalas_grilla($reglas, $baseExc . ($fExc !== '1' ? '&exc=' . $fExc : ''), true, 'excepción|excepciones',
                     $fExc === '1' ? 'Cobra todo por las escalas generales. Si cobra distinto en algún tipo, cargá la excepción con «Nueva excepción».' : 'Ninguna excepción con ese estado.',
                     filtro_select('exc', 'Estado', ['1' => 'Activas', '0' => 'De baja', 'todas' => 'Todas'], $fExc, '', $fExc !== '1'), $fExc !== '1') ?>
</div>

<div class="card">
  <?= card_head('Lo que cobra hoy', 'por tipo de asociado: la escala general y la suya') ?>
  <?= barra_local('vendedores.php?edit=' . (int) $r['id_vendedor'] . '&solapa=comisiones', 'Tipo de asociado…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="tipo|tipos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Tipo de asociado</th><th class="th-right">Escala general</th><th class="th-right">Este vendedor</th></tr></thead>
      <tbody>
      <?php foreach ($cobra as $c):
          // Una sola escala: su % y, si no cubre todos los montos, el tramo.
          $txt = function ($n, $p, $tm, $md, $mh) {
              if (!$n) {
                  return null;
              }
              if ((int) $n > 1) {
                  return [num($n) . ' escalas por monto', ''];
              }
              $tramo = escala_tramo_txt($md, $mh);
              return [escala_pct_campo($p) . ' % · TM ' . escala_pct_campo($tm ?? $p) . ' %', $tramo === 'Cualquier monto' ? '' : $tramo];
          };
          $gen = $txt($c['g_n'], $c['g_pct'], $c['g_tm'], $c['g_md'], $c['g_mh']);
          $exc = $txt($c['x_n'], $c['x_pct'], $c['x_tm'], $c['x_md'], $c['x_mh']); ?>
        <tr>
          <td><div class="acciones"><?= puede('tipos_asociado.php') ? btn_accion('ver', 'tipos_asociado.php?id=' . (int) $c['id_tipo_asociado'] . '&solapa=escalas', 'Escalas del tipo de asociado') : '' ?>
            <?= btn_accion('agregar', 'vendedores.php?edit=' . (int) $r['id_vendedor'] . '&solapa=comisiones&escala=nueva&tipo=' . (int) $c['id_tipo_asociado'], 'Nueva excepción en este tipo') ?></div></td>
          <td class="td-fuerte"><?= h($c['nombre']) ?></td>
          <td class="td-right"><?= $gen !== null ? h($gen[0]) . ($gen[1] !== '' ? '<span class="segunda-linea">' . h($gen[1]) . '</span>' : '') : estado('Sin escala', 'warn') ?></td>
          <td class="td-right"><?= $exc !== null ? '<strong>' . h($exc[0]) . '</strong><span class="segunda-linea">excepción' . ($exc[1] !== '' ? ' · ' . h(mb_strtolower($exc[1])) . '; el resto, la general' : '') . '</span>'
                                                 : '<span class="td-muted">la general</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cobra): ?><tr><td colspan="4" class="no-data">No hay tipos de asociado activos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
  <?= card_head('Vendedores') ?>
  <form method="get" action="vendedores.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Código, nombre, CUIT, CBU o alias…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '1' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <?= opciones(['1' => 'Activos', '0' => 'Dados de baja', 'todos' => 'Todos'], $fEstado) ?>
      </select></label>
    <?= filtro_botones('vendedores.php', $fQ !== '' || $fEstado !== '1') ?>
    <?= filtro_fin_local(null) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="vendedor|vendedores">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Código</th><th>Nombre</th><th>CUIT</th><th>Celular</th><th>Vínculo</th><th>Zona</th>
        <th>Supervisor</th><th>Usuario</th><th class="th-right">Clientes</th><th class="th-right">Préstamos vigentes</th><th class="th-right">Excepciones</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $v): $vid = (int) $v['id_vendedor']; ?>
        <tr class="<?= (int) $v['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'vendedores.php?edit=' . $vid, 'Editar') ?>
            <?= btn_accion('ver', 'vendedores.php?edit=' . $vid . '&solapa=comisiones', 'Excepciones de comisión') ?>
            <?= (int) $v['activo']
                  ? accion_vendedor($vid, 'baja', 'cerrar', 'Dar de baja', '«' . $v['nombre'] . '» deja de ofrecerse al cargar clientes y préstamos. Lo que tiene sigue a su nombre.', 'borrar')
                  : accion_vendedor($vid, 'reactivar', 'ok', 'Reactivar') ?>
            <?= !(int) $v['clientes'] && !(int) $v['prestamos'] && !usos_vendedor($vid)
                  ? accion_vendedor($vid, 'borrar', 'borrar', 'Eliminar', 'Se elimina «' . $v['nombre'] . '» con sus excepciones de comisión. No tiene clientes, préstamos ni comisiones.', 'borrar') : '' ?>
          </div></td>
          <td class="td-mono td-fuerte"><?= h($v['codigo']) ?></td>
          <td><?= h($v['nombre']) ?></td>
          <td class="td-mono"><?= $v['cuit'] ? h(cuit_fmt($v['cuit'])) : '<span class="td-muted">—</span>' ?>
            <?php if (!empty($v['alias_cbu']) || !empty($v['cbu'])): ?><span class="segunda-linea"><?= !empty($v['alias_cbu']) ? 'alias ' . h($v['alias_cbu']) : 'CBU ' . h(cbu_fmt($v['cbu'])) ?></span><?php endif; ?></td>
          <td><?= tel_enlace($v['telefono'] ?? null, (string) $v['nombre']) ?: '<span class="td-muted">—</span>' ?></td>
          <td><?= h(VINCULOS[$v['tipo_vinculo']] ?? $v['tipo_vinculo']) ?></td>
          <td><?= h((string) $v['zona']) ?: '<span class="td-muted">—</span>' ?></td>
          <td><?= h((string) $v['supervisor']) ?: '<span class="td-muted">—</span>' ?></td>
          <td><?= h((string) $v['usuario']) ?: '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= num($v['clientes']) ?></td>
          <td class="td-right"><?= num($v['prestamos']) ?></td>
          <td class="td-right"><?= (int) $v['reglas'] ? num($v['reglas']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $v['activo'] ? estado('Activo', 'ok') : estado('Baja', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="13" class="no-data">No hay vendedores con esos filtros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
layout_fin();
