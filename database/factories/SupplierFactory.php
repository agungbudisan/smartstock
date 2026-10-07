<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' '.fake()->randomElement(['Distribusi', 'Logistik', 'S Supplies', 'Group', 'Trading']),
            'contact_person' => fake()->name(),
            'phone' => fake()->numerify('021-#######'),
            'address' => fake()->address(),
        ];
    }

    /**
     * Pemasok tanpa kontak lengkap (lazim pada data master lama).
     */
    public function tanpaKontak(): static
    {
        return $this->state(fn (array $attributes) => [
            'contact_person' => null,
            'phone' => null,
            'address' => null,
        ]);
    }
}
