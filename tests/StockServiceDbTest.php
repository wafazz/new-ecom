<?php
// Stock Control: adjustments, the movement log, the item list, and the hooks from checkout / cancellation. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\StockService as S;
use App\Services\VariantService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM categories'); DB::query('DELETE FROM stock_movements');
DB::query("INSERT INTO categories (name, slug) VALUES ('Home', 'home')");
$home = (int) DB::query("SELECT id FROM categories WHERE slug = 'home'")->fetchColumn();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Ann Admin', 'ann@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$mk = fn(string $name, string $sku, int $stock, string $status = 'active', ?int $cat = null) => Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => $sku, 'category_id' => $cat, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'stock' => $stock, 'status' => $status]);
$mug = $mk('Mug', 'MUG-1', 10, 'active', $home);
$gone = $mk('Old Lamp', 'LAMP-1', 4);
Product::softDelete($gone);
$tee = $mk('Tee', 'TEE-P', 0);
$rows = VariantService::validateRows([
    ['id' => 0, 'sku' => 'TEE-S', 'option' => 'Size', 'value' => 'S', 'price' => '', 'stock' => '6'],
    ['id' => 0, 'sku' => 'TEE-M', 'option' => 'Size', 'value' => 'M', 'price' => '', 'stock' => '0'],
], ['base_price' => '5.00', 'company_base_price' => '10.00', 'retail_price' => '30.00', 'discount_price' => null], $tee);
VariantService::saveRows($tee, $rows['rows']);
[$vS, $vM] = array_map('intval', array_column(VariantService::variants($tee), 'id'));
$stock = fn(int $id) => (int) Product::find($id)['stock'];
$vstock = fn(int $id) => (int) DB::query('SELECT stock FROM product_variants WHERE id = ?', [$id])->fetchColumn();
$moves = fn(string $w = '1=1') => DB::query("SELECT * FROM stock_movements WHERE {$w} ORDER BY id")->fetchAll();

// ---- adjusting a single product
$r = S::adjust($mug, null, 'add', '5', 'restock', 'PO #77', $actor);
check('add stock', $r['ok'] && $r['before'] === 10 && $r['after'] === 15 && $stock($mug) === 15);
$m = $moves('product_id = ' . $mug)[0] ?? [];
check('the change is logged: who, why, before/after, note, sku', ($m['change_qty'] ?? 0) == 5 && $m['qty_before'] == 10 && $m['qty_after'] == 15 && $m['reason'] === 'restock' && $m['note'] === 'PO #77' && (int) $m['actor_id'] === $actor && $m['sku'] === 'MUG-1');
check('remove stock', S::adjust($mug, null, 'remove', '3', 'damaged', null, $actor)['after'] === 12 && $stock($mug) === 12);
check('set an exact count', S::adjust($mug, null, 'set', '40', 'correction', 'cycle count', $actor)['after'] === 40 && $stock($mug) === 40);
$n = count($moves());
$r = S::adjust($mug, null, 'set', '40', 'correction', null, $actor);
check('setting the same number changes nothing and logs nothing', $r['ok'] && !$r['changed'] && count($moves()) === $n);
check('set to 0 is allowed', S::adjust($mug, null, 'set', '0', 'damaged', null, $actor)['after'] === 0);

// ---- refusals
$bad = fn(array $a) => S::adjust(...$a)['ok'] === false;
S::adjust($mug, null, 'set', '3', 'correction', null, $actor);
check('cannot remove more than is in stock (and nothing changes)', $bad([$mug, null, 'remove', '4', 'damaged', null, $actor]) && $stock($mug) === 3);
check('quantity must be a whole number', $bad([$mug, null, 'add', '-1', 'restock', null, $actor]) && $bad([$mug, null, 'add', '1.5', 'restock', null, $actor]) && $bad([$mug, null, 'add', 'abc', 'restock', null, $actor]) && $bad([$mug, null, 'add', '', 'restock', null, $actor]) && $bad([$mug, null, 'add', '5; DROP', 'restock', null, $actor]));
check('add / remove need 1 or more', $bad([$mug, null, 'add', '0', 'restock', null, $actor]) && $bad([$mug, null, 'remove', '0', 'damaged', null, $actor]));
check('huge quantities are refused', $bad([$mug, null, 'add', '99999999', 'restock', null, $actor]) && $bad([$mug, null, 'set', '1000001', 'correction', null, $actor]));
check('unknown mode / reason (including system reasons) are refused', $bad([$mug, null, 'multiply', '2', 'restock', null, $actor]) && $bad([$mug, null, 'add', '1', 'nonsense', null, $actor]) && $bad([$mug, null, 'add', '1', 'order', null, $actor]));
check('over-long note is refused', $bad([$mug, null, 'add', '1', 'restock', str_repeat('x', 256), $actor]));
check('deleted / unknown products are refused', $bad([$gone, null, 'add', '1', 'restock', null, $actor]) && $bad([999999, null, 'add', '1', 'restock', null, $actor]));
check('a product with variations must say which variation; a plain one cannot take a variant id', $bad([$tee, null, 'add', '1', 'restock', null, $actor]) && $bad([$mug, $vS, 'add', '1', 'restock', null, $actor]) && $bad([$tee, 999999, 'add', '1', 'restock', null, $actor]));

