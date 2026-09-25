<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Members;
use App\Mail\GroupInvitationMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Services\Invitations\GroupInvitationService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Rule: owner and moderators invite several people at once with a
 * comma-separated list; everybody gets the same role.
 */
beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->member = User::factory()->create(['email' => 'schon@example.org']);
    $this->group->members()->attach($this->member->id, ['role' => GroupRole::Participant->value]);
});

it('reads addresses however they were pasted', function () {
    $parsed = GroupInvitationService::parseAddresses(
        "anna@example.org, Ben@Example.org;carla@example.org\n Dora Dorf <dora@example.org>, anna@example.org",
    );

    expect($parsed['valid'])->toBe(['anna@example.org', 'ben@example.org', 'carla@example.org', 'dora@example.org'])
        ->and($parsed['invalid'])->toBe([]);

    expect(GroupInvitationService::parseAddresses('anna@example.org, kein-at-zeichen, @example.org')['invalid'])
        ->toBe(['kein-at-zeichen', '@example.org']);
});

it('invites several people at once, all with the chosen role', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->callAction('invite', data: [
            'emails' => 'anna@example.org, ben@example.org, schon@example.org',
            'role' => GroupRole::Moderator->value,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('2 Einladungen verschickt.');

    expect(GroupInvitation::where('group_id', $this->group->id)->orderBy('email')->pluck('role', 'email')->map->value->all())
        ->toBe(['anna@example.org' => 'moderator', 'ben@example.org' => 'moderator']);

    Mail::assertSent(GroupInvitationMail::class, 2);
    Mail::assertNotSent(GroupInvitationMail::class, fn (GroupInvitationMail $mail): bool => $mail->hasTo('schon@example.org'));
});

it('names what could not be read before anybody is invited', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->callAction('invite', data: [
            'emails' => 'anna@example.org, anna(at)example.org',
            'role' => GroupRole::Participant->value,
        ])
        ->assertHasActionErrors(['emails']);

    expect(GroupInvitation::count())->toBe(0);
    Mail::assertNothingSent();
});

it('still invites everybody else when one mail fails', function () {
    Mail::shouldReceive('to')->andReturnUsing(function (string $address) {
        if ($address === 'kaputt@example.org') {
            throw new RuntimeException('SMTP hiccup');
        }

        return Mockery::mock()->shouldReceive('send')->andReturnNull()->getMock();
    });

    $result = app(GroupInvitationService::class)->inviteMany(
        $this->group,
        ['kaputt@example.org', 'heil@example.org', 'schon@example.org'],
        GroupRole::Participant,
        $this->owner,
    );

    expect($result)->toBe([
        'invited' => ['heil@example.org'],
        'members' => ['schon@example.org'],
        'failed' => ['kaputt@example.org'],
    ])->and(GroupInvitation::where('email', 'kaputt@example.org')->exists())->toBeTrue();
});
