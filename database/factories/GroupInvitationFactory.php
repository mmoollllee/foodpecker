<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupInvitation>
 */
class GroupInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => GroupRole::Participant->value,
            'expires_at' => now()->addDays(14),
        ];
    }
}
