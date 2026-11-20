<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/chat.php';

// ---------------------------------------------------------------------
// Archivo adjunto de un mensaje
//   chat_adjunto.php?m=N          PDF e imágenes se muestran; el resto se descarga
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
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . $modo . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
exit;
