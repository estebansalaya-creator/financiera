<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/comisiones_escalas.php';

// ---------------------------------------------------------------------
// Tipos de asociado y tasas (configuración; antes «productos»)
//   tipos_asociado.php                        listado
//   tipos_asociado.php?nuevo=1                alta
//   tipos_asociado.php?id=N                   ficha, solapa Datos
//   tipos_asociado.php?id=N&solapa=tasas      sus tasas (&nueva=1: la nueva)
//   tipos_asociado.php?id=N&solapa=escalas    sus escalas de comisión
//                                             (&escala=M o &escala=nueva)
//   tipos_asociado.php?id=N&solapa=excepciones  los vendedores que cobran distinto
// El tipo de asociado (NACION, REGGIO DC, HABERES ACTIVOS…, los de las
// planillas de comisiones) define los plazos y montos, la tasa y la
// comisión del vendedor. El cliente tiene uno habitual y cada solicitud y
// préstamo guarda el suyo.
// Las tasas no se editan: se carga una nueva con su vigencia y la anterior
// queda como historial (los préstamos guardan la tasa con la que se
// liquidaron). Una tasa se puede borrar solo si ningún préstamo la usó.
// Las escalas de comisión (inc/comisiones_escalas.php) son las generales
// del tipo; las excepciones de un vendedor se cargan en su ficha.
// Tabla de configuración: sin Excel ni PDF (criterio de gestion_adm).
// ---------------------------------------------------------------------

const DESTINATARIOS = ['AMBOS' => 'Personas y empresas', 'F' => 'Personas físicas', 'J' => 'Empresas'];

const SOLAPAS_TIPO = ['datos', 'tasas', 'escalas', 'excepciones'];

$errores = [];
$id      = (int) get_str('id');
$nuevo   = get_str('nuevo') === '1';
$fEstado = in_array(get_str('estado'), ['0', 'todos'], true) ? get_str('estado') : '1';
$solapa  = in_array(get_str('solapa'), SOLAPAS_TIPO, true) ? get_str('solapa') : 'datos';
$base    = 'tipos_asociado.php?id=' . $id . '&solapa=' . $solapa;     // la lista de la solapa

/** Dónde figura un tipo de asociado (lo que impide eliminarlo). */
function usos_tipo(int $id): array
{
    $u = q1('SELECT (SELECT COUNT(*) FROM prestamos WHERE id_tipo_asociado = ?) AS prestamos,
                    (SELECT COUNT(*) FROM solicitudes WHERE id_tipo_asociado = ?) AS solicitudes,
                    (SELECT COUNT(*) FROM clientes WHERE id_tipo_asociado = ?) AS clientes,
                    (SELECT COUNT(*) FROM comisiones_importadas WHERE id_tipo_asociado = ?) AS importadas',
            [$id, $id, $id, $id]);
    $nombres = ['prestamos' => 'préstamos', 'solicitudes' => 'solicitudes', 'clientes' => 'clientes',
                'importadas' => 'renglones de planillas de comisiones'];
    $out = [];
    foreach ($nombres as $k => $n) {
        if ((int) $u[$k]) {
            $out[] = num($u[$k]) . ' ' . $n;
        }
    }
    return $out;
}

/** Fracción guardada (0.85) -> texto para el campo («85»). */
function pct_campo($f): string
{
    if ($f === null || $f === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $f * 100, 4, ',', ''), '0'), ',');
}

