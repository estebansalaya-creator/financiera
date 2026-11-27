<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/contable.php';
require __DIR__ . '/../inc/conciliacion.php';

// ---------------------------------------------------------------------
// Conceptos de tesorería (sql/financiera_conciliacion.sql): lo que el
// banco cobra o acredita por su cuenta (comisiones, impuestos,
// percepciones, intereses). Son los c_concepto_de_gastos y
// c_motivos_de_ingreso de gestion_adm en un solo catálogo. Los usa la
// Clasificación de conceptos y, con su cuenta contable, el asiento del
// comprobante bancario.
//   conceptos_tesoreria.php            listado
//   conceptos_tesoreria.php?nuevo=1    alta
//   conceptos_tesoreria.php?edit=N     edición
// Un concepto con comprobantes o reglas no se elimina: se desactiva.
// Tabla de configuración: sin Excel.
// ---------------------------------------------------------------------

const CTES_TIPOS = ['GASTO' => 'Gasto', 'INGRESO' => 'Ingreso'];

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));
$lista = 'conceptos_tesoreria.php';

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        $actual = $id ? q1('SELECT * FROM conceptos_tesoreria WHERE id_concepto_tes = ?', [$id]) : null;
        if ($id && !$actual) {
            flash('danger', 'El concepto ya no existe.');
            redirigir($lista);
        }
        if (in_array($accion, ['activar', 'desactivar'], true) && $actual) {
            q('UPDATE conceptos_tesoreria SET activo = ? WHERE id_concepto_tes = ?', [$accion === 'activar' ? 1 : 0, $id]);
            flash('ok', '«' . $actual['nombre'] . '» ' . ($accion === 'activar' ? 'activo otra vez.' : 'desactivado: no se ofrece al clasificar. Lo ya emitido no cambia.'));
            redirigir($lista);
        }
        if ($accion === 'borrar' && $actual) {
            $usos = (int) q1('SELECT (SELECT COUNT(*) FROM comprobantes_bancarios_det WHERE id_concepto_tes = ?)
                                   + (SELECT COUNT(*) FROM conc_reglas WHERE id_concepto_tes = ?) AS n', [$id, $id])['n'];
            if ($usos) {
                flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: tiene comprobantes o reglas. Desactivalo.');
            } else {
                q('DELETE FROM conceptos_tesoreria WHERE id_concepto_tes = ?', [$id]);
                flash('ok', '«' . $actual['nombre'] . '» eliminado.');
            }
            redirigir($lista);
        }
        if ($accion === 'guardar') {
            $reg = ['id_concepto_tes' => $id ?: null, 'tipo' => post_str('tipo'), 'codigo' => strtoupper(trim(post_str('codigo'))),
                    'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))), 'orden' => post_str('orden') ?: '100',
                    'activo' => post_str('activo') === '1' ? 1 : 0, 'id_cuenta_contable' => post_str('id_cuenta_contable')];
            if (!isset(CTES_TIPOS[$reg['tipo']])) {
                $errores[] = 'Elegí si es un gasto o un ingreso.';
            }
            if (!preg_match('/^[A-Z0-9_]{1,20}$/', $reg['codigo'])) {
                $errores[] = 'El código va en mayúsculas, números y guion bajo (hasta 20).';
            } elseif (q1('SELECT 1 AS x FROM conceptos_tesoreria WHERE codigo = ? AND id_concepto_tes <> ?', [$reg['codigo'], $id])) {
                $errores[] = 'Ya hay un concepto con el código ' . $reg['codigo'] . '.';
            }
            if ($reg['nombre'] === '' || mb_strlen($reg['nombre']) > 100) {
                $errores[] = 'Falta el nombre (hasta 100 caracteres).';
            }
            if (!preg_match('/^-?\d{1,4}$/', $reg['orden'])) {
                $errores[] = 'El orden es un número.';
            }
            if (($e = ctb_error_cuenta_imputable($reg['id_cuenta_contable'])) !== null) {
                $errores[] = $e;
            }
            if (!$errores) {
                $datos = [$reg['tipo'], $reg['codigo'], $reg['nombre'], (int) $reg['orden'], $reg['activo'],
                          $reg['id_cuenta_contable'] !== '' ? (int) $reg['id_cuenta_contable'] : null];
                if ($id) {
                    q('UPDATE conceptos_tesoreria SET tipo=?, codigo=?, nombre=?, orden=?, activo=?, id_cuenta_contable=? WHERE id_concepto_tes=?',
                      array_merge($datos, [$id]));
                } else {
                    q('INSERT INTO conceptos_tesoreria (tipo, codigo, nombre, orden, activo, id_cuenta_contable, creado_por) VALUES (?,?,?,?,?,?,?)',
                      array_merge($datos, [usuario_id()]));
                }
                flash('ok', '«' . $reg['nombre'] . '» ' . ($id ? 'guardado.' : 'agregado.'));
                redirigir($lista);
            }
        }
    } catch (Throwable $e) {
        $errores[] = conc_falta_script($e);
    }
}

