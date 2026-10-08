<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pemetaan harga beli & lead time per pemasok untuk satu produk
 * (PRD Modul 1 — "Pemetaan Harga & Lead Time": satu produk dapat disuplai
 * banyak pemasok, masing-masing punya harga beli terakhir dan lead time).
 *
 * Logika relasi many-to-many + kolom pivot TIDAK ditulis di controller, dan
 * `InventoryMovementService` memakai kelas ini juga saat mencatat inbound
 * bermuatan harga — jadi ada satu aturan saja untuk pivot.
 */
final class ProductSupplierService
{
    /**
     * Sambungkan pemasok ke produk, atau perbarui pemetaan yang sudah ada.
     *
     * Idempoten: memanggil ulang dengan pemasok yang sama hanya memperbarui
     * baris pivot, tidak pernah membuat baris kedua.
     *
     * Nilai null berarti "kosongkan kolom pivot", bukan "biarkan yang lama".
     * Untuk memperbarui harga saja tanpa menyentuh lead time, pakai
     * recordPurchasePrice().
     */
    public function attach(
        Product $product,
        Supplier $supplier,
        ?float $lastPurchasePrice = null,
        ?int $leadTimeDays = null,
        ?User $user = null,
    ): void {
        $attributes = [
            'last_purchase_price' => $lastPurchasePrice,
            'lead_time_days' => $leadTimeDays,
        ];

        DB::transaction(function () use ($product, $supplier, $attributes, $user): void {
            $alreadyLinked = $product->suppliers()->whereKey($supplier->getKey())->exists();

            if ($alreadyLinked) {
                $product->suppliers()->updateExistingPivot($supplier->getKey(), $attributes);
            } else {
                $product->suppliers()->attach($supplier->getKey(), $attributes);
            }

            Log::info('SmartStock: pemetaan pemasok diperbarui', [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'created' => ! $alreadyLinked,
                'by' => $user?->id,
            ]);
        }, 3);
    }

    /**
     * Pastikan pemasok terhubung ke produk, tanpa menyentuh nilai yang sudah ada.
     *
     * Dipakai `InventoryMovementService` setiap kali inbound menyebut pemasok.
     * Hubungan "pemasok ini pernah menyuplai produk ini" adalah fakta historis,
     * bukan konfigurasi — jadi harus terbentuk sendiri dari pencatatan barang,
     * bukan menunggu admin mengisinya di halaman Kelola Pemasok.
     *
     * Bedanya dengan `attach()`: nilai `last_purchase_price` dan
     * `lead_time_days` yang sudah terisi TIDAK ditimpa jadi null. Menautkan
     * lagi pemasok yang sama tidak boleh menghapus hasil Negotiasi lama.
     *
     * @return bool true kalau tautan baru dibuat, false kalau sudah ada
     */
    public function ensureLinked(Product $product, Supplier $supplier, ?User $user = null): bool
    {
        return DB::transaction(function () use ($product, $supplier, $user): bool {
            if ($product->suppliers()->whereKey($supplier->getKey())->exists()) {
                return false;
            }

            $product->suppliers()->attach($supplier->getKey(), [
                'last_purchase_price' => null,
                'lead_time_days' => null,
            ]);

            Log::info('SmartStock: pemasok ditautkan otomatis dari riwayat movements', [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'by' => $user?->id,
            ]);

            return true;
        }, 3);
    }

    /**
     * Catat harga beli terakhir tanpa mengubah lead time.
     *
     * Dipakai `InventoryMovementService` saat inbound menyertakan harga: yang
     * perlu diperbarui hanya harga, lead time tetap hasil kesepakatan terpisah.
     */
    public function recordPurchasePrice(Product $product, Supplier $supplier, float $unitPrice): void
    {
        $attributes = ['last_purchase_price' => $unitPrice];

        DB::transaction(function () use ($product, $supplier, $attributes): void {
            if ($product->suppliers()->whereKey($supplier->getKey())->exists()) {
                $product->suppliers()->updateExistingPivot($supplier->getKey(), $attributes);
            } else {
                $product->suppliers()->attach($supplier->getKey(), $attributes);
            }
        }, 3);
    }

    /**
     * Lepaskan pemasok dari produk.
     *
     * Riwayat `inventory_movements` tidak ikut terhapus — kolom supplier_id
     * memakai nullOnDelete dan ledger adalah catatan historis.
     */
    public function detach(Product $product, Supplier $supplier, ?User $user = null): void
    {
        DB::transaction(function () use ($product, $supplier, $user): void {
            $product->suppliers()->detach($supplier->getKey());

            Log::info('SmartStock: pemasok dilepas dari produk', [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'by' => $user?->id,
            ]);
        }, 3);
    }

