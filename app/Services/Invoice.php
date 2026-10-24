<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use App\Models\Setting;
use App\Services\Payment\Payments;
use App\Services\Pdf\PdfDocument;

/** Customer invoices as PDF: the shop's details, the order's items, totals and how it is paid. */
final class Invoice
{
    /** Shop details printed on every invoice: key => label. */
    public const FIELDS = [
        'company' => 'Company name',
        'address' => 'Address',
        'phone' => 'Phone',
        'email' => 'Email',
        'reg_no' => 'Business registration no.',
        'footer' => 'Footer note',
    ];

    private const INK = [0.10, 0.11, 0.14];
    private const MUTED = [0.42, 0.45, 0.52];
    private const ACCENT = [0.18, 0.36, 0.92];
    private const LINE = [0.88, 0.90, 0.93];
    private const TINT = [0.95, 0.96, 0.98];
    private const GOOD = [0.08, 0.46, 0.22];
    private const LEFT = 40.0;
    private const RIGHT = 555.28;
    private const BREAK_AT = 690.0;

    public static function details(): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $default = $k === 'company' ? (string) config('app.name') : '';
            $out[$k] = trim((string) Setting::get('invoice.' . $k, $default));
        }
        return $out;
    }

    public static function saveDetails(array $in, int $actorId): void
    {
        foreach (array_keys(self::FIELDS) as $k) {
            Setting::set('invoice.' . $k, mb_substr(trim((string) ($in[$k] ?? '')), 0, 500));
        }
        AuditService::log($actorId, 'invoice.details', 'invoice details saved');
    }

    /** INV-2026-000123: the year the order was placed and the order's number. Unique, and stable for the order. */
    public static function number(array $order): string
    {
        return 'INV-' . date('Y', strtotime((string) $order['created_at'])) . '-' . str_pad((string) $order['id'], 6, '0', STR_PAD_LEFT);
    }

    public static function filename(array $order): string
    {
        return self::number($order) . '.pdf';
    }

    /** The invoice as PDF bytes. */
    public static function pdf(array $order): string
    {
        $pdf = new PdfDocument();
        $d = self::details();
        $items = OrderService::items((int) $order['id']);
        $paid = $order['payment_status'] === 'paid';

        $y = self::header($pdf, $d, $order, $paid);
        $y = self::parties($pdf, $order, $y + 24, $paid);
        $y = self::itemsTable($pdf, $items, $y + 26);
        $y = self::totals($pdf, $order, $paid, $y + 14);
        self::footers($pdf, $d);
        return $pdf->output();
    }

    /**
     * Send the invoice to the browser, shown inline so it can be saved or printed. It holds customer details,
     * so it is never cached. Cancelled orders have no invoice.
     */
    public static function send(array $order): void
    {
        if ($order['status'] === 'cancelled') {
            Response::abort(404);
        }
        $pdf = self::pdf($order);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . self::filename($order) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    /** Logo and shop details on the left, the invoice title and status on the right. Returns the rule's height. */
    private static function header(PdfDocument $pdf, array $d, array $order, bool $paid): float
    {
        $logo = SiteLogo::file();
        $handle = $logo !== null ? $pdf->loadImage($logo, 300) : null;
        $y = 52.0;
        if ($handle !== null) {
            $pdf->image($handle, self::LEFT, 34, 120, 40);
            $y = 90.0;
        }
        $pdf->text(self::LEFT, $y, $d['company'] !== '' ? $d['company'] : 'Shop', 15, true, self::INK);
        $y += 14;
        foreach ($pdf->wrap($d['address'], 9, 250) as $line) {
            $pdf->text(self::LEFT, $y, $line, 9, false, self::MUTED);
            $y += 12;
        }
        $contact = implode('  ·  ', array_filter([$d['phone'], $d['email'], $d['reg_no'] !== '' ? 'Reg. ' . $d['reg_no'] : '']));
        if ($contact !== '') {
            $pdf->text(self::LEFT, $y, $contact, 9, false, self::MUTED);
        }

        $pdf->text(self::RIGHT, 52, 'INVOICE', 26, true, self::ACCENT, 'right');
        $pdf->text(self::RIGHT, 72, self::number($order), 10, true, self::INK, 'right');
        $pdf->text(self::RIGHT, 88, 'Date  ' . date('j M Y', strtotime((string) $order['created_at'])), 9, false, self::MUTED, 'right');
        $pill = $paid ? [0.90, 0.97, 0.92] : [1.0, 0.95, 0.87];
        $pdf->rect(self::RIGHT - 86, 98, 86, 20, $pill);
        $pdf->text(self::RIGHT - 43, 112, $paid ? 'PAID' : 'UNPAID', 9, true, $paid ? self::GOOD : [0.55, 0.35, 0.0], 'center');

        $pdf->line(self::LEFT, 166, self::RIGHT, 166, self::ACCENT, 1.5);
        return 166.0;
    }

    /** Who it is billed to, and how it is paid. Returns the lowest line used. */
    private static function parties(PdfDocument $pdf, array $order, float $y, bool $paid): float
    {
        $pdf->text(self::LEFT, $y, 'BILLED TO', 8, true, self::MUTED);
        $pdf->text(self::LEFT, $y + 17, (string) $order['customer_name'], 11, true, self::INK);
        $address = implode("\n", array_filter([
            (string) $order['ship_line1'],
            (string) $order['ship_line2'],
            trim($order['ship_postcode'] . ' ' . $order['ship_city']) . ', ' . $order['ship_state'],
        ], fn($v) => trim((string) $v, " ,") !== ''));
        $ly = $y + 32;
        foreach ($pdf->wrap($address, 9.5, 250) as $line) {
            $pdf->text(self::LEFT, $ly, $line, 9.5, false, self::INK);
            $ly += 12.5;
        }
        $pdf->text(self::LEFT, $ly, (string) $order['customer_email'] . ($order['customer_phone'] ? '  ·  ' . $order['customer_phone'] : ''), 9, false, self::MUTED);

        $rx = 330.0;
        $pdf->text($rx, $y, 'PAYMENT', 8, true, self::MUTED);
        $pdf->text($rx, $y + 17, Payments::label((string) $order['payment_method']), 10, true, self::INK);
        $pdf->text($rx, $y + 32, 'Order ' . $order['order_no'], 9, false, self::MUTED);
        $status = $paid ? 'Paid in full' : ($order['payment_method'] === 'cod' ? 'Pay the courier on delivery' : 'Payment due');
        $pdf->text($rx, $y + 46, $status, 9.5, true, $paid ? self::GOOD : [0.55, 0.35, 0.0]);

        return max($ly, $y + 46);
    }

    /** The item table, starting a new page when the next row would not fit. Returns the lowest line used. */
    private static function itemsTable(PdfDocument $pdf, array $items, float $y): float
    {
        $header = function (float $at) use ($pdf): float {
            $pdf->rect(self::LEFT, $at, self::RIGHT - self::LEFT, 22, self::TINT);
            $pdf->text(self::LEFT + 10, $at + 15, 'ITEM', 8, true, self::MUTED);
            $pdf->text(400, $at + 15, 'QTY', 8, true, self::MUTED, 'right');
            $pdf->text(480, $at + 15, 'UNIT PRICE', 8, true, self::MUTED, 'right');
            $pdf->text(self::RIGHT - 10, $at + 15, 'AMOUNT', 8, true, self::MUTED, 'right');
            return $at + 22;
        };

        $y = $header($y);
        foreach ($items as $it) {
            $names = $pdf->wrap((string) $it['product_name'], 10, 300);
            $detail = implode('  ·  ', array_filter([$it['variant_label'] ?? '', $it['sku'] ? 'SKU ' . $it['sku'] : '']));
            $height = max(34.0, 14.0 * count($names) + ($detail !== '' ? 12.0 : 0.0) + 16.0);
            if ($y + $height > self::BREAK_AT) {
                $pdf->addPage();
                $y = $header(48.0);
            }
            $base = $y + 17;
            foreach ($names as $line) {
                $pdf->text(self::LEFT + 10, $base, $line, 10, false, self::INK);
                $base += 13;
            }
            if ($detail !== '') {
                $pdf->text(self::LEFT + 10, $base - 1, $detail, 8, false, self::MUTED);
            }
            $pdf->text(400, $y + 17, (string) $it['qty'], 10, false, self::INK, 'right');
            $pdf->text(480, $y + 17, self::money((float) $it['unit_price']), 10, false, self::INK, 'right');
            $pdf->text(self::RIGHT - 10, $y + 17, self::money((float) $it['line_total']), 10, false, self::INK, 'right');
            $y += $height;
            $pdf->line(self::LEFT, $y, self::RIGHT, $y);
        }
        return $y;
    }

    /** Payment instructions on the left, the totals on the right. Returns the lowest line used. */
    private static function totals(PdfDocument $pdf, array $order, bool $paid, float $y): float
    {
        if ($y + 170 > self::BREAK_AT) {
            $pdf->addPage();
            $y = 48.0;
        }
        $end = self::paymentNote($pdf, $order, $paid, $y);

        $x = 360.0;
        $row = function (string $label, string $value, bool $bold = false, array $color = self::INK) use ($pdf, $x, &$y): void {
            $pdf->text($x, $y, $label, $bold ? 11 : 9.5, $bold, $bold ? self::INK : self::MUTED);
            $pdf->text(self::RIGHT, $y, $value, $bold ? 11 : 9.5, $bold, $color, 'right');
            $y += 19;
        };
        $row('Subtotal', self::money((float) $order['subtotal']));
        if ((float) $order['discount_total'] > 0) {
            $row('Discount' . ($order['voucher_code'] ? ' (' . $order['voucher_code'] . ')' : ''), '-' . self::money((float) $order['discount_total']), false, self::GOOD);
        }
        $row('Shipping', (float) $order['shipping_fee'] > 0 ? self::money((float) $order['shipping_fee']) : 'Free');
        $pdf->line($x, $y - 8, self::RIGHT, $y - 8, self::ACCENT, 0.8);
        $y += 6;
        $row('Total', self::money((float) $order['total']), true);
        return max($end, $y);
    }

    /** A box explaining how to pay, depending on the method and whether it is paid. Returns its bottom edge. */
    private static function paymentNote(PdfDocument $pdf, array $order, bool $paid, float $y): float
    {
        $lines = [];
        if ($paid) {
            $lines[] = ['Paid', 10, true, self::GOOD];
            $lines[] = ['Thank you. This invoice is paid in full.', 9, false, self::INK];
        } elseif ($order['payment_method'] === 'bank_transfer') {
            $bank = PaymentSettings::bank();
            $lines[] = ['Pay by bank transfer', 10, true, self::INK];
            foreach (array_filter([$bank['name'], $bank['holder'], $bank['number'] !== '' ? 'Account no. ' . $bank['number'] : '']) as $t) {
                $lines[] = [(string) $t, 9.5, false, self::INK];
            }
            foreach ($bank['note'] !== '' ? $pdf->wrap($bank['note'], 9, 260) : [] as $t) {
                $lines[] = [$t, 9, false, self::MUTED];
            }
            $lines[] = ['Use ' . $order['order_no'] . ' as your payment reference.', 9, false, self::INK];
        } elseif ($order['payment_method'] === 'cod') {
            $lines[] = ['Cash on delivery', 10, true, self::INK];
            $lines[] = ['Pay the courier in cash when your parcel arrives.', 9, false, self::INK];
        } else {
            $lines[] = ['Payment due', 10, true, [0.55, 0.35, 0.0]];
            $lines[] = ['Pay online with the link on your order page.', 9, false, self::INK];
        }

        $height = 22.0;
        foreach ($lines as [, $size]) {
            $height += $size + 6;
        }
        $pdf->rect(self::LEFT, $y, 290, $height, self::TINT);
        $ty = $y + 18;
        foreach ($lines as [$text, $size, $bold, $color]) {
            $pdf->text(self::LEFT + 12, $ty, $text, $size, $bold, $color);
            $ty += $size + 6;
        }
        return $y + $height;
    }

    /** Footer on every page: the shop's footer note on the left, the page number on the right. */
    private static function footers(PdfDocument $pdf, array $d): void
    {
        $note = $d['footer'] !== '' ? $d['footer'] : 'Thank you for shopping with us.';
        $count = $pdf->pageCount();
        for ($i = 0; $i < $count; $i++) {
            $pdf->selectPage($i);
            $pdf->line(self::LEFT, 800, self::RIGHT, 800, self::LINE, 0.6);
            $lines = $pdf->wrap($note, 8, 400);
            $base = 814.0;
            foreach (array_slice($lines, 0, 2) as $line) {
                $pdf->text(self::LEFT, $base, $line, 8, false, self::MUTED);
                $base += 10;
            }
            $pdf->text(self::RIGHT, 814, 'Page ' . ($i + 1) . ' of ' . $count, 8, false, self::MUTED, 'right');
        }
    }

    /** RM 1,234.50 */
    public static function money(float $v): string
    {
        return 'RM ' . number_format($v, 2);
    }
}
