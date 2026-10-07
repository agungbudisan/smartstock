<?php

namespace Database\Factories;

use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyDemandPrediction>
 */
class MonthlyDemandPredictionFactory extends Factory
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
            'target_month' => now()->startOfMonth()->toDateString(),
            'predicted_qty' => fake()->numberBetween(20, 500),
            'confidence_score' => fake()->randomFloat(2, 0, 100),
            'calculated_at' => now(),
        ];
    }

    public function untukBulan(string $targetMonth): static
    {
        return $this->state(fn (array $attributes) => [
            'target_month' => $targetMonth,
        ]);
    }
}
