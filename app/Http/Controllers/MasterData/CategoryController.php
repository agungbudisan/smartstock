<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? null;

        $query = Category::query()->withCount('products');

        if (! empty($search)) {
            $query->where('name', 'like', "%{$search}%");
        }

        return Inertia::render('Categories/Index', [
            'categories' => $query->orderBy('name')->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    // Prefix tampil di bawah nama supaya pola SKU kategori ini
                    // kelihatan tanpa harus membuka form ubah.
                    'sku_prefix' => $category->sku_prefix,
                    'description' => $category->description,
                    'products_count' => $category->products_count,
                ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Category::create($request->validated());

        return back()->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function update(StoreCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', 'Kategori berhasil diperbarui.');
    }

    /**
     * Menghapus kategori tidak boleh menghapus produk — FK nullOnDelete
     * membuat products.category_id jadi NULL.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $name = $category->name;
        $category->delete();

        return back()->with('success', "Kategori {$name} dihapus. Produk terkait kini tanpa kategori.");
    }
}