    /**
     * Sinkronkan daftar pemasok sekaligus, hasil dari satu form.
     *
     * Baris dengan `supplier_id` kosong diabaikan (baris baru yang belum
     * dipilih). Pemasok yang tidak lagi ada di daftar dilepas.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int jumlah pemasok yang dipertahankan
     */
    public function sync(Product $product, array $rows, ?User $user = null): int
    {
        $kept = [];

        foreach ($rows as $row) {
            $supplierId = (int) ($row['supplier_id'] ?? 0);

            if ($supplierId === 0) {
                continue;
            }

            $supplier = Supplier::find($supplierId);

            if ($supplier === null) {
                continue;
            }

            $this->attach(
                product: $product,
                supplier: $supplier,
                lastPurchasePrice: $this->toNullableFloat($row['last_purchase_price'] ?? null),
                leadTimeDays: $this->toNullableInt($row['lead_time_days'] ?? null),
                user: $user,
            );

            $kept[] = $supplierId;
        }

        $toRemove = $product->suppliers()
            ->pluck('suppliers.id')
            ->reject(fn ($id): bool => in_array((int) $id, $kept, true))
            ->all();

        if ($toRemove !== []) {
            $product->suppliers()->detach($toRemove);
        }

        return count($kept);
    }

    /**
     * Ringkasan pemetaan untuk ditampilkan di antarmuka.
     *
     * Menandai pemasok dengan lead time tercepat, karena angka itu yang dipakai
     * ReorderService menghitung days of cover.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaryFor(Product $product): array
    {
        $product->loadMissing('suppliers');

        $fastest = $product->suppliers
            ->pluck('pivot.lead_time_days')
            ->filter()
            ->map(fn ($days): int => (int) $days)
            ->min();

        return $product->suppliers
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'phone' => $supplier->phone,
                'last_purchase_price' => $supplier->pivot->last_purchase_price,
                'lead_time_days' => $supplier->pivot->lead_time_days,
                'is_fastest' => $fastest !== null
                    && (int) $supplier->pivot->lead_time_days === $fastest,
            ])
            ->sortBy(fn (array $row): array => [
                // Tanpa lead time dinilai paling akhir.
                $row['lead_time_days'] === null ? 1 : 0,
                $row['lead_time_days'] ?? PHP_INT_MAX,
                $row['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * Daftar pemasok per produk, untuk menyaring dropdown di form pergerakan.
     *
     * Sumbernya gabungan dua hal, dan itu disengaja:
     *  - pemetaan pivot (yang diisi admin lewat halaman Kelola Pemasok), dan
     *  - pemasok yang benar-benar pernah menyuplai produk menurut riwayat
     *    `inventory_movements`.
     *
     * Histori ikut dipertahankan supaya penyaringan tidak pernah menyembunyikan
     * pemasok yang faktanya pernah menyuplai. Kalau admin sempat melepas
     * pemasok dari pemetaan, tetapi barang dari pemasok itu masih tercatat di
     * ledger, pencatatannya tetap harus bisa dilakukan.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, array{id: int, name: string}>> indeks = product_id
     */
    public function suppliersByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $result = [];

        foreach ($productIds as $productId) {
            $result[$productId] = [];
        }

        // Sumber 1: pemetaan yang dikonfigurasi admin.
        $rows = DB::table('product_supplier')
            ->join('suppliers', 'suppliers.id', '=', 'product_supplier.supplier_id')
            ->whereIn('product_supplier.product_id', $productIds)
            ->orderBy('suppliers.name')
            ->get(['product_supplier.product_id', 'product_supplier.supplier_id', 'suppliers.name']);

        foreach ($rows as $row) {
            $result[(int) $row->product_id][(int) $row->supplier_id] = [
                'id' => (int) $row->supplier_id,
                'name' => $row->name,
            ];
        }

        // Sumber 2: pemasok yang pernah menyuplai menurut ledger.
        //
        // Lewat DOKUMEN, bukan `inventory_movements.supplier_id` — kolom itu
        // dibuang di tahap 4, dan pemasok memang milik faktur bukan milik
        // tiap baris detail. DISTINCT supaya satu PO dengan 12 baris tidak
        // menghasilkan 12 baris yang sama.
        $histori = DB::table('inventory_movements')
            ->join('inventory_transactions', 'inventory_transactions.id', '=', 'inventory_movements.transaction_id')
            ->join('suppliers', 'suppliers.id', '=', 'inventory_transactions.supplier_id')
            ->whereIn('inventory_movements.product_id', $productIds)
            ->distinct()
            ->orderBy('suppliers.name')
            ->get(['inventory_movements.product_id', 'inventory_transactions.supplier_id', 'suppliers.name']);

        foreach ($histori as $row) {
            $result[(int) $row->product_id][(int) $row->supplier_id] = [
                'id' => (int) $row->supplier_id,
                'name' => $row->name,
            ];
        }

        // array_values supaya tiap daftar jadi indeks 0..n. Kalau dibiarkan
        // apa adanya, Inertia/JS akan menerima objek yang kuncinya id
        // supplier, bukan array, dan `v-for` di Vue tidak mengiterasinya.
        foreach ($result as $productId => $suppliers) {
            $result[$productId] = array_values($suppliers);
        }

        return $result;
    }

    // -----------------------------------------------------------------

    private function toNullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function toNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
