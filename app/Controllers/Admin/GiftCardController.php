<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\GiftCardService;
use App\Services\PricingService;

/** Gift cards for admins and superadmins: issue a card for an amount, switch a card on or off. */
final class GiftCardController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/gift-cards', [
            'title' => 'Gift cards',
            'cards' => GiftCardService::listing(),
            'issued' => \App\Core\Session::flash('gift_code'),
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $raw = trim($request->str('amount'));
        if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $raw) || (float) $raw < 1) {
            $this->backWithErrors('/admin/gift-cards', ['form' => 'Enter an amount from RM1.00 to RM10,000.00.']);
        }
        try {
            $code = GiftCardService::issue((int) round((float) $raw * 100), (int) Auth::user()['id']);
        } catch (\DomainException $e) {
            $this->backWithErrors('/admin/gift-cards', ['form' => $e->getMessage()]);
        }
        \App\Core\Session::flash('gift_code', $code);
        $this->success('/admin/gift-cards', 'Gift card created. Copy the code now: it is shown only once.');
    }

    public function toggle(Request $request, string $id): void
    {
        GiftCardService::setActive((int) $id, $request->str('active') === '1', (int) Auth::user()['id']);
        $this->success('/admin/gift-cards', 'Gift card updated.');
    }
}
