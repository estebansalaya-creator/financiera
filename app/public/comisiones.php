<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Liquidación de comisiones
//   Sin vendedor: resumen por vendedor del período (la hoja "Resumen"
//   de la macro) + subtotales por % aplicado.
//   Con vendedor: detalle de sus operaciones + subtotales por %.
//   Filtros: tipo de asociado, telemarketing, % comisión y búsqueda
//   (apellido, nombre o repartición). Se aplican a todo, también al Excel.
//   ?export=xlsx descarga lo que se está viendo en Excel, con autofiltro.
//   ?export=pdf  abre lo que se está viendo en PDF (en otra pestaña).
// Fuente: comisiones_importadas (historial de las planillas).
// ---------------------------------------------------------------------

$where      = '';
$params     = [];
$error      = null;
$periodos   = [];
$periodo    = '';
$vendedores = [];
$vendedor   = null;
$tipos      = [];
$pcts       = [];
$resumen    = [];
$detalle    = [];
$subtotales = [];
$tot        = ['registros' => 0, 'monto' => 0, 'comision' => 0, 'tm' => 0];
// Datos bancarios del vendedor (para pagarle), con sql\financiera_datos_bancarios.sql.
$conBanco   = columna_existe('vendedores', 'cbu');
$colBanco   = $conBanco ? 'v.cbu, v.alias_cbu' : 'NULL AS cbu, NULL AS alias_cbu';

// Filtros pedidos (se validan contra los valores del período)
$fTipo = trim(get_str('tipo'));
$fTm   = in_array(get_str('tm'), ['si', 'no'], true) ? get_str('tm') : '';
$fPct  = get_str('pct');
$fQ    = trim(get_str('q'));
if (strlen($fQ) > 60) {
    $fQ = substr($fQ, 0, 60);
}

/** Operaciones con nombre de vendedor, para el Excel y el PDF. */
function operaciones(string $where, array $params): array
{
    return q(
        "SELECT v.nombre AS vendedor, i.fecha, i.tipo_asociado, i.apellido, i.nombre, i.reparticion,
                i.monto, i.porcentaje, i.comision, i.telemarketing
           FROM comisiones_importadas i
           JOIN vendedores v ON v.id_vendedor = i.id_vendedor
          WHERE $where
          ORDER BY v.nombre, i.fecha, i.archivo, i.fila_origen",
        $params
    );
}

/** "0.100000,0.180000" -> "10,00 % / 18,00 %" */
function lista_pct(?string $csv): string
{
    if ($csv === null || $csv === '') {
        return '';
    }
    return implode(' / ', array_map('pct', explode(',', $csv)));
}

