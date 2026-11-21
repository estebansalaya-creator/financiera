<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/solicitudes.php';

// ---------------------------------------------------------------------
// Solicitud de préstamo
//   solicitud.php               nueva (?cliente=N viene de la ficha del cliente)
//   solicitud.php?id=N          la ficha: datos, controles, documentación,
//                               historial y, según el usuario y el estado,
//                               autorizar (aprobar / observar / rechazar),
//                               anular o ir a liquidarla
//   solicitud.php?id=N&editar=1 modificarla (pendiente u observada); al
//                               guardar vuelve a quedar pendiente
//   Cargar es en dos pasos, como el préstamo: Revisar (cuota y controles)
//   y Enviar a autorización. Lo que se revisa es lo que se envía.
//   El préstamo recién nace al liquidar la solicitud aprobada.
// ---------------------------------------------------------------------

$id          = (int) get_str('id');
$autoriza    = sol_autoriza();
$liquida     = sol_liquida();
$puedeCargar = puede('solicitudes.php');
$volver      = $puedeCargar ? 'solicitudes.php' : 'autorizaciones.php';
$errores     = [];
$aviso       = '';
$sol         = null;

try {
    if ($id > 0) {
        $sol = sol_cargar($id);
        if (!$sol) {
            flash('warning', 'Esa solicitud no existe o no es tuya.');
            redirigir($volver);
        }
    } elseif (!$puedeCargar) {
        redirigir($volver);
    }
} catch (PDOException $e) {
    $sinTablas = (int) ($e->errorInfo[1] ?? 0) === 1146;
    layout_inicio('Solicitud', '');
    titulo('Solicitud de préstamo');
    mensajes([$sinTablas ? 'Faltan las tablas de solicitudes: corré sql\financiera_solicitudes.sql.' : error_amigable($e)]);
    layout_fin();
    exit;
}

$accion   = es_post() ? post_str('accion') : '';
$editable = $sol && $puedeCargar && in_array($sol['estado'], ['PENDIENTE', 'OBSERVADA'], true);
$modoForm = !$sol || ($editable && (get_str('editar') === '1' || in_array($accion, ['revisar', 'enviar'], true)));
$anulable = $sol && ((in_array($sol['estado'], ['PENDIENTE', 'OBSERVADA'], true) && ($puedeCargar || $autoriza))
                     || ($sol['estado'] === 'APROBADA' && ($autoriza || $liquida)));
if (es_post()) {
    csrf_exigir();
}

/**
 * Simula a hoy y corre los controles, con las otras solicitudes en curso
 * del cliente ($excluir: la que se está modificando o controlando).
 * Devuelve [resumen, cuadro, controles].
 */
function sol_revisar(array $cliente, int $tipo, float $monto, int $plazo, int $excluir = 0): array
{
    [$res, $cuadro] = prestamo_simular($tipo, $monto, $plazo, date('Y-m-d'), null);
    [$ctl] = prestamo_controles($cliente, (float) $res['valor_cuota']);
    $ctl = sol_controles_limpios($ctl);
    $ctl = array_merge($ctl, sol_controles_en_curso($cliente, sol_en_curso((int) $cliente['id_cliente'], $excluir),
                                                    $tipo, $monto, $plazo, (float) $res['valor_cuota'], $excluir === 0));
    return [$res, $cuadro, $ctl];
}

