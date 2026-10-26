<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Database;
use App\Core\Request;
use App\Core\Str;
use App\Core\View;
use App\Core\Validator;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AffiliateService;
use App\Services\DigitalService;
use App\Core\Response;
use App\Services\PricingService;
use App\Services\ProductAdminService;
use App\Services\ProductImageService;
use App\Services\RichText;
use App\Services\VariantService;

final class ProductController extends ManageController
{
    private const PER_PAGE = 15;

    public function index(Request $request): void
    {
        $page = max(1, (int) $request->input('page', 1));
        $q = trim($request->str('q'));
        $categoryId = (int) $request->input('category', 0) ?: null;
        $brandId = (int) $request->input('brand', 0) ?: null;
        $status = in_array($request->str('status'), ['active', 'draft'], true) ? $request->str('status') : '';
        $stock = in_array($request->str('stock'), ['out', 'low'], true) ? $request->str('stock') : '';
        $sort = array_key_exists($request->str('sort'), Product::ADMIN_SORTS) ? $request->str('sort') : 'newest';

        $result = Product::paginate($q, $categoryId, $status, $page, self::PER_PAGE, $stock, $sort, $brandId);
        $data = [
            'title' => 'Products',
            'base' => $this->base(),
            'isAdmin' => $this->isAdmin(),
            'products' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => ['q' => $q, 'category' => $categoryId, 'brand' => $brandId, 'status' => $status, 'stock' => $stock, 'sort' => $sort],
            'categories' => Category::options(),
            'brands' => Brand::options(),
            'counts' => Product::counts(),
            'sorts' => Product::ADMIN_SORTS,
            'scripts' => ['products.js', 'variations.js', 'product-images.js', 'digital-product.js', 'rich-text.js'],
            'vendor' => ['quill'],
        ];
        if ($request->str('partial') === '1') {
            echo View::render('manage/products/_list', $data, null); // fragment for the live list
            return;
        }
        $this->view('manage/products/index', $data, 'portal');
    }

    public function create(Request $request): void
    {
        $this->view($request->str('modal') === '1' ? 'manage/products/_modal' : 'manage/products/create', [
            'isNew' => true,
            'title' => 'Add product',
            'base' => $this->base(),
            'isAdmin' => $this->isAdmin(),
            'categories' => Category::options(),
            'brands' => Brand::options(),
            'productType' => 'single',
            'variantRows' => [],
            'images' => [],
            'scripts' => ['variations.js', 'product-images.js', 'digital-product.js', 'rich-text.js'],
            'vendor' => ['quill'],
            'product' => ['name' => '', 'slug' => '', 'sku' => '', 'description' => '', 'category_id' => '', 'status' => 'draft', 'stock' => 0,
                'base_price' => '', 'company_base_price' => '', 'retail_price' => '', 'discount_price' => '', 'discount_start' => null, 'discount_end' => null, 'commission_rate' => null],
        ], $request->str('modal') === '1' ? null : 'portal');
    }

    public function store(Request $request): void
    {
        [$data, $errors] = $this->collect($request, null);
        [$type, $rows, $errors] = $this->variations($request, $data, $errors, null);
        ['plan' => $images, 'errors' => $imageErrors] = ProductImageService::plan(null, $request->input('image_order'), $_FILES['images'] ?? null);
        $errors += $imageErrors;
        if ($errors !== []) {
            $this->backWithErrors($this->base() . '/products/create', $errors, $request->body);
        }
        $data['slug'] = Str::uniqueSlug('products', 'slug', $data['slug'] ?: Str::slug($data['name']));
        $data['stock'] = 0; // stock is set on the Stock Control page, never from this form
        try {
            $id = $this->persist(null, $data, $type, $rows, $images, $this->digitalInput($request));
        } catch (\RuntimeException $e) {
            $this->storageFailed($e, $this->base() . '/products/create', $request);
        }
        $this->success($this->base() . "/products/{$id}/edit", 'Product created.');
    }

