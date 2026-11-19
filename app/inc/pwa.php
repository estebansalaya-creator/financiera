<?php
declare(strict_types=1);

require_once __DIR__ . '/empresa.php';

// ---------------------------------------------------------------------
// PWA: lo que va en el <head> de todas las pantallas para que el celular
// pueda instalar el backoffice como app (manifest, íconos, colores y el
// script que registra sw.js). La instalación solo se ofrece por HTTPS
// (Railway); desde http://IP-del-IIS el celular no la ofrece.
// El manifiesto (manifest.php) y los íconos salen de Datos de la empresa:
// con logo cargado, la app instalada usa el logo como ícono.
// ---------------------------------------------------------------------

function pwa_head(): void
{
    $v = static fn(string $f): int => (int) (@filemtime(__DIR__ . '/../public/' . $f) ?: 0);
    ?>
<link rel="manifest" href="manifest.php?v=<?= h((string) (strtotime((string) (empresa()['actualizado_el'] ?? '')) ?: 0)) ?>">
<meta name="theme-color" content="#1c5a8f">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(empresa_nombre()) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(app_icono_url('32')) ?>">
<link rel="apple-touch-icon" href="<?= h(app_icono_url('180')) ?>">
<script src="assets/pwa.js?v=<?= $v('assets/pwa.js') ?>" defer></script>
<?php
}
