<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Members;
use App\Filament\Pages\Tenancy\EditGroupProfile;
use App\Models\Activity;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\GroupMembership;
use App\Services\Groups\OwnerTransfer;
use App\Services\Invitations\GroupInvitationService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Rule: everything that changes who is in a group — and with which role —
 * ends up in the group history.
 */
beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Speisekammer']);

    $this->moderator = User::factory()->create(['first_name' => 'Mona', 'last_name' => 'Mod']);
    $this->participant = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Teil']);
    $this->group->members()->attach($this->moderator->id, ['role' => GroupRole::Moderator->value]);
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);
});

/**
 * The group history as "Who · what", oldest first.
 *
 * @return array<int, string>
 */
function historyOf(Group $group): array
{
    return $group->activities()->reorder()->orderBy('id')->with('user')->get()
        ->map(fn (Activity $activity): string => ($activity->user?->fullName() ?? 'System').' · '.$activity->describeAction())
        ->all();
}

it('starts with the founding of the group', function () {
    expect(historyOf($this->group))->toBe(['Olga Owner · Gruppe „Speisekammer“ gegründet']);
});

it('records who joined through an invitation, even outside the panel', function () {
    actingInGroup($this->owner, $this->group);
    $invitation = app(GroupInvitationService::class)->invite($this->group, 'neu@example.test', GroupRole::Participant, $this->owner);

    Filament::setTenant(null);
    auth()->logout();

    $newcomer = User::factory()->create(['first_name' => 'Nina', 'last_name' => 'Neu', 'email' => 'neu@example.test']);
    $invitation->accept($newcomer);

    $joined = $this->group->activities()->where('action', 'member_joined')->firstOrFail();

    expect($joined->user_id)->toBe($newcomer->id)
        ->and($joined->group_id)->toBe($this->group->id)
        ->and(historyOf($this->group))->toContain(
            'Olga Owner · neu@example.test als Teilnehmer eingeladen',
            'Nina Neu · der Gruppe als Teilnehmer beigetreten',
        );
});

it('records role changes, removals and people leaving', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->callAction('changeRole', data: ['user_id' => $this->participant->id, 'role' => GroupRole::Moderator->value])
        ->assertNotified('Rolle aktualisiert.')
        ->callAction(TestAction::make('removeMember')->arguments(['member' => $this->moderator->id]))
        ->assertNotified('Mona Mod wurde aus der Gruppe entfernt.');

    actingInGroup($this->participant, $this->group->fresh());

    Livewire::test(Members::class)->callAction('leaveGroup');

    expect(historyOf($this->group))->toContain(
        'Olga Owner · Rolle von Paul Teil: Teilnehmer → Moderator',
        'Olga Owner · Mona Mod aus der Gruppe entfernt',
        'Paul Teil · hat die Gruppe verlassen',
    );
});

it('records changed group settings and owner changes', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(EditGroupProfile::class)
        ->fillForm(['name' => 'Speisekammer Nord', 'slug' => 'speisekammer-nord'])
        ->call('save')
        ->assertHasNoFormErrors();

    auth()->logout();

    app(OwnerTransfer::class)->request($this->group->fresh(), $this->moderator, $this->owner);
    app(OwnerTransfer::class)->accept($this->group->fresh(), $this->moderator);

    expect(historyOf($this->group))->toContain(
        'Olga Owner · Geändert: Name, URL-Kürzel',
        'Olga Owner · Owner-Übergabe an Mona Mod angefragt',
        'Mona Mod · Owner-Rolle von Olga Owner an Mona Mod übergeben',
    );
});

it('records withdrawn invitations', function () {
    actingInGroup($this->owner, $this->group);
    $invitation = app(GroupInvitationService::class)->invite($this->group, 'vielleicht@example.test', GroupRole::Participant, $this->owner);

    app(GroupInvitationService::class)->withdraw($invitation);

    expect(historyOf($this->group))->toContain('Olga Owner · Einladung an vielleicht@example.test zurückgezogen');
});

it('shows the history to every member, invitations only to those who invite', function () {
    actingInGroup($this->owner, $this->group);
    app(GroupInvitationService::class)->invite($this->group, 'neu@example.test', GroupRole::Participant, $this->owner);
    app(GroupMembership::class)->changeRole($this->group, $this->participant, GroupRole::Moderator, $this->owner);
    app(GroupMembership::class)->changeRole($this->group, $this->participant, GroupRole::Participant, $this->owner);

    actingInGroup($this->participant, $this->group);

    Livewire::test(Members::class)
        ->assertSee('Verlauf')
        ->assertSee('Gruppe „Speisekammer“ gegründet')
        ->assertSee('Rolle von Paul Teil: Moderator → Teilnehmer')
        ->assertSee('Olga Owner')
        ->assertDontSee('neu@example.test');

    actingInGroup($this->moderator, $this->group);

    Livewire::test(Members::class)
        ->assertSee('neu@example.test als Teilnehmer eingeladen');
});

it('keeps the rules for role changes and leaving', function () {
    $membership = app(GroupMembership::class);

    expect(fn () => $membership->changeRole($this->group, $this->moderator, GroupRole::Participant, $this->participant))
        ->toThrow(ValidationException::class, 'keine Rollen vergeben');

    expect(fn () => $membership->changeRole($this->group, $this->owner, GroupRole::Participant, $this->moderator))
        ->toThrow(ValidationException::class, 'nur per Übergabe');

    expect(fn () => $membership->changeRole($this->group, $this->participant, GroupRole::Owner, $this->owner))
        ->toThrow(ValidationException::class, 'nicht vergeben');

    expect(fn () => $membership->remove($this->group, $this->participant, $this->moderator))
        ->toThrow(ValidationException::class, 'Nur der Owner');

    expect(fn () => $membership->leave($this->group, $this->owner))
        ->toThrow(ValidationException::class, 'übergib erst die Owner-Rolle');

    $membership->changeRole($this->group, $this->participant, GroupRole::Participant, $this->owner);

    expect($this->group->activities()->where('action', 'role_changed')->exists())->toBeFalse();
});
