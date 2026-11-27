<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/extractos.php';

// ---------------------------------------------------------------------
// Extractos bancarios: lo que informa el banco, importado de sus archivos
// (el modelo de gestion_adm; los formatos y los controles, en
// inc/extractos.php). Es el lado banco de la conciliación.
//   ?tab=extracto   los movimientos de una cuenta en un período, con el
//                   saldo que informa el banco y el del libro a la misma
//                   fecha (la diferencia es lo que explica la conciliación)
//   ?tab=importar   subir uno o varios archivos, o una carpeta entera, y
//                   el historial de importaciones (cada una se deshace
//                   entera mientras nada esté conciliado)
// Un movimiento conciliado no se borra.
// ---------------------------------------------------------------------

$errores = [];
$tab = get_str('tab') === 'importar' ? 'importar' : 'extracto';
$maxArchivos = max(1, (int) ini_get('max_file_uploads'));

$bancos = [];
$instalado = false;
try {
    $bancos = array_filter(cuentas_fondos(null, true), function ($c) { return $c['tipo'] === 'B'; });
    q('SELECT 1 FROM extractos_bancarios LIMIT 1');
    $instalado = true;
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? ($bancos ? 'Falta crear las tablas de extractos: corré sql\financiera_extractos.sql.' : 'Falta crear las tablas de Tesorería: corré sql\financiera_fondos.sql y después sql\financiera_extractos.sql.')
        : error_amigable($e);
}
$habilitadas = array_filter($bancos, function ($c) { return (int) $c['habilitada'] === 1; });

