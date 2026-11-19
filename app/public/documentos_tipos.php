<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Catálogo de documentación: qué se le pide a cada cliente.
//   Toda la grilla es un solo formulario: se guardan todos los cambios
//   juntos, en una transacción. Un documento que ya se usó no se borra:
//   se desactiva (deja de pedirse y de ofrecerse, lo cargado queda).
// ---------------------------------------------------------------------

const DESTINATARIOS = ['F' => 'Personas', 'J' => 'Empresas', 'AMBOS' => 'Ambos'];

$errores = [];
$filasForm = null;

/** Arreglo POST como lista de textos. */
function post_arr(string $clave): array
{
    $v = $_POST[$clave] ?? [];
    return is_array($v) ? array_map(function ($x) { return is_string($x) ? trim($x) : ''; }, $v) : [];
}

/** «Recibo de sueldo» -> RECIBO_DE_SUELDO */
function codigo_de(string $nombre): string
{
    $s = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
                         'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
    $s = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_'));
    return substr($s, 0, 30) ?: 'DOC';
}

if (es_post()) {
    csrf_exigir();
    $ids   = post_arr('id');
    $nom   = post_arr('nombre');
    $dest  = post_arr('destinatario');
    $vig   = post_arr('vigencia_dias');
    $ord   = post_arr('orden');
    $oblig = array_map('strval', array_keys((array) ($_POST['obligatorio'] ?? [])));
    $act   = array_map('strval', array_keys((array) ($_POST['activo'] ?? [])));

    $filas = [];
    foreach ($nom as $k => $n) {
        $id = (int) ($ids[$k] ?? 0);
        if ($id === 0 && $n === '') {
            continue;                                  // renglón nuevo vacío
        }
        $f = ['id_tipo_doc' => $id, 'nombre' => $n, 'destinatario' => $dest[$k] ?? 'AMBOS',
              'vigencia_dias' => $vig[$k] ?? '', 'orden' => $ord[$k] ?? '0',
              'obligatorio' => in_array((string) $k, $oblig, true) ? 1 : 0,
              'activo' => $id === 0 || in_array((string) $k, $act, true) ? 1 : 0];
        $r = 'Renglón ' . ($k + 1) . ($n !== '' ? ' («' . $n . '»)' : '') . ': ';
        if ($n === '') {
            $errores[] = $r . 'falta el nombre.';
        }
        if (!isset(DESTINATARIOS[$f['destinatario']])) {
            $errores[] = $r . 'elegí a quién se le pide.';
        }
        if ($f['vigencia_dias'] !== '' && (!ctype_digit($f['vigencia_dias']) || (int) $f['vigencia_dias'] < 1 || (int) $f['vigencia_dias'] > 3650)) {
            $errores[] = $r . 'la vigencia son días, de 1 a 3650 (vacío: no vence).';
        }
        if (!preg_match('/^-?\d{1,4}$/', $f['orden'])) {
            $errores[] = $r . 'el orden es un número.';
        }
        $filas[] = $f;
    }
    $filasForm = $filas;

    if (!$errores) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            foreach ($filas as $f) {
                $v = $f['vigencia_dias'] === '' ? null : (int) $f['vigencia_dias'];
                if ($f['id_tipo_doc'] > 0) {
                    q('UPDATE documentos_tipos SET nombre=?, destinatario=?, obligatorio=?, vigencia_dias=?, orden=?, activo=? WHERE id_tipo_doc=?',
                      [$f['nombre'], $f['destinatario'], $f['obligatorio'], $v, (int) $f['orden'], $f['activo'], $f['id_tipo_doc']]);
                } else {
                    $cod = codigo_de($f['nombre']);
                    $base = substr($cod, 0, 26);
                    for ($i = 2; q1('SELECT 1 AS x FROM documentos_tipos WHERE codigo = ?', [$cod]); $i++) {
                        $cod = $base . '_' . $i;
                    }
                    q('INSERT INTO documentos_tipos (codigo, nombre, destinatario, obligatorio, vigencia_dias, orden, activo) VALUES (?,?,?,?,?,?,1)',
                      [$cod, $f['nombre'], $f['destinatario'], $f['obligatorio'], $v, (int) $f['orden']]);
                }
            }
            $pdo->commit();
            flash('ok', 'Catálogo de documentación guardado.');
            redirigir('documentos_tipos.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = mensaje_bd($e);
        }
    }
}

