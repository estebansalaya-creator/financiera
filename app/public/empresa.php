<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/pdf.php';

// ---------------------------------------------------------------------
// Datos de la empresa: razón social, CUIT, domicilio, contacto, WhatsApp
// Business y logo. Una sola fila (empresa.id_empresa = 1). Salen en el
// encabezado de la app, en el ingreso y en todos los PDF.
//   ?pdf=1  PDF de muestra, para ver cómo queda el encabezado.
// ---------------------------------------------------------------------

const PROVINCIAS_AR = ['Buenos Aires', 'Ciudad Autónoma de Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
                       'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
                       'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
                       'Santiago del Estero', 'Tierra del Fuego', 'Tucumán'];

/** Campo de texto => [rótulo, largo máximo]. */
const CAMPOS_EMPRESA = [
    'razon_social' => ['Razón social', 150], 'nombre_fantasia' => ['Nombre de fantasía', 100],
    'ingresos_brutos' => ['Ingresos brutos', 30], 'bcra_codigo' => ['Código BCRA', 20],
    'domicilio' => ['Domicilio', 150], 'localidad' => ['Localidad', 80], 'codigo_postal' => ['Código postal', 10],
    'telefono' => ['Teléfono', 50], 'email' => ['Correo', 120], 'sitio_web' => ['Sitio web', 120],
    'whatsapp_mensaje' => ['Mensaje de saludo', 500], 'whatsapp_phone_id' => ['Phone Number ID', 30],
    'whatsapp_waba_id' => ['WABA ID', 30],
];

if (get_str('pdf') === '1') {
    $rep = new PdfReporte('Muestra del encabezado', 'Así salen los datos de la empresa en recibos, planes de pago y liquidaciones', false);
    $rep->seccion('Ejemplo');
    $rep->tabla([['Concepto', 3, 'L'], ['Importe', 1, 'R']], [['Cuota 1 de 12', money(125000)], ['Punitorios', money(3200)]],
                ['Total', money(128200)]);
    $rep->enviar('muestra-encabezado.pdf');
    exit;
}

$errores = [];
$e = empresa(true);
if (!$e['existe']) {
    $errores[] = 'Falta crear la tabla de la empresa: corré sql\financiera_empresa.sql.';
}
$v = $e;

