<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Discount codes: checking a code against a cart, using it on an order, and giving the use back when that order is cancelled. */
final class VoucherService
{
    public const TYPES = ['percent' => 'Percent off the items', 'fixed' => 'Fixed amount off the items'];

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function find(string $code): ?array
    {
        return Database::query('SELECT * FROM vouchers WHERE code = ?', [self::normalize($code)])->fetch() ?: null;
    }

    /**
     * Check a code against the item subtotal (cents). Shipping is never discounted.
     * Refusals use the same wording for unknown and inactive codes, so the answer reveals nothing about other codes.
     * @return array{ok:bool, message:string, discount:int, voucher:?array}
     */
    public static function quote(string $code, int $subtotalCents): array
    {
        $v = self::find($code);
        $refuse = fn(string $message) => ['ok' => false, 'message' => $message, 'discount' => 0, 'voucher' => null];
        if ($v === null) {
            return $refuse('That code is not valid.');
        }
        if (($reason = self::blocked($v)) !== null) {
            return $refuse($reason);
        }
        $min = PricingService::cents((string) $v['min_subtotal']);
        if ($subtotalCents < $min) {
            return $refuse('Spend RM' . number_format($min / 100, 2) . ' or more to use that code.');
        }
        $discount = $v['type'] === 'percent'
            ? intdiv($subtotalCents * PricingService::cents((string) $v['value']) + 5000, 10000)
            : PricingService::cents((string) $v['value']);
        return ['ok' => true, 'message' => 'Code applied.', 'discount' => min($discount, $subtotalCents), 'voucher' => $v];
    }

    /** Why a code cannot be used right now, or null when it can. Unknown and switched-off codes give the same answer. */
    private static function blocked(array $v): ?string
    {
        if ((int) $v['active'] !== 1) {
            return 'That code is not valid.';
        }
        $now = time();
        if ($v['starts_at'] !== null && strtotime((string) $v['starts_at']) > $now) {
            return 'That code is not active yet.';
        }
        if ($v['expires_at'] !== null && strtotime((string) $v['expires_at']) <= $now) {
            return 'That code has expired.';
        }
        if ($v['max_uses'] !== null && (int) $v['used_count'] >= (int) $v['max_uses']) {
            return 'That code has been used up.';
        }
        return null;
    }

    /**
     * Email a live code to every member who has not unsubscribed. Uses the announcement sender, so it is
     * logged as a campaign and carries the unsubscribe link. @return array|string the counts, or an error message
     */
    public static function emailMembers(int $id, int $actorId): array|string
    {
        $v = Database::query('SELECT * FROM vouchers WHERE id = ?', [$id])->fetch();
        if (!$v) {
            return 'That code does not exist.';
        }
        if (($reason = self::blocked($v)) !== null) {
            return 'This code cannot be sent: ' . $reason;
        }
        $offer = $v['type'] === 'percent'
            ? rtrim(rtrim(number_format((float) $v['value'], 2), '0'), '.') . '% off the items'
            : 'RM' . number_format((float) $v['value'], 2) . ' off the items';
        $min = (float) $v['min_subtotal'] > 0 ? 'Belanja minimum: RM' . number_format((float) $v['min_subtotal'], 2) . "\n" : '';
        $until = $v['expires_at'] !== null ? 'Sah sehingga: ' . date('d M Y, H:i', strtotime((string) $v['expires_at'])) . "\n" : '';
        $subject = 'Kod diskaun ' . $v['code'] . ' untuk anda';
        // The code holds only A-Z, 0-9, dash and underscore, so it cannot clash with the {name} and {shop} placeholders.
        $body = "Hai {name},\n\nAda tawaran khas untuk anda dari {shop}!\n\nKod: {$v['code']}\nPotongan: {$offer}\n{$min}{$until}\n"
            . "Gunakan kod ini semasa checkout. Selamat berbelanja!\n\n{shop}";
        $r = EmailService::announce($subject, $body, 'members', $actorId);
        if (is_array($r)) {
            AuditService::log($actorId, 'voucher.email', "{$v['code']} to {$r['recipients']} members: {$r['sent']} sent, {$r['failed']} failed");
        }
        return $r;
    }

