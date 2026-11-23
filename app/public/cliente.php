<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Ficha del cliente (alta y edición)
//   cliente.php         alta
//   cliente.php?id=N    edición
// Persona física o empresa en el mismo formulario; los domicilios y
// teléfonos son renglones (el primero es el principal). Se guarda todo en
// una transacción: si algo falla, no queda nada a medias.
// ---------------------------------------------------------------------

const TIPOS_CLIENTE   = ['F' => 'Persona física', 'J' => 'Empresa'];
const ESTADOS_CLIENTE = ['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo', 'BLOQUEADO' => 'Bloqueado'];
const COND_IVA        = ['CF' => 'Consumidor final', 'MONO' => 'Monotributista', 'RI' => 'Responsable inscripto', 'EX' => 'Exento'];
const SEXOS           = ['F' => 'Femenino', 'M' => 'Masculino', 'X' => 'No binario'];
const ESTADOS_CIVILES = ['SOLTERO' => 'Soltero/a', 'CASADO' => 'Casado/a', 'CONVIVIENTE' => 'Unión convivencial',
                         'DIVORCIADO' => 'Divorciado/a', 'VIUDO' => 'Viudo/a'];
const TIPOS_SOC       = ['SA' => 'S.A.', 'SRL' => 'S.R.L.', 'SAS' => 'S.A.S.', 'SH' => 'Sociedad de hecho',
                         'COOP' => 'Cooperativa', 'ASOC' => 'Asociación civil', 'OTRA' => 'Otra'];
const TIPOS_DOM       = ['REAL' => 'Real', 'LEGAL' => 'Legal', 'LABORAL' => 'Laboral', 'COMERCIAL' => 'Comercial'];
const TIPOS_TEL       = ['CELULAR' => 'Celular', 'FIJO' => 'Fijo', 'LABORAL' => 'Laboral', 'REFERENCIA' => 'Referencia'];
const PROVINCIAS      = ['Buenos Aires', 'Ciudad Autónoma de Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
                         'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
                         'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
                         'Santiago del Estero', 'Tierra del Fuego', 'Tucumán'];

$id      = (int) get_str('id');
$errores = [];
$cliente = null;
// CBU y alias llegan con sql\financiera_datos_bancarios.sql: hasta que se corra,
// los campos no se muestran y todo lo demás sigue andando.
$conBanco = columna_existe('clientes', 'cbu');

/** Arreglo POST como lista de textos (vacía si falta o viene mal). */
function post_lista(string $clave): array
{
    $v = $_POST[$clave] ?? [];
    return is_array($v) ? array_values(array_map(function ($x) { return is_string($x) ? trim($x) : ''; }, $v)) : [];
}

function mayus(string $s): string
{
    return strtr(strtoupper($s), ['á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü']);
}

function monto_campo($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
}

// ---------------------------------------------------------------------
// Guardar
// ---------------------------------------------------------------------
// Contactos y observaciones (tarjetas al pie de la ficha): sus POST se
// atienden acá y redirigen; no pasan por el guardado de la ficha.
require_once __DIR__ . '/../inc/contactos.php';
require_once __DIR__ . '/../inc/observaciones.php';
require_once __DIR__ . '/../inc/cuota_social.php';
$contactoForm = null;
$contactoErrores = [];
$obsForm = null;
$obsErrores = [];
$csErrores = [];
$accionTarjeta = es_post() && in_array(post_str('accion'), ['contacto_guardar', 'contacto_baja', 'observacion_agregar', 'observacion_hecho',
                                                    'cs_alta', 'cs_baja', 'cs_reactivar', 'cs_anular', 'cs_pago_anular'], true);
if ($accionTarjeta && $id > 0) {
    csrf_exigir();
    try {
        $contactoErrores = contacto_acciones('cliente', $id, 'cliente.php?id=' . $id . '#contactos', $contactoForm) ?? [];
        $obsErrores = observacion_acciones($id, 'cliente.php?id=' . $id . '#observaciones', $obsForm) ?? [];
        $csErrores = cs_cliente_acciones($id, 'cliente.php?id=' . $id . '#cuota-social') ?? [];
    } catch (Throwable $e) {
        $obsErrores = [error_amigable($e)];
    }
}

// Sucursal y cuenta, fecha de alta, otro banco y venta de cartera llegan con
// sql\financiera_clientes_ficha.sql: hasta que se corra no se muestran.
$conFicha = columna_existe('clientes', 'cuenta_bancaria');

