<?php

namespace App\Services\Support;

use Carbon\CarbonImmutable;

/**
 * Hasil perhitungan prediksi untuk satu produk pada satu bulan target.
 *
 * Objek ini murni nilai: belum menyentuh database. Penyimpanan ke tabel
 * monthly_demand_predictions dilakukan PredictionService::store().
 */
final class PredictionResult
{
    /**
     * @param  int  $productId  produk yang diprediksi
     * @param  string  $targetMonth  awal bulan target, format Y-m-d
     * @param  int  $predictedQty  total permintaan bulan target (selalu >= 0)
     * @param  float  $confidenceScore  0.00 - 100.00, diturunkan dari R kuadrat
     * @param  float  $slope  perubahan permintaan rata-rata per hari
     * @param  float  $intercept  titik potong garis regresi
     * @param  float  $averageDailyDemand  rata-rata permintaan per hari sepanjang jendela analisis
     * @param  int  $historyDays  panjang jendela histori yang dianalisis
     * @param  int  $observedDays  jumlah hari yang benar-benar punya catatan agregasi
     * @param  int  $daysInTargetMonth  jumlah hari pada bulan target
     * @param  bool  $hasEnoughHistory  false bila fallback ke rata-rata sederhana dipakai
     * @param  CarbonImmutable|null  $calculatedAt  waktu kalkulasi
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $targetMonth,
        public readonly int $predictedQty,
        public readonly float $confidenceScore,
        public readonly float $slope = 0.0,
        public readonly float $intercept = 0.0,
        public readonly float $averageDailyDemand = 0.0,
        public readonly int $historyDays = 0,
        public readonly int $observedDays = 0,
        public readonly int $daysInTargetMonth = 0,
        public readonly bool $hasEnoughHistory = false,
        public readonly ?CarbonImmutable $calculatedAt = null,
    ) {}

    /**
     * Nilai siap-simpan untuk tabel monthly_demand_predictions.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'product_id' => $this->productId,
            'target_month' => $this->targetMonth,
            'predicted_qty' => $this->predictedQty,
            'confidence_score' => round($this->confidenceScore, 2),
            'calculated_at' => $this->calculatedAt ?? CarbonImmutable::now(),
        ];
    }

    /**
     * Kunci pencarian untuk updateOrCreate(), mengikuti unique key tabel.
     *
     * targetMonth sengaja dikembalikan sebagai objek Carbon, bukan string
     * Y-m-d. Query builder memformatnya menjadi Y-m-d H:i:s, persis seperti
     * format yang ditulis Eloquent ke kolom tersebut. Kalau dipakai string
     * Y-m-d, pencarian TIDAK akan cocok pada SQLite (yang menyimpan kolom DATE
     * apa adanya sebagai teks), lalu updateOrCreate akan mencoba INSERT baris
     * kedua sampai menabrak UNIQUE constraint.
     *
     * @return array<string, mixed>
     */
    public function uniqueKey(): array
    {
        return [
            'product_id' => $this->productId,
            'target_month' => CarbonImmutable::parse($this->targetMonth)->startOfDay(),
        ];
    }

    /**
     * Rata-rata permintaan harian untuk menghitung berapa lama stok bertahan.
     */
    public function dailyDemand(): float
    {
        return $this->averageDailyDemand;
    }

    /**
     * Apakah hasil prediksi layak dipakai mengambil keputusan pemesanan?
     *
     * Yang menentukan adalah JUMLAH hari yang punya catatan agregasi, bukan
     * sekadar panjang jendela analisis: jendela 180 hari yang isinya cuma 3
     * hari data belum layak dijadikan dasar keputusan.
     */
    public function isReliable(int $minimumObservedDays = 14): bool
    {
        return $this->hasEnoughHistory
            && $this->observedDays >= $minimumObservedDays
            && $this->confidenceScore >= 50.0;
    }
}
