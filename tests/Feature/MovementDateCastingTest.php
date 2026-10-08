<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `movement_date` harus jadi objek Carbon sebelum masuk service.
 *
 * Aturan validasi `date` hanya memeriksa format. Nilai yang keluar dari
 * `validated()` tetap string `Y-m-d`, tapi `InventoryMovementService`
 * mendeklarasikan `?Carbon\CarbonInterface`. String mentah memicu TypeError
 * dan request berakhir **500**, bukan pesan validasi yang rapi.
 *
 * Bug ini tersembunyi selama ini karena form Vue tidak punya input tanggal,
 * jadi `movement_date` tidak pernah terkirim dari peramban dan `isset()`
 * bernilai false. Test yang ada hanya mengirim string kosong, yang diubah
 * `ConvertEmptyStringsToNull` menjadi null lalu lolos tanpa pernah menyentuh
 * jalur bermasalah. Karena itu kelas ini mengirim tanggal sungguhan dan
 * menguji ketiga mode.
 */
class MovementDateCastingTest extends TestCase
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

    private function produkDenganStok(int $stok): Product
    {
        $product = Product::factory()->create([
            'current_stock' => 0,
            'reorder_point' => 10,
        ]);

        if ($stok > 0) {
            $this->service->recordInbound($product, $stok, $this->admin, referenceCode: 'SETUP');
        }

        return $product->fresh();
    }

    // -----------------------------------------------------------------
    // Regresi: ketiga mode dengan tanggal harus TIDAK 500
    // -----------------------------------------------------------------

    public function test_inbound_dengan_tanggal_tidak_menghasilkan_500(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 30,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'supplier_id' => Supplier::factory()->create()->id,
                'reference_code' => 'PO-TANGGAL',
                'movement_date' => '2026-10-05',
            ])
            ->assertSessionHasNoErrors()
            ->assertStatus(302);

        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('2026-10-05', $movement->movement_date->format('Y-m-d'));
        $this->assertSame(30, $movement->quantity);
    }

    public function test_outbound_dengan_tanggal_tidak_menghasilkan_500(): void
    {
        $product = $this->produkDenganStok(50);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 12,
                ]],
                'movement_type' => MovementType::Outbound->value,
                'reference_code' => 'SO-TANGGAL',
                'movement_date' => '2026-10-05',
            ])
            ->assertSessionHasNoErrors()
            ->assertStatus(302);

        $movement = InventoryMovement::ofMovementType(MovementType::Outbound)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2026-10-05', $movement->movement_date->format('Y-m-d'));
        $this->assertSame(-12, $movement->quantity);
    }

    public function test_stock_opname_dengan_tanggal_tidak_menghasilkan_500(): void
    {
        $product = $this->produkDenganStok(40);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'physical_count' => 37,
                ]],
                'movement_type' => MovementType::Adjustment->value,
                'reference_code' => 'OPNAME-TANGGAL',
                'movement_date' => '2026-10-05',
            ])
            ->assertSessionHasNoErrors()
            ->assertStatus(302);

        $movement = InventoryMovement::ofMovementType(MovementType::Adjustment)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2026-10-05', $movement->movement_date->format('Y-m-d'));
        $this->assertSame(-3, $movement->quantity);
    }

    // -----------------------------------------------------------------
    // Tanggal tidak diisi tetap jatuh ke now()
    // -----------------------------------------------------------------

    public function test_tanpa_tanggal_tetap_memakai_waktu_sekarang(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'reference_code' => 'PO-NO-TANGGAL',
            ])
            ->assertSessionHasNoErrors();

        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertTrue($movement->movement_date->isToday());
    }

    /**
     * String kosong pernah menjadi jalur uji lama. `ConvertEmptyStringsToNull`
     * mengubahnya jadi null, jadi tidak boleh sampai ke service sebagai string.
     */
    public function test_tanggal_kosong_tidak_menggagalkan_entri(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 7,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'movement_date' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            7,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );
    }

    // -----------------------------------------------------------------
    // Tanggal lampau boleh dipakai, dan tidak merusak invarian ledger
    // -----------------------------------------------------------------

    public function test_entri_bertanggal_lampau_menjaga_invarian_ledger(): void
    {
        $product = $this->produkDenganStok(20);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'reference_code' => 'PO-LAMPAU',
                'movement_date' => '2026-01-15',
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame(
            (int) $product->current_stock,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );
    }

    public function test_tanggal_tidak_valid_masih_ditolak_dengan_pesan_validasi(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'movement_date' => 'bukan-tanggal',
            ])
            ->assertSessionHasErrors('movement_date');

        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());
    }
}
