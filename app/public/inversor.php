<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/inversiones.php';

// ---------------------------------------------------------------------
// Ficha del inversor (alta y edición) y sus aportes
//   inversor.php        alta
//   inversor.php?id=N   edición
// ---------------------------------------------------------------------

$id = (int) get_str('id');
$errores = [];
$inv = null;
$form = null;

if (es_post()) {
    csrf_exigir();
    $f = ['tipo' => post_str('tipo'), 'cuit' => cuit_limpio(post_str('cuit')), 'denominacion' => post_str('denominacion'),
          'email' => post_str('email'), 'telefono' => post_str('telefono'), 'domicilio' => post_str('domicilio'),
          'cbu' => preg_replace('/\D/', '', post_str('cbu')), 'alias_cbu' => post_str('alias_cbu'),
          'estado' => post_str('estado') === 'INACTIVO' ? 'INACTIVO' : 'ACTIVO', 'observaciones' => post_str('observaciones')];
    $form = $f;
    if (!in_array($f['tipo'], ['F', 'J'], true)) {
        $errores[] = 'Elegí si es persona o empresa.';
    }
    if (!cuit_valido($f['cuit'])) {
        $errores[] = 'El CUIT/CUIL no es válido.';
    }
    if ($f['denominacion'] === '') {
        $errores[] = 'Falta el nombre o la razón social.';
    }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no es válido.';
    }
    if ($f['cbu'] !== '' && strlen($f['cbu']) !== 22) {
        $errores[] = 'El CBU/CVU tiene 22 dígitos.';
    }
    $tel = null;
    if ($f['telefono'] !== '') {
        $tel = tel_normalizar($f['telefono']);            // celular si se escribe con 15 o con +54 9
        if ($tel === null) {
            $errores[] = tel_error('Teléfono');
        }
    }
    if (!$errores) {
        $datos = [$f['tipo'], $f['cuit'], function_exists('mb_strtoupper') ? mb_strtoupper($f['denominacion'], 'UTF-8') : strtoupper($f['denominacion']),
                  $f['email'] ?: null, $tel, $f['domicilio'] ?: null, $f['cbu'] ?: null, $f['alias_cbu'] ?: null,
                  $f['estado'], $f['observaciones'] ?: null];
        try {
            if ($id > 0) {
                q('UPDATE inversores SET tipo=?, cuit=?, denominacion=?, email=?, telefono=?, domicilio=?, cbu=?, alias_cbu=?, estado=?, observaciones=?
                    WHERE id_inversor = ?', array_merge($datos, [$id]));
            } else {
                q('INSERT INTO inversores (tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, observaciones, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge(array_values($datos), [usuario_id()]));
                $id = (int) db()->lastInsertId();
            }
            flash('ok', 'Inversor guardado.');
            redirigir('inversor.php?id=' . $id);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e, ['uq_inversores_cuit' => 'Ya hay un inversor con ese CUIT/CUIL.']);
            if (!isset($_GET['id'])) {
                $id = 0;
            }
        }
    }
}