$tipos = [];
try {
    $tipos = q('SELECT t.*, (SELECT COUNT(*) FROM clientes_documentos d WHERE d.id_tipo_doc = t.id_tipo_doc AND d.baja_el IS NULL) AS usos
                  FROM documentos_tipos t ORDER BY t.activo DESC, t.orden, t.nombre');
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de documentación: corré sql\financiera_documentos.sql.' : error_amigable($e);
}
if ($filasForm !== null) {
    $usos = array_column($tipos, 'usos', 'id_tipo_doc');
    $cods = array_column($tipos, 'codigo', 'id_tipo_doc');
    $tipos = array_map(function ($f) use ($usos, $cods) {
        return $f + ['usos' => $usos[$f['id_tipo_doc']] ?? 0, 'codigo' => $cods[$f['id_tipo_doc']] ?? ''];
    }, $filasForm);
}

function renglon_tipo(array $t, int $k): string
{
    $nuevo = (int) ($t['id_tipo_doc'] ?? 0) === 0;
    return '<tr' . (!$nuevo && !(int) $t['activo'] ? ' class="fila-baja"' : '') . '>'
        . '<td><input type="hidden" name="id[]" value="' . (int) ($t['id_tipo_doc'] ?? 0) . '">'
        . '<input name="nombre[]" value="' . h($t['nombre'] ?? '') . '" maxlength="100" class="celda-input" placeholder="Nombre del documento">'
        . ($nuevo ? '' : '<span class="segunda-linea mono">' . h($t['codigo']) . '</span>') . '</td>'
        . '<td><select name="destinatario[]" class="celda-input">' . opciones(DESTINATARIOS, $t['destinatario'] ?? 'AMBOS') . '</select></td>'
        . '<td class="td-center"><input type="checkbox" name="obligatorio[' . $k . ']" value="1"' . (!empty($t['obligatorio']) ? ' checked' : '') . '></td>'
        . '<td><input name="vigencia_dias[]" value="' . h((string) ($t['vigencia_dias'] ?? '')) . '" inputmode="numeric" class="celda-input derecha" placeholder="no vence" style="width:90px"></td>'
        . '<td><input name="orden[]" value="' . h((string) ($t['orden'] ?? '0')) . '" inputmode="numeric" class="celda-input derecha" style="width:70px"></td>'
        . '<td class="td-center">' . ($nuevo ? '<span class="td-muted">nuevo</span>'
              : '<input type="checkbox" name="activo[' . $k . ']" value="1"' . ((int) $t['activo'] ? ' checked' : '') . '>') . '</td>'
        . '<td class="td-right">' . ($nuevo ? '' : ((int) $t['usos'] ?: '<span class="td-muted">—</span>')) . '</td>'
        . '</tr>';
}

layout_inicio('Documentación requerida', 'clientes');
titulo('Documentación requerida', 'Qué se le pide a cada cliente, si es obligatorio y cuánto dura', '',
       '<a href="clientes.php">Clientes</a> / Documentación requerida');
mensajes($errores);
?>

<form method="post" action="documentos_tipos.php">
  <?= csrf_campo() ?>
  <div class="card">
    <?= card_head('Documentos', count($tipos) . ' en el catálogo') ?>
    <div class="data-table-wrap">
      <table class="data-table tabla-edicion" data-sin-orden>
        <thead>
          <tr>
            <th data-no-sort>Documento</th><th data-no-sort>Se pide a</th><th class="th-center" data-no-sort>Obligatorio</th>
            <th data-no-sort title="Si al subirlo no se indica vencimiento, se calcula: emisión + estos días">Vigencia (días)</th>
            <th data-no-sort>Orden</th><th class="th-center" data-no-sort>Activo</th><th class="th-right" data-no-sort>Cargados</th>
          </tr>
        </thead>
        <tbody id="tipos">
          <?php $k = 0; foreach ($tipos as $t) { echo renglon_tipo($t, $k++); } ?>
          <?= renglon_tipo(['orden' => 500], $k) ?>
        </tbody>
      </table>
    </div>
    <div class="bloque">
      <p class="campo-ayuda" style="margin:0">
        <strong>Obligatorio</strong>: entra en la lista de la ficha y en el estado «Completa / Incompleta» del listado.
        <strong>Vigencia</strong>: vacío si no vence (DNI, estatuto). <strong>Activo</strong>: destildado deja de pedirse y de ofrecerse al subir; lo ya cargado queda.
        Para agregar uno, completá el último renglón.
      </p>
    </div>
    <div class="form-acciones">
      <?= boton('Volver', 'secondary', 'clientes.php', 'volver') ?>
      <?= boton('Guardar cambios', 'primary', null, 'guardar', true) ?>
    </div>
  </div>
</form>

<?php layout_fin(); ?>
