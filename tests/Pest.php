<?php

use App\Enums\GroupRole;
use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Scenario helpers
|--------------------------------------------------------------------------
*/

/**
 * A group whose owner leads a round, plus members who take part in it.
 *
 * @return array{group: Group, round: Round, lead: User, members: array<int, User>}
 */
function roundScenario(int $members = 3, RoundPhase $phase = RoundPhase::Shopping): array
{
    $lead = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $lead->id]);
    $round = Round::factory()->for($group)->inPhase($phase)->create(['lead_user_id' => $lead->id]);

    RoundParticipant::create(['round_id' => $round->id, 'user_id' => $lead->id]);

    $participants = User::factory()->count($members)->create()->each(function (User $user) use ($group, $round): void {
        $group->members()->attach($user->id, ['role' => GroupRole::Participant->value, 'joined_at' => now()]);
        RoundParticipant::create(['round_id' => $round->id, 'user_id' => $user->id]);
    });

    return ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => $participants->all()];
}

/**
 * A product of the group with a single package size. Without a portion
 * size every package goes whole to one person.
 */
function productWithTier(Group $group, float $packageAmount = 10, int $priceCents = 3000, ?float $portion = 0.5): Product
{
    $supplier = Supplier::factory()->create(['group_id' => $group->id]);
    $product = Product::factory()->create(['group_id' => $group->id, 'supplier_id' => $supplier->id, 'portion_size' => $portion]);

    PriceTier::factory()->for($product)->create([
        'label' => $packageAmount.' kg',
        'package_amount' => $packageAmount,
        'price_cents' => $priceCents,
    ]);

    return $product->load('priceTiers');
}

/**
 * Acts as the user inside the group's panel, like a real request would.
 */
function actingInGroup(User $user, Group $group): void
{
    test()->actingAs($user);

    Filament::setCurrentPanel('global');
    Filament::setTenant($group);
    Filament::bootCurrentPanel();
}
