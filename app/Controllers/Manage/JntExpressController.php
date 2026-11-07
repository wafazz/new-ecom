<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\JntExpressSettings;

/** J&T Express courier settings. Mounted in /admin and /staff behind permission:shipping.manage. */
final class JntExpressController extends ManageController
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/jnt-express', [
            'title' => 'J&T Express',
            'base' => $this->base(),
            'cfg' => JntExpressSettings::overview(),
            'modes' => JntExpressSettings::MODES,
            'history' => AuditService::recent('jnt.'),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $in = [
            'enabled' => $request->str('enabled') === '1' ? '1' : '0',
            'mode' => $request->str('mode'),
            'api_account' => trim($request->str('api_account')),
            'customer_code' => trim($request->str('customer_code')),
            'private_key' => trim($request->str('private_key')),
            'password' => trim($request->str('password')),
        ];
        $back = $this->base() . '/shipping/jnt-express';
        $errors = JntExpressSettings::validate($in, JntExpressSettings::overview());
        if ($errors !== []) {
            unset($in['private_key'], $in['password']); // never echo secrets back into the session
            $this->backWithErrors($back, $errors, $in);
        }
        $changed = JntExpressSettings::save($in, (int) Auth::user()['id']);
        $this->success($back, $changed === [] ? 'Nothing changed.' : 'J&T Express settings saved.' . ($in['enabled'] === '1' ? ' The Push to J&T Express button now shows on Processing orders.' : ''));
    }
}