if (es_post() && $e['existe']) {
    csrf_exigir();
    foreach (CAMPOS_EMPRESA as $c => [$rot, $max]) {
        $v[$c] = post_str($c);
        if (preg_match_all('/./su', $v[$c]) > $max) {
            $errores[] = $rot . ': hasta ' . $max . ' caracteres.';
        }
    }
    // El teléfono general toma el formato de siempre si es un número solo; si trae
    // un interno o dos líneas («4321-0000 int. 12») queda como se escribió.
    $telEmpresa = $v['telefono'] !== '' ? (tel_normalizar($v['telefono']) ?? $v['telefono']) : null;
    $v['cuit'] = cuit_limpio(post_str('cuit'));
    $v['condicion_iva'] = post_str('condicion_iva');
    $v['provincia'] = post_str('provincia');
    $v['inicio_actividades'] = post_str('inicio_actividades');
    $v['whatsapp_numero'] = post_str('whatsapp_numero');

    if ($v['razon_social'] === '') {
        $errores[] = 'Falta la razón social.';
    }
    if ($v['cuit'] !== '' && !cuit_valido($v['cuit'])) {
        $errores[] = 'El CUIT no es válido (revisá el dígito verificador).';
    }
    if ($v['condicion_iva'] !== '' && !isset(EMPRESA_COND_IVA[$v['condicion_iva']])) {
        $errores[] = 'Elegí la condición frente al IVA.';
    }
    if ($v['provincia'] !== '' && !in_array($v['provincia'], PROVINCIAS_AR, true)) {
        $errores[] = 'Elegí la provincia de la lista.';
    }
    $inicio = null;
    if ($v['inicio_actividades'] !== '') {
        $inicio = leer_fecha($v['inicio_actividades']);
        if ($inicio === null || $inicio > date('Y-m-d')) {
            $errores[] = 'La fecha de inicio de actividades no es válida.';
        }
    }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo no es válido.';
    }
    if ($v['sitio_web'] !== '') {
        $v['sitio_web'] = preg_replace('#^https?://#i', '', rtrim($v['sitio_web'], '/'));
        if (!preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $v['sitio_web'])) {
            $errores[] = 'El sitio web no es válido (ej.: www.mifinanciera.com.ar).';
        }
    }
    $wa = null;
    if ($v['whatsapp_numero'] !== '') {
        $wa = whatsapp_normalizar($v['whatsapp_numero']);
        if ($wa === null) {
            $errores[] = 'El número de WhatsApp no es válido: escribilo con la característica y sin el 0 ni el 15 (ej.: 11 2345-6789), o con +código de país si es de otro país.';
        }
    }
    foreach (['whatsapp_phone_id' => 'Phone Number ID', 'whatsapp_waba_id' => 'WABA ID'] as $c => $rot) {
        if ($v[$c] !== '' && !ctype_digit($v[$c])) {
            $errores[] = 'El ' . $rot . ' de WhatsApp son solo números (está en Meta › WhatsApp › Configuración de la API).';
        }
    }

    // Logo
    $logo = null;                 // null: no se toca; false: se quita; array: nuevo
    $f = $_FILES['logo'] ?? null;
    $err = is_array($f) && !is_array($f['error'] ?? null) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])) {
        try {
            $bin = (string) file_get_contents((string) $f['tmp_name']);
            $logo = logo_preparar($bin) + ['bin' => $bin];
        } catch (RuntimeException $ex) {
            $errores[] = 'Logo: ' . $ex->getMessage();
        }
    } elseif ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        $errores[] = 'Logo: el archivo pesa más de lo permitido (máximo 1 MB).';
    } elseif ($err !== UPLOAD_ERR_NO_FILE) {
        $errores[] = 'Logo: no se pudo subir el archivo (código ' . $err . '). Probá de nuevo.';
    } elseif (post_str('quitar_logo') === '1') {
        $logo = false;
    }

    // Ícono de la app: lo arma el navegador (canvas) desde el logo
    $iconoUsar = post_str('app_icono_usar') === '1' ? 1 : 0;
    $iconoFondo = strtolower(ltrim(post_str('app_icono_fondo'), '#'));     // en la base, sin el numeral
    if (!preg_match('/^[0-9a-f]{6}$/', $iconoFondo)) {
        $iconoFondo = 'ffffff';
    }
    $v['app_icono_usar'] = $iconoUsar;
    $v['app_icono_fondo'] = $iconoFondo;
    $iconoDesde = max(0, min(95, (int) post_str('app_icono_desde')));
    $iconoHasta = max($iconoDesde + 5, min(100, (int) (post_str('app_icono_hasta') === '' ? 100 : post_str('app_icono_hasta'))));
    $iconos = null;
    if ($iconoUsar && $logo !== false) {
        $iconos = [];
        foreach (ICONOS_APP as $t => [$col, $lado]) {
            $png = app_icono_png(post_str('icono_' . $t), $lado);
            if ($png === null) {
                $iconos = null;                 // sin JavaScript o sin logo: se dejan los que había
                break;
            }
            $iconos[$col] = $png;
        }
    }

    if (!$errores) {
        $nul = fn(string $c) => $v[$c] !== '' ? $v[$c] : null;
        try {
            q('UPDATE empresa SET razon_social=?, nombre_fantasia=?, cuit=?, condicion_iva=?, ingresos_brutos=?, inicio_actividades=?,
                      bcra_codigo=?, domicilio=?, localidad=?, provincia=?, codigo_postal=?, telefono=?, email=?, sitio_web=?,
                      whatsapp_numero=?, whatsapp_mensaje=?, whatsapp_phone_id=?, whatsapp_waba_id=?, actualizado_por=?
                WHERE id_empresa = 1',
              [$v['razon_social'], $nul('nombre_fantasia'), $nul('cuit'), $nul('condicion_iva'), $nul('ingresos_brutos'), $inicio,
               $nul('bcra_codigo'), $nul('domicilio'), $nul('localidad'), $nul('provincia'), $nul('codigo_postal'),
               $telEmpresa, $nul('email'), $nul('sitio_web'), $wa, $nul('whatsapp_mensaje'),
               $nul('whatsapp_phone_id'), $nul('whatsapp_waba_id'), usuario_id()]);
            if (is_array($logo)) {
                q('UPDATE empresa SET logo=?, logo_tipo=?, logo_ancho=?, logo_alto=?, logo_pdf=?, logo_actualizado_el=NOW() WHERE id_empresa = 1',
                  [$logo['bin'], $logo['tipo'], $logo['ancho'], $logo['alto'], serialize($logo['pdf'])]);
            } elseif ($logo === false) {
                q('UPDATE empresa SET logo=NULL, logo_tipo=NULL, logo_ancho=NULL, logo_alto=NULL, logo_pdf=NULL, logo_actualizado_el=NOW(),
                          app_icono_32=NULL, app_icono_180=NULL, app_icono_192=NULL, app_icono_512=NULL, app_icono_mask=NULL,
                          app_icono_actualizado_el=NOW() WHERE id_empresa = 1');
            }
            q('UPDATE empresa SET app_icono_usar=?, app_icono_fondo=? WHERE id_empresa = 1', [$iconoUsar, $iconoFondo]);
            try {
                q('UPDATE empresa SET app_icono_desde=?, app_icono_hasta=? WHERE id_empresa = 1', [$iconoDesde, $iconoHasta]);
            } catch (Throwable $ex) {
                // base sin esas columnas (falta volver a correr sql\financiera_empresa.sql): el ícono sale igual, no se recuerda el recorte
            }
            if ($iconos) {
                $cols = array_keys($iconos);
                q('UPDATE empresa SET ' . implode('=?, ', $cols) . '=?, app_icono_actualizado_el=NOW() WHERE id_empresa = 1',
                  array_values($iconos));
            }
            flash('ok', 'Datos de la empresa guardados.' . (is_array($logo) ? ' Logo actualizado.' : ($logo === false ? ' Logo quitado.' : '')));
            redirigir('empresa.php');
        } catch (Throwable $ex) {
            $errores[] = mensaje_bd($ex);
        }
    }
}