// =====================================================================
// Ficha: autorizar, anular, volver a controlar
// =====================================================================
if ($sol && !$modoForm && es_post()) {
    $coment = post_str('comentario');
    // La versión que la persona tenía a la vista (null: formulario de antes del script).
    $versionVista = post_str('version') !== '' ? (int) post_str('version') : null;
    $largo  = preg_match_all('/./su', $coment);
    try {
        if ($accion === 'decidir' && $autoriza) {
            $decision = post_str('decision');
            $ctl = sol_controles($sol['controles']);
            if ($sol['estado'] !== 'PENDIENTE') {
                $errores[] = 'La solicitud ya no está pendiente (está ' . strtolower(ESTADOS_SOLICITUD[$sol['estado']]) . ').';
            } elseif (!in_array($decision, ['APROBADA', 'OBSERVADA', 'RECHAZADA'], true)) {
                $errores[] = 'Elegí qué hacer: aprobar, observar o rechazar.';
            } elseif ($decision !== 'APROBADA' && $largo < SOLICITUD_COMENTARIO_MIN) {
                $errores[] = ($decision === 'OBSERVADA' ? 'Para observarla, escribí qué tiene que corregir o completar'
                                                        : 'Para rechazarla, escribí el motivo') . ' (al menos ' . SOLICITUD_COMENTARIO_MIN . ' letras).';
            } elseif ($decision === 'APROBADA') {
                $falta = sol_documentacion_falta(sol_documentacion((int) $sol['id_cliente'], (string) $sol['cliente_tipo']));
                if ($sol['cliente_estado'] !== 'ACTIVO') {
                    $errores[] = 'El cliente está ' . strtolower($sol['cliente_estado']) . ': no se le puede aprobar un préstamo.';
                } elseif ($falta) {
                    $errores[] = 'No se puede aprobar: falta documentación obligatoria (' . implode(', ', $falta) . '). Cargala en la ficha del cliente, o observá la solicitud para pedirla.';
                } elseif (controles_hay($ctl, 'bloqueo')) {
                    $errores[] = 'Hay controles que impiden aprobarla. Si ya se resolvieron, tocá «Volver a controlar».';
                } elseif (controles_hay($ctl, 'advertencia') && $largo < SOLICITUD_COMENTARIO_MIN) {
                    $errores[] = 'Hay advertencias: para aprobarla igual, escribí por qué (al menos ' . SOLICITUD_COMENTARIO_MIN . ' letras). Queda guardado.';
                }
            }
            if (!$errores) {
                if (sol_transicion($id, ['PENDIENTE'], $decision, ['resuelto_el' => 'NOW()', 'resuelto_por' => usuario_id(),
                                                                   'resolucion' => $coment !== '' ? substr_utf8($coment, 500) : null],
                                   $versionVista)) {
                    sol_historial($id, $decision, $coment);
                    flash($decision === 'APROBADA' ? 'ok' : 'warning', 'Solicitud ' . $sol['nro_solicitud'] . ' '
                          . ['APROBADA' => 'aprobada: queda lista para liquidar.', 'OBSERVADA' => 'observada: vuelve al que la cargó.',
                             'RECHAZADA' => 'rechazada.'][$decision]);
                    redirigir('autorizaciones.php');
                }
                $errores[] = sol_por_que_no($id, ['PENDIENTE']);
            }
        } elseif ($accion === 'anular' && $anulable) {
            if ($largo < SOLICITUD_COMENTARIO_MIN) {
                $errores[] = 'Para anularla, escribí el motivo (al menos ' . SOLICITUD_COMENTARIO_MIN . ' letras).';
            } elseif (sol_transicion($id, ['PENDIENTE', 'OBSERVADA', 'APROBADA'], 'ANULADA', [], $versionVista)) {
                sol_historial($id, 'ANULADA', $coment);
                flash('warning', 'Solicitud ' . $sol['nro_solicitud'] . ' anulada.');
                redirigir('solicitud.php?id=' . $id);
            } else {
                $errores[] = sol_por_que_no($id, ['PENDIENTE', 'OBSERVADA', 'APROBADA']);
            }
        } elseif ($accion === 'controlar' && $sol['estado'] === 'PENDIENTE' && ($autoriza || $puedeCargar)) {
            $cli = sol_buscar_cliente((string) $sol['id_cliente'], '')[0];
            [$res, , $ctl] = sol_revisar($cli, (int) $sol['id_tipo_asociado'], (float) $sol['monto'], (int) $sol['plazo'], $id);
            q('UPDATE solicitudes SET tna = ?, valor_cuota = ?, neto_desembolso = ?, total_a_pagar = ?, controles = ?, controles_el = NOW()'
              . (columna_existe('solicitudes', 'version') ? ', version = version + 1' : '') . '
                WHERE id_solicitud = ? AND estado = ?',
              [$res['tna'], $res['valor_cuota'], $res['neto_desembolso'], $res['total_a_pagar'], json_encode($ctl, JSON_UNESCAPED_UNICODE), $id, 'PENDIENTE']);
            flash('ok', 'Controles y cuota actualizados a hoy.');
            redirigir('solicitud.php?id=' . $id);
        }
    } catch (Throwable $e) {
        $errores[] = mensaje_bd($e);
    }
}

// =====================================================================
// Formulario: nueva o modificar
// =====================================================================
$cliente = null;
$candidatos = [];          // búsqueda por nombre con varias coincidencias: se elige de la lista
$res = null;
$cuadro = [];
$ctl = [];
$docs = [];
$revisado = false;
$tipos = $vendedores = [];
$vendPropio = null;
$f = [];
$firma = '';

