<?php

namespace App\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Channel `database` yang menambah kolom `audience_key`.
 *
 * Kenapa tidak cukup kolom `data`? Karena debounce menanyakan
 * "apakah sudah ada notifikasi untuk produk ini?" — itu query filter yang
 * harus pakai indeks. Mencari nilai di dalam JSON COLUMN tidak portabel:
 * MySQL butuh `JSON_EXTRACT`, SQLite punya fungsi yang sama, dan
 * driver-nya bisa berbeda lagi. Kolom terpisah + index jauh lebih aman.
 *
 * Daftarkan lewat `Notification::extend('database', ...)` di AppServiceProvider.
 */
class DebouncedDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  Model  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        // Notifikasi tanpa kunci debounce tetap boleh lewat; kolomnya nullable.
        if (method_exists($notification, 'databaseAudienceKey')) {
            $payload['audience_key'] = $notification->databaseAudienceKey($notifiable);
        }

        return $payload;
    }
}