$form = null;
if (es_post() && !$accionTarjeta) {
    csrf_exigir();
    $f = [
        'tipo' => post_str('tipo'), 'cuit' => cuit_limpio(post_str('cuit')), 'estado' => post_str('estado'),
        'id_vendedor' => post_str('id_vendedor'), 'condicion_iva' => post_str('condicion_iva'), 'email' => post_str('email'),
        'id_tipo_asociado' => post_str('id_tipo_asociado'), 'id_reparticion' => post_str('id_reparticion'),
        'apellido' => mayus(post_str('apellido')), 'nombres' => mayus(post_str('nombres')), 'dni' => preg_replace('/\D/', '', post_str('dni')),
        'fecha_nac' => post_str('fecha_nac'), 'sexo' => post_str('sexo'), 'estado_civil' => post_str('estado_civil'),
        'ocupacion' => post_str('ocupacion'), 'empleador' => post_str('empleador'), 'ingreso_mensual' => post_str('ingreso_mensual'),
        'razon_social' => mayus(post_str('razon_social')), 'nombre_fantasia' => post_str('nombre_fantasia'),
        'tipo_societario' => post_str('tipo_societario'), 'actividad' => post_str('actividad'),
        'fecha_inicio' => post_str('fecha_inicio'), 'facturacion_anual' => post_str('facturacion_anual'),
        'cbu' => cbu_limpio(post_str('cbu')), 'alias_cbu' => alias_cbu_limpio(post_str('alias_cbu')),
        'id_banco' => post_str('id_banco'), 'fecha_alta' => post_str('fecha_alta'), 'banco_sucursal' => preg_replace('/\D/', '', post_str('banco_sucursal')),
        'cuenta_bancaria' => preg_replace('/[^0-9\/-]/', '', post_str('cuenta_bancaria')),
        'otro_banco' => post_str('otro_banco') === '1' ? 1 : 0, 'venta_cartera' => post_str('venta_cartera') === '1' ? 1 : 0,
        'domicilios' => [], 'telefonos' => [],
    ];
    $dT = post_lista('dom_tipo');   $dC = post_lista('dom_calle'); $dN = post_lista('dom_numero');
    $dP = post_lista('dom_piso');   $dL = post_lista('dom_localidad'); $dPr = post_lista('dom_provincia'); $dCp = post_lista('dom_cp');
    foreach ($dC as $i => $calle) {
        $r = ['tipo' => $dT[$i] ?? 'REAL', 'calle' => $calle, 'numero' => $dN[$i] ?? '', 'piso_depto' => $dP[$i] ?? '',
              'localidad' => $dL[$i] ?? '', 'provincia' => $dPr[$i] ?? '', 'cp' => $dCp[$i] ?? ''];
        if (implode('', [$r['calle'], $r['numero'], $r['piso_depto'], $r['localidad'], $r['cp']]) !== '') {
            $f['domicilios'][] = $r;
        }
    }
    // Los teléfonos son contactos (tarjeta Contactos). En el alta, un celular
    // opcional que queda como el contacto titular principal.
    $f['celular_alta'] = $id > 0 ? '' : post_str('celular_alta');
    $form = $f;

    // Validaciones
    if (!isset(TIPOS_CLIENTE[$f['tipo']])) {
        $errores[] = 'Elegí si es persona física o empresa.';
    }
    if (!cuit_valido($f['cuit'])) {
        $errores[] = strlen($f['cuit']) === 11 ? 'El CUIT/CUIL no es válido: el dígito verificador no coincide.' : 'El CUIT/CUIL tiene que tener 11 dígitos.';
    } elseif ($f['tipo'] === 'F' && !in_array(substr($f['cuit'], 0, 2), ['20', '23', '24', '27'], true)) {
        $errores[] = 'Un CUIL de persona empieza con 20, 23, 24 o 27; ' . cuit_fmt($f['cuit']) . ' parece de una empresa.';
    } elseif ($f['tipo'] === 'J' && !in_array(substr($f['cuit'], 0, 2), ['30', '33', '34'], true)) {
        $errores[] = 'Un CUIT de empresa empieza con 30, 33 o 34; ' . cuit_fmt($f['cuit']) . ' parece de una persona.';
    }
    if (!isset(ESTADOS_CLIENTE[$f['estado']])) {
        $errores[] = 'Elegí el estado.';
    }
    if ($f['condicion_iva'] !== '' && !isset(COND_IVA[$f['condicion_iva']])) {
        $errores[] = 'La condición de IVA no es válida.';
    }
    if ((int) $f['id_tipo_asociado'] && !q1('SELECT 1 AS x FROM tipos_asociado WHERE id_tipo_asociado = ?', [(int) $f['id_tipo_asociado']])) {
        $errores[] = 'El tipo de asociado elegido ya no existe.';
    }
    if ((int) $f['id_reparticion'] && !q1('SELECT 1 AS x FROM reparticiones WHERE id_reparticion = ?', [(int) $f['id_reparticion']])) {
        $errores[] = 'La repartición elegida ya no existe.';
    }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no es válido.';
    }
    if ($f['tipo'] === 'F') {
        if ($f['apellido'] === '' || $f['nombres'] === '') {
            $errores[] = 'Faltan el apellido y los nombres.';
        }
        if (!preg_match('/^\d{7,8}$/', $f['dni'])) {
            $errores[] = 'El DNI tiene 7 u 8 dígitos.';
        } elseif (strlen($f['cuit']) === 11 && ltrim(substr($f['cuit'], 2, 8), '0') !== ltrim($f['dni'], '0')) {
            $errores[] = 'El DNI no coincide con el CUIL (' . cuit_fmt($f['cuit']) . ').';
        }
        if ($f['fecha_nac'] !== '' && (leer_fecha($f['fecha_nac']) === null || $f['fecha_nac'] > date('Y-m-d'))) {
            $errores[] = 'La fecha de nacimiento no es válida.';
        }
        if ($f['sexo'] !== '' && !isset(SEXOS[$f['sexo']])) {
            $errores[] = 'El sexo no es válido.';
        }
        if ($f['ingreso_mensual'] !== '' && (leer_num($f['ingreso_mensual']) === null || leer_num($f['ingreso_mensual']) < 0)) {
            $errores[] = 'El ingreso mensual no es un importe válido.';
        }
    } elseif ($f['tipo'] === 'J') {
        if ($f['razon_social'] === '') {
            $errores[] = 'Falta la razón social.';
        }
        if ($f['fecha_inicio'] !== '' && leer_fecha($f['fecha_inicio']) === null) {
            $errores[] = 'La fecha de inicio de actividades no es válida.';
        }
        if ($f['facturacion_anual'] !== '' && (leer_num($f['facturacion_anual']) === null || leer_num($f['facturacion_anual']) < 0)) {
            $errores[] = 'La facturación anual no es un importe válido.';
        }
    }
    if ($conBanco) {
        $errores = array_merge($errores, datos_bancarios_errores($f['cbu'], $f['alias_cbu']));
    }
    if ($conFicha) {
        if ($f['fecha_alta'] !== '' && (!leer_fecha($f['fecha_alta']) || leer_fecha($f['fecha_alta']) > date('Y-m-d'))) {
            $errores[] = 'La fecha de alta no es válida (no puede ser futura).';
        }
        if (strlen($f['banco_sucursal']) > 10) {
            $errores[] = 'La sucursal del banco va con hasta 10 números.';
        }
        if (strlen($f['cuenta_bancaria']) > 20) {
            $errores[] = 'El número de cuenta va con hasta 20 caracteres.';
        }
    }
    foreach ($f['domicilios'] as $k => $d) {
        if ($d['calle'] === '' || $d['localidad'] === '' || !in_array($d['provincia'], PROVINCIAS, true)) {
            $errores[] = 'Domicilio ' . ($k + 1) . ': faltan la calle, la localidad o la provincia.';
        }
    }
    // El celular del alta, en el formato único del sistema (tel_normalizar en ui.php).
    $celAlta = null;
    if ($f['celular_alta'] !== '') {
        $celAlta = tel_normalizar($f['celular_alta'], true);
        if ($celAlta === null) {
            $errores[] = tel_error('El celular');
        }
    }

    if (!$errores) {
        $denominacion = $f['tipo'] === 'F' ? $f['apellido'] . ' ' . $f['nombres'] : $f['razon_social'];
        $vend = (int) $f['id_vendedor'] ?: null;
        $tipoAs = (int) $f['id_tipo_asociado'] ?: null;
        $repart = (int) $f['id_reparticion'] ?: null;
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                q('UPDATE clientes SET tipo=?, cuit=?, denominacion=?, email=?, condicion_iva=?, id_vendedor=?, estado=?,
                          id_tipo_asociado=?, id_reparticion=? WHERE id_cliente=?',
                  [$f['tipo'], $f['cuit'], $denominacion, $f['email'] ?: null, $f['condicion_iva'] ?: null, $vend, $f['estado'],
                   $tipoAs, $repart, $id]);
            } else {
                q('INSERT INTO clientes (tipo, cuit, denominacion, email, condicion_iva, id_vendedor, estado, id_tipo_asociado, id_reparticion, creado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [$f['tipo'], $f['cuit'], $denominacion, $f['email'] ?: null, $f['condicion_iva'] ?: null, $vend, $f['estado'],
                   $tipoAs, $repart, usuario_id()]);
                $id = (int) $pdo->lastInsertId();
            }
            if ($f['tipo'] === 'F') {
                q('DELETE FROM clientes_empresas WHERE id_cliente = ?', [$id]);
                q('INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
                   VALUES (?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE apellido=VALUES(apellido), nombres=VALUES(nombres), dni=VALUES(dni), fecha_nac=VALUES(fecha_nac),
                     sexo=VALUES(sexo), estado_civil=VALUES(estado_civil), ocupacion=VALUES(ocupacion), empleador=VALUES(empleador),
                     ingreso_mensual=VALUES(ingreso_mensual)',
                  [$id, $f['apellido'], $f['nombres'], $f['dni'], leer_fecha($f['fecha_nac']), $f['sexo'] ?: null,
                   $f['estado_civil'] ?: null, $f['ocupacion'] ?: null, $f['empleador'] ?: null,
                   $f['ingreso_mensual'] === '' ? null : round(leer_num($f['ingreso_mensual']), 2)]);
            } else {
                q('DELETE FROM clientes_personas WHERE id_cliente = ?', [$id]);
                q('INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
                   VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE razon_social=VALUES(razon_social), nombre_fantasia=VALUES(nombre_fantasia),
                     tipo_societario=VALUES(tipo_societario), actividad=VALUES(actividad), fecha_inicio=VALUES(fecha_inicio),
                     facturacion_anual=VALUES(facturacion_anual)',
                  [$id, $f['razon_social'], $f['nombre_fantasia'] ?: null, $f['tipo_societario'] ?: null, $f['actividad'] ?: null,
                   leer_fecha($f['fecha_inicio']),
                   $f['facturacion_anual'] === '' ? null : round(leer_num($f['facturacion_anual']), 2)]);
            }
            q('DELETE FROM domicilios WHERE id_cliente = ?', [$id]);
            foreach ($f['domicilios'] as $k => $d) {
                q('INSERT INTO domicilios (id_cliente, tipo, calle, numero, piso_depto, localidad, provincia, cp, principal) VALUES (?,?,?,?,?,?,?,?,?)',
                  [$id, isset(TIPOS_DOM[$d['tipo']]) ? $d['tipo'] : 'REAL', $d['calle'], $d['numero'] ?: null, $d['piso_depto'] ?: null,
                   $d['localidad'], $d['provincia'], $d['cp'] ?: null, $k === 0 ? 1 : 0]);
            }
            if ($conBanco) {
                q('UPDATE clientes SET cbu = ?, alias_cbu = ? WHERE id_cliente = ?', [$f['cbu'] ?: null, $f['alias_cbu'] ?: null, $id]);
            }
            if ($conFicha) {
                // Sin banco elegido, el del CBU (sus tres primeros números son el código BCRA).
                $idBanco = (int) $f['id_banco'] ?: (int) (q1('SELECT id_banco FROM bancos WHERE codigo = ?', [substr((string) $f['cbu'], 0, 3)])['id_banco'] ?? 0);
                q('UPDATE clientes SET id_banco = ?, fecha_alta = COALESCE(?, fecha_alta, CURDATE()), banco_sucursal = ?, cuenta_bancaria = ?,
                          otro_banco = ?, venta_cartera = ? WHERE id_cliente = ?',
                  [$idBanco ?: null, leer_fecha($f['fecha_alta']), $f['banco_sucursal'] ?: null, $f['cuenta_bancaria'] ?: null,
                   $f['otro_banco'], $f['venta_cartera'], $id]);
            }
            if ($celAlta !== null) {
                $tit = q1("SELECT id_tipo_contacto FROM contactos_tipos WHERE nombre = 'Titular'");
                q('INSERT INTO contactos (entidad_tipo, entidad_id, nombre, id_tipo_contacto, celular, principal, creado_por)
                   VALUES (?,?,?,?,?,1,?)', ['cliente', $id, mb_substr($denominacion, 0, 150), $tit['id_tipo_contacto'] ?? null, $celAlta, usuario_id()]);
            }
            $pdo->commit();

            // Mismo celular en otro cliente: se avisa, no se impide (puede ser un familiar).
            $aviso = '';
            if ($celAlta !== null) {
                $otros = q("SELECT DISTINCT c.denominacion FROM contactos t JOIN clientes c ON c.id_cliente = t.entidad_id
                             WHERE t.entidad_tipo = 'cliente' AND t.activo = 1 AND (t.celular = ? OR t.telefono = ?) AND t.entidad_id <> ? LIMIT 3",
                           [$celAlta, $celAlta, $id]);
                if ($otros) {
                    $aviso = ' Ojo: el celular también figura en ' . implode(', ', array_column($otros, 'denominacion')) . '.';
                }
            }
            if ($conBanco && $f['cbu'] !== '') {
                $otros = q('SELECT denominacion FROM clientes WHERE cbu = ? AND id_cliente <> ? LIMIT 3', [$f['cbu'], $id]);
                if ($otros) {
                    $aviso .= ' Ojo: el CBU también es de ' . implode(', ', array_column($otros, 'denominacion')) . ': el préstamo se transfiere a una cuenta a nombre del cliente.';
                }
            }
            flash($aviso ? 'warning' : 'ok', 'Cliente ' . $denominacion . ' guardado.' . $aviso);
            redirigir('cliente.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062 && strpos($e->getMessage(), 'uq_clientes_cuit') !== false) {
                $otro = q1('SELECT id_cliente, denominacion FROM clientes WHERE cuit = ?', [$f['cuit']]);
                $errores[] = 'Ya hay un cliente con el CUIT ' . cuit_fmt($f['cuit']) . ($otro ? ': ' . $otro['denominacion'] . ' (N° ' . $otro['id_cliente'] . ').' : '.');
            } else {
                $errores[] = mensaje_bd($e);
            }
            if (!isset($_GET['id'])) {
                $id = 0;
            }
        }
    }
}