if ($modoForm) {
    $campos = ['cliente', 'buscar', 'tipo', 'monto', 'plazo', 'vendedor', 'telemarketing', 'destino', 'observaciones', 'comentario'];
    foreach ($campos as $c) {
        $f[$c] = es_post() ? post_str($c) : get_str($c);
    }
    $elegido = es_post() ? (int) post_str('elegir') : 0;           // de la lista de coincidencias
    if (!$sol && $elegido > 0) {
        $f['cliente'] = (string) $elegido;
        $f['buscar'] = '';
    }
    if ($sol) {
        $f['cliente'] = (string) $sol['id_cliente'];                 // el cliente de una solicitud no se cambia
        if (!es_post()) {
            $f['tipo'] = (string) $sol['id_tipo_asociado'];
            $f['monto'] = str_replace('.', ',', (string) (float) $sol['monto']);
            $f['plazo'] = (string) $sol['plazo'];
            $f['vendedor'] = (string) $sol['id_vendedor'];
            $f['telemarketing'] = (string) $sol['telemarketing'];
            $f['destino'] = (string) $sol['destino'];
            $f['observaciones'] = (string) $sol['observaciones'];
        }
    }
    $f['telemarketing'] = $f['telemarketing'] === '1' ? '1' : '0';

    try {
        $tipos = q("SELECT p.*, EXISTS(SELECT 1 FROM tasas_vigencia t WHERE t.id_tipo_asociado = p.id_tipo_asociado
                                            AND t.vigencia_desde <= CURDATE()) AS con_tasa
                          FROM tipos_asociado p WHERE p.activo = 1 ORDER BY p.nombre");
        $vendedores = q('SELECT id_vendedor, nombre FROM vendedores WHERE activo = 1 ORDER BY nombre');
        $vendPropio = sol_ve_todas() ? null : sol_vendedor_propio();

        if ($f['cliente'] !== '' || $f['buscar'] !== '') {
            $encontrados = sol_buscar_cliente($f['cliente'], $f['buscar']);
            if (count($encontrados) === 1) {
                $cliente = $encontrados[0];
                $f['cliente'] = (string) $cliente['id_cliente'];
                if ($f['vendedor'] === '' && !es_post()) {
                    $f['vendedor'] = (string) $cliente['id_vendedor'];
                }
                if ($f['tipo'] === '' && !es_post() && !$sol) {
                    $f['tipo'] = (string) ($cliente['id_tipo_asociado'] ?? '');   // el habitual del cliente: se puede cambiar
                }
            } elseif (count($encontrados) > 1) {
                $candidatos = $encontrados;
                $f['cliente'] = '';
            } elseif (es_post() || $f['buscar'] !== '') {
                $porNombre = (bool) preg_match('/\p{L}/u', $f['buscar']);
                $errores[] = $porNombre && preg_match_all('/./su', trim($f['buscar'])) < 3
                    ? 'Para buscar por nombre escribí al menos 3 letras.'
                    : 'No hay un cliente con ' . ($f['buscar'] !== '' ? '«' . $f['buscar'] . '»' : 'ese número')
                      . ($porNombre && !sol_ve_todas() ? ' entre tus clientes (por nombre se buscan los de tu cartera; con el CUIT, CUIL o DNI, todos)' : '')
                      . '. Si es nuevo, dalo de alta primero en Clientes.';
            }
        }
        if ($vendPropio) {
            $f['vendedor'] = (string) $vendPropio;                   // un vendedor carga a su nombre
        }
    } catch (Throwable $e) {
        $errores[] = error_amigable($e);
    }

    $firmar = function () use (&$f) {
        return hash('sha256', implode('|', [$f['cliente'], $f['tipo'], $f['monto'], $f['plazo'], $f['vendedor'], $f['telemarketing']]));
    };

    if (es_post() && in_array($accion, ['revisar', 'enviar'], true) && !$errores) {
        $monto = leer_num($f['monto']);
        $plazo = leer_num($f['plazo']);
        if (!$cliente) {
            $errores[] = 'Elegí el cliente.';
        }
        if ((int) $f['tipo'] <= 0) {
            $errores[] = 'Elegí el tipo de asociado.';
        }
        if ($monto === null || $monto <= 0) {
            $errores[] = 'Escribí el monto que pide.';
        }
        if ($plazo === null || $plazo < 1 || floor($plazo) != $plazo) {
            $errores[] = 'El plazo va en cuotas enteras.';
        }
        if ($f['vendedor'] !== '' && !in_array((int) $f['vendedor'], array_map('intval', array_column($vendedores, 'id_vendedor')), true)) {
            $errores[] = 'Elegí el vendedor de la lista.';
        }
        if (preg_match_all('/./su', $f['destino']) > 150) {
            $errores[] = 'El destino del préstamo: hasta 150 caracteres.';
        }
        if (preg_match_all('/./su', $f['observaciones']) > 500) {
            $errores[] = 'Las observaciones: hasta 500 caracteres.';
        }
        if (!$errores) {
            try {
                [$res, $cuadro, $ctl] = sol_revisar($cliente, (int) $f['tipo'], $monto, (int) $plazo, $sol ? $id : 0);
                $revisado = true;
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
                $res = null;
            }
        }
        if ($accion === 'enviar' && $revisado && post_str('firma') !== $firmar()) {
            $aviso = 'Cambiaste datos después de revisar: mirá de nuevo la cuota y los controles antes de enviar.';
            $accion = 'revisar';
        }
        if ($accion === 'enviar' && $revisado) {
            if (controles_hay($ctl, 'bloqueo')) {
                $errores[] = 'Hay controles que impiden cargar la solicitud.';
            } else {
                $pdo = db();
                $conc = sol_concurrencia_instalada();
                $idCli = (int) $cliente['id_cliente'];
                $eraObservada = $sol && $sol['estado'] === 'OBSERVADA';
                $versionVista = post_str('version') !== '' ? (int) post_str('version') : null;
                $nuevoId = 0;
                $nro = '';
                try {
                    // Una transacción corta: los controles lentos (el BCRA) ya se
                    // corrieron afuera. Bloquea solo la fila del cliente: dos
                    // solicitudes del mismo cliente se graban de a una y las de
                    // clientes distintos no se esperan. En READ COMMITTED, lo que se
                    // lee después del bloqueo es lo último grabado: la segunda ve a
                    // la primera (una igual se frena; las demás quedan advertidas).
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    $pdo->beginTransaction();
                    q('SELECT id_cliente FROM clientes WHERE id_cliente = ? FOR UPDATE', [$idCli]);
                    $ctl = array_merge(sol_controles_sin_en_curso($ctl),
                                       sol_controles_en_curso($cliente, sol_en_curso($idCli, $sol ? $id : 0), (int) $f['tipo'], $monto,
                                                              (int) $plazo, (float) $res['valor_cuota'], !$sol));
                    foreach ($ctl as $c) {
                        if (($c['n'] ?? '') === 'bloqueo') {
                            throw new SolicitudFrenada($c['t']);
                        }
                    }
                    $datos = [(int) $f['tipo'], (int) $f['vendedor'] ?: null, (int) $f['telemarketing'], round($monto, 2), (int) $plazo,
                              $f['destino'] !== '' ? $f['destino'] : null, $f['observaciones'] !== '' ? $f['observaciones'] : null,
                              $res['tna'], $res['valor_cuota'], $res['neto_desembolso'], $res['total_a_pagar'], json_encode($ctl, JSON_UNESCAPED_UNICODE)];
                    if ($sol) {
                        $conVersion = $conc && $versionVista !== null;
                        $st = $pdo->prepare("UPDATE solicitudes SET id_tipo_asociado=?, id_vendedor=?, telemarketing=?, monto=?, plazo=?, destino=?, observaciones=?,
                                                    tna=?, valor_cuota=?, neto_desembolso=?, total_a_pagar=?, controles=?, controles_el=NOW(),
                                                    enviado_el = IF(estado = 'OBSERVADA', NOW(), enviado_el), estado = 'PENDIENTE'"
                                          . ($conc ? ', version = version + 1' : '') . "
                                              WHERE id_solicitud = ? AND estado IN ('PENDIENTE','OBSERVADA')" . ($conVersion ? ' AND version = ?' : ''));
                        $st->execute(array_merge($datos, [$id], $conVersion ? [$versionVista] : []));
                        if ($st->rowCount() !== 1) {
                            $f2 = q1('SELECT estado FROM solicitudes WHERE id_solicitud = ?', [$id]);
                            throw new SolicitudFrenada($f2 && in_array($f2['estado'], ['PENDIENTE', 'OBSERVADA'], true)
                                ? 'Otra persona cambió la solicitud mientras la modificabas: volvé a abrirla y revisala.'
                                : 'La solicitud cambió de estado recién y ya no se puede modificar.');
                        }
                        sol_historial($id, $eraObservada ? 'REENVIADA' : 'MODIFICADA', $f['comentario']);
                    } elseif ($conc) {
                        $nro = sol_nuevo_nro();
                        q('INSERT INTO solicitudes (nro_solicitud, id_cliente, id_tipo_asociado, id_vendedor, telemarketing, monto, plazo, destino, observaciones,
                                                    tna, valor_cuota, neto_desembolso, total_a_pagar, controles, controles_el, creado_por)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)',
                          array_merge([$nro, $idCli], $datos, [usuario_id()]));
                        $nuevoId = (int) $pdo->lastInsertId();
                        sol_historial($nuevoId, 'CREADA', null);
                    } else {
                        // Sin financiera_solicitudes_concurrencia.sql: el número de antes (S-000001).
                        for ($intento = 0; $intento < 3 && !$nuevoId; $intento++) {
                            $nro = sol_proximo_nro($intento);
                            try {
                                q('INSERT INTO solicitudes (nro_solicitud, id_cliente, id_tipo_asociado, id_vendedor, telemarketing, monto, plazo, destino, observaciones,
                                                            tna, valor_cuota, neto_desembolso, total_a_pagar, controles, controles_el, creado_por)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)',
                                  array_merge([$nro, $idCli], $datos, [usuario_id()]));
                                $nuevoId = (int) $pdo->lastInsertId();
                            } catch (PDOException $e) {
                                if ((int) ($e->errorInfo[1] ?? 0) === 1062 && strpos($e->getMessage(), 'uq_solicitudes_nro') !== false) {
                                    continue;                      // otro cargó al mismo tiempo: siguiente número
                                }
                                throw $e;
                            }
                        }
                        if (!$nuevoId) {
                            throw new SolicitudFrenada('No se pudo asignar un número de solicitud. Probá de nuevo.');
                        }
                        sol_historial($nuevoId, 'CREADA', null);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errores[] = $e instanceof SolicitudFrenada ? $e->getMessage() : mensaje_bd($e);
                }
                if (!$errores) {
                    if ($sol) {
                        flash('ok', 'Solicitud ' . $sol['nro_solicitud'] . ($eraObservada ? ' enviada de nuevo a autorización.' : ' modificada.'));
                        redirigir('solicitud.php?id=' . $id);
                    }
                    flash('ok', 'Solicitud ' . $nro . ' enviada a autorización: ' . money($monto) . ' en ' . (int) $plazo . ' cuotas de ' . money($res['valor_cuota']) . '.');
                    redirigir('solicitud.php?id=' . $nuevoId);
                }
            }
        }
    }
    $firma = $firmar();
}

