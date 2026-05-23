<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Round;
use App\Models\RoundParticipant;
use Filament\Resources\Pages\CreateRecord;

class CreateRound extends CreateRecord
{
    protected static string $resource = RoundResource::class;

    public function getTitle(): string
    {
        return 'Neue Bestellrunde starten';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['phase'] = RoundPhase::Draft->value;
        $data['phase_changed_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Round $round */
        $round = $this->record;
        $round->lead?->roleIn($round->group); // touch

        // Lead automatisch als Teilnehmer registrieren
        RoundParticipant::firstOrCreate([
            'round_id' => $round->id,
            'user_id' => $round->lead_user_id,
        ]);

        $round->logActivity('created', ['title' => $round->title]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
