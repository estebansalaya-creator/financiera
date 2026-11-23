<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Tablas y parámetros › Parámetros del sistema (lo de «Parámetros del
// Sistema» de Reggio que no es de un tipo de asociado):
//   - los parámetros generales (tabla parametros) que se pueden tocar sin
//     riesgo, cada uno con su validación;
//   - las condiciones de refinanciación (refinanciacion_condiciones);
//   - el punitorio de cada tipo de asociado, para ver (se edita en el tipo).
// La cuota social y el punitorio fijo son del tipo de asociado (en
// Reggio estaban acá, uno por banco).
// ---------------------------------------------------------------------

/** Los editables: clave => [etiqueta, tipo, ayuda]. tipo: entero | decimal | pct | importe | opciones:A,B */
const PARAMETROS_EDITABLES = [
    'DIAS_GRACIA_MORA'           => ['Días de gracia', 'entero', 'Días después del vencimiento antes de cobrar punitorios.'],
    'PUNITORIO_PCT_TNA'          => ['Punitorio por tasa: % de la TNA', 'pct', 'Si la tasa del tipo no tiene punitoria: punitoria = TNA × este %. Solo para los tipos con punitorio por tasa.'],
    'PUNITORIO_BASE'             => ['Punitorio por tasa: base', 'opciones:CAPITAL,CUOTA', 'CAPITAL: el capital impago de la cuota. CUOTA: capital + interés + IVA impagos.'],
    'CUOTA_MAXIMA'               => ['Cuota máxima', 'importe', 'Valor máximo de la cuota de un préstamo; 0 = sin tope. Pasarse es una advertencia en los controles de la solicitud.'],
    'CONTROL_CUOTA_INGRESO_MAX'  => ['Cuotas / ingreso máximo', 'pct', 'Advierte si las cuotas vigentes más la nueva superan este % del ingreso mensual.'],
    'CONTROL_BCRA_DIAS'          => ['BCRA: antigüedad máxima de la consulta', 'entero', 'Días. Si la última consulta es más vieja, se consulta de nuevo al liquidar.'],
    'CONTROL_BCRA_SITUACION_MAX' => ['BCRA: situación máxima', 'entero', 'Advierte si la peor situación actual es mayor a este valor (1 a 5).'],
    'CLAWBACK_CUOTAS'            => ['Reversión de comisión', 'entero', 'Si el préstamo cae en mora antes de esta cuota, se revierte la comisión del comercializador.'],
];

$errores = [];
$tieneRefi = true;
try {
    q('SELECT 1 FROM refinanciacion_condiciones LIMIT 1');
} catch (PDOException $e) {
    $tieneRefi = false;
}

/** El valor guardado -> lo que se muestra en el campo. */
function param_campo(string $tipo, string $v): string
{
    if ($tipo === 'pct') {
        return rtrim(rtrim(number_format((float) $v * 100, 4, ',', ''), '0'), ',');
    }
    if ($tipo === 'importe') {
        return number_format((float) $v, 2, ',', '.');
    }
    return $v;
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        if ($accion === 'guardar_parametros') {
            $nuevos = [];
            foreach (PARAMETROS_EDITABLES as $clave => [$etq, $tipo]) {
                $txt = trim(post_str('p_' . $clave));
                if (strncmp($tipo, 'opciones:', 9) === 0) {
                    if (!in_array($txt, explode(',', substr($tipo, 9)), true)) {
                        $errores[] = $etq . ': elegí una de las opciones.';
                    }
                    $nuevos[$clave] = $txt;
                    continue;
                }
                $n = leer_num($txt);
                if ($n === null || $n < 0) {
                    $errores[] = $etq . ': tiene que ser un número mayor o igual a cero.';
                    continue;
                }
                if ($tipo === 'entero') {
                    if (floor($n) != $n) {
                        $errores[] = $etq . ': va un número entero.';
                    }
                    $nuevos[$clave] = (string) (int) $n;
                } elseif ($tipo === 'pct') {
                    if ($n > 1000) {
                        $errores[] = $etq . ': va en %.';
                    }
                    $nuevos[$clave] = rtrim(rtrim(number_format($n / 100, 6, '.', ''), '0'), '.');
                } else {
                    $nuevos[$clave] = number_format($n, 2, '.', '');
                }
            }
            if (isset($nuevos['CONTROL_BCRA_SITUACION_MAX']) && ((int) $nuevos['CONTROL_BCRA_SITUACION_MAX'] < 1 || (int) $nuevos['CONTROL_BCRA_SITUACION_MAX'] > 5)) {
                $errores[] = 'BCRA: la situación máxima va de 1 a 5.';
            }
            if (!$errores) {
                foreach ($nuevos as $clave => $valor) {
                    q('UPDATE parametros SET valor = ? WHERE clave = ?', [$valor, $clave]);
                    if ((int) db()->query('SELECT ROW_COUNT()')->fetchColumn() === 0 && !q1('SELECT 1 AS x FROM parametros WHERE clave = ?', [$clave])) {
                        q('INSERT INTO parametros (clave, valor) VALUES (?, ?)', [$clave, $valor]);
                    }
                }
                flash('ok', 'Parámetros guardados.');
                redirigir('parametros.php');
            }
        } elseif ($accion === 'refi_guardar' && $tieneRefi) {
            $plazo = leer_num(post_str('plazo'));
            $pagas = leer_num(post_str('cuotas_pagas'));
            if ($plazo === null || $plazo < 1 || $plazo > 360 || floor($plazo) != $plazo) {
                $errores[] = 'El plazo va en cuotas enteras (1 a 360).';
            } elseif ($pagas === null || $pagas < 0 || $pagas > $plazo || floor($pagas) != $pagas) {
                $errores[] = 'Las cuotas pagas van de 0 hasta el plazo.';
            } else {
                q('INSERT INTO refinanciacion_condiciones (plazo, cuotas_pagas, modificado_por) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE cuotas_pagas = VALUES(cuotas_pagas), modificado_por = VALUES(modificado_por)',
                  [(int) $plazo, (int) $pagas, usuario_id()]);
                flash('ok', 'Préstamos de ' . (int) $plazo . ' cuotas: refinancian con ' . (int) $pagas . ' pagas.');
                redirigir('parametros.php#refinanciacion');
            }
        } elseif ($accion === 'refi_borrar' && $tieneRefi) {
            q('DELETE FROM refinanciacion_condiciones WHERE plazo = ?', [(int) post_str('plazo')]);
            flash('ok', 'Condición eliminada.');
            redirigir('parametros.php#refinanciacion');
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$valores = array_column(q('SELECT clave, valor FROM parametros'), 'valor', 'clave');
$refi = $tieneRefi ? q('SELECT r.*, u.usuario FROM refinanciacion_condiciones r LEFT JOIN usuarios u ON u.id_usuario = r.modificado_por ORDER BY r.plazo') : [];
$conPun = columna_existe('tipos_asociado', 'punitorio_modo');
$tipos = $conPun ? q('SELECT id_tipo_asociado, nombre, punitorio_modo, punitorio_fijo, activo FROM tipos_asociado ORDER BY activo DESC, nombre') : [];

layout_inicio('Parámetros del sistema');
titulo('Parámetros del sistema', 'Punitorios, controles, cuota máxima y refinanciación', '', 'Tablas y parámetros / Parámetros del sistema');
mensajes($errores);
?>

<div class="card">
  <?= card_head('Generales') ?>
  <form method="post" action="parametros.php" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar_parametros">
    <div class="campos">
    <?php foreach (PARAMETROS_EDITABLES as $clave => [$etq, $tipo, $ayuda]):
        $v = es_post() && post_str('accion') === 'guardar_parametros' ? post_str('p_' . $clave) : param_campo($tipo, (string) ($valores[$clave] ?? '0')); ?>
      <div class="campo" style="--span:4">
        <label for="p_<?= h($clave) ?>"><?= h($etq) ?></label>
        <?php if (strncmp($tipo, 'opciones:', 9) === 0): ?>
          <select id="p_<?= h($clave) ?>" name="p_<?= h($clave) ?>"><?= opciones(array_combine(explode(',', substr($tipo, 9)), explode(',', substr($tipo, 9))), $v) ?></select>
        <?php else: ?>
          <input id="p_<?= h($clave) ?>" name="p_<?= h($clave) ?>" value="<?= h($v) ?>" inputmode="decimal" class="derecha">
        <?php endif; ?>
        <span class="campo-ayuda"><?= h($ayuda) ?><?= $tipo === 'pct' ? ' (en %)' : '' ?></span>
      </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:8px"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </form>
</div>

<?php if ($tieneRefi): ?>
<div class="card" id="refinanciacion">
  <?= card_head('Condiciones de refinanciación', 'cuántas cuotas pagas necesita un préstamo, según su plazo, para refinanciarse') ?>
  <form method="post" action="parametros.php" class="bloque">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="refi_guardar">
    <div class="campos">
      <div class="campo" style="--span:2"><label for="plazo">Plazo (cuotas)</label><input id="plazo" name="plazo" inputmode="numeric" class="derecha" required></div>
      <div class="campo" style="--span:2"><label for="cuotas_pagas">Cuotas pagas</label><input id="cuotas_pagas" name="cuotas_pagas" inputmode="numeric" class="derecha" required></div>
      <div class="campo campo-boton" style="--span:2"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
    </div>
    <span class="campo-ayuda">Un plazo que ya está se reemplaza. Un plazo que no está en la lista toma la condición del plazo menor más cercano; si no hay ninguno menor, no se puede refinanciar.</span>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th class="th-right">Plazo</th><th class="th-right">Cuotas pagas</th><th>Modificado</th></tr></thead>
      <tbody>
      <?php if (!$refi): ?><tr><td colspan="4" class="no-data">Sin condiciones: no se controla.</td></tr><?php endif; ?>
      <?php foreach ($refi as $r): ?>
        <tr><td><div class="acciones"><?= accion_post('parametros.php', ['accion' => 'refi_borrar', 'plazo' => (int) $r['plazo']], 'borrar', 'Eliminar',
                                                          'Se elimina la condición de ' . (int) $r['plazo'] . ' cuotas.', 'borrar') ?></div></td>
          <td class="td-right td-fuerte"><?= (int) $r['plazo'] ?> cuotas</td><td class="td-right"><?= (int) $r['cuotas_pagas'] ?></td>
          <td class="td-muted"><?= h(fecha(substr((string) $r['modificado_el'], 0, 10))) ?><?= $r['usuario'] ? ' · ' . h($r['usuario']) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($conPun): ?>
<div class="card">
  <?= card_head('Punitorio por tipo de asociado', 'se cambia en la ficha de cada tipo (Datos)') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="tipo|tipos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Tipo de asociado</th><th>Punitorio</th></tr></thead>
      <tbody>
      <?php foreach ($tipos as $t): ?>
        <tr class="<?= (int) $t['activo'] ? '' : 'fila-baja' ?>"><td><div class="acciones"><?= btn_accion('editar', 'tipos_asociado.php?id=' . (int) $t['id_tipo_asociado'], 'Editar el tipo') ?></div></td>
          <td class="td-fuerte"><?= h($t['nombre']) ?></td>
          <td><?= $t['punitorio_modo'] === 'FIJO' ? money($t['punitorio_fijo']) . ' por mes o fracción de atraso, IVA incluido'
                                                  : 'Por tasa (' . h(param_campo('pct', (string) ($valores['PUNITORIO_PCT_TNA'] ?? '0.5'))) . ' % de la TNA si la tasa no define otra)' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>
<?php layout_fin(); ?>
