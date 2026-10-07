<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel notifikasi (channel `database` milik Laravel).
 *
 * Dipakai untuk peringatan stok menipis yang tampil di lonceng notifikasi
 * pada AppLayout. Kolom `audience_key` ditambahkan di luar tabel standar
 * supaya notifikasi lama untuk produk yang sama bisa dideteksi, sehingga
 * outbound beruntun tidak mengirim peringatan berulang (debounce).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('type');

            // notifiable_id + notifiable_type. Tidak dipasang foreign key ke
            // users karena tabel ini generik (bisa dipakai user lain juga),
            // dan notifikasi ikut terhapus lewat User::deleting().
            $table->morphs('notifiable');

            // Data notifikasi (judul, pesan, tautan) dalam JSON.
            $table->text('data');

            // Kunci debounce, mis. "low_stock:42".
            $table->string('audience_key')->nullable()->index();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_id', 'notifiable_type', 'read_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
