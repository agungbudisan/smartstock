<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLTP: inventory_movements — buku besar pergerakan stok, sebagai baris
 * DETAIL dari satu dokumen (`inventory_transactions`).
 *
 * Konvensi penting:
 * - `quantity` = DELTA BERTANDA (lihat App\Enums\MovementType):
 *   inbound positif, outbound negatif, adjustment +/-. Menjumlahkan kolom ini
 *   per produk harus menghasilkan `products.current_stock`.
 * - `movement_date` defaulted ke CURRENT_TIMESTAMP supaya caller tidak wajib
 *   mengirim waktu; koreksi historis tetap bisa dilakukan dengan nilai eksplisit.
 *
 * ## Kenapa tabel ini tidak punya `movement_type` / `user_id` / `supplier_id`
 *
 * Ketiganya milik DOKUMEN, bukan milik baris detail. Satu purchase order
 * menutup sepuluh SKU: jenis, pemasok, dan pencatatnya sama untuk kesepuluh
 * baris, jadi menyimpannya sepuluh kali adalah duplikasi yang bisa –
 * dan memang pernah — menyimpang dari header.
 *
 * Kolom-kolom itu dihapus langsung dari migration `create`, BUKAN lewat
 * migration `dropColumn` terpisah. Alasannya teknis dan sudah diuji di proyek
 * ini: di SQLite, `ALTER TABLE` apa pun membuat Laravel me-rebuild seluruh
 * tabel, dan rebuild itu membuang CHECK constraint kolom enum. MySQL tidak
 * terpengaruh, tapi test suite berjalan di SQLite. Karena tabel ini belum
 * pernah dipakai di instalasi produksi, bentuk finalnya lebih aman ditetapkan
 * di `create` — tidak ada ALTER yang perlu jalan sama sekali.
 *
 * Konsekuensi kalau database dev masih punya kolom lama: tidak ada. Kolom yang
 * tersisa tapi tidak terpakai itu membosankan, bukan merusak; yang berbahaya
 * adalah migration yang setengah jalan. Jalankan `php artisan migrate:fresh
 * --seed` untuk menyelaraskan.
 *
 * Index dirancang untuk job agregasi harian:
 *   JOIN inventory_transactions ON transactions.id = movements.transaction_id
 *   WHERE transactions.movement_type = ? AND movements.movement_date BETWEEN ? AND ?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            // Pemecahan header-detail. Identitas dokumen (jenis, pemasok,
            // pencatat, tanggal transaksi) ada di `inventory_transactions`.
            //
            // Kolom ini sengaja ada sejak tabel pertama dibuat, bukan lewat
            // migration ALTER terpisah, demi alasan CHECK constraint di atas.
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->integer('quantity');

            // Harga beli satuan per baris. Sebelumnya tidak ada tempat untuk ini:
            // `recordInbound()` menerimanya lalu menyimpannya ke pivot
            // `product_supplier`, tanpa snapshot di ledger — jadi harga historis
            // tidak bisa direkonstruksi kalau pivot-nya berubah.
            $table->decimal('unit_price', 15, 2)->nullable();

            // Nomor faktur fisik milik dokumen (`physical_reference`), disalin ke
            // sini supaya Buku Besar tidak perlu JOIN hanya untuk menampilkan
            // kolom yang paling sering dibaca. Salinan ini disengaja dan
            // konsisten: `recordDocument()` menulis keduanya dari satu nilai.
            $table->string('reference_code', 100)->nullable();

            $table->timestamp('movement_date')->useCurrent();
            $table->timestamps();

            // Ambil histori satu produk pada rentang tanggal (audit trail produk).
            $table->index(['product_id', 'movement_date'], 'inv_movements_product_date_index');

            // Pintasan job agregasi harian. Sebelumnya index ini ber warming
            // `['movement_type', 'movement_date']`, yang tidak lagi ada; filter
            // jenisnya pindah ke tabel dokumen, jadi yang diindeks di sini
            // hanya rentang tanggal yang benar-benar difilter.
            $table->index('movement_date', 'inv_movements_date_index');

            // Baris detail milik satu dokumen.
            $table->index('transaction_id', 'inv_movements_transaction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