/** Las filas de $_FILES['archivos'] (archivos[] de uno o de los dos campos), como lista. */
function archivos_subidos(): array
{
    $f = $_FILES['archivos'] ?? null;
    if (!$f || !is_array($f['name'])) {
        return [];
    }
    $out = [];
    foreach ($f['name'] as $i => $nombre) {
        if ((int) $f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['nombre' => basename(str_replace('\\', '/', (string) $nombre)), 'tmp' => (string) $f['tmp_name'][$i], 'error' => (int) $f['error'][$i]];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post() && $instalado) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'importar') {
        $idCuenta = (int) post_str('id_cuenta');
        $volver = 'extractos_bancarios.php?tab=importar' . ($idCuenta ? '&cuenta=' . $idCuenta : '');
        if (!isset($habilitadas[$idCuenta])) {
            flash('warning', 'Elegí la cuenta bancaria del extracto.');
            redirigir($volver);
        }
        $subidos = archivos_subidos();
        if (!$subidos) {
            flash('warning', 'Elegí uno o varios archivos, o una carpeta.');
            redirigir($volver);
        }
        $ignorados = 0;
        $problemas = [];
        $candidatos = [];
        foreach ($subidos as $s) {
            $ext = strtolower(pathinfo($s['nombre'], PATHINFO_EXTENSION));
            if (!in_array($ext, EXT_EXTENSIONES, true)) {
                $ignorados++;                    // de una carpeta vienen también PDF, Excel…
            } elseif ($s['error'] !== UPLOAD_ERR_OK) {
                $problemas[] = $s['nombre'] . ': ' . (in_array($s['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'es demasiado grande' : 'no llegó completo');
            } else {
                $candidatos[] = $s;
            }
        }
        // En orden cronológico según la fecha del nombre (si la tiene): deja el extracto cargado en orden.
        usort($candidatos, function ($a, $b) {
            return [ext_fecha_del_nombre($a['nombre']) ?? '', $a['nombre']] <=> [ext_fecha_del_nombre($b['nombre']) ?? '', $b['nombre']];
        });
        $tot = ['ok' => 0, 'error' => 0, 'nuevos' => 0, 'repetidos' => 0, 'deb' => 0.0, 'cred' => 0.0];
        $uno = null;
        foreach ($candidatos as $s) {
            $raw = (string) @file_get_contents($s['tmp']);
            $imp = ext_importar($habilitadas[$idCuenta], $s['nombre'], $raw);
            $uno = $imp;
            if ($imp['resultado'] === 'OK') {
                $tot['ok']++;
                $tot['nuevos'] += $imp['insertados'];
                $tot['repetidos'] += $imp['repetidos'];
                $tot['deb'] += $imp['total_debitos'];
                $tot['cred'] += $imp['total_creditos'];
            } else {
                $tot['error']++;
            }
        }
        $mon = $habilitadas[$idCuenta]['moneda'];
        $notas = [];
        if ($ignorados) {
            $notas[] = num($ignorados) . ($ignorados === 1 ? ' archivo ignorado' : ' archivos ignorados') . ' (no son .csv ni .txt)';
        }
        if ($problemas) {
            $notas[] = implode('; ', array_slice($problemas, 0, 3));
        }
        if (count($subidos) >= $maxArchivos) {
            $notas[] = 'se reciben hasta ' . $maxArchivos . ' archivos por vez: si elegiste más, importá el resto en otra tanda';
        }
        $cola = $notas ? ' · ' . implode(' · ', $notas) . '.' : '';
        if (!$candidatos) {
            flash('warning', 'No se importó nada' . $cola);
        } elseif (count($candidatos) === 1 && $uno['resultado'] === 'ERROR') {
            flash('danger', $uno['archivo'] . ': ' . $uno['motivo'] . $cola);
        } elseif (count($candidatos) === 1 && $uno['insertados'] === 0) {
            flash('warning', 'Nada nuevo: ' . ($uno['repetidos'] === 1 ? 'el movimiento' : 'los ' . num($uno['repetidos']) . ' movimientos')
                . ' de ' . $uno['archivo'] . ($uno['repetidos'] === 1 ? ' ya estaba cargado' : ' ya estaban cargados') . ' en la cuenta.'
                . ($uno['motivo'] ? ' ' . $uno['motivo'] : '') . $cola);
        } elseif ($tot['nuevos'] === 0 && !$tot['error']) {
            flash('warning', 'Nada nuevo: los ' . num($tot['repetidos']) . ' movimientos de los ' . num(count($candidatos)) . ' archivos ya estaban cargados en la cuenta.' . $cola);
        } else {
            $txt = (count($candidatos) === 1 ? $uno['archivo'] . ': ' : num(count($candidatos)) . ' archivos: ')
                 . num($tot['nuevos']) . ($tot['nuevos'] === 1 ? ' movimiento nuevo' : ' movimientos nuevos')
                 . ($tot['repetidos'] ? ', ' . num($tot['repetidos']) . ' ya estaban' : '')
                 . ($tot['nuevos'] ? ' · créditos ' . dinero($tot['cred'], $mon) . ' · débitos ' . dinero($tot['deb'], $mon) : '')
                 . ($tot['error'] ? ' · ' . num($tot['error']) . ($tot['error'] === 1 ? ' archivo no se importó' : ' archivos no se importaron') . ': el motivo está en el historial' : '')
                 . (count($candidatos) === 1 && $uno['motivo'] ? '. ' . $uno['motivo'] : '') . $cola;
            flash($tot['error'] || $notas || (count($candidatos) === 1 && $uno['motivo']) ? 'warning' : 'ok', $txt);
        }
        redirigir($volver);
    }

    if ($accion === 'deshacer') {
        $idImp = (int) post_str('id_importacion');
        $imp = q1('SELECT id_cuenta_fondos, archivo FROM extractos_importaciones WHERE id_importacion = ?', [$idImp]);
        try {
            $n = ext_deshacer($idImp);
            flash('ok', 'Importación de ' . $imp['archivo'] . ' deshecha: ' . num($n) . ($n === 1 ? ' movimiento borrado.' : ' movimientos borrados.'));
        } catch (Throwable $e) {
            flash('danger', $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e));
        }
        redirigir('extractos_bancarios.php?tab=importar' . ($imp ? '&cuenta=' . (int) $imp['id_cuenta_fondos'] : ''));
    }

    if ($accion === 'borrar') {
        $volver = (string) ($_POST['volver'] ?? '');
        $volver = preg_match('#^extractos_bancarios\.php(\?[^\s]*)?$#', $volver) ? $volver : 'extractos_bancarios.php';
        $st = db()->prepare('DELETE e FROM extractos_bancarios e WHERE e.id_extracto = ? AND NOT (e.conciliado = 1' . ext_sql_en_conciliacion('e') . ')');
        $st->execute([(int) post_str('id_extracto')]);
        flash($st->rowCount() ? 'ok' : 'warning', $st->rowCount() ? 'Movimiento borrado del extracto.' : 'No se borró: está conciliado o ya no existe.');
        redirigir($volver);
    }
}

// ---------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------
$fCuenta = (int) get_str('cuenta');
if (!isset($bancos[$fCuenta])) {
    $fCuenta = 0;
    if ($instalado && $bancos) {                        // la última cuenta que recibió un extracto
        $u = q1('SELECT id_cuenta_fondos FROM extractos_bancarios ORDER BY importado_el DESC, id_extracto DESC LIMIT 1');
        $fCuenta = $u && isset($bancos[(int) $u['id_cuenta_fondos']]) ? (int) $u['id_cuenta_fondos'] : (int) array_key_first($habilitadas ?: $bancos);
    }
}
$cuenta = $bancos[$fCuenta] ?? null;
$mon = $cuenta['moneda'] ?? 'ARS';

// ---------------------------------------------------------------------
// Solapa Extracto
// ---------------------------------------------------------------------
$filas = [];
$tot = ['n' => 0, 'debitos' => 0, 'creditos' => 0];
$paginas = 1;
$pagina = max(1, (int) get_str('p'));
$porPag = por_pagina(100);
$saldoIni = $saldoFin = $libroFin = null;
$ultimo = null;
$fDesde = $fHasta = '';
$fLado = in_array(get_str('lado'), ['debito', 'credito'], true) ? get_str('lado') : '';
$fQ = trim(get_str('q'));
if ($tab === 'extracto' && $cuenta && $instalado) {
    try {
        $ultimo = q1('SELECT MIN(fecha) AS primera, MAX(fecha) AS ultima, COUNT(*) AS n FROM extractos_bancarios WHERE id_cuenta_fondos = ?', [$fCuenta]);
        // Sin fechas: el mes del último movimiento de la cuenta (si está al día, el mes actual).
        $base = $ultimo['ultima'] ?? date('Y-m-d');
        $fDesde = leer_fecha(get_str('desde')) ?? (get_str('desde') === '' && get_str('hasta') === '' ? substr($base, 0, 8) . '01' : '');
        $fHasta = leer_fecha(get_str('hasta')) ?? (get_str('desde') === '' && get_str('hasta') === '' ? date('Y-m-t', strtotime($base)) : '');

        $where = ['e.id_cuenta_fondos = ?'];
        $params = [$fCuenta];
        if ($fDesde !== '') {
            $where[] = 'e.fecha >= ?';
            $params[] = $fDesde;
        }
        if ($fHasta !== '') {
            $where[] = 'e.fecha <= ?';
            $params[] = $fHasta;
        }
        if ($fLado !== '') {
            $where[] = $fLado === 'debito' ? 'e.debito IS NOT NULL' : 'e.credito IS NOT NULL';
        }
        if ($fQ !== '') {
            $like = '%' . addcslashes($fQ, '%_\\') . '%';
            $where[] = '(e.referencia LIKE ? OR e.concepto LIKE ? OR e.cuit = ? OR cl.denominacion LIKE ? OR iv.denominacion LIKE ?)';
            array_push($params, $like, $like, preg_replace('/\D/', '', $fQ) ?: '-', $like, $like);
        }
        $desdeSql = 'FROM extractos_bancarios e
                     LEFT JOIN clientes cl ON cl.cuit = e.cuit
                     LEFT JOIN inversores iv ON iv.cuit = e.cuit
                    WHERE ' . implode(' AND ', $where);
        $columnas = 'e.*, cl.id_cliente, cl.denominacion AS cliente, iv.id_inversor, iv.denominacion AS inversor';

        $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(e.debito), 0) AS debitos, COALESCE(SUM(e.credito), 0) AS creditos $desdeSql", $params);

        // Saldos que informó el banco (el último renglón con saldo antes del período y dentro de él) y el del libro.
        $saldoIni = $fDesde !== '' ? q1('SELECT fecha, saldo FROM extractos_bancarios WHERE id_cuenta_fondos = ? AND saldo IS NOT NULL AND fecha < ?
                                          ORDER BY fecha DESC, id_extracto DESC LIMIT 1', [$fCuenta, $fDesde]) : null;
        $saldoFin = q1('SELECT fecha, saldo FROM extractos_bancarios WHERE id_cuenta_fondos = ? AND saldo IS NOT NULL'
                       . ($fHasta !== '' ? ' AND fecha <= ?' : '') . ' ORDER BY fecha DESC, id_extracto DESC LIMIT 1',
                       $fHasta !== '' ? [$fCuenta, $fHasta] : [$fCuenta]);
        if ($saldoFin) {
            $libroFin = q1('SELECT COALESCE(SUM(importe_debe - importe_haber), 0) AS saldo FROM movimientos_fondos
                             WHERE id_cuenta_fondos = ? AND anulado_el IS NULL AND fecha <= ?', [$fCuenta, $saldoFin['fecha']])['saldo'];
        }

        if (get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $todas = q("SELECT $columnas $desdeSql ORDER BY e.fecha, e.id_extracto", $params);
            $libro = new XlsxLibro();
            $libro->hoja('Extracto')->titulo('Extracto · ' . $cuenta['nombre'],
                    ($fDesde !== '' ? 'Del ' . fecha($fDesde) : 'Desde el principio') . ($fHasta !== '' ? ' al ' . fecha($fHasta) : '') . ' · '
                    . num($tot['n']) . ' movimientos · generado el ' . date('d/m/Y H:i'))
                ->tabla([['Fecha', 'fecha', 12], ['Fecha valor', 'fecha', 12], ['Referencia', 'texto', 18], ['Concepto', 'texto', 55],
                         ['CUIT', 'texto', 14], ['Contraparte', 'texto', 30], ['Débito', 'moneda', 15], ['Crédito', 'moneda', 15],
                         ['Saldo del banco', 'moneda', 16], ['Conciliado', 'centro', 11]],
                        array_map(function ($e) {
                            return [$e['fecha'], $e['fecha_valor'], (string) $e['referencia'], (string) $e['concepto'], (string) $e['cuit'],
                                    (string) ($e['cliente'] ?? $e['inversor'] ?? ''), $e['debito'] === null ? null : (float) $e['debito'],
                                    $e['credito'] === null ? null : (float) $e['credito'], $e['saldo'] === null ? null : (float) $e['saldo'],
                                    (int) $e['conciliado'] ? 'SI' : 'NO'];
                        }, $todas),
                        [0 => ['texto' => 'TOTAL'], 6 => ['suma' => true], 7 => ['suma' => true]]);
            $libro->enviar('extracto_' . preg_replace('/[^A-Za-z0-9]+/', '_', $cuenta['nombre']) . '_' . str_replace('-', '', $fDesde ?: 'inicio') . '.xlsx');
            exit;
        }

        $paginas = max(1, (int) ceil($tot['n'] / $porPag));
        $pagina = min($pagina, $paginas);
        $filas = q("SELECT $columnas $desdeSql ORDER BY e.fecha, e.id_extracto LIMIT ? OFFSET ?", array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
        // El BNA informa el saldo una vez por día (en el último renglón del día el clásico, en el primero Banca
        // Empresas): es el saldo al cierre del día, así que se muestra en el último movimiento del día. El ICBC
        // lo informa en todos los renglones (el saldo después de cada uno) y queda donde está.
        $porDia = [];
        foreach ($filas as $k => $e) {
            $porDia[$e['fecha']][] = $k;
        }
        foreach ($porDia as $ks) {
            $delDia = null;
            foreach ($ks as $k) {
                if ($filas[$k]['origen'] === 'BNA' && $filas[$k]['saldo'] !== null) {
                    $delDia = $filas[$k]['saldo'];
                    $filas[$k]['saldo'] = null;
                }
            }
            if ($delDia !== null) {
                $filas[end($ks)]['saldo'] = $delDia;
            }
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

// ---------------------------------------------------------------------
// Solapa Importar: el historial
// ---------------------------------------------------------------------
$importaciones = [];
$totImp = 0;
if ($tab === 'importar' && $instalado) {
    try {
        $w = $fCuenta && get_str('cuenta') !== '' ? ' WHERE i.id_cuenta_fondos = ' . $fCuenta : '';
        $totImp = (int) q1("SELECT COUNT(*) AS n FROM extractos_importaciones i$w")['n'];
        $paginas = max(1, (int) ceil($totImp / 25));
        $pagina = min($pagina, $paginas);
        $importaciones = q("SELECT i.*, c.nombre AS cuenta, c.moneda, u.nombre AS usuario,
                                   (SELECT COUNT(*) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion AND e.conciliado = 1) AS conciliados
                              FROM extractos_importaciones i
                              JOIN cuentas_fondos c ON c.id_cuenta_fondos = i.id_cuenta_fondos
                              LEFT JOIN usuarios u ON u.id_usuario = i.importado_por$w
                             ORDER BY i.importado_el DESC, i.id_importacion DESC LIMIT 25 OFFSET " . (($pagina - 1) * 25));
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$volver = 'extractos_bancarios.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

layout_inicio('Extractos bancarios');
titulo('Extractos bancarios', ($cuenta ? $cuenta['nombre'] . ($cuenta['numero_cuenta'] ? ' · cuenta ' . $cuenta['numero_cuenta'] : '') . ' · ' : '')
       . 'Lo que informa el banco, importado de sus archivos',
       '',
       '<a href="movimientos_fondos.php">Tesorería</a> / Extractos bancarios');
mensajes($errores);
if ($instalado && !$bancos) {
    alerta('Todavía no hay cuentas de tipo Banco. ' . (puede('cuentas_fondos.php') ? 'Creá una en Tesorería › Cuentas de fondos, con su número de cuenta.' : ''), 'info');
}
?>

<?php if ($instalado && $bancos): ?>
<div class="abm-tabs">
  <a class="solapa <?= $tab === 'extracto' ? 'activa' : '' ?>" href="extractos_bancarios.php?cuenta=<?= (int) $fCuenta ?>">Extracto</a>
  <a class="solapa <?= $tab === 'importar' ? 'activa' : '' ?>" href="extractos_bancarios.php?tab=importar&amp;cuenta=<?= (int) $fCuenta ?>">Importar</a>
</div>
<?php endif; ?>

<?php if ($instalado && $bancos && $tab === 'extracto'): ?>
<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo del banco al <?= $saldoFin ? h(fecha($saldoFin['fecha'])) : '…' ?></span>
    <strong><?= $saldoFin ? dinero($saldoFin['saldo'], $mon) : '—' ?></strong>
    <span class="bcra-tarjeta-sub"><?= $saldoFin ? 'el último que informó el banco' . ($saldoIni ? '; antes del período, ' . dinero($saldoIni['saldo'], $mon) . ' al ' . h(fecha($saldoIni['fecha'])) : '')
                                               : 'el archivo no informa saldos (recaudaciones)' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Saldo del libro a esa fecha</span>
    <strong><?= $libroFin !== null ? dinero($libroFin, $mon) : '—' ?></strong>
    <span class="bcra-tarjeta-sub">Tesorería › Subdiario de cuentas de fondos, sin anulados</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Diferencia</span>
    <strong<?= $libroFin !== null && abs((float) $saldoFin['saldo'] - (float) $libroFin) >= 0.01 ? ' class="importe-neg"' : '' ?>><?= $libroFin !== null ? dinero((float) $saldoFin['saldo'] - (float) $libroFin, $mon) : '—' ?></strong>
    <span class="bcra-tarjeta-sub">banco menos libro: la explica la conciliación (gastos, lo que el banco todavía no vio)</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">En el período</span><strong><?= num($tot['n']) ?> movimientos</strong>
    <span class="bcra-tarjeta-sub">créditos <?= dinero($tot['creditos'], $mon) ?> · débitos <?= dinero($tot['debitos'], $mon) ?></span></div>
</div>

<div class="card">
  <?= card_head('Movimientos', $ultimo && (int) $ultimo['n'] ? 'la cuenta tiene extracto del ' . fecha($ultimo['primera']) . ' al ' . fecha($ultimo['ultima']) : 'todavía no se importó ningún extracto de esta cuenta',
        boton('Importar', 'ghost', 'extractos_bancarios.php?tab=importar&cuenta=' . (int) $fCuenta, 'subir', false, true)) ?>
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $bancos, $fCuenta, '', ['class' => 'filtro', 'onchange' => 'this.form.desde.value = this.form.hasta.value = ""; this.form.submit()']) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Lado</span>
      <select name="lado" class="filtro <?= $fLado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['credito' => 'Créditos (entra)', 'debito' => 'Débitos (sale)'], $fLado, 'Los dos') ?></select></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Referencia, concepto, CUIT, cliente o inversor…"></label>
    <?= filtro_botones('extractos_bancarios.php?cuenta=' . (int) $fCuenta, $fLado !== '' || $fQ !== '' || get_str('desde') !== '' || get_str('hasta') !== '') ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'movimientos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>

  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Fecha</th><th data-no-sort>Referencia</th><th data-no-sort>Concepto</th>
        <th class="th-right" data-no-sort>Débito</th><th class="th-right" data-no-sort>Crédito</th><th class="th-right" data-no-sort title="El que informa el banco. El BNA lo da una vez por día: va en el último movimiento del día.">Saldo del banco</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="7" class="no-data"><?= $ultimo && (int) $ultimo['n'] ? 'No hay movimientos con este filtro.' : 'Importá un extracto en la solapa Importar.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $e): ?>
        <tr>
          <td><div class="acciones">
            <?php if ((int) $e['conciliado']): ?>
              <?= estado('Conciliado', 'ok') ?>
            <?php else: ?>
              <form method="post" action="extractos_bancarios.php" class="form-en-linea"
                    data-confirmar="Se borra del extracto el movimiento del <?= h(fecha($e['fecha'])) ?> por <?= h(dinero((float) ($e['debito'] ?? $e['credito']), $mon)) ?>. Si era del banco, vuelve a entrar la próxima vez que se importe su archivo."
                    data-confirmar-titulo="Borrar el movimiento" data-confirmar-boton="Borrar" data-peligro>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="borrar">
                <input type="hidden" name="id_extracto" value="<?= (int) $e['id_extracto'] ?>">
                <input type="hidden" name="volver" value="<?= h($volver) ?>">
                <button type="submit" class="btn-accion borrar" title="Borrar del extracto" aria-label="Borrar del extracto"><?= icono('borrar') ?></button>
              </form>
            <?php endif; ?>
          </div></td>
          <td><?= h(fecha($e['fecha'])) ?><?= $e['fecha_valor'] && $e['fecha_valor'] !== $e['fecha'] ? '<span class="segunda-linea">valor ' . h(fecha($e['fecha_valor'])) . '</span>' : '' ?></td>
          <td class="mono td-wrap"><?= $e['referencia'] !== null ? h($e['referencia']) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-wrap"><?= h((string) $e['concepto']) ?>
            <?php if ($e['cuit']): ?><span class="segunda-linea"><?=
                $e['id_cliente'] ? '<a href="cliente.php?id=' . (int) $e['id_cliente'] . '">' . h($e['cliente']) . '</a> (cliente) · '
                : ($e['id_inversor'] ? '<a href="inversor.php?id=' . (int) $e['id_inversor'] . '">' . h($e['inversor']) . '</a> (inversor) · ' : '') ?>CUIT <?= h(cuit_fmt($e['cuit'])) ?></span><?php endif; ?></td>
          <td class="td-right"><?= $e['debito'] !== null ? dinero($e['debito'], $mon) : '' ?></td>
          <td class="td-right"><?= $e['credito'] !== null ? dinero($e['credito'], $mon) : '' ?></td>
          <td class="td-right"><?= $e['saldo'] !== null ? dinero($e['saldo'], $mon) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($filas): ?>
      <tfoot><tr class="total-row"><td colspan="4">TOTAL DEL FILTRO · <?= num($tot['n']) ?> movimientos</td>
        <td class="td-right"><?= dinero($tot['debitos'], $mon) ?></td><td class="td-right"><?= dinero($tot['creditos'], $mon) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'movimientos') ?>
</div>
<?php endif; ?>

<?php if ($instalado && $bancos && $tab === 'importar'): ?>
<form method="post" action="extractos_bancarios.php" enctype="multipart/form-data" id="frm-importar">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="importar">
  <div class="card">
    <?= card_head('Importar extractos', 'el archivo tal como lo baja el banco') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="id_cuenta">Cuenta <span class="req">*</span></label>
          <?= cuenta_select('id_cuenta', $habilitadas, isset($habilitadas[$fCuenta]) ? $fCuenta : '', 'Elegí la cuenta…', ['id' => 'id_cuenta', 'required' => true]) ?>
          <span class="campo-ayuda" id="cuenta-ayuda"></span></div>
        <div class="campo" style="--span:5"><label for="archivos">Archivos <span class="req">*</span></label>
          <input type="file" id="archivos" name="archivos[]" multiple accept=".csv,.txt,text/csv,text/plain">
          <span class="campo-ayuda" id="archivos-ayuda">uno o varios .csv / .txt · hasta <?= (int) $maxArchivos ?> por vez</span></div>
        <div class="campo campo-boton" style="--span:3"><span class="campo-label">o una carpeta</span>
          <input type="file" id="carpeta" name="archivos[]" class="oculto" webkitdirectory directory multiple>
          <?= boton('Elegir carpeta', 'secondary', null, 'documento', false, true, ['id' => 'btn-carpeta']) ?></div>
      </div>
      <p class="nota-arbol">Lee los extractos de <strong>Banco Nación</strong> (el clásico, <span class="mono">Fecha Mvto;Fecha Valor;Monto;…</span>, y el de Banca Empresas, <span class="mono">BEE_Reporte_*.csv</span>) y del <strong>ICBC</strong> (cuenta corriente y reporte de recaudaciones; a este último ponele la fecha al principio del nombre, como <span class="mono">19.11.2026 …</span>). El formato se reconoce solo. Si el archivo es de otra cuenta, no se importa nada. Se puede reimportar cualquier período: lo que ya estaba cargado no se repite.</p>
    </div>
    <div class="form-acciones">
      <?= boton('Importar', 'primary', null, 'subir', true, false, ['id' => 'btn-importar']) ?>
    </div>
  </div>
</form>

<div class="card">
  <?= card_head('Importaciones', num($totImp) . ($totImp === 1 ? ' archivo' : ' archivos') . ($fCuenta && get_str('cuenta') !== '' && isset($bancos[$fCuenta]) ? ' de ' . $bancos[$fCuenta]['nombre'] : ''),
        $fCuenta && get_str('cuenta') !== '' ? boton('Ver todas las cuentas', 'ghost', 'extractos_bancarios.php?tab=importar', null, false, true) : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Importado</th><th data-no-sort>Archivo</th><th data-no-sort>Período</th>
        <th data-no-sort>Resultado</th><th class="th-right" data-no-sort>Movimientos</th><th class="th-right" data-no-sort>Importes nuevos</th></tr></thead>
      <tbody>
      <?php if (!$importaciones): ?><tr><td colspan="7" class="no-data">Todavía no se importó ningún extracto.</td></tr><?php endif; ?>
      <?php foreach ($importaciones as $i):
          $ok = $i['resultado'] === 'OK';
          $deshecha = $i['deshecha_el'] !== null;
          $sePuede = $ok && !$deshecha && (int) $i['insertados'] > 0 && !(int) $i['conciliados']; ?>
        <tr<?= $deshecha ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones">
            <?php if ($ok && !$deshecha && (int) $i['insertados'] > 0): ?>
              <?= btn_accion('ver', 'extractos_bancarios.php?cuenta=' . (int) $i['id_cuenta_fondos'] . '&desde=' . $i['fecha_desde'] . '&hasta=' . $i['fecha_hasta'], 'Ver el extracto de esas fechas') ?>
            <?php endif; ?>
            <?php if ($sePuede): ?>
              <form method="post" action="extractos_bancarios.php" class="form-en-linea"
                    data-confirmar="Se borran los <?= num($i['insertados']) ?> movimientos que entraron con «<?= h($i['archivo']) ?>». Si otro archivo traía los mismos, volvé a importarlo: entran los que falten."
                    data-confirmar-titulo="Deshacer la importación" data-confirmar-boton="Deshacer" data-peligro>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="deshacer">
                <input type="hidden" name="id_importacion" value="<?= (int) $i['id_importacion'] ?>">
                <button type="submit" class="btn-accion borrar" title="Deshacer la importación" aria-label="Deshacer la importación"><?= icono('volver') ?></button>
              </form>
            <?php endif; ?>
          </div></td>
          <td><?= h(date('d/m/Y H:i', strtotime($i['importado_el']))) ?><span class="segunda-linea"><?= h((string) $i['usuario']) ?></span></td>
          <td class="col-archivo"><span class="archivo-nombre"><?= h($i['archivo']) ?></span>
            <span class="segunda-linea"><?= h($i['cuenta']) ?><?= $i['formato'] ? ' · ' . h(EXT_FORMATOS[$i['formato']] ?? $i['formato']) : '' ?></span></td>
          <td><?= $i['fecha_desde'] ? h(fecha($i['fecha_desde'])) . ($i['fecha_hasta'] !== $i['fecha_desde'] ? '<span class="segunda-linea">al ' . h(fecha($i['fecha_hasta'])) . '</span>' : '') : '<span class="td-muted">—</span>' ?></td>
          <td class="col-motivo"><?php
              if ($deshecha) {
                  echo estado('Deshecha', 'neutro') . '<span class="segunda-linea">el ' . h(date('d/m/Y H:i', strtotime($i['deshecha_el']))) . '</span>';
              } elseif (!$ok) {
                  echo estado('No se importó', 'danger');
              } elseif ((int) $i['insertados'] === 0) {
                  echo estado('Nada nuevo', 'neutro');
              } else {
                  echo estado('Importado', 'ok') . ((int) $i['conciliados'] ? '<span class="segunda-linea">' . num($i['conciliados']) . ' conciliados</span>' : '');
              }
              if ($i['motivo']) {
                  echo '<span class="segunda-linea' . ($ok ? '' : ' texto-error') . '">' . h($i['motivo']) . '</span>';
              } ?></td>
          <td class="td-right"><?php if ($ok): ?><?= num($i['insertados']) ?> <?= (int) $i['insertados'] === 1 ? 'nuevo' : 'nuevos' ?>
            <?= (int) $i['repetidos'] ? '<span class="segunda-linea">' . num($i['repetidos']) . ' ya estaban</span>' : '' ?><?php endif; ?></td>
          <td class="td-right"><?php if ($ok && (int) $i['insertados']): ?>
            <?= (float) $i['total_creditos'] ? '+ ' . dinero($i['total_creditos'], $i['moneda']) : '' ?>
            <?= (float) $i['total_debitos'] ? '<span class="segunda-linea">- ' . dinero($i['total_debitos'], $i['moneda']) . '</span>' : '' ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $totImp, 25, 'archivos') ?>
</div>

<script>
(function () {
  var form = document.getElementById('frm-importar'), arch = document.getElementById('archivos'), carp = document.getElementById('carpeta'),
      ayuda = document.getElementById('archivos-ayuda'), boton = document.getElementById('btn-importar'),
      cuenta = document.getElementById('id_cuenta'), ayudaCta = document.getElementById('cuenta-ayuda'),
      max = <?= (int) $maxArchivos ?>, base = ayuda.textContent;
  var numeros = <?= json_encode(array_map(function ($c) { return (string) $c['numero_cuenta']; }, $habilitadas), JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('btn-carpeta').addEventListener('click', function () { carp.click(); });
  function sirve(f) { return /\.(csv|txt)$/i.test(f.name); }
  function contar() {
    var todos = Array.prototype.slice.call(arch.files).concat(Array.prototype.slice.call(carp.files));
    var csv = todos.filter(sirve).length, otros = todos.length - csv;
    ayuda.className = 'campo-ayuda';
    boton.disabled = false;
    if (!todos.length) { ayuda.textContent = base; return; }
    if (todos.length > max) {
      ayuda.textContent = '✗ Son ' + todos.length + ' archivos y se reciben hasta ' + max + ' por vez: elegí menos.';
      ayuda.className = 'campo-ayuda error';
      boton.disabled = true;
      return;
    }
    ayuda.textContent = csv + (csv === 1 ? ' archivo para importar' : ' archivos para importar') + (otros ? ' · ' + otros + ' que no son .csv ni .txt se ignoran' : '');
    if (!csv) { ayuda.className = 'campo-ayuda error'; boton.disabled = true; }
  }
  arch.addEventListener('change', function () { if (arch.files.length) carp.value = ''; contar(); });
  carp.addEventListener('change', function () { if (carp.files.length) arch.value = ''; contar(); });
  function cuentaAyuda() {
    var n = numeros[cuenta.value];
    ayudaCta.textContent = cuenta.value ? (n ? 'cuenta N° ' + n + ': el archivo tiene que ser de esta cuenta' : 'sin número de cuenta: cargalo en Cuentas de fondos (solo entra el BNA clásico, que no lo trae)') : '';
    ayudaCta.className = 'campo-ayuda' + (cuenta.value && !n ? ' error' : '');
  }
  cuenta.addEventListener('change', cuentaAyuda);
  cuentaAyuda();
  form.addEventListener('submit', function (e) {
    if (!arch.files.length && !carp.files.length) { e.preventDefault(); ayuda.textContent = '✗ Elegí uno o varios archivos, o una carpeta.'; ayuda.className = 'campo-ayuda error'; }
  });
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
