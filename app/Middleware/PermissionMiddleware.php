<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** `permission:products.edit` — admin always passes; staff need it in their permissions. */
final class PermissionMiddleware
{
    public function handle(Request $request, ?string $permission = null): void
    {
        if ($permission === null || !Auth::can($permission)) {
            Response::abort(403);
        }
    }
}
