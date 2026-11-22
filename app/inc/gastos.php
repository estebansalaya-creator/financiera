<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Conceptos de gasto de los préstamos (sql/financiera_gastos.sql)
//   gastos_conceptos        el catálogo: nombre, IVA y valores sugeridos
//   gastos_conceptos_tipos  lo que cobra cada tipo de asociado y con qué
//                           valores (se carga en la solapa Gastos del tipo)
// Pantallas: gastos_conceptos.php (catálogo) y tipos_asociado.php?solapa=gastos.
// ---------------------------------------------------------------------

const GC_CALCULO     = ['PORCENTAJE' => '% del capital', 'FIJO' => 'Importe fijo'];
const GC_COBRO       = ['DESCUENTO' => 'Se descuenta del desembolso', 'FINANCIADO' => 'Se financia en las cuotas'];
const GC_COBRO_CORTO = ['DESCUENTO' => 'Descuento', 'FINANCIADO' => 'Financiado'];

/** «Sellado provincial» -> SELLADO_PROVINCIAL */
function gc_codigo_de(string $nombre): string
{
    $s = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
                         'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
    $s = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_'));
    return substr($s, 0, 30) ?: 'GASTO';
}

/** Fracción (0.015) -> «1,5» para el campo. */
function gc_pct_campo($f): string
{
    return $f === null || $f === '' ? '' : rtrim(rtrim(number_format((float) $f * 100, 4, ',', ''), '0'), ',');
}

function gc_monto_campo($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
}

/** El valor de una fila (concepto o gasto de un tipo): «1,5 % (mín. $ 3.000,00)» o «$ 5.000,00». */
function gc_valor_txt(array $g): string
{
    if ($g['tipo_calculo'] === 'FIJO') {
        return money($g['valor']);
    }
    $t = rtrim(rtrim(number_format((float) $g['valor'] * 100, 4, ',', '.'), '0'), ',') . ' %';
    $topes = [];
    if ($g['importe_minimo'] !== null) {
        $topes[] = 'mín. ' . money($g['importe_minimo']);
    }
    if ($g['importe_maximo'] !== null) {
        $topes[] = 'máx. ' . money($g['importe_maximo']);
    }
    return $t . ($topes ? ' (' . implode(', ', $topes) . ')' : '');
}

/** Los valores de una fila como texto para el formulario. */
function gc_valores_campos(array $g): array
{
    return [
        'tipo_calculo' => $g['tipo_calculo'],
        'valor_txt'    => $g['tipo_calculo'] === 'FIJO' ? gc_monto_campo($g['valor']) : gc_pct_campo($g['valor']),
        'minimo_txt'   => gc_monto_campo($g['importe_minimo']),
        'maximo_txt'   => gc_monto_campo($g['importe_maximo']),
        'forma_cobro'  => $g['forma_cobro'],
    ];
}

/**
 * Lee y valida los valores del formulario (tipo_calculo, valor, importe_minimo,
 * importe_maximo, forma_cobro). Devuelve [lo cargado como texto, valores para
 * la base o null, errores].
 */
function gc_valores_post(): array
{
    $f = ['tipo_calculo' => post_str('tipo_calculo'), 'valor_txt' => post_str('valor'),
          'minimo_txt' => post_str('importe_minimo'), 'maximo_txt' => post_str('importe_maximo'),
          'forma_cobro' => post_str('forma_cobro')];
    $err = [];
    $valor = leer_num($f['valor_txt']);
    $min = $f['minimo_txt'] === '' ? null : leer_num($f['minimo_txt']);
    $max = $f['maximo_txt'] === '' ? null : leer_num($f['maximo_txt']);
    if (!isset(GC_CALCULO[$f['tipo_calculo']])) {
        $err[] = 'Elegí si es un importe fijo o un % del capital.';
    }
    if (!isset(GC_COBRO[$f['forma_cobro']])) {
        $err[] = 'Elegí si se descuenta del desembolso o se financia en las cuotas.';
    }
    if ($valor === null || $valor <= 0) {
        $err[] = 'Escribí el valor (mayor a cero).';
    } elseif ($f['tipo_calculo'] === 'PORCENTAJE' && $valor >= 100) {
        $err[] = 'El % va entre 0 y 100.';
    }
    if ($f['tipo_calculo'] === 'PORCENTAJE') {
        if (($f['minimo_txt'] !== '' && ($min === null || $min < 0)) || ($f['maximo_txt'] !== '' && ($max === null || $max <= 0))) {
            $err[] = 'Los topes son importes en $ (vacíos si no hay).';
        } elseif ($min !== null && $max !== null && $max < $min) {
            $err[] = 'El importe máximo no puede ser menor que el mínimo.';
        }
    } else {
        $min = $max = null;
    }
    if ($err) {
        return [$f, null, $err];
    }
    return [$f, [
        'tipo_calculo'   => $f['tipo_calculo'],
        'valor'          => $f['tipo_calculo'] === 'FIJO' ? round($valor, 2) : round($valor / 100, 6),
        'importe_minimo' => $min !== null ? round($min, 2) : null,
        'importe_maximo' => $max !== null ? round($max, 2) : null,
        'forma_cobro'    => $f['forma_cobro'],
    ], []];
}

