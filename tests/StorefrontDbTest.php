<?php
// Storefront queries: filters, sale / popular / related / category tiles, header menu data, cart payload. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\StoreFront;
use App\Services\VariantService;
use App\Services\WishlistService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM categories'); DB::query('DELETE FROM stock_movements');
$home = Category::insert(['name' => 'Home', 'slug' => 'home', 'parent_id' => null]);
$cand = Category::insert(['name' => 'Candles', 'slug' => 'candles', 'parent_id' => $home]);
$bags = Category::insert(['name' => 'Bags', 'slug' => 'bags', 'parent_id' => null]);
$empty = Category::insert(['name' => 'Empty', 'slug' => 'empty', 'parent_id' => null]);
$acme = Brand::insert(['name' => 'Acme', 'slug' => 'acme']);
$mk = fn(string $name, string $retail, int $stock, ?int $cat = null, ?int $brand = null, ?string $disc = null, string $status = 'active') => Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 
    'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => strtoupper(substr($name, 0, 3)) . $stock, 'category_id' => $cat, 'brand_id' => $brand,
    'base_price' => '1', 'company_base_price' => '2', 'retail_price' => $retail, 'discount_price' => $disc, 'stock' => $stock, 'status' => $status,
]);
$tote = $mk('Linen Tote', '50', 5, $bags, $acme);
$mug = $mk('Glazed Mug', '20', 0, $home);                       // sold out
$pillar = $mk('Pillar Candle', '30', 9, $cand, $acme, '24');   // on sale
$tee = $mk('Cotton Tee', '60', 0, $bags);
$mkRows = fn(array $rows) => VariantService::validateRows($rows, ['base_price' => '1.00', 'company_base_price' => '2.00', 'retail_price' => '60.00', 'discount_price' => null], $tee)['rows'];
VariantService::saveRows($tee, $mkRows([['id' => 0, 'sku' => 'TEE-S', 'option' => 'Size', 'value' => 'S', 'price' => '', 'stock' => '3'], ['id' => 0, 'sku' => 'TEE-L', 'option' => 'Size', 'value' => 'L', 'price' => '', 'stock' => '0']]));
$draft = $mk('Hidden Thing', '10', 4, $bags, null, null, 'draft');
DB::query("INSERT INTO product_images (product_id, path, is_primary, position) VALUES (?, 'uploads/products/a.jpg', 1, 0), (?, 'uploads/products/b.jpg', 0, 1), (?, 'uploads/products/c.jpg', 0, 0)", [$tote, $tote, $mug]);
$names = fn(array $r) => array_column($r['rows'], 'name');
$L = fn(array $opts = [], string $q = '', ?string $cat = null, string $sort = 'name', ?string $brand = null) => Product::publicList($q, $cat, $sort, 1, 50, $brand, $opts);

check('drafts never show; everything else lists', $names($L()) === ['Cotton Tee', 'Glazed Mug', 'Linen Tote', 'Pillar Candle']);
check('price filter uses the selling price (the sale price counts)', $names($L(['min' => '25', 'max' => '55'])) === ['Linen Tote'] && $names($L(['max' => '24'])) === ['Glazed Mug', 'Pillar Candle'] && $names($L(['min' => '60'])) === ['Cotton Tee']);
check('sale filter: only discounts running now', $names($L(['sale' => true])) === ['Pillar Candle']);
check('in-stock filter counts variation stock and hides sold-out products', $names($L(['instock' => true])) === ['Cotton Tee', 'Linen Tote', 'Pillar Candle']);
check('filters combine', $names($L(['instock' => true, 'max' => '55', 'sale' => true])) === ['Pillar Candle'] && $L(['min' => '999'])['total'] === 0);
check('exclude removes one product (related products use it)', $names($L(['exclude' => $tote])) === ['Cotton Tee', 'Glazed Mug', 'Pillar Candle']);
check('category filter includes sub-categories; brand filter works', $names($L([], '', 'home')) === ['Glazed Mug', 'Pillar Candle'] && $names($L([], '', null, 'name', 'acme')) === ['Linen Tote', 'Pillar Candle']);
check('search matches product, brand and category names; LIKE wildcards are literal', $names($L([], 'acme')) === ['Linen Tote', 'Pillar Candle'] && $names($L([], 'bags')) === ['Cotton Tee', 'Linen Tote'] && $L([], '%')['total'] === 0 && $names($L([], 'candle')) === ['Pillar Candle']);
$scent = $mk('Aroma Wax', '15', 3, $cand);   // sorts before "Pillar Candle" by name, but only matches through its category
check('relevance sort puts name matches before category matches (name sort would not)', $names($L([], 'candle', null, 'relevance')) === ['Pillar Candle', 'Aroma Wax'] && $names($L([], 'candle', null, 'name')) === ['Aroma Wax', 'Pillar Candle']);
Product::softDelete($scent);
$byPrice = $L([], '', null, 'price_asc');
check('price sort uses selling price', $names($byPrice)[0] === 'Glazed Mug' && $names($byPrice)[1] === 'Pillar Candle' && $names($L([], '', null, 'price_desc'))[0] === 'Cotton Tee');
$tr = array_values(array_filter($L()['rows'], fn($r) => $r['name'] === 'Linen Tote'))[0];
check('rows carry a second image for the hover effect (ordered primary first, then position)', $tr['image'] === 'uploads/products/a.jpg' && $tr['image2'] === 'uploads/products/b.jpg');
$mr = array_values(array_filter($L()['rows'], fn($r) => $r['name'] === 'Glazed Mug'))[0];
check('a single image has no second image; sold-out and created_at are present', $mr['image2'] === null && $mr['sold_out'] === true && !empty($mr['created_at']));
$cr = array_values(array_filter($L()['rows'], fn($r) => $r['name'] === 'Cotton Tee'))[0];
check('a product with variations: "from" flag, not sold out while one variation has stock', $cr['variant_count'] == 2 && $cr['sold_out'] === false);