$idCliente = $cliente ? (int) $cliente['id_cliente'] : ($sol ? (int) $sol['id_cliente'] : 0);
$tipoCliente = $cliente ? (string) $cliente['tipo'] : ($sol ? (string) $sol['cliente_tipo'] : '');
if ($idCliente && (!$modoForm || $revisado)) {
    $docs = sol_documentacion($idCliente, $tipoCliente);
}
$docFalta = sol_documentacion_falta($docs);
$verCliente = puede('clientes.php');

/** La lista de documentación: cada documento, si es obligatorio u opcional y cómo está. */
function documentacion_html(array $docs): string
{
    if (!$docs) {
        return '<p class="campo-ayuda" style="margin:0">No hay documentación definida para este tipo de cliente.</p>';
    }
    $o = '<ul class="controles">';
    foreach ($docs as $d) {
        $obl = (int) $d['obligatorio'] === 1;
        $ok = $d['estado'] === 'OK';
        $clase = $ok ? 'ok' : ($obl ? 'danger' : 'info');
        $txt = $ok ? 'cargado' . ($d['ultimo_vencimiento'] ? ', vence el ' . fecha($d['ultimo_vencimiento']) : '')
                   : ($d['estado'] === 'VENCIDO' ? 'vencido el ' . fecha($d['ultimo_vencimiento']) : 'falta');
        $o .= '<li class="control-' . $clase . '">' . icono($ok ? 'ok' : ($obl ? 'error' : 'info'), 15)
            . '<span><strong>' . h($d['nombre']) . '</strong> · ' . ($obl ? 'obligatorio' : 'opcional') . ' · ' . h($txt) . '</span></li>';
    }
    return $o . '</ul>';
}

