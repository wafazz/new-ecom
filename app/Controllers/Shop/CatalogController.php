<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Services\PricingService;
use App\Services\StoreFront;
use App\Services\VariantService;
use App\Services\WishlistService;

final class CatalogController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): void
    {
        $money = fn(string $v): ?string => preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v) ? $v : null;
        $min = $money(trim($request->str('min')));
        $max = $money(trim($request->str('max')));
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            [$min, $max] = [$max, $min];
        }
        $filters = [
            'q' => trim($request->str('q')),
            'category' => trim($request->str('category')),
            'brand' => trim($request->str('brand')),
            'sort' => array_key_exists($request->str('sort'), Product::SORTS) ? $request->str('sort') : 'newest',
            'min' => $min, 'max' => $max,
            'sale' => $request->str('sale') === '1' ? '1' : '',
            'instock' => $request->str('instock') === '1' ? '1' : '',
        ];
        $page = max(1, (int) $request->input('page', 1));
        $result = Product::publicList($filters['q'], $filters['category'], $filters['sort'], $page, self::PER_PAGE, $filters['brand'], [
            'min' => $min, 'max' => $max, 'sale' => $filters['sale'] === '1', 'instock' => $filters['instock'] === '1',
        ]);

        $nav = StoreFront::nav();
        $brands = Product::publicBrands();
        $catName = null;
        foreach ($nav['categories'] as $c) {
            foreach ([$c, ...$c['children']] as $x) {
                if ($x['slug'] === $filters['category']) {
                    $catName = $x['name'];
                }
            }
        }
        $brandName = null;
        foreach ($brands as $b) {
            if ($b['slug'] === $filters['brand']) {
                $brandName = $b['name'];
            }
        }
        $data = [
            'products' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'categories' => $nav['categories'],
            'brands' => $brands,
            'catName' => $catName,
            'brandName' => $brandName,
        ];
        $partial = $request->str('partial');
        if ($partial === '1' || $partial === 'cards') { // AJAX: the results block, or only the next page of cards
            header('Cache-Control: no-store');
            $this->view($partial === 'cards' ? 'shop/_cards' : 'shop/_results', $data, null);
            return;
        }
        $heading = $filters['q'] !== '' ? 'Results for “' . $filters['q'] . '”' : ($catName ?? $brandName ?? 'All products');
        $this->view('shop/catalog', $data + [
            'title' => $heading,
            'heading' => $heading,
            'scripts' => ['catalog.js'],
        ]);
    }

    public function show(Request $request, string $slug): void
    {
        $product = Product::publicBySlug($slug) ?? Response::abort(404);
        $pid = (int) $product['id'];
        $variants = VariantService::publicVariants($pid);

        // Per-variant selling price, computed server-side and handed to the page script.
        foreach ($variants as &$v) {
            $e = PricingService::effective($product, $v);
            $v['price'] = $e['price'];
            $v['retail'] = $e['retail'];
            $v['on_sale'] = $e['on_sale'];
            unset($v['retail_price'], $v['discount_price']);
        }
        unset($v);

        $me = Auth::user();
        $images = Product::images($pid);
        $this->view('shop/product', [
            'wish' => $me !== null && $me['role'] === 'member' ? WishlistService::has((int) $me['id'], $pid) : null, // null = not a member
            'title' => $product['name'],
            'product' => $product,
            'images' => $images,
            'variants' => $variants,
            'base' => PricingService::effective($product),
            'inStock' => $variants === [] ? (int) $product['stock'] > 0 : array_sum(array_column($variants, 'stock')) > 0,
            'related' => Product::related($pid, $product['category_id'] !== null ? (int) $product['category_id'] : null, 4),
            'metaDescription' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(\App\Services\RichText::clean($product['description'])))), 0, 155),
            'metaImage' => ($images[0]['path'] ?? null),
            // A signed-in member gets a copy-ready affiliate link for this product.
            'affiliateLink' => $me !== null && $me['role'] === 'member'
                ? \App\Services\AffiliateService::productLink(\App\Services\AffiliateService::ensureCode((int) $me['id']), (string) $product['slug'])
                : null,
            'scripts' => ['product-page.js', 'copy-link.js'],
        ]);
    }
}
