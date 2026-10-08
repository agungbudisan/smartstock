<?php

namespace Tests\Feature\Models;

use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 3 — Regression test untuk model, cast, dan relasi Eloquent.
 */
class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Kontrak dasar tiap model
    // ------------------------------------------------------------------

    public function test_every_table_has_a_matching_model_and_table_name(): void
    {
        $expected = [
            Category::class => 'categories',
            Product::class => 'products',
            Supplier::class => 'suppliers',
            InventoryMovement::class => 'inventory_movements',
            AuditLog::class => 'audit_logs',
            DailyOutboundAggregation::class => 'daily_outbound_aggregations',
            MonthlyDemandPrediction::class => 'monthly_demand_predictions',
        ];

        foreach ($expected as $model => $table) {
            $instance = new $model;

            $this->assertSame($table, $instance->getTable(), $model.' memakai nama tabel yang salah');
            $this->assertFalse($instance->exists);
        }
    }

    public function test_only_product_uses_soft_deletes(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Product::class));

        foreach ([Category::class, Supplier::class, InventoryMovement::class, AuditLog::class] as $model) {
            $this->assertNotContains(SoftDeletes::class, class_uses_recursive($model), $model.' seharusnya tidak soft delete');
        }
    }

    // ------------------------------------------------------------------
    // Casts
    // ------------------------------------------------------------------

    public function test_inventory_movement_casts_to_php_enums_and_dates(): void
    {
        $movement = InventoryMovement::factory()->inbound(120)->create([
            'movement_date' => '2026-10-05 08:30:00',
        ]);

        // `movement_type` TIDAK lagi ada di baris detail (tahap 4), jadi yang di-cast
        // ke enum dibaca dari dokumennya.
        $this->assertInstanceOf(MovementType::class, $movement->transaction->movement_type);
        $this->assertSame(MovementType::Inbound, $movement->transaction->movement_type);
        $this->assertIsInt($movement->quantity);
        $this->assertSame(120, $movement->quantity);
        $this->assertInstanceOf(Carbon::class, $movement->movement_date);
        $this->assertSame('2026-10-05 08:30:00', $movement->movement_date->format('Y-m-d H:i:s'));
    }

    public function test_user_casts_role_to_enum(): void
    {
        $user = User::factory()->managerBisnis()->create();

        $this->assertInstanceOf(UserRole::class, $user->role);
        $this->assertSame(UserRole::ManagerBisnis, $user->role);
        $this->assertTrue($user->isManagerBisnis());
        $this->assertFalse($user->isAdminGudang());
        $this->assertTrue($user->hasRole(UserRole::ManagerBisnis, UserRole::AdminGudang));
        $this->assertFalse($user->hasRole(UserRole::AdminGudang));
    }

    public function test_user_role_can_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Mass Assigned',
            'email' => 'mass@example.test',
            'password' => 'rahasia123',
            'role' => UserRole::ManagerBisnis,
        ]);

        $this->assertSame(UserRole::ManagerBisnis, $user->fresh()->role);
    }

    public function test_monthly_prediction_casts_month_to_date_and_score_to_decimal(): void
    {
        $prediction = MonthlyDemandPrediction::factory()->create([
            'target_month' => '2026-11-01',
            'predicted_qty' => 250,
            'confidence_score' => 87.255,
        ]);

        $this->assertInstanceOf(Carbon::class, $prediction->target_month);
        $this->assertSame('2026-11-01', $prediction->target_month->toDateString());
        $this->assertIsInt($prediction->predicted_qty);
        $this->assertSame('87.26', $prediction->confidence_score);
        $this->assertInstanceOf(Carbon::class, $prediction->calculated_at);
    }

    public function test_daily_aggregation_casts_date_and_quantity(): void
    {
        $aggregation = DailyOutboundAggregation::factory()->create([
            'date' => '2026-10-06',
            'total_qty_out' => 42,
        ]);

        $this->assertSame('2026-10-06', $aggregation->date->toDateString());
        $this->assertIsInt($aggregation->total_qty_out);
    }

    public function test_audit_log_is_append_only_and_has_no_updated_at(): void
    {
        $log = AuditLog::factory()->create(['action' => 'inbound.create']);

        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertNotNull($log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());

        // Update tetap tidak merusak kolom waktu.
        $log->update(['description' => 'diubah']);
        $this->assertSame('diubah', $log->fresh()->description);
    }

    // ------------------------------------------------------------------
    // Relasi basics
    // ------------------------------------------------------------------

    public function test_category_has_many_products(): void
    {
        $category = Category::factory()->create();
        $products = Product::factory()->count(3)->kategori($category)->create();

        $this->assertInstanceOf(HasMany::class, $category->products());
        $this->assertCount(3, $category->products);
        $this->assertCount(3, $products->pluck('category_id')->filter(fn ($id) => $id === $category->id));
    }

    public function test_product_belongs_to_optional_category(): void
    {
        $product = Product::factory()->tanpaKategori()->create();

        $this->assertInstanceOf(BelongsTo::class, $product->category());
        $this->assertNull($product->category);
        $this->assertNull($product->category_id);
    }

    public function test_supplier_reaches_movements_through_documents(): void
    {
        $supplier = Supplier::factory()->create();

        // Dua baris inbound dari pemasok ini. Tiap panggilan factory membuat
        // dokumen sendiri, jadi dua baris = dua dokumen.
        InventoryMovement::factory()->inbound(50, $supplier)->count(2)->create();

        // Relasi langsungnya ke dokumen.
        $this->assertInstanceOf(HasMany::class, $supplier->inventoryTransactions());
        $this->assertCount(2, $supplier->inventoryTransactions);

        // Dan jalur dua hop ke baris detail, lewat dokumen.
        $this->assertInstanceOf(HasManyThrough::class, $supplier->inventoryMovements());
        $this->assertCount(2, $supplier->inventoryMovements);
    }

    public function test_user_has_many_movements_and_audit_logs(): void
    {
        $user = User::factory()->adminGudang()->create();
        InventoryTransaction::factory()->count(2)->create(['user_id' => $user->id]);
        AuditLog::factory()->count(3)->create(['user_id' => $user->id]);

        $this->assertInstanceOf(HasMany::class, $user->inventoryTransactions());
        $this->assertInstanceOf(HasMany::class, $user->auditLogs());
        $this->assertCount(2, $user->inventoryTransactions);
        $this->assertCount(3, $user->auditLogs);
    }

    public function test_movement_resolves_product_and_its_document(): void
    {
        $supplier = Supplier::factory()->create();
        $movement = InventoryMovement::factory()->inbound(40, $supplier)->create();

        $this->assertInstanceOf(BelongsTo::class, $movement->product());
        $this->assertInstanceOf(Product::class, $movement->product);
        $this->assertInstanceOf(InventoryTransaction::class, $movement->transaction);

        // Pencatat dan pemasok milik DOKUMEN, bukan baris detail — relasi
        // `user()` / `supplier()` di baris detail dihapus di tahap 4. Relasi
        // yang dibiarkan hidup sementara kolomnya hilang akan mengembalikan
        // null ke setiap pemanggil tanpa error, jadi halaman bisa tampil "benar"
        // sambil menampilkan kolom kosong di mana-mana.
        $this->assertInstanceOf(User::class, $movement->transaction->user);
        $this->assertInstanceOf(Supplier::class, $movement->transaction->supplier);
    }

    public function test_relasi_yang_telah_dihapus_tidak_lagi_ada_di_baris_detail(): void
    {
        // Dikunci secara eksplisit: methods yang dihapus tidak boleh "dikembalikan
        // diam-diam" oleh eager-load yang salah atau proxy Eloquent.
        $movement = InventoryMovement::factory()->outbound(5)->create();

        $this->assertFalse(method_exists($movement, 'user'), 'Relasi user() sudah dihapus di tahap 4');
        $this->assertFalse(method_exists($movement, 'supplier'), 'Relasi supplier() sudah dihapus di tahap 4');
        $this->assertInstanceOf(Product::class, $movement->product);
    }

    public function test_product_has_many_analytical_tables(): void
    {
        $product = Product::factory()->create();
        DailyOutboundAggregation::factory()
            ->count(2)
            ->sequence(
                ['date' => '2026-10-05'],
                ['date' => '2026-10-06'],
            )
            ->create(['product_id' => $product->id]);
        MonthlyDemandPrediction::factory()
            ->count(2)
            ->sequence(
                ['target_month' => '2026-11-01'],
                ['target_month' => '2026-12-01'],
            )
            ->create(['product_id' => $product->id]);

        $this->assertInstanceOf(HasMany::class, $product->dailyOutboundAggregations());
        $this->assertInstanceOf(HasMany::class, $product->monthlyDemandPredictions());
        $this->assertCount(2, $product->dailyOutboundAggregations);
        $this->assertCount(2, $product->monthlyDemandPredictions);

        $aggregation = DailyOutboundAggregation::find($product->dailyOutboundAggregations->first()->id);
        $this->assertInstanceOf(BelongsTo::class, $aggregation->product());
        $this->assertTrue($aggregation->product->is($product));
    }

    // ------------------------------------------------------------------
    // Relasi many-to-many + pivot
    // ------------------------------------------------------------------

    public function test_product_and_supplier_are_many_to_many_with_pivot_columns(): void
    {
        $product = Product::factory()->create();
        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();

        $product->suppliers()->attach($supplierA->id, [
            'last_purchase_price' => 12500.50,
            'lead_time_days' => 7,
        ]);
        $product->suppliers()->attach($supplierB->id, [
            'last_purchase_price' => 13100.00,
            'lead_time_days' => 3,
        ]);

        $this->assertInstanceOf(BelongsToMany::class, $product->suppliers());
        $this->assertInstanceOf(BelongsToMany::class, $supplierA->products());

        $product->load('suppliers');
        $this->assertCount(2, $product->suppliers);

        $pivotA = $product->suppliers->firstWhere('id', $supplierA->id)->pivot;
        // Nilai pivot dibaca apa adanya dari driver, jadi dibandingkan sebagai
        // float agar sama-sama lulus di MySQL (string "12500.50") dan SQLite.
        $this->assertSame(12500.50, (float) $pivotA->last_purchase_price);
        $this->assertSame(7, (int) $pivotA->lead_time_days);
        $this->assertNotNull($pivotA->created_at, 'withTimestamps() harus mengisi pivot');
        $this->assertNotNull($pivotA->updated_at);
    }

    public function test_pivot_can_be_synced_updated_and_detached(): void
    {
        $product = Product::factory()->create();
        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();

        $product->suppliers()->sync([
            $supplierA->id => ['last_purchase_price' => 10000, 'lead_time_days' => 5],
            $supplierB->id => ['last_purchase_price' => 11000, 'lead_time_days' => 10],
        ]);
        $this->assertCount(2, $product->suppliers()->get());

        // updateExistingPivot = perbarui baris pivot yang sudah ada.
        $product->suppliers()->updateExistingPivot($supplierA->id, [
            'last_purchase_price' => 9900,
            'lead_time_days' => 2,
        ]);

        $product->load('suppliers');
        $this->assertSame(9900.00, (float) $product->suppliers->firstWhere('id', $supplierA->id)->pivot->last_purchase_price);

        $product->suppliers()->detach($supplierB->id);
        $this->assertCount(1, $product->fresh()->suppliers()->get());
    }

    public function test_product_can_query_pivot_columns_with_wherepivot(): void
    {
        $product = Product::factory()->create();
        $murah = Supplier::factory()->create();
        $mahal = Supplier::factory()->create();

        $product->suppliers()->attach($murah->id, ['last_purchase_price' => 9000, 'lead_time_days' => 14]);
        $product->suppliers()->attach($mahal->id, ['last_purchase_price' => 15000, 'lead_time_days' => 2]);

        $fast = $product->suppliers()->wherePivot('lead_time_days', '<=', 3)->first();

        $this->assertNotNull($fast);
        $this->assertTrue($fast->is($mahal));
    }

    public function test_fastest_lead_time_days_uses_pivot_and_ignores_missing_values(): void
    {
        $product = Product::factory()->create();
        $lambat = Supplier::factory()->create();
        $cepat = Supplier::factory()->create();
        $tanpaEstimasi = Supplier::factory()->create();

        $product->suppliers()->attach($lambat->id, ['lead_time_days' => 21]);
        $product->suppliers()->attach($cepat->id, ['lead_time_days' => 3]);
        $product->suppliers()->attach($tanpaEstimasi->id, ['lead_time_days' => null]);

        $product->load('suppliers');

        $this->assertSame(3, $product->fastestLeadTimeDays());
    }

    public function test_fastest_lead_time_days_is_null_without_suppliers(): void
    {
        $this->assertNull(Product::factory()->create()->fastestLeadTimeDays());
    }

    // ------------------------------------------------------------------
    // Soft delete
    // ------------------------------------------------------------------

    public function test_soft_deleting_a_product_hides_it_but_keeps_the_ledger(): void
    {
        $product = Product::factory()->create(['current_stock' => 100]);
        InventoryMovement::factory()->outbound(10)->count(2)->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertSoftDeleted($product);
        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, Product::count());
        $this->assertSame(1, Product::withTrashed()->count());

        // Buku besar tidak ikut terhapus: halaman detail produk masih bisa
        // menampilkan histori (harus pakai withTrashed()).
        $this->assertSame(2, $product->inventoryMovements()->count());

        $movement = $product->inventoryMovements()->first();
        $this->assertNull($movement->product, 'Produk soft-deleted tidak terlihat tanpa withTrashed()');
        $this->assertTrue($movement->product()->withTrashed()->first()->is($product));
    }

    public function test_soft_deleted_product_can_be_restored(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->assertSame(0, Product::count());

        $product->restore();

        $this->assertNotNull(Product::find($product->id));
        $this->assertNull($product->fresh()->deleted_at);
    }

    public function test_category_products_relation_excludes_soft_deleted_products(): void
    {
        $category = Category::factory()->create();
        $active = Product::factory()->kategori($category)->create();
        $trashed = Product::factory()->kategori($category)->create();
        $trashed->delete();

        $this->assertCount(1, $category->products);
        // withTrashed() mematikan global scope SoftDeletes, jadi produk yang
        // di-soft-delete ikut muncul lagi — itulah cara membaca katalog arsip.
        $this->assertCount(2, $category->products()->withTrashed()->get());
        $this->assertTrue($category->products->first()->is($active));
    }

    // ------------------------------------------------------------------
    // Scope & helper domain
    // ------------------------------------------------------------------

    public function test_needing_reorder_scope_selects_products_at_or_below_reorder_point(): void
    {
        $aman = Product::factory()->create(['current_stock' => 200, 'reorder_point' => 50]);
        Product::factory()->create(['current_stock' => 50, 'reorder_point' => 50]); // tepat di batas
        Product::factory()->create(['current_stock' => 10, 'reorder_point' => 50]);
        Product::factory()->create(['current_stock' => 0, 'reorder_point' => 50]);

        $results = Product::needingReorder()->pluck('id');

        $this->assertCount(3, $results);
        $this->assertFalse($results->contains($aman->id));
    }

    public function test_needing_reorder_excludes_soft_deleted_products(): void
    {
        $product = Product::factory()->diBawahReorderPoint()->create();
        $product->delete();

        $this->assertCount(0, Product::needingReorder()->get());
        $this->assertCount(1, Product::needingReorder()->withTrashed()->get());
    }

    public function test_needs_reorder_helper(): void
    {
        $this->assertTrue(Product::factory()->diBawahReorderPoint()->create()->needsReorder());
        $this->assertTrue(Product::factory()->diBatasReorder()->create()->needsReorder());
        $this->assertFalse(Product::factory()->create(['current_stock' => 500, 'reorder_point' => 50])->needsReorder());
    }

    public function test_movement_scopes(): void
    {
        $lalu = InventoryMovement::factory()->outbound(3)->create(['movement_date' => '2026-09-30 12:00:00']);
        $kemarin = InventoryMovement::factory()->outbound(7)->create(['movement_date' => '2026-10-05 09:00:00']);
        $inbound = InventoryMovement::factory()->inbound(50)->create(['movement_date' => '2026-10-05 10:00:00']);

        $this->assertEqualsCanonicalizing(
            [$lalu->id, $kemarin->id],
            InventoryMovement::outboundOnly()->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$kemarin->id, $inbound->id],
            InventoryMovement::forDateRange('2026-10-01', '2026-10-31')->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$kemarin->id],
            InventoryMovement::outboundOnly()->forDateRange('2026-10-01', '2026-10-31')->pluck('id')->all(),
        );
    }

    // ------------------------------------------------------------------
    // Factory
    // ------------------------------------------------------------------

    public function test_factories_produce_valid_rows(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();

        $this->assertModelExists($supplier);
        $this->assertModelExists($product);
        $this->assertNotNull($product->category_id);

        $movement = InventoryMovement::factory()->inbound(75, $supplier)->create();
        $this->assertSame(75, $movement->quantity);
        $this->assertSame($supplier->id, $movement->transaction->supplier_id);

        $this->assertSame(-12, InventoryMovement::factory()->outbound(12)->create()->quantity);
        $this->assertSame(-5, InventoryMovement::factory()->adjustment(-5)->create()->quantity);

        $this->assertNotNull(User::factory()->create()->email_verified_at);
        $this->assertNull(User::factory()->unverified()->create()->email_verified_at);

        $this->assertSame(UserRole::AdminGudang, User::factory()->create()->role);
        $this->assertSame(UserRole::ManagerBisnis, User::factory()->managerBisnis()->create()->role);

        $tanpaKontak = Supplier::factory()->tanpaKontak()->create();
        $this->assertNull($tanpaKontak->contact_person);
        $this->assertNull($tanpaKontak->phone);
        $this->assertNull($tanpaKontak->address);
    }
}
