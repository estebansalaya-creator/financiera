<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Talonarios de comprobantes, su clasificación y los tipos de ARCA.
// El modelo y las reglas de gestion_adm (talonarios_routes.py); las
// tablas, en sql\financiera_talonarios.sql.
//
//   comprobantes_tipos              Factura, Recibo, Nota de Crédito…: de
//                                   qué lado de la cuenta del cliente va
//     └─> talonarios_clasificaciones   agrupa talonarios de un tipo
//           └─> talonarios          letra, punto de venta, rango de números
//                                   y el último emitido
//
// Talonario de ARCA: la marca es el tipo ARCA. Con tipo, pide CAE por
// WSFEv1 y la numeración es la de ARCA para el punto de venta y el tipo:
//   - tiene que ser nuevo (uno que ya numeró comprobantes propios no pasa a
//     ARCA: los números viejos chocarían con los de ARCA);
//   - uno solo por punto de venta, tipo y ambiente;
//   - la letra es la del tipo, y la clasificación va del mismo lado de la
//     cuenta (nota de crédito y recibo al haber, factura y nota de débito
//     al debe);
//   - ya usado, no se le cambia el punto de venta, la letra, el tipo ni el
//     ambiente, ni se le saca el tipo.
// El último emitido no retrocede desde la pantalla: rompería la
// trazabilidad de los comprobantes. Un talonario usado no se borra: se
// deshabilita.
//
// Dos a la vez: guardar un talonario bloquea su clasificación (y el
// talonario, si se edita), así los controles de rango y de serie de ARCA
// ven lo último grabado. Los índices únicos de la tabla son la última
// barrera.
// ---------------------------------------------------------------------

const TAL_LETRAS    = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'E' => 'E', 'M' => 'M', 'X' => 'X (interno)'];
const TAL_AMBIENTES = ['homo' => 'Homologación (pruebas)', 'prod' => 'Producción'];
const TAL_CONCEPTOS = ['1' => 'Productos', '2' => 'Servicios', '3' => 'Productos y servicios'];
const ARCA_CLASES   = ['FACTURA' => 'Factura', 'NOTA_DEBITO' => 'Nota de débito', 'NOTA_CREDITO' => 'Nota de crédito',
                       'RECIBO' => 'Recibo', 'OTRO' => 'Otro'];
const TAL_LADOS     = ['D' => 'Debe', 'C' => 'Haber'];

/** La razón para la persona: no es un error de la base. */
class TalonarioError extends RuntimeException
{
}

/** ¿Está sql\financiera_talonarios.sql? */
function tal_instalado(): bool
{
    return columna_existe('talonarios', 'id_talonario');
}

/** «A 00001-00000123» */
function tal_formato(string $letra, int $pv, int $numero): string
{
    return $letra . ' ' . str_pad((string) $pv, 5, '0', STR_PAD_LEFT) . '-' . str_pad((string) $numero, 8, '0', STR_PAD_LEFT);
}

/** El próximo número de un talonario (el desde si no emitió ninguno). */
function tal_proximo(array $t): int
{
    return (int) $t['ultimo_emitido'] >= (int) $t['numero_desde'] ? (int) $t['ultimo_emitido'] + 1 : (int) $t['numero_desde'];
}

/** ¿Ya emitió algún comprobante? */
function tal_usado(array $t): bool
{
    return (int) $t['ultimo_emitido'] >= (int) $t['numero_desde'];
}

/** Cuánto lleva usado: capacidad, usados, restantes, porcentaje y si está por agotarse o agotado. */
function tal_uso(array $t): array
{
    $cap = max(0, (int) $t['numero_hasta'] - (int) $t['numero_desde'] + 1);
    $usados = min($cap, max(0, (int) $t['ultimo_emitido'] - (int) $t['numero_desde'] + 1));
    $pct = $cap ? round($usados * 100 / $cap, 1) : 0.0;
    return ['capacidad' => $cap, 'usados' => $usados, 'restantes' => $cap - $usados, 'pct' => $pct,
            'agotado' => $cap - $usados <= 0, 'por_agotar' => $cap - $usados > 0 && $pct >= 90];
}

/** «al debe» / «al haber», con lo que significa para el cliente. */
function tal_lado(string $dc, bool $largo = false): string
{
    if ($dc === 'C') {
        return $largo ? 'al haber: baja lo que debe el cliente' : 'al haber';
    }
    return $largo ? 'al debe: suma a lo que debe el cliente' : 'al debe';
}

