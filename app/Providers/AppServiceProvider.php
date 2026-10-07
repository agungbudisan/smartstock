<?php

namespace App\Providers;

use App\Notifications\Channels\DebouncedDatabaseChannel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Channel `database` Laravel tidak bisa menyuntik kolom sendiri ke
        // tabel notifications. Kita butuh kolom `audience_key` yang terindeks
        // untuk debounce peringatan stok menipis, jadi channel aslinya
        // diganti lewat extension point resmi Laravel.
        Notification::resolved(function (ChannelManager $service): void {
            $service->extend('database', fn (): DebouncedDatabaseChannel => new DebouncedDatabaseChannel);
        });
    }
}