$conceptos = [];
try {
    if ($id && $reg === null) {
        $reg = q1('SELECT * FROM conceptos_tesoreria WHERE id_concepto_tes = ?', [$id]);
        if (!$reg) {
            flash('danger', 'El concepto no existe.');
            redirigir($lista);
        }
    } elseif ($reg === null && get_str('nuevo') !== '') {
        $reg = ['id_concepto_tes' => null, 'tipo' => 'GASTO', 'codigo' => '', 'nombre' => '', 'orden' => '100', 'activo' => 1, 'id_cuenta_contable' => ''];
    }
    $conceptos = q('SELECT t.*,
                           (SELECT COUNT(*) FROM conc_reglas r WHERE r.id_concepto_tes = t.id_concepto_tes AND r.activa = 1) AS reglas,
                           (SELECT COUNT(DISTINCT d.id_comp_banco) FROM comprobantes_bancarios_det d WHERE d.id_concepto_tes = t.id_concepto_tes) AS comprobantes
                      FROM conceptos_tesoreria t ORDER BY t.activo DESC, t.tipo, t.orden, t.nombre');
} catch (Throwable $e) {
    $errores[] = conc_falta_script($e);
}

layout_inicio('Conceptos de tesorería');
titulo('Conceptos de tesorería', 'Lo que el banco cobra o acredita por su cuenta: comisiones, impuestos, percepciones, intereses',
       $reg ? '' : boton('Nuevo concepto', 'primary', 'conceptos_tesoreria.php?nuevo=1', 'agregar'),
       'Tesorería / Conceptos de tesorería');
mensajes($errores);

if ($reg):
    $esNuevo = !$reg['id_concepto_tes'];
?>
<form method="post" action="conceptos_tesoreria.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_concepto_tes'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo concepto' : $reg['nombre'], '', boton('Volver', 'secondary', $lista, 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2"><label for="tipo">Tipo <span class="req">*</span></label>
          <select id="tipo" name="tipo"><?= opciones(CTES_TIPOS, $reg['tipo']) ?></select></div>
        <div class="campo" style="--span:2"><label for="codigo">Código <span class="req">*</span></label>
          <input id="codigo" name="codigo" value="<?= h($reg['codigo']) ?>" maxlength="20" required class="mono" placeholder="COMISION"></div>
        <div class="campo" style="--span:5"><label for="nombre">Concepto <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="100" required placeholder="Comisiones y gastos bancarios"></div>
        <div class="campo" style="--span:1"><label for="orden">Orden</label>
          <input id="orden" name="orden" value="<?= h((string) $reg['orden']) ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Activo</label></div>
        <div class="campo" style="--span:6"><label for="id_cuenta_contable">Cuenta contable</label>
          <?= ctb_select_cuenta('id_cuenta_contable', ctb_cuentas_imputables(), $reg['id_cuenta_contable'] ?? '', 'Sin asignar') ?>
          <span class="campo-ayuda">contra la cuenta de fondos en el asiento del comprobante bancario</span></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Conceptos', 'tabla de configuración') ?>
  <?= barra_local('conceptos_tesoreria.php', 'Concepto o código…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="concepto|conceptos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Concepto</th><th>Tipo</th><th>Cuenta contable</th>
        <th class="th-right">Reglas</th><th class="th-right">Comprobantes</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($conceptos as $c): $cid = (int) $c['id_concepto_tes']; $usos = (int) $c['reglas'] + (int) $c['comprobantes']; ?>
        <tr class="<?= (int) $c['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'conceptos_tesoreria.php?edit=' . $cid, 'Editar') ?>
            <?= (int) $c['activo'] ? accion_post($lista, ['accion' => 'desactivar', 'id' => $cid], 'cerrar', 'Desactivar', '«' . $c['nombre'] . '» deja de ofrecerse al clasificar.', 'borrar')
                                  : accion_post($lista, ['accion' => 'activar', 'id' => $cid], 'ok', 'Activar') ?>
            <?= !$usos ? accion_post($lista, ['accion' => 'borrar', 'id' => $cid], 'borrar', 'Eliminar', 'Se elimina «' . $c['nombre'] . '». No tiene reglas ni comprobantes.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="conceptos_tesoreria.php?edit=<?= $cid ?>"><?= h($c['nombre']) ?></a><span class="segunda-linea mono"><?= h($c['codigo']) ?></span></td>
          <td><?= estado(CTES_TIPOS[$c['tipo']], $c['tipo'] === 'GASTO' ? 'warn' : 'ok') ?></td>
          <td><?= $c['id_cuenta_contable'] ? h(ctb_cuenta_txt($c['id_cuenta_contable'])) : '<span class="texto-error">sin cuenta contable</span>' ?></td>
          <td class="td-right"><?= (int) $c['reglas'] ? num($c['reglas']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (int) $c['comprobantes'] ? num($c['comprobantes']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $c['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$conceptos): ?><tr><td colspan="7" class="no-data">Todavía no hay conceptos. Cargá el primero con «Nuevo concepto».</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php layout_fin(); ?>
