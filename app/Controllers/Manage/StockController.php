<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Brand;
use App\Models\Category;
use App\Services\StockService;

/** Stock Control Management: the one place stock quantities are changed (permission stock.manage for staff). */
final class StockController extends ManageController
{
    private const PER_PAGE = 25;
    private const MOVES_PER_PAGE = 40;
    private const LEVELS = ['ok', 'low', 'out'];

    public function index(Request $request): void
    {
        $filters = [
            'q' => trim($request->str('q')),
            'category' => (int) $request->input('category', 0) ?: null,
            'brand' => (int) $request->input('brand', 0) ?: null,
            'product' => (int) $request->input('product', 0) ?: null,
            'level' => in_array($request->str('level'), self::LEVELS, true) ? $request->str('level') : '',
            'sort' => array_key_exists($request->str('sort'), StockService::SORTS) ? $request->str('sort') : 'name',
        ];
        $page = max(1, (int) $request->input('page', 1));
        $result = StockService::items($filters, $page, self::PER_PAGE);

        $this->view('manage/stock/index', [
            'title' => 'Stock Control Management',
            'base' => $this->base(),
            'groups' => $result['groups'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'summary' => StockService::summary(),
            'categories' => Category::options(),
            'brands' => Brand::options(),
            'sorts' => StockService::SORTS,
            'reasons' => StockService::REASONS,
            'scripts' => ['stock.js'],
        ], 'portal');
    }

    /** The old /stock/history address keeps working. */
    public function history(Request $request): void
    {
        $qs = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        redirect(url($this->base() . '/stock/movements' . ($qs !== '' ? '?' . $qs : '')));
    }

    /** Stock Movement: every change to any product's stock, with filters and totals for what is filtered. */
    public function movements(Request $request): void
    {
        $date = function (string $v): ?string {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v ? $v : null;
        };
        $from = $date($request->str('from'));
        $to = $date($request->str('to'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $actor = $request->str('actor');
        $filters = [
            'q' => trim($request->str('q')),
            'product' => (int) $request->input('product', 0) ?: null,
            'variant' => (int) $request->input('variant', 0) ?: null,
            'reason' => trim($request->str('reason')),
            'dir' => in_array($request->str('dir'), ['in', 'out'], true) ? $request->str('dir') : '',
            'from' => $from,
            'to' => $to,
            'actor' => $actor === 'system' || ctype_digit($actor) ? $actor : '',
        ];
        $page = max(1, (int) $request->input('page', 1));
        $result = StockService::history($filters, $page, self::MOVES_PER_PAGE);

        $this->view('manage/stock/movements', [
            'title' => 'Stock Movement',
            'base' => $this->base(),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::MOVES_PER_PAGE)),
            'filters' => $filters,
            'summary' => StockService::movementSummary($filters),
            'actors' => StockService::actors(),
            'unit' => $filters['product'] ? StockService::unit($filters['product'], $filters['variant']) : null,
            'reasons' => StockService::REASONS,
            'canOrders' => Auth::can('orders.view'),
        ], 'portal');
    }

    /** AJAX from the Adjust popup. */
    public function adjust(Request $request): void
    {
        $r = StockService::adjust(
            (int) $request->input('product_id', 0),
            (int) $request->input('variant_id', 0) ?: null,
            $request->str('mode'),
            $request->str('qty'),
            $request->str('reason'),
            $request->str('note'),
            (int) Auth::user()['id']
        );
        if (!$r['ok']) {
            Response::json(['ok' => false, 'message' => $r['error']], 422);
        }
        $diff = $r['after'] - $r['before'];
        Response::json([
            'ok' => true, 'before' => $r['before'], 'after' => $r['after'], 'changed' => $r['changed'],
            'message' => $r['changed'] ? 'Stock updated: ' . $r['before'] . ' → ' . $r['after'] . ' (' . ($diff > 0 ? '+' : '') . $diff . ').' : 'No change: the stock is already ' . $r['after'] . '.',
        ]);
    }
}
