<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/contable.php';

// ---------------------------------------------------------------------
// Plan de cuentas (sql/financiera_contabilidad.sql), el ABM de gestion_adm
//   plan_cuentas.php               el plan, en árbol
//   plan_cuentas.php?nuevo=1       alta (&bajo=N: debajo de esa agrupadora,
//                                  con el código siguiente propuesto)
//   plan_cuentas.php?edit=N        edición
//   plan_cuentas.php?importar=1    importar un CSV (código; nombre; naturaleza)
//   ?export=xlsx · ?ejemplo=1      el plan en Excel · el CSV de ejemplo
// Las reglas (máscara, padre, nivel, imputable, corriente) están en
// inc/contable.php. Una cuenta con movimientos o con cuentas abajo no se
// elimina ni cambia de código: se desactiva.
// ---------------------------------------------------------------------

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));
$importar = get_str('importar') !== '';
$resultadoImp = null;

$fClas = get_str('clas');
$fTipo = in_array(get_str('tipo'), ['imputables', 'agrupadoras'], true) ? get_str('tipo') : '';
$fEstado = in_array(get_str('estado'), ['0', 'todas'], true) ? get_str('estado') : '1';
$hayFiltro = $fClas !== '' || $fTipo !== '' || $fEstado !== '1';
$lista = 'plan_cuentas.php' . (($q = http_build_query(array_filter(['clas' => $fClas, 'tipo' => $fTipo,
                                   'estado' => $fEstado !== '1' ? $fEstado : '']))) !== '' ? '?' . $q : '');

