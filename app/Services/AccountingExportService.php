<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * A journal for accounting software, built from orders and refunds. Each order is one entry:
 *   Dr  payment clearing (the amount received)            = subtotal - discount + postage
 *   Cr  sales, net of SST                                 = subtotal - discount - SST
 *   Cr  postage income                                    = postage
 *   Cr  SST payable                                       = SST included in the price
 * Discounts are already netted off the sales line. A refund reverses the sale: Dr sales refunds, Cr clearing.
 * Cancelled orders are left out. The export refuses to run if debits and credits differ.
 */
final class AccountingExportService
{
    public const ACCOUNTS = [
        'clearing_cod' => ['1010', 'Cash on delivery clearing'],
        'clearing_bank' => ['1020', 'Bank transfer clearing'],
        'clearing_billplz' => ['1030', 'Online payment clearing (Billplz)'],
        'sales' => ['4000', 'Sales (net of SST)'],
        'postage' => ['4100', 'Postage income'],
        'refunds' => ['4900', 'Sales refunds'],
        'sst' => ['2300', 'SST payable'],
    ];

    private static function clearingFor(string $method): string
    {
        return match ($method) {
            'cod' => 'clearing_cod',
            'billplz' => 'clearing_billplz',
            default => 'clearing_bank',
        };
    }

    /** @return array<int,array{date:string, ref:string, account:string, name:string, text:string, debit:float, credit:float}> */
    public static function journal(string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $end = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        $lines = [];
        $line = function (string $date, string $ref, string $key, string $text, float $debit, float $credit) use (&$lines): void {
            [$code, $name] = self::ACCOUNTS[$key];
            $lines[] = ['date' => $date, 'ref' => $ref, 'account' => $code, 'name' => $name, 'text' => $text, 'debit' => round($debit, 2), 'credit' => round($credit, 2)];
        };

        foreach (Database::query("SELECT order_no, payment_method, subtotal, discount_total, shipping_fee, tax_total, total, DATE(created_at) d FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? ORDER BY id", [$start, $end])->fetchAll() as $o) {
            $subtotal = (float) $o['subtotal'];
            $discount = (float) $o['discount_total'];
            $postage = (float) $o['shipping_fee'];
            $sst = (float) $o['tax_total'];
            $received = round($subtotal - $discount + $postage, 2);
            $text = 'Order ' . $o['order_no'];
            $line($o['d'], $o['order_no'], self::clearingFor((string) $o['payment_method']), $text, $received, 0);
            $line($o['d'], $o['order_no'], 'sales', $text, 0, round($subtotal - $discount - $sst, 2));
            $line($o['d'], $o['order_no'], 'postage', $text, 0, $postage);
            $line($o['d'], $o['order_no'], 'sst', $text, 0, $sst);
        }

        foreach (Database::query("SELECT r.id, o.order_no, o.payment_method, r.refund_amount, DATE(r.refunded_at) d FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE r.status = 'refunded' AND r.refunded_at >= ? AND r.refunded_at < ? ORDER BY r.id", [$start, $end])->fetchAll() as $r) {
            $amount = (float) $r['refund_amount'];
            $text = 'Refund ' . $r['order_no'];
            $line($r['d'], $r['order_no'], 'refunds', $text, $amount, 0);
            $line($r['d'], $r['order_no'], self::clearingFor((string) $r['payment_method']), $text, 0, $amount);
        }
        return $lines;
    }

    /** @return array{debit:float, credit:float, balanced:bool} */
    public static function totals(array $lines): array
    {
        $d = array_sum(array_column($lines, 'debit'));
        $c = array_sum(array_column($lines, 'credit'));
        return ['debit' => round($d, 2), 'credit' => round($c, 2), 'balanced' => abs($d - $c) < 0.005];
    }

    /** The CSV file, or an error when the journal does not balance (nothing is produced then). @return string|null */
    public static function csv(string $from, string $to, ?string &$error): ?string
    {
        $lines = self::journal($from, $to);
        if (!self::totals($lines)['balanced']) {
            $error = 'The journal does not balance for this period, so no file was made. Check the orders and refunds in this period.';
            return null;
        }
        $h = fopen('php://memory', 'r+');
        fputcsv($h, ['date', 'reference', 'account_code', 'account_name', 'description', 'debit', 'credit']);
        foreach ($lines as $l) {
            fputcsv($h, [$l['date'], $l['ref'], $l['account'], $l['name'], $l['text'], number_format($l['debit'], 2, '.', ''), number_format($l['credit'], 2, '.', '')]);
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);
        return $csv;
    }
}
