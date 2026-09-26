<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Concerns\InteractsWithNotesAndDocuments;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithCarts;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithCatalogPrices;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithFulfillment;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithLeadHandover;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithNotifications;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithParticipants;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithPhases;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithProposals;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithSupplierFeedback;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithSupplierMails;
use App\Filament\Resources\Rounds\Pages\Concerns\InteractsWithSupplierOrders;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Round;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Dashboard of a single round. Lead-only actions are hidden for everybody
 * else — and because hidden Filament actions can't be mounted, that is
 * enforced on the server as well.
 */
class ViewRound extends ViewRecord
{
    use InteractsWithCarts;
    use InteractsWithCatalogPrices;
    use InteractsWithFulfillment;
    use InteractsWithLeadHandover;
    use InteractsWithNotesAndDocuments;
    use InteractsWithNotifications;
    use InteractsWithParticipants;
    use InteractsWithPhases;
    use InteractsWithProposals;
    use InteractsWithSupplierFeedback;
    use InteractsWithSupplierMails;
    use InteractsWithSupplierOrders;

    protected static string $resource = RoundResource::class;

    /**
     * Phase whose details and actions are shown below the phase steps.
     * Empty means the phase the round is in.
     */
    #[Url(as: 'phase')]
    public ?string $selectedPhase = null;

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
        'cartItems.product.supplier',
        'cartItems.product.priceTiers',
        'proposals.items.product.priceTiers',
        'proposals.items.product.supplier',
        'proposals.items.packages',
        'proposals.items.allocations.user',
        'proposals.items.votes.user',
        'proposals.proposedBy',
        'payments.user',
        'pickups.user',
        'pickups.pickupDate',
        'roundSuppliers',
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

        return 'Lead: '.($round->lead?->fullName() ?? '—');
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(static::RELATIONS);
    }

    /**
     * Livewire restores the round from its key alone on every request —
     * without the relations the page reads row by row.
     */
    public function hydrate(): void
    {
        parent::hydrate();

        $this->getRound()->loadMissing(static::RELATIONS);
    }

    /**
     * Starting the round and moving on sit at the top right of the phases,
     * everything else that belongs to a phase in its panel.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->acceptLeadHandoverAction(),
            $this->declineLeadHandoverAction(),
            $this->editRoundAction(),
            ActionGroup::make([
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

    /**
     * Everything that happens in a phase sits in that phase's panel, then
     * what happened so far and what belongs to the whole round.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.rounds.phases'),
                View::make('filament.rounds.history'),
                View::make('filament.rounds.overview'),
            ]);
    }

    /**
     * The phase shown in the phase panel: the one somebody clicked, else the
     * running one. A draft previews shopping, a cancelled round shows none.
     */
    public function getSelectedPhase(): ?RoundPhase
    {
        $selected = RoundPhase::tryFrom((string) $this->selectedPhase);

        if ($selected !== null && ! in_array($selected, [RoundPhase::Draft, RoundPhase::Cancelled], true)) {
            return $selected;
        }

        return match ($this->getRound()->phase) {
            RoundPhase::Draft => RoundPhase::Shopping,
            RoundPhase::Cancelled => null,
            default => $this->getRound()->phase,
        };
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
        $this->feedbackSuppliersCache = null;
        $this->orderSuppliersCache = null;
        $this->pendingCatalogPricesCache = null;
        $this->composableSuppliersCache = [];
        $this->fillMissingSupplierFeedback();
        $this->cartEstimate = null;
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
