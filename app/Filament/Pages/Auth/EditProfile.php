<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Forms\UserFields;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\FilamentUserProfile\Filament\Pages\EditProfile as ProfilePage;
use SensitiveParameter;

/**
 * The personal profile: photo, names and nickname, mobile number, location
 * and household size — plus e-mail and password on the second tab and the
 * bank details for rounds one leads on the third.
 */
class EditProfile extends ProfilePage
{
    protected function getProfileFormComponents(): array
    {
        return [
            UserFields::firstName(),
            UserFields::lastName(),
            UserFields::nickname(),
            UserFields::mobilePhone(),
        ];
    }

    /**
     * Where somebody lives and for how many people — one row on desktop,
     * below the photo and the names.
     */
    protected function getProfileFooterComponents(): array
    {
        return [
            Grid::make(3)->schema([
                UserFields::postalCode(),
                UserFields::city(),
                UserFields::householdSize(),
            ]),
        ];
    }

    /**
     * @return array<int, Tab>
     */
    protected function getExtraTabs(): array
    {
        return [
            Tab::make('Bankverbindung')
                ->id('bank')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    Text::make('Leitest du eine Runde, überweisen dir alle ihren Anteil. Mit deiner IBAN sehen sie in der Zahlungsphase Empfänger, Betrag und Verwendungszweck — und einen GiroCode für die Banking-App.'),
                    Grid::make(2)->schema([
                        UserFields::iban(),
                        UserFields::bankAccountHolder(),
                        UserFields::bic(),
                    ]),
                ]),
        ];
    }

    /**
     * The bank details are hidden from serialization, so they are added to
     * the form by hand.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...parent::mutateFormDataBeforeFill($data),
            ...$this->getUser()->only(['iban', 'bic', 'bank_account_holder']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);
        $data['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        return $data;
    }
}
