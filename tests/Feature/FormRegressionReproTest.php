<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduksi masalah form produk & pergerakan stok.
 *
 * Fokus: apakah form Create/Edit produk dan form entri pergerakan benar-benar
 * bekerja, dan apakah invarian `SUM(quantity) == current_stock` tetap berlaku
 * setelah pengguna memakai form tersebut.
 */
class FormRegressionReproTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->category = Category::factory()->create();
    }

    private function ledgerSum(int $productId): int
    {
        return (int) InventoryMovement::where('product_id', $productId)->sum('quantity');
    }

    /**
     * Buat produk yang bukunya benar sejak awal.
     *
     * `Product::factory()` mengisi `current_stock` langsung tanpa baris ledger,
     * jadi produk dari factory TIDAK bisa dipakai untuk memeriksa invarian
     * `SUM(quantity) == current_stock`. Semua setup di sini lewat service.
     */
    private function produkDenganStok(int $stok, array $atribut = []): Product
    {
        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'current_stock' => 0,
            'reorder_point' => 20,
            ...$atribut,
        ]);

        if ($stok > 0) {
            app(InventoryMovementService::class)->recordInbound(
                product: $product,
                quantity: $stok,
                user: $this->admin,
                referenceCode: 'SETUP',
            );
        }

        return $product->fresh();
    }

    // -----------------------------------------------------------------
    // Produk: create
    // -----------------------------------------------------------------

    public function test_create_produk_dengan_stok_awal(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'NEW-001',
                'name' => 'Produk Baru',
                'category_id' => $this->category->id,
                'current_stock' => 50,
                'reorder_point' => 10,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'NEW-001')->firstOrFail();

        $this->assertSame(50, $product->current_stock);
    }

    public function test_produk_baru_yang_punya_stok_harus_punya_baris_buku_besar(): void
    {
        $this->actingAs($this->admin)->post('/products', [
            'sku' => 'NEW-002',
            'name' => 'Produk Tanpa Ledger',
            'category_id' => $this->category->id,
            'current_stock' => 50,
            'reorder_point' => 10,
        ]);

        $product = Product::where('sku', 'NEW-002')->firstOrFail();

        // Invarian inti proyek: stok harus selalu bisa dijelaskan oleh buku besar.
        $this->assertSame(
            $product->current_stock,
            $this->ledgerSum($product->id),
            'Stok awal harus tercatat sebagai Inbound pembuka, kalau tidak invarian ledger rusak',
        );
    }

    // -----------------------------------------------------------------
    // Produk: edit
    // -----------------------------------------------------------------

    public function test_edit_produk_tidak_boleh_mengubah_stok_langsung(): void
    {
        $product = $this->produkDenganStok(100, ['sku' => 'EDIT-001']);

        // Form edit yang (lama) mengirim current_stock harus DITOLAK dengan
        // pesan yang mengarahkan ke Inbound/Opname — bukan diam-diam diabaikan
        // (yang membuat perubahan hilang tanpa jejak) dan bukan diterima diam-diam
        // (yang merusak invarian ledger).
        $response = $this->actingAs($this->admin)
            ->from("/products/{$product->id}/edit")
            ->patch("/products/{$product->id}", [
                'sku' => 'EDIT-001',
                'name' => 'Nama Baru',
                'category_id' => $this->category->id,
                'current_stock' => 999,
                'reorder_point' => 20,
            ]);

        $response->assertSessionHasErrors('current_stock');

        $this->assertStringContainsString(
            'Inbound',
            session('errors')->first('current_stock'),
            'Pesan harus mengarahkan ke Inbound/Opname',
        );

        $product->refresh();

        $this->assertSame(
            100,
            $product->current_stock,
            'Stok TIDAK boleh berubah karena form produk',
        );
        $this->assertSame(
            'EDIT-001',
            $product->sku,
            'Penolakan validasi berarti tidak ada perubahan sama sekali',
        );
        $this->assertSame(
            $product->current_stock,
            $this->ledgerSum($product->id),
            'Invarian ledger harus tetap berlaku',
        );
    }

    public function test_perubahan_stok_lewat_api_tidak_diterima(): void
    {
        $product = $this->produkDenganStok(100);

        $this->actingAs($this->admin)
            ->patch("/products/{$product->id}", [
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => $this->category->id,
                'current_stock' => 999,
                'reorder_point' => 20,
            ])
            ->assertSessionHasErrors('current_stock');

        $this->assertSame(100, $product->fresh()->current_stock);
    }

    public function test_edit_produk_berubah_tanpa_menentuh_stok(): void
    {
        $product = $this->produkDenganStok(100, ['sku' => 'EDIT-003']);

        $this->actingAs($this->admin)
            ->patch("/products/{$product->id}", [
                'sku' => 'EDIT-003',
                'name' => 'Nama Sudah Diperbarui',
                'category_id' => $this->category->id,
                'reorder_point' => 35,
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame('Nama Sudah Diperbarui', $product->name);
        $this->assertSame(35, $product->reorder_point);
        $this->assertSame(100, $product->current_stock);
        $this->assertSame($product->current_stock, $this->ledgerSum($product->id));
    }

    public function test_edit_tanpa_mengirim_current_stock_tetap_berhasil(): void
    {
        $product = $this->produkDenganStok(100, ['sku' => 'EDIT-002']);

        $this->actingAs($this->admin)
            ->patch("/products/{$product->id}", [
                'sku' => 'EDIT-002',
                'name' => 'Tanpa Stok',
                'category_id' => $this->category->id,
                'reorder_point' => 30,
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame(30, $product->reorder_point);
        $this->assertSame(100, $product->current_stock);
    }

    // -----------------------------------------------------------------
    // Pergerakan stok
    // -----------------------------------------------------------------

    public function test_outbound_berhasil_mengubah_stok_dan_ledger(): void
    {
        $product = $this->produkDenganStok(100);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'outbound',
                'items' => [['product_id' => $product->id, 'quantity' => 30]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(70, $product->fresh()->current_stock);
        $this->assertSame(70, $this->ledgerSum($product->id));
    }

    public function test_outbound_melebihi_stok_menolak_dengan_pesan(): void
    {
        $product = $this->produkDenganStok(10, ['reorder_point' => 5]);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'movement_type' => 'outbound',
                'items' => [['product_id' => $product->id, 'quantity' => 50]],
            ])
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(10, $product->fresh()->current_stock);
    }

    public function test_inbound_dengan_pemasok_dan_harga_memperbarui_pivot(): void
    {
        $product = $this->produkDenganStok(0);
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'supplier_id' => $supplier->id,
                'reference_code' => 'PO-1',
                'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_price' => 12500]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(20, $product->fresh()->current_stock);
        $this->assertSame(
            12500.0,
            (float) $product->suppliers()->first()->pivot->last_purchase_price,
        );
    }

    public function test_opname_menerima_angka_fisik_absolut(): void
    {
        $product = $this->produkDenganStok(100);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'adjustment',
                'items' => [['product_id' => $product->id, 'physical_count' => 87]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(87, $product->fresh()->current_stock);
        $this->assertSame(87, $this->ledgerSum($product->id));
    }

    // -----------------------------------------------------------------
    // Bentuk payload dari frontend
    // -----------------------------------------------------------------

    /**
     * Frontend mengirim SELURUH isi form apa adanya, tanpa memotong field yang
     * tidak relevan. `Movements.vue::setMode()` yang mengosongkan kolom, bukan
     * pemanggil request. Test ini mengunci bahwa server tetap benar saat
     * menerima semua field itu — kalau tidak, notchonzero/null yang "kebetulan"
     * dihapus di sisi klien adalah jebakan.
     *
     * @param  array<string, mixed>  $tambahan
     * @return array<string, mixed>
     */
    private function payloadLengkap(array $tambahan): array
    {
        // Sejak tahap 3 satu request berarti satu faktur: baris produk
        // dikemas dalam `items`. Kolom level dokumen (jenis, pemasok,
        // referensi) tetap di luar.
        $baris = [
            'product_id' => $tambahan['product_id'] ?? '',
            'quantity' => $tambahan['quantity'] ?? null,
            'physical_count' => $tambahan['physical_count'] ?? null,
            'unit_price' => $tambahan['unit_price'] ?? null,
        ];

        foreach (['quantity', 'physical_count', 'unit_price'] as $kunci) {
            unset($tambahan[$kunci], $tambahan['product_id']);
        }

        return [
            'movement_type' => 'outbound',
            'supplier_id' => '',
            'reference_code' => '',
            'movement_date' => '',
            ...$tambahan,
            'items' => [$baris],
        ];
    }

    public function test_payload_lengkap_mode_outbound_diterima(): void
    {
        $product = $this->produkDenganStok(100);

        // Kolom Inbound/Opname ikut terkirim tapi bernilai kosong.
        $this->actingAs($this->admin)
            ->post('/inventory/movements', $this->payloadLengkap([
                'movement_type' => 'outbound',
                'product_id' => $product->id,
                'quantity' => 10,
                'reference_code' => 'SO-FULL',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $product->fresh()->current_stock);
    }

    public function test_payload_lengkap_mode_inbound_diterima(): void
    {
        $product = $this->produkDenganStok(0);
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post('/inventory/movements', $this->payloadLengkap([
                'movement_type' => 'inbound',
                'product_id' => $product->id,
                'quantity' => 15,
                'supplier_id' => $supplier->id,
                'unit_price' => 9000,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(15, $product->fresh()->current_stock);
    }

    public function test_payload_lengkap_mode_opname_diterima(): void
    {
        $product = $this->produkDenganStok(100);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', $this->payloadLengkap([
                'movement_type' => 'adjustment',
                'product_id' => $product->id,
                'physical_count' => 93,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(93, $product->fresh()->current_stock);
        $this->assertSame(93, $this->ledgerSum($product->id));
    }

    public function test_nilai_tidak_relevan_yang_tertinggal_masih_ditolak(): void
    {
        $product = $this->produkDenganStok(100);

        // Mode outbound, tapi user sempat mengetik harga satuan negatif
        // lalu tidak sempat pindah mode (mis. klik submit sambil mode masih
        // terindeks). Server harus tetap menolak, bukan diam-diam menerima.
        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', $this->payloadLengkap([
                'movement_type' => 'outbound',
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_price' => -250,
            ]))
            ->assertSessionHasErrors('items.0.unit_price');

        $this->assertSame(100, $product->fresh()->current_stock);
    }

    public function test_gagal_validasi_memulihkan_mode_dan_input_yang_sudah_diisi(): void
    {
        $product = $this->produkDenganStok(100, ['reorder_point' => 90]);

        // Mode inbound + pemasok + harga, lalu validasi gagal (harga negatif).
        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => -100]],
            ])
            ->assertSessionHasErrors('items.0.unit_price');

        // Mode datang dari `oldMovement`. Dulu ia juga dibaca dari prop `filters`,
        $this->actingAs($this->admin)
            ->get('/inventory/movements')
            ->assertInertia(fn ($page) => $page
                ->where('oldMovement.movement_type', 'inbound')
                ->where('oldMovement.items.0.quantity', 5)
            );
    }
}
