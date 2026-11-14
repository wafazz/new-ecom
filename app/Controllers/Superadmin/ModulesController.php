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
            'audit' => $this->auditRows(),
            'scripts' => ['modules.js'],
        ], 'portal');
    }

    /** Plain form posts redirect (works without JavaScript). AJAX posts get JSON so the page can update in place. */
    public function toggle(Request $request, string $key): void
    {
        if (!isset(Modules::LIST[$key])) {
            Response::abort(404);
        }
        $on = $request->str('enabled') === '1';
        Modules::set($key, $on);
        AuditService::log((int) Auth::user()['id'], 'module.' . ($on ? 'on' : 'off'), $key);
        $label = Modules::LIST[$key][0];
        $message = $label . ($on ? ' is now on.' : ' is now off. Its pages and storefront blocks are hidden.');

        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'key' => $key, 'enabled' => $on, 'message' => $message, 'audit' => $this->auditRows()]);
        }
        $this->success('/superadmin/modules', $message);
    }

    /**
     * The change log in plain words, newest first: [when, who, what].
     * @return array<int,array{when:string, who:string, what:string}>
     */
    private function auditRows(): array
    {
        $rows = [];
        foreach (AuditService::recent('module.', 10) as $a) {
            $key = (string) $a['detail'];
            $name = Modules::LIST[$key][0] ?? $key;
            $rows[] = [
                'when' => date('d M Y, H:i', strtotime((string) $a['created_at'])),
                'who' => (string) ($a['actor'] ?? 'Unknown'),
                'what' => ($a['action'] === 'module.on' ? 'Switched on: ' : 'Switched off: ') . $name,
            ];
        }
        return $rows;
    }
}
