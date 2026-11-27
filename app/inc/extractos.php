<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Extractos bancarios: lectura de los archivos del banco e importación.
// El modelo de gestion_adm (extractos_bancarios.py / c_extractos_bancarios).
//
// Formatos (CSV con «;»; UTF-8 con o sin BOM, o Latin-1 como lo baja el BNA).
// El formato sale del ENCABEZADO, no del nombre: el nombre lo cambia quien
// baja el archivo, el encabezado lo pone el banco.
//   BNA_CLASICO   Fecha Mvto;Fecha Valor;Monto;Referencia;Concepto;Saldo
//                 Un solo monto: + crédito, - débito. El saldo, en el último
//                 renglón de cada día. No trae el número de cuenta.
//   BNA_EMPRESAS  Cuenta;CUIT Cuenta;Fecha;Monto;N° de Comprobante;Descripción;Saldo
//                 (BEE_Reporte_*.csv de Banca Empresas). La cuenta en cada
//                 fila. «CUIT Cuenta» es el de la cuenta propia y no se
//                 guarda: marcaría todos los movimientos contra uno mismo.
//   ICBC_CC       «Movimientos de CC $ 0501/02146686/72» en la primera línea y
//                 Fecha contable;Cod de Concepto;Concepto;Debito en $;Credito en $;
//                 Saldo en $;Informacion Complementaria;Nro de cheque;Sucursal Origen;Canal
//                 Débito (con signo -) y crédito en columnas separadas; el saldo
//                 en todos los renglones.
//   ICBC_REC      CUENTA;NOMBRE;MONEDA;COMPROBANTE;IMPORTE MOVTO.;CONCEPTO;SUC;CHEQUE;
//                 DATOS COMPLEMENTARIOS DE LA OPERACION
//                 Reporte de recaudaciones. Los renglones no traen fecha: va al
//                 principio del nombre del archivo («19.11.2026 …»); sin ella no
//                 se importa (en gestion_adm tomaba la de hoy sin avisar). Un
//                 concepto que empieza con DEB es débito; el resto, crédito.
//
// Cada renglón queda con fecha, fecha valor, referencia, débito o crédito
// (siempre positivos), el saldo si el banco lo informa, el concepto y el
// CUIT de la contraparte (el primer CUIT/CUIL válido que aparezca en el texto).
//
// Controles
//   - Banco: un extracto del BNA no entra en una cuenta del ICBC, ni al revés.
//   - Cuenta: los últimos cinco dígitos de CADA número de cuenta que declara
//     el archivo contra el número cargado en la cuenta elegida (el mismo número
//     se escribe «CC $ 00010003978039», «0001-0003978039» o «0501/02146774/97»:
//     separadores y ceros cambian, el final no). Si no coincide, o la cuenta no
//     tiene número cargado, no se importa nada. El BNA clásico no trae el
//     número: pasa con un aviso.
//   - Duplicados: misma referencia, fecha e importes; sin referencia, mismo
//     concepto. No se compara el saldo (el BNA lo trae en un renglón por día y
//     depende de cómo se bajó). Los renglones iguales se cuentan: si el archivo
//     trae siete y la cuenta ya tiene cinco, entran dos. Sin filtro de fecha:
//     se reimporta cualquier período y en cualquier orden.
//   - Cada archivo deja su renglón en extractos_importaciones, también si no
//     se importó (con el motivo). Una importación se deshace entera mientras
//     ninguno de sus movimientos esté conciliado.
// ---------------------------------------------------------------------

require_once __DIR__ . '/fondos.php';

const EXT_FORMATOS = [
    'BNA_CLASICO'  => 'Banco Nación clásico',
    'BNA_EMPRESAS' => 'Banco Nación · Banca Empresas',
    'ICBC_CC'      => 'ICBC cuenta corriente',
    'ICBC_REC'     => 'ICBC recaudaciones',
];
/** Código BCRA del banco de cada formato (los tres primeros números del CBU). */
const EXT_BANCO_FORMATO  = ['BNA_CLASICO' => '011', 'BNA_EMPRESAS' => '011', 'ICBC_CC' => '015', 'ICBC_REC' => '015'];
/** Lo que se guarda en extractos_bancarios.origen. */
const EXT_ORIGEN_FORMATO = ['BNA_CLASICO' => 'BNA', 'BNA_EMPRESAS' => 'BNA', 'ICBC_CC' => 'ICBC', 'ICBC_REC' => 'ICBC'];
const EXT_DIGITOS_CUENTA = 5;
const EXT_LARGO_REF      = 40;
const EXT_LARGO_CONCEPTO = 200;
const EXT_EXTENSIONES    = ['csv', 'txt'];

