<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Installer;
use App\Services\Migrator;

/** The first-run setup wizard: closed for good once a shop is installed. */
final class InstallController extends Controller
{
    public function show(Request $request): void
    {
        $this->closedIfInstalled();
        header('Cache-Control: no-store');
        $checks = Installer::checks();
        $this->view('install/wizard', [
            'title' => 'Set up your shop',
            'checks' => $checks,
            'passed' => Installer::passed($checks),
            'tokenReady' => Installer::tokenConfigured(),
            'checked' => (bool) Session::get('install_checked', false),
        ], 'auth');
    }

    /** Step 1: the install token, the server checks, then the database tables. */
    public function check(Request $request): void
    {
        $this->closedIfInstalled();
        if (!Installer::tokenMatches($request->str('token'))) {
            $this->backWithErrors('/install', ['token' => 'That install token does not match INSTALL_TOKEN in .env.']);
        }
        if (!Installer::passed(Installer::checks())) {
            $this->backWithErrors('/install', ['form' => 'Fix the items marked as failed, then check again.']);
        }
        try {
            $applied = Migrator::run();
        } catch (\Throwable $e) {
            $this->backWithErrors('/install', ['form' => 'The database tables could not be created: ' . $e->getMessage()]);
        }
        Session::set('install_checked', true);
        $this->success('/install', 'Server checks passed. ' . (count($applied) > 0 ? count($applied) . ' database update(s) applied.' : 'The database is up to date.'));
    }

    /** Step 2: the first admin account and the shop defaults. */
    public function finish(Request $request): void
    {
        $this->closedIfInstalled();
        if (!Session::get('install_checked', false)) {
            $this->backWithErrors('/install', ['form' => 'Run the server check first.']);
        }
        if (!Installer::tokenMatches($request->str('token'))) {
            $this->backWithErrors('/install', ['token' => 'Enter the install token again.']);
        }
        try {
            $error = Installer::finish([
                'admin_name' => trim($request->str('admin_name')),
                'admin_email' => trim($request->str('admin_email')),
                'admin_password' => (string) ($request->body['admin_password'] ?? ''),
                'admin_password_confirm' => (string) ($request->body['admin_password_confirm'] ?? ''),
                'shipping_flat' => trim($request->str('shipping_flat', '0')),
                'commission_rate' => trim($request->str('commission_rate', '10')),
                'guest_checkout' => $request->str('guest_checkout'),
            ]);
        } catch (\Throwable $e) {
            $error = 'Setup could not finish: ' . $e->getMessage();
        }
        if ($error !== null) {
            $this->backWithErrors('/install', ['form' => $error]);
        }
        Session::forget('install_checked');
        $this->success('/admin/login', 'Your shop is set up. Sign in with the admin account you just created.');
    }

    private function closedIfInstalled(): void
    {
        if (Installer::installed()) {
            Response::abort(404);
        }
    }
}
