<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/prestamos.php';
require __DIR__ . '/../inc/solicitudes.php';
require __DIR__ . '/../inc/ordenes_pago.php';
require __DIR__ . '/../inc/comisiones_escalas.php';

// ---------------------------------------------------------------------
// Nuevo préstamo (liquidación)
//   1. Revisar: simula con el mismo motor que la liquidación y corre los
//      controles (BCRA, cheques, documentación, mora, cuota / ingreso).
//      Si la consulta al BCRA es vieja, la hace.
//   2. Liquidar: vuelve a simular y a controlar en el servidor (no se
//      confía en lo que vino del navegador). Con advertencias, pide
//      confirmar y el motivo; con bloqueos, no deja.
//   Llama a sp_liquidar_prestamo y guarda los controles en el préstamo.
//   prestamo_nuevo.php?solicitud=N  liquida una solicitud APROBADA: cliente,
//                                   tipo de asociado, monto, cuotas y comercializador vienen
//                                   de la solicitud y no se cambian. Las
//                                   advertencias ya las aceptó el que aprobó.
//                                   Al liquidar, la solicitud queda LIQUIDADA
//                                   con su préstamo.
//   Sin una solicitud aprobada no se liquida, sea quien sea: la opción del
//   menú lleva a las solicitudes aprobadas, y desde ahí se elige cuál.
// ---------------------------------------------------------------------

$errores = [];
$aviso   = '';
$cliente = null;
$res     = null;
$cuadro  = [];
$gastos  = [];
$ctl     = [];
$revisado = false;
$comEst  = null;      // la comisión que va a tocar, para mostrarla antes de liquidar

$campos = ['cliente', 'buscar', 'tipo', 'monto', 'plazo', 'fecha', 'primer_vto', 'vendedor', 'comision', 'telemarketing', 'motivo'];
$f = [];
foreach ($campos as $c) {
    $f[$c] = es_post() ? post_str($c) : get_str($c);
}
$f['fecha'] = $f['fecha'] ?: date('Y-m-d');
$f['telemarketing'] = $f['telemarketing'] === '1' ? '1' : '0';
$confirmo = es_post() && post_str('confirmo') === '1';

// Tesorería: de qué cuenta sale el neto, con qué medio y su referencia (N° de
// transferencia o de cheque). Viene elegida la cuenta predeterminada en pesos.
$cuentasDes = [];
$des = ['cuenta' => post_str('cuenta_desembolso'), 'medio' => post_str('medio_desembolso') ?: 'TRANSFERENCIA', 'ref' => post_str('ref_desembolso')];
// Con órdenes de pago (sql\financiera_ordenes_pago.sql) el neto se paga con la orden que nace al liquidar:
// la cuenta y el medio los elige Tesorería al emitir el pago.
$conOP = false;
try {
    $conOP = op_instalado();
    if (fondos_instalado() && !$conOP) {
        $cuentasDes = cuentas_fondos('ARS');
        if (!es_post()) {
            $des['cuenta'] = (string) (cuenta_predeterminada('ARS') ?? '');
        }
    }
} catch (Throwable $e) {
    error_log('[financiera] cuentas de fondos: ' . $e->getMessage());
}
$accion = es_post() ? post_str('accion') : '';

