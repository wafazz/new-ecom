<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Request;
use App\Core\Response;
use App\Services\FlashSaleService;

/**
 * Flash sales for the admin and staff portals (staff need products.manage): timeframes, product picks, and a live preview
 * of the storefront block built from the form as it is typed.
 */
final class FlashSaleController extends ManageController
{
    public function index(Request $request): void
    {
        $this->view('manage/flash-sales/index', [
            'title' => 'Flash sales',
            'base' => $this->base(),
            'sales' => FlashSaleService::listing(),
            'scripts' => ['flash-sales.js'],
        ], 'portal');
    }

    public function create(Request $request): void
    {
        $this->renderForm(null, ['name' => '', 'starts_at' => '', 'ends_at' => ''], [], []);
    }

    public function edit(Request $request, string $id): void
    {
        $sale = FlashSaleService::find((int) $id) ?? Response::abort(404);
        $this->renderForm($sale, [
            'name' => (string) $sale['name'],
            'starts_at' => date('Y-m-d\TH:i', strtotime((string) $sale['starts_at'])),
            'ends_at' => date('Y-m-d\TH:i', strtotime((string) $sale['ends_at'])),
        ], FlashSaleService::itemsOf((int) $sale['id']), []);
    }

    public function store(Request $request): void
    {
        $this->save($request, null);
    }

    public function update(Request $request, string $id): void
    {
        $sale = FlashSaleService::find((int) $id) ?? Response::abort(404);
        $this->save($request, $sale);
    }

    public function toggle(Request $request, string $id): void
    {
        $sale = FlashSaleService::find((int) $id) ?? Response::abort(404);
        $switchOn = (int) $sale['active'] !== 1;
        FlashSaleService::setActive((int) $sale['id'], $switchOn);
        $this->success($this->base() . '/flash-sales', $switchOn ? 'Flash sale switched on.' : 'Flash sale switched off.');
    }

    public function destroy(Request $request, string $id): void
    {
        $sale = FlashSaleService::find((int) $id) ?? Response::abort(404);
        FlashSaleService::delete((int) $sale['id']);
        $this->success($this->base() . '/flash-sales', 'Flash sale deleted.');
    }

    /** The storefront block for the form as it stands, nothing saved. Posted from the editor on every change. */
    public function preview(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/flash-sales/preview', ['sale' => FlashSaleService::preview($request->body)], null);
    }

    private function save(Request $request, ?array $existing): void
    {
        $check = FlashSaleService::validate($request->body);
        // Only products the picker offers can be saved (a deleted or unlisted product id is refused, not a server error).
        $offered = array_map('intval', array_column(FlashSaleService::selectable((int) ($existing['id'] ?? 0)), 'id'));
        if (array_diff(array_keys($check['items']), $offered) !== []) {
            $check['errors']['items'] = 'Some of those products are no longer available. Please pick them again.';
        }
        if ($check['errors'] !== []) {
            $this->renderForm($existing, $this->fields($request->body), $this->picks($request->body), $check['errors']);
            return;
        }
        FlashSaleService::save($existing !== null ? (int) $existing['id'] : null, $check['sale'], $check['items']);
        $this->success($this->base() . '/flash-sales', $existing !== null ? 'Flash sale saved.' : 'Flash sale created.');
    }

    /** Re-shows the form with what was typed (dates as typed, ticked products with their percentages) and the errors. */
    private function renderForm(?array $sale, array $fields, array $picked, array $errors): void
    {
        if ($errors !== []) {
            http_response_code(422);
        }
        $this->view('manage/flash-sales/form', [
            'title' => $sale !== null ? 'Edit flash sale' : 'New flash sale',
            'base' => $this->base(),
            'sale' => $sale,
            'fields' => $fields,
            'picked' => $picked,
            'errors' => $errors,
            'products' => FlashSaleService::selectable((int) ($sale['id'] ?? 0)),
            'max' => FlashSaleService::MAX_PERCENT,
            'scripts' => ['flash-sales.js'],
        ], 'portal');
    }

    /** @return array{name:string, starts_at:string, ends_at:string} */
    private function fields(array $in): array
    {
        return [
            'name' => trim((string) ($in['name'] ?? '')),
            'starts_at' => (string) ($in['starts_at'] ?? ''),
            'ends_at' => (string) ($in['ends_at'] ?? ''),
        ];
    }

    /** Ticked rows as typed, so a row with a bad percentage keeps its tick and its value. @return array<int,array{percent:string}> */
    private function picks(array $in): array
    {
        $out = [];
        foreach (is_array($in['items'] ?? null) ? $in['items'] : [] as $pid => $row) {
            if (is_array($row) && !empty($row['on']) && ctype_digit((string) $pid)) {
                $out[(int) $pid] = ['percent' => trim((string) ($row['percent'] ?? ''))];
            }
        }
        return $out;
    }
}
