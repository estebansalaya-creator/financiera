<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cotizaciones.php';

// ---------------------------------------------------------------------
// Cotizaciones de moneda extranjera (BCRA)
//   Se actualizan solas con la tarea programada (cron\actualizar_cotizaciones.php);
//   si la última actualización tiene más de COTIZACIONES_HORAS, esta
//   pantalla también consulta al abrirse.
//   ?fecha=        todas las monedas de ese día
//   ?moneda=&dias= evolución de una moneda   (&export=xlsx en Excel)
//   POST accion=actualizar | historico
// ---------------------------------------------------------------------

$errores = [];
$aviso = null;

if (es_post()) {
    csrf_exigir();
    try {
        if (post_str('accion') === 'historico') {
            $dias = min(1825, max(7, (int) post_str('dias')));
            [$n, $err] = cotiz_historico(date('Y-m-d', strtotime('-' . $dias . ' days')), date('Y-m-d'));
            flash($err ? ($n ? 'warning' : 'danger') : 'ok', 'Histórico de ' . $dias . ' días: ' . num($n) . ' cotizaciones guardadas.' . ($err ? ' ' . $err : ''));
        } else {
            [$n, $err] = cotiz_actualizar('PANTALLA');
            flash($err ? 'danger' : 'ok', $err ?: 'Cotizaciones actualizadas: ' . num($n) . ' guardadas.');
        }
    } catch (Throwable $e) {
        flash('danger', $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
            ? 'Falta crear las tablas: corré sql\financiera_cotizaciones.sql.' : error_amigable($e));
    }
    redirigir('cotizaciones.php' . (get_str('moneda') !== '' ? '?moneda=' . urlencode(get_str('moneda')) : ''));
}

$monedas = $dia = $serie = $logs = $destacadas = [];
$fechaDia = null;
$ultima = null;
$fMoneda = strtoupper(get_str('moneda')) ?: 'USD';
$fDias = in_array((int) get_str('dias'), [30, 90, 180, 365, 730], true) ? (int) get_str('dias') : 90;
try {
    // Actualización perezosa: si está vieja y no falló hace un rato
    $ultima = cotiz_ultima_actualizacion();
    $horas = (float) (q1("SELECT valor FROM parametros WHERE clave = 'COTIZACIONES_HORAS'")['valor'] ?? 4);
    $falloReciente = q1('SELECT 1 AS x FROM cotizaciones_log WHERE ok = 0 AND ejecutado_el > NOW() - INTERVAL 15 MINUTE');
    if ((!$ultima || strtotime($ultima) < time() - $horas * 3600) && !$falloReciente) {
        [$n, $err] = cotiz_actualizar('PANTALLA');
        if ($err !== '') {
            $aviso = 'No se pudo actualizar desde el BCRA: ' . $err . ' Se muestra lo último guardado.';
        }
        $ultima = cotiz_ultima_actualizacion();
    }

    $destacadas = cotiz_monedas();
    $fechaDia = leer_fecha(get_str('fecha')) ?? (q1('SELECT MAX(fecha) AS f FROM cotizaciones')['f'] ?? null);
    if ($fechaDia) {
        $real = q1('SELECT MAX(fecha) AS f FROM cotizaciones WHERE fecha <= ?', [$fechaDia])['f'] ?? null;
        $dia = $real ? q('SELECT c.*, (SELECT c2.cotizacion FROM cotizaciones c2 WHERE c2.moneda = c.moneda AND c2.fecha < c.fecha
                                         ORDER BY c2.fecha DESC LIMIT 1) AS anterior
                            FROM cotizaciones c WHERE c.fecha = ? ORDER BY c.moneda', [$real]) : [];
        if ($real && $real !== $fechaDia) {
            $aviso = ($aviso ? $aviso . ' ' : '') . 'El ' . fecha($fechaDia) . ' no hubo cotización (feriado o fin de semana): se muestra la del ' . fecha($real) . '.';
        }
        $fechaDia = $real;
    }
    $monedas = q('SELECT moneda, MAX(descripcion) AS descripcion FROM cotizaciones GROUP BY moneda ORDER BY moneda');
    $serie = q('SELECT fecha, cotizacion, pase FROM cotizaciones WHERE moneda = ? AND fecha >= CURDATE() - INTERVAL ? DAY ORDER BY fecha',
               [$fMoneda, $fDias]);
    $logs = q('SELECT * FROM cotizaciones_log ORDER BY id_log DESC LIMIT 6');

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $libro = new XlsxLibro();
        $libro->hoja($fMoneda)->titulo('Cotización ' . $fMoneda . ' (BCRA)', 'últimos ' . $fDias . ' días · pesos por unidad · generado el ' . date('d/m/Y H:i'))
            ->tabla([['Fecha', 'fecha', 12], ['Pesos por unidad', 'moneda', 18], ['Unidades por US$', 'texto', 16]],
                    array_map(function ($r) { return [$r['fecha'], $r['cotizacion'], $r['pase'] !== null ? number_format((float) $r['pase'], 6, ',', '.') : '']; }, $serie));
        if ($dia) {
            $libro->hoja('Todas ' . str_replace('-', '', $fechaDia))->titulo('Cotizaciones del ' . fecha($fechaDia) . ' (BCRA)', 'pesos por unidad')
                ->tabla([['Moneda', 'texto', 8], ['Descripción', 'texto', 34], ['Pesos por unidad', 'moneda', 18]],
                        array_map(function ($r) { return [$r['moneda'], $r['descripcion'], $r['cotizacion']]; }, $dia));
        }
        $libro->enviar('cotizaciones_' . $fMoneda . '_' . date('Ymd') . '.xlsx');
        exit;
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas: corré sql\financiera_cotizaciones.sql.' : error_amigable($e);
}
$porMoneda = array_column($dia, null, 'moneda');