    public function edit(Request $request, string $id): void
    {
        $product = $this->product($id);
        $variants = VariantService::variants((int) $product['id']);
        $this->view($request->str('modal') === '1' ? 'manage/products/_modal' : 'manage/products/edit', [
            'isNew' => false,
            'title' => 'Edit ' . $product['name'],
            'base' => $this->base(),
            'isAdmin' => $this->isAdmin(),
            'product' => $product,
            'categories' => Category::options(),
            'brands' => Brand::options(),
            'images' => Product::images((int) $product['id']),
            'productType' => $variants === [] ? 'single' : 'variable',
            'stockTotal' => $variants === [] ? (int) $product['stock'] : (int) array_sum(array_column($variants, 'stock')),
            'variantRows' => VariantService::rows((int) $product['id']),
            'scripts' => ['variations.js', 'product-images.js', 'digital-product.js', 'rich-text.js'],
            'vendor' => ['quill'],
        ], $request->str('modal') === '1' ? null : 'portal');
    }

    public function update(Request $request, string $id): void
    {
        $product = $this->product($id);
        $back = $this->base() . "/products/{$product['id']}/edit";
        [$data, $errors] = $this->collect($request, $product);
        [$type, $rows, $errors] = $this->variations($request, $data, $errors, (int) $product['id']);
        ['plan' => $images, 'errors' => $imageErrors] = ProductImageService::plan((int) $product['id'], $request->input('image_order'), $_FILES['images'] ?? null);
        $errors += $imageErrors;
        if ($errors !== []) {
            $this->backWithErrors($back, $errors, $request->body);
        }

        $data['slug'] = Str::uniqueSlug('products', 'slug', $data['slug'] ?: Str::slug($data['name']), (int) $product['id']);
        try {
            $this->persist((int) $product['id'], $data, $type, $rows, $images, $this->digitalInput($request));
        } catch (\RuntimeException $e) {
            $this->storageFailed($e, $back, $request);
        }
        $this->success($back, 'Product saved.');
    }

    /**
     * Product type + variation rows from the form. "variable" needs valid rows; the product's own SKU and
     * stock are then unused (each variation has its own), so they are cleared.
     *
     * @return array{0: string, 1: array, 2: array} [type, rows, errors]
     */
    private function variations(Request $request, array &$data, array $errors, ?int $productId): array
    {
        if ($request->str('product_type') !== 'variable' || $request->str('product_kind') === 'digital') {
            return ['single', [], $errors]; // digital products have no variations
        }
        unset($errors['sku']);
        $data['sku'] = null;
        $priceFields = array_intersect_key($errors, array_flip(['base_price', 'company_base_price', 'retail_price', 'discount_price']));
        $prices = $priceFields === [] ? array_intersect_key($data, array_flip(['base_price', 'company_base_price', 'retail_price', 'discount_price'])) : null;
        $v = VariantService::validateRows($request->input('variants'), $prices, $productId);
        return ['variable', $v['rows'], $errors + $v['errors']];
    }

    /** The digital file and the license keys from the form (only the ones the chosen delivery uses). */
    private function digitalInput(Request $request): array
    {
        $digital = $request->str('product_kind') === 'digital';
        return [
            'upload' => $digital && $request->str('digital_delivery') === 'file' ? $this->digitalUpload() : null,
            'keys' => $digital && $request->str('digital_delivery') === 'keys' ? DigitalService::parseKeys($request->str('license_keys')) : [],
        ];
    }

    /** The file picked for "Uploaded file", or null when none was picked. Size and upload errors are reported on the form. */
    private function digitalUpload(): ?array
    {
        $file = $_FILES['digital_file'] ?? null;
        return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /** A whole number in range, or null. */
    private static function whole(string $raw, int $min, int $max): ?int
    {
        return preg_match('/^\d{1,5}$/', $raw) === 1 && (int) $raw >= $min && (int) $raw <= $max ? (int) $raw : null;
    }

    /** Save the product, its variations and its images together, or none of them. */
    /** The photos could not be written to disk, usually because the uploads folder is not writable. Log the reason, and tell the admin in plain words. */
    private function storageFailed(\RuntimeException $e, string $back, Request $request): never
    {
        if ($e instanceof \PDOException) {
            throw $e; // a database problem is not a storage problem: let it show as an error
        }
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] product files: ' . $e->getMessage() . ' (check that public/uploads and storage/digital can be written to by the web server user)' . "\n", FILE_APPEND | LOCK_EX);
        $this->backWithErrors($back, ['images' => 'The files could not be saved on the server. The shop developer needs to check that the uploads and storage folders can be written to.'], $request->body);
    }

