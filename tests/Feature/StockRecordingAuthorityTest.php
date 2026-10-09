<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manager boleh MENULIS master data, tapi tidak boleh MENCATAT stok.
 *
 * Dua hal itu berbeda, dan sebelumnya tidak dipisahkan. `POST /products`
 * terbuka untuk Manager (master data boleh ditulis keduanya), sementara
 * `ProductController::store()` memanggil `InventoryMovementService::recordInbound()`
 * kalau `current_stock` di isi. Akibatnya Manager bisa membuat produk yang
 * langsung menghasilkan dokumen Inbound atas namanya sendiri -- dan jejaknya
 * berbunyi "Inbound +500 ... oleh Dewi Lestari", persis operasi yang matriks
 * RBAC nyatakan hanya boleh dilakukan Admin Gudang.
 *
 * Aturan RBAC tegak per-route, sedangkan yang menentukan di sini adalah APA
 * yang dilakukan. Makanya penjaga placed di validasi, bukan di middleware.
 *
 * Invarian `SUM(quantity) == current_stock` sendiri tidak rusak di kasus itu --
 * jalur lewat service, jadi angkanya tetap cocok. Yang rusak adalah wewenang.
 */
class StockRecordingAuthorityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // (1) Manager tidak boleh membuat stok
    // -----------------------------------------------------------------

    public function test_manager_tidak_bisa_membuat_produk_dengan_stok_awal(): void
    {
        $manager = User::factory()->managerBisnis()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($manager)
            ->from('/products/create')
            ->post('/products', [
                'sku' => 'LUB-001',
                'name' => 'Produk Uji',
                'current_stock' => 500,
                'reorder_point' => 10,
                'supplier_id' => $supplier->id,
            ])
            ->assertSessionHasErrors(['current_stock', 'supplier_id']);

        // Bukan hanya ditolak di validasi: TIDAK ADA produk, tidak ada stok,
        // tidak ada dokumen, tidak ada jejak audit. Kalau salah satu dari
        // empat ini muncul, lubang masih terbuka walau errornya terlihat.
        $this->assertDatabaseMissing('products', ['sku' => 'LUB-001']);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_manager_bisa_membuat_produk_dengan_stok_nol(): void
    {
        $manager = User::factory()->managerBisnis()->create();

        $this->actingAs($manager)
            ->post('/products', [
                'sku' => 'MGR-001',
                'name' => 'Produk Milik Manager',
                'current_stock' => 0,
                'reorder_point' => 10,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'MGR-001', 'current_stock' => 0]);

        // Nol berarti tidak ada pergerakan sama sekali -- tidak perlu dokumen.
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryTransaction::count());
    }

    /**
     * Mengirim `current_stock: 0` dan sama sekali tidak mengirimnya harus
     * dianggap sama oleh server.
     *
     * Kalau hanya yang kedua yang diterima, form wajib tahu hal yang tidak
     * seharusnya ia punya, dan cepat atau lambat seseorang menambahkan default
     * `0` di form -- yang justru membuka jalan untuk sends `500` lolos.
     */
    public function test_manager_boleh_membuat_produk_tanpa_mengirim_stok(): void
    {
        $manager = User::factory()->managerBisnis()->create();

        $this->actingAs($manager)
            ->post('/products', [
                'sku' => 'MGR-002',
                'name' => 'Tanpa Kolom Stok',
                'reorder_point' => 10,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'MGR-002', 'current_stock' => 0]);
    }

    // -----------------------------------------------------------------
    // (2) Admin Gudang tetap bisa -- jangan sampai perbaikannya berlebihan
    // -----------------------------------------------------------------

    public function test_admin_gudang_tetap_bisa_membuat_produk_dengan_stok_awal(): void
    {
        $admin = User::factory()->adminGudang()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($admin)
            ->post('/products', [
                'sku' => 'ADM-001',
                'name' => 'Produk Admin',
                'current_stock' => 250,
                'reorder_point' => 10,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'ADM-001')->firstOrFail();

        $this->assertSame(250, $product->current_stock);

        // Stok TIDAK ditulis langsung: harus ada dokumen Inbound pembuka.
        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(250, $movement->quantity);
        $this->assertSame('inbound', $movement->transaction->movement_type->value);
        $this->assertSame($supplier->id, $movement->transaction->supplier_id);
        $this->assertSame('OPENING', $movement->transaction->physical_reference);
        $this->assertSame($admin->id, $movement->transaction->user_id);

        // Invarian proyek tetap berlaku.
        $this->assertSame(
            $product->current_stock,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );
    }

    // -----------------------------------------------------------------
    // (3) Pesan error harus mengarah ke tempat yang benar
    // -----------------------------------------------------------------

    public function test_pesan_error_mengarah_ke_halaman_entri(): void
    {
        $manager = User::factory()->managerBisnis()->create();

        $respons = $this->actingAs($manager)
            ->from('/products/create')
            ->post('/products', [
                'sku' => 'LUB-002',
                'name' => 'Produk Uji',
                'current_stock' => 500,
                'reorder_point' => 10,
            ]);

        $respons->assertSessionHasErrors('current_stock');

        $pesan = session('errors')->first('current_stock');

        $this->assertStringContainsString('Admin Gudang', $pesan);
        $this->assertStringContainsString('Inbound', $pesan);

        // Admin Gudang boleh mengisinya, jadi pesan yang sama TIDAK boleh
        // muncul padanya. Satu pesan untuk dua aturan berbeda akan menyesatkan
        // orang yang memang berhak.
        $admin = User::factory()->adminGudang()->create();

        $this->actingAs($admin)
            ->from('/products/create')
            ->post('/products', [
                'sku' => 'ADM-002',
                'name' => 'Produk Admin',
                'current_stock' => 10,
                'reorder_point' => 10,
            ])
            ->assertSessionHasNoErrors();
    }

    // -----------------------------------------------------------------
    // (4) Halaman create tidak boleh menawarkan kemampuan yang ditolak
    // -----------------------------------------------------------------

    /**
     * Kolom harus DISEMBUNYIKAN untuk Manager, bukan ditampilkan lalu
     * ditolak. Kolom yang tampil tapi selalu ditolak adalah jebakan:Manager
     * mengisinya dengan benar lalu mendapat penolakan tanpa penjelasan --
     * persis kelas bug yang sudah dibongkar di halaman pergerakan stok.
     */
    public function test_halaman_create_tidak_menawarkan_stok_awal_ke_manager(): void
    {
        $manager = User::factory()->managerBisnis()->create();
        $admin = User::factory()->adminGudang()->create();

        // Manager: `bolehCatatStok` false, dan daftar pemasok kosong supaya
        // dropdown-nya tidak menawarkan pilihan yang tidak bisa dipakai.
        $this->actingAs($manager)
            ->get('/products/create')
            ->assertInertia(fn ($page) => $page
                ->component('Products/Create')
                ->where('bolehCatatStok', false)
                ->where('suppliers', [])
            );

        // Admin: semuanya tersedia.
        $this->actingAs($admin)
            ->get('/products/create')
            ->assertInertia(fn ($page) => $page
                ->component('Products/Create')
                ->where('bolehCatatStok', true)
                ->has('suppliers')
            );
    }

    /**
     * Kolom harus hilang dari PAYLOAD juga, bukan cuma dari template.
     *
     * `useForm().data()` mengambil seluruh key objek form. Kalau
     * `current_stock` masih ada di sana, field itu ikut terkirim walaupun
     * kolomnya disembunyikan -- jadi penyembunyian di template tidak
     * encapsulates apa pun. Kalau halaman edit (yang memang tidak punya
     * kolom stok) dibuka dengan prop yang salah, angka yang diketik di
     * tempat lain akan ikut terkirim.
     */
    public function test_form_tidak_mengirim_field_stok_kala_tidak_boleh(): void
    {
        $form = (string) file_get_contents(resource_path('js/Pages/Products/Form.vue'));

        $this->assertStringContainsString(
            'props.product || !props.bolehCatatStok',
            $form,
            'Field stok harus hilang dari objek form, bukan cuma disembunyikan di template',
        );
    }
}
