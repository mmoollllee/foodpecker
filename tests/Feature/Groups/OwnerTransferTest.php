<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Members;
use App\Filament\Widgets\MyTasks;
use App\Mail\OwnerTransferRequestedMail;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\OwnerTransfer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Rule: the owner hands the group over only with the consent of the new
 * owner, and stays in the group as a moderator.
 */
beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);

    $this->moderator = User::factory()->create();
    $this->participant = User::factory()->create(['first_name' => 'Paula', 'last_name' => 'Teil']);
    $this->group->members()->attach($this->moderator->id, ['role' => GroupRole::Moderator->value]);
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);

    $this->transfer = app(OwnerTransfer::class);
});

it('keeps the owner until the new owner accepts', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner, 'https://example.test/mitglieder');

    expect($this->group->fresh())
        ->owner_id->toBe($this->owner->id)
        ->pending_owner_id->toBe($this->participant->id);

    Mail::assertSent(OwnerTransferRequestedMail::class, fn (OwnerTransferRequestedMail $mail): bool => $mail->hasTo($this->participant->email)
        && $mail->membersUrl === 'https://example.test/mitglieder');

    $this->transfer->accept($this->group->fresh(), $this->participant);

    $group = $this->group->fresh();

    expect($group->owner_id)->toBe($this->participant->id)
        ->and($group->pending_owner_id)->toBeNull()
        ->and($group->roleOf($this->participant))->toBe(GroupRole::Owner)
        ->and($group->roleOf($this->owner))->toBe(GroupRole::Moderator)
        ->and($group->members()->whereKey($this->participant->id)->first()->membership->role)->toBe(GroupRole::Owner)
        ->and($group->activities()->where('action', 'owner_transferred')->first()?->describeAction())
        ->toBe('Owner-Rolle von Olga Owner an Paula Teil übergeben');
});

it('lets the former owner leave and the new owner dissolve the group', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);
    $this->transfer->accept($this->group->fresh(), $this->participant);

    $group = $this->group->fresh();

    expect($this->owner->can('leave', $group))->toBeTrue()
        ->and($this->owner->can('delete', $group))->toBeFalse()
        ->and($this->participant->can('delete', $group))->toBeTrue()
        ->and($this->participant->can('leave', $group))->toBeFalse();
});

it('lets the asked person decline and the owner withdraw the request', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);
    $this->transfer->decline($this->group->fresh(), $this->participant);

    expect($this->group->fresh())
        ->pending_owner_id->toBeNull()
        ->owner_id->toBe($this->owner->id);

    $this->transfer->request($this->group->fresh(), $this->moderator, $this->owner);
    $this->transfer->cancel($this->group->fresh(), $this->owner);

    expect($this->group->fresh()->pending_owner_id)->toBeNull()
        ->and($this->group->activities()->pluck('action')->all())
        ->toContain('owner_transfer_declined', 'owner_transfer_cancelled');
});

it('only lets the asked person accept', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);

    expect(fn () => $this->transfer->accept($this->group->fresh(), $this->moderator))
        ->toThrow(ValidationException::class, 'Es gibt keine offene Übergabe an dich.');
});

it('only lets the owner hand over, and only to other members', function () {
    expect(fn () => $this->transfer->request($this->group, $this->participant, $this->moderator))
        ->toThrow(ValidationException::class, 'Nur der Owner');

    expect(fn () => $this->transfer->request($this->group, User::factory()->create(), $this->owner))
        ->toThrow(ValidationException::class, 'Nur Mitglieder der Gruppe');

    expect(fn () => $this->transfer->request($this->group, $this->owner, $this->owner))
        ->toThrow(ValidationException::class, 'bereits Owner');

    $this->transfer->request($this->group, $this->participant, $this->owner);

    expect(fn () => $this->transfer->request($this->group->fresh(), $this->moderator, $this->owner))
        ->toThrow(ValidationException::class, 'schon eine offene Übergabe');
});

it('drops the request when the asked person leaves or is removed', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);

    actingInGroup($this->participant, $this->group);
    Livewire::test(Members::class)->callAction('leaveGroup');

    expect($this->group->fresh()->pending_owner_id)->toBeNull();

    $this->transfer->request($this->group->fresh(), $this->moderator, $this->owner);

    actingInGroup($this->owner, $this->group->fresh());
    Livewire::test(Members::class)
        ->callAction(TestAction::make('removeMember')->arguments(['member' => $this->moderator->id]));

    expect($this->group->fresh()->pending_owner_id)->toBeNull();
});

it('refuses a request that outlived the membership', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);
    $this->group->members()->detach($this->participant->id);

    expect(fn () => $this->transfer->accept($this->group->fresh(), $this->participant))
        ->toThrow(ValidationException::class, 'kein Mitglied');
});

it('hands the group over through the members page', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->assertActionVisible('transferOwnership')
        ->assertActionHidden('cancelOwnerTransfer')
        ->callAction('transferOwnership', data: ['user_id' => $this->participant->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Anfrage an Paula Teil verschickt.')
        ->assertActionHidden('transferOwnership')
        ->assertActionVisible('cancelOwnerTransfer')
        ->assertSee('Owner-Übergabe an Paula Teil angefragt.');

    Mail::assertSent(OwnerTransferRequestedMail::class, fn (OwnerTransferRequestedMail $mail): bool => $mail->membersUrl === Members::getUrl());

    actingInGroup($this->moderator, $this->group->fresh());

    Livewire::test(Members::class)
        ->assertActionHidden('acceptOwnerTransfer')
        ->assertActionHidden('declineOwnerTransfer')
        ->assertActionHidden('cancelOwnerTransfer');

    actingInGroup($this->participant, $this->group->fresh());

    Livewire::test(Members::class)
        ->assertSee('Olga Owner möchte dir die Owner-Rolle übergeben.')
        ->callAction('acceptOwnerTransfer')
        ->assertNotified('Du bist jetzt Owner der Gruppe.');

    expect($this->group->fresh()->owner_id)->toBe($this->participant->id);
});

it('hides the handover from everybody but the owner', function () {
    actingInGroup($this->moderator, $this->group);

    Livewire::test(Members::class)
        ->assertActionHidden('transferOwnership')
        ->assertActionHidden('acceptOwnerTransfer');
});

it('offers the handover only when there is somebody to hand over to', function () {
    $loner = User::factory()->create();
    $solo = Group::factory()->create(['owner_id' => $loner->id]);

    actingInGroup($loner, $solo);

    Livewire::test(Members::class)->assertActionHidden('transferOwnership');
});

it('shows the request as a task to the asked person', function () {
    $this->transfer->request($this->group, $this->participant, $this->owner);

    actingInGroup($this->participant, $this->group->fresh());

    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->toContain('Owner-Rolle für „'.$this->group->name.'“ übernehmen?');

    actingInGroup($this->moderator, $this->group->fresh());

    expect(collect(Livewire::test(MyTasks::class)->viewData('tasks'))->pluck('title'))
        ->not->toContain('Owner-Rolle für „'.$this->group->name.'“ übernehmen?');
});
