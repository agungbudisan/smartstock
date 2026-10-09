<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RBAC: tiap peran hanya boleh mengakses modulnya (PRD bagian 2).
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gudang_tidak_bisa_membuka_dasbor_analitik(): void
    {
        $this->actingAs(User::factory()->adminGudang()->create())
            ->get('/analytics')
            ->assertForbidden();
    }

    public function test_manager_bisnis_tidak_bisa_mencatat_pergerakan_stok(): void
    {
        $this->actingAs(User::factory()->managerBisnis()->create())
            ->post('/inventory/movements', [])
            ->assertForbidden();
    }

    public function test_admin_gudang_boleh_mencatat_pergerakan(): void
    {
        $this->actingAs(User::factory()->adminGudang()->create())
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 10,
                ]],
                'movement_type' => 'inbound',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_manager_bisnis_boleh_membuka_dasbor(): void
    {
        $this->actingAs(User::factory()->managerBisnis()->create())
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Analytics/Dashboard'));
    }

    public function test_kedua_peran_boleh_membuka_master_data(): void
    {
        Product::factory()->create();

        foreach (['adminGudang', 'managerBisnis'] as $state) {
            $this->actingAs(User::factory()->{$state}()->create())
                ->get('/products')
                ->assertOk();
        }
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/analytics')->assertRedirect('/login');
        $this->get('/products')->assertRedirect('/login');
    }

    public function test_peran_tidak_dikenal_ditolak_dengan_pesan_yang_jelas(): void
    {
        $this->app['router']->get('/_test-unknown-role', fn () => 'ok')
            ->middleware('role:superuser');

        $this->actingAs(User::factory()->adminGudang()->create())
            ->get('/_test-unknown-role')
            ->assertStatus(500)
            ->assertSee('Peren');
    }

    public function test_endpoint_tanpa_daftar_peran_membolehkan_semua_peran(): void
    {
        // Tanpa daftar peran, middleware tidak memfilter apa pun.
        $this->app['router']->get('/_test-no-role', fn () => 'ok')->middleware(['role:']);

        foreach (['adminGudang', 'managerBisnis'] as $state) {
            $this->actingAs(User::factory()->{$state}()->create())
                ->get('/_test-no-role')
                ->assertOk()
                ->assertSee('ok');
        }
    }

    public function test_manager_tidak_bisa_mencatat_stok_tapi_bisa_membaca_buku_besar(): void
    {
        $manager = User::factory()->managerBisnis()->create();

        // Membaca ledger: BOLEH. Ledger adalah jejak audit -- tanpa itu
        // pertanyaan "stok ini naik tanpa ada PO, kenapa?" tidak punya jawaban.
        $this->actingAs($manager)
            ->get('/inventory/ledger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Ledger'));

        // Halaman ENTRI: DITOLAK. Halaman itu menampilkan form yang hanya bisa
        // disimpan Admin Gudang; membukanya untuk Manager berarti
        // mengirimnya ke form yang tidak bisa ia pakai -- mengisi dengan benar
        // lalu mendapat 403 saat menekan Simpan.
        $this->actingAs($manager)->get('/inventory/movements')->assertForbidden();

        // Mencatat: dilarang.
        $this->actingAs($manager)
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 10,
                ]],
                'movement_type' => 'inbound',
            ])
            ->assertForbidden();
    }

    /**
     * Halaman ledger Manager tidak boleh menyertakan form entri dalam bentuk
     * apa pun -- termasuk yang disembunyikan dengan CSS.
     *
     * Ini yang membuat pemisahan halaman berarti. Kalau form-nya masih ada di
     * komponen yang sama dan hanya disembunyikan lewat `v-if`, satu perubahan
     * kecil di template cukup untuk memunculkannya lagi tanpa ada yang gagal.
     * Halaman yang terpisah tidak punya form sama sekali untuk dimunculkan.
     */
    public function test_halaman_ledger_tidak_berisi_form_entri(): void
    {
        $ledger = (string) file_get_contents(resource_path('js/Pages/Inventory/Ledger.vue'));
        $komponen = (string) file_get_contents(resource_path('js/Components/Inventory/LedgerTable.vue'));

        foreach (['<form', 'useForm', 'submit(', 'form.post'] as $jejak) {
            $this->assertStringNotContainsString(
                $jejak,
                $ledger,
                "Halaman ledger tidak boleh punya {$jejak}",
            );
        }

        // Komponen tabel boleh punya <form> untuk filter-nya, tapi tidak boleh
        // punya form entri yang mengirim ke /inventory/movements.
        $this->assertStringNotContainsString("'/inventory/movements'", $komponen);
        $this->assertStringNotContainsString('useForm', $komponen);
    }
}
