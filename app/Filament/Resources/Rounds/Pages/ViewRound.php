<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\ProposalStatus;
use App\Filament\Concerns\InteractsWithNotesAndDocuments;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithCarts;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithFulfillment;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithLeadHandover;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithManufacturerMails;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithNotifications;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithParticipants;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithPhases;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithProposals;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Round;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard of a single round. Lead-only actions are hidden for everybody
 * else — and because hidden Filament actions can't be mounted, that is
 * enforced on the server as well.
 */
class ViewRound extends ViewRecord
{
    use InteractsWithCarts;
    use InteractsWithFulfillment;
    use InteractsWithLeadHandover;
    use InteractsWithManufacturerMails;
    use InteractsWithNotesAndDocuments;
    use InteractsWithNotifications;
    use InteractsWithParticipants;
    use InteractsWithPhases;
    use InteractsWithProposals;

    protected static string $resource = RoundResource::class;

    /**
     * @var array<int, string>
     */
    protected const RELATIONS = [
        'lead',
        'pendingLead',
        'group',
        'pickupDates',
        'availableProducts',
        'participants.user',
        'participants.removedBy',
        'cartItems.user',
        'cartItems.product.manufacturer',
        'cartItems.product.priceTiers',
        'proposals.items.product',
        'proposals.items.priceTier',
        'proposals.items.allocations.user',
        'proposals.items.votes.user',
        'proposals.proposedBy',
        'payments.user',
        'pickups.user',
        'pickups.pickupDate',
        'notificationDrafts.preparedBy',
        'notificationDrafts.sentBy',
        'notes.user',
        'attachments',
    ];

    public function getRound(): Round
    {
        /** @var Round $round */
        $round = $this->getRecord();

        return $round;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRound()->title;
    }

    public function getSubheading(): ?string
    {
        $round = $this->getRound();

        return sprintf(
            'Lead: %s · Phase: %s · %d Teilnehmer',
            $round->lead?->fullName() ?? '—',
            $round->phase->getLabel(),
            $round->activeParticipantCount(),
        );
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(static::RELATIONS);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->acceptLeadHandoverAction(),
            $this->declineLeadHandoverAction(),
            $this->startRoundAction(),
            $this->nextPhaseAction(),
            $this->addCartItemAction(),
            $this->createProposalAction(),
            $this->editRoundAction(),
            ActionGroup::make([
                $this->generateNotificationAction(),
                $this->composeManufacturerMailAction(),
                $this->requestLeadHandoverAction(),
                $this->cancelLeadHandoverAction(),
                $this->previousPhaseAction(),
                $this->cancelRoundAction(),
                $this->deleteRoundAction(),
            ])
                ->label('Weitere Aktionen')
                ->icon(Heroicon::OutlinedBars3)
                ->button()
                ->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.rounds.partials.phase-header'),
                Tabs::make('round')
                    ->tabs([
                        Tab::make('Übersicht')
                            ->id('overview')
                            ->icon(Heroicon::OutlinedEye)
                            ->schema([View::make('filament.rounds.tabs.overview')]),
                        Tab::make('Warenkörbe')
                            ->id('carts')
                            ->icon(Heroicon::OutlinedShoppingCart)
                            ->badge(fn (): ?int => $this->getRound()->cartItems->pluck('product_id')->unique()->count() ?: null)
                            ->schema([View::make('filament.rounds.tabs.carts')]),
                        Tab::make('Vorschläge')
                            ->id('proposals')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->badge(fn (): ?int => $this->getRound()->proposals->where('status', '!=', ProposalStatus::Withdrawn)->count() ?: null)
                            ->schema([View::make('filament.rounds.tabs.proposals')]),
                        Tab::make('Zahlung & Abholung')
                            ->id('fulfillment')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([View::make('filament.rounds.tabs.fulfillment')]),
                        Tab::make('Notizen & Verlauf')
                            ->id('activity')
                            ->icon(Heroicon::OutlinedClock)
                            ->badge(fn (): ?int => ($this->getRound()->notes->count() + $this->getRound()->attachments->count()) ?: null)
                            ->schema([View::make('filament.rounds.tabs.activity')]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    public function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function canManage(): bool
    {
        return $this->currentUser()->can('manage', $this->getRound());
    }

    protected function annotatedRecord(): Model
    {
        return $this->getRound();
    }

    protected function refreshAnnotations(): void
    {
        $this->refreshRound();
    }

    /**
     * Reloads the round with everything the tabs need after a change.
     */
    public function refreshRound(): void
    {
        $this->record = $this->resolveRecord($this->getRound()->getKey());
        $this->forgetComputedProposalData();
    }

    /**
     * Runs a domain operation. A broken rule becomes a readable danger
     * notification instead of an error page.
     */
    protected function attempt(callable $operation, string $failureTitle): bool
    {
        try {
            $operation();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($failureTitle)
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            return false;
        } finally {
            $this->refreshRound();
        }
    }
}
