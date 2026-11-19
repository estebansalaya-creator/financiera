<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';
require __DIR__ . '/../inc/documentos.php';

// ---------------------------------------------------------------------
// Importar un lote de cobranza (CSV)
//   1. Subir: el archivo queda en <docs_dir>\lotes\ y se muestra la vista
//      previa, fila por fila, con lo que se va a hacer y los errores.
//   2. Procesar: se vuelve a leer y validar el archivo guardado (no lo que
//      vino del navegador), se crea el lote y se registra cada pago con
//      sp_registrar_pago, por fecha. Las filas con error no se cargan.
//
// Formato: primera fila con los títulos; separador ; , o tabulación.
//   prestamo   N° de préstamo (P-000123)       } uno de los dos
//   cuit       CUIT/CUIL o DNI del cliente     } (con CUIT, el cliente
//                                                tiene que tener un solo
//                                                préstamo vigente)
//   importe    1234,56 o 1234.56               obligatorio
//   fecha      dd/mm/aaaa o aaaa-mm-dd         si falta, la del formulario
//   referencia N° de operación, código…        opcional
// ---------------------------------------------------------------------

const LOTE_COLUMNAS = [
    'prestamo'   => ['prestamo', 'préstamo', 'nro_prestamo', 'nro prestamo', 'n° prestamo', 'numero', 'nro'],
    'cuit'       => ['cuit', 'cuil', 'cuit/cuil', 'dni', 'documento', 'doc'],
    'importe'    => ['importe', 'monto', 'pago', 'importe pagado', 'total'],
    'fecha'      => ['fecha', 'fecha pago', 'fecha_pago', 'fecha de pago'],
    'referencia' => ['referencia', 'ref', 'operacion', 'operación', 'codigo', 'código', 'comprobante'],
];

function lotes_dir(): string
{
    return docs_dir() . DIRECTORY_SEPARATOR . 'lotes';
}

/** Texto en UTF-8 (los CSV de Excel en Windows suelen venir en Windows-1252). */
function a_utf8(string $s): string
{
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    if (preg_match('//u', $s)) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    if (function_exists('iconv')) {
        return (string) iconv('Windows-1252', 'UTF-8//IGNORE', $s);
    }
    return preg_replace('/[\x80-\xFF]/', '?', $s);
}

/** Lee el CSV: [filas como [campo => valor, '_fila' => n], error o '']. */
function lote_leer(string $ruta): array
{
    $txt = a_utf8((string) file_get_contents($ruta));
    $lineas = preg_split('/\r\n|\r|\n/', $txt);
    $lineas = array_values(array_filter($lineas, function ($l) { return trim($l) !== ''; }));
    if (count($lineas) < 2) {
        return [[], 'El archivo no tiene datos (hace falta la fila de títulos y al menos un pago).'];
    }
    $sep = ';';
    foreach (["\t", ';', ','] as $s) {
        if (substr_count($lineas[0], $s) > 0) {
            $sep = $s;
            break;
        }
    }
    $titulos = array_map(function ($t) { return strtolower(trim($t, " \t\"")); }, str_getcsv($lineas[0], $sep));
    $mapa = [];
    foreach ($titulos as $i => $t) {
        foreach (LOTE_COLUMNAS as $campo => $alias) {
            if (in_array($t, $alias, true) && !isset($mapa[$campo])) {
                $mapa[$campo] = $i;
            }
        }
    }
    if (!isset($mapa['importe']) || (!isset($mapa['prestamo']) && !isset($mapa['cuit']))) {
        return [[], 'No encuentro las columnas: hacen falta «importe» y «prestamo» (o «cuit»). Títulos del archivo: ' . implode(', ', $titulos) . '.'];
    }
    if (count($lineas) > 2001) {
        return [[], 'El archivo tiene más de 2000 pagos: partilo en varios lotes.'];
    }
    $filas = [];
    for ($n = 1; $n < count($lineas); $n++) {
        $cel = str_getcsv($lineas[$n], $sep);
        $f = ['_fila' => $n + 1];
        foreach (array_keys(LOTE_COLUMNAS) as $campo) {
            $f[$campo] = isset($mapa[$campo]) ? trim((string) ($cel[$mapa[$campo]] ?? '')) : '';
        }
        $filas[] = $f;
    }
    return [$filas, ''];
}