function campo_txt(array $v, string $c, int $span, string $extra = '', string $ayuda = ''): string
{
    [$rot, $max] = CAMPOS_EMPRESA[$c];
    return '<div class="campo" style="--span:' . $span . '"><label for="' . $c . '">' . h($rot)
         . ($c === 'razon_social' ? ' <span class="req">*</span>' : '') . '</label>'
         . '<input id="' . $c . '" name="' . $c . '" value="' . h($v[$c] ?? '') . '" maxlength="' . $max . '" ' . $extra . '>'
         . ($ayuda !== '' ? '<small class="campo-ayuda">' . $ayuda . '</small>' : '') . '</div>';
}

$logoUrl = empresa_logo_url();
// Qué franja del logo va al ícono (columnas nuevas: si faltan, el logo entero)
if (!isset($iconoDesde)) {
    $iconoDesde = 0;
    $iconoHasta = 100;
    try {
        $r = q1('SELECT app_icono_desde, app_icono_hasta FROM empresa WHERE id_empresa = 1');
        if ($r) {
            $iconoDesde = max(0, min(95, (int) $r['app_icono_desde']));
            $iconoHasta = max($iconoDesde + 5, min(100, (int) $r['app_icono_hasta']));
        }
    } catch (Throwable $ex) {
    }
}
$tokenOk = (string) cfg('whatsapp_token', '') !== '';
$waNum = (string) ($v['whatsapp_numero'] ?? '');
$waNorm = $waNum !== '' ? (whatsapp_normalizar($waNum) ?? '') : '';

layout_inicio('Datos de la empresa', '');
titulo('Datos de la empresa', 'Salen en el encabezado del sistema, en el ingreso y en todos los PDF.',
       boton('PDF de muestra', 'pdf', 'empresa.php?pdf=1', 'pdf', false, false, ['target' => '_blank', 'rel' => 'noopener']));
