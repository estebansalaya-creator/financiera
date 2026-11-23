<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// ARCA: constancia de inscripción (ws_sr_constancia_inscripcion, A5)
//   - El botón «Consultar ARCA» de la ficha del cliente (public/arca.php)
//     trae nombre o razón social, domicilio fiscal, condición frente al IVA,
//     impuestos y actividades de un CUIT/CUIL, como en gestion_adm
//     (afip.consultar_cuit_constancia).
//   - Lo último consultado queda guardado por CUIT (arca_constancia*,
//     sql\financiera_arca.sql).
//   - El ticket de acceso de WSAA (dura 12 h) se guarda en arca_tokens.
//   - Si el certificado no tiene la constancia pero sí el padrón A13
//     (ws_sr_padron_a13), se consulta el A13: nombre o razón social y
//     domicilio fiscal, sin la condición frente al IVA (como gestion_sindicato).
//     Que la constancia no está autorizada se recuerda una hora en
//     arca_tokens («ws_sr_constancia_inscripcion#sin_permiso») para no
//     preguntarle a WSAA en cada consulta.
//
// Configuración (config.php en IIS, variables FIN_ARCA_* en Railway):
//   arca_cert      ruta al certificado (.crt)          FIN_ARCA_CERT
//   arca_key       ruta a la clave privada (.key)      FIN_ARCA_KEY
//   arca_cert_b64  el .crt en base64 (Railway)         FIN_ARCA_CERT_B64
//   arca_key_b64   el .key en base64 (Railway)         FIN_ARCA_KEY_B64
//   arca_cuit      CUIT dueño del certificado          FIN_ARCA_CUIT
//   arca_cainfo    cacert.pem, si PHP no valida HTTPS  FIN_ARCA_CAINFO
//
// Homologación (factura electrónica de prueba, inc\facturacion.php) usa su
// propio certificado, del autoservicio de homologación de ARCA:
//   arca_cert_homo, arca_key_homo (o _b64), arca_cuit_homo
//                                  FIN_ARCA_CERT_HOMO, FIN_ARCA_KEY_HOMO…
// Sin ellos usa los de producción (y ARCA lo rechaza).
//
// Un mismo certificado tiene UN ticket vigente por servicio: si otro
// sistema (gestion_adm) pidió el suyo con el mismo certificado, ARCA no
// entrega otro hasta que venza (hasta 12 h). Para evitarlo conviene que la
// financiera tenga su propio certificado (otro alias del mismo CUIT).
// ---------------------------------------------------------------------

const ARCA_WSAA_URL      = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
const ARCA_WSAA_URL_HOMO = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
const ARCA_A5_URL        = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5';
const ARCA_SERVICIO_A5   = 'ws_sr_constancia_inscripcion';
const ARCA_A13_URL       = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13';
const ARCA_SERVICIO_A13  = 'ws_sr_padron_a13';

/** Condición frente al IVA, con los códigos de la ficha del cliente (cliente.php COND_IVA). */
const ARCA_COND_IVA = ['RI' => 'Responsable inscripto', 'MONO' => 'Monotributista', 'EX' => 'Exento', 'CF' => 'Consumidor final'];

/** idProvincia de ARCA -> el nombre de la lista de provincias de la ficha. */
const ARCA_PROVINCIAS = [
    0 => 'Ciudad Autónoma de Buenos Aires', 1 => 'Buenos Aires', 2 => 'Catamarca', 3 => 'Córdoba', 4 => 'Corrientes',
    5 => 'Entre Ríos', 6 => 'Jujuy', 7 => 'Mendoza', 8 => 'La Rioja', 9 => 'Salta', 10 => 'San Juan', 11 => 'San Luis',
    12 => 'Santa Fe', 13 => 'Santiago del Estero', 14 => 'Tucumán', 16 => 'Chaco', 17 => 'Chubut', 18 => 'Formosa',
    19 => 'Misiones', 20 => 'Neuquén', 21 => 'La Pampa', 22 => 'Río Negro', 23 => 'Santa Cruz', 24 => 'Tierra del Fuego',
];

class ArcaError extends RuntimeException
{
}

/** El pedido salió y no volvió la respuesta: ARCA pudo haberlo procesado. */
class ArcaSinRespuesta extends ArcaError
{
}

