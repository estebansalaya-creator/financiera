<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/solicitudes.php';

// ---------------------------------------------------------------------
// Solicitudes de préstamo: listado
//   Un vendedor ve las que cargó y las de su vendedor; el que tiene
//   «Autorizaciones» o «Préstamos» en el menú las ve todas.
//   Paginado en el servidor; el Excel trae todo el filtro.
//   ?instructivo=pdf  el instructivo para vendedores, con la documentación
//                     requerida como esté configurada en ese momento
//   ?estado=ABIERTAS  pendientes + observadas + aprobadas (las que
//                     todavía tienen algo por hacer)
// ---------------------------------------------------------------------

const FILTRO_ESTADO = ['ABIERTAS' => 'Abiertas (todas)'] + ESTADOS_SOLICITUD;

if (get_str('instructivo') === 'pdf') {
    sol_instructivo_pdf();                 // cómo cargar una solicitud y qué documentación pedir
    exit;
}

$errores = [];
$fQ      = trim(get_str('q'));
$fEstado = isset(FILTRO_ESTADO[get_str('estado')]) ? get_str('estado') : '';
$fVend   = (int) get_str('vendedor');
$fDesde  = leer_fecha(get_str('desde')) ?? '';
$fHasta  = leer_fecha(get_str('hasta')) ?? '';
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));
$todas   = false;
$liquida = false;

$filas = [];
$tot = ['n' => 0, 'monto' => 0];
$cuenta = [];
$paginas = 1;
$vendedores = [];
// Orden por encabezado (en la base); por defecto, las que están en curso primero.
$orden = orden_grilla(['curso' => "FIELD(s.estado, 'OBSERVADA', 'PENDIENTE', 'APROBADA') = 0", 'nro' => 's.id_solicitud',
                       'fecha' => 's.creado_el', 'cliente' => 'c.denominacion', 'tipo' => 'ta.nombre', 'monto' => 's.monto',
                       'plazo' => 's.plazo', 'cuota' => 's.valor_cuota', 'estado' => 's.estado'], 'curso', 'asc', 's.id_solicitud DESC');
