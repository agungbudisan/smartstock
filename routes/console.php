<?php

use App\Jobs\AggregateDailyOutboundJob;
use App\Jobs\CalculateMonthlyPredictionJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks (Laravel 12)
|--------------------------------------------------------------------------
| Dijadwalkan di routes/console.php — Laravel 12 tidak lagi memakai
| app/Console/Kernel.php. Jangan taruh schedule di tempat lain.
|
| Jalankan manual untuk menguji:
|     php artisan schedule:run
| Lihat daftar:
|     php artisan schedule:list
*/

Schedule::call(function (): void {
    /*
     * PRD UseCase: "Menghitung Prediksi Permintaan" setiap malam MEWUJIBKAN
     * "Mengeksekusi Agregasi Data Harian" terlebih dahulu.
     *
     * Bus::chain dipakai (bukan dua Schedule::job() terpisah) karena di
     * Laravel 12 Event::after() hanya menerima Closure — tidak ada cara
     * mengurutkan dua event job terjadwal, dan Schedule::job() tidak
     * menjamin urutan saat keduanya dibangkitkan pada menit yang sama.
     * Chain membuat urutan agregasi -> prediksi dijamin oleh antrean.
     */
    Bus::chain([
        new AggregateDailyOutboundJob,      // ringkas OLTP -> OLAP harian (kemarin)
        new CalculateMonthlyPredictionJob,  // regresi linear -> prediksi bulan depan
    ])->dispatch();
})
    ->dailyAt('00:00')
    ->name('smartstock:nightly-etl')
    ->description('Agregasi harian + prediksi bulanan (berurutan, 00:00)')
    ->withoutOverlapping()
    ->onOneServer();
