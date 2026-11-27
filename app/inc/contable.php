<?php
declare(strict_types=1);
// ---------------------------------------------------------------------
// Contabilidad: las reglas del plan de cuentas, ejercicios y períodos de
// gestion_adm (contable.py), en PHP. sql/financiera_contabilidad.sql.
//
//   Máscara   CONTABLE_MASCARA en parametros: '9' = dígito, cualquier otro
//             carácter = separador (9.9.99.999). Vacía hasta la primera
//             cuenta: se toma de ese código, como al importar en gestion_adm.
//   Imputable el ÚLTIMO tramo del código distinto de cero (_es_imputable de
//             gestion_adm: las agrupadoras lo tienen en cero).
//   Padre     la agrupadora existente más cercana: se van poniendo en cero
//             los tramos de la derecha (4.1.0.01.050 → 4.1.0.01.000 →
//             4.1.0.00.000…) hasta dar con una.
//   Nivel     el del padre + 1 (1 sin padre). gestion_adm cuenta los tramos
//             distintos de cero desde la izquierda, y con un cero en el
//             medio daba un nivel de menos; el árbol no se equivoca.
//   Clasif.   el primer dígito (c_clasificacion_plan_cuentas).
//   Corriente nivel 2: por el nombre (ACTIVO / PASIVO CORRIENTE = 1,
//             NO CORRIENTE = 0); de ahí para abajo, el de su cuenta de
//             nivel 2 (_calcular_corriente).
// ---------------------------------------------------------------------

const CTB_ESTADOS = ['abierto' => 'Abierto', 'cerrado' => 'Cerrado', 'bloqueado' => 'Bloqueado'];
const CTB_ESTADOS_TIPO = ['abierto' => 'ok', 'cerrado' => 'neutro', 'bloqueado' => 'danger'];
const CTB_TIPOS = ['activo' => 'Activo', 'pasivo' => 'Pasivo', 'patrimonio' => 'Patrimonio neto',
                   'ingreso' => 'Ingresos', 'egreso' => 'Egresos'];
const CTB_MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                   'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

/** true si ya se corrió sql/financiera_contabilidad.sql. */
function ctb_instalado(): bool
{
    static $si = null;
    if ($si === null) {
        try {
            q('SELECT 1 FROM c_plan_de_cuentas LIMIT 1');
            $si = true;
        } catch (Throwable $e) {
            $si = false;
        }
    }
    return $si;
}

/** El aviso de las pantallas cuando falta el script. */
function ctb_falta_script(Throwable $e): string
{
    return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146
        ? 'Falta crear las tablas de contabilidad: corré sql\financiera_contabilidad.sql.' : error_amigable($e);
}

// ---------------------------------------------------------------------
// Máscara
// ---------------------------------------------------------------------

function ctb_mascara(): string
{
    $r = q1("SELECT valor FROM parametros WHERE clave = 'CONTABLE_MASCARA'");
    return trim((string) ($r['valor'] ?? ''));
}

function ctb_guardar_mascara(string $m): void
{
    q("INSERT INTO parametros (clave, valor, descripcion) VALUES ('CONTABLE_MASCARA', ?, ?)
       ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
      [$m, 'Máscara del código de cuenta contable: 9 = dígito, lo demás separador (ej. 9.9.99.999)']);
}

/** La máscara que corresponde a un código: cada dígito un 9 (_detectar_mascara). */
function ctb_mascara_de(string $codigo): string
{
    return (string) preg_replace('/\d/', '9', $codigo);
}

/** Una máscara aceptable: empieza con 9, un solo tipo de separador, sin tramos vacíos. */
function ctb_mascara_valida(string $m): bool
{
    if (!preg_match('/^9[9\W_]*9$/u', $m) && $m !== '9') {
        return false;
    }
    $seps = array_unique(str_split((string) preg_replace('/9/', '', $m)));
    if (count($seps) !== 1) {
        return false;
    }
    return !in_array('', explode($seps[0], $m), true);
}

function ctb_separador(string $mascara): string
{
    return preg_match('/[^9]/', $mascara, $m) ? $m[0] : '.';
}

