<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/conciliacion.php';

// ---------------------------------------------------------------------
// Clasificación de conceptos (gestion_adm: /conc/clasificar). Los textos
// del extracto de una cuenta, sin repetir («COMISION MANTENIMIENTO
// CUENTA», «IMPUESTO LEY 25413 S/DEBITOS»…), y qué es cada uno: un
// concepto de tesorería (gasto o ingreso bancario), una transferencia a
// otra cuenta propia, o algo que se concilia a mano. Guardar crea o
// actualiza la regla (conc_reglas, patrón «contiene» con ese texto).
// Quitar la desactiva: lo conciliado guarda con qué regla se clasificó.
//   ?solapa=reglas    todas las reglas, con las de otro patrón
//                     (empieza con, igual, expresión regular) y la prueba
//                     de un texto
// ---------------------------------------------------------------------

$errores = [];
$solapa = get_str('solapa') === 'reglas' ? 'reglas' : 'conceptos';
$bancos = [];
$fCuenta = (int) get_str('cuenta');
$fDesde = leer_fecha(get_str('desde')) ?: (new DateTimeImmutable('first day of this month'))->modify('-3 months')->format('Y-m-d');
$fHasta = leer_fecha(get_str('hasta')) ?: date('Y-m-d');
$base = 'conc_clasificar.php?' . http_build_query(array_filter(['cuenta' => $fCuenta ?: null, 'desde' => get_str('desde'), 'hasta' => get_str('hasta'),
                                                                 'solapa' => $solapa === 'reglas' ? 'reglas' : null]));

/** Lo elegido en el combo de destino: 'c:ID' concepto, 'm:ID' cuenta propia, 'manual' o ''. */
function clasif_destino(string $v): array
{
    if (preg_match('/^c:(\d+)$/', $v, $m)) {
        return ['concepto', (int) $m[1], null];
    }
    if (preg_match('/^m:(\d+)$/', $v, $m)) {
        return ['movimiento_cuentas', null, (int) $m[1]];
    }
    return $v === 'manual' ? ['manual', null, null] : ['', null, null];
}

/** El combo de destino de una regla. */
function clasif_combo(string $name, array $conceptos, array $cuentas, int $excluir, ?array $r, string $vacio = 'Sin clasificar'): string
{
    $act = !$r ? '' : ($r['tipo_resultado'] === 'concepto' ? 'c:' . (int) $r['id_concepto_tes']
                    : ($r['tipo_resultado'] === 'movimiento_cuentas' ? 'm:' . (int) $r['id_cuenta_destino'] : 'manual'));
    $out = '<select name="' . h($name) . '" class="clasif-destino"><option value="">' . h($vacio) . '</option>';
    foreach (['GASTO' => 'Gastos bancarios', 'INGRESO' => 'Ingresos bancarios'] as $t => $et) {
        $out .= '<optgroup label="' . h($et) . '">';
        foreach ($conceptos as $c) {
            if ($c['tipo'] === $t && ((int) $c['activo'] || $act === 'c:' . $c['id_concepto_tes'])) {
                $out .= '<option value="c:' . (int) $c['id_concepto_tes'] . '"' . ($act === 'c:' . $c['id_concepto_tes'] ? ' selected' : '') . '>' . h($c['nombre']) . '</option>';
            }
        }
        $out .= '</optgroup>';
    }
    $out .= '<optgroup label="Transferencia a otra cuenta propia">';
    foreach ($cuentas as $c) {
        if ((int) $c['id_cuenta_fondos'] !== $excluir) {
            $out .= '<option value="m:' . (int) $c['id_cuenta_fondos'] . '"' . ($act === 'm:' . $c['id_cuenta_fondos'] ? ' selected' : '') . '>' . h($c['nombre'] . ' (' . $c['moneda'] . ')') . '</option>';
        }
    }
    $out .= '</optgroup><option value="manual"' . ($act === 'manual' ? ' selected' : '') . '>Se concilia a mano (sin comprobante)</option>';
    return $out . '</select>';
}

