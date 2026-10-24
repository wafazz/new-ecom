<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\VisitorService;

/** Unique visitors (today, last 7 and 30 days, overall) and who is online now. HQ only. */
final class VisitorsController extends Controller
{
    public function index(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/visitors', [
            'title' => 'Visitors',
            'stats' => VisitorService::cachedStats(),
            'online' => VisitorService::ONLINE_MINUTES,
            'scripts' => ['visitors.js'],
        ], 'portal');
    }

    /** Polled by the Visitors page every few seconds. */
    public function stats(Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json(VisitorService::cachedStats());
    }
}
