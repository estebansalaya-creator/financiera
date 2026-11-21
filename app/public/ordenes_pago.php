<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ordenes_pago.php';

// ---------------------------------------------------------------------
// Órdenes de pago (el modelo de gestion_adm; las reglas, en
// sql\financiera_ordenes_pago.sql e inc\ordenes_pago.php).
//   ordenes_pago.php         la bandeja: por defecto, lo que está a pagar.
//                            Se tildan varias y se emiten los pagos de una
//                            vez (transferencia o efectivo, desde una cuenta).
//   ordenes_pago.php?id=N    la orden: el pago uno por uno (también con
//                            cheque), sus pagos y la anulación del pago.
// Al liquidar un préstamo nace su orden, ya aprobada. La plata sale en el
// libro de Tesorería cuando se emite el pago. Las transferencias salen
// después en un archivo (Tesorería › Transferencias masivas).
// ---------------------------------------------------------------------

const ESTADOS_FILTRO_OP = ['APROBADA' => 'A pagar', 'PAGADA' => 'Pagadas', 'ANULADA' => 'Anuladas', 'todas' => 'Todas'];

$errores = [];
$hoy = date('Y-m-d');
$instalado = false;
try {
    $instalado = op_instalado() && (bool) q('SHOW TABLES LIKE \'ordenes_pago\'');
} catch (Throwable $e) {
}
if (!$instalado) {
    $errores[] = 'Falta crear las órdenes de pago: corré sql\financiera_ordenes_pago.sql (después de financiera_fondos.sql).';
}
$id = (int) get_str('id');
$previo = [];                       // lo cargado en «Emitir el pago» si no se pudo emitir
if (!$id && $instalado && (int) get_str('pago') > 0) {          // desde el libro de fondos: el pago -> su orden
    $x = q1('SELECT id_orden FROM pagos_emitidos WHERE id_pago_emitido = ?', [(int) get_str('pago')]);
    $id = $x ? (int) $x['id_orden'] : 0;
}

/** Llama a sp_emitir_pago_op y devuelve el id del pago. */
function emitir_pago(int $idOrden, string $fecha, int $idCuenta, string $medio, ?string $cheque, ?string $cbu, ?string $alias, ?string $ref): int
{
    $st = db()->prepare('CALL sp_emitir_pago_op(?, ?, ?, ?, ?, ?, ?, ?, ?, @id_pago)');
    $st->execute([$idOrden, $fecha, $idCuenta, $medio, $cheque, $cbu, $alias, $ref, usuario_id()]);
    while ($st->nextRowset()) {
    }
    $st->closeCursor();
    return (int) q1('SELECT @id_pago AS id')['id'];
}

