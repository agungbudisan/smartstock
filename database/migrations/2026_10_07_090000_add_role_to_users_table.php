<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom `role` pada `users` sesuai PRD bagian 5 (users: id, name,
 * email, password, role Enum) dan ERD (users.role : ENUM).
 *
 * Nilai enum ditulis hardcoded, bukan dibaca dari App\Enums\UserRole, karena
 * migration harus tetap bisa dijalankan walau class enum berubah/ dihapus
 * di kemudian hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin_gudang', 'manager_bisnis'])
                ->default('admin_gudang')
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
