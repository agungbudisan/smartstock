<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 2 — Regression test untuk skema database.
 *
 * Sengaja ditulis secara portabel: assertion-nya memverifikasi perilaku
 * constraint (unique / FK / on-delete), bukan tipe kolom mentah, supaya test
 * yang sama tetap hijau di MySQL (dev) maupun SQLite in-memory (sesuai
 * phpunit.xml). Nama method memakai bahasa Inggris mengikuti gaya test Breeze
 * yang sudah ada di repo.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Struktur tabel
    // ------------------------------------------------------------------

    /**
     * Schema::getTableListing() mengembalikan nama berprefiks skema
     * (smartstock.products di MySQL, main.products di SQLite), jadi awalan
     * itu dibuang agar assertion bisa dipakai di kedua driver.
     *
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        return array_map(
            fn (string $table) => str_contains($table, '.') ? explode('.', $table)[1] : $table,
            Schema::getTableListing(),
        );
    }

    public function test_all_project_tables_exist(): void
    {
        $expected = [
            'users',
            'categories',
            'products',
            'suppliers',
            'product_supplier',
            'inventory_movements',
            'audit_logs',
            'daily_outbound_aggregations',
            'monthly_demand_predictions',
        ];

        $this->assertEmpty(
            array_diff($expected, $this->tableNames()),
            'Tabel proyek Smart-Stock belum lengkap dibuat.'
        );
    }

    public function test_audit_logs_only_has_created_at_and_products_has_deleted_at(): void
    {
        // Log audit bersifat append-only: tidak ada updated_at (sesuai ERD).
        $this->assertContains('created_at', Schema::getColumnListing('audit_logs'));
        $this->assertNotContains('updated_at', Schema::getColumnListing('audit_logs'));

        // PRD mewajibkan soft delete pada produk.
        $this->assertContains('deleted_at', Schema::getColumnListing('products'));
    }

    // ------------------------------------------------------------------
    // users.role (enum)
    // ------------------------------------------------------------------

    public function test_users_role_accepts_both_defined_values(): void
    {
        foreach (UserRole::cases() as $case) {
            $id = DB::table('users')->insertGetId([
                'name' => $case->value,
                'email' => $case->value.'@example.test',
                'password' => bcrypt('password'),
                'role' => $case->value,
            ]);

            $this->assertSame($case->value, DB::table('users')->where('id', $id)->value('role'));
        }
    }

    public function test_users_role_rejects_unknown_value(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'X',
            'email' => 'x@example.test',
            'password' => bcrypt('password'),
            'role' => 'superuser',
        ]);
    }

    public function test_users_role_defaults_to_admin_gudang(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Tanpa Role',
            'email' => 'tanpa-role@example.test',
            'password' => bcrypt('password'),
        ]);

        $this->assertSame(UserRole::AdminGudang->value, DB::table('users')->where('id', $id)->value('role'));
    }

    // ------------------------------------------------------------------
    // inventory_movements.movement_type (enum) & quantity (delta bertanda)
    // ------------------------------------------------------------------

    public function test_inventory_movements_accepts_every_movement_type(): void
    {
        $productId = $this->makeProduct();

        foreach (MovementType::cases() as $case) {
            DB::table('inventory_movements')->insert([
                'product_id' => $productId,
                'movement_type' => $case->value,
                'quantity' => 1,
            ]);
        }

        $this->assertSame(
            count(MovementType::cases()),
            DB::table('inventory_movements')->where('product_id', $productId)->count()
        );
    }

    public function test_inventory_movements_rejects_unknown_movement_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('inventory_movements')->insert([
            'product_id' => $this->makeProduct(),
            'movement_type' => 'transfer',
            'quantity' => 1,
        ]);
    }

    public function test_quantity_accepts_negative_values_for_the_ledger_delta(): void
    {
        $id = DB::table('inventory_movements')->insertGetId([
            'product_id' => $this->makeProduct(),
            'movement_type' => MovementType::Outbound->value,
            'quantity' => -25,
        ]);

        $this->assertSame(-25, DB::table('inventory_movements')->where('id', $id)->value('quantity'));
    }

    public function test_movement_date_falls_back_to_now_when_omitted(): void
    {
        $id = DB::table('inventory_movements')->insertGetId([
            'product_id' => $this->makeProduct(),
            'movement_type' => MovementType::Inbound->value,
            'quantity' => 5,
        ]);

        $this->assertNotNull(DB::table('inventory_movements')->where('id', $id)->value('movement_date'));
    }

    public function test_supplier_id_and_user_id_are_optional_on_movements(): void
    {
        $id = DB::table('inventory_movements')->insertGetId([
            'product_id' => $this->makeProduct(),
            'movement_type' => MovementType::Outbound->value,
            'quantity' => -1,
        ]);

        $row = DB::table('inventory_movements')->where('id', $id)->first();

        $this->assertNull($row->user_id);
        $this->assertNull($row->supplier_id);
    }

    // ------------------------------------------------------------------
    // Unique / composite primary key
    // ------------------------------------------------------------------

    public function test_product_supplier_uses_a_composite_primary_key(): void
    {
        // Pasangan produk + pemasok yang sama inserted dua kali.
        $productId = $this->makeProduct();
        $supplierId = $this->makeSupplier();

        $this->makePivot($productId, $supplierId);

        $this->expectException(QueryException::class);

        $this->makePivot($productId, $supplierId);
    }

    public function test_product_sku_must_be_unique(): void
    {
        $this->makeProduct(sku: 'SKU-DUP');

        $this->expectException(QueryException::class);

        $this->makeProduct(sku: 'SKU-DUP');
    }

    public function test_category_name_must_be_unique(): void
    {
        DB::table('categories')->insert(['name' => 'Elektronik']);

        $this->expectException(QueryException::class);

        DB::table('categories')->insert(['name' => 'Elektronik']);
    }

    public function test_daily_aggregations_are_unique_per_product_and_date(): void
    {
        // Idempotensi AggregateDailyOutboundJob.
        $productId = $this->makeProduct();

        DB::table('daily_outbound_aggregations')->insert([
            'product_id' => $productId,
            'date' => '2026-10-06',
            'total_qty_out' => 10,
        ]);

        $this->expectException(QueryException::class);

        DB::table('daily_outbound_aggregations')->insert([
            'product_id' => $productId,
            'date' => '2026-10-06',
            'total_qty_out' => 99,
        ]);
    }

    public function test_monthly_predictions_are_unique_per_product_and_target_month(): void
    {
        // Idempotensi CalculateMonthlyPredictionJob.
        $productId = $this->makeProduct();

        DB::table('monthly_demand_predictions')->insert([
            'product_id' => $productId,
            'target_month' => '2026-11-01',
            'predicted_qty' => 100,
            'confidence_score' => 80.00,
        ]);

        $this->expectException(QueryException::class);

        DB::table('monthly_demand_predictions')->insert([
            'product_id' => $productId,
            'target_month' => '2026-11-01',
            'predicted_qty' => 999,
            'confidence_score' => 10.00,
        ]);
    }

    // ------------------------------------------------------------------
    // Foreign keys: insert & on-delete behaviour
    // ------------------------------------------------------------------

    public function test_foreign_keys_reject_dangling_references(): void
    {
        $productId = $this->makeProduct();
        $supplierId = $this->makeSupplier();
        $userId = $this->makeUser();

        $invalid = [
            ['inventory_movements', ['product_id' => 999999, 'movement_type' => 'inbound', 'quantity' => 1]],
            ['inventory_movements', ['product_id' => $productId, 'user_id' => 999999, 'movement_type' => 'inbound', 'quantity' => 1]],
            ['inventory_movements', ['product_id' => $productId, 'supplier_id' => 999999, 'movement_type' => 'inbound', 'quantity' => 1]],
            ['products', ['category_id' => 999999, 'name' => 'x', 'sku' => 'SKU-FK']],
            ['audit_logs', ['user_id' => 999999, 'action' => 'test', 'description' => 'test']],
            ['product_supplier', ['product_id' => 999999, 'supplier_id' => $supplierId]],
            ['product_supplier', ['product_id' => $productId, 'supplier_id' => 999999]],
            ['daily_outbound_aggregations', ['product_id' => 999999, 'date' => '2026-10-06']],
            ['monthly_demand_predictions', ['product_id' => 999999, 'target_month' => '2026-11-01']],
        ];

        foreach ($invalid as [$table, $row]) {
            try {
                DB::table($table)->insert($row);
                $this->fail("FK pada {$table} seharusnya menolak: ".json_encode($row));
            } catch (QueryException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_deleting_a_category_detaches_products_instead_of_deleting_them(): void
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Temporary']);
        $productId = $this->makeProduct(categoryId: $categoryId);

        DB::table('categories')->where('id', $categoryId)->delete();

        $this->assertNull(DB::table('products')->where('id', $productId)->value('category_id'));
        $this->assertTrue(DB::table('products')->where('id', $productId)->exists());
    }

    public function test_deleting_a_user_keeps_movements_and_audit_logs(): void
    {
        $userId = $this->makeUser();
        $movementId = DB::table('inventory_movements')->insertGetId([
            'product_id' => $this->makeProduct(),
            'user_id' => $userId,
            'movement_type' => MovementType::Inbound->value,
            'quantity' => 10,
        ]);
        $logId = DB::table('audit_logs')->insertGetId([
            'user_id' => $userId,
            'action' => 'inbound.create',
            'description' => 'barang masuk',
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertNull(DB::table('inventory_movements')->where('id', $movementId)->value('user_id'));
        $this->assertNotNull(DB::table('inventory_movements')->where('id', $movementId)->value('movement_date'));

        $auditLog = DB::table('audit_logs')->where('id', $logId)->first();
        $this->assertNotNull($auditLog, 'Jejak rekam audit tidak boleh hilang saat user dihapus.');
        $this->assertNull($auditLog->user_id);
    }

    public function test_deleting_a_supplier_cascades_pivot_but_keeps_movements(): void
    {
        $supplierId = $this->makeSupplier();
        $productId = $this->makeProduct();
        $this->makePivot($productId, $supplierId);

        $movementId = DB::table('inventory_movements')->insertGetId([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'movement_type' => MovementType::Inbound->value,
            'quantity' => 10,
        ]);

        DB::table('suppliers')->where('id', $supplierId)->delete();

        $this->assertSame(0, DB::table('product_supplier')->where('supplier_id', $supplierId)->count());
        $this->assertNull(DB::table('inventory_movements')->where('id', $movementId)->value('supplier_id'));
    }

    public function test_deleting_a_product_cascades_to_all_child_tables(): void
    {
        $productId = $this->makeProduct();
        $supplierId = $this->makeSupplier();
        $this->makePivot($productId, $supplierId);

        DB::table('inventory_movements')->insert([
            'product_id' => $productId, 'movement_type' => 'inbound', 'quantity' => 1,
        ]);
        DB::table('daily_outbound_aggregations')->insert([
            'product_id' => $productId, 'date' => '2026-10-06', 'total_qty_out' => 1,
        ]);
        DB::table('monthly_demand_predictions')->insert([
            'product_id' => $productId, 'target_month' => '2026-11-01', 'predicted_qty' => 1,
        ]);

        DB::table('products')->where('id', $productId)->delete();

        $this->assertSame(0, DB::table('inventory_movements')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('daily_outbound_aggregations')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('monthly_demand_predictions')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('product_supplier')->where('product_id', $productId)->count());
    }

    // ------------------------------------------------------------------
    // PHP enum <-> migration tetap sinkron
    // ------------------------------------------------------------------

    public function test_php_enums_stay_in_sync_with_the_migrations(): void
    {
        $this->assertSame(
            $this->enumValuesIn('2026_10_07_090000_add_role_to_users_table.php'),
            UserRole::values(),
            'Nilai UserRole tidak sinkron dengan kolom users.role di migration.'
        );

        $this->assertSame(
            $this->enumValuesIn('2026_10_07_090500_create_inventory_movements_table.php'),
            MovementType::values(),
            'Nilai MovementType tidak sinkron dengan kolom movement_type di migration.'
        );
    }

    /**
     * Membaca daftar nilai enum yang tertulis di migration.
     *
     * @return array<int, string>
     */
    private function enumValuesIn(string $migrationFile): array
    {
        $path = database_path('migrations/'.$migrationFile);
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match("/->enum\(\s*'(?:role|movement_type)',\s*\[(.*?)\]/s", $contents, $matches),
            "Tidak bisa menemukan definisi enum pada {$migrationFile}."
        );

        preg_match_all("/'([^']+)'/", $matches[1], $values);

        return $values[1];
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function makeUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'User Uji',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);
    }

    private function makeSupplier(): int
    {
        return DB::table('suppliers')->insertGetId(['name' => 'Pemasok Uji '.fake()->unique()->numberBetween(1, 999999)]);
    }

    private function makeProduct(?int $categoryId = null, ?string $sku = null): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku ?? 'SKU-'.fake()->unique()->numberBetween(1, 999999),
            'category_id' => $categoryId,
            'name' => 'Produk Uji',
            'current_stock' => 0,
            'reorder_point' => 10,
        ]);
    }

    private function makePivot(?int $productId = null, ?int $supplierId = null): void
    {
        DB::table('product_supplier')->insert([
            'product_id' => $productId ?? $this->makeProduct(),
            'supplier_id' => $supplierId ?? $this->makeSupplier(),
            'last_purchase_price' => 12500.50,
            'lead_time_days' => 7,
        ]);
    }
}