// ---- variations have their own stock
check('adjusting one variation leaves the other alone', S::adjust($tee, $vM, 'add', '8', 'restock', null, $actor)['after'] === 8 && $vstock($vM) === 8 && $vstock($vS) === 6);
$mv = $moves('variant_id = ' . $vM)[0];
check('variation movements keep the variation label and SKU', $mv['label'] === 'M' && $mv['sku'] === 'TEE-M');
check('a variation of another product is refused', $bad([$mug, $vS, 'add', '1', 'restock', null, $actor]));

// ---- the item list: one group per product
$all = S::items([], 1, 50);
$gnames = array_column($all['groups'], 'name');
check('one group per product: a plain product, a product with variations (listed once); deleted products absent', $all['total'] === 2 && $gnames === ['Mug', 'Tee']);
[$gMug, $gTee] = $all['groups'];
check('a plain product is a group of one', $gMug['single'] === true && $gMug['sku'] === 'MUG-1' && count($gMug['units']) === 1 && $gMug['stock'] === 3);
check('a product with variations carries all its variations and their summed stock', $gTee['single'] === false && $gTee['variations'] === 2 && $gTee['stock'] === 14 && array_column($gTee['units'], 'variation') === ['M', 'S'] && $gTee['sku'] === null);
check('group level counts (variations out / low)', $gTee['out'] === 0 && $gTee['low'] === 0 && $gMug['low'] === 1);
$col = fn(array $rows, string $k) => array_map('intval', array_column($rows, $k));
check('level filter lists a product when ANY variation matches, and flags which variations matched', (function () use ($tee, $vS) {
    S::adjust($tee, $vS, 'set', '2', 'correction', null, null);
    $g = S::items(['level' => 'low'], 1, 50)['groups'];
    $tg = array_values(array_filter($g, fn($x) => $x['name'] === 'Tee'))[0] ?? null;
    $r = $tg !== null && count($tg['units']) === 2 && $tg['matches'] === 1 && array_column(array_filter($tg['units'], fn($u) => $u['match']), 'variation') === ['S'] && $tg['low'] === 1 && $tg['stock'] === 10;
    S::adjust($tee, $vS, 'set', '6', 'correction', null, null);
    return $r;
})());
check('"ok" and "out" level filters', array_column(S::items(['level' => 'ok'], 1, 50)['groups'], 'name') === ['Tee'] && S::items(['level' => 'out'], 1, 50)['total'] === 0);
check('search matches name, variation and SKU (a variation hit still lists the whole product)', S::items(['q' => 'tee'], 1, 50)['total'] === 1 && count(S::items(['q' => 'TEE-M'], 1, 50)['groups'][0]['units']) === 2 && S::items(['q' => 'TEE-M'], 1, 50)['groups'][0]['matches'] === 1 && array_column(S::items(['q' => 'M'], 1, 50)['groups'], 'name') === ['Mug', 'Tee']);
check('category and product filters', array_column(S::items(['category' => $home], 1, 50)['groups'], 'name') === ['Mug'] && S::items(['product' => $tee], 1, 50)['total'] === 1);
check('sorting: lowest stock first uses the emptiest variation; highest uses the total', array_column(S::items(['sort' => 'stock_asc'], 1, 50)['groups'], 'name') === ['Mug', 'Tee'] && array_column(S::items(['sort' => 'stock_desc'], 1, 50)['groups'], 'name') === ['Tee', 'Mug']);
check('pagination counts products, not variations', S::items([], 1, 1)['total'] === 2 && count(S::items([], 1, 1)['groups']) === 1 && S::items([], 2, 1)['groups'][0]['name'] === 'Tee');
$sum = S::summary();
check('summary still counts sellable units: items, units, ok / low / out', $sum === ['items' => 3, 'units' => 17, 'ok' => 2, 'low' => 1, 'out' => 0]);
check('unit() finds a plain product and a variation', S::unit($mug, null)['name'] === 'Mug' && S::unit($tee, $vS)['variation'] === 'S' && S::unit($tee, null) === null);