// La solicitud aprobada que se liquida
$idSol = (int) (es_post() ? post_str('solicitud') : get_str('solicitud'));
$sol = null;
if ($idSol > 0) {
    try {
        $sol = sol_cargar($idSol);
    } catch (Throwable $e) {
        $sol = null;
    }
    if (!$sol || $sol['estado'] !== 'APROBADA') {
        flash('warning', $sol ? 'La solicitud ' . $sol['nro_solicitud'] . ' está ' . strtolower(ESTADOS_SOLICITUD[$sol['estado']]) . ': solo se liquida una aprobada.'
                              : 'Esa solicitud no existe.');
        redirigir($sol ? 'solicitud.php?id=' . $idSol : 'solicitudes.php');
    }
    $f['cliente'] = (string) $sol['id_cliente'];
    $f['buscar'] = '';
    $f['tipo'] = (string) $sol['id_tipo_asociado'];
    $f['monto'] = str_replace('.', ',', (string) (float) $sol['monto']);
    $f['plazo'] = (string) $sol['plazo'];
    $f['vendedor'] = (string) $sol['id_vendedor'];
    $f['telemarketing'] = (string) (int) $sol['telemarketing'];
} else {
    if (puede('solicitudes.php')) {
        if ((int) $f['cliente'] > 0) {
            redirigir('solicitud.php?cliente=' . (int) $f['cliente']);
        }
        flash('info', 'Los préstamos se liquidan desde una solicitud aprobada. Estas son las que están para liquidar.');
        redirigir('solicitudes.php?estado=APROBADA');
    }
    flash('warning', 'Los préstamos se liquidan desde una solicitud aprobada.');
    redirigir('prestamos.php');
}
$bloq = $sol ? ' disabled' : '';

/** Cliente por id, o por CUIT / CUIL / DNI escrito. */
function buscar_cliente(string $id, string $txt): array
{
    $sql = 'SELECT c.*, cp.ingreso_mensual, cp.dni FROM clientes c LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente ';
    if ((int) $id > 0) {
        $c = q1($sql . 'WHERE c.id_cliente = ?', [(int) $id]);
        return $c ? [$c] : [];
    }
    $d = preg_replace('/\D/', '', $txt);
    if (strlen($d) === 11) {
        return q($sql . 'WHERE c.cuit = ?', [$d]);
    }
    if (strlen($d) >= 7 && strlen($d) <= 8) {
        return q($sql . 'WHERE cp.dni = ? OR SUBSTRING(c.cuit, 3, 8) = LPAD(?, 8, \'0\')', [$d, $d]);
    }
    return [];
}

