<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/chat.php';

// ---------------------------------------------------------------------
// Mensajes: conversaciones directas, grupos y los hilos de las solicitudes
// en las que participás. Todo lo dinámico lo arma assets/chat.js contra
// chat_api.php; esta página solo dibuja el marco.
//   chat.php?c=N      abre esa conversación
//   Dos solapas: Chats (directas y grupos) y Solicitudes (los hilos de las
//   solicitudes), y en Solicitudes, por estado: Todas, Pendientes,
//   Observadas, Aprobadas y Cerradas. Lo último elegido queda recordado en
//   el navegador.
//   chat.php?para=N   abre (o empieza) la conversación con ese usuario
//   &volver=pantalla  adónde lleva Cerrar (la pone el globito flotante)
// ---------------------------------------------------------------------

$volver = get_str('volver');
if (!url_local($volver) || stripos($volver, 'chat') === 0) {
    $volver = 'index.php';
}

$error = null;
try {
    q1('SELECT 1 FROM chat_conversaciones LIMIT 1');
} catch (PDOException $e) {
    $error = (int) ($e->errorInfo[1] ?? 0) === 1146 ? 'Faltan las tablas de mensajes: corré sql\financiera_chat.sql.' : error_amigable($e);
}

layout_inicio('Mensajes');
if ($error) {
    titulo('Mensajes');
    mensajes([$error]);
    layout_fin();
    exit;
}
?>
<div class="chat card" data-chat-app>
  <aside class="chat-lado">
    <div class="chat-lado-head">
      <strong>Mensajes</strong>
      <div class="chat-lado-acc">
        <?= boton('Nuevo', 'primary', null, 'agregar', false, true, ['data-nuevo' => true, 'title' => 'Nuevo mensaje: elegir a quién escribirle']) ?>
        <?= boton('Grupo', 'ghost', null, 'grupo', false, true, ['data-nuevo-grupo' => true, 'title' => 'Armar un grupo']) ?>
        <button type="button" class="btn-accion chat-sonido" data-chat-sonido aria-pressed="true">🔔</button>
        <a class="btn-accion chat-cerrar" href="<?= h($volver) ?>" data-chat-salir title="Cerrar Mensajes y volver" aria-label="Cerrar Mensajes y volver"><?= icono('cerrar') ?></a>
      </div>
    </div>
    <?php if (chat_ve_solicitudes()): ?>
    <div class="chat-tabs" role="tablist" data-tabs>
      <button type="button" role="tab" data-tab="chats" aria-selected="true">Chats <span class="chat-badge" hidden></span></button>
      <button type="button" role="tab" data-tab="solicitudes" aria-selected="false">Solicitudes <span class="chat-badge" hidden></span></button>
    </div>
    <div class="chat-subtabs" data-subtabs hidden></div>
    <?php endif; ?>
    <div class="menu-buscar chat-buscar">
      <?= icono('buscar', 13) ?>
      <input type="search" data-buscar placeholder="Buscar conversación…" autocomplete="off" aria-label="Buscar conversación">
    </div>
    <ul class="chat-convs" data-convs><li class="chat-lista-vacia">Cargando…</li></ul>
  </aside>
  <section class="chat-panel" data-panel hidden>
    <div class="chat-cab" data-cabecera></div>
    <div class="chat-zona" data-zona-hilo></div>
  </section>
  <div class="chat-sin" data-sin-conv>
    <?= icono('mensaje', 40) ?>
    <p>Elegí una conversación o empezá una nueva.</p>
    <p class="campo-ayuda">Podés mandar PDF, imágenes, Word, Excel o texto (hasta <?= h(bytes_txt(docs_max_bytes())) ?>): con el clip, arrastrándolos o pegando una captura.</p>
  </div>
</div>
<?php layout_fin(); ?>
