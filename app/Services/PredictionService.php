<?php

namespace App\Services;

use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Services\Support\LinearRegression;
use App\Services\Support\PredictionResult;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Kerangka algoritma prediksi permintaan (PRD Modul 3 & 4).
 *
 * Sumber data: tabel OLAP `daily_outbound_aggregations` — bukan books besar
 * OLTP — supaya kalkulasi tetap ringan walau movement millions rows.
 *
 * Algoritma: regresi linear sederhana (OLS) pada deret harian permintaan:
 *   1. ambil agregasi harian produk pada jendela lookback
 *   2. rapatkan deret (hari tanpa catatan berarti 0 barang keluar)
 *   3. fit garis y = a + b·x dengan x = indeks hari
 *   4. jumlahkan prediksi tiap hari pada bulan target (clamp >= 0)
 *   5. R² dipakai sebagai confidence score (0-100)
 *
 * Bila histori belum cukup (< 2 titik), service jatuh ke rata-rata harian
 * sederhana dengan confidence 0 supaya dasbor tetap punya angka namun ditandai
 * belum layak dipakai.
 *
 * DIJALANKAN SECARA ASINKRON: dipanggil CalculateMonthlyPredictionJob (Fase 5).
 */
final class PredictionService
{
    /** Jendela histori default (hari) sebelum bulan target. */
    public const DEFAULT_LOOKBACK_DAYS = 180;

    /** Pengaman: jumlah data harian yang dianalisis per produk. */
    public const MAX_LOOKBACK_DAYS = 730;

    /** Jumlah hari minimal agar hasil dianggap layak dipakai. */
    public const MIN_HISTORY_DAYS = 2;

    // -----------------------------------------------------------------
    // Prediksi
    // -----------------------------------------------------------------

