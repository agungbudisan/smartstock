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
        $targetMonth = $this->targetMonth !== null
            ? CarbonImmutable::parse($this->targetMonth)->startOfMonth()
            : CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth();

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

        Log::info('Smart-Stock: kalkulasi prediksi bulanan selesai', [
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

            Log::warning('Smart-Stock: stok diprediksi habis sebelum barang tiba', [
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
