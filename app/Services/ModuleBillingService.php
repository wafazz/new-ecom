<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Payment\BillplzGateway;

/**
 * Invoices for priced modules. A module is paid for by a period (one-time, monthly or yearly). An invoice is paid either
 * through Billplz (confirmed by the Billplz callback, checked against Billplz itself) or by a bank transfer receipt that
 * the superadmin approves. Approval extends the module's paid-until date.
 */
final class ModuleBillingService
{
    public const FOREVER = '9999-12-31';
    public const RECEIPT_MAX_BYTES = 5242880;
    private const RECEIPT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    /** The date a module is paid up to, or null when it never was. */
    public static function paidUntil(string $key): ?string
    {
        $d = Database::query('SELECT paid_until FROM module_subscriptions WHERE module_key = ?', [$key])->fetchColumn();
        return $d === false || $d === null ? null : (string) $d;
    }

    /** Free modules are always covered. A priced module is covered while its paid-until date has not passed. */
    public static function covered(string $key): bool
    {
        if (ModulePricing::isFree($key)) {
            return true;
        }
        $until = self::paidUntil($key);
        return $until !== null && $until >= date('Y-m-d');
    }

    /** The end of a period that starts on $start (Y-m-d). */
    public static function periodEnd(string $start, string $period): string
    {
        return match ($period) {
            'monthly' => date('Y-m-d', strtotime($start . ' +1 month -1 day')),
            'yearly' => date('Y-m-d', strtotime($start . ' +1 year -1 day')),
            default => self::FOREVER,
        };
    }

    /** The invoice still waiting for payment for this module, or a new one. @return array<string,mixed>|string error or invoice */
    public static function invoiceFor(string $key, int $requestedBy): array|string
    {
        if (!isset(Modules::LIST[$key])) {
            return 'Unknown module.';
        }
        if (ModulePricing::isFree($key)) {
            return 'This module is free. There is nothing to pay.';
        }
        $open = Database::query("SELECT * FROM module_invoices WHERE module_key = ? AND status IN ('unpaid','pending_review') ORDER BY id DESC LIMIT 1", [$key])->fetch();
        if ($open) {
            return $open;
        }
        $price = ModulePricing::price($key);
        $until = self::paidUntil($key);
        $start = ($until !== null && $until >= date('Y-m-d') && $until !== self::FOREVER)
            ? date('Y-m-d', strtotime($until . ' +1 day'))
            : date('Y-m-d');
        $end = self::periodEnd($start, $price['period']);
        Database::query(
            "INSERT INTO module_invoices (module_key, period, amount, period_start, period_end, status, requested_by) VALUES (?, ?, ?, ?, ?, 'unpaid', ?)",
            [$key, $price['period'], number_format($price['amount'], 2, '.', ''), $start, $end, $requestedBy]
        );
        return Database::query('SELECT * FROM module_invoices WHERE id = ?', [(int) Database::connection()->lastInsertId()])->fetch();
    }

