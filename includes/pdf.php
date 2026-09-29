<?php
/**
 * Minimal dependency-free PDF writer (no external libraries).
 *
 * Supports: multi-page A4, Helvetica/Helvetica-Bold, font sizes, colors,
 * headings, wrapped body text, bullet lists, horizontal rules, page numbers
 * and zlib-compressed content streams (falls back to uncompressed when
 * gzcompress() is unavailable).
 *
 * Typical use:
 *   $pdf = new SimplePdf('Help Guide');
 *   $pdf->h1('Chapter'); $pdf->body('text'); $pdf->bullet('item');
 *   $pdf->output('download.pdf');          // sends to browser
 *   $pdf->save('/path/file.pdf');          // writes to disk
 */

declare(strict_types=1);

class SimplePdf
{
    /** Page geometry (A4 portrait, points). */
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private const MARGIN_L = 56.0;
    private const MARGIN_R = 56.0;
    private const MARGIN_T = 64.0;
    private const MARGIN_B = 64.0;

    private const CHAR_W_REGULAR = 0.500; // avg Helvetica char width / font size
    private const CHAR_W_BOLD = 0.545;

    /** @var array<int, string> finished content streams (one per page) */
    private array $pages = [];
    /** @var string content stream being built for the current page */
    private string $current = '';
    /** @var float current Y position on the page (from top) */
    private float $y = self::MARGIN_T;
    /** @var int page counter */
    private int $pageNo = 0;
    /** @var string document title (metadata + footer) */
    private string $title;

    public function __construct(string $title = 'Document')
    {
        $this->title = $title;
        $this->addPage();
    }

    // ------------------------------------------------------------------
    // Public drawing API
    // ------------------------------------------------------------------

