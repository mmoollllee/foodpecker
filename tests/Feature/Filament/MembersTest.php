<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Members;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);

    $this->moderator = User::factory()->create();
    $this->participant = User::factory()->create();
    $this->group->members()->attach($this->moderator->id, ['role' => GroupRole::Moderator->value]);
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);
});

it('lists the owner once, as a member with the owner role', function () {
    expect($this->group->members()->count())->toBe(3)
        ->and($this->group->roleOf($this->owner))->toBe(GroupRole::Owner);
});

it('lets only the owner remove members', function () {
    actingInGroup($this->moderator, $this->group);

    Livewire::test(Members::class)
        ->assertActionVisible('invite')
        ->assertActionHidden(TestAction::make('removeMember')->arguments(['member' => $this->participant->id]));

    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->assertActionHidden(TestAction::make('removeMember')->arguments(['member' => $this->owner->id]))
        ->callAction(TestAction::make('removeMember')->arguments(['member' => $this->participant->id]));

    expect($this->group->hasMember($this->participant))->toBeFalse();
});

it('keeps participants out of invitations and role changes', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(Members::class)
        ->assertActionHidden('invite')
        ->assertActionHidden(TestAction::make('toggleRole')->arguments(['member' => $this->moderator->id, 'role' => GroupRole::Moderator->value]))
        ->assertActionVisible('leaveGroup');
});

it('switches the role with one click on the badge', function (GroupRole $shown, GroupRole $switchedTo, string $notification) {
    $member = User::factory()->create(['first_name' => 'Nora', 'last_name' => 'Neu']);
    $this->group->members()->attach($member->id, ['role' => $shown->value]);
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->mountAction(TestAction::make('toggleRole')->arguments(['member' => $member->id, 'role' => $shown->value]))
        ->assertNotified($notification);

    expect($this->group->roleOf($member))->toBe($switchedTo);
})->with([
    'participant to moderator' => [GroupRole::Participant, GroupRole::Moderator, 'Nora Neu ist jetzt Moderator.'],
    'moderator to participant' => [GroupRole::Moderator, GroupRole::Participant, 'Nora Neu ist jetzt Teilnehmer.'],
]);

it('asks before moderators give up their own role', function () {
    actingInGroup($this->moderator, $this->group);

    Livewire::test(Members::class)
        ->mountAction(TestAction::make('toggleRole')->arguments(['member' => $this->moderator->id, 'role' => GroupRole::Moderator->value]))
        ->assertActionMounted('toggleRole');

    expect($this->group->roleOf($this->moderator))->toBe(GroupRole::Moderator);
});

it('keeps the owner badge out of the role switch', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->assertActionHidden(TestAction::make('toggleRole')->arguments(['member' => $this->owner->id, 'role' => GroupRole::Owner->value]));
});

it('does not switch a moderator back when an outdated badge still showed participant', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->callAction(TestAction::make('toggleRole')->arguments(['member' => $this->moderator->id, 'role' => GroupRole::Participant->value]));

    expect($this->group->roleOf($this->moderator))->toBe(GroupRole::Moderator);
});

it('lets everybody but the owner leave the group', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(Members::class)->callAction('leaveGroup');

    expect($this->group->hasMember($this->participant))->toBeFalse();

    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)->assertActionHidden('leaveGroup');
});

it('only touches invitations of the current group', function () {
    $otherOwner = User::factory()->create();
    $otherGroup = Group::factory()->create(['owner_id' => $otherOwner->id]);
    $foreignInvitation = GroupInvitation::factory()->for($otherGroup)->create();

    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->call('mountAction', 'withdrawInvitation', ['invitation' => $foreignInvitation->id])
        ->call('callMountedAction');

    expect($foreignInvitation->fresh())->not->toBeNull();
});

it('does not invite people who are already members', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->callAction('invite', data: ['emails' => $this->participant->email, 'role' => GroupRole::Participant->value])
        ->assertNotified('Diese Person ist schon Mitglied der Gruppe.');

    expect(GroupInvitation::where('email', $this->participant->email)->exists())->toBeFalse();
});
