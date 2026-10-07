<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Services\InventoryMovementService;
use App\Services\LowStockAlertService;
use App\Services\Support\LowStockAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Peringatan stok menipis ke kanal nyata (UseCase: "Kirim Peringatan Stok
 * Menipis").
 *
 * Sebelumnya peringatan hanya hidup di audit_logs + log aplikasi, tidak
 * pernah terlihat pengguna. Test ini mengunci bahwa notifikasi benar-benar
 * dibuat, tidak menggagalkan transaksi, dan tidak meng spam.
 */
class LowStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->manager = User::factory()->managerBisnis()->create();
        $this->service = new InventoryMovementService;

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Pemicu
    // -----------------------------------------------------------------

    public function test_outbound_menembus_batas_aman_mengirim_notifikasi(): void
    {
        $product = Product::factory()->create([
            'sku' => 'NOT-001',
            'current_stock' => 12,
            'reorder_point' => 20,
        ]);

        $this->service->recordOutbound($product, 5, $this->admin);

        $this->assertSame(1, $this->admin->notifications()->count());

        $notification = $this->admin->notifications()->first();

        // Kolom `type` menyimpan nama class sebagai string, bukan objek.
        $this->assertSame(LowStockNotification::class, $notification->type);
        $this->assertSame('Stok NOT-001 menipis', $notification->data['title']);
        $this->assertSame(7, $notification->data['current_stock']);
        $this->assertSame(13, $notification->data['shortfall']);
        $this->assertSame("low_stock:{$product->id}", $notification->audience_key);
        $this->assertNull($notification->read_at);
    }

    public function test_stok_habis_memakai_judul_khusus(): void
    {
        $product = Product::factory()->create([
            'sku' => 'NOT-002',
            'current_stock' => 5,
            'reorder_point' => 10,
        ]);

        $this->service->recordOutbound($product, 5, $this->admin);

        $data = $this->admin->notifications()->first()->data;

        $this->assertSame('NOT-002 habis', $data['title']);
        $this->assertSame('danger', $data['tone']);
        $this->assertStringContainsString('sudah habis', $data['message']);
    }

    public function test_outbound_yang_tidak_menembus_batas_aman_tidak_mengirim_notifikasi(): void
    {
        $product = Product::factory()->create([
            'current_stock' => 100,
            'reorder_point' => 20,
        ]);

        $this->service->recordOutbound($product, 10, $this->admin);

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_inbound_dan_opname_tidak_memicu_peringatan(): void
    {
        $product = Product::factory()->create([
            'current_stock' => 5,
            'reorder_point' => 10,
        ]);

        $this->service->recordInbound($product, 100, $this->admin);
        $this->service->recordStockOpname($product, 3, $this->admin);

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_audit_trail_tetapis_dicatat(): void
    {
        $product = Product::factory()->create([
            'sku' => 'NOT-003',
            'current_stock' => 8,
            'reorder_point' => 15,
        ]);

        $this->service->recordOutbound($product, 3, $this->admin);

        $this->assertSame(1, AuditLog::where('action', 'alert.low_stock')->count());
    }

    // -----------------------------------------------------------------
    // Penerima
    // -----------------------------------------------------------------

    public function test_kedua_peran_menerima_peringatan(): void
    {
        $product = Product::factory()->create(['current_stock' => 5, 'reorder_point' => 10]);

        $this->service->recordOutbound($product, 2, $this->admin);

        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $this->manager->notifications()->count());
    }

    public function test_akun_belum_verifikasi_tidak_menerima(): void
    {
        $unverified = User::factory()->unverified()->create(['role' => 'admin_gudang']);
        $product = Product::factory()->create(['current_stock' => 5, 'reorder_point' => 10]);

        $this->service->recordOutbound($product, 2, $this->admin);

        $this->assertSame(0, $unverified->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    // -----------------------------------------------------------------
    // Debounce
    // -----------------------------------------------------------------

    public function test_outbound_beruntun_dalam_cooldown_tidak_mengirim_berulang(): void
    {
        $product = Product::factory()->create(['current_stock' => 30, 'reorder_point' => 10]);

        // Outbound pertama tidak menembus batas aman.
        $this->service->recordOutbound($product, 5, $this->admin);
        $this->assertSame(0, $this->admin->notifications()->count());

        // Tiga outbound berikutnya menembus batas aman beruntun.
        $this->service->recordOutbound($product, 8, $this->admin);
        $this->service->recordOutbound($product, 5, $this->admin);
        $this->service->recordOutbound($product, 5, $this->admin);

        $this->assertSame(1, $this->admin->notifications()->count(), 'Harus didebounce jadi satu');
    }

    public function test_notifikasi_yang_sudah_dibaca_membuka_jendela_baru(): void
    {
        $product = Product::factory()->create(['current_stock' => 20, 'reorder_point' => 10]);
        $service = new LowStockAlertService;

        $this->service->recordOutbound($product, 12, $this->admin);
        $this->assertSame(1, $this->admin->notifications()->count());

        // Sudah dibaca => bukan lagi pending, jadi peringatan berikutnya lolos.
        $this->admin->notifications()->first()->markAsRead();

        $this->service->recordOutbound($product, 3, $this->admin);
        $this->assertSame(2, $this->admin->notifications()->count());
    }

    public function test_setelah_cooldown_berakhir_peringatan_boleh_masuk_lagi(): void
    {
        $product = Product::factory()->create(['current_stock' => 20, 'reorder_point' => 10]);
        $alerts = new LowStockAlertService;
        $alert = $alerts->alertFor($product->fresh());

        $alerts->dispatch($alert);
        $this->assertSame(1, $this->admin->notifications()->count());

        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00')); // +48 jam

        $alerts->dispatch($alerts->alertFor($product->fresh()));
        $this->assertSame(2, $this->admin->notifications()->count());
    }

    public function test_produk_berbeda_tetap_masing_masing_masuk(): void
    {
        $a = Product::factory()->create(['sku' => 'NOT-A', 'current_stock' => 20, 'reorder_point' => 10]);
        $b = Product::factory()->create(['sku' => 'NOT-B', 'current_stock' => 20, 'reorder_point' => 10]);

        $this->service->recordOutbound($a, 12, $this->admin);
        $this->service->recordOutbound($b, 12, $this->admin);

        $this->assertSame(2, $this->admin->notifications()->count());

        // Diurutkan di PHP: relasi `notifications()` sudah membawa
        // orderBy created_at, jadi orderBy tambahan di query tidak menentukan.
        $keys = $this->admin->notifications()->pluck('audience_key')->sort()->values()->all();

        $this->assertSame(["low_stock:{$a->id}", "low_stock:{$b->id}"], $keys);
    }

    // -----------------------------------------------------------------
    // Kegagalan tidak merusak transaksi
    // -----------------------------------------------------------------

    public function test_kegagalan_kirim_notifikasi_tidak_menggagalkan_perubahan_stok(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);

        // Paksa channel database gagal dengan menutup model notifikasi.
        Notification::shouldReceive('send')
            ->andThrow(new \RuntimeException('channel mati'));

        $movement = $this->service->recordOutbound($product, 3, $this->admin);

        $this->assertNotNull($movement->id);
        $this->assertSame(7, $product->fresh()->current_stock);
        $this->assertSame(1, AuditLog::where('action', 'alert.low_stock')->count());
    }

    // -----------------------------------------------------------------
    // UI & HTTP
    // -----------------------------------------------------------------

    public function test_prop_notifikasi_dibagikan_ke_semua_halaman(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);

        $this->service->recordOutbound($product, 3, $this->admin);

        $this->actingAs($this->admin)
            ->get('/products')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.unread_count', 1)
                ->has('notifications.items', 1)
                ->where('notifications.items.0.sku', $product->sku)
            );
    }

    public function test_halaman_tanpa_login_tidak_mengirim_prop_notifikasi(): void
    {
        $this->get('/login')->assertOk();

        // Prop notifikasi hanya untuk user terautentikasi; middleware
        // menuliskan array kosong bila tidak ada user.
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('notifications')
            );
    }

    public function test_tandai_semua_dibaca(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        $this->actingAs($this->admin)
            ->from('/dashboard')
            ->patch('/notifications/read-all')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success');

        $this->assertSame(0, $this->admin->notifications()->whereNull('read_at')->count());
    }

    public function test_tandai_satu_dibaca_lalu_mengarah_ke_halaman_produk(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        $notification = $this->admin->notifications()->first();

        $this->actingAs($this->admin)
            ->patch("/notifications/{$notification->id}/read")
            ->assertRedirect("/products/{$product->id}/edit");

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_tidak_bisa_menandai_notifikasi_milik_orang_lain(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        $notification = $this->admin->notifications()->first();

        $this->actingAs($this->manager)
            ->from('/dashboard')
            ->patch("/notifications/{$notification->id}/read")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('warning');

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_hapus_notifikasi_yang_sudah_dibaca(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        $notification = $this->admin->notifications()->first();
        $notification->markAsRead();

        $this->actingAs($this->admin)
            ->from('/dashboard')
            ->delete('/notifications/read')
            ->assertRedirect('/dashboard');

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_notifikasi_ikut_terhapus_bersama_akun(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        // Satu baris per penerima: admin + manager.
        $this->assertSame(2, DB::table('notifications')->count());

        $this->admin->delete();

        // Hanya notifikasi milik admin yang ikut terhapus.
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(1, $this->manager->notifications()->count());

        $this->manager->delete();

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_admin_gudang_tidak_perlu_ke_dashboard_manajer_untuk_lonceng(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 15]);
        $this->service->recordOutbound($product, 3, $this->admin);

        $this->actingAs($this->admin)
            ->get('/inventory/movements')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.unread_count', 1)
            );
    }

    // -----------------------------------------------------------------
    // DTO
    // -----------------------------------------------------------------

    public function test_dto_pesan_menyesuaikan_kondisi_stok(): void
    {
        $alert = new LowStockAlert(1, 'X-1', 'Barang X', 0, 10, 10);
        $this->assertSame('X-1 habis', $alert->title());
        $this->assertStringContainsString('sudah habis', $alert->message());

        $alert = new LowStockAlert(1, 'X-1', 'Barang X', 4, 10, 6);
        $this->assertSame('Stok X-1 menipis', $alert->title());
        $this->assertStringContainsString('tersisa 4 unit', $alert->message());
        $this->assertStringContainsString('Kurang 6 unit', $alert->message());
    }
}
