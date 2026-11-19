<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Productos y tasas (configuración)
//   productos.php            listado
//   productos.php?nuevo=1    alta
//   productos.php?id=N       ficha: datos del producto y sus tasas
// Las tasas no se editan: se carga una nueva con su vigencia y la anterior
// queda como historial (los préstamos guardan la tasa con la que se
// liquidaron). Una tasa se puede borrar solo si ningún préstamo la usó.
// Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

const DESTINATARIOS = ['AMBOS' => 'Personas y empresas', 'F' => 'Personas físicas', 'J' => 'Empresas'];

$errores = [];
$id      = (int) get_str('id');
$nuevo   = get_str('nuevo') === '1';

/** Fracción guardada (0.85) -> texto para el campo («85»). */
function pct_campo($f): string
{
    if ($f === null || $f === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $f * 100, 4, ',', ''), '0'), ',');
}

function monto_campo($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
$form = null;   // lo cargado, para volver a mostrarlo si hay errores
if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'guardar_producto') {
        $form = [
            'codigo'          => strtoupper(post_str('codigo')),
            'nombre'          => post_str('nombre'),
            'destinatario'    => post_str('destinatario'),
            'plazo_min'       => post_str('plazo_min'),
            'plazo_max'       => post_str('plazo_max'),
            'monto_min'       => post_str('monto_min'),
            'monto_max'       => post_str('monto_max'),
            'alicuota_iva'    => post_str('alicuota_iva'),
            'gasto_otorg_pct' => post_str('gasto_otorg_pct'),
            'activo'          => isset($_POST['activo']) ? 1 : 0,
        ];
        $pmin = leer_num($form['plazo_min']);
        $pmax = leer_num($form['plazo_max']);
        $mmin = leer_num($form['monto_min']) ?? 0.0;
        $mmax = leer_num($form['monto_max']);
        $iva  = leer_num($form['alicuota_iva']);
        $gst  = leer_num($form['gasto_otorg_pct']) ?? 0.0;

        if (!preg_match('/^[A-Z0-9_]{1,20}$/', $form['codigo'])) {
            $errores[] = 'El código va en mayúsculas, sin espacios, hasta 20 caracteres (letras, números y _).';
        }
        if ($form['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        }
        if (!isset(DESTINATARIOS[$form['destinatario']])) {
            $errores[] = 'Elegí a quién va dirigido.';
        }
        if ($pmin === null || $pmax === null || $pmin < 1 || $pmax < $pmin || $pmax > 360 || floor($pmin) != $pmin || floor($pmax) != $pmax) {
            $errores[] = 'El plazo va en cuotas enteras: mínimo 1, máximo hasta 360 y no menor que el mínimo.';
        }
        if ($mmax === null || $mmin < 0 || $mmax < $mmin) {
            $errores[] = 'El monto máximo tiene que ser mayor o igual que el mínimo.';
        }
        if ($iva === null || $iva < 0 || $iva > 100) {
            $errores[] = 'El IVA sobre intereses va en %, entre 0 y 100 (normalmente 21).';
        }
        if ($gst < 0 || $gst > 100) {
            $errores[] = 'Los gastos de otorgamiento van en %, entre 0 y 100.';
        }

        if (!$errores) {
            $datos = [$form['codigo'], $form['nombre'], $form['destinatario'], (int) $pmin, (int) $pmax,
                      round($mmin, 2), round($mmax, 2), round($iva / 100, 4), round($gst / 100, 4), $form['activo']];
            try {
                if ($id > 0) {
                    q('UPDATE productos SET codigo=?, nombre=?, destinatario=?, plazo_min=?, plazo_max=?, monto_min=?,
                              monto_max=?, alicuota_iva=?, gasto_otorg_pct=?, activo=? WHERE id_producto=?',
                      array_merge($datos, [$id]));
                    flash('ok', 'Producto ' . $form['codigo'] . ' guardado.');
                } else {
                    q("INSERT INTO productos (codigo, nombre, destinatario, plazo_min, plazo_max, monto_min, monto_max,
                                             alicuota_iva, gasto_otorg_pct, activo, sistema_amort)
                       VALUES (?,?,?,?,?,?,?,?,?,?,'FRANCES')", $datos);
                    $id = (int) db()->lastInsertId();
                    flash('ok', 'Producto ' . $form['codigo'] . ' creado. Ahora cargale las tasas.');
                }
                redirigir('productos.php?id=' . $id);
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e, ['uq_productos_codigo' => 'Ya hay un producto con el código ' . $form['codigo'] . '.']);
            }
        }
    } elseif ($accion === 'agregar_tasa' && $id > 0) {
        $vig  = leer_fecha(post_str('vigencia_desde'));
        $pd   = leer_num(post_str('plazo_desde'));
        $ph   = leer_num(post_str('plazo_hasta'));
        $md   = leer_num(post_str('monto_desde')) ?? 0.0;
        $mh   = leer_num(post_str('monto_hasta'));
        $tna  = leer_num(post_str('tna'));
        $pun  = post_str('tasa_punitoria') === '' ? null : leer_num(post_str('tasa_punitoria'));
        if ($vig === null) {
            $errores[] = 'Falta la fecha desde la que rige la tasa.';
        }
        if ($pd === null || $ph === null || $pd < 1 || $ph < $pd || floor($pd) != $pd || floor($ph) != $ph) {
            $errores[] = 'El rango de plazos va en cuotas enteras y el «hasta» no puede ser menor que el «desde».';
        }
        if ($mh === null || $md < 0 || $mh < $md) {
            $errores[] = 'El rango de montos no es válido.';
        }
        if ($tna === null || $tna < 0 || $tna >= 1000) {
            $errores[] = 'La TNA va en % (por ejemplo 85 para 85 %).';
        }
        if (post_str('tasa_punitoria') !== '' && ($pun === null || $pun < 0 || $pun >= 1000)) {
            $errores[] = 'La tasa punitoria va en % de TNA (o vacía para usar el parámetro general).';
        }
        if (!$errores) {
            try {
                q('INSERT INTO tasas_vigencia (id_producto, vigencia_desde, plazo_desde, plazo_hasta, monto_desde, monto_hasta, tna, tasa_punitoria, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?)',
                  [$id, $vig, (int) $pd, (int) $ph, round($md, 2), round($mh, 2), round($tna / 100, 6),
                   $pun === null ? null : round($pun / 100, 6), usuario_id()]);
                flash('ok', 'Tasa agregada: TNA ' . pct($tna / 100) . ' desde el ' . fecha($vig) . '.');
                redirigir('productos.php?id=' . $id);
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
            }
        }
    } elseif ($accion === 'borrar_tasa' && $id > 0) {
        $idTasa = (int) post_str('id_tasa');
        try {
            $usos = (int) (q1('SELECT COUNT(*) AS n FROM prestamos WHERE id_tasa = ?', [$idTasa])['n'] ?? 0);
            if ($usos > 0) {
                flash('warning', 'No se puede borrar: la usan ' . $usos . ' préstamos. Cargá una tasa nueva con otra vigencia.');
            } else {
                q('DELETE FROM tasas_vigencia WHERE id_tasa = ? AND id_producto = ?', [$idTasa, $id]);
                flash('ok', 'Tasa borrada.');
            }
        } catch (Throwable $e) {
            flash('danger', mensaje_bd($e));
        }
        redirigir('productos.php?id=' . $id);
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$producto = null;
$tasas    = [];
$lista    = [];
$puniPct  = 0.5;
try {
    $puniPct = (float) (q1("SELECT valor FROM parametros WHERE clave = 'PUNITORIO_PCT_TNA'")['valor'] ?? 0.5);
    if ($id > 0) {
        $producto = q1('SELECT * FROM productos WHERE id_producto = ?', [$id]);
        if ($producto) {
            $tasas = q(
                'SELECT t.*, (SELECT COUNT(*) FROM prestamos p WHERE p.id_tasa = t.id_tasa) AS usos
                   FROM tasas_vigencia t
                  WHERE t.id_producto = ?
                  ORDER BY t.vigencia_desde DESC, t.plazo_desde, t.monto_desde, t.id_tasa DESC',
                [$id]
            );
        }
    } elseif (!$nuevo) {
        $lista = q(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM tasas_vigencia t WHERE t.id_producto = p.id_producto) AS n_tasas,
                    (SELECT COUNT(*) FROM prestamos pr WHERE pr.id_producto = p.id_producto) AS n_prestamos,
                    v.tna_min, v.tna_max, v.desde
               FROM productos p
               LEFT JOIN (SELECT t.id_producto, MIN(t.tna) AS tna_min, MAX(t.tna) AS tna_max, t.vigencia_desde AS desde
                            FROM tasas_vigencia t
                            JOIN (SELECT id_producto, MAX(vigencia_desde) AS vd FROM tasas_vigencia
                                   WHERE vigencia_desde <= CURDATE() GROUP BY id_producto) u
                              ON u.id_producto = t.id_producto AND u.vd = t.vigencia_desde
                           GROUP BY t.id_producto, t.vigencia_desde) v ON v.id_producto = p.id_producto
              ORDER BY p.activo DESC, p.nombre"
        );
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
if ($id > 0 && !$producto && !$errores) {
    http_response_code(404);
}
layout_inicio('Productos y tasas', 'productos');

if ($id > 0 && !$producto && !$errores) {
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese producto</h1><p>Puede que lo hayan borrado.</p>'
       . boton('Volver a productos', 'primary', 'productos.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

// ── Listado ────────────────────────────────────────────────────────────
if ($id === 0 && !$nuevo) {
    titulo('Productos y tasas', 'Líneas de préstamo con sus límites y sus tasas por vigencia, plazo y monto',
           boton('Nuevo producto', 'primary', 'productos.php?nuevo=1', 'agregar'));
    mensajes($errores);
    $sinTasa = count(array_filter($lista, function ($p) { return $p['activo'] && !$p['n_tasas']; }));
    if ($sinTasa) {
        alerta($sinTasa . ' productos activos no tienen tasa cargada: no se pueden simular ni liquidar hasta cargarles una.', 'warning');
    }
    ?>
    <div class="card">
      <?= card_head('Productos', count($lista) . ' productos') ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="col-acciones" data-no-sort>Acciones</th>
              <th>Código</th>
              <th>Nombre</th>
              <th>Para</th>
              <th class="th-right">Plazo</th>
              <th class="th-right">Monto</th>
              <th class="th-right">IVA s/int.</th>
              <th class="th-right">Gastos</th>
              <th class="th-right">TNA vigente</th>
              <th class="th-right">Préstamos</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$lista): ?>
            <tr><td colspan="11" class="no-data">Todavía no hay productos.</td></tr>
          <?php endif; ?>
          <?php foreach ($lista as $p): ?>
            <tr>
              <td><div class="acciones"><?= btn_accion('editar', 'productos.php?id=' . $p['id_producto'], 'Editar el producto y sus tasas') ?></div></td>
              <td class="td-fuerte"><?= h($p['codigo']) ?></td>
              <td><?= h($p['nombre']) ?></td>
              <td><?= h(DESTINATARIOS[$p['destinatario']] ?? $p['destinatario']) ?></td>
              <td class="td-right" data-orden="<?= h($p['plazo_max']) ?>"><?= h($p['plazo_min']) ?> a <?= h($p['plazo_max']) ?> cuotas</td>
              <td class="td-right" data-orden="<?= h($p['monto_max']) ?>"><?= money($p['monto_min']) ?> a <?= money($p['monto_max']) ?></td>
              <td class="td-right" data-orden="<?= h($p['alicuota_iva']) ?>"><?= pct($p['alicuota_iva']) ?></td>
              <td class="td-right" data-orden="<?= h($p['gasto_otorg_pct']) ?>"><?= pct($p['gasto_otorg_pct']) ?></td>
              <td class="td-right" data-orden="<?= h($p['tna_max'] ?? -1) ?>">
                <?php if ($p['tna_min'] === null): ?>
                  <?= estado($p['n_tasas'] ? 'Rige más adelante' : 'Sin tasa', $p['activo'] ? 'warn' : 'neutro') ?>
                <?php else: ?>
                  <?= $p['tna_min'] === $p['tna_max'] ? pct($p['tna_min']) : pct($p['tna_min']) . ' a ' . pct($p['tna_max']) ?>
                  <span class="segunda-linea">desde el <?= h(fecha($p['desde'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="td-right" data-orden="<?= h($p['n_prestamos']) ?>"><?= num($p['n_prestamos']) ?></td>
              <td data-orden="<?= (int) $p['activo'] ?>"><?= $p['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    layout_fin();
    exit;
}

// ── Ficha (alta o edición) ─────────────────────────────────────────────
$v = $form ?? ($producto ? [
    'codigo' => $producto['codigo'], 'nombre' => $producto['nombre'], 'destinatario' => $producto['destinatario'],
    'plazo_min' => $producto['plazo_min'], 'plazo_max' => $producto['plazo_max'],
    'monto_min' => monto_campo($producto['monto_min']), 'monto_max' => monto_campo($producto['monto_max']),
    'alicuota_iva' => pct_campo($producto['alicuota_iva']), 'gasto_otorg_pct' => pct_campo($producto['gasto_otorg_pct']),
    'activo' => (int) $producto['activo'],
] : ['codigo' => '', 'nombre' => '', 'destinatario' => 'AMBOS', 'plazo_min' => '1', 'plazo_max' => '60',
     'monto_min' => '0', 'monto_max' => '', 'alicuota_iva' => '21', 'gasto_otorg_pct' => '0', 'activo' => 1]);

titulo($producto ? $producto['nombre'] : 'Nuevo producto',
       $producto ? 'Código ' . $producto['codigo'] . ' · sistema francés, cuotas mensuales' : 'Sistema francés, cuotas mensuales',
       '', '<a href="productos.php">Productos y tasas</a>' . ($producto ? ' / ' . h($producto['codigo']) : ''));
mensajes($errores);
?>

<div class="card">
  <?= card_head('Datos del producto') ?>
  <form method="post" action="productos.php<?= $id ? '?id=' . $id : '?nuevo=1' ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="guardar_producto">
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="codigo">Código <span class="req">*</span></label>
          <input id="codigo" name="codigo" value="<?= h($v['codigo']) ?>" maxlength="20" class="mono" required autocomplete="off">
        </div>
        <div class="campo" style="--span:5">
          <label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($v['nombre']) ?>" maxlength="100" required>
        </div>
        <div class="campo" style="--span:3">
          <label for="destinatario">Para</label>
          <select id="destinatario" name="destinatario"><?= opciones(DESTINATARIOS, $v['destinatario']) ?></select>
        </div>
        <div class="campo" style="--span:2">
          <span class="campo-label">Estado</span>
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= $v['activo'] ? 'checked' : '' ?>> Activo</label>
        </div>
      </div>
    </div>
    <div class="bloque">
      <div class="bloque-titulo">Límites y cargos</div>
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="plazo_min">Plazo mínimo</label>
          <input id="plazo_min" name="plazo_min" value="<?= h($v['plazo_min']) ?>" inputmode="numeric" class="derecha" required>
          <span class="campo-ayuda">cuotas</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo_max">Plazo máximo</label>
          <input id="plazo_max" name="plazo_max" value="<?= h($v['plazo_max']) ?>" inputmode="numeric" class="derecha" required>
          <span class="campo-ayuda">cuotas</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_min">Monto mínimo</label>
          <input id="monto_min" name="monto_min" value="<?= h($v['monto_min']) ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">$</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_max">Monto máximo <span class="req">*</span></label>
          <input id="monto_max" name="monto_max" value="<?= h($v['monto_max']) ?>" inputmode="decimal" class="derecha" required>
          <span class="campo-ayuda">$</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="alicuota_iva">IVA s/intereses</label>
          <input id="alicuota_iva" name="alicuota_iva" value="<?= h($v['alicuota_iva']) ?>" inputmode="decimal" class="derecha" required>
          <span class="campo-ayuda">%</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="gasto_otorg_pct">Gastos de otorgamiento</label>
          <input id="gasto_otorg_pct" name="gasto_otorg_pct" value="<?= h($v['gasto_otorg_pct']) ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">% del capital, se descuenta del desembolso</span>
        </div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Volver', 'secondary', 'productos.php', 'volver') ?>
      <?= boton($producto ? 'Guardar cambios' : 'Crear producto', 'primary', null, 'guardar', true) ?>
    </div>
  </form>
</div>

<?php if ($producto): ?>
<div class="card">
  <?= card_head('Tasas', 'La que rige es la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos') ?>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <th>Vigente desde</th>
          <th class="th-right">Plazo</th>
          <th class="th-right">Monto</th>
          <th class="th-right">TNA</th>
          <th class="th-right">TEM</th>
          <th class="th-right">TEA</th>
          <th class="th-right">Punitoria (TNA)</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tasas): ?>
        <tr><td colspan="9" class="no-data">Sin tasas: el producto no se puede simular ni liquidar hasta cargar una.</td></tr>
      <?php endif; ?>
      <?php
        $hoy = date('Y-m-d');
        $vigente = null;
        foreach ($tasas as $t) {
            if ($t['vigencia_desde'] <= $hoy && ($vigente === null || $t['vigencia_desde'] > $vigente)) {
                $vigente = $t['vigencia_desde'];
            }
        }
        foreach ($tasas as $t):
            $tem = (float) $t['tna'] / 12;
            $tea = pow(1 + $tem, 12) - 1;
            $est = $t['vigencia_desde'] > $hoy ? estado('Futura', 'info')
                 : ($t['vigencia_desde'] === $vigente ? estado('Vigente', 'ok') : estado('Anterior', 'neutro'));
      ?>
        <tr>
          <td>
            <div class="acciones">
              <?php if ((int) $t['usos'] === 0): ?>
                <form method="post" class="form-en-linea" data-confirmar="Se borra la tasa del <?= h(fecha($t['vigencia_desde'])) ?> (TNA <?= h(pct($t['tna'])) ?>). Ningún préstamo la usó."
                      data-confirmar-titulo="Borrar la tasa" data-confirmar-boton="Borrar" data-peligro>
                  <?= csrf_campo() ?>
                  <input type="hidden" name="accion" value="borrar_tasa">
                  <input type="hidden" name="id_tasa" value="<?= (int) $t['id_tasa'] ?>">
                  <button type="submit" class="btn-accion borrar" title="Borrar la tasa" aria-label="Borrar la tasa"><?= icono('borrar') ?></button>
                </form>
              <?php else: ?>
                <span class="btn-accion borrar deshabilitado" title="La usan <?= (int) $t['usos'] ?> préstamos: no se puede borrar"><?= icono('borrar') ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-orden="<?= h($t['vigencia_desde']) ?>"><?= h(fecha($t['vigencia_desde'])) ?></td>
          <td class="td-right" data-orden="<?= h($t['plazo_desde']) ?>"><?= h($t['plazo_desde']) ?> a <?= h($t['plazo_hasta']) ?> cuotas</td>
          <td class="td-right" data-orden="<?= h($t['monto_desde']) ?>"><?= money($t['monto_desde']) ?> a <?= money($t['monto_hasta']) ?></td>
          <td class="td-right td-fuerte" data-orden="<?= h($t['tna']) ?>"><?= pct($t['tna']) ?></td>
          <td class="td-right" data-orden="<?= h($tem) ?>"><?= pct($tem, 3) ?></td>
          <td class="td-right" data-orden="<?= h($tea) ?>"><?= pct($tea) ?></td>
          <td class="td-right">
            <?php if ($t['tasa_punitoria'] !== null): ?>
              <?= pct($t['tasa_punitoria']) ?>
            <?php else: ?>
              <?= pct((float) $t['tna'] * $puniPct) ?><span class="segunda-linea"><?= h(pct($puniPct, 0)) ?> de la TNA</span>
            <?php endif; ?>
          </td>
          <td><?= $est ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" action="productos.php?id=<?= $id ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="agregar_tasa">
    <div class="bloque">
      <div class="bloque-titulo">Nueva tasa</div>
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="vigencia_desde">Vigente desde <span class="req">*</span></label>
          <input type="date" id="vigencia_desde" name="vigencia_desde" value="<?= h(post_str('vigencia_desde') ?: date('Y-m-d')) ?>" required>
        </div>
        <div class="campo" style="--span:1">
          <label for="plazo_desde">Plazo desde</label>
          <input id="plazo_desde" name="plazo_desde" value="<?= h(post_str('plazo_desde') ?: (string) $producto['plazo_min']) ?>" inputmode="numeric" class="derecha" required>
        </div>
        <div class="campo" style="--span:1">
          <label for="plazo_hasta">hasta</label>
          <input id="plazo_hasta" name="plazo_hasta" value="<?= h(post_str('plazo_hasta') ?: (string) $producto['plazo_max']) ?>" inputmode="numeric" class="derecha" required>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_desde">Monto desde</label>
          <input id="monto_desde" name="monto_desde" value="<?= h(post_str('monto_desde') ?: monto_campo($producto['monto_min'])) ?>" inputmode="decimal" class="derecha">
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_hasta">hasta</label>
          <input id="monto_hasta" name="monto_hasta" value="<?= h(post_str('monto_hasta') ?: monto_campo($producto['monto_max'])) ?>" inputmode="decimal" class="derecha" required>
        </div>
        <div class="campo" style="--span:1">
          <label for="tna">TNA % <span class="req">*</span></label>
          <input id="tna" name="tna" value="<?= h(post_str('tna')) ?>" inputmode="decimal" class="derecha" required placeholder="85">
        </div>
        <div class="campo" style="--span:1">
          <label for="tasa_punitoria">Punit. %</label>
          <input id="tasa_punitoria" name="tasa_punitoria" value="<?= h(post_str('tasa_punitoria')) ?>" inputmode="decimal" class="derecha" placeholder="auto" title="Vacía: <?= h(pct($puniPct, 0)) ?> de la TNA (parámetro general)">
        </div>
        <div class="campo campo-boton" style="--span:2">
          <?= boton('Agregar tasa', 'primary', null, 'agregar', true) ?>
        </div>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
