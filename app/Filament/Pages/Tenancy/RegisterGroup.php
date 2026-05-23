<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\GroupRole;
use App\Models\Group;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RegisterGroup extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Neue Gruppe gründen';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gruppe gründen')
                ->description('Du wirst automatisch Owner dieser neuen Gruppe und kannst Mitglieder per E-Mail einladen.')
                ->schema([
                    TextInput::make('name')
                        ->label('Gruppenname')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Group::generateUniqueSlug($state ?? ''))),
                    TextInput::make('slug')
                        ->label('Slug (URL-Kürzel)')
                        ->required()
                        ->maxLength(255)
                        ->unique('groups', 'slug')
                        ->helperText('Wird in der URL verwendet, z. B. /g/speisekammer-schoeneberg'),
                    TextInput::make('contact_email')
                        ->label('Kontakt-E-Mail (optional)')
                        ->email()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Beschreibung (optional)')
                        ->rows(3)
                        ->maxLength(1000),
                ]),
        ]);
    }

    protected function handleRegistration(array $data): Group
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['owner_id'] = auth()->id();

        $group = Group::create($data);

        $group->members()->attach(auth()->id(), [
            'role' => GroupRole::Owner->value,
            'joined_at' => now(),
        ]);

        auth()->user()->forceFill(['current_group_id' => $group->id])->save();

        return $group;
    }
}
