<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Jobs\AggregateDailyOutboundJob;
use App\Models\AuditLog;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\ProductSupplierService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tahap 4 - kolom `movement_type`, `user_id`, `supplier_id` DIHAPUS dari
 * `inventory_movements`.
 *
 * File ini sebelumnya bernama `OldColumnsAreNoLongerReadTest`. Ia melawan kondisi
 * yang sekarang mustahil: kolom lama di-*racuni* dengan `adjustment`, lalu
 * semua bacaan dijalankan untuk membuktikan tidak ada yang masih memakainya.
 *
 * Itu prayarat yang benar, dan perannya sudah selesai. Sekarang semuanya
 * dihapus, karena tidak ada lagi kolom untuk diracuni.
 *
 * Yang diganti bukan sekadar "test masih hijau", tapi pemeriksaan yang lebih
 * keras: membaca kolom yang sudah dibuang HARUS melempar error. Kalau suatu
 * saat ada pemanggil yang kembali ke kolom lama - entah karena hasil refactor
 * atau karena menyalin dari catatan lama - kuerinya akan gagal keras, bukan diam-diam
 * mengembalikan NULL.
 */
class OldColumnsAreGoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->movements = new InventoryMovementService;
    }

    // -----------------------------------------------------------------
    // Skema
    // -----------------------------------------------------------------

    public function test_kolom_lama_sudah_tidak_ada_di_baris_detail(): void
    {
        foreach (['movement_type', 'user_id', 'supplier_id'] as $kolom) {
            $this->assertFalse(
                Schema::hasColumn('inventory_movements', $kolom),
                "Kolom {$kolom} seharusnya sudah dihapus di tahap 4",
            );
        }
    }

    public function test_kolom_yang_tetap_ada(): void
    {
        $tetap = ['transaction_id', 'product_id', 'quantity', 'unit_price', 'reference_code', 'movement_date'];

        foreach ($tetap as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('inventory_movements', $kolom),
                "Kolom {$kolom} seharusnya masih ada di baris detail",
            );
        }
    }

    /**
     * Membaca kolom yang sudah dihapus harus gagal KERAS.
     *
     * PENTING: pemeriksaan ini memakai SQL MENTAH, bukan query builder.
     *
     * Query builder menutup identifier dengan tanda kutip, jadi ia
     * menghasilkan `where "movement_type" = ?`. SQLite punya "double-quoted
     * string" sebagai fitur kompatibilitas warisan, sehingga `"movement_type"`
     * diperlakukan sebagai LITERAL STRING, bukan sebagai nama kolom. Hasilnya
     * `where()` di kolom yang sudah dibuang mengembalikan NOL BARIS tanpa
     * error sama sekali.
     *
     * MySQL berperilaku sebaliknya: "Unknown column" dan melempar keras. Jadi
     * filter yang tertinggal bisa lolos seluruh test SQLite -- yang
     * assertion-nya "0 hasil, sesuai harapan" -- lalu meledak begitu menyentuh
     * database sungguhan. Itu sebabnya pemeriksaan di sini sengaja memakai SQL tanpa
     * kutip: satu-satunya cara yang benar-benar membuktikan kolomnya hilang.
     */
    public function test_kolom_lama_benar_benar_hilang_dari_database(): void
    {
        // Barisnya harus ada dulu: pada tabel kosong, SQLite bisa melewati
        // validasi nama kolom dan test akan "lulus" tanpa pernah menyentuh
        // kolom yang diuji.
        $produk = $this->produkDenganStok(10);

        $this->assertGreaterThan(0, InventoryMovement::where('product_id', $produk->id)->count());

        $this->expectException(QueryException::class);

        DB::select('SELECT movement_type FROM inventory_movements LIMIT 1');
    }

    // -----------------------------------------------------------------
    // Pembacaan yang benar-benar hidup
    // -----------------------------------------------------------------

    public function test_scope_outbound_only_membaca_dokumen(): void
    {
        $produk = $this->produkDenganStok(50);

        $this->movements->recordOutbound($produk, 5, $this->admin);
        $this->movements->recordInbound($produk, 5, $this->admin);

        $this->assertSame(
            1,
            InventoryMovement::outboundOnly()->where('product_id', $produk->id)->count(),
            'Scope harus membaca jenis dari dokumen',
        );

        // `produkDenganStok()` membuat satu Inbound berlabel SETUP, jadi di
        // sini ada 2 inbound (SETUP + uji) dan 1 outbound.
        $this->assertSame(
            2,
            InventoryMovement::ofMovementType(MovementType::Inbound)
                ->where('product_id', $produk->id)->count(),
        );
    }

    public function test_job_agregasi_membaca_dokumen(): void
    {
        $produk = $this->produkDenganStok(80);

        // Job default-nya mengagregasi KEMARIN, jadi movement harus bertanggal
        // kemarin juga - kalau tidak, memang tidak ada yang boleh dijumlah.
        $kemarin = now()->subDay();

        $this->movements->recordOutbound($produk, 10, $this->admin, movementDate: $kemarin);
        $this->movements->recordOutbound($produk, 7, $this->admin, movementDate: $kemarin);
        $this->movements->recordInbound($produk, 20, $this->admin, movementDate: $kemarin);

        $this->assertGreaterThan(0, (new AggregateDailyOutboundJob)->handle());

        $row = DailyOutboundAggregation::where('product_id', $produk->id)
            ->whereDate('date', $kemarin->toDateString())
            ->first();

        $this->assertNotNull($row, 'Job harus tetap menjumlahkan barang keluar');
        $this->assertSame(17, (int) $row->total_qty_out, 'Inbound tidak boleh ikut dihitung');
    }

    public function test_pemetaan_pemasok_membaca_dokumen(): void
    {
        $produk = Product::factory()->create(['current_stock' => 0]);
        $supplier = Supplier::factory()->create(['name' => 'PT Dari Dokumen']);

        $this->movements->recordInbound($produk, 10, $this->admin, supplierId: $supplier->id);

        $peta = (new ProductSupplierService)->suppliersByProduct([$produk->id]);

        $this->assertContains('PT Dari Dokumen', array_column($peta[$produk->id] ?? [], 'name'));
    }

    public function test_jejak_audit_dan_peringatan_membaca_dokumen(): void
    {
        $produk = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 100]);
        $supplier = Supplier::factory()->create(['name' => 'CV Dari Dokumen']);

        $this->movements->recordInbound($produk, 10, $this->admin, supplierId: $supplier->id);
        $this->movements->recordOutbound($produk, 5, $this->admin);

        $audit = AuditLog::where('action', 'inbound.create')->latest('id')->firstOrFail();
        $this->assertStringContainsString('CV Dari Dokumen', $audit->description);

        // Peringatan ditentukan dari jenis dokumen. Kalau masih membaca kolom
        // lama, kolom yang sudah dibuang akan mematikan funcinya diam-diam.
        $this->assertSame(1, AuditLog::where('action', 'alert.low_stock')->count());
    }

    public function test_halaman_buku_besar_menampilkan_dari_dokumen(): void
    {
        $produk = $this->produkDenganStok(50);
        $supplier = Supplier::factory()->create(['name' => 'PT Tampil']);

        $this->movements->recordInbound($produk, 30, $this->admin, supplierId: $supplier->id);
        $this->movements->recordOutbound($produk, 4, $this->admin);

        $this->actingAs($this->admin)
            ->get('/inventory/ledger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movements.data.0.movement_type', MovementType::Outbound->value)
                ->where('movements.data.0.movement_type_label', 'Outbound')
                ->has('movements.data.0.system_code')
                ->has('movements.data.0.sibling_count')
            );
    }

    public function test_filter_jenis_di_halaman_masih_berfungsi(): void
    {
        $produk = $this->produkDenganStok(50);

        $this->movements->recordInbound($produk, 30, $this->admin);
        $this->movements->recordOutbound($produk, 4, $this->admin);

        // Dua inbound: SETUP dari helper + yang diuji.
        $this->actingAs($this->admin)
            ->get('/inventory/ledger?movement_type='.MovementType::Inbound->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movements.data.0.movement_type', MovementType::Inbound->value)
                ->where('movements.total', 2)
            );

        $this->actingAs($this->admin)
            ->get('/inventory/ledger?movement_type='.MovementType::Outbound->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('movements.total', 1));
    }

    // -----------------------------------------------------------------

    private function produkDenganStok(int $stok): Product
    {
        $product = Product::factory()->create([
            'current_stock' => 0,
            'reorder_point' => 10,
        ]);

        if ($stok > 0) {
            $this->movements->recordInbound($product, $stok, $this->admin, referenceCode: 'SETUP');
        }

        return $product->fresh();
    }
}
