<?php

namespace Database\Factories;

use App\Enums\PackagingStrategy;
use App\Enums\Visibility;
use App\Models\Manufacturer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Bio-Reis', 'Dinkelmehl', 'Olivenöl', 'Polenta', 'Hafer']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'manufacturer_id' => Manufacturer::factory(),
            'visibility' => Visibility::Private,
            'unit' => 'kg',
            'packaging_strategy' => PackagingStrategy::Tiered,
            'estimated_price_cents' => fake()->numberBetween(1500, 9500),
        ];
    }
}