try {
    $tipos = q("SELECT p.*, EXISTS(SELECT 1 FROM tasas_vigencia t WHERE t.id_tipo_asociado = p.id_tipo_asociado
                                        AND t.vigencia_desde <= CURDATE()) AS con_tasa
                      FROM tipos_asociado p WHERE p.activo = 1 ORDER BY p.nombre");
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores WHERE activo = 1 ORDER BY nombre');

    if ($f['cliente'] !== '' || $f['buscar'] !== '') {
        $encontrados = buscar_cliente($f['cliente'], $f['buscar']);
        if (count($encontrados) === 1) {
            $cliente = $encontrados[0];
            $f['cliente'] = (string) $cliente['id_cliente'];
            if ($f['vendedor'] === '' && !es_post() && !$sol) {
                $f['vendedor'] = (string) $cliente['id_vendedor'];
            }
        } elseif (count($encontrados) > 1) {
            $errores[] = 'Hay más de un cliente con ese DNI: buscalo por CUIT/CUIL.';
        } elseif (es_post() || $f['buscar'] !== '') {
            $errores[] = 'No hay un cliente con ' . ($f['buscar'] !== '' ? '«' . $f['buscar'] . '»' : 'ese número') . '. Si es nuevo, dalo de alta primero.';
        }
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
    $tipos = $vendedores = [];
}

// ---------------------------------------------------------------------
// Revisar / liquidar
// ---------------------------------------------------------------------
if (es_post() && in_array($accion, ['revisar', 'liquidar', 'buscar'], true)) {
    csrf_exigir();
}
if (es_post() && in_array($accion, ['revisar', 'liquidar'], true) && !$errores) {
    $monto  = leer_num($f['monto']);
    $plazo  = leer_num($f['plazo']);
    $fecha  = leer_fecha($f['fecha']);
    $primer = $f['primer_vto'] === '' ? null : leer_fecha($f['primer_vto']);
    $com    = $f['comision'] === '' ? null : leer_num($f['comision']);
    if (!$cliente) {
        $errores[] = 'Elegí el cliente.';
    }
    if ((int) $f['tipo'] <= 0) {
        $errores[] = 'Elegí el tipo de asociado.';
    }
    if ($monto === null || $monto <= 0) {
        $errores[] = 'Escribí el monto a prestar.';
    }
    if ($plazo === null || $plazo < 1 || floor($plazo) != $plazo) {
        $errores[] = 'El plazo va en cuotas enteras.';
    }
    if ($fecha === null) {
        $errores[] = 'La fecha de liquidación no es válida.';
    } elseif ($fecha > date('Y-m-d', strtotime('+30 days'))) {
        $errores[] = 'La fecha de liquidación es muy adelante.';
    }
    if ($f['primer_vto'] !== '' && ($primer === null || ($fecha !== null && $primer <= $fecha))) {
        $errores[] = 'El primer vencimiento tiene que ser posterior a la liquidación.';
    }
    if ($com !== null && ($com < 0 || $com > 100)) {
        $errores[] = 'La comisión manual es un % entre 0 y 100.';
    }

    if (!$errores) {
        try {
            [$res, $cuadro, $gastos] = prestamo_simular((int) $f['tipo'], $monto, (int) $plazo, $fecha, $primer);
            [$ctl] = prestamo_controles($cliente, (float) $res['valor_cuota']);
            $revisado = true;
            $comEst = comision_estimada((int) $f['vendedor'] ?: null, (int) $f['tipo'], (int) $plazo, round($monto, 2),
                                        (float) $res['gastos_otorg'], $fecha, $f['telemarketing'] === '1',
                                        $com === null ? null : round($com / 100, 6));
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
            $res = null;
        }
    }

    // Lo que se revisó tiene que ser lo que se liquida
    $firma = hash('sha256', implode('|', [$f['cliente'], $f['tipo'], $f['monto'], $f['plazo'], $f['fecha'], $f['primer_vto'], $f['vendedor'], $f['comision'], $f['telemarketing'], (string) $idSol]));
    if ($accion === 'liquidar' && $revisado && post_str('firma') !== $firma) {
        $aviso = 'Cambiaste datos después de revisar: mirá de nuevo el cuadro y los controles antes de liquidar.';
        $accion = 'revisar';
    }

    if ($accion === 'liquidar' && $revisado) {
        $errDes = [];
        if ($cuentasDes) {
            $cd = $cuentasDes[(int) $des['cuenta']] ?? null;
            if (!$cd) {
                $errDes[] = 'Elegí la cuenta de fondos de la que sale el desembolso.';
            }
            if (!isset(MEDIOS_DESEMBOLSO[$des['medio']])) {
                $errDes[] = 'Elegí cómo se desembolsa (transferencia, cheque o efectivo).';
            } elseif ($des['medio'] === 'CHEQUE') {
                if ($cd && $cd['tipo'] !== 'B') {
                    $errDes[] = 'Un desembolso con cheque sale de una cuenta bancaria.';
                }
                if (!preg_match('/^\d{1,18}$/', $des['ref'])) {
                    $errDes[] = 'Escribí el número del cheque del desembolso (solo números).';
                }
            }
        }
        if (controles_hay($ctl, 'bloqueo')) {
            $errores[] = 'Hay controles que impiden liquidar.';
        } elseif (controles_hay($ctl, 'advertencia') && !$sol && (!$confirmo || strlen($f['motivo']) < 10)) {
            $errores[] = 'Hay advertencias: para liquidar igual, tildá la confirmación y escribí el motivo (al menos 10 letras).';
        } elseif ($errDes) {
            $errores = array_merge($errores, $errDes);
        } else {
            $pdo = db();
            $idPrestamo = 0;
            // La solicitud se toma antes de liquidar: si dos la liquidan a la vez, gana uno solo.
            $tomada = !$sol || sol_transicion($idSol, ['APROBADA'], 'LIQUIDADA');
            if (!$tomada) {
                $errores[] = 'La solicitud ya fue liquidada o cambió de estado recién.';
            }
            for ($intento = 0; $tomada && $intento < 3 && !$idPrestamo; $intento++) {
                $nro = prestamo_proximo_nro($intento);
                try {
                    $args = [$nro, (int) $cliente['id_cliente'], (int) $f['tipo'], (int) $f['vendedor'] ?: null,
                             round($monto, 2), (int) $plazo, $fecha, $primer,
                             $com === null ? null : round($com / 100, 6), (int) $f['telemarketing'], usuario_id()];
                    if (fondos_instalado() && !$conOP) {   // Tesorería sin órdenes de pago: el desembolso entra al libro al liquidar
                        $st = $pdo->prepare('CALL sp_liquidar_prestamo(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @id_prestamo)');
                        $st->execute(array_merge($args, [$cuentasDes ? (int) $des['cuenta'] : null, $des['medio'],
                                                         $des['ref'] !== '' ? mb_substr($des['ref'], 0, 100) : null]));
                    } else {
                        $st = $pdo->prepare('CALL sp_liquidar_prestamo(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @id_prestamo)');
                        $st->execute($args);
                    }
                    while ($st->nextRowset()) {
                    }
                    $st->closeCursor();
                    $idPrestamo = (int) q1('SELECT @id_prestamo AS id')['id'];
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062 && strpos($e->getMessage(), 'uq_prestamos_nro') !== false) {
                        continue;                          // otro liquidó al mismo tiempo: siguiente número
                    }
                    $errores[] = mensaje_bd($e);
                    break;
                }
            }
            if ($idPrestamo) {
                $motivoCtl = controles_hay($ctl, 'advertencia') ? $f['motivo'] : null;
                if ($sol) {
                    $motivoCtl = 'Solicitud ' . $sol['nro_solicitud'] . ' aprobada por ' . ($sol['resuelto_por_nombre'] ?: '—')
                               . ($sol['resolucion'] ? ': ' . $sol['resolucion'] : '');
                    try {
                        q('UPDATE solicitudes SET id_prestamo = ? WHERE id_solicitud = ?', [$idPrestamo, $idSol]);
                        sol_historial($idSol, 'LIQUIDADA', 'Préstamo ' . $nro);
                    } catch (Throwable $e) {
                        error_log('[financiera] no se vinculó la solicitud ' . $idSol . ' con el préstamo ' . $idPrestamo . ': ' . $e->getMessage());
                    }
                }
                try {
                    q('UPDATE prestamos SET controles = ?, controles_motivo = ? WHERE id_prestamo = ?',
                      [json_encode($ctl, JSON_UNESCAPED_UNICODE), $motivoCtl !== null ? substr_utf8($motivoCtl, 255) : null, $idPrestamo]);
                } catch (Throwable $e) {
                    error_log('[financiera] no se guardaron los controles del préstamo ' . $idPrestamo . ': ' . $e->getMessage());
                }
                $op = $conOP ? q1('SELECT nro_orden, importe FROM ordenes_pago WHERE id_prestamo = ?', [$idPrestamo]) : null;
                flash('ok', 'Préstamo ' . $nro . ' liquidado: ' . money($res['capital']) . ' en ' . (int) $res['plazo'] . ' cuotas de ' . money($res['valor_cuota']) . '.'
                    . ($op ? ' El neto (' . money($op['importe']) . ') se paga con la orden ' . $op['nro_orden'] . ': la emite Tesorería en Órdenes de pago.' : ''));
                redirigir('prestamo.php?id=' . $idPrestamo);
            } elseif (!$errores) {
                $errores[] = 'No se pudo asignar un número de préstamo. Probá de nuevo.';
            }
            if ($sol && $tomada) {
                sol_transicion($idSol, ['LIQUIDADA'], 'APROBADA');      // no se liquidó: la solicitud vuelve a estar para liquidar
            }
        }
    }
}

