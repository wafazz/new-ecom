<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Gift cards. A shopper enters a code at checkout; the card covers up to its balance, and no more than the items.
 * The card row is locked while it is spent, so two checkouts cannot use the same money.
 */
final class GiftCardService
{
    /** No 0, O, 1 or I, so codes read out loud are not misheard. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function issue(int $cents, int $actorId): string
    {
        if ($cents < 100 || $cents > 1000000) {
            throw new \DomainException('A gift card is between RM1.00 and RM10,000.00.');
        }
        $code = '';
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (Database::query('SELECT COUNT(*) FROM gift_cards WHERE code = ?', [$code])->fetchColumn() > 0);

        Database::query('INSERT INTO gift_cards (code, initial_cents, balance_cents, created_by) VALUES (?, ?, ?, ?)', [$code, $cents, $cents, $actorId]);
        $id = (int) Database::connection()->lastInsertId();
        Database::query("INSERT INTO gift_card_ledger (card_id, kind, amount_cents) VALUES (?, 'issue', ?)", [$id, $cents]);
        AuditService::log($actorId, 'gift_card.issued', 'RM' . number_format($cents / 100, 2));
        return $code;
    }

    /** Codes are typed loosely: spaces, dashes and case do not matter. */
    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /**
     * What a code would cover on these items, for the checkout page. Nothing is locked.
     * @return array{ok:bool, message:string, cents:int, balance:int}
     */
    public static function quote(string $code, int $itemsCents): array
    {
        $card = self::find($code);
        if ($card === null) {
            return ['ok' => false, 'message' => 'That gift card code is not valid.', 'cents' => 0, 'balance' => 0];
        }
        $balance = (int) $card['balance_cents'];
        if ($balance < 1) {
            return ['ok' => false, 'message' => 'That gift card has no balance left.', 'cents' => 0, 'balance' => 0];
        }
        return ['ok' => true, 'message' => 'Gift card applied.', 'cents' => min($balance, max(0, $itemsCents)), 'balance' => $balance];
    }

    /**
     * Locks the card and returns what it covers now. Call inside the checkout transaction.
     * @return array{id:int, cents:int}
     */
    public static function reserve(string $code, int $itemsCents): array
    {
        $card = self::find($code, true);
        if ($card === null) {
            throw new \DomainException('That gift card code is not valid. Remove it and try again.');
        }
        $balance = (int) $card['balance_cents'];
        $cents = min($balance, max(0, $itemsCents));
        if ($cents < 1) {
            throw new \DomainException('That gift card has no balance left. Remove it and try again.');
        }
        return ['id' => (int) $card['id'], 'cents' => $cents];
    }

    public static function spend(int $orderId, int $cardId, int $cents): void
    {
        $n = Database::query('UPDATE gift_cards SET balance_cents = balance_cents - ? WHERE id = ? AND balance_cents >= ?', [$cents, $cardId, $cents])->rowCount();
        if ($n !== 1) {
            throw new \DomainException('That gift card no longer covers this order. Please try again.');
        }
        Database::query("INSERT INTO gift_card_ledger (card_id, kind, amount_cents, order_id) VALUES (?, 'spend', ?, ?)", [$cardId, -$cents, $orderId]);
    }

    /** A cancelled order: give back what its gift card paid, once. */
    public static function restore(int $orderId): void
    {
        $row = Database::query("SELECT card_id, -amount_cents AS cents FROM gift_card_ledger WHERE order_id = ? AND kind = 'spend'", [$orderId])->fetch();
        if (!$row) {
            return;
        }
        $already = Database::query("SELECT COUNT(*) FROM gift_card_ledger WHERE order_id = ? AND kind = 'restore'", [$orderId])->fetchColumn();
        if ((int) $already > 0) {
            return;
        }
        Database::query('UPDATE gift_cards SET balance_cents = balance_cents + ? WHERE id = ?', [(int) $row['cents'], (int) $row['card_id']]);
        Database::query("INSERT INTO gift_card_ledger (card_id, kind, amount_cents, order_id) VALUES (?, 'restore', ?, ?)", [(int) $row['card_id'], (int) $row['cents'], $orderId]);
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function listing(int $limit = 200): array
    {
        return Database::query('SELECT * FROM gift_cards ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    public static function setActive(int $id, bool $active, int $actorId): void
    {
        Database::query('UPDATE gift_cards SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        AuditService::log($actorId, $active ? 'gift_card.on' : 'gift_card.off', "card {$id}");
    }

    private static function find(string $code, bool $lock = false): ?array
    {
        $norm = self::normalise($code);
        if (strlen($norm) !== 12) {
            return null;
        }
        $formatted = implode('-', str_split($norm, 4));
        $row = Database::query('SELECT * FROM gift_cards WHERE code = ? AND active = 1' . ($lock ? ' FOR UPDATE' : ''), [$formatted])->fetch();
        return $row ?: null;
    }
}
