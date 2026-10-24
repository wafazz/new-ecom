<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;

/** Logged-in users are sent to their own dashboard instead of a login/register page. */
final class GuestMiddleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        $user = Auth::user();
        if ($user !== null) {
            redirect(Auth::HOME[$user['role']]);
        }
    }
}
