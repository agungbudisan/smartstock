<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReorderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Dasbor Manager Bisnis (Fase 6.2) + Pemantauan Reorder Point.
 */
class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private string $month = '2026-11-01';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 07:00:00'));
        $this->manager = User::factory()->managerBisnis()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function predict(Product $product, int $qty = 500, float $confidence = 87.5): void
    {
        MonthlyDemandPrediction::factory()->create([
            'product_id' => $product->id,
            'target_month' => $this->month,
            'predicted_qty' => $qty,
            'confidence_score' => $confidence,
        ]);
    }

    // -----------------------------------------------------------------
    // Dasbor
    // -----------------------------------------------------------------

    public function test_dasbor_dirender_dengan_kpi_dan_tren(): void
    {
        Product::factory()->count(2)->create();
        $product = Product::factory()->create();

        $this->predict($product, 300, 87.5);
        $this->predict(Product::factory()->create(), 200, 60);

        $this->actingAs($this->manager)
            ->get("/analytics?month={$this->month}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Analytics/Dashboard')
                ->where('kpi.total_products', 4)
                ->where('kpi.predicted_total', 500)
                ->where('kpi.predicted_products', 2)
                ->where('kpi.average_confidence', 73.75)
                ->has('trend', 1)
                ->has('predictions', 2)
            );
    }

    public function test_kpi_menghitung_produk_yang_perlu_reorder_dan_habis(): void
    {
        Product::factory()->create(['current_stock' => 100, 'reorder_point' => 50]); // aman
        Product::factory()->create(['current_stock' => 10, 'reorder_point' => 50]); // perlu reorder
        Product::factory()->create(['current_stock' => 0, 'reorder_point' => 50]);  // habis + perlu reorder

        $this->actingAs($this->manager)
            ->get('/analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpi.needs_reorder', 2)
                ->where('kpi.out_of_stock', 1)
            );
    }

    public function test_dasbor_bisa_difilter_bulan_dan_kategori(): void
    {
        $electronics = Category::factory()->create();
        $food = Category::factory()->create();

        $this->predict(Product::factory()->kategori($electronics)->create());
        $this->predict(Product::factory()->kategori($food)->create());

        $this->actingAs($this->manager)
            ->get("/analytics?month={$this->month}&category_id={$electronics->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('predictions', 1)
                ->where('filters.category_id', (string) $electronics->id)
            );
    }

    public function test_dasbor_menampilkan_bulan_yang_tersedia(): void
    {
        MonthlyDemandPrediction::factory()->create(['target_month' => '2026-12-01']);
        MonthlyDemandPrediction::factory()->create(['target_month' => '2026-11-01']);

        $this->actingAs($this->manager)
            ->get('/analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('availableMonths', 2)
                ->where('availableMonths.0', '2026-12-01', 'Urut terbaru dulu')
            );
    }

    public function test_dasbor_menampilkan_tren_bulanan(): void
    {
        MonthlyDemandPrediction::factory()->create(['target_month' => '2026-10-01', 'predicted_qty' => 100]);
        MonthlyDemandPrediction::factory()->create(['target_month' => '2026-11-01', 'predicted_qty' => 200]);

        $this->actingAs($this->manager)
            ->get('/analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trend', 2)
                ->where('trend.0.total_predicted', 100)
                ->where('trend.1.total_predicted', 200)
            );
    }

    public function test_dasbor_menampilkan_prioritas_reorder_singkat(): void
    {
        $product = Product::factory()->create(['current_stock' => 2, 'reorder_point' => 10]);
        $supplier = Supplier::factory()->create();
        $product->suppliers()->attach($supplier->id, ['lead_time_days' => 3]);

        // Demand 5/hari: stok 2 habis sebelum lead time 3 hari terpenuhi -> mendesak.
        $this->seedOutbound($product, days: 30, dailyQty: 5);

        $this->actingAs($this->manager)
            ->get('/analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reorderTop', 1)
                ->where('reorderTop.0.sku', $product->sku)
                ->where('reorderTop.0.leadTimeDays', 3)
                ->where('reorderTop.0.isUrgent', true)
            );
    }

    // -----------------------------------------------------------------
    // Reorder point
    // -----------------------------------------------------------------

    public function test_halaman_reorder_menampilkan_daftar_prioritas(): void
    {
        Product::factory()->create(['current_stock' => 5, 'reorder_point' => 10]);
        Product::factory()->create(['current_stock' => 200, 'reorder_point' => 10]); // aman, tidak tampil

        $this->actingAs($this->manager)
            ->get('/analytics/reorder')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Analytics/Reorder')
                ->has('recommendations', 1)
                ->where('recommendations.0.currentStock', 5)
            );
    }

    public function test_reorder_bisa_menampilkan_semua_produk(): void
    {
        Product::factory()->create(['current_stock' => 5, 'reorder_point' => 10]);
        Product::factory()->create(['current_stock' => 200, 'reorder_point' => 10]);

        $this->actingAs($this->manager)
            ->get('/analytics/reorder?only_needing_reorder=0')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recommendations', 2));
    }

    public function test_urutan_reorder_menempatkan_yang_paling_mendesak_di_atas(): void
    {
        // Habis sama sekali -> paling mendesak.
        $habis = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 50]);

        // Stok menipis dengan demand tinggi -> habis sebelum barang tiba.
        $menipis = Product::factory()->create(['current_stock' => 5, 'reorder_point' => 50]);

        // Masih di atas batas aman -> tidak masuk daftar.
        Product::factory()->create(['current_stock' => 300, 'reorder_point' => 50]);

        // Demand 20/hari -> 5 unit habis dalam <1 hari.
        $this->seedOutbound($habis, 30, 20);
        $this->seedOutbound($menipis, 30, 20);

        $rows = app(ReorderService::class)->recommendations()->values();

        $this->assertSame(2, $rows->count());
        $this->assertSame($habis->id, $rows->first()->productId, 'Stok habis harus di urutan pertama');
    }

    public function test_saran_jumlah_pesan_memakai_demand_dan_lead_time(): void
    {
        // Demand 10/hari, lead time 7 hari + safety 7 hari = 140 unit.
        // Stok sekarang 20 -> saran 120.
        $product = Product::factory()->create(['current_stock' => 20, 'reorder_point' => 10]);
        $product->suppliers()->attach(Supplier::factory()->create()->id, ['lead_time_days' => 7]);

        $this->seedOutbound($product, days: 30, dailyQty: 10);

        $row = app(ReorderService::class)->forProduct($product);

        $this->assertSame(10.0, $row->averageDailyDemand);
        $this->assertSame(2, $row->daysOfCover);
        $this->assertSame(120, $row->suggestedOrderQty);
        $this->assertTrue($row->isUrgent);
    }

    public function test_demand_harian_membagi_dengan_jumlah_hari_bukan_jumlah_baris(): void
    {
        // Hanya 3 hari ada outbound dalam 30 hari: 60 pcs total.
        // Rata-rata harus 60/30 = 2 per hari, bukan 60/3 = 20.
        $product = Product::factory()->create(['current_stock' => 40, 'reorder_point' => 5]);

        foreach ([1, 2, 3] as $daysAgo) {
            DailyOutboundAggregation::factory()->create([
                'product_id' => $product->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'total_qty_out' => 20,
            ]);
        }

        $row = app(ReorderService::class)->forProduct($product);

        $this->assertSame(2.0, $row->averageDailyDemand);
        $this->assertSame(20, $row->daysOfCover);
    }

    public function test_produk_tanpa_pemasok_memakai_lead_time_asumsi(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 5]);

        $row = app(ReorderService::class)->forProduct($product);

        $this->assertSame(ReorderService::DEFAULT_LEAD_TIME_DAYS, $row->leadTimeDays);
        $this->assertSame(0, $row->supplierCount);
    }

    public function test_produk_tanpa_demand_menghasilkan_days_of_cover_kosong(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 50]);

        $row = app(ReorderService::class)->forProduct($product);

        $this->assertNull($row->daysOfCover);
        $this->assertSame(0.0, $row->averageDailyDemand);
        // Demand 0 tapi di bawah batas aman -> tetap saran sebesar stok safety.
        $this->assertSame(50, $row->suggestedOrderQty);
    }

    // -----------------------------------------------------------------
    // Ekspor CSV
    // -----------------------------------------------------------------

    public function test_reorder_bisa_dit_export_sebagai_csv(): void
    {
        $product = Product::factory()->create([
            'sku' => 'CSV-001',
            'name' => 'Produk Ekspor',
            'current_stock' => 3,
            'reorder_point' => 20,
        ]);

        $response = $this->actingAs($this->manager)->get('/analytics/reorder.csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertDownload('reorder-point-'.now()->format('Y-m-d').'.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('sku', $csv);
        $this->assertStringContainsString('CSV-001', $csv);
        $this->assertStringContainsString('saran_pesan', $csv);
        // BOM UTF-8 agar Excel membaca karakter non-ASCII dengan benar.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    /**
     * Seed outbound harian selama beberapa hari berturut-turut.
     */
    private function seedOutbound(Product $product, int $days, int $dailyQty): void
    {
        for ($i = 0; $i < $days; $i++) {
            DailyOutboundAggregation::factory()->create([
                'product_id' => $product->id,
                'date' => now()->subDays($i)->toDateString(),
                'total_qty_out' => $dailyQty,
            ]);
        }
    }
}
