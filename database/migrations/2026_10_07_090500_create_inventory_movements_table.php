<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLTP: inventory_movements — buku besar pergerakan stok
 * (ERD -> inventory_movements: id, product_id, user_id, supplier_id,
 * movement_type, quantity, reference_code, movement_date).
 *
 * Konvensi penting:
 * - `quantity` = DELTA BERTANDA (lihat App\Enums\MovementType):
 *   inbound positif, outbound negatif, adjustment +/-. Menjumlahkan kolom ini
 *   per produk harus menghasilkan `products.current_stock`.
 * - `supplier_id` NULLABLE sesuai ERD: outbound dan stock opname tidak punya
 *   pemasok, dan sebuah produk belum tentu punya pemasok terdaftar.
 * - `user_id` NULLABLE + nullOnDelete agar riwayat tetap utuh walaupun user
 *   dihapus permanen.
 * - `movement_date` defaulted ke CURRENT_TIMESTAMP supaya caller tidak wajib
 *   mengirim waktu; koreksi historis tetap bisa dilakukan dengan nilai eksplisit.
 *
 * Index dirancang untuk job agregasi harian:
 *   WHERE movement_type = ? AND movement_date BETWEEN ? AND ? GROUP BY product_id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->enum('movement_type', ['inbound', 'outbound', 'adjustment']);
            $table->integer('quantity');
            $table->string('reference_code', 100)->nullable();
            $table->timestamp('movement_date')->useCurrent();
            $table->timestamps();

            // Ambil histori satu produk pada rentang tanggal (audit trail produk).
            $table->index(['product_id', 'movement_date'], 'inv_movements_product_date_index');

            // Kueri utama AggregateDailyOutboundJob.
            $table->index(['movement_type', 'movement_date'], 'inv_movements_type_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
