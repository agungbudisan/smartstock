<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'category_id' => Category::factory(),
            'name' => Str::title(fake()->unique()->words(3, true)),
            'current_stock' => fake()->numberBetween(50, 500),
            'reorder_point' => fake()->numberBetween(10, 100),
        ];
    }

    /**
     * Produk yang belum dikategorikan.
     */
    public function tanpaKategori(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
        ]);
    }

    /**
     * Produk untuk kategori tertentu.
     */
    public function kategori(Category $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    /**
     * Stok berada tepat pada batas aman (reorder point).
     */
    public function diBatasReorder(): static
    {
        return $this->state(function (array $attributes) {
            $reorderPoint = $attributes['reorder_point'] ?? 50;

            return [
                'reorder_point' => $reorderPoint,
                'current_stock' => $reorderPoint,
            ];
        });
    }

    /**
     * Stok sudah di bawah batas aman.
     */
    public function diBawahReorderPoint(): static
    {
        return $this->state(function (array $attributes) {
            $reorderPoint = $attributes['reorder_point'] ?? 50;

            return [
                'reorder_point' => $reorderPoint,
                'current_stock' => max(0, $reorderPoint - fake()->numberBetween(1, 20)),
            ];
        });
    }

    /**
     * Habis sama sekali.
     */
    public function habis(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_stock' => 0,
        ]);
    }
}
