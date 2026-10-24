<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/dashboard', [
            'title' => 'Dashboard',
            'dash' => DashboardService::admin($this->days($request)),
            'scripts' => ['charts.js', 'dashboard.js'],
        ], 'portal');
    }

    /** JSON for the live refresh / range switch. */
    public function data(Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json(DashboardService::admin($this->days($request)));
    }

    private function days(Request $request): int
    {
        return in_array((int) $request->input('range', 30), DashboardService::RANGES, true) ? (int) $request->input('range', 30) : 30;
    }
}
