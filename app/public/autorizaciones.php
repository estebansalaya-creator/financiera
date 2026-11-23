<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/solicitudes.php';

// ---------------------------------------------------------------------
// Autorizaciones: la cola de solicitudes de préstamo que esperan decisión.
//   La más vieja arriba. Cada una dice cuánto pide, la cuota, qué dijeron
//   los controles y cómo está la documentación obligatoria (sin ella no
//   se puede aprobar). «Revisar» abre la solicitud, donde se aprueba, se
//   observa o se rechaza.
//   Abajo, lo último que se resolvió y las aprobadas que falta liquidar.
// ---------------------------------------------------------------------

$errores = [];
$pend = $aprob = $ult = [];
$hoy = ['APROBADA' => 0, 'OBSERVADA' => 0, 'RECHAZADA' => 0];
try {
    $sel = "SELECT s.*, c.denominacion, c.cuit, c.tipo AS cliente_tipo, ta.nombre AS tipo_asociado, v.nombre AS vendedor,
                   uc.nombre AS creado_por_nombre, ur.nombre AS resuelto_por_nombre
              FROM solicitudes s
              JOIN clientes c ON c.id_cliente = s.id_cliente
              JOIN tipos_asociado ta ON ta.id_tipo_asociado = s.id_tipo_asociado
              LEFT JOIN vendedores v ON v.id_vendedor = s.id_vendedor
              LEFT JOIN usuarios uc ON uc.id_usuario = s.creado_por
              LEFT JOIN usuarios ur ON ur.id_usuario = s.resuelto_por";
    $pend  = q("$sel WHERE s.estado = 'PENDIENTE' ORDER BY s.enviado_el, s.id_solicitud LIMIT 300");
    $aprob = q("$sel WHERE s.estado = 'APROBADA' ORDER BY s.resuelto_el LIMIT 100");
    $ult   = q("$sel WHERE s.estado IN ('APROBADA','OBSERVADA','RECHAZADA','LIQUIDADA') AND s.resuelto_el IS NOT NULL
                ORDER BY s.resuelto_el DESC LIMIT 15");
    foreach (q("SELECT accion, COUNT(*) AS n FROM solicitudes_historial
                 WHERE accion IN ('APROBADA','OBSERVADA','RECHAZADA') AND fecha >= CURDATE() GROUP BY accion") as $r) {
        $hoy[$r['accion']] = (int) $r['n'];
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Faltan las tablas de solicitudes: corré sql\financiera_solicitudes.sql.' : error_amigable($e);
}
$montoPend = array_sum(array_map(function ($s) { return (float) $s['monto']; }, $pend));
$montoAprob = array_sum(array_map(function ($s) { return (float) $s['monto']; }, $aprob));
$liquida = sol_liquida();

layout_inicio('Autorizaciones', '');
titulo('Autorizaciones', 'Solicitudes de préstamo que esperan decisión: aprobar, observar o rechazar',
       puede('solicitudes.php') ? boton('Todas las solicitudes', 'secondary', 'solicitudes.php', 'documento') : '');
mensajes($errores);
?>

<div class="bcra-tarjetas indicadores-fila">
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Esperan decisión</span><strong><?= num(count($pend)) ?></strong><span class="bcra-tarjeta-sub"><?= $pend ? money($montoPend) . ' · la más vieja ' . sol_espera($pend[0]['enviado_el']) : 'no hay nada pendiente' ?></span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Resueltas hoy</span><strong><?= num(array_sum($hoy)) ?></strong><span class="bcra-tarjeta-sub"><?= num($hoy['APROBADA']) ?> aprobadas · <?= num($hoy['OBSERVADA']) ?> observadas · <?= num($hoy['RECHAZADA']) ?> rechazadas</span></div>
  <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Aprobadas sin liquidar</span><strong><?= num(count($aprob)) ?></strong><span class="bcra-tarjeta-sub"><?= $aprob ? money($montoAprob) : 'ninguna' ?></span></div>
</div>

<div class="card">
  <?= card_head('Pendientes de autorización', count($pend) ? 'la más vieja primero' : '') ?>
  <?= barra_local('autorizaciones.php', 'Número, cliente, CUIT, comercializador o tipo…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="solicitud|solicitudes">
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <th>N°</th><th>Espera</th><th>Cliente</th><th>Tipo de asociado</th>
          <th class="th-right">Monto</th><th class="th-right">Cuotas</th><th class="th-right">Cuota</th>
          <th data-no-sort>Controles</th><th data-no-sort>Documentación</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pend): ?>
        <tr><td colspan="10" class="no-data">No hay solicitudes esperando autorización.</td></tr>
      <?php endif; ?>
      <?php foreach ($pend as $s): ?>
        <tr>
          <td><div class="acciones"><?= boton('Revisar', 'primary', 'solicitud.php?id=' . (int) $s['id_solicitud'], null, false, true) ?></div></td>
          <td class="mono td-fuerte"><a href="solicitud.php?id=<?= (int) $s['id_solicitud'] ?>"><?= h($s['nro_solicitud']) ?></a></td>
          <td data-orden="<?= h((string) $s['enviado_el']) ?>"><?= h(sol_espera($s['enviado_el'])) ?><span class="segunda-linea"><?= h((string) $s['creado_por_nombre']) ?></span></td>
          <td><?= h($s['denominacion']) ?>
            <span class="segunda-linea mono"><?= h(cuit_fmt($s['cuit'])) ?><?= $s['vendedor'] ? ' · ' . h($s['vendedor']) : '' ?></span></td>
          <td><?= h($s['tipo_asociado']) ?></td>
          <td class="td-right td-fuerte"><?= money($s['monto']) ?></td>
          <td class="td-right"><?= (int) $s['plazo'] ?></td>
          <td class="td-right"><?= money($s['valor_cuota']) ?></td>
          <td><?= sol_controles_resumen(sol_controles($s['controles'])) ?></td>
          <td><?= sol_documentacion_resumen(sol_documentacion((int) $s['id_cliente'], (string) $s['cliente_tipo'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php if ($aprob): ?>
<div class="card">
  <?= card_head('Aprobadas sin liquidar', 'el préstamo nace cuando se liquidan') ?>
  <?= barra_local('autorizaciones.php', 'Número, cliente o CUIT…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="solicitud|solicitudes">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>N°</th><th>Aprobada</th><th>Cliente</th>
        <th class="th-right">Monto</th><th class="th-right">Cuotas</th><th>Aprobó</th></tr></thead>
      <tbody>
      <?php foreach ($aprob as $s): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('ver', 'solicitud.php?id=' . $s['id_solicitud'], 'Abrir la solicitud') ?>
            <?= $liquida ? btn_accion('agregar', 'prestamo_nuevo.php?solicitud=' . $s['id_solicitud'], 'Liquidar') : '' ?></div></td>
          <td class="mono td-fuerte"><a href="solicitud.php?id=<?= (int) $s['id_solicitud'] ?>"><?= h($s['nro_solicitud']) ?></a></td>
          <td data-orden="<?= h((string) $s['resuelto_el']) ?>"><?= h(sol_espera((string) $s['resuelto_el'])) ?></td>
          <td><?= h($s['denominacion']) ?><span class="segunda-linea mono"><?= h(cuit_fmt($s['cuit'])) ?></span></td>
          <td class="td-right"><?= money($s['monto']) ?></td>
          <td class="td-right"><?= (int) $s['plazo'] ?></td>
          <td><?= h((string) $s['resuelto_por_nombre']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php if ($ult): ?>
<div class="card">
  <?= card_head('Últimas resueltas') ?>
  <?= barra_local('autorizaciones.php', 'Número, cliente, estado o comentario…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="solicitud|solicitudes">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>N°</th><th>Resuelta</th><th>Cliente</th><th class="th-right">Monto</th>
        <th>Estado</th><th>Quién</th><th data-no-sort>Comentario</th></tr></thead>
      <tbody>
      <?php foreach ($ult as $s): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('ver', 'solicitud.php?id=' . (int) $s['id_solicitud'], 'Abrir la solicitud') ?></div></td>
          <td class="mono td-fuerte"><a href="solicitud.php?id=<?= (int) $s['id_solicitud'] ?>"><?= h($s['nro_solicitud']) ?></a></td>
          <td class="td-nowrap"><?= h(fecha_hora($s['resuelto_el'])) ?></td>
          <td><?= h($s['denominacion']) ?></td>
          <td class="td-right"><?= money($s['monto']) ?></td>
          <td><?= estado_solicitud($s['estado']) ?></td>
          <td><?= h((string) $s['resuelto_por_nombre']) ?></td>
          <td class="td-wrap"><?= h((string) $s['resolucion']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