/** '' si el código respeta la máscara; si no, el error (_validar_mascara). */
function ctb_error_mascara(string $codigo, string $mascara): string
{
    if ($mascara === '') {
        return '';
    }
    $re = '';
    foreach (str_split($mascara) as $ch) {
        $re .= $ch === '9' ? '\d' : preg_quote($ch, '/');
    }
    return preg_match('/^' . $re . '$/', $codigo) ? '' : 'El código «' . $codigo . '» no respeta el formato ' . $mascara . '.';
}

/** El código de ejemplo de una máscara para el placeholder: 9.9.99.999 → 1.1.01.001. */
function ctb_ejemplo(string $mascara): string
{
    if ($mascara === '') {
        return '1.1.01.001';
    }
    $sep = ctb_separador($mascara);
    return implode($sep, array_map(fn ($t) => str_pad('1', strlen($t), '0', STR_PAD_LEFT), explode($sep, $mascara)));
}

// ---------------------------------------------------------------------
// Reglas de la cuenta
// ---------------------------------------------------------------------

/** Imputable: el último tramo distinto de cero. */
function ctb_es_imputable(string $codigo, string $mascara): bool
{
    $t = explode(ctb_separador($mascara), $codigo);
    $ultimo = end($t);
    return ctype_digit($ultimo) && (int) $ultimo !== 0;
}

/**
 * Los códigos de los posibles padres, del más cercano al más lejano: se
 * van poniendo en cero los tramos de la derecha que no lo están.
 * 1.1.01.001 → [1.1.01.000, 1.1.00.000, 1.0.00.000]
 */
function ctb_codigos_padre(string $codigo, string $mascara): array
{
    $sep = ctb_separador($mascara);
    $t = explode($sep, $codigo);
    $out = [];
    for ($i = count($t) - 1; $i >= 1; $i--) {
        if ((int) $t[$i] === 0) {
            continue;
        }
        $t[$i] = str_repeat('0', strlen($t[$i]));
        $out[] = implode($sep, $t);
    }
    return $out;
}

/**
 * La agrupadora que corresponde a un código: la del primer tramo de la
 * derecha puesto en cero (1.1.01.001 → 1.1.01.000). Tiene que existir: no
 * se salta a una de más arriba, porque la cuenta quedaría colgada de un
 * nivel que no es el suyo. [padre|null, código esperado|null]; los dos
 * null: es de primer nivel.
 */
function ctb_padre_de(string $codigo, string $mascara, ?int $excluir = null): array
{
    $c = ctb_codigos_padre($codigo, $mascara)[0] ?? null;
    if ($c === null) {
        return [null, null];
    }
    $p = q1('SELECT id_cuenta, codigo, nombre, nivel, permite_movimiento, corriente FROM c_plan_de_cuentas WHERE codigo = ?', [$c]);
    return [$p && (int) $p['id_cuenta'] !== $excluir ? $p : null, $c];
}

/** Corriente por el nombre en nivel 2; heredado de la de nivel 2 más abajo. */
function ctb_corriente(string $nombre, int $nivel, ?int $idPadre): int
{
    $n = mb_strtoupper(trim($nombre));
    if ($nivel <= 1) {
        return 0;
    }
    if ($nivel === 2) {
        return in_array($n, ['ACTIVO CORRIENTE', 'PASIVO CORRIENTE'], true) ? 1 : 0;
    }
    for ($id = $idPadre, $i = 0; $id && $i < 12; $i++) {
        $p = q1('SELECT nivel, corriente, id_cuenta_padre FROM c_plan_de_cuentas WHERE id_cuenta = ?', [$id]);
        if (!$p) {
            break;
        }
        if ((int) $p['nivel'] === 2) {
            return (int) $p['corriente'];
        }
        $id = $p['id_cuenta_padre'] !== null ? (int) $p['id_cuenta_padre'] : null;
    }
    return 0;
}

function ctb_clasificaciones(): array
{
    $out = [];
    foreach (q('SELECT * FROM c_clasificacion_plan_cuentas ORDER BY codigo') as $c) {
        $out[(int) $c['codigo']] = $c;
    }
    return $out;
}

