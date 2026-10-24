<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Services\UnsubscribeService;

/** Stop announcement emails. GET asks for confirmation; POST (the button, or a mail client's one-click) unsubscribes. */
final class UnsubscribeController extends Controller
{
    public function show(Request $request, string $token): void
    {
        $email = UnsubscribeService::emailFor($token);
        $this->view('auth/unsubscribe', [
            'title' => 'Unsubscribe',
            'state' => $email === null ? 'invalid' : 'ask',
            'email' => $email,
            'token' => $token,
        ], 'auth');
    }

    public function unsubscribe(Request $request, string $token): void
    {
        $email = UnsubscribeService::emailFor($token);
        if ($email !== null) {
            UnsubscribeService::unsubscribe($email);
        }
        $this->view('auth/unsubscribe', [
            'title' => 'Unsubscribed',
            'state' => $email === null ? 'invalid' : 'done',
            'email' => $email,
            'token' => $token,
        ], 'auth');
    }
}