$firma = hash('sha256', implode('|', [$f['cliente'], $f['tipo'], $f['monto'], $f['plazo'], $f['fecha'], $f['primer_vto'], $f['vendedor'], $f['comision'], $f['telemarketing'], (string) $idSol]));
$cft = null;
if ($res && $cuadro) {
    $i = prestamo_tir_mensual((float) $res['neto_desembolso'], array_map(function ($c) { return (float) $c['importe']; }, $cuadro));
    $cft = $i === null ? null : pow(1 + $i, 12) - 1;
}

$tituloPag = $sol ? 'Liquidar la solicitud ' . $sol['nro_solicitud'] : 'Liquidar préstamo';
layout_inicio($tituloPag, 'prestamos');
titulo($tituloPag, $cliente ? $cliente['denominacion'] . ' · ' . cuit_fmt($cliente['cuit']) : 'Solicitud aprobada', '',
       $sol ? '<a href="solicitudes.php">Solicitudes</a> / <a href="solicitud.php?id=' . $idSol . '">' . h($sol['nro_solicitud']) . '</a> / Liquidar'
            : '<a href="prestamos.php">Préstamos</a> / Liquidar');
if ($sol) {
    alerta('Aprobada por ' . ($sol['resuelto_por_nombre'] ?: '—') . ' el ' . fecha_hora($sol['resuelto_el'])
           . ($sol['resolucion'] ? ': ' . $sol['resolucion'] : '.') . ' Elegí la fecha de liquidación, revisá y liquidá.', 'ok');
}
if ($aviso !== '') {
    alerta($aviso, 'warning');
}
mensajes($errores);
?>

