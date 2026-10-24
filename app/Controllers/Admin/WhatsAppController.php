<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\WhatsAppService;
use App\Services\WhatsAppSettings;

/** WhatsApp settings and status message templates. HQ (admin) only, enforced by the route group. */
final class WhatsAppController extends Controller
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/whatsapp', [
            'title' => 'WhatsApp',
            'overview' => WhatsAppSettings::overview(),
            'statuses' => WhatsAppSettings::STATUSES,
            'variables' => WhatsAppSettings::VARIABLES,
            'defaults' => WhatsAppSettings::defaults(),
            'scripts' => ['messaging.js'],
            'log' => Database::query(
                'SELECT w.created_at, w.status_key, w.phone, w.ok, w.error, o.order_no
                 FROM whatsapp_log w LEFT JOIN orders o ON o.id = w.order_id ORDER BY w.id DESC LIMIT 20'
            )->fetchAll(),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $tpl = is_array($request->body['tpl'] ?? null) ? $request->body['tpl'] : [];
        $errors = WhatsAppSettings::save([
            'enabled' => $request->str('enabled'),
            'token' => trim($request->str('token')),
            'tpl' => $tpl,
        ], (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/whatsapp', ['form' => implode(' ', $errors)]);
        }
        $this->success('/admin/whatsapp', 'WhatsApp settings saved.');
    }

    public function test(Request $request): void
    {
        $phone = WhatsAppService::phone($request->str('phone'));
        if ($phone === null) {
            $this->backWithErrors('/admin/whatsapp', ['form' => 'Enter a valid phone number, for example 012-3456789.']);
        }
        $text = 'Test message from ' . config('app.name') . '. If you received this, WhatsApp is connected.';
        $r = WhatsAppService::send($phone, $text);
        WhatsAppService::log(null, 'test', $phone, $r);
        $r['ok']
            ? $this->success('/admin/whatsapp', 'Test message sent.')
            : $this->backWithErrors('/admin/whatsapp', ['form' => 'Test message failed: ' . $r['message']]);
    }

    public function check(Request $request): void
    {
        $r = WhatsAppService::check();
        $r['ok']
            ? $this->success('/admin/whatsapp', $r['message'])
            : $this->backWithErrors('/admin/whatsapp', ['form' => $r['message']]);
    }
}
