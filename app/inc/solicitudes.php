<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Solicitudes de préstamo: piezas comunes del listado, la ficha, la cola
// de autorización y la liquidación.
//
//   Circuito   PENDIENTE -> APROBADA -> LIQUIDADA
//                        -> OBSERVADA (vuelve a PENDIENTE al guardarla)
//                        -> RECHAZADA
//              PENDIENTE / OBSERVADA / APROBADA -> ANULADA
//
//   Quién ve qué
//     - Con «Autorizaciones» o «Préstamos» en el menú: todas.
//     - El resto (vendedores): las que cargó y las de su vendedor.
//   Quién autoriza: el que tiene «Autorizaciones» en el menú.
//   Quién liquida: el que tiene «Liquidar préstamo» (prestamo_nuevo.php).
//
//   Los cambios de estado se hacen con UPDATE … WHERE estado = el
//   esperado: si dos personas resuelven la misma a la vez, gana una sola.
// ---------------------------------------------------------------------

require_once __DIR__ . '/prestamos.php';

const ESTADOS_SOLICITUD = ['PENDIENTE' => 'Pendiente', 'OBSERVADA' => 'Observada', 'APROBADA' => 'Aprobada',
                           'RECHAZADA' => 'Rechazada', 'LIQUIDADA' => 'Liquidada', 'ANULADA' => 'Anulada'];
const ESTADOS_SOLICITUD_TIPO = ['PENDIENTE' => 'info', 'OBSERVADA' => 'warn', 'APROBADA' => 'ok',
                                'RECHAZADA' => 'danger', 'LIQUIDADA' => 'neutro', 'ANULADA' => 'neutro'];
const ACCIONES_SOLICITUD = ['CREADA' => 'Cargó la solicitud', 'MODIFICADA' => 'La modificó', 'REENVIADA' => 'La volvió a enviar',
                            'OBSERVADA' => 'La observó', 'APROBADA' => 'La aprobó', 'RECHAZADA' => 'La rechazó',
                            'ANULADA' => 'La anuló', 'LIQUIDADA' => 'La liquidó'];
const ACCIONES_SOLICITUD_TIPO = ['OBSERVADA' => 'warn', 'APROBADA' => 'ok', 'RECHAZADA' => 'danger', 'ANULADA' => 'neutro', 'LIQUIDADA' => 'ok'];
const SOLICITUD_COMENTARIO_MIN = 10;

function estado_solicitud(string $e): string
{
    return estado(ESTADOS_SOLICITUD[$e] ?? $e, ESTADOS_SOLICITUD_TIPO[$e] ?? 'neutro');
}

/** ¿Autoriza (aprueba, rechaza, observa)? */
function sol_autoriza(): bool
{
    return puede('autorizaciones.php');
}

/** ¿Liquida solicitudes aprobadas? */
function sol_liquida(): bool
{
    return puede('prestamo_nuevo.php');
}

/** ¿Ve las solicitudes de todos, o solo las suyas? */
function sol_ve_todas(): bool
{
    return sol_autoriza() || puede('prestamos.php');
}

/** El vendedor vinculado al usuario (Vendedores › Usuario del sistema), o null. */
function sol_vendedor_propio(): ?int
{
    static $v = false;
    if ($v === false) {
        $r = q1('SELECT id_vendedor FROM vendedores WHERE id_usuario = ? AND activo = 1 LIMIT 1', [usuario_id()]);
        $v = $r ? (int) $r['id_vendedor'] : null;
    }
    return $v;
}

/** Condición SQL (sobre el alias s) y parámetros para lo que el usuario puede ver. */
function sol_filtro_visibles(): array
{
    if (sol_ve_todas()) {
        return ['1 = 1', []];
    }
    $vend = sol_vendedor_propio();
    return $vend ? ['(s.creado_por = ? OR s.id_vendedor = ?)', [usuario_id(), $vend]] : ['s.creado_por = ?', [usuario_id()]];
}

