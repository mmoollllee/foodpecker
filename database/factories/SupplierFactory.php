<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Bio-Mühle Eichelhain', 'Hofladen Brandenburg', 'Senfwerk Düsseldorf',
            'Pasta Italia GmbH', 'Speicher-Genossenschaft', 'Hofmolkerei Bremen',
        ]).' '.Str::upper(Str::random(2));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'visibility' => Visibility::Private,
            'website' => 'https://'.fake()->domainName(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->numerify('+49 30 #######'),
            'address' => fake()->address(),
            'shipping_notes' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function publicSupplier(): static
    {
        return $this->state([
            'visibility' => Visibility::Public,
            'group_id' => null,
        ]);
    }
}