// ── Certificado ──────────────────────────────────────────────────────

/**
 * Las rutas del certificado y la clave. En Railway llegan en base64 y se
 * escriben en la carpeta temporal (el disco del contenedor es efímero).
 */
function arca_credenciales(string $amb = 'prod'): array
{
    // En homologación, las claves con _homo si están cargadas.
    $suf = '';
    if ($amb === 'homo' && ((string) cfg('arca_cert_homo', '') !== '' || (string) cfg('arca_cert_homo_b64', '') !== '')) {
        $suf = '_homo';
    }
    $ruta = function (string $clave, string $b64, string $nombre) use ($suf): string {
        $clave .= $suf;
        $b64 = $suf !== '' ? str_replace('_b64', $suf . '_b64', $b64) : $b64;
        $nombre = $suf !== '' ? str_replace('.', $suf . '.', $nombre) : $nombre;
        $p = (string) cfg($clave, '');
        if ($p !== '' && is_file($p)) {
            return $p;
        }
        $contenido = (string) cfg($b64, '');
        if ($contenido !== '') {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fin_arca';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $dest = $dir . DIRECTORY_SEPARATOR . $nombre;
            $bin = base64_decode($contenido, true);
            if ($bin === false) {
                throw new ArcaError('La variable ' . strtoupper('fin_' . $b64) . ' no es base64 válido.');
            }
            if (!is_file($dest) || md5_file($dest) !== md5($bin)) {
                file_put_contents($dest, $bin);
                @chmod($dest, 0600);
            }
            return $dest;
        }
        throw new ArcaError($p !== ''
            ? 'No se encuentra ' . $p . ' (o el sitio no tiene permiso para leerlo). Revisá ' . $clave . ' en config.php.'
            : 'Falta configurar ' . $clave . ' en config.php (o FIN_' . strtoupper($b64) . ' en Railway).');
    };
    $cuit = preg_replace('/\D/', '', (string) (cfg('arca_cuit' . $suf, '') ?: cfg('arca_cuit', '')));
    if (strlen($cuit) !== 11) {
        throw new ArcaError('Falta arca_cuit' . $suf . ' en config.php: el CUIT dueño del certificado de ARCA.');
    }
    return ['cert' => $ruta('arca_cert', 'arca_cert_b64', 'arca.crt'),
            'key'  => $ruta('arca_key', 'arca_key_b64', 'arca.key'),
            'cuit' => $cuit];
}

// ── HTTP / SOAP ──────────────────────────────────────────────────────

/** POST de un sobre SOAP. Devuelve el cuerpo de la respuesta (también si es un SOAP Fault). */
function arca_soap(string $url, string $sobre, string $accion = '', int $timeout = 40): string
{
    if (!function_exists('curl_init')) {
        throw new ArcaError('PHP no tiene curl habilitado: agregá extension=curl en php.ini.');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $sobre,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $accion . '"'],
        CURLOPT_USERAGENT      => 'financiera-backoffice/1.0',
        // Los servidores de ARCA usan claves DH cortas que OpenSSL 3 rechaza
        // con el nivel de seguridad por defecto («dh key too small»).
        CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
    ];
    $ca = (string) (cfg('arca_cainfo', '') ?: cfg('bcra_cainfo', ''));
    if ($ca !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        // PHP en Windows no trae la lista de certificados raíz: se usa la de
        // Windows (la misma que el navegador, con la raíz del antivirus o del
        // proxy si inspecciona HTTPS).
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $txt = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    // curl_close() no hace falta desde PHP 8 (y en 8.5 avisa que está obsoleta).
    if ($errno) {
        if (in_array($errno, [28, 52, 56], true)) {         // timeout / sin respuesta: puede haber llegado
            throw new ArcaSinRespuesta('ARCA no respondió a tiempo: ' . $error);
        }
        throw new ArcaError('No se pudo conectar con ARCA: ' . $error
            . (in_array($errno, [60, 77], true) ? '. Configurá arca_cainfo en config.php con la ruta a cacert.pem.' : ''));
    }
    return (string) $txt;
}

