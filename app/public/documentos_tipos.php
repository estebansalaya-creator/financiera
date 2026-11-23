<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Catálogo de documentación: qué se le pide a cada cliente.
//   documentos_tipos.php            listado
//   documentos_tipos.php?nuevo=1    alta
//   documentos_tipos.php?edit=N     edición
//   «Para aprobar» dice si el documento es OBLIGATORIO (sin él cargado y
//   sin vencer no se aprueba una solicitud de préstamo) u OPCIONAL (se
//   pide y se muestra, pero no frena la aprobación).
//   Un documento que ya se cargó alguna vez no se elimina: se desactiva
//   (deja de pedirse y de ofrecerse; lo cargado queda).
//   Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

const DESTINATARIOS = ['F' => 'Personas', 'J' => 'Empresas', 'AMBOS' => 'Ambos'];
const PARA_APROBAR  = ['1' => 'Obligatoria', '0' => 'Opcional'];

$errores = [];
$reg = null;
$id = (int) (post_str('id') ?: get_str('edit'));

/** «Recibo de sueldo» -> RECIBO_DE_SUELDO */
function codigo_de(string $nombre): string
{
    $s = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
                         'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
    $s = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_'));
    return substr($s, 0, 30) ?: 'DOC';
}

/** Cuántos archivos se cargaron de un documento (vigentes y dados de baja). */
function usos_doc(int $id): int
{
    return (int) q1('SELECT COUNT(*) AS n FROM clientes_documentos WHERE id_tipo_doc = ?', [$id])['n'];
}

$fDest  = isset(DESTINATARIOS[get_str('dest')]) ? get_str('dest') : '';
$fAprob = isset(PARA_APROBAR[get_str('aprobar')]) ? get_str('aprobar') : '';
$fEstado = in_array(get_str('estado'), ['0', 'todos'], true) ? get_str('estado') : '1';
$volverQs = http_build_query(array_filter(['dest' => $fDest ?: null, 'aprobar' => $fAprob !== '' ? $fAprob : null,
                                           'estado' => $fEstado !== '1' ? $fEstado : null], function ($x) { return $x !== null; }));
