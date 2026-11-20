<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Mensajes internos: conversaciones directas, grupos y el hilo de cada
// solicitud (sql\financiera_chat.sql).
//
//   Quién usa qué
//     - Directas y grupos: quien tiene la opción «Mensajes» (chat.php).
//       Todos le pueden escribir a todos los usuarios activos.
//     - Hilo de una solicitud: quien puede ver la solicitud (sol_cargar:
//       los vendedores, solo las suyas). No hace falta «Mensajes».
//
//   No leídos: participante.ultimo_leido contra
//   conversación.ultimo_mensaje_id (ver el .sql).
//
//   Llegada de mensajes: la pantalla consulta chat_api.php cada pocos
//   segundos (con la conversación abierta) y el globito flotante cada 20 (el
//   contador). Esas consultas no cuentan como actividad: no impiden que
//   la sesión se cierre por inactividad (FIN_SIN_ACTIVIDAD en seguridad.php).
// ---------------------------------------------------------------------

require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/solicitudes.php';

const CHAT_TEXTO_MAX     = 4000;    // caracteres por mensaje
const CHAT_GRUPO_NOMBRE  = 80;
const CHAT_PAGINA        = 50;      // mensajes por tanda al abrir o al ir hacia atrás
const CHAT_LISTA_MAX     = 200;     // conversaciones en la lista

/**
 * Archivos que se pueden mandar: extensión => [mime, se muestra en el navegador].
 * PDF e imágenes se reconocen por el contenido (doc_detectar_tipo). Office se
 * reconoce por el contenedor (ZIP para los nuevos, OLE para .doc / .xls) y la
 * extensión; texto, por la extensión y por no tener bytes binarios. Office y
 * texto siempre se descargan, nunca se abren dentro de la aplicación.
 */
const CHAT_TIPOS_ARCHIVO = [
    'pdf'  => ['application/pdf', true],
    'jpg'  => ['image/jpeg', true],
    'png'  => ['image/png', true],
    'webp' => ['image/webp', true],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
    'xls'  => ['application/vnd.ms-excel', false],
    'doc'  => ['application/msword', false],
    'csv'  => ['text/csv', false],
    'txt'  => ['text/plain', false],
];

/** ¿Usa directas y grupos? */
function chat_habilitado(): bool
{
    return puede('chat.php');
}

/** ¿Abre la ficha de las solicitudes? (las mismas opciones que solicitud.php en PAGINAS_PERMISO) */
function chat_ve_solicitudes(): bool
{
    return puede('solicitudes.php') || puede('autorizaciones.php');
}

/** Error que la API devuelve con su código HTTP y el texto para la persona. */
class ChatError extends RuntimeException
{
    public int $http;

    public function __construct(string $mensaje, int $http = 400)
    {
        parent::__construct($mensaje);
        $this->http = $http;
    }
}

// ---------------------------------------------------------------------
// Conversaciones
// ---------------------------------------------------------------------

/**
 * La conversación si el usuario actual puede verla, o null. Agrega
 * 'participante' (su fila en chat_participantes o null) y, para el hilo de
 * una solicitud, 'solicitud' (lo que devuelve sol_cargar).
 */
function chat_conversacion(int $id): ?array
{
    $c = q1('SELECT * FROM chat_conversaciones WHERE id_conversacion = ?', [$id]);
    if (!$c) {
        return null;
    }
    $c['participante'] = q1('SELECT * FROM chat_participantes WHERE id_conversacion = ? AND id_usuario = ?', [$id, usuario_id()]);
    if ($c['tipo'] === 'SOLICITUD') {
        $c['solicitud'] = chat_ve_solicitudes() ? sol_cargar((int) $c['id_solicitud']) : null;
        return $c['solicitud'] ? $c : null;
    }
    return $c['participante'] && chat_habilitado() ? $c : null;
}

/** La conversación o un ChatError 404. */
function chat_conversacion_o_error(int $id): array
{
    $c = $id > 0 ? chat_conversacion($id) : null;
    if (!$c) {
        throw new ChatError('Esa conversación no existe o no participás de ella.', 404);
    }
    return $c;
}

