<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Modules;

/** `module:flash_sales` — a switched-off module answers 404 on its pages, even to a logged-in admin. */
final class ModuleMiddleware
{
    public function handle(Request $request, ?string $key = null): void
    {
        if ($key === null || !Modules::enabled($key)) {
            Response::abort(404);
        }
    }
}
