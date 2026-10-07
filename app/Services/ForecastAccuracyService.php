<?php

namespace App\Services;

use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Services\Support\AccuracyReport;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Evaluasi akurasi algoritma prediksi (PRD Modul 3 — "Evaluasi Akurasi &
 * Ekspor Laporan": mengukur tingkat eror algoritma).
 *
 * Bandingan diambil dari tabel OLAP `daily_outbound_aggregations` — basis yang
 * sama dengan yang dipakai PredictionService saat menghitung prediksi, jadi
 * perbandingannya apples-to-apples dan tidak membaca buku besar OLTP.
 *
 * ATURAN PENTING — hanya bulan yang sudah lewat penuh yang boleh dievaluasi.
 * Bulan yang sedang berjalan baru punya sebagian hari, sehingga permintaan
 * aktual selalu kelihatan jauh lebih kecil daripada prediksi dan algoritma
 * akan terlihat selalu under-forecast. `isEvaluable()` menjaga hal itu, dan
 * halaman menampilkan penjelasannya alih-alih menampilkan angka menyesatkan.
 *
 * Bulan berjalan dan bulan depan tetap dapat dievaluasi bila dipaksa lewat
 * `allowIncomplete()` — berguna untuk data uji, tidak untuk dipakai UI.
 */
final class ForecastAccuracyService
{
    /** Pembulatan metrik ke 2 desimal, supaya konsisten di JSON & CSV. */
    private const PRECISION = 2;

    /** Ambang WAPE (%) di bawah itu algoritma dianggap andal. */
    public const GOOD_WAPE_THRESHOLD = 25.0;

    // -----------------------------------------------------------------
    // Evaluasi satu bulan
    // -----------------------------------------------------------------

    public function evaluate(
        string|DateTimeInterface $targetMonth,
        ?int $categoryId = null,
        bool $allowIncomplete = false,
    ): AccuracyReport {
        $month = CarbonImmutable::instance(
            is_string($targetMonth) ? CarbonImmutable::parse($targetMonth) : $targetMonth,
        )->startOfMonth();

        $rows = $this->rows($month, $categoryId);
        $isEvaluable = $allowIncomplete || $this->isEvaluable($month);

        $totalPredicted = array_sum(array_column($rows, 'predicted_qty'));
        $totalActual = array_sum(array_column($rows, 'actual_qty'));

        $withActual = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['actual_qty'] > 0,
        ));

        $zeroActual = count($rows) - count($withActual);
        $count = count($rows);

        return new AccuracyReport(
            targetMonth: $month->toDateString(),
            label: $month->translatedFormat('F Y'),
            isEvaluable: $isEvaluable,
            predictionCount: $count,
            comparedCount: $count > 0 ? $count - $zeroActual : 0,
            zeroActualCount: $zeroActual,
            totalPredicted: (int) $totalPredicted,
            totalActual: (int) $totalActual,
            mae: $count > 0 ? $this->round(array_sum(array_column($rows, 'abs_error')) / $count) : null,
            // MAPE hanya terdefinisi bila actual > 0; baris tanpa penjualan
            // nyata akan menghasilkan Infinity, makanya dikeluarkan dari
            // perhitungan dan dihitung terpisah sebagai zeroActualCount.
            mape: $this->meanAbsolutePercentage($withActual),
            wape: $this->weightedAbsolutePercentage($rows),
            rmse: $count > 0 ? $this->round($this->rootMeanSquareError($rows)) : null,
            bias: $count > 0 ? $this->round(array_sum(array_column($rows, 'error')) / $count) : null,
            rows: $rows,
        );
    }

    /**
     * Rincian per produk: prediksi vs aktual beserta error-nya.
     *
     * Produk tanpa baris prediksi untuk bulan ini tidak ikut masuk — kita
     * menilai algoritma, bukan katalog.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(CarbonImmutable $month, ?int $categoryId = null): array
    {
        $actuals = $this->actualDemand($month);

        $predictions = MonthlyDemandPrediction::query()
            ->with('product:id,sku,name,current_stock,category_id')
            ->whereDate('target_month', $month->toDateString())
            ->when($categoryId, fn ($query) => $query->whereHas(
                'product',
                fn ($productQuery) => $productQuery->where('category_id', $categoryId),
            ))
            ->orderByDesc('predicted_qty')
            ->get();

        return $predictions
            ->map(function (MonthlyDemandPrediction $prediction) use ($actuals): array {
                $predicted = (int) $prediction->predicted_qty;
                $actual = (int) ($actuals[$prediction->product_id] ?? 0);
                $error = $predicted - $actual;

                return [
                    'product_id' => $prediction->product_id,
                    'sku' => $prediction->product?->sku,
                    'name' => $prediction->product?->name,
                    'predicted_qty' => $predicted,
                    'actual_qty' => $actual,
                    'error' => $error,
                    'abs_error' => abs($error),
                    // Error persen tidak terdefinisi saat actual = 0.
                    'error_pct' => $actual > 0 ? $this->round(abs($error) / $actual * 100) : null,
                    'signed_error_pct' => $actual > 0 ? $this->round($error / $actual * 100) : null,
                    'direction' => $error > 0 ? 'over' : ($error < 0 ? 'under' : 'exact'),
                    'confidence_score' => (float) $prediction->confidence_score,
                    'has_actual' => $actual > 0,
                ];
            })
            ->all();
    }

    // -----------------------------------------------------------------
    // Tren akurasi lintas bulan
    // -----------------------------------------------------------------

    /**
     * Akurasi bulan demi bulan untuk bulan-bulan yang sudah selesai.
     *
     * @param  int  $limit  berapa bulan terakhir yang dihitung
     * @return array<int, array<string, mixed>>
     */
    public function trend(int $limit = 6): array
    {
        $months = MonthlyDemandPrediction::query()
            ->select('target_month')
            ->distinct()
            ->orderByDesc('target_month')
            ->limit(max(1, $limit) + self::INCOMPLETE_TAIL_MONTHS)
            ->pluck('target_month')
            ->map(fn ($month): CarbonImmutable => CarbonImmutable::parse($month)->startOfMonth());

        return $months
            // Diurutkan ulang secara eksplisit: Collection::reverse() mempertahankan
            // kunci, jadi urutan indeksnya tidak boleh dijadikan andalan
            // oleh grafik atau ekspor CSV.
            ->sortBy(fn (CarbonImmutable $month): string => $month->toDateString())
            ->map(function (CarbonImmutable $month): array {
                $report = $this->evaluate($month);

                return [
                    'month' => $month->toDateString(),
                    'label' => $month->translatedFormat('M Y'),
                    'is_evaluable' => $report->isEvaluable,
                    'wape' => $report->wape,
                    'mae' => $report->mae,
                    'mape' => $report->mape,
                    'bias' => $report->bias,
                    'compared_count' => $report->comparedCount,
                    'total_predicted' => $report->totalPredicted,
                    'total_actual' => $report->totalActual,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Daftar bulan target yang punya prediksi, newest first.
     *
     * @return array<int, array{value: string, label: string, is_evaluable: bool}>
     */
    public function selectableMonths(int $limit = 24): array
    {
        return MonthlyDemandPrediction::query()
            ->select('target_month')
            ->distinct()
            ->orderByDesc('target_month')
            ->limit($limit)
            ->get()
            ->map(fn (MonthlyDemandPrediction $row): array => [
                'value' => $row->target_month->toDateString(),
                'label' => $row->target_month->translatedFormat('F Y'),
                'is_evaluable' => $this->isEvaluable($row->target_month),
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Aturan
    // -----------------------------------------------------------------

    /**
     * Hanya bulan yang sudah lewat penuh yang punya data aktual yang bisa
     * dipercaya.
     *
     * Parameter sengaja `DateTimeInterface`, bukan `CarbonImmutable`: cast
     * `date` pada model mengembalikan `Illuminate\Support\Carbon` (subclass
     * dari Carbon, bukan CarbonImmutable), jadi type-hipe yang sempit akan
     * melempar TypeError saat halaman ini dipanggil.
     */
    public function isEvaluable(DateTimeInterface $month): bool
    {
        $end = CarbonImmutable::instance($month)->endOfMonth()->startOfDay();

        return $end->lessThan(CarbonImmutable::now()->startOfDay());
    }

    /** Bulan berjalan + bulan depan, yang sengaja tidak bisa dievaluasi. */
    private const INCOMPLETE_TAIL_MONTHS = 2;

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * Permintaan nyata per produk pada satu bulan, dari agregasi harian.
     *
     * @return array<int, int>
     */
    private function actualDemand(CarbonImmutable $month): array
    {
        // Batas dikunci ke 'Y-m-d H:i:s' — format yang sama dipakai Eloquent
        // saat menulis kolom date. SQLite menyimpan kolom DATE sebagai teks, dan
        // dibandingkan string 'Y-m-d' baris terakhir hari itu akan terlewat.
        $totals = DailyOutboundAggregation::query()
            ->whereBetween('date', [
                $month->startOfMonth()->format('Y-m-d H:i:s'),
                $month->endOfMonth()->format('Y-m-d H:i:s'),
            ])
            ->select('product_id')
            ->selectRaw('SUM(total_qty_out) as actual_qty')
            ->groupBy('product_id')
            ->pluck('actual_qty', 'product_id');

        return $totals->map(fn ($qty): int => (int) $qty)->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function meanAbsolutePercentage(array $rows): ?float
    {
        if ($rows === []) {
            return null;
        }

        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += (float) $row['error_pct'];
        }

        return $this->round($sum / count($rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function weightedAbsolutePercentage(array $rows): ?float
    {
        $totalActual = array_sum(array_column($rows, 'actual_qty'));

        if ($rows === [] || $totalActual <= 0) {
            return null;
        }

        $totalAbsError = array_sum(array_column($rows, 'abs_error'));

        return $this->round($totalAbsError / $totalActual * 100);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function rootMeanSquareError(array $rows): float
    {
        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += ((float) $row['error']) ** 2;
        }

        return sqrt($sum / count($rows));
    }

    private function round(float $value): float
    {
        return round($value, self::PRECISION);
    }
}
