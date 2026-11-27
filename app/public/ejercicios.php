<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/contable.php';

// ---------------------------------------------------------------------
// Ejercicios y períodos (sql/financiera_contabilidad.sql), como en
// gestion_adm (contable.py: ejercicios, periodos, sp_abrir_ejercicio)
//   ejercicios.php            los ejercicios
//   ejercicios.php?nuevo=1    abrir un ejercicio (con sus meses)
//   ejercicios.php?edit=N     editar
//   ejercicios.php?ver=N      sus períodos: cerrar, reabrir, bloquear
// Un período abierto admite asientos; cerrado no, y se puede reabrir;
// bloqueado no, y no se reabre. El ejercicio «de trabajo» es uno solo.
// Con asientos, ni el ejercicio ni sus períodos se eliminan, y las fechas
// no se cambian. El cierre del ejercicio (asiento de refundición y de
// cierre) llega con los asientos.
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));
$ver = (int) get_str('ver');

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        $actual = $id ? q1('SELECT * FROM c_ejercicios WHERE id_ejercicio = ?', [$id]) : null;
        if ($id && !$actual) {
            flash('danger', 'El ejercicio ya no existe.');
            redirigir('ejercicios.php');
        }
        if ($accion === 'activar' && $actual) {
            db()->beginTransaction();
            q('UPDATE c_ejercicios SET activo = 0 WHERE activo = 1');
            q('UPDATE c_ejercicios SET activo = 1 WHERE id_ejercicio = ?', [$id]);
            db()->commit();
            flash('ok', $actual['nombre'] . ' es ahora el ejercicio de trabajo.');
            redirigir('ejercicios.php');
        }
        if ($accion === 'borrar' && $actual) {
            if (ctb_asientos_ejercicio($id)) {
                flash('danger', 'No se puede eliminar ' . $actual['nombre'] . ': tiene asientos.');
            } else {
                q('DELETE FROM c_ejercicios WHERE id_ejercicio = ?', [$id]);        // los períodos, en cascada
                // Si era el de trabajo, pasa a serlo el más reciente que quede.
                if ((int) $actual['activo']) {
                    q('UPDATE c_ejercicios SET activo = 1 ORDER BY fecha_inicio DESC LIMIT 1');
                }
                flash('ok', $actual['nombre'] . ' eliminado, con sus períodos.');
            }
            redirigir('ejercicios.php');
        }
        if (in_array($accion, ['cerrar_periodo', 'abrir_periodo', 'bloquear_periodo'], true)) {
            $p = q1('SELECT p.*, e.nombre AS ejercicio FROM c_periodos p JOIN c_ejercicios e ON e.id_ejercicio = p.id_ejercicio
                      WHERE p.id_periodo = ?', [(int) post_str('id_periodo')]);
            if (!$p) {
                flash('danger', 'El período ya no existe.');
                redirigir('ejercicios.php');
            }
            $nuevo = ['cerrar_periodo' => 'cerrado', 'abrir_periodo' => 'abierto', 'bloquear_periodo' => 'bloqueado'][$accion];
            if ($p['estado'] === 'bloqueado') {
                flash('danger', $p['nombre'] . ' está bloqueado: no cambia de estado.');
            } elseif ($nuevo === 'abierto' && q1("SELECT 1 AS x FROM c_ejercicios WHERE id_ejercicio = ? AND estado <> 'abierto'", [$p['id_ejercicio']])) {
                flash('danger', 'El ejercicio ' . $p['ejercicio'] . ' no está abierto: sus períodos no se reabren.');
            } else {
                q('UPDATE c_periodos SET estado = ? WHERE id_periodo = ?', [$nuevo, $p['id_periodo']]);
                flash('ok', $p['nombre'] . ': ' . mb_strtolower(CTB_ESTADOS[$nuevo]) . ($nuevo === 'abierto' ? ', admite asientos otra vez.'
                      : ($nuevo === 'cerrado' ? '. No admite asientos; se puede reabrir.' : '. No admite asientos ni se reabre.')));
            }
            redirigir('ejercicios.php?ver=' . (int) $p['id_ejercicio'] . '#periodos');
        }
        if ($accion === 'guardar') {
            $reg = ['id_ejercicio' => $id ?: null, 'nombre' => trim(post_str('nombre')), 'fecha_inicio' => post_str('fecha_inicio'),
                    'fecha_fin' => post_str('fecha_fin'), 'cuit' => cuit_limpio(post_str('cuit')), 'razon_social' => trim(post_str('razon_social')),
                    'observaciones' => trim(post_str('observaciones')), 'generar' => post_str('generar') === '1', 'trabajo' => post_str('trabajo') === '1',
                    'estado' => $actual['estado'] ?? 'abierto', 'activo' => $actual['activo'] ?? 0];
            $desde = leer_fecha($reg['fecha_inicio']);
            $hasta = leer_fecha($reg['fecha_fin']);
            $conAsientos = $actual ? ctb_asientos_ejercicio($id) > 0 : false;
            $cambiaFechas = $actual && ($desde !== $actual['fecha_inicio'] || $hasta !== $actual['fecha_fin']);
            if ($reg['nombre'] === '') {
                $errores[] = 'Falta el nombre.';
            }
            if (!$desde || !$hasta) {
                $errores[] = 'Faltan las fechas de inicio y de fin.';
            } elseif ($hasta <= $desde) {
                $errores[] = 'El fin tiene que ser posterior al inicio.';
            } elseif ((new DateTimeImmutable($desde))->modify('+18 months') <= new DateTimeImmutable($hasta)) {
                $errores[] = 'Un ejercicio dura hasta 18 meses.';
            } elseif ($o = ctb_ejercicio_superpuesto($desde, $hasta, $id ?: null)) {
                $errores[] = 'Las fechas se cruzan con ' . $o['nombre'] . '.';
            }
            if ($cambiaFechas && $conAsientos) {
                $errores[] = 'Las fechas no se cambian: el ejercicio ya tiene asientos.';
            }
            if ($reg['cuit'] !== '' && !cuit_valido($reg['cuit'])) {
                $errores[] = 'El CUIT no es válido.';
            }
            if (!$errores) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $vals = [$reg['nombre'], $desde, $hasta, $reg['cuit'] ?: null, $reg['razon_social'] ?: null, $reg['observaciones'] ?: null];
                    $regenerar = !$id ? $reg['generar']
                        : ($cambiaFechas || !q1('SELECT 1 AS x FROM c_periodos WHERE id_ejercicio = ?', [$id]));
                    if ($id) {
                        q('UPDATE c_ejercicios SET nombre=?, fecha_inicio=?, fecha_fin=?, cuit=?, razon_social=?, observaciones=? WHERE id_ejercicio=?',
                          array_merge($vals, [$id]));
                        if ($regenerar) {
                            q('DELETE FROM c_periodos WHERE id_ejercicio = ?', [$id]);
                        }
                    } else {
                        q('INSERT INTO c_ejercicios (nombre, fecha_inicio, fecha_fin, cuit, razon_social, observaciones, creado_por)
                           VALUES (?,?,?,?,?,?,?)', array_merge($vals, [usuario_id()]));
                        $id = (int) $pdo->lastInsertId();
                        if ($reg['trabajo'] || !q1('SELECT 1 AS x FROM c_ejercicios WHERE activo = 1')) {
                            q('UPDATE c_ejercicios SET activo = 0 WHERE activo = 1');
                            q('UPDATE c_ejercicios SET activo = 1 WHERE id_ejercicio = ?', [$id]);
                        }
                    }
                    $meses = 0;
                    if ($regenerar) {
                        foreach (ctb_meses($desde, $hasta) as [$nom, $a, $b]) {
                            q('INSERT INTO c_periodos (id_ejercicio, nombre, fecha_inicio, fecha_fin) VALUES (?,?,?,?)', [$id, $nom, $a, $b]);
                            $meses++;
                        }
                    }
                    $pdo->commit();
                    flash('ok', $reg['nombre'] . ($actual ? ' guardado' : ' abierto') . ($meses ? ', con ' . $meses . ' períodos mensuales' : '') . '.');
                    redirigir('ejercicios.php?ver=' . $id . '#periodos');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errores[] = mensaje_bd($e);
                }
            }
        }
    } catch (Throwable $e) {
        $errores[] = ctb_falta_script($e);
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$ejercicios = [];
$verEj = null;
$periodos = [];
try {
    if ($id && $reg === null) {
        $e = q1('SELECT * FROM c_ejercicios WHERE id_ejercicio = ?', [$id]);
        if (!$e) {
            flash('danger', 'El ejercicio no existe.');
            redirigir('ejercicios.php');
        }
        $reg = $e + ['generar' => false, 'trabajo' => false];
    } elseif ($reg === null && get_str('nuevo') !== '') {
        // Propone el ejercicio que sigue al último, o el año calendario.
        $ult = q1('SELECT fecha_fin FROM c_ejercicios ORDER BY fecha_fin DESC LIMIT 1');
        $ini = $ult ? (new DateTimeImmutable($ult['fecha_fin']))->modify('+1 day') : new DateTimeImmutable(date('Y') . '-01-01');
        $fin = $ini->modify('+1 year')->modify('-1 day');
        $emp = null;
        try {
            $emp = q1('SELECT razon_social, cuit FROM empresa LIMIT 1');
        } catch (Throwable $e) {
        }
        $reg = ['id_ejercicio' => null, 'nombre' => 'Ejercicio ' . ($fin->format('Y') === $ini->format('Y') ? $ini->format('Y') : $ini->format('Y') . '/' . $fin->format('y')),
                'fecha_inicio' => $ini->format('Y-m-d'), 'fecha_fin' => $fin->format('Y-m-d'),
                'cuit' => $emp['cuit'] ?? '', 'razon_social' => $emp['razon_social'] ?? '', 'observaciones' => '',
                'generar' => true, 'trabajo' => !$ult, 'estado' => 'abierto', 'activo' => 0];
    }
    $ejercicios = q("SELECT e.*,
                            (SELECT COUNT(*) FROM c_periodos p WHERE p.id_ejercicio = e.id_ejercicio) AS periodos,
                            (SELECT COUNT(*) FROM c_periodos p WHERE p.id_ejercicio = e.id_ejercicio AND p.estado = 'abierto') AS abiertos,
                            (SELECT COUNT(*) FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo
                              WHERE p.id_ejercicio = e.id_ejercicio) AS asientos
                       FROM c_ejercicios e ORDER BY e.fecha_inicio DESC");
    $verId = $ver ?: (!$reg && $ejercicios ? (int) (array_values(array_filter($ejercicios, fn ($e) => (int) $e['activo']))[0]['id_ejercicio'] ?? 0) : 0);
    if ($verId) {
        foreach ($ejercicios as $e) {
            if ((int) $e['id_ejercicio'] === $verId) {
                $verEj = $e;
            }
        }
        $periodos = $verEj ? q("SELECT p.*,
                                       (SELECT COUNT(*) FROM c_asientos a WHERE a.id_periodo = p.id_periodo AND a.estado = 'confirmado') AS asientos,
                                       (SELECT COUNT(*) FROM c_asientos a WHERE a.id_periodo = p.id_periodo AND a.estado = 'borrador') AS borradores
                                  FROM c_periodos p WHERE p.id_ejercicio = ? ORDER BY p.fecha_inicio", [$verId]) : [];
    }
} catch (Throwable $e) {
    $errores[] = ctb_falta_script($e);
}

layout_inicio('Ejercicios y períodos');
titulo('Ejercicios y períodos', 'Los ejercicios contables y sus meses: en un período abierto se pueden registrar asientos',
       $reg ? '' : boton('Nuevo ejercicio', 'primary', 'ejercicios.php?nuevo=1', 'agregar'),
       'Contabilidad / Ejercicios y períodos');
mensajes($errores);

if ($reg):
    $esNuevo = !$reg['id_ejercicio'];
    $conAsientos = !$esNuevo && ctb_asientos_ejercicio((int) $reg['id_ejercicio']) > 0;
?>
<form method="post" action="ejercicios.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_ejercicio'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Abrir ejercicio' : $reg['nombre'],
                  $esNuevo ? 'se crean sus períodos mensuales' : ($conAsientos ? 'tiene asientos: las fechas no se cambian' : 'si cambian las fechas, los períodos se vuelven a armar'),
                  boton('Volver', 'secondary', 'ejercicios.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="100" required></div>
        <div class="campo" style="--span:2"><label for="fecha_inicio">Inicio <span class="req">*</span></label>
          <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= h($reg['fecha_inicio']) ?>" required <?= $conAsientos ? 'readonly' : '' ?>></div>
        <div class="campo" style="--span:2"><label for="fecha_fin">Fin <span class="req">*</span></label>
          <input type="date" id="fecha_fin" name="fecha_fin" value="<?= h($reg['fecha_fin']) ?>" required <?= $conAsientos ? 'readonly' : '' ?>></div>
        <div class="campo" style="--span:2"><label for="cuit">CUIT</label>
          <input id="cuit" name="cuit" value="<?= h(cuit_fmt($reg['cuit'] ?? '')) ?>" maxlength="13" class="mono" data-cuit></div>
        <div class="campo" style="--span:2"></div>
        <div class="campo" style="--span:6"><label for="razon_social">Razón social</label>
          <input id="razon_social" name="razon_social" value="<?= h($reg['razon_social'] ?? '') ?>" maxlength="150"></div>
        <div class="campo" style="--span:6"><label for="observaciones">Observaciones</label>
          <input id="observaciones" name="observaciones" value="<?= h($reg['observaciones'] ?? '') ?>" maxlength="500"></div>
      </div>
      <?php if ($esNuevo): ?>
      <div class="campos" style="margin-top:6px">
        <div class="campo" style="--span:4"><label class="campo-check"><input type="checkbox" name="generar" value="1" <?= $reg['generar'] ? 'checked' : '' ?>> Crear los períodos mensuales</label></div>
        <div class="campo" style="--span:4"><label class="campo-check"><input type="checkbox" name="trabajo" value="1" <?= $reg['trabajo'] ? 'checked' : '' ?>> Dejarlo como ejercicio de trabajo</label></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Abrir ejercicio' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Ejercicios') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones">Acciones</th><th>Ejercicio</th><th>Inicio</th><th>Fin</th>
        <th class="th-right">Períodos abiertos</th><th class="th-right">Asientos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($ejercicios as $e): $eid = (int) $e['id_ejercicio']; ?>
        <tr>
          <td><div class="acciones">
            <?= btn_accion('ver', 'ejercicios.php?ver=' . $eid . '#periodos', 'Ver los períodos') ?>
            <?= btn_accion('editar', 'ejercicios.php?edit=' . $eid, 'Editar') ?>
            <?= !(int) $e['activo'] ? accion_post('ejercicios.php', ['accion' => 'activar', 'id' => $eid], 'ok', 'Dejar como ejercicio de trabajo') : '' ?>
            <?= !(int) $e['asientos'] ? accion_post('ejercicios.php', ['accion' => 'borrar', 'id' => $eid], 'borrar', 'Eliminar',
                                                    'Se elimina ' . $e['nombre'] . ' con sus ' . num($e['periodos']) . ' períodos. No tiene asientos.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="ejercicios.php?ver=<?= $eid ?>#periodos"><?= h($e['nombre']) ?></a>
            <?php if ($e['razon_social'] || $e['cuit']): ?><span class="segunda-linea"><?= h(trim(($e['razon_social'] ?? '') . ' ' . cuit_fmt($e['cuit']))) ?></span><?php endif; ?></td>
          <td><?= fecha($e['fecha_inicio']) ?></td>
          <td><?= fecha($e['fecha_fin']) ?></td>
          <td class="td-right"><?= num($e['abiertos']) ?> <span class="td-muted">de <?= num($e['periodos']) ?></span></td>
          <td class="td-right"><?= (int) $e['asientos'] ? num($e['asientos']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= estado(CTB_ESTADOS[$e['estado']], CTB_ESTADOS_TIPO[$e['estado']]) ?>
              <?= (int) $e['activo'] ? estado('De trabajo', 'info') : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ejercicios): ?><tr><td colspan="7" class="no-data">Todavía no hay ejercicios. Abrí el primero con «Nuevo ejercicio».</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($verEj): ?>
<div class="card" id="periodos">
  <?= card_head('Períodos de ' . $verEj['nombre'], fecha($verEj['fecha_inicio']) . ' al ' . fecha($verEj['fecha_fin'])) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="col-acciones">Acciones</th><th>Período</th><th>Desde</th><th>Hasta</th>
        <th class="th-right">Asientos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($periodos as $p): $pid = (int) $p['id_periodo']; ?>
        <tr>
          <td><div class="acciones">
            <?php if ($p['estado'] === 'abierto'): ?>
              <?= accion_post('ejercicios.php', ['accion' => 'cerrar_periodo', 'id_periodo' => $pid], 'cerrar', 'Cerrar',
                              $p['nombre'] . ' deja de admitir asientos. Se puede reabrir.') ?>
            <?php elseif ($p['estado'] === 'cerrado'): ?>
              <?= $verEj['estado'] === 'abierto' ? accion_post('ejercicios.php', ['accion' => 'abrir_periodo', 'id_periodo' => $pid], 'ok', 'Reabrir',
                              $p['nombre'] . ' vuelve a admitir asientos.') : '' ?>
              <?= accion_post('ejercicios.php', ['accion' => 'bloquear_periodo', 'id_periodo' => $pid], 'llave', 'Bloquear',
                              $p['nombre'] . ' queda bloqueado: no admite asientos y no se puede reabrir.', 'borrar') ?>
            <?php endif; ?>
          </div></td>
          <td class="td-fuerte"><?= h($p['nombre']) ?></td>
          <td><?= fecha($p['fecha_inicio']) ?></td>
          <td><?= fecha($p['fecha_fin']) ?></td>
          <td class="td-right"><?= (int) $p['asientos'] ? num($p['asientos']) : '<span class="td-muted">—</span>' ?>
            <?= (int) $p['borradores'] ? '<span class="segunda-linea">' . num($p['borradores']) . ' en borrador</span>' : '' ?></td>
          <td><?= estado(CTB_ESTADOS[$p['estado']], CTB_ESTADOS_TIPO[$p['estado']]) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$periodos): ?><tr><td colspan="6" class="no-data">El ejercicio no tiene períodos. Editalo y guardalo para armar sus períodos mensuales.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
