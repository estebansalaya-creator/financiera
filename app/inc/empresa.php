<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';

// ---------------------------------------------------------------------
// Datos de la empresa (tabla empresa, una sola fila) y su logo.
//   empresa()             los datos, sin los BLOB (una consulta por pedido)
//   empresa_nombre()      el nombre corto: fantasía, si no la razón social
//   empresa_logo_url()    logo.php?v=… o null si no hay logo
//   empresa_logo_pdf()    el logo listo para PdfDoc::imagen(), o null
//   logo_preparar()       valida un PNG/JPG subido y lo prepara para PDF
// Si la tabla todavía no existe, todo devuelve «Financiera» sin logo: la
// aplicación sigue andando hasta que se corra sql\financiera_empresa.sql.
// ---------------------------------------------------------------------

const EMPRESA_COND_IVA = ['RI' => 'Responsable inscripto', 'MONO' => 'Monotributista', 'EX' => 'Exento'];
const LOGO_MAX_BYTES   = 1048576;       // 1 MB
const LOGO_MAX_LADO    = 2000;          // px
const LOGO_MAX_PIXELES = 1500000;       // para separar la transparencia sin GD

/** Íconos de la app (PWA): clave => [columna, lado en px, archivo estándar en assets/iconos]. */
const ICONOS_APP = [
    '32'   => ['app_icono_32', 32, 'favicon-32.png'],
    '180'  => ['app_icono_180', 180, 'apple-touch-icon.png'],
    '192'  => ['app_icono_192', 192, 'icono-192.png'],
    '512'  => ['app_icono_512', 512, 'icono-512.png'],
    'mask' => ['app_icono_mask', 512, 'icono-maskable-512.png'],
];

