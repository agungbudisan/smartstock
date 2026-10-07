<?php

namespace App\Enums;

/**
 * Jenis pergerakan pada buku besar stok (`inventory_movements.movement_type`).
 *
 * PENTING: kolom `quantity` disimpan sebagai DELTA BERTANDA, bukan angka absolut.
 *   - Inbound     -> qty positif   (stok bertambah)
 *   - Outbound    -> qty negatif   (stok berkurang)
 *   - Adjustment  -> qty +/-       (selisih stock opname: fisik dikurangi sistem)
 *
 * Dengan konvensi ini `SUM(quantity)` per produk selalu sama dengan
 * `products.current_stock`, sehingga buku besar dapat diaudit ulang.
 *
 * Nilai string di sini harus selalu sinkron dengan migration
 * 2026_10_07_090500.
 */
enum MovementType: string
{
    /** Penerimaan barang dari pemasok. */
    case Inbound = 'inbound';

    /** Pengeluaran barang. */
    case Outbound = 'outbound';

    /** Penyesuaian stok / stock opname. */
    case Adjustment = 'adjustment';

    /**
     * Tanda yang mengalikan jumlah absolut untuk menghasilkan delta tersimpan.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Inbound => 1,
            self::Outbound => -1,
            self::Adjustment => 1,
        };
    }

    /**
     * Label yang tampil di antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
            self::Adjustment => 'Stock Opname',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
