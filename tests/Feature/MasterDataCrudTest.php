<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * CRUD Master Data (Fase 6.1) — semua balasan lewat Inertia::render().
 */
class MasterDataCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
    }

    // -----------------------------------------------------------------
    // Produk
    // -----------------------------------------------------------------

    public function test_daftar_produk_dirender_bersama_kategori(): void
    {
        $category = Category::factory()->create(['name' => 'Elektronik']);
        Product::factory()->kategori($category)->create(['name' => 'Lampu LED']);

        $this->actingAs($this->admin)
            ->get('/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Index')
                ->where('products.total', 1)
                ->where('categories.0.name', 'Elektronik')
                ->has('products.data.0.name')
            );
    }

    public function test_daftar_produk_bisa_difilter_pencarian_dan_stok(): void
    {
        Product::factory()->create(['name' => 'Kabel Listrik', 'sku' => 'KBL-001', 'current_stock' => 300, 'reorder_point' => 50]);
        Product::factory()->create(['name' => 'Sekrup', 'sku' => 'SKR-002', 'current_stock' => 80, 'reorder_point' => 40]);

        // Stok & batas aman ditulis eksplisit: default factory mengacak
        // current_stock (50-500) dan reorder_point (10-100), sehingga sesekali
        // produk "aman" ikut terbawa filter "perlu reorder".
        Product::factory()->create(['name' => 'Rokok Kretek', 'sku' => 'RKK-003', 'current_stock' => 5, 'reorder_point' => 20]);

        $this->actingAs($this->admin)
            ->get('/products?search=Kabel')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.total', 1));

        $this->actingAs($this->admin)
            ->get('/products?stock=low')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.total', 1));

        $this->actingAs($this->admin)
            ->get('/products?stock=out')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.total', 0));

        $this->actingAs($this->admin)
            ->get('/products?stock=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.total', 3));
    }

    public function test_produk_dapat_dibuat_dan_diperbarui(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'NEW-001',
                'name' => 'Produk Baru',
                'category_id' => null,
                'current_stock' => 25,
                'reorder_point' => 10,
            ])
            ->assertRedirect('/products')
            ->assertSessionHas('success');

        $product = Product::where('sku', 'NEW-001')->firstOrFail();
        $this->assertSame('Produk Baru', $product->name);
        $this->assertSame(25, $product->current_stock);

        // Stok awal harus bisa dijelaskan buku besar, bukan hanya ada di kolom.
        $this->assertSame(
            25,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );

        // Edit TIDAK mengirim current_stock: field itu dilarang saat update.
        $this->actingAs($this->admin)
            ->patch("/products/{$product->id}", [
                'sku' => 'NEW-001',
                'name' => 'Produk Diperbarui',
                'category_id' => null,
                'reorder_point' => 12,
            ])
            ->assertRedirect('/products');

        $this->assertSame('Produk Diperbarui', $product->fresh()->name);
        $this->assertSame(12, $product->fresh()->reorder_point);
        $this->assertSame(25, $product->fresh()->current_stock, 'Stok tidak boleh berubah via form');
    }

    public function test_sku_duplikat_ditolak(): void
    {
        Product::factory()->create(['sku' => 'DUP-001']);

        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'DUP-001',
                'name' => 'Kembar',
                'current_stock' => 0,
                'reorder_point' => 0,
            ])
            ->assertSessionHasErrors('sku');
    }

    public function test_produk_yang_diedit_boleh_memakai_sku_sendiri(): void
    {
        $product = Product::factory()->create(['sku' => 'SAMA-001']);

        $this->actingAs($this->admin)
            ->patch("/products/{$product->id}", [
                'sku' => 'SAMA-001',
                'name' => 'Nama Baru',
                'reorder_point' => 5,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nama Baru', $product->fresh()->name);
    }

    public function test_validasi_produk(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => '',
                'name' => '',
                'current_stock' => -5,
                'reorder_point' => -1,
            ])
            ->assertSessionHasErrors(['sku', 'name', 'current_stock', 'reorder_point']);
    }

    public function test_hapus_produk_memakai_soft_delete_bisa_dipulihkan(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/products/{$product->id}")
            ->assertRedirect('/products');

        $this->assertSoftDeleted($product);
        $this->assertSame(0, Product::count());
        $this->assertSame(1, Product::withTrashed()->count());

        $this->actingAs($this->admin)
            ->post("/products/{$product->id}/restore")
            ->assertRedirect('/products');

        $this->assertNotNull(Product::find($product->id));
    }

    public function test_arsip_bisa_ditampilkan_lewat_filter(): void
    {
        $archived = Product::factory()->create();
        $archived->delete();

        $this->actingAs($this->admin)
            ->get('/products?trashed=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.total', 1));
    }

    public function test_halaman_ubah_produk_menampilkan_pemasok_terhubung(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'PT Sumber']);
        $product->suppliers()->attach($supplier->id, [
            'last_purchase_price' => 12500.50,
            'lead_time_days' => 7,
        ]);

        $this->actingAs($this->admin)
            ->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Edit')
                ->where('product.suppliers.0.name', 'PT Sumber')
                ->where('product.suppliers.0.lead_time_days', 7)
            );
    }

    // -----------------------------------------------------------------
    // Kategori
    // -----------------------------------------------------------------

    public function test_kategori_dapat_dibuat_diperbarui_dan_dihapus(): void
    {
        $this->actingAs($this->admin)
            ->post('/categories', ['name' => 'Frozen Goods', 'description' => 'Simpan beku'])
            ->assertSessionHasNoErrors();

        $category = Category::where('name', 'Frozen Goods')->firstOrFail();

        $this->actingAs($this->admin)
            ->patch("/categories/{$category->id}", ['name' => 'Frozen Food'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Frozen Food', $category->fresh()->name);

        $this->actingAs($this->admin)
            ->delete("/categories/{$category->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Category::count());
    }

    public function test_nama_kategori_duplikat_ditolak(): void
    {
        Category::factory()->create(['name' => 'Kembar']);

        $this->actingAs($this->admin)
            ->post('/categories', ['name' => 'Kembar'])
            ->assertSessionHasErrors('name');
    }

    public function test_menghapus_kategori_tidak_menghapus_produk(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->kategori($category)->create();

        $this->actingAs($this->admin)->delete("/categories/{$category->id}");

        $this->assertNotNull($product->fresh());
        $this->assertNull($product->fresh()->category_id, 'category_id jadi NULL, produk tetap ada');
    }

    public function test_daftar_kategori_menampilkan_jumlah_produk(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(2)->kategori($category)->create();

        $this->actingAs($this->admin)
            ->get('/categories')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories.0.products_count', 2)
            );
    }

    // -----------------------------------------------------------------
    // Pemasok
    // -----------------------------------------------------------------

    public function test_pemasok_dapat_dibuat_diperbarui_dan_dihapus(): void
    {
        $this->actingAs($this->admin)
            ->post('/suppliers', [
                'name' => 'PT Sumber Baja',
                'contact_person' => 'Budi',
                'phone' => '021-5551234',
                'address' => 'Jl. Industri No. 5',
            ])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::where('name', 'PT Sumber Baja')->firstOrFail();

        $this->actingAs($this->admin)
            ->patch("/suppliers/{$supplier->id}", [
                'name' => 'PT Sumber Jaya',
                'contact_person' => 'Andi',
                'phone' => '021-5559999',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('PT Sumber Jaya', $supplier->fresh()->name);
        $this->assertSame('Andi', $supplier->fresh()->contact_person);

        $this->actingAs($this->admin)->delete("/suppliers/{$supplier->id}");
        $this->assertSame(0, Supplier::count());
    }

    public function test_nama_pemasok_wajib_diisi(): void
    {
        $this->actingAs($this->admin)
            ->post('/suppliers', ['name' => ''])
            ->assertSessionHasErrors('name');
    }
}
