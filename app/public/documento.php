<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/documentos.php';

// ---------------------------------------------------------------------
// Documentación del cliente
//   GET  documento.php?id=N            muestra el archivo (PDF / imagen)
//   GET  documento.php?id=N&bajar=1    lo descarga
//   POST documento.php?cliente=N       accion=subir: uno o más archivos de un tipo
//                                      accion=baja:  da de baja un documento
// Después de un POST vuelve a la ficha del cliente, a la tarjeta Documentación.
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Ver / descargar
// ---------------------------------------------------------------------
if (!es_post()) {
    $id = (int) get_str('id');
    try {
        $d = $id > 0 ? q1('SELECT * FROM clientes_documentos WHERE id_documento = ?', [$id]) : null;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit(error_amigable($e));
    }
    $ruta = $d ? doc_ruta_absoluta($d['archivo']) : null;
    if (!$d || !$ruta || !in_array($d['mime'], DOC_TIPOS_ARCHIVO, true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit($d ? 'El archivo no está en la carpeta de documentos (' . docs_dir() . ').' : 'No existe ese documento.');
    }

    $nombre = $d['nombre_original'];
    $ascii  = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nombre);
    $modo   = get_str('bajar') !== '' ? 'attachment' : 'inline';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $d['mime']);
    header('Content-Length: ' . filesize($ruta));
    header('Content-Disposition: ' . $modo . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($ruta);
    exit;
}

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
$idCliente = (int) get_str('cliente');
$volver    = 'cliente.php?id=' . $idCliente . '&doc=1#documentos';

// Si el pedido supera post_max_size, PHP descarta todo el POST (también el
// token): se avisa en vez de mostrar «el formulario venció».
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('danger', 'Los archivos juntos superan lo que acepta el servidor (' . ini_get('post_max_size') . '). Subilos de a menos.');
    redirigir($volver);
}
csrf_exigir();

try {
    $cliente = $idCliente > 0 ? q1('SELECT id_cliente, tipo, denominacion FROM clientes WHERE id_cliente = ?', [$idCliente]) : null;
} catch (Throwable $e) {
    flash('danger', error_amigable($e));
    redirigir($volver);
}
if (!$cliente) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No existe ese cliente.');
}

$accion = post_str('accion');

