<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pencatatan pergerakan stok lewat HTTP.
 *
 * Sejak tahap 3 satu request berarti satu FAKTUR: baris produk dikemas dalam
 * `items`. Ini bentuk yang dikirim form multi-baris.
 */
class WarehouseMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
    }

    public function test_mencatat_inbound(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'supplier_id' => $supplier->id,
                'reference_code' => 'INV-2026-9',
                'items' => [['product_id' => $product->id, 'quantity' => 40, 'unit_price' => 1500.50]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(50, $product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_movements', [
            'quantity' => 40,
            'reference_code' => 'INV-2026-9',
            'unit_price' => 1500.50,
        ]);

        // Jenis pergerakan tinggal di dokumen sejak tahap 4. Memperiksanya di
        // baris detail hanya akan "lulus" karena kolomnya tidak ada — bukan
        // karena data benar.
        $this->assertDatabaseHas('inventory_transactions', ['movement_type' => 'inbound']);
        $this->assertSame(1500.50, (float) $product->suppliers()->first()->pivot->last_purchase_price);
    }

    public function test_satu_faktur_bisa_memuat_banyak_produk(): void
    {
        $a = Product::factory()->create(['current_stock' => 0]);
        $b = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'reference_code' => 'PO-MULTI',
                'items' => [
                    ['product_id' => $a->id, 'quantity' => 10],
                    ['product_id' => $b->id, 'quantity' => 25],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $a->fresh()->current_stock);
        $this->assertSame(25, $b->fresh()->current_stock);

        // Satu dokumen, dua baris detail.
        $this->assertSame(2, InventoryMovement::count());
        $this->assertSame(1, InventoryTransaction::count());

        $trx = InventoryTransaction::firstOrFail();

        $this->assertSame(2, $trx->movements()->count());
        $this->assertSame('PO-MULTI', $trx->physical_reference);
    }

    public function test_mencatat_outbound_mengurangi_stok(): void
    {
        $product = Product::factory()->create(['current_stock' => 60]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'outbound',
                'reference_code' => 'SO-77',
                'items' => [['product_id' => $product->id, 'quantity' => 25]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(35, $product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_movements', ['quantity' => -25]);
        $this->assertDatabaseHas('inventory_transactions', ['movement_type' => 'outbound']);
    }

    public function test_outbound_melebihi_stok_ditolak_sebagai_error_validasi(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'outbound',
                'items' => [['product_id' => $product->id, 'quantity' => 99]],
            ])
            ->assertSessionHasErrors('items.0.quantity');

        // Pesan harus menyebut stok tersedia, bukan generic error 500.
        $this->assertStringContainsString(
            'tidak cukup',
            session('errors')->first('items.0.quantity'),
        );

        $this->assertSame(10, $product->fresh()->current_stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_produk_yang_sama_didua_ditolak(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5],
                    ['product_id' => $product->id, 'quantity' => 7],
                ],
            ])
            ->assertSessionHasErrors('items.1.product_id');

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_stock_opname_menggunakan_jumlah_fisik(): void
    {
        $product = Product::factory()->create(['current_stock' => 50]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'adjustment',
                'reference_code' => 'OPNAME-1',
                'items' => [['product_id' => $product->id, 'physical_count' => 44]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(44, $product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_movements', ['quantity' => -6]);
        $this->assertDatabaseHas('inventory_transactions', ['movement_type' => 'adjustment']);
    }

    public function test_baris_tanpa_produk_ditolak(): void
    {
        $product = Product::factory()->create(['current_stock' => 50]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'adjustment',
                'items' => [['product_id' => $product->id, 'physical_count' => null]],
            ])
            ->assertSessionHasErrors('items.0.physical_count');
    }

    public function test_faktur_kosong_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [],
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_jumlah_nol_ditolak(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [['product_id' => $product->id, 'quantity' => 0]],
            ])
            ->assertSessionHasErrors('items.0.quantity');
    }

    public function test_produk_tidak_ada_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [['product_id' => 999999, 'quantity' => 5]],
            ])
            ->assertSessionHasErrors('items.0.product_id');
    }

    public function test_jenis_bergerak_tidak_dikenal_ditolak(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'transfer',
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
            ])
            ->assertSessionHasErrors('movement_type');
    }

    public function test_harga_negatif_ditolak(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => -100]],
            ])
            ->assertSessionHasErrors('items.0.unit_price');
    }

    public function test_invarian_ledger_terjaga(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);
        $lain = Product::factory()->create(['current_stock' => 0]);

        $this->actingAs($this->admin)
            ->post('/inventory/movements', [
                'movement_type' => 'inbound',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 30],
                    ['product_id' => $lain->id, 'quantity' => 12],
                ],
            ])
            ->assertSessionHasNoErrors();

        foreach ([$product, $lain] as $p) {
            $this->assertSame(
                $p->fresh()->current_stock,
                (int) InventoryMovement::where('product_id', $p->id)->sum('quantity'),
            );
        }
    }
}
