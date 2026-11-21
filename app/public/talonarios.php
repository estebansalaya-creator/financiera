<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/empresa.php';
require_once __DIR__ . '/../inc/talonarios.php';

// ---------------------------------------------------------------------
// Tablas y parámetros › Talonarios (el modelo de gestion_adm)
//   talonarios.php                       los talonarios: serie, rango, por
//                                        dónde va la numeración y cuánto queda
//   talonarios.php?nuevo=1 | ?edit=N     el alta y la edición
//   talonarios.php?solapa=clasificaciones  agrupan talonarios de un tipo de
//                                        comprobante (de ahí sale si suma o
//                                        resta en la cuenta del cliente)
//   talonarios.php?solapa=tipos          los tipos de comprobante y el
//                                        nomenclador de ARCA (consulta)
// Las reglas, en inc\talonarios.php.
// ---------------------------------------------------------------------

$solapa = in_array(get_str('solapa'), ['clasificaciones', 'tipos'], true) ? get_str('solapa') : 'talonarios';
$errores = [];
$instalado = false;
try {
    $instalado = tal_instalado();
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}
if (!$instalado && !$errores) {
    $errores[] = 'Falta crear las tablas de talonarios: corré sql\financiera_talonarios.sql.';
}
$previo = null;          // el talonario que no se pudo guardar, con lo cargado
$clasPrevia = null;      // ídem, la clasificación

