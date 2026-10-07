<?php

namespace Tests\Feature\Jobs;

use App\Enums\MovementType;
use App\Jobs\AggregateDailyOutboundJob;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5 — AggregateDailyOutboundJob.
 *
 * Waktu dibekukan supaya tanggal default ("kemarin") deterministik.
 */
class AggregateDailyOutboundJobTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    private CarbonImmutable $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::parse('2026-10-08 07:00:00');
        CarbonImmutable::setTestNow($this->today);
        $this->yesterday = $this->today->subDay();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  int  $quantity  positif = outbound, negatif = inbound
     */
    private function movement(
        Product $product,
        int $quantity,
        string $movementType,
        string $at,
    ): InventoryMovement {
        return InventoryMovement::create([
            'product_id' => $product->id,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'movement_date' => $at,
        ]);
    }

    private function outbound(Product $product, int $absQuantity, string $at): InventoryMovement
    {
        return $this->movement($product, -$absQuantity, MovementType::Outbound->value, $at);
    }

    private function totalFor(int $productId, string $date): ?int
    {
        $row = DailyOutboundAggregation::query()
            ->where('product_id', $productId)
            ->whereDate('date', $date)
            ->first();

        return $row?->total_qty_out;
    }

    // -----------------------------------------------------------------

    public function test_it_sums_outbound_quantities_per_product_per_day(): void
    {
        $product = Product::factory()->create();

        $this->outbound($product, 10, '2026-10-07 09:00:00');
        $this->outbound($product, 15, '2026-10-07 14:00:00');
        $this->outbound($product, 7, '2026-10-07 20:00:00');

        $written = (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(1, $written);
        $this->assertSame(32, $this->totalFor($product->id, '2026-10-07'));
    }

    public function test_it_ignores_inbound_and_stock_opname(): void
    {
        $product = Product::factory()->create();

        $this->outbound($product, 10, '2026-10-07 09:00:00');
        $this->movement($product, 500, MovementType::Inbound->value, '2026-10-07 10:00:00');
        $this->movement($product, -3, MovementType::Adjustment->value, '2026-10-07 11:00:00');

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(10, $this->totalFor($product->id, '2026-10-07'), 'Hanya outbound yang dihitung');
    }

    public function test_it_separates_products_and_days(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $this->outbound($a, 10, '2026-10-06 09:00:00');
        $this->outbound($a, 5, '2026-10-07 09:00:00');
        $this->outbound($b, 20, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob(lookbackDays: 2))->handle();

        $this->assertSame(10, $this->totalFor($a->id, '2026-10-06'));
        $this->assertSame(5, $this->totalFor($a->id, '2026-10-07'));
        $this->assertSame(20, $this->totalFor($b->id, '2026-10-07'));
        $this->assertSame(3, DailyOutboundAggregation::count());
    }

    public function test_it_defaults_to_yesterday(): void
    {
        $product = Product::factory()->create();

        $this->outbound($product, 12, '2026-10-07 09:00:00'); // kemarin
        $this->outbound($product, 99, '2026-10-08 09:00:00'); // hari ini -> diabaikan

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(1, DailyOutboundAggregation::count());
        $this->assertSame(12, $this->totalFor($product->id, '2026-10-07'));
        $this->assertNull($this->totalFor($product->id, '2026-10-08'));
    }

    public function test_it_accepts_an_explicit_date(): void
    {
        $product = Product::factory()->create();
        $this->outbound($product, 30, '2026-10-01 09:00:00');

        (new AggregateDailyOutboundJob(forDate: '2026-10-01'))->handle();

        $this->assertSame(30, $this->totalFor($product->id, '2026-10-01'));
    }

    public function test_it_includes_movements_at_both_ends_of_the_day(): void
    {
        $product = Product::factory()->create();

        $this->outbound($product, 3, '2026-10-07 00:00:00');   // tepat tengah malam
        $this->outbound($product, 4, '2026-10-07 23:59:59');   // detik terakhir

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(7, $this->totalFor($product->id, '2026-10-07'));
    }

    public function test_it_ignores_movements_outside_the_range(): void
    {
        $product = Product::factory()->create();

        $this->outbound($product, 10, '2026-10-05 09:00:00');
        $this->outbound($product, 10, '2026-10-07 09:00:00');
        $this->outbound($product, 10, '2026-10-09 09:00:00');

        (new AggregateDailyOutboundJob(lookbackDays: 2))->handle();

        $this->assertSame(1, DailyOutboundAggregation::count());
        $this->assertSame(10, $this->totalFor($product->id, '2026-10-07'));
        $this->assertNull($this->totalFor($product->id, '2026-10-05'));
        $this->assertNull($this->totalFor($product->id, '2026-10-09'));
    }

    // -----------------------------------------------------------------
    // Idempotensi
    // -----------------------------------------------------------------

    public function test_running_it_twice_updates_instead_of_duplicating(): void
    {
        $product = Product::factory()->create();
        $this->outbound($product, 10, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob)->handle();
        $this->assertSame(1, DailyOutboundAggregation::count());

        // Barang keluar tambahan setelah agregasi pertama jalan.
        $this->outbound($product, 8, '2026-10-07 21:00:00');

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(1, DailyOutboundAggregation::count(), 'Tidak boleh ada baris duplikat');
        $this->assertSame(18, $this->totalFor($product->id, '2026-10-07'));
    }

    public function test_it_updates_the_existing_row_rather_than_inserting_a_new_one(): void
    {
        $product = Product::factory()->create();
        $this->outbound($product, 10, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob)->handle();
        $firstId = DailyOutboundAggregation::first()->id;

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame($firstId, DailyOutboundAggregation::first()->id, 'Baris yang sama harus diperbarui');
    }

    public function test_it_prunes_aggregates_whose_movements_are_gone(): void
    {
        $product = Product::factory()->create();
        $movement = $this->outbound($product, 10, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob)->handle();
        $this->assertSame(1, DailyOutboundAggregation::count());

        // Movements dihapus (mis. koreksi data), agregasi harus ikut hilang.
        $movement->forceDelete();

        (new AggregateDailyOutboundJob)->handle();

        $this->assertSame(0, DailyOutboundAggregation::count());
    }

    public function test_pruning_never_touches_rows_outside_the_range(): void
    {
        $product = Product::factory()->create();

        // Agregasi lama di luar rentang backfill.
        DailyOutboundAggregation::factory()->create([
            'product_id' => $product->id,
            'date' => '2026-01-05',
            'total_qty_out' => 50,
        ]);
        $this->outbound($product, 10, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob(lookbackDays: 2))->handle();

        $this->assertSame(2, DailyOutboundAggregation::count());
        $this->assertSame(50, $this->totalFor($product->id, '2026-01-05'), 'Baris di luar rentang harus utuh');
    }

    // -----------------------------------------------------------------
    // Backfill
    // -----------------------------------------------------------------

    public function test_it_can_backfill_a_range_of_days_in_one_run(): void
    {
        $product = Product::factory()->create();

        foreach (range(1, 5) as $daysAgo) {
            $this->outbound($product, $daysAgo * 2, '2026-10-0'.$daysAgo.' 09:00:00');
        }

        $written = (new AggregateDailyOutboundJob(forDate: '2026-10-07', lookbackDays: 7))->handle();

        $this->assertSame(5, $written);
        $this->assertSame(2, $this->totalFor($product->id, '2026-10-01'));
        $this->assertSame(10, $this->totalFor($product->id, '2026-10-05'));
    }

    public function test_backfill_is_also_idempotent(): void
    {
        $product = Product::factory()->create();
        $this->outbound($product, 6, '2026-10-06 09:00:00');

        $job = fn () => (new AggregateDailyOutboundJob(forDate: '2026-10-07', lookbackDays: 7))->handle();

        $job();
        $job();
        $job();

        $this->assertSame(1, DailyOutboundAggregation::count());
        $this->assertSame(6, $this->totalFor($product->id, '2026-10-06'));
    }

    // -----------------------------------------------------------------

    public function test_it_handles_products_with_no_outbound_at_all(): void
    {
        Product::factory()->create(); // produk tanpa movements sama sekali

        $this->assertSame(0, (new AggregateDailyOutboundJob)->handle());
        $this->assertSame(0, DailyOutboundAggregation::count());
    }

    public function test_the_stored_date_is_readable_through_the_model_cast(): void
    {
        $product = Product::factory()->create();
        $this->outbound($product, 10, '2026-10-07 09:00:00');

        (new AggregateDailyOutboundJob)->handle();

        $aggregation = DailyOutboundAggregation::first();

        $this->assertSame('2026-10-07', $aggregation->date->toDateString());
        $this->assertIsInt($aggregation->total_qty_out);
    }
}
