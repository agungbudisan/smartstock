<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CalculateMonthlyPredictionJob;
use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\PredictionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Fase 5 — CalculateMonthlyPredictionJob.
 */
class CalculateMonthlyPredictionJobTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-01 00:00:00');
        CarbonImmutable::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Historis permintaan naik linear 10, 11, 12, ... selama $days hari.
     */
    private function seedHistory(Product $product, int $days, int $startQty = 10): void
    {
        $end = $this->now->subDay();

        for ($i = 0; $i < $days; $i++) {
            DailyOutboundAggregation::factory()->create([
                'product_id' => $product->id,
                'date' => $end->subDays($days - 1 - $i)->toDateString(),
                'total_qty_out' => $startQty + $i,
            ]);
        }
    }

    private function job(?string $targetMonth = null, ?int $lookbackDays = null): CalculateMonthlyPredictionJob
    {
        return new CalculateMonthlyPredictionJob(
            targetMonth: $targetMonth,
            lookbackDays: $lookbackDays,
        );
    }

    // -----------------------------------------------------------------
    // Penyimpanan prediksi
    // -----------------------------------------------------------------

    public function test_it_stores_predictions_for_products_with_history(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 60);

        $count = $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        $this->assertSame(1, $count);

        $prediction = MonthlyDemandPrediction::first();
        $this->assertSame($product->id, $prediction->product_id);
        $this->assertSame('2026-11-01', $prediction->target_month->toDateString());
        $this->assertSame(2535, $prediction->predicted_qty);
        $this->assertSame('100.00', $prediction->confidence_score);
        $this->assertNotNull($prediction->calculated_at);
    }

    public function test_it_defaults_to_next_month(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 30);

        // "now" = 2026-11-01, jadi bulan target default = 2026-12-01.
        $this->job()->handle(app(PredictionService::class));

        $this->assertSame('2026-12-01', MonthlyDemandPrediction::first()->target_month->toDateString());
    }

    public function test_it_skips_products_without_any_history(): void
    {
        Product::factory()->count(2)->create();
        $withHistory = Product::factory()->create();
        $this->seedHistory($withHistory, 30);

        $count = $this->job('2026-11-01', 30)->handle(app(PredictionService::class));

        $this->assertSame(1, $count);
        $this->assertSame(1, MonthlyDemandPrediction::count());
        $this->assertSame($withHistory->id, MonthlyDemandPrediction::first()->product_id);
    }

    public function test_it_ignores_soft_deleted_products(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 30);
        $product->delete();

        $this->assertSame(0, $this->job('2026-11-01', 30)->handle(app(PredictionService::class)));
        $this->assertSame(0, MonthlyDemandPrediction::count());
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 60);

        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        // Perbarui histori, lalu jalankan lagi untuk bulan yang sama.
        DailyOutboundAggregation::where('product_id', $product->id)->update(['total_qty_out' => 1]);
        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        $this->assertSame(1, MonthlyDemandPrediction::count());
        $this->assertSame(30, MonthlyDemandPrediction::first()->predicted_qty, '30 hari x 1 pcs');
    }

    public function test_it_can_write_several_target_months(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 60);

        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));
        $this->job('2026-12-01', 60)->handle(app(PredictionService::class));
        $this->job('2027-01-01', 60)->handle(app(PredictionService::class));

        $this->assertSame(3, MonthlyDemandPrediction::count());
    }

    // -----------------------------------------------------------------
    // DI via container
    // -----------------------------------------------------------------

    public function test_it_resolves_prediction_service_through_the_container(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product, 60);

        // dispatchSync() melewati handle() sehingga dependensi method di-inject.
        $this->job('2026-11-01', 60)->dispatchSync();

        $this->assertSame(1, MonthlyDemandPrediction::count());
    }

    // -----------------------------------------------------------------
    // Peringatan dini stok menipis
    // -----------------------------------------------------------------

    public function test_it_warns_about_products_projected_to_run_out(): void
    {
        Log::spy();

        // Rata-rata 39,5 pcs/hari dengan stok 40 -> days of cover 1 hari.
        $product = Product::factory()->create(['current_stock' => 40, 'reorder_point' => 50]);
        $product->suppliers()->attach(Supplier::factory()->create()->id, ['lead_time_days' => 7]);
        $this->seedHistory($product, 60);

        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (...$args) use ($product): bool {
                $context = $args[1] ?? [];

                return ($context['sku'] ?? null) === $product->sku
                    && ($context['days_of_cover'] ?? null) === 1
                    && ($context['lead_time_days'] ?? null) === 7
                    && ($context['projected_stockout_at'] ?? null) !== null;
            });
    }

    public function test_it_does_not_warn_when_stock_covers_the_lead_time(): void
    {
        Log::spy();

        $product = Product::factory()->create(['current_stock' => 5000, 'reorder_point' => 100]);
        $this->seedHistory($product, 60);

        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_stockout_threshold_is_configurable(): void
    {
        Log::spy();

        // Days of cover 1 hari: DI BAWAH default 14 (peringatan muncul) tapi
        // DIATAS threshold 0 (tidak ada peringatan).
        $product = Product::factory()->create(['current_stock' => 40]);
        $this->seedHistory($product, 60);

        (new CalculateMonthlyPredictionJob(targetMonth: '2026-11-01', lookbackDays: 60, stockoutThresholdDays: 0))
            ->handle(app(PredictionService::class));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_no_warning_when_there_is_no_demand_history(): void
    {
        Log::spy();

        Product::factory()->create(['current_stock' => 5]);

        $this->assertSame(0, $this->job('2026-11-01', 30)->handle(app(PredictionService::class)));

        Log::shouldNotHaveReceived('warning');
    }

    // -----------------------------------------------------------------

    public function test_it_logs_a_summary_of_its_run(): void
    {
        Log::spy();

        $product = Product::factory()->create(['current_stock' => 9999]);
        $this->seedHistory($product, 60);

        $this->job('2026-11-01', 60)->handle(app(PredictionService::class));

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (...$args): bool {
                $context = $args[1] ?? [];

                return str_contains($args[0], 'prediksi bulanan selesai')
                    && ($context['predictions'] ?? null) === 1
                    && ($context['target_month'] ?? null) === '2026-11-01'
                    && ($context['stockout_alerts'] ?? null) === 0;
            });
    }
}
