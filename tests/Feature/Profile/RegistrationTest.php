<?php

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Rule: everybody gives their mobile number, where they live and for how many
 * people they shop when they register.
 */
beforeEach(function () {
    Filament::setCurrentPanel('global');
});

function registrationData(array $overrides = []): array
{
    return [
        'first_name' => 'Nina',
        'last_name' => 'Neu',
        'email' => 'nina@example.org',
        'phone' => '+49 171 2345678',
        'postal_code' => '10827',
        'city' => 'Berlin',
        'household_size' => 3,
        'password' => 'geheim-und-lang',
        'passwordConfirmation' => 'geheim-und-lang',
        ...$overrides,
    ];
}

it('stores mobile number, location and household size', function () {
    Livewire::test(Register::class)
        ->fillForm(registrationData())
        ->call('register')
        ->assertHasNoFormErrors();

    $nina = User::where('email', 'nina@example.org')->firstOrFail();

    expect($nina)
        ->name->toBe('Nina Neu')
        ->phone->toBe('+49 171 2345678')
        ->postal_code->toBe('10827')
        ->city->toBe('Berlin')
        ->household_size->toBe(3)
        ->and($nina->location())->toBe('10827 Berlin');
});

it('requires mobile number, location and household size', function () {
    Livewire::test(Register::class)
        ->fillForm(registrationData(['phone' => null, 'postal_code' => null, 'city' => null, 'household_size' => null]))
        ->call('register')
        ->assertHasFormErrors([
            'phone' => 'required',
            'postal_code' => 'required',
            'city' => 'required',
            'household_size' => 'required',
        ]);

    expect(User::where('email', 'nina@example.org')->exists())->toBeFalse();
});

it('checks postal code, mobile number and household size', function (array $invalid, string $field) {
    Livewire::test(Register::class)
        ->fillForm(registrationData($invalid))
        ->call('register')
        ->assertHasFormErrors([$field]);
})->with([
    'postal code with letters' => [['postal_code' => '10A27'], 'postal_code'],
    'postal code too long' => [['postal_code' => '108270'], 'postal_code'],
    'mobile number with letters' => [['phone' => 'ruf mich an'], 'phone'],
    'mobile number too short' => [['phone' => '123'], 'phone'],
    'nobody in the household' => [['household_size' => 0], 'household_size'],
    'a whole village' => [['household_size' => 50], 'household_size'],
]);