function monto_campo($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
$form = null;          // lo cargado, para volver a mostrarlo si hay errores
$escalaForm = null;    // ídem, la escala de comisión
if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'guardar_tipo') {
        $form = [
            'codigo'          => strtoupper(post_str('codigo')),
            'nombre'          => post_str('nombre'),
            'destinatario'    => post_str('destinatario'),
            'plazo_min'       => post_str('plazo_min'),
            'plazo_max'       => post_str('plazo_max'),
            'monto_min'       => post_str('monto_min'),
            'monto_max'       => post_str('monto_max'),
            'alicuota_iva'    => post_str('alicuota_iva'),
            'gasto_otorg_pct' => post_str('gasto_otorg_pct'),
            'activo'          => isset($_POST['activo']) ? 1 : 0,
        ];
        $pmin = leer_num($form['plazo_min']);
        $pmax = leer_num($form['plazo_max']);
        $mmin = leer_num($form['monto_min']) ?? 0.0;
        $mmax = leer_num($form['monto_max']);
        $iva  = leer_num($form['alicuota_iva']);
        $gst  = leer_num($form['gasto_otorg_pct']) ?? 0.0;

        if (!preg_match('/^[A-Z0-9_]{1,20}$/', $form['codigo'])) {
            $errores[] = 'El código va en mayúsculas, sin espacios, hasta 20 caracteres (letras, números y _).';
        }
        if ($form['nombre'] === '') {
            $errores[] = 'Falta el nombre.';
        }
        if (!isset(DESTINATARIOS[$form['destinatario']])) {
            $errores[] = 'Elegí a quién va dirigido.';
        }
        if ($pmin === null || $pmax === null || $pmin < 1 || $pmax < $pmin || $pmax > 360 || floor($pmin) != $pmin || floor($pmax) != $pmax) {
            $errores[] = 'El plazo va en cuotas enteras: mínimo 1, máximo hasta 360 y no menor que el mínimo.';
        }
        if ($mmax === null || $mmin < 0 || $mmax < $mmin) {
            $errores[] = 'El monto máximo tiene que ser mayor o igual que el mínimo.';
        }
        if ($iva === null || $iva < 0 || $iva > 100) {
            $errores[] = 'El IVA sobre intereses va en %, entre 0 y 100 (normalmente 21).';
        }
        if ($gst < 0 || $gst > 100) {
            $errores[] = 'Los gastos de otorgamiento van en %, entre 0 y 100.';
        }

        if (!$errores) {
            $datos = [$form['codigo'], $form['nombre'], $form['destinatario'], (int) $pmin, (int) $pmax,
                      round($mmin, 2), round($mmax, 2), round($iva / 100, 4), round($gst / 100, 4), $form['activo']];
            try {
                if ($id > 0) {
                    q('UPDATE tipos_asociado SET codigo=?, nombre=?, destinatario=?, plazo_min=?, plazo_max=?, monto_min=?,
                              monto_max=?, alicuota_iva=?, gasto_otorg_pct=?, activo=? WHERE id_tipo_asociado=?',
                      array_merge($datos, [$id]));
                    flash('ok', 'Tipo de asociado ' . $form['codigo'] . ' guardado.');
                } else {
                    q("INSERT INTO tipos_asociado (codigo, nombre, destinatario, plazo_min, plazo_max, monto_min, monto_max,
                                                  alicuota_iva, gasto_otorg_pct, activo, sistema_amort)
                       VALUES (?,?,?,?,?,?,?,?,?,?,'FRANCES')", $datos);
                    $id = (int) db()->lastInsertId();
                    flash('ok', 'Tipo de asociado ' . $form['codigo'] . ' creado. Ahora cargale las tasas y las escalas de comisión.');
                }
                redirigir('tipos_asociado.php?id=' . $id . ($form && $nuevo ? '&solapa=tasas&nueva=1' : ''));
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e, ['uq_productos_codigo' => 'Ya hay un tipo de asociado con el código ' . $form['codigo'] . '.']);
            }
        }
    } elseif ($accion === 'agregar_tasa' && $id > 0) {
        $vig  = leer_fecha(post_str('vigencia_desde'));
        $pd   = leer_num(post_str('plazo_desde'));
        $ph   = leer_num(post_str('plazo_hasta'));
        $md   = leer_num(post_str('monto_desde')) ?? 0.0;
        $mh   = leer_num(post_str('monto_hasta'));
        $tna  = leer_num(post_str('tna'));
        $pun  = post_str('tasa_punitoria') === '' ? null : leer_num(post_str('tasa_punitoria'));
        if ($vig === null) {
            $errores[] = 'Falta la fecha desde la que rige la tasa.';
        }
        if ($pd === null || $ph === null || $pd < 1 || $ph < $pd || floor($pd) != $pd || floor($ph) != $ph) {
            $errores[] = 'El rango de plazos va en cuotas enteras y el «hasta» no puede ser menor que el «desde».';
        }
        if ($mh === null || $md < 0 || $mh < $md) {
            $errores[] = 'El rango de montos no es válido.';
        }
        if ($tna === null || $tna < 0 || $tna >= 1000) {
            $errores[] = 'La TNA va en % (por ejemplo 85 para 85 %).';
        }
        if (post_str('tasa_punitoria') !== '' && ($pun === null || $pun < 0 || $pun >= 1000)) {
            $errores[] = 'La tasa punitoria va en % de TNA (o vacía para usar el parámetro general).';
        }
        if (!$errores) {
            try {
                q('INSERT INTO tasas_vigencia (id_tipo_asociado, vigencia_desde, plazo_desde, plazo_hasta, monto_desde, monto_hasta, tna, tasa_punitoria, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?)',
                  [$id, $vig, (int) $pd, (int) $ph, round($md, 2), round($mh, 2), round($tna / 100, 6),
                   $pun === null ? null : round($pun / 100, 6), usuario_id()]);
                flash('ok', 'Tasa agregada: TNA ' . pct($tna / 100) . ' desde el ' . fecha($vig) . '.');
                redirigir('tipos_asociado.php?id=' . $id . '&solapa=tasas');
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
            }
        }
    } elseif ($accion === 'borrar_tasa' && $id > 0) {
        $idTasa = (int) post_str('id_tasa');
        try {
            $usos = (int) (q1('SELECT COUNT(*) AS n FROM prestamos WHERE id_tasa = ?', [$idTasa])['n'] ?? 0);
            if ($usos > 0) {
                flash('warning', 'No se puede borrar: la usan ' . $usos . ' préstamos. Cargá una tasa nueva con otra vigencia.');
            } else {
                q('DELETE FROM tasas_vigencia WHERE id_tasa = ? AND id_tipo_asociado = ?', [$idTasa, $id]);
                flash('ok', 'Tasa borrada.');
            }
        } catch (Throwable $e) {
            flash('danger', mensaje_bd($e));
        }
        redirigir('tipos_asociado.php?id=' . $id . '&solapa=tasas');
    } elseif (in_array($accion, ['activar_tipo', 'desactivar_tipo', 'borrar_tipo'], true)) {
        $idT = (int) post_str('id_tipo_asociado');
        $t = q1('SELECT * FROM tipos_asociado WHERE id_tipo_asociado = ?', [$idT]);
        $volver = 'tipos_asociado.php' . ($fEstado !== '1' ? '?estado=' . $fEstado : '');
        if (!$t) {
            flash('danger', 'El tipo de asociado ya no existe.');
        } elseif ($accion === 'borrar_tipo') {
            $usos = usos_tipo($idT);
            if ($usos) {
                flash('danger', 'No se puede eliminar ' . $t['codigo'] . ': tiene ' . implode(', ', $usos) . '. Desactivalo.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                q('DELETE FROM comision_reglas WHERE id_tipo_asociado = ?', [$idT]);
                q('DELETE FROM tasas_vigencia WHERE id_tipo_asociado = ?', [$idT]);
                q('DELETE FROM tipos_asociado WHERE id_tipo_asociado = ?', [$idT]);
                $pdo->commit();
                flash('ok', $t['codigo'] . ' eliminado, con sus tasas y sus escalas.');
            }
        } else {
            q('UPDATE tipos_asociado SET activo = ? WHERE id_tipo_asociado = ?', [$accion === 'activar_tipo' ? 1 : 0, $idT]);
            flash('ok', $t['codigo'] . ($accion === 'activar_tipo' ? ' activado.' : ' desactivado: deja de ofrecerse al simular y al cargar solicitudes. Lo que ya tiene sigue igual.'));
        }
        redirigir($volver);
    } elseif (strncmp($accion, 'escala_', 7) === 0 && $id > 0) {
        try {
            $errores = escala_acciones($accion, $id, null, 'tipos_asociado.php?id=' . $id . '&solapa=escalas', $escalaForm);
        } catch (Throwable $e) {
            $errores[] = mensaje_bd($e);
        }
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$tipo        = null;
$tasas       = [];
$escalas     = [];
$excepciones = [];
$lista       = [];
$puniPct     = 0.5;
try {
    $puniPct = (float) (q1("SELECT valor FROM parametros WHERE clave = 'PUNITORIO_PCT_TNA'")['valor'] ?? 0.5);
    if ($id > 0) {
        $tipo = q1('SELECT t.*, (SELECT COUNT(*) FROM clientes c WHERE c.id_tipo_asociado = t.id_tipo_asociado) AS n_clientes
                      FROM tipos_asociado t WHERE t.id_tipo_asociado = ?', [$id]);
        if ($tipo) {
            $tasas = q(
                'SELECT t.*, (SELECT COUNT(*) FROM prestamos p WHERE p.id_tasa = t.id_tasa) AS usos
                   FROM tasas_vigencia t
                  WHERE t.id_tipo_asociado = ?
                  ORDER BY t.vigencia_desde DESC, t.plazo_desde, t.monto_desde, t.id_tasa DESC',
                [$id]
            );
            $escalas = escalas_de($id, null);
            $excepciones = q('SELECT r.*, v.codigo AS vendedor_codigo, v.nombre AS vendedor_nombre, v.id_vendedor
                                FROM comision_reglas r JOIN vendedores v ON v.id_vendedor = r.id_vendedor
                               WHERE r.id_tipo_asociado = ? AND r.activo = 1
                                 AND (r.vigencia_hasta IS NULL OR r.vigencia_hasta >= CURDATE())
                               ORDER BY v.nombre, r.monto_desde, r.vigencia_desde', [$id]);
        }
    } elseif (!$nuevo) {
        $lista = q(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM tasas_vigencia t WHERE t.id_tipo_asociado = p.id_tipo_asociado) AS n_tasas,
                    (SELECT COUNT(*) FROM prestamos pr WHERE pr.id_tipo_asociado = p.id_tipo_asociado) AS n_prestamos,
                    (SELECT COUNT(*) FROM clientes c WHERE c.id_tipo_asociado = p.id_tipo_asociado) AS n_clientes,
                    v.tna_min, v.tna_max, v.desde,
                    e.n_escalas, e.pct, e.pct_tm,
                    (SELECT COUNT(DISTINCT r.id_vendedor) FROM comision_reglas r
                      WHERE r.id_tipo_asociado = p.id_tipo_asociado AND r.id_vendedor IS NOT NULL AND r.activo = 1
                        AND r.vigencia_desde <= CURDATE() AND (r.vigencia_hasta IS NULL OR r.vigencia_hasta >= CURDATE())) AS n_excepciones
               FROM tipos_asociado p
               LEFT JOIN (SELECT t.id_tipo_asociado, MIN(t.tna) AS tna_min, MAX(t.tna) AS tna_max, t.vigencia_desde AS desde
                            FROM tasas_vigencia t
                            JOIN (SELECT id_tipo_asociado, MAX(vigencia_desde) AS vd FROM tasas_vigencia
                                   WHERE vigencia_desde <= CURDATE() GROUP BY id_tipo_asociado) u
                              ON u.id_tipo_asociado = t.id_tipo_asociado AND u.vd = t.vigencia_desde
                           GROUP BY t.id_tipo_asociado, t.vigencia_desde) v ON v.id_tipo_asociado = p.id_tipo_asociado
               LEFT JOIN (SELECT r.id_tipo_asociado, COUNT(*) AS n_escalas, MIN(r.porcentaje) AS pct, MIN(r.porcentaje_tm) AS pct_tm
                            FROM comision_reglas r
                           WHERE r.id_vendedor IS NULL AND r.activo = 1
                             AND r.vigencia_desde <= CURDATE() AND (r.vigencia_hasta IS NULL OR r.vigencia_hasta >= CURDATE())
                           GROUP BY r.id_tipo_asociado) e ON e.id_tipo_asociado = p.id_tipo_asociado"
            . ($fEstado !== 'todos' ? ' WHERE p.activo = ' . (int) $fEstado : '') . "
              ORDER BY p.activo DESC, p.nombre"
        );
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
if ($id > 0 && !$tipo && !$errores) {
    http_response_code(404);
}
layout_inicio('Tipos de asociado y tasas');

if ($id > 0 && !$tipo && !$errores) {
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese tipo de asociado</h1><p>Puede que lo hayan borrado.</p>'
       . boton('Volver a tipos de asociado', 'primary', 'tipos_asociado.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

// ── Listado ────────────────────────────────────────────────────────────
if ($id === 0 && !$nuevo) {
    titulo('Tipos de asociado y tasas', 'Con qué límites, tasas y comisiones se presta a cada tipo de asociado',
           boton('Nuevo tipo de asociado', 'primary', 'tipos_asociado.php?nuevo=1', 'agregar'));
    mensajes($errores);
    $sinTasa = count(array_filter($lista, function ($p) { return $p['activo'] && !$p['n_tasas']; }));
    if ($sinTasa) {
        alerta($sinTasa . ($sinTasa === 1 ? ' tipo de asociado activo no tiene' : ' tipos de asociado activos no tienen')
               . ' tasa cargada: no se pueden simular ni liquidar hasta cargarles una.', 'warning');
    }
    $sinEscala = count(array_filter($lista, function ($p) { return $p['activo'] && !$p['n_escalas']; }));
    if ($sinEscala) {
        alerta($sinEscala . ($sinEscala === 1 ? ' tipo de asociado activo no tiene' : ' tipos de asociado activos no tienen')
               . ' escala de comisión vigente: sus préstamos no generan comisión salvo que el vendedor tenga una excepción.', 'warning');
    }
    ?>
    <div class="card">
      <?= card_head('Tipos de asociado') ?>
      <?= barra_local('tipos_asociado.php', 'Código o nombre…',
                      filtro_select('estado', 'Estado', ['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'], $fEstado, '', $fEstado !== '1'),
                      $fEstado !== '1') ?>
      <div class="data-table-wrap">
        <table class="data-table" data-local data-paginada data-unidad="tipo|tipos">
          <thead>
            <tr>
              <th class="col-acciones" data-no-sort>Acciones</th>
              <th>Código</th>
              <th>Nombre</th>
              <th>Para</th>
              <th class="th-right">Plazo</th>
              <th class="th-right">Monto</th>
              <th class="th-right">Gastos</th>
              <th class="th-right">TNA vigente</th>
              <th class="th-right">Comisión vigente</th>
              <th class="th-right">Clientes</th>
              <th class="th-right">Préstamos</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$lista): ?>
            <tr><td colspan="12" class="no-data"><?= $fEstado !== '1' ? 'Ningún tipo de asociado con ese estado.' : 'Todavía no hay tipos de asociado.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($lista as $p): ?>
            <tr class="<?= $p['activo'] ? '' : 'fila-baja' ?>">
              <?php $tid = (int) $p['id_tipo_asociado']; $volverQs = $fEstado !== '1' ? '?estado=' . $fEstado : ''; ?>
              <td><div class="acciones">
                <?= btn_accion('editar', 'tipos_asociado.php?id=' . $tid, 'Editar') ?>
                <?= btn_accion('ver', 'tipos_asociado.php?id=' . $tid . '&solapa=escalas', 'Escalas de comisión') ?>
                <?= $p['activo']
                      ? accion_post('tipos_asociado.php' . $volverQs, ['accion' => 'desactivar_tipo', 'id_tipo_asociado' => $tid], 'cerrar', 'Desactivar',
                                    $p['codigo'] . ' deja de ofrecerse al simular y al cargar solicitudes. Lo que ya tiene sigue igual.', 'borrar')
                      : accion_post('tipos_asociado.php' . $volverQs, ['accion' => 'activar_tipo', 'id_tipo_asociado' => $tid], 'ok', 'Activar') ?>
                <?= !$p['n_prestamos'] && !$p['n_clientes'] && !usos_tipo($tid)
                      ? accion_post('tipos_asociado.php' . $volverQs, ['accion' => 'borrar_tipo', 'id_tipo_asociado' => $tid], 'borrar', 'Eliminar',
                                    'Se elimina ' . $p['codigo'] . ' con sus tasas y escalas. No tiene clientes, solicitudes ni préstamos.', 'borrar') : '' ?>
              </div></td>
              <td class="td-fuerte"><?= h($p['codigo']) ?></td>
              <td><?= h($p['nombre']) ?></td>
              <td><?= h(DESTINATARIOS[$p['destinatario']] ?? $p['destinatario']) ?></td>
              <td class="td-right" data-orden="<?= h($p['plazo_max']) ?>"><?= h($p['plazo_min']) ?> a <?= h($p['plazo_max']) ?> cuotas</td>
              <td class="td-right" data-orden="<?= h($p['monto_max']) ?>"><?= money($p['monto_min']) ?> a <?= money($p['monto_max']) ?>
                <span class="segunda-linea">IVA s/int. <?= pct($p['alicuota_iva'], 0) ?></span></td>
              <td class="td-right" data-orden="<?= h($p['gasto_otorg_pct']) ?>"><?= pct($p['gasto_otorg_pct']) ?></td>
              <td class="td-right" data-orden="<?= h($p['tna_max'] ?? -1) ?>">
                <?php if ($p['tna_min'] === null): ?>
                  <?= estado($p['n_tasas'] ? 'Rige más adelante' : 'Sin tasa', $p['activo'] ? 'warn' : 'neutro') ?>
                <?php else: ?>
                  <?= $p['tna_min'] === $p['tna_max'] ? pct($p['tna_min']) : pct($p['tna_min']) . ' a ' . pct($p['tna_max']) ?>
                  <span class="segunda-linea">desde el <?= h(fecha($p['desde'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="td-right" data-orden="<?= h($p['pct'] ?? -1) ?>">
                <?php if (!$p['n_escalas']): ?>
                  <?= estado('Sin escala', $p['activo'] ? 'warn' : 'neutro') ?>
                <?php elseif ((int) $p['n_escalas'] === 1): ?>
                  <?= h(escala_pct_campo($p['pct'])) ?> %
                  <span class="segunda-linea">telemarketing <?= h(escala_pct_campo($p['pct_tm'] ?? $p['pct'])) ?> %</span>
                <?php else: ?>
                  <?= num($p['n_escalas']) ?> escalas por monto
                <?php endif; ?>
                <?php if ((int) $p['n_excepciones']): ?>
                  <span class="segunda-linea"><?= num($p['n_excepciones']) ?> <?= (int) $p['n_excepciones'] === 1 ? 'vendedor con excepción' : 'vendedores con excepción' ?></span>
                <?php endif; ?>
              </td>
              <td class="td-right" data-orden="<?= h($p['n_clientes']) ?>"><?= num($p['n_clientes']) ?></td>
              <td class="td-right" data-orden="<?= h($p['n_prestamos']) ?>"><?= num($p['n_prestamos']) ?></td>
              <td data-orden="<?= (int) $p['activo'] ?>"><?= $p['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= paginacion_local() ?>
    </div>
    <?php
    layout_fin();
    exit;
}


// ── Ficha (alta o edición) ─────────────────────────────────────────────
$v = $form ?? ($tipo ? [
    'codigo' => $tipo['codigo'], 'nombre' => $tipo['nombre'], 'destinatario' => $tipo['destinatario'],
    'plazo_min' => $tipo['plazo_min'], 'plazo_max' => $tipo['plazo_max'],
    'monto_min' => monto_campo($tipo['monto_min']), 'monto_max' => monto_campo($tipo['monto_max']),
    'alicuota_iva' => pct_campo($tipo['alicuota_iva']), 'gasto_otorg_pct' => pct_campo($tipo['gasto_otorg_pct']),
    'activo' => (int) $tipo['activo'],
] : ['codigo' => '', 'nombre' => '', 'destinatario' => 'AMBOS', 'plazo_min' => '1', 'plazo_max' => '60',
     'monto_min' => '0', 'monto_max' => '', 'alicuota_iva' => '21', 'gasto_otorg_pct' => '0', 'activo' => 1]);

titulo($tipo ? $tipo['nombre'] : 'Nuevo tipo de asociado',
       $tipo ? 'Código ' . $tipo['codigo'] . ' · ' . num($tipo['n_clientes']) . ($tipo['n_clientes'] == 1 ? ' cliente' : ' clientes')
               . ' · sistema francés, cuotas mensuales' : 'Sistema francés, cuotas mensuales',
       '', '<a href="tipos_asociado.php">Tipos de asociado y tasas</a>' . ($tipo ? ' / ' . h($tipo['codigo']) : ''));
mensajes($errores);

if ($tipo) {
    $vigentes = function (array $filas) {
        $hoy = date('Y-m-d');
        return count(array_filter($filas, function ($e) use ($hoy) {
            return (int) $e['activo'] && $e['vigencia_desde'] <= $hoy && ($e['vigencia_hasta'] === null || $e['vigencia_hasta'] >= $hoy);
        }));
    };
    echo solapas([
        'datos'       => ['Datos', 'tipos_asociado.php?id=' . $id, null],
        'tasas'       => ['Tasas', 'tipos_asociado.php?id=' . $id . '&solapa=tasas', count($tasas)],
        'escalas'     => ['Escalas de comisión', 'tipos_asociado.php?id=' . $id . '&solapa=escalas', $vigentes($escalas)],
        'excepciones' => ['Excepciones de vendedores', 'tipos_asociado.php?id=' . $id . '&solapa=excepciones', count($excepciones)],
    ], $solapa);
}

// ── Solapa Datos (y el alta) ─────────────────────────────────────────────
if (!$tipo || $solapa === 'datos'):
?>
<div class="card">
  <?= card_head('Datos del tipo de asociado', '', boton('Volver', 'secondary', 'tipos_asociado.php', 'volver', false, true)) ?>
  <form method="post" action="tipos_asociado.php<?= $id ? '?id=' . $id : '?nuevo=1' ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="guardar_tipo">
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="codigo">Código <span class="req">*</span></label>
          <input id="codigo" name="codigo" value="<?= h($v['codigo']) ?>" maxlength="20" class="mono" required autocomplete="off">
        </div>
        <div class="campo" style="--span:5">
          <label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($v['nombre']) ?>" maxlength="100" required>
          <span class="campo-ayuda">como figura en las planillas de comisiones</span>
        </div>
        <div class="campo" style="--span:3">
          <label for="destinatario">Para</label>
          <select id="destinatario" name="destinatario"><?= opciones(DESTINATARIOS, $v['destinatario']) ?></select>
        </div>
        <div class="campo campo-boton" style="--span:2">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= $v['activo'] ? 'checked' : '' ?>> Activo</label>
        </div>
      </div>
    </div>
    <div class="bloque">
      <div class="bloque-titulo">Límites y cargos</div>
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="plazo_min">Plazo mínimo</label>
          <input id="plazo_min" name="plazo_min" value="<?= h($v['plazo_min']) ?>" inputmode="numeric" class="derecha" required>
          <span class="campo-ayuda">cuotas</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo_max">Plazo máximo</label>
          <input id="plazo_max" name="plazo_max" value="<?= h($v['plazo_max']) ?>" inputmode="numeric" class="derecha" required>
          <span class="campo-ayuda">cuotas</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_min">Monto mínimo</label>
          <input id="monto_min" name="monto_min" value="<?= h($v['monto_min']) ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">$</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_max">Monto máximo <span class="req">*</span></label>
          <input id="monto_max" name="monto_max" value="<?= h($v['monto_max']) ?>" inputmode="decimal" class="derecha" required>
          <span class="campo-ayuda">$</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="alicuota_iva">IVA s/intereses</label>
          <input id="alicuota_iva" name="alicuota_iva" value="<?= h($v['alicuota_iva']) ?>" inputmode="decimal" class="derecha" required>
          <span class="campo-ayuda">%</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="gasto_otorg_pct">Gastos de otorgamiento</label>
          <input id="gasto_otorg_pct" name="gasto_otorg_pct" value="<?= h($v['gasto_otorg_pct']) ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">% del capital, se descuenta del desembolso</span>
        </div>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton($tipo ? 'Guardar cambios' : 'Crear tipo de asociado', 'primary', null, 'guardar', true) ?>
    </div>
  </form>
</div>
<?php
// ── Solapa Tasas ─────────────────────────────────────────────────────────
elseif ($solapa === 'tasas'):
    $nueva = get_str('nueva') === '1' || (es_post() && post_str('accion') === 'agregar_tasa');
    if ($nueva):
?>
<form method="post" action="tipos_asociado.php?id=<?= $id ?>&amp;solapa=tasas&amp;nueva=1" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="agregar_tasa">
  <div class="card">
    <?= card_head('Nueva tasa', 'las tasas no se editan: cuando cambian, se carga una nueva con su vigencia',
                  boton('Volver', 'secondary', 'tipos_asociado.php?id=' . $id . '&solapa=tasas', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="vigencia_desde">Vigente desde <span class="req">*</span></label>
          <input type="date" id="vigencia_desde" name="vigencia_desde" value="<?= h(post_str('vigencia_desde') ?: date('Y-m-d')) ?>" required>
        </div>
        <div class="campo" style="--span:1">
          <label for="plazo_desde">Plazo desde</label>
          <input id="plazo_desde" name="plazo_desde" value="<?= h(post_str('plazo_desde') ?: (string) $tipo['plazo_min']) ?>" inputmode="numeric" class="derecha" required>
        </div>
        <div class="campo" style="--span:1">
          <label for="plazo_hasta">hasta</label>
          <input id="plazo_hasta" name="plazo_hasta" value="<?= h(post_str('plazo_hasta') ?: (string) $tipo['plazo_max']) ?>" inputmode="numeric" class="derecha" required>
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_desde">Monto desde</label>
          <input id="monto_desde" name="monto_desde" value="<?= h(post_str('monto_desde') ?: monto_campo($tipo['monto_min'])) ?>" inputmode="decimal" class="derecha">
        </div>
        <div class="campo" style="--span:2">
          <label for="monto_hasta">hasta</label>
          <input id="monto_hasta" name="monto_hasta" value="<?= h(post_str('monto_hasta') ?: monto_campo($tipo['monto_max'])) ?>" inputmode="decimal" class="derecha" required>
        </div>
        <div class="campo" style="--span:2">
          <label for="tna">TNA % <span class="req">*</span></label>
          <input id="tna" name="tna" value="<?= h(post_str('tna')) ?>" inputmode="decimal" class="derecha" required placeholder="85">
        </div>
        <div class="campo" style="--span:2">
          <label for="tasa_punitoria">Punitoria %</label>
          <input id="tasa_punitoria" name="tasa_punitoria" value="<?= h(post_str('tasa_punitoria')) ?>" inputmode="decimal" class="derecha" placeholder="automática">
          <span class="campo-ayuda">vacía: el <?= h(pct($puniPct, 0)) ?> de la TNA</span>
        </div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Agregar tasa', 'primary', null, 'agregar', true) ?></div>
  </div>
</form>
<?php endif; ?>
<div class="card">
  <?= card_head('Tasas', 'rige la de vigencia más reciente que ya empezó y cubre el plazo y el monto pedidos',
                $nueva ? '' : boton('Nueva tasa', 'primary', 'tipos_asociado.php?id=' . $id . '&solapa=tasas&nueva=1', 'agregar', false, true)) ?>
  <?= barra_local('tipos_asociado.php?id=' . $id . '&solapa=tasas', 'Vigencia, plazo o TNA…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="tasa|tasas">
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <th>Vigente desde</th>
          <th class="th-right">Plazo</th>
          <th class="th-right">Monto</th>
          <th class="th-right">TNA</th>
          <th class="th-right">TEM</th>
          <th class="th-right">TEA</th>
          <th class="th-right">Punitoria (TNA)</th>
          <th class="th-right">Préstamos</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tasas): ?>
        <tr><td colspan="10" class="no-data">Sin tasas: el tipo de asociado no se puede simular ni liquidar hasta cargar una.</td></tr>
      <?php endif; ?>
      <?php
        $hoy = date('Y-m-d');
        $vigente = null;
        foreach ($tasas as $t) {
            if ($t['vigencia_desde'] <= $hoy && ($vigente === null || $t['vigencia_desde'] > $vigente)) {
                $vigente = $t['vigencia_desde'];
            }
        }
        foreach ($tasas as $t):
            $tem = (float) $t['tna'] / 12;
            $tea = pow(1 + $tem, 12) - 1;
            $est = $t['vigencia_desde'] > $hoy ? estado('Futura', 'info')
                 : ($t['vigencia_desde'] === $vigente ? estado('Vigente', 'ok') : estado('Anterior', 'neutro'));
      ?>
        <tr>
          <td><div class="acciones">
            <?= (int) $t['usos'] === 0
                  ? accion_post('tipos_asociado.php?id=' . $id . '&solapa=tasas', ['accion' => 'borrar_tasa', 'id_tasa' => (int) $t['id_tasa']], 'borrar', 'Eliminar',
                                'Se elimina la tasa del ' . fecha($t['vigencia_desde']) . ' (TNA ' . pct($t['tna']) . '). Ningún préstamo la usó.', 'borrar')
                  : '<span class="btn-accion borrar deshabilitado" title="La usan ' . (int) $t['usos'] . ' préstamos: no se puede eliminar">' . icono('borrar') . '</span>' ?>
          </div></td>
          <td data-orden="<?= h($t['vigencia_desde']) ?>"><?= h(fecha($t['vigencia_desde'])) ?></td>
          <td class="td-right" data-orden="<?= h($t['plazo_desde']) ?>"><?= h($t['plazo_desde']) ?> a <?= h($t['plazo_hasta']) ?> cuotas</td>
          <td class="td-right" data-orden="<?= h($t['monto_desde']) ?>"><?= money($t['monto_desde']) ?> a <?= money($t['monto_hasta']) ?></td>
          <td class="td-right td-fuerte" data-orden="<?= h($t['tna']) ?>"><?= pct($t['tna']) ?></td>
          <td class="td-right" data-orden="<?= h($tem) ?>"><?= pct($tem, 3) ?></td>
          <td class="td-right" data-orden="<?= h($tea) ?>"><?= pct($tea) ?></td>
          <td class="td-right">
            <?php if ($t['tasa_punitoria'] !== null): ?>
              <?= pct($t['tasa_punitoria']) ?>
            <?php else: ?>
              <?= pct((float) $t['tna'] * $puniPct) ?><span class="segunda-linea"><?= h(pct($puniPct, 0)) ?> de la TNA</span>
            <?php endif; ?>
          </td>
          <td class="td-right" data-orden="<?= (int) $t['usos'] ?>"><?= (int) $t['usos'] ? num($t['usos']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= $est ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php
// ── Solapa Escalas de comisión ───────────────────────────────────────────
elseif ($solapa === 'escalas'):
    $editar = get_str('escala');
    if ($escalaForm !== null || $editar !== '') {
        $fila = $editar !== '' && $editar !== 'nueva'
            ? q1('SELECT * FROM comision_reglas WHERE id_regla = ? AND id_tipo_asociado = ? AND id_vendedor IS NULL', [(int) $editar, $id]) : null;
        $vals = $escalaForm ?? escala_valores($fila, $id);
        echo escala_form($vals, 'tipos_asociado.php?id=' . $id . '&solapa=escalas' . ($editar !== '' ? '&escala=' . rawurlencode($editar) : ''),
                         'tipos_asociado.php?id=' . $id . '&solapa=escalas', null,
                         (int) $vals['id_regla'] ? 'Editar la escala' : 'Nueva escala de comisión');
    }
    $huecos = escalas_huecos($escalas, (float) $tipo['monto_min'], (float) $tipo['monto_max']);
?>
<div class="card">
  <?= card_head('Escalas de comisión', 'para todos los vendedores · por tramo del monto del préstamo',
                boton('Nueva escala', 'primary', 'tipos_asociado.php?id=' . $id . '&solapa=escalas&escala=nueva', 'agregar', false, true)) ?>
  <div class="bloque">
    <p class="nota-arbol">Al liquidar un préstamo de este tipo se usa la escala activa y vigente cuyo tramo incluye el monto prestado; si la operación vino por telemarketing, con su % de telemarketing. Si el vendedor tiene una excepción para este tipo y ese monto, se usa la suya. El % queda guardado en el préstamo: cambiar una escala no modifica lo ya liquidado. Para un aumento, cerrá la escala con la vigencia «hasta» y cargá otra desde la fecha nueva.</p>
    <?php if ($huecos && $escalas): ?>
      <?php alerta('Hoy no tienen escala los préstamos ' . implode(' y ', array_map(function ($x) { return escala_tramo_frase($x[0], $x[1]); }, $huecos))
                   . ': no generan comisión, salvo que el vendedor tenga una excepción.', 'warning'); ?>
    <?php elseif (!$escalas): ?>
      <?php alerta('Sin escalas: los préstamos de este tipo no generan comisión, salvo que el vendedor tenga una excepción.', 'warning'); ?>
    <?php endif; ?>
  </div>
  <?= escalas_grilla($escalas, 'tipos_asociado.php?id=' . $id . '&solapa=escalas', false, 'escala|escalas',
                     'Sin escalas. Cargá la primera con «Nueva escala».') ?>
</div>
<?php
// ── Solapa Excepciones de vendedores ─────────────────────────────────────
else:
?>
<div class="card">
  <?= card_head('Excepciones de vendedores', 'los que cobran distinto en este tipo: se cargan en la ficha del vendedor') ?>
  <?= barra_local('tipos_asociado.php?id=' . $id . '&solapa=excepciones', 'Vendedor o monto…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="excepción|excepciones">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Vendedor</th><th class="th-right">Desde</th><th class="th-right">Hasta</th>
        <th class="th-right">% comisión</th><th class="th-right">% con telemarketing</th><th>Vigencia</th></tr></thead>
      <tbody>
      <?php if (!$excepciones): ?>
        <tr><td colspan="7" class="no-data">Todos los vendedores cobran por la escala general.</td></tr>
      <?php endif; ?>
      <?php foreach ($excepciones as $x): ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'vendedores.php?edit=' . (int) $x['id_vendedor'] . '&solapa=comisiones&escala=' . (int) $x['id_regla'], 'Editar en la ficha del vendedor') ?></div></td>
          <td class="td-fuerte"><?= h($x['vendedor_nombre']) ?><span class="segunda-linea mono"><?= h($x['vendedor_codigo']) ?></span></td>
          <td class="td-right" data-orden="<?= h($x['monto_desde']) ?>"><?= money($x['monto_desde']) ?></td>
          <td class="td-right" data-orden="<?= h($x['monto_hasta']) ?>"><?= (float) $x['monto_hasta'] >= ESCALA_MONTO_MAX ? '<span class="td-muted">sin tope</span>' : money($x['monto_hasta']) ?></td>
          <td class="td-right td-fuerte" data-orden="<?= h($x['porcentaje']) ?>"><?= h(escala_pct_campo($x['porcentaje'])) ?> %</td>
          <td class="td-right" data-orden="<?= h($x['porcentaje_tm'] ?? $x['porcentaje']) ?>"><?= $x['porcentaje_tm'] === null ? '<span class="td-muted">el mismo</span>' : h(escala_pct_campo($x['porcentaje_tm'])) . ' %' ?></td>
          <td data-orden="<?= h($x['vigencia_desde']) ?>">desde el <?= h(fecha($x['vigencia_desde'])) ?><?= $x['vigencia_hasta'] ? '<span class="segunda-linea">hasta el ' . h(fecha($x['vigencia_hasta'])) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