    private function persist(?int $id, array $data, string $type, array $rows, ?array $images, array $digital = ['upload' => null, 'keys' => []]): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $written = ['unlink' => [], 'stored' => []];
        $storedFile = null;
        try {
            if ($digital['upload'] !== null) {
                $storedFile = DigitalService::storeFile($digital['upload']);
                $data['digital_file_path'] = $storedFile['path'];
                $data['digital_file_name'] = $storedFile['name'];
            }
            if ($id === null) {
                $id = Product::insert($data);
            } else {
                Product::update($id, $data);
            }
            if ($digital['keys'] !== []) {
                DigitalService::addKeys($id, $digital['keys']);
            }
            $type === 'variable' ? VariantService::saveRows($id, $rows) : VariantService::clear($id);
            if ($images !== null) {
                $written = ProductImageService::apply($id, $images);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ($written['stored'] as $path) {
                \App\Services\UploadService::delete($path); // files written for a save that did not happen
            }
            if ($storedFile !== null) {
                DigitalService::deleteFile($storedFile['path']);
            }
            throw $e;
        }
        foreach ($written['unlink'] as $path) {
            \App\Services\UploadService::delete($path); // removed images go only once the save is final
        }
        return $id;
    }

    /** Inline edits from the list (AJAX): status. Stock is only changed on the Stock Control page. */
    public function quick(Request $request, string $id): void
    {
        $field = $request->str('field');
        $r = match ($field) {
            'status' => ProductAdminService::setStatus((int) $id, $request->str('value')),
            default => ['ok' => false, 'error' => 'Unknown field.'],
        };
        Response::json($r + ['message' => $r['error'] ?? 'Saved.'], $r['ok'] ? 200 : 422);
    }

    public function bulk(Request $request): void
    {
        $r = ProductAdminService::bulk($request->str('action'), $request->input('ids'));
        $word = ['activate' => 'activated', 'draft' => 'moved to draft', 'delete' => 'deleted'][$request->str('action')] ?? 'updated';
        Response::json($r + ['message' => $r['ok'] ? "{$r['count']} product(s) {$word}." : $r['error']], $r['ok'] ? 200 : 422);
    }

    public function destroy(Request $request, string $id): void
    {
        $product = $this->product($id);
        Product::softDelete((int) $product['id']);
        $this->success($this->base() . '/products', "\"{$product['name']}\" deleted.");
    }

