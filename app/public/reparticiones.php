<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Reparticiones: dónde cobra el asociado (ANSES MINIMA 7, IPS 123,
// MINISTERIO DE EDUCACION…), las de las planillas de comisiones.
//   reparticiones.php             listado (filtros: organismo, estado)
//   reparticiones.php?nuevo=1     alta (con ?organismo=N viene elegido)
//   reparticiones.php?edit=N      edición
//   - Se asigna en la ficha del cliente y no depende del tipo de asociado:
//     la misma repartición aparece con varios tipos.
//   - Cada una es de un organismo (organismos.php), que las agrupa.
//   - Una repartición con clientes o en las planillas de comisiones no se
//     elimina: se deshabilita (deja de ofrecerse; los clientes que la
//     tienen la conservan).
//   Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (es_post() ? post_str('id') : get_str('edit'));

/** Dónde figura una repartición (lo que impide eliminarla). */
function usos_reparticion(int $id): array
{
    $u = q1('SELECT (SELECT COUNT(*) FROM clientes WHERE id_reparticion = ?) AS clientes,
                    (SELECT COUNT(*) FROM comisiones_importadas WHERE id_reparticion = ?) AS importadas', [$id, $id]);
    $out = [];
    if ((int) $u['clientes']) {
        $out[] = num($u['clientes']) . ((int) $u['clientes'] === 1 ? ' cliente' : ' clientes');
    }
    if ((int) $u['importadas']) {
        $out[] = num($u['importadas']) . ' renglones de planillas de comisiones';
    }
    return $out;
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $actual = $id ? q1('SELECT * FROM reparticiones WHERE id_reparticion = ?', [$id]) : null;
    if ($id && !$actual) {
        flash('danger', 'La repartición ya no existe.');
        redirigir('reparticiones.php');
    }
    $volver = 'reparticiones.php' . (preg_match('/^\?[\w=&%.-]*$/', post_str('volver')) ? post_str('volver') : '');

    if (in_array($accion, ['habilitar', 'deshabilitar'], true) && $actual) {
        q('UPDATE reparticiones SET activo = ? WHERE id_reparticion = ?', [$accion === 'habilitar' ? 1 : 0, $id]);
        flash('ok', '«' . $actual['nombre'] . '» ' . ($accion === 'habilitar' ? 'habilitada.' : 'deshabilitada: deja de ofrecerse en la ficha del cliente; los clientes que la tienen la conservan.'));
        redirigir($volver);
    }
    if ($accion === 'borrar' && $actual) {
        $usos = usos_reparticion($id);
        if ($usos) {
            flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: la tienen ' . implode(' y ', $usos) . '. Deshabilitala.');
        } else {
            q('DELETE FROM reparticiones WHERE id_reparticion = ?', [$id]);
            flash('ok', '«' . $actual['nombre'] . '» eliminada.');
        }
        redirigir($volver);
    }
    if ($accion === 'guardar') {
        $reg = ['id_reparticion' => $id ?: null,
                'nombre' => mb_strtoupper(trim(preg_replace('/\s+/u', ' ', post_str('nombre'))), 'UTF-8'),
                'id_organismo' => (int) post_str('id_organismo') ?: null,
                'activo' => post_str('activo') === '1' ? 1 : 0];
        if ($reg['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        } elseif (mb_strlen($reg['nombre']) > 80) {
            $errores[] = 'El nombre va hasta 80 caracteres.';
        } elseif (q1('SELECT 1 AS x FROM reparticiones WHERE nombre = ? AND id_reparticion <> ?', [$reg['nombre'], $id])) {
            $errores[] = 'Ya hay una repartición «' . $reg['nombre'] . '».';
        }
        if ($reg['id_organismo'] && !q1('SELECT 1 AS x FROM organismos WHERE id_organismo = ?', [$reg['id_organismo']])) {
            $errores[] = 'El organismo elegido ya no existe.';
        }
        if (!$errores) {
            try {
                if ($id) {
                    q('UPDATE reparticiones SET nombre = ?, id_organismo = ?, activo = ? WHERE id_reparticion = ?',
                      [$reg['nombre'], $reg['id_organismo'], $reg['activo'], $id]);
                } else {
                    q('INSERT INTO reparticiones (nombre, id_organismo, activo) VALUES (?, ?, ?)', [$reg['nombre'], $reg['id_organismo'], $reg['activo']]);
                }
                flash('ok', '«' . $reg['nombre'] . '» ' . ($id ? 'guardada.' : 'dada de alta.'));
                redirigir('reparticiones.php');
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e, ['uq_reparticiones_nombre' => 'Ya hay una repartición «' . $reg['nombre'] . '».']);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$fOrg    = (int) get_str('organismo');
$fEstado = in_array(get_str('estado'), ['0', 'todas'], true) ? get_str('estado') : '1';

if ($id && $reg === null) {
    $reg = q1('SELECT * FROM reparticiones WHERE id_reparticion = ?', [$id]);
    if (!$reg) {
        flash('danger', 'La repartición no existe.');
        redirigir('reparticiones.php');
    }
} elseif ($reg === null && get_str('nuevo') !== '') {
    $reg = ['id_reparticion' => null, 'nombre' => '', 'id_organismo' => $fOrg ?: null, 'activo' => 1];
}

$organismos = q('SELECT id_organismo, nombre, activo FROM organismos ORDER BY nombre');
$orgNombre = array_column($organismos, 'nombre', 'id_organismo');

$where = [];
$par = [];
if ($fOrg) {
    $where[] = 'r.id_organismo = ?';
    $par[] = $fOrg;
}
if ($fEstado !== 'todas') {
    $where[] = 'r.activo = ?';
    $par[] = (int) $fEstado;
}
$lista = q('SELECT r.*, o.nombre AS organismo, o.activo AS organismo_activo,
                   (SELECT COUNT(*) FROM clientes c WHERE c.id_reparticion = r.id_reparticion) AS clientes,
                   (SELECT COUNT(*) FROM comisiones_importadas ci WHERE ci.id_reparticion = r.id_reparticion) AS importadas
              FROM reparticiones r
              LEFT JOIN organismos o ON o.id_organismo = r.id_organismo'
          . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
          . ' ORDER BY r.activo DESC, o.nombre IS NULL, o.nombre, r.nombre', $par);
$hayFiltros = $fOrg > 0 || $fEstado !== '1';
$volverQs = http_build_query(array_filter(['organismo' => $fOrg ?: null, 'estado' => $fEstado !== '1' ? $fEstado : null]));
$volverQs = $volverQs !== '' ? '?' . $volverQs : '';     // al volver de una acción, con los mismos filtros

/** Botón de una acción de la grilla por POST, con confirmación opcional. */
function accion_reparticion(int $id, string $accion, string $icono, string $titulo, string $volver, string $confirmar = '', string $clase = ''): string
{
    return '<form method="post" action="reparticiones.php" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo)
             . '" data-confirmar-boton="' . h($titulo) . '"' . ($clase === 'borrar' ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="' . h($accion) . '">'
         . '<input type="hidden" name="volver" value="' . h($volver) . '">'
         . '<button class="btn-accion ' . h($clase) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

layout_inicio('Reparticiones');
titulo('Reparticiones', 'Dónde cobra cada asociado: se elige en la ficha del cliente',
       $reg ? '' : boton('Nueva repartición', 'primary', 'reparticiones.php?nuevo=1' . ($fOrg ? '&organismo=' . $fOrg : ''), 'agregar'));
mensajes($errores);

if ($reg):
    $esNueva = !$reg['id_reparticion'];
    $opOrg = [];
    foreach ($organismos as $o) {
        if ((int) $o['activo'] || (int) $o['id_organismo'] === (int) $reg['id_organismo']) {
            $opOrg[$o['id_organismo']] = $o['nombre'] . ((int) $o['activo'] ? '' : ' (deshabilitado)');
        }
    }
?>
<form method="post" action="reparticiones.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_reparticion'] ?>">
  <div class="card">
    <?= card_head($esNueva ? 'Nueva repartición' : $reg['nombre'], $esNueva ? '' : implode(' · ', usos_reparticion((int) $reg['id_reparticion'])),
                  boton('Volver', 'secondary', 'reparticiones.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="80" required>
          <small class="campo-ayuda">Como en las planillas de comisiones; queda en mayúsculas.</small></div>
        <div class="campo" style="--span:4"><label for="id_organismo">Organismo</label>
          <select id="id_organismo" name="id_organismo"><?= opciones($opOrg, $reg['id_organismo'] ?? '', 'Sin organismo') ?></select>
          <small class="campo-ayuda"><?= puede('organismos.php') ? '<a href="organismos.php">¿no está?</a>' : 'Los agrupa en la ficha del cliente.' ?></small></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Habilitada</label>
          <small class="campo-ayuda">Deshabilitada no se ofrece en la ficha del cliente.</small></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNueva ? 'Dar de alta' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Reparticiones') ?>
  <form method="get" action="reparticiones.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" class="filter-input" placeholder="Repartición u organismo…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Organismo</span>
      <select name="organismo" class="filtro <?= $fOrg ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones($orgNombre, $fOrg ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '1' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['1' => 'Habilitadas', '0' => 'Deshabilitadas', 'todas' => 'Todas'], $fEstado) ?></select></label>
    <?= filtro_botones('reparticiones.php', $hayFiltros) ?>
    <?= filtro_fin_local(null) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="repartición|reparticiones">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Repartición</th><th>Organismo</th>
        <th class="th-right">Clientes</th><th class="th-right" title="Renglones de las planillas de comisiones importadas">En planillas</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $r): $rid = (int) $r['id_reparticion']; $sinUso = !(int) $r['clientes'] && !(int) $r['importadas']; ?>
        <tr class="<?= (int) $r['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'reparticiones.php?edit=' . $rid, 'Editar') ?>
            <?= (int) $r['activo']
                  ? accion_reparticion($rid, 'deshabilitar', 'cerrar', 'Deshabilitar', $volverQs, '«' . $r['nombre'] . '» deja de ofrecerse en la ficha del cliente. Los clientes que la tienen la conservan.', 'borrar')
                  : accion_reparticion($rid, 'habilitar', 'ok', 'Habilitar', $volverQs) ?>
            <?= $sinUso ? accion_reparticion($rid, 'borrar', 'borrar', 'Eliminar', $volverQs, 'Se elimina «' . $r['nombre'] . '». Ningún cliente la tiene.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="reparticiones.php?edit=<?= $rid ?>"><?= h($r['nombre']) ?></a></td>
          <td><?= $r['organismo'] !== null ? h($r['organismo']) . ((int) $r['organismo_activo'] ? '' : ' <span class="td-muted">(deshabilitado)</span>')
                                            : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right" data-orden="<?= (int) $r['clientes'] ?>"><?= (int) $r['clientes']
                ? (puede('clientes.php') ? '<a href="clientes.php?reparticion=' . $rid . '">' . num($r['clientes']) . '</a>' : num($r['clientes']))
                : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right" data-orden="<?= (int) $r['importadas'] ?>"><?= (int) $r['importadas'] ? num($r['importadas']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $r['activo'] ? estado('Habilitada', 'ok') : estado('Deshabilitada', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="6" class="no-data"><?= $hayFiltros ? 'Ninguna repartición con esos filtros.' : 'Todavía no hay reparticiones. Cargá la primera con «Nueva repartición».' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
layout_fin();
