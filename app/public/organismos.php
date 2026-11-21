<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Organismos: quién paga los haberes del asociado (ANSES, IPS, Pensiones
// graciables…). Agrupan las reparticiones (reparticiones.php).
//   organismos.php            listado
//   organismos.php?nuevo=1    alta
//   organismos.php?edit=N     edición
//   - Un organismo con reparticiones no se elimina: se deshabilita (deja de
//     ofrecerse al cargar una repartición; las que tiene lo conservan).
//   Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (es_post() ? post_str('id') : get_str('edit'));

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $actual = $id ? q1('SELECT * FROM organismos WHERE id_organismo = ?', [$id]) : null;
    if ($id && !$actual) {
        flash('danger', 'El organismo ya no existe.');
        redirigir('organismos.php');
    }
    $volver = 'organismos.php' . (preg_match('/^\?[\w=&%.-]*$/', post_str('volver')) ? post_str('volver') : '');

    if (in_array($accion, ['habilitar', 'deshabilitar'], true) && $actual) {
        q('UPDATE organismos SET activo = ? WHERE id_organismo = ?', [$accion === 'habilitar' ? 1 : 0, $id]);
        flash('ok', '«' . $actual['nombre'] . '» ' . ($accion === 'habilitar' ? 'habilitado.' : 'deshabilitado: deja de ofrecerse al cargar una repartición; las que tiene lo conservan.'));
        redirigir($volver);
    }
    if ($accion === 'borrar' && $actual) {
        $n = (int) q1('SELECT COUNT(*) AS n FROM reparticiones WHERE id_organismo = ?', [$id])['n'];
        if ($n) {
            flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: tiene ' . num($n) . ($n === 1 ? ' repartición' : ' reparticiones') . '. Deshabilitalo.');
        } else {
            q('DELETE FROM organismos WHERE id_organismo = ?', [$id]);
            flash('ok', '«' . $actual['nombre'] . '» eliminado.');
        }
        redirigir($volver);
    }
    if ($accion === 'guardar') {
        $reg = ['id_organismo' => $id ?: null,
                'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))),
                'activo' => post_str('activo') === '1' ? 1 : 0];
        if ($reg['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        } elseif (mb_strlen($reg['nombre']) > 60) {
            $errores[] = 'El nombre va hasta 60 caracteres.';
        } elseif (q1('SELECT 1 AS x FROM organismos WHERE nombre = ? AND id_organismo <> ?', [$reg['nombre'], $id])) {
            $errores[] = 'Ya hay un organismo «' . $reg['nombre'] . '».';
        }
        if (!$errores) {
            try {
                if ($id) {
                    q('UPDATE organismos SET nombre = ?, activo = ? WHERE id_organismo = ?', [$reg['nombre'], $reg['activo'], $id]);
                } else {
                    q('INSERT INTO organismos (nombre, activo) VALUES (?, ?)', [$reg['nombre'], $reg['activo']]);
                    $id = (int) db()->lastInsertId();
                }
                flash('ok', '«' . $reg['nombre'] . '» ' . ($reg['id_organismo'] ? 'guardado.' : 'dado de alta. Sus reparticiones se cargan en Reparticiones.'));
                redirigir('organismos.php');
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e, ['uq_organismos_nombre' => 'Ya hay un organismo «' . $reg['nombre'] . '».']);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$fEstado = in_array(get_str('estado'), ['0', 'todos'], true) ? get_str('estado') : '1';

if ($id && $reg === null) {
    $reg = q1('SELECT * FROM organismos WHERE id_organismo = ?', [$id]);
    if (!$reg) {
        flash('danger', 'El organismo no existe.');
        redirigir('organismos.php');
    }
} elseif ($reg === null && get_str('nuevo') !== '') {
    $reg = ['id_organismo' => null, 'nombre' => '', 'activo' => 1];
}

$lista = q('SELECT o.*,
                   (SELECT COUNT(*) FROM reparticiones r WHERE r.id_organismo = o.id_organismo) AS reparticiones,
                   (SELECT COUNT(*) FROM reparticiones r WHERE r.id_organismo = o.id_organismo AND r.activo = 1) AS habilitadas,
                   (SELECT COUNT(*) FROM clientes c JOIN reparticiones r ON r.id_reparticion = c.id_reparticion
                     WHERE r.id_organismo = o.id_organismo) AS clientes
              FROM organismos o'
          . ($fEstado !== 'todos' ? ' WHERE o.activo = ' . (int) $fEstado : '')
          . ' ORDER BY o.activo DESC, o.nombre');
$volverQs = $fEstado !== '1' ? '?estado=' . $fEstado : '';

/** Botón de una acción de la grilla por POST, con confirmación opcional. */
function accion_organismo(int $id, string $accion, string $icono, string $titulo, string $volver, string $confirmar = '', string $clase = ''): string
{
    return '<form method="post" action="organismos.php" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo)
             . '" data-confirmar-boton="' . h($titulo) . '"' . ($clase === 'borrar' ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="' . h($accion) . '">'
         . '<input type="hidden" name="volver" value="' . h($volver) . '">'
         . '<button class="btn-accion ' . h($clase) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

layout_inicio('Organismos');
titulo('Organismos', 'Quién paga los haberes del asociado: agrupan las reparticiones',
       $reg ? '' : boton('Nuevo organismo', 'primary', 'organismos.php?nuevo=1', 'agregar'));
mensajes($errores);

if ($reg):
    $esNuevo = !$reg['id_organismo'];
    $nRep = $esNuevo ? 0 : (int) q1('SELECT COUNT(*) AS n FROM reparticiones WHERE id_organismo = ?', [(int) $reg['id_organismo']])['n'];
?>
<form method="post" action="organismos.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_organismo'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo organismo' : $reg['nombre'], $esNuevo ? '' : num($nRep) . ($nRep === 1 ? ' repartición' : ' reparticiones'),
                  boton('Volver', 'secondary', 'organismos.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:6"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="60" required>
          <small class="campo-ayuda">ANSES, IPS, Pensiones graciables…</small></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Habilitado</label>
          <small class="campo-ayuda">Deshabilitado no se ofrece al cargar una repartición.</small></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Dar de alta' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Organismos') ?>
  <form method="get" action="organismos.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" class="filter-input" placeholder="Organismo…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '1' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['1' => 'Habilitados', '0' => 'Deshabilitados', 'todos' => 'Todos'], $fEstado) ?></select></label>
    <?= filtro_botones('organismos.php', $fEstado !== '1') ?>
    <?= filtro_fin_local(null) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="organismo|organismos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Organismo</th><th class="th-right">Reparticiones</th>
        <th class="th-right">Clientes</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $o): $oid = (int) $o['id_organismo']; ?>
        <tr class="<?= (int) $o['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'organismos.php?edit=' . $oid, 'Editar') ?>
            <?= btn_accion('ver', 'reparticiones.php?organismo=' . $oid, 'Sus reparticiones') ?>
            <?= (int) $o['activo']
                  ? accion_organismo($oid, 'deshabilitar', 'cerrar', 'Deshabilitar', $volverQs, '«' . $o['nombre'] . '» deja de ofrecerse al cargar una repartición. Las que tiene lo conservan.', 'borrar')
                  : accion_organismo($oid, 'habilitar', 'ok', 'Habilitar', $volverQs) ?>
            <?= !(int) $o['reparticiones'] ? accion_organismo($oid, 'borrar', 'borrar', 'Eliminar', $volverQs, 'Se elimina «' . $o['nombre'] . '». No tiene reparticiones.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="organismos.php?edit=<?= $oid ?>"><?= h($o['nombre']) ?></a></td>
          <td class="td-right" data-orden="<?= (int) $o['reparticiones'] ?>"><?= (int) $o['reparticiones']
                ? '<a href="reparticiones.php?organismo=' . $oid . '&amp;estado=todas">' . num($o['reparticiones']) . '</a>'
                  . ((int) $o['habilitadas'] < (int) $o['reparticiones'] ? '<span class="segunda-linea">' . num($o['habilitadas']) . ' habilitadas</span>' : '')
                : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right" data-orden="<?= (int) $o['clientes'] ?>"><?= (int) $o['clientes'] ? num($o['clientes']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $o['activo'] ? estado('Habilitado', 'ok') : estado('Deshabilitado', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="5" class="no-data"><?= $fEstado !== '1' ? 'Ningún organismo con ese estado.' : 'Todavía no hay organismos. Cargá el primero con «Nuevo organismo».' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
layout_fin();
