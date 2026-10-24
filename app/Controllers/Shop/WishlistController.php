<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\WishlistService;

final class WishlistController extends Controller
{
    /** Members only (auth:member in the route). Returns to the product page, or the wishlist when `next=wishlist`. */
    public function toggle(Request $request): void
    {
        $pid = (int) $request->input('product_id', 0);
        $slug = Database::query('SELECT slug FROM products WHERE id = ?', [$pid])->fetchColumn();
        $back = $request->str('next') === 'wishlist' || $slug === false ? '/member/wishlist' : '/products/' . $slug;

        $added = WishlistService::toggle((int) Auth::user()['id'], $pid);
        if ($added === null) {
            $this->backWithErrors($back, ['form' => 'That product is unavailable or your wishlist is full.']);
        }
        $message = $added ? 'Saved to your wishlist.' : 'Removed from your wishlist.';
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'added' => $added, 'message' => $message, 'count' => count(WishlistService::ids((int) Auth::user()['id']))]);
        }
        $this->success($back, $message);
    }
}
