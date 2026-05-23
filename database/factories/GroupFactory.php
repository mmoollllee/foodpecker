<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Speisekammer Schöneberg',
            'Hofgemeinschaft Lichtenrade',
            'Kollektiv Kreuzberg',
            'Familie Müller & Friends',
            'Speicherbäume Stuttgart',
        ]).' '.Str::upper(Str::random(3));

        return [
            'name' => $name,
            'slug' => Group::generateUniqueSlug($name),
            'description' => fake()->optional()->sentence(),
            'contact_email' => fake()->safeEmail(),
            'locale' => 'de',
            'owner_id' => User::factory(),
        ];
    }
}
