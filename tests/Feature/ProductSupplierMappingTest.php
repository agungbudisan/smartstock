<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\ProductSupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * UI Pemetaan Harga & Lead Time (PRD Modul 1) — satu produk bisa disuplai
 * banyak pemasok, masing-masing dengan harga beli terakhir & lead time.
 */
class ProductSupplierMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private ProductSupplierService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->product = Product::factory()->create(['sku' => 'MAP-001']);
        $this->service = new ProductSupplierService;
    }

    // -----------------------------------------------------------------
    // Halaman
    // -----------------------------------------------------------------

    public function test_halaman_pemetaan_menampilkan_pemasok_terpasang_dan_yang_tersedia(): void
    {
        $linked = Supplier::factory()->create(['name' => 'PT Terpasang']);
        $free = Supplier::factory()->create(['name' => 'PT Belum Terpasang']);

        $this->product->suppliers()->attach($linked->id, [
            'last_purchase_price' => 12500.50,
            'lead_time_days' => 7,
        ]);

        $this->actingAs($this->admin)
            ->get("/products/{$this->product->id}/suppliers")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Suppliers')
                ->where('product.sku', 'MAP-001')
                ->has('linked', 1)
                ->where('linked.0.name', 'PT Terpasang')
                ->where('linked.0.lead_time_days', 7)
                ->where('linked.0.is_fastest', true)
                // Pemasok yang belum terpasang harus tersedia untuk dipilih.
                ->has('available', 1)
                ->where('available.0.name', 'PT Belum Terpasang')
            );
    }

    public function test_pemasok_terpasang_tidak_ditawarkan_lagi(): void
    {
        $a = Supplier::factory()->create();
        $b = Supplier::factory()->create();
        $this->product->suppliers()->attach($a->id, []);

        $this->actingAs($this->admin)
            ->get("/products/{$this->product->id}/suppliers")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('available', 1)
                ->where('available.0.id', $b->id)
            );
    }

    // -----------------------------------------------------------------
    // Service
    // -----------------------------------------------------------------

    public function test_attach_membuat_pivot_dengan_harga_dan_lead_time(): void
    {
        $supplier = Supplier::factory()->create();

        $this->service->attach($this->product, $supplier, 9900.75, 5);

        $pivot = $this->product->suppliers()->first()->pivot;
        $this->assertSame(9900.75, (float) $pivot->last_purchase_price);
        $this->assertSame(5, (int) $pivot->lead_time_days);
    }

    public function test_attach_ulang_memperbarui_bukan_menambah_baris(): void
    {
        $supplier = Supplier::factory()->create();

        $this->service->attach($this->product, $supplier, 1000, 10);
        $this->service->attach($this->product, $supplier, 2000, 4);

        $this->assertSame(1, $this->product->suppliers()->count());
        $this->assertSame(2000.00, (float) $this->product->suppliers()->first()->pivot->last_purchase_price);
        $this->assertSame(4, (int) $this->product->suppliers()->first()->pivot->lead_time_days);
    }

    public function test_seatunya_pivot_bisa_dikosongkan(): void
    {
        $supplier = Supplier::factory()->create();
        $this->service->attach($this->product, $supplier, 1000, 10);

        $this->service->attach($this->product, $supplier, null, null);

        $pivot = $this->product->suppliers()->first()->pivot;
        $this->assertNull($pivot->last_purchase_price);
        $this->assertNull($pivot->lead_time_days);
    }

    public function test_detach_melepas_tanpa_menghapus_riwayat_pergerakan(): void
    {
        $supplier = Supplier::factory()->create();

        // Baris ledger yang merujuk pemasok harus tetap ada. Pemasoknya sekarang
        // ada di dokumen, jadi dokumennya yang dibuat lebih dulu.
        $document = InventoryTransaction::factory()
            ->inbound($supplier)
            ->create(['physical_reference' => 'PO-DETACH']);

        $movement = new InventoryMovement([
            'transaction_id' => $document->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'movement_date' => now(),
        ]);
        $movement->save();

        $this->service->attach($this->product, $supplier, 1000, 3);
        $this->service->detach($this->product, $supplier);

        $this->assertSame(0, $this->product->suppliers()->count());

        // Melepas pivot BUKAN menghapus dokumen atau ledger — keduanya utuh,
        // termasuk jejak asal-usul barangnya.
        $this->assertSame(1, InventoryMovement::where('product_id', $this->product->id)->count());
        $this->assertSame(
            $supplier->id,
            $movement->fresh()->transaction->supplier_id,
            'Pemasok pada dokumen tidak berubah karena hanya baris pivot yang dihapus',
        );
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $document->id,
            'physical_reference' => 'PO-DETACH',
        ]);
    }

    public function test_sync_menambah_memperbarui_dan_melepas_dalam_satu_panggilan(): void
    {
        $keep = Supplier::factory()->create(['name' => 'Tetap']);
        $remove = Supplier::factory()->create(['name' => 'Dilepas']);
        $add = Supplier::factory()->create(['name' => 'Baru']);

        $this->product->suppliers()->attach([$keep->id, $remove->id], ['lead_time_days' => 3]);

        $this->service->sync($this->product, [
            ['supplier_id' => $keep->id, 'last_purchase_price' => 5000, 'lead_time_days' => 3],
            ['supplier_id' => $add->id, 'last_purchase_price' => 7000, 'lead_time_days' => 9],
            // Baris kosong harus diabaikan, bukan menggagalkan seluruh sync.
            ['supplier_id' => '', 'last_purchase_price' => null, 'lead_time_days' => null],
        ]);

        // Dinormalkan ke int dan diurutkan: pluck lewat pivot bisa mengembalikan
        // string, dan urutan baris tidak dijamin database.
        $ids = array_map(
            'intval',
            $this->product->suppliers()->pluck('suppliers.id')->all(),
        );
        sort($ids);

        $expected = [$keep->id, $add->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertSame(7000.00, (float) $this->product->suppliers()->find($add->id)->pivot->last_purchase_price);
    }

    public function test_sync_mengabaikan_supplier_id_yang_tidak_ada(): void
    {
        $real = Supplier::factory()->create();

        $this->service->sync($this->product, [
            ['supplier_id' => $real->id, 'last_purchase_price' => 100, 'lead_time_days' => 1],
            ['supplier_id' => 999999, 'last_purchase_price' => 200, 'lead_time_days' => 2],
        ]);

        $this->assertSame([$real->id], $this->product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_summary_menandai_pemasok_tercepat(): void
    {
        $cepat = Supplier::factory()->create(['name' => 'Cepat']);
        $lambat = Supplier::factory()->create(['name' => 'Lambat']);
        $tanpaLeadTime = Supplier::factory()->create(['name' => 'Belum Ada Lead Time']);

        $this->product->suppliers()->attach($cepat->id, ['lead_time_days' => 3]);
        $this->product->suppliers()->attach($lambat->id, ['lead_time_days' => 21]);
        $this->product->suppliers()->attach($tanpaLeadTime->id, ['lead_time_days' => null]);

        $summary = $this->service->summaryFor($this->product);

        // Terurut: tercepat dulu, tanpa lead time paling akhir.
        $this->assertSame('Cepat', $summary[0]['name']);
        $this->assertTrue($summary[0]['is_fastest']);
        $this->assertFalse($summary[1]['is_fastest']);
        $this->assertSame('Belum Ada Lead Time', $summary[2]['name']);
        $this->assertNull($summary[2]['lead_time_days']);
    }

    public function test_summary_menandai_semua_pemasok_tercepat_jika_seri(): void
    {
        $a = Supplier::factory()->create();
        $b = Supplier::factory()->create();
        $this->product->suppliers()->attach([$a->id, $b->id], ['lead_time_days' => 5]);

        $summary = $this->service->summaryFor($this->product);

        $this->assertCount(2, array_filter($summary, fn (array $row): bool => $row['is_fastest']));
    }

    public function test_record_purchase_price_tidak_mengubah_lead_time(): void
    {
        $supplier = Supplier::factory()->create();
        $this->service->attach($this->product, $supplier, 1000, 14);

        $this->service->recordPurchasePrice($this->product, $supplier, 1500);

        $pivot = $this->product->suppliers()->first()->pivot;
        $this->assertSame(1500.00, (float) $pivot->last_purchase_price);
        $this->assertSame(14, (int) $pivot->lead_time_days, 'Lead time harus tetap');
    }

    public function test_inbound_dengan_harga_otomatis_memperbarui_pivot_lewat_service_yang_sama(): void
    {
        $supplier = Supplier::factory()->create();
        $movementService = new InventoryMovementService($this->service);

        $movementService->recordInbound(
            $this->product,
            50,
            $this->admin,
            $supplier->id,
            'INV-1',
            null,
            25000,
        );

        $this->assertSame(1, $this->product->suppliers()->count());
        $this->assertSame(
            25000.00,
            (float) $this->product->suppliers()->first()->pivot->last_purchase_price,
        );
    }

    public function test_lead_time_tercepat_dipakai_untuk_perhitungan_reorder(): void
    {
        $cepat = Supplier::factory()->create();
        $lambat = Supplier::factory()->create();

        $this->service->attach($this->product, $cepat, null, 2);
        $this->service->attach($this->product, $lambat, null, 30);

        $this->product->refresh();

        $this->assertSame(2, $this->product->fastestLeadTimeDays());
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    public function test_tambah_pemasok_lewat_http(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post("/products/{$this->product->id}/suppliers", [
                'supplier_id' => $supplier->id,
                'last_purchase_price' => 12000,
                'lead_time_days' => 6,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->product->suppliers()->count());
    }

    public function test_tambah_pemasok_tidak_kosong_lewat_http(): void
    {
        $this->actingAs($this->admin)
            ->post("/products/{$this->product->id}/suppliers", [
                'supplier_id' => '',
                'last_purchase_price' => 12000,
            ])
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, $this->product->suppliers()->count());
    }

    public function test_lead_time_lebih_dari_365_hari_ditolak(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post("/products/{$this->product->id}/suppliers", [
                'supplier_id' => $supplier->id,
                'lead_time_days' => 400,
            ])
            ->assertSessionHasErrors('lead_time_days');
    }

    public function test_harga_negatif_ditolak(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post("/products/{$this->product->id}/suppliers", [
                'supplier_id' => $supplier->id,
                'last_purchase_price' => -1,
            ])
            ->assertSessionHasErrors('last_purchase_price');
    }

    public function test_lepas_pemasok_lewat_http(): void
    {
        $supplier = Supplier::factory()->create();
        $this->product->suppliers()->attach($supplier->id, ['lead_time_days' => 4]);

        $this->actingAs($this->admin)
            ->delete("/products/{$this->product->id}/suppliers/{$supplier->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->product->suppliers()->count());
    }

    public function test_simpan_banyak_pemasok_sekaligus_lewat_http(): void
    {
        $a = Supplier::factory()->create();
        $b = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->put("/products/{$this->product->id}/suppliers", [
                'suppliers' => [
                    ['supplier_id' => $a->id, 'last_purchase_price' => 1000, 'lead_time_days' => 3],
                    ['supplier_id' => $b->id, 'last_purchase_price' => 2000, 'lead_time_days' => 8],
                    ['supplier_id' => null],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->product->suppliers()->count());
    }

    public function test_pemasok_duplikat_dalam_satu_form_ditolak(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->put("/products/{$this->product->id}/suppliers", [
                'suppliers' => [
                    ['supplier_id' => $supplier->id, 'lead_time_days' => 3],
                    ['supplier_id' => $supplier->id, 'lead_time_days' => 5],
                ],
            ])
            ->assertSessionHasErrors('suppliers');

        $this->assertSame(0, $this->product->suppliers()->count());
    }

    public function test_halaman_ubah_produk_menautkan_ke_manajemen_pemasok(): void
    {
        $this->actingAs($this->admin)
            ->get("/products/{$this->product->id}/edit")
            ->assertOk();
    }

    public function test_manager_boleh_mengelola_pemetaan_pemasok(): void
    {
        $manager = User::factory()->managerBisnis()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($manager)
            ->get("/products/{$this->product->id}/suppliers")
            ->assertOk();

        $this->actingAs($manager)
            ->post("/products/{$this->product->id}/suppliers", [
                'supplier_id' => $supplier->id,
                'lead_time_days' => 7,
            ])
            ->assertSessionHasNoErrors();
    }
}
