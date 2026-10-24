<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** `auth:admin` — must be logged in with exactly that role. */
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
        if ($role !== null && $user['role'] !== $role) {
            Response::abort(403);
        }
    }
}
