<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'category_id',
        'name',
        'current_stock',
        'reorder_point',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'reorder_point' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * Kategori produk ini. Nullabel: produk boleh belum terkategori.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Pemasok yang mampu memasok produk ini, lengkap dengan harga beli
     * terakhir dan estimasi lead time per pemasok.
     *
     * @return BelongsToMany<Supplier, $this>
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)
            ->withPivot(['last_purchase_price', 'lead_time_days'])
            ->withTimestamps();
    }

    /**
     * Buku besar pergerakan stok produk ini.
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Ringkasan harian OILAP untuk produk ini.
     *
     * @return HasMany<DailyOutboundAggregation, $this>
     */
    public function dailyOutboundAggregations(): HasMany
    {
        return $this->hasMany(DailyOutboundAggregation::class);
    }

    /**
     * Prediksi permintaan bulanan untuk produk ini.
     *
     * @return HasMany<MonthlyDemandPrediction, $this>
     */
    public function monthlyDemandPredictions(): HasMany
    {
        return $this->hasMany(MonthlyDemandPrediction::class);
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * Produk yang sisa stoknya sudah menyentuh/melewati reorder point.
     *
     * Dipakai oleh modul Pemantauan Reorder Point (PRD Modul 3).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeNeedingReorder(Builder $query): void
    {
        $query->whereColumn('current_stock', '<=', 'reorder_point');
    }

    // -----------------------------------------------------------------
    // Helper domain
    // -----------------------------------------------------------------

    /**
     * Apakah produk ini sudah harus dipesan ulang?
     */
    public function needsReorder(): bool
    {
        return $this->current_stock <= $this->reorder_point;
    }

    /**
     * Lead time terrendah di antara pemasok yang terdaftar — dasar perhitungan
     * kecepatan produk habis (days of cover) di dasbor manajer.
     */
    public function fastestLeadTimeDays(): ?int
    {
        return $this->suppliers
            ->pluck('pivot.lead_time_days')
            ->filter()
            ->map(fn ($days) => (int) $days)
            ->min();
    }
}