/** Tipos de ARCA activos, por código. */
function arca_tipos(bool $soloWsfe = false): array
{
    $out = [];
    foreach (q('SELECT * FROM arca_tipos_comprobante WHERE activo = 1' . ($soloWsfe ? ' AND wsfe = 1' : '')
               . ' ORDER BY CAST(codigo AS UNSIGNED)') as $a) {
        $out[$a['codigo']] = $a;
    }
    return $out;
}

/**
 * Lo que se lee del formulario. electronico sale del tipo ARCA (la marca):
 * con tipo, pide CAE.
 */
function tal_leer_post(): array
{
    $ent = function (string $k): int {
        $v = post_str($k);
        return preg_match('/^\d{1,10}$/', $v) ? (int) $v : ($v === '' ? 0 : -1);
    };
    $arca = post_str('codigo_arca');
    return [
        'nombre'           => mb_substr(post_str('nombre'), 0, 50),
        'id_clasificacion' => (int) post_str('id_clasificacion'),
        'letra'            => strtoupper(mb_substr(post_str('letra'), 0, 1)),
        'punto_venta'      => $ent('punto_venta'),
        'numero_desde'     => $ent('numero_desde'),
        'numero_hasta'     => $ent('numero_hasta'),
        'ultimo_emitido'   => $ent('ultimo_emitido'),
        'habilitado'       => post_str('habilitado') === '1' ? 1 : 0,
        'comentario'       => post_str('comentario') !== '' ? mb_substr(post_str('comentario'), 0, 250) : null,
        'codigo_arca'      => $arca !== '' ? str_pad(preg_replace('/\D/', '', $arca), 3, '0', STR_PAD_LEFT) : null,
        'arca_concepto'    => post_str('arca_concepto') !== '' ? (int) post_str('arca_concepto') : null,
        'arca_ambiente'    => post_str('arca_ambiente') !== '' ? post_str('arca_ambiente') : null,
    ];
}

/** Los controles que no necesitan mirar otros talonarios. */
function tal_validar(array $d, array $arca, array $empresa): array
{
    $e = [];
    if ($d['nombre'] === '') {
        $e[] = 'Escribí el nombre del talonario.';
    }
    if (!$d['id_clasificacion']) {
        $e[] = 'Elegí la clasificación.';
    }
    if (!isset(TAL_LETRAS[$d['letra']])) {
        $e[] = 'Elegí la letra: ' . implode(', ', array_keys(TAL_LETRAS)) . '.';
    }
    if ($d['punto_venta'] < 0 || $d['punto_venta'] > 99999) {
        $e[] = 'El punto de venta es un número de hasta 5 cifras.';
    }
    if ($d['numero_desde'] < 1) {
        $e[] = 'El número desde tiene que ser 1 o más.';
    }
    if ($d['numero_hasta'] < 1 || $d['numero_hasta'] < $d['numero_desde']) {
        $e[] = 'El número hasta no puede ser menor que el desde.';
    }
    if ($d['ultimo_emitido'] < 0) {
        $e[] = 'El último emitido es un número (0 si no se emitió ninguno).';
    } elseif ($d['ultimo_emitido'] > 0 && $d['numero_desde'] >= 1) {
        if ($d['ultimo_emitido'] < $d['numero_desde'] - 1) {
            $e[] = 'El último emitido no puede ser anterior al comienzo del rango (0 si no se emitió ninguno).';
        }
        if ($d['ultimo_emitido'] > $d['numero_hasta']) {
            $e[] = 'El último emitido no puede pasar el final del rango.';
        }
    }

    // Talonario de ARCA (con tipo ARCA)
    if ($d['codigo_arca'] !== null) {
        $t = $arca[$d['codigo_arca']] ?? null;
        if (!$t) {
            $e[] = 'Ese tipo de ARCA no existe o no está activo.';
        } elseif (!(int) $t['wsfe']) {
            $e[] = 'El tipo ' . $t['codigo'] . ' (' . $t['descripcion'] . ') no se emite por web service: van facturas, notas de débito y de crédito y recibos A, B o C.';
        } elseif ($t['letra'] !== $d['letra']) {
            $e[] = 'La letra no corresponde al tipo de ARCA: ' . $t['codigo'] . ' es ' . $t['descripcion'] . '.';
        } else {
            $cond = (string) ($empresa['condicion_iva'] ?? '');
            if ($cond === 'RI' && $d['letra'] === 'C') {
                $e[] = 'La empresa es responsable inscripta: emite comprobantes A y B (la C es de monotributistas y exentos).';
            } elseif (in_array($cond, ['MONO', 'EX'], true) && $d['letra'] !== 'C') {
                $e[] = 'La empresa es ' . strtolower(EMPRESA_COND_IVA[$cond] ?? $cond) . ': emite comprobantes C.';
            }
        }
        if (!isset(TAL_AMBIENTES[(string) $d['arca_ambiente']])) {
            $e[] = 'Elegí el ambiente de ARCA del talonario (homologación o producción).';
        }
        if ($d['punto_venta'] < 1 || $d['punto_venta'] > 99998) {
            $e[] = 'El punto de venta de ARCA va de 1 a 99998.';
        }
        if ($d['arca_concepto'] !== null && !isset(TAL_CONCEPTOS[(string) $d['arca_concepto']])) {
            $e[] = 'El concepto de ARCA es productos, servicios o los dos.';
        }
    } else {
        $d['arca_concepto'] = null;
        $d['arca_ambiente'] = null;
    }
    return $e;
}

