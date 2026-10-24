<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AffiliateService;

final class AffiliateRedirectController extends Controller
{
    /** /r/{code}[?to=/products/slug]: log the click, set the ref cookie, send the visitor on. Unknown codes just go home. */
    public function go(Request $request, string $code): void
    {
        AffiliateService::visit($code, $request);
        $to = $request->str('to');
        $this->redirect(preg_match('#^/(?!/)[A-Za-z0-9/_\-]*$#', $to) ? $to : '/');
    }
}
