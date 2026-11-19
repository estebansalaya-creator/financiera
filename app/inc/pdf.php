<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Generador mínimo de PDF sin dependencias, con el aspecto de los
// listados de gestion_adm (exportar_pdf / estado de cuenta):
//   - A4 horizontal o vertical, Helvetica con acentos (WinAnsiEncoding).
//   - PdfDoc: primitivas (texto, rectángulo, línea, medir y recortar texto).
//   - PdfReporte: encabezado con título y bajada, tarjetas de totales,
//     secciones y tablas con encabezado azul que se repite en cada página,
//     filas alternadas, fila de totales y pie «Página X de Y».
// ---------------------------------------------------------------------

final class PdfDoc
{
    /** Anchos de Helvetica (1/1000 del cuerpo) para los bytes 32..255 en WinAnsi. */
    private const ANCHO_NORMAL = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761,
        556, 278, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278,
        278, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 278, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];
    private const ANCHO_NEGRITA = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 761,
        556, 278, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278,
        278, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 278, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];

    private float $w;
    private float $h;
    /** @var string[] contenido de cada página */
    private array $paginas = [];
    private int $actual = -1;
    private bool $negrita = false;
    private float $tam = 9.0;

    public function __construct(bool $horizontal = true)
    {
        [$this->w, $this->h] = $horizontal ? [841.89, 595.28] : [595.28, 841.89];
    }

    public function ancho(): float { return $this->w; }
    public function alto(): float { return $this->h; }
    public function cantidadPaginas(): int { return count($this->paginas); }

    public function nuevaPagina(): void
    {
        $this->paginas[] = '';
        $this->actual = count($this->paginas) - 1;
    }

    public function irAPagina(int $i): void
    {
        $this->actual = $i;
    }

    public function fuente(bool $negrita, float $tam): void
    {
        $this->negrita = $negrita;
        $this->tam = $tam;
    }

    /** Rectángulo; y es el borde de arriba, medido desde arriba de la hoja. */
    public function rect(float $x, float $y, float $w, float $h, ?string $relleno, ?string $borde = null, float $grosor = 0.5): void
    {
        $op = '';
        if ($relleno !== null) {
            $op .= self::rgb($relleno) . ' rg ';
        }
        if ($borde !== null) {
            $op .= self::rgb($borde) . ' RG ' . self::n($grosor) . ' w ';
        }
        $modo = $relleno !== null && $borde !== null ? 'B' : ($relleno !== null ? 'f' : 'S');
        $this->agregar($op . self::n($x) . ' ' . self::n($this->h - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . " re $modo");
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, string $color, float $grosor = 0.5): void
    {
        $this->agregar(self::rgb($color) . ' RG ' . self::n($grosor) . ' w '
            . self::n($x1) . ' ' . self::n($this->h - $y1) . ' m ' . self::n($x2) . ' ' . self::n($this->h - $y2) . ' l S');
    }

    /**
     * Texto con la línea de base en $y (desde arriba de la hoja).
     * $alin: L (desde $x), R (termina en $x + $ancho), C (centrado en $ancho).
     */
    public function texto(float $x, float $y, string $s, string $color = '#1a2740', string $alin = 'L', float $ancho = 0): void
    {
        $b = self::a1252($s);
        if ($b === '') {
            return;
        }
        $tw = $this->anchoBytes($b);
        if ($alin === 'R') {
            $x += $ancho - $tw;
        } elseif ($alin === 'C') {
            $x += ($ancho - $tw) / 2;
        }
        $f = $this->negrita ? 'F2' : 'F1';
        $this->agregar('BT /' . $f . ' ' . self::n($this->tam) . ' Tf ' . self::rgb($color) . ' rg '
            . self::n($x) . ' ' . self::n($this->h - $y) . ' Td (' . self::esc($b) . ') Tj ET');
    }

    public function anchoTexto(string $s): float
    {
        return $this->anchoBytes(self::a1252($s));
    }

    /** Corta el texto para que entre en $ancho, terminando en «…». */
    public function recortar(string $s, float $ancho): string
    {
        if ($this->anchoTexto($s) <= $ancho) {
            return $s;
        }
        preg_match_all('/./us', $s, $m);
        $chars = $m[0];
        while ($chars && $this->anchoTexto(implode('', $chars) . '…') > $ancho) {
            array_pop($chars);
        }
        return $chars ? rtrim(implode('', $chars)) . '…' : '';
    }

    public function salida(): string
    {
        if (!$this->paginas) {
            $this->nuevaPagina();
        }
        $comprimir = function_exists('gzcompress');
        $objs = [];
        // 1 catálogo, 2 páginas, 3 y 4 fuentes; después, de a dos: página y contenido.
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->paginas as $contenido) {
            $pag = $n++;
            $cont = $n++;
            $kids[] = "$pag 0 R";
            $objs[$pag] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($this->w) . ' ' . self::n($this->h)
                        . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $cont . ' 0 R >>';
            $datos = $comprimir ? gzcompress($contenido, 6) : $contenido;
            $objs[$cont] = '<< /Length ' . strlen($datos) . ($comprimir ? ' /Filter /FlateDecode' : '') . " >>\nstream\n"
                         . $datos . "\nendstream";
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $obj) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$obj\nendobj\n";
        }
        $xref = strlen($out);
        $total = count($objs) + 1;
        $out .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    // ---- internos ----------------------------------------------------

    private function agregar(string $op): void
    {
        if ($this->actual < 0) {
            $this->nuevaPagina();
        }
        $this->paginas[$this->actual] .= $op . "\n";
    }

    private function anchoBytes(string $b): float
    {
        $tabla = $this->negrita ? self::ANCHO_NEGRITA : self::ANCHO_NORMAL;
        $suma = 0;
        $len = strlen($b);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($b[$i]);
            $suma += $o >= 32 ? $tabla[$o - 32] : 0;
        }
        return $suma * $this->tam / 1000;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
    }

    /** '#1c5a8f' -> '0.11 0.353 0.561' */
    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $p = [];
        for ($i = 0; $i < 6; $i += 2) {
            $p[] = rtrim(rtrim(sprintf('%.3F', hexdec(substr($hex, $i, 2)) / 255), '0'), '.') ?: '0';
        }
        return implode(' ', $p);
    }

    private static function esc(string $b): string
    {
        return strtr($b, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /** UTF-8 -> Windows-1252 (sin mbstring ni iconv). Lo que no existe en 1252 queda como «?». */
    public static function a1252(string $s): string
    {
        static $esp = [0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
                       0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
                       0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
                       0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
                       0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
                       0x017E => 0x9E, 0x0178 => 0x9F];
        if (!preg_match('/[\x80-\xFF]/', $s)) {
            return $s;
        }
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $out .= $s[$i];
                continue;
            }
            if (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 1;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $i += 3;
                $out .= '?';
                continue;
            } else {
                $out .= '?';
                continue;
            }
            if ($cp >= 0xA0 && $cp <= 0xFF) {
                $out .= chr($cp);
            } elseif (isset($esp[$cp])) {
                $out .= chr($esp[$cp]);
            } else {
                $out .= '?';
            }
        }
        return $out;
    }
}

