<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Tesorería: piezas comunes de las cuentas de fondos, el libro de
// movimientos de fondos y los débitos y créditos bancarios.
// El modelo es el de gestion_adm (sql\financiera_fondos.sql lo explica):
//   movimientos_fondos           un renglón por comprobante y cuenta
//     └─ debitos_creditos_bancarios  uno por instrumento, solo cuentas banco
// El libro lo escriben solo los registradores de la base
// (sp_fondos_cobranza, sp_fondos_desembolso, sp_fondos_aporte,
// sp_fondos_pago_inversor, sp_fondos_movimiento, sp_fondos_anular),
// siempre dentro de la transacción del comprobante. Desde PHP no se
// inserta ni se borra un renglón del libro a mano.
// ---------------------------------------------------------------------

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/inversiones.php';

const TIPOS_CUENTA = ['B' => 'Banco', 'C' => 'Caja', 'R' => 'Cartera de cheques', 'V' => 'Billetera virtual',
                      'I' => 'Inversiones', 'O' => 'Otro'];
const TIPOS_CUENTA_ESTADO = ['B' => 'info', 'C' => 'ok', 'R' => 'warn', 'V' => 'info', 'I' => 'neutro', 'O' => 'neutro'];
// RECIBO es la cobranza desde financiera_recibos.sql (un renglón por recibo);
// COBRANZA, la de antes (un renglón por pago). En los filtros van juntas.
const ORIGENES_FONDOS = ['COBRANZA' => 'Cobranza', 'DESEMBOLSO' => 'Desembolso', 'APORTE' => 'Aporte de inversor',
                         'PAGO_INVERSOR' => 'Pago a inversor', 'MOVIMIENTO' => 'Entre cuentas', 'PAGO_OP' => 'Orden de pago',
                         'RECIBO' => 'Cobranza'];
const TIPOS_MOV_CUENTAS = ['ENTRE_CUENTAS' => 'Entre cuentas', 'DEPOSITO' => 'Depósito bancario',
                           'EXTRACCION' => 'Extracción bancaria', 'INVERSION' => 'Inversión'];
const INSTRUMENTOS = ['CHEQUE' => 'Cheque', 'TRANSFERENCIA' => 'Transferencia', 'DEPOSITO' => 'Depósito',
                      'DEBITO_AUTOMATICO' => 'Débito automático', 'EFECTIVO' => 'Efectivo', 'OTRO' => 'Otro'];
const MEDIOS_DESEMBOLSO = ['TRANSFERENCIA' => 'Transferencia', 'CHEQUE' => 'Cheque', 'EFECTIVO' => 'Efectivo'];

/**
 * ¿Ya se corrió sql\financiera_fondos.sql? Hasta entonces las pantallas de
 * cobro, liquidación y aportes siguen como antes (sin cuenta de fondos).
 */
function fondos_instalado(): bool
{
    return columna_existe('pagos', 'id_cuenta_fondos');
}

/**
 * Cuentas de fondos: id => fila (con el nombre del banco).
 * $moneda: solo esa moneda; $todas: también las deshabilitadas.
 */
function cuentas_fondos(?string $moneda = null, bool $todas = false): array
{
    $w = [];
    $p = [];
    if (!$todas) {
        $w[] = 'c.habilitada = 1';
    }
    if ($moneda !== null) {
        $w[] = 'c.moneda = ?';
        $p[] = $moneda;
    }
    $filas = q('SELECT c.*, b.codigo AS banco_codigo, b.nombre AS banco
                  FROM cuentas_fondos c LEFT JOIN bancos b ON b.id_banco = c.id_banco'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY c.habilitada DESC, FIELD(c.tipo, \'C\', \'B\', \'R\', \'V\', \'I\', \'O\'), c.nombre', $p);
    return array_column($filas, null, 'id_cuenta_fondos');
}

