<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** `auth:admin` — admin or superadmin; `auth:staff` / `auth:member` / `auth:superadmin` — exactly that role. */
final class AuthMiddleware
{
    public function handle(Request $request, ?string $role = null): void
    {
        $user = Auth::user();
        if ($user === null) {
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'message' => 'Please log in again.', 'login' => true], 401);
            }
            redirect(Auth::LOGIN[$role] ?? '/login');
        }
        // The admin portal also admits a superadmin; every other portal is exact.
        $allowed = $role === 'admin' ? Auth::ADMIN_ROLES : [$role];
        if ($role !== null && !in_array($user['role'], $allowed, true)) {
            Response::abort(403);
        }
    }
}
