<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Forms\UserFields;
use Filament\Schemas\Components\Grid;
use Mmoollllee\FilamentUserProfile\Filament\Pages\EditProfile as ProfilePage;
use SensitiveParameter;

/**
 * The personal profile: photo, names and nickname, mobile number, location
 * and household size — plus e-mail and password on the second tab.
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
