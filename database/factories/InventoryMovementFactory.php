<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     *Ingat: `quantity` adalah DELTA BERTANDA.
     *
     * Tidak ada `movement_type` / `user_id` / `supplier_id` di sini — ketiganya
     * milik dokumen sejak tahap 4. Baris detail hanya menjawab pertanyaan tentang
     * baris itu sendiri.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => -fake()->numberBetween(1, 20),
            'reference_code' => strtoupper('OUT-'.fake()->unique()->numerify('######')),
            'movement_date' => now(),
        ];
    }

    /**
     * Setiap baris detail wajib punya dokumen.
     *
     * Tidak ada `afterCreating` yang membaca kolom baris untuk menyusun
     * header — kolom-kolom itu sudah tidak ada. Sebaliknya, dokumen dibuat
     * LEWAT STATE di bawah, dan `configure()` hanya menjadi jaring pengaman
     * untuk `create()` polos tanpa state: kalau `transaction_id` masih kosong setelah baris
     * dibuat, buat satu dokumen outbound minimal.
     *
     * Jaring pengaman itu perlu guard `transaction_id !== null`:
     * pemanggil yang sudah menentukan dokumennya sendiri (mis. membuat
     * beberapa baris untuk satu faktur) tidak boleh dokumennya ditimpa.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (InventoryMovement $movement): void {
            if ($movement->transaction_id !== null) {
                return;
            }

            $movement->forceFill([
                'transaction_id' => InventoryTransaction::factory()
                    ->outbound()
                    ->create([
                        'transaction_date' => $movement->movement_date,
                        'physical_reference' => $movement->reference_code,
                    ])
                    ->id,
            ])->save();
        });
    }

    /**
     * Barang masuk dari pemasok: quantity positif, satu dokumen inbound.
     *
     * Dokumen dibangun di dalam state, bukan di `configure()`, supaya jenis,
     * pemasok, pencatat, dan tanggalnya benar-benar milik dokumen sejak awal.
     * `Supplier::factory()` dibiarkan apa adanya karena Eloquent me-resolve
     * instance factory menjadi primary key model yang dibuat.
     */
    public function inbound(int $quantity = 100, ?Supplier $supplier = null): static
    {
        // Nomor faktur dibuat DI SINI dan dipakai dua kali: untuk dokumennya
        // dan untuk baris detailnya. Kalau masing-masing mengarang sendiri, satu
        // "faktur" akan punya dua nomor berbeda — persis kebalikan dari tujuan
        // header-detail.
        $referensi = strtoupper('IN-'.fake()->unique()->numerify('######'));

        return $this->state(fn (array $attributes): array => [
            'quantity' => $quantity,
            'reference_code' => $referensi,
            'transaction_id' => $this->dokumen(
                MovementType::Inbound,
                $supplier?->id ?? Supplier::factory(),
                $attributes['movement_date'] ?? now(),
                $referensi,
            ),
        ]);
    }

    /**
     * Barang keluar: quantity negatif, tanpa pemasok.
     */
    public function outbound(int $quantity = 10): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => -abs($quantity),
            'transaction_id' => $this->dokumen(
                MovementType::Outbound,
                null,
                $attributes['movement_date'] ?? now(),
                $attributes['reference_code'] ?? strtoupper('OUT-'.fake()->unique()->numerify('######')),
            ),
        ]);
    }

    /**
     * Penyesuaian stok. Masukkan selisih bertanda, mis. -5 bila fisik 5 kurang.
     */
    public function adjustment(int $delta): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => $delta,
            'transaction_id' => $this->dokumen(
                MovementType::Adjustment,
                null,
                $attributes['movement_date'] ?? now(),
                strtoupper('OPNAME-'.fake()->unique()->numerify('######')),
            ),
        ]);
    }

    /**
     * Bangun dokumen yang cocok dengan baris detailnya.
     *
     * `system_code` sengaja dibiarkan ke factory dokumen: nilainya unik per
     * tanggal, dan yang penting di sini bukan formatnya melainkan jenis,
     * pemasok, pencatat, dan tanggalnya yang tidak boleh menyimpang dari baris.
     */
    private function dokumen(
        MovementType $tipe,
        mixed $supplierId,
        mixed $tanggal,
        ?string $referensi,
    ): InventoryTransaction {
        return InventoryTransaction::factory()->create([
            'movement_type' => $tipe,
            'supplier_id' => $supplierId,
            'transaction_date' => $tanggal,
            'physical_reference' => $referensi,
        ]);
    }
}
