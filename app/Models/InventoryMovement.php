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
 *
 * TAHAP 4: tabel ini tidak lagi punya `movement_type`, `user_id`, maupun
 * `supplier_id`. Ketiganya milik dokumen — baca lewat
 * `$movement->transaction->movement_type`, `->user`, `->supplier`. Baris
 * detail sekarang hanya menjawab "produk mana, berapa, harga berapa, dan
 * masuk ke faktur yang mana".
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
        'transaction_id',
        'product_id',
        'quantity',
        'unit_price',
        'reference_code',
        'movement_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * Tidak ada `movement_type` di sini sejak tahap 4: kolomnya dihapus dari
     * tabel. Kalau cast-nya dibiarkan, `$movement->movement_type` tidak akan
     * melempar error — ia hanya mengembalikan null untuk atribut yang tidak ada,
     * dan null itu lalu ikut ke seluruh halaman dan laporan sebagai "jenis
     * pergerakan tidak diketahui". Pelanggaran skema yang diam-diam jauh lebih
     * buruk daripada error yang langsung.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'movement_date' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * Dokumen pemilik baris ini (tahap 1 pemecahan header-detail).
     *
     * Relasi ini sumber kebenaran identitas pergerakan sejak tahap 3, dan
     * satu-satunya sejak tahap 4 saat kolom `movement_type` / `user_id` /
     * `supplier_id` dihapus dari baris detail.
     *
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'transaction_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi `user()` dan `supplier()` DIHAPUS di tahap 4.
     *
     * Keduanya dulu menunjuk ke kolom di baris ini. Sekarang kolomnya tidak
     * ada, dan pencatat serta pemasok milik dokumen — accessed lewat
     * `$movement->transaction->user` / `->supplier`.
     *
     * Menyingkirkan relasi sepenuhnya, bukan cuma kolomnya, penting: relasi
     * yang masih ada tapi kolomnya hilang akan mengembalikan null untuk setiap
     * pemanggil, dan null itu bukan error — halaman akan terlihat benar sambil
     * menampilkan kolom kosong di mana-mana.
     */

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * Hanya pergerakan keluar.
     *
     * Seit tahap 3 filter ini membaca DOKUMEN, bukan kolom `movement_type` di
     * baris ini — kolom itu akan dibuang di tahap 4. `whereHas` dipakai, bukan
     * JOIN, supaya pemanggil tetap bisa melakukan `with('transaction')`
     * terpisah dan tidak_query-nya tetap satu tabel.
     *
     * @param  Builder<InventoryMovement>  $query
     */
    public function scopeOutboundOnly(Builder $query): void
    {
        $query->whereHas(
            'transaction',
            fn (Builder $trx) => $trx->where('movement_type', MovementType::Outbound),
        );
    }

    /**
     * Scope yang sama untuk pergerakan masuk dan stock opname.
     *
     * Satu scope generik lebih baik daripada dua scope terpisah yang isinya
     * sama — pemanggil cukup `whereMovementType($type)`.
     *
     * @param  Builder<InventoryMovement>  $query
     */
    public function scopeOfMovementType(Builder $query, MovementType $type): void
    {
        $query->whereHas('transaction', fn (Builder $trx) => $trx->where('movement_type', $type));
    }

    /**
     * @param  Builder<InventoryMovement>  $query
     */
    public function scopeForDateRange(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): void
    {
        $query->whereBetween('movement_date', [$from, $to]);
    }
}
