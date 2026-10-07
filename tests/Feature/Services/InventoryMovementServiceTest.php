<?php

namespace Tests\Feature\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 4 — InventoryMovementService.
 *
 * Fokus test: atomicity (satu transaksi untuk stok + buku besar + audit),
 * aturan delta bertanda, dan idempotensi efek samping.
 */
class InventoryMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InventoryMovementService;
    }

    // -----------------------------------------------------------------
    // Inbound
    // -----------------------------------------------------------------

    public function test_inbound_increases_stock_and_writes_the_ledger_and_audit_trail(): void
    {
        $product = Product::factory()->create(['current_stock' => 20, 'reorder_point' => 10]);
        $admin = User::factory()->adminGudang()->create(['name' => 'Budi']);
        $supplier = Supplier::factory()->create(['name' => 'PT Sumber']);

        $movement = $this->service->recordInbound(
            product: $product,
            quantity: 80,
            user: $admin,
            supplierId: $supplier->id,
            referenceCode: 'INV-2026-001',
            unitPrice: 12500.50,
        );

        // 1. stok naik
        $this->assertSame(100, $product->fresh()->current_stock);

        // 2. buku besar: delta positif, tipe inbound
        $this->assertSame(MovementType::Inbound, $movement->movement_type);
        $this->assertSame(80, $movement->quantity);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame($supplier->id, $movement->supplier_id);
        $this->assertSame('INV-2026-001', $movement->reference_code);
        $this->assertSame($admin->id, $movement->user_id);
        $this->assertTrue($movement->product->is($product));

        // 3. jejak audit
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'inbound.create',
        ]);
        $this->assertStringContainsString('SKU-', AuditLog::where('action', 'inbound.create')->value('description'));
        $this->assertStringContainsString('Budi', AuditLog::where('action', 'inbound.create')->value('description'));
        $this->assertStringContainsString('20 menjadi 100', AuditLog::where('action', 'inbound.create')->value('description'));
    }

    public function test_inbound_records_last_purchase_price_on_the_pivot(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->service->recordInbound($product, 10, supplierId: $supplier->id, unitPrice: 9900.75);

        $product->load('suppliers');
        $pivot = $product->suppliers->firstWhere('id', $supplier->id)->pivot;

        $this->assertSame(9900.75, (float) $pivot->last_purchase_price);
    }

    public function test_a_second_purchase_updates_the_existing_pivot_row(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->service->recordInbound($product, 10, supplierId: $supplier->id, unitPrice: 1000);
        $this->service->recordInbound($product, 10, supplierId: $supplier->id, unitPrice: 2000);

        $this->assertSame(1, $product->suppliers()->count());
        $this->assertSame(2000.00, (float) $product->suppliers()->first()->pivot->last_purchase_price);
    }

    public function test_inbound_without_unit_price_does_not_create_a_pivot_row(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->service->recordInbound($product, 10, supplierId: $supplier->id);

        $this->assertSame(0, $product->suppliers()->count());
    }

    public function test_inbound_rejects_an_unknown_supplier(): void
    {
        $product = Product::factory()->create(['current_stock' => 5]);

        try {
            $this->service->recordInbound($product, 10, supplierId: 999999);
            $this->fail('Harus melempar exception untuk pemasok yang tidak ada.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('999999', $e->getMessage());
        }

        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    // -----------------------------------------------------------------
    // Outbound
    // -----------------------------------------------------------------

    public function test_outbound_decreases_stock_and_stores_a_negative_delta(): void
    {
        $product = Product::factory()->create(['current_stock' => 100, 'reorder_point' => 10]);
        $admin = User::factory()->adminGudang()->create();

        $movement = $this->service->recordOutbound(
            product: $product,
            quantity: 30,
            user: $admin,
            referenceCode: 'SO-778',
        );

        $this->assertSame(70, $product->fresh()->current_stock);
        $this->assertSame(MovementType::Outbound, $movement->movement_type);
        $this->assertSame(-30, $movement->quantity);
        $this->assertNull($movement->supplier_id, 'Outbound tidak boleh punya pemasok');
        $this->assertDatabaseHas('audit_logs', ['action' => 'outbound.create']);
    }

    public function test_outbound_is_allowed_to_bring_stock_exactly_to_zero(): void
    {
        $product = Product::factory()->create(['current_stock' => 30, 'reorder_point' => 5]);

        $this->service->recordOutbound($product, 30);

        $this->assertSame(0, $product->fresh()->current_stock);
    }

    public function test_outbound_beyond_available_stock_is_rejected_and_nothing_is_written(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'reorder_point' => 5]);

        try {
            $this->service->recordOutbound($product, 25);
            $this->fail('Harus melempar InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame($product->id, $e->productId);
            $this->assertSame(25, $e->requested);
            $this->assertSame(10, $e->available);
            $this->assertSame(15, $e->shortfall());
        }

        // Tidak boleh ada efek samping sama sekali.
        $this->assertSame(10, $product->fresh()->current_stock);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, AuditLog::count());
    }

    // -----------------------------------------------------------------
    // Stock opname
    // -----------------------------------------------------------------

    public function test_stock_opname_stores_the_difference_not_the_physical_count(): void
    {
        $product = Product::factory()->create(['current_stock' => 50, 'reorder_point' => 10]);

        $movement = $this->service->recordStockOpname($product, physicalCount: 43);

        $this->assertSame(43, $product->fresh()->current_stock);
        $this->assertSame(MovementType::Adjustment, $movement->movement_type);
        $this->assertSame(-7, $movement->quantity, 'Delta harus -7, bukan 43');
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_opname.create']);
    }

    public function test_stock_opname_can_increase_stock_when_physical_exceeds_system(): void
    {
        $product = Product::factory()->create(['current_stock' => 50]);

        $movement = $this->service->recordStockOpname($product, physicalCount: 55);

        $this->assertSame(55, $product->fresh()->current_stock);
        $this->assertSame(5, $movement->quantity);
    }

    public function test_stock_opname_with_a_matching_count_still_records_the_audit_event(): void
    {
        $product = Product::factory()->create(['current_stock' => 50]);
        $admin = User::factory()->adminGudang()->create();

        $movement = $this->service->recordStockOpname($product, 50, $admin);

        $this->assertSame(0, $movement->quantity);
        $this->assertSame(50, $product->fresh()->current_stock);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_opname.create',
            'user_id' => $admin->id,
        ]);
    }

    public function test_stock_opname_can_zero_out_a_product(): void
    {
        $product = Product::factory()->create(['current_stock' => 12]);

        $this->service->recordStockOpname($product, 0);

        $this->assertSame(0, $product->fresh()->current_stock);
    }

    public function test_stock_opname_rejects_a_negative_physical_count(): void
    {
        $product = Product::factory()->create(['current_stock' => 12]);

        $this->expectException(InventoryException::class);

        try {
            $this->service->recordStockOpname($product, -1);
        } finally {
            $this->assertSame(12, $product->fresh()->current_stock);
            $this->assertSame(0, InventoryMovement::count());
        }
    }

    // -----------------------------------------------------------------
    // Validasi input
    // -----------------------------------------------------------------

    public function test_inbound_and_outbound_reject_non_positive_quantities(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);

        foreach ([0, -5, -100] as $invalid) {
            try {
                $this->service->recordInbound($product, $invalid);
                $this->fail("Inbound dengan quantity {$invalid} seharusnya ditolak.");
            } catch (InventoryException $e) {
                $this->assertStringContainsString('lebih besar dari nol', $e->getMessage());
            }

            try {
                $this->service->recordOutbound($product, $invalid);
                $this->fail("Outbound dengan quantity {$invalid} seharusnya ditolak.");
            } catch (InventoryException $e) {
                $this->assertStringContainsString('lebih besar dari nol', $e->getMessage());
            }
        }

        $this->assertSame(10, $product->fresh()->current_stock);
    }

    public function test_inbound_rejects_a_negative_unit_price(): void
    {
        $product = Product::factory()->create();

        $this->expectException(InventoryException::class);

        $this->service->recordInbound($product, 10, unitPrice: -1);
    }

    public function test_soft_deleted_products_cannot_receive_movements(): void
    {
        $product = Product::factory()->create(['current_stock' => 30]);
        $product->delete();

        foreach ([
            fn () => $this->service->recordInbound($product, 5),
            fn () => $this->service->recordOutbound($product, 5),
            fn () => $this->service->recordStockOpname($product, 5),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Produk yang sudah di-soft-delete harus ditolak.');
            } catch (InventoryException $e) {
                $this->assertStringContainsString('tidak tersedia', $e->getMessage());
            }
        }

        $this->assertSame(0, InventoryMovement::count());
    }

    // -----------------------------------------------------------------
    // Peringatan stok menipis (UseCase: "Kirim Peringatan Stok Menipis")
    // -----------------------------------------------------------------

    public function test_outbound_triggering_the_reorder_point_raises_a_low_stock_alert(): void
    {
        $product = Product::factory()->create(['current_stock' => 25, 'reorder_point' => 20]);

        $this->service->recordOutbound($product, 10); // 25 -> 15, sudah di bawah 20

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'alert.low_stock',
        ]);
        $this->assertStringContainsString('Stok menipis', AuditLog::where('action', 'alert.low_stock')->value('description'));
        $this->assertStringContainsString('Kurang 5 unit', AuditLog::where('action', 'alert.low_stock')->value('description'));
    }

    public function test_outbound_that_keeps_stock_above_the_reorder_point_raises_no_alert(): void
    {
        $product = Product::factory()->create(['current_stock' => 100, 'reorder_point' => 20]);

        $this->service->recordOutbound($product, 10);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'alert.low_stock']);
    }

    public function test_inbound_never_raises_a_low_stock_alert_even_when_stock_is_low(): void
    {
        $product = Product::factory()->create(['current_stock' => 5, 'reorder_point' => 50]);

        $this->service->recordInbound($product, 1);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'alert.low_stock']);
    }

    // -----------------------------------------------------------------
    // Atomicity
    // -----------------------------------------------------------------

    public function test_the_whole_change_rolls_back_when_the_audit_trail_cannot_be_written(): void
    {
        $product = Product::factory()->create(['current_stock' => 40, 'reorder_point' => 5]);

        // Pasang jebakan: penulisan audit log akan meledak DI TENGAH transaksi,
        // padahal baris movement sudah ter-insert sebelum itu.
        Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
            throw new RuntimeException('Simpan audit gagal (disengaja oleh test).');
        });

        try {
            $this->service->recordInbound($product, 25, referenceCode: 'INV-ROLLBACK');
            $this->fail('Transaksi seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('disengaja oleh test', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        // Tidak boleh ada efek samping tertinggal.
        $this->assertSame(40, $product->fresh()->current_stock, 'current_stock harus kembali ke nilai awal');
        $this->assertSame(0, InventoryMovement::count(), 'Baris buku besar harus ter-rollback');
        $this->assertSame(0, AuditLog::count(), 'Jejak audit harus konsisten');
    }

    public function test_movement_date_defaults_to_now_but_can_be_overridden(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 0, 0));

        $product = Product::factory()->create(['current_stock' => 0]);

        $default = $this->service->recordInbound($product, 5);
        $this->assertSame('2026-10-07 09:00:00', $default->movement_date->format('Y-m-d H:i:s'));

        $backdated = $this->service->recordInbound($product, 5, movementDate: now()->subDays(3));
        $this->assertSame('2026-10-04 09:00:00', $backdated->movement_date->format('Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------
    // Invarian buku besar
    // -----------------------------------------------------------------

    public function test_sum_of_deltas_always_equals_current_stock(): void
    {
        $admin = User::factory()->adminGudang()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 1]);

        $this->service->recordInbound($product, 120, $admin, $supplier->id, 'IN-1');
        $this->service->recordInbound($product, 30, $admin, $supplier->id, 'IN-2');
        $this->service->recordOutbound($product, 45, $admin, referenceCode: 'OUT-1');
        $this->service->recordStockOpname($product, 90, $admin, 'OPNAME-1');
        $this->service->recordOutbound($product, 60, $admin, referenceCode: 'OUT-2');

        $ledgerSum = (int) InventoryMovement::where('product_id', $product->id)->sum('quantity');

        // 0 +120 +30 -45 +(90-105) -60 = 30
        $this->assertSame(30, $product->fresh()->current_stock);
        $this->assertSame($ledgerSum, $product->fresh()->current_stock);
    }

    public function test_audit_trail_is_written_for_every_single_movement(): void
    {
        $product = Product::factory()->create(['current_stock' => 0, 'reorder_point' => 100]);

        $this->service->recordInbound($product, 50); // 50 <= 100, tapi inbound tidak memicu peringatan
        $this->service->recordOutbound($product, 10); // 40 <= 100 -> peringatan
        $this->service->recordStockOpname($product, 40);

        $actions = AuditLog::orderBy('id')->pluck('action')->all();

        $this->assertSame(
            ['inbound.create', 'outbound.create', 'alert.low_stock', 'stock_opname.create'],
            $actions,
        );
    }
}