    /**
     * Starts a Billplz payment for an invoice. @return string|null error message, or null when the bill was created
     * and the admin should be sent to $url (returned by reference through $redirect).
     */
    public static function startBillplz(int $invoiceId, array $payer, ?string &$redirect): ?string
    {
        $creds = PlatformSettings::billplzCredentials();
        if ($creds === null) {
            return 'Payment is not set up yet. The superadmin needs to add the Billplz details, or you can upload a bank receipt.';
        }
        $inv = Database::query("SELECT * FROM module_invoices WHERE id = ? AND status = 'unpaid'", [$invoiceId])->fetch();
        if (!$inv) {
            return 'This invoice is not waiting for payment.';
        }
        try {
            $bill = BillplzGateway::createInvoiceBill([
                'name' => $payer['name'], 'email' => $payer['email'], 'amount' => $inv['amount'],
                'description' => 'Module: ' . Modules::LIST[$inv['module_key']][0] . ' (' . ModulePricing::PERIODS[$inv['period']] . ')',
                'reference' => 'INV-' . $inv['id'],
                'callback' => url('/billing/billplz/callback'), 'redirect' => url('/billing/billplz/return'),
            ], $creds);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        Database::query("UPDATE module_invoices SET method = 'billplz', billplz_bill_id = ?, billplz_url = ? WHERE id = ?", [$bill['id'], $bill['url'], $inv['id']]);
        $redirect = $bill['url'];
        return null;
    }

    /**
     * The Billplz callback. Trusted only after the X-Signature verifies, and then the bill is read back from Billplz.
     * @param array<string,mixed> $post
     */
    public static function billplzCallback(array $post): bool
    {
        $creds = PlatformSettings::billplzCredentials();
        if ($creds === null || !BillplzGateway::callbackValid($post, $creds['x_signature_key'])) {
            return false;
        }
        $billId = (string) ($post['id'] ?? '');
        $inv = Database::query('SELECT * FROM module_invoices WHERE billplz_bill_id = ?', [$billId])->fetch();
        if (!$inv) {
            return false;
        }
        $bill = BillplzGateway::fetchBill($billId, $creds);
        if ($bill === null || ($bill['state'] ?? '') !== 'paid' || (int) ($bill['amount'] ?? -1) !== PricingService::cents((string) $inv['amount'])) {
            return false;
        }
        self::markPaid((int) $inv['id'], null, 'Paid through Billplz');
        return true;
    }

    /** Marks an invoice paid and extends the module's paid-until date. Safe to call twice. */
    public static function markPaid(int $invoiceId, ?int $actorId, string $note): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $inv = Database::query('SELECT * FROM module_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->fetch();
            if (!$inv || $inv['status'] === 'paid') {
                $pdo->rollBack();
                return false;
            }
            Database::query("UPDATE module_invoices SET status = 'paid', paid_at = NOW(), reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE id = ?", [$actorId, mb_substr($note, 0, 255), $invoiceId]);
            Database::query(
                'INSERT INTO module_subscriptions (module_key, paid_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE paid_until = VALUES(paid_until)',
                [$inv['module_key'], $inv['period_end']]
            );
            AuditService::log($actorId, 'module.paid', "invoice {$invoiceId}: {$inv['module_key']} until {$inv['period_end']}");
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Stores an uploaded transfer receipt for an invoice and sends it to the superadmin for review. @return string|null error */
    public static function attachReceipt(int $invoiceId, array $file, int $requestedBy): ?string
    {
        $inv = Database::query("SELECT * FROM module_invoices WHERE id = ? AND status = 'unpaid'", [$invoiceId])->fetch();
        if (!$inv) {
            return 'This invoice is not waiting for a receipt.';
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'Choose a receipt file to upload.';
        }
        if ((int) $file['size'] > self::RECEIPT_MAX_BYTES) {
            return 'The receipt is too big. Keep it under 5 MB.';
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!isset(self::RECEIPT_TYPES[(string) $type])) {
            return 'The receipt must be a JPG, PNG or PDF file.';
        }
        $dir = BASE_PATH . '/storage/billing';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return 'Could not store the receipt. Please try again.';
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::RECEIPT_TYPES[(string) $type];
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            return 'Could not store the receipt. Please try again.';
        }
        Database::query("UPDATE module_invoices SET method = 'bank_receipt', receipt_path = ?, status = 'pending_review', requested_by = ? WHERE id = ?", [$name, $requestedBy, $invoiceId]);
        return null;
    }

    /** Approves or rejects a receipt. Rejected invoices can be paid again. @return string|null error */
    public static function review(int $invoiceId, bool $approve, string $note, int $actorId): ?string
    {
        if (!$approve) {
            $n = Database::query("UPDATE module_invoices SET status = 'unpaid', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE id = ? AND status = 'pending_review'", [$actorId, mb_substr(trim($note) ?: 'Receipt not accepted', 0, 255), $invoiceId])->rowCount();
            return $n === 1 ? null : 'That invoice is not waiting for review.';
        }
        $ok = self::markPaid($invoiceId, $actorId, 'Receipt approved');
        return $ok ? null : 'That invoice is not waiting for review.';
    }

    /** Invoices waiting for a superadmin decision. */
    public static function waiting(): array
    {
        return Database::query("SELECT i.*, u.name requester FROM module_invoices i LEFT JOIN users u ON u.id = i.requested_by WHERE i.status = 'pending_review' ORDER BY i.id")->fetchAll();
    }
}
