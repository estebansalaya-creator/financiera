<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Tablas y parámetros › Grillas de valores (sql\financiera_grillas.sql).
// Una grilla dice la cuota (IVA incluido) según el monto prestado y la
// cantidad de cuotas. Si el tipo de asociado tiene grilla y el monto y el
// plazo están en ella (o entre dos montos, interpolando), el préstamo se
// liquida con cuota fija igual a la de la grilla; si no, con la tasa.
//   grillas.php               la lista
//   grillas.php?id=N          una grilla: la tabla, sus tipos y la carga
//   POST accion=nueva | celda_guardar | celda_borrar | tipos | activar | desactivar
// ---------------------------------------------------------------------

$errores = [];
$id = (int) get_str('id');
$instalada = true;
try {
    q('SELECT 1 FROM grillas LIMIT 1');
} catch (PDOException $e) {
    $instalada = false;
}

if ($instalada && es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $volver = 'grillas.php?id=' . $id;
    try {
        if ($accion === 'nueva') {
            $nom = mb_strtoupper(trim(post_str('nombre')));
            if ($nom === '') {
                $errores[] = 'Falta el nombre de la grilla.';
            } elseif (q1('SELECT 1 AS x FROM grillas WHERE nombre = ?', [$nom])) {
                $errores[] = 'Ya hay una grilla ' . $nom . '.';
            } else {
                q('INSERT INTO grillas (nombre, descripcion) VALUES (?, ?)', [$nom, trim(post_str('descripcion')) ?: null]);
                flash('ok', 'Grilla ' . $nom . ' creada: cargale los valores.');
                redirigir('grillas.php?id=' . (int) db()->lastInsertId());
            }
        } elseif ($id && $accion === 'celda_guardar') {
            $monto = leer_num(post_str('monto'));
            $plazo = leer_num(post_str('plazo'));
            $valor = leer_num(post_str('valor_cuota'));
            if ($monto === null || $monto <= 0 || $plazo === null || $plazo < 1 || $plazo > 360 || floor($plazo) != $plazo || $valor === null || $valor <= 0) {
                $errores[] = 'Cargá el monto, la cantidad de cuotas (entera) y el valor de la cuota.';
            } elseif ($valor * $plazo < $monto) {
                $errores[] = 'Con esa cuota no se devuelve ni el capital (' . (int) $plazo . ' × ' . money($valor) . ' < ' . money($monto) . ').';
            } else {
                q('INSERT INTO grillas_celdas (id_grilla, monto, plazo, valor_cuota, modificado_por) VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE valor_cuota = VALUES(valor_cuota), modificado_por = VALUES(modificado_por)',
                  [$id, round($monto, 2), (int) $plazo, round($valor, 2), usuario_id()]);
                flash('ok', money($monto) . ' en ' . (int) $plazo . ' cuotas: ' . money($valor) . '.');
                redirigir($volver);
            }
        } elseif ($id && $accion === 'celda_borrar') {
            q('DELETE FROM grillas_celdas WHERE id_grilla = ? AND monto = ? AND plazo = ?', [$id, post_str('monto'), (int) post_str('plazo')]);
            flash('ok', 'Valor eliminado.');
            redirigir($volver);
        } elseif ($id && $accion === 'tipos') {
            $sel = array_map('intval', (array) ($_POST['tipos'] ?? []));
            q('UPDATE tipos_asociado SET id_grilla = NULL WHERE id_grilla = ?', [$id]);
            if ($sel) {
                q('UPDATE tipos_asociado SET id_grilla = ? WHERE id_tipo_asociado IN (' . implode(',', array_fill(0, count($sel), '?')) . ')',
                  array_merge([$id], $sel));
            }
            flash('ok', count($sel) . ' tipo' . (count($sel) === 1 ? '' : 's') . ' de asociado con esta grilla.');
            redirigir($volver);
        } elseif ($id && in_array($accion, ['activar', 'desactivar'], true)) {
            q('UPDATE grillas SET activo = ? WHERE id_grilla = ?', [$accion === 'activar' ? 1 : 0, $id]);
            flash('ok', $accion === 'activar' ? 'Grilla activa.' : 'Grilla desactivada: sus tipos vuelven a liquidar con la tasa.');
            redirigir($volver);
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$grillas = $instalada ? q('SELECT g.*, (SELECT COUNT(*) FROM grillas_celdas c WHERE c.id_grilla = g.id_grilla) AS celdas,
                                  (SELECT GROUP_CONCAT(t.nombre ORDER BY t.nombre SEPARATOR \', \') FROM tipos_asociado t WHERE t.id_grilla = g.id_grilla) AS tipos
                             FROM grillas g ORDER BY g.activo DESC, g.nombre') : [];
$g = null;
foreach ($grillas as $x) {
    if ((int) $x['id_grilla'] === $id) {
        $g = $x;
    }
}

layout_inicio('Grillas de valores');
titulo($g ? 'Grilla ' . $g['nombre'] : 'Grillas de valores', 'La cuota según el monto prestado y la cantidad de cuotas',
       '',
       'Tablas y parámetros / ' . ($g ? '<a href="grillas.php">Grillas de valores</a> / ' . h($g['nombre']) : 'Grillas de valores'));
mensajes($errores);

if (!$instalada): ?>
<div class="alert alert-warning"><div>Falta correr sql\financiera_grillas.sql.</div></div>
<?php layout_fin(); return; endif;

if (!$g): ?>
<div class="card">
  <?= card_head('Grillas', num(count($grillas)) . ' grillas') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Grilla</th><th class="th-right">Valores</th><th>Tipos de asociado</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$grillas): ?><tr><td colspan="5" class="no-data">No hay grillas.</td></tr><?php endif; ?>
      <?php foreach ($grillas as $x): ?>
        <tr class="<?= (int) $x['activo'] ? '' : 'fila-baja' ?>"><td><div class="acciones"><?= btn_accion('ver', 'grillas.php?id=' . (int) $x['id_grilla'], 'Ver la grilla') ?></div></td>
          <td class="td-fuerte"><a href="grillas.php?id=<?= (int) $x['id_grilla'] ?>"><?= h($x['nombre']) ?></a><?= $x['descripcion'] ? '<span class="segunda-linea">' . h($x['descripcion']) . '</span>' : '' ?></td>
          <td class="td-right"><?= num((int) $x['celdas']) ?></td>
          <td class="td-wrap"><?= $x['tipos'] ? h($x['tipos']) : '<span class="td-muted">ninguno</span>' ?></td>
          <td><?= (int) $x['activo'] ? estado('Activa', 'ok') : estado('Inactiva', 'neutro') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="grillas.php" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nueva">
    <div class="bloque-titulo">Nueva grilla</div>
    <div class="campos">
      <div class="campo" style="--span:3"><label for="nombre">Nombre</label><input id="nombre" name="nombre" maxlength="60" required></div>
      <div class="campo" style="--span:6"><label for="descripcion">Descripción</label><input id="descripcion" name="descripcion" maxlength="200"></div>
      <div class="campo campo-boton" style="--span:2"><?= boton('Crear', 'primary', null, 'agregar', true) ?></div>
    </div>
  </form>
  <p class="nota-arbol bloque">Si un tipo de asociado tiene grilla y el monto y la cantidad de cuotas están en ella (o el monto queda entre dos montos de la grilla, que se interpola), el préstamo se liquida con cuota fija igual a la de la grilla, IVA incluido, y la TNA sale de esa cuota. Si no está, se usa la tasa de la solapa Tasas del tipo.</p>
</div>
<?php layout_fin(); return; endif;

// Una grilla
$celdas = q('SELECT monto, plazo, valor_cuota FROM grillas_celdas WHERE id_grilla = ? ORDER BY monto, plazo', [$id]);
$plazos = [];
$matriz = [];
foreach ($celdas as $c) {
    $plazos[(int) $c['plazo']] = true;
    $matriz[$c['monto']][(int) $c['plazo']] = $c['valor_cuota'];
}
ksort($plazos);
$plazos = array_keys($plazos);
$tipos = q('SELECT id_tipo_asociado, nombre, id_grilla, activo FROM tipos_asociado ORDER BY activo DESC, nombre');
$otras = array_column($grillas, 'nombre', 'id_grilla');
?>
<div class="card">
  <?= card_head('Valores', num(count($celdas)) . ' valores · ' . num(count($matriz)) . ' montos · ' . num(count($plazos)) . ' plazos' . ((int) $g['activo'] ? '' : ' · INACTIVA'),
                '<form method="post" action="grillas.php?id=' . $id . '" class="form-en-linea"'
                . ((int) $g['activo'] ? ' data-confirmar="Sus tipos de asociado vuelven a liquidar con la tasa." data-confirmar-titulo="Desactivar la grilla" data-confirmar-boton="Desactivar"' : '') . '>'
                . csrf_campo() . '<input type="hidden" name="accion" value="' . ((int) $g['activo'] ? 'desactivar' : 'activar') . '">'
                . boton((int) $g['activo'] ? 'Desactivar grilla' : 'Activar grilla', (int) $g['activo'] ? 'secondary' : 'ok', null, (int) $g['activo'] ? null : 'ok', true, true)
                . '</form>') ?>
  <form method="post" action="grillas.php?id=<?= $id ?>" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="celda_guardar">
    <div class="campos">
      <div class="campo" style="--span:3"><label for="monto">Monto</label><input id="monto" name="monto" inputmode="decimal" class="derecha" required></div>
      <div class="campo" style="--span:2"><label for="plazo">Cuotas</label><input id="plazo" name="plazo" inputmode="numeric" class="derecha" required></div>
      <div class="campo" style="--span:3"><label for="valor_cuota">Valor de la cuota</label><input id="valor_cuota" name="valor_cuota" inputmode="decimal" class="derecha" required></div>
      <div class="campo campo-boton" style="--span:2"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
    </div>
    <span class="campo-ayuda">Un monto y cantidad de cuotas que ya está se reemplaza. Para borrar un valor, tocalo en la tabla.</span>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="monto|montos">
      <thead><tr><th class="th-right">Monto</th><?php foreach ($plazos as $p): ?><th class="th-right"><?= $p ?> c.</th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$matriz): ?><tr><td colspan="<?= count($plazos) + 1 ?>" class="no-data">Sin valores.</td></tr><?php endif; ?>
      <?php foreach ($matriz as $monto => $fila): ?>
        <tr><td class="td-right td-fuerte" data-orden="<?= h((string) $monto) ?>"><?= money($monto) ?></td>
        <?php foreach ($plazos as $p): ?>
          <td class="td-right"><?php if (isset($fila[$p])): ?>
            <form method="post" action="grillas.php?id=<?= $id ?>" class="form-en-linea" data-confirmar="Se borra el valor de <?= h(money($monto)) ?> en <?= $p ?> cuotas." data-confirmar-titulo="Borrar el valor" data-confirmar-boton="Borrar" data-peligro>
              <?= csrf_campo() ?><input type="hidden" name="accion" value="celda_borrar"><input type="hidden" name="monto" value="<?= h((string) $monto) ?>"><input type="hidden" name="plazo" value="<?= $p ?>">
              <button style="background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer" title="Borrar este valor"><?= money($fila[$p]) ?></button></form>
          <?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<div class="card">
  <?= card_head('Tipos de asociado con esta grilla', 'un tipo tiene una sola grilla: tildarlo acá lo saca de la que tenga') ?>
  <form method="post" action="grillas.php?id=<?= $id ?>" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="tipos">
    <div class="campos" style="--cols:3">
    <?php foreach ($tipos as $t): $mia = (int) $t['id_grilla'] === $id; ?>
      <label class="campo-check" style="--span:4"><input type="checkbox" name="tipos[]" value="<?= (int) $t['id_tipo_asociado'] ?>" <?= $mia ? 'checked' : '' ?>>
        <?= h($t['nombre']) ?><?= !(int) $t['activo'] ? ' <span class="td-muted">(inactivo)</span>' : '' ?>
        <?= $t['id_grilla'] && !$mia ? ' <span class="td-muted">· ' . h($otras[$t['id_grilla']] ?? '') . '</span>' : '' ?></label>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:8px"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </form>
</div>
<?php layout_fin(); ?>
