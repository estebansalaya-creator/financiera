<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Generador mínimo de .xlsx (Office Open XML) sin dependencias.
//   - Varias hojas, cada una con título y una tabla.
//   - Autofiltro en el encabezado, encabezado fijo al hacer scroll.
//   - Formatos: texto, fecha, moneda, porcentaje, entero.
//   - Fila de totales con SUBTOTAL(), que se recalcula al filtrar.
// El zip se arma a mano (sin comprimir) para no depender de ext-zip.
// ---------------------------------------------------------------------

final class XlsxLibro
{
    /** @var XlsxHoja[] */
    private array $hojas = [];

    public function hoja(string $nombre): XlsxHoja
    {
        $nombre = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $nombre));
        $nombre = self::cortar($nombre !== '' ? $nombre : 'Hoja', 31);
        $base = $nombre;
        $n = 2;
        while ($this->existe($nombre)) {
            $nombre = self::cortar($base, 28) . ' ' . $n++;
        }
        $h = new XlsxHoja($nombre);
        $this->hojas[] = $h;
        return $h;
    }

    private function existe(string $nombre): bool
    {
        foreach ($this->hojas as $h) {
            if (strtolower($h->nombre) === strtolower($nombre)) {
                return true;
            }
        }
        return false;
    }

    /** Corta a $max caracteres sin romper UTF-8 (no depende de mbstring). */
    private static function cortar(string $s, int $max): string
    {
        return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
    }

    public function enviar(string $archivo): void
    {
        $bin = $this->generar();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $archivo) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }

    public function generar(): string
    {
        if (!$this->hojas) {
            $this->hoja('Hoja1');
        }
        $ns  = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $nsr = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $cab = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

        $archivos = [];
        $tipos = '';
        $sheets = '';
        $rels = '';
        $nombres = '';
        foreach ($this->hojas as $i => $h) {
            $n = $i + 1;
            $archivos["xl/worksheets/sheet$n.xml"] = $h->xml($i === 0);
            $tipos  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheets .= '<sheet name="' . XlsxHoja::esc($h->nombre) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            if ($h->filtro() !== null) {
                $ref = preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $h->filtro());
                $nombres .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">'
                          . XlsxHoja::esc("'" . str_replace("'", "''", $h->nombre) . "'!" . $ref) . '</definedName>';
            }
        }
        $nEstilos = count($this->hojas) + 1;

        $archivos['[Content_Types].xml'] = $cab
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $tipos . '</Types>';

        $archivos['_rels/.rels'] = $cab
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $archivos['xl/workbook.xml'] = $cab
            . "<workbook $ns $nsr><bookViews><workbookView/></bookViews>"
            . "<sheets>$sheets</sheets>"
            . ($nombres !== '' ? "<definedNames>$nombres</definedNames>" : '')
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';

        $archivos['xl/_rels/workbook.xml.rels'] = $cab
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . '<Relationship Id="rId' . $nEstilos . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $archivos['xl/styles.xml'] = $cab . self::estilos($ns);

        return self::zip($archivos);
    }

    /** Índices de estilo (cellXfs) que usa XlsxHoja. */
    public const S_NORMAL = 0, S_ENCAB = 1, S_MONEDA = 2, S_PCT = 3, S_FECHA = 4, S_ENTERO = 5,
                 S_TOT_TXT = 6, S_TOT_MONEDA = 7, S_TOT_PCT = 8, S_TOT_ENTERO = 9,
                 S_TITULO = 10, S_SUB = 11, S_ENCAB_NUM = 12, S_TOT_NUM_TXT = 13,
                 S_CENTRO = 14, S_ENCAB_CENTRO = 15;

    private static function estilos(string $ns): string
    {
        $xf = function (int $fmt, int $font, int $fill, int $border, string $alin = '') {
            return '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
                 . ($fmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '')
                 . ($fill ? ' applyFill="1"' : '') . ($border ? ' applyBorder="1"' : '')
                 . ($alin !== '' ? ' applyAlignment="1"><alignment horizontal="' . $alin . '"/></xf>' : '/>');
        };
        $xfs = [
            $xf(0, 0, 0, 0),            // 0 normal
            $xf(0, 1, 2, 1),            // 1 encabezado
            $xf(164, 0, 0, 0),          // 2 moneda
            $xf(10, 0, 0, 0),           // 3 porcentaje
            $xf(165, 0, 0, 0),          // 4 fecha
            $xf(3, 0, 0, 0),            // 5 entero
            $xf(0, 1, 0, 2),            // 6 total texto
            $xf(164, 1, 0, 2),          // 7 total moneda
            $xf(10, 1, 0, 2),           // 8 total porcentaje
            $xf(3, 1, 0, 2),            // 9 total entero
            $xf(0, 2, 0, 0),            // 10 título
            $xf(0, 3, 0, 0),            // 11 subtítulo
            $xf(0, 1, 2, 1, 'right'),   // 12 encabezado de columna numérica
            $xf(0, 1, 0, 2, 'right'),   // 13 total texto alineado a la derecha
            $xf(0, 0, 0, 0, 'center'),  // 14 texto centrado
            $xf(0, 1, 2, 1, 'center'),  // 15 encabezado centrado
        ];
        return "<styleSheet $ns>"
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;$ &quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF616E7C"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F1F8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF9AA5B1"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top style="thin"><color rgb="FF9AA5B1"/></top><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** Zip sin compresión (método "stored"): suficiente para Excel y sin extensiones. */
    private static function zip(array $archivos): string
    {
        $t = getdate();
        $hora  = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $fecha = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

        $datos = '';
        $central = '';
        foreach ($archivos as $nombre => $contenido) {
            $crc = crc32($contenido);
            $tam = strlen($contenido);
            $offset = strlen($datos);
            $datos .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $hora, $fecha, $crc, $tam, $tam, strlen($nombre), 0)
                    . $nombre . $contenido;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $hora, $fecha, $crc, $tam, $tam,
                             strlen($nombre), 0, 0, 0, 0, 0, $offset) . $nombre;
        }
        $n = count($archivos);
        return $datos . $central
             . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($datos), 0);
    }
}

