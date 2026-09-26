<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Auth\Register;
use App\Http\Controllers\InvitationController;
use App\Mail\GroupInvitationMail;
use App\Models\Group;
use App\Models\User;
use App\Services\Invitations\GroupInvitationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Speisekammer']);
    $this->invitation = app(GroupInvitationService::class)->invite($this->group, 'friend@example.org', GroupRole::Participant, $this->owner);
    $this->url = $this->invitation->acceptUrl();
});

it('sends an invitation mail with a clickable button', function () {
    Mail::assertSent(GroupInvitationMail::class, function (GroupInvitationMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'href="'.e($this->url).'"')
            && str_contains($html, 'Einladung annehmen')
            && ! str_contains($html, '**');
    });
});

it('sends people who already have an account to the login and lets them join afterwards', function () {
    $friend = User::factory()->create(['email' => 'Friend@Example.org']);

    $this->get($this->url)->assertRedirect(Filament::getPanel('global')->getLoginUrl());

    expect(session('url.intended'))->toBe($this->url);

    $this->actingAs($friend)
        ->get($this->url)
        ->assertRedirect(Filament::getPanel('global')->getUrl($this->group));

    expect(Filament::getPanel('global')->getUrl($this->group))->toEndWith('/g/'.$this->group->slug);

    expect($this->group->hasMember($friend))->toBeTrue()
        ->and($this->invitation->fresh()->isAccepted())->toBeTrue();
});

it('lets new people register with the invited address and join the group', function () {
    $this->get($this->url)->assertRedirect(Filament::getPanel('global')->getRegistrationUrl());

    expect(session(InvitationController::SESSION_KEY))->toBe($this->invitation->token);

    Filament::setCurrentPanel('global');

    Livewire::test(Register::class)
        ->assertSet('data.email', 'friend@example.org')
        ->fillForm([
            'first_name' => 'Fritzi',
            'last_name' => 'Freund',
            'phone' => '+49 171 2345678',
            'postal_code' => '10827',
            'city' => 'Berlin',
            'household_size' => 2,
            'password' => 'geheim-und-lang',
            'passwordConfirmation' => 'geheim-und-lang',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $friend = User::where('email', 'friend@example.org')->firstOrFail();

    expect($this->group->hasMember($friend))->toBeTrue()
        ->and($this->group->roleOf($friend))->toBe(GroupRole::Participant);
});

it('lets an account under another address take the invitation over', function () {
    $friend = User::factory()->create(['email' => 'fritzi@elsewhere.org']);

    $this->actingAs($friend)
        ->get($this->url)
        ->assertRedirect(Filament::getPanel('global')->getUrl($this->group));

    expect($this->group->hasMember($friend))->toBeTrue()
        ->and($this->invitation->fresh()->email)->toBe('fritzi@elsewhere.org')
        ->and($this->invitation->fresh()->isAccepted())->toBeTrue();
});

it('lets new people register with another address than the invited one', function () {
    $this->get($this->url);

    Filament::setCurrentPanel('global');

    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Fritzi',
            'last_name' => 'Freund',
            'email' => 'fritzi@elsewhere.org',
            'phone' => '+49 171 2345678',
            'postal_code' => '10827',
            'city' => 'Berlin',
            'household_size' => 2,
            'password' => 'geheim-und-lang',
            'passwordConfirmation' => 'geheim-und-lang',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $friend = User::where('email', 'fritzi@elsewhere.org')->firstOrFail();

    expect($this->group->hasMember($friend))->toBeTrue()
        ->and($this->invitation->fresh()->email)->toBe('fritzi@elsewhere.org');
});

it('comes back to the invitation after signing in instead of registering', function () {
    $this->get($this->url)->assertRedirect(Filament::getPanel('global')->getRegistrationUrl());

    expect(session('url.intended'))->toBe($this->url);
});

it('does not let a second account use an accepted invitation', function () {
    $this->invitation->acceptAs(User::factory()->create(['email' => 'friend@example.org']));
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)->get($this->url)->assertRedirect();

    expect($this->group->hasMember($someoneElse))->toBeFalse();
});

it('leaves an invitation open for its invitee when somebody of the group opens the link', function () {
    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertRedirect(Filament::getPanel('global')->getUrl($this->group));

    expect($this->invitation->fresh())
        ->email->toBe('friend@example.org')
        ->isAccepted()->toBeFalse();
});

it('keeps an invitation that somebody else accepted in the meantime as it is', function () {
    $first = User::factory()->create(['email' => 'friend@example.org']);
    $second = User::factory()->create(['email' => 'second@example.org']);
    $secondsOwnInvitation = app(GroupInvitationService::class)->invite($this->group, 'second@example.org', GroupRole::Participant, $this->owner);
    $this->invitation->acceptAs($first);

    $this->invitation->fresh()->acceptAs($second);

    expect($this->invitation->fresh()->email)->toBe('friend@example.org')
        ->and($secondsOwnInvitation->fresh())->not->toBeNull()
        ->and($this->group->hasMember($second))->toBeFalse();
});

it('takes the invitation only from the signed link, not from a bare token', function () {
    Filament::setCurrentPanel('global');

    Livewire::withQueryParams(['invitation_token' => $this->invitation->token])
        ->test(Register::class)
        ->assertSet('invitation', null);
});

it('rejects tampered links', function () {
    $friend = User::factory()->create(['email' => 'friend@example.org']);

    $this->actingAs($friend)->get($this->url.'x')->assertRedirect();

    expect($this->group->hasMember($friend))->toBeFalse();
});
