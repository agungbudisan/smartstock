<?php

namespace App\Models;

use App\Enums\MovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buku besar pergerakan stok (OLTP).
 *
 * PENTING: `quantity` adalah DELTA BERTANDA, bukan angka absolut:
 *   inbound    -> positif
 *   outbound   -> negatif
 *   adjustment -> positif/negatif (selisih stock opname)
 *
 * Karena itu `SUM(quantity)` untuk satu produk selalu sama dengan
 * `products.current_stock`. Lihat App\Enums\MovementType.
 *
 * Catatan SoftDeletes: produk memakai soft delete, sehingga
 * `inventory_movements.product_id` yang menunjuk produk terhapus TETAP ADA di
 * database (FK cascade tidak pernah menyala). Relasi `product()` karena itu
 * mengembalikan null. Untuk tetap membaca histori produk yang di-soft-delete,
 * pakai `$movement->product()->withTrashed()`.
 */
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'supplier_id',
        'movement_type',
        'quantity',
        'reference_code',
        'movement_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'integer',
            'movement_date' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Admin Gudang yang mencatat pergerakan ini. Nullabel bila akunnya sudah
     * dihapus permanen.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pemasok terkait (umumnya pada inbound). Nullable.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * Hanya pergerakan keluar.
     *
     * @param  Builder<InventoryMovement>  $query
     */
    public function scopeOutboundOnly(Builder $query): void
    {
        $query->where('movement_type', MovementType::Outbound);
    }

    /**
     * @param  Builder<InventoryMovement>  $query
     */
    public function scopeForDateRange(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): void
    {
        $query->whereBetween('movement_date', [$from, $to]);
    }
}
