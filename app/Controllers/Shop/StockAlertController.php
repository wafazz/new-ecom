<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Product;
use App\Services\RateLimiter;
use App\Services\StockAlertService;

/** "Tell me when it's back": sign-up on a sold-out product or option. Switched by the back_in_stock module. */
final class StockAlertController extends Controller
{
    public function store(Request $request, string $slug): void
    {
        $product = Product::publicBySlug($slug) ?? \App\Core\Response::abort(404);
        $back = '/products/' . $slug;
        if (RateLimiter::tooMany('stock_alert', $request->ip(), 10, 10)) {
            $this->backWithErrors($back, ['stock_alert' => 'Too many sign-ups from this connection. Please try again later.']);
        }
        RateLimiter::hit('stock_alert', $request->ip());

        $me = Auth::user();
        $email = $request->str('email') !== '' ? $request->str('email') : (string) ($me['email'] ?? '');
        $variantKey = (int) $request->input('variant_id', 0);
        $userId = $me !== null && $me['role'] === 'member' ? (int) $me['id'] : null;

        $result = StockAlertService::store((int) $product['id'], $variantKey, $email, $userId);
        match ($result) {
            'saved' => $this->success($back, "We'll email you once it's back in stock. You'll only get one email."),
            'in_stock' => $this->success($back, "It's in stock now. You can add it to your cart."),
            'invalid' => $this->backWithErrors($back, ['stock_alert' => 'Enter a valid email address, and choose an option if the product has them.']),
            default => $this->backWithErrors($back, ['stock_alert' => 'We could not save your sign-up. Please try again.']),
        };
    }
}
