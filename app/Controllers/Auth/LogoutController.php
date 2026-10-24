<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;

final class LogoutController extends Controller
{
    public function logout(Request $request): void
    {
        $role = Auth::user()['role'] ?? 'member';
        Auth::logout();
        redirect(Auth::LOGIN[$role]);
    }
}
