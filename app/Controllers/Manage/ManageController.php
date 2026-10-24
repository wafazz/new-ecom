<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Models\Product;

/** Shared by the admin and staff portals; URLs are prefixed with the caller's portal. */
abstract class ManageController extends Controller
{
    protected function base(): string
    {
        return Auth::HOME[Auth::user()['role']];
    }

    protected function isAdmin(): bool
    {
        return Auth::user()['role'] === 'admin';
    }

    protected function product(string $id): array
    {
        return Product::findActive((int) $id) ?? Response::abort(404);
    }
}