    /** New page. */
    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->pageNo++;
        $this->y = self::MARGIN_T;
    }

    /** Main heading (brand color). */
    public function h1(string $text): void
    {
        $this->ensureSpace(46);
        $this->fillText($text, 19, 'B', [13, 104, 94], 10);
        $this->rule(1.2);
    }

    /** Section heading. */
    public function h2(string $text): void
    {
        $this->ensureSpace(34);
        $this->fillText($text, 13.5, 'B', [11, 61, 61], 4);
    }

    /** Small sub-heading. */
    public function h3(string $text): void
    {
        $this->ensureSpace(26);
        $this->fillText($text, 11, 'B', [35, 60, 60], 2);
    }

    /** Wrapped paragraph text. */
    public function body(string $text, float $size = 10.5): void
    {
        $this->wrap($text, $size, 'F', [45, 55, 55]);
    }

    /** Bullet item (uses "•" marker). */
    public function bullet(string $text, float $size = 10.5): void
    {
        $lines = $this->splitLines($text, $size, 'F', self::PAGE_W - self::MARGIN_L - self::MARGIN_R - 16);
        $first = true;
        foreach ($lines as $line) {
            $this->ensureSpace($size + 5);
            if ($first) {
                $this->fillText("\xe2\x80\xa2", $size, 'B', [13, 138, 128], 0, self::MARGIN_L);
                $first = false;
            }
            $this->fillText($line, $size, 'F', [45, 55, 55], 0, self::MARGIN_L + 16);
        }
        $this->y += 3;
    }

    /** Numbered step (1., 2., ...). */
    public function step(int $n, string $text, float $size = 10.5): void
    {
        $lines = $this->splitLines($text, $size, 'F', self::PAGE_W - self::MARGIN_L - self::MARGIN_R - 18);
        $first = true;
        foreach ($lines as $line) {
            $this->ensureSpace($size + 5);
            if ($first) {
                $this->fillText($n . '.', $size, 'B', [13, 138, 128], 0, self::MARGIN_L);
                $first = false;
            }
            $this->fillText($line, $size, 'F', [45, 55, 55], 0, self::MARGIN_L + 18);
        }
        $this->y += 3;
    }

    /** Horizontal rule. */
    public function rule(float $width = 0.8): void
    {
        $this->ensureSpace(14);
        $this->y += 6;
        $x2 = self::PAGE_W - self::MARGIN_R;
        $this->current .= sprintf(
            "%s w %s G %s %s m %s %s l S 0 G\n",
            number_format($width, 2, '.', ''),
            '0.80 0.86 0.85 RG',
            number_format(self::MARGIN_L, 2, '.', ''),
            number_format(self::PAGE_H - $this->y, 2, '.', ''),
            number_format($x2, 2, '.', ''),
            number_format(self::PAGE_H - $this->y, 2, '.', '')
        );
        $this->y += 8;
    }

    /** Vertical spacing. */
    public function space(float $points): void
    {
        $this->y += $points;
    }

    /** Send to browser as download. */
    public function output(string $filename = 'document.pdf'): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        // Build exactly once (build() finalizes pages, so a second call would
        // duplicate footers and mismatch the Content-Length below).
        $data = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $data;
        exit;
    }

    /** Write to disk; returns bytes written or false. */
    public function save(string $path): bool
    {
        return file_put_contents($path, $this->build()) !== false;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Make sure $needed points are available, else page-break. */
    private function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > self::PAGE_H - self::MARGIN_B) {
            $this->addPage();
        }
    }

    /** Emit one text line with fill color. */
    private function fillText(string $text, float $size, string $font, array $rgb, float $spaceAfter, ?float $x = null): void
    {
        $x = $x ?? self::MARGIN_L;
        $this->current .= sprintf(
            "BT %s rg /F%d %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
            number_format($rgb[0] / 255, 3, '.', '') . ' ' . number_format($rgb[1] / 255, 3, '.', '') . ' ' . number_format($rgb[2] / 255, 3, '.', ''),
            $font === 'B' ? 2 : 1,
            number_format($size, 1, '.', ''),
            number_format($x, 2, '.', ''),
            number_format(self::PAGE_H - $this->y - $size, 2, '.', ''),
            $this->escape($text)
        );
        $this->y += $size + $spaceAfter;
    }

    /** Word-wrap text into lines that fit the content width. */
    private function splitLines(string $text, float $size, string $font, float $width): array
    {
        $charW = ($font === 'B' ? self::CHAR_W_BOLD : self::CHAR_W_REGULAR) * $size;
        $maxChars = max(10, (int) floor($width / $charW));
        $words = preg_split('/\s+/', trim(str_replace("\r", '', $text))) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $w) {
            $candidate = $line === '' ? $w : $line . ' ' . $w;
            if (mb_strlen($candidate) <= $maxChars) {
                $line = $candidate;
            } else {
                if ($line !== '') $lines[] = $line;
                // Hard-break overlong single words.
                while (mb_strlen($w) > $maxChars) {
                    $lines[] = mb_substr($w, 0, $maxChars);
                    $w = mb_substr($w, $maxChars);
                }
                $line = $w;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }

    /** Wrapped paragraph. */
    private function wrap(string $text, float $size, string $font, array $rgb): void
    {
        $lines = $this->splitLines($text, $size, $font, self::PAGE_W - self::MARGIN_L - self::MARGIN_R);
        foreach ($lines as $line) {
            $this->ensureSpace($size + 5);
            $this->fillText($line, $size, $font, $rgb, 5);
        }
    }

    /** Escape text for a PDF literal string. */
    private function escape(string $text): string
    {
        // Convert to CP1252 where possible; fall back to '?'. Keeps Latin scripts safe.
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
        if ($conv === false) {
            $conv = @iconv('UTF-8', 'CP1252//IGNORE', $text);
        }
        $conv = $conv !== false ? $conv : $text;
        $conv = str_replace('\\', '\\\\', $conv);
        $conv = str_replace(['(', ')'], ['\\(', '\\)'], $conv);
        // Control characters are not allowed in literal strings.
        $conv = preg_replace('/[\x00-\x1f]/', ' ', $conv) ?? $conv;
        return $conv;
    }

    /** Assemble the final PDF document string. */
    private function build(): string
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }

        // Footer with page numbers on every page.
        $footerDate = date('d/m/Y H:i');
        $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $page = $i + 1;
            $footer = $this->title . '  |  Page ' . $page . ' of ' . $total;
            $right = 'Generated ' . $footerDate;
            $footerText = sprintf(
                "BT 0.45 0.55 0.55 rg /F1 8 Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n"
                . "BT 0.45 0.55 0.55 rg /F1 8 Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
                self::MARGIN_L,
                36.0,
                $this->escape($footer),
                self::PAGE_W - self::MARGIN_R - 110,
                36.0,
                $this->escape($right)
            );
            $this->pages[$i] = $content . $footerText;
        }

        $objects = [];

        // 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        // 2: Pages tree
        $kids = [];
        for ($p = 1; $p <= $total; $p++) {
            $kids[] = (2 + $p) . ' 0 R';
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . $total . " >>";

        // 3..(2+total): Page objects. Streams and fonts follow at fixed offsets.
        $fontRegular = 3 + $total;
        $fontBold = 4 + $total;
        $streamFirst = 5 + $total;

        for ($p = 1; $p <= $total; $p++) {
            $objects[2 + $p] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                . number_format(self::PAGE_W, 2, '.', '') . ' '
                . number_format(self::PAGE_H, 2, '.', '') . "] "
                . "/Resources << /Font << /F1 {$fontRegular} 0 R /F2 {$fontBold} 0 R >> >> "
                . "/Contents " . ($streamFirst + $p - 1) . " 0 R >>";
        }

        // Fonts (built-in Helvetica — zero external dependencies)
        $objects[$fontRegular] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fontBold] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Content streams (compressed when zlib is available)
        $useZlib = function_exists('gzcompress');
        for ($p = 1; $p <= $total; $p++) {
            $raw = $this->pages[$p - 1];
            $filter = '';
            if ($useZlib) {
                $raw = gzcompress($raw, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $objects[$streamFirst + $p - 1] = "<< /Length " . strlen($raw) . $filter . " >>\nstream\n" . $raw . "\nendstream";
        }

        // Info
        $infoObj = $streamFirst + $total;
        $objects[$infoObj] = "<< /Title (" . $this->escape($this->title) . ") /Producer (Clinic CMS SimplePdf) /CreationDate (D:"
            . date('YmdHis') . ") >>";

        // Serialize with xref
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];
        $maxObj = $infoObj;
        for ($n = 1; $n <= $maxObj; $n++) {
            if (!isset($objects[$n])) continue;
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $objects[$n] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            $pdf .= isset($offsets[$n])
                ? sprintf("%010d 00000 n \n", $offsets[$n])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R /Info " . $infoObj . " 0 R >>\n"
            . "startxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }
}
