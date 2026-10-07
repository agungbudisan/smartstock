<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception untuk seluruh pelanggaran aturan di domain inventaris.
 *
 * Controller (Fase 6) boleh menangkap exception ini untuk mengembalikan
 * pesan validasi yang ramah ke antarmuka, bukan 500 error.
 */
class InventoryException extends RuntimeException
{
    public static function invalidQuantity(string $movementLabel): self
    {
        return new self("Jumlah untuk {$movementLabel} harus lebih besar dari nol.");
    }

    public static function negativePhysicalCount(): self
    {
        return new self('Jumlah fisik hasil stock opname tidak boleh negatif.');
    }

    public static function productUnavailable(int $productId): self
    {
        return new self("Produk #{$productId} tidak tersedia (tidak ada atau sudah di-soft-delete).");
    }

    public static function supplierNotFound(int $supplierId): self
    {
        return new self("Pemasok #{$supplierId} tidak ditemukan.");
    }

    public static function unitPriceNegative(float $price): self
    {
        return new self('Harga satuan tidak boleh negatif.');
    }
}
