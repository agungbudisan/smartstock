<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 (dari 5) pemecahan Header-Detail pergerakan stok.
 *
 * SEBELUM: `inventory_movements` mencampur dua hal — identitas dokumen (pemasok,
 * jenis, siapa yang mencatat, nomor referensi) dan isi dokumen (produk, jumlah).
 * Konsekuensinya `supplier_id` diduplikasi di 57 baris padahal itu milik
 * dokumen, dan `reference_code` berupa teks bebas tanpa integritas apa pun.
 *
 * SESUDAH: `inventory_transactions` memegang dokumen, `inventory_movements`
 * menjadi baris detail yang menunjuknya.
 *
 * KUNCI PEMISAHAN DUA KOLOM:
 *
 *  - `system_code` (INB-20261008-0001) adalah identitasinternal yang SELALU
 *    ada dan dijamin unik. Sistem yang mengarangnya tidak sedang memalsukan
 *    bukti, karena ini nomor urut miliknya sendiri.
 *
 *  - `physical_reference` (PO-20261008-001) adalah nomor yang tercetak di
 *    dokumen fisik milik pihak lain — faktur supplier, nota penjualan. Aturannya
 *    nullable, karena banyak entri internal memang tidak punya dokumen
 *    eksternal: stok pembuka hasil hitung, atau koreksi yang berdiri sendiri.
 *
 * Pemisahan inilah yang membuat keduanya bisa hidup berdampingan tanpa
 * saling menimpa: kode internal selalu ada, nomor eksternal tidak pernah
 * dikarang.
 *
 * CATATAN MIGRASI: kolom `supplier_id`, `user_id`, dan `movement_type` di
 * `inventory_movements` BELUM dibuang di tahap ini. Kolom lama tetap jadi
 * sumber kebenaran sampai semua bacaan berpindah (tahap 2-3), supaya test
 * suite tidak pernah melihat keadaan setengah jadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();

            // Nomor internal. Prefix mengikuti jenis: INB / OUT / ADJ.
            $table->string('system_code', 40)->unique();

            // Nomor dokumen fisik milik pihak lain. Nullable — lihat docblock.
            $table->string('physical_reference', 100)->nullable();

            // ENUM-nya di-hardcode, bukan dibaca dari MovementType, supaya
            // migration tetap jalan walau enum berubah (konvensi proyek ini).
            //
            // Awalnya baris di sini menulis `MovementType::Inbound->value` —
            // sementara komentarnya sudah sejak awal bilang "di-hardcode". Selain melanggar
            // konvensi, bentuk itu juga tidak bisa diperiksa: test sinkronisasi
            // enum membaca literal dari teks migration, dan
            // `MovementType::Outbound->value` di sana bukan literal.
            $table->enum('movement_type', [
                'inbound',
                'outbound',
                'adjustment',
            ]);

            // Pemasok menempel di HEADER, bukan per baris detail. Satu PO tidak mungkin
            // punya dua pemasok; kalau iya, itu dua dokumen berbeda.
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('transaction_date')->useCurrent();
            $table->timestamps();

            // Pencarian faktur + rekap per hari (filter Buku Besar).
            $table->index(['movement_type', 'transaction_date'], 'inv_txn_type_date_index');
            $table->index('physical_reference', 'inv_txn_physical_ref_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