/**
 * Valida y arma una cuenta para grabar. $datos: codigo, nombre, activa,
 * es_monetario, naturaleza (solo para un primer dígito sin clasificación).
 * Devuelve [fila lista para grabar, errores]. Si la máscara está vacía, la
 * toma del código (no la graba: eso lo hace ctb_grabar_cuenta).
 */
function ctb_armar_cuenta(array $datos, ?int $id = null): array
{
    $err = [];
    $mascara = ctb_mascara();
    $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
    $nombre = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['nombre'] ?? ''))));
    if ($codigo === '') {
        $err[] = 'Falta el código.';
    }
    if ($nombre === '') {
        $err[] = 'Falta el nombre.';
    } elseif (mb_strlen($nombre) > 150) {
        $err[] = 'El nombre va hasta 150 caracteres.';
    }
    if ($codigo !== '' && $mascara === '') {
        $mascara = ctb_mascara_de($codigo);
        if (!ctb_mascara_valida($mascara)) {
            $err[] = 'El código tiene que ser de dígitos separados por un mismo carácter (1.1.01.001).';
        }
    } elseif ($codigo !== '' && ($e = ctb_error_mascara($codigo, $mascara)) !== '') {
        $err[] = $e;
    }
    $fila = ['codigo' => $codigo, 'nombre' => $nombre, 'mascara' => $mascara,
             'activa' => !empty($datos['activa']) ? 1 : 0, 'es_monetario' => !empty($datos['es_monetario']) ? 1 : 0];
    if ($err) {
        return [$fila, $err];
    }
    if (!ctype_digit($codigo[0]) || (int) $codigo[0] === 0) {
        return [$fila, ['El código tiene que empezar con el dígito de su clasificación (1 a 9).']];
    }
    if (q1('SELECT 1 AS x FROM c_plan_de_cuentas WHERE codigo = ?' . ($id ? ' AND id_cuenta <> ' . (int) $id : ''), [$codigo])) {
        return [$fila, ['Ya hay una cuenta con el código ' . $codigo . '.']];
    }
    [$padre, $esperado] = ctb_padre_de($codigo, $mascara, $id);
    $clasif = (int) $codigo[0];
    $existeClasif = (bool) q1('SELECT 1 AS x FROM c_clasificacion_plan_cuentas WHERE codigo = ?', [$clasif]);
    if (!$padre && $esperado !== null) {
        return [$fila, ['No existe la cuenta agrupadora ' . $esperado . ': cargala primero.']];
    }
    if (!$padre) {
        // Primer nivel: todo en cero después del primer tramo.
        if (!$existeClasif) {
            $nat = (string) ($datos['naturaleza'] ?? '');
            if (!in_array($nat, ['deudora', 'acreedora'], true)) {
                return [$fila, ['El dígito ' . $clasif . ' no tiene clasificación todavía: elegí si la cuenta es deudora o acreedora.']];
            }
            $fila['nueva_clasificacion'] = ['codigo' => $clasif, 'clasificacion' => mb_substr($nombre, 0, 25), 'naturaleza' => $nat,
                                            'tipo' => ['1' => 'activo', '2' => 'pasivo', '3' => 'patrimonio', '4' => 'ingreso', '5' => 'egreso'][(string) $clasif]
                                                      ?? ($nat === 'deudora' ? 'egreso' : 'ingreso')];
        }
    } elseif ((int) $padre['permite_movimiento']) {
        return [$fila, ['La cuenta ' . $padre['codigo'] . ' ' . $padre['nombre'] . ' es imputable y no puede tener cuentas abajo. '
                       . 'Una agrupadora termina en cero.']];
    } elseif (!$existeClasif) {
        return [$fila, ['El dígito ' . $clasif . ' no tiene clasificación.']];
    }
    $nivel = $padre ? (int) $padre['nivel'] + 1 : 1;
    if ($nivel > 12) {
        return [$fila, ['Demasiados niveles.']];
    }
    $fila += ['id_clasificacion' => $clasif, 'id_cuenta_padre' => $padre ? (int) $padre['id_cuenta'] : null, 'nivel' => $nivel,
              'permite_movimiento' => ctb_es_imputable($codigo, $mascara) ? 1 : 0,
              'corriente' => ctb_corriente($nombre, $nivel, $padre ? (int) $padre['id_cuenta'] : null), 'padre' => $padre];
    if ($nivel === 1 && $fila['permite_movimiento']) {
        $fila['permite_movimiento'] = 0;          // el rubro de primer nivel siempre agrupa
    }
    return [$fila, []];
}

