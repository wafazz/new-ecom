<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Models\User;
use App\Services\RateLimiter;

final class RegisterController extends Controller
{
    public function show(Request $request): void
    {
        $this->view('auth/register', ['title' => 'Create account'], 'auth');
    }

    public function register(Request $request): void
    {
        if (RateLimiter::tooMany('register', $request->ip(), 10, 60)) {
            $this->backWithErrors('/register', ['form' => 'Too many sign-ups from your network. Please try again later.']);
        }
        RateLimiter::hit('register', $request->ip());

        $input = [
            'name' => trim($request->str('name')),
            'email' => trim($request->str('email')),
            'phone' => trim($request->str('phone')),
        ];
        $password = $request->str('password');

        $v = new Validator($request->body, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'max:30',
            'password' => 'required|min:8|max:72',
        ]);
        $errors = $v->errors();
        if ($password !== $request->str('password_confirmation')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if (!isset($errors['email']) && User::findByEmail($input['email']) !== null) {
            $errors['email'] = 'That email is already registered. Try logging in.';
        }
        if ($errors !== []) {
            $this->backWithErrors('/register', $errors, $input);
        }

        $id = User::create('member', $input['name'], $input['email'], $password, $input['phone']);
        try {
            \App\Services\EmailService::sendVerification($id, $input['email'], $input['name']);
        } catch (\Throwable) {
            // the account is created either way; the member can ask for the link again from the dashboard
        }
        Auth::login(User::find($id));
        $this->success(PortalLoginController::landing('member'), 'Welcome! Your account is ready.');
    }
}
