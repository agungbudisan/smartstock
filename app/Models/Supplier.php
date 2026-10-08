<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'address',
    ];

    /**
     * Produk yang disuplai oleh pemasok ini.
     *
     * Kolom pivot `last_purchase_price` dan `lead_time_days` disimpan di
     * tabel product_supplier sesuai PRD Modul 1 (Pemetaan Harga & Lead Time).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot(['last_purchase_price', 'lead_time_days'])
            ->withTimestamps();
    }

    /**
     * Dokumen pergerakan yang menyebut pemasok ini.
     *
     * Sejak tahap 4 `supplier_id` tidak lagi ada di baris detail, jadi relasi
     * yang benar adalah ke dokumen. Relasi lama (`hasMany` ke kolom
     * `supplier_id`) tidak bisa dipertahankan dan tidak dibiarkan menggantung:
     * dipanggil, ia melempar "no such column" saat query.
     *
     * @return HasMany<InventoryTransaction, $this>
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Semua baris detail dari dokumen-dokumen di atas.
     *
     * `hasManyThrough` dua hop, jadi jangan dipakai di halaman yang hanya
     * butuh dokumen. Ada supaya pertanyaan "barang dari pemasok mana" tetap
     * bisa dijawab tanpa menulis ulang kuerinya.
     *
     * @return HasManyThrough<InventoryMovement, InventoryTransaction, $this>
     */
    public function inventoryMovements(): HasManyThrough
    {
        return $this->hasManyThrough(
            InventoryMovement::class,
            InventoryTransaction::class,
            'supplier_id',
            'transaction_id',
            'id',
            'id',
        );
    }
}