mensajes($errores);
?>
<form method="post" action="empresa.php" enctype="multipart/form-data" autocomplete="off">
  <?= csrf_campo() ?>
  <input type="hidden" name="MAX_FILE_SIZE" value="<?= LOGO_MAX_BYTES ?>">
  <div class="columnas-2-1">
  <div>
    <div class="card">
      <?= card_head('Identificación') ?>
      <div class="bloque">
        <div class="campos">
          <?= campo_txt($v, 'razon_social', 8, 'required') ?>
          <?= campo_txt($v, 'nombre_fantasia', 4, '', 'El nombre corto que se ve en el sistema.') ?>
          <div class="campo" style="--span:4"><label for="cuit">CUIT</label>
            <input id="cuit" name="cuit" value="<?= h(cuit_fmt($v['cuit'] ?? '')) ?>" maxlength="13" class="mono" data-cuit placeholder="30-12345678-9">
            <small class="campo-ayuda"></small></div>
          <div class="campo" style="--span:4"><label for="condicion_iva">Condición frente al IVA</label>
            <select id="condicion_iva" name="condicion_iva"><?= opciones(EMPRESA_COND_IVA, $v['condicion_iva'] ?? '', '—') ?></select></div>
          <div class="campo" style="--span:4"><label for="inicio_actividades">Inicio de actividades</label>
            <input id="inicio_actividades" name="inicio_actividades" type="date" value="<?= h($v['inicio_actividades'] ?? '') ?>"></div>
          <?= campo_txt($v, 'ingresos_brutos', 6, '', 'N.º de inscripción o «Convenio Multilateral …».') ?>
          <?= campo_txt($v, 'bcra_codigo', 6, 'class="mono"', 'Si está inscripta en el BCRA como proveedor no financiero de crédito.') ?>
        </div>
      </div>
    </div>

    <div class="card">
      <?= card_head('Domicilio y contacto') ?>
      <div class="bloque">
        <div class="campos">
          <?= campo_txt($v, 'domicilio', 12, 'placeholder="Calle, número, piso, oficina"') ?>
          <?= campo_txt($v, 'localidad', 5) ?>
          <div class="campo" style="--span:5"><label for="provincia">Provincia</label>
            <select id="provincia" name="provincia"><?= opciones(array_combine(PROVINCIAS_AR, PROVINCIAS_AR), $v['provincia'] ?? '', '—') ?></select></div>
          <?= campo_txt($v, 'codigo_postal', 2, 'class="mono"') ?>
          <?= campo_txt(['telefono' => tel_fmt($v['telefono'] ?? '')] + $v, 'telefono', 4, 'type="tel" data-tel="" data-tel-libre placeholder="11 4321-0000"') ?>
          <?= campo_txt($v, 'email', 4, 'type="email"') ?>
          <?= campo_txt($v, 'sitio_web', 4, 'placeholder="www.mifinanciera.com.ar"') ?>
        </div>
      </div>
    </div>

    <div class="card">
      <?= card_head('WhatsApp Business', $tokenOk && !empty($e['whatsapp_phone_id']) ? 'API conectada' : '') ?>
      <div class="bloque">
        <div class="bloque-titulo">Número de atención</div>
        <div class="campos">
          <div class="campo" style="--span:5"><label for="whatsapp_numero">Número de WhatsApp</label>
            <input id="whatsapp_numero" name="whatsapp_numero" type="tel" class="mono" maxlength="25" data-tel="movil"
                   value="<?= h($waNorm !== '' ? whatsapp_fmt($waNorm) : $waNum) ?>" placeholder="11 2345-6789">
            <small class="campo-ayuda">Con la característica, sin 0 ni 15. Se guarda como +54 9 …</small></div>
          <div class="campo" style="--span:7"><label>&nbsp;</label>
            <?php if (!empty($e['whatsapp_numero'])): ?>
              <div><?= boton('Probar el número', 'secondary', whatsapp_link((string) $e['whatsapp_numero'], whatsapp_saludo('Prueba')), 'telefono', false, false, ['target' => '_blank', 'rel' => 'noopener']) ?></div>
              <small class="campo-ayuda">Abre WhatsApp con un chat a <?= h(whatsapp_fmt((string) $e['whatsapp_numero'])) ?>.</small>
            <?php else: ?>
              <small class="campo-ayuda">Con el número cargado, sale en los PDF y los botones de WhatsApp lo usan.</small>
            <?php endif; ?>
          </div>
          <div class="campo" style="--span:12"><label for="whatsapp_mensaje">Mensaje de saludo</label>
            <textarea id="whatsapp_mensaje" name="whatsapp_mensaje" maxlength="500" rows="2"
                      placeholder="Hola {cliente}, te escribimos de {empresa}."><?= h($v['whatsapp_mensaje'] ?? '') ?></textarea>
            <small class="campo-ayuda">Con lo que arranca el chat al escribirle a un cliente. <code>{cliente}</code> y <code>{empresa}</code> se reemplazan solos.</small></div>
        </div>
      </div>
      <div class="bloque">
        <div class="bloque-titulo">API de WhatsApp Cloud (avisos automáticos)</div>
        <div class="campos">
          <?= campo_txt($v, 'whatsapp_phone_id', 4, 'class="mono" inputmode="numeric"') ?>
          <?= campo_txt($v, 'whatsapp_waba_id', 4, 'class="mono" inputmode="numeric"') ?>
          <div class="campo" style="--span:4"><span class="campo-label">Token de acceso</span>
            <div><?= $tokenOk ? estado('Configurado', 'ok') : estado('Falta', 'warn') ?></div>
            <small class="campo-ayuda">Va en config.php (<code>whatsapp_token</code>) o en la variable <code>FIN_WA_TOKEN</code> de Railway, no acá.</small></div>
        </div>
        <small class="campo-ayuda">Solo hacen falta para mandar mensajes desde el sistema (recordatorios de vencimiento, recibos). Están en Meta for Developers › tu app › WhatsApp › Configuración de la API.</small>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <?= card_head('Logo') ?>
      <div class="bloque">
        <div class="logo-muestras">
          <div class="logo-muestra logo-muestra-clara" title="Así sale en los PDF">
            <?= $logoUrl ? '<img src="' . h($logoUrl) . '" alt="Logo">' : '<span>Sin logo</span>' ?>
            <small>PDF</small>
          </div>
          <div class="logo-muestra logo-muestra-oscura" title="Así sale en el encabezado del sistema">
            <span class="header-logo<?= $logoUrl ? ' con-logo' : '' ?>"><?= $logoUrl ? '<img src="' . h($logoUrl) . '" alt="">' : icono('dinero', 20) ?></span>
            <strong><?= h(empresa_nombre()) ?></strong>
            <small>Encabezado</small>
          </div>
        </div>
        <?php if ($logoUrl): ?>
          <p class="campo-ayuda" style="margin:8px 0 0"><?= (int) $e['logo_ancho'] ?>×<?= (int) $e['logo_alto'] ?> px · <?= $e['logo_tipo'] === 'image/png' ? 'PNG' : 'JPG' ?> · cargado el <?= h(fecha(substr((string) $e['logo_actualizado_el'], 0, 10))) ?></p>
        <?php endif; ?>
        <div class="campos" style="margin-top:14px">
          <div class="campo" style="--span:12"><label for="logo"><?= $logoUrl ? 'Cambiar el logo' : 'Subir el logo' ?></label>
            <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
            <small class="campo-ayuda">PNG (mejor con fondo transparente) o JPG, hasta 1 MB. Rinde más uno apaisado, de unos 600 px de ancho.</small></div>
          <?php if ($logoUrl): ?>
          <div class="campo" style="--span:12"><label class="campo-check"><input type="checkbox" name="quitar_logo" value="1"> Quitar el logo</label></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="bloque">
        <div class="bloque-titulo">Ícono de la app</div>
        <div class="icono-app">
          <div class="icono-app-muestras" id="icono-muestras">
            <figure><img id="icono-prev-any" src="<?= h(app_icono_url('192')) ?>" alt="" width="96" height="96"><figcaption>iPhone / PC</figcaption></figure>
            <figure><img id="icono-prev-mask" class="redondo" src="<?= h(app_icono_url('mask')) ?>" alt="" width="96" height="96"><figcaption>Android</figcaption></figure>
          </div>
          <div class="campos">
            <div class="campo" style="--span:12"><label class="campo-check">
              <input type="checkbox" name="app_icono_usar" value="1" id="app_icono_usar" <?= !empty($v['app_icono_usar']) || !$e['existe'] ? 'checked' : '' ?>>
              Usar el logo como ícono de la app</label></div>
            <div class="campo" style="--span:12"><label for="app_icono_fondo">Fondo del ícono</label>
              <div class="icono-app-fondos">
                <input type="color" id="app_icono_fondo" name="app_icono_fondo" value="#<?= h(ltrim((string) ($v['app_icono_fondo'] ?? 'ffffff'), '#')) ?>">
                <button type="button" class="btn btn-ghost btn-sm" data-fondo="#ffffff">Blanco</button>
                <button type="button" class="btn btn-ghost btn-sm" data-fondo="#1c5a8f">Azul</button>
              </div>
            </div>
            <div class="campo" style="--span:12"><label for="app_icono_desde">Recorte del logo <span id="app_icono_rec_txt" class="icono-app-valor"></span></label>
              <div class="icono-app-rango"><span>Desde</span><input type="range" id="app_icono_desde" name="app_icono_desde" min="0" max="95" step="1" value="<?= (int) $iconoDesde ?>"></div>
              <div class="icono-app-rango"><span>Hasta</span><input type="range" id="app_icono_hasta" name="app_icono_hasta" min="5" max="100" step="1" value="<?= (int) $iconoHasta ?>"></div>
              <div><button type="button" class="btn btn-ghost btn-sm" id="icono-reset">Logo entero</button></div>
              <small class="campo-ayuda">Al logo se le saca solo el blanco de alrededor. Si entero se ve chico (un logo apaisado), mové «Desde» y «Hasta» para quedarte con una parte, por ejemplo solo el isotipo: se muestra entera y centrada. Es el ícono del celular y de la pestaña del navegador. Sin logo, queda el estándar. En un iPhone que ya la tenga instalada, el ícono nuevo aparece al volver a agregarla a la pantalla de inicio.</small></div>
          </div>
        </div>
        <?php foreach (ICONOS_APP as $t => $_): ?><input type="hidden" name="icono_<?= h($t) ?>" id="icono_<?= h($t) ?>" value=""><?php endforeach; ?>
      </div>
    </div>
    <?php if (!empty($e['actualizado_el'])): ?>
      <p class="campo-ayuda" style="margin:0 4px">Última modificación: <?= h(fecha(substr((string) $e['actualizado_el'], 0, 10))) ?> <?= h(substr((string) $e['actualizado_el'], 11, 5)) ?></p>
    <?php endif; ?>
  </div>
  </div>
  <div class="card"><div class="form-acciones"><?= boton('Guardar', 'primary', null, 'guardar', true) ?></div></div>
