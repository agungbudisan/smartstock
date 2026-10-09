<?php

namespace Tests\Feature;

use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dropdown "Bulan target" di Dasbor Manager.
 *
 * Bug aslinya: `availableMonths()` memakai `->distinct()` tanpa `->select()`,
 * yaitu `SELECT DISTINCT *`. Karena (product_id, target_month) unik, tidak ada
 * baris yang pernah sama -- jadi tidak ada yang ter-dedup dan hasilnya satu
 * baris per PRODUK. `LIMIT 12` mengambil 12 baris terbaru, semuanya dari bulan
 * yang sama: dropdown berisi satu bulan yang tertulis dua belas kali, dan tiga
 * bulan lain yang punya prediksi ikut hilang tanpa jejak.
 *
 * Test ini sengaja memakai LEBIH DARI 12 produk, karena justru pada 12 produk
 * per bulan hasilnya masih "terlihat benar": 12 opsi, semuanya satu bulan.
 * Yang membuatnya menyesatkan adalah jumlah opsi sudah sampai batas, jadi tidak
 * ada yangalate satu baris sebagai "kurang".
 */
class DashboardMonthFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 08:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dropdown_bulan_tidak_mengulang_bulan_yang_sama(): void
    {
        $this->seedPredictions();

        $manager = User::factory()->managerBisnis()->create();

        $bulan = $this->availableMonths($manager);

        $this->assertNotEmpty($bulan, 'Dropdown harus berisi bulan yang punya prediksi.');

        $unik = array_values(array_unique($bulan));

        $this->assertSame(
            $unik,
            $bulan,
            'Dropdown bulan target mengirim bulan yang sama lebih dari sekali. '
            .'Penyebabnya `distinct()` tanpa `select()` = `SELECT DISTINCT *`, '
            .'yang tidak pernah menduplikasi apa pun di sini.',
        );
    }

    public function test_dropdown_memuat_semua_bulan_yang_punya_prediksi(): void
    {
        $this->seedPredictions();

        $manager = User::factory()->managerBisnis()->create();
        $bulan = $this->availableMonths($manager);

        // Lima bulan target: tiga lampau, bulan berjalan, bulan depan.
        $this->assertCount(5, $bulan, 'Semua bulan yang punya prediksi harus bisa dipilih.');
    }

    public function test_dropdown_batasnya_bukan_bulan_tapi_baris(): void
    {
        // 20 produk per bulan. Kalau `LIMIT` diterapkan ke BARIS, hanya 12 baris
        // pertama yang terbaca -- dan dengan urutan terbaru itu satu bulan saja.
        $this->seedPredictions(produkPerBulan: 20);

        $manager = User::factory()->managerBisnis()->create();
        $bulan = $this->availableMonths($manager);

        $this->assertCount(5, $bulan);
        $this->assertLessThanOrEqual(12, count($bulan));
    }

    /**
     * Grafik tren harus menandai dari mana tiap angka berasal.
     *
     * Tanpa itu, Juli (backtest) dan November (proyeksi) tampil sebagai
     * "Total kuantitas prediksi" yang sama artinya, dan deret yang lompat
     * bulan terlihat seperti data rusak.
     */
    public function test_grafik_membedakan_riwayat_berjalan_dan_proyeksi(): void
    {
        $this->seedPredictions();

        $manager = User::factory()->managerBisnis()->create();

        $this->actingAs($manager)
            ->get('/analytics')
            ->assertInertia(function ($page): void {
                $status = [];

                foreach ($page->toArray()['props']['trend'] as $titik) {
                    $status[$titik['month']] = $titik['status'];
                }

                $this->assertSame('2026-07', array_key_first($status), 'Deret harus terurut dari bulan tertua.');

                $this->assertSame('riwayat', $status['2026-07']);
                $this->assertSame('riwayat', $status['2026-08']);
                $this->assertSame('riwayat', $status['2026-09']);
                $this->assertSame('berjalan', $status['2026-10'], 'Bulan berjalan harus ditandai berjalan.');
                $this->assertSame('proyeksi', $status['2026-11']);
            });
    }

    // -----------------------------------------------------------------
    // Pembantu
    // -----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function availableMonths(User $user): array
    {
        return $this->actingAs($user)
            ->get('/analytics')
            ->assertInertia(fn ($page) => $page->component('Analytics/Dashboard'))
            ->viewData('page')['props']['availableMonths'];
    }

    private function seedPredictions(int $produkPerBulan = 14): void
    {
        $bulan = ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'];

        foreach ($bulan as $target) {
            $products = Product::factory()->count($produkPerBulan)->create();

            foreach ($products as $product) {
                MonthlyDemandPrediction::factory()->create([
                    'product_id' => $product->id,
                    // Objek Carbon, bukan string 'Y-m-d': kolom DATE di SQLite
                    // menyimpan teks `Y-m-d H:i:s`, jadi `updateOrCreate` dengan
                    // string akan INSERT terus sampai menabrak UNIQUE.
                    'target_month' => CarbonImmutable::parse($target),
                    'predicted_qty' => 100,
                    'confidence_score' => 80,
                ]);
            }
        }
    }
}