/** 1234.5678 -> "1.234,57"; menos de 10 con 4 decimales */
function cot_fmt($n): string
{
    $n = (float) $n;
    return number_format($n, $n < 10 ? 4 : 2, ',', '.');
}

/** Gráfico de línea simple (SVG) de la serie. */
function cot_grafico(array $serie): string
{
    if (count($serie) < 2) {
        return '<div class="vacio">Hace falta más de un día para el gráfico. Usá «Cargar histórico».</div>';
    }
    $w = 900; $hgt = 220; $pl = 70; $pr = 14; $pt = 12; $pb = 26;
    $vals = array_map(function ($r) { return (float) $r['cotizacion']; }, $serie);
    $min = min($vals); $max = max($vals);
    if ($max - $min < 1e-9) { $max = $min + 1; }
    $n = count($serie);
    $x = function ($i) use ($n, $w, $pl, $pr) { return $pl + ($w - $pl - $pr) * $i / ($n - 1); };
    $y = function ($v) use ($min, $max, $hgt, $pt, $pb) { return $pt + ($hgt - $pt - $pb) * (1 - ($v - $min) / ($max - $min)); };
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
    }
    $o = '<svg class="cot-grafico" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" role="img" aria-label="Evolución de la cotización">';
    foreach ([0, 0.5, 1] as $k) {
        $v = $min + ($max - $min) * $k;
        $yy = round($y($v), 1);
        $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" class="cot-guia"/>'
            . '<text x="' . ($pl - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" class="cot-eje">' . h(cot_fmt($v)) . '</text>';
    }
    $o .= '<polyline points="' . implode(' ', $pts) . '" class="cot-linea"/>';
    foreach ([0, (int) floor(($n - 1) / 2), $n - 1] as $i) {
        $o .= '<text x="' . round($x($i), 1) . '" y="' . ($hgt - 6) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '" class="cot-eje">'
            . h(fecha($serie[$i]['fecha'])) . '</text>';
    }
    $o .= '<circle cx="' . round($x($n - 1), 1) . '" cy="' . round($y($vals[$n - 1]), 1) . '" r="4" class="cot-punto"/>';
    return $o . '</svg>';
}