try {
    $todas   = sol_ve_todas();
    $liquida = sol_liquida();
    [$where, $params] = sol_filtro_visibles();
    $baseWhere = $where;
    $baseParams = $params;
    if ($fQ !== '') {
        $dig = preg_replace('/\D/', '', $fQ);
        if ($dig !== '' && strlen($dig) >= 7 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
            $where .= ' AND (c.cuit LIKE ? OR cp.dni = ?)';
            array_push($params, '%' . $dig . '%', $dig);
        } else {
            $like = '%' . addcslashes($fQ, '%_\\') . '%';
            $where .= ' AND (s.nro_solicitud LIKE ? OR c.denominacion LIKE ?)';
            array_push($params, $like, $like);
        }
    }
    if ($fEstado === 'ABIERTAS') {
        $where .= " AND s.estado IN ('PENDIENTE','OBSERVADA','APROBADA')";
    } elseif ($fEstado !== '') {
        $where .= ' AND s.estado = ?';
        $params[] = $fEstado;
    }
    if ($fVend > 0 && $todas) {
        $where .= ' AND s.id_vendedor = ?';
        $params[] = $fVend;
    }
    if ($fDesde !== '') {
        $where .= ' AND s.creado_el >= ?';
        $params[] = $fDesde . ' 00:00:00';
    }
    if ($fHasta !== '') {
        $where .= ' AND s.creado_el <= ?';
        $params[] = $fHasta . ' 23:59:59';
    }
    $desde = "FROM solicitudes s
              JOIN clientes c ON c.id_cliente = s.id_cliente
              LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
              JOIN tipos_asociado ta ON ta.id_tipo_asociado = s.id_tipo_asociado
              LEFT JOIN vendedores v ON v.id_vendedor = s.id_vendedor
              LEFT JOIN usuarios uc ON uc.id_usuario = s.creado_por
              LEFT JOIN usuarios ur ON ur.id_usuario = s.resuelto_por
              LEFT JOIN prestamos p ON p.id_prestamo = s.id_prestamo";
    $columnas = "s.*, c.denominacion, c.cuit, ta.nombre AS tipo_asociado, v.nombre AS vendedor, uc.nombre AS creado_por_nombre,
                 ur.nombre AS resuelto_por_nombre, p.nro_prestamo";

    $vendedores = $todas ? q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre') : [];
    foreach (q("SELECT s.estado, COUNT(*) AS n, COALESCE(SUM(s.monto), 0) AS monto FROM solicitudes s WHERE $baseWhere GROUP BY s.estado", $baseParams) as $r) {
        $cuenta[$r['estado']] = $r;
    }
    $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(s.monto), 0) AS monto $desde WHERE $where", $params);

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $lista = q("SELECT $columnas $desde WHERE $where ORDER BY {$orden['sql']}", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Solicitudes')->titulo('Solicitudes de préstamo', num($tot['n']) . ' solicitudes · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['N°', 'texto', 11], ['Cargada', 'fecha', 12], ['Cliente', 'texto', 32], ['CUIT/CUIL', 'texto', 15],
                 ['Tipo de asociado', 'texto', 20], ['Vendedor', 'texto', 20], ['Monto', 'moneda', 15], ['Cuotas', 'entero', 8],
                 ['Cuota', 'moneda', 14], ['Estado', 'texto', 12], ['Cargó', 'texto', 20], ['Resolvió', 'texto', 20],
                 ['Resuelta', 'fecha', 12], ['Comentario', 'texto', 40], ['Préstamo', 'texto', 11]],
                array_map(function ($s) {
                    return [$s['nro_solicitud'], substr((string) $s['creado_el'], 0, 10), $s['denominacion'], cuit_fmt($s['cuit']), $s['tipo_asociado'],
                            $s['vendedor'], $s['monto'], $s['plazo'], $s['valor_cuota'], ESTADOS_SOLICITUD[$s['estado']] ?? $s['estado'],
                            $s['creado_por_nombre'], $s['resuelto_por_nombre'], $s['resuelto_el'] ? substr((string) $s['resuelto_el'], 0, 10) : null,
                            $s['resolucion'], $s['nro_prestamo']];
                }, $lista),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:A})', 'valor' => 'Total: ' . count($lista)], 6 => ['suma' => true]]
            );
        $libro->enviar('solicitudes_' . date('Ymd_Hi') . '.xlsx');
        exit;
    }

    $paginas = max(1, (int) ceil($tot['n'] / $porPag));
    $pagina  = min($pagina, $paginas);
    $filas = q("SELECT $columnas $desde WHERE $where
                 ORDER BY {$orden['sql']} LIMIT ? OFFSET ?",
               array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Faltan las tablas de solicitudes: corré sql\financiera_solicitudes.sql.' : error_amigable($e);
}

$hayFiltros = $fQ !== '' || $fEstado !== '' || $fVend || $fDesde !== '' || $fHasta !== '';
$n = function (string $e) use ($cuenta): int { return (int) ($cuenta[$e]['n'] ?? 0); };
$m = function (string $e) use ($cuenta): float { return (float) ($cuenta[$e]['monto'] ?? 0); };

