<?php
// PDF writer: a valid file with correct cross-reference offsets, Helvetica widths, escaping, wrapping, pages and transparent images. No database needed.
use App\Services\Pdf\PdfDocument;

$pdf = new PdfDocument();
check('"RM" measures with the Helvetica metrics', abs($pdf->width('RM', 10) - (722 + 833) * 10 / 1000) < 0.001);
check('each digit is 556 units wide', abs($pdf->width('0123', 10) - 4 * 5.56) < 0.001);

$pdf->text(40, 60, 'Price (RM1) \\ ok', 10);
$pdf->rect(40, 80, 100, 20, [0.9, 0.9, 0.9]);
$pdf->line(40, 110, 555, 110);
$pdf->addPage();
$pdf->text(40, 60, 'Page two');
$bytes = $pdf->output();

check('it starts with the PDF header', str_starts_with($bytes, '%PDF-1.4'));
check('it ends with the end-of-file marker', str_ends_with(trim($bytes), '%%EOF'));
check('the two pages are declared', preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count 2 >>/', $bytes) === 1);
check('parentheses and backslashes in text are escaped', str_contains($bytes, 'Price \(RM1\) \\\\ ok'));

preg_match('/startxref\n(\d+)\n%%EOF/', $bytes, $m);
$xref = (int) ($m[1] ?? -1);
check('startxref points at the cross-reference table', substr($bytes, $xref, 4) === 'xref');
preg_match_all('/^(\d{10}) (\d{5}) ([nf]) $/m', substr($bytes, $xref), $entries, PREG_SET_ORDER);
$pointsOk = $entries !== [];
foreach (array_slice($entries, 1) as $id => $entry) {
    if (substr($bytes, (int) $entry[1], strlen(($id + 1) . ' 0 obj')) !== ($id + 1) . ' 0 obj') {
        $pointsOk = false;
    }
}
check('every cross-reference entry points at its object', $pointsOk && count($entries) >= 7);

$lines = $pdf->wrap('alpha beta gamma delta epsilon zeta', 10, $pdf->width('alpha beta', 10) + 1);
check('long text wraps into lines that fit the width', count($lines) >= 2 && $lines[0] === 'alpha beta');
check('a line break in text starts a new line', $pdf->wrap("one\ntwo", 10, 500) === ['one', 'two']);

$png = sys_get_temp_dir() . '/pdf-test-' . bin2hex(random_bytes(4)) . '.png';
$im = imagecreatetruecolor(40, 20);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 5, 5, 30, 15, imagecolorallocate($im, 200, 30, 30));
imagepng($im, $png);
$handle = $pdf->loadImage($png, 240);
check('a PNG with transparency loads', $handle !== null);
check('a missing image is not an error', $pdf->loadImage('/no/such/file.png') === null);
$pdf->image((int) $handle, 40, 300, 80, 80);
$withImage = $pdf->output();
check('the image is embedded with its transparency mask', str_contains($withImage, '/Subtype /Image') && str_contains($withImage, '/SMask'));
unlink($png);
