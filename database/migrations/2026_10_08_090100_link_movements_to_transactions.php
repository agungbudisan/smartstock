<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 (dari 5): lengkapi `inventory_movements` di database yang dibuat
 * SEBELUM pemecahan header-detail.
 *
 * KOLOMNYA SUDAH ADA SEJAK TABLE CREATE (lihat
 * `2026_10_07_090500_create_inventory_movements_table.php`), jadi di instalasi
 * baru migration ini tidak melakukan apa-apa. Yang dilayani hanya database
 * MySQL lama yang sudah menjalankan versi migration sebelumnya.
 *
 * Kenapa tidak semuanya lewat ALTER saja:
 *
 * SQLite tidak bisa `ALTER TABLE ADD COLUMN` dengan klausa REFERENCES, jadi
 * Laravel me-rebuild seluruh tabel — dan rebuild itu membuang CHECK constraint
 * kolom enum. Akibatnya `movement_type = 'transfer'` bisa masuk diam-diam.
 * MySQL tidak terpengaruh karena ENUM-nya native, tapi test suite berjalan di
 * SQLite. Kolom yang lahir dari `create` tidak punya masalah itu sama sekali.
 *
 * TAHAP 4: `BackfillTransactions` DIHAPUS dari sini. Migration ini dulu memanggil
 * kelas itu untuk menautkan baris lama yang belum punya dokumen. Sekarang
 * tidak ada baris seperti itu yang bisa ada: `transaction_id` lahir bersama
 * `recordDocument()`, dan kolom `movement_type` / `user_id` / `supplier_id`
 * yang dipakai backfill untuk menebak header sudah dibuang. Mempertahankan
 * pemanggilannya hanya akan menyisakan SELECT ke kolom yang tidak ada.
 *
 * Untuk database lama yang mungkin masih menyimpan baris terlantar, jalankan
 * `php artisan migrate:fresh --seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_movements', 'transaction_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreignId('transaction_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('inventory_transactions')
                    ->cascadeOnDelete();

                $table->decimal('unit_price', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_movements', 'transaction_id')) {
                $table->dropConstrainedForeignId('transaction_id');
            }

            if (Schema::hasColumn('inventory_movements', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });
    }
};
