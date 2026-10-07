<?php

namespace App\Services\Support;

/**
 * Data peringatan stok menipis yang siap dikirim sebagai notifikasi.
 *
 * Dipisah dari efek pengiriman supaya `InventoryMovementService` bisa
 * menuliskan audit trail di dalam transaksi, lalu mengirim notifikasi
 * SETELAH transaksi selesai. Notifikasi tidak boleh menggagalkan perubahan
 * stok yang sudah sah.
 */
final class LowStockAlert
{
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $productName,
        public readonly int $currentStock,
        public readonly int $reorderPoint,
        public readonly int $shortfall,
    ) {}

    /**
     * Judul pendek untuk dropdown notifikasi.
     */
    public function title(): string
    {
        return $this->currentStock === 0
            ? "{$this->sku} habis"
            : "Stok {$this->sku} menipis";
    }

    /**
     * Pesan lengkap untuk halaman notifikasi.
     */
    public function message(): string
    {
        return $this->currentStock === 0
            ? sprintf('%s (%s) sudah habis. Segera buat pesanan pembelian.', $this->productName, $this->sku)
            : sprintf(
                '%s (%s) tersisa %d unit, batas aman %d. Kurang %d unit.',
                $this->productName,
                $this->sku,
                $this->currentStock,
                $this->reorderPoint,
                $this->shortfall,
            );
    }

    /**
     * Kunci debounce: satu produk menghasilkan satu peringatan aktif,
     * bukan satu per pergerakan outbound.
     */
    public function debounceKey(): string
    {
        return "low_stock:{$this->productId}";
    }
}