$lista = 'documentos_tipos.php' . ($volverQs !== '' ? '?' . $volverQs : '');

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $actual = $id ? q1('SELECT * FROM documentos_tipos WHERE id_tipo_doc = ?', [$id]) : null;
    if ($id && !$actual) {
        flash('danger', 'El documento ya no existe.');
        redirigir('documentos_tipos.php');
    }
    if (in_array($accion, ['activar', 'desactivar'], true) && $actual) {
        q('UPDATE documentos_tipos SET activo = ? WHERE id_tipo_doc = ?', [$accion === 'activar' ? 1 : 0, $id]);
        flash('ok', '«' . $actual['nombre'] . '» ' . ($accion === 'activar' ? 'vuelve a pedirse.' : 'deja de pedirse y de ofrecerse al subir; lo cargado queda.'));
        redirigir($lista);
    }
    if ($accion === 'borrar' && $actual) {
        if (usos_doc($id)) {
            flash('danger', 'No se puede eliminar «' . $actual['nombre'] . '»: ya se cargó para algún cliente. Desactivalo.');
        } else {
            q('DELETE FROM documentos_tipos WHERE id_tipo_doc = ?', [$id]);
            flash('ok', '«' . $actual['nombre'] . '» eliminado del catálogo.');
        }
        redirigir($lista);
    }
    if ($accion === 'guardar') {
        $reg = ['id_tipo_doc' => $id ?: null, 'codigo' => $actual['codigo'] ?? '',
                'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))),
                'destinatario' => post_str('destinatario'), 'obligatorio' => post_str('obligatorio') === '1' ? 1 : 0,
                'vigencia_dias' => post_str('vigencia_dias'), 'orden' => post_str('orden') ?: '0',
                'activo' => post_str('activo') === '1' ? 1 : 0];
        if ($reg['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        } elseif (mb_strlen($reg['nombre']) > 100) {
            $errores[] = 'El nombre va hasta 100 caracteres.';
        }
        if (!isset(DESTINATARIOS[$reg['destinatario']])) {
            $errores[] = 'Elegí a quién se le pide.';
        }
        if ($reg['vigencia_dias'] !== '' && (!ctype_digit($reg['vigencia_dias']) || (int) $reg['vigencia_dias'] < 1 || (int) $reg['vigencia_dias'] > 3650)) {
            $errores[] = 'La vigencia son días, de 1 a 3650 (vacío: no vence).';
        }
        if (!preg_match('/^-?\d{1,4}$/', $reg['orden'])) {
            $errores[] = 'El orden es un número.';
        }
        if (!$errores) {
            $vig = $reg['vigencia_dias'] === '' ? null : (int) $reg['vigencia_dias'];
            try {
                if ($id) {
                    q('UPDATE documentos_tipos SET nombre = ?, destinatario = ?, obligatorio = ?, vigencia_dias = ?, orden = ?, activo = ? WHERE id_tipo_doc = ?',
                      [$reg['nombre'], $reg['destinatario'], $reg['obligatorio'], $vig, (int) $reg['orden'], $reg['activo'], $id]);
                } else {
                    $cod = codigo_de($reg['nombre']);
                    $base = substr($cod, 0, 26);
                    for ($i = 2; q1('SELECT 1 AS x FROM documentos_tipos WHERE codigo = ?', [$cod]); $i++) {
                        $cod = $base . '_' . $i;
                    }
                    q('INSERT INTO documentos_tipos (codigo, nombre, destinatario, obligatorio, vigencia_dias, orden, activo) VALUES (?,?,?,?,?,?,?)',
                      [$cod, $reg['nombre'], $reg['destinatario'], $reg['obligatorio'], $vig, (int) $reg['orden'], $reg['activo']]);
                }
                flash('ok', '«' . $reg['nombre'] . '» ' . ($id ? 'guardado.' : 'agregado al catálogo.') . ' Vale desde ahora para todas las solicitudes pendientes.');
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
$tipos = [];
try {
    if ($id && $reg === null) {
        $reg = q1('SELECT * FROM documentos_tipos WHERE id_tipo_doc = ?', [$id]);
        if (!$reg) {
            flash('danger', 'El documento no existe.');
            redirigir('documentos_tipos.php');
        }
    } elseif ($reg === null && get_str('nuevo') !== '') {
        $reg = ['id_tipo_doc' => null, 'codigo' => '', 'nombre' => '', 'destinatario' => 'AMBOS', 'obligatorio' => 0,
                'vigencia_dias' => '', 'orden' => '500', 'activo' => 1];
    }
    $where = [];
    $par = [];
    if ($fDest !== '') {
        $where[] = 't.destinatario = ?';
        $par[] = $fDest;
    }
    if ($fAprob !== '') {
        $where[] = 't.obligatorio = ?';
        $par[] = (int) $fAprob;
    }
    if ($fEstado !== 'todos') {
        $where[] = 't.activo = ?';
        $par[] = (int) $fEstado;
    }
    $tipos = q('SELECT t.*, (SELECT COUNT(*) FROM clientes_documentos d WHERE d.id_tipo_doc = t.id_tipo_doc AND d.baja_el IS NULL) AS cargados,
                       (SELECT COUNT(*) FROM clientes_documentos d WHERE d.id_tipo_doc = t.id_tipo_doc) AS usos
                  FROM documentos_tipos t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
                 ORDER BY t.activo DESC, t.orden, t.nombre', $par);
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de documentación: corré sql\financiera_documentos.sql.' : error_amigable($e);
}
$hayFiltros = $fDest !== '' || $fAprob !== '' || $fEstado !== '1';

layout_inicio('Documentación requerida', 'clientes');
titulo('Documentación requerida', 'Qué se le pide a cada cliente, si es obligatoria u opcional para aprobar y cuánto dura',
       ($reg ? '' : boton('Nuevo documento', 'primary', 'documentos_tipos.php?nuevo=1', 'agregar'))
       . (puede('solicitudes.php') ? ' ' . boton('Instructivo para comercializadores', 'pdf', 'solicitudes.php?instructivo=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener']) : ''),
       '<a href="clientes.php">Clientes</a> / Documentación requerida');
mensajes($errores);

if ($reg):
    $esNuevo = !$reg['id_tipo_doc'];
?>
<form method="post" action="documentos_tipos.php" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $reg['id_tipo_doc'] ?>">
  <div class="card">
    <?= card_head($esNuevo ? 'Nuevo documento' : $reg['nombre'], $esNuevo ? 'el código se arma solo con el nombre' : 'código ' . $reg['codigo'],
                  boton('Volver', 'secondary', $lista, 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:5"><label for="nombre">Documento <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($reg['nombre']) ?>" maxlength="100" required placeholder="Recibo de sueldo"></div>
        <div class="campo" style="--span:2"><label for="destinatario">Se pide a</label>
          <select id="destinatario" name="destinatario"><?= opciones(DESTINATARIOS, $reg['destinatario']) ?></select></div>
        <div class="campo" style="--span:2"><label for="obligatorio">Para aprobar</label>
          <select id="obligatorio" name="obligatorio"><?= opciones(PARA_APROBAR, (int) $reg['obligatorio'] ? '1' : '0') ?></select></div>
        <div class="campo" style="--span:2"><label for="vigencia_dias">Vigencia</label>
          <input id="vigencia_dias" name="vigencia_dias" value="<?= h((string) ($reg['vigencia_dias'] ?? '')) ?>" inputmode="numeric" class="derecha" placeholder="no vence">
          <small class="campo-ayuda">días</small></div>
        <div class="campo" style="--span:1"><label for="orden">Orden</label>
          <input id="orden" name="orden" value="<?= h((string) $reg['orden']) ?>" inputmode="numeric" class="derecha"></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $reg['activo'] ? 'checked' : '' ?>> Activo</label>
          <small class="campo-ayuda">Inactivo deja de pedirse; lo cargado queda.</small></div>
      </div>
      <p class="campo-ayuda" style="margin:8px 0 0">
        <strong>Obligatoria</strong>: sin ese documento cargado y sin vencer, la solicitud de préstamo no se puede aprobar; además entra en el estado «Completa / Incompleta» del cliente.
        <strong>Opcional</strong>: se pide y se ve en la solicitud, pero no frena la aprobación.
        <strong>Vigencia</strong>: si al subirlo no se indica vencimiento, se calcula con la emisión más estos días; vacía si no vence (DNI, estatuto).
      </p>
    </div>
    <div class="form-acciones"><?= boton($esNuevo ? 'Agregar' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <?= card_head('Documentos') ?>
  <?= barra_local('documentos_tipos.php', 'Documento o código…',
                  filtro_select('dest', 'Se pide a', DESTINATARIOS, $fDest, 'Todos', $fDest !== '')
                  . filtro_select('aprobar', 'Para aprobar', PARA_APROBAR, $fAprob, 'Todas', $fAprob !== '')
                  . filtro_select('estado', 'Estado', ['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'], $fEstado, '', $fEstado !== '1'),
                  $hayFiltros) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="documento|documentos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Documento</th><th>Se pide a</th>
        <th title="Obligatoria: sin ella cargada y sin vencer no se puede aprobar una solicitud de préstamo">Para aprobar</th>
        <th class="th-right">Vigencia</th><th class="th-right">Orden</th><th class="th-right">Cargados</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($tipos as $t): $tid = (int) $t['id_tipo_doc']; ?>
        <tr class="<?= (int) $t['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'documentos_tipos.php?edit=' . $tid, 'Editar') ?>
            <?= (int) $t['activo']
                  ? accion_post($lista, ['accion' => 'desactivar', 'id' => $tid], 'cerrar', 'Desactivar', '«' . $t['nombre'] . '» deja de pedirse y de ofrecerse al subir. Lo cargado queda.', 'borrar')
                  : accion_post($lista, ['accion' => 'activar', 'id' => $tid], 'ok', 'Activar') ?>
            <?= !(int) $t['usos'] ? accion_post($lista, ['accion' => 'borrar', 'id' => $tid], 'borrar', 'Eliminar', 'Se elimina «' . $t['nombre'] . '» del catálogo. No se cargó para ningún cliente.', 'borrar') : '' ?>
          </div></td>
          <td class="td-fuerte"><a href="documentos_tipos.php?edit=<?= $tid ?>"><?= h($t['nombre']) ?></a><span class="segunda-linea mono"><?= h($t['codigo']) ?></span></td>
          <td><?= h(DESTINATARIOS[$t['destinatario']] ?? $t['destinatario']) ?></td>
          <td data-orden="<?= (int) $t['obligatorio'] ?>"><?= (int) $t['obligatorio'] ? estado('Obligatoria', 'danger') : estado('Opcional', 'neutro') ?></td>
          <td class="td-right" data-orden="<?= (int) ($t['vigencia_dias'] ?? 99999) ?>"><?= $t['vigencia_dias'] !== null ? num($t['vigencia_dias']) . ' días' : '<span class="td-muted">no vence</span>' ?></td>
          <td class="td-right"><?= (int) $t['orden'] ?></td>
          <td class="td-right" data-orden="<?= (int) $t['cargados'] ?>"><?= (int) $t['cargados'] ? num($t['cargados']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $t['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tipos): ?><tr><td colspan="8" class="no-data"><?= $hayFiltros ? 'Ningún documento con esos filtros.' : 'El catálogo está vacío. Cargá el primero con «Nuevo documento».' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php layout_fin(); ?>
