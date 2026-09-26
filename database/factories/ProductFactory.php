<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Product;
use App\Models\Supplier;
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
            'supplier_id' => Supplier::factory(),
            'visibility' => Visibility::Private,
            'unit' => 'kg',
            'portion_size' => 0.5,
        ];
    }

    /**
     * Every package goes whole to one person, like spaghetti bags.
     */
    public function wholePackages(): static
    {
        return $this->state(['portion_size' => null]);
    }

    public function portions(float $size): static
    {
        return $this->state(['portion_size' => $size]);
    }
}