final class XlsxHoja
{
    public string $nombre;

    /** @var array<int, array<int, array>> fila => [col => celda] */
    private array $celdas = [];
    private array $anchos = [];
    private ?string $filtro = null;
    private int $congelar = 0;
    private int $fila = 0;

    public function __construct(string $nombre)
    {
        $this->nombre = $nombre;
    }

    public function filtro(): ?string
    {
        return $this->filtro;
    }

    /** Título y subtítulo arriba de la tabla (deja una fila en blanco). */
    public function titulo(string $titulo, string $subtitulo = ''): self
    {
        $this->poner(++$this->fila, 0, $titulo, XlsxLibro::S_TITULO);
        if ($subtitulo !== '') {
            $this->poner(++$this->fila, 0, $subtitulo, XlsxLibro::S_SUB);
        }
        $this->fila++;
        return $this;
    }

    /**
     * Tabla con autofiltro y fila de totales.
     *
     * @param array $columnas [[título, tipo, ancho], ...]
     *                        tipo: texto | centro | fecha | moneda | pct | entero
     * @param array $filas    filas de valores crudos, en el orden de $columnas
     * @param array $totales  por índice de columna:
     *                        ['texto' => 'TOTAL'] | ['suma' => true] | ['cuenta' => true]
     *                        | ['formula' => 'IFERROR({I}/{G},0)', 'valor' => 0.17]
     *                        ({X} = celda de la columna X en la fila de totales;
     *                         {rango:X} = rango de datos de la columna X)
     */
    public function tabla(array $columnas, array $filas, array $totales = []): self
    {
        $encab = ++$this->fila;
        foreach ($columnas as $c => [$titulo, $tipo, $ancho]) {
            $num = in_array($tipo, ['moneda', 'pct', 'entero'], true);
            $this->poner($encab, $c, $titulo, $num ? XlsxLibro::S_ENCAB_NUM
                : ($tipo === 'centro' ? XlsxLibro::S_ENCAB_CENTRO : XlsxLibro::S_ENCAB));
            $this->anchos[$c] = max($this->anchos[$c] ?? 0, $ancho);
        }
        $this->congelar = $encab;

        $estilo = ['texto' => XlsxLibro::S_NORMAL, 'centro' => XlsxLibro::S_CENTRO,
                   'fecha' => XlsxLibro::S_FECHA, 'moneda' => XlsxLibro::S_MONEDA,
                   'pct' => XlsxLibro::S_PCT, 'entero' => XlsxLibro::S_ENTERO];
        $primera = $encab + 1;
        foreach ($filas as $f) {
            $r = ++$this->fila;
            foreach ($columnas as $c => [, $tipo]) {
                $v = $f[$c] ?? null;
                if ($tipo === 'fecha') {
                    $v = self::serialFecha($v);
                }
                $this->poner($r, $c, $v, $estilo[$tipo] ?? 0, !in_array($tipo, ['texto', 'centro'], true));
            }
        }
        $ultima = max($this->fila, $primera);
        $ultCol = self::col(count($columnas) - 1);
        $this->filtro = 'A' . $encab . ':' . $ultCol . $ultima;

        if ($totales) {
            $t = ++$this->fila;
            $estiloTot = ['moneda' => XlsxLibro::S_TOT_MONEDA, 'pct' => XlsxLibro::S_TOT_PCT,
                          'entero' => XlsxLibro::S_TOT_ENTERO];
            foreach ($columnas as $c => [, $tipo]) {
                $letra = self::col($c);
                $rango = "{$letra}{$primera}:{$letra}{$ultima}";
                $spec  = $totales[$c] ?? null;
                $s     = $estiloTot[$tipo] ?? XlsxLibro::S_TOT_TXT;
                if ($spec === null) {
                    $this->poner($t, $c, '', XlsxLibro::S_TOT_TXT);
                } elseif (isset($spec['texto'])) {
                    $this->poner($t, $c, $spec['texto'], ($spec['derecha'] ?? false) ? XlsxLibro::S_TOT_NUM_TXT : XlsxLibro::S_TOT_TXT);
                } elseif (!empty($spec['suma'])) {
                    $cache = array_sum(array_map(function ($f) use ($c) { return (float) ($f[$c] ?? 0); }, $filas));
                    $this->celdas[$t][$c] = ['f' => "SUBTOTAL(109,$rango)", 'v' => $cache, 's' => $s];
                } elseif (!empty($spec['cuenta'])) {
                    $this->celdas[$t][$c] = ['f' => "SUBTOTAL(103,$rango)", 'v' => count($filas), 's' => XlsxLibro::S_TOT_ENTERO];
                } elseif (isset($spec['formula'])) {
                    // {rango:X} -> X<primera>:X<ultima>   {X} -> X<fila de totales>
                    $f = preg_replace_callback('/\{rango:([A-Z]+)\}/', function ($m) use ($primera, $ultima) {
                        return $m[1] . $primera . ':' . $m[1] . $ultima;
                    }, $spec['formula']);
                    $f = preg_replace_callback('/\{([A-Z]+)\}/', function ($m) use ($t) { return $m[1] . $t; }, $f);
                    $this->celdas[$t][$c] = ['f' => $f, 'v' => $spec['valor'] ?? null, 's' => $s];
                }
            }
        }
        $this->fila++; // fila en blanco después de la tabla
        return $this;
    }