function empresa(bool $recargar = false): array
{
    static $e = null;
    if ($e !== null && !$recargar) {
        return $e;
    }
    $e = ['razon_social' => 'Financiera', 'nombre_fantasia' => null, 'tiene_logo' => 0, 'existe' => false];
    try {
        $r = q1('SELECT id_empresa, razon_social, nombre_fantasia, cuit, condicion_iva, ingresos_brutos, inicio_actividades,
                        domicilio, localidad, provincia, codigo_postal, telefono, email, sitio_web, bcra_codigo,
                        whatsapp_numero, whatsapp_mensaje, whatsapp_phone_id, whatsapp_waba_id,
                        logo_tipo, logo_ancho, logo_alto, logo_actualizado_el, actualizado_el, actualizado_por,
                        app_icono_usar, app_icono_fondo, app_icono_actualizado_el, app_icono_512 IS NOT NULL AS tiene_icono,
                        logo IS NOT NULL AS tiene_logo, logo_pdf IS NOT NULL AS tiene_logo_pdf
                   FROM empresa WHERE id_empresa = 1');
        if ($r) {
            $e = $r + ['existe' => true];
        }
    } catch (Throwable $ex) {
        // sin tabla o sin base: quedan los valores por defecto
    }
    return $e;
}

function empresa_nombre(): string
{
    $e = empresa();
    $n = trim((string) ($e['nombre_fantasia'] ?? ''));
    return $n !== '' ? $n : (trim((string) ($e['razon_social'] ?? '')) ?: 'Financiera');
}

function empresa_logo_url(): ?string
{
    $e = empresa();
    if (empty($e['tiene_logo'])) {
        return null;
    }
    return 'logo.php?v=' . (strtotime((string) ($e['logo_actualizado_el'] ?? '')) ?: 0);
}

/** Dirección en una línea: «Av. Corrientes 1234, piso 5 · C1043 CABA, Buenos Aires». */
function empresa_domicilio(): string
{
    $e = empresa();
    $loc = trim(trim((string) ($e['codigo_postal'] ?? '')) . ' ' . trim((string) ($e['localidad'] ?? '')));
    $prov = trim((string) ($e['provincia'] ?? ''));
    if ($prov !== '' && stripos($loc, $prov) === false) {
        $loc = $loc !== '' ? $loc . ', ' . $prov : $prov;
    }
    return implode(' · ', array_filter([trim((string) ($e['domicilio'] ?? '')), $loc], 'strlen'));
}

/** Renglones para el encabezado de los PDF (sin el nombre). */
function empresa_renglones_pdf(): array
{
    $e = empresa();
    $l1 = [];
    if (!empty($e['cuit'])) {
        $l1[] = 'CUIT ' . cuit_fmt((string) $e['cuit']);
    }
    if (!empty($e['condicion_iva']) && isset(EMPRESA_COND_IVA[$e['condicion_iva']])) {
        $l1[] = 'IVA ' . EMPRESA_COND_IVA[$e['condicion_iva']];
    }
    if (!empty($e['ingresos_brutos'])) {
        $l1[] = 'IIBB ' . $e['ingresos_brutos'];
    }
    $wa = !empty($e['whatsapp_numero']) ? 'WhatsApp ' . whatsapp_fmt((string) $e['whatsapp_numero']) : '';
    $l3 = array_filter([tel_fmt((string) ($e['telefono'] ?? '')), $wa, (string) ($e['email'] ?? ''), (string) ($e['sitio_web'] ?? '')], 'strlen');
    return array_values(array_filter([implode(' · ', $l1), empresa_domicilio(), implode(' · ', $l3)], 'strlen'));
}

function empresa_logo_pdf(): ?array
{
    static $img = false;
    if ($img !== false) {
        return $img;
    }
    $img = null;
    if (empty(empresa()['tiene_logo_pdf'])) {
        return null;
    }
    try {
        $r = q1('SELECT logo_pdf FROM empresa WHERE id_empresa = 1');
        $d = $r ? @unserialize((string) $r['logo_pdf'], ['allowed_classes' => false]) : null;
        $img = is_array($d) && isset($d['w'], $d['h'], $d['datos']) ? $d : null;
    } catch (Throwable $e) {
        $img = null;
    }
    return $img;
}

// ---------------------------------------------------------------------
// Ícono de la app (PWA)
// ---------------------------------------------------------------------

/** ¿Hay íconos armados desde el logo y están en uso? */
function app_icono_propio(): bool
{
    $e = empresa();
    return !empty($e['tiene_icono']) && !empty($e['app_icono_usar']) && !empty($e['tiene_logo']);
}

/** URL de un ícono de la app ('32', '180', '192', '512', 'mask'): el armado desde el logo o el estándar. */
function app_icono_url(string $t): string
{
    if (app_icono_propio()) {
        return 'icono.php?t=' . $t . '&v=' . (strtotime((string) (empresa()['app_icono_actualizado_el'] ?? '')) ?: 0);
    }
    return 'assets/iconos/' . (ICONOS_APP[$t][2] ?? 'icono-192.png');
}

/** PNG de un data URL «data:image/png;base64,…» si es un PNG cuadrado de $lado px; si no, null. */
function app_icono_png(string $dataUrl, int $lado): ?string
{
    if (strncmp($dataUrl, 'data:image/png;base64,', 22) !== 0 || strlen($dataUrl) > 1400000) {
        return null;
    }
    $bin = base64_decode(substr($dataUrl, 22), true);
    if ($bin === false || strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0 || substr($bin, 12, 4) !== 'IHDR') {
        return null;
    }
    $d = unpack('Nw/Nh', substr($bin, 16, 8));
    return $d['w'] === $lado && $d['h'] === $lado ? $bin : null;
}

// ---------------------------------------------------------------------
// WhatsApp
// ---------------------------------------------------------------------

/** Número de WhatsApp en formato de la base (siempre celular), o null. */
function whatsapp_normalizar(string $s): ?string
{
    $n = tel_normalizar($s, true);
    return $n !== null && tel_es_movil($n) ? $n : null;
}

function whatsapp_fmt(?string $n): string
{
    return tel_fmt($n);
}

/**
 * Un teléfono para mostrar en una grilla: con el formato de siempre y, si es
 * celular, con el link para escribirle por WhatsApp (arranca con el saludo de
 * Datos de la empresa). Devuelve HTML.
 */
function tel_enlace(?string $n, string $nombre = ''): string
{
    $n = trim((string) $n);
    if ($n === '') {
        return '';
    }
    $txt = h(tel_fmt($n));
    if (!tel_es_movil($n)) {
        return '<span class="tel">' . $txt . '</span>';
    }
    $nombre = function_exists('mb_convert_case') ? mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($nombre));
    return '<a class="tel" href="' . h(whatsapp_link($n, whatsapp_saludo($nombre))) . '" target="_blank" rel="noopener" title="Escribirle por WhatsApp">' . $txt . '</a>';
}

/** Link para abrir un chat: wa.me/<número>?text=… */
function whatsapp_link(string $numero, string $texto = ''): string
{
    return 'https://wa.me/' . preg_replace('/\D/', '', $numero) . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
}

/** El saludo de la empresa con {cliente} y {empresa} reemplazados. */
function whatsapp_saludo(string $cliente = ''): string
{
    $m = trim((string) (empresa()['whatsapp_mensaje'] ?? ''));
    return strtr($m, ['{cliente}' => $cliente, '{empresa}' => empresa_nombre()]);
}

/** ¿Está todo para mandar mensajes por la API de WhatsApp Cloud? */
function whatsapp_api_lista(): bool
{
    $e = empresa();
    return !empty($e['whatsapp_phone_id']) && (string) cfg('whatsapp_token', '') !== '';
}