/** Graba la cuenta armada por ctb_armar_cuenta (alta o edición). Devuelve el id. */
function ctb_grabar_cuenta(array $f, ?int $id = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (ctb_mascara() === '') {
            ctb_guardar_mascara($f['mascara']);
        }
        if (!empty($f['nueva_clasificacion'])) {
            $c = $f['nueva_clasificacion'];
            q('INSERT IGNORE INTO c_clasificacion_plan_cuentas (codigo, clasificacion, naturaleza, tipo) VALUES (?,?,?,?)',
              [$c['codigo'], $c['clasificacion'], $c['naturaleza'], $c['tipo']]);
        }
        $vals = [$f['id_clasificacion'], $f['codigo'], $f['nombre'], $f['id_cuenta_padre'], $f['nivel'],
                 $f['permite_movimiento'], $f['activa'], $f['es_monetario'], $f['corriente']];
        if ($id) {
            q('UPDATE c_plan_de_cuentas SET id_clasificacion=?, codigo=?, nombre=?, id_cuenta_padre=?, nivel=?,
                      permite_movimiento=?, activa=?, es_monetario=?, corriente=? WHERE id_cuenta=?', array_merge($vals, [$id]));
            // Una de nivel 2 que cambió de nombre arrastra el «corriente» de las de abajo.
            if ((int) $f['nivel'] === 2) {
                ctb_propagar_corriente($id, (int) $f['corriente']);
            }
        } else {
            q('INSERT INTO c_plan_de_cuentas (id_clasificacion, codigo, nombre, id_cuenta_padre, nivel,
                      permite_movimiento, activa, es_monetario, corriente, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?)',
              array_merge($vals, [usuario_id()]));
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ctb_propagar_corriente(int $id, int $corriente): void
{
    $hijas = array_map('intval', array_column(q('SELECT id_cuenta FROM c_plan_de_cuentas WHERE id_cuenta_padre = ?', [$id]), 'id_cuenta'));
    for ($i = 0; $hijas && $i < 12; $i++) {
        $in = implode(',', $hijas);
        q("UPDATE c_plan_de_cuentas SET corriente = ? WHERE id_cuenta IN ($in)", [$corriente]);
        $hijas = array_map('intval', array_column(q("SELECT id_cuenta FROM c_plan_de_cuentas WHERE id_cuenta_padre IN ($in)"), 'id_cuenta'));
    }
}

/** Renglones de asientos que usan la cuenta (cualquier estado). */
function ctb_movimientos_cuenta(int $id): int
{
    return (int) q1('SELECT COUNT(*) AS n FROM c_asiento_detalle WHERE id_cuenta = ?', [$id])['n'];
}

/** El plan entero en el orden del código, con su clasificación y cuántas hijas y movimientos tiene. */
function ctb_plan(): array
{
    return q('SELECT pc.*, cl.clasificacion, cl.naturaleza, cl.tipo,
                     (SELECT COUNT(*) FROM c_plan_de_cuentas h WHERE h.id_cuenta_padre = pc.id_cuenta) AS hijas,
                     (SELECT COUNT(*) FROM c_asiento_detalle d WHERE d.id_cuenta = pc.id_cuenta) AS movimientos
                FROM c_plan_de_cuentas pc
                JOIN c_clasificacion_plan_cuentas cl ON cl.codigo = pc.id_clasificacion
               ORDER BY pc.codigo');
}

/** Las cuentas imputables activas, para elegir una (los maestros de las próximas etapas). */
function ctb_cuentas_imputables(): array
{
    return q('SELECT id_cuenta, codigo, nombre FROM c_plan_de_cuentas WHERE permite_movimiento = 1 AND activa = 1 ORDER BY codigo');
}

// ---------------------------------------------------------------------
// Importación (CSV: código; nombre; naturaleza en las de primer nivel)
// ---------------------------------------------------------------------

/**
 * Lee el CSV del plan. Separador ; , o tabulación; la primera fila puede
 * ser el encabezado (codigo / nombre / naturaleza, en cualquier orden).
 * Devuelve [filas [codigo, nombre, naturaleza, renglon], errores].
 */
function ctb_leer_csv(string $raw): array
{
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $lineas = preg_split('/\r\n|\r|\n/', $raw);
    $primera = '';
    foreach ($lineas as $l) {
        if (trim($l) !== '') {
            $primera = $l;
            break;
        }
    }
    $sep = ';';
    foreach (["\t", ';', ','] as $s) {
        if (substr_count($primera, $s) >= 1) {
            $sep = $s;
            break;
        }
    }
    $col = ['codigo' => 0, 'nombre' => 1, 'naturaleza' => 2];
    $filas = [];
    $err = [];
    foreach ($lineas as $n => $l) {
        if (trim($l) === '') {
            continue;
        }
        $c = array_map('trim', str_getcsv($l, $sep));
        $bajo = array_map(fn ($x) => mb_strtolower($x), $c);
        if (!$filas && !ctype_digit(substr($c[0] ?? '', 0, 1))) {
            // Encabezado: ubica las columnas por nombre.
            foreach ($bajo as $i => $t) {
                if (in_array($t, ['codigo', 'código', 'cuenta', 'cod'], true)) {
                    $col['codigo'] = $i;
                } elseif (in_array($t, ['nombre', 'detalle', 'descripcion', 'descripción', 'denominacion', 'denominación'], true)) {
                    $col['nombre'] = $i;
                } elseif ($t === 'naturaleza') {
                    $col['naturaleza'] = $i;
                }
            }
            continue;
        }
        $cod = strtoupper($c[$col['codigo']] ?? '');
        $nom = $c[$col['nombre']] ?? '';
        if ($cod === '' && $nom === '') {
            continue;
        }
        if ($cod === '' || $nom === '') {
            $err[] = 'Renglón ' . ($n + 1) . ': falta el código o el nombre.';
            continue;
        }
        $nat = mb_strtolower($c[$col['naturaleza']] ?? '');
        $filas[] = [$cod, $nom, in_array($nat, ['deudora', 'acreedora'], true) ? $nat : '', $n + 1];
    }
    return [$filas, $err];
}

/**
 * Importa las filas: en el orden del código (las agrupadoras primero), las
 * que ya existen se saltean. Con el plan vacío, la máscara sale del primer
 * código. Devuelve [importadas, salteadas, errores].
 */
function ctb_importar(array $filas): array
{
    usort($filas, fn ($a, $b) => strcmp($a[0], $b[0]));
    $imp = 0;
    $salt = 0;
    $err = [];
    foreach ($filas as [$cod, $nom, $nat, $renglon]) {
        if (q1('SELECT 1 AS x FROM c_plan_de_cuentas WHERE codigo = ?', [$cod])) {
            $salt++;
            continue;
        }
        [$f, $e] = ctb_armar_cuenta(['codigo' => $cod, 'nombre' => $nom, 'naturaleza' => $nat, 'activa' => 1, 'es_monetario' => 0]);
        if ($e) {
            $err[] = 'Renglón ' . $renglon . ' (' . $cod . '): ' . $e[0];
            continue;
        }
        ctb_grabar_cuenta($f);
        $imp++;
    }
    return [$imp, $salt, $err];
}

/** El CSV de ejemplo (el plan de ejemplo de gestion_adm, con la máscara 9.99.999.9999). */
function ctb_csv_ejemplo(): string
{
    $filas = [
        ['1.00.000.0000', 'ACTIVO', 'deudora'], ['1.01.000.0000', 'ACTIVO CORRIENTE', ''],
        ['1.01.001.0000', 'DISPONIBILIDADES', ''], ['1.01.001.0001', 'Caja', ''], ['1.01.001.0002', 'Banco cuenta corriente', ''],
        ['1.01.002.0000', 'CREDITOS POR PRESTAMOS', ''], ['1.01.002.0001', 'Deudores por préstamos', ''],
        ['1.01.002.0002', 'Intereses a cobrar', ''], ['1.02.000.0000', 'ACTIVO NO CORRIENTE', ''],
        ['1.02.001.0000', 'BIENES DE USO', ''], ['1.02.001.0001', 'Muebles y útiles', ''],
        ['2.00.000.0000', 'PASIVO', 'acreedora'], ['2.01.000.0000', 'PASIVO CORRIENTE', ''],
        ['2.01.001.0000', 'DEUDAS FINANCIERAS', ''], ['2.01.001.0001', 'Aportes de inversores', ''],
        ['2.01.002.0000', 'DEUDAS FISCALES', ''], ['2.01.002.0001', 'IVA débito fiscal', ''],
        ['3.00.000.0000', 'PATRIMONIO NETO', 'acreedora'], ['3.01.000.0000', 'CAPITAL', ''], ['3.01.001.0000', 'CAPITAL SOCIAL', ''],
        ['3.01.001.0001', 'Capital suscripto', ''],
        ['4.00.000.0000', 'INGRESOS', 'acreedora'], ['4.01.000.0000', 'INGRESOS FINANCIEROS', ''],
        ['4.01.001.0000', 'INTERESES GANADOS', ''], ['4.01.001.0001', 'Intereses por préstamos', ''],
        ['4.01.001.0002', 'Punitorios', ''], ['4.01.002.0000', 'OTROS INGRESOS', ''], ['4.01.002.0001', 'Gastos de otorgamiento', ''],
        ['5.00.000.0000', 'EGRESOS', 'deudora'], ['5.01.000.0000', 'GASTOS', ''], ['5.01.001.0000', 'GASTOS DE COMERCIALIZACION', ''],
        ['5.01.001.0001', 'Comisiones a comercializadores', ''], ['5.01.002.0000', 'GASTOS FINANCIEROS', ''],
        ['5.01.002.0001', 'Intereses a inversores', ''], ['5.01.002.0002', 'Gastos bancarios', ''],
    ];
    $out = "codigo;nombre;naturaleza\r\n";
    foreach ($filas as $f) {
        $out .= implode(';', $f) . "\r\n";
    }
    return "\xEF\xBB\xBF" . $out;
}

// ---------------------------------------------------------------------
// Ejercicios y períodos
// ---------------------------------------------------------------------

/** El ejercicio de trabajo (activo), o null. */
function ctb_ejercicio_activo(): ?array
{
    return q1('SELECT * FROM c_ejercicios WHERE activo = 1 ORDER BY fecha_inicio DESC LIMIT 1');
}

/** Los meses entre dos fechas, como períodos [nombre, inicio, fin] (sp_abrir_ejercicio). */
function ctb_meses(string $desde, string $hasta): array
{
    $out = [];
    $ini = new DateTimeImmutable(substr($desde, 0, 7) . '-01');
    $fin = new DateTimeImmutable($hasta);
    $d0 = new DateTimeImmutable($desde);
    for ($i = 0; $ini <= $fin && $i < 60; $i++, $ini = $ini->modify('first day of next month')) {
        $a = max($ini, $d0);
        $b = min($ini->modify('last day of this month'), $fin);
        $out[] = [CTB_MESES[(int) $ini->format('n')] . ' ' . $ini->format('Y'), $a->format('Y-m-d'), $b->format('Y-m-d')];
    }
    return $out;
}

/** El ejercicio que se cruza con las fechas (otro que $excluir), o null. */
function ctb_ejercicio_superpuesto(string $desde, string $hasta, ?int $excluir = null): ?array
{
    return q1('SELECT id_ejercicio, nombre FROM c_ejercicios WHERE fecha_inicio <= ? AND fecha_fin >= ?'
              . ($excluir ? ' AND id_ejercicio <> ' . (int) $excluir : '') . ' LIMIT 1', [$hasta, $desde]);
}

/** Asientos del ejercicio (cualquier estado). */
function ctb_asientos_ejercicio(int $id): int
{
    return (int) q1('SELECT COUNT(*) AS n FROM c_asientos a JOIN c_periodos p ON p.id_periodo = a.id_periodo
                      WHERE p.id_ejercicio = ?', [$id])['n'];
}

/** El código que sigue a las hijas de una agrupadora (o la primera, si no tiene). */
function ctb_codigo_siguiente(array $padre, string $mascara): string
{
    $sep = ctb_separador($mascara);
    $tp = explode($sep, $padre['codigo']);
    $hijas = array_column(q('SELECT codigo FROM c_plan_de_cuentas WHERE id_cuenta_padre = ? ORDER BY codigo DESC', [$padre['id_cuenta']]), 'codigo');
    // El tramo que cambia: el primero en cero después del último distinto de cero del padre.
    $ult = 0;
    foreach ($tp as $i => $t) {
        if ((int) $t !== 0) {
            $ult = $i;
        }
    }
    $pos = $ult + 1;
    if ($hijas) {
        $th = explode($sep, $hijas[0]);
        foreach ($th as $i => $t) {
            if (($tp[$i] ?? '') !== $t) {
                $pos = $i;
                break;
            }
        }
        $tp = $th;
    }
    if (!isset($tp[$pos])) {
        return '';
    }
    $n = (int) $tp[$pos] + 1;
    if (strlen((string) $n) > strlen($tp[$pos])) {
        return '';
    }
    $tp[$pos] = str_pad((string) $n, strlen($tp[$pos]), '0', STR_PAD_LEFT);
    for ($i = $pos + 1; $i < count($tp); $i++) {
        $tp[$i] = str_repeat('0', strlen($tp[$i]));
    }
    return implode($sep, $tp);
}

// ---------------------------------------------------------------------
// Imputaciones (sql/financiera_contabilidad_imputaciones.sql)
// ---------------------------------------------------------------------

/**
 * Las clases de operación que no tienen la cuenta en otro maestro, como
 * pago_imputaciones_config de gestion_adm. Por cada una: el nombre, qué
 * lleva en el debe y en el haber (null: ese lado sale de otro lado) y qué
 * es el subtipo ('tipo' = tipo de asociado, 'moneda', null = no tiene).
 * La cuenta de fondos sale de cuentas_fondos; la del gasto cobrado, de
 * gastos_conceptos.
 */
const CTB_CLASES = [
    'LIQUIDACION'        => ['Liquidación de préstamos', 'Deudores por préstamos: el capital prestado',
                             'Préstamos a desembolsar: el neto, hasta que se paga la orden', 'tipo'],
    'INTERES'            => ['Intereses de préstamos', null, 'Intereses ganados (lo que cobra cada cuota)', 'tipo'],
    'PUNITORIO'          => ['Punitorios', null, 'Punitorios ganados', 'tipo'],
    'IVA_DEBITO'         => ['IVA débito fiscal', null, 'IVA de intereses, punitorios y gastos gravados', null],
    'QUITA'              => ['Quitas por cancelación anticipada', 'Quitas y bonificaciones de intereses', null, 'tipo'],
    'CUOTA_SOCIAL'       => ['Cuota social', null, 'Ingreso por cuota social', null],
    'COMISION'           => ['Comisiones de comercializadores', 'Comisiones (egreso)', 'Comisiones a pagar', null],
    'APORTE'             => ['Aportes de inversores', null, 'Deuda con el inversor por el capital aportado', 'moneda'],
    'INTERES_INVERSOR'   => ['Intereses a inversores', 'Intereses pagados (egreso)', 'Intereses a pagar al inversor', 'moneda'],
    'COBRANZA_A_IMPUTAR' => ['Cobranzas a imputar', null, 'Lo que entra al banco sin cliente identificado', null],
];

/** true si están las columnas de cuenta contable en los maestros y la tabla de imputaciones. */
function ctb_imputaciones_instaladas(): bool
{
    static $si = null;
    if ($si === null) {
        try {
            q('SELECT 1 FROM c_imputaciones_config LIMIT 1');
            $si = columna_existe('cuentas_fondos', 'id_cuenta_contable') && columna_existe('gastos_conceptos', 'id_cuenta_contable');
        } catch (Throwable $e) {
            $si = false;
        }
    }
    return $si;
}

/**
 * El combo de una cuenta imputable. $cuentas: las de ctb_cuentas_imputables()
 * (se pasan para no leerlas en cada combo). La elegida se muestra aunque
 * esté inactiva, para no perderla al guardar.
 */
function ctb_select_cuenta(string $name, array $cuentas, $elegida, string $vacio = 'Sin cuenta', array $attrs = []): string
{
    $a = '';
    foreach ($attrs + ['id' => $name] as $k => $v) {
        $a .= ' ' . h($k) . '="' . h((string) $v) . '"';
    }
    $out = '<select name="' . h($name) . '"' . $a . '><option value="">' . h($vacio) . '</option>';
    $esta = false;
    foreach ($cuentas as $c) {
        $sel = (string) $c['id_cuenta'] === (string) $elegida;
        $esta = $esta || $sel;
        $out .= '<option value="' . (int) $c['id_cuenta'] . '"' . ($sel ? ' selected' : '') . '>' . h($c['codigo'] . ' ' . $c['nombre']) . '</option>';
    }
    if (!$esta && $elegida) {
        $c = q1('SELECT id_cuenta, codigo, nombre FROM c_plan_de_cuentas WHERE id_cuenta = ?', [(int) $elegida]);
        if ($c) {
            $out .= '<option value="' . (int) $c['id_cuenta'] . '" selected>' . h($c['codigo'] . ' ' . $c['nombre'] . ' (inactiva)') . '</option>';
        }
    }
    return $out . '</select>';
}

/** null si la cuenta se puede imputar; si no, el motivo. */
function ctb_error_cuenta_imputable($id): ?string
{
    if ($id === null || $id === '' || (int) $id === 0) {
        return null;
    }
    $c = q1('SELECT codigo, nombre, permite_movimiento, activa FROM c_plan_de_cuentas WHERE id_cuenta = ?', [(int) $id]);
    if (!$c) {
        return 'La cuenta contable elegida no existe.';
    }
    if (!(int) $c['permite_movimiento']) {
        return $c['codigo'] . ' ' . $c['nombre'] . ' es agrupadora: elegí una imputable.';
    }
    return null;
}

/** «1.1.01.001 CAJA EN PESOS» de una cuenta, o ''. */
function ctb_cuenta_txt($id): string
{
    if (!$id) {
        return '';
    }
    static $cache = [];
    if (!array_key_exists((int) $id, $cache)) {
        $c = q1('SELECT codigo, nombre FROM c_plan_de_cuentas WHERE id_cuenta = ?', [(int) $id]);
        $cache[(int) $id] = $c ? $c['codigo'] . ' ' . $c['nombre'] : '';
    }
    return $cache[(int) $id];
}

/** Dónde se usa una cuenta fuera de los asientos (maestros e imputaciones): lista de textos. */
function ctb_usos_cuenta(int $id): array
{
    $u = [];
    $consultas = [
        'cuentas de fondos'    => "SELECT nombre FROM cuentas_fondos WHERE id_cuenta_contable = ?",
        'conceptos de gasto'   => "SELECT nombre FROM gastos_conceptos WHERE id_cuenta_contable = ?",
        'conceptos de tesorería' => "SELECT nombre FROM conceptos_tesoreria WHERE id_cuenta_contable = ?",
        'imputaciones'         => "SELECT nombre FROM c_imputaciones_config WHERE id_cuenta_debe = ? OR id_cuenta_haber = ?",
    ];
    foreach ($consultas as $que => $sql) {
        try {
            $f = q($sql, substr_count($sql, '?') === 2 ? [$id, $id] : [$id]);
        } catch (Throwable $e) {
            continue;                                     // la tabla o la columna todavía no está
        }
        if ($f) {
            $u[] = $que . ' (' . implode(', ', array_slice(array_column($f, 'nombre'), 0, 3)) . (count($f) > 3 ? '…' : '') . ')';
        }
    }
    return $u;
}

/**
 * La imputación de una clase: la del subtipo si hay, si no la general.
 * Devuelve la fila de c_imputaciones_config o null. La usa el generador
 * de asientos.
 */
function ctb_imputacion(string $clase, string $subtipo = ''): ?array
{
    return q1("SELECT * FROM c_imputaciones_config WHERE clase = ? AND activo = 1 AND subtipo IN (?, '')
                ORDER BY subtipo = '' LIMIT 1", [$clase, $subtipo]);
}