/**
 * Valida cada fila contra la base. Agrega a cada una: id_prestamo, nro,
 * cliente, fecha (aaaa-mm-dd), importe (float), deuda, ok (bool), error.
 * La suma de las filas de un mismo préstamo no puede superar su deuda.
 */
function lote_validar(array $filas, string $fechaDef): array
{
    $acum = [];
    $vistos = [];
    foreach ($filas as &$f) {
        $f['ok'] = false;
        $f['error'] = '';
        $f['deuda'] = null;
        $f['id_prestamo'] = null;
        $f['nro'] = '';
        $f['cliente'] = '';
        $imp = leer_num($f['importe']);
        $fec = $f['fecha'] === '' ? $fechaDef : leer_fecha($f['fecha']);
        $f['importe_n'] = $imp;
        $f['fecha_n'] = $fec;
        if ($imp === null || $imp <= 0) {
            $f['error'] = 'Importe inválido («' . $f['importe'] . '»).';
            continue;
        }
        if ($fec === null || $fec > date('Y-m-d')) {
            $f['error'] = 'Fecha inválida o futura («' . $f['fecha'] . '»).';
            continue;
        }
        // Préstamo
        $p = null;
        if ($f['prestamo'] !== '') {
            $p = q1("SELECT p.id_prestamo, p.nro_prestamo, p.estado, p.fecha_liquidacion, c.denominacion
                       FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente WHERE p.nro_prestamo = ?", [$f['prestamo']]);
            if (!$p) {
                $f['error'] = 'No existe el préstamo «' . $f['prestamo'] . '».';
                continue;
            }
        } else {
            $d = preg_replace('/\D/', '', $f['cuit']);
            $cond = strlen($d) === 11 ? 'c.cuit = ?' : 'cp.dni = ?';
            $vig = strlen($d) >= 7 ? q("SELECT p.id_prestamo, p.nro_prestamo, p.estado, p.fecha_liquidacion, c.denominacion
                                          FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente
                                          LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
                                         WHERE $cond AND p.estado = 'VIGENTE'", [$d]) : [];
            if (count($vig) !== 1) {
                $f['error'] = $vig ? 'El cliente ' . $f['cuit'] . ' tiene ' . count($vig) . ' préstamos vigentes: indicá el N° de préstamo.'
                                   : 'No hay un préstamo vigente para «' . $f['cuit'] . '».';
                continue;
            }
            $p = $vig[0];
        }
        $f['id_prestamo'] = (int) $p['id_prestamo'];
        $f['nro'] = $p['nro_prestamo'];
        $f['cliente'] = $p['denominacion'];
        if ($p['estado'] !== 'VIGENTE') {
            $f['error'] = 'El préstamo está ' . strtolower(ESTADOS_PRESTAMO[$p['estado']] ?? $p['estado']) . '.';
            continue;
        }
        if ($fec < $p['fecha_liquidacion']) {
            $f['error'] = 'La fecha es anterior a la liquidación del préstamo.';
            continue;
        }
        $ult = q1("SELECT MAX(fecha) AS f FROM pagos WHERE id_prestamo = ? AND estado = 'APLICADO'", [$p['id_prestamo']]);
        if ($ult['f'] && $fec < $ult['f']) {
            $f['error'] = 'Ya tiene un pago del ' . fecha($ult['f']) . ': no se puede imputar con fecha anterior.';
            continue;
        }
        // Duplicados: en el mismo archivo o ya registrado
        $clave = $p['id_prestamo'] . '|' . $fec . '|' . number_format($imp, 2, '.', '') . '|' . $f['referencia'];
        if (isset($vistos[$clave])) {
            $f['error'] = 'Repetido en el archivo (igual a la fila ' . $vistos[$clave] . ').';
            continue;
        }
        $vistos[$clave] = $f['_fila'];
        $dup = q1("SELECT nro_recibo FROM pagos WHERE id_prestamo = ? AND fecha = ? AND importe = ? AND estado = 'APLICADO'
                    AND COALESCE(referencia, '') = ?", [$p['id_prestamo'], $fec, round($imp, 2), $f['referencia']]);
        if ($dup) {
            $f['error'] = 'Ya está registrado (recibo ' . $dup['nro_recibo'] . ').';
            continue;
        }
        $deuda = estado_cuenta((int) $p['id_prestamo'], $fec)['tot']['total'];
        $f['deuda'] = $deuda;
        $acum[$p['id_prestamo']] = ($acum[$p['id_prestamo']] ?? 0) + $imp;
        if ($acum[$p['id_prestamo']] > $deuda + 0.004) {
            $f['error'] = 'Supera la deuda del préstamo (' . money($deuda) . ' al ' . fecha($fec) . '). Una cancelación se registra desde «Cobrar».';
            continue;
        }
        $f['ok'] = true;
    }
    unset($f);
    return $filas;
}

$errores = [];
$filas = [];
$token = '';
$nombreArchivo = '';
$medio = post_str('medio') ?: 'RAPIPAGO';
$fechaDef = leer_fecha(post_str('fecha')) ?? date('Y-m-d');

if (es_post()) {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errores[] = 'El archivo supera lo que acepta el servidor (' . ini_get('post_max_size') . ').';
    } else {
        csrf_exigir();
        $accion = post_str('accion');
        if (!isset(MEDIOS_PAGO[$medio])) {
            $errores[] = 'Elegí el medio de pago del lote.';
        }
        if ($accion === 'subir' && !$errores) {
            $a = doc_archivos_subidos('archivo')[0] ?? null;
            if (!$a || $a['error'] !== UPLOAD_ERR_OK) {
                $errores[] = $a ? 'El archivo ' . doc_error_subida($a['error']) . '.' : 'Elegí el archivo.';
            } elseif (!preg_match('/\.(csv|txt)$/i', $a['name'])) {
                $errores[] = 'El archivo tiene que ser CSV o TXT (en Excel: Guardar como → CSV).';
            } else {
                if (!is_dir(lotes_dir())) {
                    @mkdir(lotes_dir(), 0770, true);
                }
                $token = bin2hex(random_bytes(12));
                $nombreArchivo = doc_nombre_limpio($a['name']);
                if (!@move_uploaded_file($a['tmp_name'], lotes_dir() . DIRECTORY_SEPARATOR . 'pendiente_' . $token . '.csv')) {
                    $errores[] = 'No se pudo guardar el archivo en ' . lotes_dir() . ' (permisos de la carpeta de documentos).';
                    $token = '';
                }
            }
        } elseif ($accion === 'procesar') {
            $token = preg_match('/^[a-f0-9]{24}$/', post_str('token')) ? post_str('token') : '';
            $nombreArchivo = doc_nombre_limpio(post_str('archivo_nombre'));
            if ($token === '' || !is_file(lotes_dir() . DIRECTORY_SEPARATOR . 'pendiente_' . $token . '.csv')) {
                $errores[] = 'No encuentro el archivo subido (¿ya se procesó?). Subilo de nuevo.';
                $token = '';
            }
        }

        if ($token !== '' && !$errores) {
            $ruta = lotes_dir() . DIRECTORY_SEPARATOR . 'pendiente_' . $token . '.csv';
            try {
                [$filas, $err] = lote_leer($ruta);
                if ($err !== '') {
                    $errores[] = $err;
                } else {
                    $filas = lote_validar($filas, $fechaDef);
                }
            } catch (Throwable $e) {
                $errores[] = error_amigable($e);
            }

            if ($accion === 'procesar' && !$errores) {
                $ok = array_values(array_filter($filas, function ($f) { return $f['ok']; }));
                if (!$ok) {
                    $errores[] = 'No hay filas para cargar.';
                } else {
                    try {
                        q('INSERT INTO lotes_cobranza (medio, fecha, archivo, creado_por) VALUES (?,?,?,?)', [$medio, $fechaDef, $nombreArchivo, usuario_id()]);
                        $idLote = (int) db()->lastInsertId();
                        usort($ok, function ($a, $b) { return [$a['fecha_n'], $a['_fila']] <=> [$b['fecha_n'], $b['_fila']]; });
                        $hechos = 0;
                        $total = 0.0;
                        $fallas = [];
                        foreach ($ok as $f) {
                            try {
                                registrar_pago($f['id_prestamo'], $f['fecha_n'], $f['importe_n'], $medio, substr($f['referencia'], 0, 100), 'NORMAL', $idLote);
                                $hechos++;
                                $total += $f['importe_n'];
                            } catch (Throwable $e) {
                                $fallas[] = 'fila ' . $f['_fila'] . ': ' . mensaje_bd($e);
                            }
                        }
                        q("UPDATE lotes_cobranza SET registros = ?, total = ?, estado = 'PROCESADO' WHERE id_lote = ?", [$hechos, round($total, 2), $idLote]);
                        @rename($ruta, lotes_dir() . DIRECTORY_SEPARATOR . 'lote_' . $idLote . '_' . preg_replace('/[^\w.\-]+/', '_', $nombreArchivo));
                        $sinCargar = count($filas) - $hechos;
                        flash($sinCargar ? 'warning' : 'ok', 'Lote N° ' . $idLote . ': se registraron ' . $hechos . ' pagos por ' . money($total) . '.'
                              . ($sinCargar ? ' Quedaron sin cargar ' . $sinCargar . ' filas' . ($fallas ? ' (' . implode('; ', array_slice($fallas, 0, 5)) . ')' : '') . '.' : ''));
                        redirigir('cobranzas.php?lote=' . $idLote);
                    } catch (Throwable $e) {
                        $errores[] = mensaje_bd($e);
                    }
                }
            }
        }
    }
}

$nOk = count(array_filter($filas, function ($f) { return $f['ok']; }));
$totOk = array_sum(array_map(function ($f) { return $f['ok'] ? $f['importe_n'] : 0; }, $filas));

layout_inicio('Importar lote', 'cobranzas');
titulo('Importar lote de cobranza', 'Rapipago, Pago Fácil, banco o Mercado Pago, desde un archivo CSV', '',
       '<a href="cobranzas.php">Cobranzas</a> / Importar lote');
mensajes($errores);
?>

<?php if (!$filas): ?>
<div class="columnas-2-1">
  <div class="card">
    <?= card_head('Archivo') ?>
    <form method="post" action="lote.php" enctype="multipart/form-data">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="subir">
      <div class="bloque">
        <div class="campos">
          <div class="campo" style="--span:12"><label for="archivo">Archivo CSV <span class="req">*</span></label>
            <input type="file" id="archivo" name="archivo[]" accept=".csv,.txt,text/csv" required></div>
          <div class="campo" style="--span:6"><label for="medio">Medio de pago</label>
            <select id="medio" name="medio"><?= opciones(MEDIOS_PAGO, $medio) ?></select></div>
          <div class="campo" style="--span:6"><label for="fecha">Fecha (si el archivo no la trae)</label>
            <input type="date" id="fecha" name="fecha" value="<?= h($fechaDef) ?>" max="<?= date('Y-m-d') ?>"></div>
        </div>
      </div>
      <div class="form-acciones"><?= boton('Ver vista previa', 'primary', null, 'filtro', true) ?></div>
    </form>
  </div>
  <div class="card">
    <?= card_head('Formato') ?>
    <div class="bloque formato-lote">
      <p>Primera fila con los títulos; separado por <code>;</code>, <code>,</code> o tabulación (en Excel: <em>Guardar como → CSV</em>).</p>
      <ul>
        <li><code>prestamo</code> N° de préstamo, o <code>cuit</code> CUIT/CUIL o DNI (si tiene un solo préstamo vigente)</li>
        <li><code>importe</code> 1234,56 o 1234.56</li>
        <li><code>fecha</code> dd/mm/aaaa (opcional)</li>
        <li><code>referencia</code> N° de operación (opcional)</li>
      </ul>
      <pre>prestamo;fecha;importe;referencia
P-000012;18/11/2026;45.042,16;RP-88213
P-000027;18/11/2026;30000;RP-88214</pre>
      <p>Nada se carga hasta confirmar la vista previa. Las filas con error se saltean.</p>
    </div>
  </div>
</div>
<?php else: ?>
<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Archivo</span><strong style="font-size:1rem"><?= h($nombreArchivo) ?></strong>
    <span class="bcra-tarjeta-sub"><?= h(MEDIOS_PAGO[$medio]) ?> · <?= count($filas) ?> filas</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Se cargan</span><strong><?= num($nOk) ?></strong><span class="bcra-tarjeta-sub"><?= money($totOk) ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Con error</span><strong><?= num(count($filas) - $nOk) ?></strong><span class="bcra-tarjeta-sub">no se cargan</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Fecha por defecto</span><strong><?= h(fecha($fechaDef)) ?></strong><span class="bcra-tarjeta-sub">para filas sin fecha</span></div>
</div>

<div class="card">
  <?= card_head('Vista previa', 'revisá antes de procesar') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th class="th-right" data-no-sort>Fila</th><th data-no-sort>Préstamo</th><th data-no-sort>Cliente</th><th data-no-sort>Fecha</th>
        <th class="th-right" data-no-sort>Importe</th><th class="th-right" data-no-sort>Deuda a esa fecha</th><th data-no-sort>Referencia</th><th data-no-sort>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($filas as $f): ?>
        <tr<?= $f['ok'] ? '' : ' class="fila-vencida"' ?>>
          <td class="td-right"><?= (int) $f['_fila'] ?></td>
          <td class="mono"><?= h($f['nro'] ?: ($f['prestamo'] ?: $f['cuit'])) ?></td>
          <td><?= h($f['cliente']) ?></td>
          <td><?= h($f['fecha_n'] ? fecha($f['fecha_n']) : $f['fecha']) ?></td>
          <td class="td-right"><?= $f['importe_n'] !== null ? money($f['importe_n']) : h($f['importe']) ?></td>
          <td class="td-right"><?= $f['deuda'] !== null ? money($f['deuda']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= h($f['referencia']) ?></td>
          <td class="td-wrap"><?= $f['ok'] ? estado('Se carga', 'ok') : '<span class="texto-error">' . h($f['error']) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><td colspan="4">SE CARGAN <?= num($nOk) ?> DE <?= count($filas) ?></td><td class="td-right"><?= money($totOk) ?></td><td colspan="3"></td></tr></tfoot>
    </table>
  </div>
  <form method="post" action="lote.php" class="form-acciones"
        data-confirmar="Se registran <?= $nOk ?> pagos por <?= h(money($totOk)) ?> en un lote de <?= h(MEDIOS_PAGO[$medio]) ?>. Cada uno genera su recibo." data-confirmar-titulo="Procesar el lote" data-confirmar-boton="Procesar">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="procesar">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="archivo_nombre" value="<?= h($nombreArchivo) ?>">
    <input type="hidden" name="medio" value="<?= h($medio) ?>">
    <input type="hidden" name="fecha" value="<?= h($fechaDef) ?>">
    <?= boton('Subir otro archivo', 'secondary', 'lote.php', 'volver') ?>
    <?php if ($nOk): ?><?= boton('Procesar ' . $nOk . ' pagos', 'ok', null, 'guardar', true) ?><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