/** El archivo como texto UTF-8: sin BOM y, si no venía en UTF-8, leído como Latin-1. */
function ext_texto(string $raw): string
{
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    if (preg_match('//u', $raw)) {
        return $raw;
    }
    $out = '';
    for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
        $b = ord($raw[$i]);
        $out .= $b < 0x80 ? $raw[$i] : chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F));
    }
    return $out;
}

/** Las líneas que tienen algo escrito. */
function ext_lineas(string $texto): array
{
    return array_values(array_filter(preg_split('/\r\n|\r|\n/', $texto), function ($l) {
        return trim($l) !== '';
    }));
}

/** «8.000,00», «-1.044.540,00», «-49830,37  » -> número; null si no es un importe. */
function ext_monto(string $s): ?float
{
    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if ($s === '' || $s === '-') {
        return null;
    }
    $s = str_replace(['.', ','], ['', '.'], $s);
    return preg_match('/^-?\d+(\.\d+)?$/', $s) ? round((float) $s, 2) : null;
}

/** DD/MM/AAAA -> AAAA-MM-DD; null si no es una fecha. */
function ext_fecha(string $s): ?string
{
    if (!preg_match('#^\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$#', $s, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
}

/** La fecha al principio del nombre del archivo («19.11.2026 …», «30.07.26 …»), o null. */
function ext_fecha_del_nombre(string $nombre): ?string
{
    if (!preg_match('/^\s*(\d{2})[.\-\/](\d{2})[.\-\/](\d{4}|\d{2})(?!\d)/', $nombre, $m)) {
        return null;
    }
    $a = (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0);
    return checkdate((int) $m[2], (int) $m[1], $a) ? sprintf('%04d-%02d-%02d', $a, (int) $m[2], (int) $m[1]) : null;
}

/** El primer CUIT/CUIL válido (dígito verificador) que aparece en los textos: el de la contraparte. */
function ext_cuit(string ...$textos): ?string
{
    foreach ($textos as $t) {
        if (preg_match_all('/(?<!\d)(?:20|23|24|27|30|33|34)\d{9}(?!\d)/', $t, $m)) {
            foreach ($m[0] as $c) {
                if (cuit_valido($c)) {
                    return $c;
                }
            }
        }
    }
    return null;
}

/** Texto en una línea, sin espacios de más y cortado a $n caracteres (sin partir una letra). */
function ext_corte(string $s, int $n): string
{
    if (!preg_match('//u', $s)) {
        $s = ext_texto($s);
    }
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    return preg_match('/^.{0,' . $n . '}/u', $s, $m) ? $m[0] : substr($s, 0, $n);
}

function ext_cola(?string $s): string
{
    $d = preg_replace('/\D/', '', (string) $s);
    return strlen($d) >= EXT_DIGITOS_CUENTA ? substr($d, -EXT_DIGITOS_CUENTA) : '';
}

/** ¿El mismo número de cuenta, escrito de dos formas? */
function ext_mismo_numero(?string $a, ?string $b): bool
{
    $x = ext_cola($a);
    return $x !== '' && $x === ext_cola($b);
}

/** ¿Es el encabezado de Banca Empresas del BNA? Dos marcas, no una: confundir el formato lee el importe de otra columna. */
function ext_es_bna_empresas(string $linea): bool
{
    $l = strtolower($linea);
    return strpos($l, 'cuit cuenta') !== false && strpos($l, 'comprobante') !== false && strpos($l, 'monto') !== false;
}

/** [formato, n° de la línea del encabezado], o null si no es ninguno de los conocidos. */
function ext_formato(array $lineas): ?array
{
    foreach (array_slice($lineas, 0, 10) as $i => $l) {
        $u = strtoupper($l);
        if (ext_es_bna_empresas($l)) {
            return ['BNA_EMPRESAS', $i];
        }
        if (strpos($u, 'FECHA MVTO') !== false) {
            return ['BNA_CLASICO', $i];
        }
        if (strpos($u, 'FECHA CONTABLE') !== false) {
            return ['ICBC_CC', $i];
        }
        if (strpos($u, 'IMPORTE MOVTO') !== false) {
            return ['ICBC_REC', $i];
        }
    }
    return null;
}

/** Un renglón del extracto, con los textos ya cortados a lo que entra en la tabla. */
function ext_renglon(string $fecha, ?string $fechaValor, string $ref, ?float $debito, ?float $credito, ?float $saldo,
                     string $concepto, ?string $cuit): array
{
    return ['fecha' => $fecha, 'fecha_valor' => $fechaValor ?? $fecha, 'referencia' => ext_corte($ref, EXT_LARGO_REF),
            'debito' => $debito, 'credito' => $credito, 'saldo' => $saldo,
            'concepto' => ext_corte($concepto, EXT_LARGO_CONCEPTO), 'cuit' => $cuit];
}

/**
 * Lee el archivo: ['formato' => …, 'filas' => [renglones], 'cuentas' => números de cuenta que declara].
 * RuntimeException si no se reconoce el formato o le falta algo para leerlo.
 */
function ext_leer(string $texto, string $nombre): array
{
    $lineas = ext_lineas($texto);
    $f = ext_formato($lineas);
    if (!$f) {
        throw new RuntimeException('No se reconoce el formato: tiene que ser un extracto de Banco Nación (clásico o Banca Empresas) o del ICBC (cuenta corriente o recaudaciones), tal como lo baja el banco.');
    }
    [$formato, $enc] = $f;
    $filas = [];
    $cuentas = [];
    $cuenta = function (string $c) use (&$cuentas): void {
        $c = trim($c);
        if (ext_cola($c) !== '' && !in_array($c, $cuentas, true)) {
            $cuentas[] = $c;
        }
    };

    if ($formato === 'BNA_CLASICO' || $formato === 'BNA_EMPRESAS') {
        $e = $formato === 'BNA_EMPRESAS';
        // Banca Empresas trae una sola fecha: la fecha valor queda igual (inventar otra sería peor).
        [$cFecha, $cValor, $cMonto, $cRef, $cConc, $cSaldo] = $e ? [2, 2, 3, 4, 5, 6] : [0, 1, 2, 3, 4, 5];
        foreach (array_slice($lineas, $enc + 1) as $l) {
            $p = array_map('trim', explode(';', $l));
            if ($e) {
                $cuenta($p[0]);
            }
            if (count($p) < $cConc + 1) {
                continue;
            }
            $fecha = ext_fecha($p[$cFecha]);
            $monto = ext_monto($p[$cMonto]);
            if ($fecha === null || $monto === null || $monto == 0.0) {
                continue;
            }
            $filas[] = ext_renglon($fecha, ext_fecha($p[$cValor]), ltrim($p[$cRef], '0'), $monto < 0 ? -$monto : null,
                                   $monto > 0 ? $monto : null, ext_monto($p[$cSaldo] ?? ''), $p[$cConc], ext_cuit($p[$cConc]));
        }
    } elseif ($formato === 'ICBC_CC') {
        foreach (array_slice($lineas, 0, $enc) as $l) {          // «Movimientos de CC $ 0501/02146686/72»
            if (preg_match('#(\d{3,5}\s*/\s*\d{5,10}\s*/\s*\d{1,3})#', $l, $m)) {
                $cuenta($m[1]);
                break;
            }
        }
        foreach (array_slice($lineas, $enc + 1) as $l) {
            $p = array_map('trim', explode(';', $l));
            $fecha = ext_fecha($p[0]);
            if ($fecha === null || count($p) < 5) {
                continue;
            }
            $deb = ext_monto($p[3]);
            $cred = ext_monto($p[4]);
            $deb = $deb !== null && $deb != 0.0 ? abs($deb) : null;     // viene con signo -: se guarda positivo
            $cred = $cred !== null && $cred > 0 ? $cred : null;
            if ($deb === null && $cred === null) {
                continue;
            }
            $info = $p[6] ?? '';
            $cheque = $p[7] ?? '';
            $concepto = $info !== '' && stripos($p[2], $info) === false ? $p[2] . ' — ' . $info : $p[2];
            $filas[] = ext_renglon($fecha, null, $cheque !== '' ? $cheque : $info, $deb, $cred, ext_monto($p[5] ?? ''),
                                   $concepto, ext_cuit($info, $p[2]));
        }
    } else {                                                        // ICBC_REC
        $fecha = ext_fecha_del_nombre($nombre);
        if ($fecha === null) {
            throw new RuntimeException('El reporte de recaudaciones del ICBC no trae la fecha en los renglones: va al principio del nombre del archivo, por ejemplo «19.11.2026 ' . $nombre . '». Renombralo y volvé a importarlo.');
        }
        foreach (array_slice($lineas, $enc + 1) as $l) {
            if (substr_count($l, ';') < 5) {
                continue;
            }
            $p = array_map('trim', explode(';', $l));
            $cuenta($p[0]);
            $importe = ext_monto($p[4]);
            if ($importe === null || $importe == 0.0) {
                continue;
            }
            $datos = $p[8] ?? '';
            $esDeb = stripos($p[5], 'DEB') === 0;                   // DEB.TRANSF.REC: el barrido a la cuenta operativa
            $concepto = $datos !== '' && stripos($p[5], $datos) === false ? $p[5] . ' — ' . $datos : $p[5];
            $filas[] = ext_renglon($fecha, null, $p[3] !== '' ? $p[3] : ($p[7] ?? ''), $esDeb ? abs($importe) : null,
                                   $esDeb ? null : abs($importe), null, $concepto, ext_cuit($datos, $p[5]));
        }
    }
    return ['formato' => $formato, 'filas' => $filas, 'cuentas' => $cuentas];
}

/**
 * ¿El archivo es de la cuenta elegida? RuntimeException si no. Devuelve un
 * aviso cuando no se pudo comprobar (el BNA clásico no trae el número).
 * $cuenta: una fila de cuentas_fondos() (con banco_codigo y banco).
 */
function ext_validar_cuenta(array $cuenta, string $formato, array $delArchivo): string
{
    $codigo = EXT_BANCO_FORMATO[$formato];
    if (!empty($cuenta['banco_codigo']) && $cuenta['banco_codigo'] !== $codigo) {
        throw new RuntimeException('El archivo es un extracto de ' . (cbu_bancos()[$codigo] ?? EXT_ORIGEN_FORMATO[$formato]) . ' y '
            . $cuenta['nombre'] . ' es de ' . $cuenta['banco'] . '. No se importó nada.');
    }
    if (!$delArchivo) {
        return 'El archivo no trae el número de cuenta: no se pudo comprobar que sea de ' . $cuenta['nombre'] . '.';
    }
    $numero = (string) ($cuenta['numero_cuenta'] ?? '');
    if (ext_cola($numero) === '') {
        throw new RuntimeException($cuenta['nombre'] . ' no tiene cargado el número de cuenta y el archivo es de la cuenta '
            . $delArchivo[0] . ': cargalo en Cuentas de fondos antes de importar. No se importó nada.');
    }
    $ajenas = array_values(array_filter($delArchivo, function ($n) use ($numero) { return !ext_mismo_numero($n, $numero); }));
    if (!$ajenas) {
        return '';
    }
    $todas = cuentas_fondos(null, true);
    $deQuien = function (string $n) use ($todas): string {
        foreach ($todas as $o) {
            if (ext_mismo_numero($n, $o['numero_cuenta'] ?? '')) {
                return $n . ' (' . $o['nombre'] . ')';
            }
        }
        return $n . ' (no está en Cuentas de fondos)';
    };
    throw new RuntimeException((count($ajenas) < count($delArchivo) ? 'El archivo mezcla movimientos de la cuenta elegida con otras: '
                                                                      : 'El archivo es de la cuenta ')
        . implode(', ', array_map($deQuien, $ajenas)) . ' y se eligió ' . $cuenta['nombre'] . ' (…' . ext_cola($numero) . '). No se importó nada.');
}

/** Clave de duplicado: referencia, fecha e importes; sin referencia, el concepto. El saldo no cuenta. */
function ext_clave(array $f): string
{
    $ref = (string) ($f['referencia'] ?? '');
    $imp = number_format((float) ($f['debito'] ?? 0), 2, '.', '') . '|' . number_format((float) ($f['credito'] ?? 0), 2, '.', '');
    return $ref !== '' ? 'R|' . $ref . '|' . $f['fecha'] . '|' . $imp
                       : 'C|' . $f['fecha'] . '|' . $imp . '|' . strtoupper((string) ($f['concepto'] ?? ''));
}

/**
 * Importa un archivo en una cuenta (una fila de cuentas_fondos()). Siempre deja
 * su renglón en extractos_importaciones —si no se importó, con el motivo— y
 * lo devuelve.
 */
function ext_importar(array $cuenta, string $nombre, string $raw): array
{
    $imp = ['id_importacion' => 0, 'archivo' => ext_corte($nombre, 255), 'formato' => null, 'resultado' => 'ERROR', 'motivo' => null,
            'renglones' => 0, 'insertados' => 0, 'repetidos' => 0, 'total_debitos' => 0.0, 'total_creditos' => 0.0,
            'fecha_desde' => null, 'fecha_hasta' => null];
    $idCuenta = (int) $cuenta['id_cuenta_fondos'];
    $pdo = db();
    try {
        $leido = ext_leer(ext_texto($raw), $nombre);
        $imp['formato'] = $leido['formato'];
        $aviso = ext_validar_cuenta($cuenta, $leido['formato'], $leido['cuentas']);
        $filas = $leido['filas'];
        if (!$filas) {
            throw new RuntimeException('El archivo no tiene movimientos.');
        }
        $fechas = array_column($filas, 'fecha');
        $imp['renglones'] = count($filas);
        $imp['fecha_desde'] = min($fechas);
        $imp['fecha_hasta'] = max($fechas);
        $imp['motivo'] = $aviso !== '' ? $aviso : null;

        $pdo->beginTransaction();
        // Lo que la cuenta ya tiene en esas fechas, agrupado por clave (id y saldo de cada uno).
        $ya = [];
        foreach (q('SELECT id_extracto, referencia, fecha, debito, credito, concepto, saldo FROM extractos_bancarios
                     WHERE id_cuenta_fondos = ? AND fecha BETWEEN ? AND ? ORDER BY id_extracto FOR UPDATE',
                   [$idCuenta, $imp['fecha_desde'], $imp['fecha_hasta']]) as $e) {
            $ya[ext_clave($e)][] = $e;
        }
        $completa = $pdo->prepare('UPDATE extractos_bancarios SET saldo = ? WHERE id_extracto = ? AND saldo IS NULL');
        q("INSERT INTO extractos_importaciones (id_cuenta_fondos, archivo, formato, resultado, motivo, renglones, fecha_desde, fecha_hasta, importado_por)
           VALUES (?, ?, ?, 'OK', ?, ?, ?, ?, ?)",
          [$idCuenta, $imp['archivo'], $imp['formato'], $imp['motivo'], $imp['renglones'], $imp['fecha_desde'], $imp['fecha_hasta'], usuario_id()]);
        $imp['id_importacion'] = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, fecha_valor, referencia,
                                                              debito, credito, saldo, concepto, cuit, origen)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $vistos = [];
        foreach ($filas as $f) {
            $k = ext_clave($f);
            $vistos[$k] = ($vistos[$k] ?? 0) + 1;
            if ($vistos[$k] <= count($ya[$k] ?? [])) {
                // Ya estaba. Si este archivo trae el saldo y el renglón cargado no (el BNA lo pone en un renglón
                // distinto según el formato), se completa: el saldo del día no se pierde.
                $existente = $ya[$k][$vistos[$k] - 1];
                if ($f['saldo'] !== null && $existente['saldo'] === null) {
                    $completa->execute([$f['saldo'], $existente['id_extracto']]);
                }
                $imp['repetidos']++;
                continue;
            }
            $st->execute([$idCuenta, $imp['id_importacion'], $f['fecha'], $f['fecha_valor'], $f['referencia'] !== '' ? $f['referencia'] : null,
                          $f['debito'], $f['credito'], $f['saldo'], $f['concepto'] !== '' ? $f['concepto'] : null, $f['cuit'],
                          EXT_ORIGEN_FORMATO[$imp['formato']]]);
            $imp['insertados']++;
            $imp['total_debitos'] += (float) $f['debito'];
            $imp['total_creditos'] += (float) $f['credito'];
        }
        q('UPDATE extractos_importaciones SET insertados = ?, repetidos = ?, total_debitos = ?, total_creditos = ? WHERE id_importacion = ?',
          [$imp['insertados'], $imp['repetidos'], round($imp['total_debitos'], 2), round($imp['total_creditos'], 2), $imp['id_importacion']]);
        $pdo->commit();
        $imp['resultado'] = 'OK';
        return $imp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $imp = array_merge($imp, ['resultado' => 'ERROR', 'insertados' => 0, 'repetidos' => 0, 'total_debitos' => 0.0, 'total_creditos' => 0.0,
                                  'motivo' => ext_corte($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e), 255)]);
        try {
            q("INSERT INTO extractos_importaciones (id_cuenta_fondos, archivo, formato, resultado, motivo, renglones, fecha_desde, fecha_hasta, importado_por)
               VALUES (?, ?, ?, 'ERROR', ?, ?, ?, ?, ?)",
              [$idCuenta, $imp['archivo'], $imp['formato'], $imp['motivo'], $imp['renglones'], $imp['fecha_desde'], $imp['fecha_hasta'], usuario_id()]);
            $imp['id_importacion'] = (int) $pdo->lastInsertId();
        } catch (Throwable $e2) {
            error_log('[financiera] no se registró la importación fallida de ' . $nombre . ': ' . $e2->getMessage());
        }
        return $imp;
    }
}

