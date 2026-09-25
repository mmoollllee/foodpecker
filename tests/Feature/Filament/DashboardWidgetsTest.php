<?php

use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Filament\Widgets\CurrentRound;
use App\Filament\Widgets\GroupStatsOverview;
use App\Filament\Widgets\MyTasks;
use App\Models\CartItem;
use App\Models\Round;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ParticipantExclusion;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);

    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);

    $this->proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Vorschlag A']);
    app(ProposalWorkflow::class)->publish($this->proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Finalizing]);
});

it('counts every member once', function () {
    actingInGroup($this->lead, $this->group);

    Livewire::test(GroupStatsOverview::class)->assertSeeInOrder(['Mitglieder', '3']);
});

it('asks the people involved to vote', function () {
    actingInGroup($this->anna, $this->group);

    $titles = collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title');

    expect($titles)->toContain('1 offene Abstimmung(en) in „Vorschlag A“');
});

it('tells the lead when a proposal is blocked or ready', function () {
    $item = $this->proposal->items()->firstOrFail();
    app(ProposalWorkflow::class)->vote($item, $this->anna, VoteValue::Down, 'Zu viel');

    actingInGroup($this->lead, $this->group);
    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->toContain('„Vorschlag A“ wird blockiert');

    app(ProposalWorkflow::class)->vote($item, $this->anna, VoteValue::Up);
    app(ProposalWorkflow::class)->vote($item, $this->ben, VoteValue::Up);

    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->toContain('„Vorschlag A“ ist einstimmig');
});

it('gives excluded participants no tasks in the round', function () {
    app(ParticipantExclusion::class)->exclude($this->round, $this->ben, 'Blockiert', $this->lead);

    actingInGroup($this->ben, $this->group);

    expect(Livewire::test(MyTasks::class)->viewData('tasks'))->toBe([]);
});

it('shows the personal tasks first, then the running round, then the group figures', function () {
    actingInGroup($this->lead, $this->group);

    expect(array_values(Filament::getWidgets()))->toBe([MyTasks::class, CurrentRound::class, GroupStatsOverview::class]);
});

it('shows the running round and where you stand in it', function () {
    actingInGroup($this->anna, $this->group);

    Livewire::test(CurrentRound::class)
        ->assertSee($this->round->title)
        ->assertSee('Bestätigung')
        ->assertSee('Du bist dabei — 1 Artikel im Warenkorb.');
});

it('points to the last round and to the next one while no round is running', function () {
    $this->round->update(['phase' => RoundPhase::Completed]);

    actingInGroup($this->lead, $this->group);

    Livewire::test(CurrentRound::class)
        ->assertSee('Gerade läuft keine Bestellrunde.')
        ->assertSee($this->round->title)
        ->assertSee('Neue Runde starten');

    actingInGroup($this->anna, $this->group);

    Livewire::test(CurrentRound::class)
        ->assertSee('Gerade läuft keine Bestellrunde.')
        ->assertDontSee('Neue Runde starten');
});

it('lets a prepared draft wait until the running round is over', function () {
    Round::factory()->for($this->group)->draft()->create(['lead_user_id' => $this->lead->id, 'title' => 'Nächste Runde']);

    actingInGroup($this->lead, $this->group);

    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->toContain('Entwurf „Nächste Runde“ ist vorbereitet');

    $this->round->update(['phase' => RoundPhase::Completed]);

    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->toContain('Entwurf „Nächste Runde“ starten');
});
