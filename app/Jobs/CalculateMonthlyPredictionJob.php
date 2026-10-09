<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\PredictionService;
use App\Services\Support\PredictionResult;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hitung prediksi permintaan bulanan dan simpan ke
 * `monthly_demand_predictions` — PRD Modul 3 & 4.
 *
 * Dijalankan asinkron setiap tengah malam, setelah agregasi harian selesai
 * (lihat routes/console.php: keduanya berada dalam satu Bus::chain).
 *
 * Sesuai PRD Modul 4, job ini juga mengevaluasi stok yang diprediksi habis
 * dalam waktu dekat. Untuk sekarang notifikasi dikirim lewat Log; titik
 * integrasi yang tepat untuk kanal lain (email, tabel notifications) adalah
 * method reportProjectedStockouts().
 */
class CalculateMonthlyPredictionJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Jumlah percobaan bila gagal. */
    public int $tries = 3;

    /** Jeda antar percobaan, dalam detik. */
    public int $backoff = 120;

    /** Batas waktu eksekusi, dalam detik. */
    public int $timeout = 1800;

    /** Ambang default: beri peringatan bila stok habis sebelum lead time cepat. */
    public const DEFAULT_STOCKOUT_THRESHOLD_DAYS = 14;

    /**
     * @param  string|null  $targetMonth  bulan target format Y-m-01; null = bulan depan
     * @param  int|null  $lookbackDays  panjang histori yang dianalisis
     * @param  int  $stockoutThresholdDays  abaikan peringatan bila days of cover
     *                                      lebih besar dari angka ini
     */
    public function __construct(
        public readonly ?string $targetMonth = null,
        public readonly ?int $lookbackDays = null,
        public readonly int $stockoutThresholdDays = self::DEFAULT_STOCKOUT_THRESHOLD_DAYS,
    ) {}

    /**
     * @return int jumlah prediksi yang tersimpan
     */
    public function handle(PredictionService $predictionService): int
    {
        $bulan = $this->targetMonth !== null
            ? [CarbonImmutable::parse($this->targetMonth)->startOfMonth()]
            : $this->bulanDefault();

        $total = 0;
        foreach ($bulan as $satuBulan) {
            $total += $this->hitung($predictionService, $satuBulan);
        }

        return $total;
    }

    /**
     * Bulan yang harus ada setiap hari: bulan BERJALAN dan bulan depan.
     *
     * Bulan depan saja -- seperti sebelumnya -- berarti bulan berjalan tidak
     * pernah punya prediksi sama sekali: begitu bulan itu berganti, ia
     * langsung berpindah dari "bulan depan" ke "bulan lampau" dan tidak ada
     * yang menghitungnya. Dasbor lalu melompati bulan itu, padahal bulan
     * berjalan adalah yang paling operatif: itu yang menjawab "stok cukup untuk
     * bulan ini?".
     *
     * Perhitungannya sendiri tetap sah untuk bulan berjalan: `predict()`
     * mengambil histori sampai `$targetMonth->subDay()` -- akhir bulan
     * sebelumnya -- lalu memroyeksikan seluruh bulan target. Tidak ada
     * data masa depan yang bocor, dan tidak ada data bulan berjalan yang
     * separuh. Karena batas historinya tidak bergerak selama sebulan, hasilnya
     * juga stabil: dihitung ulang tiap malam, angkanya tidak berubah.
     *
     * @return array<int, CarbonImmutable>
     */
    private function bulanDefault(): array
    {
        $sekarang = CarbonImmutable::now();

        return [
            $sekarang->startOfMonth(),
            $sekarang->addMonthNoOverflow()->startOfMonth(),
        ];
    }

    /**
     * @return int jumlah prediksi untuk satu bulan target
     */
    private function hitung(PredictionService $predictionService, CarbonImmutable $targetMonth): int
    {
        /*
         * Sengaja memakai predictAll() + store() per hasil, bukan
         * calculateAndStore(): object PredictionResult asli menyimpan
         * averageDailyDemand historis, sedangkan baris tersimpan di tabel hanya
         * menyimpan prediksi bulanan. Kalau days of cover dihitung dari
         * predicted_qty / days_in_month, angkanya ikut terpengaruh tren dan
         * tidak lagi mencerminkan laju pemakaian sebenarnya.
         */
        $results = $predictionService->predictAll(
            targetMonth: $targetMonth,
            lookbackDays: $this->lookbackDays,
        );

        foreach ($results as $result) {
            $predictionService->store($result);
        }

        $alerts = $this->reportProjectedStockouts($predictionService, $results);

        Log::info('SmartStock: kalkulasi prediksi bulanan selesai', [
            'target_month' => $targetMonth->toDateString(),
            'predictions' => $results->count(),
            'stockout_alerts' => $alerts,
        ]);

        return $results->count();
    }

    /**
     * Peringatan dini: produk yang stoknya diperkirakan habis sebelum barang
     * dari pemasok sempat tiba (PRD bagian 4).
     *
     * @param  Collection<int, PredictionResult>  $predictions
     * @return int jumlah produk yang perlu diwaspadai
     */
    private function reportProjectedStockouts(
        PredictionService $predictionService,
        Collection $predictions,
    ): int {
        if ($predictions->isEmpty()) {
            return 0;
        }

        $products = Product::query()
            ->whereIn('id', $predictions->pluck('productId'))
            ->get()
            ->keyBy('id');

        $alerts = 0;

        foreach ($predictions as $prediction) {
            $product = $products->get($prediction->productId);

            if (! $product instanceof Product) {
                continue;
            }

            $daysOfCover = $predictionService->daysOfCover($product, $prediction);

            if ($daysOfCover === null || $daysOfCover > $this->stockoutThresholdDays) {
                continue;
            }

            Log::warning('SmartStock: stok diprediksi habis sebelum barang tiba', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'current_stock' => $product->current_stock,
                'days_of_cover' => $daysOfCover,
                'lead_time_days' => $product->fastestLeadTimeDays(),
                'projected_stockout_at' => $predictionService
                    ->projectedStockoutDate($product, $prediction)?->toDateString(),
                'confidence_score' => $prediction->confidenceScore,
            ]);

            $alerts++;
        }

        return $alerts;
    }
}
