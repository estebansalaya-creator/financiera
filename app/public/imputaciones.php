<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/contable.php';

// ---------------------------------------------------------------------
// Imputaciones contables (sql/financiera_contabilidad_imputaciones.sql),
// la pantalla de pago_imputaciones_config de gestion_adm:
//   imputaciones.php                 las clases y las cuentas de los maestros
//   imputaciones.php?nuevo=CLASE     configurar una clase (o un subtipo)
//   imputaciones.php?edit=N          editar
// Las clases y qué lado lleva cada una: CTB_CLASES (inc/contable.php). La
// cuenta de fondos y la del gasto cobrado no van acá: están en su maestro
// (Cuentas de fondos, Conceptos de gasto) y abajo se ven las que faltan.
// Tabla de configuración: sin Excel.
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));

/** Las opciones del subtipo según la clase: [valor => texto]. */
function imp_subtipos(?string $tipo): array
{
    if ($tipo === 'moneda') {
        return ['ARS' => 'Pesos', 'USD' => 'Dólares'];
    }
    if ($tipo === 'tipo') {
        $out = [];
        foreach (q('SELECT codigo, nombre FROM tipos_asociado ORDER BY codigo') as $t) {
            $out[$t['codigo']] = $t['codigo'] . ' · ' . $t['nombre'];
        }
        return $out;
    }
    return [];
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        $actual = $id ? q1('SELECT * FROM c_imputaciones_config WHERE id = ?', [$id]) : null;
        if ($id && !$actual) {
            flash('danger', 'La imputación ya no existe.');
            redirigir('imputaciones.php');
        }
        if ($accion === 'borrar' && $actual) {
            q('DELETE FROM c_imputaciones_config WHERE id = ?', [$id]);
            flash('ok', 'Se quitó «' . $actual['nombre'] . '». '
                  . ($actual['subtipo'] !== '' ? 'Ese subtipo usa ahora la general de la clase.' : 'La clase queda sin cuenta: sus asientos van a salir con aviso.'));
            redirigir('imputaciones.php');
        }
        if ($accion === 'guardar') {
            $clase = $actual['clase'] ?? post_str('clase');
            $def = CTB_CLASES[$clase] ?? null;
            $reg = ['id' => $id ?: null, 'clase' => $clase, 'subtipo' => $def && $def[3] ? post_str('subtipo') : '',
                    'nombre' => trim(post_str('nombre')), 'id_cuenta_debe' => $def && $def[1] ? post_str('id_cuenta_debe') : '',
                    'id_cuenta_haber' => $def && $def[2] ? post_str('id_cuenta_haber') : '', 'activo' => post_str('activo') === '1' ? 1 : 0,
                    'observaciones' => trim(post_str('observaciones'))];
            if (!$def) {
                $errores[] = 'Elegí la clase de operación.';
            } else {
                if ($reg['subtipo'] !== '' && !isset(imp_subtipos($def[3])[$reg['subtipo']])) {
                    $errores[] = 'El subtipo elegido no existe.';
                }
                if ($reg['nombre'] === '') {
                    $reg['nombre'] = $def[0] . ($reg['subtipo'] !== '' ? ' · ' . $reg['subtipo'] : '');
                }
                foreach (['id_cuenta_debe' => 1, 'id_cuenta_haber' => 2] as $campo => $i) {
                    if ($def[$i] && $reg[$campo] === '') {
                        $errores[] = 'Falta la cuenta del ' . ($i === 1 ? 'debe' : 'haber') . ': ' . mb_strtolower($def[$i]) . '.';
                    } elseif (($e = ctb_error_cuenta_imputable($reg[$campo])) !== null) {
                        $errores[] = $e;
                    }
                }
                if (q1('SELECT 1 AS x FROM c_imputaciones_config WHERE clase = ? AND subtipo = ?' . ($id ? ' AND id <> ' . (int) $id : ''),
                       [$clase, $reg['subtipo']])) {
                    $errores[] = $def[0] . ($reg['subtipo'] !== '' ? ' para ' . $reg['subtipo'] : ' (general)') . ' ya está configurada: editá esa.';
                }
            }
            if (!$errores) {
                $vals = [$reg['subtipo'], mb_substr($reg['nombre'], 0, 100), $reg['id_cuenta_debe'] !== '' ? (int) $reg['id_cuenta_debe'] : null,
                         $reg['id_cuenta_haber'] !== '' ? (int) $reg['id_cuenta_haber'] : null, $reg['activo'],
                         $reg['observaciones'] !== '' ? mb_substr($reg['observaciones'], 0, 255) : null];
                if ($id) {
                    q('UPDATE c_imputaciones_config SET subtipo=?, nombre=?, id_cuenta_debe=?, id_cuenta_haber=?, activo=?, observaciones=? WHERE id=?',
                      array_merge($vals, [$id]));
                } else {
                    q('INSERT INTO c_imputaciones_config (subtipo, nombre, id_cuenta_debe, id_cuenta_haber, activo, observaciones, clase, creado_por)
                       VALUES (?,?,?,?,?,?,?,?)', array_merge($vals, [$clase, usuario_id()]));
                }
                flash('ok', '«' . $reg['nombre'] . '» guardada.');
                redirigir('imputaciones.php');
            }
        }
    } catch (Throwable $e) {
        $errores[] = ctb_falta_script($e);
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$filas = [];
$porClase = [];
$fondos = [];
$gastos = [];
$tesoreria = [];
$cuentas = [];
try {
    if (!ctb_imputaciones_instaladas()) {
        throw new RuntimeException('Falta correr sql\financiera_contabilidad_imputaciones.sql: agrega la cuenta contable a las cuentas de fondos y a los conceptos de gasto, y la tabla de imputaciones.');
    }
    $cuentas = ctb_cuentas_imputables();
    if ($id && $reg === null) {
        $reg = q1('SELECT * FROM c_imputaciones_config WHERE id = ?', [$id]);
        if (!$reg) {
            flash('danger', 'La imputación no existe.');
            redirigir('imputaciones.php');
        }
    } elseif ($reg === null && ($c = get_str('nuevo')) !== '') {
        $c = isset(CTB_CLASES[$c]) ? $c : array_key_first(CTB_CLASES);
        $reg = ['id' => null, 'clase' => $c, 'subtipo' => get_str('subtipo'), 'nombre' => '', 'id_cuenta_debe' => '', 'id_cuenta_haber' => '',
                'activo' => 1, 'observaciones' => ''];
    }
    $filas = q("SELECT i.*, d.codigo AS debe_codigo, d.nombre AS debe_nombre, hb.codigo AS haber_codigo, hb.nombre AS haber_nombre
                  FROM c_imputaciones_config i
                  LEFT JOIN c_plan_de_cuentas d  ON d.id_cuenta  = i.id_cuenta_debe
                  LEFT JOIN c_plan_de_cuentas hb ON hb.id_cuenta = i.id_cuenta_haber
                 ORDER BY i.clase, i.subtipo <> '', i.subtipo");
    foreach ($filas as $f) {
        $porClase[$f['clase']][] = $f;
    }
    $fondos = q('SELECT id_cuenta_fondos, tipo, moneda, nombre, id_cuenta_contable FROM cuentas_fondos WHERE habilitada = 1 ORDER BY tipo, moneda, nombre');
    $gastos = q('SELECT id_concepto_gasto, nombre, lleva_iva, id_cuenta_contable FROM gastos_conceptos WHERE activo = 1 ORDER BY orden, nombre');
    try {
        $tesoreria = q('SELECT id_concepto_tes, tipo, nombre, id_cuenta_contable FROM conceptos_tesoreria WHERE activo = 1 ORDER BY tipo, orden, nombre');
    } catch (Throwable $e) {
        $tesoreria = [];                                      // todavía no se corrió financiera_conciliacion.sql
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof RuntimeException ? $e->getMessage() : ctb_falta_script($e);
}

$sinClase = array_diff(array_keys(CTB_CLASES), array_keys(array_filter($porClase, fn ($fs) => array_filter($fs, fn ($f) => $f['subtipo'] === '' && (int) $f['activo']))));
$sinFondos = array_filter($fondos, fn ($f) => !$f['id_cuenta_contable']);
$sinGastos = array_filter($gastos, fn ($g) => !$g['id_cuenta_contable']);
$sinTesoreria = array_filter($tesoreria, fn ($g) => !$g['id_cuenta_contable']);

layout_inicio('Imputaciones contables');
titulo('Imputaciones contables', 'Contra qué cuentas va cada operación en los asientos',
       $reg ? '' : boton('Nueva imputación', 'primary', 'imputaciones.php?nuevo=' . (reset($sinClase) ?: 'LIQUIDACION'), 'agregar'),
       'Contabilidad / Imputaciones contables');
mensajes($errores);

if (!$errores && !$reg && ($sinClase || $sinFondos || $sinGastos || $sinTesoreria)):
    $falta = array_filter([
        $sinClase ? num(count($sinClase)) . (count($sinClase) === 1 ? ' clase' : ' clases') . ' sin la imputación general' : '',
        $sinFondos ? num(count($sinFondos)) . (count($sinFondos) === 1 ? ' cuenta de fondos' : ' cuentas de fondos') . ' sin cuenta contable' : '',
        $sinGastos ? num(count($sinGastos)) . (count($sinGastos) === 1 ? ' concepto de gasto' : ' conceptos de gasto') . ' sin cuenta contable' : '',
        $sinTesoreria ? num(count($sinTesoreria)) . (count($sinTesoreria) === 1 ? ' concepto de tesorería' : ' conceptos de tesorería') . ' sin cuenta contable' : '']);
?>
<div class="alert alert-warning"><?= icono('alerta', 16) ?><div>Falta: <?= h(implode(' · ', $falta)) ?>. Los asientos que las usen van a salir con aviso y no se van a emitir.</div></div>
<?php endif;

if ($reg):
    $esNueva = !$reg['id'];
    $def = CTB_CLASES[$reg['clase']];
?>
<form method="post" action="imputaciones.php" autocomplete="off" id="form">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id'] ?>">
  <div class="card">
    <?= card_head($esNueva ? 'Nueva imputación' : $reg['nombre'], $esNueva ? 'una general por clase; un subtipo la reemplaza para ese tipo de asociado o moneda' : '',
                  boton('Volver', 'secondary', 'imputaciones.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="clase">Clase de operación <span class="req">*</span></label>
          <?php if ($esNueva): ?>
            <select id="clase" name="clase" onchange="location.href='imputaciones.php?nuevo=' + this.value + '#form'">
              <?= opciones(array_map(fn ($d) => $d[0], CTB_CLASES), $reg['clase']) ?></select>
          <?php else: ?>
            <input value="<?= h($def[0]) ?>" readonly>
          <?php endif; ?></div>
        <?php if ($def[3]): ?>
        <div class="campo" style="--span:4"><label for="subtipo"><?= $def[3] === 'moneda' ? 'Moneda' : 'Tipo de asociado' ?></label>
          <select id="subtipo" name="subtipo"><?= opciones(imp_subtipos($def[3]), $reg['subtipo'], 'General (todos)') ?></select>
          <span class="campo-ayuda">vacío: la general de la clase</span></div>
        <?php endif; ?>
        <div class="campo" style="--span:<?= $def[3] ? 4 : 8 ?>"><label for="nombre">Nombre</label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="100" placeholder="<?= h($def[0]) ?>"></div>
      </div>
      <div class="campos" style="margin-top:6px">
        <div class="campo" style="--span:6"><label for="id_cuenta_debe">Debe<?= $def[1] ? ' <span class="req">*</span>' : '' ?></label>
          <?php if ($def[1]): ?>
            <?= ctb_select_cuenta('id_cuenta_debe', $cuentas, $reg['id_cuenta_debe'], 'Elegí la cuenta…') ?>
            <span class="campo-ayuda"><?= h($def[1]) ?></span>
          <?php else: ?>
            <input value="Sale de otro lado (la cuenta de fondos o el deudor)" readonly><span class="campo-ayuda">esta clase no lleva cuenta propia al debe</span>
          <?php endif; ?></div>
        <div class="campo" style="--span:6"><label for="id_cuenta_haber">Haber<?= $def[2] ? ' <span class="req">*</span>' : '' ?></label>
          <?php if ($def[2]): ?>
            <?= ctb_select_cuenta('id_cuenta_haber', $cuentas, $reg['id_cuenta_haber'], 'Elegí la cuenta…') ?>
            <span class="campo-ayuda"><?= h($def[2]) ?></span>
          <?php else: ?>
            <input value="Sale de otro lado (el deudor por préstamos)" readonly><span class="campo-ayuda">esta clase no lleva cuenta propia al haber</span>
          <?php endif; ?></div>
        <div class="campo" style="--span:8"><label for="observaciones">Observaciones</label>
          <input id="observaciones" name="observaciones" value="<?= h((string) $reg['observaciones']) ?>" maxlength="255"></div>
        <div class="campo campo-boton" style="--span:4">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Activa</label></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNueva ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<?php if (!$errores || $reg): ?>
<div class="card">
  <?= card_head('Por clase de operación', 'la del subtipo manda sobre la general') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones">Acciones</th><th>Clase</th><th>Subtipo</th><th>Debe</th><th>Haber</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach (CTB_CLASES as $clase => $def): $fs = $porClase[$clase] ?? []; ?>
        <?php if (!$fs): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('agregar', 'imputaciones.php?nuevo=' . $clase . '#form', 'Configurar') ?></div></td>
          <td class="td-fuerte"><?= h($def[0]) ?></td>
          <td class="td-muted">—</td>
          <td><?= $def[1] ? '<span class="texto-error">sin cuenta</span><span class="segunda-linea">' . h($def[1]) . '</span>' : '<span class="td-muted">—</span>' ?></td>
          <td><?= $def[2] ? '<span class="texto-error">sin cuenta</span><span class="segunda-linea">' . h($def[2]) . '</span>' : '<span class="td-muted">—</span>' ?></td>
          <td><?= estado('Sin configurar', 'warn') ?></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($fs as $f): $fid = (int) $f['id']; ?>
        <tr class="<?= (int) $f['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'imputaciones.php?edit=' . $fid . '#form', 'Editar') ?>
            <?= $def[3] && $f['subtipo'] === '' ? btn_accion('agregar', 'imputaciones.php?nuevo=' . $clase . '#form',
                                                             $def[3] === 'moneda' ? 'Otra cuenta para una moneda' : 'Otra cuenta para un tipo de asociado') : '' ?>
            <?= accion_post('imputaciones.php', ['accion' => 'borrar', 'id' => $fid], 'borrar', 'Quitar',
                            'Se quita «' . $f['nombre'] . '».' . ($f['subtipo'] !== '' ? ' Ese subtipo pasa a usar la general.' : ' La clase queda sin cuenta.'), 'borrar') ?>
          </div></td>
          <td class="td-fuerte"><a href="imputaciones.php?edit=<?= $fid ?>#form"><?= h($def[0]) ?></a>
            <?= $f['nombre'] !== $def[0] ? '<span class="segunda-linea">' . h($f['nombre']) . '</span>' : '' ?></td>
          <td><?= $f['subtipo'] !== '' ? '<span class="mono">' . h($f['subtipo']) . '</span>' : '<span class="td-muted">General</span>' ?></td>
          <td><?= $def[1] ? ($f['debe_codigo'] ? '<span class="mono">' . h($f['debe_codigo']) . '</span><span class="segunda-linea">' . h($f['debe_nombre']) . '</span>'
                                               : '<span class="texto-error">sin cuenta</span>') : '<span class="td-muted">—</span>' ?></td>
          <td><?= $def[2] ? ($f['haber_codigo'] ? '<span class="mono">' . h($f['haber_codigo']) . '</span><span class="segunda-linea">' . h($f['haber_nombre']) . '</span>'
                                                : '<span class="texto-error">sin cuenta</span>') : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $f['activo'] ? estado('Activa', 'ok') : estado('Inactiva', 'neutro') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <?= card_head('Cuentas de los maestros', 'se cargan en su pantalla') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones">Acciones</th><th>Maestro</th><th>Nombre</th><th>Cuenta contable</th></tr></thead>
      <tbody>
      <?php foreach ($fondos as $f): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'cuentas_fondos.php?edit=' . (int) $f['id_cuenta_fondos'], 'Editar la cuenta de fondos') ?></div></td>
          <td>Cuenta de fondos<span class="segunda-linea"><?= h(['B' => 'Banco', 'C' => 'Caja', 'R' => 'Cartera de cheques', 'V' => 'Billetera virtual', 'I' => 'Inversiones', 'O' => 'Otro'][$f['tipo']] ?? $f['tipo']) ?> · <?= h($f['moneda']) ?></span></td>
          <td class="td-fuerte"><?= h($f['nombre']) ?></td>
          <td><?= $f['id_cuenta_contable'] ? h(ctb_cuenta_txt($f['id_cuenta_contable'])) : '<span class="texto-error">sin cuenta contable</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($gastos as $g): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'gastos_conceptos.php?edit=' . (int) $g['id_concepto_gasto'], 'Editar el concepto de gasto') ?></div></td>
          <td>Concepto de gasto<?= (int) $g['lleva_iva'] ? '<span class="segunda-linea">con IVA</span>' : '' ?></td>
          <td class="td-fuerte"><?= h($g['nombre']) ?></td>
          <td><?= $g['id_cuenta_contable'] ? h(ctb_cuenta_txt($g['id_cuenta_contable'])) : '<span class="texto-error">sin cuenta contable</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($tesoreria as $g): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'conceptos_tesoreria.php?edit=' . (int) $g['id_concepto_tes'], 'Editar el concepto de tesorería') ?></div></td>
          <td>Concepto de tesorería<span class="segunda-linea"><?= $g['tipo'] === 'GASTO' ? 'gasto bancario' : 'ingreso bancario' ?></span></td>
          <td class="td-fuerte"><?= h($g['nombre']) ?></td>
          <td><?= $g['id_cuenta_contable'] ? h(ctb_cuenta_txt($g['id_cuenta_contable'])) : '<span class="texto-error">sin cuenta contable</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fondos && !$gastos): ?><tr><td colspan="4" class="no-data">No hay cuentas de fondos ni conceptos de gasto activos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
