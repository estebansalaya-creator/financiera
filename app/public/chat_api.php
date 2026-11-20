<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// API de Mensajes (JSON). La usan chat.php, el hilo de la ficha de la
// solicitud y el contador del encabezado (assets/chat.js).
//
//   GET  ?accion=contador                       conversaciones con algo sin leer
//   GET  ?accion=lista                          conversaciones del usuario
//   GET  ?accion=usuarios                       a quién se le puede escribir
//   GET  ?accion=mensajes&conv=N                la última tanda
//        &desde=ID                              solo lo posterior (consulta periódica)
//        &antes=ID                              la tanda anterior (ir hacia atrás)
//        &solicitud=N (en vez de conv)          el hilo de esa solicitud
//        &leer=1                                marca leído hasta lo devuelto
//   POST accion=enviar   conv | para | solicitud, texto, archivo
//   POST accion=grupo    nombre, miembros[]                      crea un grupo
//   POST accion=editar   conv, nombre, agregar[], quitar[]       edita un grupo
//   POST accion=salir    conv
//   POST accion=silenciar conv, si (1|0)
//   POST accion=retirar  mensaje
//   POST accion=leido    conv, hasta
//
// Con &auto=1 el pedido es automático (contador, mensajes nuevos): no
// cuenta como actividad para el cierre de sesión por inactividad.
// La seguridad (sesión, opción de menú) la hace bootstrap, que con FIN_API
// contesta los cortes en JSON.
// ---------------------------------------------------------------------

define('FIN_API', true);
if (($_GET['auto'] ?? '') === '1') {
    define('FIN_SIN_ACTIVIDAD', true);
}

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(array $datos, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($datos + ['ok' => $http < 400], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lista de enteros de un campo POST que puede venir como miembros[] o «1,2,3». */
function post_ids(string $clave): array
{
    $v = $_POST[$clave] ?? [];
    if (is_string($v)) {
        $v = explode(',', $v);
    }
    return is_array($v) ? array_values(array_filter(array_map('intval', $v), function ($x) { return $x > 0; })) : [];
}

$accion = es_post() ? post_str('accion') : get_str('accion');

try {
    // ------------------------------------------------------------- GET
    if (!es_post()) {
        switch ($accion) {
            case 'contador':
                responder(['no_leidos' => chat_no_leidos()]);

            case 'lista':
                responder(['conversaciones' => chat_lista()]);

            case 'usuarios':
                if (!chat_habilitado()) {
                    throw new ChatError('No tenés la opción Mensajes.', 403);
                }
                responder(['usuarios' => chat_usuarios()]);

            case 'mensajes':
                $idSol = (int) get_str('solicitud');
                $id = $idSol > 0 ? (int) chat_hilo_solicitud($idSol) : (int) get_str('conv');
                if ($id === 0) {
                    responder(['conv' => null, 'mensajes' => []]);   // hilo de solicitud todavía vacío
                }
                $c = chat_conversacion_o_error($id);
                $desde = (int) get_str('desde');
                $msjs = chat_mensajes($id, $desde, (int) get_str('antes'));
                if (get_str('leer') === '1' && $c['participante'] && $msjs) {
                    chat_marcar_leido($id, max(array_column($msjs, 'id')));
                }
                $datos = ['mensajes' => $msjs, 'leido_otro' => chat_leido_por_otro($c)];
                if ($desde === 0) {
                    // Al abrirla: los datos de la cabecera.
                    $datos['conv'] = [
                        'id' => $id, 'tipo' => $c['tipo'], 'titulo' => chat_titulo($c),
                        'solicitud' => $c['id_solicitud'] !== null ? (int) $c['id_solicitud'] : null,
                        'admin' => $c['participante'] && (int) $c['participante']['es_admin'],
                        'silenciada' => $c['participante'] && (int) $c['participante']['silenciada'],
                        'participa' => (bool) $c['participante'],
                        'integrantes' => $c['tipo'] === 'DIRECTA' ? [] : chat_integrantes($id),
                        'hay_mas' => count($msjs) >= CHAT_PAGINA,
                    ];
                }
                responder($datos);
        }
        throw new ChatError('Acción desconocida.');
    }

    // ------------------------------------------------------------- POST
    // Un envío más grande que post_max_size llega vacío, también sin el token.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new ChatError('El archivo supera lo que acepta el servidor (' . ini_get('post_max_size') . ').', 413);
    }
    $f = $_POST['_csrf'] ?? '';
    $k = $_COOKIE['fin_csrf'] ?? '';
    if (!is_string($f) || !is_string($k) || $k === '' || !hash_equals($k, $f)) {
        throw new ChatError('La pantalla venció. Recargala y probá de nuevo.', 400);
    }

    switch ($accion) {
        case 'enviar':
            $idSol = (int) post_str('solicitud');
            $para  = (int) post_str('para');
            if ($idSol > 0) {
                $id = (int) chat_hilo_solicitud($idSol, true);
            } elseif ($para > 0) {
                $id = chat_directa($para);
            } else {
                $id = (int) post_str('conv');
            }
            $archivos = doc_archivos_subidos('archivo');
            if (count($archivos) > 1) {
                throw new ChatError('Mandá los archivos de a uno.');
            }
            $idMsj = chat_enviar($id, (string) ($_POST['texto'] ?? ''), $archivos[0] ?? null);
            responder(['conv' => $id, 'mensaje' => $idMsj]);

        case 'abrir':                    // la directa con alguien, aunque no tenga mensajes todavía
            responder(['conv' => chat_directa((int) post_str('para'))]);

        case 'grupo':
            responder(['conv' => chat_grupo_crear(post_str('nombre'), post_ids('miembros'))]);

        case 'editar':
            $id = (int) post_str('conv');
            chat_grupo_editar($id, isset($_POST['nombre']) ? post_str('nombre') : null, post_ids('agregar'), post_ids('quitar'));
            responder(['conv' => $id]);

        case 'salir':
            chat_grupo_salir((int) post_str('conv'));
            responder([]);

        case 'silenciar':
            $id = (int) post_str('conv');
            chat_conversacion_o_error($id);
            q('UPDATE chat_participantes SET silenciada = ? WHERE id_conversacion = ? AND id_usuario = ?',
              [post_str('si') === '1' ? 1 : 0, $id, usuario_id()]);
            responder([]);

        case 'retirar':
            chat_retirar((int) post_str('mensaje'));
            responder([]);

        case 'leido':
            $id = (int) post_str('conv');
            chat_conversacion_o_error($id);
            chat_marcar_leido($id, (int) post_str('hasta'));
            responder([]);
    }
    throw new ChatError('Acción desconocida.');
} catch (ChatError $e) {
    responder(['error' => $e->getMessage()], $e->http);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1146) {
        responder(['error' => 'Faltan las tablas de mensajes: corré sql\\financiera_chat.sql.'], 500);
    }
    responder(['error' => mensaje_bd($e)], 500);
} catch (Throwable $e) {
    responder(['error' => error_amigable($e)], 500);
}