try {
    $periodos = array_column(
        q('SELECT DISTINCT periodo FROM comisiones_importadas ORDER BY periodo DESC'),
        'periodo'
    );

    $periodo = get_str('periodo');
    if (!in_array($periodo, $periodos, true)) {
        $periodo = $periodos[0] ?? '';
    }

    if ($periodo !== '') {
        $vendedores = q(
            "SELECT DISTINCT v.id_vendedor, v.codigo, v.nombre, $colBanco
               FROM comisiones_importadas i
               JOIN vendedores v ON v.id_vendedor = i.id_vendedor
              WHERE i.periodo = ?
              ORDER BY v.nombre",
            [$periodo]
        );
        $tipos = array_column(
            q('SELECT DISTINCT tipo_asociado FROM comisiones_importadas WHERE periodo = ? ORDER BY tipo_asociado', [$periodo]),
            'tipo_asociado'
        );
        $pcts = array_column(
            q('SELECT DISTINCT porcentaje FROM comisiones_importadas WHERE periodo = ? ORDER BY porcentaje', [$periodo]),
            'porcentaje'
        );

        $idVend = get_str('vendedor');
        foreach ($vendedores as $v) {
            if ((string) $v['id_vendedor'] === $idVend) {
                $vendedor = $v;
            }
        }
        if (!in_array($fTipo, $tipos, true)) {
            $fTipo = '';
        }
        if (!in_array($fPct, array_map('strval', $pcts), true)) {
            $fPct = '';
        }

        $where  = 'i.periodo = ?';
        $params = [$periodo];
        if ($vendedor) {
            $where   .= ' AND i.id_vendedor = ?';
            $params[] = (int) $vendedor['id_vendedor'];
        }
        if ($fTipo !== '') {
            $where   .= ' AND i.tipo_asociado = ?';
            $params[] = $fTipo;
        }
        if ($fTm !== '') {
            $where   .= ' AND i.telemarketing = ?';
            $params[] = $fTm === 'si' ? 1 : 0;
        }
        if ($fPct !== '') {
            $where   .= ' AND i.porcentaje = ?';
            $params[] = $fPct;
        }
        if ($fQ !== '') {
            $like     = '%' . addcslashes($fQ, '%_\\') . '%';
            $where   .= ' AND (i.apellido LIKE ? OR i.nombre LIKE ? OR i.reparticion LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $tot = q1(
            "SELECT COUNT(*) AS registros,
                    COALESCE(SUM(i.monto), 0)         AS monto,
                    COALESCE(SUM(i.comision), 0)      AS comision,
                    COALESCE(SUM(i.telemarketing), 0) AS tm
               FROM comisiones_importadas i
              WHERE $where",
            $params
        );

        $subtotales = q(
            "SELECT i.porcentaje, COUNT(*) AS registros,
                    SUM(i.monto) AS monto, SUM(i.comision) AS comision
               FROM comisiones_importadas i
              WHERE $where
              GROUP BY i.porcentaje
              ORDER BY i.porcentaje",
            $params
        );

        if ($vendedor) {
            $detalle = q(
                "SELECT i.fecha, i.tipo_asociado, i.apellido, i.nombre, i.reparticion,
                        i.monto, i.porcentaje, i.comision, i.telemarketing
                   FROM comisiones_importadas i
                  WHERE $where
                  ORDER BY i.fecha, i.archivo, i.fila_origen",
                $params
            );
        } else {
            $resumen = q(
                "SELECT v.id_vendedor, v.codigo, v.nombre AS vendedor, $colBanco,
                        COUNT(*)                     AS registros,
                        SUM(i.monto)                 AS monto,
                        SUM(i.comision)              AS comision,
                        COUNT(DISTINCT i.porcentaje) AS n_pct,
                        GROUP_CONCAT(DISTINCT i.porcentaje ORDER BY i.porcentaje) AS pcts
                   FROM comisiones_importadas i
                   JOIN vendedores v ON v.id_vendedor = i.id_vendedor
                  WHERE $where
                  GROUP BY v.id_vendedor, v.codigo, v.nombre" . ($conBanco ? ', v.cbu, v.alias_cbu' : '') . "
                  ORDER BY v.nombre",
                $params
            );
        }
    }
} catch (Throwable $e) {
    $error = error_amigable($e);
}

$pctProm = (float) $tot['monto'] > 0 ? (float) $tot['comision'] / (float) $tot['monto'] : 0;

/** Filtros activos (sin período ni vendedor), para mostrar y para el Excel. */
$activos = [];
if ($fTipo !== '') {
    $activos[] = 'Tipo: ' . $fTipo;
}
if ($fTm !== '') {
    $activos[] = 'Telemarketing: ' . strtoupper($fTm);
}
if ($fPct !== '') {
    $activos[] = '% comisión: ' . pct($fPct);
}
if ($fQ !== '') {
    $activos[] = 'Búsqueda: "' . $fQ . '"';
}
$hayFiltros = (bool) $activos;