// ---------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------
$vendedores = [];
$prestamos  = [];
$tiposAsoc  = [];
$reparts    = [];
try {
    $vendedores = q('SELECT id_vendedor, nombre FROM vendedores WHERE activo = 1 ORDER BY nombre');
    // Los inactivos solo si el cliente ya los tiene: no se ofrecen para uno nuevo.
    $tiposAsoc = q('SELECT id_tipo_asociado, nombre, activo FROM tipos_asociado
                     WHERE activo = 1 OR id_tipo_asociado = (SELECT id_tipo_asociado FROM clientes WHERE id_cliente = ?) ORDER BY nombre', [$id]);
    $reparts = q('SELECT r.id_reparticion, r.nombre, o.nombre AS organismo, r.activo FROM reparticiones r
                   LEFT JOIN organismos o ON o.id_organismo = r.id_organismo
                   WHERE r.activo = 1 OR r.id_reparticion = (SELECT id_reparticion FROM clientes WHERE id_cliente = ?)
                   ORDER BY o.nombre IS NULL, o.nombre, r.nombre', [$id]);
    if ($id > 0) {
        $cliente = q1('SELECT c.*, cp.apellido, cp.nombres, cp.dni, cp.fecha_nac, cp.sexo, cp.estado_civil, cp.ocupacion,
                              cp.empleador, cp.ingreso_mensual, ce.razon_social, ce.nombre_fantasia, ce.tipo_societario,
                              ce.actividad, ce.fecha_inicio, ce.facturacion_anual
                         FROM clientes c
                         LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
                         LEFT JOIN clientes_empresas ce ON ce.id_cliente = c.id_cliente
                        WHERE c.id_cliente = ?', [$id]);
        if ($cliente) {
            $cliente['domicilios'] = q('SELECT * FROM domicilios WHERE id_cliente = ? ORDER BY principal DESC, id_domicilio', [$id]);
            $prestamos = q('SELECT p.id_prestamo, p.nro_prestamo, p.fecha_liquidacion, p.capital, p.plazo, p.valor_cuota, p.estado, ta.nombre AS tipo_asociado
                              FROM prestamos p JOIN tipos_asociado ta ON ta.id_tipo_asociado = p.id_tipo_asociado
                             WHERE p.id_cliente = ? ORDER BY p.fecha_liquidacion DESC, p.id_prestamo DESC', [$id]);
        }
    }
} catch (Throwable $e) {
    $errores[] = error_amigable($e);
}

// Documentación: en su propio try, así si falta correr financiera_documentos.sql
// la ficha igual funciona.
$docTipos   = [];
$checklist  = [];
$documentos = [];
$docBajas   = 0;
$verBajas   = get_str('bajas') === '1';
$docError   = '';
if ($cliente) {
    require_once __DIR__ . '/../inc/documentos.php';
    try {
        $docTipos  = doc_tipos_para($cliente['tipo']);
        $checklist = q('SELECT * FROM v_clientes_doc_checklist WHERE id_cliente = ? ORDER BY orden, nombre', [$id]);
        $documentos = q('SELECT d.*, t.nombre AS tipo_nombre, t.orden
                           FROM clientes_documentos d
                           JOIN documentos_tipos t ON t.id_tipo_doc = d.id_tipo_doc
                          WHERE d.id_cliente = ? AND (d.baja_el IS NULL OR ? = 1)
                          ORDER BY d.baja_el IS NOT NULL, t.orden, t.nombre, d.subido_el DESC, d.id_documento DESC',
                        [$id, $verBajas ? 1 : 0]);
        $docBajas = (int) q1('SELECT COUNT(*) AS n FROM clientes_documentos WHERE id_cliente = ? AND baja_el IS NOT NULL', [$id])['n'];
    } catch (Throwable $e) {
        $docError = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
            ? 'Falta crear las tablas de documentación: corré sql\financiera_documentos.sql.'
            : error_amigable($e);
    }
}

// Central de Deudores: lo último guardado (la consulta al BCRA se hace con el botón).
$bcraFilas = [];
$bcraError = '';
if ($cliente) {
    require_once __DIR__ . '/../inc/bcra.php';
    try {
        $bcraFilas = bcra_guardadas($cliente['cuit']);
    } catch (Throwable $e) {
        $bcraError = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
            ? 'Falta crear la tabla de consultas: corré sql\\financiera_bcra.sql.' : error_amigable($e);
    }
}

// ARCA: la última constancia guardada (la consulta se hace con el botón «Consultar ARCA»).
require_once __DIR__ . '/../inc/arca.php';
$arcaGuardada = null;
if ($cliente) {
    $arcaGuardada = arca_constancia_guardada($cliente['cuit']);
}

if ($id > 0 && !$cliente && !$form) {
    http_response_code(404);
}
layout_inicio($cliente ? $cliente['denominacion'] : 'Nuevo cliente', 'clientes');

if ($id > 0 && !$cliente && !$form) {
    echo '<div class="pantalla-aviso"><div class="pantalla-aviso-icono">' . icono('alerta', 40) . '</div>'
       . '<h1>No existe ese cliente</h1><p>Revisá el número o buscalo en el listado.</p>'
       . boton('Ir a clientes', 'primary', 'clientes.php', 'volver') . '</div>';
    layout_fin();
    exit;
}

// Valores a mostrar: lo cargado (si volvió con errores), lo guardado o vacío.
$v = $form ?? ($cliente ? array_merge($cliente, [
    'ingreso_mensual' => monto_campo($cliente['ingreso_mensual']),
    'facturacion_anual' => monto_campo($cliente['facturacion_anual']),
    'id_vendedor' => (string) $cliente['id_vendedor'],
    'id_tipo_asociado' => (string) $cliente['id_tipo_asociado'], 'id_reparticion' => (string) $cliente['id_reparticion'],
]) : ['tipo' => 'F', 'cuit' => '', 'estado' => 'ACTIVO', 'id_vendedor' => '', 'condicion_iva' => 'CF', 'email' => '',
      'id_tipo_asociado' => '', 'id_reparticion' => '',
      'apellido' => '', 'nombres' => '', 'dni' => '', 'fecha_nac' => '', 'sexo' => '', 'estado_civil' => '', 'ocupacion' => '',
      'empleador' => '', 'ingreso_mensual' => '', 'razon_social' => '', 'nombre_fantasia' => '', 'tipo_societario' => '',
      'actividad' => '', 'fecha_inicio' => '', 'facturacion_anual' => '', 'cbu' => '', 'alias_cbu' => '',
      'id_banco' => '', 'fecha_alta' => date('Y-m-d'), 'banco_sucursal' => '', 'cuenta_bancaria' => '', 'otro_banco' => 0, 'venta_cartera' => 0,
      'domicilios' => [], 'telefonos' => []]);
$doms = $v['domicilios'] ?: [['tipo' => 'REAL', 'calle' => '', 'numero' => '', 'piso_depto' => '', 'localidad' => '', 'provincia' => 'Buenos Aires', 'cp' => '']];
$esF  = $v['tipo'] !== 'J';

titulo($cliente ? $cliente['denominacion'] : 'Nuevo cliente',
       $cliente ? cuit_fmt($cliente['cuit']) . ' · ' . TIPOS_CLIENTE[$cliente['tipo']] . ' · cliente N° ' . $cliente['id_cliente'] : 'Persona física o empresa',
       '', '<a href="clientes.php">Clientes</a>' . ($cliente ? ' / ' . h($cliente['denominacion']) : ''));
// El mensaje de la documentación se muestra en su tarjeta (la página vuelve ahí).
$docFlash = null;
if (get_str('doc') === '1' && $GLOBALS['FLASH']) {
    $docFlash = $GLOBALS['FLASH'];
    $GLOBALS['FLASH'] = null;
}
$bcraFlash = null;
if (get_str('bcra') === '1' && $GLOBALS['FLASH']) {
    $bcraFlash = $GLOBALS['FLASH'];
    $GLOBALS['FLASH'] = null;
}
mensajes($errores);

function renglon_domicilio(array $d): string
{
    $sel = function (array $lista, $v) { return opciones($lista, $v); };
    $provs = '';
    foreach (PROVINCIAS as $p) {
        $provs .= '<option' . ($p === ($d['provincia'] ?? '') ? ' selected' : '') . '>' . h($p) . '</option>';
    }
    return '<div class="renglon">'
        . '<div class="campo" style="--span:2"><label>Tipo</label><select name="dom_tipo[]">' . $sel(TIPOS_DOM, $d['tipo'] ?? 'REAL') . '</select></div>'
        . '<div class="campo" style="--span:3"><label>Calle</label><input name="dom_calle[]" value="' . h($d['calle'] ?? '') . '" maxlength="120"></div>'
        . '<div class="campo" style="--span:1"><label>Número</label><input name="dom_numero[]" value="' . h($d['numero'] ?? '') . '" maxlength="10"></div>'
        . '<div class="campo" style="--span:1"><label>Piso/Dpto</label><input name="dom_piso[]" value="' . h($d['piso_depto'] ?? '') . '" maxlength="20"></div>'
        . '<div class="campo" style="--span:2"><label>Localidad</label><input name="dom_localidad[]" value="' . h($d['localidad'] ?? '') . '" maxlength="80"></div>'
        . '<div class="campo" style="--span:2"><label>Provincia</label><select name="dom_provincia[]">' . $provs . '</select></div>'
        . '<div class="campo" style="--span:1"><label>CP</label><input name="dom_cp[]" value="' . h($d['cp'] ?? '') . '" maxlength="10"></div>'
        . '<button type="button" class="btn-accion borrar renglon-quitar" title="Quitar el domicilio" aria-label="Quitar el domicilio">' . icono('cerrar') . '</button>'
        . '</div>';
}

?>

<form method="post" action="cliente.php<?= $id ? '?id=' . $id : '' ?>" id="frm-cliente">
  <?= csrf_campo() ?>
  <div class="card">
    <?= card_head('Identificación') ?>
    <div class="bloque">
      <div class="campos">
        <div class="campo" style="--span:3">
          <span class="campo-label">Tipo</span>
          <div class="radios">
            <?php foreach (TIPOS_CLIENTE as $k => $t): ?>
              <label><input type="radio" name="tipo" value="<?= $k ?>" <?= $v['tipo'] === $k ? 'checked' : '' ?>> <?= h($t) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="campo" style="--span:3">
          <label for="cuit"><span class="solo-F">CUIL</span><span class="solo-J">CUIT</span> <span class="req">*</span></label>
          <div style="display:flex;gap:6px;align-items:center">
            <input id="cuit" name="cuit" value="<?= h(cuit_fmt($v['cuit'])) ?>" maxlength="13" class="mono" data-cuit required autocomplete="off" placeholder="20-12345678-9" style="flex:1;min-width:0">
            <?= boton('ARCA', 'ghost', null, 'buscar', false, true, ['id' => 'btn-arca', 'title' => 'Consultar ARCA: trae nombre, domicilio fiscal y condición frente al IVA']) ?>
          </div>
          <span class="campo-ayuda"></span>
        </div>
        <div class="campo" style="--span:2">
          <label for="estado">Estado</label>
          <select id="estado" name="estado"><?= opciones(ESTADOS_CLIENTE, $v['estado']) ?></select>
        </div>
        <div class="campo" style="--span:2">
          <label for="condicion_iva">Condición IVA</label>
          <select id="condicion_iva" name="condicion_iva"><?= opciones(COND_IVA, $v['condicion_iva'] ?? '', '—') ?></select>
        </div>
        <div class="campo" style="--span:2">
          <label for="id_vendedor">Comercializador</label>
          <select id="id_vendedor" name="id_vendedor"><?= opciones(array_column($vendedores, 'nombre', 'id_vendedor'), $v['id_vendedor'] ?? '', 'Sin comercializador') ?></select>
        </div>
        <div class="campo" style="--span:4">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="<?= h($v['email'] ?? '') ?>" maxlength="150">
        </div>
        <div class="campo" style="--span:4">
          <label for="id_tipo_asociado">Tipo de asociado</label>
          <select id="id_tipo_asociado" name="id_tipo_asociado">
            <option value="">—</option>
            <?php foreach ($tiposAsoc as $t): ?>
              <option value="<?= (int) $t['id_tipo_asociado'] ?>" <?= (string) $t['id_tipo_asociado'] === (string) ($v['id_tipo_asociado'] ?? '') ? 'selected' : '' ?>><?= h($t['nombre']) ?><?= (int) $t['activo'] ? '' : ' (inactivo)' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda">el habitual: la solicitud lo trae y se puede cambiar</span>
        </div>
        <div class="campo" style="--span:4">
          <label for="id_reparticion">Repartición</label>
          <select id="id_reparticion" name="id_reparticion">
            <option value="">—</option>
            <?php $grupo = false; foreach ($reparts as $rp):
                $org = (string) ($rp['organismo'] ?? '');
                if ($grupo === false || $org !== $grupo): ?>
              <?= $grupo !== false ? '</optgroup>' : '' ?><optgroup label="<?= h($org !== '' ? $org : 'Otras') ?>">
            <?php $grupo = $org; endif; ?>
                <option value="<?= (int) $rp['id_reparticion'] ?>" <?= (string) $rp['id_reparticion'] === (string) ($v['id_reparticion'] ?? '') ? 'selected' : '' ?>><?= h($rp['nombre']) ?><?= (int) $rp['activo'] ? '' : ' (inactiva)' ?></option>
            <?php endforeach; ?>
            <?= $grupo !== false ? '</optgroup>' : '' ?>
          </select>
          <span class="campo-ayuda">dónde cobra</span>
        </div>
      </div>
    </div>
    <div class="bloque" id="arca-bloque" hidden
         data-guardada="<?= h($arcaGuardada ? json_encode($arcaGuardada, JSON_UNESCAPED_UNICODE) : '') ?>">
      <div class="bloque-titulo">ARCA <span id="arca-cuando" class="td-muted" style="font-weight:400"></span></div>
      <div id="arca-aviso"></div>
      <dl class="datos-lista" id="arca-datos" style="padding:8px 0"></dl>
    </div>

    <fieldset class="bloque solo-F" <?= $esF ? '' : 'disabled' ?>>
      <div class="bloque-titulo">Datos de la persona</div>
      <div class="campos">
        <div class="campo" style="--span:3"><label for="apellido">Apellido <span class="req">*</span></label>
          <input id="apellido" name="apellido" value="<?= h($v['apellido'] ?? '') ?>" maxlength="80" required></div>
        <div class="campo" style="--span:3"><label for="nombres">Nombres <span class="req">*</span></label>
          <input id="nombres" name="nombres" value="<?= h($v['nombres'] ?? '') ?>" maxlength="80" required></div>
        <div class="campo" style="--span:2"><label for="dni">DNI <span class="req">*</span></label>
          <input id="dni" name="dni" value="<?= h($v['dni'] ?? '') ?>" maxlength="10" inputmode="numeric" class="mono" required>
          <span class="campo-ayuda">sale del CUIL</span></div>
        <div class="campo" style="--span:2"><label for="fecha_nac">Nacimiento</label>
          <input type="date" id="fecha_nac" name="fecha_nac" value="<?= h($v['fecha_nac'] ?? '') ?>"></div>
        <div class="campo" style="--span:2"><label for="sexo">Sexo</label>
          <select id="sexo" name="sexo"><?= opciones(SEXOS, $v['sexo'] ?? '', '—') ?></select></div>
        <div class="campo" style="--span:2"><label for="estado_civil">Estado civil</label>
          <select id="estado_civil" name="estado_civil"><?= opciones(ESTADOS_CIVILES, $v['estado_civil'] ?? '', '—') ?></select></div>
        <div class="campo" style="--span:3"><label for="ocupacion">Ocupación</label>
          <input id="ocupacion" name="ocupacion" value="<?= h($v['ocupacion'] ?? '') ?>" maxlength="80" placeholder="Jubilado, docente…"></div>
        <div class="campo" style="--span:4"><label for="empleador">Empleador / repartición</label>
          <input id="empleador" name="empleador" value="<?= h($v['empleador'] ?? '') ?>" maxlength="120" placeholder="ANSES, IPS, Ministerio de Educación…"></div>
        <div class="campo" style="--span:3"><label for="ingreso_mensual">Ingreso mensual</label>
          <input id="ingreso_mensual" name="ingreso_mensual" value="<?= h($v['ingreso_mensual'] ?? '') ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">$ neto</span></div>
      </div>
    </fieldset>

    <fieldset class="bloque solo-J" <?= $esF ? 'disabled' : '' ?>>
      <div class="bloque-titulo">Datos de la empresa</div>
      <div class="campos">
        <div class="campo" style="--span:5"><label for="razon_social">Razón social <span class="req">*</span></label>
          <input id="razon_social" name="razon_social" value="<?= h($v['razon_social'] ?? '') ?>" maxlength="150" required></div>
        <div class="campo" style="--span:4"><label for="nombre_fantasia">Nombre de fantasía</label>
          <input id="nombre_fantasia" name="nombre_fantasia" value="<?= h($v['nombre_fantasia'] ?? '') ?>" maxlength="150"></div>
        <div class="campo" style="--span:3"><label for="tipo_societario">Tipo societario</label>
          <select id="tipo_societario" name="tipo_societario"><?= opciones(TIPOS_SOC, $v['tipo_societario'] ?? '', '—') ?></select></div>
        <div class="campo" style="--span:5"><label for="actividad">Actividad</label>
          <input id="actividad" name="actividad" value="<?= h($v['actividad'] ?? '') ?>" maxlength="150"></div>
        <div class="campo" style="--span:3"><label for="fecha_inicio">Inicio de actividades</label>
          <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= h($v['fecha_inicio'] ?? '') ?>"></div>
        <div class="campo" style="--span:4"><label for="facturacion_anual">Facturación anual</label>
          <input id="facturacion_anual" name="facturacion_anual" value="<?= h($v['facturacion_anual'] ?? '') ?>" inputmode="decimal" class="derecha">
          <span class="campo-ayuda">$</span></div>
      </div>
    </fieldset>

    <?php if ($conBanco): ?>
    <div class="bloque">
      <div class="bloque-titulo">Datos bancarios</div>
      <div class="campos">
        <?= campos_datos_bancarios($v['cbu'] ?? '', $v['alias_cbu'] ?? '', 'de una cuenta a nombre del cliente: ahí se le transfiere el préstamo') ?>
        <?php if ($conFicha):
            $bancosLista = q('SELECT id_banco, codigo, nombre FROM bancos WHERE activo = 1 OR id_banco = ? ORDER BY nombre', [(int) ($v['id_banco'] ?? 0)]); ?>
        <div class="campo" style="--span:4">
          <label for="id_banco">Banco</label>
          <select id="id_banco" name="id_banco">
            <option value="">—</option>
            <?php foreach ($bancosLista as $b): ?>
              <option value="<?= (int) $b['id_banco'] ?>" data-codigo="<?= h($b['codigo']) ?>" <?= (string) $b['id_banco'] === (string) ($v['id_banco'] ?? '') ? 'selected' : '' ?>><?= h($b['nombre']) ?> (<?= h($b['codigo']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda">sale solo del CBU; los bancos se cargan en Tesorería › Bancos</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="banco_sucursal">Sucursal</label>
          <input id="banco_sucursal" name="banco_sucursal" value="<?= h($v['banco_sucursal'] ?? '') ?>" maxlength="10" inputmode="numeric" class="mono">
        </div>
        <div class="campo" style="--span:2">
          <label for="cuenta_bancaria">N° de cuenta</label>
          <input id="cuenta_bancaria" name="cuenta_bancaria" value="<?= h($v['cuenta_bancaria'] ?? '') ?>" maxlength="20" class="mono">
          <span class="campo-ayuda">la usan los débitos</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($conFicha): ?>
    <div class="bloque">
      <div class="bloque-titulo">Cartera</div>
      <div class="campos">
        <div class="campo" style="--span:2">
          <label for="fecha_alta">Cliente desde</label>
          <input type="date" id="fecha_alta" name="fecha_alta" value="<?= h($v['fecha_alta'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="campo campo-boton arriba" style="--span:2"><span class="campo-label">&nbsp;</span>
          <label class="campo-check"><input type="checkbox" name="otro_banco" value="1" <?= (int) ($v['otro_banco'] ?? 0) ? 'checked' : '' ?>> Cobra en otro banco</label>
        </div>
        <div class="campo campo-boton arriba" style="--span:2"><span class="campo-label">&nbsp;</span>
          <label class="campo-check"><input type="checkbox" name="venta_cartera" value="1" <?= (int) ($v['venta_cartera'] ?? 0) ? 'checked' : '' ?>> Venta de cartera</label>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <?= card_head('Domicilios', 'el primero es el principal', boton('Agregar domicilio', 'ghost', null, 'agregar', false, true, ['data-agregar' => 'tpl-dom', 'data-destino' => 'doms'])) ?>
    <div class="bloque"><div id="doms"><?php foreach ($doms as $d) { echo renglon_domicilio($d); } ?></div></div>
  </div>

  <div class="card">
    <?php if (!$cliente): ?>
    <?= card_head('Teléfono', 'los demás teléfonos, referidos y familiares se cargan después en Contactos') ?>
    <div class="bloque"><div class="campos">
      <div class="campo" style="--span:4">
        <label for="celular_alta">Celular</label>
        <input id="celular_alta" name="celular_alta" value="<?= h($v['celular_alta'] ?? '') ?>" maxlength="25" inputmode="tel" class="mono" data-tel="movil" placeholder="11 2345-6789">
      </div>
    </div></div>
    <?php endif; ?>
    <div class="form-acciones">
      <?= boton('Volver', 'secondary', 'clientes.php', 'volver') ?>
      <?= boton($cliente ? 'Guardar cambios' : 'Crear cliente', 'primary', null, 'guardar', true) ?>
    </div>
  </div>
</form>

<template id="tpl-dom"><?= renglon_domicilio(['provincia' => 'Buenos Aires']) ?></template>

<?php if ($cliente): ?>
  <?= observaciones_tarjeta($id, 'cliente.php?id=' . $id, $prestamos, $obsForm, $obsErrores) ?>
  <?= contactos_tarjeta('cliente', $id, 'cliente.php?id=' . $id, $contactoForm, (int) get_str('contacto'), $contactoErrores) ?>
  <?= cs_tarjeta_cliente($id, $cliente, $csErrores) ?>
<?php endif; ?>

<?php if ($cliente):
    $hoy  = date('Y-m-d');
    $nOk  = count(array_filter($checklist, function ($c) { return $c['estado'] === 'OK'; }));
    $nota = $checklist ? $nOk . ' de ' . count($checklist) . ' obligatorios al día' : '';
?>
<div class="card" id="documentos">
  <?= card_head('Documentación', $nota,
        ($docBajas ? boton($verBajas ? 'Ocultar dados de baja' : 'Ver dados de baja (' . $docBajas . ')', 'ghost',
                          url_con(['bajas' => $verBajas ? null : '1', 'doc' => null, 'bcra' => null]) . '#documentos', null, false, true) : '')
        . boton('Qué se pide', 'ghost', 'documentos_tipos.php', 'ajustes', false, true)) ?>
  <?php if ($docFlash): ?>
    <div class="bloque"><?php alerta($docFlash[1], $docFlash[0]); ?></div>
  <?php endif; ?>
  <?php if ($docError !== ''): ?>
    <div class="bloque"><?php alerta($docError, 'warning'); ?></div>
  <?php else: ?>
    <?php if ($checklist): ?>
    <div class="bloque">
      <div class="bloque-titulo">Obligatorios</div>
      <div class="doc-checklist">
        <?php foreach ($checklist as $c):
            $t = ['OK' => 'ok', 'VENCIDO' => 'warn', 'FALTA' => 'danger'][$c['estado']];
            $txt = $c['nombre'] . ($c['estado'] === 'VENCIDO' ? ' · venció el ' . fecha($c['ultimo_vencimiento']) : ($c['estado'] === 'FALTA' ? ' · falta' : ''));
        ?>
          <?= estado($txt, $t) ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" action="documento.php?cliente=<?= (int) $id ?>" enctype="multipart/form-data" class="bloque" id="frm-doc">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="subir">
      <div class="bloque-titulo">Subir documentación</div>
      <div class="campos">
        <div class="campo" style="--span:4">
          <label for="id_tipo_doc">Documento <span class="req">*</span></label>
          <select id="id_tipo_doc" name="id_tipo_doc" required>
            <option value="">Elegí qué es…</option>
            <?php foreach ($docTipos as $t): ?>
              <option value="<?= (int) $t['id_tipo_doc'] ?>" data-vigencia="<?= (int) $t['vigencia_dias'] ?>"><?= h($t['nombre']) ?><?= $t['obligatorio'] ? ' *' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo-ayuda">* obligatorio para dar un préstamo</span>
        </div>
        <div class="campo" style="--span:4">
          <label for="archivos">Archivos <span class="req">*</span></label>
          <input type="file" id="archivos" name="archivos[]" multiple required
                 accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
          <span class="campo-ayuda">PDF, JPG, PNG o WEBP · hasta <?= h(bytes_txt(docs_max_bytes())) ?> cada uno · podés elegir varios (frente y dorso)</span>
        </div>
        <div class="campo" style="--span:2">
          <label for="fecha_emision">Emisión</label>
          <input type="date" id="fecha_emision" name="fecha_emision" max="<?= $hoy ?>">
        </div>
        <div class="campo" style="--span:2">
          <label for="fecha_vencimiento">Vencimiento</label>
          <input type="date" id="fecha_vencimiento" name="fecha_vencimiento">
          <span class="campo-ayuda" id="vto-ayuda"></span>
        </div>
        <div class="campo" style="--span:10">
          <label for="observacion">Observación</label>
          <input id="observacion" name="observacion" maxlength="200" placeholder="Período del recibo, quién lo entregó…">
        </div>
        <div class="campo campo-boton" style="--span:2">
          <span class="campo-label">&nbsp;</span>
          <?= boton('Subir', 'primary', null, 'subir', true) ?>
        </div>
      </div>
    </form>

    <?= barra_local('cliente.php?id=' . (int) $id, 'Documento, archivo u observación…') ?>
    <div class="data-table-wrap">
      <table class="data-table" data-local data-paginada data-unidad="documento|documentos">
        <thead>
          <tr>
            <th class="col-acciones" data-no-sort>Acciones</th>
            <th>Documento</th><th>Archivo</th>
            <th>Emisión</th><th>Vencimiento</th>
            <th>Cargado el</th><th data-no-sort>Observación</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$documentos): ?>
          <tr><td colspan="7" class="no-data">Todavía no hay documentación cargada.</td></tr>
        <?php endif; ?>
        <?php foreach ($documentos as $d):
            $baja = $d['baja_el'] !== null;
            $vencido = $d['fecha_vencimiento'] !== null && $d['fecha_vencimiento'] < $hoy;
        ?>
          <tr<?= $baja ? ' class="fila-baja"' : '' ?>>
            <td>
              <div class="acciones">
                <?= btn_accion('ver', 'documento.php?id=' . $d['id_documento'], 'Ver el archivo', true) ?>
                <?= btn_accion('descargar', 'documento.php?id=' . $d['id_documento'] . '&bajar=1', 'Descargar') ?>
                <?php if (!$baja): ?>
                  <form method="post" action="documento.php?cliente=<?= (int) $id ?>" class="form-en-linea"
                        data-confirmar="Se da de baja «<?= h($d['tipo_nombre']) ?>» (<?= h($d['nombre_original']) ?>). El archivo se conserva y se puede reactivar."
                        data-confirmar-titulo="Dar de baja el documento" data-confirmar-boton="Dar de baja" data-peligro>
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="baja">
                    <input type="hidden" name="id_documento" value="<?= (int) $d['id_documento'] ?>">
                    <button type="submit" class="btn-accion borrar" title="Dar de baja" aria-label="Dar de baja"><?= icono('borrar') ?></button>
                  </form>
                <?php else: ?>
                  <form method="post" action="documento.php?cliente=<?= (int) $id ?>" class="form-en-linea">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="reactivar">
                    <input type="hidden" name="id_documento" value="<?= (int) $d['id_documento'] ?>">
                    <button type="submit" class="btn-accion agregar" title="Reactivar" aria-label="Reactivar"><?= icono('volver') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
            <td class="td-fuerte"><?= h($d['tipo_nombre']) ?>
              <?php if ($baja): ?><span class="segunda-linea">Dado de baja el <?= h(fecha($d['baja_el'])) ?></span><?php endif; ?></td>
            <td><a href="documento.php?id=<?= (int) $d['id_documento'] ?>" target="_blank" rel="noopener"><?= h($d['nombre_original']) ?></a>
              <span class="segunda-linea"><?= h(strtoupper((string) array_search($d['mime'], DOC_TIPOS_ARCHIVO, true))) ?> · <?= h(bytes_txt((int) $d['bytes'])) ?></span></td>
            <td data-orden="<?= h((string) $d['fecha_emision']) ?>"><?= h(fecha($d['fecha_emision'])) ?></td>
            <td data-orden="<?= h((string) $d['fecha_vencimiento']) ?>"><?= $d['fecha_vencimiento'] === null ? '<span class="td-muted">—</span>'
                    : ($vencido && !$baja ? estado('Venció el ' . fecha($d['fecha_vencimiento']), 'warn') : h(fecha($d['fecha_vencimiento']))) ?></td>
            <td data-orden="<?= h((string) $d['subido_el']) ?>"><?= h(date('d/m/Y H:i', strtotime($d['subido_el']))) ?></td>
            <td class="td-wrap"><?= h($d['observacion'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion_local() ?>
  <?php endif; ?>
</div>

<div class="card" id="bcra">
  <?php
    $bcraBoton = '<form method="post" action="bcra.php" class="form-en-linea">' . csrf_campo()
        . '<input type="hidden" name="cuit" value="' . h($cliente['cuit']) . '">'
        . '<input type="hidden" name="cliente" value="' . (int) $id . '">'
        . ($bcraFilas ? '<input type="hidden" name="forzar" value="1">' : '')
        . boton($bcraFilas ? 'Actualizar' : 'Consultar BCRA', $bcraFilas ? 'secondary' : 'primary', null, 'filtro', true, true)
        . '</form>';
  ?>
  <?php
    if ($bcraFilas) {
        $bcraBoton = boton('Generar PDF', 'pdf', 'bcra.php?cuit=' . $cliente['cuit'] . '&export=pdf', 'pdf', false, true, ['target' => '_blank', 'rel' => 'noopener'])
            . '<form method="post" action="bcra.php" class="form-en-linea">' . csrf_campo()
            . '<input type="hidden" name="accion" value="legajo"><input type="hidden" name="cliente" value="' . (int) $id . '">'
            . boton('Guardar en documentación', 'ghost', null, 'guardar', true, true, ['title' => 'Guarda el PDF de esta consulta en la documentación del cliente'])
            . '</form>' . $bcraBoton;
    }
  ?>
  <?= card_head('Central de Deudores (BCRA)', $bcraFilas ? '' : 'sin consultar', $bcraError === '' ? $bcraBoton : '') ?>
  <?php if ($bcraFlash): ?>
    <div class="bloque"><?php alerta($bcraFlash[1], $bcraFlash[0]); ?></div>
  <?php endif; ?>
  <div class="bloque bcra">
    <?php if ($bcraError !== ''): ?>
      <?php alerta($bcraError, 'warning'); ?>
    <?php elseif ($bcraFilas): ?>
      <?= bcra_informe_html($bcraFilas) ?>
    <?php else: ?>
      <div class="vacio">Tocá «Consultar BCRA» para traer la deuda actual, el historial de 24 meses y los cheques rechazados de <?= h(cuit_fmt($cliente['cuit'])) ?>.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <?= card_head('Préstamos', '',
        puede('solicitudes.php') ? boton('Nueva solicitud', 'primary', 'solicitud.php?cliente=' . (int) $id, 'agregar', false, true) : '') ?>
  <?= barra_local('cliente.php?id=' . (int) $id, 'Número, tipo o estado…') ?>
  <div class="data-table-wrap">
    <table class="data-table" data-local data-paginada data-unidad="préstamo|préstamos">
      <thead>
        <tr>
          <th class="col-acciones" data-no-sort>Acciones</th>
          <th>N°</th><th>Liquidación</th><th>Tipo de asociado</th>
          <th class="th-right">Capital</th><th class="th-right">Cuotas</th>
          <th class="th-right">Cuota</th><th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$prestamos): ?>
        <tr><td colspan="8" class="no-data">Sin préstamos.</td></tr>
      <?php endif; ?>
      <?php foreach ($prestamos as $p): ?>
        <tr<?= $p['estado'] === 'ANULADO' ? ' class="fila-baja"' : '' ?>>
          <td><div class="acciones"><?= btn_accion('ver', 'prestamo.php?id=' . (int) $p['id_prestamo'], 'Ver el préstamo') ?></div></td>
          <td class="mono td-fuerte"><a href="prestamo.php?id=<?= (int) $p['id_prestamo'] ?>"><?= h($p['nro_prestamo']) ?></a></td>
          <td data-orden="<?= h((string) $p['fecha_liquidacion']) ?>"><?= h(fecha($p['fecha_liquidacion'])) ?></td>
          <td><?= h($p['tipo_asociado']) ?></td>
          <td class="td-right" data-orden="<?= (float) $p['capital'] ?>"><?= money($p['capital']) ?></td>
          <td class="td-right"><?= (int) $p['plazo'] ?></td>
          <td class="td-right" data-orden="<?= (float) $p['valor_cuota'] ?>"><?= money($p['valor_cuota']) ?></td>
          <td><?= estado(ucfirst(strtolower($p['estado'])), ['VIGENTE' => 'info', 'CANCELADO' => 'ok', 'LEGALES' => 'danger', 'ANULADO' => 'neutro'][$p['estado']] ?? 'neutro') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginacion_local() ?>
</div>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('frm-cliente');
  function tipo() { var r = form.querySelector('input[name=tipo]:checked'); return r ? r.value : 'F'; }
  function aplicar() {
    var t = tipo();
    form.querySelectorAll('.solo-F').forEach(function (el) {
      el.classList.toggle('oculto', t !== 'F');
      if (el.tagName === 'FIELDSET') el.disabled = t !== 'F';
    });
    form.querySelectorAll('.solo-J').forEach(function (el) {
      el.classList.toggle('oculto', t !== 'J');
      if (el.tagName === 'FIELDSET') el.disabled = t !== 'J';
    });
  }
  form.querySelectorAll('input[name=tipo]').forEach(function (r) { r.addEventListener('change', aplicar); });
  aplicar();
  // El DNI sale del CUIL (dígitos 3 a 10) si todavía está vacío.
  var cuit = document.getElementById('cuit'), dni = document.getElementById('dni');
  cuit.addEventListener('blur', function () {
    var c = cuit.value.replace(/\D/g, '');
    if (c.length === 11 && tipo() === 'F' && !dni.value) dni.value = String(parseInt(c.substr(2, 8), 10));
  });
  // Documentación: avisa cómo se calcula el vencimiento si se deja vacío.
  var tdoc = document.getElementById('id_tipo_doc'), vto = document.getElementById('fecha_vencimiento'),
      ayuda = document.getElementById('vto-ayuda');
  function ayudaVto() {
    if (!tdoc || !ayuda) return;
    var op = tdoc.options[tdoc.selectedIndex], dias = op ? +op.getAttribute('data-vigencia') : 0;
    ayuda.textContent = dias && !vto.value ? 'vacío: emisión + ' + dias + ' días' : '';
  }
  if (tdoc) { tdoc.addEventListener('change', ayudaVto); vto.addEventListener('input', ayudaVto); }

  // ── Consultar ARCA ────────────────────────────────────────────────────
  // Trae de la constancia de inscripción el nombre o la razón social, el
  // domicilio fiscal y la condición frente al IVA, y completa la ficha
  // (no guarda: se revisa y se guarda como siempre).
  var btnArca = document.getElementById('btn-arca'), bloque = document.getElementById('arca-bloque'),
      lista = document.getElementById('arca-datos'), avisoArca = document.getElementById('arca-aviso'),
      cuando = document.getElementById('arca-cuando');
  function fila(t, v) {
    if (!v) return;
    var dt = document.createElement('dt'), dd = document.createElement('dd');
    dt.textContent = t; dd.textContent = v; lista.appendChild(dt); lista.appendChild(dd);
  }
  function aviso(texto, tipo) {
    avisoArca.innerHTML = '';
    if (!texto) return;
    var d = document.createElement('div'); d.className = 'alert alert-' + (tipo || 'info'); d.style.margin = '0 0 8px';
    var t = document.createElement('div'); t.textContent = texto; d.appendChild(t); avisoArca.appendChild(d);
  }
  function fechaHora(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(s || '');
    return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
  }
  // d: lo que devuelve arca.php, o lo guardado (arca_constancia, otros nombres de campo).
  function pintar(d, guardada) {
    var dom = d.domicilio || (d.dom_direccion ? { direccion: d.dom_direccion, localidad: d.dom_localidad, provincia: d.dom_provincia, cp: d.dom_cp } : null);
    lista.innerHTML = '';
    fila(d.tipo_persona === 'JURIDICA' ? 'Razón social' : 'Apellido y nombre', d.razon_social);
    fila('Estado', d.estado_clave);
    fila('Condición IVA', (d.condicion_iva_txt || '') + (d.categoria_monotributo ? ' · ' + d.categoria_monotributo : ''));
    if (dom && dom.direccion) fila('Domicilio fiscal', [dom.direccion, dom.localidad, dom.provincia, dom.cp ? 'CP ' + dom.cp : ''].filter(Boolean).join(', '));
    var acts = d.actividades || [];
    if (acts.length) fila('Actividad', acts[0].descripcion + (acts.length > 1 ? ' (y ' + (acts.length - 1) + ' más)' : ''));
    var imps = (d.impuestos || []).filter(function (i) { return !i.estado || i.estado === 'AC'; });
    if (imps.length) fila('Impuestos activos', imps.map(function (i) { return i.descripcion; }).join(' · '));
    var errs = d.errores ? (Array.isArray(d.errores) ? d.errores.join(' · ') : d.errores) : '';
    fila('ARCA informa', errs);
    cuando.textContent = guardada
      ? '· consultado el ' + fechaHora(d.consultado_el) + (d.consultado_por_usuario ? ' por ' + d.consultado_por_usuario : '')
      : '· recién consultado';
    bloque.hidden = false;
  }
  function poner(id, valor) {
    var el = document.getElementById(id);
    if (el && valor && el.value !== valor) { el.value = valor; return true; }
    return false;
  }
  function completar(d) {
    var hechos = [];
    var t = d.tipo_persona === 'JURIDICA' ? 'J' : 'F';
    if (tipo() !== t) {
      var r = form.querySelector('input[name=tipo][value=' + t + ']');
      if (r) { r.checked = true; aplicar(); hechos.push('tipo'); }
    }
    if (t === 'F') {
      if (poner('apellido', d.apellido)) hechos.push('apellido');
      if (poner('nombres', d.nombre)) hechos.push('nombres');
      var c = (d.cuit || '').replace(/\D/g, '');
      if (c.length === 11 && dni && !dni.value) dni.value = String(parseInt(c.substr(2, 8), 10));
    } else {
      if (poner('razon_social', d.razon_social)) hechos.push('razón social');
      var act = document.getElementById('actividad');
      if (act && !act.value && d.actividades && d.actividades.length) { act.value = d.actividades[0].descripcion.substr(0, 150); hechos.push('actividad'); }
    }
    if (poner('condicion_iva', d.condicion_iva)) hechos.push('condición IVA');
    var dom = d.domicilio;
    if (dom && dom.direccion) {
      var cont = document.getElementById('doms'), filas = cont.querySelectorAll('.renglon'), destino = null;
      filas.forEach(function (f) { if (!destino && f.querySelector('[name="dom_tipo[]"]').value === 'LEGAL') destino = f; });
      if (!destino) filas.forEach(function (f) { if (!destino && !f.querySelector('[name="dom_calle[]"]').value) destino = f; });
      if (!destino) {
        var tpl = document.getElementById('tpl-dom');
        cont.appendChild(tpl.content.cloneNode(true));
        filas = cont.querySelectorAll('.renglon'); destino = filas[filas.length - 1];
      }
      var set = function (n, v) { var el = destino.querySelector('[name="' + n + '[]"]'); if (el && v !== undefined && v !== null) el.value = v; };
      if (cont.querySelectorAll('.renglon').length > 1) set('dom_tipo', 'LEGAL');
      set('dom_calle', (dom.calle || dom.direccion).substr(0, 120));
      set('dom_numero', (dom.numero || '').substr(0, 10));
      set('dom_piso', (dom.piso_depto || '').substr(0, 20));
      set('dom_localidad', (dom.localidad || '').substr(0, 80));
      set('dom_cp', (dom.cp || '').substr(0, 10));
      if (dom.provincia) {
        var sp = destino.querySelector('[name="dom_provincia[]"]');
        Array.prototype.forEach.call(sp.options, function (o) { if (o.text === dom.provincia) sp.value = o.value || o.text; });
      }
      hechos.push('domicilio fiscal');
    }
    return hechos;
  }
  if (btnArca) {
    btnArca.addEventListener('click', function () {
      var c = cuit.value.replace(/\D/g, '');
      if (c.length !== 11) { aviso('Cargá el CUIT/CUIL completo (11 dígitos) y volvé a tocar ARCA.', 'warning'); bloque.hidden = false; return; }
      var fd = new FormData();
      fd.append('cuit', c);
      fd.append('_csrf', form.querySelector('[name=_csrf]').value);
      btnArca.disabled = true;
      var texto = btnArca.lastChild.textContent; btnArca.lastChild.textContent = 'Consultando…';
      fetch('arca.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'ARCA no respondió (HTTP ' + r.status + ').' }; }); })
        .then(function (j) {
          if (!j.ok) { aviso(j.error || 'No se pudo consultar ARCA.', 'danger'); bloque.hidden = false; return; }
          pintar(j.datos, false);
          var hechos = completar(j.datos);
          aviso((hechos.length ? 'Se completó: ' + hechos.join(', ') + '. Revisá los datos y guardá.' : 'Los datos de la ficha ya coinciden con ARCA.')
                + (j.aviso ? ' ' + j.aviso : ''), hechos.length ? 'info' : 'ok');
        })
        .catch(function () { aviso('No se pudo conectar con el servidor.', 'danger'); bloque.hidden = false; })
        .then(function () { btnArca.disabled = false; btnArca.lastChild.textContent = texto; });
    });
  }
  // El banco sale del CBU (tres primeros números = código BCRA) si no hay uno elegido.
  var cbuIn = document.getElementById('cbu'), selBanco = document.getElementById('id_banco');
  if (cbuIn && selBanco) {
    cbuIn.addEventListener('change', function () {
      var c = cbuIn.value.replace(/\D/g, '');
      if (c.length < 3 || selBanco.value) return;
      Array.prototype.forEach.call(selBanco.options, function (o) {
        if (o.getAttribute('data-codigo') === c.substr(0, 3)) selBanco.value = o.value;
      });
    });
  }
  var g = bloque && bloque.getAttribute('data-guardada');
  if (g) { try { pintar(JSON.parse(g), true); } catch (e) {} }
})();
</script>

<?php layout_fin(); ?>
