<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;
use App\Models\User;

/**
 * Member price groups. A signed-in member in a group pays the group's price for a product when it is lower than the
 * public price. Guests and other members see the public price. Group prices are never listed to anyone else.
 */
final class MemberPriceService
{
    /** The group price for this member and product in cents, or the public price when none applies. */
    public static function priceFor(?int $userId, int $productId, int $publicCents): int
    {
        if ($userId === null || !Modules::enabled('member_prices')) {
            return $publicCents;
        }
        $p = Database::query(
            'SELECT gp.unit_price FROM users u JOIN member_group_prices gp ON gp.group_id = u.member_group_id WHERE u.id = ? AND gp.product_id = ?',
            [$userId, $productId]
        )->fetchColumn();
        if ($p === false || $p === null) {
            return $publicCents;
        }
        return min($publicCents, PricingService::cents(number_format((float) $p, 2, '.', '')));
    }

    /** The group price this member would see on a product, for the product page, or null. */
    public static function visibleFor(?int $userId, int $productId, int $publicCents): ?string
    {
        $cents = self::priceFor($userId, $productId, $publicCents);
        return $cents < $publicCents ? number_format($cents / 100, 2, '.', '') : null;
    }

    /** @return string|null error */
    public static function createGroup(string $name, int $actorId): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            return 'Give the group a name of up to 80 characters.';
        }
        try {
            Database::query('INSERT INTO member_groups (name) VALUES (?)', [$name]);
        } catch (\PDOException) {
            return 'A group with that name already exists.';
        }
        AuditService::log($actorId, 'member_group.created', $name);
        return null;
    }

    /** @return string|null error */
    public static function assign(string $email, ?int $groupId, int $actorId): ?string
    {
        $user = User::findByEmail(trim($email));
        if ($user === null || $user['role'] !== 'member') {
            return 'No member has that email address.';
        }
        if ($groupId !== null && Database::query('SELECT COUNT(*) FROM member_groups WHERE id = ?', [$groupId])->fetchColumn() == 0) {
            return 'That group does not exist.';
        }
        Database::query('UPDATE users SET member_group_id = ? WHERE id = ?', [$groupId, (int) $user['id']]);
        AuditService::log($actorId, 'member_group.assigned', "member {$user['id']} to " . ($groupId ?? 'no group'));
        return null;
    }

    /** @return string|null error. The group price must be lower than the product's public price. */
    public static function setPrice(int $groupId, int $productId, string $price, int $actorId): ?string
    {
        $product = Product::findActive($productId);
        if ($product === null || DigitalService::isDigital($product)) {
            return 'Choose a live product (digital products have no group prices).';
        }
        if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', trim($price)) || (float) $price < 0.01) {
            return 'Enter a price in RM, for example 8.00.';
        }
        $public = (float) PricingService::effective($product)['price'];
        if ((float) $price >= $public) {
            return 'A group price must be lower than the public price of RM' . number_format($public, 2) . '.';
        }
        Database::query(
            'INSERT INTO member_group_prices (group_id, product_id, unit_price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price)',
            [$groupId, $productId, number_format((float) $price, 2, '.', '')]
        );
        AuditService::log($actorId, 'member_group.price', "group {$groupId}, product {$productId}: RM" . number_format((float) $price, 2));
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function groups(): array
    {
        return Database::query('SELECT g.id, g.name, (SELECT COUNT(*) FROM users u WHERE u.member_group_id = g.id) members FROM member_groups g ORDER BY g.name')->fetchAll();
    }
}