<form method="post" action="prestamo_nuevo.php" id="frm-prestamo">
  <?= csrf_campo() ?>
  <input type="hidden" name="firma" value="<?= h($firma) ?>">
  <?php if ($sol): ?><input type="hidden" name="solicitud" value="<?= $idSol ?>"><?php endif; ?>
  <div class="card">
    <?= card_head('Datos del préstamo') ?>
    <div class="bloque">
      <div class="campos">
        <?php if ($cliente): ?>
          <input type="hidden" name="cliente" value="<?= (int) $cliente['id_cliente'] ?>">
          <div class="campo" style="--span:5">
            <span class="campo-label">Cliente</span>
            <div class="campo-fijo">
              <a href="cliente.php?id=<?= (int) $cliente['id_cliente'] ?>"><strong><?= h($cliente['denominacion']) ?></strong></a>
              <span class="mono"><?= h(cuit_fmt($cliente['cuit'])) ?></span>
              <?= $cliente['estado'] !== 'ACTIVO' ? estado(ucfirst(strtolower($cliente['estado'])), 'danger') : '' ?>
              <?php if (!$sol): ?><a href="prestamo_nuevo.php" class="campo-cambiar">cambiar</a><?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="campo" style="--span:4">
            <label for="buscar">Cliente <span class="req">*</span></label>
            <input id="buscar" name="buscar" value="<?= h($f['buscar']) ?>" class="mono" placeholder="CUIT, CUIL o DNI" autofocus>
          </div>
          <div class="campo campo-boton" style="--span:1">
            <span class="campo-label">&nbsp;</span>
            <button type="submit" name="accion" value="buscar" class="btn btn-secondary">Buscar</button>
          </div>
        <?php endif; ?>
        <div class="campo" style="--span:4">
          <label for="tipo">Tipo de asociado <span class="req">*</span></label>
          <select id="tipo" name="tipo" required<?= $bloq ?>>
            <option value="">Elegí…</option>
            <?php foreach ($tipos as $p): ?>
              <option value="<?= (int) $p['id_tipo_asociado'] ?>" <?= (string) $p['id_tipo_asociado'] === $f['tipo'] ? 'selected' : '' ?> <?= $p['con_tasa'] ? '' : 'disabled' ?>
                      data-plazo="<?= (int) $p['plazo_min'] ?>-<?= (int) $p['plazo_max'] ?>" data-monto="<?= h(money($p['monto_min'])) ?> a <?= h(money($p['monto_max'])) ?>">
                <?= h($p['nombre']) ?><?= $p['con_tasa'] ? '' : ' (sin tasa vigente)' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda" id="tipo-ayuda"></span>
        </div>
        <div class="campo" style="--span:3">
          <label for="vendedor">Comercializador</label>
          <select id="vendedor" name="vendedor"<?= $bloq ?>><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $f['vendedor'], 'Sin comercializador') ?></select>
        </div>
        <div class="campo" style="--span:3">
          <label for="monto">Monto a prestar <span class="req">*</span></label>
          <input id="monto" name="monto" value="<?= h($f['monto']) ?>" inputmode="decimal" class="derecha" required placeholder="0,00"<?= $sol ? ' readonly' : '' ?>>
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo">Cuotas <span class="req">*</span></label>
          <input id="plazo" name="plazo" value="<?= h($f['plazo']) ?>" inputmode="numeric" class="derecha" required<?= $sol ? ' readonly' : '' ?>>
        </div>
        <div class="campo" style="--span:2">
          <label for="fecha">Liquidación</label>
          <input type="date" id="fecha" name="fecha" value="<?= h($f['fecha']) ?>">
        </div>
        <div class="campo" style="--span:2">
          <label for="primer_vto">Primer vencimiento</label>
          <input type="date" id="primer_vto" name="primer_vto" value="<?= h($f['primer_vto']) ?>">
          <span class="campo-ayuda">vacío: un mes después</span>
        </div>
        <div class="campo" style="--span:3">
          <label for="comision">Comisión manual (%)</label>
          <input id="comision" name="comision" value="<?= h($f['comision']) ?>" inputmode="decimal" class="derecha" placeholder="según escala">
          <span class="campo-ayuda">solo si es una operación negociada</span>
        </div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="telemarketing" value="1" <?= $f['telemarketing'] === '1' ? 'checked' : '' ?><?= $bloq ?>> Por telemarketing</label>
          <span class="campo-ayuda">define el % de comisión</span>
        </div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Cancelar', 'secondary', $sol ? 'solicitud.php?id=' . $idSol : ($cliente ? 'cliente.php?id=' . (int) $cliente['id_cliente'] : 'prestamos.php'), 'volver') ?>
      <button type="submit" name="accion" value="revisar" class="btn btn-primary"><?= icono('filtro') ?><?= $revisado ? 'Revisar de nuevo' : 'Revisar' ?></button>
    </div>
  </div>

