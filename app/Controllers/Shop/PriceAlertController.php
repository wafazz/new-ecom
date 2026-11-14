<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Services\PriceAlertService;
use App\Services\PricingService;
use App\Services\RateLimiter;

/** "Tell me when it drops to RM X": sign-up on a product. Switched by the price_alerts module. */
final class PriceAlertController extends Controller
{
    public function store(Request $request, string $slug): void
    {
        $product = Product::publicBySlug($slug) ?? Response::abort(404);
        $back = '/products/' . $slug;
        if (RateLimiter::tooMany('price_alert', $request->ip(), 10, 10)) {
            $this->backWithErrors($back, ['price_alert' => 'Too many sign-ups from this connection. Please try again later.']);
        }
        RateLimiter::hit('price_alert', $request->ip());
        $me = Auth::user();
        $email = $request->str('email') !== '' ? $request->str('email') : (string) ($me['email'] ?? '');
        $userId = $me !== null && $me['role'] === 'member' ? (int) $me['id'] : null;
        $result = PriceAlertService::store((int) $product['id'], trim($request->str('target')), $email, $userId);
        match ($result) {
            'saved' => $this->success($back, "We'll email you when the price reaches RM" . number_format((float) $request->str('target'), 2) . '.'),
            'at_or_below' => $this->backWithErrors($back, ['price_alert' => 'The price is already at or below that. Buy it now, or choose a lower price.']),
            default => $this->backWithErrors($back, ['price_alert' => 'Enter a valid email address and a price in RM.']),
        };
    }
}