    /**
     * Validate the product form. Staff never see or change base_price (cost): on create it is
     * seeded from the company base price (admin can correct it), on edit it is left as-is.
     *
     * @return array{0: array, 1: array} [columns, errors]
     */
    private function collect(Request $request, ?array $existing): array
    {
        $errors = (new Validator($request->body, [
            'name' => 'required|max:190',
            'slug' => 'max:200',
            'sku' => 'max:60',
        ]))->errors();

        $in = [
            'base_price' => $this->isAdmin() ? $request->str('base_price') : ($existing['base_price'] ?? $request->str('company_base_price')),
            'company_base_price' => $request->str('company_base_price'),
            'retail_price' => $request->str('retail_price'),
            'discount_price' => $request->str('discount_price'),
        ];
        $price = PricingService::validate($in);
        $errors += $price['errors'];
        [$start, $end, $windowErrors] = PricingService::window($request->str('discount_start'), $request->str('discount_end'));
        $errors += $windowErrors;

        $categoryId = (int) $request->input('category_id', 0) ?: null;
        if ($categoryId !== null && Category::find($categoryId) === null) {
            $errors['category_id'] = 'Unknown category.';
        }

        $brandId = (int) $request->input('brand_id', 0) ?: null;
        if ($brandId !== null && Brand::find($brandId) === null) {
            $errors['brand_id'] = 'Unknown brand.';
        }

        $description = RichText::clean($request->str('description')); // editor HTML -> allowlisted HTML
        if (strlen($description) > RichText::MAX_HTML) {
            $errors['description'] = 'The description is too long. Shorten it a little.';
        }

        $sku = trim($request->str('sku'));
        $sku = $sku === '' ? null : $sku;
        if ($sku !== null && !isset($errors['sku'])) {
            $sql = 'SELECT COUNT(*) FROM products WHERE sku = ?' . ($existing ? ' AND id <> ?' : '');
            $dupe = (int) Database::query($sql, $existing ? [$sku, $existing['id']] : [$sku])->fetchColumn();
            $dupe += (int) Database::query('SELECT COUNT(*) FROM product_variants WHERE sku = ?', [$sku])->fetchColumn();
            if ($dupe > 0) {
                $errors['sku'] = 'That SKU is already in use.';
            }
        }

        // Commission override is admin-only; staff edits keep whatever is stored.
        $rate = $existing['commission_rate'] ?? null;
        if ($this->isAdmin()) {
            $raw = trim($request->str('commission_rate'));
            if ($raw === '') {
                $rate = null;
            } elseif (AffiliateService::isRate($raw)) {
                $rate = PricingService::format(PricingService::cents($raw));
            } else {
                $errors['commission_rate'] = 'Commission rate must be 0-100, or blank for the default.';
            }
        }

        // Digital products are not shipped: no weight, no size, and they are delivered by download or license key.
        $digital = $request->str('product_kind') === 'digital';
        $delivery = null;
        $link = null;
        $expiry = null;
        $maxDownloads = null;
        if ($digital) {
            $delivery = $request->str('digital_delivery');
            if (!isset(DigitalService::DELIVERIES[$delivery])) {
                $errors['digital_delivery'] = 'Choose how the buyer gets the product.';
                $delivery = null;
            }
            if ($delivery === 'file' && $this->digitalUpload() === null && ($existing['digital_file_path'] ?? null) === null) {
                $errors['digital_file'] = 'Upload the file the buyer will download.';
            }
            if ($delivery === 'link') {
                $link = trim($request->str('digital_link'));
                if (!DigitalService::validLink($link)) {
                    $errors['digital_link'] = 'Enter a full web address that starts with https:// or http://.';
                }
            }
            $expiry = self::whole(trim($request->str('digital_expiry_days')), 1, 365);
            if ($expiry === null) {
                $errors['digital_expiry_days'] = 'Enter how many days the download stays open (1 to 365).';
            }
            $maxDownloads = self::whole(trim($request->str('digital_max_downloads')), 1, 100);
            if ($maxDownloads === null) {
                $errors['digital_max_downloads'] = 'Enter how many times the buyer can download (1 to 100).';
            }
        }

        // Weight and size are mandatory for physical products: shipping is worked out from them.
        $ship = [
            'weight_kg' => trim($request->str('weight_kg')),
            'width_cm' => trim($request->str('width_cm')),
            'length_cm' => trim($request->str('length_cm')),
            'height_cm' => trim($request->str('height_cm')),
        ];
        foreach ($digital ? [] : [['weight_kg', 'Weight (kg)', 100, 3], ['width_cm', 'Width (cm)', 300, 2], ['length_cm', 'Length (cm)', 300, 2], ['height_cm', 'Height (cm)', 300, 2]] as [$field, $label, $max, $decimals]) {
            $v = $ship[$field];
            if ($v === '' || preg_match('/^\d{1,4}(\.\d{1,' . $decimals . '})?$/', $v) !== 1 || (float) $v <= 0 || (float) $v > $max) {
                $errors[$field] = "{$label} is required: enter a number above 0, up to {$max}.";
            }
        }

        $status = $request->input('status') === 'active' ? 'active' : 'draft';
        $rawSlug = trim($request->str('slug'));

        return [[
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'name' => trim($request->str('name')),
            'slug' => $rawSlug === '' ? '' : Str::slug($rawSlug),
            'sku' => $sku,
            'description' => $description === '' ? null : $description,
            'base_price' => $price['values']['base_price'],
            'company_base_price' => $price['values']['company_base_price'],
            'retail_price' => $price['values']['retail_price'],
            'discount_price' => $price['values']['discount_price'],
            'discount_start' => $start,
            'discount_end' => $end,
            'commission_rate' => $rate,
            'weight_kg' => $digital ? null : $ship['weight_kg'],
            'width_cm' => $digital ? null : $ship['width_cm'],
            'length_cm' => $digital ? null : $ship['length_cm'],
            'height_cm' => $digital ? null : $ship['height_cm'],
            'status' => $status,
            'product_type' => $digital ? 'digital' : 'physical',
            'digital_delivery' => $digital ? $delivery : null,
            'digital_link' => $delivery === 'link' ? $link : null,
            'digital_expiry_days' => $digital ? $expiry : null,
            'digital_max_downloads' => $digital ? $maxDownloads : null,
            // A physical product drops its digital file; buyers who bought earlier keep their copy (digital_grants has its own).
            ...($digital ? [] : ['digital_file_path' => null, 'digital_file_name' => null]),
        ], $errors];
    }
}