<?php if ($revisado && $res): ?>
  <div class="card">
    <?= card_head('Controles', controles_hay($ctl, 'bloqueo') ? 'no se puede liquidar' : (controles_hay($ctl, 'advertencia') ? 'con advertencias' : 'todo en orden')) ?>
    <div class="bloque"><?= controles_html($ctl) ?></div>
  </div>

  <div class="card">
    <?= card_head('Cuadro de cuotas', (int) $res['plazo'] . ' cuotas · sistema francés') ?>
    <div class="bloque">
      <div class="bcra-tarjetas">
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cuota</span><strong><?= money($res['valor_cuota']) ?></strong><span class="bcra-tarjeta-sub">capital + interés + IVA</span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Neto a desembolsar</span><strong><?= money($res['neto_desembolso']) ?></strong><span class="bcra-tarjeta-sub">gastos descontados <?= money($res['gastos_otorg']) ?><?= (float) ($res['gastos_financiados'] ?? 0) > 0 ? ' · financiados en las cuotas ' . money($res['gastos_financiados']) : '' ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Total a pagar</span><strong><?= money($res['total_a_pagar']) ?></strong><span class="bcra-tarjeta-sub">intereses <?= money($res['total_intereses']) ?> · IVA <?= money($res['total_iva']) ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Tasas</span><strong><?= pct($res['tna']) ?> TNA</strong><span class="bcra-tarjeta-sub">TEA <?= pct($res['tea']) ?><?= $cft !== null ? ' · CFT con IVA ' . pct($cft) : '' ?></span></div>
        <?php if ($comEst): ?>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Comisión del comercializador</span>
          <?php if ($comEst['pct'] === null): ?>
            <strong>Sin comisión</strong><span class="bcra-tarjeta-sub">ninguna escala cubre este monto</span>
          <?php else: ?>
            <strong><?= money($comEst['monto']) ?></strong>
            <span class="bcra-tarjeta-sub"><?= h(escala_pct_campo($comEst['pct'])) ?> % · <?= $comEst['origen'] === 'manual' ? 'manual'
                : ($comEst['origen'] === 'excepción' ? 'excepción del comercializador' : 'escala del tipo de asociado') ?><?= $f['telemarketing'] === '1' && $comEst['origen'] !== 'manual' ? ' (telemarketing)' : '' ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($gastos): ?><div class="bloque"><?= gastos_detalle_html($gastos) ?></div><?php endif; ?>
    <div class="data-table-wrap">
      <table class="data-table" data-sin-orden>
        <thead><tr><th class="th-right" data-no-sort>N°</th><th data-no-sort>Vencimiento</th><th class="th-right" data-no-sort>Capital</th>
          <th class="th-right" data-no-sort>Interés</th><th class="th-right" data-no-sort>IVA</th><th class="th-right" data-no-sort>Cuota</th>
          <th class="th-right" data-no-sort>Saldo de capital</th></tr></thead>
        <tbody>
        <?php foreach ($cuadro as $c): ?>
          <tr><td class="td-right"><?= (int) $c['nro_cuota'] ?></td><td><?= h(fecha($c['vencimiento'])) ?></td>
            <td class="td-right"><?= money($c['capital']) ?></td><td class="td-right"><?= money($c['interes']) ?></td>
            <td class="td-right"><?= money($c['iva_interes']) ?></td><td class="td-right td-fuerte"><?= money($c['importe']) ?></td>
            <td class="td-right"><?= money($c['saldo_capital']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right"><?= money($res['capital_financiado']) ?></td>
          <td class="td-right"><?= money($res['total_intereses']) ?></td><td class="td-right"><?= money($res['total_iva']) ?></td>
          <td class="td-right"><?= money($res['total_a_pagar']) ?></td><td></td></tr></tfoot>
      </table>
    </div>
    <?php if (!controles_hay($ctl, 'bloqueo')): ?>
      <?php if (controles_hay($ctl, 'advertencia') && !$sol): ?>
        <div class="bloque">
          <div class="campos">
            <div class="campo" style="--span:12">
              <label class="campo-check"><input type="checkbox" name="confirmo" value="1" <?= $confirmo ? 'checked' : '' ?>> Revisé las advertencias y el préstamo se liquida igual</label>
            </div>
            <div class="campo" style="--span:12">
              <label for="motivo">Motivo <span class="req">*</span></label>
              <input id="motivo" name="motivo" value="<?= h($f['motivo']) ?>" maxlength="255" placeholder="Por qué se aprueba igual (queda guardado en el préstamo)">
            </div>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($cuentasDes): ?>
        <div class="bloque">
          <div class="bloque-titulo">Desembolso</div>
          <div class="campos">
            <?= campo_cuenta_fondos('cuenta_desembolso', 'Sale de', $cuentasDes, $des['cuenta'], 5, 'sale el neto: ' . money($res['neto_desembolso'])) ?>
            <div class="campo" style="--span:3"><label for="medio_desembolso">Medio</label>
              <select id="medio_desembolso" name="medio_desembolso"><?= opciones(MEDIOS_DESEMBOLSO, $des['medio']) ?></select></div>
            <div class="campo" style="--span:4"><label for="ref_desembolso" id="lbl-ref-des">N° de transferencia</label>
              <input id="ref_desembolso" name="ref_desembolso" value="<?= h($des['ref']) ?>" maxlength="100" class="mono">
              <span class="campo-ayuda" id="ayuda-ref-des"></span></div>
            <?php if ($cliente && columna_existe('clientes', 'cbu')):
                $destino = datos_bancarios_html($cliente['cbu'] ?? null, $cliente['alias_cbu'] ?? null); ?>
            <div class="campo" style="--span:12" id="des-destino">
              <span class="campo-label">Se le transfiere a</span>
              <?php if ($destino !== ''): ?>
                <div><?= $destino ?></div>
              <?php else: ?>
                <div class="texto-error">El cliente no tiene CBU ni alias: cargalos en <a href="cliente.php?id=<?= (int) $cliente['id_cliente'] ?>" target="_blank" rel="noopener">su ficha</a> antes de transferirle.</div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <script>
        (function () {
          var m = document.getElementById('medio_desembolso'), l = document.getElementById('lbl-ref-des'), a = document.getElementById('ayuda-ref-des');
          function poner() {
            l.textContent = m.value === 'CHEQUE' ? 'N° de cheque' : (m.value === 'EFECTIVO' ? 'Referencia' : 'N° de transferencia');
            a.textContent = m.value === 'CHEQUE' ? 'de una chequera de la cuenta (Cuentas de fondos › Chequeras)' : '';
            var d = document.getElementById('des-destino');
            if (d) d.classList.toggle('oculto', m.value !== 'TRANSFERENCIA');
          }
          m.addEventListener('change', poner);
          poner();
        })();
        </script>
      <?php endif; ?>
      <?php if ($conOP): ?>
        <div class="bloque">
          <div class="bloque-titulo">Desembolso</div>
          <div class="campos">
            <div class="campo" style="--span:12"><span class="campo-label">Se paga con una orden de pago</span>
              <div>El neto, <strong><?= money($res['neto_desembolso']) ?></strong>, queda en una orden de pago a nombre del cliente. Tesorería la paga en <em>Órdenes de pago</em> (transferencia, cheque o efectivo) y ahí sale la plata de la cuenta.</div></div>
            <?php if ($cliente && columna_existe('clientes', 'cbu')):
                $destino = datos_bancarios_html($cliente['cbu'] ?? null, $cliente['alias_cbu'] ?? null); ?>
            <div class="campo" style="--span:12"><span class="campo-label">Cuenta del cliente</span>
              <?php if ($destino !== ''): ?>
                <div><?= $destino ?></div>
              <?php else: ?>
                <div class="texto-error">El cliente no tiene CBU ni alias: cargalos en <a href="cliente.php?id=<?= (int) $cliente['id_cliente'] ?>" target="_blank" rel="noopener">su ficha</a> para que se le pueda transferir.</div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <div class="form-acciones">
        <span class="campo-ayuda" style="margin-right:auto">Se liquida con el número <?= h(prestamo_proximo_nro()) ?> (o el siguiente libre).</span>
        <button type="submit" name="accion" value="liquidar" class="btn btn-ok"><?= icono('guardar') ?>Liquidar <?= money($res['capital']) ?></button>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</form>

<script>
(function () {
  var sel = document.getElementById('tipo'), ayuda = document.getElementById('tipo-ayuda');
  function mostrar() {
    var op = sel.options[sel.selectedIndex];
    ayuda.textContent = op && op.value ? op.getAttribute('data-plazo').replace('-', ' a ') + ' cuotas · ' + op.getAttribute('data-monto') : '';
  }
  sel.addEventListener('change', mostrar);
  mostrar();
})();
</script>

<?php layout_fin(); ?>