</form>
<script>
// Vista previa del logo elegido y armado de los íconos de la app (PWA):
// el navegador dibuja el logo en cuadrados de 32, 180, 192 y 512 px y en el
// «maskable» de Android (con más margen, porque Android lo recorta en círculo
// o en gota). Los PNG viajan en campos ocultos al guardar.
//   - Antes de dibujar se le recorta al logo el blanco (o la transparencia)
//     que tenga alrededor, para que ocupe todo el ícono.
//   - Recorte: «desde» y «hasta» eligen qué franja del logo (de izquierda a
//     derecha) va al ícono; esa parte se muestra entera y centrada. Sirve
//     para dejar solo el isotipo de un logo apaisado, que entero se ve chico.
(function () {
  var input = document.getElementById('logo');
  var usar = document.getElementById('app_icono_usar');
  var fondo = document.getElementById('app_icono_fondo');
  var desde = document.getElementById('app_icono_desde');
  var hasta = document.getElementById('app_icono_hasta');
  var recTxt = document.getElementById('app_icono_rec_txt');
  var quitar = document.querySelector('input[name=quitar_logo]');
  var LADOS = { '32': [32, 0.04], '180': [180, 0.07], '192': [192, 0.06], '512': [512, 0.06], 'mask': [512, 0.14] };
  var ESTANDAR = { any: 'assets/iconos/icono-192.png', mask: 'assets/iconos/icono-maskable-512.png' };
  var imgLogo = null, caja = null, pendiente = null;
  <?php if ($logoUrl): ?>
  cargar(<?= json_encode($logoUrl) ?>);
  <?php endif; ?>

  function cargar(url) {
    imgLogo = new Image();
    imgLogo.onload = function () { caja = recorte(imgLogo); armar(); };
    imgLogo.src = url;
  }

  // La parte del logo que tiene dibujo: sin el borde del color de la esquina (o transparente).
  function recorte(img) {
    var W = img.naturalWidth, H = img.naturalHeight, todo = { x: 0, y: 0, w: W, h: H };
    try {
      var f = Math.min(1, 600 / Math.max(W, H)), w = Math.max(1, Math.round(W * f)), h = Math.max(1, Math.round(H * f));
      var c = document.createElement('canvas');
      c.width = w; c.height = h;
      var g = c.getContext('2d');
      g.drawImage(img, 0, 0, w, h);
      var d = g.getImageData(0, 0, w, h).data;
      var r0 = d[0], g0 = d[1], b0 = d[2], a0 = d[3];
      var x1 = w, y1 = h, x2 = -1, y2 = -1;
      for (var y = 0; y < h; y++) {
        for (var x = 0; x < w; x++) {
          var i = (y * w + x) * 4, a = d[i + 3];
          var dibujo = a0 < 16 ? a > 24
                     : a > 24 && (Math.abs(d[i] - r0) + Math.abs(d[i + 1] - g0) + Math.abs(d[i + 2] - b0)) > 36;
          if (dibujo) {
            if (x < x1) x1 = x;
            if (x > x2) x2 = x;
            if (y < y1) y1 = y;
            if (y > y2) y2 = y;
          }
        }
      }
      if (x2 < x1 || y2 < y1) return todo;
      return { x: Math.max(0, (x1 - 1) / f), y: Math.max(0, (y1 - 1) / f),
               w: Math.min(W, (x2 - x1 + 3) / f), h: Math.min(H, (y2 - y1 + 3) / f) };
    } catch (e) {
      return todo;
    }
  }

  function dibujar(lado, margen) {
    var c = document.createElement('canvas');
    c.width = c.height = lado;
    var g = c.getContext('2d');
    g.fillStyle = fondo.value || '#ffffff';
    g.fillRect(0, 0, lado, lado);
    var b = caja || { x: 0, y: 0, w: imgLogo.naturalWidth, h: imgLogo.naturalHeight };
    var d = (parseInt(desde.value, 10) || 0) / 100, h100 = (parseInt(hasta.value, 10) || 100) / 100;
    var sx = b.x + b.w * d, sw = Math.max(1, b.w * (h100 - d));          // la franja elegida
    var util = lado * (1 - 2 * margen);
    var esc = Math.min(util / sw, util / b.h);
    var w = sw * esc, h = b.h * esc;
    g.imageSmoothingQuality = 'high';
    g.drawImage(imgLogo, sx, b.y, sw, b.h, (lado - w) / 2, (lado - h) / 2, w, h);
    return c.toDataURL('image/png');
  }

  function armar() {
    var activo = imgLogo && imgLogo.naturalWidth && usar.checked && !(quitar && quitar.checked);
    Object.keys(LADOS).forEach(function (t) {
      document.getElementById('icono_' + t).value = activo ? dibujar(LADOS[t][0], LADOS[t][1]) : '';
    });
    document.getElementById('icono-prev-any').src = activo ? document.getElementById('icono_192').value : ESTANDAR.any;
    document.getElementById('icono-prev-mask').src = activo ? document.getElementById('icono_mask').value : ESTANDAR.mask;
    rotulo();
  }
  function rotulo() {
    var entero = desde.value === '0' && hasta.value === '100';
    recTxt.textContent = entero ? 'el logo entero' : 'del ' + desde.value + ' % al ' + hasta.value + ' % del ancho';
  }
  function armarPronto(e) {
    // «hasta» siempre a la derecha de «desde»
    if (parseInt(hasta.value, 10) - parseInt(desde.value, 10) < 5) {
      if (e && e.target === desde) hasta.value = Math.min(100, parseInt(desde.value, 10) + 5);
      else desde.value = Math.max(0, parseInt(hasta.value, 10) - 5);
      if (parseInt(hasta.value, 10) - parseInt(desde.value, 10) < 5) desde.value = parseInt(hasta.value, 10) - 5;
    }
    rotulo();
    clearTimeout(pendiente);
    pendiente = setTimeout(armar, 60);
  }

  input.addEventListener('change', function () {
    var f = this.files && this.files[0];
    if (!f || !/^image\/(png|jpeg)$/.test(f.type)) return;
    var url = URL.createObjectURL(f);
    document.querySelectorAll('.logo-muestra-clara, .logo-muestra-oscura .header-logo').forEach(function (cj) {
      var img = cj.querySelector('img');
      if (!img) {
        cj.querySelectorAll('span:not(.header-logo), svg').forEach(function (x) { x.remove(); });
        img = document.createElement('img');
        cj.insertBefore(img, cj.firstChild);
      }
      img.src = url;
      cj.classList.add('con-logo');
    });
    desde.value = 0;
    hasta.value = 100;
    cargar(url);
  });
  [usar, fondo, quitar].forEach(function (el) {
    if (el) { el.addEventListener('input', armar); el.addEventListener('change', armar); }
  });
  [desde, hasta].forEach(function (el) { el.addEventListener('input', armarPronto); el.addEventListener('change', armar); });
  document.querySelectorAll('[data-fondo]').forEach(function (b) {
    b.addEventListener('click', function () { fondo.value = b.getAttribute('data-fondo'); armar(); });
  });
  document.getElementById('icono-reset').addEventListener('click', function () { desde.value = 0; hasta.value = 100; armar(); });
  armar();
})();
</script>
<?php
layout_fin();
