<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryMovementService;
use App\Services\SkuService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Katalog produk (PRD Modul 1). Soft-deleted tidak ikut tampil kecuali
     * diminta lewat filter ?trashed=1.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'stock' => ['nullable', 'in:all,low,out'],
            'trashed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = $filters['per_page'] ?? 15;

        $query = Product::query()->with('category');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        match ($filters['stock'] ?? 'all') {
            'low' => $query->needingReorder(),
            'out' => $query->where('current_stock', 0),
            default => null,
        };

        $products = $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category?->only(['id', 'name']),
                'current_stock' => $product->current_stock,
                'reorder_point' => $product->reorder_point,
                'needs_reorder' => $product->needsReorder(),
                'supplier_count' => $product->suppliers_count ?? 0,
                'deleted_at' => $product->deleted_at?->toIso8601String(),
            ]);

        return Inertia::render('Products/Index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $filters['search'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'stock' => $filters['stock'] ?? 'all',
                'trashed' => $request->boolean('trashed'),
            ],
        ]);
    }

    public function create(Request $request, SkuService $sku): Response
    {
        $categories = Category::orderBy('name')->get(['id', 'name', 'sku_prefix']);

        // Daftar pemasok hanya relevan untuk kolom "Pemasok Stok Awal" pada
        // Inbound pembuka. Peran yang tidak berwenang mencatat stok tidak
        // boleh melihat kolom itu sama sekali -- mengirim daftar pemasok ke
        // sini membuat form terlihat punya kemampuan yang tidak ada, dan itu
        // persis jebakan yang baru saja dibongkar di halaman pergerakan stok.
        $bolehCatatStok = (bool) $request->user()?->isAdminGudang();

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'bolehCatatStok' => $bolehCatatStok,
            'suppliers' => $bolehCatatStok
                ? Supplier::orderBy('name')->get(['id', 'name'])
                : [],

            // Saran SKU per kategori, dihitung SEKALI saat halaman dirender --
            // bukan lewat endpoint yang dipanggil tiap kali operator memilih
            // kategori. Saran boleh basi kalau produk lain dibuat bersamaan;
            // validasi unique di server yang menjadi jaring pengamannya.
            'saranSku' => $sku->suggestFor($categories),
        ]);
    }

    /**
     * Buat produk baru.
     *
     * Stok pembuka dari form dicatat sebagai Inbound, bukan ditulis langsung ke
     * `products.current_stock`. Alasannya invarian proyek:
     * `SUM(inventory_movements.quantity) == products.current_stock`. Kalau stok
     * langsung ditulis, produk baru akan punya stok tetapi buku besarnya kosong —
     * Buku Besar tidak bisa menjelaskan asal-usul stok, dan jejaknya kosong.
     */
    public function store(StoreProductRequest $request, InventoryMovementService $movements): RedirectResponse
    {
        $validated = $request->validated();

        // `?? 0`, bukan akses langsung: untuk peran yang tidak berwenang
        // mencatat stok, `current_stock` tidak wajib dikirim sama sekali
        // (lihat StoreProductRequest). Tanpa `??` di sini, key yang tidak
        // ada jadi "Undefined array key" — HTTP 500, bukan pesan validasi.
        $stokPembuka = (int) ($validated['current_stock'] ?? 0);

        // `supplier_id` bukan kolom products — hanya untuk Inbound pembuka.
        $supplierId = $validated['supplier_id'] ?? null;
        unset($validated['supplier_id']);

        $product = Product::create([
            ...$validated,
            'current_stock' => 0,
        ]);

        if ($stokPembuka > 0) {
            $movements->recordInbound(
                product: $product,
                quantity: $stokPembuka,
                user: $request->user(),
                supplierId: $supplierId,
                referenceCode: 'OPENING',
                movementDate: now(),
            );
        }

        $pesan = $stokPembuka > 0
            ? "Produk {$product->name} berhasil ditambahkan dengan stok awal {$stokPembuka}."
            : "Produk {$product->name} berhasil ditambahkan.";

        return to_route('products.index')->with('success', $pesan);
    }

    public function edit(Product $product, SkuService $sku): Response
    {
        $product->load(['category', 'suppliers']);

        $categories = Category::orderBy('name')->get(['id', 'name', 'sku_prefix']);

        return Inertia::render('Products/Edit', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'current_stock' => $product->current_stock,
                'reorder_point' => $product->reorder_point,
                'suppliers' => $product->suppliers->map(fn ($supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'last_purchase_price' => $supplier->pivot->last_purchase_price,
                    'lead_time_days' => $supplier->pivot->lead_time_days,
                ]),
            ],
            'categories' => $categories,

            // Saran SKU dikirim juga ke halaman edit, tapi TIDAK dipakai mengisi
            // ulang field yang sudah terisi -- SKU yang sudah pernah terbit tidak
            // boleh berubah sendiri karena kategori diubah.
            'saranSku' => $sku->suggestFor($categories),

            // Halaman penuh untuk menambah / melepas pemasok ditangani
            // ProductSupplierController (route products.suppliers.index).
            'suppliersUrl' => route('products.suppliers.index', $product->id),
        ]);
    }

    /**
     * Ubah produk.
     *
     * `current_stock` sengaja TIDAK bisa dikirim saat edit — sudah ditangani oleh
     * StoreProductRequest dengan aturan `prohibited`. Penghapusan di bawah adalah
     * pertahanan kedua: kalau suatu saat aturan itu dilonggarkan, stok tetap tidak
     * bisa ditulis diam-diam lewat sini.
     */
    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        unset($validated['current_stock'], $validated['supplier_id']);

        $product->update($validated);

        return to_route('products.index')
            ->with('success', "Produk {$product->name} berhasil diperbarui.");
    }

    /**
     * Soft delete (PRD Modul 1: data tidak dihapus permanen).
     */
    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return to_route('products.index')
            ->with('success', "Produk {$product->name} telah diarsipkan (soft delete).");
    }

    public function restore(int $product): RedirectResponse
    {
        $restored = Product::onlyTrashed()->findOrFail($product);
        $restored->restore();

        return to_route('products.index')
            ->with('success', "Produk {$restored->name} berhasil dipulihkan.");
    }
}