// ---------------------------------------------------------------------
// Descargas
// ---------------------------------------------------------------------
if (get_str('ejemplo') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plan_de_cuentas_ejemplo.csv"');
    echo ctb_csv_ejemplo();
    exit;
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    try {
        $actual = $id ? q1('SELECT * FROM c_plan_de_cuentas WHERE id_cuenta = ?', [$id]) : null;
        if ($id && !$actual) {
            flash('danger', 'La cuenta ya no existe.');
            redirigir($lista);
        }
        if ($accion === 'mascara') {
            $m = trim(post_str('mascara'));
            if ((int) q1('SELECT COUNT(*) AS n FROM c_plan_de_cuentas')['n']) {
                $errores[] = 'La máscara se fija antes de cargar la primera cuenta: con cuentas cargadas, cambiarla dejaría códigos que no la respetan.';
            } elseif (!ctb_mascara_valida($m)) {
                $errores[] = 'La máscara va con 9 en lugar de cada dígito y un mismo separador entre los tramos: 9.9.99.999 o 9.99.999.9999.';
            } else {
                ctb_guardar_mascara($m);
                flash('ok', 'Máscara fijada: ' . $m . '. Ejemplo de código: ' . ctb_ejemplo($m) . '.');
                redirigir($lista);
            }
        }
        if (in_array($accion, ['activar', 'desactivar'], true) && $actual) {
            q('UPDATE c_plan_de_cuentas SET activa = ? WHERE id_cuenta = ?', [$accion === 'activar' ? 1 : 0, $id]);
            flash('ok', $actual['codigo'] . ' ' . $actual['nombre'] . ($accion === 'activar' ? ' vuelve a estar activa.'
                  : ' queda inactiva: no se ofrece para imputar. Sus movimientos no cambian.'));
            redirigir($lista);
        }
        if ($accion === 'borrar' && $actual) {
            if ((int) q1('SELECT COUNT(*) AS n FROM c_plan_de_cuentas WHERE id_cuenta_padre = ?', [$id])['n']) {
                flash('danger', 'No se puede eliminar ' . $actual['codigo'] . ': tiene cuentas abajo.');
            } elseif (ctb_movimientos_cuenta($id)) {
                flash('danger', 'No se puede eliminar ' . $actual['codigo'] . ': ya tiene movimientos. Desactivala.');
            } elseif ($usos = ctb_usos_cuenta($id)) {
                flash('danger', 'No se puede eliminar ' . $actual['codigo'] . ': está asignada en ' . implode('; ', $usos) . '.');
            } else {
                q('DELETE FROM c_plan_de_cuentas WHERE id_cuenta = ?', [$id]);
                flash('ok', $actual['codigo'] . ' ' . $actual['nombre'] . ' eliminada.');
            }
            redirigir($lista);
        }
        if ($accion === 'guardar') {
            $datos = ['codigo' => post_str('codigo'), 'nombre' => post_str('nombre'), 'naturaleza' => post_str('naturaleza'),
                      'activa' => post_str('activa') === '1', 'es_monetario' => post_str('es_monetario') === '1'];
            if ($actual && strtoupper(trim($datos['codigo'])) !== $actual['codigo']) {
                if ((int) q1('SELECT COUNT(*) AS n FROM c_plan_de_cuentas WHERE id_cuenta_padre = ?', [$id])['n']) {
                    $errores[] = 'El código no se cambia: la cuenta tiene cuentas abajo.';
                } elseif (ctb_movimientos_cuenta($id)) {
                    $errores[] = 'El código no se cambia: la cuenta ya tiene movimientos.';
                }
            }
            [$f, $e] = $errores ? [[], []] : ctb_armar_cuenta($datos, $id ?: null);
            $errores = array_merge($errores, $e);
            if (!$errores && $actual && (int) $actual['permite_movimiento'] && !$f['permite_movimiento'] && ctb_movimientos_cuenta($id)) {
                $errores[] = 'La cuenta tiene movimientos: no puede pasar a ser agrupadora.';
            }
            if (!$errores) {
                $nuevoId = ctb_grabar_cuenta($f, $id ?: null);
                flash('ok', $f['codigo'] . ' ' . $f['nombre'] . ($id ? ' guardada.' : ' agregada al plan')
                      . ($f['permite_movimiento'] ? ' (imputable).' : ' (agrupadora).'));
                redirigir($id ? $lista : 'plan_cuentas.php?nuevo=1&bajo=' . (int) ($f['id_cuenta_padre'] ?? 0) . '#form');
            }
            $reg = ['id_cuenta' => $id ?: null] + $datos + ['activa' => $datos['activa'] ? 1 : 0, 'es_monetario' => $datos['es_monetario'] ? 1 : 0];
        }
        if ($accion === 'importar') {
            $importar = true;
            $a = $_FILES['archivo'] ?? null;
            if (!$a || ($a['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errores[] = 'Elegí el archivo CSV.';
            } elseif ($a['error'] !== UPLOAD_ERR_OK) {
                $errores[] = 'No se pudo subir el archivo (código ' . (int) $a['error'] . ').';
            } elseif (!preg_match('/\.(csv|txt)$/i', $a['name'])) {
                $errores[] = 'El archivo tiene que ser CSV o TXT (en Excel: Guardar como → CSV).';
            } else {
                [$filas, $errLeer] = ctb_leer_csv((string) file_get_contents($a['tmp_name']));
                if (!$filas) {
                    $errores[] = 'El archivo no tiene cuentas: hacen falta las columnas código y nombre.';
                } else {
                    [$imp, $salt, $errImp] = ctb_importar($filas);
                    $resultadoImp = ['importadas' => $imp, 'salteadas' => $salt, 'errores' => array_merge($errLeer, $errImp)];
                    if ($imp && !$resultadoImp['errores']) {
                        flash('ok', num($imp) . ($imp === 1 ? ' cuenta importada' : ' cuentas importadas')
                              . ($salt ? ' (' . num($salt) . ' ya estaban y se saltearon)' : '') . '.');
                        redirigir('plan_cuentas.php');
                    }
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
$plan = [];
$clasif = [];
$mascara = '';
$propuesto = null;
try {
    $mascara = ctb_mascara();
    $clasif = ctb_clasificaciones();

    if ($id && $reg === null) {
        $c = q1('SELECT * FROM c_plan_de_cuentas WHERE id_cuenta = ?', [$id]);
        if (!$c) {
            flash('danger', 'La cuenta no existe.');
            redirigir('plan_cuentas.php');
        }
        $reg = $c + ['naturaleza' => ''];
    } elseif ($reg === null && get_str('nuevo') !== '') {
        $reg = ['id_cuenta' => null, 'codigo' => '', 'nombre' => '', 'naturaleza' => '', 'activa' => 1, 'es_monetario' => 0];
        // Debajo de una agrupadora: el código siguiente a sus hijas.
        $bajo = (int) get_str('bajo');
        if ($bajo && $mascara !== '' && ($p = q1('SELECT * FROM c_plan_de_cuentas WHERE id_cuenta = ? AND permite_movimiento = 0', [$bajo]))) {
            $propuesto = $p;
            $reg['codigo'] = ctb_codigo_siguiente($p, $mascara);
            $reg['es_monetario'] = (int) $p['es_monetario'];
        }
    }

    foreach (ctb_plan() as $c) {
        if ($fClas !== '' && (int) $c['id_clasificacion'] !== (int) $fClas) {
            continue;
        }
        if ($fTipo === 'imputables' && !(int) $c['permite_movimiento'] || $fTipo === 'agrupadoras' && (int) $c['permite_movimiento']) {
            continue;
        }
        if ($fEstado !== 'todas' && (int) $c['activa'] !== (int) $fEstado) {
            continue;
        }
        $plan[] = $c;
    }
    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $libro = new XlsxLibro();
        $todas = $plan;
        $libro->hoja('Plan de cuentas')->titulo('Plan de cuentas', 'Máscara ' . ($mascara ?: '—') . ' · ' . num(count($todas))
                . ' cuentas' . ($hayFiltro ? ' (filtradas)' : '') . ' · generado el ' . date('d/m/Y H:i'))
              ->tabla([['Código', 'texto', 16], ['Cuenta', 'texto', 50], ['Nivel', 'entero', 7], ['Clasificación', 'texto', 18],
                       ['Naturaleza', 'texto', 12], ['Imputable', 'centro', 10], ['Corriente', 'centro', 10],
                       ['Monetaria', 'centro', 10], ['Activa', 'centro', 8]],
                      array_map(fn ($c) => [$c['codigo'], str_repeat('   ', max(0, (int) $c['nivel'] - 1)) . $c['nombre'], (int) $c['nivel'],
                                            $c['clasificacion'], $c['naturaleza'], (int) $c['permite_movimiento'] ? 'Sí' : 'No',
                                            (int) $c['nivel'] >= 2 && in_array($c['tipo'], ['activo', 'pasivo'], true)
                                                ? ((int) $c['corriente'] ? 'Sí' : 'No') : '',
                                            (int) $c['es_monetario'] ? 'Sí' : 'No', (int) $c['activa'] ? 'Sí' : 'No'], $todas));
        $libro->enviar('plan_de_cuentas_' . date('Ymd') . '.xlsx');
        exit;
    }

} catch (Throwable $e) {
    $errores[] = ctb_falta_script($e);
}

$total = count($plan);
$imputables = count(array_filter($plan, fn ($c) => (int) $c['permite_movimiento']));

layout_inicio('Plan de cuentas');
titulo('Plan de cuentas',
       $mascara !== '' ? 'Máscara ' . $mascara . ' · las cuentas que terminan en cero agrupan; las demás son imputables'
                       : 'La estructura contable de gestion_adm: rubros de primer nivel, agrupadoras e imputables',
       ($reg || $importar) ? '' : boton('Importar', 'secondary', 'plan_cuentas.php?importar=1', 'subir')
                                 . boton('Nueva cuenta', 'primary', 'plan_cuentas.php?nuevo=1#form', 'agregar'),
       'Contabilidad / Plan de cuentas');
mensajes($errores);

// ---------------------------------------------------------------------
// Máscara (solo con el plan vacío)
// ---------------------------------------------------------------------
if (!$reg && !$importar && $mascara === '' && !$plan && !$hayFiltro && !$errores):
?>
<form method="post" action="plan_cuentas.php" autocomplete="off">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="mascara">
  <div class="card">
    <?= card_head('Formato del código', 'se fija una vez, antes de la primera cuenta') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="mascara">Máscara <span class="req">*</span></label>
          <input id="mascara" name="mascara" class="mono" maxlength="20" required placeholder="9.9.99.999" value="<?= h(post_str('mascara')) ?>">
          <small class="campo-ayuda">9 por cada dígito y un separador entre los tramos</small></div>
        <div class="campo" style="--span:8"><label>Cómo se arma</label>
          <p class="campo-ayuda" style="margin:6px 0 0">
            El primer tramo es el rubro (1 Activo, 2 Pasivo, 3 Patrimonio neto, 4 Ingresos, 5 Egresos). Una cuenta con el último tramo en cero
            agrupa (1.1.01.000); con el último tramo distinto de cero es imputable (1.1.01.001). Si importás un plan, la máscara sale de su primer código.
          </p></div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Importar un plan', 'secondary', 'plan_cuentas.php?importar=1', 'subir') ?>
      <?= boton('Fijar máscara', 'primary', null, 'guardar', true) ?>
    </div>
  </div>
</form>
<?php endif;

// ---------------------------------------------------------------------
// Importar
// ---------------------------------------------------------------------
if ($importar):
?>
<form method="post" action="plan_cuentas.php?importar=1" enctype="multipart/form-data">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
  <div class="card">
    <?= card_head('Importar plan de cuentas', 'CSV con código y nombre', boton('Volver', 'secondary', 'plan_cuentas.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="archivo">Archivo <span class="req">*</span></label>
          <input type="file" id="archivo" name="archivo" accept=".csv,.txt" required></div>
        <div class="campo" style="--span:7"><label>Formato</label>
          <p class="campo-ayuda" style="margin:6px 0 0">
            Columnas <strong>codigo</strong>, <strong>nombre</strong> y, en las cuentas de primer nivel de un rubro nuevo, <strong>naturaleza</strong>
            (deudora o acreedora), separadas por punto y coma. Lo demás se calcula: padre, nivel, imputable y corriente.
            Las cuentas que ya existen se saltean. <?= $mascara === '' ? 'Con el plan vacío, la máscara sale del primer código.' : 'Los códigos tienen que respetar la máscara ' . h($mascara) . '.' ?>
            <a href="plan_cuentas.php?ejemplo=1">Bajar un CSV de ejemplo</a>.
          </p></div>
      </div>
    </div>
    <?php if ($resultadoImp): ?>
    <div class="bloque">
      <div class="bloque-titulo">Resultado</div>
      <p style="margin:0 0 8px"><?= num($resultadoImp['importadas']) ?> importadas · <?= num($resultadoImp['salteadas']) ?> ya estaban
        · <?= num(count($resultadoImp['errores'])) ?> con error</p>
      <?php if ($resultadoImp['errores']): ?>
        <div class="alert alert-warning"><?= icono('alerta', 16) ?><div><ul>
          <?php foreach (array_slice($resultadoImp['errores'], 0, 50) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          <?php if (count($resultadoImp['errores']) > 50): ?><li>… y <?= num(count($resultadoImp['errores']) - 50) ?> más.</li><?php endif; ?>
        </ul></div></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="form-acciones"><?= boton('Importar', 'primary', null, 'subir', true) ?></div>
  </div>
</form>
<?php endif;

// ---------------------------------------------------------------------
// Alta / edición
// ---------------------------------------------------------------------
if ($reg):
    $esNuevo = !$reg['id_cuenta'];
    $conMov = !$esNuevo && ctb_movimientos_cuenta((int) $reg['id_cuenta']);
    $conHijas = !$esNuevo && (int) q1('SELECT COUNT(*) AS n FROM c_plan_de_cuentas WHERE id_cuenta_padre = ?', [$reg['id_cuenta']])['n'];
    $codigoFijo = $conMov || $conHijas;
?>
<form method="post" action="<?= h($lista) ?>" autocomplete="off" id="form">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_cuenta'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nueva cuenta' : $reg['codigo'] . ' ' . $reg['nombre'],
                  $esNuevo ? ($propuesto ? 'debajo de ' . $propuesto['codigo'] . ' ' . $propuesto['nombre'] : 'el padre y el nivel salen del código')
                           : ($codigoFijo ? 'el código no se cambia: ' . ($conHijas ? 'tiene cuentas abajo' : 'tiene movimientos') : ''),
                  boton('Volver', 'secondary', $lista, 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:3"><label for="codigo">Código <span class="req">*</span></label>
          <input id="codigo" name="codigo" class="mono" value="<?= h($reg['codigo']) ?>" maxlength="20" required
                 placeholder="<?= h(ctb_ejemplo($mascara)) ?>" <?= $codigoFijo ? 'readonly' : '' ?>
                 data-mascara="<?= h($mascara) ?>" oninput="ctbCodigo(this)">
          <small class="campo-ayuda" id="codigo-ayuda"><?= $mascara !== '' ? 'formato ' . h($mascara) : 'el primero fija la máscara' ?></small></div>
        <div class="campo" style="--span:6"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="150" required placeholder="DEUDORES POR PRÉSTAMOS"
                 style="text-transform:uppercase"></div>
        <div class="campo" style="--span:3;display:none" id="campo-naturaleza"><label for="naturaleza">Naturaleza</label>
          <select id="naturaleza" name="naturaleza"><?= opciones(['deudora' => 'Deudora', 'acreedora' => 'Acreedora'], $reg['naturaleza'] ?? '', 'Elegí…') ?></select>
          <small class="campo-ayuda">solo para un rubro nuevo</small></div>
      </div>
      <div class="campos" style="margin-top:6px">
        <div class="campo" style="--span:3"><label class="campo-check"><input type="checkbox" name="activa" value="1" <?= (int) $reg['activa'] ? 'checked' : '' ?>> Activa</label></div>
        <div class="campo" style="--span:4"><label class="campo-check"><input type="checkbox" name="es_monetario" value="1" <?= (int) $reg['es_monetario'] ? 'checked' : '' ?>> Monetaria (expuesta a inflación)</label></div>
      </div>
      <p class="campo-ayuda" style="margin:8px 0 0">
        Con el último tramo en cero la cuenta agrupa; si no, es imputable. El padre es la agrupadora del mismo código con los tramos de la derecha en cero,
        y tiene que existir. La clasificación sale del primer dígito; «corriente», del nombre de la cuenta de nivel 2 (ACTIVO / PASIVO CORRIENTE).
      </p>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<script>
// El código: imputable o agrupadora, y si es un rubro sin clasificación, pide la naturaleza.
(function () {
  var clasif = <?= json_encode(array_map('strval', array_keys($clasif))) ?>;
  window.ctbCodigo = function (inp) {
    var c = inp.value.trim(), m = inp.getAttribute('data-mascara') || '', ay = document.getElementById('codigo-ayuda');
    var sep = (m.match(/[^9]/) || (c.match(/\D/) || ['.']))[0];
    var t = c.split(sep), ult = t[t.length - 1], d = c.charAt(0);
    var resto = t.slice(1).every(function (x) { return /^0*$/.test(x); });
    document.getElementById('campo-naturaleza').style.display = /^[1-9]$/.test(d) && resto && clasif.indexOf(d) < 0 ? '' : 'none';
    if (!c) { ay.textContent = m ? 'formato ' + m : 'el primero fija la máscara'; ay.className = 'campo-ayuda'; return; }
    var re = m ? new RegExp('^' + m.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/9/g, '\\d') + '$') : null;
    if (re && !re.test(c)) { ay.textContent = 'no respeta el formato ' + m; ay.className = 'campo-ayuda error'; return; }
    ay.textContent = t.length > 1 && /^\d+$/.test(ult) && +ult !== 0 ? 'imputable' : 'agrupadora';
    ay.className = 'campo-ayuda ok';
  };
  ctbCodigo(document.getElementById('codigo'));
})();
</script>
<?php endif; ?>

<?php if ($plan || $hayFiltro || $mascara !== ''): ?>
<div class="card">
  <?= card_head('Cuentas', $total ? num($imputables) . ' imputables de ' . num($total) : '') ?>
  <?= barra_local('plan_cuentas.php', 'Código o nombre…',
                  filtro_select('clas', 'Rubro', array_map(fn ($c) => $c['codigo'] . ' ' . $c['clasificacion'], $clasif), $fClas, 'Todos', $fClas !== '')
                  . filtro_select('tipo', 'Tipo', ['imputables' => 'Imputables', 'agrupadoras' => 'Agrupadoras'], $fTipo, 'Todas', $fTipo !== '')
                  . filtro_select('estado', 'Estado', ['1' => 'Activas', '0' => 'Inactivas', 'todas' => 'Todas'], $fEstado, '', $fEstado !== '1'),
                  $hayFiltro, url_con(['export' => 'xlsx'])) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-sin-orden data-unidad="cuenta|cuentas">
      <thead><tr><th class="col-acciones">Acciones</th><th>Código</th><th>Cuenta</th><th>Rubro</th>
        <th>Tipo</th><th>Corriente</th><th class="th-right">Movimientos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($plan as $c):
          $cid = (int) $c['id_cuenta'];
          $agrupa = !(int) $c['permite_movimiento'];
          $borrable = !(int) $c['hijas'] && !(int) $c['movimientos'];
      ?>
        <tr class="<?= (int) $c['activa'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'plan_cuentas.php?edit=' . $cid . '#form', 'Editar') ?>
            <?= $agrupa && (int) $c['activa'] ? btn_accion('agregar', 'plan_cuentas.php?nuevo=1&bajo=' . $cid . '#form', 'Agregar una cuenta abajo') : '' ?>
            <?= (int) $c['activa']
                  ? accion_post($lista, ['accion' => 'desactivar', 'id' => $cid], 'cerrar', 'Desactivar',
                                $c['codigo'] . ' ' . $c['nombre'] . ' deja de ofrecerse para imputar. Sus movimientos no cambian.', 'borrar')
                  : accion_post($lista, ['accion' => 'activar', 'id' => $cid], 'ok', 'Activar') ?>
            <?= $borrable ? accion_post($lista, ['accion' => 'borrar', 'id' => $cid], 'borrar', 'Eliminar',
                                        'Se elimina ' . $c['codigo'] . ' ' . $c['nombre'] . '. No tiene cuentas abajo ni movimientos.', 'borrar') : '' ?>
          </div></td>
          <td class="mono"><?= h($c['codigo']) ?></td>
          <td class="<?= $agrupa ? 'td-fuerte' : '' ?>"><span class="arbol-celda" style="--prof:<?= max(0, (int) $c['nivel'] - 1) ?>">
            <a href="plan_cuentas.php?edit=<?= $cid ?>#form"><?= h($c['nombre']) ?></a></span></td>
          <td><?= h($c['clasificacion']) ?><span class="segunda-linea"><?= h($c['naturaleza']) ?></span></td>
          <td><?= $agrupa ? estado('Agrupadora', 'info') : estado('Imputable', 'ok') ?><?= (int) $c['es_monetario'] ? '<span class="segunda-linea">monetaria</span>' : '' ?></td>
          <td><?= (int) $c['nivel'] >= 2 && in_array($c['tipo'], ['activo', 'pasivo'], true)
                    ? ((int) $c['corriente'] ? 'Corriente' : 'No corriente') : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right"><?= (int) $c['movimientos'] ? num($c['movimientos']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $c['activa'] ? estado('Activa', 'ok') : estado('Inactiva', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$plan): ?><tr><td colspan="8" class="no-data"><?= $hayFiltro ? 'Ninguna cuenta con ese filtro.'
          : 'Todavía no hay cuentas. Empezá por los rubros de primer nivel (ACTIVO, PASIVO, PATRIMONIO NETO, INGRESOS, EGRESOS) con «Nueva cuenta», o importá un plan.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
