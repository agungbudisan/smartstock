<?php

namespace App\Services\Support;

use InvalidArgumentException;

/**
 * Regresi linear sederhana (OLS) untuk satu variabel bebas.
 *
 * Sengaja ditulis murni-matematis tanpa menyentuh database supaya bisa diuji
 * terpisah dari logika bisnis. PredictionService membaca tabel
 * daily_outbound_aggregations, lalu menyerahkan deret angkanya ke kelas ini.
 *
 * Model: y = a + b·x
 *   b = Σ((x - x̄)(y - ȳ)) / Σ((x - x̄)²)
 *   a = ȳ - b·x̄
 *   R² = 1 - SSres / SStot
 */
final class LinearRegression
{
    private function __construct(
        /** Kemiringan garis regresi: rata-rata perubahan permintaan per hari. */
        public readonly float $slope,
        /** Titik potong garis regresi. */
        public readonly float $intercept,
        /** Koefisien determinasi 0.0 - 1.0; dipakai sebagai confidence score. */
        public readonly float $rSquared,
        /** Rata-rata nilai y dari data yang dipakai. */
        public readonly float $meanY,
        /** Jumlah titik data yang dipakai. */
        public readonly int $dataPoints,
    ) {}

    /**
     * Bangun model dari sekumpulan titik (x, y).
     *
     * @param  list<array{0: int|float, 1: int|float}>  $points  titik (x, y), minimal 2
     *
     * @throws InvalidArgumentException bila titik < 2 atau seluruh nilai x identik
     *                                  (garis regresi tidak terdefinisi karena penyebutnya nol)
     */
    public static function fit(array $points): self
    {
        if (count($points) < 2) {
            throw new InvalidArgumentException('Regresi linear membutuhkan minimal 2 titik data.');
        }

        $n = count($points);
        $sumX = 0.0;
        $sumY = 0.0;

        foreach ($points as [$x, $y]) {
            $sumX += (float) $x;
            $sumY += (float) $y;
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;

        $covariance = 0.0; // Σ((x - x̄)(y - ȳ))
        $varianceX = 0.0;   // Σ((x - x̄)²)
        $totalY = 0.0;      // Σ(y - ȳ)²

        foreach ($points as [$x, $y]) {
            $dx = (float) $x - $meanX;
            $dy = (float) $y - $meanY;
            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $totalY += $dy * $dy;
        }

        if ($varianceX === 0.0) {
            throw new InvalidArgumentException('Seluruh nilai x identik, regresi tidak dapat dihitung.');
        }

        $slope = $covariance / $varianceX;
        $intercept = $meanY - ($slope * $meanX);

        $residualSum = 0.0; // SSE untuk R²
        foreach ($points as [$x, $y]) {
            $error = ((float) $y) - ($intercept + ($slope * (float) $x));
            $residualSum += $error * $error;
        }

        if ($totalY === 0.0) {
            // y konstan: garis sudah memprediksi nilai itu persis (R² = 1).
            // Jika tetap ada residual, itu hanya derau pembulatan (R² = 0).
            $rSquared = $residualSum <= 1e-12 ? 1.0 : 0.0;
        } else {
            $rSquared = 1.0 - ($residualSum / $totalY);
        }

        return new self(
            slope: $slope,
            intercept: $intercept,
            // R² bisa sedikit keluar rentang karena pembulatan floating point.
            rSquared: min(1.0, max(0.0, $rSquared)),
            meanY: $meanY,
            dataPoints: $n,
        );
    }

    /**
     * Nilai prediksi untuk satu titik x.
     */
    public function predict(float $x): float
    {
        return $this->intercept + ($this->slope * $x);
    }

    /**
     * Prediksi untuk deret x, di-clamp agar tidak pernah negatif karena
     * permintaan barang tidak bisa minus.
     *
     * @param  list<int|float>  $xs
     * @return list<float>
     */
    public function predictNonNegative(array $xs): array
    {
        return array_map(fn (int|float $x) => max(0.0, $this->predict((float) $x)), $xs);
    }

    /**
     * Apakah tren permintaan menurun?
     */
    public function isDeclining(): bool
    {
        return $this->slope < 0;
    }
}