// ── Dar de baja ──────────────────────────────────────────────────────
if ($accion === 'baja') {
    try {
        $st = db()->prepare('UPDATE clientes_documentos SET baja_el = NOW(), baja_motivo = ?, baja_por = ?
                              WHERE id_documento = ? AND id_cliente = ? AND baja_el IS NULL');
        $st->execute([post_str('motivo') ?: null, usuario_id(), (int) post_str('id_documento'), $idCliente]);
        flash($st->rowCount() ? 'ok' : 'warning', $st->rowCount() ? 'Documento dado de baja. El archivo se conserva.' : 'Ese documento ya estaba dado de baja.');
    } catch (Throwable $e) {
        flash('danger', mensaje_bd($e));
    }
    redirigir($volver);
}

// ── Reactivar ────────────────────────────────────────────────────────
if ($accion === 'reactivar') {
    try {
        q('UPDATE clientes_documentos SET baja_el = NULL, baja_por = NULL, baja_motivo = NULL
            WHERE id_documento = ? AND id_cliente = ?', [(int) post_str('id_documento'), $idCliente]);
        flash('ok', 'Documento reactivado.');
    } catch (Throwable $e) {
        flash('danger', mensaje_bd($e));
    }
    redirigir($volver);
}

if ($accion !== 'subir') {
    redirigir($volver);
}

// ── Subir ────────────────────────────────────────────────────────────
$idTipo  = (int) post_str('id_tipo_doc');
$emision = post_str('fecha_emision');
$vto     = post_str('fecha_vencimiento');
$obs     = post_str('observacion');
$errores = [];

try {
    $tipoDoc = q1("SELECT * FROM documentos_tipos WHERE id_tipo_doc = ? AND activo = 1 AND destinatario IN (?, 'AMBOS')",
                  [$idTipo, $cliente['tipo']]);
} catch (Throwable $e) {
    flash('danger', error_amigable($e));
    redirigir($volver);
}
$archivos = doc_archivos_subidos('archivos');

if (!$tipoDoc) {
    $errores[] = 'Elegí qué documento es.';
}
if (!$archivos) {
    $errores[] = 'Elegí al menos un archivo.';
} elseif (count($archivos) > 10) {
    $errores[] = 'Subí hasta 10 archivos por vez.';
}
if ($emision !== '' && (leer_fecha($emision) === null || leer_fecha($emision) > date('Y-m-d'))) {
    $errores[] = 'La fecha de emisión no es válida.';
}
if ($vto !== '' && leer_fecha($vto) === null) {
    $errores[] = 'La fecha de vencimiento no es válida.';
}
if (strlen($obs) > 200) {
    $obs = substr($obs, 0, 200);
}
if ($errores) {
    flash('danger', implode(' ', $errores));
    redirigir($volver);
}

$emision = $emision !== '' ? leer_fecha($emision) : null;
$vto     = $vto !== '' ? leer_fecha($vto) : null;
$vtoCalc = false;
if ($vto === null && $tipoDoc['vigencia_dias']) {
    $vto = date('Y-m-d', strtotime(($emision ?? date('Y-m-d')) . ' +' . (int) $tipoDoc['vigencia_dias'] . ' days'));
    $vtoCalc = true;
}

$carpetaRel = 'clientes/' . $idCliente;
$carpeta    = docs_dir() . DIRECTORY_SEPARATOR . 'clientes' . DIRECTORY_SEPARATOR . $idCliente;
if (!is_dir($carpeta) && !@mkdir($carpeta, 0770, true) && !is_dir($carpeta)) {
    flash('danger', 'No se pudo crear la carpeta ' . $carpeta . '. El grupo de aplicaciones de IIS necesita permiso de modificación sobre ' . docs_dir() . '.');
    redirigir($volver);
}

$max = docs_max_bytes();
$ok = [];
$rechazados = [];
foreach ($archivos as $a) {
    $nombre = doc_nombre_limpio($a['name']);
    if ($a['error'] !== UPLOAD_ERR_OK) {
        $rechazados[] = $nombre . ': ' . doc_error_subida($a['error']);
        continue;
    }
    if (!is_uploaded_file($a['tmp_name'])) {
        $rechazados[] = $nombre . ': no se pudo subir';
        continue;
    }
    if ($a['size'] <= 0) {
        $rechazados[] = $nombre . ': está vacío';
        continue;
    }
    if ($a['size'] > $max) {
        $rechazados[] = $nombre . ': pesa ' . bytes_txt($a['size']) . ' y el máximo es ' . bytes_txt($max);
        continue;
    }
    $ext = doc_detectar_tipo($a['tmp_name']);
    if ($ext === null) {
        $rechazados[] = $nombre . ': no es un PDF ni una imagen JPG, PNG o WEBP';
        continue;
    }
    $sha = hash_file('sha256', $a['tmp_name']);
    try {
        $dup = q1('SELECT d.id_documento, t.nombre FROM clientes_documentos d JOIN documentos_tipos t ON t.id_tipo_doc = d.id_tipo_doc
                    WHERE d.id_cliente = ? AND d.sha256 = ? AND d.baja_el IS NULL LIMIT 1', [$idCliente, $sha]);
    } catch (Throwable $e) {
        $rechazados[] = $nombre . ': ' . error_amigable($e);
        continue;
    }
    if ($dup) {
        $rechazados[] = $nombre . ': ya está cargado como «' . $dup['nombre'] . '»';
        continue;
    }

    $rel  = $carpetaRel . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $carpeta . DIRECTORY_SEPARATOR . basename($rel);
    if (!@move_uploaded_file($a['tmp_name'], $dest)) {
        $rechazados[] = $nombre . ': no se pudo guardar en ' . $carpeta . ' (permisos de la carpeta)';
        continue;
    }
    try {
        q('INSERT INTO clientes_documentos (id_cliente, id_tipo_doc, nombre_original, archivo, mime, bytes, sha256,
                                            fecha_emision, fecha_vencimiento, observacion, subido_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$idCliente, $idTipo, $nombre, $rel, DOC_TIPOS_ARCHIVO[$ext], $a['size'], $sha, $emision, $vto, $obs ?: null, usuario_id()]);
        $ok[] = $nombre;
    } catch (Throwable $e) {
        @unlink($dest);                      // sin registro no queda archivo huérfano
        $rechazados[] = $nombre . ': ' . mensaje_bd($e);
    }
}

$msg = '';
if ($ok) {
    $msg = (count($ok) === 1 ? 'Se cargó ' . $ok[0] : 'Se cargaron ' . count($ok) . ' archivos') . ' como «' . $tipoDoc['nombre'] . '»'
         . ($vtoCalc ? ', vence el ' . fecha($vto) . ' (' . (int) $tipoDoc['vigencia_dias'] . ' días)' : '') . '.';
    if ($vto !== null && $vto < date('Y-m-d')) {
        $msg .= ' Ojo: ya está vencido, no cuenta como presentado.';
    }
}
if ($rechazados) {
    $msg .= ($msg ? ' ' : '') . 'No se cargaron: ' . implode('; ', $rechazados) . '.';
}
$vencido = $ok && $vto !== null && $vto < date('Y-m-d');
flash($rechazados ? ($ok ? 'warning' : 'danger') : ($vencido ? 'warning' : 'ok'), $msg);
redirigir($volver);
