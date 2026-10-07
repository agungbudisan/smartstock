<?php

namespace App\Models;

use Database\Factories\MonthlyDemandPredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prediksi permintaan bulanan (OLAP), hasil CalculateMonthlyPredictionJob.
 *
 * UNIQUE (product_id, target_month) membuat tabel ini idempoten: kalkulasi
 * ulang untuk bulan yang sama menimpa hasil sebelumnya.
 */
class MonthlyDemandPrediction extends Model
{
    /** @use HasFactory<MonthlyDemandPredictionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'target_month',
        'predicted_qty',
        'confidence_score',
        'calculated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_month' => 'date',
            'predicted_qty' => 'integer',
            // confidence_score disimpan sebagai 0.00 - 100.00 (persentase).
            'confidence_score' => 'decimal:2',
            'calculated_at' => 'datetime',
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
