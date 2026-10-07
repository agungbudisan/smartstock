<?php

namespace App\Models;

use Database\Factories\DailyOutboundAggregationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ringkasan harian pergerakan keluar (OLAP), hasil AggregateDailyOutboundJob.
 *
 * UNIQUE (product_id, date) membuat tabel ini idempoten: agregasi ulang untuk
 * tanggal yang sama menimpa baris, bukan menambah duplikat.
 */
class DailyOutboundAggregation extends Model
{
    /** @use HasFactory<DailyOutboundAggregationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'date',
        'total_qty_out',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_qty_out' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
