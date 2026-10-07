<?php

namespace App\Exceptions;

use Throwable;

/**
 * Dilempar ketika stok tidak cukup untuk memenuhi pengeluaran (outbound).
 *
 * Melempar exception — bukan menulis diam-diam — memastikan buku besar
 * inventory_movements tidak pernah bisa menerima quantity yang membuat
 * current_stock menjadi negatif.
 */
class InsufficientStockException extends InventoryException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly int $available,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Stok produk #{$productId} tidak cukup: diminta {$requested}, tersedia {$available}.",
            0,
            $previous,
        );
    }

    /**
     * Stok shortfall (berapa yang kurang).
     */
    public function shortfall(): int
    {
        return max(0, $this->requested - $this->available);
    }
}
