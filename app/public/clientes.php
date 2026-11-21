<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Clientes: listado
//   Pagina en el servidor (después de la migración son miles): la grilla no
//   se ordena ni se pagina en el navegador. Los filtros van a la base y el
//   Excel trae todo el resultado del filtro, no solo la página.
// ---------------------------------------------------------------------

const TIPOS_CLIENTE   = ['F' => 'Persona física', 'J' => 'Empresa'];
const ESTADOS_CLIENTE = ['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo', 'BLOQUEADO' => 'Bloqueado'];
const ESTADOS_DOC     = ['COMPLETA' => 'Completa', 'INCOMPLETA' => 'Incompleta'];

$errores = [];
$fQ      = trim(get_str('q'));
$fTipo   = isset(TIPOS_CLIENTE[get_str('tipo')]) ? get_str('tipo') : '';
$fEstado = isset(ESTADOS_CLIENTE[get_str('estado')]) ? get_str('estado') : '';
$fVend   = (int) get_str('vendedor');
$fDoc    = isset(ESTADOS_DOC[get_str('doc')]) ? get_str('doc') : '';
$fAsoc   = (int) get_str('asociado');                  // tipo de asociado
$fRep    = (int) get_str('reparticion');
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));

$where  = '1 = 1';
$params = [];
if ($fQ !== '') {
    $dig  = preg_replace('/\D/', '', $fQ);
    $like = '%' . addcslashes($fQ, '%_\\') . '%';
    $conBanco = columna_existe('clientes', 'cbu');
    if ($conBanco && strlen($dig) === 22 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
        $where   .= ' AND c.cbu = ?';                      // un CBU entero
        $params[] = $dig;
    } elseif ($dig !== '' && strlen($dig) >= 3 && preg_match('/^[\d\s.\-]+$/', $fQ)) {
        $where   .= ' AND (c.cuit LIKE ? OR cp.dni LIKE ?)';
        array_push($params, '%' . $dig . '%', '%' . $dig . '%');
    } else {
        $where   .= ' AND (c.denominacion LIKE ? OR ce.nombre_fantasia LIKE ? OR c.email LIKE ?' . ($conBanco ? ' OR c.alias_cbu LIKE ?' : '') . ')';
        array_push($params, $like, $like, $like);
        if ($conBanco) {
            $params[] = $like;
        }
    }
}
if ($fTipo !== '') {
    $where   .= ' AND c.tipo = ?';
    $params[] = $fTipo;
}
if ($fEstado !== '') {
    $where   .= ' AND c.estado = ?';
    $params[] = $fEstado;
}
if ($fVend > 0) {
    $where   .= ' AND c.id_vendedor = ?';
    $params[] = $fVend;
}
if ($fAsoc > 0) {
    $where   .= ' AND c.id_tipo_asociado = ?';
    $params[] = $fAsoc;
}
if ($fRep > 0) {
    $where   .= ' AND c.id_reparticion = ?';
    $params[] = $fRep;
}
if ($fDoc === 'COMPLETA') {
    $where .= ' AND COALESCE(dr.faltan, 0) + COALESCE(dr.vencidos, 0) = 0';
} elseif ($fDoc === 'INCOMPLETA') {
    $where .= ' AND COALESCE(dr.faltan, 0) + COALESCE(dr.vencidos, 0) > 0';
}
$desde = "FROM clientes c
          LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
          LEFT JOIN clientes_empresas ce ON ce.id_cliente = c.id_cliente
          LEFT JOIN vendedores v ON v.id_vendedor = c.id_vendedor
          LEFT JOIN tipos_asociado ta ON ta.id_tipo_asociado = c.id_tipo_asociado
          LEFT JOIN reparticiones rp ON rp.id_reparticion = c.id_reparticion
          LEFT JOIN v_clientes_doc_resumen dr ON dr.id_cliente = c.id_cliente
         WHERE $where";
$columnas = "c.id_cliente, c.tipo, c.cuit, c.denominacion, c.email, c.condicion_iva, c.estado, c.creado_el,
             v.nombre AS vendedor, cp.dni, ce.nombre_fantasia, ta.nombre AS tipo_asociado, rp.nombre AS reparticion,
             (SELECT t.numero FROM telefonos t WHERE t.id_cliente = c.id_cliente ORDER BY t.principal DESC, t.id_telefono LIMIT 1) AS telefono,
             (SELECT CONCAT(d.localidad, ', ', d.provincia) FROM domicilios d WHERE d.id_cliente = c.id_cliente
               ORDER BY d.principal DESC, d.id_domicilio LIMIT 1) AS localidad,
             (SELECT COUNT(*) FROM prestamos p WHERE p.id_cliente = c.id_cliente AND p.estado = 'VIGENTE') AS vigentes,
             COALESCE(dr.requeridos, 0) AS doc_requeridos, COALESCE(dr.ok, 0) AS doc_ok,
             COALESCE(dr.faltan, 0) AS doc_faltan, COALESCE(dr.vencidos, 0) AS doc_vencidos";

