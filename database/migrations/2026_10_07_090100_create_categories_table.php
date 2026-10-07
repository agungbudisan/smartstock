<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data: categories (ERD -> categories: id, name, description).
 *
 * Satu kategori memiliki nol atau banyak produk (products.category_id nullable
 * karena notasi `categories ||--o{ products` mengizinkan produk tanpa kategori).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
