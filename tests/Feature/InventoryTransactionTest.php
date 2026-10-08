<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tahap 1 dan 2 pemecahan header-detail.
 *
 * Invarian yang dikunci di sini semuanya bisa diperiksa dengan kueri, jadi
 * tidak ada yang bergantung pada browser.
 *
 * Jejak audit dan invarian ledger TIDAK boleh bergeser selama refactor. Kolom
 * lama (`movement_type`, `user_id`, `supplier_id`) masih ditulis sampai tahap 4,
 * jadi test suite tidak pernah melihat keadaan setengah jadi.
 */
class InventoryTransactionTest extends TestCase
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

    /**
     * Putuskan semua baris detail dari dokumennya, lalu buang dokumennya.
     *
     * Dipakai test backfill untuk mengembalikan keadaan "sebelum tahap 2",
     * ketika `InventoryMovementService` belum membuat dokumen sendiri.
     *
     * Urutannya penting: putuskan `transaction_id` DULU, baru hapus dokumennya.
     * Kalau dibalik, cascade delete akan ikut menghapus baris detail yang
     * justru sedang diuji.
     */
    private function lepasSemuaDokumen(): void
    {
        DB::table('inventory_movements')->update(['transaction_id' => null]);
        DB::table('inventory_transactions')->delete();
    }

    // -----------------------------------------------------------------
    // Skema
    // -----------------------------------------------------------------

    public function test_tabel_dan_kolom_ada(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_transactions'));

        foreach (['system_code', 'physical_reference', 'movement_type', 'supplier_id', 'user_id', 'transaction_date'] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('inventory_transactions', $kolom),
                "kolom {$kolom} tidak ada",
            );
        }

        $this->assertTrue(Schema::hasColumn('inventory_movements', 'transaction_id'));
        $this->assertTrue(Schema::hasColumn('inventory_movements', 'unit_price'));
    }

    /**
     * REGRESI YANG PERNAH TERJADI: menambah kolom dengan `->constrained()` di
     * SQLite membuat Laravel me-rebuild tabel, dan rebuild itu membuang CHECK
     * constraint kolom enum. Akibatnya nilai ngawur masuk tanpa error.
     *
     * Yang ini menutup jalan yang sama di tabel BARU.
     */
    public function test_enum_pada_tabel_baru_benar_benar_ditegakkan(): void
    {
        $this->expectException(QueryException::class);

        DB::table('inventory_transactions')->insert([
            'system_code' => 'NGGAWUR-0001',
            'movement_type' => 'transfer',
            'transaction_date' => '2026-10-08 10:00:00',
        ]);
    }

    public function test_system_code_unik(): void
    {
        $ini = InventoryTransaction::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('inventory_transactions')->insert([
            'system_code' => $ini->system_code,
            'movement_type' => MovementType::Inbound->value,
            'transaction_date' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Tahap 2: service membuat dokumen
    // -----------------------------------------------------------------

    public function test_setiap_movement_baru_punya_dokumen(): void
    {
        $produk = $this->produkDenganStok(0);

        $this->movements->recordInbound($produk, 10, $this->admin, referenceCode: 'PO-BARU');

        $movement = InventoryMovement::where('product_id', $produk->id)->latest('id')->firstOrFail();

        $this->assertNotNull($movement->transaction_id, 'Setiap movement dari service harus tertaut ke dokumen');

        $trx = $movement->refresh()->transaction;

        $this->assertSame('PO-BARU', $trx->physical_reference);
        $this->assertSame(MovementType::Inbound, $trx->movement_type);
    }

    public function test_method_lama_membuat_dokumen_satu_baris(): void
    {
        $produk = $this->produkDenganStok(20);

        $movement = $this->movements->recordOutbound($produk, 5, $this->admin, referenceCode: 'SO-LAMA');

        $this->assertSame(1, $movement->transaction->movements()->count());
        $this->assertSame('SO-LAMA', $movement->transaction->physical_reference);
    }

    public function test_unit_price_kini_tersimpan_di_baris_detail(): void
    {
        $produk = $this->produkDenganStok(0);

        $movement = $this->movements->recordInbound(
            $produk, 10, $this->admin,
            supplierId: Supplier::factory()->create()->id,
            referenceCode: 'PO-HARGA',
            unitPrice: 25_000.0,
        );

        // Ini yang tadinya mustahil: harga historis tidak punya tempat tinggal
        // di ledger, hanya di pivot yang bisa berubah kapan saja.
        $this->assertEquals(25_000.0, (float) $movement->refresh()->unit_price);
    }

    // -----------------------------------------------------------------
    // Tahap 2: dokumen banyak baris
    // -----------------------------------------------------------------

    public function test_satu_dokumen_bisa_memuat_banyak_baris(): void
    {
        $supplier = Supplier::factory()->create();
        $produkA = $this->produkDenganStok(0);
        $produkB = $this->produkDenganStok(0);
        $produkC = $this->produkDenganStok(0);

        $document = $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [
                ['product' => $produkA, 'quantity' => 10, 'unit_price' => 5_000.0],
                ['product' => $produkB, 'quantity' => 25],
                ['product' => $produkC, 'quantity' => 7],
            ],
            user: $this->admin,
            supplierId: $supplier->id,
            physicalReference: 'PO-MULTI',
        );

        $this->assertSame(3, $document->movements()->count());
        $this->assertSame(1, InventoryTransaction::count());

        $this->assertSame(10, $produkA->refresh()->current_stock);
        $this->assertSame(25, $produkB->refresh()->current_stock);
        $this->assertSame(7, $produkC->refresh()->current_stock);

        $this->assertEquals(5_000.0, (float) $document->movements()->firstWhere('product_id', $produkA->id)->unit_price);
    }

    public function test_semua_baris_satu_dokumen_membagi_nomor_yang_sama(): void
    {
        $produkA = $this->produkDenganStok(30);
        $produkB = $this->produkDenganStok(40);

        $this->movements->recordDocument(
            type: MovementType::Outbound,
            lines: [
                ['product' => $produkA, 'quantity' => 3],
                ['product' => $produkB, 'quantity' => 4],
            ],
            user: $this->admin,
            physicalReference: 'SO-SAMA',
        );

        // Satu nota penjualan boleh menutup beberapa baris.
        $this->assertSame(
            2,
            InventoryMovement::where('reference_code', 'SO-SAMA')->count(),
        );

        $this->assertSame(
            1,
            InventoryMovement::where('reference_code', 'SO-SAMA')
                ->distinct()->count('transaction_id'),
        );
    }

    public function test_dokumen_batal_jika_satu_baris_tidak_cukup_stok(): void
    {
        $produkA = $this->produkDenganStok(50);
        $produkB = $this->produkDenganStok(3);

        $hanyaSatu = $this->movements->recordOutbound($produkB, 1, $this->admin);

        try {
            $this->movements->recordDocument(
                type: MovementType::Outbound,
                lines: [
                    ['product' => $produkA, 'quantity' => 10],
                    ['product' => $produkB, 'quantity' => 999],
                ],
                user: $this->admin,
                physicalReference: 'SO-GAGAL',
            );

            $this->fail('Harus melempar exception karena stok tidak cukup.');
        } catch (InsufficientStockException) {
            // Satu dokumen bersifat utuh. Purchase order yang Separuh
            // half received gets recorded as a PARTIAL receipt, not as a
            // complete document that failed.
        }

        // Tidak boleh ada dokumen maupun baris yang tersisa dari percobaan gagal.
        $this->assertSame(0, InventoryTransaction::where('physical_reference', 'SO-GAGAL')->count());

        // Rollback mengembalikan stok produk PERTAMA juga, bukan hanya produk
        // yang jadi penyebab kegagalan.
        $this->assertSame(50, $produkA->refresh()->current_stock);
        $this->assertSame(2, $produkB->refresh()->current_stock);

        $this->assertSame(1, $hanyaSatu->transaction->movements()->count());
    }

    public function test_baris_diurutkan_tidak_mengubah_hasil(): void
    {
        $produkA = $this->produkDenganStok(0);
        $produkB = $this->produkDenganStok(0);

        $dokumen = $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [
                ['product' => $produkB, 'quantity' => 5],
                ['product' => $produkA, 'quantity' => 3],
            ],
            user: $this->admin,
        );

        // Baris diurutkan berdasarkan product_id supaya semua dokumen mengunci
        // dalam urutan yang sama. Kalau tidak, dua dokumen yang memuat produk A
        // dan B bisa saling mengunci berlawanan lalu deadlock.
        $ids = $dokumen->movements->pluck('product_id')->all();

        $this->assertSame($ids, collect($ids)->sort()->values()->all());
    }

    public function test_dokumen_kosong_ditolak(): void
    {
        $this->expectException(InventoryException::class);

        $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [],
            user: $this->admin,
        );
    }

    public function test_jumlah_nol_ditolak(): void
    {
        $this->expectException(InventoryException::class);

        $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [['product' => $this->produkDenganStok(0), 'quantity' => 0]],
            user: $this->admin,
        );
    }

    public function test_harga_negatif_ditolak(): void
    {
        $this->expectException(InventoryException::class);

        $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [['product' => $this->produkDenganStok(0), 'quantity' => 5, 'unit_price' => -1]],
            user: $this->admin,
        );
    }

    public function test_opname_banyak_baris_menggunakan_angka_fisik(): void
    {
        $produkA = $this->produkDenganStok(50);
        $produkB = $this->produkDenganStok(20);

        $this->movements->recordDocument(
            type: MovementType::Adjustment,
            lines: [
                ['product' => $produkA, 'physical_count' => 47],
                ['product' => $produkB, 'physical_count' => 25],
            ],
            user: $this->admin,
            physicalReference: 'OPNAME-BANYAK',
        );

        // Yang masuk ke buku besar adalah SELISIH, bukan angka fisik.
        $this->assertSame(47, $produkA->refresh()->current_stock);
        $this->assertSame(25, $produkB->refresh()->current_stock);

        // Jenis hidup di dokumen sejak tahap 4.
        $deltaA = (int) InventoryMovement::ofMovementType(MovementType::Adjustment)
            ->where('product_id', $produkA->id)
            ->latest('id')->value('quantity');

        $this->assertSame(-3, $deltaA);
    }

    public function test_setiap_baris_mendapat_audit_log_sendiri(): void
    {
        $supplier = Supplier::factory()->create();

        $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [
                ['product' => $this->produkDenganStok(0), 'quantity' => 4],
                ['product' => $this->produkDenganStok(0), 'quantity' => 6],
            ],
            user: $this->admin,
            supplierId: $supplier->id,
            physicalReference: 'PO-AUDIT',
        );

        // Jejak audit per baris dipertahankan: auditor butuh tahu perubahan
        // stok tiap SKU, bukan cuma "ada 10 unit masuk".
        $this->assertSame(
            2,
            AuditLog::where('action', 'inbound.create')
                ->where('description', 'like', '%ref PO-AUDIT%')
                ->count(),
        );
    }

    public function test_pemetaan_pemasok_ikut_taut_untuk_setiap_baris(): void
    {
        $supplier = Supplier::factory()->create();
        $produkA = $this->produkDenganStok(0);
        $produkB = $this->produkDenganStok(0);

        $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [
                ['product' => $produkA, 'quantity' => 4],
                ['product' => $produkB, 'quantity' => 6],
            ],
            user: $this->admin,
            supplierId: $supplier->id,
            physicalReference: 'PO-TAUT',
        );

        $this->assertTrue($produkA->refresh()->suppliers()->whereKey($supplier->id)->exists());
        $this->assertTrue($produkB->refresh()->suppliers()->whereKey($supplier->id)->exists());
    }

    // -----------------------------------------------------------------
    // Backfill: DIHAPUS di tahap 4
    // -----------------------------------------------------------------

    /*
     * Lima test backfill pernah ada di sini: `test_backfill_menghubungkan_semua_baris_lama`,
     * `test_backfill_mengelompokkan_baris_dengan_dokumen_yang_sama`,
     * `test_backfill_tidak_menggabungkan_pemasok_berbeda`,
     * `test_backfill_tidak_menggabungkan_baris_tanpa_nomor`, dan
     * `test_backfill_idempoten`. Semuanya memanggil `BackfillTransactions`,
     * yang dibaca dari kolom `movement_type` / `supplier_id` / `user_id`.
     *
     * KELAS DAN TESTNYA SUDAH DIHAPUS, dan alasannya bukan "sudah usang" tapi "input-nya
     * tidak ada lagi":
     *
     *  - Baris tanpa dokumen hanya mungkin kalau kolom lama masih ada. Tanpa
     *    kolom itu, keadaan "baris tanpa dokumen" tidak bisa direpresentasikan
     *    lagi sama sekali.
     *  - `recordDocument()` selalu membuat dokumen di dalam transaksi yang sama,
     *    jadi tidak ada jalur yang menghasilkan baris terlantar.
     *  - Mempertahankan kelasnya berarti menyisakan kode yang SELECT kolom
     *    tidak ada. Itu akan melempar "no such column" di instalasi baru.
     *
     * Yang menggantikannya adalah jaminan yang lebih kuat, bukan lebih lemah:
     * setiap baris detail WAJIB punya dokumen, diuji lewat invarian ledger dan
     * `SeederDocumentGroupingTest`.
     */

    // -----------------------------------------------------------------
    // Konsistensi header vs detail
    // -----------------------------------------------------------------

    public function test_header_dan_detail_konsisten(): void
    {
        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound(
            $this->produkDenganStok(0), 10, $this->admin,
            supplierId: $supplier->id, referenceCode: 'PO-KONSISTEN',
        );

        $movement = InventoryMovement::with('transaction')->latest('id')->firstOrFail();
        $trx = $movement->transaction;

        // Sejak tahap 4 kolom-kolom ini hanya ada di dokumen. Yang diperiksa
        // justru kebalikannya: tidak ada apa pun di baris detail yang boleh
        // menyimpang, karena tidak ada kolom untuk menyimpang.
        $this->assertSame('PO-KONSISTEN', $trx->physical_reference);
        $this->assertSame($movement->reference_code, $trx->physical_reference);
        $this->assertTrue($movement->movement_date->isSameDay($trx->transaction_date));
    }

    public function test_factory_membuat_header_yang_cocok_dengan_detailnya(): void
    {
        $supplier = Supplier::factory()->create();
        $movement = InventoryMovement::factory()->inbound(30, $supplier)->create();

        $trx = $movement->refresh()->transaction;

        $this->assertNotNull($trx, 'Factory harus membuat dokumen untuk tiap baris detail');
        $this->assertSame(MovementType::Inbound, $trx->movement_type);
        $this->assertSame($supplier->id, $trx->supplier_id);
        $this->assertInstanceOf(User::class, $trx->user);
        $this->assertSame($movement->reference_code, $trx->physical_reference);
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    public function test_satu_dokumen_punya_banyak_baris_detail(): void
    {
        $trx = InventoryTransaction::factory()->denganFaktur('PO-20261008-001')->create();

        InventoryMovement::factory()->count(3)->create(['transaction_id' => $trx->id]);

        $this->assertSame(3, $trx->movements()->count());
        $this->assertCount(3, $trx->refresh()->movements()->get());
    }

    public function test_relasi_dokumen_menyediakan_pemasok_dan_pencatat(): void
    {
        $trx = InventoryTransaction::factory()
            ->inbound(Supplier::factory()->create(['name' => 'PT Uji']))
            ->create();

        $this->assertSame('PT Uji', $trx->refresh()->supplier->name);
        $this->assertTrue($trx->user->isAdminGudang());
    }

    // -----------------------------------------------------------------
    // Penomoran
    // -----------------------------------------------------------------

    public function test_pola_system_code_ikut_jenis(): void
    {
        $this->assertSame('INB', InventoryTransaction::prefixFor(MovementType::Inbound));
        $this->assertSame('OUT', InventoryTransaction::prefixFor(MovementType::Outbound));
        $this->assertSame('ADJ', InventoryTransaction::prefixFor(MovementType::Adjustment));
    }

    public function test_nomor_berikutnya_menghitung_per_jenis_dan_per_hari(): void
    {
        InventoryTransaction::factory()->create([
            'system_code' => 'INB-20261008-0001',
            'movement_type' => MovementType::Inbound,
        ]);

        InventoryTransaction::factory()->create([
            'system_code' => 'INB-20261008-0002',
            'movement_type' => MovementType::Inbound,
        ]);

        $tanggal = '2026-10-08';

        $this->assertSame('INB-20261008-0003', InventoryTransaction::nextSystemCode(MovementType::Inbound, $tanggal));
        $this->assertSame('OUT-20261008-0001', InventoryTransaction::nextSystemCode(MovementType::Outbound, $tanggal));
        $this->assertSame('INB-20261009-0001', InventoryTransaction::nextSystemCode(MovementType::Inbound, '2026-10-09'));
    }

    public function test_label_mengutamakan_nomor_fisik(): void
    {
        $dengan = InventoryTransaction::factory()->denganFaktur('PO-20261008-001')->create();

        $this->assertSame('PO-20261008-001', $dengan->displayReference());

        $tanpa = InventoryTransaction::factory()->create();

        $this->assertSame(
            $tanpa->system_code,
            $tanpa->displayReference(),
            'Tanpa nomor fisik, kode internal harus dipakai supaya baris tetap punya identitas',
        );
    }

    public function test_system_code_otomatis_dari_service(): void
    {
        $produk = $this->produkDenganStok(0);

        $dokumen = $this->movements->recordDocument(
            type: MovementType::Inbound,
            lines: [['product' => $produk, 'quantity' => 5]],
            user: $this->admin,
            transactionDate: Carbon::parse('2026-10-08 09:00:00'),
        );

        $this->assertMatchesRegularExpression(
            '/^INB-20261008-\d{4}$/',
            $dokumen->system_code,
        );
    }

    // -----------------------------------------------------------------
    // Invarian yang tidak boleh berganggu
    // -----------------------------------------------------------------

    public function test_invarian_ledger_tidak_berganggu(): void
    {
        $produk = $this->produkDenganStok(20);

        $supplier = Supplier::factory()->create();

        $this->movements->recordInbound($produk, 15, $this->admin, supplierId: $supplier->id);
        $this->movements->recordOutbound($produk, 8, $this->admin);

        $produk->refresh();

        $this->assertSame(27, $produk->current_stock);
        $this->assertSame(27, (int) InventoryMovement::where('product_id', $produk->id)->sum('quantity'));
    }
}
