<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;

/**
 * A person's own details — asked at registration and editable in the
 * profile. Everybody sharing a group sees them in the member list.
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
}
