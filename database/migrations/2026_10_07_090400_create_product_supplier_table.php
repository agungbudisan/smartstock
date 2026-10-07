<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data: tabel pivot product_supplier
 * (ERD -> product_supplier: product_id, supplier_id, last_purchase_price,
 * lead_time_days).
 *
 * Mengimplementasikan relasi many-to-many "satu produk dapat disuplai banyak
 * pemasok" (PRD Modul 1 - Pemetaan Harga & Lead Time).
 *
 * - Primary key komposit (product_id, supplier_id) sesuai ERD, bukan
 *   bigIncrements() seperti pivot Laravel bawaan.
 * - `last_purchase_price` DECIMAL(15,2) nullable karena belum ada pembelian
 *   saat relasi dibuat; `lead_time_days` nullable karena bisa belum disepakati.
 * - timestamps() diperlukan agar relasi bisa memakai ->withTimestamps() di
 *   Fase 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->cascadeOnDelete();
            $table->decimal('last_purchase_price', 15, 2)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->timestamps();

            $table->primary(['product_id', 'supplier_id'], 'product_supplier_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier');
    }
};
