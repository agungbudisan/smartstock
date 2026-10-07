<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLTP: audit_logs — jejak rekam aktivitas kritis
 * (ERD -> audit_logs: id, user_id, action, description, created_at).
 *
 * Sesuai ERD tabel ini HANYA punya `created_at` (bukan `updated_at`): log audit
 * bersifat append-only dan tidak pernah diedit. Karena itu Model AuditLog di
 * Fase 3 harus mengeset `const UPDATED_AT = null;`.
 *
 * `user_id` nullable + nullOnDelete: baris log tetap bertahan bila akun
 * pengguna dihapus permanen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action', 100);
            $table->text('description');
            $table->timestamp('created_at')->useCurrent();

            // Chronological scan + filter per jenis aksi (mis. "inbound.create").
            $table->index(['action', 'created_at'], 'audit_logs_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
