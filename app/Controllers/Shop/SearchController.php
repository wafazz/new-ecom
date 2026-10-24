<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Services\RateLimiter;

/** Search-as-you-type suggestions for the header search box. */
final class SearchController extends Controller
{
    public function suggest(Request $request): void
    {
        header('Cache-Control: no-store');
        $q = trim($request->str('q'));
        $empty = ['q' => $q, 'items' => [], 'total' => 0, 'all' => url('/products?q=' . rawurlencode($q))];
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            Response::json($empty);
        }
        if (RateLimiter::tooMany('suggest', $request->ip(), 120, 1)) {
            Response::json($empty, 429);
        }
        RateLimiter::hit('suggest', $request->ip());
        $res = Product::publicList($q, null, 'relevance', 1, 6);
        Response::json([
            'q' => $q, 'total' => $res['total'], 'all' => $empty['all'],
            'items' => array_map(fn($p) => [
                'name' => $p['name'], 'href' => url('/products/' . $p['slug']), 'image' => $p['image'] ? url($p['image']) : null,
                'brand' => $p['brand_name'], 'price' => ($p['from'] ? 'From ' : '') . money($p['price']), 'was' => $p['on_sale'] ? money($p['retail']) : null, 'sold_out' => $p['sold_out'],
            ], $res['rows']),
        ]);
    }
}
