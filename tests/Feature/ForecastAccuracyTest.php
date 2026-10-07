<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\User;
use App\Services\ForecastAccuracyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Evaluasi akurasi algoritma prediksi (PRD Modul 3 — "Evaluasi Akurasi &
 * Ekspor Laporan").
 *
 * Fokus test: perhitungan metrik benar, bulan yang belum selesai TIDAK
 * dievaluasi, dan ekspor CSV berisi angka yang bisa dipercaya.
 */
class ForecastAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ForecastAccuracyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->managerBisnis()->create();
        $this->service = new ForecastAccuracyService;

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /**
     * Satu produk dengan prediksi bulan $month dan permintaan nyata per hari.
     *
     * @param  array<int, int>  $dailyOutbound  qty keluar per tanggal (Y-m-d)
     */
    private function produkDengan(
        string $sku,
        string $month,
        int $predictedQty,
        float $confidence = 80.0,
        array $dailyOutbound = [],
        ?float $currentStock = null,
    ): Product {
        $product = Product::factory()->create([
            'sku' => $sku,
            'current_stock' => $currentStock ?? 100,
        ]);

        MonthlyDemandPrediction::factory()->untukBulan($month)->create([
            'product_id' => $product->id,
            'predicted_qty' => $predictedQty,
            'confidence_score' => $confidence,
        ]);

        foreach ($dailyOutbound as $date => $qty) {
            DailyOutboundAggregation::factory()->create([
                'product_id' => $product->id,
                'date' => $date,
                'total_qty_out' => $qty,
            ]);
        }

        return $product;
    }

    // -----------------------------------------------------------------
    // Metrik dasar
    // -----------------------------------------------------------------

    public function test_mape_dan_mae_terhitung_benar(): void
    {
        // September 2026 sudah lewat penuh, jadi boleh dievaluasi.
        // Prediksi 100 vs aktual 90  -> error +10, 10%
        // Prediksi 200 vs aktual 240 -> error -40, 16.67%
        $this->produkDengan('AK-001', '2026-09-01', 100, 80.0, [
            '2026-09-10' => 90,
        ]);
        $this->produkDengan('AK-002', '2026-09-01', 200, 60.0, [
            '2026-09-10' => 120,
            '2026-09-20' => 120,
        ]);

        $report = $this->service->evaluate('2026-09-01');

        $this->assertTrue($report->isEvaluable);
        $this->assertSame(2, $report->predictionCount);
        $this->assertSame(2, $report->comparedCount);
        $this->assertSame(0, $report->zeroActualCount);

        // MAE = (10 + 40) / 2 = 25
        $this->assertSame(25.0, $report->mae);

        // MAPE = (|10|/90 + |40|/240) / 2 * 100 = (11.11 + 16.67) / 2 = 13.89
        $this->assertSame(13.89, $report->mape);

        // WAPE = 50 / 330 * 100 = 15.15
        $this->assertSame(15.15, $report->wape);

        // RMSE = sqrt((100 + 1600) / 2) = sqrt(850) = 29.15
        $this->assertSame(29.15, $report->rmse);

        // bias = (10 - 40) / 2 = -15 -> cenderung under-predict
        $this->assertSame(-15.0, $report->bias);
    }

    public function test_produk_tanpa_penjualan_tidak_memasuk_mappe_tapi_masih_masuk_mae(): void
    {
        // Aktual nol bikin MAPE tak terdefinisi (Infinity), jadi harus
        // dikeluarkan dari MAPE tapi tetap dihitung di MAE/WAPE.
        $this->produkDengan('AK-003', '2026-09-01', 100, 80.0, ['2026-09-10' => 50]);
        $this->produkDengan('AK-004', '2026-09-01', 30, 20.0, []); // tidak terjual

        $report = $this->service->evaluate('2026-09-01');

        $this->assertSame(2, $report->predictionCount);
        $this->assertSame(1, $report->comparedCount);
        $this->assertSame(1, $report->zeroActualCount);

        // MAPE hanya dari satu baris yang punya aktual: |100-50|/50 = 100%
        $this->assertSame(100.0, $report->mape);

        // MAE tetap dua baris: (50 + 30) / 2 = 40
        $this->assertSame(40.0, $report->mae);

        $this->assertSame(50.0, $report->coveragePercent());
    }

    public function test_bias_positif_artinya_over_predict(): void
    {
        $this->produkDengan('AK-005', '2026-09-01', 200, 90.0, ['2026-09-05' => 100]);

        $report = $this->service->evaluate('2026-09-01');

        $this->assertSame(100.0, $report->bias);
        $this->assertSame(100.0, $report->mae);
        $this->assertSame('over', $report->rows[0]['direction']);
        $this->assertSame(100.0, $report->rows[0]['signed_error_pct']);
    }

    public function test_error_persen_kosong_saat_aktual_nol(): void
    {
        $this->produkDengan('AK-006', '2026-09-01', 50, 55.0, []);

        $report = $this->service->evaluate('2026-09-01');
        $row = $report->rows[0];

        // Produk ini sama sekali tidak terjual di September 2026.
        $this->assertSame(0, $row['actual_qty']);
        $this->assertSame(50, $row['error']);
        $this->assertNull($row['error_pct'], 'Error persen tidak terdefinisi saat aktual nol');
        $this->assertNull($row['signed_error_pct']);
        $this->assertFalse($row['has_actual']);
        // Prediksi 50 tapi tidak terjual sama sekali = over-prediction.
        $this->assertSame('over', $row['direction']);
    }

    public function test_tanpa_data_aktual_semua_metrik_null_bukan_nol(): void
    {
        $this->produkDengan('AK-007', '2026-09-01', 100, 80.0, []);

        $report = $this->service->evaluate('2026-09-01');

        $this->assertSame(1, $report->predictionCount);
        $this->assertSame(0, $report->comparedCount);

        // MAE tetap bisa dihitung (predicted vs 0), tapi metrik berbasis
        // persentase aktual tidak boleh/report null.
        $this->assertNull($report->mape);
        $this->assertNull($report->wape, 'WAPE butuh total aktual > 0');
        $this->assertSame(100.0, $report->mae);
    }

    public function test_bulan_tanpa_prediksi_tidak_menghasilkan_angka(): void
    {
        $report = $this->service->evaluate('2026-09-01');

        $this->assertSame(0, $report->predictionCount);
        $this->assertNull($report->mae);
        $this->assertNull($report->mape);
        $this->assertNull($report->wape);
        $this->assertNull($report->rmse);
        $this->assertNull($report->bias);
        $this->assertNull($report->coveragePercent());
    }

    // -----------------------------------------------------------------
    // Aturan bulan yang belum selesai
    // -----------------------------------------------------------------

    public function test_bulan_sebelum_ini_belum_bisa_ditilai(): void
    {
        // -now() = 2026-10-08, jadi Oktober masih berjalan.
        $this->produkDengan('AK-008', '2026-10-01', 500, 80.0, ['2026-10-01' => 10]);

        $report = $this->service->evaluate('2026-10-01');

        $this->assertFalse($report->isEvaluable, 'Bulan berjalan tidak boleh dinilai');
    }

    public function test_bulan_depan_belum_bisa_ditilai(): void
    {
        $this->produkDengan('AK-009', '2026-11-01', 500, 80.0, []);

        $this->assertFalse($this->service->evaluate('2026-11-01')->isEvaluable);
    }

    public function test_bulan_lampau_lengkap_bisa_ditilai(): void
    {
        // September 2026 sudah lewat 8 hari setelah akhir bulan.
        $this->assertTrue($this->service->evaluate('2026-09-01')->isEvaluable);
        $this->assertTrue($this->service->evaluate('2026-08-01')->isEvaluable);
    }

    public function test_allow_incomplete_memaksa_penilaian_bulan_berjalan(): void
    {
        $this->produkDengan('AK-010', '2026-10-01', 100, 80.0, ['2026-10-01' => 40]);

        $report = $this->service->evaluate('2026-10-01', allowIncomplete: true);

        $this->assertTrue($report->isEvaluable);
        $this->assertSame(60.0, $report->bias);
    }

    // -----------------------------------------------------------------
    // Agregasi harian
    // -----------------------------------------------------------------

    public function test_permintaan_bulanan_menjumlahkan_seluruh_hari(): void
    {
        $this->produkDengan('AK-011', '2026-09-01', 100, 80.0, [
            '2026-09-30' => 30, // baris terakhir bulan
            '2026-09-01' => 40,
            '2026-09-15' => 30,
            '2026-08-31' => 999, // bulan sebelumnya, tidak boleh ikut
            '2026-10-01' => 999, // bulan berikutnya, tidak boleh ikut
        ]);

        $report = $this->service->evaluate('2026-09-01');

        $this->assertSame(100, $report->totalActual);
    }

    // -----------------------------------------------------------------
    // Tren & daftar bulan
    // -----------------------------------------------------------------

    public function test_tren_menampilkan_akurasi_bulan_bulan(): void
    {
        $this->produkDengan('AK-012', '2026-07-01', 100, 80.0, ['2026-07-10' => 100]);
        $this->produkDengan('AK-013', '2026-08-01', 100, 80.0, ['2026-08-10' => 50]);
        $this->produkDengan('AK-014', '2026-09-01', 100, 80.0, ['2026-09-10' => 25]);

        $trend = $this->service->trend();

        $this->assertCount(3, $trend);
        $this->assertSame('2026-07-01', $trend[0]['month']);
        $this->assertSame('2026-09-01', $trend[2]['month']);

        $this->assertSame(0.0, $trend[0]['wape'], 'Tepat sama, tanpa error');
        // Prediksi 100, aktual 25 -> |75| / 25 * 100 = 300%. WAPE boleh jauh
        // melewati 100% karena penyebutnya jauh lebih kecil dari pembilang.
        $this->assertSame(300.0, $trend[2]['wape']);
    }

    public function test_daftar_bulan_menandai_yang_belum_selesai(): void
    {
        MonthlyDemandPrediction::factory()->untukBulan('2026-09-01')->create([
            'product_id' => Product::factory()->create()->id,
        ]);
        MonthlyDemandPrediction::factory()->untukBulan('2026-11-01')->create([
            'product_id' => Product::factory()->create()->id,
        ]);

        $months = collect($this->service->selectableMonths())->keyBy('value');

        $this->assertTrue($months['2026-09-01']['is_evaluable']);
        $this->assertFalse($months['2026-11-01']['is_evaluable']);
    }

    // -----------------------------------------------------------------
    // Filter kategori
    // -----------------------------------------------------------------

    public function test_rincian_dapat_difilter_kategori(): void
    {
        $elektronik = Category::factory()->create(['name' => 'Elektronik']);
        $sembako = Category::factory()->create(['name' => 'Sembako']);

        $p1 = $this->produkDengan('AK-015', '2026-09-01', 100, 80.0, ['2026-09-10' => 100]);
        $p1->update(['category_id' => $elektronik->id]);

        $p2 = $this->produkDengan('AK-016', '2026-09-01', 100, 80.0, ['2026-09-10' => 100]);
        $p2->update(['category_id' => $sembako->id]);

        $report = $this->service->evaluate('2026-09-01', categoryId: $elektronik->id);

        $this->assertSame(1, $report->predictionCount);
        $this->assertSame('AK-015', $report->rows[0]['sku']);
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    public function test_halaman_akurasi_bisa_diakses_manager(): void
    {
        $this->produkDengan('AK-017', '2026-09-01', 100, 80.0, ['2026-09-10' => 90]);

        $this->actingAs($this->manager)
            ->get('/analytics/accuracy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Analytics/Accuracy')
                ->has('report')
                ->has('rows', 1)
                ->has('trend')
                ->where('report.is_evaluable', true)
                ->where('report.target_month', '2026-09-01')
            );
    }

    public function test_admin_gudang_tidak_bisa_membuka_akurasi(): void
    {
        $this->actingAs(User::factory()->adminGudang()->create())
            ->get('/analytics/accuracy')
            ->assertForbidden();
    }

    public function test_halaman_bulan_berjalan_menandai_bukan_evaluable(): void
    {
        $this->produkDengan('AK-018', '2026-10-01', 100, 80.0, ['2026-10-01' => 50]);

        $this->actingAs($this->manager)
            ->get('/analytics/accuracy?month=2026-10-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.is_evaluable', false)
            );
    }

    public function test_ekspor_csv_akurasi_mengandung_rincian_dan_ringkasan(): void
    {
        $this->produkDengan('AK-019', '2026-09-01', 100, 80.0, ['2026-09-10' => 90]);
        $this->produkDengan('AK-020', '2026-09-01', 200, 55.0, ['2026-09-10' => 240]);

        $response = $this->actingAs($this->manager)
            ->get('/analytics/accuracy.csv?month=2026-09-01');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('AK-019', $csv);
        $this->assertStringContainsString('AK-020', $csv);
        $this->assertStringContainsString('RINGKASAN', $csv);
        $this->assertStringContainsString('WAPE (%)', $csv);

        // BOM UTF-8 supaya Excel tidak mengacak karakter.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_ekspor_csv_menandai_arah_error(): void
    {
        $this->produkDengan('AK-021', '2026-09-01', 100, 80.0, ['2026-09-10' => 50]);
        $this->produkDengan('AK-022', '2026-09-01', 100, 80.0, ['2026-09-10' => 100]);

        $csv = $this->actingAs($this->manager)
            ->get('/analytics/accuracy.csv?month=2026-09-01')
            ->streamedContent();

        $this->assertStringContainsString('Over', $csv);
        $this->assertStringContainsString('Tepat', $csv);
    }
}
