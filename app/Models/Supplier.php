<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Pergerakan stok yang merujuk pemasok ini (hanya inbound yang biasanya
     * punya supplier_id; kolomnya nullable karena itu).
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
