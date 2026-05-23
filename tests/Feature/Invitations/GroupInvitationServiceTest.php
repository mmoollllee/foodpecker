<?php

use App\Enums\GroupRole;
use App\Mail\GroupInvitationMail;
use App\Models\Group;
use App\Models\User;
use App\Services\Invitations\GroupInvitationService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->service = new GroupInvitationService;

    $this->owner = User::factory()->create();
    $this->group = Group::create([
        'name' => 'Gruppe', 'slug' => 'gruppe-'.uniqid(), 'owner_id' => $this->owner->id,
    ]);
});

it('lädt einen neuen Nutzer per E-Mail ein und vergibt Token + Ablaufdatum', function () {
    $invitation = $this->service->invite($this->group, 'neu@foodpecker.test', GroupRole::Participant, $this->owner);

    expect($invitation->email)->toBe('neu@foodpecker.test');
    expect($invitation->role)->toBe(GroupRole::Participant);
    expect($invitation->token)->toHaveLength(64);
    expect($invitation->expires_at)->not->toBeNull();
    expect($invitation->isPending())->toBeTrue();

    Mail::assertSent(GroupInvitationMail::class, fn ($mail) => $mail->hasTo('neu@foodpecker.test'));
});

it('aktualisiert Token bei wiederholter Einladung an dieselbe E-Mail', function () {
    $first = $this->service->invite($this->group, 'doppelt@foodpecker.test', GroupRole::Participant, $this->owner);
    $second = $this->service->invite($this->group, 'doppelt@foodpecker.test', GroupRole::Moderator, $this->owner);

    expect($first->id)->toBe($second->id);
    expect($second->fresh()->role)->toBe(GroupRole::Moderator);
    expect($second->fresh()->token)->not->toBe($first->token);
});

it('fügt den Nutzer zur Gruppe hinzu, sobald die Einladung angenommen wird', function () {
    $invitation = $this->service->invite($this->group, 'beitreten@foodpecker.test', GroupRole::Moderator, $this->owner);

    $newUser = User::factory()->create(['email' => 'beitreten@foodpecker.test']);
    $invitation->accept($newUser);

    expect($invitation->fresh()->isAccepted())->toBeTrue();
    expect($this->group->members()->where('users.id', $newUser->id)->exists())->toBeTrue();
    expect($this->group->roleOf($newUser->fresh()))->toBe(GroupRole::Moderator);
});

it('ignoriert wiederholtes Akzeptieren', function () {
    $invitation = $this->service->invite($this->group, 'einmal@foodpecker.test', GroupRole::Participant, $this->owner);
    $user = User::factory()->create(['email' => 'einmal@foodpecker.test']);

    $invitation->accept($user);
    $invitation->accept($user); // sollte nichts kaputt machen

    expect($this->group->members()->where('users.id', $user->id)->count())->toBe(1);
});
