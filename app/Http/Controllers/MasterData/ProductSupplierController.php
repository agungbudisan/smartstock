<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductSupplierRequest;
use App\Http\Requests\SyncProductSuppliersRequest;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ProductSupplierService;
use App\Services\ReorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pemetaan harga beli & lead time per pemasok (PRD Modul 1).
 *
 * Semua operasi pivot lewat ProductSupplierService — controller tidak
 * menyentuh tabel product_supplier secara langsung.
 */
class ProductSupplierController extends Controller
{
    public function __construct(private readonly ProductSupplierService $service) {}

    /**
     * Halaman pengaturan pemasok untuk satu produk.
     */
    public function index(Product $product): Response
    {
        return Inertia::render('Products/Suppliers', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'current_stock' => $product->current_stock,
                'reorder_point' => $product->reorder_point,
            ],
            'linked' => $this->service->summaryFor($product),
            'available' => Supplier::query()
                ->whereNotIn('id', $product->suppliers()->pluck('suppliers.id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values(),
            'defaults' => [
                'safety_days' => ReorderService::DEFAULT_SAFETY_DAYS,
                'default_lead_time_days' => ReorderService::DEFAULT_LEAD_TIME_DAYS,
            ],
        ]);
    }

    /**
     * Tambah atau perbarui pemetaan satu pemasok.
     */
    public function store(StoreProductSupplierRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();
        $supplier = Supplier::findOrFail($validated['supplier_id']);

        $this->service->attach(
            product: $product,
            supplier: $supplier,
            lastPurchasePrice: isset($validated['last_purchase_price'])
                ? (float) $validated['last_purchase_price']
                : null,
            leadTimeDays: isset($validated['lead_time_days'])
                ? (int) $validated['lead_time_days']
                : null,
            user: $request->user(),
        );

        return back()->with(
            'success',
            "Pemasok {$supplier->name} tersambung ke {$product->sku}.",
        );
    }

    /**
     * Simpan seluruh daftar pemetaan sekaligus (satu form berisi semua baris).
     *
     * Baris yang dikosongkan di UI berarti pemasok dilepas; baris yang diisi
     * diperbarui atau ditambahkan.
     */
    public function sync(SyncProductSuppliersRequest $request, Product $product): RedirectResponse
    {
        $rows = $request->validated()['suppliers'] ?? [];

        $this->service->sync($product, $rows, $request->user());

        $count = $product->suppliers()->count();

        return back()->with('success', "Pemetaan {$count} pemasok disimpan untuk {$product->sku}.");
    }

    /**
     * Perbarui kolom pivot tanpa melepas pemasok.
     */
    public function update(StoreProductSupplierRequest $request, Product $product, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validated();

        $this->service->attach(
            product: $product,
            supplier: $supplier,
            lastPurchasePrice: isset($validated['last_purchase_price'])
                ? (float) $validated['last_purchase_price']
                : null,
            leadTimeDays: isset($validated['lead_time_days'])
                ? (int) $validated['lead_time_days']
                : null,
            user: $request->user(),
        );

        return back()->with('success', "Pemetaan {$supplier->name} diperbarui.");
    }

    /**
     * Lepaskan pemasok dari produk. Riwayat pergerakan tetap tersimpan.
     */
    public function destroy(Request $request, Product $product, Supplier $supplier): RedirectResponse
    {
        $this->service->detach($product, $supplier, $request->user());

        return back()->with('success', "Pemasok {$supplier->name} dilepas dari {$product->sku}.");
    }
}