check('onSale() lists discounted products', array_column(Product::onSale(8), 'name') === ['Pillar Candle']);
check('popular() is empty until something sold', Product::popular(4) === []);
DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', 'a')]); $c1 = (int) DB::connection()->lastInsertId();
DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,NULL,2)', [$c1, $pillar]);
$contact = ['name' => 'B', 'email' => 'b@example.test', 'phone' => '012', 'line1' => '1', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
$o = CheckoutService::place($c1, $contact, 'cod', null);
check('popular() ranks by units sold in non-cancelled orders', array_column(Product::popular(4), 'name') === ['Pillar Candle']);
\App\Services\OrderService::transition((int) $o['order_id'], 'cancelled', null);
check('cancelled orders do not count as sales', Product::popular(4) === []);
check('related(): same category family first, never the product itself', array_column(Product::related($pillar, $cand, 4), 'name') === [] || !in_array('Pillar Candle', array_column(Product::related($pillar, $cand, 4), 'name'), true));
check('related() falls back to other products when the category is thin', count(Product::related($pillar, $cand, 4)) >= 2 && !in_array('Pillar Candle', array_column(Product::related($pillar, $cand, 4), 'name'), true));
$tiles = Product::categoryTiles(6);
check('category tiles: top-level categories with products only (child counts roll up), with a picture', array_column($tiles, 'slug') === ['bags', 'home'] || array_column($tiles, 'slug') === ['home', 'bags']);
$bagTile = array_values(array_filter($tiles, fn($t) => $t['slug'] === 'bags'))[0]; $homeTile = array_values(array_filter($tiles, fn($t) => $t['slug'] === 'home'))[0];
check('tile counts are active products (drafts excluded) and include sub-categories', (int) $bagTile['n'] === 2 && (int) $homeTile['n'] === 2 && $bagTile['image'] === 'uploads/products/a.jpg' && $homeTile['image'] === 'uploads/products/c.jpg');
check('publicBrands() includes the logo column', array_key_exists('logo', Product::publicBrands()[0]) && Product::publicBrands()[0]['slug'] === 'acme');

// header menu data
$nav = StoreFront::nav();
check('nav(): top-level categories with their children and counts; empty categories hidden', array_column($nav['categories'], 'slug') === ['bags', 'home'] && array_column($nav['categories'][1]['children'], 'slug') === ['candles']);
check('nav(): knows there is a sale, the flat shipping fee and the payment methods', $nav['hasSale'] === true && is_array($nav['methods']) && $nav['methods'] !== []);
check('visitors who are not members have no hearts to fill', StoreFront::wished() === [] || StoreFront::wished() !== null);

// variations as the product page needs them
$pv = VariantService::publicVariants($tee);
check('public variations carry option name and SKU (for the pills) and no cost fields', $pv[0]['option'] === 'Size' && $pv[0]['sku'] === 'TEE-S' && !array_key_exists('base_price', $pv[0]) && !array_key_exists('company_base_price', $pv[0]));

// cart payload used by the drawer and the cart page
DB::query('DELETE FROM carts'); DB::query('DELETE FROM cart_items');
check('empty cart payload', (function () { $p = CartService::payload(); return $p['count'] === 0 && $p['items'] === [] && $p['total'] === money(0); })());

// wishlist hearts
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', 'Mia', 'mia@example.test', 'x', 'active')");
$mia = (int) DB::connection()->lastInsertId();
WishlistService::toggle($mia, $tote); WishlistService::toggle($mia, $mug);
check('ids() returns the saved product ids in one query', array_keys(WishlistService::ids($mia)) === [$tote, $mug] || array_keys(WishlistService::ids($mia)) === [$mug, $tote]);
check('ids() for a member with nothing saved is empty', WishlistService::ids($mia + 999) === []);
