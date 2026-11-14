<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\BulkPricingService;

/** Bulk price changes for admins and superadmins: preview first, then apply, with undo. Sale prices are not changed. */
final class BulkPricingController extends Controller
{
    public function index(Request $request): void
    {
        $this->render(null, null);
    }

    /** Shows exactly what would change. Nothing is saved here. */
    public function preview(Request $request): void
    {
        [$scope, $percent] = $this->input($request);
        $this->render(['scope' => $scope, 'percent' => $percent, 'rows' => BulkPricingService::preview($scope, $percent)], null);
    }

    public function apply(Request $request): void
    {
        [$scope, $percent] = $this->input($request);
        try {
            $r = BulkPricingService::apply($scope, $percent, (int) Auth::user()['id']);
        } catch (\DomainException $e) {
            $this->backWithErrors('/admin/bulk-pricing', ['form' => $e->getMessage()]);
        }
        $this->success('/admin/bulk-pricing', "Prices changed for {$r['products']} product(s). You can undo this batch below.");
    }

    public function undo(Request $request, string $id): void
    {
        try {
            $r = BulkPricingService::undo((int) $id, (int) Auth::user()['id']);
        } catch (\DomainException $e) {
            $this->backWithErrors('/admin/bulk-pricing', ['form' => $e->getMessage()]);
        }
        $note = $r['skipped'] > 0 ? " {$r['skipped']} price(s) had been changed again since, so they were left as they are." : '';
        $this->success('/admin/bulk-pricing', "Undone: {$r['restored']} price(s) restored." . $note);
    }

    /** @return array{0: array{type:string,id:int}, 1: float} */
    private function input(Request $request): array
    {
        $type = $request->str('scope');
        // The preview form has separate category and brand pickers; the apply form carries one scope_id.
        $id = (int) $request->input('scope_id', 0);
        if ($type === 'brand' && (int) $request->input('brand_id', 0) > 0) {
            $id = (int) $request->input('brand_id', 0);
        }
        $scope = BulkPricingService::scope($type, $id);
        $raw = trim($request->str('percent'));
        if (!is_numeric($raw)) {
            $this->backWithErrors('/admin/bulk-pricing', ['form' => 'Enter a percentage, for example 10 or -5.']);
        }
        $percent = round((float) $raw, 2);
        if (($reason = BulkPricingService::checkPercent($percent)) !== null) {
            $this->backWithErrors('/admin/bulk-pricing', ['form' => $reason]);
        }
        return [$scope, $percent];
    }

    private function render(?array $preview, ?string $unused): void
    {
        $this->view('admin/bulk-pricing', [
            'title' => 'Bulk prices',
            'preview' => $preview,
            'categories' => Database::query('SELECT id, name FROM categories ORDER BY name')->fetchAll(),
            'brands' => Database::query('SELECT id, name FROM brands ORDER BY name')->fetchAll(),
            'batches' => BulkPricingService::batches(),
        ], 'portal');
    }
}
