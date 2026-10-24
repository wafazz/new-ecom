<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\EmailService;

/** Confirms a member's email address from the link sent at sign-up, and sends the link again on request. */
final class VerifyEmailController extends Controller
{
    public function confirm(Request $request, string $token): void
    {
        $id = EmailService::confirm($token);
        if ($id === null) {
            $this->success('/login', 'That confirmation link is not valid or has expired. Sign in and ask for a new one.');
        }
        $this->success(Auth::check() ? PortalLoginController::landing('member') : '/login', 'Thank you. Your email address is confirmed.');
    }

    public function resend(Request $request): void
    {
        $back = '/member';
        $r = EmailService::resendVerification((int) Auth::user()['id']);
        $message = match ($r) {
            'sent' => 'We sent you a new confirmation link. Check your inbox.',
            'wait' => 'Please wait a couple of minutes before asking for another link.',
            'verified' => 'Your email address is already confirmed.',
            'off' => 'Email is not switched on in this shop yet.',
            default => 'The link could not be sent right now. Please try again later.',
        };
        $r === 'sent' || $r === 'verified'
            ? $this->success($back, $message)
            : $this->backWithErrors($back, ['form' => $message]);
    }
}
