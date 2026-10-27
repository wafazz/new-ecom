<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReviewService;

/** Review moderation for the admin and staff portals (staff need products.manage). */
final class ReviewController extends ManageController
{
    public function index(Request $request): void
    {
        $this->view('manage/reviews/index', [
            'title' => 'Reviews',
            'base' => $this->base(),
            'reviews' => ReviewService::all(),
        ], 'portal');
    }

    /** Show or hide a review. */
    public function status(Request $request, string $id): void
    {
        $status = $request->str('status');
        if (!in_array($status, ['approved', 'hidden'], true)) {
            Response::abort(422);
        }
        ReviewService::setStatus((int) $id, $status);
        $this->success($this->base() . '/reviews', $status === 'hidden' ? 'Review hidden from the shop.' : 'Review is showing again.');
    }

    public function destroy(Request $request, string $id): void
    {
        ReviewService::delete((int) $id);
        $this->success($this->base() . '/reviews', 'Review deleted.');
    }
}