    /**
     * Prediksi total permintaan satu produk untuk satu bulan target.
     *
     * @param  Product  $product  produk yang diprediksi
     * @param  DateTimeInterface|null  $targetMonth  bulan target, default bulan depan
     * @param  int|null  $lookbackDays  panjang histori yang dianalisis
     */
    public function predict(
        Product $product,
        ?DateTimeInterface $targetMonth = null,
        ?int $lookbackDays = null,
    ): PredictionResult {
        $targetMonth = $this->normalizeTargetMonth($targetMonth);

        $lookbackDays = $this->sanitizeLookback($lookbackDays);
        $daysInTargetMonth = $targetMonth->daysInMonth;

        // Histori berhenti di akhir hari sebelum bulan target dimulai, atau
        // hari ini bila bulan target sedang berjalan.
        $historyEnd = $targetMonth->subDay()->endOfDay();
        if ($historyEnd->greaterThan(CarbonImmutable::now())) {
            $historyEnd = CarbonImmutable::now();
        }

        $historyStart = $historyEnd->subDays($lookbackDays - 1)->startOfDay();
        [$series, $observedDays] = $this->dailyDemandSeries($product, $historyStart, $historyEnd);

        $historyDays = count($series);
        $averageDailyDemand = $historyDays > 0
            ? array_sum($series) / $historyDays
            : 0.0;

        // Fallback: data yang benar-benar tercatat belum cukup untuk fitting garis.
        if ($observedDays < self::MIN_HISTORY_DAYS) {
            return new PredictionResult(
                productId: $product->id,
                targetMonth: $targetMonth->toDateString(),
                predictedQty: (int) round($averageDailyDemand * $daysInTargetMonth),
                confidenceScore: 0.0,
                slope: 0.0,
                intercept: $averageDailyDemand,
                averageDailyDemand: $averageDailyDemand,
                historyDays: $historyDays,
                observedDays: $observedDays,
                daysInTargetMonth: $daysInTargetMonth,
                hasEnoughHistory: false,
                calculatedAt: CarbonImmutable::now(),
            );
        }

        // x = indeks hari (0 = awal histori), y = qty keluar per hari.
        $points = [];
        foreach (array_values($series) as $index => $quantity) {
            $points[] = [(float) $index, (float) $quantity];
        }

        $regression = LinearRegression::fit($points);

        // Prediksi bulan target melanjutkan indeks histori.
        $targetXs = range((float) $historyDays, (float) ($historyDays + $daysInTargetMonth - 1));
        $predictedQty = (int) round(array_sum($regression->predictNonNegative($targetXs)));

        return new PredictionResult(
            productId: $product->id,
            targetMonth: $targetMonth->toDateString(),
            predictedQty: $predictedQty,
            confidenceScore: round($regression->rSquared * 100, 2),
            slope: $regression->slope,
            intercept: $regression->intercept,
            averageDailyDemand: $averageDailyDemand,
            historyDays: $historyDays,
            observedDays: $observedDays,
            daysInTargetMonth: $daysInTargetMonth,
            hasEnoughHistory: true,
            calculatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Prediksi untuk seluruh produk aktif (chunked agar bebas memori).
     *
     * @param  bool  $skipWithoutHistory  lewati produk yang belum punya satu
     *                                    pun histori agregasi
     * @return Collection<int, PredictionResult>
     */
    public function predictAll(
        ?DateTimeInterface $targetMonth = null,
        ?int $lookbackDays = null,
        bool $skipWithoutHistory = true,
    ): Collection {
        $results = new Collection;

        Product::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use (&$results, $targetMonth, $lookbackDays, $skipWithoutHistory): void {
                foreach ($products as $product) {
                    $result = $this->predict($product, $targetMonth, $lookbackDays);

                    if ($skipWithoutHistory && $result->observedDays === 0) {
                        continue;
                    }

                    $results->push($result);
                }
            });

        return $results;
    }

    // -----------------------------------------------------------------
    // Persistensi
    // -----------------------------------------------------------------

    /**
     * Simpan hasil prediksi ke monthly_demand_predictions.
     *
     * Idempoten lewat updateOrCreate pada unique key (product_id, target_month).
     */
    public function store(PredictionResult $result): MonthlyDemandPrediction
    {
        return MonthlyDemandPrediction::updateOrCreate(
            $result->uniqueKey(),
            $result->toAttributes(),
        );
    }

    /**
     * Hitung lalu simpan prediksi untuk semua produk — method yang dipanggil
     * CalculateMonthlyPredictionJob.
     *
     * @return Collection<int, MonthlyDemandPrediction>
     */
    public function calculateAndStore(
        ?DateTimeInterface $targetMonth = null,
        ?int $lookbackDays = null,
        bool $skipWithoutHistory = true,
    ): Collection {
        return $this->predictAll($targetMonth, $lookbackDays, $skipWithoutHistory)
            ->map(fn (PredictionResult $result) => $this->store($result));
    }

    // -----------------------------------------------------------------
    // Evaluasi stok (PRD bagian 4: "stok diprediksi habis dalam waktu dekat")
    // -----------------------------------------------------------------

    /**
     * Tanggal perkiraan habisnya stok (PRD bagian 4: "stok diprediksi habis
     * dalam waktu dekat").
     *
     * Proyeksi memakai laju rata-rata harian historis (flat rate) yang
     * dikumulasi sampai melampaui stok saat ini; sederhana, konservatif, dan
     * mudah dijelaskan ke manajer. Angka tren regresi tidak dipakai di sini
     * karena confidence-nya bisa rendah.
     *
     * @param  int  $horizonDays  batas horizon, mencegah prediksi tak terbatas
     *                            untuk produk dengan permintaan mendekati nol
     */
    public function projectedStockoutDate(
        Product $product,
        PredictionResult $result,
        int $horizonDays = 365,
    ): ?CarbonImmutable {
        if ($result->averageDailyDemand <= 0.0) {
            return null; // tidak ada tekanan permintaan
        }

        if ($product->current_stock <= 0) {
            return CarbonImmutable::now();
        }

        $cumulative = 0.0;
        $today = CarbonImmutable::now();

        for ($day = 1; $day <= $horizonDays; $day++) {
            $cumulative += $result->averageDailyDemand;

            if ($cumulative >= $product->current_stock) {
                return $today->addDays($day);
            }
        }

        return null;
    }

    /**
     * Berapa hari stok bertahan pada laju permintaan rata-rata saat ini.
     */
    public function daysOfCover(Product $product, PredictionResult $result): ?int
    {
        if ($result->averageDailyDemand <= 0.0) {
            return null;
        }

        return (int) floor($product->current_stock / $result->averageDailyDemand);
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * Deret harian permintaan, rapat (dense): hari tanpa baris agregasi berarti
     * 0 barang keluar, bukan "tidak diketahui".
     *
     * @return array{0: array<int, int>, 1: int} [deret qty harian, jumlah hari yang punya catatan]
     */
    private function dailyDemandSeries(Product $product, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Batas query dikunci ke format 'Y-m-d H:i:s' — format yang sama dipakai
        // Eloquent saat menulis kolom date. Ini penting karena SQLite tidak punya
        // tipe DATE sungguhan dan menyimpannya sebagai teks; membandingkan
        // teks '2026-10-31 00:00:00' dengan string '2026-10-31' akan salah dan
        // membuat baris terakhir hari itu terlewat. Format ISO membuat
        // perbandingan leksikografis selalu benar di kedua driver.
        $totals = DailyOutboundAggregation::query()
            ->where('product_id', $product->id)
            ->whereBetween('date', [
                $from->startOfDay()->format('Y-m-d H:i:s'),
                $to->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->pluck('total_qty_out', 'date')
            ->mapWithKeys(function ($quantity, $date): array {
                // Kunci hasil pluck bisa 'Y-m-d' (MySQL) atau
                // 'Y-m-d H:i:s' (SQLite); dinormalkan ke tanggal saja.
                return [substr((string) $date, 0, 10) => (int) $quantity];
            })
            ->all();

        $series = [];
        $cursor = $from;
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($to) && $guard++ < self::MAX_LOOKBACK_DAYS) {
            $date = $cursor->toDateString();
            $series[] = $totals[$date] ?? 0;
            $cursor = $cursor->addDay();
        }

        return [$series, count($totals)];
    }

    /**
     * Normalisasi bulan target: jadi CarbonImmutable pada tanggal 1 pukul 00:00.
     * Nilai kosong berarti bulan depan.
     */
    private function normalizeTargetMonth(?DateTimeInterface $targetMonth): CarbonImmutable
    {
        if ($targetMonth === null) {
            return CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth();
        }

        return CarbonImmutable::instance($targetMonth)->startOfMonth();
    }

    /**
     * Jaga agar nilai lookback masuk rentang yang masuk akal.
     */
    private function sanitizeLookback(?int $lookbackDays): int
    {
        $lookbackDays ??= self::DEFAULT_LOOKBACK_DAYS;

        return max(self::MIN_HISTORY_DAYS, min($lookbackDays, self::MAX_LOOKBACK_DAYS));
    }
}