/** El texto del SOAP Fault, si la respuesta es uno. */
function arca_fault(string $xml): ?string
{
    if (stripos($xml, 'Fault') === false) {
        return null;
    }
    $d = arca_dom($xml);
    if (!$d) {
        return null;
    }
    $f = $d->getElementsByTagName('faultstring')->item(0);
    return $f ? trim($f->textContent) : null;
}

function arca_dom(string $xml): ?DOMDocument
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $d : null;
}

/** Un elemento XML como arreglo: los hijos repetidos quedan en lista. */
function arca_xml_array(DOMElement $e)
{
    $hijos = [];
    foreach ($e->childNodes as $c) {
        if ($c instanceof DOMElement) {
            $hijos[] = $c;
        }
    }
    if (!$hijos) {
        return trim($e->textContent);
    }
    $out = [];
    foreach ($hijos as $c) {
        $k = $c->localName;
        $v = arca_xml_array($c);
        if (array_key_exists($k, $out)) {
            if (!is_array($out[$k]) || !array_is_list_compat($out[$k])) {
                $out[$k] = [$out[$k]];
            }
            $out[$k][] = $v;
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** array_is_list para PHP 7.4 / 8.0. */
function array_is_list_compat(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

/** Siempre una lista (ARCA manda un elemento suelto cuando hay uno solo). */
function arca_lista($v): array
{
    if ($v === null || $v === '' || $v === []) {
        return [];
    }
    return is_array($v) && array_is_list_compat($v) ? $v : [$v];
}

// ── WSAA ─────────────────────────────────────────────────────────────

/** Token y sign de WSAA para un servicio, del guardado si sigue vigente. */
function arca_ticket(string $servicio, string $amb = 'prod'): array
{
    $clave = $servicio . ($amb === 'homo' ? '@homo' : '');      // el ticket de homologación es otro
    $t = q1('SELECT token, sign FROM arca_tokens WHERE servicio = ? AND expira > NOW() + INTERVAL 5 MINUTE', [$clave]);
    if ($t) {
        return [$t['token'], $t['sign']];
    }
    $cred = arca_credenciales($amb);

    $tra = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<loginTicketRequest version="1.0"><header>'
         . '<uniqueId>' . time() . '</uniqueId>'
         . '<generationTime>' . date('c', time() - 600) . '</generationTime>'
         . '<expirationTime>' . date('c', time() + 600) . '</expirationTime>'
         . '</header><service>' . $servicio . '</service></loginTicketRequest>';

    $tmp = tempnam(sys_get_temp_dir(), 'tra');
    $firmado = $tmp . '.p7';
    file_put_contents($tmp, $tra);
    $archivo = function (string $p): string { return 'file://' . str_replace('\\', '/', (string) realpath($p)); };
    $ok = @openssl_pkcs7_sign($tmp, $firmado, $archivo($cred['cert']), [$archivo($cred['key']), ''], [], 0);
    $smime = $ok ? (string) file_get_contents($firmado) : '';
    @unlink($tmp);
    @unlink($firmado);
    if (!$ok) {
        $e = openssl_error_string();
        throw new ArcaError('No se pudo firmar el pedido a ARCA con el certificado: ' . ($e ?: 'revisá que el .crt y el .key sean pareja.'));
    }
    // S/MIME: después de los encabezados viene el CMS en base64.
    $partes = preg_split("/\r?\n\r?\n/", $smime, 2);
    $cms = preg_replace('/\s+/', '', $partes[1] ?? '');

    $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
           . 'xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov"><soapenv:Header/><soapenv:Body>'
           . '<wsaa:loginCms><wsaa:in0>' . $cms . '</wsaa:in0></wsaa:loginCms></soapenv:Body></soapenv:Envelope>';
    $resp = arca_soap($amb === 'homo' ? ARCA_WSAA_URL_HOMO : ARCA_WSAA_URL, $sobre);
    if ($f = arca_fault($resp)) {
        if (stripos($f, 'ya posee un TA valido') !== false) {
            throw new ArcaError('ARCA ya le entregó un ticket vigente a otro sistema con este mismo certificado (por ejemplo gestion_adm) '
                . 'y no da otro hasta que venza (hasta 12 horas). La solución definitiva es un certificado propio para la financiera.');
        }
        throw new ArcaError('ARCA rechazó el acceso (WSAA): ' . $f
            . (stripos($f, 'autoriz') !== false ? '. El certificado no está habilitado para ' . $servicio . ' en el Administrador de Relaciones.' : ''));
    }
    $d = arca_dom($resp);
    $ret = $d ? $d->getElementsByTagName('loginCmsReturn')->item(0) : null;
    $ta = $ret ? arca_dom(html_entity_decode($ret->textContent, ENT_QUOTES | ENT_XML1, 'UTF-8')) : null;
    if (!$ta) {
        throw new ArcaError('ARCA (WSAA) respondió algo inesperado.');
    }
    $token = trim($ta->getElementsByTagName('token')->item(0)->textContent ?? '');
    $sign = trim($ta->getElementsByTagName('sign')->item(0)->textContent ?? '');
    $exp = trim($ta->getElementsByTagName('expirationTime')->item(0)->textContent ?? '');
    if ($token === '' || $sign === '') {
        throw new ArcaError('ARCA (WSAA) no devolvió el ticket de acceso.');
    }
    $expira = $exp !== '' ? date('Y-m-d H:i:s', strtotime($exp)) : date('Y-m-d H:i:s', time() + 11 * 3600);
    q('INSERT INTO arca_tokens (servicio, token, sign, expira) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), expira = VALUES(expira)',
      [$clave, $token, $sign, $expira]);
    return [$token, $sign];
}

// ── Constancia de inscripción ────────────────────────────────────────

/**
 * Consulta la constancia de un CUIT/CUIL. Devuelve los datos normalizados
 * (ver el final de la función). Lanza ArcaError con un mensaje para mostrar.
 */
function arca_constancia(string $cuit): array
{
    $cuit = preg_replace('/\D/', '', $cuit);
    if (strlen($cuit) !== 11) {
        throw new ArcaError('El CUIT/CUIL tiene que tener 11 dígitos.');
    }
    $marca = ARCA_SERVICIO_A5 . '#sin_permiso';
    $sinConstancia = (bool) q1('SELECT 1 FROM arca_tokens WHERE servicio = ? AND expira > NOW()', [$marca]);
    if (!$sinConstancia) {
        try {
            return arca_constancia_a5($cuit);
        } catch (ArcaError $e) {
            if (!arca_no_autorizado($e)) {
                throw $e;
            }
            q("INSERT INTO arca_tokens (servicio, token, sign, expira) VALUES (?, '', '', NOW() + INTERVAL 1 HOUR)
               ON DUPLICATE KEY UPDATE expira = VALUES(expira)", [$marca]);
        }
    }
    try {
        return arca_padron_a13($cuit);
    } catch (ArcaError $e) {
        if (arca_no_autorizado($e)) {
            throw new ArcaError('El certificado no tiene asociado en ARCA ni la Constancia de Inscripción '
                . '(ws_sr_constancia_inscripcion) ni el padrón A13 (ws_sr_padron_a13). Se asocian en el Administrador de Relaciones.');
        }
        throw $e;
    }
}

/** El WSAA dice que el certificado no tiene el servicio. */
function arca_no_autorizado(Throwable $e): bool
{
    return (bool) preg_match('/notAuthorized|no autorizado|no está habilitado/iu', $e->getMessage());
}

/** La constancia de inscripción (A5). */
function arca_constancia_a5(string $cuit): array
{
    $cuit = preg_replace('/\D/', '', $cuit);
    if (strlen($cuit) !== 11) {
        throw new ArcaError('El CUIT/CUIL tiene que tener 11 dígitos.');
    }
    [$token, $sign] = arca_ticket(ARCA_SERVICIO_A5);
    $cred = arca_credenciales();
    $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:a5="http://a5.soap.ws.server.puc.sr/">'
           . '<soapenv:Header/><soapenv:Body><a5:getPersona_v2>'
           . '<token>' . htmlspecialchars($token, ENT_XML1) . '</token>'
           . '<sign>' . htmlspecialchars($sign, ENT_XML1) . '</sign>'
           . '<cuitRepresentada>' . $cred['cuit'] . '</cuitRepresentada>'
           . '<idPersona>' . $cuit . '</idPersona>'
           . '</a5:getPersona_v2></soapenv:Body></soapenv:Envelope>';
    $resp = arca_soap(ARCA_A5_URL, $sobre);
    if ($f = arca_fault($resp)) {
        if (stripos($f, 'token') !== false || stripos($f, 'expir') !== false) {
            q('DELETE FROM arca_tokens WHERE servicio = ?', [ARCA_SERVICIO_A5]);   // la próxima pide otro
        }
        throw new ArcaError(stripos($f, 'No existe persona') !== false
            ? 'ARCA no tiene registrado el CUIT/CUIL ' . cuit_fmt($cuit) . '.'
            : 'ARCA respondió: ' . $f);
    }
    return arca_constancia_leer($resp, $cuit);
}

/** La respuesta de getPersona_v2, normalizada. */
function arca_constancia_leer(string $resp, string $cuit): array
{
    $d = arca_dom($resp);
    $pr = $d ? $d->getElementsByTagName('personaReturn')->item(0) : null;
    if (!$pr) {
        throw new ArcaError('ARCA respondió algo inesperado al consultar ' . cuit_fmt($cuit) . '.');
    }
    $p = arca_xml_array($pr);
    $p = is_array($p) ? $p : [];

    $dg  = is_array($p['datosGenerales'] ?? null) ? $p['datosGenerales'] : [];
    $err = is_array($p['errorConstancia'] ?? null) ? $p['errorConstancia'] : [];
    $rg  = is_array($p['datosRegimenGeneral'] ?? null) ? $p['datosRegimenGeneral'] : [];
    $mt  = is_array($p['datosMonotributo'] ?? null) ? $p['datosMonotributo'] : [];

    // Sin inscripción en impuestos (un jubilado, un empleado) ARCA no manda
    // datosGenerales: el nombre viene en errorConstancia.
    $apellido = (string) ($dg['apellido'] ?? $err['apellido'] ?? '');
    $nombre   = (string) ($dg['nombre'] ?? $err['nombre'] ?? '');
    $razon    = (string) ($dg['razonSocial'] ?? '');
    $tipoPers = (string) ($dg['tipoPersona'] ?? (in_array(substr($cuit, 0, 2), ['30', '33', '34'], true) ? 'JURIDICA' : 'FISICA'));
    $errores  = array_values(array_filter(array_map('strval', arca_lista($err['error'] ?? null))));

    // Impuestos (régimen general + monotributo)
    $imps = [];
    foreach (array_merge(arca_lista($rg['impuesto'] ?? null), arca_lista($mt['impuesto'] ?? null)) as $i) {
        if (!is_array($i)) {
            continue;
        }
        $imps[] = ['id_impuesto' => (int) ($i['idImpuesto'] ?? 0), 'descripcion' => (string) ($i['descripcionImpuesto'] ?? ''),
                   'estado' => (string) ($i['estadoImpuesto'] ?? 'AC'), 'periodo_desde' => (string) ($i['periodo'] ?? '')];
    }
    $activos = array_column(array_filter($imps, function ($i) { return $i['estado'] === '' || $i['estado'] === 'AC'; }), 'id_impuesto');

    // Condición IVA (mismos códigos que gestion_adm: 30/32 RI, 33 exento,
    // monotributo 20 y 150-165; sin IVA, consumidor final).
    $mono = array_intersect($activos, [20, 150, 155, 156, 157, 158, 159, 160, 161, 162, 163, 164, 165]);
    if ($mono || $mt) {
        $iva = 'MONO';
    } elseif (array_intersect($activos, [30, 32])) {
        $iva = 'RI';
    } elseif (in_array(33, $activos, true)) {
        $iva = 'EX';
    } else {
        $iva = 'CF';
    }
    $cat = '';
    foreach (arca_lista($mt['categoriaMonotributo'] ?? null) as $c) {
        if (is_array($c)) {
            $cat = (string) ($c['descripcionCategoria'] ?? $c['idCategoria'] ?? '');
            break;
        }
    }

    // Actividades (la principal es la de orden 1)
    $acts = [];
    foreach (array_merge(arca_lista($rg['actividad'] ?? null), arca_lista($mt['actividadMonotributista'] ?? $mt['actividad'] ?? null)) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $acts[] = ['id_actividad' => (string) ($a['idActividad'] ?? ''), 'descripcion' => (string) ($a['descripcionActividad'] ?? ''),
                   'nomenclador' => (int) ($a['nomenclador'] ?? 0) ?: null, 'orden' => isset($a['orden']) ? (int) $a['orden'] : null,
                   'periodo_desde' => (string) ($a['periodo'] ?? '')];
    }
    usort($acts, function ($x, $y) { return ($x['orden'] ?? 999) <=> ($y['orden'] ?? 999); });

    // Domicilio fiscal: ARCA manda la dirección en un solo texto.
    $dom = [];
    $df = $dg['domicilioFiscal'] ?? null;
    if (is_array($df) && array_is_list_compat($df)) {
        $df = $df[0] ?? null;
    }
    if (is_array($df)) {
        $dir = trim((string) ($df['direccion'] ?? ''));
        $calle = $dir;
        $numero = '';
        $piso = '';
        // «CALLE 7 1234 PISO 2 DTO B»: desde PISO/DTO/… es el piso; el último
        // número de lo que queda es la altura (así «CALLE 7» sigue entera).
        $resto = $dir;
        if (preg_match('/^(.*?)\s+((?:PISO|PS|DTO|DPTO|DEPTO|DEP|OF|OFICINA|LOCAL|LOC|PB|UF|TORRE|MZ|MZA|MANZANA|LOTE|CASA)\b.*)$/iu', $resto, $m)) {
            [$resto, $piso] = [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^(.*\S)\s+(\d{1,6}[A-Z]?)$/u', $resto, $m)) {
            [$calle, $numero] = [trim($m[1]), $m[2]];
        } else {
            $calle = $resto;
        }
        $idProv = isset($df['idProvincia']) && $df['idProvincia'] !== '' ? (int) $df['idProvincia'] : null;
        $dom = ['direccion' => $dir, 'calle' => $calle, 'numero' => $numero, 'piso_depto' => $piso,
                'localidad' => (string) ($df['localidad'] ?? ($idProv === 0 ? 'CABA' : '')),
                'provincia' => $idProv !== null ? (ARCA_PROVINCIAS[$idProv] ?? '') : '',
                'provincia_arca' => (string) ($df['descripcionProvincia'] ?? ''),
                'cp' => (string) ($df['codPostal'] ?? '')];
    }

    return [
        'cuit' => $cuit, 'tipo_persona' => $tipoPers,
        'razon_social' => $razon !== '' ? $razon : trim($apellido . ' ' . $nombre),
        'apellido' => $apellido, 'nombre' => $nombre,
        'estado_clave' => (string) ($dg['estadoClave'] ?? ''),
        'condicion_iva' => $iva, 'condicion_iva_txt' => ARCA_COND_IVA[$iva], 'categoria_monotributo' => $cat,
        'domicilio' => $dom, 'impuestos' => $imps, 'actividades' => $acts, 'errores' => $errores,
        'consultado_el' => date('Y-m-d H:i:s'),
    ];
}

// ── Padrón A13 (cuando el certificado no tiene la constancia) ────────

/** El padrón A13 con la forma de la constancia: sin impuestos ni condición de IVA. */
function arca_padron_a13(string $cuit): array
{
    [$token, $sign] = arca_ticket(ARCA_SERVICIO_A13);
    $cred = arca_credenciales();
    $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:a13="http://a13.soap.ws.server.puc.sr/">'
           . '<soapenv:Header/><soapenv:Body><a13:getPersona>'
           . '<token>' . htmlspecialchars($token, ENT_XML1) . '</token>'
           . '<sign>' . htmlspecialchars($sign, ENT_XML1) . '</sign>'
           . '<cuitRepresentada>' . $cred['cuit'] . '</cuitRepresentada>'
           . '<idPersona>' . $cuit . '</idPersona>'
           . '</a13:getPersona></soapenv:Body></soapenv:Envelope>';
    $resp = arca_soap(ARCA_A13_URL, $sobre);
    if ($f = arca_fault($resp)) {
        if (stripos($f, 'token') !== false || stripos($f, 'expir') !== false) {
            q('DELETE FROM arca_tokens WHERE servicio = ?', [ARCA_SERVICIO_A13]);
        }
        throw new ArcaError(preg_match('/No existe persona|no se encontr/iu', $f)
            ? 'ARCA no tiene registrado el CUIT/CUIL ' . cuit_fmt($cuit) . '.'
            : 'ARCA (padrón A13) respondió: ' . $f);
    }
    return arca_a13_leer($resp, $cuit);
}

/** La respuesta de getPersona del A13, normalizada como arca_constancia_leer. */
function arca_a13_leer(string $resp, string $cuit): array
{
    $d = arca_dom($resp);
    $pe = $d ? $d->getElementsByTagName('persona')->item(0) : null;
    if (!$pe) {
        throw new ArcaError('ARCA (padrón A13) no trae a ' . cuit_fmt($cuit) . '.');
    }
    $p = arca_xml_array($pe);
    $p = is_array($p) ? $p : [];
    $apellido = (string) ($p['apellido'] ?? '');
    $nombre   = (string) ($p['nombre'] ?? '');
    $razon    = (string) ($p['razonSocial'] ?? '');
    $tipoPers = (string) ($p['tipoPersona'] ?? (in_array(substr($cuit, 0, 2), ['30', '33', '34'], true) ? 'JURIDICA' : 'FISICA'));
    $estado   = (string) ($p['estadoClave'] ?? '');

    $doms = array_values(array_filter(arca_lista($p['domicilio'] ?? null), 'is_array'));
    $df = null;
    foreach ($doms as $x) {
        if (strtoupper((string) ($x['tipoDomicilio'] ?? '')) === 'FISCAL') {
            $df = $x;
            break;
        }
    }
    $df = $df ?? ($doms[0] ?? null);
    $dom = [];
    if ($df) {
        $calle  = trim((string) ($df['calle'] ?? ''));
        $numero = trim((string) ($df['numero'] ?? ''));
        $piso   = trim(implode(' ', array_filter([
            ($df['piso'] ?? '') !== '' ? 'PISO ' . $df['piso'] : '',
            ($df['oficinaDptoLocal'] ?? '') !== '' ? 'DTO ' . $df['oficinaDptoLocal'] : '',
        ])));
        $dir = trim((string) ($df['direccion'] ?? ''));
        if ($calle === '') {
            $calle = $dir;
        }
        if ($dir === '') {
            $dir = trim($calle . ' ' . $numero . ' ' . $piso);
        }
        $idProv = isset($df['idProvincia']) && $df['idProvincia'] !== '' ? (int) $df['idProvincia'] : null;
        $provTxt = (string) ($df['descripcionProvincia'] ?? '');
        $dom = ['direccion' => $dir, 'calle' => $calle, 'numero' => $numero, 'piso_depto' => $piso,
                'localidad' => (string) ($df['localidad'] ?? ($idProv === 0 ? 'CABA' : '')),
                'provincia' => $idProv !== null ? (ARCA_PROVINCIAS[$idProv] ?? '') : '',
                'provincia_arca' => $provTxt, 'cp' => (string) ($df['codigoPostal'] ?? '')];
    }
    $acts = [];
    if (($p['idActividadPrincipal'] ?? '') !== '' || ($p['descripcionActividadPrincipal'] ?? '') !== '') {
        $acts[] = ['id_actividad' => (string) ($p['idActividadPrincipal'] ?? ''), 'descripcion' => (string) ($p['descripcionActividadPrincipal'] ?? ''),
                   'nomenclador' => null, 'orden' => 1, 'periodo_desde' => ''];
    }
    $avisos = [];
    if ($estado !== '' && strtoupper($estado) !== 'ACTIVO') {
        $avisos[] = 'Clave en ARCA: ' . $estado . '.';
    }
    if (($p['fechaFallecimiento'] ?? '') !== '') {
        $avisos[] = 'ARCA informa fallecimiento: ' . substr((string) $p['fechaFallecimiento'], 0, 10) . '.';
    }
    return [
        'cuit' => $cuit, 'tipo_persona' => $tipoPers,
        'razon_social' => $razon !== '' ? $razon : trim($apellido . ' ' . $nombre),
        'apellido' => $apellido, 'nombre' => $nombre, 'estado_clave' => $estado,
        'condicion_iva' => '', 'condicion_iva_txt' => '', 'categoria_monotributo' => '',
        'domicilio' => $dom, 'impuestos' => [], 'actividades' => $acts, 'errores' => $avisos,
        'consultado_el' => date('Y-m-d H:i:s'), 'fuente' => 'padron_a13',
    ];
}

/** Guarda la consulta (reemplaza lo anterior del CUIT). */
function arca_constancia_guardar(array $c, ?int $usuario): void
{
    $dom = $c['domicilio'] ?: [];
    $cut = function ($s, int $n) { $s = (string) $s; return $s === '' ? null : mb_substr($s, 0, $n); };
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO arca_constancia (cuit, tipo_persona, razon_social, apellido, nombre, estado_clave, condicion_iva, condicion_iva_txt,
                                        categoria_monotributo, dom_direccion, dom_localidad, dom_provincia, dom_cp, errores,
                                        consultado_el, consultado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE tipo_persona = VALUES(tipo_persona), razon_social = VALUES(razon_social), apellido = VALUES(apellido),
                nombre = VALUES(nombre), estado_clave = VALUES(estado_clave), condicion_iva = VALUES(condicion_iva),
                condicion_iva_txt = VALUES(condicion_iva_txt), categoria_monotributo = VALUES(categoria_monotributo),
                dom_direccion = VALUES(dom_direccion), dom_localidad = VALUES(dom_localidad), dom_provincia = VALUES(dom_provincia),
                dom_cp = VALUES(dom_cp), errores = VALUES(errores), consultado_el = VALUES(consultado_el), consultado_por = VALUES(consultado_por)',
          [$c['cuit'], $cut($c['tipo_persona'], 30), $cut($c['razon_social'], 200), $cut($c['apellido'], 100), $cut($c['nombre'], 100),
           $cut($c['estado_clave'], 30), $c['condicion_iva'], $cut($c['condicion_iva_txt'], 60), $cut($c['categoria_monotributo'], 100),
           $cut($dom['direccion'] ?? '', 200), $cut($dom['localidad'] ?? '', 100), $cut($dom['provincia'] ?? ($dom['provincia_arca'] ?? ''), 60),
           $cut($dom['cp'] ?? '', 10), $cut(implode(' | ', $c['errores']), 500), $c['consultado_el'], $usuario]);
        q('DELETE FROM arca_constancia_impuestos WHERE cuit = ?', [$c['cuit']]);
        foreach ($c['impuestos'] as $i) {
            q('INSERT INTO arca_constancia_impuestos (cuit, id_impuesto, descripcion, estado, periodo_desde) VALUES (?,?,?,?,?)',
              [$c['cuit'], $i['id_impuesto'] ?: null, $cut($i['descripcion'], 200), $cut($i['estado'], 10), $cut($i['periodo_desde'], 8)]);
        }
        q('DELETE FROM arca_constancia_actividades WHERE cuit = ?', [$c['cuit']]);
        foreach ($c['actividades'] as $a) {
            q('INSERT INTO arca_constancia_actividades (cuit, id_actividad, descripcion, nomenclador, orden, periodo_desde) VALUES (?,?,?,?,?,?)',
              [$c['cuit'], $a['id_actividad'] !== '' ? $a['id_actividad'] : null, $cut($a['descripcion'], 300), $a['nomenclador'], $a['orden'],
               $cut($a['periodo_desde'], 8)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Lo último guardado de un CUIT (null si nunca se consultó o faltan las tablas). */
function arca_constancia_guardada(string $cuit): ?array
{
    try {
        $c = q1('SELECT a.*, u.usuario AS consultado_por_usuario FROM arca_constancia a
                   LEFT JOIN usuarios u ON u.id_usuario = a.consultado_por WHERE a.cuit = ?', [$cuit]);
    } catch (PDOException $e) {
        return null;
    }
    if (!$c) {
        return null;
    }
    $c['impuestos'] = q('SELECT id_impuesto, descripcion, estado, periodo_desde FROM arca_constancia_impuestos WHERE cuit = ? ORDER BY estado, id_impuesto', [$cuit]);
    $c['actividades'] = q('SELECT id_actividad, descripcion, orden, periodo_desde FROM arca_constancia_actividades WHERE cuit = ? ORDER BY orden IS NULL, orden', [$cuit]);
    return $c;
}
