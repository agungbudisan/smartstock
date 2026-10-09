<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pelacakan pemasok pada barang masuk.
 *
 * Dua pertanyaan nyata dari user:
 *  1. Apakah setiap produk baru punya `supplier_id` NULL, dan apakah itu
 *     masalah untuk real case?
 *  2. Bagaimana melacak inbound datang dari supplier mana, mengingat jejak
 *     audit tidak menampilkannya?
 *
 * Jawaban untuk (1): yang NULL hanyalah Inbound pembuka dari form create —
 * sekarang form itu punya kolom pemasok opsional.
 * Jawaban untuk (2): kolom `supplier_id` sebenarnya sudah tersimpan, tapi
 * tidak terlihat di dua tempat: pesan audit dan tabel Buku Besar. Keduanya
 * dikunci di sini.
 */
class InboundSupplierTrackingTest extends TestCase
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

    private function ledgerSum(int $productId): int
    {
        return (int) InventoryMovement::where('product_id', $productId)->sum('quantity');
    }

    // -----------------------------------------------------------------
    // (2) Jejak audit menyebut pemasok
    // -----------------------------------------------------------------

    public function test_audit_inbound_menyebut_nama_pemasok(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'PT Sumber Nusantara']);
        $product = Product::factory()->create(['sku' => 'TRK-001']);

        $this->service->recordInbound(
            product: $product,
            quantity: 40,
            user: $this->admin,
            supplierId: $supplier->id,
            referenceCode: 'PO-123',
        );

        $audit = AuditLog::where('action', 'inbound.create')->latest('id')->first();

        $this->assertStringContainsString('PT Sumber Nusantara', $audit->description);
        $this->assertStringContainsString('PO-123', $audit->description);
        $this->assertStringContainsString('TRK-001', $audit->description);
    }

    public function test_audit_tanpa_pemasok_tidak_menulis_tanda_kosong(): void
    {
        $product = Product::factory()->create(['sku' => 'TRK-002']);

        $this->service->recordInbound($product, 25, $this->admin, referenceCode: 'OPENING');

        $audit = AuditLog::where('action', 'inbound.create')->latest('id')->first();

        // Stok pembuka sering memang tanpa dokumen, jadi tidak boleh muncul
        // teks "dari " yang menggantung.
        $this->assertStringNotContainsString('dari ', $audit->description);
        $this->assertStringContainsString('OPENING', $audit->description);
    }

    public function test_audit_outbound_tidak_ikut_menambah_pemasok(): void
    {
        $product = $this->produkDenganStok(50);

        $this->service->recordOutbound($product, 5, $this->admin);

        $audit = AuditLog::where('action', 'outbound.create')->latest('id')->first();

        $this->assertStringNotContainsString('dari ', $audit->description);
    }

    // -----------------------------------------------------------------
    // (2) Buku Besar menampilkan pemasok
    // -----------------------------------------------------------------

    public function test_buku_besar_mengirim_data_pemasok_ke_frontend(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'CV Bersih Higienis']);
        $product = Product::factory()->create(['sku' => 'TRK-003']);

        $this->service->recordInbound(
            product: $product,
            quantity: 10,
            user: $this->admin,
            supplierId: $supplier->id,
        );

        $this->actingAs($this->admin)
            ->get('/inventory/ledger')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('movements.data.0.supplier.name', 'CV Bersih Higienis')
            );
    }

    public function test_kolom_ref_memakai_label_yang_menyebut_pemasok(): void
    {
        // Tabel Buku Besar sudah diekstrak jadi komponen sendiri, jadi sel
        // pemasoknya ada di sana. Yang diperiksa di halaman induk hanya
        // bahwa datanya diteruskan -- kalau tidak, tabelnya tampil tapi
        // kolomnya kosong tanpa jejak.
        $tabel = (string) file_get_contents(resource_path('js/Components/Inventory/LedgerTable.vue'));

        $this->assertStringContainsString(
            'row.supplier',
            $tabel,
            'Buku Besar harus merender pemasok; datanya sudah dikirim controller '
            .'tapi tanpa sel di tabel tidak ada yang bisa dilihat pengguna',
        );

        $this->assertStringContainsString('Ref / Pemasok', $tabel);

        // Halaman entri tidak lagi merender tabel buku besar, jadi tidak ada
        // lagi yang perlu diperiksa di sana. Yang dijaga di halaman ini hanya
        // bahwa tabel itu dipanggil dari SATU tempat: kalau nanti dipakai lagi,
        // dua salinannya akan berbeda begitu ada perbaikan di salah satunya.
        $ledger = (string) file_get_contents(resource_path('js/Pages/Inventory/Ledger.vue'));
        $this->assertStringContainsString(
            '<LedgerTable',
            $ledger,
            'Halaman Buku Besar harus memakai komponen tabel.'
        );
    }

    // -----------------------------------------------------------------
    // (1) Stok pembuka boleh punya pemasok
    // -----------------------------------------------------------------

    public function test_create_produk_dengan_pemasok_mencatat_inbound_pembuka(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'PT Baja Permai']);

        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'TRK-004',
                'name' => 'Produk Dengan Pemasok',
                'current_stock' => 80,
                'reorder_point' => 20,
                'supplier_id' => $supplier->id,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'TRK-004')->firstOrFail();
        $movement = InventoryMovement::with('transaction')->where('product_id', $product->id)->firstOrFail();

        $this->assertSame(80, $movement->quantity);

        // Pemasok milik dokumen sejak tahap 4; kolomnya di baris detail sudah
        // dihapus. Memperiksa `$movement->supplier_id` di sini akan mengembalikan
        // null dan terlihat seperti "pemasok hilang" — bukan "sudah pindah".
        $this->assertSame($supplier->id, $movement->transaction->supplier_id);
        $this->assertSame(80, $this->ledgerSum($product->id));

        $audit = AuditLog::where('action', 'inbound.create')->latest('id')->first();
        $this->assertStringContainsString('PT Baja Permai', $audit->description);
    }

    public function test_create_produk_tanpa_pemasok_tetap_boleh(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', [
                'sku' => 'TRK-005',
                'name' => 'Produk Tanpa Pemasok',
                'current_stock' => 15,
                'reorder_point' => 5,
                'supplier_id' => '',
            ])
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'TRK-005')->firstOrFail();
        $movement = InventoryMovement::with('transaction')->where('product_id', $product->id)->firstOrFail();

        // Stok pembuka tanpa dokumen pembelian adalah kondisi nyata. Yang
        // diperiksa: dokumenNYA tidak punya pemasok — bukan baris detailnya.
        $this->assertNull($movement->transaction->supplier_id);
        $this->assertSame(15, $this->ledgerSum($product->id));
    }

    public function test_pemasok_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->from('/products/create')
            ->post('/products', [
                'sku' => 'TRK-006',
                'name' => 'Pemasok Ngawur',
                'current_stock' => 10,
                'reorder_point' => 5,
                'supplier_id' => 999999,
            ])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_edit_produk_tidak_bisa_mengubah_pemasok_stok_awal(): void
    {
        $product = $this->produkDenganStok(20, ['sku' => 'TRK-007']);
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->from("/products/{$product->id}/edit")
            ->patch("/products/{$product->id}", [
                'sku' => 'TRK-007',
                'name' => 'Nama Baru',
                'reorder_point' => 30,
                'supplier_id' => $supplier->id,
            ])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_halaman_create_menyediakan_daftar_pemasok(): void
    {
        Supplier::factory()->create(['name' => 'UD Sumber Pangan']);

        $this->actingAs($this->admin)
            ->get('/products/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Create')
                ->has('suppliers', 1)
                ->where('suppliers.0.name', 'UD Sumber Pangan')
            );
    }

    // -----------------------------------------------------------------

    private function produkDenganStok(int $stok, array $atribut = []): Product
    {
        $product = Product::factory()->create([
            'current_stock' => 0,
            'reorder_point' => 20,
            ...$atribut,
        ]);

        if ($stok > 0) {
            $this->service->recordInbound($product, $stok, $this->admin, referenceCode: 'SETUP');
        }

        return $product->fresh();
    }
}
