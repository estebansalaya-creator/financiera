<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Documentación de clientes: dónde quedan los archivos y cómo se validan.
//
//   docs_dir (config.php)  carpeta fuera de public\, por defecto
//                          D:\financiera\documentos. El grupo de
//                          aplicaciones de IIS necesita permiso de
//                          modificación ahí (instalar_iis.ps1 lo da).
//   clientes\<id>\<32 hex>.<ext>   un archivo por documento, nombre al azar:
//                          no se puede adivinar ni pisar.
//
// El tipo se decide por el contenido (los primeros bytes), no por la
// extensión ni por lo que diga el navegador: un .exe renombrado a .pdf no
// entra. Se aceptan PDF e imágenes, que el navegador muestra sin plugins.
// ---------------------------------------------------------------------

const DOC_TIPOS_ARCHIVO = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

function docs_dir(): string
{
    $d = (string) cfg('docs_dir', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'documentos');
    return rtrim($d, '\\/');
}

/** Tamaño máximo por archivo en bytes (config docs_max_mb, por defecto 10). */
function docs_max_bytes(): int
{
    return (int) round(((float) cfg('docs_max_mb', 10)) * 1024 * 1024);
}

/** 'pdf' | 'jpg' | 'png' | 'webp' según los primeros bytes, o null. */
function doc_detectar_tipo(string $ruta): ?string
{
    $fh = @fopen($ruta, 'rb');
    if (!$fh) {
        return null;
    }
    $b = (string) fread($fh, 16);
    fclose($fh);
    if (strncmp($b, '%PDF-', 5) === 0) {
        return 'pdf';
    }
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strncmp($b, "\x89PNG\r\n\x1A\n", 8) === 0) {
        return 'png';
    }
    if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

/** Texto para el error de subida de PHP. */
function doc_error_subida(int $cod): string
{
    switch ($cod) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'supera el tamaño máximo que acepta el servidor';
        case UPLOAD_ERR_PARTIAL:
            return 'llegó incompleto, probá de nuevo';
        case UPLOAD_ERR_NO_FILE:
            return 'no se eligió ningún archivo';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'el servidor no pudo guardarlo (carpeta temporal de PHP)';
        default:
            return 'no se pudo subir (error ' . $cod . ')';
    }
}

/** $_FILES[campo] con multiple → lista de [name, tmp_name, size, error]. */
function doc_archivos_subidos(string $campo): array
{
    $f = $_FILES[$campo] ?? null;
    if (!is_array($f) || !isset($f['name'])) {
        return [];
    }
    $out = [];
    foreach ((array) $f['name'] as $i => $nombre) {
        $err = (int) (((array) $f['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE && (string) $nombre === '') {
            continue;
        }
        $out[] = [
            'name'     => (string) $nombre,
            'tmp_name' => (string) (((array) $f['tmp_name'])[$i] ?? ''),
            'size'     => (int) (((array) $f['size'])[$i] ?? 0),
            'error'    => $err,
        ];
    }
    return $out;
}

/** Nombre original limpio para guardar y para mostrar. */
function doc_nombre_limpio(string $n): string
{
    $n = basename(str_replace('\\', '/', $n));
    $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n) ?? '';
    $n = trim($n);
    if ($n === '') {
        $n = 'documento';
    }
    // 200 caracteres (no bytes), sin depender de mbstring
    return preg_match('/^.{0,200}/us', $n, $m) ? $m[0] : substr($n, 0, 200);
}

/** Ruta absoluta de un archivo guardado, verificando que esté dentro de docs_dir. */
function doc_ruta_absoluta(string $relativa): ?string
{
    if ($relativa === '' || strpos($relativa, '..') !== false) {
        return null;
    }
    $base = realpath(docs_dir());
    $ruta = realpath(docs_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa));
    if ($base === false || $ruta === false || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
        return null;
    }
    return $ruta;
}

/** 1536 -> "1,5 KB" */
function bytes_txt(int $n): string
{
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1024 * 1024) {
        return num($n / 1024, 1) . ' KB';
    }
    return num($n / 1024 / 1024, 1) . ' MB';
}

/** Tipos de documento que se le pueden cargar a un cliente F o J. */
function doc_tipos_para(string $tipoCliente): array
{
    return q("SELECT id_tipo_doc, codigo, nombre, obligatorio, vigencia_dias
                FROM documentos_tipos
               WHERE activo = 1 AND destinatario IN (?, 'AMBOS')
               ORDER BY obligatorio DESC, orden, nombre", [$tipoCliente]);
}
