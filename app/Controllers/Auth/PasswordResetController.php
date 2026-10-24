<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;

/** Forgot password: ask for a reset link by email, then choose a new password from the link. */
final class PasswordResetController extends Controller
{
    public const FORGOT = '/forgot-password';

    public function show(Request $request): void
    {
        $this->view('auth/forgot', ['title' => 'Forgot password'], 'auth');
    }

    public function send(Request $request): void
    {
        $input = ['email' => $request->str('email')];
        $v = new Validator($request->body, ['email' => 'required|email']);
        if ($v->fails()) {
            $this->backWithErrors(self::FORGOT, $v->errors(), $input);
        }

        $ip = $request->ip();
        if (RateLimiter::tooMany('forgot', $ip, 10, 15)) {
            $this->backWithErrors(self::FORGOT, ['form' => 'Too many requests. Please wait a few minutes and try again.'], $input);
        }
        RateLimiter::hit('forgot', $ip);

        PasswordResetService::request($input['email'], $ip);
        // Same answer whether or not the address has an account.
        $this->success(self::FORGOT, 'If that email belongs to an account, we have sent a link to choose a new password. The link is valid for ' . PasswordResetService::MINUTES . ' minutes.');
    }

    public function form(Request $request, string $token): void
    {
        if (PasswordResetService::findByToken($token) === null) {
            $this->backWithErrors(self::FORGOT, ['form' => 'This reset link is not valid or has expired. Ask for a new one.']);
        }
        $this->view('auth/reset', ['title' => 'Choose a new password', 'token' => $token], 'auth');
    }

    public function update(Request $request): void
    {
        $token = $request->str('token');
        $role = (string) (PasswordResetService::findByToken($token)['role'] ?? 'member');
        $errors = PasswordResetService::reset($token, $request->str('password'), $request->str('password_confirmation'));
        if ($errors !== []) {
            $back = isset($errors['form']) ? self::FORGOT : '/reset-password/' . rawurlencode($token);
            $this->backWithErrors($back, $errors);
        }
        $this->success(Auth::LOGIN[$role] ?? '/login', 'Your password is reset. Log in with the new password.');
    }
}
