<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;

abstract class PortalLoginController extends Controller
{
    protected string $role;
    protected string $portalName;

    public function show(Request $request): void
    {
        $this->view('auth/login', [
            'title' => $this->portalName . ' login',
            'portalName' => $this->portalName,
            'action' => Auth::LOGIN[$this->role],
            'showRegister' => $this->role === 'member',
        ], 'auth');
    }

    public function login(Request $request): void
    {
        $path = Auth::LOGIN[$this->role];
        $input = ['email' => $request->str('email')];
        $password = $request->str('password');

        $v = new Validator($request->body, ['email' => 'required|email', 'password' => 'required']);
        if ($v->fails()) {
            $this->backWithErrors($path, $v->errors(), $input);
        }

        if (Auth::throttled($input['email'], $request->ip())) {
            $this->backWithErrors($path, ['form' => 'Too many failed attempts. Try again in ' . Auth::WINDOW_MINUTES . ' minutes.'], $input);
        }

        $user = Auth::attempt($input['email'], $password, $this->role, $request->ip());
        if ($user === null) {
            $this->backWithErrors($path, ['form' => 'Invalid email or password.'], $input);
        }

        Auth::login($user);
        redirect(self::landing($this->role));
    }

    /** After login: a member who was sent here from checkout goes back there. Whitelist only. */
    public static function landing(string $role): string
    {
        $intended = Session::get('intended');
        Session::forget('intended');
        return $role === 'member' && $intended === '/checkout' ? '/checkout' : Auth::HOME[$role];
    }
}
