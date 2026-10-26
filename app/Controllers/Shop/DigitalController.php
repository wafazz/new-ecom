<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DigitalService;

final class DigitalController extends Controller
{
    /** A digital download. The token is 48 hex characters and is only ever shown to the buyer on their order page. */
    public function download(Request $request, string $token): void
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            Response::abort(404);
        }
        $result = DigitalService::claim($token);
        if (!$result['ok']) {
            Response::abort($result['code'], $result['message']);
        }
        DigitalService::send($result['grant']);
    }
}
