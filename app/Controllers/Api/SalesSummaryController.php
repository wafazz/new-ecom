<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\SalesApiTokenService;
use App\Services\SalesSummaryReport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * GET /api/v1/sales-summary
 * Auth:  Authorization: Bearer <token>  (created on /admin/sales-api-tokens)
 * Query: days = 1..366 (default 30), or from & to = YYYY-MM-DD (both inclusive; to defaults to today).
 */
final class SalesSummaryController
{
    public function index(Request $request): void
    {
        header('Cache-Control: no-store');

        $token = SalesApiTokenService::bearer();
        if ($token === null || !SalesApiTokenService::authorise($token)) {
            header('WWW-Authenticate: Bearer');
            Response::json(['error' => 'Missing or invalid bearer token.'], 401);
        }

        $from = $request->input('from');
        $to = $request->input('to');
        $range = null;
        try {
            if ($from !== null || $to !== null) {
                $range = SalesSummaryReport::parseRange(
                    is_scalar($from) ? (string) $from : null,
                    is_scalar($to) ? (string) $to : null,
                    new DateTimeImmutable('now')
                );
            }
        } catch (InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }

        $days = (int) $request->input('days', SalesSummaryReport::DEFAULT_DAYS);
        Response::json(SalesSummaryReport::build($days, $range));
    }
}
