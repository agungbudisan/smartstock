<?php

namespace App\Notifications;

use App\Services\Support\LowStockAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Peringatan stok menipis (UseCase: "Kirim Peringatan Stok Menipis").
 *
 * Hanya memakai channel `database` — kanal yang benar-benar bisa dilihat
 * pengguna tanpa infrastruktur tambahan. Dapur notifikasi ada di
 * `resources/js/Components/NotificationBell.vue`.
 *
 * Sengaja TIDAK `ShouldQueue`: notifikasi ini bagian dari alur operasional
 * yang harus langsung terlihat, dan memakai antrean akan membuat peringatan
 * hilang bila worker tidak berjalan.
 */
class LowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LowStockAlert $alert,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Kunci yang diisi ke kolom `audience_key` oleh DebouncedDatabaseChannel.
     */
    public function databaseAudienceKey(object $notifiable): string
    {
        return $this->alert->debounceKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->alert->productId,
            'sku' => $this->alert->sku,
            'title' => $this->alert->title(),
            'message' => $this->alert->message(),
            'tone' => $this->alert->currentStock === 0 ? 'danger' : 'warning',
            'current_stock' => $this->alert->currentStock,
            'reorder_point' => $this->alert->reorderPoint,
            'shortfall' => $this->alert->shortfall,
            // Tautan ke daftar reorder (khusus Manager) / halaman produk.
            'action_url' => "/products/{$this->alert->productId}/edit",
        ];
    }
}
