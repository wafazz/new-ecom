<?php
declare(strict_types=1);

namespace App\Controllers\Superadmin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\Modules;

/** Superadmin only (route group auth:superadmin). Turns whole modules on and off. */
final class ModulesController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('superadmin/modules', [
            'title' => 'Modules',
            'modules' => Modules::all(),
            'audit' => AuditService::recent('module.', 10),
        ], 'portal');
    }

    public function toggle(Request $request, string $key): void
    {
        if (!isset(Modules::LIST[$key])) {
            Response::abort(404);
        }
        $on = $request->str('enabled') === '1';
        Modules::set($key, $on);
        AuditService::log((int) Auth::user()['id'], 'module.' . ($on ? 'on' : 'off'), $key);
        $this->success('/superadmin/modules', Modules::LIST[$key][0] . ($on ? ' is now on.' : ' is now off. Its pages and storefront blocks are hidden.'));
    }
}
