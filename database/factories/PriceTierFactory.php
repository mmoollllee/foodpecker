<?php

namespace Database\Factories;

use App\Models\PriceTier;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTier>
 */
class PriceTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'label' => '25 kg Sack',
            'package_amount' => 25,
            'min_order_packages' => 1,
            'price_cents' => 5000,
            'sort_order' => 1,
        ];
    }

    public function package(float $amount, int $priceCents, ?string $label = null): static
    {
        return $this->state([
            'package_amount' => $amount,
            'price_cents' => $priceCents,
            'label' => $label ?? rtrim(rtrim(number_format($amount, 3, ',', ''), '0'), ',').' kg',
        ]);
    }
}
