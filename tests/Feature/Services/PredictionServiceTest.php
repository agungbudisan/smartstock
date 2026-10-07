<?php

namespace Tests\Feature\Services;

use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Services\PredictionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4 — PredictionService.
 *
 * Waktu dibekukan (setTestNow) supaya perhitungan hari dan hasil prediksi
 * deterministik dan tidak bergantung tanggal eksekusi test.
 */
class PredictionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** "Sekarang" dibekukan di akhir Oktober 2026. */
    private const NOW = '2026-11-01 00:00:00';

    private PredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        $this->service = new PredictionService;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helper penyusun data
    // -----------------------------------------------------------------

    /**
     * Buat agregasi harian naik linear: qty = $startQty + index.
     *
     * @return array<int, string> tanggal sebagai kunci
     */
    private function seedLinearDemand(Product $product, int $days, int $startQty = 10, int $step = 1, ?string $endDate = null): array
    {
        $end = CarbonImmutable::parse($endDate ?? '2026-10-31')->startOfDay();
        $dates = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $end->subDays($i)->toDateString();
            $index = $days - 1 - $i;

            DailyOutboundAggregation::create([
                'product_id' => $product->id,
                'date' => $date,
                'total_qty_out' => $startQty + ($step * $index),
            ]);

            $dates[$date] = $startQty + ($step * $index);
        }

        return $dates;
    }

    // -----------------------------------------------------------------
    // Perhitungan regresi
    // -----------------------------------------------------------------

    public function test_it_extrapolates_a_perfectly_linear_trend(): void
    {
        // 60 hari: qty 10..69, slope 1/hari, intercept 10, R² = 1.
        $product = Product::factory()->create(['current_stock' => 500]);
        $this->seedLinearDemand($product, days: 60);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);

        // November 2026 = 30 hari; x berlanjut dari 60 sampai 89.
        // Σ(10 + x) untuk x = 60..89 = 300 + 2235 = 2535
        $this->assertSame(2535, $result->predictedQty);
        $this->assertSame(100.0, $result->confidenceScore);
        $this->assertEqualsWithDelta(1.0, $result->slope, 1e-9);
        $this->assertEqualsWithDelta(10.0, $result->intercept, 1e-9);
        $this->assertSame(60, $result->historyDays);
        $this->assertSame(30, $result->daysInTargetMonth);
        $this->assertTrue($result->hasEnoughHistory);
        $this->assertTrue($result->isReliable());
    }

    public function test_it_reports_a_lower_confidence_for_a_noisy_series(): void
    {
        $product = Product::factory()->create();
        $end = CarbonImmutable::parse('2026-10-31');

        // Lonjakan tak beraturan di tengah.
        foreach ([3, 5, 40, 6, 4, 45, 7, 5, 60, 6] as $i => $qty) {
            DailyOutboundAggregation::create([
                'product_id' => $product->id,
                'date' => $end->subDays(9 - $i)->toDateString(),
                'total_qty_out' => $qty,
            ]);
        }

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 10);

        $this->assertGreaterThanOrEqual(0.0, $result->confidenceScore);
        $this->assertLessThan(60.0, $result->confidenceScore, 'Data bergelombang seharusnya berkepercayaan rendah');
        $this->assertFalse($result->isReliable());
    }

    public function test_a_declining_trend_never_produces_a_negative_prediction(): void
    {
        $product = Product::factory()->create();
        $end = CarbonImmutable::parse('2026-10-31');

        // Demand 100 turun 3/hari sampai habis, lalu nol.
        for ($i = 0; $i < 20; $i++) {
            DailyOutboundAggregation::create([
                'product_id' => $product->id,
                'date' => $end->subDays(19 - $i)->toDateString(),
                'total_qty_out' => max(0, 100 - (3 * $i)),
            ]);
        }

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 20);

        $this->assertLessThan(0.0, $result->slope);
        $this->assertGreaterThanOrEqual(0, $result->predictedQty);
    }

    public function test_days_without_records_are_treated_as_zero_demand(): void
    {
        $product = Product::factory()->create();
        $end = CarbonImmutable::parse('2026-10-31');

        // Hanya 3 hari ada datanya dalam jendela 10 hari.
        foreach ([9 => 0, 5 => 0, 1 => 12] as $daysAgo => $qty) {
            DailyOutboundAggregation::create([
                'product_id' => $product->id,
                'date' => $end->subDays($daysAgo)->toDateString(),
                'total_qty_out' => $qty,
            ]);
        }

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 10);

        $this->assertSame(10, $result->historyDays, 'Deret harus rapat: 10 titik');
        $this->assertEqualsWithDelta(1.2, $result->averageDailyDemand, 1e-9, '12 pcs / 10 hari');
    }

    // -----------------------------------------------------------------
    // Kasus tepi
    // -----------------------------------------------------------------

    public function test_a_product_without_any_history_returns_a_zero_result(): void
    {
        $product = Product::factory()->create(['current_stock' => 40]);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertSame(30, $result->historyDays, 'Jendela analisis tetap 30 hari walau kosong');
        $this->assertSame(0, $result->observedDays, 'Tapi tidak ada satu pun hari yang punya catatan');
        $this->assertSame(0, $result->predictedQty);
        $this->assertSame(0.0, $result->confidenceScore);
        $this->assertFalse($result->hasEnoughHistory);
        $this->assertFalse($result->isReliable());
    }

    public function test_a_single_observed_day_falls_back_to_the_simple_average(): void
    {
        $product = Product::factory()->create();
        DailyOutboundAggregation::factory()->create([
            'product_id' => $product->id,
            'date' => '2026-10-31',
            'total_qty_out' => 12,
        ]);

        // Jendela 10 hari, tapi hanya 1 hari yang punya catatan.
        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 10);

        $this->assertFalse($result->hasEnoughHistory);
        $this->assertSame(10, $result->historyDays);
        $this->assertSame(1, $result->observedDays);
        $this->assertSame(0.0, $result->confidenceScore);
        $this->assertSame(0.0, $result->slope);
        // 12 pcs / 10 hari = 1.2 per hari, x 30 hari = 36
        $this->assertSame(36, $result->predictedQty);
    }

    public function test_the_target_month_defaults_to_next_month_and_is_normalised_to_day_one(): void
    {
        $product = Product::factory()->create();
        $this->seedLinearDemand($product, days: 30);

        $result = $this->service->predict($product);

        $this->assertSame('2026-12-01', $result->targetMonth, 'Bulan target default = bulan setelah 2026-11-01');

        // Tanggal target di tengah bulan harus dinormalkan ke tanggal 1.
        $midMonth = $this->service->predict($product, CarbonImmutable::parse('2027-03-17 15:30:00'));
        $this->assertSame('2027-03-01', $midMonth->targetMonth);
    }

    public function test_lookback_is_clamped_to_a_sane_range(): void
    {
        $product = Product::factory()->create();

        // Nilai ekstrem tidak boleh membuat loop tanpa batas.
        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 999999);

        $this->assertSame(PredictionService::MAX_LOOKBACK_DAYS, $result->historyDays, 'Lookback dibatasi maksimal 730 hari');

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 0);
        $this->assertSame(PredictionService::MIN_HISTORY_DAYS, $result->historyDays, 'Lookback dibatasi minimal 2 hari');
    }

    public function test_history_stops_at_the_end_of_the_previous_month(): void
    {
        $product = Product::factory()->create();

        // Agregasi SETELAH akhir bulan sebelumnya tidak boleh dipakai.
        DailyOutboundAggregation::factory()->create([
            'product_id' => $product->id,
            'date' => '2026-11-15',
            'total_qty_out' => 9999,
        ]);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertSame(0, $result->predictedQty, 'Data setelah batas histori tidak boleh terpakai');
    }

    // -----------------------------------------------------------------
    // Persistensi
    // -----------------------------------------------------------------

    public function test_store_writes_to_monthly_demand_predictions(): void
    {
        $product = Product::factory()->create();
        $this->seedLinearDemand($product, days: 60);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);
        $stored = $this->service->store($result);

        $this->assertInstanceOf(MonthlyDemandPrediction::class, $stored);

        // Kolom date dibaca lewat model (cast), bukan lewat assertDatabaseHas,
        // karena SQLite menyimpannya sebagai teks 'Y-m-d H:i:s'.
        $fresh = $stored->fresh();
        $this->assertSame($product->id, $fresh->product_id);
        $this->assertSame('2026-11-01', $fresh->target_month->toDateString());
        $this->assertSame(2535, $fresh->predicted_qty);
        $this->assertSame('100.00', $fresh->confidence_score);
        $this->assertNotNull($fresh->calculated_at);
    }

    public function test_storing_twice_for_the_same_month_updates_instead_of_duplicating(): void
    {
        $product = Product::factory()->create();
        $this->seedLinearDemand($product, days: 60);

        $first = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);
        $this->service->store($first);

        // KOREKSI histori -> hasil berubah, bulan target sama.
        DailyOutboundAggregation::where('product_id', $product->id)->update(['total_qty_out' => 1]);

        $second = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);
        $this->service->store($second);

        $this->assertSame(1, MonthlyDemandPrediction::where('product_id', $product->id)->count());

        $fresh = MonthlyDemandPrediction::where('product_id', $product->id)->first();
        $this->assertSame('2026-11-01', $fresh->target_month->toDateString());
        $this->assertSame($second->predictedQty, $fresh->predicted_qty);
    }

    public function test_calculate_and_store_covers_products_with_history_and_skips_the_rest(): void
    {
        $adaHistori = Product::factory()->create(['current_stock' => 200]);
        Product::factory()->create(['current_stock' => 10]); // tanpa histori

        $this->seedLinearDemand($adaHistori, days: 60);

        $stored = $this->service->calculateAndStore(CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);

        $this->assertCount(1, $stored);
        $this->assertSame(1, MonthlyDemandPrediction::count());
        $this->assertSame($adaHistori->id, $stored->first()->product_id);
        $this->assertSame(2535, $stored->first()->predicted_qty);
    }

    public function test_calculate_and_store_can_include_products_without_history(): void
    {
        Product::factory()->count(2)->create();

        $stored = $this->service->calculateAndStore(
            CarbonImmutable::parse('2026-11-01'),
            lookbackDays: 30,
            skipWithoutHistory: false,
        );

        $this->assertCount(2, $stored);
        $this->assertSame(2, MonthlyDemandPrediction::count());
        $this->assertSame(0.0, (float) $stored->first()->confidence_score);
    }

    public function test_soft_deleted_products_are_not_predicted(): void
    {
        $product = Product::factory()->create();
        $this->seedLinearDemand($product, days: 30);
        $product->delete();

        $results = $this->service->predictAll(CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertCount(0, $results);
    }

    // -----------------------------------------------------------------
    // Evaluasi stok
    // -----------------------------------------------------------------

    public function test_days_of_cover_uses_the_historical_daily_average(): void
    {
        $product = Product::factory()->create(['current_stock' => 300]);
        $this->seedLinearDemand($product, days: 60);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);

        // Rata-rata (10..69)/60 = 39.5 -> 300 / 39.5 = 7 hari
        $this->assertEqualsWithDelta(39.5, $result->averageDailyDemand, 1e-9);
        $this->assertSame(7, $this->service->daysOfCover($product, $result));
    }

    public function test_days_of_cover_is_null_without_demand(): void
    {
        $product = Product::factory()->create(['current_stock' => 50]);
        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertNull($this->service->daysOfCover($product, $result));
    }

    public function test_projected_stockout_date_falls_inside_the_horizon(): void
    {
        $product = Product::factory()->create(['current_stock' => 300]);
        $this->seedLinearDemand($product, days: 60);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 60);

        $stockout = $this->service->projectedStockoutDate($product, $result);

        $this->assertNotNull($stockout);
        // 300 / 39.5 -> kumulatif melewati 300 pada hari ke-8 (2026-11-01 + 8 hari).
        $this->assertSame('2026-11-09', $stockout->toDateString());
    }

    public function test_projected_stockout_date_is_null_when_there_is_no_demand(): void
    {
        $product = Product::factory()->create(['current_stock' => 300]);
        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertNull($this->service->projectedStockoutDate($product, $result));
    }

    public function test_a_product_already_empty_is_projected_to_run_out_today(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $this->seedLinearDemand($product, days: 30);

        $result = $this->service->predict($product, CarbonImmutable::parse('2026-11-01'), lookbackDays: 30);

        $this->assertSame(
            CarbonImmutable::parse('2026-11-01')->toDateString(),
            $this->service->projectedStockoutDate($product, $result)->toDateString(),
        );
    }
}
