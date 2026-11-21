<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Movimientos entre cuentas propias (Tesorería): depósitos, extracciones,
// transferencias entre cuentas e inversiones (plazo fijo).
//   movimientos_cuentas.php             listado (el mes en curso)
//   movimientos_cuentas.php?nuevo=1     alta, arriba del listado
//   movimientos_cuentas.php?id=N        ficha: renglones del libro, lo que
//                                       informa el banco y la anulación
//   …?id=N&export=pdf                   constancia en PDF
//   …?export=xlsx                       el listado con los filtros, en Excel
// No hay tercero: la plata cambia de cuenta. El tipo se deduce de las
// cuentas (tipo_mov_cuentas). La cuenta que entrega va al haber del libro y
// la que recibe al debe; las dos tienen que ser de la misma moneda.
// ---------------------------------------------------------------------

$errores = [];
$hoy     = date('Y-m-d');
$id      = (int) get_str('id');
$nuevo   = get_str('nuevo') === '1';
$f = ['fecha' => $hoy, 'origen' => get_str('origen'), 'destino' => '', 'importe' => '', 'referencia' => '', 'comentario' => ''];

$cuentas = [];
try {
    $cuentas = cuentas_fondos();
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de Tesorería: corré sql\financiera_fondos.sql.' : error_amigable($e);
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'nuevo') {
        $nuevo = true;
        $f = ['fecha' => post_str('fecha'), 'origen' => post_str('origen'), 'destino' => post_str('destino'), 'importe' => post_str('importe'),
              'referencia' => post_str('referencia'), 'comentario' => post_str('comentario')];
        $fecha = leer_fecha($f['fecha']);
        $imp = leer_num($f['importe']);
        $o = $cuentas[(int) $f['origen']] ?? null;
        $d = $cuentas[(int) $f['destino']] ?? null;
        if ($fecha === null || $fecha > $hoy) {
            $errores[] = 'La fecha no es válida (no puede ser futura).';
        }
        if (!$o || !$d) {
            $errores[] = 'Elegí la cuenta de la que sale la plata y la que la recibe.';
        } elseif ($o['id_cuenta_fondos'] === $d['id_cuenta_fondos']) {
            $errores[] = 'La cuenta de origen y la de destino no pueden ser la misma.';
        } elseif ($o['moneda'] !== $d['moneda']) {
            $errores[] = 'Las dos cuentas tienen que ser de la misma moneda (' . $o['nombre'] . ' es en ' . strtolower(MONEDAS[$o['moneda']])
                       . ' y ' . $d['nombre'] . ' en ' . strtolower(MONEDAS[$d['moneda']]) . ').';
        }
        if ($imp === null || $imp <= 0) {
            $errores[] = 'Escribí el importe.';
        }
        if (!$errores) {
            $tipo = tipo_mov_cuentas($o['tipo'], $d['tipo']);
            $pdo = db();
            try {
                $pdo->beginTransaction();
                q('INSERT INTO movimientos_entre_cuentas (fecha, tipo, id_cuenta_origen, id_cuenta_destino, importe, referencia, comentario, creado_por)
                   VALUES (?,?,?,?,?,?,?,?)',
                  [$fecha, $tipo, (int) $o['id_cuenta_fondos'], (int) $d['id_cuenta_fondos'], round($imp, 2),
                   $f['referencia'] !== '' ? mb_substr($f['referencia'], 0, 100) : null, $f['comentario'] !== '' ? mb_substr($f['comentario'], 0, 250) : null, usuario_id()]);
                $idNuevo = (int) $pdo->lastInsertId();
                fondos_call('CALL sp_fondos_movimiento(?)', [$idNuevo]);
                $pdo->commit();
                flash('ok', TIPOS_MOV_CUENTAS[$tipo] . ' N° ' . $idNuevo . ' registrado: ' . dinero($imp, $o['moneda']) . ' de ' . $o['nombre'] . ' a ' . $d['nombre'] . '.');
                redirigir('movimientos_cuentas.php?id=' . $idNuevo);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e);
            }
        }
    } elseif ($accion === 'anular' && $id > 0) {
        $mot = post_str('motivo');
        $pdo = db();
        try {
            if (mb_strlen($mot) < 5) {
                throw new RuntimeException('Escribí el motivo de la anulación.');
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare("UPDATE movimientos_entre_cuentas SET estado = 'ANULADO', anulado_el = NOW(), anulado_por = ?, motivo_anulacion = ?
                                  WHERE id_mov_cuentas = ? AND estado = 'VIGENTE'");
            $st->execute([usuario_id(), mb_substr($mot, 0, 200), $id]);
            if (!$st->rowCount()) {
                throw new RuntimeException('El movimiento ya estaba anulado.');
            }
            fondos_call('CALL sp_fondos_anular(?, ?, ?, ?)', ['MOVIMIENTO', $id, mb_substr($mot, 0, 200), usuario_id()]);
            $pdo->commit();
            flash('ok', 'Movimiento N° ' . $id . ' anulado: ya no suma en el saldo de las cuentas.');
            redirigir('movimientos_cuentas.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : mensaje_bd($e);
        }
    }
}

// ---------------------------------------------------------------------
// Ficha de un movimiento
// ---------------------------------------------------------------------
$mov = null;
$libro = $banco = [];
if ($id > 0) {
    try {
        $mov = q1('SELECT x.*, o.nombre AS origen, o.tipo AS tipo_origen, o.moneda, d.nombre AS destino, d.tipo AS tipo_destino,
                          u.nombre AS creado_por_nombre, ua.nombre AS anulado_por_nombre
                     FROM movimientos_entre_cuentas x
                     JOIN cuentas_fondos o ON o.id_cuenta_fondos = x.id_cuenta_origen
                     JOIN cuentas_fondos d ON d.id_cuenta_fondos = x.id_cuenta_destino
                     LEFT JOIN usuarios u  ON u.id_usuario  = x.creado_por
                     LEFT JOIN usuarios ua ON ua.id_usuario = x.anulado_por
                    WHERE x.id_mov_cuentas = ?', [$id]);
        if ($mov) {
            $libro = q('SELECT m.*, c.nombre AS cuenta, c.moneda FROM movimientos_fondos m JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos
                         WHERE m.id_mov_cuentas = ? ORDER BY m.importe_haber DESC', [$id]);
            $banco = q('SELECT d.*, c.nombre AS cuenta, c.moneda FROM debitos_creditos_bancarios d
                          JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
                          JOIN cuentas_fondos c ON c.id_cuenta_fondos = m.id_cuenta_fondos
                         WHERE m.id_mov_cuentas = ? ORDER BY d.id_deb_cred', [$id]);
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }
    if (!$mov && !$errores) {
        flash('warning', 'Ese movimiento no existe.');
        redirigir('movimientos_cuentas.php');
    }
    if ($mov && get_str('export') === 'pdf') {
        require_once __DIR__ . '/../inc/pdf.php';
        $rep = new PdfReporte(TIPOS_MOV_CUENTAS[$mov['tipo']] . ' N° ' . $mov['id_mov_cuentas'] . ($mov['estado'] === 'ANULADO' ? ' · ANULADO' : ''),
                              'Movimiento entre cuentas propias del ' . fecha($mov['fecha']), false);
        $rep->indicadores([['Importe', dinero($mov['importe'], $mov['moneda'])], ['Fecha', fecha($mov['fecha'])], ['Tipo', TIPOS_MOV_CUENTAS[$mov['tipo']]]]);
        $rep->seccion('Cuentas');
        $rep->tabla([['Cuenta', 4, 'L'], ['Sale', 1.6, 'R'], ['Entra', 1.6, 'R']],
                    array_map(function ($m) {
                        return [$m['cuenta'], (float) $m['importe_haber'] ? dinero($m['importe_haber'], $m['moneda']) : '',
                                (float) $m['importe_debe'] ? dinero($m['importe_debe'], $m['moneda']) : ''];
                    }, $libro));
        if ($mov['referencia']) {
            $rep->nota('Referencia: ' . $mov['referencia']);
        }
        if ($mov['comentario']) {
            $rep->nota('Comentario: ' . $mov['comentario']);
        }
        $rep->nota('Registrado el ' . date('d/m/Y H:i', strtotime($mov['creado_el'])) . ($mov['creado_por_nombre'] ? ' por ' . $mov['creado_por_nombre'] : '') . '.');
        if ($mov['estado'] === 'ANULADO') {
            $rep->nota('Anulado el ' . date('d/m/Y H:i', strtotime($mov['anulado_el'])) . ($mov['anulado_por_nombre'] ? ' por ' . $mov['anulado_por_nombre'] : '')
                       . ': ' . $mov['motivo_anulacion']);
        }
        $rep->enviar('movimiento_cuentas_' . $mov['id_mov_cuentas'] . '.pdf');
        exit;
    }
}

// ---------------------------------------------------------------------
// Listado
// ---------------------------------------------------------------------
$fDesde  = array_key_exists('desde', $_GET) ? (leer_fecha(get_str('desde')) ?? '') : date('Y-m-01');
$fHasta  = array_key_exists('hasta', $_GET) ? (leer_fecha(get_str('hasta')) ?? '') : $hoy;
$fCuenta = (int) get_str('cuenta');
$fTipo   = isset(TIPOS_MOV_CUENTAS[get_str('tipo')]) ? get_str('tipo') : '';
$fEstado = in_array(get_str('estado'), ['VIGENTE', 'ANULADO'], true) ? get_str('estado') : '';
$porPag  = por_pagina(50);
$pagina  = max(1, (int) get_str('p'));
$where = ['1=1'];
$params = [];
if ($fDesde !== '') {
    $where[] = 'x.fecha >= ?';
    $params[] = $fDesde;
}
if ($fHasta !== '') {
    $where[] = 'x.fecha <= ?';
    $params[] = $fHasta;
}
if ($fCuenta) {
    $where[] = '? IN (x.id_cuenta_origen, x.id_cuenta_destino)';
    $params[] = $fCuenta;
}
if ($fTipo !== '') {
    $where[] = 'x.tipo = ?';
    $params[] = $fTipo;
}
if ($fEstado !== '') {
    $where[] = 'x.estado = ?';
    $params[] = $fEstado;
}
$desdeSql = 'FROM movimientos_entre_cuentas x
             JOIN cuentas_fondos o ON o.id_cuenta_fondos = x.id_cuenta_origen
             JOIN cuentas_fondos d ON d.id_cuenta_fondos = x.id_cuenta_destino
            WHERE ' . implode(' AND ', $where);
// Orden por encabezado (en la base): la grilla y el Excel salen igual.
$orden = orden_grilla(['nro' => 'x.id_mov_cuentas', 'fecha' => 'x.fecha', 'tipo' => 'CAST(x.tipo AS CHAR)', 'cuentas' => 'o.nombre',
                       'importe' => 'x.importe', 'estado' => 'x.estado'], 'fecha', 'desc', 'x.id_mov_cuentas');
$lista = [];
$tot = ['n' => 0];
$paginas = 1;
$todasCuentas = [];
try {
    if (!$errores || $cuentas) {
        $todasCuentas = cuentas_fondos(null, true);
        $tot = q1("SELECT COUNT(*) AS n FROM movimientos_entre_cuentas x WHERE " . implode(' AND ', $where), $params) ?: ['n' => 0];
        if (!$mov && get_str('export') === 'xlsx') {
            require_once __DIR__ . '/../inc/xlsx.php';
            $todas = q("SELECT x.*, o.nombre AS origen, o.moneda, d.nombre AS destino $desdeSql ORDER BY {$orden['sql']}", $params);
            $libro = new XlsxLibro();
            $libro->hoja('Movimientos entre cuentas')->titulo('Movimientos entre cuentas',
                    ($fDesde !== '' ? 'Del ' . fecha($fDesde) : 'Desde el principio') . ($fHasta !== '' ? ' al ' . fecha($fHasta) : '') . ' · '
                    . num($tot['n']) . ' movimientos · generado el ' . date('d/m/Y H:i'))
                ->tabla([['N°', 'entero', 8], ['Fecha', 'fecha', 12], ['Tipo', 'texto', 22], ['Sale de', 'texto', 26], ['Entra en', 'texto', 26],
                         ['Moneda', 'centro', 8], ['Importe', 'moneda', 16], ['Referencia', 'texto', 20], ['Comentario', 'texto', 40], ['Estado', 'texto', 10]],
                        array_map(function ($x) {
                            return [(int) $x['id_mov_cuentas'], $x['fecha'], TIPOS_MOV_CUENTAS[$x['tipo']] ?? $x['tipo'], $x['origen'], $x['destino'],
                                    $x['moneda'], (float) $x['importe'], (string) $x['referencia'], (string) $x['comentario'],
                                    $x['estado'] === 'ANULADO' ? 'Anulado' : 'Vigente'];
                        }, $todas));
            $libro->enviar('movimientos_entre_cuentas_' . date('Ymd') . '.xlsx');
            exit;
        }
        $paginas = max(1, (int) ceil($tot['n'] / $porPag));
        $pagina = min($pagina, $paginas);
        $lista = q("SELECT x.*, o.nombre AS origen, o.moneda, d.nombre AS destino $desdeSql
                     ORDER BY {$orden['sql']} LIMIT ? OFFSET ?", array_merge($params, [$porPag, ($pagina - 1) * $porPag]));
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}
$hayFiltros = $fCuenta || $fTipo !== '' || $fEstado !== '';

layout_inicio($mov ? 'Movimiento N° ' . $mov['id_mov_cuentas'] : 'Movimientos entre cuentas');
titulo($mov ? TIPOS_MOV_CUENTAS[$mov['tipo']] . ' N° ' . $mov['id_mov_cuentas'] : 'Movimientos entre cuentas',
       $mov ? $mov['origen'] . ' → ' . $mov['destino'] . ' · ' . fecha($mov['fecha'])
            : 'Depósitos, extracciones, transferencias entre cuentas propias e inversiones',
       $mov ? boton('PDF', 'pdf', 'movimientos_cuentas.php?id=' . $mov['id_mov_cuentas'] . '&export=pdf', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener'])
              . boton('Volver', 'secondary', 'movimientos_cuentas.php', 'volver')
            : ($nuevo ? '' : boton('Nuevo movimiento', 'primary', 'movimientos_cuentas.php?nuevo=1', 'agregar')),
       '<a href="movimientos_fondos.php">Tesorería</a> / ' . ($mov ? '<a href="movimientos_cuentas.php">Movimientos entre cuentas</a> / N° ' . (int) $mov['id_mov_cuentas'] : 'Movimientos entre cuentas'));
mensajes($errores);

if ($mov):
    $mon = $mov['moneda'];
?>
<div class="columnas-2-1">
  <div>
    <div class="card">
      <?= card_head('Movimiento', '', $mov['estado'] === 'ANULADO' ? estado('Anulado', 'neutro') : estado('Vigente', 'ok')) ?>
      <div class="bloque">
        <div class="bcra-tarjetas">
          <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Importe</span><strong><?= dinero($mov['importe'], $mon) ?></strong><span class="bcra-tarjeta-sub"><?= h(TIPOS_MOV_CUENTAS[$mov['tipo']]) ?></span></div>
          <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Sale de</span><strong style="font-size:1rem"><?= h($mov['origen']) ?></strong><span class="bcra-tarjeta-sub"><?= h(TIPOS_CUENTA[$mov['tipo_origen']] ?? '') ?></span></div>
          <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Entra en</span><strong style="font-size:1rem"><?= h($mov['destino']) ?></strong><span class="bcra-tarjeta-sub"><?= h(TIPOS_CUENTA[$mov['tipo_destino']] ?? '') ?></span></div>
        </div>
        <p class="nota-arbol" style="margin-top:12px">
          Fecha <?= h(fecha($mov['fecha'])) ?><?= $mov['referencia'] ? ' · referencia ' . h($mov['referencia']) : '' ?><?= $mov['comentario'] ? ' · ' . h($mov['comentario']) : '' ?>.
          Registrado el <?= h(date('d/m/Y H:i', strtotime($mov['creado_el']))) ?><?= $mov['creado_por_nombre'] ? ' por ' . h($mov['creado_por_nombre']) : '' ?>.
          <?php if ($mov['estado'] === 'ANULADO'): ?><br><strong>Anulado</strong> el <?= h(date('d/m/Y H:i', strtotime($mov['anulado_el']))) ?><?= $mov['anulado_por_nombre'] ? ' por ' . h($mov['anulado_por_nombre']) : '' ?>: <?= h($mov['motivo_anulacion']) ?><?php endif; ?>
        </p>
      </div>
    </div>

    <div class="card">
      <?= card_head('En el libro de fondos', 'un renglón por cuenta') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden>
          <thead><tr><th data-no-sort>Cuenta</th><th class="th-right" data-no-sort>Sale</th><th class="th-right" data-no-sort>Entra</th></tr></thead>
          <tbody>
          <?php foreach ($libro as $m): ?>
            <tr<?= $m['anulado_el'] ? ' class="fila-baja"' : '' ?>><td><a href="movimientos_fondos.php?cuenta=<?= (int) $m['id_cuenta_fondos'] ?>&amp;desde=<?= h($mov['fecha']) ?>&amp;hasta=<?= h($mov['fecha']) ?>"><?= h($m['cuenta']) ?></a></td>
              <td class="td-right"><?= (float) $m['importe_haber'] ? dinero($m['importe_haber'], $m['moneda']) : '' ?></td>
              <td class="td-right"><?= (float) $m['importe_debe'] ? dinero($m['importe_debe'], $m['moneda']) : '' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$libro): ?><tr><td colspan="3" class="no-data">Sin renglones en el libro.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <?= card_head('Lo que va a informar el banco', $banco ? '' : 'ninguna de las cuentas es un banco') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-sin-orden>
          <thead><tr><th data-no-sort>Cuenta</th><th data-no-sort>Instrumento</th><th class="th-right" data-no-sort>Importe</th><th data-no-sort>Estado</th></tr></thead>
          <tbody>
          <?php foreach ($banco as $d): ?>
            <tr><td><?= h($d['cuenta']) ?></td><td><?= h(INSTRUMENTOS[$d['tipo_instrumento']] ?? $d['tipo_instrumento']) ?><?= $d['nro_referencia'] ? '<span class="segunda-linea">' . h($d['nro_referencia']) . '</span>' : '' ?></td>
              <td class="td-right"><?= dinero((float) $d['importe_debito'] ?: $d['importe_credito'], $d['moneda']) ?><span class="segunda-linea"><?= (float) $d['importe_debito'] ? 'débito' : 'crédito' ?></span></td>
              <td><?= estado_deb_cred($d) ?><?= $d['fecha_banco'] ? '<span class="segunda-linea">' . h(fecha($d['fecha_banco'])) . '</span>' : '' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$banco): ?><tr><td colspan="4" class="no-data">—</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($mov['estado'] === 'VIGENTE'): ?>
    <div class="card">
      <?= card_head('Anular', 'solo para errores de carga') ?>
      <form method="post" action="movimientos_cuentas.php?id=<?= (int) $mov['id_mov_cuentas'] ?>" class="bloque"
            data-confirmar="Se anula el movimiento N° <?= (int) $mov['id_mov_cuentas'] ?> de <?= h(dinero($mov['importe'], $mon)) ?>: deja de sumar en el saldo de <?= h($mov['origen']) ?> y de <?= h($mov['destino']) ?>."
            data-confirmar-titulo="Anular el movimiento" data-confirmar-boton="Anular" data-peligro>
        <?= csrf_campo() ?><input type="hidden" name="accion" value="anular">
        <div class="campos">
          <div class="campo" style="--span:12"><label for="motivo">Motivo <span class="req">*</span></label>
            <input id="motivo" name="motivo" maxlength="200" required minlength="5"></div>
        </div>
        <div class="form-acciones"><?= boton('Anular movimiento', 'danger', null, 'borrar', true) ?></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<?php if ($nuevo): ?>
<form method="post" action="movimientos_cuentas.php" id="frm-mov" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="nuevo">
  <div class="card">
    <?= card_head('Nuevo movimiento entre cuentas', '', boton('Cancelar', 'secondary', 'movimientos_cuentas.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2"><label for="fecha">Fecha <span class="req">*</span></label>
          <input type="date" id="fecha" name="fecha" value="<?= h($f['fecha']) ?>" max="<?= $hoy ?>" required></div>
        <?= campo_cuenta_fondos('origen', 'Sale de', $cuentas, $f['origen'], 4) ?>
        <?= campo_cuenta_fondos('destino', 'Entra en', $cuentas, $f['destino'], 4) ?>
        <div class="campo" style="--span:2"><label for="importe">Importe <span class="req">*</span></label>
          <input id="importe" name="importe" value="<?= h($f['importe']) ?>" inputmode="decimal" class="derecha" required placeholder="0,00"></div>
        <div class="campo" style="--span:12"><span class="campo-label">Tipo</span>
          <div class="campo-fijo" id="tipo-mov">Elegí las dos cuentas</div></div>
        <div class="campo" style="--span:4"><label for="referencia">Referencia</label>
          <input id="referencia" name="referencia" value="<?= h($f['referencia']) ?>" maxlength="100" placeholder="N° de transferencia, de boleta de depósito…"></div>
        <div class="campo" style="--span:8"><label for="comentario">Comentario</label>
          <input id="comentario" name="comentario" value="<?= h($f['comentario']) ?>" maxlength="250"></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Registrar movimiento', 'ok', null, 'guardar', true) ?></div>
  </div>
</form>
<script>
(function () {
  var o = document.getElementById('cf-origen'), d = document.getElementById('cf-destino'), t = document.getElementById('tipo-mov'),
      form = document.getElementById('frm-mov'), imp = document.getElementById('importe');
  var nombres = <?= json_encode(TIPOS_MOV_CUENTAS, JSON_UNESCAPED_UNICODE) ?>;
  function dato(sel, k) { var op = sel.options[sel.selectedIndex]; return op && op.value ? op.getAttribute('data-' + k) : null; }
  function tipo(a, b) {
    if (a === 'I' || b === 'I') return 'INVERSION';
    if (a === 'B' && b === 'C') return 'EXTRACCION';
    if ((a === 'C' || a === 'R') && b === 'B') return 'DEPOSITO';
    return 'ENTRE_CUENTAS';
  }
  function revisar() {
    // El destino solo ofrece cuentas de la misma moneda que el origen, y no la misma cuenta.
    var mon = dato(o, 'moneda');
    Array.prototype.forEach.call(d.options, function (op) {
      if (!op.value) return;
      op.disabled = op.value === o.value || (mon !== null && op.getAttribute('data-moneda') !== mon);
    });
    if (d.value && d.options[d.selectedIndex].disabled) d.value = '';
    if (!o.value || !d.value) { t.textContent = 'Elegí las dos cuentas'; form.removeAttribute('data-confirmar'); return; }
    var n = nombres[tipo(dato(o, 'tipo'), dato(d, 'tipo'))];
    t.textContent = n;
    form.setAttribute('data-confirmar', n + ' de ' + (mon === 'USD' ? 'US$ ' : '$ ') + (imp.value || '0') + ': sale de '
      + o.options[o.selectedIndex].text + ' y entra en ' + d.options[d.selectedIndex].text + '.');
    form.setAttribute('data-confirmar-titulo', 'Registrar el movimiento');
    form.setAttribute('data-confirmar-boton', 'Registrar');
  }
  o.addEventListener('change', revisar);
  d.addEventListener('change', revisar);
  imp.addEventListener('input', revisar);
  revisar();
})();
</script>
<?php endif; ?>

<div class="card">
  <form method="get" class="filter-bar">
    <?= orden_ocultos() ?>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $todasCuentas, $fCuenta ?: '', 'Todas', ['class' => 'filtro' . ($fCuenta ? ' activo' : ''), 'onchange' => 'this.form.submit()']) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Tipo</span>
      <select name="tipo" class="filtro <?= $fTipo !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(TIPOS_MOV_CUENTAS, $fTipo, 'Todos') ?></select></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['VIGENTE' => 'Vigentes', 'ANULADO' => 'Anulados'], $fEstado, 'Todos') ?></select></label>
    <?= filtro_botones('movimientos_cuentas.php', $hayFiltros || $fDesde !== date('Y-m-01') || $fHasta !== $hoy) ?>
    <?= filtro_fin(['total' => (int) $tot['n'], 'pagina' => $pagina, 'por_pagina' => $porPag, 'unidad' => 'movimientos',
                    'excel' => url_con(['export' => 'xlsx', 'p' => null, 'nuevo' => null]), 'filas' => true]) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-paginar>
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><?= th_orden('N°', 'nro', $orden) ?><?= th_orden('Fecha', 'fecha', $orden) ?><?= th_orden('Tipo', 'tipo', $orden) ?>
        <?= th_orden('Sale de → entra en', 'cuentas', $orden) ?><?= th_orden('Importe', 'importe', $orden, 'th-right') ?><?= th_orden('Estado', 'estado', $orden) ?></tr></thead>
      <tbody>
      <?php if (!$lista): ?><tr><td colspan="7" class="no-data">No hay movimientos <?= $hayFiltros ? 'con este filtro' : 'en el período' ?>.</td></tr><?php endif; ?>
      <?php foreach ($lista as $x): ?>
        <tr<?= $x['estado'] === 'ANULADO' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'movimientos_cuentas.php?id=' . $x['id_mov_cuentas'], 'Ver el movimiento') ?>
            <?= btn_accion('pdf', 'movimientos_cuentas.php?id=' . $x['id_mov_cuentas'] . '&export=pdf', 'Constancia en PDF', true) ?></div></td>
          <td class="mono"><a href="movimientos_cuentas.php?id=<?= (int) $x['id_mov_cuentas'] ?>"><?= (int) $x['id_mov_cuentas'] ?></a></td>
          <td><?= h(fecha($x['fecha'])) ?></td>
          <td><?= h(TIPOS_MOV_CUENTAS[$x['tipo']] ?? $x['tipo']) ?></td>
          <td><?= h($x['origen']) ?> → <?= h($x['destino']) ?><?php $det = array_filter([(string) $x['referencia'], (string) $x['comentario']]); ?>
            <?= $det ? '<span class="segunda-linea">' . h(implode(' · ', $det)) . '</span>' : '' ?></td>
          <td class="td-right td-fuerte"><?= dinero($x['importe'], $x['moneda']) ?></td>
          <td><?= $x['estado'] === 'ANULADO' ? estado('Anulado', 'neutro') : estado('Vigente', 'ok') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginador($pagina, $paginas, (int) $tot['n'], $porPag, 'movimientos') ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
