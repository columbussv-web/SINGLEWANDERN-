<?php
declare(strict_types=1);

/**
 * Minimaler PDF-Generator ohne Abhängigkeiten.
 * A4, Helvetica und Helvetica-Bold, WinAnsi-Kodierung (Umlaute, €, ®).
 * Koordinaten in Punkt, Ursprung oben links.
 */
final class SimplePdf
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    private int $cur = -1;

    // Zeichenbreiten (1/1000 em) für ASCII 32–126 nach den Adobe-AFM-Daten
    private const REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    // Ausgewählte WinAnsi-Zeichen oberhalb ASCII: [regular, bold]
    private const EXT = [0x80 => [556, 556], 0x84 => [333, 500], 0x93 => [333, 500], 0x96 => [556, 556], 0x97 => [1000, 1000], 0xA0 => [278, 278], 0xAE => [737, 737], 0xB7 => [278, 278], 0xC4 => [667, 722], 0xD6 => [778, 778], 0xD7 => [584, 584], 0xDC => [722, 722], 0xDF => [611, 611], 0xE4 => [556, 556], 0xE9 => [556, 556], 0xF6 => [556, 611], 0xFC => [556, 611]];

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    private static function enc(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        foreach (str_split(self::enc($s)) as $ch) {
            $o = ord($ch);
            if ($o >= 32 && $o <= 126) {
                $w += ($bold ? self::BOLD : self::REG)[$o - 32];
            } else {
                $w += (self::EXT[$o] ?? [556, 556])[$bold ? 1 : 0];
            }
        }
        return $w * $size / 1000;
    }

    /** Bricht Text an Wortgrenzen auf die angegebene Breite um. */
    public function wrap(string $s, float $max, float $size, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $s) as $para) {
            $line = '';
            foreach (preg_split('/ +/u', $para) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->width($try, $size, $bold) > $max) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0], string $align = 'L'): void
    {
        if ($align === 'R') {
            $x -= $this->width($s, $size, $bold);
        } elseif ($align === 'C') {
            $x -= $this->width($s, $size, $bold) / 2;
        }
        $t = strtr(self::enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $this->pages[$this->cur] .= sprintf(
            "BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, self::H - $y, $t
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [0.8, 0.8, 0.8], float $w = 0.6): void
    {
        $this->pages[$this->cur] .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $w, $x1, self::H - $y1, $x2, self::H - $y2
        );
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->pages[$this->cur] .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h
        );
    }

    public function output(array $meta = []): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $info = [];
        foreach ($meta as $k => $v) {
            $info[] = "/$k (" . strtr(self::enc($v), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }
        $objs[5] = '<< ' . implode(' ', $info) . ' /Producer (SINGLEWANDERN Mediabuchung) >>';
        $kids = [];
        $n = 6;
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentId);
            $objs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