layout_inicio('Cotizaciones', 'cotizaciones');
titulo('Cotizaciones', 'Moneda extranjera · fuente: Banco Central (Estadísticas Cambiarias)'
       . ($ultima ? ' · actualizado el ' . date('d/m/Y H:i', strtotime($ultima)) : ''),
       '<form method="post" class="form-en-linea">' . csrf_campo() . '<input type="hidden" name="accion" value="actualizar">'
       . boton('Actualizar ahora', 'primary', null, 'filtro', true) . '</form>'
       . boton('Exportar a Excel', 'excel', url_con(['export' => 'xlsx']), 'planilla'));
if ($aviso) {
    alerta($aviso, 'warning');
}
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila cot-destacadas">
  <?php foreach (array_slice($destacadas, 0, 4) as $m): $c = $porMoneda[$m] ?? null;
      $var = $c && $c['anterior'] ? ((float) $c['cotizacion'] / (float) $c['anterior'] - 1) : null; ?>
    <a class="bcra-tarjeta" href="cotizaciones.php?moneda=<?= h($m) ?>&dias=<?= $fDias ?>#evolucion">
      <span class="bcra-tarjeta-lbl"><?= h($m) ?> · <?= h($c ? mb_substr_seguro(ucfirst(strtolower((string) $c['descripcion'])), 26) : '') ?></span>
      <strong><?= $c ? '$ ' . h(cot_fmt($c['cotizacion'])) : '—' ?></strong>
      <span class="bcra-tarjeta-sub"><?= $var !== null ? '<span class="' . ($var > 0 ? 'cot-sube' : ($var < 0 ? 'cot-baja' : '')) . '">' . ($var > 0 ? '▲ ' : ($var < 0 ? '▼ ' : '')) . h(number_format($var * 100, 2, ',', '.')) . ' %</span> vs. día anterior' : ($fechaDia ? h(fecha($fechaDia)) : 'sin datos') ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="columnas-2-1">
  <div class="card" id="evolucion">
    <?= card_head('Evolución', $fMoneda . ' · pesos por unidad') ?>
    <form method="get" class="filter-bar">
      <label class="filtro-campo"><span class="filtro-lbl">Moneda</span>
        <select name="moneda" class="filtro" onchange="this.form.submit()">
          <?php foreach ($monedas as $m): ?><option value="<?= h($m['moneda']) ?>" <?= $m['moneda'] === $fMoneda ? 'selected' : '' ?>><?= h($m['moneda'] . ' · ' . ucfirst(strtolower((string) $m['descripcion']))) ?></option><?php endforeach; ?>
          <?php if (!$monedas): ?><option value="USD">USD</option><?php endif; ?>
        </select></label>
      <label class="filtro-campo"><span class="filtro-lbl">Período</span>
        <select name="dias" class="filtro" onchange="this.form.submit()"><?= opciones([30 => '30 días', 90 => '90 días', 180 => '6 meses', 365 => '1 año', 730 => '2 años'], $fDias) ?></select></label>
      <?php if ($serie): $p0 = (float) $serie[0]['cotizacion']; $p1 = (float) end($serie)['cotizacion']; ?>
        <span class="filter-count" style="margin-left:auto">del <?= h(fecha($serie[0]['fecha'])) ?> al <?= h(fecha(end($serie)['fecha'])) ?>:
          <strong class="<?= $p1 >= $p0 ? 'cot-sube' : 'cot-baja' ?>"><?= h(number_format(($p1 / $p0 - 1) * 100, 2, ',', '.')) ?> %</strong></span>
      <?php endif; ?>
    </form>
    <div class="bloque"><?= cot_grafico($serie) ?></div>
    <div class="data-table-wrap cot-serie">
      <table class="data-table" data-sin-orden>
        <thead><tr><th data-no-sort>Fecha</th><th class="th-right" data-no-sort>Pesos por unidad</th><th class="th-right" data-no-sort>Variación</th><th class="th-right" data-no-sort>Unidades por US$</th></tr></thead>
        <tbody>
        <?php $rev = array_reverse($serie); foreach ($rev as $k => $r): $ant = $rev[$k + 1] ?? null; $v = $ant ? (float) $r['cotizacion'] / (float) $ant['cotizacion'] - 1 : null; ?>
          <tr><td><?= h(fecha($r['fecha'])) ?></td><td class="td-right td-fuerte">$ <?= h(cot_fmt($r['cotizacion'])) ?></td>
            <td class="td-right"><?= $v === null ? '' : '<span class="' . ($v > 0 ? 'cot-sube' : ($v < 0 ? 'cot-baja' : '')) . '">' . h(number_format($v * 100, 2, ',', '.')) . ' %</span>' ?></td>
            <td class="td-right"><?= $r['pase'] !== null ? h(number_format((float) $r['pase'], 4, ',', '.')) : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$serie): ?><tr><td colspan="4" class="no-data">Sin cotizaciones de <?= h($fMoneda) ?> en el período.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card">
      <?= card_head('Todas las monedas', $fechaDia ? fecha($fechaDia) : '') ?>
      <form method="get" class="filter-bar">
        <label class="filtro-campo"><span class="filtro-lbl">Fecha</span>
          <input type="date" name="fecha" value="<?= h($fechaDia ?? '') ?>" max="<?= date('Y-m-d') ?>" class="filter-input" onchange="this.form.submit()"></label>
        <input type="hidden" name="moneda" value="<?= h($fMoneda) ?>">
      </form>
      <div class="data-table-wrap cot-todas">
        <table class="data-table">
          <thead><tr><th>Moneda</th><th class="th-right">$ por unidad</th></tr></thead>
          <tbody>
          <?php foreach ($dia as $r): ?>
            <tr><td><a href="cotizaciones.php?moneda=<?= h($r['moneda']) ?>&dias=<?= $fDias ?>#evolucion"><strong><?= h($r['moneda']) ?></strong></a>
              <span class="segunda-linea"><?= h(ucfirst(strtolower((string) $r['descripcion']))) ?></span></td>
              <td class="td-right"><?= h(cot_fmt($r['cotizacion'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$dia): ?><tr><td colspan="2" class="no-data">Todavía no hay cotizaciones. Tocá «Actualizar ahora».</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <?= card_head('Actualización automática') ?>
      <div class="bloque cot-estado">
        <p>La tarea programada de Windows consulta al BCRA de lunes a viernes a las 10:00, 16:30 y 19:30
          (<code>instalar_tarea_cotizaciones.bat</code>). Si la última actualización tiene más de <?= (int) ($horas ?? 4) ?> horas, esta pantalla también consulta al abrirse.</p>
        <form method="post" class="form-fila" data-confirmar="Se traen del BCRA las cotizaciones diarias de <?= h(implode(', ', $destacadas)) ?> del período elegido." data-confirmar-titulo="Cargar histórico" data-confirmar-boton="Cargar">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="historico">
          <select name="dias" class="celda-input" style="width:140px"><?= opciones([30 => '30 días', 90 => '90 días', 365 => '1 año', 730 => '2 años', 1825 => '5 años'], 365) ?></select>
          <?= boton('Cargar histórico', 'secondary', null, 'calendario', true, true) ?>
        </form>
      </div>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden>
          <thead><tr><th data-no-sort>Cuándo</th><th data-no-sort>Origen</th><th class="th-right" data-no-sort>Guardadas</th><th data-no-sort>Resultado</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr><td><?= h(date('d/m H:i', strtotime($l['ejecutado_el']))) ?></td><td><?= h(ucfirst(strtolower($l['origen']))) ?></td>
              <td class="td-right"><?= num($l['registros']) ?></td>
              <td class="td-wrap"><?= $l['ok'] ? estado('OK', 'ok') : '<span class="texto-error">' . h($l['mensaje']) . '</span>' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?><tr><td colspan="4" class="no-data">Todavía no corrió.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php layout_fin(); ?>