/** Usuario activo por id, o ChatError. */
function chat_usuario_activo(int $id): array
{
    $u = $id > 0 ? q1('SELECT id_usuario, nombre FROM usuarios WHERE id_usuario = ? AND activo = 1', [$id]) : null;
    if (!$u) {
        throw new ChatError('Ese usuario no existe o está dado de baja.');
    }
    return $u;
}

/** El chat entre el usuario actual y otro: lo busca o lo crea. */
function chat_directa(int $otro): int
{
    if (!chat_habilitado()) {
        throw new ChatError('No tenés la opción Mensajes.', 403);
    }
    $yo = (int) usuario_id();
    if ($otro === $yo) {
        throw new ChatError('No podés escribirte a vos mismo.');
    }
    chat_usuario_activo($otro);
    $clave = min($yo, $otro) . '-' . max($yo, $otro);
    // Si dos personas lo abren a la vez, el índice único deja uno solo y
    // LAST_INSERT_ID devuelve ese.
    q('INSERT INTO chat_conversaciones (tipo, clave_directa, creado_por) VALUES (\'DIRECTA\', ?, ?)
       ON DUPLICATE KEY UPDATE id_conversacion = LAST_INSERT_ID(id_conversacion)', [$clave, $yo]);
    $id = (int) db()->lastInsertId();
    q('INSERT IGNORE INTO chat_participantes (id_conversacion, id_usuario) VALUES (?, ?), (?, ?)', [$id, $yo, $id, $otro]);
    return $id;
}

/** Nombre de grupo limpio o ChatError. */
function chat_nombre_grupo(string $n): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
    if ($n === '') {
        throw new ChatError('Poné un nombre para el grupo.');
    }
    return substr_utf8($n, CHAT_GRUPO_NOMBRE);
}

