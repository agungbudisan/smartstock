<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLAP: daily_outbound_aggregations — ringkasan harian dari ribuan baris OLTP
 * (ERD -> daily_outbound_aggregations: id, product_id, date, total_qty_out).
 *
 * - UNIQUE (product_id, date) membuat agregasi bersifat idempoten: job yang
 *   dijalankan ulang untuk tanggal yang sama melakukan update, bukan
 *   menggandakan baris.
 * - `total_qty_out` unsigned karena hanya menghitung jumlah barang KELUAR
 *   (nilai mutlak), bukan delta bertanda seperti di inventory_movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_outbound_aggregations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('total_qty_out')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'date'], 'daily_agg_product_date_unique');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_outbound_aggregations');
    }
};
