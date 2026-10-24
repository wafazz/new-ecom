<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\WishlistService;

final class WishlistController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('member/wishlist', ['title' => 'Wishlist', 'products' => WishlistService::products((int) Auth::user()['id'])], 'portal');
    }
}
