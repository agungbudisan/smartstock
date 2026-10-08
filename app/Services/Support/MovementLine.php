<?php

namespace App\Services\Support;

use App\Models\Product;

/**
 * Satu baris item di dalam dokumen pergerakan.
 *
 * Bentuk array, bukan DTO, karena baris ini tidak pernah keluar dari
 * `InventoryMovementService::recordDocument()` — ia dibuat di situ dan langsung
 * dipakai. Membuat kelas untuk data yang lahir dan mati di satu fungsi hanya
 * menambah berkas tanpa menambah kejelasan.
 *
 * Dua nilai input berbeda, dan itu disengaja:
 *
 *  - `quantity` dipakai Inbound (jumlah masuk) dan Outbound (jumlah keluar).
 *  - `physical_count` dipakai Stock Opname, karena yang diinput adalah angka
 *    FISIK hasil hitung, bukan selisih. Sistem yang menghitung selisihnya
 *    sendiri.
 *
 * `product` dan `supplier_id` adalah objek model, bukan id, supaya pemanggil
 * tidak perlu query berulang dan supaya validasi bisa langsung membaca nama.
 */
final class MovementLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?int $physicalCount = null,
        public readonly ?float $unitPrice = null,
    ) {}

    /**
     * @param  array{product: Product, quantity?: int, physical_count?: int, unit_price?: float|null}  $baris
     */
    public static function dariArray(array $baris): self
    {
        $produk = $baris['product'];

        $harga = $baris['unit_price'] ?? null;

        return new self(
            productId: (int) $produk->getKey(),
            sku: (string) $produk->sku,
            quantity: (int) ($baris['quantity'] ?? 0),
            physicalCount: isset($baris['physical_count']) ? (int) $baris['physical_count'] : null,
            unitPrice: $harga === null ? null : (float) $harga,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, self>
     */
    public static function dariBanyak(array $baris): array
    {
        return array_map(self::dariArray(...), array_values($baris));
    }
}