final class PdfReporte
{
    // La paleta de gestion_adm (estructura.css)
    public const NAVY = '#1c5a8f', AZUL = '#1a7ac2', TEXTO = '#1a2740', TENUE = '#6b7a99',
                 BORDE = '#dde4ef', SUPERFICIE2 = '#fafbfd', CEBRA = '#f8fafd', TOTAL = '#eef3fb',
                 OK = '#166534', PELIGRO = '#b42318';

    private PdfDoc $pdf;
    private float $y = 0;
    private float $margen = 30;
    private float $pie = 34;
    private string $titulo;
    private string $bajada;
    private string $marca;
    private string $generado;

    public function __construct(string $titulo, string $bajada = '', bool $horizontal = true, string $marca = 'Financiera')
    {
        $this->pdf = new PdfDoc($horizontal);
        $this->titulo = $titulo;
        $this->bajada = $bajada;
        $this->marca = $marca;
        $this->generado = date('d/m/Y H:i');
        $this->pagina();
    }

    private function anchoUtil(): float
    {
        return $this->pdf->ancho() - 2 * $this->margen;
    }

    /** Página nueva con el encabezado de siempre. */
    private function pagina(): void
    {
        $p = $this->pdf;
        $p->nuevaPagina();
        $x = $this->margen;
        $w = $this->anchoUtil();

        $p->fuente(true, 8);
        $p->texto($x, 30, pdf_mayusculas($this->marca), self::AZUL);
        $p->fuente(false, 7.5);
        $p->texto($x, 30, 'Generado el ' . $this->generado, self::TENUE, 'R', $w);

        $p->fuente(true, 15);
        $p->texto($x, 50, $this->titulo, self::NAVY);
        $y = 50;
        if ($this->bajada !== '') {
            $p->fuente(false, 8.5);
            $y += 14;
            $p->texto($x, $y, $p->recortar($this->bajada, $w), self::TENUE);
        }
        $y += 8;
        $p->linea($x, $y, $x + $w, $y, self::NAVY, 1.5);
        $this->y = $y + 14;
    }

