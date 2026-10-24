<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Database;
use App\Core\Request;
use App\Core\Str;
use App\Core\Validator;
use App\Models\Category;

final class CategoryController extends ManageController
{
    public function index(Request $request): void
    {
        $this->view('manage/categories/index', [
            'title' => 'Categories',
            'base' => $this->base(),
            'categories' => Category::allWithCounts(),
            'parents' => Category::parents(),
            'scripts' => ['categories.js'],
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $name = trim($request->str('name'));
        $v = new Validator($request->body, ['name' => 'required|max:120']);
        if ($v->fails()) {
            $this->backWithErrors($this->base() . '/categories', $v->errors(), $request->body);
        }
        $dupe = (int) Database::query('SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?)', [$name])->fetchColumn();
        if ($dupe > 0) {
            $this->backWithErrors($this->base() . '/categories', ['name' => 'That category already exists.'], $request->body);
        }
        $parentId = $this->parentId($request, null);
        Category::insert(['name' => $name, 'slug' => Str::uniqueSlug('categories', 'slug', Str::slug($name)), 'parent_id' => $parentId]);
        $this->success($this->base() . '/categories', 'Category added.');
    }

    public function update(Request $request, string $id): void
    {
        $cat = Category::find((int) $id) ?? \App\Core\Response::abort(404);
        $name = trim($request->str('name'));
        $v = new Validator($request->body, ['name' => 'required|max:120']);
        if ($v->fails()) {
            $this->backWithErrors($this->base() . '/categories', $v->errors(), $request->body);
        }
        $dupe = (int) Database::query('SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, (int) $cat['id']])->fetchColumn();
        if ($dupe > 0) {
            $this->backWithErrors($this->base() . '/categories', ['name' => 'That category already exists.'], $request->body);
        }
        $parentId = $this->parentId($request, (int) $cat['id']);
        Category::update((int) $cat['id'], ['name' => $name, 'parent_id' => $parentId]); // slug stays, so shop URLs keep working
        $this->success($this->base() . '/categories', 'Category updated.');
    }

    public function destroy(Request $request, string $id): void
    {
        $cat = Category::find((int) $id) ?? \App\Core\Response::abort(404);
        Category::delete((int) $cat['id']); // products keep existing, category set to NULL by FK
        $this->success($this->base() . '/categories', "Category \"{$cat['name']}\" deleted.");
    }

    /** Optional parent: must be an existing top-level category, not the category itself, and a category with children cannot get one. */
    private function parentId(Request $request, ?int $selfId): ?int
    {
        $pid = (int) $request->input('parent_id', 0);
        if ($pid === 0) {
            return null;
        }
        $fail = fn(string $m) => $this->backWithErrors($this->base() . '/categories', ['parent_id' => $m], $request->body);
        $parent = Category::find($pid);
        if ($parent === null) {
            $fail('Unknown parent category.');
        }
        if ($selfId !== null && $pid === $selfId) {
            $fail('A category cannot be its own parent.');
        }
        if ($parent['parent_id'] !== null) {
            $fail('Pick a top-level category as the parent (categories go one level deep).');
        }
        if ($selfId !== null && (int) Database::query('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$selfId])->fetchColumn() > 0) {
            $fail('This category has sub-categories, so it cannot have a parent itself.');
        }
        return $pid;
    }
}
