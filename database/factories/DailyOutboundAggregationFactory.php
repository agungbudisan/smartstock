<?php

namespace Database\Factories;

use App\Models\DailyOutboundAggregation;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyOutboundAggregation>
 */
class DailyOutboundAggregationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'date' => now()->toDateString(),
            'total_qty_out' => fake()->numberBetween(0, 50),
        ];
    }

    public function padaTanggal(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }
}
