<?php
// DB-backed address book + wishlist checks. Runs only when DB_NAME ends in `_test` (see CheckoutDbTest).
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\User;
use App\Services\AddressService as A;
use App\Services\WishlistService as W;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$a = User::create('member', 'A', 'a@addr.test', 'Passw0rd!x');
$b = User::create('member', 'B', 'b@addr.test', 'Passw0rd!x');
$addr = fn(string $l1) => ['label' => '', 'name' => 'A', 'phone' => '0123456789', 'line1' => $l1, 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000'];

check('valid address has no errors', A::validate($addr('1 Jalan')) === []);
check('bad phone/postcode/state rejected', array_keys(A::validate(['phone' => 'abc', 'postcode' => '12', 'state' => 'Narnia'] + $addr('x'))) === ['phone', 'postcode', 'state']);

$id1 = A::create($a, $addr('1 Jalan'));
check('first address becomes default', (int) A::find($a, $id1)['is_default'] === 1);
$id2 = A::create($a, $addr('2 Jalan'), true);
check('new default replaces old (exactly one default)', (int) DB::query('SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1', [$a])->fetchColumn() === 1 && (int) A::find($a, $id2)['is_default'] === 1);
check('other member cannot read/update/delete/default it', A::find($b, $id1) === null && !A::update($b, $id1, $addr('hax')) && !A::delete($b, $id1) && !A::makeDefault($b, $id1) && A::find($a, $id1)['line1'] === '1 Jalan');
check('deleting the default promotes another', A::delete($a, $id2) && (int) A::find($a, $id1)['is_default'] === 1);
check('saveFromOrder skips a duplicate (line1+postcode, case-insensitive)', (A::saveFromOrder($a, $addr('1 JALAN')) ?? true) && A::count($a) === 1);
for ($i = A::count($a); $i < A::MAX; $i++) { A::create($a, $addr("{$i} Lorong")); }
check('limit enforced', A::count($a) === A::MAX && A::create($a, $addr('overflow')) === null);

$p1 = Product::insert(['name' => 'P1', 'slug' => 'p1', 'base_price' => '1', 'company_base_price' => '2', 'retail_price' => '3', 'stock' => 1, 'status' => 'active']);
$p2 = Product::insert(['name' => 'P2', 'slug' => 'p2', 'base_price' => '1', 'company_base_price' => '2', 'retail_price' => '3', 'stock' => 1, 'status' => 'draft']);
check('toggle adds, then removes', W::toggle($a, $p1) === true && W::has($a, $p1) && W::toggle($a, $p1) === false && !W::has($a, $p1));
check('draft or missing product cannot be added', W::toggle($a, $p2) === null && W::toggle($a, 99999) === null);
W::toggle($a, $p1);
check('wishlists are per member', W::products($b) === [] && count(W::products($a)) === 1);
DB::query("UPDATE products SET status = 'draft' WHERE id = ?", [$p1]);
check('product taken off sale disappears from the wishlist page', W::products($a) === []);
