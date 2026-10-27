<?php
// Product reviews in the database: only a completed buyer may review, once; the average and count; hiding; deleting.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\ReviewService as R;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

$pid = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Review Mug', 'slug' => 'review-mug', 'sku' => 'RVW-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 50, 'status' => 'active']);

$member = function (string $name, string $email): int {
    DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', ?, ?, 'x', 'active')", [$name, $email]);
    return (int) DB::connection()->lastInsertId();
};
// A member who has bought the mug: a cart, checked out, then the order marked as the given status.
$buy = function (int $userId, string $status) use ($pid): void {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,?,?)', [$cart, $pid, null, 1]);
    $contact = ['name' => 'Buyer', 'email' => "buyer{$userId}@example.test", 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '', 'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
    $o = CheckoutService::place($cart, $contact, 'cod', $userId);
    DB::query('UPDATE orders SET status = ? WHERE id = ?', [$status, (int) $o['order_id']]);
};

$nur = $member('Nur Aisyah Binti Ali', 'nur@example.test');
$ben = $member('Ben Tan', 'ben@example.test');
$cat = $member('Cat Lee', 'cat@example.test');
$buy($nur, 'completed');
$buy($ben, 'completed');
$buy($cat, 'processing'); // not finished yet

check('a member with no order is not a buyer', R::stateFor($nur, $pid) === 'eligible' && R::stateFor($member('Dan', 'dan@example.test'), $pid) === 'not_buyer');
check('an order still in progress does not count', R::stateFor($cat, $pid) === 'not_buyer');
check('refused: not a buyer', is_string(R::create($cat, $pid, ['rating' => 5, 'title' => null, 'body' => 'Looks great, shipping soon.'])));

$first = R::create($nur, $pid, ['rating' => 5, 'title' => 'Lovely', 'body' => 'Sturdy and the glaze is lovely.']);
check('a completed buyer can review', is_int($first));
check('the review keeps the order it came from', (int) DB::query('SELECT order_id FROM product_reviews WHERE id = ?', [$first])->fetchColumn() > 0);
check('state after reviewing is reviewed', R::stateFor($nur, $pid) === 'reviewed');
$again = R::create($nur, $pid, ['rating' => 1, 'title' => null, 'body' => 'Changed my mind, a second go.']);
check('one review per product: second refused', is_string($again) && str_contains($again, 'already reviewed'));

$second = R::create($ben, $pid, ['rating' => 3, 'title' => null, 'body' => 'Fine for the price, a bit small.']);
$s = R::summary($pid);
check('average of 5 and 3 is 4.0', $s['avg'] === 4.0 && $s['count'] === 2);
check('star counts are right', $s['counts'][5] === 1 && $s['counts'][3] === 1 && $s['counts'][1] === 0);

$list = R::list($pid);
check('reviewers are shown as first name and initial', $list[0]['reviewer'] === 'Ben T.' && $list[1]['reviewer'] === 'Nur A.');
check('verified buyer flag is set', $list[0]['verified'] == 1);

R::setStatus($first, 'hidden');
check('a hidden review leaves the shop', R::summary($pid)['count'] === 1 && count(R::list($pid)) === 1);
R::setStatus($first, 'approved');
check('showing it again restores the average', R::summary($pid)['avg'] === 4.0);

R::delete($second);
check('deleting a review removes it', R::summary($pid)['count'] === 1);
check('card rating columns are on the storefront listing', (int) (Product::publicByIds([$pid])[0]['rating_count'] ?? -1) === 1);
reset_test_db(); // leave the test database clean: this test creates carts and orders
