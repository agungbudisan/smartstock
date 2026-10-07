<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Ingat: `quantity` adalah DELTA BERTANDA.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory()->adminGudang(),
            'supplier_id' => null,
            'movement_type' => MovementType::Outbound,
            'quantity' => -fake()->numberBetween(1, 20),
            'reference_code' => strtoupper('OUT-'.fake()->unique()->numerify('######')),
            'movement_date' => now(),
        ];
    }

    /**
     * Barang masuk dari pemasok: quantity positif + supplier.
     *
     * Nilai Supplier::factory() dibiarkan apa adanya karena Eloquent
     * me-resolve instance factory menjadi primary key model yang dibuat.
     */
    public function inbound(int $quantity = 100, ?Supplier $supplier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'movement_type' => MovementType::Inbound,
            'quantity' => $quantity,
            'supplier_id' => $supplier?->id ?? Supplier::factory(),
            'reference_code' => strtoupper('IN-'.fake()->unique()->numerify('######')),
        ]);
    }

    /**
     * Barang keluar: quantity negatif, tanpa pemasok.
     */
    public function outbound(int $quantity = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'movement_type' => MovementType::Outbound,
            'quantity' => -abs($quantity),
            'supplier_id' => null,
        ]);
    }

    /**
     * Penyesuaian stok. Masukkan selisih bertanda, mis. -5 bila fisik 5 kurang.
     */
    public function adjustment(int $delta): static
    {
        return $this->state(fn (array $attributes) => [
            'movement_type' => MovementType::Adjustment,
            'quantity' => $delta,
            'supplier_id' => null,
        ]);
    }
}
