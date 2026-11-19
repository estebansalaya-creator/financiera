<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/prestamos.php';

// ---------------------------------------------------------------------
// Nuevo préstamo (liquidación)
//   1. Revisar: simula con el mismo motor que la liquidación y corre los
//      controles (BCRA, cheques, documentación, mora, cuota / ingreso).
//      Si la consulta al BCRA es vieja, la hace.
//   2. Liquidar: vuelve a simular y a controlar en el servidor (no se
//      confía en lo que vino del navegador). Con advertencias, pide
//      confirmar y el motivo; con bloqueos, no deja.
//   Llama a sp_liquidar_prestamo y guarda los controles en el préstamo.
//   prestamo_nuevo.php?cliente=N   viene desde la ficha del cliente
// ---------------------------------------------------------------------

$errores = [];
$aviso   = '';
$cliente = null;
$res     = null;
$cuadro  = [];
$ctl     = [];
$revisado = false;

$campos = ['cliente', 'buscar', 'producto', 'monto', 'plazo', 'fecha', 'primer_vto', 'vendedor', 'comision', 'telemarketing', 'motivo'];
$f = [];
foreach ($campos as $c) {
    $f[$c] = es_post() ? post_str($c) : get_str($c);
}
$f['fecha'] = $f['fecha'] ?: date('Y-m-d');
$f['telemarketing'] = $f['telemarketing'] === '1' ? '1' : '0';
$confirmo = es_post() && post_str('confirmo') === '1';
$accion = es_post() ? post_str('accion') : '';

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
    $productos = q("SELECT p.*, EXISTS(SELECT 1 FROM tasas_vigencia t WHERE t.id_producto = p.id_producto
                                        AND t.vigencia_desde <= CURDATE()) AS con_tasa
                      FROM productos p WHERE p.activo = 1 ORDER BY p.nombre");
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores WHERE activo = 1 ORDER BY nombre');

    if ($f['cliente'] !== '' || $f['buscar'] !== '') {
        $encontrados = buscar_cliente($f['cliente'], $f['buscar']);
        if (count($encontrados) === 1) {
            $cliente = $encontrados[0];
            $f['cliente'] = (string) $cliente['id_cliente'];
            if ($f['vendedor'] === '' && !es_post()) {
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
    $productos = $vendedores = [];
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
    if ((int) $f['producto'] <= 0) {
        $errores[] = 'Elegí el producto.';
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
            [$res, $cuadro] = prestamo_simular((int) $f['producto'], $monto, (int) $plazo, $fecha, $primer);
            [$ctl] = prestamo_controles($cliente, (float) $res['valor_cuota']);
            $revisado = true;
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
            $res = null;
        }
    }

    // Lo que se revisó tiene que ser lo que se liquida
    $firma = hash('sha256', implode('|', [$f['cliente'], $f['producto'], $f['monto'], $f['plazo'], $f['fecha'], $f['primer_vto'], $f['vendedor'], $f['comision'], $f['telemarketing']]));
    if ($accion === 'liquidar' && $revisado && post_str('firma') !== $firma) {
        $aviso = 'Cambiaste datos después de revisar: mirá de nuevo el cuadro y los controles antes de liquidar.';
        $accion = 'revisar';
    }

    if ($accion === 'liquidar' && $revisado) {
        if (controles_hay($ctl, 'bloqueo')) {
            $errores[] = 'Hay controles que impiden liquidar.';
        } elseif (controles_hay($ctl, 'advertencia') && (!$confirmo || strlen($f['motivo']) < 10)) {
            $errores[] = 'Hay advertencias: para liquidar igual, tildá la confirmación y escribí el motivo (al menos 10 letras).';
        } else {
            $pdo = db();
            $idPrestamo = 0;
            for ($intento = 0; $intento < 3 && !$idPrestamo; $intento++) {
                $nro = prestamo_proximo_nro($intento);
                try {
                    $st = $pdo->prepare('CALL sp_liquidar_prestamo(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @id_prestamo)');
                    $st->execute([$nro, (int) $cliente['id_cliente'], (int) $f['producto'], (int) $f['vendedor'] ?: null,
                                  round($monto, 2), (int) $plazo, $fecha, $primer,
                                  $com === null ? null : round($com / 100, 6), (int) $f['telemarketing'], usuario_id()]);
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
                try {
                    q('UPDATE prestamos SET controles = ?, controles_motivo = ? WHERE id_prestamo = ?',
                      [json_encode($ctl, JSON_UNESCAPED_UNICODE), controles_hay($ctl, 'advertencia') ? substr($f['motivo'], 0, 255) : null, $idPrestamo]);
                } catch (Throwable $e) {
                    error_log('[financiera] no se guardaron los controles del préstamo ' . $idPrestamo . ': ' . $e->getMessage());
                }
                flash('ok', 'Préstamo ' . $nro . ' liquidado: ' . money($res['capital']) . ' en ' . (int) $res['plazo'] . ' cuotas de ' . money($res['valor_cuota']) . '.');
                redirigir('prestamo.php?id=' . $idPrestamo);
            } elseif (!$errores) {
                $errores[] = 'No se pudo asignar un número de préstamo. Probá de nuevo.';
            }
        }
    }
}

$firma = hash('sha256', implode('|', [$f['cliente'], $f['producto'], $f['monto'], $f['plazo'], $f['fecha'], $f['primer_vto'], $f['vendedor'], $f['comision'], $f['telemarketing']]));
$cft = null;
if ($res && $cuadro) {
    $i = prestamo_tir_mensual((float) $res['neto_desembolso'], array_map(function ($c) { return (float) $c['importe']; }, $cuadro));
    $cft = $i === null ? null : pow(1 + $i, 12) - 1;
}

layout_inicio('Nuevo préstamo', 'prestamos');
titulo('Nuevo préstamo', $cliente ? $cliente['denominacion'] . ' · ' . cuit_fmt($cliente['cuit']) : 'Liquidación con controles previos', '',
       '<a href="prestamos.php">Préstamos</a> / Nuevo');
if ($aviso !== '') {
    alerta($aviso, 'warning');
}
mensajes($errores);
?>

<form method="post" action="prestamo_nuevo.php" id="frm-prestamo">
  <?= csrf_campo() ?>
  <input type="hidden" name="firma" value="<?= h($firma) ?>">
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
              <a href="prestamo_nuevo.php" class="campo-cambiar">cambiar</a>
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
          <label for="producto">Producto <span class="req">*</span></label>
          <select id="producto" name="producto" required>
            <option value="">Elegí…</option>
            <?php foreach ($productos as $p): ?>
              <option value="<?= (int) $p['id_producto'] ?>" <?= (string) $p['id_producto'] === $f['producto'] ? 'selected' : '' ?> <?= $p['con_tasa'] ? '' : 'disabled' ?>
                      data-plazo="<?= (int) $p['plazo_min'] ?>-<?= (int) $p['plazo_max'] ?>" data-monto="<?= h(money($p['monto_min'])) ?> a <?= h(money($p['monto_max'])) ?>">
                <?= h($p['nombre']) ?><?= $p['con_tasa'] ? '' : ' (sin tasa vigente)' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda" id="prod-ayuda"></span>
        </div>
        <div class="campo" style="--span:3">
          <label for="vendedor">Vendedor</label>
          <select id="vendedor" name="vendedor"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $f['vendedor'], 'Sin vendedor') ?></select>
        </div>
        <div class="campo" style="--span:3">
          <label for="monto">Monto a prestar <span class="req">*</span></label>
          <input id="monto" name="monto" value="<?= h($f['monto']) ?>" inputmode="decimal" class="derecha" required placeholder="0,00">
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo">Cuotas <span class="req">*</span></label>
          <input id="plazo" name="plazo" value="<?= h($f['plazo']) ?>" inputmode="numeric" class="derecha" required>
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
          <input id="comision" name="comision" value="<?= h($f['comision']) ?>" inputmode="decimal" class="derecha" placeholder="según reglas">
          <span class="campo-ayuda">solo si es una operación negociada</span>
        </div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="telemarketing" value="1" <?= $f['telemarketing'] === '1' ? 'checked' : '' ?>> Por telemarketing</label>
          <span class="campo-ayuda">define el % de comisión</span>
        </div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Cancelar', 'secondary', $cliente ? 'cliente.php?id=' . (int) $cliente['id_cliente'] : 'prestamos.php', 'volver') ?>
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
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Neto a desembolsar</span><strong><?= money($res['neto_desembolso']) ?></strong><span class="bcra-tarjeta-sub">gastos <?= money($res['gastos_otorg']) ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Total a pagar</span><strong><?= money($res['total_a_pagar']) ?></strong><span class="bcra-tarjeta-sub">intereses <?= money($res['total_intereses']) ?> · IVA <?= money($res['total_iva']) ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Tasas</span><strong><?= pct($res['tna']) ?> TNA</strong><span class="bcra-tarjeta-sub">TEA <?= pct($res['tea']) ?><?= $cft !== null ? ' · CFT con IVA ' . pct($cft) : '' ?></span></div>
      </div>
    </div>
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
        <tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td class="td-right"><?= money($res['capital']) ?></td>
          <td class="td-right"><?= money($res['total_intereses']) ?></td><td class="td-right"><?= money($res['total_iva']) ?></td>
          <td class="td-right"><?= money($res['total_a_pagar']) ?></td><td></td></tr></tfoot>
      </table>
    </div>
    <?php if (!controles_hay($ctl, 'bloqueo')): ?>
      <?php if (controles_hay($ctl, 'advertencia')): ?>
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
  var sel = document.getElementById('producto'), ayuda = document.getElementById('prod-ayuda');
  function mostrar() {
    var op = sel.options[sel.selectedIndex];
    ayuda.textContent = op && op.value ? op.getAttribute('data-plazo').replace('-', ' a ') + ' cuotas · ' + op.getAttribute('data-monto') : '';
  }
  sel.addEventListener('change', mostrar);
  mostrar();
})();
</script>

<?php layout_fin(); ?>