$aportes = [];
try {
    if ($id > 0) {
        $inv = q1('SELECT * FROM inversores WHERE id_inversor = ?', [$id]);
        $aportes = q('SELECT i.*, d.interes_devengado, d.interes_al_vencimiento
                        FROM inversiones i LEFT JOIN v_inversiones_devengado d ON d.id_inversion = i.id_inversion
                       WHERE i.id_inversor = ? ORDER BY i.estado = \'VIGENTE\' DESC, i.fecha_inicio DESC, i.id_inversion DESC', [$id]);
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas: corré sql\financiera_inversores.sql.' : error_amigable($e);
}
if ($id > 0 && !$inv && !$form) {
    http_response_code(404);
}

$v = $form ?? ($inv ?: ['tipo' => 'F', 'cuit' => '', 'denominacion' => '', 'email' => '', 'telefono' => '', 'domicilio' => '',
                        'cbu' => '', 'alias_cbu' => '', 'estado' => 'ACTIVO', 'observaciones' => '']);

$cotUsd = cotizacion_segura('USD');

// Totales vigentes por moneda
$tot = [];
foreach ($aportes as $a) {
    if ($a['estado'] === 'VIGENTE') {
        $tot[$a['moneda']]['capital'] = ($tot[$a['moneda']]['capital'] ?? 0) + (float) $a['capital'];
        $tot[$a['moneda']]['dev'] = ($tot[$a['moneda']]['dev'] ?? 0) + (float) $a['interes_devengado'];
    }
}

layout_inicio($inv ? $inv['denominacion'] : 'Nuevo inversor', 'inversiones');
titulo($inv ? $inv['denominacion'] : 'Nuevo inversor', $inv ? cuit_fmt($inv['cuit']) . ' · inversor N° ' . $inv['id_inversor'] : 'Persona o empresa que aporta capital',
       $inv ? boton('Nuevo aporte', 'primary', 'inversion.php?nueva=1&inversor=' . $id, 'agregar') : '',
       '<a href="inversiones.php">Inversiones</a> / <a href="inversores.php">Inversores</a>' . ($inv ? ' / ' . h($inv['denominacion']) : ''));
mensajes($errores);
?>

<?php if ($tot): ?>
<div class="bcra-tarjetas indicadores-fila">
  <?php foreach ($tot as $mon => $t): ?>
    <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Capital vigente en <?= h(strtolower(MONEDAS[$mon])) ?></span><strong><?= dinero($t['capital'], $mon) ?></strong>
      <span class="bcra-tarjeta-sub">interés devengado a hoy <?= dinero($t['dev'], $mon) ?><?=
        $mon === 'USD' ? '<br>' . h(equiv_pesos($t['capital'] + $t['dev'], 'USD', $cotUsd)) . ' capital + interés, a la cotización de hoy' : '' ?></span></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" action="inversor.php<?= $id ? '?id=' . $id : '' ?>">
  <?= csrf_campo() ?>
  <div class="card">
    <?= card_head('Datos del inversor') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:3"><span class="campo-label">Tipo</span>
          <div class="radios">
            <label><input type="radio" name="tipo" value="F" <?= $v['tipo'] === 'F' ? 'checked' : '' ?>> Persona</label>
            <label><input type="radio" name="tipo" value="J" <?= $v['tipo'] === 'J' ? 'checked' : '' ?>> Empresa</label>
          </div></div>
        <div class="campo" style="--span:3"><label for="cuit">CUIT / CUIL <span class="req">*</span></label>
          <input id="cuit" name="cuit" value="<?= h(cuit_fmt($v['cuit'])) ?>" class="mono" data-cuit required maxlength="13"><span class="campo-ayuda"></span></div>
        <div class="campo" style="--span:4"><label for="denominacion">Apellido y nombre / razón social <span class="req">*</span></label>
          <input id="denominacion" name="denominacion" value="<?= h($v['denominacion']) ?>" maxlength="150" required></div>
        <div class="campo" style="--span:2"><label for="estado">Estado</label>
          <select id="estado" name="estado"><?= opciones(['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo'], $v['estado']) ?></select></div>
        <div class="campo" style="--span:4"><label for="email">Email</label>
          <input type="email" id="email" name="email" value="<?= h($v['email'] ?? '') ?>" maxlength="150"></div>
        <div class="campo" style="--span:3"><label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono" type="tel" class="mono" value="<?= h(tel_fmt($v['telefono'] ?? '')) ?>" maxlength="25" data-tel="" placeholder="11 15 2345-6789">
          <small class="campo-ayuda">Con la característica. Un celular, con el 15.</small></div>
        <div class="campo" style="--span:5"><label for="domicilio">Domicilio</label>
          <input id="domicilio" name="domicilio" value="<?= h($v['domicilio'] ?? '') ?>" maxlength="200"></div>
        <div class="campo" style="--span:5"><label for="cbu">CBU / CVU (para pagarle)</label>
          <input id="cbu" name="cbu" value="<?= h($v['cbu'] ?? '') ?>" class="mono" maxlength="26" inputmode="numeric"></div>
        <div class="campo" style="--span:3"><label for="alias_cbu">Alias</label>
          <input id="alias_cbu" name="alias_cbu" value="<?= h($v['alias_cbu'] ?? '') ?>" maxlength="40"></div>
        <div class="campo" style="--span:12"><label for="observaciones">Observaciones</label>
          <input id="observaciones" name="observaciones" value="<?= h($v['observaciones'] ?? '') ?>" maxlength="255"></div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Volver', 'secondary', 'inversores.php', 'volver') ?>
      <?= boton($inv ? 'Guardar cambios' : 'Crear inversor', 'primary', null, 'guardar', true) ?>
    </div>
  </div>
</form>

<?php if ($inv): ?>
<div class="card">
  <?= card_head('Aportes', count($aportes) ? count($aportes) . ' aportes' : '', boton('Nuevo aporte', 'ghost', 'inversion.php?nueva=1&inversor=' . $id, 'agregar', false, true)) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>N°</th><th data-no-sort>Modalidad</th>
        <th data-no-sort>Inicio</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Capital</th>
        <th data-no-sort>Condición</th><th class="th-right" data-no-sort>Devengado a hoy</th><th data-no-sort>Estado</th></tr></thead>
      <tbody>
      <?php if (!$aportes): ?><tr><td colspan="9" class="no-data">Sin aportes todavía.</td></tr><?php endif; ?>
      <?php foreach ($aportes as $a): ?>
        <tr<?= $a['estado'] === 'ANULADA' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'inversion.php?id=' . $a['id_inversion'], 'Ver el aporte') ?></div></td>
          <td class="mono td-fuerte"><a href="inversion.php?id=<?= (int) $a['id_inversion'] ?>"><?= h($a['nro_inversion']) ?></a></td>
          <td><?= h(MODALIDADES[$a['modalidad']]) ?> · <?= h($a['moneda']) ?></td>
          <td><?= h(fecha($a['fecha_inicio'])) ?></td><td><?= h(fecha($a['fecha_vencimiento'])) ?></td>
          <td class="td-right"><?= dinero($a['capital'], $a['moneda']) ?></td>
          <td><?= h(condicion_inversion($a)) ?></td>
          <td class="td-right"><?= $a['estado'] === 'VIGENTE' ? dinero($a['interes_devengado'], $a['moneda']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= estado_inversion($a['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
