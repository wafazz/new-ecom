<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Product;
use App\Services\ReviewService;

final class ReviewController extends Controller
{
    /** Members only (auth:member in the route). Refusals go back to the review form with the reason. */
    public function store(Request $request, string $slug): void
    {
        $product = Product::publicBySlug($slug) ?? \App\Core\Response::abort(404);
        $back = '/products/' . $slug . '#reviews';
        $check = ReviewService::validate($request->body);
        if ($check['errors'] !== []) {
            $this->backWithErrors($back, $check['errors'], $request->body);
        }
        $result = ReviewService::create((int) Auth::user()['id'], (int) $product['id'], $check['data']);
        if (is_string($result)) {
            $this->backWithErrors($back, ['form' => $result], $request->body);
        }
        $this->success($back, 'Thanks! Your review is live.');
    }
}
