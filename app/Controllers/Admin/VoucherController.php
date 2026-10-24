<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmailService;
use App\Services\VoucherService;

/** Discount codes for HQ: create, switch on or off, and delete codes nobody has used. */
final class VoucherController extends Controller
{
    public function index(Request $request): void
    {
        $vouchers = Database::query('SELECT * FROM vouchers ORDER BY id DESC')->fetchAll();
        // How many members would get each code now: members who have not unsubscribed and have not had it yet.
        $members = EmailService::recipients('members');
        $newCounts = [];
        foreach ($vouchers as $v) {
            $already = VoucherService::emailedAddresses((int) $v['id']);
            $newCounts[(int) $v['id']] = count(array_filter($members, fn($p) => !isset($already[strtolower($p['email'])])));
        }
        $this->view('admin/vouchers', [
            'title' => 'Vouchers',
            'vouchers' => $vouchers,
            'types' => VoucherService::TYPES,
            'newCounts' => $newCounts,
            'canEmail' => EmailService::ready(),
            'scripts' => ['vouchers.js'],
        ], 'portal');
    }

    /** Email a live code to every member who has not unsubscribed. */
    public function email(Request $request, string $id): void
    {
        $r = VoucherService::emailMembers((int) $id, (int) Auth::user()['id']);
        if (is_string($r)) {
            $this->backWithErrors('/admin/vouchers', ['form' => $r]);
        }
        $this->success('/admin/vouchers', "Queued for {$r['recipients']} new member(s). {$r['already']} had already received this code. They are sent in the background.");
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
