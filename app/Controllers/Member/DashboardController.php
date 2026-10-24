<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('member/dashboard', [
            'title' => 'Overview',
            'd' => DashboardService::member((int) Auth::user()['id']),
            'scripts' => ['charts.js', 'dashboard.js'],
        ], 'portal');
    }
}
