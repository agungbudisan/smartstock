<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data: products (ERD -> products: id, sku, category_id, name,
 * current_stock, reorder_point).
 *
 * Catatan desain:
 * - `deleted_at` hadir karena PRD mewajibkan Soft Delete pada produk.
 * - `current_stock` sengaja dibuat SIGNED (bukan unsigned) supaya service
 *   InventoryMovementService bisa menulis nilai sementara sebelum validasi
 *   stok negatif selesai. Aturan "stok tidak boleh negatif" ditegakkan di
 *   service layer + test, bukan oleh DB.
 * - `reorder_point` unsigned karena mustahil negatif secara bisnis.
 * - `category_id` nullable: notasi ERD `categories ||--o{ products` mengizinkan
 *   produk belum terkategori. Menghapus kategori TIDAK boleh menghapus produk,
 *   jadi aksi foreign key-nya nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->string('name');
            $table->integer('current_stock')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Membantu pencarian/filter katalog dan dasbor.
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
