<?php

namespace App\Services\Support;

use App\Models\Product;

/**
 * Satu baris daftar prioritas pemesanan ulang (PRD Modul 3).
 *
 * Objek nilai murni, dipakai bersama oleh halaman dasbor, ekspor CSV, dan
 * pengujian tanpa menyentuh database.
 */
final class ReorderRecommendation
{
    /**
     * @param  int|null  $leadTimeDays  lead time tercepat dari pemasok terdaftar
     * @param  int  $supplierCount  berapa pemasok yang bisa memasok produk ini
     * @param  float  $averageDailyDemand  rata-rata pcs/hari dari histori agregasi
     * @param  int|null  $daysOfCover  berapa lama stok bertahan; null = tidak ada demand
     * @param  bool  $isUrgent  stok habis, atau habis sebelum barang sempat tiba
     * @param  bool  $isBelowReorderPoint  sisa stok <= reorder point
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $categoryName,
        public readonly int $currentStock,
        public readonly int $reorderPoint,
        public readonly ?int $leadTimeDays,
        public readonly int $supplierCount,
        public readonly float $averageDailyDemand,
        public readonly ?int $daysOfCover,
        public readonly int $suggestedOrderQty,
        public readonly bool $isUrgent,
        public readonly bool $isBelowReorderPoint,
    ) {}

    /**
     * @param  int  $safetyDays  buffer keamanan di atas lead time
     * @param  int  $defaultLeadTimeDays  dipakai bila produk belum punya pemasok
     */
    public static function fromProduct(
        Product $product,
        float $averageDailyDemand,
        int $safetyDays = 7,
        int $defaultLeadTimeDays = 14,
    ): self {
        $leadTime = $product->fastestLeadTimeDays() ?? $defaultLeadTimeDays;

        $daysOfCover = $averageDailyDemand > 0.0
            ? (int) floor($product->current_stock / $averageDailyDemand)
            : null;

        // Kebutuhan = konsumsi selama (lead time + buffer keamanan).
        $requiredForCycle = $averageDailyDemand * ($leadTime + $safetyDays);
        $suggested = max(0, (int) ceil($requiredForCycle) - $product->current_stock);

        // Demand tak terukur tapi stok sudah di bawah batas aman: tetap sarankan
        // sebesar stok safety supaya minimal aman.
        if ($suggested === 0 && $product->needsReorder()) {
            $suggested = $product->reorder_point;
        }

        return new self(
            productId: $product->id,
            sku: $product->sku,
            name: $product->name,
            categoryName: $product->category?->name,
            currentStock: $product->current_stock,
            reorderPoint: $product->reorder_point,
            leadTimeDays: $leadTime,
            supplierCount: $product->suppliers->count(),
            averageDailyDemand: round($averageDailyDemand, 2),
            daysOfCover: $daysOfCover,
            suggestedOrderQty: $suggested,
            isUrgent: $product->current_stock <= 0
                || ($daysOfCover !== null && $daysOfCover <= $leadTime),
            isBelowReorderPoint: $product->needsReorder(),
        );
    }
}
