<?php
declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\RateLimiter;

/** Profile and password for every kind of account, mounted under /admin, /staff and /member. */
final class AccountController extends Controller
{
    private function base(): string
    {
        return Auth::HOME[Auth::user()['role']];
    }

    public function profile(Request $request): void
    {
        $me = Auth::user();
        $perms = [];
        if ($me['role'] === 'staff') {
            $given = json_decode((string) $me['permissions'], true);
            foreach ((array) config('permissions') as $key => $label) {
                if (is_array($given) && in_array($key, $given, true)) {
                    $perms[$key] = $label;
                }
            }
        }
        $this->view('account/profile', ['title' => 'Profile', 'base' => $this->base(), 'perms' => $perms], 'portal');
    }

    public function updateProfile(Request $request): void
    {
        $input = ['name' => trim($request->str('name')), 'phone' => trim($request->str('phone'))];
        $errors = AccountService::updateProfile((int) Auth::user()['id'], $input['name'], $input['phone']);
        if ($errors !== []) {
            $this->backWithErrors($this->base() . '/profile', $errors, $input);
        }
        AuditService::log((int) Auth::user()['id'], 'account.profile', 'name, phone');
        $this->success($this->base() . '/profile', 'Profile saved.');
    }

    public function password(Request $request): void
    {
        $this->view('account/password', ['title' => 'Password', 'base' => $this->base(), 'scripts' => ['account.js']], 'portal');
    }

    public function updatePassword(Request $request): void
    {
        $user = Auth::user();
        $back = $this->base() . '/password';
        // Someone at an unlocked screen must not be able to guess the current password.
        if (RateLimiter::tooMany('pwchange', 'u' . $user['id'], 8, 15)) {
            $this->backWithErrors($back, ['form' => 'Too many attempts. Please wait a few minutes and try again.']);
        }
        RateLimiter::hit('pwchange', 'u' . $user['id']);

        $errors = AccountService::changePassword($user, $request->str('current_password'), $request->str('password'), $request->str('password_confirmation'));
        if ($errors !== []) {
            $this->backWithErrors($back, $errors);
        }
        AuditService::log((int) $user['id'], 'account.password', 'password changed');
        Auth::login(User::find((int) $user['id'])); // new session id after a credential change
        $this->success($back, 'Password changed.');
    }
}