/** «Banco Nación cta. cte. (US$)» — el nombre, y la moneda si no es en pesos. */
function cuenta_fondos_txt(array $c): string
{
    return $c['nombre'] . ($c['moneda'] === 'USD' ? ' (US$)' : '') . ((int) $c['habilitada'] ? '' : ' · deshabilitada');
}

/**
 * <select> de cuentas de fondos, agrupado por tipo. Cada opción lleva
 * data-moneda y data-tipo para que la pantalla filtre o deduzca cosas.
 */
function cuenta_select(string $name, array $cuentas, $elegida, string $vacio = 'Elegí la cuenta…', array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . h($k) . ($v === true ? '' : '="' . h($v) . '"');
    }
    $out = '<select name="' . h($name) . '"' . $a . '>' . ($vacio !== '' ? '<option value="">' . h($vacio) . '</option>' : '');
    $grupo = null;
    foreach ($cuentas as $id => $c) {
        if ($c['tipo'] !== $grupo) {
            $out .= ($grupo !== null ? '</optgroup>' : '') . '<optgroup label="' . h(TIPOS_CUENTA[$c['tipo']] ?? $c['tipo']) . '">';
            $grupo = $c['tipo'];
        }
        $out .= '<option value="' . (int) $id . '" data-moneda="' . h($c['moneda']) . '" data-tipo="' . h($c['tipo']) . '"'
              . ((string) $id === (string) $elegida ? ' selected' : '') . '>' . h(cuenta_fondos_txt($c)) . '</option>';
    }
    return $out . ($grupo !== null ? '</optgroup>' : '') . '</select>';
}

/** Cuenta habitual de cada medio de cobro: medio => id_cuenta_fondos. */
function cuenta_por_medio(): array
{
    return array_map('intval', array_column(q('SELECT medio, id_cuenta_fondos FROM cuentas_fondos_medios'), 'id_cuenta_fondos', 'medio'));
}

/** La cuenta predeterminada (para desembolsar y pagar) de una moneda. */
function cuenta_predeterminada(string $moneda): ?int
{
    $f = q1('SELECT id_cuenta_fondos FROM cuentas_fondos WHERE predeterminada = 1 AND moneda = ? AND habilitada = 1', [$moneda]);
    return $f ? (int) $f['id_cuenta_fondos'] : null;
}

/** Píldora con el tipo de cuenta. */
function tipo_cuenta_badge(string $t): string
{
    return estado(TIPOS_CUENTA[$t] ?? $t, TIPOS_CUENTA_ESTADO[$t] ?? 'neutro');
}

/**
 * El tipo de un movimiento entre cuentas sale de las cuentas que toca (la
 * regla de gestion_adm): con una de inversiones es una inversión; de banco a
 * caja, una extracción; de caja o cartera a banco, un depósito.
 */
function tipo_mov_cuentas(string $tipoOrigen, string $tipoDestino): string
{
    if ($tipoOrigen === 'I' || $tipoDestino === 'I') {
        return 'INVERSION';
    }
    if ($tipoOrigen === 'B' && $tipoDestino === 'C') {
        return 'EXTRACCION';
    }
    if (in_array($tipoOrigen, ['C', 'R'], true) && $tipoDestino === 'B') {
        return 'DEPOSITO';
    }
    return 'ENTRE_CUENTAS';
}

// cbu_valido(), cbu_fmt() y cbu_banco() están en inc/ui.php (también los usan clientes, comercializadores e inversores).

/** ¿Puede abrir esa pantalla? (opción de menú o pantalla que cuelga de una). */
function puede_abrir(string $pagina): bool
{
    if (puede($pagina)) {
        return true;
    }
    foreach (PAGINAS_PERMISO[$pagina] ?? [] as $p) {
        if (puede($p)) {
            return true;
        }
    }
    return false;
}

/**
 * Dónde se ve el comprobante de un renglón del libro. La fila trae
 * tipo_origen, id_origen e id_inversion_liq (la inversión de un pago a
 * inversor). null si no hay pantalla o no tiene permiso.
 */