layout_inicio('Solicitudes', '');
titulo('Solicitudes de préstamo', $todas ? 'Se cargan, las autoriza Gerencia y después se liquidan' : 'Las que cargaste y cómo van',
       boton('Instructivo', 'pdf', 'solicitudes.php?instructivo=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener', 'title' => 'Cómo cargar una solicitud y qué documentación pedir, en PDF'])
     . boton('Nueva solicitud', 'primary', 'solicitud.php', 'agregar'));
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <a class="bcra-tarjeta" href="solicitudes.php?estado=PENDIENTE"><span class="bcra-tarjeta-lbl">Esperan autorización</span><strong><?= num($n('PENDIENTE')) ?></strong><span class="bcra-tarjeta-sub"><?= $n('PENDIENTE') ? money($m('PENDIENTE')) : 'ninguna' ?></span></a>
  <a class="bcra-tarjeta" href="solicitudes.php?estado=OBSERVADA"><span class="bcra-tarjeta-lbl">Observadas</span><strong><?= num($n('OBSERVADA')) ?></strong><span class="bcra-tarjeta-sub"><?= $n('OBSERVADA') ? 'hay que corregirlas y reenviarlas' : 'ninguna' ?></span></a>
  <a class="bcra-tarjeta" href="solicitudes.php?estado=APROBADA"><span class="bcra-tarjeta-lbl">Aprobadas sin liquidar</span><strong><?= num($n('APROBADA')) ?></strong><span class="bcra-tarjeta-sub"><?= $n('APROBADA') ? money($m('APROBADA')) : 'ninguna' ?></span></a>
  <a class="bcra-tarjeta" href="solicitudes.php?estado=LIQUIDADA"><span class="bcra-tarjeta-lbl">Liquidadas</span><strong><?= num($n('LIQUIDADA')) ?></strong><span class="bcra-tarjeta-sub"><?= $n('RECHAZADA') ? num($n('RECHAZADA')) . ' rechazadas' : 'sin rechazos' ?></span></a>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="N°, cliente, CUIT o DNI…" maxlength="60"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(FILTRO_ESTADO, $fEstado, 'Todos') ?></select></label>
    <?php if ($todas): ?>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <?php endif; ?>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro <?= $fDesde !== '' ? 'activo' : '' ?>"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro <?= $fHasta !== '' ? 'activo' : '' ?>"></label>
    <?= filtro_botones('solicitudes.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'solicitudes',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <?= th_orden('N°', 'nro', $orden) ?><?= th_orden('Cargada', 'fecha', $orden) ?><?= th_orden('Cliente', 'cliente', $orden) ?><?= th_orden('Tipo de asociado', 'tipo', $orden) ?>
          <?= th_orden('Monto', 'monto', $orden, 'th-right') ?><?= th_orden('Cuotas', 'plazo', $orden, 'th-right') ?><?= th_orden('Cuota', 'cuota', $orden, 'th-right') ?>
          <?= th_orden('Estado', 'estado', $orden) ?><th data-no-sort>Resolución</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="10" class="no-data"><?= $hayFiltros ? 'Ninguna solicitud coincide con el filtro.' : 'Todavía no hay solicitudes. Cargá la primera con «Nueva solicitud».' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $s): ?>
        <tr>
          <td><div class="acciones">
            <?= btn_accion('ver', 'solicitud.php?id=' . $s['id_solicitud'], 'Abrir la solicitud') ?>
            <?= $s['estado'] === 'OBSERVADA' ? btn_accion('editar', 'solicitud.php?id=' . $s['id_solicitud'] . '&editar=1', 'Corregir y reenviar') : '' ?>
            <?= $s['estado'] === 'APROBADA' && $liquida ? btn_accion('agregar', 'prestamo_nuevo.php?solicitud=' . $s['id_solicitud'], 'Liquidar') : '' ?>
          </div></td>
          <td class="mono td-fuerte"><a href="solicitud.php?id=<?= (int) $s['id_solicitud'] ?>"><?= h($s['nro_solicitud']) ?></a></td>
          <td><?= h(fecha(substr((string) $s['creado_el'], 0, 10))) ?><span class="segunda-linea"><?= h((string) $s['creado_por_nombre']) ?></span></td>
          <td><?= h($s['denominacion']) ?>
            <span class="segunda-linea mono"><?= h(cuit_fmt($s['cuit'])) ?><?= $s['vendedor'] ? ' · ' . h($s['vendedor']) : '' ?></span></td>
          <td><?= h($s['tipo_asociado']) ?></td>
          <td class="td-right"><?= money($s['monto']) ?></td>
          <td class="td-right"><?= (int) $s['plazo'] ?></td>
          <td class="td-right"><?= money($s['valor_cuota']) ?></td>
          <td><?= estado_solicitud($s['estado']) ?>
            <?= $s['estado'] === 'PENDIENTE' ? '<span class="segunda-linea">' . h(sol_espera($s['enviado_el'])) . '</span>' : '' ?>
            <?= $s['estado'] === 'LIQUIDADA' && $s['nro_prestamo'] ? '<span class="segunda-linea mono">' . h($s['nro_prestamo']) . '</span>' : '' ?></td>
          <td style="white-space:normal;max-width:280px"><?php if ($s['resuelto_el'] && in_array($s['estado'], ['OBSERVADA', 'APROBADA', 'RECHAZADA', 'LIQUIDADA'], true)): ?>
            <?= h((string) $s['resuelto_por_nombre']) ?> · <?= h(fecha(substr((string) $s['resuelto_el'], 0, 10))) ?>
            <?= $s['resolucion'] ? '<span class="segunda-linea">' . h($s['resolucion']) . '</span>' : '' ?>
          <?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'solicitudes') ?>
</div>

<?php layout_fin(); ?>