// ---- history
check('history for one item, newest first', $col(S::history(['product' => $mug], 1)['rows'], 'qty_after') === [3, 0, 40, 12, 15]);
check('history for one variation only', S::history(['product' => $tee, 'variant' => $vM], 1)['total'] === 1);
check('history filter by reason; "manual" excludes system lines', S::history(['reason' => 'damaged'], 1)['total'] === 2 && S::history(['reason' => 'manual'], 1)['total'] === count($moves()));
check('history shows the actor name', S::history(['product' => $mug], 1)['rows'][0]['actor_name'] === 'Ann Admin');
// ---- the movement page: more filters + totals
$dirIn = S::history(['dir' => 'in'], 1)['rows']; $dirOut = S::history(['dir' => 'out'], 1)['rows'];
check('direction filter: in = positive changes only, out = negative only', $dirIn !== [] && count(array_filter($dirIn, fn($r) => (int) $r['change_qty'] <= 0)) === 0 && $dirOut !== [] && count(array_filter($dirOut, fn($r) => (int) $r['change_qty'] >= 0)) === 0);
check('search by product name, SKU and variation label', S::history(['q' => 'mug'], 1)['total'] === count($moves('product_id = ' . $mug)) && S::history(['q' => 'TEE-M'], 1)['total'] === 1 && S::history(['q' => 'zzz-none'], 1)['total'] === 0);
check('search treats % and _ literally', S::history(['q' => '%'], 1)['total'] === 0);
$today = date('Y-m-d'); $yesterday = date('Y-m-d', strtotime('-1 day')); $tomorrow = date('Y-m-d', strtotime('+1 day'));
check('date range: today includes everything just logged; "to" yesterday or "from" tomorrow excludes it', S::history(['from' => $today, 'to' => $today], 1)['total'] === count($moves()) && S::history(['to' => $yesterday], 1)['total'] === 0 && S::history(['from' => $tomorrow], 1)['total'] === 0);
DB::query("UPDATE stock_movements SET created_at = ? WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM stock_movements) x)", [$yesterday . ' 23:59:59']);
check('date range edges: "to" is inclusive of the whole day, "from" starts at midnight', S::history(['to' => $yesterday], 1)['total'] === 1 && S::history(['from' => $today], 1)['total'] === count($moves()) - 1);
check('changed-by filter: a person, or the system (orders)', S::history(['actor' => (string) $actor], 1)['total'] > 0 && count(array_filter(S::history(['actor' => (string) $actor], 1)['rows'], fn($r) => (int) $r['actor_id'] !== $actor)) === 0 && S::history(['actor' => 'system'], 1)['total'] === count($moves('actor_id IS NULL')));
check('filters combine', S::history(['product' => $mug, 'dir' => 'in', 'reason' => 'restock'], 1)['total'] === 1);
$ms = S::movementSummary(['product' => $mug]);
check('movement summary: count, units in, units out, net (matches the rows)', $ms['count'] === count($moves('product_id = ' . $mug)) && $ms['in'] - $ms['out'] === $ms['net'] && $ms['net'] === (int) $stock($mug) - 10 && $ms['in'] === 5 + 28 + 3 && $ms['out'] === 3 + 40);
check('summary follows the same filters as the list', S::movementSummary(['dir' => 'out'])['in'] === 0 && S::movementSummary(['reason' => 'zzz'])['count'] === S::movementSummary([])['count']);
check('actors list = people who changed stock', array_column(S::actors(), 'name') === ['Ann Admin']);

// ---- orders move stock and are logged
$cart = function (array $lines) {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $id = (int) DB::connection()->lastInsertId();
    foreach ($lines as [$p, $v, $q]) { DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,?,?)', [$id, $p, $v, $q]); }
    return $id;
};
$contact = ['name' => 'Buyer', 'email' => 'b@example.test', 'phone' => '0123', 'line1' => '1 Rd', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
S::adjust($mug, null, 'set', '10', 'correction', null, $actor);
$o = CheckoutService::place($cart([[$mug, null, 3], [$tee, $vS, 2]]), $contact, 'cod', null);
check('placing an order deducts stock and logs one "order" movement per line, linked to the order', $stock($mug) === 7 && $vstock($vS) === 4 && count($moves("reason = 'order' AND order_id = " . $o['order_id'])) === 2);
$line = $moves("reason = 'order' AND product_id = $mug")[0];
check('the order movement records before / after and a negative change', $line['change_qty'] == -3 && $line['qty_before'] == 10 && $line['qty_after'] == 7 && $line['actor_id'] === null);
check('an order cannot take stock below zero', (function () use ($cart, $contact, $mug) { try { CheckoutService::place($cart([[$mug, null, 99]]), $contact, 'cod', null); return false; } catch (\App\Services\CheckoutException) { return true; } })() && $stock($mug) === 7);
$err = OrderService::transition((int) $o['order_id'], 'cancelled', $actor, 'test');
check('cancelling puts the stock back and logs "cancel" movements', $err === null && $stock($mug) === 10 && $vstock($vS) === 6 && count($moves("reason = 'cancel' AND order_id = " . $o['order_id'])) === 2);
check('history links order movements to the order number', S::history(['reason' => 'order'], 1)['rows'][0]['order_no'] === $o['order_no']);

// ---- system-level guard and cleanup behaviour
check('delta() refuses to go negative', (function () use ($mug) { try { S::delta($mug, null, -999, 'order'); return false; } catch (\DomainException $e) { return str_contains($e->getMessage(), 'Only'); } })() && $stock($mug) === 10);
check('movements are removed with their product (no orphans)', (function () use ($tee) { DB::query('DELETE FROM products WHERE id = ?', [$tee]); return (int) DB::query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$tee])->fetchColumn() === 0; })());