/**
 * « OR (está en una conciliación abierta, conciliado o como excepción)», para
 * sumar a la condición de conciliado. Vacío si la conciliación no está
 * instalada. $t: el alias de extractos_bancarios.
 */
function ext_sql_en_conciliacion(string $t): string
{
    static $hay = null;
    $hay ??= (bool) q1("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conciliacion_items'");
    return $hay ? " OR EXISTS (SELECT 1 FROM conciliacion_items ci WHERE ci.id_extracto = $t.id_extracto AND ci.estado <> 'pendiente')" : '';
}

/** Deshace una importación: borra sus movimientos si ninguno está conciliado. Devuelve cuántos borró. */
function ext_deshacer(int $idImportacion): int
{
    $imp = q1('SELECT * FROM extractos_importaciones WHERE id_importacion = ?', [$idImportacion]);
    if (!$imp || $imp['resultado'] !== 'OK' || $imp['deshecha_el'] !== null) {
        throw new RuntimeException('Esa importación no existe o ya se deshizo.');
    }
    $n = q1('SELECT COUNT(*) AS n, COALESCE(SUM(conciliado' . ext_sql_en_conciliacion('e') . '), 0) AS conc FROM extractos_bancarios e WHERE id_importacion = ?', [$idImportacion]);
    if ((int) $n['conc'] > 0) {
        throw new RuntimeException('No se puede deshacer: ' . ((int) $n['conc'] === 1 ? 'uno de sus movimientos ya está conciliado.'
                                                                     : num($n['conc']) . ' de sus movimientos ya están conciliados.'));
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('DELETE FROM extractos_bancarios WHERE id_importacion = ? AND conciliado = 0');
        $st->execute([$idImportacion]);
        $borrados = $st->rowCount();
        q('UPDATE extractos_importaciones SET deshecha_el = NOW(), deshecha_por = ? WHERE id_importacion = ?', [usuario_id(), $idImportacion]);
        $pdo->commit();
        return $borrados;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
