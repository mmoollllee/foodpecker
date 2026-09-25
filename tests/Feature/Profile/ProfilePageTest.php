<?php

use App\Enums\GroupRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Members;
use App\Models\Group;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Rule: everybody keeps their own profile — photo, names, mobile number,
 * location, household size. People sharing a group see each other's.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);

    $this->me = User::factory()->create([
        'first_name' => 'Paula',
        'last_name' => 'Teil',
        'phone' => '+49 171 2345678',
        'postal_code' => '10827',
        'city' => 'Berlin',
        'household_size' => 3,
    ]);
    $this->group->members()->attach($this->me->id, ['role' => GroupRole::Participant->value]);

    $this->stranger = User::factory()->create();
});

it('lets people change their own details', function () {
    $this->actingAs($this->me);
    Filament::setCurrentPanel('global');

    Livewire::test(EditProfile::class)
        ->assertSchemaStateSet([
            'first_name' => 'Paula',
            'phone' => '+49 171 2345678',
            'household_size' => 3,
        ])
        ->fillForm([
            'last_name' => 'Teilhaber',
            'nickname' => 'Pauli',
            'phone' => '+49 160 1111111',
            'postal_code' => '12305',
            'household_size' => 4,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->me->fresh())
        ->name->toBe('Paula Teilhaber')
        ->nickname->toBe('Pauli')
        ->phone->toBe('+49 160 1111111')
        ->postal_code->toBe('12305')
        ->household_size->toBe(4);
});

it('keeps the nickname optional', function () {
    $this->me->update(['nickname' => 'Pauli']);
    $this->actingAs($this->me);
    Filament::setCurrentPanel('global');

    Livewire::test(EditProfile::class)
        ->assertSee('Wie möchtest du genannt werden – optional')
        ->fillForm(['nickname' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->me->fresh()->nickname)->toBeNull();

    Livewire::test(EditProfile::class)
        ->fillForm(['nickname' => str_repeat('x', 51)])
        ->call('save')
        ->assertHasFormErrors(['nickname' => 'max']);
});

it('keeps the details required in the profile, too', function () {
    $this->actingAs($this->me);
    Filament::setCurrentPanel('global');

    Livewire::test(EditProfile::class)
        ->fillForm(['phone' => null, 'city' => null])
        ->call('save')
        ->assertHasFormErrors(['phone' => 'required', 'city' => 'required']);
});

it('stores a profile photo privately and shows it to the group only', function () {
    $this->actingAs($this->me);
    Filament::setCurrentPanel('global');

    Livewire::test(EditProfile::class)
        ->fillForm(['profile_photo_path' => UploadedFile::fake()->image('paula.jpg', 800, 800)])
        ->call('save')
        ->assertHasNoFormErrors();

    $me = $this->me->fresh();

    Storage::disk('local')->assertExists($me->profile_photo_path);
    expect(Filament::getUserAvatarUrl($me))->toBe($me->profilePhotoUrl());

    $this->actingAs($this->owner)->get($me->profilePhotoUrl())->assertOk();
    $this->actingAs($this->stranger)->get($me->profilePhotoUrl())->assertForbidden();
});

it('draws initials for people without a photo, without asking anybody else', function () {
    Filament::setCurrentPanel('global');

    expect(Filament::getUserAvatarUrl($this->me))->toStartWith('data:image/svg+xml;base64,')
        ->and(Filament::getTenantAvatarUrl($this->group))->toStartWith('data:image/svg+xml;base64,');
});

it('shows photo, nickname, mobile number, location and household size in the member list', function () {
    $this->owner->update(['household_size' => 2]);
    $this->me->update(['nickname' => 'Pauli']);

    actingInGroup($this->owner, $this->group);

    Livewire::test(Members::class)
        ->assertSee('Paula Teil')
        ->assertSee('„Pauli“')
        ->assertSee('+49 171 2345678')
        ->assertSee('tel:+491712345678', escape: false)
        ->assertSee('10827 Berlin')
        ->assertSee('3 Personen')
        ->assertSee('2 Haushalte mit zusammen 5 Personen.')
        ->assertSee('data:image/svg+xml;base64,', escape: false);
});
