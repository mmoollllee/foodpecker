<?php

namespace App\Filament\Forms;

use App\Rules\Iban;
use Filament\Forms\Components\TextInput;

/**
 * A person's own details — asked at registration and editable in the
 * profile. Everybody sharing a group sees them in the member list; the
 * bank details only those who pay for a round the person leads.
 */
class UserFields
{
    public static function firstName(): TextInput
    {
        return TextInput::make('first_name')
            ->label('Vorname')
            ->required()
            ->maxLength(255);
    }

    public static function lastName(): TextInput
    {
        return TextInput::make('last_name')
            ->label('Nachname')
            ->required()
            ->maxLength(255);
    }

    public static function nickname(): TextInput
    {
        return TextInput::make('nickname')
            ->label('Spitzname')
            ->helperText('Wie möchtest du genannt werden – optional')
            ->maxLength(50);
    }

    public static function mobilePhone(): TextInput
    {
        return TextInput::make('phone')
            ->label('Handynummer')
            ->tel()
            ->required()
            ->minLength(6)
            ->maxLength(30)
            ->placeholder('+49 171 1234567')
            ->helperText('Für Absprachen zu Bestellung und Abholung.');
    }

    public static function postalCode(): TextInput
    {
        return TextInput::make('postal_code')
            ->label('PLZ')
            ->required()
            ->regex('/^\d{4,5}$/')
            ->validationMessages(['regex' => 'Bitte eine Postleitzahl mit 4 oder 5 Ziffern eingeben.'])
            ->maxLength(5);
    }

    public static function city(): TextInput
    {
        return TextInput::make('city')
            ->label('Wohnort')
            ->required()
            ->maxLength(100);
    }

    public static function householdSize(): TextInput
    {
        return TextInput::make('household_size')
            ->label('Personen im Haushalt')
            ->helperText('Für wie viele Personen kaufst du mit ein — dich eingeschlossen?')
            ->integer()
            ->minValue(1)
            ->maxValue(20)
            ->required();
    }

    /**
     * Where payments for rounds the person leads go. Stored without spaces,
     * shown in blocks of four.
     */
    public static function iban(): TextInput
    {
        return TextInput::make('iban')
            ->label('IBAN')
            ->placeholder('DE89 3704 0044 0532 0130 00')
            ->rule(new Iban)
            ->maxLength(42)
            ->formatStateUsing(fn (?string $state): ?string => filled($state) ? trim(chunk_split($state, 4, ' ')) : null)
            ->dehydrateStateUsing(fn (?string $state): ?string => Iban::normalize($state));
    }

    public static function bankAccountHolder(): TextInput
    {
        return TextInput::make('bank_account_holder')
            ->label('Kontoinhaber')
            ->placeholder(fn (): ?string => auth()->user()?->fullName())
            ->helperText('Leer lassen, wenn das Konto auf deinen Namen läuft.')
            ->maxLength(70);
    }

    public static function bic(): TextInput
    {
        return TextInput::make('bic')
            ->label('BIC')
            ->helperText('Optional — nur für Überweisungen aus dem Ausland nötig.')
            ->regex('/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/')
            ->validationMessages(['regex' => 'Ein BIC hat 8 oder 11 Zeichen, z. B. COBADEFFXXX.'])
            ->maxLength(11)
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null);
    }
}
