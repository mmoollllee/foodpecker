<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\Pickup;
use App\Models\PickupDate;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;

/**
 * Rule: for handing out the goods the lead prints what everybody gets from
 * the final order, in the order of the pickup dates.
 */
beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->anna->update(['first_name' => 'Anna', 'last_name' => 'Abend']);
    $this->ben->update(['first_name' => 'Ben', 'last_name' => 'Morgen']);

    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);
    $rice->update(['name' => 'Basmati-Reis']);
    CartItem::factory()->for($this->round)->exact(4)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->exact(6)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);

    $proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Final']);
    $this->round->update(['chosen_proposal_id' => $proposal->id, 'phase' => RoundPhase::Pickup]);

    $morning = PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-10-10 09:00']);
    $evening = PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-10-10 18:00']);
    Pickup::create(['round_id' => $this->round->id, 'user_id' => $this->anna->id, 'pickup_date_id' => $evening->id]);
    Pickup::create(['round_id' => $this->round->id, 'user_id' => $this->ben->id, 'pickup_date_id' => $morning->id]);
});

it('lists what everybody gets, in the order of the pickup dates', function () {
    $this->actingAs($this->lead)
        ->get(route('rounds.packing-list', $this->round))
        ->assertOk()
        ->assertSeeInOrder(['Ben Morgen', 'Basmati-Reis', '6 kg', 'Anna Abend', 'Basmati-Reis', '4 kg'])
        ->assertSeeInOrder(['Zum Aufteilen', 'Basmati-Reis', 'Anna 4 kg · Ben 6 kg']);
});

it('keeps the packing list to members of the group', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('rounds.packing-list', $this->round))
        ->assertNotFound();
});

it('has no packing list before the final order', function () {
    $this->round->update(['chosen_proposal_id' => null]);

    $this->actingAs($this->anna)
        ->get(route('rounds.packing-list', $this->round))
        ->assertNotFound();
});