// ---------------------------------------------------------------------
// Preparar el logo subido
// ---------------------------------------------------------------------

/**
 * Valida el archivo y devuelve ['tipo', 'ancho', 'alto', 'pdf' => array].
 * Tira RuntimeException con un mensaje para el usuario.
 */
function logo_preparar(string $bin): array
{
    if (strlen($bin) > LOGO_MAX_BYTES) {
        throw new RuntimeException('El logo pesa más de 1 MB: achicalo o guardalo con más compresión.');
    }
    if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $pdf = logo_png_pdf($bin);
        $tipo = 'image/png';
    } elseif (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
        $pdf = logo_jpg_pdf($bin);
        $tipo = 'image/jpeg';
    } else {
        throw new RuntimeException('El logo tiene que ser una imagen PNG o JPG.');
    }
    if ($pdf['w'] > LOGO_MAX_LADO || $pdf['h'] > LOGO_MAX_LADO) {
        throw new RuntimeException('El logo mide ' . $pdf['w'] . '×' . $pdf['h'] . ' px: el máximo es ' . LOGO_MAX_LADO . ' px por lado.');
    }
    return ['tipo' => $tipo, 'ancho' => $pdf['w'], 'alto' => $pdf['h'], 'pdf' => $pdf];
}

function logo_jpg_pdf(string $bin): array
{
    $i = @getimagesizefromstring($bin);
    if (!$i || ($i[2] ?? 0) !== IMAGETYPE_JPEG) {
        throw new RuntimeException('El JPG está dañado o no se puede leer.');
    }
    $ch = (int) ($i['channels'] ?? 3);
    $cs = $ch === 1 ? '/DeviceGray' : ($ch === 4 ? '/DeviceCMYK' : '/DeviceRGB');
    return ['w' => (int) $i[0], 'h' => (int) $i[1], 'cs' => $cs, 'bpc' => 8, 'filtro' => '/DCTDecode',
            'parms' => '', 'datos' => $bin, 'smask' => null, 'paleta' => null,
            'decode' => $ch === 4 ? '[1 0 1 0 1 0 1 0]' : null];     // CMYK de Photoshop viene invertido
}

/**
 * PNG -> imagen PDF. Sin transparencia, los datos comprimidos del PNG van
 * tal cual (Predictor 15). Con canal alfa (o paleta con tRNS) se
 * descomprime, se deshacen los filtros por renglón y el alfa va aparte
 * como SMask, así el logo transparente no sale con fondo negro.
 */
