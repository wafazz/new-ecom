<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\StockCountService;

/** Stock count sheets for admins and superadmins: create a count, enter counts blind, review differences, apply. */
final class StockCountController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/stock-counts', [
            'title' => 'Stock counts',
            'counts' => StockCountService::listing(),
            'categories' => Database::query('SELECT id, name FROM categories ORDER BY name')->fetchAll(),
        ], 'portal');
    }

    public function create(Request $request): void
    {
        $title = trim($request->str('title'));
        if ($title === '') {
            $this->backWithErrors('/admin/stock-counts', ['form' => 'Give the count a name, for example "Shelf A, Tuesday".']);
        }
        $cat = (int) $request->input('category_id', 0);
        $id = StockCountService::create($title, $cat > 0 ? $cat : null, (int) Auth::user()['id']);
        $this->success('/admin/stock-counts/' . $id, 'Count sheet made. Enter the numbers you count.');
    }

    /** The blank sheet for counting: no system quantities are shown here. */
    public function sheet(Request $request, string $id): void
    {
        $count = $this->count((int) $id);
        $this->view('admin/stock-count-sheet', [
            'title' => 'Count: ' . $count['title'],
            'count' => $count,
            'lines' => Database::query('SELECT id, label, sku, counted_qty FROM stock_count_lines WHERE count_id = ? ORDER BY label, id', [(int) $id])->fetchAll(),
            'scripts' => ['stock-count.js'],
        ], 'portal');
    }

    public function save(Request $request, string $id): void
    {
        $this->count((int) $id);
        $error = StockCountService::saveCounts((int) $id, (array) $request->input('counted', []));
        if ($error !== null) {
            $this->backWithErrors('/admin/stock-counts/' . (int) $id, ['form' => $error]);
        }
        $this->success('/admin/stock-counts/' . (int) $id . '/review', 'Counts saved. Check the differences before applying.');
    }

    public function review(Request $request, string $id): void
    {
        $count = $this->count((int) $id);
        $this->view('admin/stock-count-review', [
            'title' => 'Review: ' . $count['title'],
            'count' => $count,
            'diffs' => StockCountService::differences((int) $id),
            'uncounted' => (int) Database::query('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NULL', [(int) $id])->fetchColumn(),
        ], 'portal');
    }

    public function apply(Request $request, string $id): void
    {
        $this->count((int) $id);
        $result = StockCountService::apply((int) $id, (int) Auth::user()['id']);
        $msg = $result['applied'] . ' correction(s) applied to stock.' . ($result['failed'] !== [] ? ' ' . count($result['failed']) . ' could not be applied.' : '');
        $this->success('/admin/stock-counts/' . (int) $id . '/review', $msg);
    }

    private function count(int $id): array
    {
        return Database::query('SELECT * FROM stock_counts WHERE id = ?', [$id])->fetch() ?: Response::abort(404);
    }
}
