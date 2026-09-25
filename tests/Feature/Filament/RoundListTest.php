<?php

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Pages\ListRounds;
use App\Models\Round;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->running, 'lead' => $this->lead] = roundScenario(1, RoundPhase::Finalizing);

    $this->draft = Round::factory()->for($this->group)->draft()->create(['lead_user_id' => $this->lead->id]);
    $this->completed = Round::factory()->for($this->group)->inPhase(RoundPhase::Completed)->create(['lead_user_id' => $this->lead->id]);
    $this->cancelled = Round::factory()->for($this->group)->inPhase(RoundPhase::Cancelled)->create(['lead_user_id' => $this->lead->id]);

    actingInGroup($this->lead, $this->group);
});

it('only distinguishes between the current rounds and the history', function () {
    expect(array_keys(Livewire::test(ListRounds::class)->instance()->getTabs()))->toBe(['aktuell', 'historie']);
});

it('lists the running round and the own drafts as current', function () {
    Livewire::test(ListRounds::class)
        ->assertCanSeeTableRecords([$this->running, $this->draft])
        ->assertCanNotSeeTableRecords([$this->completed, $this->cancelled]);
});

it('lists completed and cancelled rounds as history', function () {
    Livewire::test(ListRounds::class)
        ->set('activeTab', 'historie')
        ->assertCanSeeTableRecords([$this->completed, $this->cancelled])
        ->assertCanNotSeeTableRecords([$this->running, $this->draft]);
});

it('says that no round is running when there is none', function () {
    Round::query()->whereKey([$this->running->id, $this->draft->id])->delete();

    Livewire::test(ListRounds::class)
        ->assertSee('Gerade läuft keine Bestellrunde');
});