// =====================================================================
// Pantalla
// =====================================================================
$tituloPag = $sol ? 'Solicitud ' . $sol['nro_solicitud'] : 'Nueva solicitud';
$migas = '<a href="' . h($volver) . '">' . ($puedeCargar ? 'Solicitudes' : 'Autorizaciones') . '</a> / ' . h($sol ? $sol['nro_solicitud'] : 'Nueva');
$acciones = '';
if ($sol && !$modoForm) {
    if ($editable) {
        $acciones .= boton($sol['estado'] === 'OBSERVADA' ? 'Corregir y reenviar' : 'Modificar', $sol['estado'] === 'OBSERVADA' ? 'primary' : 'secondary',
                           'solicitud.php?id=' . $id . '&editar=1', 'editar');
    }
    if ($sol['estado'] === 'APROBADA' && $liquida) {
        $acciones .= boton('Liquidar', 'ok', 'prestamo_nuevo.php?solicitud=' . $id, 'dinero');
    }
    if ($sol['estado'] === 'LIQUIDADA' && $sol['id_prestamo'] && (puede('prestamos.php') || puede('cobrar.php') || puede('cobranzas.php'))) {
        $acciones .= boton('Ver el préstamo ' . $sol['nro_prestamo'], 'primary', 'prestamo.php?id=' . (int) $sol['id_prestamo'], 'ver');
    }
}

layout_inicio($tituloPag, '');
titulo($tituloPag . ($sol && $modoForm ? ' · modificar' : ''),
       $cliente ? $cliente['denominacion'] . ' · ' . cuit_fmt($cliente['cuit']) : ($sol ? $sol['denominacion'] . ' · ' . cuit_fmt($sol['cuit']) : 'Se revisa, se envía y la autoriza Gerencia'),
       $acciones, $migas);
if ($aviso !== '') {
    alerta($aviso, 'warning');
}
mensajes($errores);

if ($modoForm):
    if ($sol && $sol['estado'] === 'OBSERVADA' && $sol['resolucion']) {
        alerta('Observada por ' . ($sol['resuelto_por_nombre'] ?: 'el autorizante') . ': ' . $sol['resolucion'], 'warning');
    }
