<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->adminGudang(),
            'action' => fake()->randomElement([
                'inbound.create',
                'outbound.create',
                'adjustment.create',
                'product.update',
            ]),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Log tanpa pemilik — dipakai bila user sudah dihapus permanen
     * (kolomnya nullable + nullOnDelete).
     */
    public function tanpaUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }
}
