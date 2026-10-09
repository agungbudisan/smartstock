<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\ProductSupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pemetaan produk <-> pemasok harus terbentuk sendiri dari riwayat.
 *
 * Akses ditanyakan user: kenapa kolom "Pemasok & Harga Beli" di halaman edit
 * produk tidak terisi otomatis dari Inbound pembuka atau entri barang masuk?
 *
 * Jawaban yang ditemukan: `recordInbound()` hanya membuat baris pivot kalau
 * `$unitPrice` ikut diberikan. Akibatnya barang masuk dari CV Sodersiktual
 * tercatat lengkap di audit, tapi CV itu tidak muncul di halaman pemetaan
 * selama admin tidak mengisinya manual — dan untuk produk baru, halaman itu
 * memang tidak pernah diisi sama sekali karena tidak ada yang mengarahkan ke
 * sana. Pemetaan menyimpang dari kenyataan.
 *
 * Diperbaiki: setiap inbound yang menyebut pemasok otomatis menautkannya,
 * tanpa syarat harga. Harga dan lead time tetap opsional karena keduanya tidak
 * bisa disimpulkan dari satu transaksi.
 */
class SupplierAutoLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryMovementService $movements;

    private ProductSupplierService $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->movements = new InventoryMovementService;
        $this->mapping = new ProductSupplierService;
    }

    // -----------------------------------------------------------------
    // Auto-tautan
    // -----------------------------------------------------------------

    public function test_inbound_tanpa_harga_tetap_menautkan_pemasok(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'CV Sodersiktual Nusantara']);

        $this->movements->recordInbound($product, 40, $this->admin, supplierId: $supplier->id);

        $this->assertTrue(
            $product->suppliers()->whereKey($supplier->id)->exists(),
            'Inbound tanpa harga harus tetap menautkan pemasok ke produk',
        );
    }

    public function test_tautan_baru_tidak_mengisi_harga_dan_lead_time(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound($product, 10, $this->admin, supplierId: $supplier->id);

        $pivot = $product->suppliers()->whereKey($supplier->id)->first()->pivot;

        // Keduanya dibiarkan kosong, bukan dikira-kira. Kolom ini hanya diisi kalau memang diketahui.
        $this->assertNull($pivot->last_purchase_price);
        $this->assertNull($pivot->lead_time_days);
    }

    public function test_inbound_berharga_mengisi_harga_bukan_lead_time(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound(
            $product, 10, $this->admin,
            supplierId: $supplier->id,
            unitPrice: 25_000.0,
        );

        $pivot = $product->suppliers()->whereKey($supplier->id)->first()->pivot;

        $this->assertEquals(25_000.0, (float) $pivot->last_purchase_price);

        // Lead time adalah hasil kesepakatan, tidak bisa ditebak dari satu
        // transaksi. Kalau ikut terisi, angka reorder jadi tidak bisa dipercaya.
        $this->assertNull($pivot->lead_time_days);
    }

    public function test_menautkan_ulang_tidak_menghapus_harga_dan_lead_time_lama(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->mapping->attach($product, $supplier, lastPurchasePrice: 30_000.0, leadTimeDays: 7);

        // Inbound kedua dari pemasok yang sama, tanpa harga.
        $this->movements->recordInbound($product, 5, $this->admin, supplierId: $supplier->id);

        $pivot = $product->suppliers()->whereKey($supplier->id)->first()->pivot;

        $this->assertEquals(30_000.0, (float) $pivot->last_purchase_price, 'Harga lama tidak boleh terhapus');
        $this->assertSame(7, (int) $pivot->lead_time_days, 'Lead time lama tidak boleh terhapus');
    }

    public function test_ensure_linked_mengembalikan_false_saat_sudah_ada(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->assertTrue($this->mapping->ensureLinked($product, $supplier));
        $this->assertFalse($this->mapping->ensureLinked($product, $supplier));
    }

    public function test_ensure_linked_tidak_menggandakan_baris_pivot(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound($product, 5, $this->admin, supplierId: $supplier->id);
        $this->movements->recordInbound($product, 5, $this->admin, supplierId: $supplier->id);

        $this->assertSame(1, $product->suppliers()->count());
    }

    public function test_pembuatan_produk_dengan_pemasok_langsung_taut(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'PT Baja Permai']);

        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'AL-001',
                'name' => 'Produk Auto Link',
                'current_stock' => 80,
                'reorder_point' => 20,
                'supplier_id' => $supplier->id,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'AL-001')->firstOrFail();

        $this->assertTrue(
            $product->suppliers()->whereKey($supplier->id)->exists(),
            'Pemasok yang dipilih saat membuat produk harus langsung muncul di pemetaan',
        );
    }

    public function test_outbound_dan_opname_tidak_menautkan_pemasok(): void
    {
        $product = Product::factory()->create(['current_stock' => 50, 'reorder_point' => 10]);
        $supplier = Supplier::factory()->create();

        $this->movements->recordOutbound($product, 5, $this->admin);
        $this->movements->recordStockOpname($product, 45, $this->admin);

        $this->assertSame(0, $product->suppliers()->count());
        $this->assertNotNull($supplier->id);
    }

    // -----------------------------------------------------------------
    // Peta pemasok per produk untuk penyaringan dropdown
    // -----------------------------------------------------------------

    public function test_peta_menyertakan_pemasok_dari_pemetaan_dan_histori(): void
    {
        $produkA = Product::factory()->create();
        $produkB = Product::factory()->create();

        $terpeta = Supplier::factory()->create(['name' => 'CV Terpeta']);
        $dariHistori = Supplier::factory()->create(['name' => 'CV Dari Histori']);
        $asing = Supplier::factory()->create(['name' => 'CV Produk Lain']);

        // Produk A: satu dari pemetaan, satu dari riwayat movement.
        $this->mapping->attach($produkA, $terpeta, leadTimeDays: 5);
        $this->movements->recordInbound($produkA, 10, $this->admin, supplierId: $dariHistori->id);

        // Produk B: pemasok lain, tidak boleh bocor ke produk A.
        $this->mapping->attach($produkB, $asing, leadTimeDays: 3);

        $peta = $this->mapping->suppliersByProduct([$produkA->id, $produkB->id]);

        $namaA = array_column($peta[$produkA->id], 'name');
        $namaB = array_column($peta[$produkB->id], 'name');

        $this->assertContains('CV Terpeta', $namaA);
        $this->assertContains('CV Dari Histori', $namaA, 'Pemasok dari ledger harus ikut, walau tidak ada di pemetaan');
        $this->assertNotContains('CV Produk Lain', $namaA, 'Pemasok produk lain tidak boleh bocor');

        $this->assertSame(['CV Produk Lain'], $namaB);
    }

    public function test_pemasok_yang_dilepas_tetap_tampil_karena_ada_di_ledger(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->mapping->attach($product, $supplier, leadTimeDays: 4);
        $this->mapping->detach($product, $supplier);

        $peta = $this->mapping->suppliersByProduct([$product->id]);

        $this->assertSame(
            [],
            $peta[$product->id],
            'Tanpa riwayat movement, pemasok yang dilepas memang harus hilang dari filter',
        );

        // Kalau barang dari pemasok itu sudah tercatat, filter tidak boleh
        // menyembunyikannya.
        $this->movements->recordInbound($product, 5, $this->admin, supplierId: $supplier->id);

        $peta = $this->mapping->suppliersByProduct([$product->id]);

        $this->assertCount(1, $peta[$product->id]);
    }

    public function test_peta_kosong_untuk_produk_tanpa_pemasok(): void
    {
        $product = Product::factory()->create();

        $peta = $this->mapping->suppliersByProduct([$product->id]);

        $this->assertSame([], $peta[$product->id]);
    }

    public function test_peta_dengan_daftar_produk_kosong(): void
    {
        $this->assertSame([], $this->mapping->suppliersByProduct([]));
    }

    // -----------------------------------------------------------------
    // Halaman pergerakan stok mengirim peta itu
    // -----------------------------------------------------------------

    public function test_halaman_pergerakan_mengirim_peta_pemasok(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'PT Sumber Nusantara']);

        $this->movements->recordInbound($product, 15, $this->admin, supplierId: $supplier->id);

        $this->actingAs($this->admin)
            ->get('/inventory/movements')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Movements')
                ->has('suppliersByProduct')
                ->where("suppliersByProduct.{$product->id}.0.name", 'PT Sumber Nusantara')
            );
    }

    public function test_dropdown_mengelompokkan_pemasok_terdaftar_dan_lainnya(): void
    {
        $vue = (string) file_get_contents(resource_path('js/Pages/Inventory/Movements.vue'));

        $this->assertStringContainsString('suppliersByProduct', $vue);
        $this->assertStringContainsString('supplierTerpilih', $vue);
        $this->assertStringContainsString('supplierLain', $vue);

        // optgroup yang dikelompokkan, supaya batas "sudah / belum pernah" jelas di layar.
        $this->assertStringContainsString('<optgroup', $vue);
        $this->assertStringContainsString('Pernah menyuplai produk di faktur ini', $vue);
        $this->assertStringContainsString('Belum pernah menyuplai produk di faktur ini', $vue);
    }

    // -----------------------------------------------------------------
    // Halaman edit produk menandai data komersial yang belum diisi
    // -----------------------------------------------------------------

    public function test_halaman_edit_menandai_lead_time_yang_kosong(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $supplier = Supplier::factory()->create();

        $this->mapping->ensureLinked($product, $supplier);

        $this->actingAs($this->admin)
            ->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Edit')
                ->where('product.suppliers.0.lead_time_days', null)
            );

        $vue = (string) file_get_contents(resource_path('js/Pages/Products/Edit.vue'));

        $this->assertStringContainsString('tanpaLeadTime', $vue, 'Lead time kosong harus ditandai jelas');
        $this->assertStringContainsString('belum punya lead time', $vue);
    }

    // -----------------------------------------------------------------
    // Invarian tidak boleh bocor
    // -----------------------------------------------------------------

    public function test_auto_tautan_tidak_mengganggu_invarian_ledger(): void
    {
        $product = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 5]);
        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound($product, 50, $this->admin, supplierId: $supplier->id, unitPrice: 10_000.0);
        $this->movements->recordOutbound($product, 20, $this->admin);

        $product->refresh();

        $this->assertSame(30, $product->current_stock);
        $this->assertSame(
            $product->current_stock,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );

        // Stok harus di bawah batas aman -> peringatan tetap jalan.
        //
        // Jenis outwardly ada di dokumen sejak tahap 4, jadi filternya lewat
        // relasi. `where('movement_type', …)` di baris detail sekarang diam-diam
        // mengembalikan nol — angka yang terlihat seperti "peringatan mati",
        // bukan seperti "kolomnya pindah".
        $this->assertSame(
            1,
            InventoryMovement::ofMovementType(MovementType::Outbound)
                ->where('product_id', $product->id)
                ->count(),
        );
    }
}