function origen_fondos_url(array $m): ?string
{
    $id = (int) $m['id_origen'];
    switch ($m['tipo_origen']) {
        case 'COBRANZA':
            return puede_abrir('recibo.php') ? 'recibo.php?id=' . $id : null;
        case 'RECIBO':
            return puede_abrir('recibo.php') ? 'recibo.php?r=' . $id : null;
        case 'DESEMBOLSO':
            return puede_abrir('prestamo.php') ? 'prestamo.php?id=' . $id : null;
        case 'APORTE':
            return puede_abrir('inversion.php') ? 'inversion.php?id=' . $id : null;
        case 'PAGO_INVERSOR':
            return puede_abrir('inversion.php') && !empty($m['id_inversion_liq'])
                ? 'inversion.php?id=' . (int) $m['id_inversion_liq'] . '#liquidaciones' : null;
        case 'MOVIMIENTO':
            return puede_abrir('movimientos_cuentas.php') ? 'movimientos_cuentas.php?id=' . $id : null;
        case 'PAGO_OP':
            return puede_abrir('ordenes_pago.php') ? 'ordenes_pago.php?pago=' . $id : null;
    }
    return null;
}

/** Estado de un débito / crédito bancario, dicho según el lado. */
function estado_deb_cred(array $d): string
{
    $debito = (float) $d['importe_debito'] > 0;
    switch ($d['estado']) {
        case 'EMITIDO':
            return estado($debito ? 'A debitar' : 'A acreditar', 'warn');
        case 'ENTREGADO':
            return estado('Entregado', 'info');
        case 'ACREDITADO':
            return estado($debito ? 'Debitado' : 'Acreditado', 'ok');
        case 'RECHAZADO':
            return estado('Rechazado', 'danger');
        case 'ANULADO':
            return estado('Anulado', 'neutro');
    }
    return estado((string) $d['estado']);
}

/** Importe con signo de color: negativo en rojo. */
function importe_saldo($n, string $moneda = 'ARS'): string
{
    return '<span class="' . ((float) $n < -0.004 ? 'importe-neg' : '') . '">' . dinero($n, $moneda) . '</span>';
}

/**
 * Llama a un procedure que no devuelve filas (los registradores) dentro de
 * la transacción abierta. Los errores de la base (SIGNAL) salen como
 * PDOException con su texto.
 */
function fondos_call(string $sql, array $params): void
{
    $st = db()->prepare($sql);
    $st->execute($params);
    do {
        try {
            $st->fetchAll();
        } catch (PDOException $e) {
        }
    } while ($st->nextRowset());
    $st->closeCursor();
}

/** El campo «Cuenta de fondos» de un formulario, listo para un .campos. */
function campo_cuenta_fondos(string $name, string $label, array $cuentas, $elegida, int $span = 4, string $ayuda = '',
                             bool $obligatoria = true, array $attrs = []): string
{
    $id = 'cf-' . preg_replace('/\W+/', '-', $name);
    $attrs = ['id' => $id] + ($obligatoria ? ['required' => true] : []) + $attrs;
    $out = '<div class="campo" style="--span:' . $span . '"><label for="' . h($id) . '">' . h($label)
         . ($obligatoria ? ' <span class="req">*</span>' : '') . '</label>'
         . cuenta_select($name, $cuentas, $elegida, $obligatoria ? 'Elegí la cuenta…' : 'Sin cuenta de fondos', $attrs);
    if (!$cuentas) {
        $out .= '<span class="campo-ayuda">No hay cuentas de fondos habilitadas'
              . (puede('cuentas_fondos.php') ? ': <a href="cuentas_fondos.php?nuevo=1">dar de alta una</a>' : '') . '.</span>';
    } elseif ($ayuda !== '') {
        $out .= '<span class="campo-ayuda">' . $ayuda . '</span>';
    }
    return $out . '</div>';
}