// ---------------------------------------------------------------------
// Exportación a Excel (.xlsx) con autofiltro y totales que siguen al filtro
// ---------------------------------------------------------------------
if (get_str('export') === 'xlsx' && $error === null && $periodo !== '') {
    try {
        require_once __DIR__ . '/../inc/xlsx.php';

        $titulo = 'Liquidación de comisiones · ' . periodo_txt($periodo) . ($vendedor ? ' · ' . $vendedor['nombre'] : '');
        $sub    = ($hayFiltros ? 'Filtros: ' . implode(' · ', $activos) . ' · ' : '')
                . 'Historial importado de las planillas de liquidación · generado el ' . date('d/m/Y H:i');
        $libro  = new XlsxLibro();

        if (!$vendedor) {
            $libro->hoja('Resumen')->titulo($titulo, $sub)->tabla(
                [['Vendedor', 'texto', 26], ['Registros', 'entero', 11], ['Monto', 'moneda', 17],
                 ['Comisión', 'moneda', 16], ['% prom.', 'pct', 10], ['CBU / CVU', 'texto', 25], ['Alias', 'texto', 22],
                 ['Observación', 'texto', 60]],
                array_map(function ($r) {
                    return [$r['vendedor'], $r['registros'], $r['monto'], $r['comision'],
                            (float) $r['monto'] > 0 ? $r['comision'] / $r['monto'] : 0,
                            (string) $r['cbu'], (string) $r['alias_cbu'],
                            $r['n_pct'] > 1 ? $r['n_pct'] . ' porcentajes distintos: ' . lista_pct($r['pcts']) : ''];
                }, $resumen),
                [0 => ['texto' => 'TOTAL GENERAL'], 1 => ['suma' => true], 2 => ['suma' => true],
                 3 => ['suma' => true], 4 => ['formula' => 'IFERROR({D}/{C},0)', 'valor' => $pctProm]]
            );
        }

        $ops = operaciones($where, $params);
        $libro->hoja('Detalle')->titulo($titulo, $sub)->tabla(
            [['Vendedor', 'texto', 24], ['Fecha', 'fecha', 12], ['Tipo de asociado', 'texto', 23],
             ['Apellido', 'texto', 16], ['Nombre', 'texto', 22], ['Repartición', 'texto', 26],
             ['Monto', 'moneda', 16], ['% comisión', 'pct', 11], ['Comisión', 'moneda', 16],
             ['Telemarketing', 'centro', 15]],
            array_map(function ($d) {
                return [$d['vendedor'], $d['fecha'], $d['tipo_asociado'], $d['apellido'], $d['nombre'],
                        $d['reparticion'], $d['monto'], $d['porcentaje'], $d['comision'],
                        $d['telemarketing'] ? 'SI' : 'NO'];
            }, $ops),
            [0 => ['formula' => '"TOTAL · "&SUBTOTAL(103,{rango:B})&" operaciones"',
                   'valor' => 'TOTAL · ' . count($ops) . ' operaciones'],
             6 => ['suma' => true],
             7 => ['formula' => 'IFERROR({I}/{G},0)', 'valor' => $pctProm],
             8 => ['suma' => true]]
        );

        $libro->hoja('Subtotales por %')->titulo($titulo, $sub)->tabla(
            [['% comisión', 'pct', 12], ['Registros', 'entero', 11], ['Monto', 'moneda', 17], ['Comisión', 'moneda', 16]],
            array_map(function ($s) {
                return [$s['porcentaje'], $s['registros'], $s['monto'], $s['comision']];
            }, $subtotales),
            [0 => ['texto' => 'Total', 'derecha' => true], 1 => ['suma' => true], 2 => ['suma' => true], 3 => ['suma' => true]]
        );

        $libro->enviar('comisiones_' . $periodo . ($vendedor ? '_' . $vendedor['codigo'] : '')
                       . ($hayFiltros ? '_filtrado' : '') . '.xlsx');
        exit;
    } catch (Throwable $e) {
        $error = error_amigable($e);
    }
}

