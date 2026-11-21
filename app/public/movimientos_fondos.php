<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Subdiario de cuentas de fondos (el de gestion_adm): el libro único de
// Tesorería. Un renglón por comprobante y cuenta: cobranzas, desembolsos,
// aportes y pagos a inversores, órdenes de pago y movimientos entre cuentas.
//   - Por defecto, el mes en curso. Con una cuenta elegida, el saldo
//     anterior y el saldo renglón por renglón (en orden de fecha; el
//     acumulado se calcula en la base antes de paginar, así la página 3
//     arrastra las anteriores).
//   - Los anulados se ven, tachados, y no suman.
//   - Excel y PDF con todo el filtro.
// ---------------------------------------------------------------------

$errores = [];
$cuentas = [];
try {
    $cuentas = cuentas_fondos(null, true);
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de Tesorería: corré sql\financiera_fondos.sql.' : error_amigable($e);
}
$fCuenta = (int) get_str('cuenta');
if (!isset($cuentas[$fCuenta])) {
    $fCuenta = 0;
}
$cta     = $fCuenta ? $cuentas[$fCuenta] : null;
$fOrigen = isset(ORIGENES_FONDOS[get_str('origen')]) && get_str('origen') !== 'RECIBO' ? get_str('origen') : '';
// El parámetro que VIENE vacío es alguien que borró la fecha a propósito: se respeta.
$fDesde  = array_key_exists('desde', $_GET) ? (leer_fecha(get_str('desde')) ?? '') : date('Y-m-01');
$fHasta  = array_key_exists('hasta', $_GET) ? (leer_fecha(get_str('hasta')) ?? '') : date('Y-m-d');
$fQ      = trim(get_str('q'));
$fAnul   = get_str('anulados') === 'no' ? 'no' : '';
// El importe se compara contra el lado que tenga valor: cada renglón es debe
// o haber, nunca los dos. Va con «Filtrar», como las fechas (es un rango).
$fImpMin = importe_filtro(get_str('imp_desde'));
$fImpMax = importe_filtro(get_str('imp_hasta'));
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));
// El saldo renglón por renglón solo tiene sentido para una cuenta y sin
// filtros que salteen renglones: con «solo cobranzas» no es el saldo de nada.
$conSaldo = $fCuenta > 0 && $fOrigen === '' && $fQ === '' && $fImpMin === null && $fImpMax === null;

$where  = ['1=1'];
$params = [];
if ($fCuenta) {
    $where[] = 'm.id_cuenta_fondos = ?';
    $params[] = $fCuenta;
}
if ($fOrigen === 'COBRANZA') {
    // Las cobranzas de antes (por pago) y las de ahora (por recibo).
    $where[] = "m.tipo_origen IN ('COBRANZA', 'RECIBO')";
} elseif ($fOrigen !== '') {
    $where[] = 'm.tipo_origen = ?';
    $params[] = $fOrigen;
}
if ($fDesde !== '') {
    $where[] = 'm.fecha >= ?';
    $params[] = $fDesde;
}
if ($fHasta !== '') {
    $where[] = 'm.fecha <= ?';
    $params[] = $fHasta;
}
if ($fQ !== '') {
    $where[] = 'm.concepto LIKE ?';
    $params[] = '%' . addcslashes($fQ, '%_\\') . '%';
}
if ($fAnul === 'no') {
    $where[] = 'm.anulado_el IS NULL';
}
if ($fImpMin !== null) {
    $where[] = 'GREATEST(m.importe_debe, m.importe_haber) >= ?';
    $params[] = $fImpMin;
}
if ($fImpMax !== null) {
    $where[] = 'GREATEST(m.importe_debe, m.importe_haber) <= ?';
    $params[] = $fImpMax;
}
$w = implode(' AND ', $where);
$desdeSql = "FROM movimientos_fondos m
             JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos
             LEFT JOIN inversiones_liquidaciones l ON l.id_liquidacion = m.id_liquidacion_inv
            WHERE $w";
$hayFiltros = $fOrigen !== '' || $fQ !== '' || $fAnul !== '' || $fImpMin !== null || $fImpMax !== null;

$filas = $porMoneda = $saldos = [];
$total = 0;
$saldoAnt = 0.0;
$paginas = 1;
$periodo = ($fDesde !== '' ? 'del ' . fecha($fDesde) : 'desde el principio') . ($fHasta !== '' ? ' al ' . fecha($fHasta) : ' a hoy');

