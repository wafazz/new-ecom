<?php
declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * A small PDF writer for A4 pages: text in the standard Helvetica fonts, filled boxes, lines and PNG images.
 * No libraries. Coordinates are points from the top-left corner. Text is Windows-1252; anything else becomes "?".
 * Widths come from the standard Helvetica metrics, which Helvetica Bold matches for digits, RM and capitals.
 */
final class PdfDocument
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** Helvetica advance widths for character codes 32 to 126 (1000 units per em). */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
        334, 260, 334, 584,
    ];

    /** @var string[] content stream per page */
    private array $pages = [''];
    /** @var array<int,array{w:int,h:int,rgb:string,alpha:?string}> */
    private array $images = [];

    /** @var int the page that drawing goes to */
    private int $current = 0;

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    /** Send further drawing to an earlier page (used to add "page 2 of 3" footers once the length is known). */
    public function selectPage(int $index): void
    {
        $this->current = $index;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Width in points of $s at $size (Helvetica metrics). */
    public function width(string $s, float $size): float
    {
        $units = 0;
        foreach (self::bytes($s) as $code) {
            $units += ($code >= 32 && $code <= 126) ? self::WIDTHS[$code - 32] : 556;
        }
        return $units * $size / 1000;
    }

    /** Draw text with its baseline at $y. $align is 'left', 'right' or 'center' relative to $x. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $color = [0.10, 0.11, 0.14], string $align = 'left'): void
    {
        if ($align === 'right') {
            $x -= $this->width($s, $size);
        } elseif ($align === 'center') {
            $x -= $this->width($s, $size) / 2;
        }
        $font = $bold ? 'F2' : 'F1';
        $this->draw(sprintf('%s rg BT /%s %s Tf %s %s Td (%s) Tj ET', self::color($color), $font, self::num($size), self::num($x), self::num(self::HEIGHT - $y), self::escape(self::encode($s))));
    }

    /** Break $s into lines no wider than $maxWidth. */
    public function wrap(string $s, float $size, float $maxWidth): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) ?: [''] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->width($try, $size) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** A filled rectangle with its top-left corner at ($x, $y). */
    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->draw(sprintf('%s rg %s %s %s %s re f', self::color($fill), self::num($x), self::num(self::HEIGHT - $y - $h), self::num($w), self::num($h)));
    }

    /** A straight line between two points. */
    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [0.87, 0.89, 0.92], float $width = 0.6): void
    {
        $this->draw(sprintf('%s RG %s w %s %s m %s %s l S', self::color($color), self::num($width), self::num($x1), self::num(self::HEIGHT - $y1), self::num($x2), self::num(self::HEIGHT - $y2)));
    }

    /**
     * Load a PNG, JPEG or WebP for drawing (scaled down to fit $maxSide). Needs GD. Returns a handle for image(), or null.
     */
    public function loadImage(string $path, int $maxSide = 240): ?int
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max(1, max($w, $h)));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        $rgb = '';
        $alpha = '';
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $c = imagecolorat($dst, $x, $y);
                $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $alpha .= chr((int) round((127 - (($c >> 24) & 0x7F)) * 255 / 127));
            }
        }
        $this->images[] = ['w' => $tw, 'h' => $th, 'rgb' => (string) gzcompress($rgb), 'alpha' => (string) gzcompress($alpha)];
        return count($this->images) - 1;
    }

    /** Draw a loaded image with its top-left corner at ($x, $y), at most $w by $h. Keeps the proportions. */
    public function image(int $handle, float $x, float $y, float $w, float $h): void
    {
        $img = $this->images[$handle];
        $scale = min($w / $img['w'], $h / $img['h']);
        $dw = $img['w'] * $scale;
        $dh = $img['h'] * $scale;
        $this->draw(sprintf('q %s 0 0 %s %s %s cm /Im%d Do Q', self::num($dw), self::num($dh), self::num($x), self::num(self::HEIGHT - $y - $dh), $handle));
    }

    /** The complete PDF file. */
    public function output(): string
    {
        $objects = [1 => '', 2 => '', 3 => '', 4 => ''];
        $add = function (string $body) use (&$objects): int {
            $id = count($objects) + 1;
            $objects[$id] = $body;
            return $id;
        };

        $imageIds = [];
        foreach ($this->images as $k => $img) {
            $smask = null;
            if ($img['alpha'] !== null) {
                $smask = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream", $img['w'], $img['h'], strlen($img['alpha']), $img['alpha']));
            }
            $imageIds[$k] = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode%s /Length %d >>\nstream\n%s\nendstream", $img['w'], $img['h'], $smask ? " /SMask {$smask} 0 R" : '', strlen($img['rgb']), $img['rgb']));
        }
        $xobjects = '';
        foreach ($imageIds as $k => $id) {
            $xobjects .= "/Im{$k} {$id} 0 R ";
        }
        $resources = "<< /Font << /F1 3 0 R /F2 4 0 R >>" . ($xobjects !== '' ? " /XObject << {$xobjects}>>" : '') . " >>";

        $kids = [];
        foreach ($this->pages as $content) {
            $contentId = $add(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content));
            $kids[] = $add(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R >>', self::num(self::WIDTH), self::num(self::HEIGHT), $resources, $contentId)) . ' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf('<< /Type /Pages /Kids [ %s ] /Count %d >>', implode(' ', $kids), count($kids));
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    private function draw(string $op): void
    {
        $this->pages[$this->current] .= $op . "\n";
    }

    private static function encode(string $s): string
    {
        $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($out) ? $out : '';
    }

    /** @return int[] */
    private static function bytes(string $s): array
    {
        return array_values(unpack('C*', self::encode($s)) ?: []);
    }

    private static function escape(string $bytes): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $bytes);
    }

    private static function color(array $c): string
    {
        return implode(' ', array_map(fn($v) => self::num($v), $c));
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.') ?: '0';
    }
}