// ---------------------------------------------------------------------
// PDF: lo mismo que la pantalla (resumen o detalle del vendedor), con los filtros
// ---------------------------------------------------------------------
if (get_str('export') === 'pdf' && $error === null && $periodo !== '') {
    try {
        require_once __DIR__ . '/../inc/pdf.php';

        $titulo = 'Liquidación de comisiones · ' . periodo_txt($periodo) . ($vendedor ? ' · ' . $vendedor['nombre'] : '');
        $bajada = ($hayFiltros ? 'Filtros: ' . implode(' · ', $activos) . '   ·   ' : '')
                . 'Historial importado de las planillas de liquidación';
        $rep = new PdfReporte($titulo, $bajada);
        $rep->indicadores([
            ['Operaciones', num($tot['registros'])],
            ['Monto liquidado', money($tot['monto'])],
            ['Comisión', money($tot['comision'])],
            ['% promedio', pct($pctProm)],
            ['Por telemarketing', num($tot['tm'])],
        ]);

        if ((int) $tot['registros'] === 0) {
            $rep->nota('No hay operaciones con estos filtros.');
        } else {
            if (!$vendedor) {
                $rep->seccion('Resumen por vendedor', count($resumen) . ' vendedores');
                $rep->tabla(
                    [['Vendedor', 2.4, 'L'], ['Registros', 0.9, 'R'], ['Monto', 1.7, 'R'], ['Comisión', 1.7, 'R'],
                     ['% prom.', 1, 'R'], ['Observación', 8, 'L']],
                    array_map(function ($r) {
                        return [$r['vendedor'], num($r['registros']), money($r['monto']), money($r['comision']),
                                pct((float) $r['monto'] > 0 ? $r['comision'] / $r['monto'] : 0),
                                $r['n_pct'] > 1 ? $r['n_pct'] . ' porcentajes distintos: ' . lista_pct($r['pcts']) : ''];
                    }, $resumen),
                    ['TOTAL GENERAL', num($tot['registros']), money($tot['monto']), money($tot['comision']), pct($pctProm), '']
                );
            }

            $rep->seccion('Subtotales por % aplicado', $vendedor ? 'de este vendedor' : 'todos los vendedores');
            $rep->tabla(
                [['% comisión', 1, 'R'], ['Registros', 1, 'R'], ['Monto', 1.6, 'R'], ['Comisión', 1.6, 'R']],
                array_map(function ($s) {
                    return [pct($s['porcentaje']), num($s['registros']), money($s['monto']), money($s['comision'])];
                }, $subtotales),
                ['Total', num($tot['registros']), money($tot['monto']), money($tot['comision'])],
                0.55
            );

            if ($vendedor) {
                $rep->seccion('Detalle de operaciones', num($tot['registros']) . ' operaciones');
                $rep->tabla(
                    [['Fecha', 1.1, 'L'], ['Tipo de asociado', 2.6, 'L'], ['Apellido', 1.6, 'L'], ['Nombre', 1.9, 'L'],
                     ['Repartición', 2.3, 'L'], ['Monto', 1.6, 'R'], ['%', 0.9, 'R'], ['Comisión', 1.6, 'R'],
                     ['Telemarketing', 1.6, 'C']],
                    array_map(function ($d) {
                        return [fecha($d['fecha']), $d['tipo_asociado'], $d['apellido'], $d['nombre'], $d['reparticion'],
                                money($d['monto']), pct($d['porcentaje']), money($d['comision']),
                                $d['telemarketing']
                                    ? ['t' => 'SI', 'color' => PdfReporte::AZUL, 'negrita' => true]
                                    : ['t' => 'NO', 'color' => PdfReporte::TENUE]];
                    }, $detalle),
                    ['TOTAL · ' . num($tot['registros']) . ' operaciones', '', '', '', '',
                     money($tot['monto']), pct($pctProm), money($tot['comision']), '']
                );
            }
        }

        $rep->enviar('comisiones_' . $periodo . ($vendedor ? '_' . $vendedor['codigo'] : '')
                     . ($hayFiltros ? '_filtrado' : '') . '.pdf');
        exit;
    } catch (Throwable $e) {
        $error = error_amigable($e);
    }
}

