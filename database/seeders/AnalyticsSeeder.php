<?php

namespace Database\Seeders;

use App\Jobs\AggregateDailyOutboundJob;
use App\Models\DailyOutboundAggregation;
use App\Services\PredictionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Menjalankan pipeline ETL sungguhan setelah riwayat pergerakan dibuat.
 *
 * Tidak ada angka yang dikarang di sini:
 *  - `daily_outbound_aggregations` dihitung oleh AggregateDailyOutboundJob
 *  - `monthly_demand_predictions` dihitung oleh PredictionService
 *
 * Prediksi dibuat untuk 5 bulan terakhir yang sudah selesai PLUS bulan depan.
 * Bagian penting: untuk bulan yang sudah lewat, `PredictionService::predict()`
 * otomatis membatasi histori hanya sampai akhir hari sebelum bulan itu
 * dimulai. Jadi angka di halaman Evaluasi Akurasi adalah backtest yang jujur,
 * bukan hasil yang melihat masa depan.
 */
class AnalyticsSeeder extends Seeder
{
    /** Berapa bulan lampau yang diprediksi (untuk bahan evaluasi akurasi). */
    private const BACKTEST_MONTHS = 5;

    public function run(): void
    {
        $this->agregasiHarian();

        $jumlah = $this->prediksi();

        $this->command?->info(sprintf(
            '  ETL: %d baris agregasi harian, %d prediksi bulanan',
            DailyOutboundAggregation::count(),
            $jumlah,
        ));
    }

    private function agregasiHarian(): void
    {
        // dispatchSync: job ini ShouldQueue, tapi untuk seeder kita memang
        // ingin menunggu sampai selesai supaya data langsung utuh.
        (new AggregateDailyOutboundJob(
            forDate: CarbonImmutable::yesterday()->toDateString(),
            lookbackDays: MovementHistorySeeder::HISTORY_DAYS + 30,
        ))->handle();
    }

    /**
     * Hitung prediksi untuk bulan-bulan lampau yang sudah selesai, bulan
     * berjalan, dan bulan depan.
     *
     * Bulan BERJALAN ikut dihitung karena Dasbor butuh jawabannya: "stok cukup
     * untuk bulan ini?".
     *
     * Halaman Evaluasi Akurasi tetap menolaknya karena `isEvaluable()` hanya
     * menerima bulan yang lewat penuh (bulan berjalan selalu terlihat
     * under-forecast karena aktualnya baru separuh). Satu bulan boleh ada
     * di Dasbor dan tidak ada di Akurasi.
     */
    private function prediksi(): int
    {
        $service = app(PredictionService::class);
        $total = 0;

        $bulanIni = CarbonImmutable::now()->startOfMonth();

        // Bulan lampau (terlama ke terbaru) — bahan Evaluasi Akurasi.
        for ($mundur = self::BACKTEST_MONTHS; $mundur >= 1; $mundur--) {
            $total += $service->calculateAndStore($bulanIni->subMonthsNoOverflow($mundur))->count();
        }

        // Bulan berjalan — bahan Dasbor. Dikomputasi dengan histori penuh
        // sampai akhir bulan sebelumnya, jadi tidak tercemar data parsial.
        $total += $service->calculateAndStore($bulanIni)->count();

        // Bulan depan — bahan Dasbor.
        $total += $service->calculateAndStore($bulanIni->addMonthNoOverflow())->count();

        return $total;
    }
}
