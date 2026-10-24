<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;

final class HomeController extends Controller
{
    public function index(Request $request): void
    {
        $new = Product::publicList('', null, 'newest', 1, 8)['rows'];
        $hero = array_values(array_filter($new, fn($p) => $p['image'] !== null));
        $this->view('shop/home', [
            'title' => '',
            'metaDescription' => 'Shop ' . config('app.name') . ': new arrivals, everyday favourites and great prices.',
            'products' => $new,
            'heroProducts' => array_slice($hero, 0, 3),
            'tiles' => Product::categoryTiles(6),
            'sale' => Product::onSale(8),
            'popular' => Product::popular(4),
            'brands' => Product::publicBrands(),
            'bleed' => true,
            'scripts' => [],
        ]);
    }

    public function health(Request $request): void
    {
        try {
            Database::query('SELECT 1');
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'down';
        }
        Response::json(['app' => 'ok', 'db' => $db]);
    }

    /** Live counts for the storefront strip (polled by live-visitors.js). */
    public function liveVisitors(Request $request): void
    {
        header('Cache-Control: no-store');
        \App\Core\Response::json(\App\Services\VisitorService::live());
    }
}