    /** Si no entran $alto puntos más, salta de página. Devuelve true si saltó. */
    private function lugar(float $alto): bool
    {
        if ($this->y + $alto > $this->pdf->alto() - $this->pie) {
            $this->pagina();
            return true;
        }
        return false;
    }

    /** Tarjetas de totales en una fila: [[rótulo, valor], …] */
    public function indicadores(array $items): void
    {
        $n = count($items);
        if (!$n) {
            return;
        }
        $this->lugar(46);
        $p = $this->pdf;
        $sep = 8;
        $w = ($this->anchoUtil() - $sep * ($n - 1)) / $n;
        $x = $this->margen;
        foreach ($items as [$rotulo, $valor]) {
            $p->rect($x, $this->y, $w, 38, self::SUPERFICIE2, self::BORDE, 0.6);
            $p->rect($x, $this->y, 3, 38, self::AZUL);
            $p->fuente(true, 6.5);
            $p->texto($x + 10, $this->y + 13, pdf_mayusculas($rotulo), self::TENUE);
            $p->fuente(true, 12);
            $p->texto($x + 10, $this->y + 30, $p->recortar($valor, $w - 16), self::NAVY);
            $x += $w + $sep;
        }
        $this->y += 38 + 16;
    }

    /** Título de una sección (una tabla). Se asegura de que entren el título y unas filas. */
    public function seccion(string $titulo, string $nota = ''): void
    {
        $this->lugar(70);
        $p = $this->pdf;
        $p->fuente(true, 10);
        $p->texto($this->margen, $this->y + 8, $titulo, self::NAVY);
        if ($nota !== '') {
            $tw = $p->anchoTexto($titulo);
            $p->fuente(false, 8);
            $p->texto($this->margen + $tw + 8, $this->y + 8, $nota, self::TENUE);
        }
        $this->y += 16;
    }