function logo_png_pdf(string $bin): array
{
    $pos = 8;
    $len = strlen($bin);
    $ihdr = null;
    $plte = '';
    $trns = null;
    $idat = '';
    while ($pos + 8 <= $len) {
        $n = unpack('N', substr($bin, $pos, 4))[1];
        $tipo = substr($bin, $pos + 4, 4);
        $dat = substr($bin, $pos + 8, $n);
        $pos += 12 + $n;
        if ($tipo === 'IHDR') {
            $ihdr = unpack('Nw/Nh/Cbd/Cct/Ccomp/Cfilt/Cinter', $dat);
        } elseif ($tipo === 'PLTE') {
            $plte = $dat;
        } elseif ($tipo === 'tRNS') {
            $trns = $dat;
        } elseif ($tipo === 'IDAT') {
            $idat .= $dat;
        } elseif ($tipo === 'IEND') {
            break;
        }
    }
    if (!$ihdr || $idat === '') {
        throw new RuntimeException('El PNG está dañado o no se puede leer.');
    }
    $w = $ihdr['w'];
    $h = $ihdr['h'];
    $bd = $ihdr['bd'];
    $ct = $ihdr['ct'];
    if ($ihdr['inter'] !== 0) {
        throw new RuntimeException('El PNG está guardado «entrelazado»: volvé a guardarlo sin esa opción.');
    }
    $canales = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct] ?? null;
    if ($canales === null || ($ct === 3 && $plte === '')) {
        throw new RuntimeException('Ese tipo de PNG no se puede usar: guardalo como PNG de 24 o 32 bits.');
    }
    $base = ['w' => $w, 'h' => $h, 'smask' => null, 'paleta' => null, 'decode' => null];
    $conAlfa = $ct === 4 || $ct === 6 || ($ct === 3 && $trns !== null);

    if (!$conAlfa) {
        $cs = $ct === 0 ? '/DeviceGray' : ($ct === 2 ? '/DeviceRGB' : 'indexed');
        return array_merge($base, ['cs' => $cs, 'bpc' => $bd, 'filtro' => '/FlateDecode',
            'parms' => '<< /Predictor 15 /Colors ' . $canales . ' /BitsPerComponent ' . $bd . ' /Columns ' . $w . ' >>',
            'datos' => $idat, 'paleta' => $ct === 3 ? $plte : null]);
    }

    if ($w * $h > LOGO_MAX_PIXELES) {
        throw new RuntimeException('El logo es muy grande (' . $w . '×' . $h . ' px). Con unos 800 px de ancho alcanza.');
    }
    $raw = @gzuncompress($idat);
    if ($raw === false) {
        throw new RuntimeException('El PNG está dañado o no se puede leer.');
    }
    $px = png_desfiltrar($raw, $w, $h, $canales, $bd);     // un string por renglón, bytes sin filtro

    $color = '';
    $alfa = '';
    $opaco = true;
    if ($ct === 3) {
        $ta = array_values(unpack('C*', $trns));            // alfa por índice de paleta
        foreach ($px as $fila) {
            $idx = png_indices($fila, $w, $bd);
            $color .= pack('C*', ...$idx);
            foreach ($idx as $k) {
                $a = $ta[$k] ?? 255;
                $alfa .= chr($a);
                $opaco = $opaco && $a === 255;
            }
        }
        $res = array_merge($base, ['cs' => 'indexed', 'paleta' => $plte, 'bpc' => 8]);
    } else {
        $bpp = $canales * ($bd === 16 ? 2 : 1);
        $nc = $canales - 1;                                 // canales de color (1 gris, 3 RGB)
        $paso = $bd === 16 ? 2 : 1;
        foreach ($px as $fila) {
            for ($i = 0, $fin = $w * $bpp; $i < $fin; $i += $bpp) {
                for ($c = 0; $c < $nc; $c++) {
                    $color .= $fila[$i + $c * $paso];
                }
                $a = $fila[$i + $nc * $paso];
                $alfa .= $a;
                $opaco = $opaco && $a === "\xFF";
            }
        }
        $res = array_merge($base, ['cs' => $nc === 1 ? '/DeviceGray' : '/DeviceRGB', 'bpc' => 8]);
    }
    $res['filtro'] = '/FlateDecode';
    $res['parms'] = '';
    $res['datos'] = gzcompress($color, 9);
    $res['smask'] = $opaco ? null : gzcompress($alfa, 9);
    return $res;
}

/** Deshace los filtros de cada renglón del PNG. Devuelve los renglones. */
function png_desfiltrar(string $raw, int $w, int $h, int $canales, int $bd): array
{
    $stride = (int) ceil($w * $canales * $bd / 8);
    $bpp = max(1, (int) ($canales * $bd / 8));
    $prev = array_fill(0, $stride, 0);
    $filas = [];
    $pos = 0;
    for ($y = 0; $y < $h; $y++) {
        $f = ord($raw[$pos] ?? "\0");
        $l = array_values(unpack('C*', substr($raw, $pos + 1, $stride)));
        if (count($l) !== $stride) {
            throw new RuntimeException('El PNG está incompleto.');
        }
        $pos += $stride + 1;
        switch ($f) {
            case 0:
                break;
            case 1:
                for ($i = $bpp; $i < $stride; $i++) {
                    $l[$i] = ($l[$i] + $l[$i - $bpp]) & 255;
                }
                break;
            case 2:
                for ($i = 0; $i < $stride; $i++) {
                    $l[$i] = ($l[$i] + $prev[$i]) & 255;
                }
                break;
            case 3:
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= $bpp ? $l[$i - $bpp] : 0;
                    $l[$i] = ($l[$i] + (($a + $prev[$i]) >> 1)) & 255;
                }
                break;
            case 4:
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= $bpp ? $l[$i - $bpp] : 0;
                    $b = $prev[$i];
                    $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $l[$i] = ($l[$i] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 255;
                }
                break;
            default:
                throw new RuntimeException('El PNG está dañado (filtro desconocido).');
        }
        $filas[] = pack('C*', ...$l);
        $prev = $l;
    }
    return $filas;
}

/** Índices de paleta de un renglón (1, 2, 4 u 8 bits por píxel). */
function png_indices(string $fila, int $w, int $bd): array
{
    $bytes = array_values(unpack('C*', $fila));
    if ($bd === 8) {
        return array_slice($bytes, 0, $w);
    }
    $out = [];
    $porByte = intdiv(8, $bd);
    $mask = (1 << $bd) - 1;
    for ($x = 0; $x < $w; $x++) {
        $b = $bytes[intdiv($x, $porByte)];
        $shift = 8 - $bd * (($x % $porByte) + 1);
        $out[] = ($b >> $shift) & $mask;
    }
    return $out;
}
