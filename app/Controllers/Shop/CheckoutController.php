<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Setting;
use App\Models\User;
use App\Services\AddressService;
use App\Services\CartService;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\Payment\BillplzGateway;
use App\Services\Payment\Payments;
use App\Services\RateLimiter;
use App\Services\ReceiptService;
use App\Services\VoucherService;
use App\Services\PaymentSettings;

final class CheckoutController extends Controller
{
    public function show(Request $request): void
    {
        $cart = $this->readyCart();
        $me = Auth::user();
        $member = $me !== null && $me['role'] === 'member' ? $me : null;

        // Prefill: the member's profile + their most recent delivery address.
        $last = $member ? (Database::query('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$member['id']])->fetch() ?: []) : [];
        $defaults = [
            'name' => $member['name'] ?? '',
            'email' => $member['email'] ?? '',
            'phone' => $member['phone'] ?? ($last['customer_phone'] ?? ''),
            'line1' => $last['ship_line1'] ?? '',
            'line2' => $last['ship_line2'] ?? '',
            'city' => $last['ship_city'] ?? '',
            'state' => $last['ship_state'] ?? '',
            'postcode' => $last['ship_postcode'] ?? '',
        ];

        // Saved addresses: ?address=ID picks one (ownership-checked), otherwise the default one wins over the last order.
        $saved = $member ? AddressService::forUser((int) $member['id']) : [];
        $picked = null;
        if ($saved !== []) {
            $wanted = (int) $request->input('address', 0);
            foreach ($saved as $a) {
                if ($wanted > 0 ? (int) $a['id'] === $wanted : (int) $a['is_default'] === 1) {
                    $picked = $a;
                }
            }
            if ($picked !== null) {
                $defaults = array_merge($defaults, array_intersect_key($picked, array_flip(AddressService::FIELDS)));
            }
        }

        // A discount code kept from earlier: re-check it against the current cart, and drop it if it no longer applies.
        $voucher = null;
        $discount = 0;
        $voucherNote = null;
        $code = Session::get('voucher');
        if (is_string($code)) {
            $q = VoucherService::quote($code, (int) $cart['subtotal_cents']);
            if ($q['ok']) {
                $voucher = $q['voucher'];
                $discount = $q['discount'];
            } else {
                Session::forget('voucher');
                $voucherNote = $q['message'];
            }
        }

        $this->view('shop/checkout', [
            'title' => 'Checkout',
            'cart' => $cart,
            'voucher' => $voucher,
            'discount_cents' => $discount,
            'voucher_note' => $voucherNote,
            'member' => $member,
            'defaults' => $defaults,
            'saved' => $saved,
            'pickedId' => $picked !== null ? (int) $picked['id'] : null,
            'canSave' => $member !== null && count($saved) < AddressService::MAX,
            'states' => config('states'),
            'methods' => Payments::checkoutMethods(),
            'shipping_cents' => Setting::shippingCents(),
            'bank' => PaymentSettings::bank(),
            'scripts' => ['receipt-form.js'],
            'vendor' => ['select2'],
        ]);
    }

    public function place(Request $request): void
    {
        $cart = $this->readyCart();
        $me = Auth::user();
        $memberId = $me !== null && $me['role'] === 'member' ? (int) $me['id'] : null;

        if (RateLimiter::tooMany('checkout', $request->ip(), 15, 60)) {
            $this->backWithErrors('/checkout', ['form' => 'Too many orders from your network. Please try again later.'], $request->body);
        }
        RateLimiter::hit('checkout', $request->ip());

        $in = [
            'name' => trim($request->str('name')),
            'email' => trim($request->str('email')),
            'phone' => trim($request->str('phone')),
            'line1' => trim($request->str('line1')),
            'line2' => trim($request->str('line2')),
            'city' => trim($request->str('city')),
            'state' => trim($request->str('state')),
            'postcode' => trim($request->str('postcode')),
            'notes' => trim($request->str('notes')),
        ];
        // A logged-in member's order always uses their account email (the field is read-only).
        if ($memberId !== null) {
            $in['email'] = $me['email'];
        }
        $errors = (new Validator($in, ['email' => 'required|email|max:190', 'notes' => 'max:500']))->errors()
            + AddressService::validate($in);

        $method = $request->str('payment_method');
        if (!isset(Payments::checkoutMethods()[$method])) {
            $errors['payment_method'] = 'Choose a payment method.';
        }
        // Bank transfer: the receipt, the bank paid from and when. Checked before any order exists.
        $proof = null;
        if ($method === 'bank_transfer') {
            $proof = ReceiptService::prepare($request->body, $_FILES['receipt'] ?? null);
            $errors += $proof['errors'];
            if (!isset($errors['receipt']) && $errors !== []) {
                $errors['receipt'] = 'Please attach your receipt again: for your privacy we cannot keep it between attempts.';
            }
        }

        $newAccount = null;
        if ($memberId === null) {
            if (!Setting::guestCheckoutEnabled()) {
                $this->requireLogin();
            }
            if ($request->str('create_account') === '1') {
                $password = $request->str('password');
                if (strlen($password) < 8 || strlen($password) > 72) {
                    $errors['password'] = 'Password must be 8-72 characters.';
                } elseif ($password !== $request->str('password_confirmation')) {
                    $errors['password_confirmation'] = 'Passwords do not match.';
                } elseif (!isset($errors['email']) && User::findByEmail($in['email']) !== null) {
                    $errors['email'] = 'That email already has an account. Please log in to check out.';
                }
                $newAccount = ['password' => $password];
            }
        }

        if ($errors !== []) {
            $this->backWithErrors('/checkout', $errors, $request->body);
        }

        $voucherCode = Session::get('voucher');
        try {
            $result = CheckoutService::place((int) CartService::id(), $in, $method, $memberId, $newAccount, is_string($_COOKIE['ref'] ?? null) ? $_COOKIE['ref'] : null, is_string($voucherCode) ? $voucherCode : null);
        } catch (CheckoutException $e) {
            $this->backWithErrors($e->getMessage() === 'Your cart is empty.' ? '/cart' : '/checkout', ['form' => $e->getMessage()], $request->body);
        }
        Session::forget('voucher');

        if ($newAccount !== null) {
            Auth::login(User::find($result['user_id']));
        }
        if ($memberId !== null && $request->str('save_address') === '1') {
            AddressService::saveFromOrder($memberId, $in);
        }
        $orderPage = $result['user_id'] !== null
            ? '/member/orders/' . $result['order_no']
            : '/order/' . $result['order_no'] . '/' . $result['token'];
        if ($method === 'billplz') {
            $pay = BillplzGateway::start((int) $result['order_id']);
            if (isset($pay['url'])) {
                redirect($pay['url']);
            }
            Session::flash('success', 'Your order has been placed, but the payment could not be started: ' . $pay['error'] . ' Use "Pay now" below to try again.');
            redirect($orderPage);
        }
        $thanks = 'Thank you! Your order has been placed.';
        if ($proof !== null && $proof['clean'] !== null) {
            try {
                ReceiptService::commit((int) $result['order_id'], $proof['clean'], $proof['info'], $_FILES['receipt'], $result['user_id'] !== null ? (int) $result['user_id'] : null);
                $thanks .= ' We have your payment receipt and will check it shortly.';
            } catch (\Throwable $e) {
                error_log('Receipt not saved for order ' . $result['order_no'] . ': ' . $e->getMessage());
                $thanks = 'Your order has been placed, but we could not save your receipt. Please upload it again below.';
            }
        }
        Session::flash('success', $thanks);
        redirect($orderPage);
    }

    /** Cart must exist, be non-empty and have no unavailable lines; guests need guest checkout on. */
    /** Apply a discount code to this checkout. Rate limited per network, so codes cannot be guessed in bulk. */
    public function applyVoucher(Request $request): void
    {
        if (RateLimiter::tooMany('voucher', $request->ip(), 20, 10)) {
            $this->backWithErrors('/checkout', ['voucher' => 'Too many codes tried. Please wait a few minutes.']);
        }
        RateLimiter::hit('voucher', $request->ip());

        $code = VoucherService::normalize($request->str('code'));
        if ($code === '') {
            $this->backWithErrors('/checkout', ['voucher' => 'Enter a code.']);
        }
        $cart = CartService::summary();
        $q = VoucherService::quote($code, (int) $cart['subtotal_cents']);
        if (!$q['ok']) {
            $this->backWithErrors('/checkout', ['voucher' => $q['message']]);
        }
        Session::set('voucher', $code);
        $this->success('/checkout', 'Code applied. You save RM' . number_format($q['discount'] / 100, 2) . '.');
    }

    public function removeVoucher(Request $request): void
    {
        Session::forget('voucher');
        $this->success('/checkout', 'Code removed.');
    }

    private function readyCart(): array
    {
        $me = Auth::user();
        if (($me === null || $me['role'] !== 'member') && !Setting::guestCheckoutEnabled()) {
            $this->requireLogin();
        }
        $cart = CartService::summary();
        if ($cart['items'] === []) {
            $this->success('/cart', 'Your cart is empty.');
        }
        if ($cart['has_problem']) {
            $this->backWithErrors('/cart', ['form' => 'Some items changed. Please review your cart before checking out.']);
        }
        return $cart;
    }

    private function requireLogin(): never
    {
        Session::set('intended', '/checkout');
        $this->backWithErrors('/login', ['form' => 'Please log in to check out.']);
    }
}