    /** Count one use. Call inside the order transaction; throws if the code ran out or expired in the meantime. */
    public static function redeem(int $voucherId): void
    {
        $n = Database::query(
            'UPDATE vouchers SET used_count = used_count + 1
             WHERE id = ? AND active = 1 AND (max_uses IS NULL OR used_count < max_uses)
               AND (starts_at IS NULL OR starts_at <= NOW()) AND (expires_at IS NULL OR expires_at > NOW())',
            [$voucherId]
        )->rowCount();
        if ($n !== 1) {
            throw new CheckoutException('That code was just used up or has expired. Remove it and try again.');
        }
    }

    /** Give back the use of a cancelled order's code. */
    public static function release(int $orderId): void
    {
        Database::query('UPDATE vouchers v JOIN orders o ON o.voucher_id = v.id SET v.used_count = GREATEST(v.used_count - 1, 0) WHERE o.id = ?', [$orderId]);
    }

    /**
     * Validate and save a new code. @return array{errors:array<string,string>, id:?int}
     */
    public static function create(array $in, int $actorId): array
    {
        $errors = [];
        $code = self::normalize((string) ($in['code'] ?? ''));
        if (preg_match('/^[A-Z0-9_-]{3,40}$/', $code) !== 1) {
            $errors['code'] = 'Use 3 to 40 letters, numbers, dashes or underscores.';
        } elseif (self::find($code) !== null) {
            $errors['code'] = 'That code already exists.';
        }

        $type = (string) ($in['type'] ?? '');
        $value = trim((string) ($in['value'] ?? ''));
        if (!array_key_exists($type, self::TYPES)) {
            $errors['type'] = 'Choose a type.';
        } elseif (!PricingService::isMoney($value) || PricingService::cents($value) <= 0) {
            $errors['value'] = 'Enter an amount above 0.';
        } elseif ($type === 'percent' && PricingService::cents($value) > 10000) {
            $errors['value'] = 'A percent code can be 100 at most.';
        }

        $min = trim((string) ($in['min_subtotal'] ?? '0'));
        if ($min === '') {
            $min = '0';
        }
        if (!PricingService::isMoney($min)) {
            $errors['min_subtotal'] = 'Enter an amount, or leave it empty.';
        }

        $maxRaw = trim((string) ($in['max_uses'] ?? ''));
        $maxUses = null;
        if ($maxRaw !== '') {
            if (ctype_digit($maxRaw) && (int) $maxRaw >= 1) {
                $maxUses = (int) $maxRaw;
            } else {
                $errors['max_uses'] = 'Enter a whole number of 1 or more, or leave it empty for no limit.';
            }
        }

        $starts = self::when($in['starts_at'] ?? '', $errors, 'starts_at');
        $expires = self::when($in['expires_at'] ?? '', $errors, 'expires_at');
        if ($starts !== null && $expires !== null && $expires <= $starts) {
            $errors['expires_at'] = 'The end must be after the start.';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'id' => null];
        }

        Database::query(
            'INSERT INTO vouchers (code, type, value, min_subtotal, max_uses, starts_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$code, $type, PricingService::format(PricingService::cents($value)), PricingService::format(PricingService::cents($min)), $maxUses, $starts, $expires]
        );
        $id = (int) Database::connection()->lastInsertId();
        AuditService::log($actorId, 'voucher.create', "{$code} ({$type} {$value})");
        return ['errors' => [], 'id' => $id];
    }

    public static function setActive(int $id, bool $active, int $actorId): void
    {
        Database::query('UPDATE vouchers SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        AuditService::log($actorId, $active ? 'voucher.enable' : 'voucher.disable', "voucher {$id}");
    }

    /** Only a code nobody has used can be deleted; a used code is switched off instead. @return bool deleted */
    public static function delete(int $id, int $actorId): bool
    {
        $n = Database::query('DELETE FROM vouchers WHERE id = ? AND used_count = 0', [$id])->rowCount();
        if ($n > 0) {
            AuditService::log($actorId, 'voucher.delete', "voucher {$id}");
        }
        return $n > 0;
    }

    /** A datetime-local value ("2026-10-24T09:30") as a DATETIME string, '' as null, anything else as an error. */
    private static function when(string $raw, array &$errors, string $field): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw);
        if ($d === false) {
            $errors[$field] = 'Pick a date and time, or leave it empty.';
            return null;
        }
        return $d->format('Y-m-d H:i:s');
    }
}