/** Crea un grupo con el usuario actual como administrador. Devuelve el id. */
function chat_grupo_crear(string $nombre, array $miembros): int
{
    if (!chat_habilitado()) {
        throw new ChatError('No tenés la opción Mensajes.', 403);
    }
    $nombre = chat_nombre_grupo($nombre);
    $yo = (int) usuario_id();
    $ids = array_values(array_unique(array_filter(array_map('intval', $miembros), function ($x) use ($yo) { return $x > 0 && $x !== $yo; })));
    if (!$ids) {
        throw new ChatError('Elegí al menos una persona para el grupo.');
    }
    $nombres = [];
    foreach ($ids as $uid) {
        $nombres[] = chat_usuario_activo($uid)['nombre'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO chat_conversaciones (tipo, nombre, creado_por) VALUES (\'GRUPO\', ?, ?)', [$nombre, $yo]);
        $id = (int) $pdo->lastInsertId();
        q('INSERT INTO chat_participantes (id_conversacion, id_usuario, es_admin) VALUES (?, ?, 1)', [$id, $yo]);
        foreach ($ids as $uid) {
            q('INSERT INTO chat_participantes (id_conversacion, id_usuario) VALUES (?, ?)', [$id, $uid]);
        }
        chat_aviso($id, usuario_actual()['nombre'] . ' creó el grupo «' . $nombre . '» con ' . chat_lista_nombres($nombres) . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** «Ana», «Ana y Luis», «Ana, Luis y Marta». */
function chat_lista_nombres(array $n): string
{
    if (count($n) <= 1) {
        return (string) ($n[0] ?? '');
    }
    $ultimo = array_pop($n);
    return implode(', ', $n) . ' y ' . $ultimo;
}

/** Renombra, agrega y quita integrantes de un grupo (solo sus administradores). */
function chat_grupo_editar(int $id, ?string $nombre, array $agregar, array $quitar): void
{
    $c = chat_conversacion_o_error($id);
    if ($c['tipo'] !== 'GRUPO') {
        throw new ChatError('Solo los grupos tienen integrantes para editar.');
    }
    if (!(int) $c['participante']['es_admin']) {
        throw new ChatError('Solo quien administra el grupo lo puede editar.', 403);
    }
    $yo = usuario_actual();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($nombre !== null && $nombre !== '' && chat_nombre_grupo($nombre) !== $c['nombre']) {
            $nombre = chat_nombre_grupo($nombre);
            q('UPDATE chat_conversaciones SET nombre = ? WHERE id_conversacion = ?', [$nombre, $id]);
            chat_aviso($id, $yo['nombre'] . ' le cambió el nombre al grupo: «' . $nombre . '».');
        }
        $suma = [];
        foreach (array_unique(array_map('intval', $agregar)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $u = chat_usuario_activo($uid);
            $st = db()->prepare('INSERT IGNORE INTO chat_participantes (id_conversacion, id_usuario, ultimo_leido)
                                 SELECT ?, ?, ultimo_mensaje_id FROM chat_conversaciones WHERE id_conversacion = ?');
            $st->execute([$id, $uid, $id]);
            if ($st->rowCount() > 0) {
                $suma[] = $u['nombre'];
            }
        }
        if ($suma) {
            chat_aviso($id, $yo['nombre'] . ' agregó a ' . chat_lista_nombres($suma) . '.');
        }
        $resta = [];
        foreach (array_unique(array_map('intval', $quitar)) as $uid) {
            if ($uid <= 0 || $uid === $yo['id']) {
                continue;
            }
            $u = q1('SELECT u.nombre FROM chat_participantes p JOIN usuarios u ON u.id_usuario = p.id_usuario
                      WHERE p.id_conversacion = ? AND p.id_usuario = ?', [$id, $uid]);
            if ($u) {
                q('DELETE FROM chat_participantes WHERE id_conversacion = ? AND id_usuario = ?', [$id, $uid]);
                $resta[] = $u['nombre'];
            }
        }
        if ($resta) {
            chat_aviso($id, $yo['nombre'] . ' sacó del grupo a ' . chat_lista_nombres($resta) . '.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Salir de un grupo. Si el que sale era el único administrador, pasa a serlo el más antiguo. */
function chat_grupo_salir(int $id): void
{
    $c = chat_conversacion_o_error($id);
    if ($c['tipo'] !== 'GRUPO') {
        throw new ChatError('Solo se puede salir de un grupo.');
    }
    $yo = usuario_actual();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM chat_participantes WHERE id_conversacion = ? AND id_usuario = ?', [$id, $yo['id']]);
        $admins = q1('SELECT COUNT(*) AS n FROM chat_participantes WHERE id_conversacion = ? AND es_admin = 1', [$id]);
        if ((int) $admins['n'] === 0) {
            q('UPDATE chat_participantes SET es_admin = 1 WHERE id_conversacion = ?
                ORDER BY agregado_el, id_usuario LIMIT 1', [$id]);
        }
        chat_aviso($id, $yo['nombre'] . ' salió del grupo.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * El hilo de una solicitud: id de la conversación, o null si todavía no
 * tiene mensajes. Con $crear = true la crea (al mandar el primer mensaje).
 */
function chat_hilo_solicitud(int $idSolicitud, bool $crear = false): ?int
{
    $sol = chat_ve_solicitudes() ? sol_cargar($idSolicitud) : null;
    if (!$sol) {
        throw new ChatError('Esa solicitud no existe o no la podés ver.', 404);
    }
    $c = q1('SELECT id_conversacion FROM chat_conversaciones WHERE id_solicitud = ?', [$idSolicitud]);
    if ($c) {
        return (int) $c['id_conversacion'];
    }
    if (!$crear) {
        return null;
    }
    q('INSERT INTO chat_conversaciones (tipo, id_solicitud, creado_por) VALUES (\'SOLICITUD\', ?, ?)
       ON DUPLICATE KEY UPDATE id_conversacion = LAST_INSERT_ID(id_conversacion)', [$idSolicitud, usuario_id()]);
    return (int) db()->lastInsertId();
}

/**
 * Los que reciben aviso de un hilo de solicitud: quien la cargó, el usuario
 * del vendedor, quien escribe ahora y, si ya se resolvió, quien la
 * resolvió; si todavía no, todos los que pueden autorizar (opción
 * «Autorizaciones», por rol o suelta). Los que ya escribieron siguen
 * siendo participantes. Se vuelve a correr en cada mensaje.
 */
function chat_hilo_participantes(int $idConv, int $idSolicitud): void
{
    q("INSERT IGNORE INTO chat_participantes (id_conversacion, id_usuario)
       SELECT ?, x.id FROM (
           SELECT s.creado_por AS id FROM solicitudes s WHERE s.id_solicitud = ?
           UNION SELECT s.resuelto_por FROM solicitudes s WHERE s.id_solicitud = ?
           UNION SELECT v.id_usuario FROM solicitudes s JOIN vendedores v ON v.id_vendedor = s.id_vendedor WHERE s.id_solicitud = ?
           UNION SELECT ur.id_usuario FROM solicitudes s
                   JOIN menu m ON m.url = 'autorizaciones.php' AND m.activo = 1
                   JOIN menu_roles mr ON mr.id_menu = m.id_menu
                   JOIN roles r ON r.id_rol = mr.id_rol AND r.activo = 1
                   JOIN usuarios_roles ur ON ur.id_rol = r.id_rol
                  WHERE s.id_solicitud = ? AND s.resuelto_por IS NULL
           UNION SELECT mu.id_usuario FROM solicitudes s
                   JOIN menu m ON m.url = 'autorizaciones.php' AND m.activo = 1
                   JOIN menu_usuarios mu ON mu.id_menu = m.id_menu
                  WHERE s.id_solicitud = ? AND s.resuelto_por IS NULL
           UNION SELECT ?
       ) x JOIN usuarios u ON u.id_usuario = x.id AND u.activo = 1
       WHERE x.id IS NOT NULL", [$idConv, $idSolicitud, $idSolicitud, $idSolicitud, $idSolicitud, $idSolicitud, usuario_id()]);
}

// ---------------------------------------------------------------------
// Mensajes
// ---------------------------------------------------------------------

/** Aviso del sistema dentro de una conversación (sin autor). Va dentro de la transacción del que llama. */
function chat_aviso(int $idConv, string $texto): int
{
    q('INSERT INTO chat_mensajes (id_conversacion, id_usuario, texto) VALUES (?, NULL, ?)', [$idConv, substr_utf8($texto, 500)]);
    $idMsj = (int) db()->lastInsertId();
    q('UPDATE chat_conversaciones SET ultimo_mensaje_id = ?, ultimo_mensaje_el = NOW() WHERE id_conversacion = ?', [$idMsj, $idConv]);
    // El que hizo el cambio ya lo sabe: no le cuenta como no leído.
    q('UPDATE chat_participantes SET ultimo_leido = ? WHERE id_conversacion = ? AND id_usuario = ?', [$idMsj, $idConv, usuario_id()]);
    return $idMsj;
}

/** Tipo de un archivo subido: extensión de CHAT_TIPOS_ARCHIVO, o null. */
function chat_detectar_tipo(string $ruta, string $nombre): ?string
{
    $ext = doc_detectar_tipo($ruta);
    if ($ext !== null) {
        return $ext;
    }
    $declarada = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $fh = @fopen($ruta, 'rb');
    if (!$fh) {
        return null;
    }
    $b = (string) fread($fh, 8192);
    fclose($fh);
    if (in_array($declarada, ['xlsx', 'docx'], true) && strncmp($b, "PK\x03\x04", 4) === 0) {
        return $declarada;
    }
    if (in_array($declarada, ['xls', 'doc'], true) && strncmp($b, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
        return $declarada;
    }
    if (in_array($declarada, ['csv', 'txt'], true) && strpos($b, "\0") === false) {
        return $declarada;
    }
    return null;
}

/**
 * Guarda el archivo subido en docs_dir\chat\<conv>\ y devuelve los datos
 * para el mensaje, o ChatError con el motivo.
 */
function chat_guardar_adjunto(int $idConv, array $a): array
{
    $nombre = doc_nombre_limpio($a['name']);
    if ($a['error'] !== UPLOAD_ERR_OK) {
        throw new ChatError($nombre . ': ' . doc_error_subida($a['error']));
    }
    if (!is_uploaded_file($a['tmp_name']) || $a['size'] <= 0) {
        throw new ChatError($nombre . ': no se pudo subir o está vacío.');
    }
    $max = docs_max_bytes();
    if ($a['size'] > $max) {
        throw new ChatError($nombre . ': pesa ' . bytes_txt($a['size']) . ' y el máximo es ' . bytes_txt($max) . '.');
    }
    $ext = chat_detectar_tipo($a['tmp_name'], $nombre);
    if ($ext === null) {
        throw new ChatError($nombre . ': se pueden mandar PDF, imágenes (JPG, PNG, WEBP), Word, Excel, CSV o texto.');
    }
    $carpeta = docs_dir() . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . $idConv;
    if (!is_dir($carpeta) && !@mkdir($carpeta, 0770, true) && !is_dir($carpeta)) {
        throw new ChatError('No se pudo crear la carpeta ' . $carpeta . ' (permisos sobre ' . docs_dir() . ').', 500);
    }
    $rel  = 'chat/' . $idConv . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $carpeta . DIRECTORY_SEPARATOR . basename($rel);
    if (!@move_uploaded_file($a['tmp_name'], $dest)) {
        throw new ChatError($nombre . ': no se pudo guardar en ' . $carpeta . ' (permisos de la carpeta).', 500);
    }
    return ['archivo' => $rel, 'ruta' => $dest, 'nombre' => $nombre, 'mime' => CHAT_TIPOS_ARCHIVO[$ext][0], 'bytes' => (int) $a['size']];
}

/** Manda un mensaje (texto, adjunto o los dos) y devuelve su id. */
function chat_enviar(int $idConv, string $texto, ?array $archivo): int
{
    $c = chat_conversacion_o_error($idConv);
    $texto = trim(str_replace("\r\n", "\n", $texto));
    if ($texto === '' && $archivo === null) {
        throw new ChatError('Escribí un mensaje o elegí un archivo.');
    }
    if (strlen($texto) > CHAT_TEXTO_MAX * 4 || (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > CHAT_TEXTO_MAX)) {
        throw new ChatError('El mensaje es muy largo (hasta ' . num(CHAT_TEXTO_MAX) . ' caracteres).');
    }
    $adj = $archivo !== null ? chat_guardar_adjunto($idConv, $archivo) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO chat_mensajes (id_conversacion, id_usuario, texto, adjunto_archivo, adjunto_nombre, adjunto_mime, adjunto_bytes)
           VALUES (?,?,?,?,?,?,?)',
          [$idConv, usuario_id(), $texto !== '' ? $texto : null, $adj['archivo'] ?? null, $adj['nombre'] ?? null,
           $adj['mime'] ?? null, $adj['bytes'] ?? null]);
        $idMsj = (int) $pdo->lastInsertId();
        q('UPDATE chat_conversaciones SET ultimo_mensaje_id = ?, ultimo_mensaje_el = NOW() WHERE id_conversacion = ?', [$idMsj, $idConv]);
        if ($c['tipo'] === 'SOLICITUD') {
            chat_hilo_participantes($idConv, (int) $c['id_solicitud']);
        }
        q('UPDATE chat_participantes SET ultimo_leido = ? WHERE id_conversacion = ? AND id_usuario = ?', [$idMsj, $idConv, usuario_id()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($adj) {
            @unlink($adj['ruta']);        // sin mensaje no queda archivo huérfano
        }
        throw $e;
    }
    return $idMsj;
}

/** El autor retira su mensaje: queda «Mensaje eliminado» y el archivo deja de servirse. */
function chat_retirar(int $idMsj): void
{
    $m = q1('SELECT id_conversacion, id_usuario, borrado_el FROM chat_mensajes WHERE id_mensaje = ?', [$idMsj]);
    if (!$m || !chat_conversacion((int) $m['id_conversacion'])) {
        throw new ChatError('Ese mensaje no existe.', 404);
    }
    if ((int) $m['id_usuario'] !== usuario_id()) {
        throw new ChatError('Solo podés eliminar tus mensajes.', 403);
    }
    q('UPDATE chat_mensajes SET borrado_el = NOW() WHERE id_mensaje = ? AND borrado_el IS NULL', [$idMsj]);
}

/**
 * Mensajes de una conversación en orden, como los usa la pantalla.
 *   $desde > 0: los posteriores a ese id (lo nuevo desde la última consulta)
 *   $antes > 0: la tanda anterior a ese id (ir hacia atrás)
 *   los dos 0:  la última tanda
 */
function chat_mensajes(int $idConv, int $desde = 0, int $antes = 0): array
{
    $sel = 'SELECT m.id_mensaje, m.id_usuario, u.nombre AS autor, m.texto, m.adjunto_nombre, m.adjunto_mime,
                   m.adjunto_bytes, m.creado_el, m.borrado_el
              FROM chat_mensajes m LEFT JOIN usuarios u ON u.id_usuario = m.id_usuario
             WHERE m.id_conversacion = ? ';
    if ($desde > 0) {
        $filas = q($sel . 'AND m.id_mensaje > ? ORDER BY m.id_mensaje LIMIT 500', [$idConv, $desde]);
    } else {
        $filas = array_reverse(q($sel . ($antes > 0 ? 'AND m.id_mensaje < ? ' : '') . 'ORDER BY m.id_mensaje DESC LIMIT ' . CHAT_PAGINA,
                                 $antes > 0 ? [$idConv, $antes] : [$idConv]));
    }
    $yo = usuario_id();
    return array_map(function (array $m) use ($yo) {
        $borrado = $m['borrado_el'] !== null;
        $adj = null;
        if (!$borrado && $m['adjunto_nombre'] !== null) {
            $adj = ['nombre' => $m['adjunto_nombre'], 'peso' => bytes_txt((int) $m['adjunto_bytes']),
                    'imagen' => strpos((string) $m['adjunto_mime'], 'image/') === 0,
                    'url' => 'chat_adjunto.php?m=' . (int) $m['id_mensaje']];
        }
        return [
            'id'      => (int) $m['id_mensaje'],
            'autor'   => $m['autor'],
            'propio'  => $m['id_usuario'] !== null && (int) $m['id_usuario'] === $yo,
            'sistema' => $m['id_usuario'] === null,
            'texto'   => $borrado ? null : $m['texto'],
            'borrado' => $borrado,
            'adjunto' => $adj,
            'fecha'   => substr($m['creado_el'], 0, 10),
            'hora'    => substr($m['creado_el'], 11, 5),
        ];
    }, $filas);
}

/** Marca leído hasta ese mensaje (nunca hacia atrás). Solo para participantes. */
function chat_marcar_leido(int $idConv, int $hasta): void
{
    if ($hasta > 0) {
        q('UPDATE chat_participantes SET ultimo_leido = LEAST(?, (SELECT ultimo_mensaje_id FROM chat_conversaciones WHERE id_conversacion = ?))
            WHERE id_conversacion = ? AND id_usuario = ? AND ultimo_leido < ?', [$hasta, $idConv, $idConv, usuario_id(), $hasta]);
    }
}

/** En una directa, hasta qué mensaje leyó el otro (para las dos tildes). */
function chat_leido_por_otro(array $c): int
{
    if ($c['tipo'] !== 'DIRECTA') {
        return 0;
    }
    $r = q1('SELECT ultimo_leido FROM chat_participantes WHERE id_conversacion = ? AND id_usuario <> ? LIMIT 1',
            [(int) $c['id_conversacion'], usuario_id()]);
    return $r ? (int) $r['ultimo_leido'] : 0;
}

// ---------------------------------------------------------------------
// Listas
// ---------------------------------------------------------------------

/**
 * Las conversaciones del usuario, la más reciente primero, con título,
 * último mensaje y no leídos. Las directas y grupos solo con «Mensajes».
 */
function chat_lista(): array
{
    $yo = usuario_id();
    $tipos = chat_habilitado() ? "('DIRECTA','GRUPO','SOLICITUD')" : "('SOLICITUD')";
    $filas = q("SELECT c.id_conversacion, c.tipo, c.nombre, c.id_solicitud, c.ultimo_mensaje_id, c.ultimo_mensaje_el,
                       p.ultimo_leido, p.silenciada, p.es_admin,
                       (SELECT u.nombre FROM chat_participantes o JOIN usuarios u ON u.id_usuario = o.id_usuario
                         WHERE o.id_conversacion = c.id_conversacion AND o.id_usuario <> p.id_usuario LIMIT 1) AS otro,
                       (SELECT u.activo FROM chat_participantes o JOIN usuarios u ON u.id_usuario = o.id_usuario
                         WHERE o.id_conversacion = c.id_conversacion AND o.id_usuario <> p.id_usuario LIMIT 1) AS otro_activo,
                       s.nro_solicitud, cl.denominacion,
                       m.texto AS ult_texto, m.adjunto_nombre AS ult_adjunto, m.borrado_el AS ult_borrado,
                       m.id_usuario AS ult_uid, um.nombre AS ult_autor,
                       (SELECT COUNT(*) FROM chat_mensajes x
                         WHERE x.id_conversacion = c.id_conversacion AND x.id_mensaje > p.ultimo_leido) AS no_leidos
                  FROM chat_participantes p
                  JOIN chat_conversaciones c ON c.id_conversacion = p.id_conversacion
                  LEFT JOIN solicitudes s ON s.id_solicitud = c.id_solicitud
                  LEFT JOIN clientes cl ON cl.id_cliente = s.id_cliente
                  LEFT JOIN chat_mensajes m ON m.id_mensaje = c.ultimo_mensaje_id
                  LEFT JOIN usuarios um ON um.id_usuario = m.id_usuario
                 WHERE p.id_usuario = ? AND c.tipo IN $tipos AND (c.tipo <> 'DIRECTA' OR c.ultimo_mensaje_id > 0)
                 ORDER BY COALESCE(c.ultimo_mensaje_el, c.creado_el) DESC
                 LIMIT " . CHAT_LISTA_MAX, [$yo]);
    // ¿Las solicitudes de los hilos todavía las ve? (a un vendedor le puede cambiar el vínculo)
    $ver = [];
    $ok = [];
    foreach ($filas as $f) {
        if ($f['tipo'] === 'SOLICITUD') {
            $ver[(int) $f['id_solicitud']] = true;
        }
    }
    if (!chat_ve_solicitudes()) {
        $ver = [];
    }
    if ($ver) {
        [$w, $prm] = sol_filtro_visibles();
        $marcas = implode(',', array_fill(0, count($ver), '?'));
        $ok = array_flip(array_map('intval', array_column(
            q("SELECT s.id_solicitud FROM solicitudes s WHERE s.id_solicitud IN ($marcas) AND $w", array_merge(array_keys($ver), $prm)),
            'id_solicitud')));
    }
    $out = [];
    foreach ($filas as $f) {
        if ($f['tipo'] === 'SOLICITUD' && !isset($ok[(int) $f['id_solicitud']])) {
            continue;
        }
        $titulo = $f['tipo'] === 'GRUPO' ? $f['nombre']
                : ($f['tipo'] === 'SOLICITUD' ? 'Solicitud ' . $f['nro_solicitud'] . ' · ' . $f['denominacion'] : ($f['otro'] ?? '(sin el otro usuario)'));
        $ult = '';
        if ((int) $f['ultimo_mensaje_id'] > 0) {
            $ult = $f['ult_borrado'] !== null ? 'Mensaje eliminado'
                 : ($f['ult_texto'] !== null && $f['ult_texto'] !== '' ? $f['ult_texto'] : '📎 ' . $f['ult_adjunto']);
            if ($f['ult_uid'] !== null && $f['tipo'] !== 'DIRECTA') {
                $ult = ((int) $f['ult_uid'] === $yo ? 'Vos' : strtok((string) $f['ult_autor'], ' ')) . ': ' . $ult;
            } elseif ($f['ult_uid'] !== null && (int) $f['ult_uid'] === $yo) {
                $ult = 'Vos: ' . $ult;
            }
        }
        $out[] = [
            'id'        => (int) $f['id_conversacion'],
            'tipo'      => $f['tipo'],
            'titulo'    => $titulo,
            'inactivo'  => $f['tipo'] === 'DIRECTA' && $f['otro_activo'] !== null && !(int) $f['otro_activo'],
            'solicitud' => $f['id_solicitud'] !== null ? (int) $f['id_solicitud'] : null,
            'ultimo'    => substr_utf8(preg_replace('/\s+/u', ' ', $ult) ?? '', 90),
            'cuando'    => chat_cuando($f['ultimo_mensaje_el']),
            'no_leidos' => (int) $f['no_leidos'],
            'silenciada' => (bool) (int) $f['silenciada'],
            'admin'     => (bool) (int) $f['es_admin'],
        ];
    }
    return $out;
}

/** «14:32» si es de hoy, «ayer», o «18/11». */
function chat_cuando(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $d = substr($dt, 0, 10);
    if ($d === date('Y-m-d')) {
        return substr($dt, 11, 5);
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'ayer';
    }
    return substr($d, 8, 2) . '/' . substr($d, 5, 2);
}

/**
 * Para el globito flotante: cuántas conversaciones tienen algo sin leer (sin las
 * silenciadas). Una consulta, por los índices de participantes y la PK de
 * conversaciones.
 */
function chat_no_leidos(): int
{
    $tipos = chat_habilitado() ? "('DIRECTA','GRUPO','SOLICITUD')" : "('SOLICITUD')";
    $r = q1("SELECT COUNT(*) AS n FROM chat_participantes p
               JOIN chat_conversaciones c ON c.id_conversacion = p.id_conversacion
              WHERE p.id_usuario = ? AND p.silenciada = 0 AND c.ultimo_mensaje_id > p.ultimo_leido AND c.tipo IN $tipos",
            [usuario_id()]);
    return (int) $r['n'];
}

/** Usuarios activos para elegir destinatario o integrantes (sin el actual), con sus roles. */
function chat_usuarios(): array
{
    return array_map(function (array $u) {
        return ['id' => (int) $u['id_usuario'], 'nombre' => $u['nombre'], 'roles' => (string) $u['roles']];
    }, q("SELECT u.id_usuario, u.nombre,
                 COALESCE(GROUP_CONCAT(r.nombre ORDER BY r.prioridad DESC SEPARATOR ', '), '') AS roles
            FROM usuarios u
            LEFT JOIN usuarios_roles ur ON ur.id_usuario = u.id_usuario
            LEFT JOIN roles r ON r.id_rol = ur.id_rol AND r.activo = 1
           WHERE u.activo = 1 AND u.id_usuario <> ?
           GROUP BY u.id_usuario, u.nombre ORDER BY u.nombre", [usuario_id()]));
}

/** Integrantes de una conversación (para el panel del grupo). */
function chat_integrantes(int $idConv): array
{
    return array_map(function (array $u) {
        return ['id' => (int) $u['id_usuario'], 'nombre' => $u['nombre'], 'admin' => (bool) (int) $u['es_admin'], 'activo' => (bool) (int) $u['activo']];
    }, q('SELECT u.id_usuario, u.nombre, p.es_admin, u.activo FROM chat_participantes p JOIN usuarios u ON u.id_usuario = p.id_usuario
           WHERE p.id_conversacion = ? ORDER BY p.es_admin DESC, u.nombre', [$idConv]));
}

/** Título de una conversación ya cargada con chat_conversacion. */
function chat_titulo(array $c): string
{
    if ($c['tipo'] === 'GRUPO') {
        return (string) $c['nombre'];
    }
    if ($c['tipo'] === 'SOLICITUD') {
        return 'Solicitud ' . $c['solicitud']['nro_solicitud'] . ' · ' . $c['solicitud']['denominacion'];
    }
    $o = q1('SELECT u.nombre FROM chat_participantes p JOIN usuarios u ON u.id_usuario = p.id_usuario
              WHERE p.id_conversacion = ? AND p.id_usuario <> ? LIMIT 1', [(int) $c['id_conversacion'], usuario_id()]);
    return $o['nombre'] ?? '';
}
