<?php

namespace App\Services\Support;

/**
 * Agregat metrik eror satu bulan target (PRD Modul 3 — "Evaluasi Akurasi").
 *
 * Semua metrik nullable: begitu tidak ada satu pun baris pembanding, metrik
 * bernilai null (bukan 0) supaya UI bisa membedakan "salah nol" dari
 * "tidak ada data untuk dibandingkan".
 *
 * Definisi (predicted = prediksi algoritma, actual = qty keluar nyata):
 *   MAE   = mean(|predicted - actual|)                 — rata-rata kesalahan absolut
 *   MAPE  = mean(|e| / actual) * 100                  — hanya baris dengan actual > 0
 *   WAPE  = sum(|e|) / sum(actual) * 100              — metrik utama; tahan actual = 0
 *   RMSE  = sqrt(mean(e^2))                           — menghukum kesalahan besar lebih berat
 *   bias  = mean(predicted - actual)                   — > 0 berarti bias over-forecast
 */
final class AccuracyReport
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  rincian per produk
     */
    public function __construct(
        public readonly string $targetMonth,
        public readonly string $label,
        public readonly bool $isEvaluable,
        public readonly int $predictionCount,
        public readonly int $comparedCount,
        public readonly int $zeroActualCount,
        public readonly int $totalPredicted,
        public readonly int $totalActual,
        public readonly ?float $mae,
        public readonly ?float $mape,
        public readonly ?float $wape,
        public readonly ?float $rmse,
        public readonly ?float $bias,
        public readonly array $rows,
    ) {}

    /**
     * Ringkasan siap kirim ke Vue sebagai prop.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_month' => $this->targetMonth,
            'label' => $this->label,
            'is_evaluable' => $this->isEvaluable,
            'prediction_count' => $this->predictionCount,
            'compared_count' => $this->comparedCount,
            'zero_actual_count' => $this->zeroActualCount,
            'total_predicted' => $this->totalPredicted,
            'total_actual' => $this->totalActual,
            'mae' => $this->mae,
            'mape' => $this->mape,
            'wape' => $this->wape,
            'rmse' => $this->rmse,
            'bias' => $this->bias,
        ];
    }

    /**
     *Persentase prediksi yang punya bandingan nyata.
     */
    public function coveragePercent(): ?float
    {
        if ($this->predictionCount === 0) {
            return null;
        }

        return round($this->comparedCount / $this->predictionCount * 100, 1);
    }
}
