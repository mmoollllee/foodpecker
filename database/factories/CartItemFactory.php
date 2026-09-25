<?php

namespace Database\Factories;

use App\Enums\QuantityMode;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'quantity_mode' => QuantityMode::Exact,
            'exact_quantity' => 5,
        ];
    }

    public function exact(float $quantity): static
    {
        return $this->state([
            'quantity_mode' => QuantityMode::Exact,
            'exact_quantity' => $quantity,
            'min_quantity' => null,
            'max_quantity' => null,
        ]);
    }

    public function flexible(float $min, float $max): static
    {
        return $this->state([
            'quantity_mode' => QuantityMode::Flexible,
            'exact_quantity' => null,
            'min_quantity' => $min,
            'max_quantity' => $max,
        ]);
    }
}
