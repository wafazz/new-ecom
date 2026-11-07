<?php
// Sales API for CodexLure: date-range rules and bearer-token parsing. No database needed.
use App\Services\SalesApiTokenService;
use App\Services\SalesSummaryReport;

$today = new DateTimeImmutable('2026-11-07 10:00:00');

$r = SalesSummaryReport::parseRange('2026-10-01', '2026-10-31', $today);
check('range: from and to are both inclusive', $r['from'] === '2026-10-01' && $r['to'] === '2026-10-31' && $r['end']->format('Y-m-d') === '2026-11-01');

$r = SalesSummaryReport::parseRange('2026-11-01', null, $today);
check('range: a missing to means today', $r['to'] === '2026-11-07' && $r['end']->format('Y-m-d') === '2026-11-08');

$refused = function (?string $from, ?string $to) use ($today): bool {
    try {
        SalesSummaryReport::parseRange($from, $to, $today);

        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};
check('range: a bad date format is refused', $refused('01/10/2026', '2026-10-31'));
check('range: an impossible date is refused', $refused('2026-02-30', '2026-03-01'));
check('range: a missing from is refused', $refused(null, '2026-10-31'));
check('range: to before from is refused', $refused('2026-10-31', '2026-10-01'));
check('range: more than 366 days is refused', $refused('2025-01-01', '2026-11-07'));
check('range: exactly 366 days is allowed', !$refused('2025-11-07', '2026-11-07'));

// Bearer header parsing (the header is read from $_SERVER, so set it for the check and restore after).
$saved = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer shop_sales_abc123';
check('bearer: token is read from the Authorization header', SalesApiTokenService::bearer() === 'shop_sales_abc123');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
check('bearer: other auth schemes are ignored', SalesApiTokenService::bearer() === null);
unset($_SERVER['HTTP_AUTHORIZATION']);
check('bearer: a missing header gives null', SalesApiTokenService::bearer() === null);
if ($saved !== null) {
    $_SERVER['HTTP_AUTHORIZATION'] = $saved;
}
