<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryTransaction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tanggal = fake()->dateTimeBetween('-30 days', 'now');

        $tipe = fake()->randomElement(MovementType::cases());

        return [
            // Nomor unik dijamin oleh unique index, tapi factory tetap memakai
            // unique() supaya dua baris test yang dibuat bersamaan tidak
            // membentrok sebelum index yang menahannya.
            'system_code' => strtoupper(
                InventoryTransaction::prefixFor($tipe).'-'.$tanggal->format('Ymd').'-'
                .fake()->unique()->numerify('####'),
            ),
            'physical_reference' => null,
            'movement_type' => $tipe,
            'supplier_id' => null,
            'user_id' => User::factory()->adminGudang(),
            'transaction_date' => $tanggal,
        ];
    }

    /**
     * Barang masuk dari pemasok.
     */
    public function inbound(?Supplier $supplier = null): static
    {
        return $this->state(fn (): array => [
            'movement_type' => MovementType::Inbound,
            'supplier_id' => $supplier?->id ?? Supplier::factory(),
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (): array => [
            'movement_type' => MovementType::Outbound,
            'supplier_id' => null,
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (): array => [
            'movement_type' => MovementType::Adjustment,
            'supplier_id' => null,
        ]);
    }

    /**
     * Dokumen dengan nomor fisik dari pihak lain (faktur / nota).
     */
    public function denganFaktur(string $nomor): static
    {
        return $this->state(fn (): array => [
            'physical_reference' => $nomor,
        ]);
    }
}