?>
<form method="post" action="solicitud.php<?= $sol ? '?id=' . $id . '&amp;editar=1' : '' ?>" id="frm-solicitud">
  <?= csrf_campo() ?>
  <input type="hidden" name="firma" value="<?= h($firma) ?>">
  <?php if ($sol && isset($sol['version'])): ?><input type="hidden" name="version" value="<?= h(post_str('version') !== '' ? post_str('version') : (string) $sol['version']) ?>"><?php endif; ?>
  <div class="card">
    <?= card_head('Datos de la solicitud') ?>
    <div class="bloque">
      <div class="campos">
        <?php if ($cliente): ?>
          <input type="hidden" name="cliente" value="<?= (int) $cliente['id_cliente'] ?>">
          <div class="campo" style="--span:5">
            <span class="campo-label">Cliente</span>
            <div class="campo-fijo">
              <?= $verCliente ? '<a href="cliente.php?id=' . (int) $cliente['id_cliente'] . '"><strong>' . h($cliente['denominacion']) . '</strong></a>'
                              : '<strong>' . h($cliente['denominacion']) . '</strong>' ?>
              <span class="mono"><?= h(cuit_fmt($cliente['cuit'])) ?></span>
              <?= $cliente['estado'] !== 'ACTIVO' ? estado(ucfirst(strtolower($cliente['estado'])), 'danger') : '' ?>
              <?php if (!$sol): ?><a href="solicitud.php" class="campo-cambiar">cambiar</a><?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="campo" style="--span:4">
            <label for="buscar">Cliente <span class="req">*</span></label>
            <input id="buscar" name="buscar" value="<?= h($f['buscar']) ?>" placeholder="Nombre, CUIT, CUIL o DNI" autocomplete="off" autofocus>
            <?php if ($verCliente): ?><span class="campo-ayuda">¿Es nuevo? <a href="cliente.php">Dalo de alta</a> y volvé.</span><?php endif; ?>
          </div>
          <div class="campo campo-boton arriba" style="--span:1">
            <span class="campo-label">&nbsp;</span>
            <button type="submit" name="accion" value="buscar" class="btn btn-secondary" formnovalidate>Buscar</button>
          </div>
          <?php if ($candidatos): ?>
          <div class="campo" style="--span:12">
            <span class="campo-label">Elegí el cliente · <?= count($candidatos) > SOL_CLIENTES_MAX
                ? 'hay más de ' . SOL_CLIENTES_MAX . ': escribí más del nombre o buscalo por CUIT'
                : count($candidatos) . ' coincidencias' ?></span>
            <div class="cli-candidatos">
              <?php foreach (array_slice($candidatos, 0, SOL_CLIENTES_MAX) as $cc): ?>
              <button type="submit" name="elegir" value="<?= (int) $cc['id_cliente'] ?>" class="cli-candidato" formnovalidate>
                <span class="cli-candidato-nom"><strong><?= h($cc['denominacion']) ?></strong>
                  <?= !empty($cc['nombre_fantasia']) && $cc['nombre_fantasia'] !== $cc['denominacion'] ? '<small>' . h($cc['nombre_fantasia']) . '</small>' : '' ?></span>
                <span class="mono"><?= h(cuit_fmt($cc['cuit'])) ?></span>
                <?= $cc['estado'] !== 'ACTIVO' ? estado(ucfirst(strtolower($cc['estado'])), 'danger') : '' ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="campo" style="--span:4">
          <label for="tipo">Tipo de asociado <span class="req">*</span></label>
          <select id="tipo" name="tipo" required>
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
          <?php if ($vendPropio): ?>
            <span class="campo-label">Vendedor</span>
            <div class="campo-fijo"><strong><?= h(array_column($vendedores, 'nombre', 'id_vendedor')[$vendPropio] ?? 'Vos') ?></strong></div>
          <?php else: ?>
            <label for="vendedor">Vendedor</label>
            <select id="vendedor" name="vendedor"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $f['vendedor'], 'Sin vendedor') ?></select>
          <?php endif; ?>
        </div>
        <div class="campo" style="--span:3">
          <label for="monto">Monto que pide <span class="req">*</span></label>
          <input id="monto" name="monto" value="<?= h($f['monto']) ?>" inputmode="decimal" class="derecha" required placeholder="0,00">
        </div>
        <div class="campo" style="--span:2">
          <label for="plazo">Cuotas <span class="req">*</span></label>
          <input id="plazo" name="plazo" value="<?= h($f['plazo']) ?>" inputmode="numeric" class="derecha" required>
        </div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="telemarketing" value="1" <?= $f['telemarketing'] === '1' ? 'checked' : '' ?>> Por telemarketing</label>
          <span class="campo-ayuda">define el % de comisión</span>
        </div>
        <div class="campo" style="--span:4">
          <label for="destino">Destino del préstamo</label>
          <input id="destino" name="destino" value="<?= h($f['destino']) ?>" maxlength="150" placeholder="Para qué lo pide">
        </div>
        <div class="campo" style="--span:12">
          <label for="observaciones">Observaciones para quien autoriza</label>
          <textarea id="observaciones" name="observaciones" maxlength="500" rows="2" placeholder="Lo que conviene saber del cliente o de la operación"><?= h($f['observaciones']) ?></textarea>
        </div>
        <?php if ($sol): ?>
        <div class="campo" style="--span:12">
          <label for="comentario"><?= $sol['estado'] === 'OBSERVADA' ? 'Qué corregiste' : 'Qué cambiaste' ?></label>
          <input id="comentario" name="comentario" value="<?= h($f['comentario']) ?>" maxlength="500" placeholder="Queda en el historial de la solicitud">
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="form-acciones">
      <?= boton('Cancelar', 'secondary', $sol ? 'solicitud.php?id=' . $id : $volver, 'volver') ?>
      <button type="submit" name="accion" value="revisar" class="btn btn-primary"><?= icono('filtro') ?><?= $revisado ? 'Revisar de nuevo' : 'Revisar' ?></button>
    </div>
  </div>

<?php if ($revisado && $res): ?>
  <div class="card">
    <?= card_head('Cuota estimada', (int) $res['plazo'] . ' cuotas · sistema francés · liquidando hoy') ?>
    <div class="bloque">
      <div class="bcra-tarjetas">
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Cuota</span><strong><?= money($res['valor_cuota']) ?></strong><span class="bcra-tarjeta-sub">capital + interés + IVA</span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Neto a desembolsar</span><strong><?= money($res['neto_desembolso']) ?></strong><span class="bcra-tarjeta-sub">gastos <?= money($res['gastos_otorg']) ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Total a pagar</span><strong><?= money($res['total_a_pagar']) ?></strong><span class="bcra-tarjeta-sub">intereses <?= money($res['total_intereses']) ?> · IVA <?= money($res['total_iva']) ?></span></div>
        <div class="bcra-tarjeta"><span class="bcra-tarjeta-lbl">Tasa</span><strong><?= pct($res['tna']) ?> TNA</strong><span class="bcra-tarjeta-sub">TEA <?= pct($res['tea']) ?></span></div>
      </div>
    </div>
  </div>

  <div class="card">
    <?= card_head('Controles', controles_hay($ctl, 'bloqueo') ? 'no se puede cargar' : (controles_hay($ctl, 'advertencia') ? 'con advertencias' : 'todo en orden')) ?>
    <div class="bloque"><?= controles_html($ctl) ?></div>
  </div>

  <div class="card">
    <?= card_head('Documentación', $docFalta ? 'falta obligatoria: no se va a poder aprobar hasta cargarla' : 'la obligatoria está completa') ?>
    <div class="bloque"><?= documentacion_html($docs) ?>
      <?php if ($docFalta && $verCliente): ?><p class="controles-motivo">Se carga en la <a href="cliente.php?id=<?= $idCliente ?>" target="_blank" rel="noopener">ficha del cliente</a>. Podés enviar la solicitud igual y subirla después.</p><?php endif; ?>
    </div>
    <?php if (!controles_hay($ctl, 'bloqueo')): ?>
    <div class="form-acciones">
      <span class="campo-ayuda" style="margin-right:auto"><?= controles_hay($ctl, 'advertencia') ? 'Sale con advertencias: quien autoriza las va a ver.' : '' ?></span>
      <button type="submit" name="accion" value="enviar" class="btn btn-ok"><?= icono('guardar') ?><?= $sol ? ($sol['estado'] === 'OBSERVADA' ? 'Reenviar a autorización' : 'Guardar cambios') : 'Enviar a autorización' ?></button>
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

