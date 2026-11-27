<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/gastos.php';
require __DIR__ . '/../inc/contable.php';

// ---------------------------------------------------------------------
// Catálogo de conceptos de gasto (sql/financiera_gastos.sql)
//   gastos_conceptos.php            listado
//   gastos_conceptos.php?nuevo=1    alta
//   gastos_conceptos.php?edit=N     edición
// El concepto tiene el nombre, el IVA y los valores SUGERIDOS. Qué cobra
// cada tipo de asociado, y con qué valores, se carga en la solapa Gastos
// del tipo (tipos_asociado.php?id=N&solapa=gastos): dos tipos pueden
// cobrar el mismo concepto con distinto % o importe.
// Un concepto ya cobrado en algún préstamo no se elimina: se desactiva.
// Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));

$fEstado = in_array(get_str('estado'), ['0', 'todos'], true) ? get_str('estado') : '1';
$lista = 'gastos_conceptos.php' . ($fEstado !== '1' ? '?estado=' . $fEstado : '');

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $actual = $id ? q1('SELECT * FROM gastos_conceptos WHERE id_concepto_gasto = ?', [$id]) : null;
    if ($id && !$actual) {
        flash('danger', 'El concepto ya no existe.');
        redirigir('gastos_conceptos.php');
    }
    if (in_array($accion, ['activar', 'desactivar'], true) && $actual) {
        q('UPDATE gastos_conceptos SET activo = ? WHERE id_concepto_gasto = ?', [$accion === 'activar' ? 1 : 0, $id]);
        flash('ok', '«' . $actual['nombre'] . '» ' . ($accion === 'activar'
            ? 'vuelve a cobrarse en los tipos de asociado que lo tienen.'
            : 'deja de cobrarse en todos los tipos de asociado (sus valores quedan guardados). Los préstamos ya liquidados no cambian.'));
        redirigir($lista);
    }
    if ($accion === 'borrar' && $actual) {
        if ((int) q1('SELECT COUNT(*) AS n FROM prestamos_gastos WHERE id_concepto_gasto = ?', [$id])['n']) {
            flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: ya se cobró en algún préstamo. Desactivalo.');
        } else {
            q('DELETE FROM gastos_conceptos WHERE id_concepto_gasto = ?', [$id]);   // se va de los tipos (ON DELETE CASCADE)
            flash('ok', '«' . $actual['nombre'] . '» eliminado.');
        }
        redirigir($lista);
    }
    if ($accion === 'guardar') {
        [$txt, $val, $errores] = gc_valores_post();
        $reg = $txt + ['id_concepto_gasto' => $id ?: null, 'codigo' => $actual['codigo'] ?? '',
                       'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))),
                       'lleva_iva' => post_str('lleva_iva') === '1' ? 1 : 0, 'iva_txt' => post_str('alicuota_iva'),
                       'orden' => post_str('orden') ?: '500', 'activo' => post_str('activo') === '1' ? 1 : 0,
                       'id_cuenta_contable' => post_str('id_cuenta_contable')];
        $iva = leer_num($reg['iva_txt']);
        if ($reg['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        } elseif (mb_strlen($reg['nombre']) > 100) {
            $errores[] = 'El nombre va hasta 100 caracteres.';
        }
        if ($reg['lleva_iva'] && ($iva === null || $iva <= 0 || $iva > 100)) {
            $errores[] = 'La alícuota de IVA va en %, entre 0 y 100 (normalmente 21).';
        }
        if (!preg_match('/^-?\d{1,4}$/', $reg['orden'])) {
            $errores[] = 'El orden es un número.';
        }
        $conCtb = ctb_imputaciones_instaladas();
        if ($conCtb && ($e = ctb_error_cuenta_imputable($reg['id_cuenta_contable'])) !== null) {
            $errores[] = $e;
        }
        if (!$errores) {
            $ali = $reg['lleva_iva'] ? round($iva / 100, 4) : 0.21;
            $datos = [$reg['nombre'], $val['tipo_calculo'], $val['valor'], $val['importe_minimo'], $val['importe_maximo'],
                      $val['forma_cobro'], $reg['lleva_iva'], $ali, (int) $reg['orden'], $reg['activo']];
            try {
                if ($id) {
                    q('UPDATE gastos_conceptos SET nombre=?, tipo_calculo=?, valor=?, importe_minimo=?, importe_maximo=?, forma_cobro=?,
                              lleva_iva=?, alicuota_iva=?, orden=?, activo=? WHERE id_concepto_gasto=?', array_merge($datos, [$id]));
                    flash('ok', '«' . $reg['nombre'] . '» guardado. Los valores sugeridos no cambian lo que ya cobra cada tipo de asociado.');
                } else {
                    $cod = gc_codigo_de($reg['nombre']);
                    $base = substr($cod, 0, 26);
                    for ($i = 2; q1('SELECT 1 AS x FROM gastos_conceptos WHERE codigo = ?', [$cod]); $i++) {
                        $cod = $base . '_' . $i;
                    }
                    q('INSERT INTO gastos_conceptos (nombre, tipo_calculo, valor, importe_minimo, importe_maximo, forma_cobro,
                                                    lleva_iva, alicuota_iva, orden, activo, codigo, creado_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($datos, [$cod, usuario_id()]));
                    flash('ok', '«' . $reg['nombre'] . '» agregado al catálogo. Para cobrarlo, agregalo en la solapa Gastos de cada tipo de asociado.');
                }
                if ($conCtb) {
                    q('UPDATE gastos_conceptos SET id_cuenta_contable = ? WHERE ' . ($id ? 'id_concepto_gasto = ?' : 'codigo = ?'),
                      [$reg['id_cuenta_contable'] !== '' ? (int) $reg['id_cuenta_contable'] : null, $id ?: $cod]);
                }
                redirigir($lista);
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$conceptos = [];
$usoTipos = [];      // id_concepto => [tipos que lo cobran, con su valor]
try {
    if ($id && $reg === null) {
        $g = q1('SELECT * FROM gastos_conceptos WHERE id_concepto_gasto = ?', [$id]);
        if (!$g) {
            flash('danger', 'El concepto no existe.');
            redirigir('gastos_conceptos.php');
        }
        $reg = gc_valores_campos($g) + $g + ['iva_txt' => gc_pct_campo($g['alicuota_iva'])];
    } elseif ($reg === null && get_str('nuevo') !== '') {
        $reg = ['id_concepto_gasto' => null, 'codigo' => '', 'nombre' => '', 'tipo_calculo' => 'PORCENTAJE', 'valor_txt' => '',
                'minimo_txt' => '', 'maximo_txt' => '', 'forma_cobro' => 'DESCUENTO', 'lleva_iva' => 0, 'iva_txt' => '21',
                'orden' => '500', 'activo' => 1, 'id_cuenta_contable' => ''];
    }
    $conceptos = q('SELECT g.*, (SELECT COUNT(*) FROM prestamos_gastos pg WHERE pg.id_concepto_gasto = g.id_concepto_gasto) AS usos
                      FROM gastos_conceptos g' . ($fEstado !== 'todos' ? ' WHERE g.activo = ' . (int) $fEstado : '') . '
                     ORDER BY g.activo DESC, g.orden, g.nombre');
    foreach (q('SELECT gt.*, t.codigo AS tipo_codigo, t.id_tipo_asociado FROM gastos_conceptos_tipos gt
                  JOIN tipos_asociado t ON t.id_tipo_asociado = gt.id_tipo_asociado
                 WHERE gt.activo = 1 ORDER BY t.codigo') as $u) {
        $usoTipos[(int) $u['id_concepto_gasto']][] = $u;
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de conceptos de gasto: corré sql\financiera_gastos.sql.' : error_amigable($e);
}

layout_inicio('Conceptos de gasto');
titulo('Conceptos de gasto', 'El catálogo de gastos que se le pueden cobrar al cliente; cada tipo de asociado elige cuáles cobra y con qué valores',
       $reg ? '' : boton('Nuevo concepto', 'primary', 'gastos_conceptos.php?nuevo=1', 'agregar'),
       '<a href="tipos_asociado.php">Tipos de asociado y tasas</a> / Conceptos de gasto');
mensajes($errores);

if ($reg):
    $esNuevo = !$reg['id_concepto_gasto'];
?>
<form method="post" action="gastos_conceptos.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_concepto_gasto'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo concepto de gasto' : $reg['nombre'], $esNuevo ? 'el código se arma solo con el nombre' : 'código ' . $reg['codigo'],
                  boton('Volver', 'secondary', $lista, 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="nombre">Concepto <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="100" required placeholder="Sellado provincial"></div>
        <div class="campo" style="--span:2"><label for="lleva_iva">IVA</label>
          <select id="lleva_iva" name="lleva_iva" onchange="document.getElementById('campo-alicuota').hidden = this.value !== '1'"><?= opciones(['0' => 'No lleva', '1' => 'Lleva IVA'], (int) $reg['lleva_iva'] ? '1' : '0') ?></select></div>
        <div class="campo" id="campo-alicuota" style="--span:2"<?= (int) $reg['lleva_iva'] ? '' : ' hidden' ?>><label for="alicuota_iva">Alícuota</label>
          <input id="alicuota_iva" name="alicuota_iva" value="<?= h($reg['iva_txt']) ?>" inputmode="decimal" class="derecha">
          <small class="campo-ayuda">%</small></div>
        <div class="campo" style="--span:1"><label for="orden">Orden</label>
          <input id="orden" name="orden" value="<?= h((string) $reg['orden']) ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Activo</label></div>
      </div>
      <?php if (ctb_imputaciones_instaladas()): ?>
      <div class="campos" style="margin-top:6px">
        <div class="campo" style="--span:6"><label for="id_cuenta_contable">Cuenta contable</label>
          <?= ctb_select_cuenta('id_cuenta_contable', ctb_cuentas_imputables(), $reg['id_cuenta_contable'] ?? '', 'Sin asignar') ?>
          <span class="campo-ayuda">el ingreso por este gasto en el asiento de la liquidación (el IVA va a la cuenta de IVA débito de Imputaciones contables)</span></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="bloque">
      <div class="bloque-titulo">Valores sugeridos</div>
      <div class="campos"><?= gc_valores_html($reg) ?></div>
      <p class="campo-ayuda" style="margin:8px 0 0">
        Se proponen al agregar el concepto a un tipo de asociado; cada tipo puede cambiarlos (otro %, otro importe, otra forma de cobro).
        Cambiarlos acá no toca lo que ya cobra cada tipo.
      </p>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?= gc_valores_js() ?>
<?php endif; ?>

<div class="card">
  <?= card_head('Conceptos') ?>
  <?= barra_local('gastos_conceptos.php', 'Concepto, código o tipo…',
                  filtro_select('estado', 'Estado', ['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'], $fEstado, '', $fEstado !== '1'),
                  $fEstado !== '1') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="concepto|conceptos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Concepto</th><th class="th-right">IVA</th>
        <th>Lo cobran (con su valor)</th><th class="th-right">Sugerido</th><th class="th-right">Préstamos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($conceptos as $g): $gid = (int) $g['id_concepto_gasto']; $us = $usoTipos[$gid] ?? []; ?>
        <tr class="<?= (int) $g['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'gastos_conceptos.php?edit=' . $gid, 'Editar') ?>
            <?= (int) $g['activo']
                  ? accion_post($lista, ['accion' => 'desactivar', 'id' => $gid], 'cerrar', 'Desactivar', '«' . $g['nombre'] . '» deja de cobrarse en todos los tipos de asociado. Sus valores quedan guardados.', 'borrar')
                  : accion_post($lista, ['accion' => 'activar', 'id' => $gid], 'ok', 'Activar') ?>
            <?= !(int) $g['usos'] ? accion_post($lista, ['accion' => 'borrar', 'id' => $gid], 'borrar', 'Eliminar',
                                                 'Se elimina «' . $g['nombre'] . '»' . ($us ? ' y se quita de ' . count($us) . (count($us) === 1 ? ' tipo de asociado' : ' tipos de asociado') : '') . '. No se cobró en ningún préstamo.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="gastos_conceptos.php?edit=<?= $gid ?>"><?= h($g['nombre']) ?></a><span class="segunda-linea mono"><?= h($g['codigo']) ?></span>
            <?php if (ctb_imputaciones_instaladas()): ?><span class="segunda-linea"><?= !empty($g['id_cuenta_contable'])
                  ? 'contable ' . h(ctb_cuenta_txt($g['id_cuenta_contable'])) : '<span class="texto-error">sin cuenta contable</span>' ?></span><?php endif; ?></td>
          <td class="td-right"><?= (int) $g['lleva_iva'] ? pct($g['alicuota_iva']) : '<span class="td-muted">no</span>' ?></td>
          <td data-orden="<?= count($us) ?>">
            <?php if (!$us): ?><span class="td-muted">ningún tipo</span><?php endif; ?>
            <?php foreach ($us as $u): ?>
              <a href="tipos_asociado.php?id=<?= (int) $u['id_tipo_asociado'] ?>&amp;solapa=gastos" class="mono"><?= h($u['tipo_codigo']) ?></a>
              <?= h(gc_valor_txt($u)) ?><?= $u['forma_cobro'] === 'FINANCIADO' ? ' <span class="td-muted">en cuotas</span>' : '' ?><br>
            <?php endforeach; ?>
          </td>
          <td class="td-right td-muted" data-orden="<?= h($g['valor']) ?>"><?= h(gc_valor_txt($g)) ?></td>
          <td class="td-right" data-orden="<?= (int) $g['usos'] ?>"><?= (int) $g['usos'] ? num($g['usos']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $g['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$conceptos): ?><tr><td colspan="7" class="no-data"><?= $fEstado !== '1' ? 'Ningún concepto con ese estado.' : 'Todavía no hay conceptos de gasto. Cargá el primero con «Nuevo concepto».' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
  <p class="campo-ayuda" style="margin:8px 16px 14px">Qué gastos cobra cada tipo de asociado, y con qué valores, se carga en la solapa <strong>Gastos</strong> de <a href="tipos_asociado.php">Tipos de asociado y tasas</a>.</p>
</div>

<?php layout_fin(); ?>
