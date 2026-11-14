<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\AbandonedCartService;

/** The link in an abandoned-cart email: puts the saved items back in the signed-in member's cart. */
final class CartRestoreController extends Controller
{
    public function restore(Request $request, string $token): void
    {
        $result = AbandonedCartService::restore((int) Auth::user()['id'], $token);
        if ($result === null) {
            $this->backWithErrors('/cart', ['form' => 'This link has expired or was already used.']);
        }
        if ($result['added'] === 0) {
            $this->backWithErrors('/cart', ['form' => 'None of those items can be added now. They may be sold out or no longer for sale.']);
        }
        $note = $result['failed'] > 0 ? " {$result['failed']} item(s) could not be added." : '';
        $this->success('/cart', "{$result['added']} item(s) added back to your cart." . $note);
    }
}
