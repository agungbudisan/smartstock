<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Waktu transaksi boleh diisi sendiri, terpisah dari waktu input.
 *
 * Sebelum kolom ini ada, `movement_date` selalu jatuh ke `now()`, jadi
 * identik dengan `created_at`. Di gudang sungguhan barang sering tiba jam
 * 14:00 lalu dicatat jam 16:00, atau dokumen kemarin baru dimasukan hari
 * ini — dua hal yang wajar tapi tidak bisa direpresentasikan kalau tanggalnya
 * dikunci ke waktu sekarang.
 *
 * Form mengirim dua input terpisah (`tanggal` + `waktu`) dan
 * `StoreMovementRequest::prepareForValidation()` menggabungkannya, supaya
 * controller tetap menerima satu `movement_date` utuh.
 */
class MovementDateTimeInputTest extends TestCase
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

    private function movementTerbaru(int $productId): InventoryMovement
    {
        return InventoryMovement::where('product_id', $productId)
            ->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Gabungan tanggal + jam
    // -----------------------------------------------------------------

    public function test_tanggal_dan_jam_digabung_jadi_movement_date(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '2026-09-15',
                'waktu' => '14:30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-09-15 14:30:00',
            $this->movementTerbaru($product->id)->movement_date->toDateTimeString(),
        );
    }

    public function test_tanggal_saja_tidak_boleh_dan_tidak_dikira_malam(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '2026-09-15',
                'waktu' => '',
            ])
            ->assertSessionHasErrors('waktu');

        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_jam_saja_tidak_boleh(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '',
                'waktu' => '14:30',
            ])
            ->assertSessionHasErrors('tanggal');

        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_keduanya_kosong_tetap_berhasil_dengan_waktu_sekarang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 11:00:00'));

        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '',
                'waktu' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->movementTerbaru($product->id)->movement_date->isToday());

        Carbon::setTestNow();
    }

    // -----------------------------------------------------------------
    // Batas masa depan
    // -----------------------------------------------------------------

    public function test_tanggal_masa_depan_ditolak(): void
    {
        $besok = now()->addDay()->format('Y-m-d');

        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => $besok,
                'waktu' => '10:00',
            ])
            ->assertSessionHasErrors('tanggal');

        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_hari_ini_tetap_boleh(): void
    {
        $product = $this->produkDenganStok(0);

        // Waktu dibekukan ke tengah hari. Tanpa ini test ini GAGAL tepat setelah
        // tengah malam: "hari ini jam 08:00" sebenarnya masih di masa depan
        // kalau sekarang 00:01, dan aturan `before_or_equal` menolaknya dengan
        // benar. Testnya bukan salah aturan — testnya yang bergantung pada jam
        // dinding. Pola yang sama dipakai test lain di kelas ini.
        Carbon::setTestNow(Carbon::parse(now()->format('Y-m-d').' 12:00:00'));

        try {
            $this->actingAs($this->admin)
                ->from('/inventory/movements')
                ->post('/inventory/movements', [
                    'items' => [[
                        'product_id' => $product->id,
                        'quantity' => 10,
                    ]],
                    'movement_type' => MovementType::Inbound->value,
                    'tanggal' => now()->format('Y-m-d'),
                    'waktu' => '08:00',
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                now()->format('Y-m-d').' 08:00:00',
                $this->movementTerbaru($product->id)->movement_date->toDateTimeString(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_backdate_boleh_berkepanjangan(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => now()->subMonths(2)->format('Y-m-d'),
                'waktu' => '09:15',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            now()->subMonths(2)->format('Y-m-d').' 09:15:00',
            $this->movementTerbaru($product->id)->movement_date->toDateTimeString(),
        );
    }

    // -----------------------------------------------------------------
    // Format salah
    // -----------------------------------------------------------------

    public function test_tanggal_dengan_format_salah_ditolak(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '15-09-2026',
                'waktu' => '14:30',
            ])
            ->assertSessionHasErrors('tanggal');
    }

    public function test_jam_dengan_format_salah_ditolak(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '2026-09-15',
                'waktu' => '14.30',
            ])
            ->assertSessionHasErrors('waktu');
    }

    // -----------------------------------------------------------------
    // Ketiga mode
    // -----------------------------------------------------------------

    public function test_berlaku_untuk_outbound(): void
    {
        $product = $this->produkDenganStok(50);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
                'movement_type' => MovementType::Outbound->value,
                'tanggal' => '2026-09-10',
                'waktu' => '16:45',
            ])
            ->assertSessionHasNoErrors();

        $movement = InventoryMovement::ofMovementType(MovementType::Outbound)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2026-09-10 16:45:00', $movement->movement_date->toDateTimeString());
    }

    public function test_berlaku_untuk_stock_opname(): void
    {
        $product = $this->produkDenganStok(30);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'physical_count' => 28,
                ]],
                'movement_type' => MovementType::Adjustment->value,
                'tanggal' => '2026-09-30',
                'waktu' => '21:00',
            ])
            ->assertSessionHasNoErrors();

        $movement = InventoryMovement::ofMovementType(MovementType::Adjustment)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2026-09-30 21:00:00', $movement->movement_date->toDateTimeString());
    }

    // -----------------------------------------------------------------
    // Pemulihan isian setelah validasi gagal
    // -----------------------------------------------------------------

    public function test_tanggal_dan_jam_pulih_setelah_validasi_gagal(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'movement_type' => MovementType::Inbound->value,
                'items' => [['product_id' => $product->id, 'quantity' => 0]],
                'tanggal' => '2026-09-15',
                'waktu' => '14:30',
            ])
            ->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($this->admin)
            ->get('/inventory/movements')
            ->assertInertia(fn ($page) => $page
                ->where('oldMovement.tanggal', '2026-09-15')
                ->where('oldMovement.waktu', '14:30')
                // Baris produk juga harus pulih, kalau tidak operator mengetik
                // ulang seluruh faktur hanya karena satu baris salah.
                ->where('oldMovement.items.0.product_id', $product->id)
            );
    }

    // -----------------------------------------------------------------
    // Invarian
    // -----------------------------------------------------------------

    public function test_backdate_tidak_mengganggu_invarian_ledger(): void
    {
        $product = $this->produkDenganStok(20);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 15,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'tanggal' => '2026-01-15',
                'waktu' => '07:30',
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame(35, $product->current_stock);
        $this->assertSame(
            35,
            (int) InventoryMovement::where('product_id', $product->id)->sum('quantity'),
        );
    }

    // -----------------------------------------------------------------
    // Kompatibilitas lama
    // -----------------------------------------------------------------

    /**
     * Klien yang mengirim `movement_date` langsung (smoke test, skrip,
     * integrasi masa depan) harus tetap jalan — `prepareForValidation()`
     * hanya menambahkan pasangan kalau keduanya ada.
     */
    public function test_movement_date_langsung_masih_diterima(): void
    {
        $product = $this->produkDenganStok(0);

        $this->actingAs($this->admin)
            ->from('/inventory/movements')
            ->post('/inventory/movements', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                ]],
                'movement_type' => MovementType::Inbound->value,
                'movement_date' => '2026-09-15 14:30:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-09-15 14:30:00',
            $this->movementTerbaru($product->id)->movement_date->toDateTimeString(),
        );
    }
}