// ---------------------------------------------------------------------
// Pantalla (sistema de estilos de gestion_adm)
// ---------------------------------------------------------------------
$urlLimpiar = url_con(['tipo' => null, 'tm' => null, 'pct' => null, 'q' => null]);
layout_inicio('Comisiones', 'comisiones');
?>

<div class="page-header">
  <div>
    <?php if ($vendedor): ?>
      <div class="migas"><a href="<?= h(url_con(['vendedor' => null])) ?>">Comisiones</a> / <?= h($vendedor['nombre']) ?></div>
    <?php endif; ?>
    <h1><?= $vendedor ? h($vendedor['nombre']) : 'Liquidación de comisiones' ?></h1>
    <?php if ($periodo !== ''): ?>
      <p><?= h(periodo_txt($periodo)) ?> · historial importado de las planillas de liquidación</p>
    <?php endif; ?>
    <?php if ($vendedor && $conBanco): ?>
      <p>Para pagarle: <?= datos_bancarios_html($vendedor['cbu'], $vendedor['alias_cbu'])
          ?: 'sin CBU ni alias (se cargan en <a href="vendedores.php?edit=' . (int) $vendedor['id_vendedor'] . '">Vendedores</a>)' ?></p>
    <?php endif; ?>
    <?php if ($hayFiltros): ?>
      <div class="filtros-activos"><span class="chip">Filtrado por <?= h(implode(' · ', $activos)) ?></span></div>
    <?php endif; ?>
  </div>
  <?php if ($periodos): ?>
  <div class="page-header-acciones">
    <a class="btn btn-excel" href="<?= h(url_con(['export' => 'xlsx'])) ?>" title="Descarga lo que se está viendo, con filtros en cada columna"><?= icono('planilla') ?>Exportar a Excel</a>
    <a class="btn btn-pdf" href="<?= h(url_con(['export' => 'pdf'])) ?>" target="_blank" rel="noopener" title="Abre lo que se está viendo en PDF"><?= icono('pdf') ?>Generar PDF</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($periodos): ?>
