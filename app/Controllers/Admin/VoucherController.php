<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\VoucherService;

/** Discount codes for HQ: create, switch on or off, and delete codes nobody has used. */
final class VoucherController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/vouchers', [
            'title' => 'Vouchers',
            'vouchers' => Database::query('SELECT * FROM vouchers ORDER BY id DESC')->fetchAll(),
            'types' => VoucherService::TYPES,
            'scripts' => ['vouchers.js'],
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $r = VoucherService::create($request->body, (int) Auth::user()['id']);
        if ($r['errors'] !== []) {
            $this->backWithErrors('/admin/vouchers', $r['errors'], $request->body);
        }
        $this->success('/admin/vouchers', 'Code created.');
    }

    public function toggle(Request $request, string $id): void
    {
        $row = Database::query('SELECT id, active FROM vouchers WHERE id = ?', [(int) $id])->fetch() ?: Response::abort(404);
        VoucherService::setActive((int) $row['id'], (int) $row['active'] !== 1, (int) Auth::user()['id']);
        $this->success('/admin/vouchers', (int) $row['active'] === 1 ? 'Code switched off.' : 'Code switched on.');
    }

    public function delete(Request $request, string $id): void
    {
        if (!VoucherService::delete((int) $id, (int) Auth::user()['id'])) {
            $this->backWithErrors('/admin/vouchers', ['form' => 'A code that has been used cannot be deleted. Switch it off instead.']);
        }
        $this->success('/admin/vouchers', 'Code deleted.');
    }
}
