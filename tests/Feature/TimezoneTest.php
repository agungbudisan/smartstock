<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyOutboundJob;
use App\Models\AuditLog;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Zona waktu: aplikasi berjalan di WIB dan mengirim ke browser string yang
 * punya penanda zona waktu.
 *
 * Gejala yang dilaporkan user: entri baru selalu tampil sekitar pukul 05:00,
 * padahal sedang pukul 12:00. Penyebabnya dua hal yang menumpuk:
 *
 *  1. `config('app.php')` dikunci ke 'UTC', jadi Carbon menulis 05:xx ke
 *     database. Bagian ini sendiri tidak salah — menyimpan UTC itu practices
 *     benar, dan yang kedua adalah penyebab sebenarnya.
 *
 *  2. String yang dikirim ke Vue adalah `toDateTimeString()`, yaitu
 *     '2026-10-08 05:26:06' TANPA penanda zona waktu. ECMAScript selalu
 *     memperlakukan string seperti itu sebagai waktu LOKAL device,
 *     jadi tidak ada konversi sama sekali — 05:26 ditampilkan apa adanya.
 *
 * Bukti smoking gun-nya ada di dalam aplikasi ini sendiri: notifikasi
 * memakai `toIso8601String()` (HandleInertiaRequests) dan waktu relatifnya
 * akurat, sementara semua halaman lain tidak. Sekarang semuanya satu jalur.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->service = new InventoryMovementService;
    }

    // -----------------------------------------------------------------
    // Konfigurasi
    // -----------------------------------------------------------------

    public function test_apikasi_berjalan_di_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    /**
     *phpunit.xml harus sinkron dengan .env. Kalau tidak, test berjalan di
     * UTC sementara aplikasi berjalan di WIB, dan setiap assertion tentang
     * startOfDay() menguji kalender yang salah.
     */
    public function test_phpunit_xml_sejajar_dengan_aplikasi(): void
    {
        $nilaiEnv = $this->nilaiEnv((string) file_get_contents(base_path('phpunit.xml')));
        $nilaiDotEnv = $this->nilaiEnv((string) file_get_contents(base_path('.env')));

        $this->assertSame('Asia/Jakarta', $nilaiEnv, 'phpunit.xml tidak sinkron dengan config');
        $this->assertSame(config('app.timezone'), $nilaiEnv);
        $this->assertSame(config('app.timezone'), $nilaiDotEnv, '.env tidak sinkron dengan config');
    }

    /**
     * Ambil nilai dari potongan `APP_TIMEZONE=...` (format .env) maupun
     * `name="APP_TIMEZONE" value="..."` (format phpunit.xml).
     *
     * Parsing teks, bukan SimpleXML: phpunit.xml punya namespace xsi, dan
     * iterasi SimpleXML di sini platform-dependent. Assertion soal
     * konfigurasi tidak perlu-keberries luxury parser.
     */
    private function nilaiEnv(string $isi): ?string
    {
        if (preg_match('/name="APP_TIMEZONE"\s+value="([^"]*)"/', $isi, $cocok)) {
            return $cocok[1];
        }

        if (preg_match('/^\s*APP_TIMEZONE\s*=\s*(.+)$/m', $isi, $cocok)) {
            return trim(trim($cocok[1]), "\"'");
        }

        return null;
    }

    public function test_config_membaca_dari_env_bukan_hardcode(): void
    {
        $isi = (string) file_get_contents(base_path('config/app.php'));

        $this->assertStringContainsString(
            "env('APP_TIMEZONE', 'UTC')",
            $isi,
            'config/app.php harus membaca APP_TIMEZONE, kalau tidak nilai di .env jadi tidak berpengaruh',
        );
    }

    // -----------------------------------------------------------------
    // Penyimpanan
    // -----------------------------------------------------------------

    public function test_timestamp_tersimpan_dalam_wib(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Asia/Jakarta'));

        $product = Product::factory()->create(['current_stock' => 0]);
        $this->service->recordInbound($product, 10, $this->admin, referenceCode: 'WIB-1');

        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('12:00:00', $movement->movement_date->format('H:i:s'));
        $this->assertSame('2026-10-08 12:00:00', $movement->movement_date->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_batas_hari_etl_mengikuti_wib_bukan_utc(): void
    {
        // 03:00 WIB masih 20:00 UTC di hari sebelumnya.
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'Asia/Jakarta'));

        $awal = Carbon::now()->startOfDay();
        $akhir = Carbon::now()->endOfDay();

        $this->assertSame('2026-10-08 00:00:00', $awal->toDateTimeString());
        $this->assertSame('2026-10-08 23:59:59', $akhir->toDateTimeString());

        Carbon::setTestNow();
    }

    // -----------------------------------------------------------------
    // Yang dikirim ke browser
    // -----------------------------------------------------------------

    public function test_string_ke_browser_punya_penanda_zona_waktu(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $this->service->recordInbound($product, 10, $this->admin, referenceCode: 'WIB-2');

        $this->actingAs($this->admin)
            ->get('/inventory/ledger')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('movements.data.0.movement_date', function ($nilai): bool {
                    $this->assertIsString($nilai);

                    // Wajib ISO 8601: ada 'T' DAN ada offset /Z.
                    // Tanpa offset, ECMAScript membacanya sebagai waktu lokal
                    // dan tidak melakukan konversi sama sekali.
                    $this->assertMatchesRegularExpression(
                        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
                        $nilai,
                        'movement_date harus ISO 8601 dengan penanda zona waktu',
                    );

                    return true;
                })
            );
    }

    public function test_penanda_zona_waktu_adalah_wib(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $this->service->recordInbound($product, 10, $this->admin, referenceCode: 'WIB-3');

        $this->actingAs($this->admin)
            ->get('/inventory/ledger')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('movements.data.0.movement_date', fn ($nilai): bool => str_ends_with($nilai, '+07:00'))
            );
    }

    public function test_jejak_audit_juga_punya_penanda_zona_waktu(): void
    {
        AuditLog::create([
            'user_id' => $this->admin->id,
            'action' => 'inbound.create',
            'description' => 'Uji zona waktu',
        ]);

        $this->actingAs($this->admin)
            ->get('/inventory/audit-logs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.data.0.created_at', fn ($nilai): bool => str_ends_with((string) $nilai, '+07:00'))
            );
    }

    public function test_notifikasi_dan_buku_besar_konsisten_formatnya(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $this->service->recordInbound($product, 10, $this->admin, referenceCode: 'WIB-4');

        $halaman = $this->actingAs($this->admin)->get('/inventory/ledger');
        $pola = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

        foreach ($halaman->viewData('page')['props']['movements']['data'] as $baris) {
            $this->assertMatchesRegularExpression($pola, $baris['movement_date']);
        }

        // Halaman notifikasi juga memakai ISO 8601 -- inilah yang membuat
        // waktu relatif di lonceng akurat sementara halaman lain tidak.
        $notif = $this->actingAs($this->admin)->get('/inventory/ledger');
        $this->assertNotEmpty($notif->viewData('page')['props']['movements']['data']);
    }

    // -----------------------------------------------------------------
    // Yang ditegakkan di kode
    // -----------------------------------------------------------------

    public function test_tidak_ada_to_date_time_string_yang_dikirim_ke_props(): void
    {
        $berkas = [
            app_path('Http/Controllers/Inventory/MovementController.php'),
            app_path('Http/Controllers/Inventory/AuditLogController.php'),
            app_path('Http/Controllers/MasterData/ProductController.php'),
            app_path('Http/Controllers/Analytics/AnalyticsController.php'),
        ];

        foreach ($berkas as $file) {
            $isi = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                '->toDateTimeString()',
                $isi,
                basename($file).' masih mengirim naive datetime tanpa zona waktu',
            );
        }
    }

    public function test_frontend_selalu_memakai_new_date(): void
    {
        $vue = (string) file_get_contents(resource_path('js/Components/Inventory/LedgerTable.vue'));

        // new Date() hanya benar kalau inputnya berpenanda zona waktu.
        $this->assertStringContainsString('new Date(row.movement_date)', $vue);

        $audit = (string) file_get_contents(resource_path('js/Pages/Inventory/AuditLogs.vue'));
        $this->assertStringContainsString('new Date(log.created_at)', $audit);
    }

    // -----------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------

    public function test_nama_file_csv_mengikuti_wib(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 01:00:00', 'Asia/Jakarta'));

        $response = $this->actingAs(User::factory()->managerBisnis()->create())
            ->get('/analytics/reorder.csv');

        $response->assertOk();

        // 01:00 WIB = 18:00 UTC HARI SEBELUMNYA. Kalau nama file memakai UTC,
        // unduhan yang dilakukan jam 1 pagi akan diberi nama tanggal kemarin.
        $this->assertStringContainsString('reorder-point-2026-10-08.csv', $response->headers->get('content-disposition'));

        Carbon::setTestNow();
    }

    // -----------------------------------------------------------------
    // ETL ikut memakai kalender WIB
    // -----------------------------------------------------------------

    public function test_agregasi_harian_mengelompokkan_per_hari_wib(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 23:00:00', 'Asia/Jakarta'));

        $product = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 5]);

        // Stok awal dulu, kalau tidak outbound pertama ditolak.
        $this->service->recordInbound(
            $product, 20, $this->admin,
            movementDate: Carbon::parse('2026-10-07 08:00:00', 'Asia/Jakarta'),
        );

        // Tiga outbound pada tiga jam berbeda di hari WIB yang sama.
        foreach (['00:30', '08:00', '20:00'] as $jam) {
            $this->service->recordOutbound(
                $product, 2, $this->admin,
                movementDate: Carbon::parse("2026-10-08 {$jam}:00", 'Asia/Jakarta'),
            );
        }

        // forDate eksplisit karena default job adalah "kemarin".
        (new AggregateDailyOutboundJob(forDate: '2026-10-08'))->handle();

        $agregat = DailyOutboundAggregation::where('product_id', $product->id)->get();

        $this->assertCount(1, $agregat, 'Semua movement pada 08-10 WIB harus masuk satu hari');
        $this->assertSame(6, (int) $agregat->first()->total_qty_out);

        Carbon::setTestNow();
    }
}
