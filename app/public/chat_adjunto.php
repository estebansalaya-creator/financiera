<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/chat.php';

// ---------------------------------------------------------------------
// Archivo adjunto de un mensaje
//   chat_adjunto.php?m=N          PDF, imágenes y audio se muestran; el resto se descarga
//   chat_adjunto.php?m=N&bajar=1  siempre se descarga
// Solo para quien puede ver la conversación (chat_conversacion), y no si
// el autor retiró el mensaje.
// ---------------------------------------------------------------------

function cortar(int $http, string $texto): void
{
    http_response_code($http);
    header('Content-Type: text/plain; charset=utf-8');
    exit($texto);
}

$id = (int) get_str('m');
try {
    $m = $id > 0 ? q1('SELECT id_conversacion, adjunto_archivo, adjunto_nombre, adjunto_mime, borrado_el
                         FROM chat_mensajes WHERE id_mensaje = ?', [$id]) : null;
    $c = $m ? chat_conversacion((int) $m['id_conversacion']) : null;
} catch (Throwable $e) {
    cortar(500, error_amigable($e));
}
if (!$m || !$c || $m['adjunto_archivo'] === null || $m['borrado_el'] !== null) {
    cortar(404, 'No existe ese archivo o no participás de la conversación.');
}
$ext = strtolower(pathinfo($m['adjunto_archivo'], PATHINFO_EXTENSION));
$ruta = doc_ruta_absoluta($m['adjunto_archivo']);
if (!$ruta || !isset(CHAT_TIPOS_ARCHIVO[$ext])) {
    cortar(404, 'El archivo no está en la carpeta de documentos (' . docs_dir() . ').');
}

[$mime, $enLinea] = CHAT_TIPOS_ARCHIVO[$ext];
$nombre = $m['adjunto_nombre'];
$ascii  = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nombre);
$modo   = $enLinea && get_str('bajar') === '' ? 'inline' : 'attachment';

while (ob_get_level() > 0) {
    ob_end_clean();
}
// Pedidos por partes (Range): el iPhone no reproduce un audio si el
// servidor no los acepta, y sirven para adelantar y atrasar.
$tam = (int) filesize($ruta);
$ini = 0;
$fin = $tam - 1;
$rango = $_SERVER['HTTP_RANGE'] ?? '';
if (is_string($rango) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rango), $r) && ($r[1] !== '' || $r[2] !== '')) {
    if ($r[1] === '') {
        $ini = max(0, $tam - (int) $r[2]);          // los últimos N bytes
    } else {
        $ini = (int) $r[1];
        if ($r[2] !== '') {
            $fin = min($fin, (int) $r[2]);
        }
    }
    if ($ini > $fin || $ini >= $tam) {
        http_response_code(416);
        header('Content-Range: bytes */' . $tam);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $ini . '-' . $fin . '/' . $tam);
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($fin - $ini + 1));
header('Content-Disposition: ' . $modo . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$fh = fopen($ruta, 'rb');
fseek($fh, $ini);
$falta = $fin - $ini + 1;
while ($falta > 0 && !feof($fh)) {
    $trozo = (string) fread($fh, (int) min(65536, $falta));
    if ($trozo === '') {
        break;
    }
    echo $trozo;
    $falta -= strlen($trozo);
}
fclose($fh);
exit;