/** La solicitud con su cliente, producto, vendedor y usuarios; null si no existe o no la puede ver. */
function sol_cargar(int $id): ?array
{
    [$w, $p] = sol_filtro_visibles();
    return q1("SELECT s.*, c.denominacion, c.cuit, c.tipo AS cliente_tipo, c.estado AS cliente_estado,
                      pr.nombre AS producto, v.nombre AS vendedor, uc.nombre AS creado_por_nombre,
                      ur.nombre AS resuelto_por_nombre, p.nro_prestamo
                 FROM solicitudes s
                 JOIN clientes c ON c.id_cliente = s.id_cliente
                 JOIN productos pr ON pr.id_producto = s.id_producto
                 LEFT JOIN vendedores v ON v.id_vendedor = s.id_vendedor
                 LEFT JOIN usuarios uc ON uc.id_usuario = s.creado_por
                 LEFT JOIN usuarios ur ON ur.id_usuario = s.resuelto_por
                 LEFT JOIN prestamos p ON p.id_prestamo = s.id_prestamo
                WHERE s.id_solicitud = ? AND $w", array_merge([$id], $p));
}

/** Cliente (con lo que piden los controles) por id, o por CUIT / CUIL / DNI escrito. */
function sol_buscar_cliente(string $id, string $txt): array
{
    $sql = 'SELECT c.*, cp.ingreso_mensual, cp.dni FROM clientes c LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente ';
    if ((int) $id > 0) {
        return q($sql . 'WHERE c.id_cliente = ?', [(int) $id]);
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

/** Próximo número de solicitud: S-000001, S-000002… */
function sol_proximo_nro(int $salto = 0): string
{
    $f = q1("SELECT COALESCE(MAX(CAST(SUBSTRING(nro_solicitud, 3) AS UNSIGNED)), 0) AS n
               FROM solicitudes WHERE nro_solicitud REGEXP '^S-[0-9]+$'");
    return 'S-' . str_pad((string) ((int) $f['n'] + 1 + $salto), 6, '0', STR_PAD_LEFT);
}

function sol_historial(int $id, string $accion, ?string $comentario = null): void
{
    q('INSERT INTO solicitudes_historial (id_solicitud, id_usuario, accion, comentario) VALUES (?,?,?,?)',
      [$id, usuario_id(), $accion, $comentario !== null && $comentario !== '' ? substr_utf8($comentario, 500) : null]);
}

/** Recorta sin partir un carácter, con o sin mbstring. */
function substr_utf8(string $s, int $largo): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $largo, 'UTF-8') : (string) preg_replace('/^(.{0,' . $largo . '}).*$/su', '$1', $s);
}

/**
 * Pasa la solicitud de uno de los estados $desde a $hacia, con los campos
 * de $set (columna => valor). Devuelve false si ya no estaba en $desde
 * (otro la resolvió antes): en ese caso no cambia nada.
 */
function sol_transicion(int $id, array $desde, string $hacia, array $set = []): bool
{
    $cols = 'estado = ?';
    $par = [$hacia];
    foreach ($set as $c => $v) {
        $cols .= ', ' . $c . ' = ' . ($v === 'NOW()' ? 'NOW()' : '?');
        if ($v !== 'NOW()') {
            $par[] = $v;
        }
    }
    $st = db()->prepare('UPDATE solicitudes SET ' . $cols . ' WHERE id_solicitud = ? AND estado IN ('
                        . implode(',', array_fill(0, count($desde), '?')) . ')');
    $st->execute(array_merge($par, [$id], $desde));
    return $st->rowCount() === 1;
}

/** Los controles guardados (JSON) como lista. */
function sol_controles(?string $json): array
{
    $c = $json ? json_decode($json, true) : null;
    return is_array($c) ? $c : [];
}

/** Píldora con lo peor que dijeron los controles. */
function sol_controles_resumen(array $ctl): string
{
    if (controles_hay($ctl, 'bloqueo')) {
        return estado('Bloqueo', 'danger');
    }
    $n = count(array_filter($ctl, function ($c) { return $c['n'] === 'advertencia'; }));
    if ($n) {
        return estado($n . ($n === 1 ? ' advertencia' : ' advertencias'), 'warn');
    }
    return $ctl ? estado('En orden', 'ok') : '<span class="td-muted">—</span>';
}

/** «hace 3 h», «hace 2 días»: cuánto lleva esperando. */
function sol_espera(string $desde): string
{
    $s = max(0, time() - strtotime($desde));
    if ($s < 3600) {
        return 'hace ' . max(1, (int) floor($s / 60)) . ' min';
    }
    if ($s < 86400) {
        return 'hace ' . (int) floor($s / 3600) . ' h';
    }
    $d = (int) floor($s / 86400);
    return 'hace ' . $d . ($d === 1 ? ' día' : ' días');
}

/** Fecha y hora: 19/11/2026 18:45. */
function fecha_hora(?string $dt): string
{
    return $dt ? fecha(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5) : '';
}

/** Otras solicitudes abiertas del mismo cliente (para avisar, no para impedir). */
function sol_abiertas_cliente(int $idCliente, int $menos = 0): array
{
    return q("SELECT id_solicitud, nro_solicitud, estado, monto FROM solicitudes
               WHERE id_cliente = ? AND id_solicitud <> ? AND estado IN ('PENDIENTE','OBSERVADA','APROBADA') ORDER BY id_solicitud",
             [$idCliente, $menos]);
}

// ---------------------------------------------------------------------
// Documentación para aprobar
//   El catálogo (Tablas y parámetros › Documentación requerida) dice, por
//   tipo de documento, si es OBLIGATORIO u OPCIONAL para aprobar.
//   Sin todos los obligatorios cargados y sin vencer, la solicitud no se
//   puede aprobar (sí cargar, observar o rechazar). Los opcionales se
//   muestran y no frenan. Se mira en el momento, no lo guardado.
// ---------------------------------------------------------------------

/** Cada documento que le corresponde al cliente (F o J), con su estado: OK, VENCIDO o FALTA. */
function sol_documentacion(int $idCliente, string $tipoCliente): array
{
    try {
        return q("SELECT t.id_tipo_doc, t.nombre, t.obligatorio, COUNT(d.id_documento) AS cargados,
                         MAX(d.fecha_vencimiento) AS ultimo_vencimiento,
                         CASE WHEN SUM(d.id_documento IS NOT NULL
                                       AND (d.fecha_vencimiento IS NULL OR d.fecha_vencimiento >= CURDATE())) > 0 THEN 'OK'
                              WHEN COUNT(d.id_documento) > 0 THEN 'VENCIDO'
                              ELSE 'FALTA' END AS estado
                    FROM documentos_tipos t
                    LEFT JOIN clientes_documentos d
                      ON d.id_cliente = ? AND d.id_tipo_doc = t.id_tipo_doc AND d.baja_el IS NULL
                   WHERE t.activo = 1 AND t.destinatario IN (?, 'AMBOS')
                   GROUP BY t.id_tipo_doc, t.nombre, t.obligatorio, t.orden
                   ORDER BY t.obligatorio DESC, t.orden, t.nombre", [$idCliente, $tipoCliente]);
    } catch (Throwable $e) {
        return [];                      // sin el módulo de documentación no se controla
    }
}

/** Los obligatorios que faltan o están vencidos: «DNI, Recibo de sueldo (vencido)». */
function sol_documentacion_falta(array $docs): array
{
    $f = [];
    foreach ($docs as $d) {
        if ((int) $d['obligatorio'] && $d['estado'] !== 'OK') {
            $f[] = $d['nombre'] . ($d['estado'] === 'VENCIDO' ? ' (vencido)' : '');
        }
    }
    return $f;
}

/** Píldora de la documentación: Completa / Faltan 2. */
function sol_documentacion_resumen(array $docs): string
{
    $obl = array_filter($docs, function ($d) { return (int) $d['obligatorio']; });
    if (!$obl) {
        return '<span class="td-muted">—</span>';
    }
    $n = count(sol_documentacion_falta($docs));
    return $n ? estado($n === 1 ? 'Falta 1' : 'Faltan ' . $n, 'danger') : estado('Completa', 'ok');
}

/**
 * Los controles de la solicitud sin el de documentación: en una solicitud
 * la documentación tiene su propia lista y su propia regla.
 */
function sol_controles_limpios(array $ctl): array
{
    return array_values(array_filter($ctl, function ($c) { return ($c['c'] ?? '') !== 'DOC'; }));
}
