<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori mendapat `sku_prefix`: kode singkat yang dipakai sebagai awal SKU
 * produk di dalamnya (Elektronik -> ELK-001, Alat Tulis -> ATS-001).
 *
 * Kenapa kolom, bukan diturunkan dari nama kategori?
 *
 * Kode yang dipakai di data demo adalah singkatan yang DISENGAJA: "Alat Tulis"
 * menjadi ATS, bukan AT. Aturan apa pun yang mencoba menurunkan kode dari nama
 * akan meleset di sini, dan lebih buruk lagi, mengubah nama kategori akan
 * mengubah pola SKU tanpa diminta. Kolom eksplisit satu-satunya jawaban yang
 * jujur.
 *
 * Kolom ini NULLABLE. Tidak semua kategori wajib punya prefix, dan memaksa
 * admin mengisinya hanya untuk bisa membuat produk akan menghasilkan tebakan
 * buruk yang lebih buruk daripada tidak ada pola sama sekali.
 *
 * Unik karena dua kategori dengan prefix sama akan saling merebut nomor,
 * membuat SKU ambiguous. NULL tidak melanggar unique index, jadi kategori
 * tanpa prefix boleh sebanyak-banyaknya.
 *
 * Catatan SQLite: kolom ini sengaja dibuat TANPA `->constrained()`. Kalau ada
 * foreign key, SQLite tidak bisa `ALTER TABLE ADD COLUMN` dan Laravel me-rebuild
 * seluruh tabel -- dan rebuild itu membuang CHECK constraint kolom enum di
 * tabel itu. Tidak ada FK di sini, jadi cukup `ALTER` biasa.
 */
return new class extends Migration
{
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_sku_prefix_unique');
            $table->dropColumn('sku_prefix');
        });
    }

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('sku_prefix', 8)->nullable()->after('name');
        });

        // Unique index dibuat terpisah, bukan inline. Di SQLite kolom unique
        // tidak bisa masuk ke definisi ALTER TABLE ADD COLUMN, dan Laravel
        // akan menolaknya; sebagai index terpisah hasilnya bersih.
        Schema::table('categories', function (Blueprint $table) {
            $table->unique('sku_prefix');
        });
    }
};
