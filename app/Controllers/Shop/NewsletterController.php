<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Services\NewsletterService;
use App\Services\RateLimiter;

/** Footer and checkout sign-ups, and the confirmation link from the email. Both are switched by the newsletter module. */
final class NewsletterController extends Controller
{
    public function subscribe(Request $request): void
    {
        if (RateLimiter::tooMany('newsletter', $request->ip(), 5, 10)) {
            $this->backWithErrors('/', ['form' => 'Too many sign-ups from this connection. Please try again later.']);
        }
        RateLimiter::hit('newsletter', $request->ip());

        $result = NewsletterService::subscribe($request->str('email'));
        match ($result) {
            'sent' => $this->success('/', 'Check your inbox and confirm your address to finish signing up.'),
            'active' => $this->success('/', "You're already subscribed. Thank you!"),
            'invalid' => $this->backWithErrors('/', ['form' => 'Enter a valid email address.']),
            'off' => $this->backWithErrors('/', ['form' => 'Sign-up is not available right now. Please try again later.']),
            default => $this->backWithErrors('/', ['form' => 'We could not send the confirmation email. Please try again later.']),
        };
    }

    public function confirm(Request $request, string $token): void
    {
        if (NewsletterService::confirm($token) === null) {
            $this->backWithErrors('/', ['form' => 'This confirmation link has expired or was already used.']);
        }
        $this->success('/', 'Thanks. Your newsletter sign-up is confirmed.');
    }
}
