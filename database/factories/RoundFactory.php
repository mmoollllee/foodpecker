<?php

namespace Database\Factories;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Round>
 */
class RoundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'lead_user_id' => fn (array $attributes): int => Group::find($attributes['group_id'])->owner_id ?? User::factory()->create()->id,
            'title' => 'Runde '.fake()->monthName().' '.fake()->year(),
            'phase' => RoundPhase::Shopping,
            'description' => fake()->optional()->sentence(),
            'shopping_deadline' => now()->addDays(14),
            'negotiation_deadline' => now()->addDays(21),
            'finalization_deadline' => now()->addDays(28),
            'payment_deadline' => now()->addDays(35),
            'expected_delivery' => now()->addDays(56),
            'pickup_location' => fake()->streetAddress(),
            'lead_fee_percent' => 2.5,
            'platform_fee_percent' => 1.0,
            'phase_changed_at' => now(),
        ];
    }

    public function inPhase(RoundPhase $phase): static
    {
        return $this->state(['phase' => $phase]);
    }

    public function draft(): static
    {
        return $this->inPhase(RoundPhase::Draft);
    }
}