    /**
     * Tabla.
     * @param array      $cols  [[título, peso_de_ancho, alineación L|R|C], …]
     * @param array      $filas filas de celdas; cada celda es texto o
     *                          ['t' => texto, 'color' => '#…', 'negrita' => bool]
     * @param array|null $total celdas de la fila de totales (o null)
     * @param float      $anchoMax fracción del ancho útil (1 = todo)
     */
    public function tabla(array $cols, array $filas, ?array $total = null, float $anchoMax = 1.0): void
    {
        $p = $this->pdf;
        $wUtil = $this->anchoUtil() * $anchoMax;
        $pesos = array_sum(array_column($cols, 1));
        $anchos = array_map(function ($c) use ($wUtil, $pesos) { return $wUtil * $c[1] / $pesos; }, $cols);
        $altoEnc = 18;
        $alto = 15;
        $pad = 5;

        $encabezado = function () use ($p, $cols, $anchos, $altoEnc, $pad) {
            $x = $this->margen;
            $p->rect($x, $this->y, array_sum($anchos), $altoEnc, self::NAVY);
            $p->fuente(true, 6.8);
            foreach ($cols as $i => [$tit, , $al]) {
                $txt = $p->recortar(pdf_mayusculas($tit), $anchos[$i] - 2 * $pad);
                $p->texto($x + $pad, $this->y + 11.8, $txt, '#ffffff', $al, $anchos[$i] - 2 * $pad);
                $x += $anchos[$i];
            }
            $this->y += $altoEnc;
        };

        $fila = function (array $celdas, bool $esTotal, int $i) use ($p, $cols, $anchos, $alto, $pad) {
            $x = $this->margen;
            $wTot = array_sum($anchos);
            if ($esTotal) {
                $p->rect($x, $this->y, $wTot, $alto + 2, self::TOTAL);
                $p->linea($x, $this->y, $x + $wTot, $this->y, self::AZUL, 1.2);
            } elseif ($i % 2 === 1) {
                $p->rect($x, $this->y, $wTot, $alto, self::CEBRA);
            }
            $vacia = function ($cel) { return (is_array($cel) ? (string) ($cel['t'] ?? '') : (string) $cel) === ''; };
            foreach ($cols as $c => [, , $al]) {
                $cel = $celdas[$c] ?? '';
                $txt = is_array($cel) ? (string) ($cel['t'] ?? '') : (string) $cel;
                $color = is_array($cel) && isset($cel['color']) ? $cel['color'] : ($esTotal ? self::NAVY : self::TEXTO);
                $neg = $esTotal || (is_array($cel) && !empty($cel['negrita']));
                $p->fuente($neg, 7.6);
                // Un texto alineado a la izquierda puede ocupar las celdas vacías que lo siguen
                // (como un colspan): «TOTAL · 70 operaciones» sobre Fecha, Tipo, Apellido…
                $ancho = $anchos[$c];
                if ($al === 'L' && $txt !== '') {
                    for ($k = $c + 1; $k < count($cols) && $vacia($celdas[$k] ?? '') && $cols[$k][2] === 'L'; $k++) {
                        $ancho += $anchos[$k];
                    }
                }
                $txt = $p->recortar($txt, $ancho - 2 * $pad);
                $p->texto($x + $pad, $this->y + 10.4, $txt, $color, $al, $anchos[$c] - 2 * $pad);
                $x += $anchos[$c];
            }
            $h = $esTotal ? $alto + 2 : $alto;
            if (!$esTotal) {
                $p->linea($this->margen, $this->y + $h, $this->margen + $wTot, $this->y + $h, self::BORDE, 0.4);
            }
            $this->y += $h;
        };

        if ($this->lugar($altoEnc + $alto * 2)) {
            // saltó de página: el encabezado de la tabla va arriba
        }
        $encabezado();
        if (!$filas) {
            $p->fuente(false, 8);
            $p->texto($this->margen, $this->y + 12, 'Sin registros.', self::TENUE, 'C', array_sum($anchos));
            $this->y += 18;
        }
        foreach (array_values($filas) as $i => $f) {
            if ($this->lugar($alto)) {
                $encabezado();
            }
            $fila($f, false, $i);
        }
        if ($total !== null) {
            if ($this->lugar($alto + 2)) {
                $encabezado();
            }
            $fila($total, true, 0);
        }
        $this->y += 16;
    }

