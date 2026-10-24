<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SecretBox;

/**
 * Affiliates ask HQ to pay out part of their approved commission. The member picks the amount; HQ pays it by bank
 * transfer and records the reference. A RM1.00 transaction fee comes out of the amount, so the member receives
 * the amount less RM1.00. While a request is pending its amount is held back from the balance.
 */
final class AffiliateWithdrawalService
{
    public const FEE_CENTS = 100;
    public const FEE = '1.00';

    /** Approved commission still owed, less what pending requests hold back. @return array{owed:int, held:int, available:int} cents */
    public static function balance(int $affiliateId): array
    {
        $owed = (string) Database::query(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) FROM affiliate_commissions WHERE affiliate_id = ? AND status = 'approved'",
            [$affiliateId]
        )->fetchColumn();
        $held = (string) Database::query(
            "SELECT COALESCE(SUM(amount), 0) FROM affiliate_withdrawals WHERE affiliate_id = ? AND status = 'pending'",
            [$affiliateId]
        )->fetchColumn();
        $owedCents = PricingService::cents($owed);
        $heldCents = PricingService::cents($held);
        return ['owed' => $owedCents, 'held' => $heldCents, 'available' => max(0, $owedCents - $heldCents)];
    }

    /**
     * Ask for a payout. Checks the amount, the bank details and the balance, then holds the amount back.
     * @return array{error:?string, id:?int}
     */
    public static function request(int $affiliateId, string $amount, string $bankName, string $holder, string $accountNo): array
    {
        $fail = fn(string $message) => ['error' => $message, 'id' => null];
        $amount = trim($amount);
        if (!PricingService::isMoney($amount) || PricingService::cents($amount) <= self::FEE_CENTS) {
            return $fail('Enter an amount above RM' . self::FEE . '. The RM' . self::FEE . ' transaction fee comes out of it.');
        }
        $cents = PricingService::cents($amount);
        if (!Banks::known($bankName)) {
            return $fail('Choose the bank the money should go to.');
        }
        $holder = trim($holder);
        if (mb_strlen($holder) < 2 || mb_strlen($holder) > 190) {
            return $fail('Enter the account holder name as it appears on the bank account.');
        }
        $accountNo = str_replace(' ', '', trim($accountNo));
        if (preg_match('/^[A-Za-z0-9-]{6,30}$/', $accountNo) !== 1) {
            return $fail('Enter the bank account number (letters, numbers and dashes only).');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // One lock per affiliate, so two requests at the same moment cannot both spend the same balance.
            Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$affiliateId])->fetch();
            $open = (int) Database::query("SELECT COUNT(*) FROM affiliate_withdrawals WHERE affiliate_id = ? AND status = 'pending'", [$affiliateId])->fetchColumn();
            if ($open > 0) {
                $pdo->rollBack();
                return $fail('You already have a withdrawal waiting for HQ. Wait until it is paid before asking again.');
            }
            $available = self::balance($affiliateId)['available'];
            if ($cents > $available) {
                $pdo->rollBack();
                return $fail('You can withdraw up to RM' . number_format($available / 100, 2) . ' right now.');
            }

            $net = $cents - self::FEE_CENTS;
            Database::query(
                'INSERT INTO affiliate_withdrawals (affiliate_id, amount, fee, net, bank_name, account_holder, account_no_enc) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$affiliateId, PricingService::format($cents), self::FEE, PricingService::format($net), $bankName, $holder, SecretBox::encrypt($accountNo)]
            );
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            AuditService::log($affiliateId, 'withdrawal.request', "withdrawal {$id}: RM" . number_format($cents / 100, 2));
            return ['error' => null, 'id' => $id];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * HQ has sent the money. Takes the amount from the member's approved commissions, oldest first (a commission
     * can be paid in parts), and records the payout. @return string|null error
     */
    public static function markPaid(int $id, int $adminId, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 190) {
            return 'Enter the bank transfer reference (up to 190 characters).';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $w = Database::query('SELECT * FROM affiliate_withdrawals WHERE id = ? FOR UPDATE', [$id])->fetch();
            if (!$w || $w['status'] !== 'pending') {
                $pdo->rollBack();
                return 'This withdrawal is not waiting for payment.';
            }
            $need = PricingService::cents((string) $w['amount']);
            $rows = Database::query(
                "SELECT id, amount, paid_amount FROM affiliate_commissions WHERE affiliate_id = ? AND status = 'approved' AND paid_amount < amount ORDER BY id FOR UPDATE",
                [(int) $w['affiliate_id']]
            )->fetchAll();
            foreach ($rows as $row) {
                if ($need <= 0) {
                    break;
                }
                $remaining = PricingService::cents((string) $row['amount']) - PricingService::cents((string) $row['paid_amount']);
                $take = min($remaining, $need);
                $paidNow = PricingService::cents((string) $row['paid_amount']) + $take;
                Database::query(
                    "UPDATE affiliate_commissions SET paid_amount = ?, status = ? WHERE id = ?",
                    [PricingService::format($paidNow), $paidNow >= PricingService::cents((string) $row['amount']) ? 'paid' : 'approved', (int) $row['id']]
                );
                Database::query(
                    'INSERT INTO affiliate_withdrawal_items (withdrawal_id, commission_id, amount) VALUES (?, ?, ?)',
                    [$id, (int) $row['id'], PricingService::format($take)]
                );
                $need -= $take;
            }
            if ($need > 0) {
                $pdo->rollBack();
                return 'There is not enough approved commission to pay this. Check the affiliate\'s commissions.';
            }

            Database::query(
                "UPDATE affiliate_withdrawals SET status = 'paid', reference = ?, processed_by = ?, processed_at = NOW() WHERE id = ?",
                [$reference, $adminId, $id]
            );
            Database::query(
                'INSERT INTO affiliate_payouts (affiliate_id, amount, reference, note, paid_by) VALUES (?, ?, ?, ?, ?)',
                [(int) $w['affiliate_id'], $w['net'], $reference, 'Withdrawal #' . $id . ' (RM' . self::FEE . ' fee kept)', $adminId]
            );
            $pdo->commit();
            AuditService::log($adminId, 'withdrawal.paid', "withdrawal {$id} paid, reference {$reference}");
            return null;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Cancel a pending request and release its amount. @return string|null error */
    public static function reject(int $id, int $adminId, ?string $note): ?string
    {
        $n = Database::query(
            "UPDATE affiliate_withdrawals SET status = 'rejected', note = ?, processed_by = ?, processed_at = NOW() WHERE id = ? AND status = 'pending'",
            [$note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, $adminId, $id]
        )->rowCount();
        if ($n !== 1) {
            return 'This withdrawal is not waiting for payment.';
        }
        AuditService::log($adminId, 'withdrawal.rejected', "withdrawal {$id} rejected");
        return null;
    }

    /**
     * Withdrawals for HQ, newest first, with the account number decrypted (HQ needs it to transfer the money).
     * @return array<int,array<string,mixed>>
     */
    public static function listFor(string $status): array
    {
        $rows = Database::query(
            "SELECT w.*, u.name AS member_name, u.email AS member_email FROM affiliate_withdrawals w JOIN users u ON u.id = w.affiliate_id
             WHERE (? = '' OR w.status = ?) ORDER BY w.id DESC LIMIT 200",
            [$status, $status]
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['account_no'] = SecretBox::decrypt((string) $r['account_no_enc']) ?? '';
        }
        unset($r);
        return $rows;
    }
}
