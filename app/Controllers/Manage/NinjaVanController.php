<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\NinjaVanSettings;

/** NinjaVan courier settings. Mounted in /admin and /staff behind permission:shipping.manage. */
final class NinjaVanController extends ManageController
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/ninjavan', [
            'title' => 'NinjaVan',
            'base' => $this->base(),
            'cfg' => NinjaVanSettings::overview(),
            'modes' => NinjaVanSettings::MODES,
            'countries' => NinjaVanSettings::COUNTRIES,
            'states' => config('states'),
            'history' => AuditService::recent('ninjavan.'),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $in = [
            'mode' => $request->str('mode'),
            'country' => $request->str('country'),
            'client_id' => trim($request->str('client_id')),
            'client_secret' => trim($request->str('client_secret')),
            'awb_show_items' => $request->str('awb_show_items') === '1' ? '1' : '0',
        ];
        foreach (NinjaVanSettings::SENDER as $f) {
            $in[$f] = trim($request->str($f));
        }
        $back = $this->base() . '/shipping/ninjavan';
        $errors = NinjaVanSettings::validate($in);
        if ($errors !== []) {
            unset($in['client_secret']); // never echo the secret back into the session
            $this->backWithErrors($back, $errors, $in);
        }
        $changed = NinjaVanSettings::save($in, (int) Auth::user()['id']);
        $this->success($back, $changed === [] ? 'Nothing changed.' : 'NinjaVan settings saved.' . (array_intersect($changed, ['mode', 'country', 'client_id', 'client_secret']) ? ' Use "Test connection" to confirm the credentials.' : ''));
    }

    public function test(Request $request): void
    {
        $result = NinjaVanSettings::testConnection((int) Auth::user()['id']);
        $back = $this->base() . '/shipping/ninjavan';
        $result['ok'] ? $this->success($back, $result['message']) : $this->backWithErrors($back, ['form' => $result['message']]);
    }
}
