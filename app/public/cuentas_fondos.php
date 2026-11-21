<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/cobranzas.php';
require __DIR__ . '/../inc/fondos.php';

// ---------------------------------------------------------------------
// Cuentas de fondos (Tesorería), como el ABM de gestion_adm:
//   Cuentas      alta, edición, baja; en una cuenta banco, sus chequeras
//                (?edit=N&sub=chequeras, &chequera=M o &chequera=nueva)
//   Bancos       el catálogo de entidades (código BCRA = 3 primeros del CBU)
//                (?solapa=bancos, &banco=M o &banco=nuevo)
//   Por medio    la cuenta que viene elegida al cobrar con cada medio
//                (?solapa=medios&medio=X)
// Todas son una lista con la barra de las grillas y el formulario arriba.
// Una cuenta con movimientos no se borra ni cambia de tipo o moneda: se
// deshabilita (deja de ofrecerse; su historia queda). La predeterminada es
// la que viene elegida al desembolsar y al pagar: una por moneda.
// ---------------------------------------------------------------------

$errores = [];
$solapa  = in_array(get_str('solapa'), ['bancos', 'medios'], true) ? get_str('solapa') : 'cuentas';
$id      = (int) (es_post() ? post_str('id') : get_str('edit'));
$sub     = get_str('sub') === 'chequeras' ? 'chequeras' : 'datos';
$reg     = null;           // la cuenta del formulario
$chqForm = null;           // la chequera que volvió con error
$bcoForm = null;           // el banco que volvió con error
$medForm = null;           // el medio de cobro que volvió con error

/** Lo que tiene una cuenta: lo que impide borrarla o cambiarle el tipo o la moneda. */
function usos_cuenta(int $id): array
{
    $u = q1('SELECT (SELECT COUNT(*) FROM movimientos_fondos WHERE id_cuenta_fondos = ?) AS movimientos,
                    (SELECT COUNT(*) FROM chequeras WHERE id_cuenta_fondos = ?) AS chequeras,
                    (SELECT COUNT(*) FROM pagos WHERE id_cuenta_fondos = ?)
                  + (SELECT COUNT(*) FROM prestamos WHERE id_cuenta_fondos = ?)
                  + (SELECT COUNT(*) FROM inversiones WHERE id_cuenta_fondos = ?)
                  + (SELECT COUNT(*) FROM inversiones_liquidaciones WHERE id_cuenta_fondos = ?)
                  + (SELECT COUNT(*) FROM lotes_cobranza WHERE id_cuenta_fondos = ?)
                  + (SELECT COUNT(*) FROM movimientos_entre_cuentas WHERE ? IN (id_cuenta_origen, id_cuenta_destino)) AS comprobantes',
            array_fill(0, 8, $id));
    return array_map('intval', $u ?: ['movimientos' => 0, 'chequeras' => 0, 'comprobantes' => 0]);
}