<?php else:   // ------------------------------------------------ ficha
    $ctl = sol_controles($sol['controles']);
    $hist = q('SELECT h.*, u.nombre AS usuario FROM solicitudes_historial h LEFT JOIN usuarios u ON u.id_usuario = h.id_usuario
                WHERE h.id_solicitud = ? ORDER BY h.id_historial', [$id]);
    $otras = sol_abiertas_cliente((int) $sol['id_cliente'], $id);
    $quien = $sol['resuelto_por_nombre'] ?: 'el autorizante';
    $cuando = fecha_hora($sol['resuelto_el']);
    if ($sol['estado'] === 'OBSERVADA') {
        alerta('Observada por ' . $quien . ' el ' . $cuando . ': ' . $sol['resolucion'], 'warning');
    } elseif ($sol['estado'] === 'RECHAZADA') {
        alerta('Rechazada por ' . $quien . ' el ' . $cuando . ': ' . $sol['resolucion'], 'danger');
    } elseif ($sol['estado'] === 'APROBADA') {
        alerta('Aprobada por ' . $quien . ' el ' . $cuando . ($sol['resolucion'] ? ': ' . $sol['resolucion'] : '.') . ' Falta liquidarla.', 'ok');
    }
    if ($otras) {
        alerta('El cliente tiene ' . (count($otras) === 1 ? 'otra solicitud abierta: ' : 'otras solicitudes abiertas: ') . implode(', ', array_map(function ($o) {
            return $o['nro_solicitud'] . ' (' . strtolower(ESTADOS_SOLICITUD[$o['estado']]) . ', ' . money($o['monto']) . ')';
        }, $otras)) . '.', 'info');
    }
    $dato = function (string $lbl, string $html, bool $grande = false): string {
        return '<div><div class="dato-lbl">' . h($lbl) . '</div><div class="dato-val' . ($grande ? ' grande' : '') . '">' . $html . '</div></div>';
    };
?>
<div class="card">
  <?= card_head('Solicitud', 'cargada por ' . ($sol['creado_por_nombre'] ?: '—') . ' el ' . fecha_hora($sol['creado_el'])) ?>
  <div class="datos">
    <?= $dato('Estado', estado_solicitud($sol['estado'])) ?>
    <?= $dato('Monto pedido', money($sol['monto']), true) ?>
    <?= $dato('Cuotas', (int) $sol['plazo'] . ' de ' . money($sol['valor_cuota']), true) ?>
    <?= $dato('Cliente', $verCliente ? '<a href="cliente.php?id=' . (int) $sol['id_cliente'] . '">' . h($sol['denominacion']) . '</a>' : h($sol['denominacion'])) ?>
    <?= $dato('Tipo de asociado', h($sol['tipo_asociado'])) ?>
    <?= !empty($sol['reparticion']) ? $dato('Repartición', h($sol['reparticion'])) : '' ?>
    <?= $dato('TNA', pct($sol['tna'])) ?>
    <?= $dato('Neto a desembolsar', money($sol['neto_desembolso'])) ?>
    <?= $dato('Total a pagar', money($sol['total_a_pagar'])) ?>
    <?= $dato('Vendedor', $sol['vendedor'] ? h($sol['vendedor']) . ((int) $sol['telemarketing'] ? ' · telemarketing' : '') : '<span class="td-muted">sin vendedor</span>') ?>
    <?= $sol['destino'] ? $dato('Destino', h($sol['destino'])) : '' ?>
  </div>
  <?php if ($sol['observaciones']): ?>
  <div class="bloque"><div class="dato-lbl">Observaciones de quien la cargó</div><p style="margin:4px 0 0"><?= nl2br(h($sol['observaciones'])) ?></p></div>
  <?php endif; ?>
</div>

<div class="columnas-2-1">
  <div>
    <div class="card">
      <?php
      $btnCtl = '';
      if ($sol['estado'] === 'PENDIENTE' && ($autoriza || $puedeCargar)) {
          $btnCtl = '<form method="post" action="solicitud.php?id=' . $id . '">' . csrf_campo() . '<input type="hidden" name="accion" value="controlar">'
                  . boton('Volver a controlar', 'ghost', null, 'filtro', true, true) . '</form>';
      }
      echo card_head('Controles', $sol['controles_el'] ? 'al ' . fecha_hora($sol['controles_el']) : '', $btnCtl);
      ?>
      <div class="bloque"><?= $ctl ? controles_html($ctl) : '<p class="campo-ayuda" style="margin:0">Sin controles guardados.</p>' ?></div>
    </div>

    <div class="card">
      <?= card_head('Documentación', $docFalta ? 'falta obligatoria' : 'la obligatoria está completa', $verCliente
            ? boton('Ficha del cliente', 'ghost', 'cliente.php?id=' . (int) $sol['id_cliente'], 'subir', false, true) : '') ?>
      <div class="bloque"><?= documentacion_html($docs) ?></div>
    </div>
  </div>

  <div>
    <?php if ($sol['estado'] === 'PENDIENTE' && $autoriza): ?>
    <form method="post" action="solicitud.php?id=<?= $id ?>" class="card card-decision">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="decidir">
      <?php if (isset($sol['version'])): ?><input type="hidden" name="version" value="<?= (int) $sol['version'] ?>"><?php endif; ?>
      <?= card_head('Autorización', 'espera ' . sol_espera($sol['enviado_el'])) ?>
      <div class="bloque">
        <?php $dec = post_str('decision'); ?>
        <div class="decision">
          <label class="decision-op decision-ok<?= $docFalta ? ' deshabilitada' : '' ?>"><input type="radio" name="decision" value="APROBADA" <?= $dec === 'APROBADA' ? 'checked' : '' ?> <?= $docFalta ? 'disabled' : '' ?> required>
            <span><strong>Aprobar</strong><small><?= $docFalta ? 'falta documentación obligatoria' : 'queda lista para liquidar' ?></small></span></label>
          <label class="decision-op decision-warn"><input type="radio" name="decision" value="OBSERVADA" <?= $dec === 'OBSERVADA' ? 'checked' : '' ?> required>
            <span><strong>Observar</strong><small>vuelve al que la cargó para corregir o completar</small></span></label>
          <label class="decision-op decision-danger"><input type="radio" name="decision" value="RECHAZADA" <?= $dec === 'RECHAZADA' ? 'checked' : '' ?> required>
            <span><strong>Rechazar</strong><small>es definitivo</small></span></label>
        </div>
        <div class="campos" style="margin-top:12px">
          <div class="campo" style="--span:12"><label for="comentario">Comentario</label>
            <textarea id="comentario" name="comentario" maxlength="500" rows="3" placeholder="Obligatorio para observar, rechazar o aprobar con advertencias"><?= h($accion === 'decidir' ? post_str('comentario') : '') ?></textarea></div>
        </div>
      </div>
      <div class="form-acciones"><?= boton('Confirmar', 'primary', null, 'guardar', true) ?></div>
    </form>
    <?php endif; ?>

    <?php if ($anulable): ?>
    <form method="post" action="solicitud.php?id=<?= $id ?>" class="card"
          data-confirmar="Se anula la solicitud <?= h($sol['nro_solicitud']) ?> de <?= h($sol['denominacion']) ?>. No se puede deshacer."
          data-confirmar-titulo="Anular la solicitud" data-confirmar-boton="Anular" data-peligro>
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="anular">
      <?php if (isset($sol['version'])): ?><input type="hidden" name="version" value="<?= (int) $sol['version'] ?>"><?php endif; ?>
      <?= card_head('Anular', 'si el cliente desistió o se cargó por error') ?>
      <div class="bloque"><div class="campos">
        <div class="campo" style="--span:12"><label for="motivo-anular">Motivo</label>
          <input id="motivo-anular" name="comentario" maxlength="500" required minlength="<?= SOLICITUD_COMENTARIO_MIN ?>" value="<?= h($accion === 'anular' ? post_str('comentario') : '') ?>"></div>
      </div></div>
      <div class="form-acciones"><?= boton('Anular la solicitud', 'danger', null, 'cerrar', true, true) ?></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" id="mensajes">
  <?= card_head('Mensajes', 'los ven quien la cargó, el vendedor y quien la autoriza; avisan en el globito de Mensajes') ?>
  <div class="chat-hilo-solicitud" data-chat-hilo data-solicitud="<?= $id ?>"></div>
</div>

<div class="card">
  <?= card_head('Historial', count($hist) . (count($hist) === 1 ? ' paso' : ' pasos')) ?>
  <div class="data-table-wrap">
    <table class="data-table" data-sin-orden>
      <thead><tr><th data-no-sort>Fecha</th><th data-no-sort>Quién</th><th data-no-sort>Qué hizo</th><th data-no-sort>Comentario</th></tr></thead>
      <tbody>
      <?php foreach ($hist as $hh): ?>
        <tr><td class="td-nowrap"><?= h(fecha_hora($hh['fecha'])) ?></td><td><?= h($hh['usuario'] ?? '—') ?></td>
          <td><?= isset(ACCIONES_SOLICITUD_TIPO[$hh['accion']]) ? estado(ACCIONES_SOLICITUD[$hh['accion']], ACCIONES_SOLICITUD_TIPO[$hh['accion']]) : h(ACCIONES_SOLICITUD[$hh['accion']] ?? $hh['accion']) ?></td>
          <td style="white-space:normal"><?= h((string) $hh['comentario']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