/**
 * Guarda un talonario (alta si $id es 0). Los controles que miran otros
 * talonarios se hacen con la clasificación bloqueada, dentro de la misma
 * transacción. Devuelve el id. TalonarioError con el motivo si no se puede.
 */
function tal_guardar(array $d, int $id): int
{
    if ($d['codigo_arca'] === null) {
        $d['arca_concepto'] = null;
        $d['arca_ambiente'] = null;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // La clasificación bloqueada: dos talonarios de la misma clasificación
        // se graban de a uno, y el control de rangos ve lo último grabado.
        $cl = q1('SELECT c.id_clasificacion, c.descripcion, t.nombre AS tipo, t.debito_credito
                    FROM talonarios_clasificaciones c
                    JOIN comprobantes_tipos t ON t.id_comprobante_tipo = c.id_comprobante_tipo
                   WHERE c.id_clasificacion = ? FOR UPDATE', [$d['id_clasificacion']]);
        if (!$cl) {
            throw new TalonarioError('La clasificación elegida ya no existe.');
        }
        $antes = null;
        if ($id) {
            $antes = q1('SELECT * FROM talonarios WHERE id_talonario = ? FOR UPDATE', [$id]);
            if (!$antes) {
                throw new TalonarioError('El talonario ya no existe.');
            }
        }

        if ($d['codigo_arca'] !== null) {
            $t = q1('SELECT codigo, descripcion, debito_credito FROM arca_tipos_comprobante WHERE codigo = ?', [$d['codigo_arca']]);
            // La cuenta corriente suma o resta según la clasificación, no según ARCA.
            if ($t && $t['debito_credito'] !== $cl['debito_credito']) {
                throw new TalonarioError($t['descripcion'] . ' va ' . tal_lado($t['debito_credito']) . ' y la clasificación «'
                    . $cl['descripcion'] . '» es de ' . $cl['tipo'] . ', que va ' . tal_lado($cl['debito_credito'])
                    . '. Elegí una clasificación del tipo que corresponde.');
            }
            // ARCA numera por punto de venta y tipo: dos talonarios del mismo par
            // se pisarían los números (cada uno pediría «el último + 1»).
            $otro = q1('SELECT nombre FROM talonarios
                         WHERE codigo_arca = ? AND punto_venta = ? AND arca_ambiente = ? AND id_talonario <> ? LIMIT 1',
                       [$d['codigo_arca'], $d['punto_venta'], $d['arca_ambiente'], $id]);
            if ($otro) {
                throw new TalonarioError('Ya hay un talonario de ARCA para el punto de venta ' . $d['punto_venta'] . ' y el tipo '
                    . $d['codigo_arca'] . ' en ' . strtolower(TAL_AMBIENTES[$d['arca_ambiente']] ?? $d['arca_ambiente'])
                    . ' («' . $otro['nombre'] . '»): ARCA lleva una sola numeración.');
            }
        }

        // Rangos que se pisan en el mismo punto de venta, letra y clasificación:
        // darían números repetidos.
        $choque = q1('SELECT nombre, numero_desde, numero_hasta FROM talonarios
                       WHERE punto_venta = ? AND letra = ? AND id_clasificacion = ? AND id_talonario <> ?
                         AND numero_desde <= ? AND numero_hasta >= ? LIMIT 1',
                     [$d['punto_venta'], $d['letra'], $d['id_clasificacion'], $id, $d['numero_hasta'], $d['numero_desde']]);
        if ($choque) {
            throw new TalonarioError('El rango se pisa con el talonario «' . $choque['nombre'] . '» (' . num($choque['numero_desde'])
                . ' a ' . num($choque['numero_hasta']) . ') del mismo punto de venta y letra.');
        }

        if ($antes) {
            $usado = tal_usado($antes);
            if ($d['codigo_arca'] !== null && $antes['codigo_arca'] === null && $usado) {
                throw new TalonarioError('El talonario ya emitió comprobantes con numeración propia (va por el '
                    . num($antes['ultimo_emitido']) . '): no puede pasar a ARCA. Creá un talonario nuevo para el punto de venta de web services.');
            }
            if ($antes['codigo_arca'] !== null && $usado) {
                if ($d['codigo_arca'] === null) {
                    throw new TalonarioError('El talonario ya tiene comprobantes autorizados por ARCA: no se le puede sacar el tipo ARCA.');
                }
                foreach (['punto_venta' => 'el punto de venta', 'letra' => 'la letra', 'codigo_arca' => 'el tipo de ARCA',
                          'arca_ambiente' => 'el ambiente'] as $c => $que) {
                    if ((string) $antes[$c] !== (string) $d[$c]) {
                        throw new TalonarioError('El talonario ya tiene comprobantes autorizados por ARCA: no se le puede cambiar ' . $que . '.');
                    }
                }
            }
            // Ya emitió: la serie (letra, punto de venta, desde) está impresa en los
            // comprobantes. Cambiarla haría que los próximos parezcan de otra serie
            // y podrían chocar con los de otro talonario. Otra serie, otro talonario.
            if ($usado) {
                foreach (['letra' => 'la letra', 'punto_venta' => 'el punto de venta', 'numero_desde' => 'el número desde'] as $c => $que) {
                    if ((string) $antes[$c] !== (string) $d[$c]) {
                        throw new TalonarioError('El talonario ya emitió comprobantes (va por el ' . num($antes['ultimo_emitido'])
                            . '): no se le puede cambiar ' . $que . '. Para otra serie, creá un talonario nuevo.');
                    }
                }
            }
            // El último emitido no retrocede: rompería la trazabilidad.
            if ($d['ultimo_emitido'] < (int) $antes['ultimo_emitido']) {
                throw new TalonarioError('El último emitido no puede volver de ' . num($antes['ultimo_emitido']) . ' a '
                    . num($d['ultimo_emitido']) . ': los comprobantes con esos números ya existen.');
            }
            q('UPDATE talonarios SET nombre = ?, id_clasificacion = ?, letra = ?, punto_venta = ?, numero_desde = ?, numero_hasta = ?,
                                     ultimo_emitido = ?, habilitado = ?, comentario = ?, codigo_arca = ?, arca_concepto = ?, arca_ambiente = ?,
                                     modificado_el = NOW(), modificado_por = ?
                WHERE id_talonario = ?',
              [$d['nombre'], $d['id_clasificacion'], $d['letra'], $d['punto_venta'], $d['numero_desde'], $d['numero_hasta'],
               $d['ultimo_emitido'], $d['habilitado'], $d['comentario'], $d['codigo_arca'], $d['arca_concepto'], $d['arca_ambiente'],
               usuario_id(), $id]);
        } else {
            q('INSERT INTO talonarios (nombre, id_clasificacion, letra, punto_venta, numero_desde, numero_hasta, ultimo_emitido,
                                       habilitado, comentario, codigo_arca, arca_concepto, arca_ambiente, creado_por)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$d['nombre'], $d['id_clasificacion'], $d['letra'], $d['punto_venta'], $d['numero_desde'], $d['numero_hasta'],
               $d['ultimo_emitido'], $d['habilitado'], $d['comentario'], $d['codigo_arca'], $d['arca_concepto'], $d['arca_ambiente'],
               usuario_id()]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new TalonarioError(strpos($e->getMessage(), 'uq_talonario_arca') !== false
                ? 'Ya hay un talonario de ARCA para ese punto de venta, tipo y ambiente: ARCA lleva una sola numeración.'
                : 'Ya hay un talonario igual (misma clasificación, nombre, letra, punto de venta y rango).');
        }
        throw $e;
    }
}