    /**
     * Grilla de celdas de color (p. ej. el historial de 24 meses del BCRA).
     * @param string $rotulo      título de la primera columna
     * @param array  $columnas    títulos de las columnas de datos
     * @param array  $filas       [[rótulo de fila, [celda, …]], …]; cada celda es
     *                            null (vacía) o ['t' => texto, 'fondo' => '#…', 'color' => '#…']
     * @param float  $anchoRotulo ancho de la primera columna en puntos
     */
    public function grilla(string $rotulo, array $columnas, array $filas, float $anchoRotulo = 150): void
    {
        $p = $this->pdf;
        $n = max(1, count($columnas));
        $wCel = ($this->anchoUtil() - $anchoRotulo) / $n;
        $alto = 14;
        $sep = 1.2;

        $encabezado = function () use ($p, $rotulo, $columnas, $anchoRotulo, $wCel) {
            $p->fuente(true, 6.5);
            $p->texto($this->margen, $this->y + 9, pdf_mayusculas($rotulo), self::TENUE);
            $x = $this->margen + $anchoRotulo;
            foreach ($columnas as $c) {
                $p->texto($x, $this->y + 9, $p->recortar($c, $wCel), self::TENUE, 'C', $wCel);
                $x += $wCel;
            }
            $this->y += 13;
        };

        $this->lugar(13 + $alto * 2);
        $encabezado();
        foreach ($filas as [$nombre, $celdas]) {
            if ($this->lugar($alto)) {
                $encabezado();
            }
            $p->fuente(false, 7.2);
            $p->texto($this->margen, $this->y + 9.8, $p->recortar((string) $nombre, $anchoRotulo - 6), self::TEXTO);
            $x = $this->margen + $anchoRotulo;
            foreach (array_values($celdas) as $cel) {
                $p->rect($x + $sep / 2, $this->y + $sep / 2, $wCel - $sep, $alto - $sep, $cel['fondo'] ?? '#f1f4f9');
                if (($cel['t'] ?? '') !== '') {
                    $p->fuente(!empty($cel['negrita']), 6.8);
                    $p->texto($x, $this->y + 9.6, $p->recortar((string) $cel['t'], $wCel - 2), $cel['color'] ?? self::TEXTO, 'C', $wCel);
                }
                $x += $wCel;
            }
            $this->y += $alto;
        }
        $this->y += 10;
    }

    /** Leyenda en una línea: [[color de fondo, color de texto, muestra, texto], …] */
    public function leyenda(array $items): void
    {
        $this->lugar(16);
        $p = $this->pdf;
        $x = $this->margen;
        foreach ($items as [$fondo, $color, $muestra, $texto]) {
            $p->rect($x, $this->y, 14, 11, $fondo);
            $p->fuente(true, 6.5);
            $p->texto($x, $this->y + 8, $muestra, $color, 'C', 14);
            $p->fuente(false, 7);
            $p->texto($x + 18, $this->y + 8, $texto, self::TENUE);
            $x += 18 + $p->anchoTexto($texto) + 14;
        }
        $this->y += 18;
    }

    public function nota(string $texto): void
    {
        $this->lugar(14);
        $this->pdf->fuente(false, 7.5);
        $this->pdf->texto($this->margen, $this->y + 6, $texto, self::TENUE);
        $this->y += 14;
    }

    public function salida(): string
    {
        $p = $this->pdf;
        $total = $p->cantidadPaginas();
        $x = $this->margen;
        $w = $this->anchoUtil();
        $yPie = $p->alto() - 20;
        for ($i = 0; $i < $total; $i++) {
            $p->irAPagina($i);
            $p->linea($x, $yPie - 10, $x + $w, $yPie - 10, self::BORDE, 0.5);
            $p->fuente(false, 7);
            $p->texto($x, $yPie, $this->marca . ' · ' . $this->titulo, self::TENUE);
            $p->texto($x, $yPie, 'Página ' . ($i + 1) . ' de ' . $total, self::TENUE, 'R', $w);
        }
        return $p->salida();
    }

    /** Lo manda al navegador. $enLinea = true lo abre en una pestaña (desde ahí se guarda o imprime). */
    public function enviar(string $archivo, bool $enLinea = true): void
    {
        $bin = $this->salida();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $archivo) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }
}

/** Mayúsculas que respetan los acentos del castellano sin depender de mbstring. */
function pdf_mayusculas(string $s): string
{
    return strtr(strtoupper($s), ['á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü']);
}