if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');
    $volver = post_str('volver');
    $volver = preg_match('#^conc_clasificar\.php(\?[^\s]*)?$#', $volver) ? $volver : 'conc_clasificar.php';
    try {
        if ($accion === 'quitar' || $accion === 'reactivar') {
            $r = q1('SELECT * FROM conc_reglas WHERE id_regla = ?', [(int) post_str('id_regla')]);
            if ($r) {
                q('UPDATE conc_reglas SET activa = ? WHERE id_regla = ?', [$accion === 'reactivar' ? 1 : 0, (int) $r['id_regla']]);
                $usos = (int) q1('SELECT COUNT(*) AS n FROM conciliacion_items WHERE id_regla = ? AND estado = \'conciliado\'', [(int) $r['id_regla']])['n'];
                flash('ok', $accion === 'reactivar' ? '«' . $r['patron_texto'] . '» vuelve a clasificarse.'
                    : '«' . $r['patron_texto'] . '» queda sin clasificar' . ($usos ? '. Lo ya conciliado con esa regla (' . num($usos) . ') no cambia.' : '.'));
            }
            redirigir($volver);
        }
        if ($accion === 'guardar') {
            $patron = trim(preg_replace('/\s+/u', ' ', post_str('patron')));
            $tipoPatron = isset(CONC_PATRONES[post_str('tipo_patron')]) ? post_str('tipo_patron') : 'contiene';
            $mov = isset(CONC_MOVIMIENTOS[post_str('tipo_movimiento')]) ? post_str('tipo_movimiento') : 'ambos';
            [$res, $idCon, $idDest] = clasif_destino(post_str('destino'));
            $idRegla = (int) post_str('id_regla');
            $agrupa = post_str('agrupa') === '1' ? 1 : 0;
            $genera = post_str('genera') === '1' ? 1 : 0;
            $orden = post_str('orden');
            if ($patron === '' || mb_strlen($patron) > 200) {
                throw new RuntimeException('Falta el texto a reconocer (hasta 200 caracteres).');
            }
            if ($tipoPatron === 'regex' && !conc_regex_valida($patron)) {
                throw new RuntimeException('La expresión regular no es válida.');
            }
            if ($orden !== '' && !preg_match('/^\d{1,4}$/', $orden)) {
                throw new RuntimeException('El orden es un número.');
            }
            if ($res === '') {
                // Sin clasificar: se desactiva la regla que tenía.
                if ($idRegla) {
                    q('UPDATE conc_reglas SET activa = 0 WHERE id_regla = ?', [$idRegla]);
                    flash('ok', '«' . $patron . '» queda sin clasificar.');
                }
                redirigir($volver);
            }
            if ($res === 'movimiento_cuentas' && !q1("SELECT 1 AS x FROM cuentas_fondos WHERE id_cuenta_fondos = ?", [$idDest])) {
                throw new RuntimeException('La cuenta destino no existe.');
            }
            if ($res === 'concepto' && !q1('SELECT 1 AS x FROM conceptos_tesoreria WHERE id_concepto_tes = ?', [$idCon])) {
                throw new RuntimeException('El concepto no existe.');
            }
            if ($res === 'manual') {
                $genera = 0;
            }
            $datos = [mb_substr($patron, 0, 100), $tipoPatron, $mov, $res, $idCon, $idDest, $genera, $agrupa];
            if (!$idRegla) {
                // La que se había quitado con el mismo texto vuelve, en lugar de duplicarla.
                $previa = q1("SELECT id_regla FROM conc_reglas WHERE tipo_patron = ? AND UPPER(TRIM(patron_texto)) = UPPER(?) ORDER BY activa DESC, id_regla DESC LIMIT 1",
                             [$tipoPatron, $patron]);
                $idRegla = $previa ? (int) $previa['id_regla'] : 0;
            }
            if ($idRegla) {
                q('UPDATE conc_reglas SET nombre=?, tipo_patron=?, tipo_movimiento=?, tipo_resultado=?, id_concepto_tes=?, id_cuenta_destino=?,
                                          genera_comprobante=?, agrupa_por_fecha=?, patron_texto=?, activa=1' . ($orden !== '' ? ', orden=' . (int) $orden : '') . '
                    WHERE id_regla=?', array_merge($datos, [$patron, $idRegla]));
            } else {
                $sig = $orden !== '' ? (int) $orden : (int) q1('SELECT COALESCE(MAX(orden), 0) + 10 AS n FROM conc_reglas')['n'];
                q('INSERT INTO conc_reglas (nombre, tipo_patron, tipo_movimiento, tipo_resultado, id_concepto_tes, id_cuenta_destino,
                                            genera_comprobante, agrupa_por_fecha, patron_texto, orden, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($datos, [$patron, $sig, usuario_id()]));
            }
            flash('ok', '«' . $patron . '» clasificado. Las conciliaciones abiertas lo reconocen al abrirlas.');
            redirigir($volver);
        }
    } catch (RuntimeException $e) {
        flash('warning', $e->getMessage());
        redirigir($volver);
    } catch (Throwable $e) {
        $errores[] = conc_falta_script($e);
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$conceptos = [];
$filas = [];
$reglas = [];
$probar = trim(get_str('probar'));
$prueba = null;
try {
    $bancos = array_filter(cuentas_fondos(), fn ($c) => $c['tipo'] === 'B');
    if (!$fCuenta && $bancos) {
        // La cuenta con extracto más reciente.
        $u = q1("SELECT e.id_cuenta_fondos FROM extractos_bancarios e JOIN cuentas_fondos c ON c.id_cuenta_fondos = e.id_cuenta_fondos
                  WHERE c.tipo = 'B' ORDER BY e.fecha DESC LIMIT 1");
        $fCuenta = $u ? (int) $u['id_cuenta_fondos'] : (int) reset($bancos)['id_cuenta_fondos'];
    }
    $cuentasPropias = q('SELECT id_cuenta_fondos, nombre, moneda FROM cuentas_fondos WHERE habilitada = 1 ORDER BY tipo, nombre');
    $conceptos = q('SELECT * FROM conceptos_tesoreria ORDER BY tipo, orden, nombre');
    $reglas = conc_reglas(false);
    $activas = array_values(array_filter($reglas, fn ($r) => (int) $r['activa']));

    if ($solapa === 'conceptos' && $fCuenta) {
        foreach (q('SELECT concepto, debito, credito FROM extractos_bancarios WHERE id_cuenta_fondos = ? AND fecha BETWEEN ? AND ?',
                   [$fCuenta, $fDesde, $fHasta]) as $e) {
            $b = conc_concepto_base($e['concepto']);
            $deb = (float) $e['debito'] > 0;
            if (!isset($filas[$b])) {
                $filas[$b] = ['base' => $b, 'n' => 0, 'deb' => 0, 'cred' => 0, 'td' => 0.0, 'tc' => 0.0, 'regla' => null, 'ejemplo' => (string) $e['concepto']];
            }
            $f = &$filas[$b];
            $f['n']++;
            $f[$deb ? 'deb' : 'cred']++;
            $f[$deb ? 'td' : 'tc'] += abs((float) ($deb ? $e['debito'] : $e['credito']));
            $f['regla'] = $f['regla'] ?? conc_regla_de($activas, $e['concepto'], $deb);
            unset($f);
        }
        // Primero lo que falta clasificar, después alfabético.
        uasort($filas, fn ($a, $b) => [$a['regla'] !== null, $a['base']] <=> [$b['regla'] !== null, $b['base']]);
    }
    if ($probar !== '') {
        $prueba = ['debito' => conc_regla_de($activas, $probar, true), 'credito' => conc_regla_de($activas, $probar, false)];
    }
} catch (Throwable $e) {
    $errores[] = conc_falta_script($e);
}
$sinClasificar = count(array_filter($filas, fn ($f) => !$f['regla']));

layout_inicio('Clasificación de conceptos');
titulo('Clasificación de conceptos', 'Qué es cada texto del extracto: un gasto o ingreso bancario, una transferencia entre cuentas propias o algo que se concilia a mano',
       boton('Conciliación bancaria', 'secondary', 'conciliaciones.php', 'volver'), 'Tesorería / Clasificación de conceptos');
mensajes($errores);
echo solapas(['conceptos' => ['Conceptos del extracto', 'conc_clasificar.php?' . http_build_query(array_filter(['cuenta' => $fCuenta ?: null, 'desde' => get_str('desde'), 'hasta' => get_str('hasta')])), $sinClasificar ?: null],
              'reglas'    => ['Reglas', 'conc_clasificar.php?solapa=reglas' . ($fCuenta ? '&cuenta=' . $fCuenta : ''), count(array_filter($reglas, fn ($r) => (int) $r['activa']))]], $solapa);

if (!$conceptos):
?>
<div class="alert alert-warning"><?= icono('alerta', 16) ?><div>No hay conceptos de tesorería: cargalos en <a href="conceptos_tesoreria.php">Conceptos de tesorería</a>.</div></div>
<?php endif;

if ($solapa === 'conceptos'): ?>
<div class="card">
  <form method="get" class="filter-bar">
    <label class="filtro-campo"><span class="filtro-lbl">Cuenta</span>
      <?= cuenta_select('cuenta', $bancos, $fCuenta, '', ['class' => 'filtro', 'onchange' => 'this.form.submit()']) ?></label>
    <label class="filtro-campo"><span class="filtro-lbl">Desde</span>
      <input type="date" name="desde" value="<?= h($fDesde) ?>" class="filtro"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Hasta</span>
      <input type="date" name="hasta" value="<?= h($fHasta) ?>" class="filtro"></label>
    <?= filtro_botones('conc_clasificar.php?cuenta=' . $fCuenta, get_str('desde') !== '' || get_str('hasta') !== '') ?>
    <?= filtro_fin(['total' => count($filas), 'unidad' => 'conceptos']) ?>
  </form>
</div>

<div class="card">
  <?= card_head('Conceptos del extracto', $sinClasificar ? num($sinClasificar) . ' sin clasificar' : 'todos clasificados') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th>Concepto del extracto</th><th>Movimientos</th>
        <th class="th-right">Débitos</th><th class="th-right">Créditos</th><th>Clasificación</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="6" class="no-data">La cuenta no tiene extracto en esas fechas. Se importa en <a href="extractos_bancarios.php">Extractos bancarios</a>.</td></tr><?php endif; ?>
      <?php foreach ($filas as $f):
          $r = $f['regla'];
          $propia = $r && $r['tipo_patron'] === 'contiene' && mb_strtoupper(trim($r['patron_texto'])) === $f['base'];
          $tipoMov = $f['deb'] && !$f['cred'] ? 'debito' : ($f['cred'] && !$f['deb'] ? 'credito' : 'ambos');
          $fid = 'f' . substr(md5($f['base']), 0, 10); ?>
        <tr id="<?= $fid ?>">
          <td><div class="acciones">
            <?= $r && $propia ? accion_post('conc_clasificar.php', ['accion' => 'quitar', 'id_regla' => (int) $r['id_regla'], 'volver' => $base . '#' . $fid], 'cerrar', 'Quitar la clasificación',
                                            '«' . $f['base'] . '» queda sin clasificar. Lo ya conciliado no cambia.', 'borrar') : '' ?>
          </div></td>
          <td class="td-wrap"><span class="td-fuerte"><?= h($f['base']) ?></span>
            <?php if ($f['ejemplo'] !== $f['base']): ?><span class="segunda-linea"><?= h(mb_strimwidth($f['ejemplo'], 0, 90, '…')) ?></span><?php endif; ?></td>
          <td><?= num($f['n']) ?> <span class="td-muted"><?= $tipoMov === 'debito' ? 'débitos' : ($tipoMov === 'credito' ? 'créditos' : 'mixto') ?></span></td>
          <td class="td-right"><?= $f['td'] ? money($f['td']) : '' ?></td>
          <td class="td-right"><?= $f['tc'] ? money($f['tc']) : '' ?></td>
          <td>
            <?php if ($r && !$propia): ?>
              <?= estado(conc_regla_txt($r), 'info') ?><span class="segunda-linea">por la regla «<?= h($r['patron_texto']) ?>» (<?= h(mb_strtolower(CONC_PATRONES[$r['tipo_patron']])) ?>):
                <a href="conc_clasificar.php?solapa=reglas&amp;regla=<?= (int) $r['id_regla'] ?>#form-regla">editarla</a></span>
            <?php else: ?>
            <form method="post" action="conc_clasificar.php" class="clasif-form">
              <?= csrf_campo() ?>
              <input type="hidden" name="accion" value="guardar">
              <input type="hidden" name="patron" value="<?= h($f['base']) ?>">
              <input type="hidden" name="tipo_patron" value="contiene">
              <input type="hidden" name="tipo_movimiento" value="<?= h($tipoMov) ?>">
              <input type="hidden" name="id_regla" value="<?= $r ? (int) $r['id_regla'] : '' ?>">
              <input type="hidden" name="volver" value="<?= h($base . '#' . $fid) ?>">
              <?= clasif_combo('destino', $conceptos, $cuentasPropias, $fCuenta, $r) ?>
              <?= boton('Guardar', $r ? 'secondary' : 'primary', null, 'guardar', true, true) ?>
              <div class="clasif-opciones">
                <label class="campo-check" title="Un comprobante por concepto y día (si no, uno por renglón)"><input type="checkbox" name="agrupa" value="1" <?= !$r || (int) $r['agrupa_por_fecha'] ? 'checked' : '' ?>> Un comprobante por día</label>
                <label class="campo-check" title="La conciliación emite el comprobante que falta en el libro"><input type="checkbox" name="genera" value="1" <?= !$r || (int) $r['genera_comprobante'] ? 'checked' : '' ?>> Emite el comprobante</label>
              </div>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else:
    $edit = null;
    foreach ($reglas as $r) {
        if ((int) $r['id_regla'] === (int) get_str('regla')) {
            $edit = $r;
        }
    }
    $volverReglas = 'conc_clasificar.php?solapa=reglas' . ($fCuenta ? '&cuenta=' . $fCuenta : '');
?>
<div class="card" id="form-regla">
  <?= card_head($edit ? 'Regla «' . $edit['patron_texto'] . '»' : 'Nueva regla', 'para textos que no alcanza con «contiene»: empieza con, igual a, expresión regular',
                $edit ? boton('Nueva regla', 'secondary', $volverReglas . '#form-regla', 'agregar', false, true) : '') ?>
  <form method="post" action="conc_clasificar.php" autocomplete="off">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id_regla" value="<?= $edit ? (int) $edit['id_regla'] : '' ?>">
    <input type="hidden" name="volver" value="<?= h($volverReglas) ?>">
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="patron">Texto <span class="req">*</span></label>
          <input id="patron" name="patron" value="<?= h($edit['patron_texto'] ?? '') ?>" maxlength="200" required placeholder="IMPUESTO LEY 25413"></div>
        <div class="campo" style="--span:2"><label for="tipo_patron">Cómo se compara</label>
          <select id="tipo_patron" name="tipo_patron"><?= opciones(CONC_PATRONES, $edit['tipo_patron'] ?? 'contiene') ?></select></div>
        <div class="campo" style="--span:2"><label for="tipo_movimiento">Movimientos</label>
          <select id="tipo_movimiento" name="tipo_movimiento"><?= opciones(CONC_MOVIMIENTOS, $edit['tipo_movimiento'] ?? 'ambos') ?></select></div>
        <div class="campo" style="--span:3"><label>Clasificación <span class="req">*</span></label>
          <?= clasif_combo('destino', $conceptos, $cuentasPropias, 0, $edit, 'Elegí…') ?></div>
        <div class="campo" style="--span:1"><label for="orden">Orden</label>
          <input id="orden" name="orden" value="<?= h((string) ($edit['orden'] ?? '')) ?>" inputmode="numeric" class="derecha" placeholder="auto"></div>
        <div class="campo" style="--span:6">
          <label class="campo-check"><input type="checkbox" name="agrupa" value="1" <?= !$edit || (int) $edit['agrupa_por_fecha'] ? 'checked' : '' ?>> Un comprobante por concepto y día (si no, uno por renglón del extracto)</label>
          <label class="campo-check"><input type="checkbox" name="genera" value="1" <?= !$edit || (int) $edit['genera_comprobante'] ? 'checked' : '' ?>> La conciliación emite el comprobante que falta en el libro</label></div>
      </div>
      <p class="campo-ayuda" style="margin:6px 0 0">Las reglas se prueban en orden (el menor primero) y gana la primera que reconoce el texto.
        Las mayúsculas no importan. En una expresión regular se mira el texto completo del extracto.</p>
    </div>
    <div class="form-acciones"><?= boton($edit ? 'Guardar' : 'Agregar regla', 'primary', null, 'guardar', true) ?></div>
  </form>
</div>

<div class="card">
  <?= card_head('Probar un texto', 'qué regla lo reconoce') ?>
  <form method="get" class="filter-bar">
    <input type="hidden" name="solapa" value="reglas">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Texto del extracto</span>
      <input type="search" name="probar" value="<?= h($probar) ?>" class="filter-input" placeholder="IMP.LEY 25413 DEB 0,6%"></label>
    <?= boton('Probar', 'primary', null, 'buscar', true, true) ?>
  </form>
  <?php if ($prueba): ?>
  <div class="bloque">
    <?php foreach (['debito' => 'Como débito', 'credito' => 'Como crédito'] as $k => $et): $r = $prueba[$k]; ?>
      <p><strong><?= $et ?>:</strong> <?= $r ? estado(conc_regla_txt($r), 'ok') . ' por «' . h($r['patron_texto']) . '» (' . h(mb_strtolower(CONC_PATRONES[$r['tipo_patron']])) . ')'
                                            : estado('ninguna regla', 'warn') ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <?= card_head('Reglas', 'en el orden en que se prueban') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden data-sin-paginar>
      <thead><tr><th class="col-acciones">Acciones</th><th class="th-right">Orden</th><th>Texto</th><th>Movimientos</th><th>Clasificación</th>
        <th>Comprobante</th><th class="th-right">Conciliados</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$reglas): ?><tr><td colspan="8" class="no-data">Todavía no hay reglas: clasificá los conceptos del extracto en la otra solapa.</td></tr><?php endif; ?>
      <?php foreach ($reglas as $r): $rid = (int) $r['id_regla'];
          $usos = (int) q1("SELECT COUNT(*) AS n FROM conciliacion_items WHERE id_regla = ? AND estado = 'conciliado'", [$rid])['n']; ?>
        <tr class="<?= (int) $r['activa'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', $volverReglas . '&regla=' . $rid . '#form-regla', 'Editar') ?>
            <?= (int) $r['activa'] ? accion_post('conc_clasificar.php', ['accion' => 'quitar', 'id_regla' => $rid, 'volver' => $volverReglas], 'cerrar', 'Quitar', '«' . $r['patron_texto'] . '» deja de clasificarse. Lo ya conciliado no cambia.', 'borrar')
                                   : accion_post('conc_clasificar.php', ['accion' => 'reactivar', 'id_regla' => $rid, 'volver' => $volverReglas], 'ok', 'Volver a usarla') ?>
          </div></td>
          <td class="td-right td-muted"><?= (int) $r['orden'] ?></td>
          <td class="td-fuerte"><?= h($r['patron_texto']) ?><span class="segunda-linea"><?= h(CONC_PATRONES[$r['tipo_patron']]) ?></span></td>
          <td><?= h(CONC_MOVIMIENTOS[$r['tipo_movimiento']]) ?></td>
          <td><?= h(conc_regla_txt($r)) ?></td>
          <td><?= $r['tipo_resultado'] === 'manual' ? '<span class="td-muted">no</span>' : ((int) $r['genera_comprobante'] ? 'Sí' . ((int) $r['agrupa_por_fecha'] ? ' <span class="td-muted">por día</span>' : ' <span class="td-muted">por renglón</span>') : 'No') ?></td>
          <td class="td-right"><?= $usos ? num($usos) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $r['activa'] ? estado('Activa', 'ok') : estado('Quitada', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif;

layout_fin();
