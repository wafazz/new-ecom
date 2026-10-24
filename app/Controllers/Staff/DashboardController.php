<?php
declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('staff/dashboard', [
            'title' => 'Dashboard',
            'dash' => $this->payload(),
            'perms' => json_decode((string) Auth::user()['permissions'], true) ?: [],
            'labels' => config('permissions'),
            'scripts' => ['charts.js', 'dashboard.js'],
        ], 'portal');
    }

    public function data(Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json($this->payload());
    }

    private function payload(): array
    {
        return DashboardService::ops('/staff', Auth::can('orders.view'), Auth::can('stock.manage')); // 'products' flag = the low-stock queue, which lives in Stock Control
    }
}
