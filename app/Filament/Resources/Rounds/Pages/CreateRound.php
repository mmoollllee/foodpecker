<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Models\Group;
use App\Models\Round;
use App\Models\RoundParticipant;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

class CreateRound extends CreateRecord
{
    use HasWizard;

    protected static string $resource = RoundResource::class;

    public function getTitle(): string
    {
        return 'Neue Bestellrunde starten';
    }

    public function getSubheading(): ?string
    {
        $group = Filament::getTenant();
        $running = $group instanceof Group ? $group->runningRound() : null;

        if ($running !== null) {
            return "Gerade läuft „{$running->title}“ — du kannst die nächste Runde schon vorbereiten und startest sie, sobald diese abgeschlossen ist.";
        }

        return 'In fünf Schritten zur neuen Runde — alle Angaben sind später noch änderbar.';
    }

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function getSteps(): array
    {
        return RoundForm::wizardSteps();
    }

    /**
     * The creator is the initial lead; handing over needs the consent of
     * the new lead and happens on the round page.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['lead_user_id'] = auth()->id();
        $data['phase'] = RoundPhase::Draft->value;
        $data['phase_changed_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Round $round */
        $round = $this->record;

        // The lead takes part in their own round.
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