function texto_error(Throwable $e): string
{
    return $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e);
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post() && $instalado) {
    csrf_exigir();
    $accion = post_str('accion');
    $volver = (string) ($_POST['volver'] ?? '');
    $volver = preg_match('#^ordenes_pago\.php(\?[^\s]*)?$#', $volver) ? $volver : 'ordenes_pago.php';
    $cuentas = op_cuentas_pago();

    // Varias órdenes de una vez: transferencia o efectivo, desde una cuenta.
    if ($accion === 'emitir_varias') {
        $sel = array_values(array_filter(array_map('intval', (array) ($_POST['sel'] ?? []))));
        $fecha = leer_fecha(post_str('fecha')) ?? '';
        $idCuenta = (int) post_str('id_cuenta');
        $medio = post_str('medio');
        if (!$sel) {
            flash('warning', 'Tildá las órdenes que se pagan.');
        } elseif ($fecha === '') {
            flash('warning', 'Escribí la fecha del pago.');
        } elseif (!isset($cuentas[$idCuenta])) {
            flash('warning', 'Elegí la cuenta de la que salen los pagos.');
        } elseif (!in_array($medio, ['TRANSFERENCIA', 'EFECTIVO'], true)) {
            flash('warning', 'De a varias se paga por transferencia o en efectivo; con cheque, de a una (desde la orden).');
        } else {
            $ok = [];
            $mal = [];
            $total = 0.0;
            foreach ($sel as $idOrden) {
                $o = q1('SELECT nro_orden, importe FROM ordenes_pago WHERE id_orden = ?', [$idOrden]);
                try {
                    emitir_pago($idOrden, $fecha, $idCuenta, $medio, null, null, null, null);
                    $ok[] = $o['nro_orden'];
                    $total += (float) $o['importe'];
                } catch (Throwable $e) {
                    $mal[] = 'OP ' . ($o['nro_orden'] ?? '#' . $idOrden) . ': ' . texto_error($e);
                }
            }
            $txt = $ok ? num(count($ok)) . (count($ok) === 1 ? ' pago emitido' : ' pagos emitidos') . ' por ' . money($total) . ' desde ' . $cuentas[$idCuenta]['nombre'] . '.' : '';
            if ($ok && $medio === 'TRANSFERENCIA' && $cuentas[$idCuenta]['tipo'] === 'B') {
                $txt .= ' Las transferencias salen en el archivo de Tesorería › Transferencias masivas.';
            }
            if ($mal) {
                $txt .= ($txt !== '' ? ' ' : '') . 'No se pagaron: ' . implode(' · ', array_slice($mal, 0, 4)) . (count($mal) > 4 ? ' y ' . (count($mal) - 4) . ' más.' : '');
            }
            flash($mal ? ($ok ? 'warning' : 'danger') : 'ok', $txt);
        }
        redirigir($volver);
    }

    // Una orden, desde su ficha (también con cheque).
    if ($accion === 'emitir') {
        $idOrden = (int) post_str('id_orden');
        $medio = post_str('medio');
        $cbu = cbu_limpio(post_str('cbu_destino'));
        $alias = alias_cbu_limpio(post_str('alias_destino'));
        $cheque = preg_replace('/\D/', '', post_str('cheque'));
        $err = [];
        if (!isset(OP_MEDIOS[$medio])) {
            $err[] = 'Elegí cómo se paga.';
        }
        if ($medio === 'CHEQUE' && !preg_match('/^\d{1,18}$/', $cheque)) {
            $err[] = 'Escribí el número del cheque.';
        }
        if ($medio === 'TRANSFERENCIA') {
            $err = array_merge($err, datos_bancarios_errores($cbu, $alias));
        }
        $fecha = leer_fecha(post_str('fecha')) ?? '';
        if ($fecha === '') {
            $err[] = 'Escribí la fecha del pago.';
        }
        if ($err) {
            $errores = array_merge($errores, $err);
        } else {
            try {
                $idPago = emitir_pago($idOrden, $fecha, (int) post_str('id_cuenta'), $medio, $medio === 'CHEQUE' ? $cheque : null,
                                      $medio === 'TRANSFERENCIA' && $cbu !== '' ? $cbu : null, $medio === 'TRANSFERENCIA' && $alias !== '' ? $alias : null,
                                      post_str('referencia') !== '' ? mb_substr(post_str('referencia'), 0, 100) : null);
                $g = q1('SELECT g.*, c.nombre AS cuenta, c.tipo FROM pagos_emitidos g JOIN cuentas_fondos c ON c.id_cuenta_fondos = g.id_cuenta_fondos
                          WHERE g.id_pago_emitido = ?', [$idPago]);
                flash('ok', 'Pago ' . $g['nro_pago'] . ' emitido: ' . money($g['importe']) . ' · ' . op_pago_txt($g) . '.'
                    . ($g['medio'] === 'TRANSFERENCIA' && $g['tipo'] === 'B' && !$g['referencia'] ? ' La transferencia sale en el archivo de Transferencias masivas.' : ''));
                redirigir('ordenes_pago.php?id=' . $idOrden);
            } catch (Throwable $e) {
                $errores[] = texto_error($e);
            }
        }
        // No se emitió: la ficha vuelve con lo cargado y el motivo arriba.
        $previo = $_POST;
        $id = $idOrden;
    }

    if ($accion === 'anular_pago') {
        $idPago = (int) post_str('id_pago');
        $g = q1('SELECT id_orden, nro_pago FROM pagos_emitidos WHERE id_pago_emitido = ?', [$idPago]);
        try {
            $st = db()->prepare('CALL sp_anular_pago_op(?, ?, ?)');
            $st->execute([$idPago, post_str('motivo'), usuario_id()]);
            while ($st->nextRowset()) {
            }
            $st->closeCursor();
            flash('ok', 'Pago ' . $g['nro_pago'] . ' anulado: la orden vuelve a estar a pagar y el pago sale del saldo de la cuenta.');
        } catch (Throwable $e) {
            flash('danger', texto_error($e));
        }
        redirigir('ordenes_pago.php?id=' . (int) ($g['id_orden'] ?? 0));
    }
}

// ---------------------------------------------------------------------
// La orden
// ---------------------------------------------------------------------
$orden = null;
$pagos = [];
$transferidos = [];
if ($instalado && $id > 0) {
    try {
        $orden = q1('SELECT o.*, p.nro_prestamo, p.estado AS estado_prestamo, c.id_cliente, c.cbu AS cbu_ficha, c.alias_cbu AS alias_ficha,
                            u.nombre AS creado_por_nombre
                       FROM ordenes_pago o
                       LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo
                       LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                       LEFT JOIN usuarios u ON u.id_usuario = o.creado_por
                      WHERE o.id_orden = ?', [$id]);
        if ($orden) {
            $pagos = q('SELECT g.*, c.nombre AS cuenta, c.tipo, u.nombre AS usuario, ua.nombre AS anulado_por_nombre
                          FROM pagos_emitidos g
                          JOIN cuentas_fondos c ON c.id_cuenta_fondos = g.id_cuenta_fondos
                          LEFT JOIN usuarios u ON u.id_usuario = g.creado_por
                          LEFT JOIN usuarios ua ON ua.id_usuario = g.anulado_por
                         WHERE g.id_orden = ? ORDER BY g.id_pago_emitido DESC', [$id]);
            $transferidos = op_transferidos(array_column($pagos, 'id_pago_emitido'));
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

// ---------------------------------------------------------------------
// La bandeja
// ---------------------------------------------------------------------
$fEstado = isset(ESTADOS_FILTRO_OP[get_str('estado')]) ? get_str('estado') : 'APROBADA';
$fQ = trim(get_str('q'));
$fDesde = leer_fecha(get_str('desde')) ?? '';
$fHasta = leer_fecha(get_str('hasta')) ?? '';
$porPag = por_pagina(50);
$pagina = max(1, (int) get_str('p'));
$filas = [];
$tot = ['n' => 0, 'importe' => 0];
$kpi = ['a_pagar' => 0, 'n_a_pagar' => 0, 'sin_cbu' => 0, 'hoy' => 0, 'n_hoy' => 0, 'sin_archivo' => 0, 'n_sin_archivo' => 0];
$paginas = 1;
// Orden por encabezado del listado (en la base): la grilla y el Excel salen igual.
$og = orden_grilla(['orden' => 'o.id_orden', 'beneficiario' => 'o.beneficiario', 'clase' => 'o.clase', 'estado' => 'o.estado',
                    'importe' => 'o.importe', 'fecha' => 'o.fecha'], 'fecha', 'asc', 'o.id_orden');
if ($instalado && !$orden) {
    $where = ['1=1'];
    $params = [];
    if ($fEstado !== 'todas') {
        $where[] = 'o.estado = ?';
        $params[] = $fEstado;
    }
    if ($fDesde !== '') {
        $where[] = 'o.fecha >= ?';
        $params[] = $fDesde;
    }
    if ($fHasta !== '') {
        $where[] = 'o.fecha <= ?';
        $params[] = $fHasta;
    }
    if ($fQ !== '') {
        $like = '%' . addcslashes($fQ, '%_\\') . '%';
        $where[] = '(o.nro_orden LIKE ? OR o.beneficiario LIKE ? OR o.cuit = ? OR p.nro_prestamo LIKE ?
                     OR EXISTS (SELECT 1 FROM pagos_emitidos x WHERE x.id_orden = o.id_orden AND x.nro_pago LIKE ?))';
        array_push($params, $like, $like, preg_replace('/\D/', '', $fQ) ?: '-', $like, $like);
    }
    $desdeSql = 'FROM ordenes_pago o
                 LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo
                 LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                 LEFT JOIN pagos_emitidos g ON g.id_orden = o.id_orden AND g.estado = \'EMITIDO\'
                 LEFT JOIN cuentas_fondos cf ON cf.id_cuenta_fondos = g.id_cuenta_fondos
                WHERE ' . implode(' AND ', $where);
    $columnas = 'o.*, p.nro_prestamo, c.cbu AS cbu_ficha, c.alias_cbu AS alias_ficha,
                 g.id_pago_emitido, g.nro_pago, g.fecha AS fecha_pago, g.medio, g.cheque_numero, g.referencia, cf.nombre AS cuenta, cf.tipo';
    try {
        $tot = q1("SELECT COUNT(*) AS n, COALESCE(SUM(o.importe), 0) AS importe $desdeSql", $params);
        $kpi = q1("SELECT COALESCE(SUM(IF(o.estado = 'APROBADA', o.importe, 0)), 0) AS a_pagar, COALESCE(SUM(o.estado = 'APROBADA'), 0) AS n_a_pagar,
                          COALESCE(SUM(o.estado = 'APROBADA' AND COALESCE(c.cbu, '') = ''), 0) AS sin_cbu
                     FROM ordenes_pago o LEFT JOIN prestamos p ON p.id_prestamo = o.id_prestamo LEFT JOIN clientes c ON c.id_cliente = p.id_cliente")
             + q1("SELECT COALESCE(SUM(importe), 0) AS hoy, COUNT(*) AS n_hoy FROM pagos_emitidos WHERE estado = 'EMITIDO' AND fecha = CURDATE()")
             + q1("SELECT COALESCE(SUM(g.importe), 0) AS sin_archivo, COUNT(*) AS n_sin_archivo
                     FROM pagos_emitidos g JOIN cuentas_fondos c ON c.id_cuenta_fondos = g.id_cuenta_fondos AND c.tipo = 'B'
                    WHERE g.estado = 'EMITIDO' AND g.medio = 'TRANSFERENCIA' AND COALESCE(g.referencia, '') = ''
                      AND NOT EXISTS (SELECT 1 FROM transferencias_lotes_det d JOIN transferencias_lotes l ON l.id_lote = d.id_lote AND l.anulado_el IS NULL
                                       WHERE d.id_pago_emitido = g.id_pago_emitido AND d.incluida = 1)");

        if (get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $todas = q("SELECT $columnas $desdeSql ORDER BY {$og['sql']}", $params);
            $libro = new XlsxLibro();
            $libro->hoja('Órdenes de pago')->titulo('Órdenes de pago · ' . ESTADOS_FILTRO_OP[$fEstado], num($tot['n']) . ' órdenes · generado el ' . date('d/m/Y H:i'))
                ->tabla([['Orden', 'texto', 12], ['Fecha', 'fecha', 12], ['Beneficiario', 'texto', 32], ['CUIT', 'texto', 14], ['CBU / CVU', 'texto', 25],
                         ['Préstamo', 'texto', 12], ['Importe', 'moneda', 16], ['Estado', 'texto', 10], ['Pago', 'texto', 12], ['Fecha del pago', 'fecha', 12],
                         ['Cómo', 'texto', 34]],
                        array_map(function ($o) {
                            return [$o['nro_orden'], $o['fecha'], $o['beneficiario'], (string) $o['cuit'], (string) $o['cbu_ficha'], (string) $o['nro_prestamo'],
                                    (float) $o['importe'], OP_ESTADOS[$o['estado']] ?? $o['estado'], (string) $o['nro_pago'], $o['fecha_pago'],
                                    $o['nro_pago'] ? op_pago_txt($o) : ''];
                        }, $todas),
                        [0 => ['texto' => 'TOTAL'], 6 => ['suma' => true]]);
            $libro->enviar('ordenes_de_pago_' . date('Ymd') . '.xlsx');
            exit;
        }

        $paginas = max(1, (int) ceil($tot['n'] / $porPag));
        $pagina = min($pagina, $paginas);
        $filas = q("SELECT $columnas $desdeSql ORDER BY {$og['sql']} LIMIT ? OFFSET ?", array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}

$cuentasPago = [];
$cuentaDef = '';
if ($instalado) {
    try {
        $cuentasPago = op_cuentas_pago();
        $cuentaDef = (string) (cuenta_predeterminada('ARS') ?? '');
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
}
$volver = 'ordenes_pago.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

/** CBU y alias para mostrar, o el aviso de que no hay. */
function destino_html(?string $cbu, ?string $alias): string
{
    $d = datos_bancarios_html($cbu, $alias);
    return $d !== '' ? $d : estado('sin CBU ni alias', 'warn');
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
if ($orden) {
    layout_inicio('Orden ' . $orden['nro_orden']);
    titulo('Orden de pago ' . $orden['nro_orden'], $orden['beneficiario'] . ' · ' . money($orden['importe']) . ' · ' . (OP_CLASES[$orden['clase']] ?? $orden['clase']), '',
           '<a href="movimientos_fondos.php">Tesorería</a> / <a href="ordenes_pago.php">Órdenes de pago</a> / ' . h($orden['nro_orden']));
} else {
    layout_inicio('Órdenes de pago');
    titulo('Órdenes de pago', 'El desembolso de cada préstamo liquidado, para pagar por transferencia, cheque o efectivo',
           '',
           '<a href="movimientos_fondos.php">Tesorería</a> / Órdenes de pago');
}
mensajes($errores);

if ($instalado && $id > 0 && !$orden && !$errores) {
    alerta('No existe esa orden de pago.', 'warning');
}
?>

<?php if ($orden):
    $vigente = null;
    foreach ($pagos as $g) {
        if ($g['estado'] === 'EMITIDO') {
            $vigente = $g;
        }
    }
?>
<div class="grilla-2">
  <div class="card">
    <?= card_head('Orden', '', op_estado($orden['estado'])) ?>
    <dl class="datos-lista">
        <dt>Número</dt><dd class="mono"><?= h($orden['nro_orden']) ?></dd>
        <dt>Fecha</dt><dd><?= h(fecha($orden['fecha'])) ?></dd>
        <dt>Concepto</dt><dd><?= h((string) $orden['concepto']) ?>
          <?php if ($orden['id_prestamo']): ?> · <a href="prestamo.php?id=<?= (int) $orden['id_prestamo'] ?>"><?= h($orden['nro_prestamo']) ?></a><?php endif; ?></dd>
        <dt>Importe</dt><dd><strong><?= money($orden['importe']) ?></strong></dd>
        <dt>Beneficiario</dt><dd><?= $orden['id_cliente'] ? '<a href="cliente.php?id=' . (int) $orden['id_cliente'] . '">' . h($orden['beneficiario']) . '</a>' : h($orden['beneficiario']) ?>
          <?= $orden['cuit'] ? '<span class="mono">' . h(cuit_fmt($orden['cuit'])) . '</span>' : '' ?></dd>
        <dt>Cuenta del cliente</dt><dd><?= destino_html($orden['cbu_ficha'], $orden['alias_ficha']) ?></dd>
        <dt>Creada</dt><dd><?= h(date('d/m/Y H:i', strtotime($orden['creado_el']))) ?><?= $orden['creado_por_nombre'] ? ' por ' . h($orden['creado_por_nombre']) : '' ?></dd>
        <?php if ($orden['estado'] === 'ANULADA'): ?>
          <dt>Anulada</dt><dd><?= h(date('d/m/Y H:i', strtotime($orden['anulada_el']))) ?>: <?= h((string) $orden['motivo_anulacion']) ?></dd>
        <?php endif; ?>
    </dl>
  </div>

  <?php if ($orden['estado'] === 'APROBADA'): ?>
  <form method="post" action="ordenes_pago.php" class="card" id="frm-pagar">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="emitir">
    <input type="hidden" name="id_orden" value="<?= (int) $orden['id_orden'] ?>">
    <?= card_head('Emitir el pago', 'sale ' . money($orden['importe'])) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="fecha">Fecha <span class="req">*</span></label>
          <input type="date" id="fecha" name="fecha" value="<?= h((string) ($previo['fecha'] ?? $hoy)) ?>" min="<?= h($orden['fecha']) ?>" required></div>
        <div class="campo" style="--span:4"><label for="medio">Medio <span class="req">*</span></label>
          <select id="medio" name="medio"><?= opciones(OP_MEDIOS, (string) ($previo['medio'] ?? 'TRANSFERENCIA')) ?></select></div>
        <?= campo_cuenta_fondos('id_cuenta', 'Sale de', $cuentasPago, (string) ($previo['id_cuenta'] ?? $cuentaDef), 4, '', true) ?>
        <div class="campo solo-cheque" style="--span:4"><label for="cheque">N° de cheque <span class="req">*</span></label>
          <input id="cheque" name="cheque" inputmode="numeric" class="mono" maxlength="18" value="<?= h((string) ($previo['cheque'] ?? '')) ?>">
          <span class="campo-ayuda">de una chequera de la cuenta</span></div>
        <div class="campo solo-transf" style="--span:12"><span class="campo-label">Se le transfiere a</span>
          <div><?= destino_html($orden['cbu_ficha'], $orden['alias_ficha']) ?> <span class="td-muted">(la cuenta de la ficha del cliente)</span></div></div>
        <div class="campo solo-transf" style="--span:7"><label for="cbu_destino">Otro CBU / CVU para este pago</label>
          <input id="cbu_destino" name="cbu_destino" class="mono" maxlength="26" inputmode="numeric" placeholder="vacío: el de la ficha" data-cbu value="<?= h((string) ($previo['cbu_destino'] ?? '')) ?>"
                 <?= cbu_bancos() ? 'data-bancos="' . h(json_encode(cbu_bancos(), JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
          <span class="campo-ayuda" data-base="solo si el cliente pidió que se le deposite en otra cuenta">solo si el cliente pidió que se le deposite en otra cuenta</span></div>
        <div class="campo solo-transf" style="--span:5"><label for="referencia">N° de transferencia</label>
          <input id="referencia" name="referencia" maxlength="100" class="mono" placeholder="vacío: sale en el archivo" value="<?= h((string) ($previo['referencia'] ?? '')) ?>">
          <span class="campo-ayuda">si se transfirió a mano desde el home banking</span></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Emitir el pago', 'ok', null, 'dinero', true) ?></div>
  </form>
  <script>
  (function () {
    var m = document.getElementById('medio');
    function mostrar() {
      document.querySelectorAll('#frm-pagar .solo-cheque').forEach(function (e) { e.classList.toggle('oculto', m.value !== 'CHEQUE'); });
      document.querySelectorAll('#frm-pagar .solo-transf').forEach(function (e) { e.classList.toggle('oculto', m.value !== 'TRANSFERENCIA'); });
      document.getElementById('cheque').required = m.value === 'CHEQUE';
    }
    m.addEventListener('change', mostrar);
    mostrar();
  })();
  </script>
  <?php elseif ($vigente): ?>
  <div class="card">
    <?= card_head('Pago ' . $vigente['nro_pago'], fecha($vigente['fecha']), estado('Emitido', 'ok')) ?>
    <dl class="datos-lista">
        <dt>Cómo</dt><dd><?= h(op_pago_txt($vigente)) ?></dd>
        <?php if ($vigente['medio'] === 'TRANSFERENCIA'): ?>
          <?php $tr = $transferidos[(int) $vigente['id_pago_emitido']] ?? null; ?>
          <dt>A</dt><dd><?= $tr ? datos_bancarios_html($tr['cbu_destino'], null) . ' <span class="td-muted">(el del archivo)</span>'
                          : ($vigente['cbu_destino'] || $vigente['alias_destino'] ? datos_bancarios_html($vigente['cbu_destino'], $vigente['alias_destino']) . ' <span class="td-muted">(escrito al emitir el pago)</span>'
                                                                                 : destino_html($orden['cbu_ficha'], $orden['alias_ficha']) . ' <span class="td-muted">(el de la ficha, al armar el archivo)</span>') ?></dd>
          <dt>Archivo</dt><dd><?php
              echo $tr ? '<a href="transferencias.php?lote=' . (int) $tr['id_lote'] . '">' . h($tr['nombre_archivo']) . '</a>'
                       : ($vigente['referencia'] ? 'transferido a mano · N° ' . h($vigente['referencia'])
                                                 : ($vigente['tipo'] === 'B' ? estado('Falta el archivo', 'warn') . ' <a href="transferencias.php?cuenta=' . (int) $vigente['id_cuenta_fondos'] . '">Transferencias masivas</a>' : '—')); ?></dd>
        <?php endif; ?>
        <dt>Emitido</dt><dd><?= h(date('d/m/Y H:i', strtotime($vigente['creado_el']))) ?><?= $vigente['usuario'] ? ' por ' . h($vigente['usuario']) : '' ?></dd>
    </dl>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <?= card_head('Pagos de la orden', count($pagos) ? num(count($pagos)) . (count($pagos) === 1 ? ' pago' : ' pagos') : '') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th data-no-sort>Pago</th><th data-no-sort>Cómo</th><th data-no-sort>Destino</th>
        <th data-no-sort>Estado</th><th class="th-right" data-no-sort>Importe</th></tr></thead>
      <tbody>
      <?php if (!$pagos): ?><tr><td colspan="6" class="no-data">Todavía no se pagó.</td></tr><?php endif; ?>
      <?php foreach ($pagos as $g): $tr = $transferidos[(int) $g['id_pago_emitido']] ?? null; ?>
        <tr<?= $g['estado'] === 'ANULADO' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones">
            <?= puede('movimientos_fondos.php') ? btn_accion('ver', 'movimientos_fondos.php?cuenta=' . (int) $g['id_cuenta_fondos'] . '&desde=' . $g['fecha'] . '&hasta=' . $g['fecha'], 'Ver en el libro de fondos') : '' ?>
            <?php if ($g['estado'] === 'EMITIDO'): ?>
              <?php if ($tr): ?>
                <span class="btn-accion bloqueado" title="Salió en el archivo <?= h($tr['nombre_archivo']) ?>: para anularlo, primero sacalo del archivo"><?= icono('borrar') ?></span>
              <?php else: ?>
                <button type="button" class="btn-accion borrar" title="Anular el pago" aria-label="Anular el pago"
                        data-anular="<?= (int) $g['id_pago_emitido'] ?>" data-nro="<?= h($g['nro_pago']) ?>"><?= icono('borrar') ?></button>
              <?php endif; ?>
            <?php endif; ?>
          </div></td>
          <td class="mono td-fuerte"><?= h($g['nro_pago']) ?><span class="segunda-linea"><?= h(fecha($g['fecha'])) ?><?= $g['usuario'] ? ' · ' . h($g['usuario']) : '' ?></span></td>
          <td><?= h(op_pago_txt($g)) ?><?= $g['referencia'] ? '<span class="segunda-linea">N° ' . h($g['referencia']) . '</span>' : '' ?></td>
          <td class="td-wrap"><?= $g['medio'] !== 'TRANSFERENCIA' ? '<span class="td-muted">—</span>'
                : ($tr ? datos_bancarios_html($tr['cbu_destino'], null)
                       : ($g['cbu_destino'] || $g['alias_destino'] ? datos_bancarios_html($g['cbu_destino'], $g['alias_destino']) : '<span class="td-muted">el CBU de la ficha</span>')) ?>
              <?= $tr ? '<span class="segunda-linea">archivo <a href="transferencias.php?lote=' . (int) $tr['id_lote'] . '">' . h($tr['nombre_archivo']) . '</a></span>' : '' ?></td>
          <td><?= $g['estado'] === 'ANULADO' ? estado('Anulado', 'neutro') . '<span class="segunda-linea">' . h(date('d/m/Y', strtotime($g['anulado_el']))) . ': ' . h((string) $g['motivo_anulacion']) . '</span>' : estado('Emitido', 'ok') ?></td>
          <td class="td-right"><?= money($g['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal" id="modal-anular">
  <form method="post" action="ordenes_pago.php" class="modal-caja">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="anular_pago">
    <input type="hidden" name="id_pago" value="">
    <div class="modal-titulo">Anular el pago <span data-nro></span></div>
    <div class="modal-texto">La orden vuelve a estar a pagar y el pago sale del saldo de la cuenta. Si el banco ya lo debitó, no se puede.</div>
    <div class="campo"><label for="motivo">Motivo <span class="req">*</span></label>
      <input id="motivo" name="motivo" maxlength="200" required minlength="5" placeholder="Por qué se anula"></div>
    <div class="modal-acciones"><button type="button" class="btn btn-secondary" data-cerrar>Cancelar</button>
      <button type="submit" class="btn btn-danger">Anular el pago</button></div>
  </form>
</div>
<script>
(function () {
  var modal = document.getElementById('modal-anular');
  document.querySelectorAll('[data-anular]').forEach(function (b) {
    b.addEventListener('click', function () {
      modal.querySelector('input[name=id_pago]').value = b.getAttribute('data-anular');
      modal.querySelector('[data-nro]').textContent = b.getAttribute('data-nro');
      modal.classList.add('abierto');
      modal.querySelector('#motivo').focus();
    });
  });
  modal.querySelector('[data-cerrar]').addEventListener('click', function () { modal.classList.remove('abierto'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('abierto'); });
})();
</script>

<?php elseif ($instalado): ?>
<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">A pagar</span><strong><?= money($kpi['a_pagar']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($kpi['n_a_pagar']) ?> órdenes<?= (int) $kpi['sin_cbu'] ? ' · ' . num($kpi['sin_cbu']) . ' sin CBU en la ficha del cliente' : '' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Pagado hoy</span><strong><?= money($kpi['hoy']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($kpi['n_hoy']) ?> pagos emitidos</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Transferencias sin archivo</span><strong><?= money($kpi['sin_archivo']) ?></strong>
    <span class="bcra-tarjeta-sub"><?= num($kpi['n_sin_archivo']) ?> pagos · <?= puede('transferencias.php') ? '<a href="transferencias.php">generar el archivo</a>' : 'Transferencias masivas' ?></span></div>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" name="q" value="<?= h($fQ) ?>" class="filter-input <?= $fQ !== '' ? 'activo' : '' ?>" placeholder="Orden, pago, préstamo, beneficiario o CUIT…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== 'APROBADA' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(ESTADOS_FILTRO_OP, $fEstado) ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span><input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span><input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <?= filtro_botones('ordenes_pago.php', $fQ !== '' || $fDesde !== '' || $fHasta !== '' || $fEstado !== 'APROBADA') ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'órdenes',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null]), 'filas' => true]) ?>
  </form>
<form method="post" action="ordenes_pago.php" id="frm-op" data-confirmar="" data-confirmar-titulo="Emitir los pagos" data-confirmar-boton="Emitir">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="emitir_varias">
  <input type="hidden" name="volver" value="<?= h($volver) ?>">
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort><?php if ($fEstado === 'APROBADA'): ?><input type="checkbox" id="sel-todos" title="Tildar todas las de la página" aria-label="Tildar todas"><?php else: ?>Acciones<?php endif; ?></th>
        <?= th_orden('Orden', 'orden', $og) ?><?= th_orden('Beneficiario', 'beneficiario', $og) ?><?= th_orden('Concepto', 'clase', $og) ?><?= th_orden('Estado', 'estado', $og) ?><?= th_orden('Importe', 'importe', $og, 'th-right') ?></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="6" class="no-data"><?= $fEstado === 'APROBADA' && $fQ === '' && $fDesde === '' && $fHasta === '' ? 'No hay nada a pagar.' : 'No hay órdenes con este filtro.' ?></td></tr><?php endif; ?>
      <?php foreach ($filas as $o): ?>
        <tr<?= $o['estado'] === 'ANULADA' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones">
            <?php if ($o['estado'] === 'APROBADA'): ?><input type="checkbox" name="sel[]" value="<?= (int) $o['id_orden'] ?>" class="sel-op" data-importe="<?= h($o['importe']) ?>" aria-label="Elegir"><?php endif; ?>
            <?= btn_accion('ver', 'ordenes_pago.php?id=' . (int) $o['id_orden'], 'Ver la orden') ?></div></td>
          <td class="mono td-fuerte"><a href="ordenes_pago.php?id=<?= (int) $o['id_orden'] ?>"><?= h($o['nro_orden']) ?></a><span class="segunda-linea"><?= h(fecha($o['fecha'])) ?></span></td>
          <td class="td-wrap"><?= h($o['beneficiario']) ?><span class="segunda-linea"><?= $o['cuit'] ? h(cuit_fmt($o['cuit'])) . ' · ' : '' ?><?= datos_bancarios_html($o['cbu_ficha'], $o['alias_ficha']) ?: 'sin CBU ni alias' ?></span></td>
          <td><?= h(OP_CLASES[$o['clase']] ?? $o['clase']) ?><?= $o['nro_prestamo'] ? '<span class="segunda-linea"><a href="prestamo.php?id=' . (int) $o['id_prestamo'] . '">' . h($o['nro_prestamo']) . '</a></span>' : '' ?></td>
          <td><?= op_estado($o['estado']) ?><?= $o['nro_pago'] ? '<span class="segunda-linea">pago ' . h($o['nro_pago']) . ' · ' . h(fecha($o['fecha_pago'])) . ' · ' . h(op_pago_txt($o)) . '</span>' : '' ?></td>
          <td class="td-right"><?= money($o['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($filas): ?>
      <tfoot><tr class="total-row"><td colspan="5">TOTAL DEL FILTRO · <?= num($tot['n']) ?> órdenes</td><td class="td-right"><?= money($tot['importe']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($filas && $fEstado === 'APROBADA'): ?>
  <div class="barra-seleccion">
    <span class="barra-seleccion-txt"><span id="n-sel">0</span> elegidas · <span id="t-sel">$ 0,00</span></span>
    <label class="filtro-campo"><span class="filtro-lbl">Fecha del pago</span>
      <input type="date" name="fecha" value="<?= $hoy ?>" class="filter-input"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Sale de</span>
      <?= cuenta_select('id_cuenta', $cuentasPago, $cuentaDef, 'Elegí la cuenta…', ['class' => 'filtro', 'id' => 'sel-cuenta', 'required' => true]) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Medio</span>
      <select name="medio" class="filtro" id="sel-medio"><?= opciones(['TRANSFERENCIA' => 'Transferencia', 'EFECTIVO' => 'Efectivo'], 'TRANSFERENCIA') ?></select></label>
    <button type="submit" class="btn btn-ok btn-sm" id="btn-emitir" disabled><?= icono('dinero') ?>Emitir los pagos</button>
  </div>
  <?php endif; ?>
</form>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'órdenes') ?>
</div>
<script>
(function () {
  var todos = document.getElementById('sel-todos'), n = document.getElementById('n-sel'), t = document.getElementById('t-sel'),
      boton = document.getElementById('btn-emitir'), casillas = document.querySelectorAll('.sel-op'), form = document.getElementById('frm-op');
  function contar() {
    var c = 0, s = 0;
    casillas.forEach(function (x) { if (x.checked) { c++; s += parseFloat(x.getAttribute('data-importe')); } });
    if (n) n.textContent = c;
    if (t) t.textContent = '$ ' + s.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (boton) boton.disabled = c === 0;
    var cta = document.getElementById('sel-cuenta'), medio = document.getElementById('sel-medio');
    if (form && cta) {
      form.setAttribute('data-confirmar', c + (c === 1 ? ' pago por ' : ' pagos por ') + t.textContent + ' desde ' + (cta.value ? cta.options[cta.selectedIndex].text : '…')
        + ' (' + medio.options[medio.selectedIndex].text.toLowerCase() + '). La plata sale en el libro de fondos con la fecha elegida.');
    }
  }
  if (todos) todos.addEventListener('change', function () { casillas.forEach(function (x) { x.checked = todos.checked; }); contar(); });
  casillas.forEach(function (x) { x.addEventListener('change', contar); });
  ['sel-cuenta', 'sel-medio'].forEach(function (i) { var el = document.getElementById(i); if (el) el.addEventListener('change', contar); });
  contar();
})();
</script>
<?php endif; ?>

<?php layout_fin(); ?>
