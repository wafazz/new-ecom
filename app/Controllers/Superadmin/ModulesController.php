<?php
declare(strict_types=1);

namespace App\Controllers\Superadmin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\ModulePricing;
use App\Services\Modules;
use App\Services\PlatformSettings;
use App\Services\SuperadminPin;

/** Superadmin only (route group auth:superadmin). Turns whole modules on and off. */
final class ModulesController extends Controller
{
    public function index(Request $request): void
    {
        $modules = Modules::all();
        foreach ($modules as &$m) {
            $m['price'] = ModulePricing::price($m['key']);
            $m['quote'] = ModulePricing::quote($m['key']);
            $m['own_discount'] = ModulePricing::discountRow($m['key']);
        }
        unset($m);
        $this->view('superadmin/modules', [
            'title' => 'Modules',
            'modules' => $modules,
            'global' => ModulePricing::discountRow('global'),
            'pinCustom' => SuperadminPin::isCustom((int) Auth::user()['id']),
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
        // A module is switched only with this superadmin's PIN, checked here as well as on the page.
        $check = SuperadminPin::verify((int) Auth::user()['id'], $request->str('pin'));
        if (!$check['ok']) {
            $msg = $check['error'] === 'locked' ? 'Too many wrong PINs. Try again in 15 minutes.' : 'Wrong PIN. Try again.';
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'pin' => true, 'locked' => $check['error'] === 'locked', 'message' => $msg], 403);
            }
            $this->backWithErrors('/superadmin/modules', ['form' => $msg]);
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

    /** Sets a module's price and billing period. Superadmin only (route group). */
    public function price(Request $request, string $key): void
    {
        if (!isset(Modules::LIST[$key])) {
            Response::abort(404);
        }
        $error = ModulePricing::set($key, $request->str('period'), trim($request->str('amount')), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/superadmin/modules', ['form' => $error]);
        }
        $this->success('/superadmin/modules', Modules::LIST[$key][0] . ' price saved.');
    }

    /** A discount on all priced modules ('global') or on one module. Clear it with clear=1. */
    public function discount(Request $request): void
    {
        $scope = $request->str('scope');
        if ($scope !== 'global' && !isset(Modules::LIST[$scope])) {
            Response::abort(404);
        }
        $actor = (int) Auth::user()['id'];
        if ($request->str('clear') === '1') {
            ModulePricing::clearDiscount($scope, $actor);
            $this->success('/superadmin/modules', 'Discount removed.');
        }
        $error = ModulePricing::setDiscount($scope, $request->str('kind'), trim($request->str('value')), trim($request->str('ends_at')), $actor);
        if ($error !== null) {
            $this->backWithErrors('/superadmin/modules', ['form' => $error]);
        }
        $this->success('/superadmin/modules', 'Discount saved. It ends at the time you chose.');
    }

    /** Security page: change this superadmin's PIN. */
    public function security(Request $request): void
    {
        $this->view('superadmin/security', ['title' => 'Security', 'custom' => SuperadminPin::isCustom((int) Auth::user()['id'])], 'portal');
    }

    public function changePin(Request $request): void
    {
        $error = SuperadminPin::change((int) Auth::user()['id'], $request->str('current'), $request->str('pin'), $request->str('confirm'));
        if ($error !== null) {
            $this->backWithErrors('/superadmin/security', ['form' => $error]);
        }
        $this->success('/superadmin/security', 'PIN changed. Use the new PIN to switch modules on and off.');
    }

    /** Payment setup page: Billplz and bank details for module payments. */
    public function billing(Request $request): void
    {
        $this->view('superadmin/billing', ['title' => 'Payment setup', 'o' => PlatformSettings::overview(), 'errors' => []], 'portal');
    }

    public function saveBilling(Request $request): void
    {
        $errors = PlatformSettings::save($request->body, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/superadmin/billing', $errors);
        }
        $this->success('/superadmin/billing', 'Payment details saved.');
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