/**
 * Campos de los valores (cálculo, valor, topes, cobro). $f: tipo_calculo,
 * valor_txt, minimo_txt, maximo_txt, forma_cobro. Va dentro de .campos.
 */
function gc_valores_html(array $f, string $ayudaValor = ''): string
{
    $esPct = $f['tipo_calculo'] !== 'FIJO';
    return '<div class="campo" style="--span:3"><label for="tipo_calculo">Se calcula como</label>'
         . '<select id="tipo_calculo" name="tipo_calculo" data-gc-calculo>' . opciones(GC_CALCULO, $f['tipo_calculo']) . '</select></div>'
         . '<div class="campo" style="--span:2"><label for="valor">Valor <span class="req">*</span></label>'
         . '<input id="valor" name="valor" value="' . h($f['valor_txt']) . '" inputmode="decimal" class="derecha" required>'
         . '<small class="campo-ayuda" data-gc-ayuda>' . ($esPct ? '% del capital' : '$ por préstamo') . h($ayudaValor) . '</small></div>'
         . '<div class="campo" style="--span:2" data-gc-pct' . ($esPct ? '' : ' hidden') . '><label for="importe_minimo">Importe mínimo</label>'
         . '<input id="importe_minimo" name="importe_minimo" value="' . h($f['minimo_txt']) . '" inputmode="decimal" class="derecha" placeholder="sin mínimo">'
         . '<small class="campo-ayuda">$</small></div>'
         . '<div class="campo" style="--span:2" data-gc-pct' . ($esPct ? '' : ' hidden') . '><label for="importe_maximo">Importe máximo</label>'
         . '<input id="importe_maximo" name="importe_maximo" value="' . h($f['maximo_txt']) . '" inputmode="decimal" class="derecha" placeholder="sin máximo">'
         . '<small class="campo-ayuda">$</small></div>'
         . '<div class="campo" style="--span:3"><label for="forma_cobro">Cómo se cobra</label>'
         . '<select id="forma_cobro" name="forma_cobro">' . opciones(GC_COBRO, $f['forma_cobro']) . '</select></div>';
}

/** Muestra u oculta los topes según el cálculo (una vez por página). */
function gc_valores_js(): string
{
    return "<script>
(function () {
  document.querySelectorAll('[data-gc-calculo]').forEach(function (sel) {
    var f = sel.form;
    function act() {
      var pct = sel.value === 'PORCENTAJE';
      f.querySelectorAll('[data-gc-pct]').forEach(function (e) { e.hidden = !pct; });
      var a = f.querySelector('[data-gc-ayuda]');
      if (a) { a.textContent = pct ? '% del capital' : '$ por préstamo'; }
    }
    sel.addEventListener('change', act);
  });
})();
</script>";
}

/** «Sellado 1,2 % · Gastos adm. $ 5.000,00 + IVA (en cuotas)» */
function gastos_tipo_txt(array $gs): string
{
    return implode(' · ', array_map(function ($g) {
        return $g['nombre'] . ' ' . gc_valor_txt($g) . ((int) $g['lleva_iva'] ? ' + IVA' : '')
             . ($g['forma_cobro'] === 'FINANCIADO' ? ' (en cuotas)' : '');
    }, $gs));
}

/** Los gastos configurados de un tipo de asociado (activos y no), con los datos del concepto. */
function gastos_de_tipo(int $idTipo): array
{
    return q('SELECT gt.*, g.codigo, g.nombre, g.lleva_iva, g.alicuota_iva, g.orden, g.activo AS concepto_activo
                FROM gastos_conceptos_tipos gt
                JOIN gastos_conceptos g ON g.id_concepto_gasto = gt.id_concepto_gasto
               WHERE gt.id_tipo_asociado = ?
               ORDER BY g.orden, g.nombre', [$idTipo]);
}
