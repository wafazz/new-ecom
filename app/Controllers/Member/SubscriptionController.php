<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Services\SubscriptionService;

/** A member's subscriptions: subscribe from a product page, see them, pause, resume or cancel. */
final class SubscriptionController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('member/subscriptions', [
            'title' => 'Subscriptions',
            'subs' => SubscriptionService::forUser((int) Auth::user()['id']),
            'cadences' => SubscriptionService::CADENCES,
        ], 'portal');
    }

    public function create(Request $request, string $slug): void
    {
        $product = Product::publicBySlug($slug) ?? Response::abort(404);
        $back = '/products/' . $slug;
        $error = SubscriptionService::create((int) Auth::user()['id'], (int) $product['id'], (int) $request->input('address_id', 0), $request->str('cadence'), $request->str('method'), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success('/member/subscriptions', 'Subscription started. Your first order is made within the hour; pay it from your orders page.');
    }

    public function change(Request $request, string $id, string $action): void
    {
        $error = SubscriptionService::change((int) $id, (int) Auth::user()['id'], $action);
        if ($error !== null) {
            $this->backWithErrors('/member/subscriptions', ['form' => $error]);
        }
        $this->success('/member/subscriptions', match ($action) { 'pause' => 'Subscription paused.', 'resume' => 'Subscription resumed.', default => 'Subscription cancelled.' });
    }
}
