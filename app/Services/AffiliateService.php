<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Models\Setting;

/**
 * Referral codes, click tracking, attribution and commissions. Money is integer cents; rates are
 * "10.00" strings (percent of margin = unit price - company base price, per line, never below 0).
 */
final class AffiliateService
{
    public const CODE_RE = '/^[A-HJ-NP-Z2-9]{8}$/';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CLICK_DEDUPE_MINUTES = 30;

    // ---- codes, clicks, cookie -------------------------------------------------------------

    public static function normalise(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return preg_match(self::CODE_RE, $code) ? $code : null;
    }

    /** The member's code, created on first use. */
    public static function ensureCode(int $userId): string
    {
        $have = Database::query('SELECT affiliate_code FROM users WHERE id = ?', [$userId])->fetchColumn();
        if (is_string($have) && $have !== '') {
            return $have;
        }
        for ($try = 0; $try < 8; $try++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, 31)];
            }
            try {
                Database::query('UPDATE users SET affiliate_code = ? WHERE id = ? AND affiliate_code IS NULL', [$code, $userId]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    continue; // code collision, draw again
                }
                throw $e;
            }
            return (string) Database::query('SELECT affiliate_code FROM users WHERE id = ?', [$userId])->fetchColumn();
        }
        throw new \RuntimeException('Could not allocate an affiliate code.');
    }

    /** An active member who owns this code, or null. */
    public static function findByCode(?string $code): ?array
    {
        $code = $code === null ? null : self::normalise($code);
        if ($code === null) {
            return null;
        }
        return Database::query("SELECT id, name, email, affiliate_code FROM users WHERE affiliate_code = ? AND role = 'member' AND status = 'active'", [$code])->fetch() ?: null;
    }

    public static function cookieDays(): int
    {
        return max(1, min(365, (int) env('AFFILIATE_COOKIE_DAYS', 30)));
    }

    /** Log the click (de-duplicated per visitor) and set the attribution cookie. Own links are ignored. */
    public static function visit(string $code, Request $request): bool
    {
        $aff = self::findByCode($code);
        $me = Auth::user();
        if ($aff === null || ($me !== null && (int) $me['id'] === (int) $aff['id'])) {
            return false;
        }

        $ipHash = hash('sha256', (string) env('APP_KEY', '') . '|' . $request->ip());
        $recent = (int) Database::query(
            'SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate_id = ? AND ip_hash = ? AND clicked_at > (NOW() - INTERVAL ' . self::CLICK_DEDUPE_MINUTES . ' MINUTE)',
            [$aff['id'], $ipHash]
        )->fetchColumn();
        if ($recent === 0) {
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            Database::query('INSERT INTO affiliate_clicks (affiliate_id, ip_hash, user_agent) VALUES (?, ?, ?)', [$aff['id'], $ipHash, $ua === '' ? null : $ua]);
        }

        $value = (string) $aff['affiliate_code'];
        $_COOKIE['ref'] = $value;
        setcookie('ref', $value, [
            'expires' => time() + self::cookieDays() * 86400,
            'path' => '/',
            'secure' => str_starts_with((string) env('APP_URL', ''), 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return true;
    }

    /** `?ref=CODE` on any public GET page behaves like /r/CODE without the redirect. */
    public static function captureFromQuery(Request $request): void
    {
        if ($request->method === 'GET' && isset($request->query['ref']) && is_string($request->query['ref'])
            && !str_starts_with($request->path, '/admin') && !str_starts_with($request->path, '/staff')) {
            self::visit($request->query['ref'], $request);
        }
    }

    // ---- attribution + rates ---------------------------------------------------------------

    /** Referrer owns the order's own account or email => no commission. */
    public static function isSelfReferral(array $affiliate, ?int $buyerId, string $buyerEmail): bool
    {
        return ($buyerId !== null && $buyerId === (int) $affiliate['id'])
            || strtolower(trim($buyerEmail)) === strtolower((string) $affiliate['email']);
    }

    public static function isRate(string $v): bool
    {
        return preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) === 1 && PricingService::cents($v) <= 10000;
    }

    public static function globalRate(): string
    {
        $v = Setting::get('commission_rate', '10.00');
        return self::isRate($v) ? PricingService::format(PricingService::cents($v)) : '10.00';
    }

    /** Product override if set, else the global rate. */
    public static function rateFor(?string $productRate): string
    {
        return $productRate !== null && self::isRate($productRate)
            ? PricingService::format(PricingService::cents($productRate))
            : self::globalRate();
    }

    /**
     * Commission base = sales price minus shipping: each line's sales (line_total), less its share of the order's
     * shipping cost, times that line's rate. Shipping is shared between lines in proportion to their sales.
     *
     * @param array<array{line_total:string,commission_rate:?string}> $items
     * @return array{base:int, amount:int} cents; amount rounds half-up per line
     */
    /** Base = what the buyer paid for the items: line sales less each line's share of the shipping and of any discount code. */
    public static function calculate(array $items, int $shippingCents = 0, int $discountCents = 0): array
    {
        $subtotal = 0;
        foreach ($items as $it) {
            $subtotal += PricingService::cents((string) $it['line_total']);
        }
        $base = 0;
        $amount = 0;
        foreach ($items as $it) {
            if ($it['commission_rate'] === null) {
                continue;
            }
            $line = PricingService::cents((string) $it['line_total']);
            $share = $subtotal > 0 ? intdiv($shippingCents * $line + intdiv($subtotal, 2), $subtotal) : 0;
            $cut = $subtotal > 0 ? intdiv($discountCents * $line + intdiv($subtotal, 2), $subtotal) : 0;
            $net = max(0, $line - $share - $cut);
            $base += $net;
            $amount += intdiv($net * PricingService::cents((string) $it['commission_rate']) + 5000, 10000);
        }
        return ['base' => $base, 'amount' => $amount];
    }

    /** Call inside the order-completion transaction. Idempotent: one row per order. */
    public static function recordCommission(array $order, array $items): void
    {
        if ($order['affiliate_id'] === null) {
            return;
        }
        $c = self::calculate($items, PricingService::cents((string) ($order['shipping_fee'] ?? '0')), PricingService::cents((string) ($order['discount_total'] ?? '0')));
        if ($c['amount'] < 1) {
            return;
        }
        Database::query(
            'INSERT IGNORE INTO affiliate_commissions (order_id, affiliate_id, base_amount, amount) VALUES (?, ?, ?, ?)',
            [$order['id'], $order['affiliate_id'], PricingService::format($c['base']), PricingService::format($c['amount'])]
        );
    }

    // ---- admin decisions + payouts ---------------------------------------------------------

    private const DECISIONS = ['approved' => ['pending'], 'rejected' => ['pending', 'approved']];

    /** @return string|null error message */
    public static function decide(int $commissionId, string $to, int $adminId): ?string
    {
        $from = self::DECISIONS[$to] ?? null;
        if ($from === null) {
            return 'Unknown decision.';
        }
        $in = implode(',', array_fill(0, count($from), '?'));
        $stmt = Database::query(
            "UPDATE affiliate_commissions SET status = ?, decided_by = ? WHERE id = ? AND status IN ({$in})",
            [$to, $adminId, $commissionId, ...$from]
        );
        return $stmt->rowCount() === 1 ? null : "This commission can't be marked {$to} from its current state.";
    }

    // Paying out is done through AffiliateWithdrawalService: a member asks for an amount, and HQ pays it.
    // The old "pay everything approved" action was removed, because it could pay money that a withdrawal already holds.

    // ---- read models -----------------------------------------------------------------------

    /** @return array{clicks:int, orders:int, pending:string, approved:string, paid:string} */
    public static function stats(int $affiliateId): array
    {
        // A commission can be paid in parts, so "approved" is what is still owed and "paid" is what has gone out.
        $sum = fn(string $sql) => (string) Database::query($sql, [$affiliateId])->fetchColumn();
        return [
            'clicks' => (int) Database::query('SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate_id = ?', [$affiliateId])->fetchColumn(),
            'orders' => (int) Database::query('SELECT COUNT(*) FROM orders WHERE affiliate_id = ?', [$affiliateId])->fetchColumn(),
            'pending' => $sum("SELECT COALESCE(SUM(amount),0) FROM affiliate_commissions WHERE affiliate_id = ? AND status = 'pending'"),
            'approved' => $sum("SELECT COALESCE(SUM(amount - paid_amount),0) FROM affiliate_commissions WHERE affiliate_id = ? AND status = 'approved'"),
            'paid' => $sum('SELECT COALESCE(SUM(paid_amount),0) FROM affiliate_commissions WHERE affiliate_id = ?'),
        ];
    }

    public static function link(string $code): string
    {
        return url('/r/' . $code);
    }
}
