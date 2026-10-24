<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;

final class CsrfMiddleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $given = $request->input('_token');
        if (!Csrf::valid(is_string($given) ? $given : null)) {
            Response::abort(419, 'Session expired. Please go back and try again.');
        }
    }
}
