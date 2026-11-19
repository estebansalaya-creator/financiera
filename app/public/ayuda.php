<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

// ---------------------------------------------------------------------
// Ayuda: índice de los manuales (busca en todos) y un manual (?m=clave).
// La ve cualquiera con sesión: los manuales explican también las
// pantallas que la persona no tiene. «Ir a la pantalla» sale solo si
// la tiene.
// ---------------------------------------------------------------------

$manuales = ayuda_manuales();
$clave = get_str('m');
$q = get_str('q');
$manual = $manuales[$clave] ?? null;

/** Enlace «Ir a la pantalla» de una sección, si el usuario la tiene. */
function ir_a_pantalla(array $s): string
{
    foreach ($s['pantallas'] as $p) {
        if ($p !== 'ayuda.php' && puede($p) && !in_array($p, ['login.php', 'cliente.php', 'documento.php', 'prestamo.php', 'recibo.php', 'inversion.php', 'inversor.php'], true)) {
            return boton('Ir a la pantalla', 'secondary', $p, 'ver', false, true);
        }
    }
    return '';
}

/** Dónde está la sección en el menú: «Créditos › Préstamos». */
function ruta_menu(array $s): string
{
    $items = menu_items();
    $porId = array_column($items, null, 'id_menu');
    foreach ($s['pantallas'] as $p) {
        foreach ($items as $it) {
            if ($it['url'] === $p) {
                $ruta = [$it['nombre']];
                $padre = $it['padre_id'];
                while ($padre && isset($porId[$padre])) {
                    array_unshift($ruta, $porId[$padre]['nombre']);
                    $padre = $porId[$padre]['padre_id'];
                }
                return implode(' › ', $ruta);
            }
        }
    }
    return '';
}

$resultados = $q !== '' ? ayuda_buscar($q, $manual ? $manual['clave'] : null) : null;

layout_inicio($manual ? 'Ayuda · ' . $manual['titulo'] : 'Ayuda');

if (!$manual):
    titulo('Ayuda', 'Los manuales de uso del backoffice. El botón Ayuda de cada pantalla (o F1) abre directo la parte que corresponde.');
?>
<div class="card">
  <form method="get" action="ayuda.php" class="filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" class="filter-input" placeholder="Buscar en todos los manuales: cancelación, comisión, contraseña…" autofocus>
    <?= boton('Buscar', 'secondary', null, 'buscar', true, true) ?>
    <?php if ($q !== ''): ?><?= boton('Quitar', 'secondary', 'ayuda.php', 'quitar_filtro', false, true) ?><?php endif; ?>
  </form>
</div>

<?php if ($resultados !== null): ?>
<div class="card">
  <?= card_head('Resultados', count($resultados) === 1 ? '1 sección' : count($resultados) . ' secciones') ?>
  <?php if (!$resultados): ?>
    <p class="no-data">Ninguna sección tiene todas esas palabras. Probá con menos o con otras.</p>
  <?php else: ?>
  <ul class="ayuda-resultados">
    <?php foreach ($resultados as $r): ?>
      <li><a href="ayuda.php?m=<?= h(rawurlencode($r['manual']['clave'])) ?>#<?= h($r['seccion']['id']) ?>"><strong><?= h($r['seccion']['titulo']) ?></strong></a>
        <span class="td-muted"> · <?= h($r['manual']['titulo']) ?></span>
        <?php if ($r['seccion']['resumen'] !== ''): ?><div class="ayuda-resumen"><?= h($r['seccion']['resumen']) ?></div><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="ayuda-indice">
  <?php foreach ($manuales as $m): ?>
  <div class="card ayuda-manual-card">
    <?= card_head($m['titulo'], count($m['secciones']) . ' secciones', boton('Abrir', 'secondary', 'ayuda.php?m=' . rawurlencode($m['clave']), 'documento', false, true)) ?>
    <div class="bloque">
      <ul class="ayuda-secciones">
        <?php foreach ($m['secciones'] as $s): ?>
          <li><a href="ayuda.php?m=<?= h(rawurlencode($m['clave'])) ?>#<?= h($s['id']) ?>"><?= h($s['titulo']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else:
    $secciones = $manual['secciones'];
    if ($resultados !== null) {
        $ids = array_map(function ($r) { return $r['seccion']['id']; }, $resultados);
        $secciones = array_values(array_filter($secciones, function ($s) use ($ids) { return in_array($s['id'], $ids, true); }));
    }
    titulo($manual['titulo'], '', '', '<a href="ayuda.php">Ayuda</a> / ' . h($manual['titulo']));
?>
<div class="manual">
  <nav class="manual-indice" aria-label="Secciones del manual">
    <form method="get" action="ayuda.php" class="manual-buscar">
      <input type="hidden" name="m" value="<?= h($manual['clave']) ?>">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar en este manual…" aria-label="Buscar en este manual">
    </form>
    <div class="manual-indice-titulo">En este manual</div>
    <ul>
      <?php foreach ($manual['secciones'] as $s): ?>
        <li><a href="#<?= h($s['id']) ?>" data-seccion="<?= h($s['id']) ?>"><?= h($s['titulo']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="manual-indice-titulo">Otros manuales</div>
    <ul>
      <?php foreach ($manuales as $m): if ($m['clave'] === $manual['clave']) continue; ?>
        <li><a href="ayuda.php?m=<?= h(rawurlencode($m['clave'])) ?>"><?= h($m['titulo']) ?></a></li>
      <?php endforeach; ?>
      <li><a href="ayuda.php">Índice de la ayuda</a></li>
    </ul>
  </nav>

  <div class="manual-cuerpo">
    <?php if ($resultados !== null): ?>
      <div class="card"><div class="bloque">
        <?= $secciones ? 'Secciones con <strong>' . h($q) . '</strong>: ' . count($secciones) . '.' : 'Ninguna sección de este manual tiene todas esas palabras.' ?>
        <a href="ayuda.php?m=<?= h(rawurlencode($manual['clave'])) ?>">Ver el manual entero</a> ·
        <a href="ayuda.php?q=<?= h(rawurlencode($q)) ?>">buscar en todos</a>
      </div></div>
    <?php elseif ($manual['intro'] !== ''): ?>
      <div class="card manual-intro"><div class="bloque manual-texto"><?= ayuda_html($manual['intro']) ?></div></div>
    <?php endif; ?>

    <?php foreach ($secciones as $s): $ruta = ruta_menu($s); ?>
    <section class="card manual-sec" id="<?= h($s['id']) ?>">
      <div class="manual-sec-cab">
        <h2><?= h($s['titulo']) ?></h2>
        <?php if ($ruta !== ''): ?><div class="manual-ruta"><?= icono('menu') ?> <?= h($ruta) ?></div><?php endif; ?>
        <?php if ($s['resumen'] !== ''): ?><p class="manual-resumen"><?= h($s['resumen']) ?></p><?php endif; ?>
      </div>
      <div class="bloque manual-texto"><?= ayuda_html($s['md']) ?></div>
      <?php if (($ir = ir_a_pantalla($s)) !== ''): ?><div class="manual-pie"><?= $ir ?></div><?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php
layout_fin();