/** Los renglones con el acumulado, entre dos números de orden (o todos). */
function renglones_libro(string $desdeSql, array $params, ?int $desde = null, ?int $hasta = null): array
{
    $sql = "SELECT * FROM (
              SELECT m.id_mov_fondos, m.id_cuenta_fondos, m.fecha, m.tipo_origen, m.id_origen, m.concepto, m.importe_debe, m.importe_haber,
                     m.anulado_el, m.motivo_anulacion, c.nombre AS cuenta, c.moneda, c.tipo AS tipo_cuenta,
                     l.id_inversion AS id_inversion_liq,
                     SUM(IF(m.anulado_el IS NULL, m.importe_debe - m.importe_haber, 0))
                         OVER (ORDER BY m.fecha, m.id_mov_fondos ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS acumulado,
                     ROW_NUMBER() OVER (ORDER BY m.fecha, m.id_mov_fondos) AS nro
              $desdeSql) t"
         . ($desde !== null ? ' WHERE t.nro > ? AND t.nro <= ?' : '') . ' ORDER BY t.nro';
    return q($sql, $desde !== null ? array_merge($params, [$desde, $hasta]) : $params);
}

try {
    if ($cuentas) {
        // Totales del filtro ENTERO, por moneda (no se suman pesos con dólares). Los anulados no suman.
        $porMoneda = q("SELECT c.moneda, COUNT(*) AS n,
                               COALESCE(SUM(IF(m.anulado_el IS NULL, m.importe_debe, 0)), 0) AS entradas,
                               COALESCE(SUM(IF(m.anulado_el IS NULL, m.importe_haber, 0)), 0) AS salidas,
                               SUM(m.anulado_el IS NOT NULL) AS anulados
                          $desdeSql GROUP BY c.moneda ORDER BY c.moneda = 'ARS' DESC", $params);
        $porMoneda = array_column($porMoneda, null, 'moneda');
        $total = (int) array_sum(array_column($porMoneda, 'n'));

        // Saldo anterior: lo que la cuenta traía antes del período. Es el de la
        // cuenta, no el del filtro.
        if ($fCuenta && $fDesde !== '') {
            $saldoAnt = (float) q1('SELECT COALESCE(SUM(importe_debe - importe_haber), 0) AS s FROM movimientos_fondos
                                     WHERE id_cuenta_fondos = ? AND fecha < ? AND anulado_el IS NULL', [$fCuenta, $fDesde])['s'];
        }

        // Saldo de cada cuenta al cierre del período (la tarjeta de la derecha)
        $saldos = q("SELECT c.id_cuenta_fondos, c.nombre, c.tipo, c.moneda, c.habilitada,
                            COALESCE(SUM(m.importe_debe - m.importe_haber), 0) AS saldo, COUNT(m.id_mov_fondos) AS n
                       FROM cuentas_fondos c
                       LEFT JOIN movimientos_fondos m ON m.id_cuenta_fondos = c.id_cuenta_fondos AND m.anulado_el IS NULL"
                  . ($fHasta !== '' ? ' AND m.fecha <= ?' : '') . "
                      GROUP BY c.id_cuenta_fondos
                     HAVING c.habilitada = 1 OR n > 0
                      ORDER BY FIELD(c.tipo, 'C', 'B', 'R', 'V', 'I', 'O'), c.moneda, c.nombre", $fHasta !== '' ? [$fHasta] : []);

        $export = get_str('export');
        if ($export === 'xlsx' || $export === 'pdf') {
            $todas = renglones_libro($desdeSql, $params);
            $titulo = 'Subdiario de cuentas de fondos · ' . ($cta ? $cta['nombre'] : 'todas las cuentas');
            $bajada = ucfirst($periodo) . ($fOrigen !== '' ? ' · ' . ORIGENES_FONDOS[$fOrigen] : '') . ($fQ !== '' ? ' · «' . $fQ . '»' : '')
                    . ' · ' . num($total) . ' renglones';
            $nombre = 'subdiario' . ($cta ? '_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(strtr($cta['nombre'],
                          ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']))), '_') : '')
                    . ($fDesde !== '' ? '_' . str_replace('-', '', $fDesde) : '') . ($fHasta !== '' ? '_' . str_replace('-', '', $fHasta) : '');
            if ($export === 'xlsx') {
                require_once __DIR__ . '/../inc/xlsx.php';
                $cols = [['Fecha', 'fecha', 12], ['Cuenta', 'texto', 26], ['Moneda', 'centro', 8], ['Origen', 'texto', 16], ['Concepto', 'texto', 60],
                         ['Debe', 'moneda', 15], ['Haber', 'moneda', 15]];
                if ($conSaldo) {
                    $cols[] = ['Saldo', 'moneda', 16];
                }
                $cols[] = ['Estado', 'texto', 12];
                $libro = new XlsxLibro();
                $hoja = $libro->hoja('Subdiario')->titulo($titulo, $bajada . ($conSaldo ? ' · saldo anterior ' . dinero($saldoAnt, $cta['moneda']) : '')
                                                       . ' · generado el ' . date('d/m/Y H:i'));
                $hoja->tabla($cols, array_map(function ($m) use ($conSaldo, $saldoAnt) {
                    $anul = $m['anulado_el'] !== null;
                    $f = [$m['fecha'], $m['cuenta'], $m['moneda'], ORIGENES_FONDOS[$m['tipo_origen']] ?? $m['tipo_origen'], $m['concepto'],
                          $anul ? 0 : (float) $m['importe_debe'], $anul ? 0 : (float) $m['importe_haber']];
                    if ($conSaldo) {
                        $f[] = $saldoAnt + (float) $m['acumulado'];
                    }
                    $f[] = $anul ? 'Anulado' : '';
                    return $f;
                }, $todas), count($porMoneda) <= 1 ? [0 => ['texto' => 'TOTAL'], 5 => ['suma' => true], 6 => ['suma' => true]] : []);
                $libro->enviar($nombre . '.xlsx');
                exit;
            }
            require_once __DIR__ . '/../inc/pdf.php';
            $rep = new PdfReporte($titulo, $bajada);
            $ind = [];
            if ($conSaldo) {
                $ind[] = ['Saldo anterior', dinero($saldoAnt, $cta['moneda'])];
            }
            foreach ($porMoneda as $mon => $t) {
                $ind[] = ['Debe' . (count($porMoneda) > 1 ? ' ' . $mon : ''), dinero($t['entradas'], $mon)];
                $ind[] = ['Haber' . (count($porMoneda) > 1 ? ' ' . $mon : ''), dinero($t['salidas'], $mon)];
            }
            if ($conSaldo) {
                $t = $porMoneda[$cta['moneda']] ?? ['entradas' => 0, 'salidas' => 0];
                $ind[] = ['Saldo' . ($fHasta !== '' ? ' al ' . fecha($fHasta) : ''), dinero($saldoAnt + $t['entradas'] - $t['salidas'], $cta['moneda'])];
            }
            $rep->indicadores(array_slice($ind, 0, 5));
            $cols = [['Fecha', 1.1, 'L']];
            if (!$cta) {
                $cols[] = ['Cuenta', 2.2, 'L'];
            }
            array_push($cols, ['Origen', 1.5, 'L'], ['Concepto', 6, 'L'], ['Debe', 1.6, 'R'], ['Haber', 1.6, 'R']);
            if ($conSaldo) {
                $cols[] = ['Saldo', 1.7, 'R'];
            }
            $pdfFilas = [];
            if ($conSaldo) {
                $pdfFilas[] = array_merge([$fDesde !== '' ? fecha($fDesde) : ''], [['t' => 'Saldo anterior', 'negrita' => true], '', '', ''],
                                          [['t' => dinero($saldoAnt, $cta['moneda']), 'negrita' => true]]);
            }
            foreach ($todas as $m) {
                $anul = $m['anulado_el'] !== null;
                $tenue = $anul ? ['color' => PdfReporte::TENUE] : [];
                $f = [fecha($m['fecha'])];
                if (!$cta) {
                    $f[] = $m['cuenta'];
                }
                $f[] = ORIGENES_FONDOS[$m['tipo_origen']] ?? $m['tipo_origen'];
                $f[] = ['t' => ($anul ? 'ANULADO · ' : '') . $m['concepto']] + $tenue;
                $f[] = ['t' => (float) $m['importe_debe'] ? dinero($m['importe_debe'], $m['moneda']) : ''] + $tenue;
                $f[] = ['t' => (float) $m['importe_haber'] ? dinero($m['importe_haber'], $m['moneda']) : ''] + $tenue;
                if ($conSaldo) {
                    $f[] = dinero($saldoAnt + (float) $m['acumulado'], $m['moneda']);
                }
                $pdfFilas[] = $f;
            }
            $tot = null;
            if (count($porMoneda) === 1) {
                $mon = array_key_first($porMoneda);
                $tot = ['TOTAL · ' . num($total) . ' renglones'];
                if (!$cta) {
                    $tot[] = '';
                }
                array_push($tot, '', '', dinero($porMoneda[$mon]['entradas'], $mon), dinero($porMoneda[$mon]['salidas'], $mon));
                if ($conSaldo) {
                    $tot[] = dinero($saldoAnt + $porMoneda[$mon]['entradas'] - $porMoneda[$mon]['salidas'], $mon);
                }
            }
            $rep->tabla($cols, $pdfFilas, $tot);
            $rep->enviar($nombre . '.pdf');
            exit;
        }

        $paginas = max(1, (int) ceil($total / $porPag));
        $pagina  = min($pagina, $paginas);
        $filas = renglones_libro($desdeSql, $params, ($pagina - 1) * $porPag, $pagina * $porPag);
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

$monCta = $cta ? $cta['moneda'] : 'ARS';
$tCta = $porMoneda[$monCta] ?? ['entradas' => 0, 'salidas' => 0, 'n' => 0, 'anulados' => 0];
$otras = array_diff_key($porMoneda, [$monCta => 1]);

layout_inicio('Subdiario de cuentas de fondos');
titulo('Subdiario de cuentas de fondos', 'Todo lo que entra y sale de cada cuenta: cobranzas, desembolsos, aportes y pagos a inversores, órdenes de pago y movimientos entre cuentas.'
       . ($cta ? ' ' . $cta['nombre'] . ' · ' . $periodo . '.' : ''),
       boton('PDF', 'pdf', url_con(['export' => 'pdf', 'p' => null]), 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener'])
     . (puede('movimientos_cuentas.php') ? boton('Movimiento entre cuentas', 'primary', 'movimientos_cuentas.php?nuevo=1' . ($fCuenta ? '&origen=' . $fCuenta : ''), 'agregar') : ''),
       '<a href="movimientos_fondos.php">Tesorería</a> / Subdiario de cuentas de fondos');
mensajes($errores);
$saldoIni = $fDesde !== '' ? $saldoAnt : 0.0;
$saldoFin = $saldoIni + $tCta['entradas'] - $tCta['salidas'];
// Las chapas del origen, con el color de gestion_adm: lo que entra en verde, lo que sale en ámbar.
$claseOrigen = ['COBRANZA' => 'ok', 'RECIBO' => 'ok', 'APORTE' => 'ok', 'DESEMBOLSO' => 'warn', 'PAGO_INVERSOR' => 'warn',
                'PAGO_OP' => 'warn', 'MOVIMIENTO' => 'info'];
$otrasTxt = function (string $campo) use ($otras): string {
    return implode(' · ', array_map(function ($m, $t) use ($campo) { return dinero($t[$campo], $m); }, array_keys($otras), $otras));
};
?>

<div class="kpi-row">
  <div class="kpi-card"><div class="kpi-ico"><?= icono('documento', 20) ?></div>
    <div><div class="kpi-lbl">Movimientos</div><div class="kpi-val"><?= num($total) ?></div>
      <div class="kpi-sub"><?= $paginas > 1 ? 'mostrando ' . num(($pagina - 1) * $porPag + 1) . '–' . num(min($pagina * $porPag, $total)) . ' · ' : '' ?><?=
          array_sum(array_column($porMoneda, 'anulados')) ? num(array_sum(array_column($porMoneda, 'anulados'))) . ' anulados (no suman)' : 'ninguno anulado' ?></div></div></div>
  <?php if ($cta): ?>
  <div class="kpi-card"><div class="kpi-ico"><?= icono('calendario', 20) ?></div>
    <div><div class="kpi-lbl">Saldo anterior</div><div class="kpi-val monto"><?= importe_saldo($saldoIni, $monCta) ?></div>
      <div class="kpi-sub"><?= $fDesde !== '' ? 'al ' . h(fecha(date('Y-m-d', strtotime($fDesde . ' -1 day')))) : 'sin fecha de corte' ?></div></div></div>
  <?php endif; ?>
  <div class="kpi-card"><div class="kpi-ico ok"><?= icono('descargar', 20) ?></div>
    <div><div class="kpi-lbl">Debe</div><div class="kpi-val monto importe-ok"><?= dinero($tCta['entradas'], $monCta) ?></div>
      <div class="kpi-sub"><?= h($otrasTxt('entradas') ?: 'lo que entró a ' . ($cta ? 'la cuenta' : 'las cuentas')) ?></div></div></div>
  <div class="kpi-card"><div class="kpi-ico danger"><?= icono('subir', 20) ?></div>
    <div><div class="kpi-lbl">Haber</div><div class="kpi-val monto importe-danger"><?= dinero($tCta['salidas'], $monCta) ?></div>
      <div class="kpi-sub"><?= h($otrasTxt('salidas') ?: 'lo que salió') ?></div></div></div>
  <?php if ($cta): ?>
  <div class="kpi-card"><div class="kpi-ico <?= $saldoFin < 0 ? 'danger' : 'ok' ?>"><?= icono('dinero', 20) ?></div>
    <div><div class="kpi-lbl">Saldo final</div><div class="kpi-val monto"><?= importe_saldo($saldoFin, $monCta) ?></div>
      <div class="kpi-sub"><?= $hayFiltros ? 'anterior + debe − haber del filtro' : 'anterior + debe − haber' ?></div></div></div>
  <?php else: ?>
  <div class="kpi-card"><div class="kpi-ico"><?= icono('dinero', 20) ?></div>
    <div><div class="kpi-lbl">Saldo</div><div class="kpi-sub">Elegí una cuenta para ver el saldo anterior y el acumulado.</div></div></div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $cuentas, $fCuenta ?: '', 'Todas', ['class' => 'filtro' . ($fCuenta ? ' activo' : ''), 'onchange' => 'this.form.submit()']) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Origen</span>
      <select name="origen" class="filtro <?= $fOrigen !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_diff_key(ORIGENES_FONDOS, ['RECIBO' => 1]), $fOrigen, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Importe desde</span>
      <input type="text" name="imp_desde" value="<?= h(get_str('imp_desde')) ?>" size="10" class="filtro" inputmode="decimal" placeholder="0,00"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Importe hasta</span>
      <input type="text" name="imp_hasta" value="<?= h(get_str('imp_hasta')) ?>" size="10" class="filtro" inputmode="decimal" placeholder="0,00"></label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Recibo, préstamo, cliente, inversor…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Anulados</span>
      <select name="anulados" class="filtro <?= $fAnul !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['' => 'Se ven', 'no' => 'Ocultos'], $fAnul) ?></select></label>
    <?= filtro_botones('movimientos_fondos.php', $hayFiltros || $fCuenta || $fDesde !== date('Y-m-01') || $fHasta !== date('Y-m-d')) ?>
    <?= filtro_fin(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'movimientos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <?php if ($fCuenta && !$conSaldo): ?>
    <div class="bloque"><p class="nota-arbol">Con un origen, un importe o una búsqueda no se muestra el saldo renglón por renglón: faltarían los demás movimientos de la cuenta.</p></div>
  <?php endif; ?>
  <?php $nCols = 6 + ($cta ? 0 : 1) + ($conSaldo ? 1 : 0); ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Fecha</th><?= $cta ? '' : '<th data-no-sort>Cuenta</th>' ?>
        <th data-no-sort>Origen</th><th data-no-sort>Concepto</th><th class="th-right" data-no-sort>Debe</th><th class="th-right" data-no-sort>Haber</th>
        <?= $conSaldo ? '<th class="th-right" data-no-sort>Saldo</th>' : '' ?><th data-no-sort>Estado</th></tr></thead>
      <tbody>
      <?php if ($conSaldo && $pagina === 1): ?>
        <tr class="fila-saldo pg-fija"><td></td><td colspan="5" class="td-fuerte">Saldo anterior<?= $fDesde !== '' ? ' al ' . h(fecha(date('Y-m-d', strtotime($fDesde . ' -1 day')))) : '' ?></td>
          <td class="td-right td-fuerte"><?= importe_saldo($saldoIni, $monCta) ?></td><td></td></tr>
      <?php endif; ?>
      <?php if (!$filas): ?><tr><td colspan="<?= $nCols ?>" class="no-data">No hay movimientos <?= $hayFiltros ? 'con este filtro' : 'en el período' ?>.</td></tr><?php endif; ?>
      <?php foreach ($filas as $m): $anul = $m['anulado_el'] !== null; $url = origen_fondos_url($m); ?>
        <tr<?= $anul ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= $url ? btn_accion('ver', $url, 'Ver el comprobante') : '' ?></div></td>
          <td><?= h(fecha($m['fecha'])) ?></td>
          <?= $cta ? '' : '<td><a href="' . h(url_con(['cuenta' => (int) $m['id_cuenta_fondos'], 'p' => null])) . '">' . h($m['cuenta']) . '</a></td>' ?>
          <td><?= estado(ORIGENES_FONDOS[$m['tipo_origen']] ?? $m['tipo_origen'], $claseOrigen[$m['tipo_origen']] ?? 'neutro') ?></td>
          <td class="td-wrap"><?= $url ? '<a href="' . h($url) . '">' . h($m['concepto']) . '</a>' : h($m['concepto']) ?>
            <?= $anul && $m['motivo_anulacion'] ? '<span class="segunda-linea texto-error">Anulado: ' . h($m['motivo_anulacion']) . '</span>' : '' ?></td>
          <td class="td-right importe-ok"><?= (float) $m['importe_debe'] ? dinero($m['importe_debe'], $m['moneda']) : '' ?></td>
          <td class="td-right importe-danger"><?= (float) $m['importe_haber'] ? dinero($m['importe_haber'], $m['moneda']) : '' ?></td>
          <?= $conSaldo ? '<td class="td-right' . ($anul ? '' : ' td-fuerte') . '">' . importe_saldo($saldoIni + (float) $m['acumulado'], $m['moneda']) . '</td>' : '' ?>
          <td><?= $anul ? estado('Anulado', 'danger') . '<span class="segunda-linea">' . h(fecha($m['anulado_el'])) . '</span>' : '<span class="chico importe-ok">Vigente</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($filas && count(array_unique(array_column($filas, 'moneda'))) === 1):
          $vig = array_filter($filas, function ($m) { return $m['anulado_el'] === null; });
          $monPag = $filas[0]['moneda']; ?>
      <tfoot><tr class="total-row"><td colspan="<?= $cta ? 4 : 5 ?>" class="td-right">Total de esta página<?= count($vig) !== count($filas) ? ' <span class="chico">(sin los anulados)</span>' : '' ?></td>
        <td class="td-right"><?= dinero(array_sum(array_column($vig, 'importe_debe')), $monPag) ?></td>
        <td class="td-right"><?= dinero(array_sum(array_column($vig, 'importe_haber')), $monPag) ?></td>
        <?= $conSaldo ? '<td class="td-right">' . importe_saldo($saldoIni + (float) end($filas)['acumulado'], $monPag) . '</td>' : '' ?><td></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $total, $porPag, 'movimientos') ?>
</div>

    <div class="card">
      <?= card_head('Saldos', $fHasta !== '' ? 'al ' . fecha($fHasta) : 'a hoy') ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th>Cuenta</th><th class="th-right">Saldo</th></tr></thead>
          <tbody>
          <?php if (!$saldos): ?><tr><td colspan="2" class="no-data">Todavía no hay cuentas<?= puede('cuentas_fondos.php') ? ': <a href="cuentas_fondos.php?nuevo=1">crear una</a>' : '' ?>.</td></tr><?php endif; ?>
          <?php foreach ($saldos as $s): ?>
            <tr<?= (int) $s['id_cuenta_fondos'] === $fCuenta ? ' class="fila-elegida"' : '' ?>>
              <td><a href="<?= h(url_con(['cuenta' => (int) $s['id_cuenta_fondos'], 'p' => null])) ?>"><?= h($s['nombre']) ?></a>
                <span class="segunda-linea"><?= h(TIPOS_CUENTA[$s['tipo']] ?? $s['tipo']) ?><?= (int) $s['habilitada'] ? '' : ' · deshabilitada' ?></span></td>
              <td class="td-right"><?= importe_saldo($s['saldo'], $s['moneda']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <?php $totMon = [];
                foreach ($saldos as $s) { $totMon[$s['moneda']] = ($totMon[$s['moneda']] ?? 0) + (float) $s['saldo']; } ?>
          <?php if ($totMon): ?>
          <tfoot><?php foreach ($totMon as $mon => $v): ?><tr class="total-row"><td>TOTAL <?= h($mon === 'USD' ? 'DÓLARES' : 'PESOS') ?></td><td class="td-right"><?= importe_saldo($v, $mon) ?></td></tr><?php endforeach; ?></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

<?php layout_fin(); ?>
