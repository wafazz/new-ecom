<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Database;
use App\Core\Request;
use App\Core\Str;
use App\Core\Validator;
use App\Models\Brand;
use App\Services\UploadService;

final class BrandController extends ManageController
{
    public function index(Request $request): void
    {
        $this->view('manage/brands/index', [
            'title' => 'Brands',
            'base' => $this->base(),
            'brands' => Brand::allWithCounts(),
            'scripts' => ['brands.js'],
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $name = $this->validName($request, null);
        $logo = $this->uploadedLogo($request);
        Brand::insert(['name' => $name, 'slug' => Str::uniqueSlug('brands', 'slug', Str::slug($name)), 'logo' => $logo]);
        $this->success($this->base() . '/brands', 'Brand added.');
    }

    public function update(Request $request, string $id): void
    {
        $brand = Brand::find((int) $id) ?? \App\Core\Response::abort(404);
        $name = $this->validName($request, (int) $brand['id']);
        $data = ['name' => $name]; // slug stays, so shop URLs keep working
        $logo = $this->uploadedLogo($request);
        if ($logo !== null) {
            $data['logo'] = $logo;
        } elseif ($request->input('remove_logo') === '1') {
            $data['logo'] = null;
        }
        Brand::update((int) $brand['id'], $data);
        if (array_key_exists('logo', $data) && $brand['logo']) {
            UploadService::delete($brand['logo']); // old file goes only after the row points elsewhere
        }
        $this->success($this->base() . '/brands', 'Brand updated.');
    }

    public function destroy(Request $request, string $id): void
    {
        $brand = Brand::find((int) $id) ?? \App\Core\Response::abort(404);
        Brand::delete((int) $brand['id']); // products keep existing, brand set to NULL by FK
        if ($brand['logo']) {
            UploadService::delete($brand['logo']);
        }
        $this->success($this->base() . '/brands', "Brand \"{$brand['name']}\" deleted.");
    }

    /** The optional logo upload (JPG/PNG/WebP, 2 MB; SVG is refused on purpose). Null when none was sent. */
    private function uploadedLogo(Request $request): ?string
    {
        $files = UploadService::normalise($_FILES['logo'] ?? null);
        if ($files === []) {
            return null;
        }
        try {
            return UploadService::saveImage($files[0], 'brands');
        } catch (\InvalidArgumentException $e) {
            $this->backWithErrors($this->base() . '/brands', ['logo' => $e->getMessage()], $request->body);
        }
    }

    private function validName(Request $request, ?int $selfId): string
    {
        $name = trim($request->str('name'));
        $v = new Validator($request->body, ['name' => 'required|max:120']);
        if ($v->fails()) {
            $this->backWithErrors($this->base() . '/brands', $v->errors(), $request->body);
        }
        $dupe = (int) Database::query('SELECT COUNT(*) FROM brands WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $selfId ?? 0])->fetchColumn();
        if ($dupe > 0) {
            $this->backWithErrors($this->base() . '/brands', ['name' => 'That brand already exists.'], $request->body);
        }
        return $name;
    }
}