function texto_error(Throwable $e): string
{
    return $e instanceof TalonarioError ? $e->getMessage() : mensaje_bd($e);
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post() && $instalado) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'guardar') {
        $id = (int) post_str('id');
        $d = tal_leer_post();
        $err = tal_validar($d, arca_tipos(), empresa());
        if (!$err) {
            try {
                tal_guardar($d, $id);
                flash('ok', 'Talonario «' . $d['nombre'] . '» ' . ($id ? 'guardado.' : 'creado.'));
                redirigir('talonarios.php');
            } catch (Throwable $e) {
                $err[] = texto_error($e);
            }
        }
        $errores = array_merge($errores, $err);
        $previo = $d + ['id_talonario' => $id];
    }

    if (in_array($accion, ['habilitar', 'deshabilitar'], true)) {
        $id = (int) post_str('id');
        q('UPDATE talonarios SET habilitado = ?, modificado_el = NOW(), modificado_por = ? WHERE id_talonario = ?',
          [$accion === 'habilitar' ? 1 : 0, usuario_id(), $id]);
        $t = q1('SELECT nombre FROM talonarios WHERE id_talonario = ?', [$id]);
        flash('ok', 'Talonario «' . ($t['nombre'] ?? '') . '» ' . ($accion === 'habilitar' ? 'habilitado.' : 'deshabilitado: deja de ofrecerse para emitir.'));
        redirigir('talonarios.php');
    }

    if ($accion === 'borrar') {
        $id = (int) post_str('id');
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $t = q1('SELECT * FROM talonarios WHERE id_talonario = ? FOR UPDATE', [$id]);
            if (!$t) {
                throw new TalonarioError('El talonario ya no existe.');
            }
            // Uno usado no se borra: se deshabilita, para no perder el rastro de lo emitido.
            if (tal_usado($t)) {
                throw new TalonarioError('El talonario «' . $t['nombre'] . '» ya emitió comprobantes (va por el ' . num($t['ultimo_emitido'])
                    . '): deshabilitalo en lugar de borrarlo.');
            }
            q('DELETE FROM talonarios WHERE id_talonario = ?', [$id]);
            $pdo->commit();
            flash('ok', 'Talonario «' . $t['nombre'] . '» eliminado.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', texto_error($e));
        }
        redirigir('talonarios.php');
    }

    if ($accion === 'guardar_clasificacion') {
        $solapa = 'clasificaciones';
        $id = (int) post_str('id');
        $desc = mb_substr(post_str('descripcion'), 0, 150);
        $tipo = (int) post_str('id_comprobante_tipo');
        $err = [];
        if ($desc === '') {
            $err[] = 'Escribí la descripción de la clasificación.';
        }
        if (!$tipo) {
            $err[] = 'Elegí el tipo de comprobante.';
        }
        if (!$err) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $ct = q1('SELECT nombre, debito_credito FROM comprobantes_tipos WHERE id_comprobante_tipo = ?', [$tipo]);
                if (!$ct) {
                    throw new TalonarioError('Ese tipo de comprobante no existe.');
                }
                if ($id) {
                    q1('SELECT id_clasificacion FROM talonarios_clasificaciones WHERE id_clasificacion = ? FOR UPDATE', [$id]);
                    // Sus talonarios de ARCA tienen que seguir del mismo lado de la cuenta.
                    $malo = q1('SELECT t.nombre, a.descripcion, a.debito_credito FROM talonarios t
                                  JOIN arca_tipos_comprobante a ON a.codigo = t.codigo_arca
                                 WHERE t.id_clasificacion = ? AND a.debito_credito <> ? LIMIT 1', [$id, $ct['debito_credito']]);
                    if ($malo) {
                        throw new TalonarioError('La clasificación tiene el talonario de ARCA «' . $malo['nombre'] . '» (' . $malo['descripcion']
                            . ', ' . tal_lado($malo['debito_credito']) . '): no puede pasar a ' . $ct['nombre'] . ', que va ' . tal_lado($ct['debito_credito']) . '.');
                    }
                    q('UPDATE talonarios_clasificaciones SET descripcion = ?, id_comprobante_tipo = ? WHERE id_clasificacion = ?', [$desc, $tipo, $id]);
                } else {
                    q('INSERT INTO talonarios_clasificaciones (descripcion, id_comprobante_tipo, creado_por) VALUES (?, ?, ?)', [$desc, $tipo, usuario_id()]);
                }
                $pdo->commit();
                flash('ok', 'Clasificación «' . $desc . '» ' . ($id ? 'guardada.' : 'creada.'));
                redirigir('talonarios.php?solapa=clasificaciones');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Ya hay una clasificación «' . $desc . '».' : texto_error($e);
            }
        }
        $errores = array_merge($errores, $err);
        $clasPrevia = ['id_clasificacion' => $id, 'descripcion' => $desc, 'id_comprobante_tipo' => $tipo];
    }

    if ($accion === 'borrar_clasificacion') {
        $id = (int) post_str('id');
        $n = (int) (q1('SELECT COUNT(*) AS n FROM talonarios WHERE id_clasificacion = ?', [$id])['n'] ?? 0);
        $c = q1('SELECT descripcion FROM talonarios_clasificaciones WHERE id_clasificacion = ?', [$id]);
        if ($n) {
            // La clave foránea lo impediría igual; así el mensaje dice por qué.
            flash('danger', 'No se puede borrar «' . ($c['descripcion'] ?? '') . '»: tiene ' . $n . ' talonario' . ($n === 1 ? '' : 's') . '.');
        } else {
            try {
                q('DELETE FROM talonarios_clasificaciones WHERE id_clasificacion = ?', [$id]);
                flash('ok', 'Clasificación «' . ($c['descripcion'] ?? '') . '» eliminada.');
            } catch (Throwable $e) {
                flash('danger', mensaje_bd($e));
            }
        }
        redirigir('talonarios.php?solapa=clasificaciones');
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$talonarios = $clasificaciones = $tipos = $arcaTodos = $arcaWs = [];
if ($instalado) {
    try {
        $talonarios = q('SELECT t.*, c.descripcion AS clasificacion, ct.nombre AS tipo, ct.debito_credito,
                                a.descripcion AS arca_descripcion
                           FROM talonarios t
                           JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
                           JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
                           LEFT JOIN arca_tipos_comprobante a ON a.codigo = t.codigo_arca
                          ORDER BY t.habilitado DESC, c.descripcion, t.punto_venta, t.letra, t.numero_desde');
        $clasificaciones = q('SELECT c.*, ct.nombre AS tipo, ct.debito_credito,
                                     (SELECT COUNT(*) FROM talonarios t WHERE t.id_clasificacion = c.id_clasificacion) AS cant,
                                     (SELECT GROUP_CONCAT(t.nombre ORDER BY t.nombre SEPARATOR \', \') FROM talonarios t
                                       WHERE t.id_clasificacion = c.id_clasificacion) AS nombres
                                FROM talonarios_clasificaciones c
                                JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
                               ORDER BY ct.nombre, c.descripcion');
        $tipos = q('SELECT ct.*, (SELECT COUNT(*) FROM talonarios_clasificaciones c WHERE c.id_comprobante_tipo = ct.id_comprobante_tipo) AS clasif
                      FROM comprobantes_tipos ct ORDER BY ct.debito_credito DESC, ct.nombre');
        $arcaTodos = q('SELECT a.*, (SELECT COUNT(*) FROM talonarios t WHERE t.codigo_arca = a.codigo) AS talonarios
                          FROM arca_tipos_comprobante a ORDER BY CAST(a.codigo AS UNSIGNED)');
        $arcaWs = arca_tipos(true);
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

// El talonario del formulario: el que no se pudo guardar, el que se edita o uno nuevo.
$form = null;
$antes = null;
if ($solapa === 'talonarios' && $instalado) {
    $idEdit = (int) get_str('edit');
    if ($idEdit) {
        foreach ($talonarios as $t) {
            if ((int) $t['id_talonario'] === $idEdit) {
                $antes = $t;
            }
        }
        if (!$antes && $previo === null) {
            $errores[] = 'Ese talonario no existe.';
        }
    }
    if ($previo !== null) {
        $form = $previo;
        if ((int) $previo['id_talonario'] && !$antes) {
            foreach ($talonarios as $t) {
                if ((int) $t['id_talonario'] === (int) $previo['id_talonario']) {
                    $antes = $t;
                }
            }
        }
    } elseif ($antes) {
        $form = $antes;
    } elseif (get_str('nuevo') === '1') {
        $form = ['id_talonario' => 0, 'nombre' => '', 'id_clasificacion' => (int) get_str('clasificacion'), 'letra' => 'X',
                 'punto_venta' => 1, 'numero_desde' => 1, 'numero_hasta' => 99999999, 'ultimo_emitido' => 0, 'habilitado' => 1,
                 'comentario' => null, 'codigo_arca' => null, 'arca_concepto' => null, 'arca_ambiente' => null];
    }
}
$clasForm = $clasPrevia;
if ($clasForm === null && $solapa === 'clasificaciones' && (int) get_str('edit')) {
    foreach ($clasificaciones as $c) {
        if ((int) $c['id_clasificacion'] === (int) get_str('edit')) {
            $clasForm = $c;
        }
    }
}

/** Un botón de la grilla que manda una acción. */
function accion_tal(int $id, string $accion, string $icono, string $titulo, string $confirmar = '', bool $peligro = false): string
{
    return '<form method="post" action="talonarios.php' . (in_array($accion, ['borrar_clasificacion'], true) ? '?solapa=clasificaciones' : '') . '" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo) . '" data-confirmar-boton="' . h($titulo) . '"'
             . ($peligro ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="' . h($accion) . '">'
         . '<button class="btn-accion' . ($peligro ? ' borrar' : '') . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

/** La barra de uso de un talonario. */
function barra_uso(array $u): string
{
    $clase = $u['agotado'] ? ' agotado' : ($u['por_agotar'] ? ' alerta' : '');
    return '<span class="uso-barra' . $clase . '" title="' . h(num($u['pct'], 1)) . ' % usado"><span style="width:' . min(100, max(0, $u['pct'])) . '%"></span></span>';
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
$empresa = empresa();
layout_inicio('Talonarios');
titulo('Talonarios', 'La numeración de cada comprobante que emite la financiera: serie, rango, por dónde va y si lo autoriza ARCA',
       $solapa === 'talonarios' && !$form && $instalado && $clasificaciones ? boton('Nuevo talonario', 'primary', 'talonarios.php?nuevo=1', 'agregar') : '',
       'Tablas y parámetros / Talonarios');
mensajes($errores);
if (!$instalado) {
    layout_fin();
    exit;
}
?>
<div class="abm-tabs">
  <a class="solapa <?= $solapa === 'talonarios' ? 'activa' : '' ?>" href="talonarios.php">Talonarios <span class="conteo"><?= count($talonarios) ?></span></a>
  <a class="solapa <?= $solapa === 'clasificaciones' ? 'activa' : '' ?>" href="talonarios.php?solapa=clasificaciones">Clasificaciones <span class="conteo"><?= count($clasificaciones) ?></span></a>
  <a class="solapa <?= $solapa === 'tipos' ? 'activa' : '' ?>" href="talonarios.php?solapa=tipos">Tipos de comprobante</a>
</div>

<?php if ($solapa === 'talonarios'): ?>

<?php if (!$clasificaciones): ?>
  <?php alerta('Todavía no hay clasificaciones. Un talonario necesita una: empezá por la solapa «Clasificaciones» (por ejemplo, «Recibos de cobranza», de tipo Recibo).', 'warning'); ?>
<?php elseif ($form):
    $f = $form;
    $esNuevo = !(int) $f['id_talonario'];
    $usado = $antes && tal_usado($antes);
    $fijoArca = $usado && $antes['codigo_arca'] !== null;       // usado y de ARCA: la serie queda fija
    $sinArca = $usado && $antes['codigo_arca'] === null;        // usado con numeración propia: no pasa a ARCA
    $fijaSerie = $usado;                                        // usado: letra, punto de venta y desde, impresos
?>
<form method="post" action="talonarios.php<?= $esNuevo ? '?nuevo=1' : '?edit=' . (int) $f['id_talonario'] ?>" autocomplete="off" id="frm-talonario">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $f['id_talonario'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo talonario' : $antes['nombre'] ?? $f['nombre'],
                  $usado ? 'va por el ' . num($antes['ultimo_emitido']) . ($fijoArca ? ': la serie de ARCA queda fija' : ': la serie queda fija') : '',
                  boton('Volver', 'secondary', 'talonarios.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="id_clasificacion">Clasificación <span class="req">*</span></label>
          <select id="id_clasificacion" name="id_clasificacion" required><option value="">Elegí…</option>
            <?php foreach ($clasificaciones as $c): ?>
              <option value="<?= (int) $c['id_clasificacion'] ?>" data-dc="<?= h($c['debito_credito']) ?>" <?= (int) $c['id_clasificacion'] === (int) $f['id_clasificacion'] ? 'selected' : '' ?>><?= h($c['descripcion'] . ' (' . $c['tipo'] . ', ' . tal_lado($c['debito_credito']) . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda"><a href="talonarios.php?solapa=clasificaciones">¿no está?</a></span></div>
        <div class="campo" style="--span:5"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($f['nombre']) ?>" maxlength="50" required placeholder="Recibos de cobranza, Facturas B de intereses…"></div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="habilitado" value="1" <?= (int) $f['habilitado'] ? 'checked' : '' ?>> Habilitado</label></div>

        <div class="campo" style="--span:6"><label for="codigo_arca">Tipo de ARCA</label>
          <?php if ($fijoArca || $sinArca): ?><input type="hidden" name="codigo_arca" value="<?= h((string) $f['codigo_arca']) ?>"><?php endif; ?>
          <select id="codigo_arca" <?= $fijoArca || $sinArca ? 'disabled' : 'name="codigo_arca"' ?>>
            <option value="" data-letra="" data-dc="">Sin tipo: numeración propia</option>
            <?php foreach ($arcaWs as $a): ?>
              <option value="<?= h($a['codigo']) ?>" data-letra="<?= h($a['letra']) ?>" data-dc="<?= h($a['debito_credito']) ?>" <?= (string) $f['codigo_arca'] === $a['codigo'] ? 'selected' : '' ?>><?= h($a['codigo'] . ' · ' . $a['descripcion']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda" id="arca-ayuda" data-base="<?= $sinArca ? 'ya emitió con numeración propia: no puede pasar a ARCA' : 'con tipo, pide CAE a ARCA por web service y la numeración es la de ARCA' ?>"><?= $sinArca ? 'ya emitió con numeración propia: no puede pasar a ARCA' : 'con tipo, pide CAE a ARCA por web service y la numeración es la de ARCA' ?></span></div>
        <div class="campo solo-arca" style="--span:3"><label for="arca_ambiente">Ambiente de ARCA <span class="req">*</span></label>
          <?php if ($fijoArca): ?><input type="hidden" name="arca_ambiente" value="<?= h((string) $f['arca_ambiente']) ?>"><?php endif; ?>
          <select id="arca_ambiente" <?= $fijoArca ? 'disabled' : 'name="arca_ambiente"' ?>><?= opciones(TAL_AMBIENTES, (string) $f['arca_ambiente'], 'Elegí…') ?></select></div>
        <div class="campo solo-arca" style="--span:3"><label for="arca_concepto">Concepto</label>
          <select id="arca_concepto" name="arca_concepto"><?= opciones(TAL_CONCEPTOS, (string) $f['arca_concepto'], 'Servicios (el de siempre)') ?></select></div>

        <div class="campo" style="--span:2"><label for="letra">Letra <span class="req">*</span></label>
          <?php if ($fijaSerie): ?><input type="hidden" name="letra" value="<?= h($f['letra']) ?>"><?php endif; ?>
          <select id="letra" <?= $fijaSerie ? 'disabled' : 'name="letra"' ?>><?= opciones(TAL_LETRAS, $f['letra']) ?></select></div>
        <div class="campo" style="--span:2"><label for="punto_venta">Punto de venta <span class="req">*</span></label>
          <?php if ($fijaSerie): ?><input type="hidden" name="punto_venta" value="<?= (int) $f['punto_venta'] ?>"><?php endif; ?>
          <input id="punto_venta" <?= $fijaSerie ? 'disabled' : 'name="punto_venta"' ?> value="<?= h((string) $f['punto_venta']) ?>" inputmode="numeric" maxlength="5" class="mono derecha" required></div>
        <div class="campo" style="--span:2"><label for="numero_desde">Número desde <span class="req">*</span></label>
          <input id="numero_desde" name="numero_desde" value="<?= h((string) $f['numero_desde']) ?>" inputmode="numeric" maxlength="8" class="mono derecha" required<?= $fijaSerie ? ' readonly' : '' ?>></div>
        <div class="campo" style="--span:3"><label for="numero_hasta">Número hasta <span class="req">*</span></label>
          <input id="numero_hasta" name="numero_hasta" value="<?= h((string) $f['numero_hasta']) ?>" inputmode="numeric" maxlength="8" class="mono derecha" required></div>
        <div class="campo" style="--span:3"><label for="ultimo_emitido">Último emitido</label>
          <input id="ultimo_emitido" name="ultimo_emitido" value="<?= h((string) $f['ultimo_emitido']) ?>" inputmode="numeric" maxlength="8" class="mono derecha">
          <span class="campo-ayuda">0 si no se emitió ninguno. No vuelve para atrás.</span></div>

        <div class="campo" style="--span:12"><label for="comentario">Comentario</label>
          <input id="comentario" name="comentario" value="<?= h((string) $f['comentario']) ?>" maxlength="250"></div>
      </div>
      <p class="nota-arbol" id="tal-resumen"></p>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Crear talonario' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<script>
(function () {
  var f = document.getElementById('frm-talonario'), arca = document.getElementById('codigo_arca'), letra = document.getElementById('letra'),
      clas = document.getElementById('id_clasificacion'), ayuda = document.getElementById('arca-ayuda'), res = document.getElementById('tal-resumen');
  function val(id) { return (document.getElementById(id).value || '').replace(/\D/g, ''); }
  function pad(n, l) { n = String(n); while (n.length < l) n = '0' + n; return n; }
  function lado(dc) { return dc === 'C' ? 'al haber' : 'al debe'; }
  function mostrar(cambio) {
    var op = arca.options[arca.selectedIndex], conArca = !!arca.value;
    f.querySelectorAll('.solo-arca').forEach(function (c) { c.classList.toggle('oculto', !conArca); });
    document.getElementById('arca_ambiente').required = conArca && !document.getElementById('arca_ambiente').disabled;
    if (conArca && cambio && op.getAttribute('data-letra')) letra.value = op.getAttribute('data-letra');
    var cl = clas.options[clas.selectedIndex], dcCl = cl ? cl.getAttribute('data-dc') : '', dcA = op.getAttribute('data-dc');
    ayuda.className = 'campo-ayuda';
    ayuda.textContent = ayuda.getAttribute('data-base');
    if (conArca && dcCl && dcA && dcCl !== dcA) {
      ayuda.className = 'campo-ayuda texto-error';
      ayuda.textContent = op.text.replace(/^\d+ · /, '') + ' va ' + lado(dcA) + ' y la clasificación elegida va ' + lado(dcCl) + ': elegí una del tipo que corresponde.';
    } else if (conArca && letra.value !== op.getAttribute('data-letra')) {
      ayuda.className = 'campo-ayuda texto-error';
      ayuda.textContent = 'la letra tiene que ser la del tipo: ' + op.getAttribute('data-letra');
    }
    var desde = +val('numero_desde') || 1, ult = +val('ultimo_emitido') || 0, hasta = +val('numero_hasta') || 0;
    var prox = ult >= desde ? ult + 1 : desde;
    res.textContent = hasta && prox > hasta ? 'El rango está agotado.'
      : 'Próximo número: ' + letra.value + ' ' + pad(val('punto_venta') || 0, 5) + '-' + pad(prox, 8)
        + (hasta ? ' · quedan ' + (hasta - prox + 1).toLocaleString('es-AR') + ' números' : '')
        + (conArca ? ' · lo confirma ARCA al autorizar' : '');
  }
  arca.addEventListener('change', function () { mostrar(true); });
  [clas, letra].forEach(function (e) { e.addEventListener('change', function () { mostrar(false); }); });
  ['punto_venta', 'numero_desde', 'numero_hasta', 'ultimo_emitido'].forEach(function (id) { document.getElementById(id).addEventListener('input', function () { mostrar(false); }); });
  mostrar(false);
})();
</script>

<?php endif; ?>
<?php if ($clasificaciones): ?>
<div class="card">
  <?= card_head('Talonarios') ?>
  <?= barra_local('talonarios.php', 'Talonario, serie o tipo…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="talonario|talonarios">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Talonario</th><th>Serie</th>
        <th class="th-right">Rango</th><th>Próximo</th><th data-no-sort>Uso</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($talonarios as $t): $tid = (int) $t['id_talonario']; $u = tal_uso($t); $usado = tal_usado($t); ?>
        <tr class="<?= (int) $t['habilitado'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'talonarios.php?edit=' . $tid, 'Editar') ?>
            <?= (int) $t['habilitado']
                  ? accion_tal($tid, 'deshabilitar', 'cerrar', 'Deshabilitar', '«' . $t['nombre'] . '» deja de ofrecerse para emitir comprobantes. Lo emitido queda.', true)
                  : accion_tal($tid, 'habilitar', 'ok', 'Habilitar') ?>
            <?= $usado ? '' : accion_tal($tid, 'borrar', 'borrar', 'Eliminar', 'Se elimina «' . $t['nombre'] . '». No emitió ningún comprobante.', true) ?>
          </div></td>
          <td class="td-wrap"><a href="talonarios.php?edit=<?= $tid ?>"><strong><?= h($t['nombre']) ?></strong></a>
            <span class="segunda-linea"><?= h($t['clasificacion']) ?> · <?= h($t['tipo']) ?>, <?= h(tal_lado($t['debito_credito'])) ?></span></td>
          <td class="td-wrap"><span class="mono td-fuerte"><?= h($t['letra'] . ' ' . str_pad((string) $t['punto_venta'], 5, '0', STR_PAD_LEFT)) ?></span>
            <span class="segunda-linea"><?= $t['codigo_arca'] !== null
                ? 'ARCA ' . h($t['codigo_arca'] . ' · ' . $t['arca_descripcion']) . ' · ' . h(strtolower(TAL_AMBIENTES[$t['arca_ambiente']] ?? (string) $t['arca_ambiente']))
                : 'numeración propia' ?></span></td>
          <td class="td-right mono"><?= h(num($t['numero_desde'])) ?> a <?= h(num($t['numero_hasta'])) ?></td>
          <td class="mono"><?= $u['agotado'] ? estado('Agotado', 'danger') : h(tal_formato($t['letra'], (int) $t['punto_venta'], tal_proximo($t))) ?></td>
          <td style="min-width:150px"><?= barra_uso($u) ?>
            <span class="segunda-linea"><?= $usado ? num($u['usados']) . ' usados · ' : 'sin usar · ' ?>quedan <?= num($u['restantes']) ?></span>
            <?= $u['por_agotar'] ? estado('Por agotarse', 'warn') : '' ?></td>
          <td class="td-wrap"><?= (int) $t['habilitado'] ? estado('Habilitado', 'ok') : estado('Deshabilitado', 'neutro') ?>
            <?= $t['codigo_arca'] !== null ? '<span class="segunda-linea">pide CAE a ARCA</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$talonarios): ?><tr><td colspan="7" class="no-data">Todavía no hay talonarios. Creá el primero con «Nuevo talonario».</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
  <div class="bloque">
    <p class="campo-ayuda" style="margin:0">
      Un talonario <strong>con tipo de ARCA</strong> pide CAE por web service y su numeración es la de ARCA: tiene que ser nuevo, uno por punto de venta, tipo y ambiente, y ya usado no se le cambia la serie.
      Uno <strong>sin tipo</strong> numera solo (recibos internos, comprobantes X). Un talonario que ya emitió no se borra: se deshabilita.
      Conviene crear el siguiente antes de que se agote, no el día que se corta la emisión.
    </p>
  </div>
</div>
<?php endif; ?>

<?php elseif ($solapa === 'clasificaciones'):
    $cf = $clasForm ?? ['id_clasificacion' => 0, 'descripcion' => '', 'id_comprobante_tipo' => 0];
    $editando = (int) $cf['id_clasificacion'] > 0;
    if ($clasForm !== null || get_str('nueva') === '1' || !$clasificaciones):
?>
<form method="post" action="talonarios.php?solapa=clasificaciones" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar_clasificacion">
  <input type="hidden" name="id" value="<?= (int) $cf['id_clasificacion'] ?>">
  <div class="card">
    <?= card_head($editando ? 'Editar clasificación' : 'Nueva clasificación', 'agrupa talonarios de un tipo de comprobante',
                  boton('Volver', 'secondary', 'talonarios.php?solapa=clasificaciones', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:6"><label for="descripcion">Descripción <span class="req">*</span></label>
          <input id="descripcion" name="descripcion" value="<?= h($cf['descripcion']) ?>" maxlength="150" required placeholder="Recibos de cobranza, Facturas de intereses…"></div>
        <div class="campo" style="--span:4"><label for="id_comprobante_tipo">Tipo de comprobante <span class="req">*</span></label>
          <select id="id_comprobante_tipo" name="id_comprobante_tipo" required><option value="">Elegí…</option>
            <?php foreach ($tipos as $ct): ?>
              <option value="<?= (int) $ct['id_comprobante_tipo'] ?>" <?= (int) $ct['id_comprobante_tipo'] === (int) $cf['id_comprobante_tipo'] ? 'selected' : '' ?>><?= h($ct['nombre'] . ' (' . tal_lado($ct['debito_credito']) . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda">de acá sale si el comprobante suma o resta en la cuenta del cliente</span></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($editando ? 'Guardar' : 'Agregar', 'primary', null, $editando ? 'guardar' : 'agregar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Clasificaciones', '', boton('Nueva clasificación', 'primary', 'talonarios.php?solapa=clasificaciones&nueva=1', 'agregar', false, true)) ?>
  <?= barra_local('talonarios.php?solapa=clasificaciones', 'Clasificación o tipo…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="clasificación|clasificaciones">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Clasificación</th><th>Tipo de comprobante</th><th class="th-right">Talonarios</th></tr></thead>
      <tbody>
      <?php foreach ($clasificaciones as $c): $cid = (int) $c['id_clasificacion']; ?>
        <tr>
          <td><div class="acciones">
            <?= btn_accion('editar', 'talonarios.php?solapa=clasificaciones&edit=' . $cid, 'Editar') ?>
            <?= btn_accion('agregar', 'talonarios.php?nuevo=1&clasificacion=' . $cid, 'Nuevo talonario de esta clasificación') ?>
            <?= (int) $c['cant'] ? '' : accion_tal($cid, 'borrar_clasificacion', 'borrar', 'Eliminar', 'Se elimina la clasificación «' . $c['descripcion'] . '». No tiene talonarios.', true) ?>
          </div></td>
          <td class="td-fuerte"><?= h($c['descripcion']) ?></td>
          <td><?= h($c['tipo']) ?><span class="segunda-linea"><?= h(tal_lado($c['debito_credito'], true)) ?></span></td>
          <td class="td-right td-wrap" data-orden="<?= (int) $c['cant'] ?>"><?= (int) $c['cant'] ? num($c['cant']) . '<span class="segunda-linea">' . h((string) $c['nombres']) . '</span>' : '<span class="td-muted">ninguno</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clasificaciones): ?><tr><td colspan="4" class="no-data">Todavía no hay clasificaciones. Cargá la primera arriba.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php else: /* tipos */ ?>
<div class="card">
  <?= card_head('Tipos de comprobante', 'de qué lado de la cuenta del cliente va cada uno') ?>
  <?= barra_local('talonarios.php?solapa=tipos', 'Tipo…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="tipo|tipos">
      <thead><tr><th>Tipo</th><th>Lado</th><th class="th-right">Clasificaciones</th></tr></thead>
      <tbody>
      <?php foreach ($tipos as $ct): ?>
        <tr><td class="td-fuerte"><?= h($ct['nombre']) ?></td>
          <td><?= estado(TAL_LADOS[$ct['debito_credito']] ?? $ct['debito_credito'], $ct['debito_credito'] === 'C' ? 'info' : 'neutro') ?>
            <span class="segunda-linea"><?= h(tal_lado($ct['debito_credito'], true)) ?></span></td>
          <td class="td-right" data-orden="<?= (int) $ct['clasif'] ?>"><?= (int) $ct['clasif'] ? num($ct['clasif']) : '<span class="td-muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<div class="card">
  <?= card_head('Tipos de comprobante de ARCA', 'el nomenclador; los que se piden por web service pueden ser el tipo de un talonario') ?>
  <?= barra_local('talonarios.php?solapa=tipos', 'Código, descripción o letra…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="tipo|tipos">
      <thead><tr><th>Código</th><th>Descripción</th><th class="th-center">Letra</th><th>Clase</th>
        <th>Lado</th><th>Web service</th><th class="th-right">Talonarios</th></tr></thead>
      <tbody>
      <?php foreach ($arcaTodos as $a): ?>
        <tr class="<?= (int) $a['activo'] ? '' : 'fila-baja' ?>">
          <td class="mono td-fuerte"><?= h($a['codigo']) ?></td>
          <td class="td-wrap"><?= h($a['descripcion']) ?></td>
          <td class="td-center mono"><?= h((string) ($a['letra'] ?? '—')) ?></td>
          <td><?= h(ARCA_CLASES[$a['clase']] ?? $a['clase']) ?></td>
          <td><?= h(tal_lado($a['debito_credito'])) ?></td>
          <td><?= (int) $a['wsfe'] ? estado('Pide CAE', 'ok') : '<span class="td-muted">—</span>' ?></td>
          <td class="td-right" data-orden="<?= (int) $a['talonarios'] ?>"><?= (int) $a['talonarios'] ? num($a['talonarios']) : '<span class="td-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