// ---------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------
if (es_post()) {
    csrf_exigir();
    $accion = post_str('accion');

    if ($accion === 'guardar') {
        $reg = ['id_cuenta_fondos' => $id, 'tipo' => post_str('tipo'), 'nombre' => post_str('nombre'), 'moneda' => post_str('moneda'),
                'id_banco' => post_str('id_banco'), 'numero_cuenta' => post_str('numero_cuenta'),
                'cbu' => preg_replace('/\D/', '', post_str('cbu')), 'alias' => post_str('alias'),
                'predeterminada' => post_str('predeterminada') === '1' ? 1 : 0, 'habilitada' => post_str('habilitada') === '1' ? 1 : 0,
                'comentario' => post_str('comentario')];
        $ant = $id ? q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$id]) : null;
        if ($id && !$ant) {
            flash('warning', 'Esa cuenta ya no existe.');
            redirigir('cuentas_fondos.php');
        }
        $t = $reg['tipo'];
        if (!isset(TIPOS_CUENTA[$t])) {
            $errores[] = 'Elegí el tipo de cuenta.';
        }
        if ($reg['nombre'] === '') {
            $errores[] = 'Escribí el nombre de la cuenta (como se la conoce: «Nación cta. cte.», «Caja chica»…).';
        }
        if (!isset(MONEDAS[$reg['moneda']])) {
            $errores[] = 'Elegí la moneda.';
        }
        if ($ant) {
            $u = usos_cuenta($id);
            if ($u['movimientos'] + $u['comprobantes'] > 0 && ($ant['tipo'] !== $t || $ant['moneda'] !== $reg['moneda'])) {
                $errores[] = 'La cuenta ya tiene movimientos: no se le puede cambiar el tipo ni la moneda. Si hace falta, deshabilitala y creá otra.';
            } elseif ($u['chequeras'] > 0 && $ant['tipo'] === 'B' && $t !== 'B') {
                $errores[] = 'La cuenta tiene chequeras: solo puede ser de tipo Banco.';
            }
        }
        $banco = null;
        if (in_array($t, ['B', 'I'], true) && $reg['id_banco'] !== '') {
            $banco = q1('SELECT * FROM bancos WHERE id_banco = ?', [(int) $reg['id_banco']]);
            if (!$banco) {
                $errores[] = 'El banco elegido no existe.';
            }
        }
        if ($t === 'B') {
            if (!$banco && $reg['id_banco'] === '') {
                $errores[] = 'Elegí el banco.';
            }
            if ($reg['numero_cuenta'] === '') {
                $errores[] = 'Escribí el número de cuenta.';
            }
            if ($reg['cbu'] === '') {
                $errores[] = 'Escribí el CBU (22 números): es con lo que se cruza el extracto y se reciben transferencias.';
            }
        }
        if (in_array($t, ['B', 'V', 'I'], true) && $reg['cbu'] !== '') {
            if (!cbu_valido($reg['cbu'])) {
                $errores[] = ($t === 'V' ? 'El CVU' : 'El CBU') . ' no es válido: son 22 números y los dígitos verificadores no coinciden.';
            } elseif ($t === 'B' && $banco && strlen($banco['codigo']) === 3 && substr($reg['cbu'], 0, 3) !== $banco['codigo']) {
                $errores[] = 'El CBU empieza con ' . substr($reg['cbu'], 0, 3) . ' y el código de ' . $banco['nombre'] . ' es ' . $banco['codigo'] . ': revisá el banco o el CBU.';
            }
        }
        if (!in_array($t, ['B', 'V', 'I'], true)) {           // caja, cartera, otro: sin banco ni CBU
            $reg['id_banco'] = $reg['numero_cuenta'] = $reg['cbu'] = $reg['alias'] = '';
        } elseif ($t === 'V') {
            $reg['id_banco'] = '';
        }
        if (!$reg['habilitada']) {
            $reg['predeterminada'] = 0;
        }

        if (!$errores) {
            $pdo = db();
            $vals = [$t, mb_substr($reg['nombre'], 0, 100), $reg['moneda'], $reg['id_banco'] !== '' ? (int) $reg['id_banco'] : null,
                     $reg['numero_cuenta'] !== '' ? mb_substr($reg['numero_cuenta'], 0, 30) : null, $reg['cbu'] !== '' ? $reg['cbu'] : null,
                     $reg['alias'] !== '' ? mb_substr($reg['alias'], 0, 50) : null, $reg['predeterminada'], $reg['habilitada'],
                     $reg['comentario'] !== '' ? mb_substr($reg['comentario'], 0, 250) : null];
            try {
                $pdo->beginTransaction();
                // La marca es única por moneda: se apaga la anterior antes de prender esta.
                if ($reg['predeterminada']) {
                    q('UPDATE cuentas_fondos SET predeterminada = 0 WHERE predeterminada = 1 AND moneda = ? AND id_cuenta_fondos <> ?', [$reg['moneda'], $id]);
                }
                if ($id) {
                    q('UPDATE cuentas_fondos SET tipo=?, nombre=?, moneda=?, id_banco=?, numero_cuenta=?, cbu=?, alias=?, predeterminada=?, habilitada=?, comentario=?
                        WHERE id_cuenta_fondos = ?', array_merge($vals, [$id]));
                    if (!$reg['habilitada']) {
                        q('DELETE FROM cuentas_fondos_medios WHERE id_cuenta_fondos = ?', [$id]);
                    }
                } else {
                    q('INSERT INTO cuentas_fondos (tipo, nombre, moneda, id_banco, numero_cuenta, cbu, alias, predeterminada, habilitada, comentario, creado_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [usuario_id()]));
                    $id = (int) $pdo->lastInsertId();
                }
                $pdo->commit();
                if (!$ant && $t === 'B') {
                    flash('ok', 'Cuenta «' . $reg['nombre'] . '» creada. Si emite cheques, cargá sus chequeras.');
                    redirigir('cuentas_fondos.php?edit=' . $id . '&sub=chequeras');
                }
                flash('ok', 'Cuenta «' . $reg['nombre'] . '» ' . ($ant ? 'guardada' : 'creada') . '.'
                            . ($reg['predeterminada'] ? ' Es la que viene elegida al desembolsar y pagar en ' . strtolower(MONEDAS[$reg['moneda']]) . '.' : ''));
                redirigir('cuentas_fondos.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errores[] = mensaje_bd($e, ['uq_cf_nombre' => 'Ya hay una cuenta con ese nombre.', 'uq_cf_cbu' => 'Ese CBU ya es de otra cuenta.']);
            }
        }
    } elseif (in_array($accion, ['habilitar', 'deshabilitar'], true) && $id) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            if ($accion === 'deshabilitar') {
                q('UPDATE cuentas_fondos SET habilitada = 0, predeterminada = 0 WHERE id_cuenta_fondos = ?', [$id]);
                q('DELETE FROM cuentas_fondos_medios WHERE id_cuenta_fondos = ?', [$id]);
            } else {
                q('UPDATE cuentas_fondos SET habilitada = 1 WHERE id_cuenta_fondos = ?', [$id]);
            }
            $pdo->commit();
            flash('ok', $accion === 'deshabilitar' ? 'Cuenta deshabilitada: deja de ofrecerse; sus movimientos quedan.' : 'Cuenta habilitada.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('danger', mensaje_bd($e));
        }
        redirigir('cuentas_fondos.php');
    } elseif ($accion === 'borrar' && $id) {
        $u = usos_cuenta($id);
        if ($u['movimientos'] + $u['comprobantes'] + $u['chequeras'] > 0) {
            flash('warning', 'La cuenta tiene movimientos o chequeras: no se borra, se deshabilita.');
        } else {
            try {
                q('DELETE FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$id]);
                flash('ok', 'Cuenta eliminada.');
            } catch (Throwable $e) {
                flash('danger', mensaje_bd($e));
            }
        }
        redirigir('cuentas_fondos.php');
    } elseif (in_array($accion, ['chequera_guardar', 'chequera_habilitar', 'chequera_deshabilitar', 'chequera_borrar'], true) && $id) {
        $sub = 'chequeras';
        $cta = q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$id]);
        if (!$cta || $cta['tipo'] !== 'B') {
            flash('warning', 'Solo las cuentas de tipo Banco tienen chequeras.');
            redirigir('cuentas_fondos.php');
        }
        $volver = 'cuentas_fondos.php?edit=' . $id . '&sub=chequeras';
        $idq = (int) post_str('id_chequera');
        $q = $idq ? q1('SELECT * FROM chequeras WHERE id_chequera = ? AND id_cuenta_fondos = ?', [$idq, $id]) : null;
        if ($idq && !$q) {
            flash('danger', 'La chequera ya no existe.');
            redirigir($volver);
        }
        $emit = $idq ? q1("SELECT COUNT(*) AS n, MIN(cheque_numero) AS minimo, MAX(cheque_numero) AS maximo
                             FROM debitos_creditos_bancarios WHERE id_chequera = ?", [$idq]) : ['n' => 0];
        if ($accion === 'chequera_borrar' && $q) {
            if ((int) $emit['n']) {
                flash('danger', 'La chequera tiene ' . num($emit['n']) . ' cheques emitidos: no se elimina, se deshabilita.');
            } else {
                q('DELETE FROM chequeras WHERE id_chequera = ?', [$idq]);
                flash('ok', 'Chequera ' . $q['numero_desde'] . '–' . $q['numero_hasta'] . ' eliminada.');
            }
            redirigir($volver);
        }
        if (in_array($accion, ['chequera_habilitar', 'chequera_deshabilitar'], true) && $q) {
            q('UPDATE chequeras SET habilitada = ? WHERE id_chequera = ?', [$accion === 'chequera_habilitar' ? 1 : 0, $idq]);
            flash('ok', 'Chequera ' . $q['numero_desde'] . '–' . $q['numero_hasta'] . ($accion === 'chequera_habilitar' ? ' habilitada.' : ' deshabilitada: no se ofrece para emitir cheques.'));
            redirigir($volver);
        }
        if ($accion === 'chequera_guardar') {
            $f = ['id_chequera' => $idq, 'descripcion' => post_str('descripcion'), 'tipo_cheque' => post_str('tipo_cheque'),
                  'numero_desde' => post_str('numero_desde'), 'numero_hasta' => post_str('numero_hasta'), 'ultimo' => post_str('ultimo'),
                  'habilitada' => post_str('habilitada') === '1' ? 1 : 0];
            $d = $f['numero_desde'];
            $h = $f['numero_hasta'];
            if (!ctype_digit($d) || !ctype_digit($h) || strlen($d) > 18 || strlen($h) > 18) {
                $errores[] = 'El rango son números de cheque (desde y hasta).';
            } elseif ((int) $h < (int) $d) {
                $errores[] = 'El número final no puede ser menor que el inicial.';
            } else {
                if ($f['ultimo'] !== '' && (!ctype_digit($f['ultimo']) || (int) $f['ultimo'] < (int) $d - 1 || (int) $f['ultimo'] > (int) $h)) {
                    $errores[] = 'El último utilizado tiene que estar en el rango (vacío: ninguno todavía).';
                }
                if ((int) $emit['n'] && ((int) $emit['minimo'] < (int) $d || (int) $emit['maximo'] > (int) $h)) {
                    $errores[] = 'Ya se emitieron cheques del ' . $emit['minimo'] . ' al ' . $emit['maximo'] . ': el rango tiene que incluirlos.';
                }
                // Dos chequeras de la misma cuenta no se pisan.
                $otra = q1('SELECT numero_desde, numero_hasta FROM chequeras WHERE id_cuenta_fondos = ? AND id_chequera <> ?
                              AND numero_desde <= ? AND ? <= numero_hasta LIMIT 1', [$id, $idq, $h, $d]);
                if ($otra) {
                    $errores[] = 'El rango se superpone con la chequera ' . $otra['numero_desde'] . '–' . $otra['numero_hasta'] . '.';
                }
            }
            if (!in_array($f['tipo_cheque'], ['AL_DIA', 'DIFERIDO'], true)) {
                $errores[] = 'Elegí si es de cheques al día o diferidos.';
            }
            $chqForm = $f;
            if (!$errores) {
                $proximo = $f['ultimo'] === '' ? (int) $d : (int) $f['ultimo'] + 1;
                if ((int) $emit['n']) {
                    $proximo = max($proximo, (int) $emit['maximo'] + 1);
                }
                try {
                    if ($idq) {
                        q('UPDATE chequeras SET descripcion = ?, tipo_cheque = ?, numero_desde = ?, numero_hasta = ?, proximo_numero = ?, habilitada = ?
                            WHERE id_chequera = ?', [$f['descripcion'] ?: null, $f['tipo_cheque'], $d, $h, $proximo, $f['habilitada'], $idq]);
                    } else {
                        q('INSERT INTO chequeras (id_cuenta_fondos, descripcion, tipo_cheque, numero_desde, numero_hasta, proximo_numero, habilitada)
                           VALUES (?,?,?,?,?,?,?)', [$id, $f['descripcion'] ?: null, $f['tipo_cheque'], $d, $h, $proximo, $f['habilitada']]);
                    }
                    flash('ok', 'Chequera ' . $d . '–' . $h . ($idq ? ' guardada.' : ' agregada a «' . $cta['nombre'] . '».'));
                    redirigir($volver);
                } catch (Throwable $e) {
                    $errores[] = mensaje_bd($e);
                }
            }
        }
    } elseif (in_array($accion, ['banco_guardar', 'banco_activar', 'banco_desactivar', 'banco_borrar'], true)) {
        $solapa = 'bancos';
        $volver = 'cuentas_fondos.php?solapa=bancos';
        $idb = (int) post_str('id_banco');
        $b = $idb ? q1('SELECT * FROM bancos WHERE id_banco = ?', [$idb]) : null;
        if ($idb && !$b) {
            flash('danger', 'El banco ya no existe.');
            redirigir($volver);
        }
        $nCtas = $idb ? (int) q1('SELECT COUNT(*) AS n FROM cuentas_fondos WHERE id_banco = ?', [$idb])['n'] : 0;
        if ($accion === 'banco_borrar' && $b) {
            if ($nCtas) {
                flash('danger', 'No se puede eliminar «' . $b['nombre'] . '»: lo usan ' . num($nCtas) . ' cuentas. Desactivalo.');
            } else {
                q('DELETE FROM bancos WHERE id_banco = ?', [$idb]);
                flash('ok', '«' . $b['nombre'] . '» eliminado del catálogo.');
            }
            redirigir($volver);
        }
        if (in_array($accion, ['banco_activar', 'banco_desactivar'], true) && $b) {
            q('UPDATE bancos SET activo = ? WHERE id_banco = ?', [$accion === 'banco_activar' ? 1 : 0, $idb]);
            flash('ok', '«' . $b['nombre'] . '» ' . ($accion === 'banco_activar' ? 'activado.' : 'desactivado: deja de ofrecerse al cargar una cuenta.'));
            redirigir($volver);
        }
        if ($accion === 'banco_guardar') {
            $f = ['id_banco' => $idb, 'codigo' => post_str('codigo'), 'nombre' => trim(preg_replace('/\s+/u', ' ', post_str('nombre'))),
                  'activo' => post_str('activo') === '1' ? 1 : 0];
            if (!preg_match('/^\d{3,5}$/', $f['codigo'])) {
                $errores[] = 'El código es el número de entidad del BCRA (3 dígitos, los primeros del CBU).';
            } elseif ($o = q1('SELECT nombre FROM bancos WHERE codigo = ? AND id_banco <> ?', [$f['codigo'], $idb])) {
                $errores[] = 'El código ' . $f['codigo'] . ' ya es de «' . $o['nombre'] . '».';
            }
            if ($f['nombre'] === '') {
                $errores[] = 'Falta el nombre.';
            }
            $bcoForm = $f;
            if (!$errores) {
                try {
                    if ($idb) {
                        q('UPDATE bancos SET codigo = ?, nombre = ?, activo = ? WHERE id_banco = ?', [$f['codigo'], mb_substr($f['nombre'], 0, 100), $f['activo'], $idb]);
                    } else {
                        q('INSERT INTO bancos (codigo, nombre, activo) VALUES (?, ?, ?)', [$f['codigo'], mb_substr($f['nombre'], 0, 100), $f['activo']]);
                    }
                    flash('ok', '«' . $f['nombre'] . '» ' . ($idb ? 'guardado.' : 'agregado al catálogo.'));
                    redirigir($volver);
                } catch (Throwable $e) {
                    $errores[] = mensaje_bd($e, ['uq_bancos_codigo' => 'Ya hay un banco con el código ' . $f['codigo'] . '.']);
                }
            }
        }
    } elseif ($accion === 'medio_guardar') {
        $solapa = 'medios';
        $m = post_str('medio');
        $c = (int) post_str('id_cuenta_fondos');
        $ars = cuentas_fondos('ARS');
        if (!isset(MEDIOS_PAGO[$m])) {
            $errores[] = 'Ese medio de cobro no existe.';
        } elseif ($c && !isset($ars[$c])) {
            $errores[] = 'La cuenta tiene que ser en pesos y estar habilitada.';
        }
        $medForm = ['medio' => $m, 'id_cuenta_fondos' => $c ?: ''];
        if (!$errores) {
            try {
                q('DELETE FROM cuentas_fondos_medios WHERE medio = ?', [$m]);
                if ($c) {
                    q('INSERT INTO cuentas_fondos_medios (medio, id_cuenta_fondos) VALUES (?, ?)', [$m, $c]);
                }
                flash('ok', MEDIOS_PAGO[$m] . ': ' . ($c ? 'entra en «' . $ars[$c]['nombre'] . '».' : 'la cuenta se elige en cada cobro.'));
                redirigir('cuentas_fondos.php?solapa=medios');
            } catch (Throwable $e) {
                $errores[] = mensaje_bd($e);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$fEstado = in_array(get_str('estado'), ['0', 'todas'], true) ? get_str('estado') : '1';
$lista = $bancos = $chequeras = [];
$usos = ['movimientos' => 0, 'chequeras' => 0, 'comprobantes' => 0];
try {
    $bancos = q('SELECT b.*, (SELECT COUNT(*) FROM cuentas_fondos c WHERE c.id_banco = b.id_banco) AS cuentas FROM bancos b ORDER BY b.codigo');
    $lista = q('SELECT c.*, b.codigo AS banco_codigo, b.nombre AS banco, v.saldo, v.ultimo_movimiento, v.debitos_pendientes,
                       v.creditos_pendientes, v.saldo_banco_estimado,
                       (SELECT COUNT(*) FROM chequeras q WHERE q.id_cuenta_fondos = c.id_cuenta_fondos AND q.habilitada = 1) AS chequeras,
                       (SELECT GROUP_CONCAT(m.medio ORDER BY m.medio) FROM cuentas_fondos_medios m WHERE m.id_cuenta_fondos = c.id_cuenta_fondos) AS medios
                  FROM cuentas_fondos c
                  JOIN v_saldos_cuentas_fondos v ON v.id_cuenta_fondos = c.id_cuenta_fondos
                  LEFT JOIN bancos b ON b.id_banco = c.id_banco'
             . ($fEstado === 'todas' ? '' : ' WHERE c.habilitada = ' . (int) $fEstado)
             . " ORDER BY FIELD(c.tipo, 'C', 'B', 'R', 'V', 'I', 'O'), c.moneda, c.nombre");
    if ($reg === null && ($id || get_str('nuevo') === '1') && $solapa === 'cuentas') {
        $reg = $id ? q1('SELECT * FROM cuentas_fondos WHERE id_cuenta_fondos = ?', [$id]) : null;
        if ($id && !$reg) {
            flash('warning', 'Esa cuenta no existe.');
            redirigir('cuentas_fondos.php');
        }
        $reg = $reg ?: ['id_cuenta_fondos' => 0, 'tipo' => 'B', 'nombre' => '', 'moneda' => 'ARS', 'id_banco' => '', 'numero_cuenta' => '',
                        'cbu' => '', 'alias' => '', 'predeterminada' => 0, 'habilitada' => 1, 'comentario' => ''];
    }
    if ($reg && $reg['id_cuenta_fondos']) {
        $usos = usos_cuenta((int) $reg['id_cuenta_fondos']);
        $chequeras = q("SELECT q.*, (SELECT COUNT(*) FROM debitos_creditos_bancarios d WHERE d.id_chequera = q.id_chequera) AS emitidos,
                               (SELECT COUNT(*) FROM debitos_creditos_bancarios d WHERE d.id_chequera = q.id_chequera AND d.estado IN ('EMITIDO','ENTREGADO')) AS circulando
                          FROM chequeras q WHERE q.id_cuenta_fondos = ? ORDER BY q.numero_desde", [(int) $reg['id_cuenta_fondos']]);
    }
} catch (Throwable $e) {
    $errores[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de Tesorería: corré sql\financiera_fondos.sql.' : error_amigable($e);
}
/** Un botón de la grilla que manda una acción sobre la cuenta. */
function accion_cuenta(int $id, string $accion, string $icono, string $titulo, string $confirmar = '', string $clase = ''): string
{
    return '<form method="post" action="cuentas_fondos.php" class="form-en-linea"'
         . ($confirmar !== '' ? ' data-confirmar="' . h($confirmar) . '" data-confirmar-titulo="' . h($titulo)
             . '" data-confirmar-boton="' . h($titulo) . '"' . ($clase === 'borrar' ? ' data-peligro' : '') : '') . '>'
         . csrf_campo() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="' . h($accion) . '">'
         . '<button class="btn-accion ' . h($clase) . '" title="' . h($titulo) . '" aria-label="' . h($titulo) . '">' . icono($icono) . '</button></form>';
}

// ---------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------
layout_inicio('Cuentas de fondos');
titulo('Cuentas de fondos', 'Bancos, cajas, cartera de cheques y billeteras: dónde está la plata de la financiera y cuánto hay en cada una.',
       $reg || $solapa !== 'cuentas' ? '' : boton('Nueva cuenta', 'primary', 'cuentas_fondos.php?nuevo=1', 'agregar'),
       '<a href="movimientos_fondos.php">Tesorería</a> / Cuentas de fondos');
mensajes($errores);
?>
<div class="abm-tabs">
  <a class="solapa <?= $solapa === 'cuentas' ? 'activa' : '' ?>" href="cuentas_fondos.php">Cuentas <span class="conteo"><?= count($lista) ?></span></a>
  <a class="solapa <?= $solapa === 'bancos' ? 'activa' : '' ?>" href="cuentas_fondos.php?solapa=bancos">Bancos</a>
  <a class="solapa <?= $solapa === 'medios' ? 'activa' : '' ?>" href="cuentas_fondos.php?solapa=medios">Cuenta por medio de cobro</a>
</div>

<?php if ($solapa === 'cuentas'): ?>
<?php if ($reg):
    $r = $reg;
    $esNueva = !(int) $r['id_cuenta_fondos'];
    $bloqueada = !$esNueva && $usos['movimientos'] + $usos['comprobantes'] > 0;
?>
<?php if (!$esNueva && $r['tipo'] === 'B'): ?>
<div class="abm-tabs">
  <a class="solapa <?= $sub === 'datos' ? 'activa' : '' ?>" href="cuentas_fondos.php?edit=<?= (int) $r['id_cuenta_fondos'] ?>">Datos</a>
  <a class="solapa <?= $sub === 'chequeras' ? 'activa' : '' ?>" href="cuentas_fondos.php?edit=<?= (int) $r['id_cuenta_fondos'] ?>&amp;sub=chequeras">Chequeras <span class="conteo"><?= count(array_filter($chequeras, function ($q) { return (int) ($q['id_chequera'] ?? 0) > 0; })) ?></span></a>
</div>
<?php endif; ?>

<?php if ($esNueva || $r['tipo'] !== 'B' || $sub === 'datos'): ?>
<form method="post" action="cuentas_fondos.php" autocomplete="off" id="frm-cuenta">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= (int) $r['id_cuenta_fondos'] ?>">
  <div class="card">
    <?= card_head($esNueva ? 'Nueva cuenta de fondos' : $r['nombre'], $esNueva ? '' : ($bloqueada ? 'con movimientos: el tipo y la moneda quedan fijos' : ''),
                  boton('Volver', 'secondary', 'cuentas_fondos.php', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:3"><label for="tipo">Tipo <span class="req">*</span></label>
          <?php if ($bloqueada): ?><input type="hidden" name="tipo" value="<?= h($r['tipo']) ?>"><?php endif; ?>
          <select id="tipo" <?= $bloqueada ? 'disabled' : 'name="tipo"' ?>><?= opciones(TIPOS_CUENTA, $r['tipo']) ?></select></div>
        <div class="campo" style="--span:6"><label for="nombre">Nombre <span class="req">*</span></label>
          <input id="nombre" name="nombre" value="<?= h($r['nombre']) ?>" maxlength="100" required placeholder="Nación cta. cte., Caja chica, Mercado Pago…"></div>
        <div class="campo" style="--span:3"><label for="moneda">Moneda <span class="req">*</span></label>
          <?php if ($bloqueada): ?><input type="hidden" name="moneda" value="<?= h($r['moneda']) ?>"><?php endif; ?>
          <select id="moneda" <?= $bloqueada ? 'disabled' : 'name="moneda"' ?>><?= opciones(MONEDAS, $r['moneda']) ?></select></div>
        <div class="campo" style="--span:4" data-tipos="B I"><label for="id_banco">Banco <span class="req solo-b">*</span></label>
          <select id="id_banco" name="id_banco"><option value="">Elegí…</option>
            <?php foreach ($bancos as $b): if (!(int) $b['activo'] && (string) $b['id_banco'] !== (string) $r['id_banco']) { continue; } ?>
              <option value="<?= (int) $b['id_banco'] ?>" data-codigo="<?= h($b['codigo']) ?>" <?= (string) $b['id_banco'] === (string) $r['id_banco'] ? 'selected' : '' ?>><?= h($b['codigo'] . ' · ' . $b['nombre']) ?></option>
            <?php endforeach; ?>
          </select><span class="campo-ayuda"><a href="cuentas_fondos.php?solapa=bancos">¿no está?</a></span></div>
        <div class="campo" style="--span:3" data-tipos="B V I"><label for="numero_cuenta">N° de cuenta <span class="req solo-b">*</span></label>
          <input id="numero_cuenta" name="numero_cuenta" value="<?= h((string) $r['numero_cuenta']) ?>" maxlength="30" class="mono"></div>
        <div class="campo" style="--span:5" data-tipos="B V I"><label for="cbu"><span class="lbl-cbu">CBU</span> <span class="req solo-b">*</span></label>
          <input id="cbu" name="cbu" value="<?= h((string) $r['cbu']) ?>" maxlength="26" inputmode="numeric" class="mono" placeholder="22 números">
          <span class="campo-ayuda" id="cbu-ayuda"></span></div>
        <div class="campo" style="--span:4" data-tipos="B V I"><label for="alias">Alias</label>
          <input id="alias" name="alias" value="<?= h((string) $r['alias']) ?>" maxlength="50" class="mono"></div>
        <div class="campo" style="--span:8"><label for="comentario">Comentario</label>
          <input id="comentario" name="comentario" value="<?= h((string) $r['comentario']) ?>" maxlength="250"></div>
        <div class="campo campo-boton" style="--span:6">
          <label class="campo-check"><input type="checkbox" name="predeterminada" value="1" <?= (int) $r['predeterminada'] ? 'checked' : '' ?>> Predeterminada</label>
          <span class="campo-ayuda">la que viene elegida al desembolsar un préstamo y al pagar a un inversor (una por moneda)</span></div>
        <div class="campo campo-boton" style="--span:6">
          <label class="campo-check"><input type="checkbox" name="habilitada" value="1" <?= (int) $r['habilitada'] ? 'checked' : '' ?>> Habilitada</label>
          <span class="campo-ayuda">deshabilitada deja de ofrecerse; sus movimientos quedan</span></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($esNueva ? 'Crear cuenta' : 'Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<script>
(function () {
  var tipo = document.getElementById('tipo'), cbu = document.getElementById('cbu'), banco = document.getElementById('id_banco'),
      ayuda = document.getElementById('cbu-ayuda');
  function mostrar() {
    var t = tipo.value;
    document.querySelectorAll('#frm-cuenta [data-tipos]').forEach(function (c) {
      c.classList.toggle('oculto', c.getAttribute('data-tipos').split(' ').indexOf(t) === -1);
    });
    document.querySelectorAll('#frm-cuenta .solo-b').forEach(function (s) { s.classList.toggle('oculto', t !== 'B'); });
    document.querySelector('#frm-cuenta .lbl-cbu').textContent = t === 'V' ? 'CVU' : 'CBU';
  }
  function dv(b, p) { var s = 0; for (var i = 0; i < p.length; i++) s += +b[i] * p[i]; return (10 - s % 10) % 10; }
  function revisar() {
    var d = cbu.value.replace(/\D/g, '');
    ayuda.className = 'campo-ayuda';
    if (!d) { ayuda.textContent = ''; return; }
    if (d.length !== 22) { ayuda.textContent = d.length + ' de 22 números'; return; }
    var ok = dv(d, [7, 1, 3, 9, 7, 1, 3]) === +d[7] && dv(d.substr(8), [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3]) === +d[21];
    ayuda.textContent = ok ? 'CBU válido' : 'los dígitos verificadores no coinciden';
    if (!ok) ayuda.className = 'campo-ayuda texto-error';
    if (ok && !banco.value) {            // el banco sale de los 3 primeros números
      var op = banco.querySelector('option[data-codigo="' + d.substr(0, 3) + '"]');
      if (op) banco.value = op.value;
    }
  }
  tipo.addEventListener('change', mostrar);
  cbu.addEventListener('input', revisar);
  mostrar();
  revisar();
})();
</script>
<?php else: ?>
<?php
    $baseChq = 'cuentas_fondos.php?edit=' . (int) $r['id_cuenta_fondos'] . '&sub=chequeras';
    $edChq = get_str('chequera');
    if ($chqForm !== null || $edChq !== ''):
        $cq = $chqForm;
        if ($cq === null) {
            $fila = $edChq !== 'nueva' ? q1('SELECT * FROM chequeras WHERE id_chequera = ? AND id_cuenta_fondos = ?', [(int) $edChq, (int) $r['id_cuenta_fondos']]) : null;
            $cq = $fila ? ['id_chequera' => (int) $fila['id_chequera'], 'descripcion' => (string) $fila['descripcion'], 'tipo_cheque' => $fila['tipo_cheque'],
                           'numero_desde' => (string) $fila['numero_desde'], 'numero_hasta' => (string) $fila['numero_hasta'],
                           'ultimo' => (int) $fila['proximo_numero'] > (int) $fila['numero_desde'] ? (string) ((int) $fila['proximo_numero'] - 1) : '',
                           'habilitada' => (int) $fila['habilitada']]
                        : ['id_chequera' => 0, 'descripcion' => '', 'tipo_cheque' => 'AL_DIA', 'numero_desde' => '', 'numero_hasta' => '', 'ultimo' => '', 'habilitada' => 1];
        }
?>
<form method="post" action="<?= h($baseChq . '&chequera=' . ($cq['id_chequera'] ?: 'nueva')) ?>" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="chequera_guardar">
  <input type="hidden" name="id" value="<?= (int) $r['id_cuenta_fondos'] ?>">
  <input type="hidden" name="id_chequera" value="<?= (int) $cq['id_chequera'] ?>">
  <div class="card">
    <?= card_head($cq['id_chequera'] ? 'Editar la chequera' : 'Nueva chequera', 'de ' . $r['nombre'], boton('Volver', 'secondary', $baseChq, 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:4"><label for="chq_desc">Descripción</label>
          <input id="chq_desc" name="descripcion" value="<?= h($cq['descripcion']) ?>" maxlength="100" placeholder="Talonario 3"></div>
        <div class="campo" style="--span:2"><label for="chq_tipo">Cheques</label>
          <select id="chq_tipo" name="tipo_cheque"><?= opciones(['AL_DIA' => 'Al día', 'DIFERIDO' => 'Diferidos'], $cq['tipo_cheque']) ?></select></div>
        <div class="campo" style="--span:2"><label for="chq_desde">Desde N° <span class="req">*</span></label>
          <input id="chq_desde" name="numero_desde" value="<?= h($cq['numero_desde']) ?>" inputmode="numeric" class="derecha mono" required></div>
        <div class="campo" style="--span:2"><label for="chq_hasta">Hasta N° <span class="req">*</span></label>
          <input id="chq_hasta" name="numero_hasta" value="<?= h($cq['numero_hasta']) ?>" inputmode="numeric" class="derecha mono" required></div>
        <div class="campo" style="--span:2"><label for="chq_ult">Último utilizado</label>
          <input id="chq_ult" name="ultimo" value="<?= h($cq['ultimo']) ?>" inputmode="numeric" class="derecha mono" placeholder="ninguno"></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="habilitada" value="1" <?= (int) $cq['habilitada'] ? 'checked' : '' ?>> Habilitada</label></div>
      </div>
      <p class="campo-ayuda" style="margin:8px 0 0">Se escribe el <strong>último utilizado</strong>, como se lee en el talonario; al emitir un cheque la chequera avanza sola y un número no se usa dos veces.</p>
    </div>
    <div class="form-acciones"><?= boton($cq['id_chequera'] ? 'Guardar' : 'Agregar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>
<div class="card">
  <?= card_head('Chequeras de ' . $r['nombre'], 'un cheque propio sale de una chequera habilitada de la cuenta',
                boton('Nueva chequera', 'primary', $baseChq . '&chequera=nueva', 'agregar', false, true)) ?>
  <?= barra_local($baseChq, 'Descripción o número…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="chequera|chequeras">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Descripción</th><th>Cheques</th><th class="th-right">Desde N°</th><th class="th-right">Hasta N°</th>
        <th class="th-right">Próximo</th><th class="th-right">Emitidos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($chequeras as $q): $qid = (int) $q['id_chequera']; $usada = (int) $q['proximo_numero'] > (int) $q['numero_hasta']; ?>
        <tr class="<?= (int) $q['habilitada'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', $baseChq . '&chequera=' . $qid, 'Editar') ?>
            <?= (int) $q['habilitada']
                  ? accion_post($baseChq, ['accion' => 'chequera_deshabilitar', 'id' => (int) $r['id_cuenta_fondos'], 'id_chequera' => $qid], 'cerrar', 'Deshabilitar',
                                'La chequera ' . $q['numero_desde'] . '–' . $q['numero_hasta'] . ' deja de ofrecerse para emitir cheques.', 'borrar')
                  : accion_post($baseChq, ['accion' => 'chequera_habilitar', 'id' => (int) $r['id_cuenta_fondos'], 'id_chequera' => $qid], 'ok', 'Habilitar') ?>
            <?= !(int) $q['emitidos'] ? accion_post($baseChq, ['accion' => 'chequera_borrar', 'id' => (int) $r['id_cuenta_fondos'], 'id_chequera' => $qid], 'borrar', 'Eliminar',
                                                    'Se elimina la chequera ' . $q['numero_desde'] . '–' . $q['numero_hasta'] . '. No tiene cheques emitidos.', 'borrar') : '' ?>
          </div></td>
          <td><?= $q['descripcion'] !== null && $q['descripcion'] !== '' ? h($q['descripcion']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= $q['tipo_cheque'] === 'DIFERIDO' ? 'Diferidos' : 'Al día' ?></td>
          <td class="td-right mono"><?= h((string) $q['numero_desde']) ?></td>
          <td class="td-right mono"><?= h((string) $q['numero_hasta']) ?></td>
          <td class="td-right mono"><?= $usada ? '<span class="td-muted">agotada</span>' : h((string) $q['proximo_numero']) ?></td>
          <td class="td-right" data-orden="<?= (int) $q['emitidos'] ?>"><?= (int) $q['emitidos'] ? num($q['emitidos']) : '<span class="td-muted">—</span>' ?>
            <?= (int) $q['circulando'] ? '<span class="segunda-linea">' . num($q['circulando']) . ' sin debitar</span>' : '' ?></td>
          <td><?= (int) $q['habilitada'] ? estado('Habilitada', 'ok') : estado('Deshabilitada', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$chequeras): ?><tr><td colspan="8" class="no-data">Sin chequeras. Cargá la primera con «Nueva chequera».</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
  <?= card_head('Cuentas') ?>
  <form method="get" action="cuentas_fondos.php" class="filter-bar">
    <label class="filtro-campo filtro-buscar"><span class="filtro-lbl">Buscar</span>
      <input type="search" class="filter-input" placeholder="Cuenta, banco, alias o número…"></label>
    <label class="filtro-campo"><span class="filtro-lbl">Estado</span>
      <select name="estado" class="filtro <?= $fEstado !== '1' ? 'activo' : '' ?>" onchange="this.form.submit()"><?= opciones(['1' => 'Habilitadas', '0' => 'Deshabilitadas', 'todas' => 'Todas'], $fEstado) ?></select></label>
    <?= filtro_botones('cuentas_fondos.php', $fEstado !== '1') ?>
    <?= filtro_fin_local(null) ?>
  </form>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="cuenta|cuentas">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Cuenta</th><th>Tipo</th><th>Moneda</th>
        <th class="th-right">Saldo</th><th class="th-right" title="Lo que debería mostrar el banco: el saldo más lo que todavía no debitó y menos lo que todavía no acreditó">Según el banco</th>
        <th>Último movimiento</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $c): $cid = (int) $c['id_cuenta_fondos']; $u = usos_cuenta($cid); $sinUso = !$u['movimientos'] && !$u['comprobantes'] && !$u['chequeras']; ?>
        <tr class="<?= (int) $c['habilitada'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'cuentas_fondos.php?edit=' . $cid, 'Editar') ?>
            <?= btn_accion('ver', 'movimientos_fondos.php?cuenta=' . $cid, 'Movimientos de la cuenta') ?>
            <?= (int) $c['habilitada']
                  ? accion_cuenta($cid, 'deshabilitar', 'cerrar', 'Deshabilitar', '«' . $c['nombre'] . '» deja de ofrecerse al cobrar, desembolsar y pagar. Sus movimientos quedan.', 'borrar')
                  : accion_cuenta($cid, 'habilitar', 'ok', 'Habilitar') ?>
            <?= $sinUso ? accion_cuenta($cid, 'borrar', 'borrar', 'Eliminar', 'Se elimina «' . $c['nombre'] . '». No tiene movimientos.', 'borrar') : '' ?>
          </div></td>
          <td class="td-wrap" data-orden="<?= h($c['nombre']) ?>"><a href="cuentas_fondos.php?edit=<?= $cid ?>"><strong><?= h($c['nombre']) ?></strong></a>
            <?php $det = array_filter([$c['banco'] ? $c['banco_codigo'] . ' · ' . $c['banco'] : '', $c['numero_cuenta'] ? 'N° ' . $c['numero_cuenta'] : '',
                                       $c['cbu'] ? ($c['tipo'] === 'V' ? 'CVU ' : 'CBU ') . $c['cbu'] : '', $c['alias'] ? 'alias ' . $c['alias'] : '']); ?>
            <?= $det ? '<span class="segunda-linea mono">' . h(implode(' · ', $det)) . '</span>' : '' ?></td>
          <td><?= tipo_cuenta_badge($c['tipo']) ?><?= (int) $c['chequeras'] ? '<span class="segunda-linea">' . num($c['chequeras']) . ' chequera' . ((int) $c['chequeras'] > 1 ? 's' : '') . '</span>' : '' ?></td>
          <td><?= h(MONEDAS[$c['moneda']] ?? $c['moneda']) ?></td>
          <td class="td-right td-fuerte" data-orden="<?= (float) $c['saldo'] ?>"><?= importe_saldo($c['saldo'], $c['moneda']) ?></td>
          <td class="td-right" data-orden="<?= (float) $c['saldo_banco_estimado'] ?>"><?php if ($c['tipo'] === 'B'): ?><?= importe_saldo($c['saldo_banco_estimado'], $c['moneda']) ?>
            <?php if ((float) $c['debitos_pendientes'] || (float) $c['creditos_pendientes']): ?><span class="segunda-linea"><a href="debitos_creditos.php?cuenta=<?= $cid ?>"><?= h(implode(' · ', array_filter([
                (float) $c['debitos_pendientes'] ? 'a debitar ' . dinero($c['debitos_pendientes'], $c['moneda']) : '',
                (float) $c['creditos_pendientes'] ? 'a acreditar ' . dinero($c['creditos_pendientes'], $c['moneda']) : '']))) ?></a></span><?php endif; ?>
            <?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
          <td data-orden="<?= h((string) $c['ultimo_movimiento']) ?>"><?= $c['ultimo_movimiento'] ? h(fecha($c['ultimo_movimiento'])) : '<span class="td-muted">—</span>' ?></td>
          <td class="td-wrap"><?= (int) $c['habilitada'] ? estado('Habilitada', 'ok') : estado('Deshabilitada', 'neutro') ?>
            <?= (int) $c['predeterminada'] ? '<span class="segunda-linea">predeterminada</span>' : '' ?>
            <?= $c['medios'] ? '<span class="segunda-linea">cobros: ' . h(strtolower(implode(', ', array_map(function ($m) { return MEDIOS_PAGO[$m] ?? $m; }, explode(',', $c['medios']))))) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="8" class="no-data">No hay cuentas <?= $fEstado === '0' ? 'deshabilitadas' : '' ?>. Creá la primera con «Nueva cuenta».</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php elseif ($solapa === 'bancos'):
    $edBco = get_str('banco');
    $fBco = in_array(get_str('estado'), ['0', 'todos'], true) ? get_str('estado') : '1';
    if ($bcoForm !== null || $edBco !== ''):
        $bv = $bcoForm;
        if ($bv === null) {
            $fila = $edBco !== 'nuevo' ? q1('SELECT * FROM bancos WHERE id_banco = ?', [(int) $edBco]) : null;
            $bv = $fila ? ['id_banco' => (int) $fila['id_banco'], 'codigo' => $fila['codigo'], 'nombre' => $fila['nombre'], 'activo' => (int) $fila['activo']]
                        : ['id_banco' => 0, 'codigo' => '', 'nombre' => '', 'activo' => 1];
        }
?>
<form method="post" action="cuentas_fondos.php?solapa=bancos&amp;banco=<?= $bv['id_banco'] ? (int) $bv['id_banco'] : 'nuevo' ?>" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="banco_guardar">
  <input type="hidden" name="id_banco" value="<?= (int) $bv['id_banco'] ?>">
  <div class="card">
    <?= card_head($bv['id_banco'] ? 'Editar el banco' : 'Nuevo banco', 'el código es el de la entidad en el BCRA: los tres primeros números del CBU',
                  boton('Volver', 'secondary', 'cuentas_fondos.php?solapa=bancos', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:2"><label for="bco_cod">Código <span class="req">*</span></label>
          <input id="bco_cod" name="codigo" value="<?= h($bv['codigo']) ?>" maxlength="5" inputmode="numeric" class="mono" required placeholder="011"></div>
        <div class="campo" style="--span:6"><label for="bco_nom">Banco <span class="req">*</span></label>
          <input id="bco_nom" name="nombre" value="<?= h($bv['nombre']) ?>" maxlength="100" required></div>
        <div class="campo campo-boton" style="--span:3">
          <label class="campo-check"><input type="checkbox" name="activo" value="1" <?= (int) $bv['activo'] ? 'checked' : '' ?>> Activo</label>
          <small class="campo-ayuda">Inactivo no se ofrece al cargar una cuenta.</small></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton($bv['id_banco'] ? 'Guardar' : 'Agregar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>
<div class="card">
  <?= card_head('Bancos', 'al cargar el CBU de una cuenta, el banco se elige solo',
                boton('Nuevo banco', 'primary', 'cuentas_fondos.php?solapa=bancos&banco=nuevo', 'agregar', false, true)) ?>
  <?= barra_local('cuentas_fondos.php?solapa=bancos', 'Código o banco…',
                  filtro_select('estado', 'Estado', ['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'], $fBco, '', $fBco !== '1'), $fBco !== '1') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="banco|bancos">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Código</th><th>Banco</th><th class="th-right">Cuentas</th><th>Estado</th></tr></thead>
      <tbody>
      <?php $vistos = 0; foreach ($bancos as $b): if ($fBco !== 'todos' && (int) $b['activo'] !== (int) $fBco) { continue; } $vistos++; $bid = (int) $b['id_banco']; ?>
        <tr class="<?= (int) $b['activo'] ? '' : 'fila-baja' ?>">
          <td><div class="acciones">
            <?= btn_accion('editar', 'cuentas_fondos.php?solapa=bancos&banco=' . $bid, 'Editar') ?>
            <?= (int) $b['activo']
                  ? accion_post('cuentas_fondos.php?solapa=bancos', ['accion' => 'banco_desactivar', 'id_banco' => $bid], 'cerrar', 'Desactivar', '«' . $b['nombre'] . '» deja de ofrecerse al cargar una cuenta.', 'borrar')
                  : accion_post('cuentas_fondos.php?solapa=bancos', ['accion' => 'banco_activar', 'id_banco' => $bid], 'ok', 'Activar') ?>
            <?= !(int) $b['cuentas'] ? accion_post('cuentas_fondos.php?solapa=bancos', ['accion' => 'banco_borrar', 'id_banco' => $bid], 'borrar', 'Eliminar', 'Se elimina «' . $b['nombre'] . '» del catálogo. Ninguna cuenta lo usa.', 'borrar') : '' ?>
          </div></td>
          <td class="mono td-fuerte"><?= h($b['codigo']) ?></td>
          <td><?= h($b['nombre']) ?></td>
          <td class="td-right" data-orden="<?= (int) $b['cuentas'] ?>"><?= (int) $b['cuentas'] ? num($b['cuentas']) : '<span class="td-muted">—</span>' ?></td>
          <td><?= (int) $b['activo'] ? estado('Activo', 'ok') : estado('Inactivo', 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vistos): ?><tr><td colspan="5" class="no-data">Ningún banco con ese estado.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>

<?php else:
    $ars = [];
    $mapa = [];
    try {
        $ars = cuentas_fondos('ARS');
        $mapa = cuenta_por_medio();
    } catch (Throwable $e) {
        alerta(error_amigable($e));
    }
    $edMed = $medForm['medio'] ?? get_str('medio');
    if (isset(MEDIOS_PAGO[$edMed])):
        $elegida = $medForm !== null ? $medForm['id_cuenta_fondos'] : ($mapa[$edMed] ?? '');
?>
<form method="post" action="cuentas_fondos.php?solapa=medios&amp;medio=<?= h($edMed) ?>" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="accion" value="medio_guardar">
  <input type="hidden" name="medio" value="<?= h($edMed) ?>">
  <div class="card">
    <?= card_head('Cobros con ' . mb_strtolower(MEDIOS_PAGO[$edMed]), 'la cuenta que viene elegida; se puede cambiar en cada cobro',
                  boton('Volver', 'secondary', 'cuentas_fondos.php?solapa=medios', 'volver', false, true)) ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:6"><label for="med_cta">Entra en</label>
          <?= cuenta_select('id_cuenta_fondos', $ars, $elegida, '— se elige en cada cobro —', ['id' => 'med_cta']) ?></div>
      </div>
    </div>
    <div class="form-acciones"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div>
  </div>
</form>
<?php endif; ?>
<div class="card">
  <?= card_head('Cuenta habitual de cada medio de cobro', 'el efectivo a la caja, las transferencias al banco, Rapipago y Pago Fácil a la cuenta donde acreditan') ?>
  <?= barra_local('cuentas_fondos.php?solapa=medios', 'Medio o cuenta…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="medio|medios">
      <thead><tr><th class="col-acciones" data-no-sort>Acciones</th><th>Medio de cobro</th><th>Entra en</th></tr></thead>
      <tbody>
      <?php foreach (MEDIOS_PAGO as $m => $txt): $cid = $mapa[$m] ?? null; ?>
        <tr>
          <td><div class="acciones"><?= btn_accion('editar', 'cuentas_fondos.php?solapa=medios&medio=' . $m, 'Elegir la cuenta') ?></div></td>
          <td class="td-fuerte"><?= h($txt) ?></td>
          <td><?= $cid && isset($ars[$cid]) ? h($ars[$cid]['nombre']) : ($cid ? '<span class="texto-error">una cuenta deshabilitada</span>' : '<span class="td-muted">se elige en cada cobro</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<?php layout_fin(); ?>
