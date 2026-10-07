<?php

namespace App\Enums;

/**
 * Peran pengguna sesuai PRD bagian 2 (Aktor Pengguna).
 *
 * Nilai string di sini adalah sumber kebenaran untuk kolom `users.role`
 * dan harus selalu sinkron dengan migration 2026_10_07_090000.
 */
enum UserRole: string
{
    /** Admin Gudang — entri operasional stok (inbound/outbound/opname). */
    case AdminGudang = 'admin_gudang';

    /** Manager Bisnis — dasbor analitik, prediksi, reorder point. */
    case ManagerBisnis = 'manager_bisnis';

    /**
     * Label yang tampil di antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::AdminGudang => 'Admin Gudang',
            self::ManagerBisnis => 'Manager Bisnis',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
