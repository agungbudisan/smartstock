<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLAP: monthly_demand_predictions — hasil prediksi permintaan per bulan
 * (ERD -> monthly_demand_predictions: id, product_id, target_month,
 * predicted_qty, confidence_score, calculated_at).
 *
 * - UNIQUE (product_id, target_month) => kalkulasi ulang pada bulan yang sama
 *   menimpa hasil lama (CalculateMonthlyPredictionJob idempoten).
 * - `predicted_qty` unsigned; PredictionService wajib meng-clamp hasil regresi
 *   negatif menjadi 0.
 * - `confidence_score` DECIMAL(5,2) pada rentang 0.00 - 100.00 (persentase,
 *   diturunkan dari R^2 lalu di-clamp) supaya mudah dibaca di dasbor.
 * - `calculated_at` selalu di-refresh saat baris di-upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_demand_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->date('target_month');
            $table->unsignedInteger('predicted_qty')->default(0);
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['product_id', 'target_month'], 'monthly_pred_product_month_unique');
            $table->index('target_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_demand_predictions');
    }
};
