<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Services\Support\LowStockAlert;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pengiriman peringatan stok menipis ke kanal nyata (PRD UseCase: "Kirim
 * Peringatan Stok Menipis").
 *
 * Sebelumnya peringatan hanya muncul sebagai baris `alert.low_stock` di
 * audit_logs + `Log::warning`, yang tidak pernah dilihat pengguna. Kelas ini
 * mengubahnya jadi notifikasi yang muncul di lonceng notifikasi.
 *
 * Dua aturan penting:
 *
 * 1. DIKIRIM DI LUAR TRANSANSI. `afterCommit()` pada notifikasi Laravel hanya
 *    berlaku untuk notifikasi berantrean (ShouldQueue), bukan channel
 *    `database`. Karena itu pemanggil mengirimkannya setelah
 *    `DB::transaction()` selesai, dan seluruh pengiriman dibungkus try/catch:
 *    notifikasi gagal tidak boleh membatalkan perubahan stok yang sah.
 *
 * 2. DIBATASI COOLDOWN. Outbound beruntun pada produk yang sama bisa memicu
 *    puluhan peringatan. Alert yang belum dibaca dalam
 *    `cooldownHours` terakhir untuk produk yang sama dilewati, sehingga satu
 *    masalah menghasilkan satu notifikasi, bukan satu per movements.
 */
final class LowStockAlertService
{
    /** Default: satu peringatan per produk per 24 jam. */
    public const DEFAULT_COOLDOWN_HOURS = 24;

    /** Batas jumlah penerima per peringatan, biar tidak membanjiri tabel. */
    private const MAX_RECIPIENTS = 50;

    /**
     * Kirim peringatan ke seluruh pengguna yang berhak.
     *
     * @return int jumlah notifikasi yang benar-benar dibuat
     */
    public function dispatch(LowStockAlert $alert, int $cooldownHours = self::DEFAULT_COOLDOWN_HOURS): int
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $notification = new LowStockNotification($alert);
        $sent = 0;

        foreach ($recipients as $recipient) {
            if ($this->shouldSuppress($recipient, $alert, $cooldownHours)) {
                continue;
            }

            try {
                $recipient->notify($notification);

                $sent++;
            } catch (\Throwable $e) {
                // Kegagalan satu penerima tidak boleh menghentikan sisa
                // penerima, dan tidak boleh meruntuhkan perubahan stok.
                Log::error('SmartStock: gagal mengirim notifikasi stok menipis', [
                    'user_id' => $recipient->id,
                    'product_id' => $alert->productId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Siapa yang berhak menerima peringatan.
     *
     * Admin Gudang adalah pemilik operasional stok dan Manager Bisnis yang
     * mengambil keputusan pembelian — keduanya perlu tahu. Akun belum
     * terverifikasi tidak dikirimi karena belum bisa masuk aplikasi.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::AdminGudang->value, UserRole::ManagerBisnis->value])
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->limit(self::MAX_RECIPIENTS)
            ->get();
    }

    /**
     * True bila peringatan untuk produk ini sudah ada dan belum dibaca dalam
     * jendela cooldown.
     */
    public function shouldSuppress(
        User $recipient,
        LowStockAlert $alert,
        int $cooldownHours = self::DEFAULT_COOLDOWN_HOURS,
    ): bool {
        $threshold = CarbonImmutable::now()->subHours(max(0, $cooldownHours));

        return $recipient->notifications()
            ->where('type', LowStockNotification::class)
            ->where('audience_key', $alert->debounceKey())
            ->where('created_at', '>=', $threshold->format('Y-m-d H:i:s'))
            ->whereNull('read_at')
            ->exists();
    }

    /**
     * Susun peringatan dari kondisi produk saat ini.
     */
    public function alertFor(Product $product): LowStockAlert
    {
        return new LowStockAlert(
            productId: (int) $product->id,
            sku: $product->sku,
            productName: $product->name,
            currentStock: (int) $product->current_stock,
            reorderPoint: (int) $product->reorder_point,
            shortfall: max(0, (int) $product->reorder_point - (int) $product->current_stock),
        );
    }

    /**
     * Bersihkan notifikasi lama supaya tabel tidak tumbuh tanpa batas.
     *
     * Notifikasi yang sudah dibaca tidak perlu disimpan selamanya; yang belum
     * dibaca tetap dipertahankan sampai dibaca.
     *
     * @return int jumlah baris terhapus
     */
    public function pruneReadOlderThan(int $days = 30, ?DateTimeInterface $before = null): int
    {
        $cutoff = $before !== null
            ? CarbonImmutable::instance($before)
            : CarbonImmutable::now()->subDays($days);

        return User::query()
            ->select('id')
            ->get()
            ->reduce(function (int $total, User $user): int {
                return $total + $user->notifications()
                    ->whereNotNull('read_at')
                    ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s'))
                    ->delete();
            }, 0);
    }
}