$filas = [];
$total = 0;
$vendedores = [];
$tiposAsoc = [];
$reparts = [];
$hayFiltros = $fQ !== '' || $fTipo !== '' || $fEstado !== '' || $fVend > 0 || $fDoc !== '' || $fAsoc > 0 || $fRep > 0;
// Orden por encabezado (en la base): la grilla y el Excel salen igual.
$orden = orden_grilla(['cuit' => 'c.cuit', 'cliente' => 'c.denominacion', 'asociado' => 'ta.nombre', 'telefono' => 'telefono', 'localidad' => 'localidad',
                       'vendedor' => 'v.nombre', 'vigentes' => 'vigentes', 'estado' => 'c.estado'], 'cliente', 'asc', 'c.id_cliente');

/** «Completa», «Faltan 2», «1 vencido»… */
function doc_estado_txt(array $c): string
{
    if ((int) $c['doc_requeridos'] === 0) {
        return '';
    }
    $partes = [];
    if ((int) $c['doc_faltan']) {
        $partes[] = 'Faltan ' . (int) $c['doc_faltan'];
    }
    if ((int) $c['doc_vencidos']) {
        $partes[] = (int) $c['doc_vencidos'] . ((int) $c['doc_vencidos'] === 1 ? ' vencido' : ' vencidos');
    }
    return $partes ? implode(' · ', $partes) : 'Completa';
}
try {
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores ORDER BY nombre');
    $tiposAsoc = array_column(q('SELECT id_tipo_asociado, nombre FROM tipos_asociado ORDER BY nombre'), 'nombre', 'id_tipo_asociado');
    $reparts = array_column(q('SELECT r.id_reparticion, r.nombre FROM reparticiones r LEFT JOIN organismos o ON o.id_organismo = r.id_organismo
                                ORDER BY o.nombre IS NULL, o.nombre, r.nombre'), 'nombre', 'id_reparticion');
    $total = (int) q1("SELECT COUNT(*) AS n $desde", $params)['n'];

    if (get_str('export') === 'xlsx') {
        require_once __DIR__ . '/../inc/xlsx.php';
        $todas = q("SELECT $columnas $desde ORDER BY {$orden['sql']}", $params);
        $libro = new XlsxLibro();
        $libro->hoja('Clientes')->titulo('Clientes', ($hayFiltros ? 'Filtrado · ' : '') . num($total) . ' clientes · generado el ' . date('d/m/Y H:i'))
            ->tabla(
                [['CUIT/CUIL', 'texto', 15], ['Cliente', 'texto', 34], ['Tipo', 'texto', 15], ['DNI', 'texto', 11],
                 ['Tipo de asociado', 'texto', 24], ['Repartición', 'texto', 24],
                 ['Condición IVA', 'texto', 13], ['Email', 'texto', 28], ['Teléfono', 'texto', 20], ['Localidad', 'texto', 28],
                 ['Vendedor', 'texto', 22], ['Préstamos vigentes', 'entero', 12], ['Documentación', 'texto', 18], ['Estado', 'texto', 11], ['Alta', 'fecha', 12]],
                array_map(function ($c) {
                    return [cuit_fmt($c['cuit']), $c['denominacion'], TIPOS_CLIENTE[$c['tipo']] ?? $c['tipo'], $c['dni'],
                            $c['tipo_asociado'], $c['reparticion'],
                            $c['condicion_iva'], $c['email'], tel_fmt($c['telefono']), $c['localidad'], $c['vendedor'],
                            $c['vigentes'], doc_estado_txt($c), ESTADOS_CLIENTE[$c['estado']] ?? $c['estado'], substr((string) $c['creado_el'], 0, 10)];
                }, $todas),
                [0 => ['formula' => '"Total: "&SUBTOTAL(103,{rango:B})&" clientes"', 'valor' => 'Total: ' . count($todas) . ' clientes']]
            );
        $libro->enviar('clientes_' . date('Ymd_Hi') . '.xlsx');
        exit;
    }

    $paginas = max(1, (int) ceil($total / $porPag));
    $pagina  = min($pagina, $paginas);
    $filas = q("SELECT $columnas $desde ORDER BY {$orden['sql']} LIMIT ? OFFSET ?",
               array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $paginas = 1;
}

layout_inicio('Clientes', 'clientes');
titulo('Clientes', 'Personas y empresas que piden préstamos',
       boton('Nuevo cliente', 'primary', 'cliente.php', 'agregar'));
mensajes($errores);
?>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>"
             placeholder="CUIT, DNI, nombre, razón social, CBU o alias…" maxlength="60"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Tipo</span>
      <select name="tipo" class="filtro <?= $fTipo !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(TIPOS_CLIENTE, $fTipo, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_CLIENTE, $fEstado, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Vendedor</span>
      <select name="vendedor" class="filtro <?= $fVend ? 'activo' : '' ?>" onchange="this.form.submit()">
        <?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $fVend ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Tipo de asociado</span>
      <select name="asociado" class="filtro <?= $fAsoc ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones($tiposAsoc, $fAsoc ?: '', 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Repartición</span>
      <select name="reparticion" class="filtro <?= $fRep ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones($reparts, $fRep ?: '', 'Todas') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Documentación</span>
      <select name="doc" class="filtro <?= $fDoc !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_DOC, $fDoc, 'Todas') ?></select></label>
    <?= filtro_botones('clientes.php', $hayFiltros) ?>
    <?= filtro_fin(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'clientes',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <?= th_orden('CUIT / CUIL', 'cuit', $orden) ?>
          <?= th_orden('Cliente', 'cliente', $orden) ?>
          <?= th_orden('Tipo de asociado', 'asociado', $orden) ?>
          <?= th_orden('Teléfono', 'telefono', $orden) ?>
          <?= th_orden('Localidad', 'localidad', $orden) ?>
          <?= th_orden('Vendedor', 'vendedor', $orden) ?>
          <?= th_orden('Préstamos vigentes', 'vigentes', $orden, 'th-right') ?>
          <th data-no-sort>Documentación</th>
          <?= th_orden('Estado', 'estado', $orden) ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="10" class="no-data">
          <?= $hayFiltros ? 'Ningún cliente coincide con el filtro.' : 'Todavía no hay clientes. Cargá el primero con «Nuevo cliente».' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $c): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'cliente.php?id=' . $c['id_cliente'], 'Abrir la ficha') ?></div></td>
          <td class="mono"><?= h(cuit_fmt($c['cuit'])) ?></td>
          <td class="td-fuerte"><a href="cliente.php?id=<?= (int) $c['id_cliente'] ?>"><?= h($c['denominacion']) ?></a>
            <span class="segunda-linea"><?= h(TIPOS_CLIENTE[$c['tipo']] ?? $c['tipo']) ?><?= $c['nombre_fantasia'] ? ' · ' . h($c['nombre_fantasia']) : '' ?><?= $c['email'] ? ' · ' . h($c['email']) : '' ?></span></td>
          <td><?= $c['tipo_asociado'] ? h($c['tipo_asociado']) : '<span class="td-muted">—</span>' ?>
            <?php if ($c['reparticion']): ?><span class="segunda-linea"><?= h($c['reparticion']) ?></span><?php endif; ?></td>
          <td><?= tel_enlace($c['telefono'] ?? null, (string) $c['denominacion']) ?></td>
          <td><?= h($c['localidad'] ?? '') ?></td>
          <td><?= h($c['vendedor'] ?? '') ?></td>
          <td class="td-right"><?= (int) $c['vigentes'] ?: '<span class="td-muted">—</span>' ?></td>
          <td><?php $dt = doc_estado_txt($c); ?><?= $dt === '' ? '<span class="td-muted">—</span>'
                : '<a href="cliente.php?id=' . (int) $c['id_cliente'] . '#documentos">' . estado($dt, $dt === 'Completa' ? 'ok' : ((int) $c['doc_faltan'] ? 'danger' : 'warn')) . '</a>' ?></td>
          <td><?= estado(ESTADOS_CLIENTE[$c['estado']] ?? $c['estado'], ['ACTIVO' => 'ok', 'INACTIVO' => 'neutro', 'BLOQUEADO' => 'danger'][$c['estado']] ?? 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, $total, $porPag, 'clientes') ?>
</div>

<?php layout_fin(); ?>
