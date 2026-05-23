<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditGroupProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Gruppen-Einstellungen';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stammdaten')
                ->schema([
                    TextInput::make('name')
                        ->label('Gruppenname')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('contact_email')
                        ->label('Kontakt-E-Mail')
                        ->email(),
                    Textarea::make('description')
                        ->label('Beschreibung')
                        ->rows(4),
                ])->columns(2),
        ]);
    }
}
