<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Payment\Payments;

/** Data every storefront page needs (header menu, footer, wishlist hearts), computed once per request. */
final class StoreFront
{
    private static ?array $nav = null;
    private static ?array $wished = null;

    /**
     * @return array{categories: array, hasSale: bool, shipping: string|null, methods: string[]}
     *   categories = top-level categories that have products, each with 'children' (sub-categories that have products)
     */
    public static function nav(): array
    {
        if (self::$nav !== null) {
            return self::$nav;
        }
        $tree = [];
        foreach (Product::publicCategories() as $c) {
            if ((int) $c['depth'] === 0) {
                $tree[(int) $c['id']] = $c + ['children' => []];
            } elseif (isset($tree[(int) $c['parent_id']])) {
                $tree[(int) $c['parent_id']]['children'][] = $c;
            } else {
                $tree[(int) $c['id']] = $c + ['children' => []]; // its parent has no products of its own listed: show it on its own
            }
        }
        return self::$nav = [
            'categories' => array_values($tree),
            'hasSale' => FlashSaleService::hasLive() || (int) Database::query("SELECT COUNT(*) FROM products p WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.discount_price IS NOT NULL AND p.discount_price < p.retail_price
                AND (p.discount_start IS NULL OR p.discount_start <= NOW()) AND (p.discount_end IS NULL OR p.discount_end >= NOW())")->fetchColumn() > 0,            'shipping' => null, // postage depends on weight and zone, so it is shown at checkout only
            'methods' => array_values(array_map(fn($m) => $m->label(), Payments::checkoutMethods())),
        ];
    }

    /** Product ids the logged-in member has saved, or null when the visitor is not a member (no hearts to fill). @return array<int,true>|null */
    public static function wished(): ?array
    {
        if (self::$wished !== null) {
            return self::$wished;
        }
        $me = Auth::user();
        return self::$wished = ($me !== null && $me['role'] === 'member') ? WishlistService::ids((int) $me['id']) : [];
    }

    public static function isMember(): bool
    {
        $me = Auth::user();
        return $me !== null && $me['role'] === 'member';
    }
}
