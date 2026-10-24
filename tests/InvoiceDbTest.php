<?php
// Invoices: numbered, listing the items and totals, marked paid or unpaid, using the shop details and bank details, and
// repeating the table header across pages. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutService;
use App\Services\Invoice;
use App\Services\OrderService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'invoice.%' OR `key` LIKE 'bank_%' OR `key` = 'site.logo'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Inv Admin', 'inv@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$mug = Product::insert(['name' => 'Ceramic Mug', 'slug' => 'inv-mug', 'sku' => 'INV-MUG', 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '29', 'stock' => 100, 'status' => 'active']);
$cart = function (array $lines): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $id = (int) DB::connection()->lastInsertId();
    foreach ($lines as [$product, $qty]) {
        DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,?,?)', [$id, $product, null, $qty]);
    }
    return $id;
};
$contact = ['name' => 'Nur Aisyah', 'email' => 'aisyah@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '', 'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];

$o = CheckoutService::place($cart([[$mug, 2]]), $contact, 'cod', null);
$order = OrderService::find((int) $o['order_id']);
$pdf = Invoice::pdf($order);
check('the invoice is a PDF', str_starts_with($pdf, '%PDF-1.4'));
check('it carries the invoice number, INV-year-000000 style', preg_match('/^INV-\d{4}-\d{6}$/', Invoice::number($order)) === 1 && str_contains($pdf, '(' . Invoice::number($order) . ')'));
check('it lists the item and the total', str_contains($pdf, '(Ceramic Mug)') && str_contains($pdf, '(' . Invoice::money((float) $order['total']) . ')'));
check('an unpaid order is marked UNPAID', str_contains($pdf, '(UNPAID)') && !str_contains($pdf, '(PAID)'));

DB::query("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$order['id']]);
$paid = Invoice::pdf(OrderService::find((int) $order['id']));
check('a paid order is marked PAID, and says so', str_contains($paid, '(PAID)') && !str_contains($paid, '(UNPAID)') && str_contains($paid, '(Paid in full)'));

Invoice::saveDetails(['company' => 'Test Company Sdn Bhd', 'address' => "Lot 1, Jalan Test\n50000 Kuala Lumpur", 'phone' => '03-0000 0000', 'email' => 'info@example.test', 'reg_no' => '123', 'footer' => 'Thank you.'], $actor);
check('the shop details are saved', Invoice::details()['company'] === 'Test Company Sdn Bhd');
check('the saved shop name is printed on the invoice', str_contains(Invoice::pdf($order), '(Test Company Sdn Bhd)'));

Setting::set('bank_name', 'Maybank');
Setting::set('bank_account_holder', 'TEST COMPANY');
Setting::set('bank_account_no', '5123 4567 8901');
$b = CheckoutService::place($cart([[$mug, 1]]), $contact, 'bank_transfer', null);
$bankOrder = OrderService::find((int) $b['order_id']);
$bankPdf = Invoice::pdf($bankOrder);
check('a bank transfer invoice shows the bank details and the payment reference', str_contains($bankPdf, '(Pay by bank transfer)') && str_contains($bankPdf, '(Maybank)') && str_contains($bankPdf, '(Use ' . $bankOrder['order_no'] . ' as your payment reference.)'));

// A long order runs onto a second page, and the table header is repeated there.
$lines = [];
for ($i = 1; $i <= 14; $i++) {
    $lines[] = [Product::insert(['name' => "Long item {$i}", 'slug' => "long-{$i}", 'sku' => "LNG-{$i}", 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10', 'stock' => 100, 'status' => 'active']), 1];
}
$long = CheckoutService::place($cart($lines), $contact, 'cod', null);
$longPdf = Invoice::pdf(OrderService::find((int) $long['order_id']));
preg_match_all('/\/Count (\d+)/', $longPdf, $counts);
check('a long order runs onto more than one page', (int) max($counts[1] ?: [1]) >= 2);
check('the table header is repeated on the next page', substr_count($longPdf, '(UNIT PRICE)') >= 2);
check('the footers say which page is which', str_contains($longPdf, '(Page 1 of ') && str_contains($longPdf, '(Page 2 of '));
