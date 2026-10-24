<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\CartService;

final class CartController extends Controller
{
    public function show(Request $request): void
    {
        $cart = CartService::summary();
        $this->view('shop/cart', [
            'title' => 'Your cart', 'cart' => $cart, 'scripts' => ['cart-page.js'],
            'suggest' => $cart['items'] === [] ? (\App\Models\Product::popular(4) ?: \App\Models\Product::publicList('', null, 'newest', 1, 4)['rows']) : [],
        ]);
    }

    /** Mini-cart drawer contents. */
    public function mini(Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json(['ok' => true, 'cart' => CartService::payload()]);
    }

    public function add(Request $request): void
    {
        $productId = (int) $request->input('product_id', 0);
        $variant = $request->str('variant_id');
        $variantId = $variant === '' ? null : (int) $variant;
        $qty = (int) $request->input('qty', 1);

        $error = CartService::add($productId, $variantId, $qty);
        if ($error !== null) {
            // Go back to the product page when we can find it, else the catalog.
            $slug = \App\Core\Database::query('SELECT slug FROM products WHERE id = ? AND deleted_at IS NULL', [$productId])->fetchColumn();
            $this->backWithErrors($slug ? '/products/' . $slug : '/products', ['form' => $error]);
        }
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'message' => 'Added to your cart.', 'cart' => CartService::payload()]);
        }
        $this->success('/cart', 'Added to your cart.');
    }

    public function update(Request $request): void
    {
        $qty = [];
        foreach ((array) $request->input('qty', []) as $itemId => $n) {
            $qty[(int) $itemId] = is_scalar($n) ? (int) $n : 0;
        }
        $notice = CartService::update($qty);
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'message' => $notice ?? 'Cart updated.', 'capped' => $notice !== null, 'cart' => CartService::payload()]);
        }
        Session::flash('success', $notice ?? 'Cart updated.');
        redirect('/cart');
    }

    public function remove(Request $request): void
    {
        CartService::remove((int) $request->input('item_id', 0));
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'message' => 'Item removed.', 'cart' => CartService::payload()]);
        }
        $this->success('/cart', 'Item removed.');
    }
}