    private function poner(int $r, int $c, $v, int $s, bool $numerico = false): void
    {
        if ($numerico && $v !== null && $v !== '' && is_numeric($v)) {
            $this->celdas[$r][$c] = ['n' => $v, 's' => $s];
        } else {
            $this->celdas[$r][$c] = ['t' => (string) ($v ?? ''), 's' => $s];
        }
    }

    public function xml(bool $activa): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
             . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $out .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $out .= '<sheetViews><sheetView workbookViewId="0"' . ($activa ? ' tabSelected="1"' : '') . '>';
        if ($this->congelar > 0) {
            $out .= '<pane ySplit="' . $this->congelar . '" topLeftCell="A' . ($this->congelar + 1)
                  . '" activePane="bottomLeft" state="frozen"/>';
        }
        $out .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        if ($this->anchos) {
            ksort($this->anchos);
            $out .= '<cols>';
            foreach ($this->anchos as $c => $w) {
                $out .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $out .= '</cols>';
        }

        $out .= '<sheetData>';
        ksort($this->celdas);
        foreach ($this->celdas as $r => $cols) {
            ksort($cols);
            $out .= '<row r="' . $r . '">';
            foreach ($cols as $c => $cel) {
                $ref = self::col($c) . $r;
                $s = ' s="' . $cel['s'] . '"';
                if (isset($cel['f'])) {
                    $texto = $cel['v'] !== null && !is_numeric($cel['v']);
                    $out .= '<c r="' . $ref . '"' . $s . ($texto ? ' t="str"' : '') . '><f>' . self::esc($cel['f']) . '</f>'
                          . ($cel['v'] === null ? '' : '<v>' . ($texto ? self::esc((string) $cel['v']) : self::numero($cel['v'])) . '</v>')
                          . '</c>';
                } elseif (isset($cel['n'])) {
                    $out .= '<c r="' . $ref . '"' . $s . '><v>' . self::numero($cel['n']) . '</v></c>';
                } elseif ($cel['t'] === '') {
                    $out .= '<c r="' . $ref . '"' . $s . '/>';
                } else {
                    $out .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                          . self::esc($cel['t']) . '</t></is></c>';
                }
            }
            $out .= '</row>';
        }
        $out .= '</sheetData>';

        if ($this->filtro !== null) {
            $out .= '<autoFilter ref="' . $this->filtro . '"/>';
        }
        $out .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
              . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        return $out . '</worksheet>';
    }

    // ---- utilidades -------------------------------------------------

    /** 0 -> A, 25 -> Z, 26 -> AA */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    public static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function numero($v): string
    {
        if (is_string($v) && is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;
        return (floor($f) == $f && abs($f) < 1e15) ? (string) (int) $f : rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    /** 'AAAA-MM-DD' -> número de serie de Excel (sistema 1900). */
    private static function serialFecha($iso)
    {
        if (!is_string($iso) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return $iso;
        }
        return intdiv(gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), 86400) + 25569;
    }
}
