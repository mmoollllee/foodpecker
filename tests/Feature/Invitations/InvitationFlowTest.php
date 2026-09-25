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

it('does not accept an invitation for another address', function () {
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)->get($this->url)->assertRedirect();

    expect($this->group->hasMember($someoneElse))->toBeFalse()
        ->and($this->invitation->fresh()->isAccepted())->toBeFalse();
});

it('rejects tampered links', function () {
    $friend = User::factory()->create(['email' => 'friend@example.org']);

    $this->actingAs($friend)->get($this->url.'x')->assertRedirect();

    expect($this->group->hasMember($friend))->toBeFalse();
});