<div class="card">
  <form class="filter-bar" method="get">
    <label class="filtro-campo"><span class="filtro-lbl">Período</span>
      <select name="periodo" class="filtro" onchange="this.form.submit()">
        <?php foreach ($periodos as $p): ?>
          <option value="<?= h($p) ?>" <?= $p === $periodo ? 'selected' : '' ?>><?= h(periodo_txt($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($vendedores as $v): ?>
          <option value="<?= h($v['id_vendedor']) ?>" <?= $vendedor && $v['id_vendedor'] === $vendedor['id_vendedor'] ? 'selected' : '' ?>><?= h($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="filtro-campo"><span class="filtro-lbl">Tipo de asociado</span>
      <select name="tipo" class="filtro <?= $fTipo !== '' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($tipos as $t): ?>
          <option value="<?= h($t) ?>" <?= $t === $fTipo ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="filtro-campo"><span class="filtro-lbl">Telemarketing</span>
      <select name="tm" class="filtro <?= $fTm !== '' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <option value="">Todos</option>
        <option value="si" <?= $fTm === 'si' ? 'selected' : '' ?>>SI</option>
        <option value="no" <?= $fTm === 'no' ? 'selected' : '' ?>>NO</option>
      </select>
    </label>
    <label class="filtro-campo"><span class="filtro-lbl">% comisión</span>
      <select name="pct" class="filtro <?= $fPct !== '' ? 'activo' : '' ?>" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($pcts as $p): ?>
          <option value="<?= h($p) ?>" <?= (string) $p === $fPct ? 'selected' : '' ?>><?= h(pct($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" maxlength="60"
             class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Apellido, nombre o repartición…">
    </label>
    <?= filtro_botones($urlLimpiar, $hayFiltros) ?>
  </form>
</div>
<?php endif; ?>

<?php if ($error !== null): ?>
  <?php alerta($error); ?>
<?php elseif ($periodo === ''): ?>
  <div class="card"><div class="vacio">Todavía no hay comisiones cargadas.</div></div>
<?php elseif ((int) $tot['registros'] === 0): ?>
  <div class="card"><div class="vacio">
    No hay operaciones con estos filtros.
    <?php if ($hayFiltros): ?><a href="<?= h($urlLimpiar) ?>">Quitar filtro</a><?php endif; ?>
  </div></div>
<?php else: ?>

  <div class="kpi-row">
    <div class="kpi-card"><div class="kpi-ico"><?= icono('documento', 20) ?></div>
      <div><div class="kpi-lbl">Operaciones</div><div class="kpi-val"><?= num($tot['registros']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico"><?= icono('dinero', 20) ?></div>
      <div><div class="kpi-lbl">Monto liquidado</div><div class="kpi-val monto"><?= money($tot['monto']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico ok"><?= icono('dinero', 20) ?></div>
      <div><div class="kpi-lbl">Comisión</div><div class="kpi-val monto"><?= money($tot['comision']) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico"><?= icono('grafico', 20) ?></div>
      <div><div class="kpi-lbl">% promedio</div><div class="kpi-val"><?= pct($pctProm) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-ico warn"><?= icono('telefono', 20) ?></div>
      <div><div class="kpi-lbl">Por telemarketing</div><div class="kpi-val"><?= num($tot['tm']) ?></div></div></div>
  </div>

  <?php if (!$vendedor): ?>
  <div class="card">
    <?= card_head('Resumen por vendedor', 'clic en un encabezado para ordenar') ?>
    <?= barra_local('comisiones.php', 'Vendedor, CBU o alias…', '', false, null) ?>
    <div class="data-table-wrap">
      <table class="data-table" data-local data-paginada data-unidad="vendedor|vendedores">
        <thead>
          <tr>
            <th class="col-acciones" data-no-sort>Acciones</th>
            <th>Vendedor</th>
            <th class="th-right">Registros</th>
            <th class="th-right">Monto</th>
            <th class="th-right">Comisión</th>
            <th class="th-right">% prom.</th>
            <th data-no-sort>Observación</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($resumen as $r): $pr = (float) $r['monto'] > 0 ? $r['comision'] / $r['monto'] : 0; ?>
          <tr>
            <td>
              <div class="acciones">
                <a class="btn-accion ver" href="<?= h(url_con(['vendedor' => $r['id_vendedor']])) ?>" title="Ver el detalle"><?= icono('ver') ?></a>
                <a class="btn-accion pdf" href="<?= h(url_con(['vendedor' => $r['id_vendedor'], 'export' => 'pdf'])) ?>" target="_blank" rel="noopener" title="PDF del vendedor"><?= icono('pdf') ?></a>
                <a class="btn-accion planilla" href="<?= h(url_con(['vendedor' => $r['id_vendedor'], 'export' => 'xlsx'])) ?>" title="Excel del vendedor"><?= icono('planilla') ?></a>
              </div>
            </td>
            <td class="td-fuerte"><a href="<?= h(url_con(['vendedor' => $r['id_vendedor']])) ?>"><?= h($r['vendedor']) ?></a>
              <?php if ($conBanco): ?><span class="segunda-linea"><?= datos_bancarios_html($r['cbu'], $r['alias_cbu']) ?: 'sin CBU ni alias' ?></span><?php endif; ?></td>
            <td class="td-right" data-orden="<?= h($r['registros']) ?>"><?= num($r['registros']) ?></td>
            <td class="td-right" data-orden="<?= h($r['monto']) ?>"><?= money($r['monto']) ?></td>
            <td class="td-right" data-orden="<?= h($r['comision']) ?>"><?= money($r['comision']) ?></td>
            <td class="td-right" data-orden="<?= h($pr) ?>"><?= pct($pr) ?></td>
            <td class="td-muted td-wrap"><?= $r['n_pct'] > 1 ? h($r['n_pct'] . ' porcentajes distintos: ' . lista_pct($r['pcts'])) : '' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td></td>
            <td>TOTAL GENERAL</td>
            <td class="td-right"><?= num($tot['registros']) ?></td>
            <td class="td-right"><?= money($tot['monto']) ?></td>
            <td class="td-right"><?= money($tot['comision']) ?></td>
            <td class="td-right"><?= pct($pctProm) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?= paginacion_local() ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <?= card_head('Subtotales por % aplicado', $vendedor ? 'de este vendedor' : 'todos los vendedores') ?>
    <div class="data-table-wrap">
      <table class="data-table compacta">
        <thead>
          <tr>
            <th class="th-right">% comisión</th>
            <th class="th-right">Registros</th>
            <th class="th-right">Monto</th>
            <th class="th-right">Comisión</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subtotales as $s): ?>
          <tr>
            <td class="td-right" data-orden="<?= h($s['porcentaje']) ?>"><?= pct($s['porcentaje']) ?></td>
            <td class="td-right" data-orden="<?= h($s['registros']) ?>"><?= num($s['registros']) ?></td>
            <td class="td-right" data-orden="<?= h($s['monto']) ?>"><?= money($s['monto']) ?></td>
            <td class="td-right" data-orden="<?= h($s['comision']) ?>"><?= money($s['comision']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td class="td-right">Total</td>
            <td class="td-right"><?= num($tot['registros']) ?></td>
            <td class="td-right"><?= money($tot['monto']) ?></td>
            <td class="td-right"><?= money($tot['comision']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($vendedor): ?>
  <div class="card">
    <?= card_head('Detalle de operaciones') ?>
    <?= barra_local('comisiones.php', 'Apellido, nombre, repartición o tipo…', '', false, null) ?>
    <div class="data-table-wrap">
      <table class="data-table" data-local data-paginada data-unidad="operación|operaciones">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Tipo de asociado</th>
            <th>Apellido</th>
            <th>Nombre</th>
            <th>Repartición</th>
            <th class="th-right">Monto</th>
            <th class="th-right">%</th>
            <th class="th-right">Comisión</th>
            <th class="th-center">Telemarketing</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detalle as $d): ?>
          <tr>
            <td data-orden="<?= h($d['fecha']) ?>"><?= h(fecha($d['fecha'])) ?></td>
            <td><?= h($d['tipo_asociado']) ?></td>
            <td class="td-fuerte"><?= h($d['apellido']) ?></td>
            <td><?= h($d['nombre']) ?></td>
            <td><?= h($d['reparticion']) ?></td>
            <td class="td-right" data-orden="<?= h($d['monto']) ?>"><?= money($d['monto']) ?></td>
            <td class="td-right" data-orden="<?= h($d['porcentaje']) ?>"><?= pct($d['porcentaje']) ?></td>
            <td class="td-right" data-orden="<?= h($d['comision']) ?>"><?= money($d['comision']) ?></td>
            <td class="td-center" data-orden="<?= $d['telemarketing'] ? 1 : 0 ?>">
              <?= $d['telemarketing'] ? '<span class="estado estado-info">SI</span>' : '<span class="estado estado-neutro">NO</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="5">TOTAL · <?= num($tot['registros']) ?> operaciones</td>
            <td class="td-right"><?= money($tot['monto']) ?></td>
            <td class="td-right"><?= pct($pctProm) ?></td>
            <td class="td-right"><?= money($tot['comision']) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?= paginacion_local() ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php layout_fin(); ?>
