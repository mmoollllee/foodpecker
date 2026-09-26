<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\NotificationKind;
use App\Enums\ProductUnit;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\ProposalItem;
use App\Services\Estimates\PriceEstimator;
use App\Services\Estimates\RoundEstimate;
use App\Services\Money\OrderCalculator;
use App\Services\Money\ProposalTotals;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalChanges;
use App\Services\Proposals\ProposalComparison;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ConsensusChecker;
use App\Services\Rounds\ParticipantExclusion;
use App\Services\Rounds\PhaseTransitioner;
use App\Services\Rounds\ProposalConsensus;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Order proposals: the draft that calculates itself from the carts and the
 * suppliers' feedback, corrections by hand, voting and choosing the final
 * order.
 */
trait InteractsWithProposals
{
    /**
     * @var array<int, ProposalConsensus>
     */
    protected array $consensusCache = [];

    /**
     * @var array<int, ProposalTotals>
     */
    protected array $totalsCache = [];

    /**
     * @var array<int, array<int, string>>
     */
    protected array $exclusionCandidatesCache = [];

    /**
     * @var array<int, ProposalChanges|null>
     */
    protected array $changesCache = [];

    protected ?RoundEstimate $listPriceEstimate = null;

    /**
     * Normally the draft exists as soon as the adjustment phase starts —
     * this brings it back, e.g. after it was deleted.
     */
    public function createProposalAction(): Action
    {
        return Action::make('createProposal')
            ->label('Bestellvorschlag erstellen')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('info')
            ->visible(fn (): bool => $this->getRound()->phase === RoundPhase::Negotiating
                && $this->currentUser()->can('propose', $this->getRound())
                && $this->draftForVote() === null)
            ->requiresConfirmation()
            ->modalDescription('Der Vorschlag wird aus den Warenkörben und den Rückmeldungen der Lieferanten berechnet. Mengen und Gebinde kannst du danach anpassen.')
            ->action(function (): void {
                $created = $this->attempt(
                    fn () => app(ProposalBuilder::class)->createFromCarts($this->getRound(), $this->currentUser(), ['title' => 'Bestellvorschlag']),
                    'Vorschlag konnte nicht erstellt werden',
                );

                if ($created) {
                    Notification::make()->title('Bestellvorschlag erstellt.')->success()->send();
                }
            });
    }

    public function editProposalAction(): Action
    {
        return Action::make('editProposal')
            ->label('Umbenennen')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalFromArguments($arguments)))
            ->fillForm(fn (array $arguments): array => $this->proposalFromArguments($arguments)?->only(['title', 'description']) ?? [])
            ->schema([
                TextInput::make('title')
                    ->label('Titel')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Begründung / Hinweise')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->proposalFromArguments($arguments)?->update($data);

                Notification::make()->title('Vorschlag gespeichert.')->success()->send();
                $this->refreshRound();
            });
    }

    /**
     * How many packages of each size are ordered — the cheapest combination
     * unless fixed by hand.
     */
    public function editPackagesAction(): Action
    {
        return Action::make('editPackages')
            ->label('Gebinde ändern')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(function (array $arguments): bool {
                $item = $this->proposalItemFromArguments($arguments);

                return $item !== null && $item->isPortioned() && $this->canEditProposal($item->proposal);
            })
            ->modalHeading(fn (array $arguments): string => 'Gebinde: '.($this->proposalItemFromArguments($arguments)?->product?->name ?? ''))
            ->modalDescription('Foodpecker sucht die günstigste Kombination der Gebindegrößen. Hier kannst du die Anzahl selbst festlegen — die Mengen werden dann neu verteilt.')
            ->modalWidth('lg')
            ->fillForm(function (array $arguments): array {
                $item = $this->proposalItemFromArguments($arguments);

                return [
                    'automatic' => ! $item?->packages_fixed,
                    'counts' => $item?->product?->priceTiers
                        ->mapWithKeys(fn (PriceTier $tier): array => [$tier->id => $item->packages->firstWhere('price_tier_id', $tier->id)?->count ?? 0])
                        ->all() ?? [],
                ];
            })
            ->schema(fn (array $arguments): array => [
                Toggle::make('automatic')
                    ->label('Automatisch die günstigste Kombination')
                    ->live(),
                Grid::make(2)
                    ->schema($this->proposalItemFromArguments($arguments)?->product?->priceTiers
                        ->map(fn (PriceTier $tier): TextInput => TextInput::make('counts.'.$tier->id)
                            ->label($tier->label)
                            ->integer()
                            ->minValue(0)
                            ->maxValue(999)
                            ->suffix('×'))
                        ->all() ?? [])
                    ->visible(fn (Get $get): bool => ! $get('automatic')),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = $this->proposalItemFromArguments($arguments);
                $counts = $data['automatic'] ? null : array_map('intval', $data['counts'] ?? []);

                if ($item && $this->attempt(fn () => app(ProposalBuilder::class)->setPackageCounts($item, $counts), 'Gebinde nicht geändert')) {
                    Notification::make()->title('Gebinde übernommen, Mengen neu verteilt.')->success()->send();
                }
            });
    }

    /**
     * Rounds the flexible shares of every position in a draft to
     * friendlier amounts — half kilograms, 100 grams.
     */
    public function roundProposalAction(): Action
    {
        return Action::make('roundProposal')
            ->label('Mengen runden')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            ->size('sm')
            ->tooltip('Flexible Mengen auf glattere Werte runden, z. B. 0,5 kg')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($proposal = $this->proposalFromArguments($arguments))
                && $proposal->items->contains(fn (ProposalItem $item): bool => $this->defaultRounding($item) !== null))
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $rounded = $proposal && $this->attempt(function () use ($proposal): void {
                    foreach ($proposal->items as $item) {
                        if (($step = $this->defaultRounding($item)) !== null) {
                            app(ProposalBuilder::class)->setRounding($item, $step);
                        }
                    }
                }, 'Runden nicht möglich');

                if ($rounded) {
                    Notification::make()->title('Mengen gerundet.')->body('Pro Position lässt sich die Rundung einzeln ändern.')->success()->send();
                }
            });
    }

    public function withdrawProposalAction(): Action
    {
        return Action::make('withdrawProposal')
            ->label('Zurückziehen')
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('gray')
            ->size('sm')
            ->visible(function (array $arguments): bool {
                $proposal = $this->proposalFromArguments($arguments);

                return $proposal !== null
                    && $proposal->isPublished()
                    && ($proposal->isProposedBy($this->currentUser()) || $this->canManage());
            })
            ->modalHeading('Vorschlag zurückziehen?')
            ->schema([
                Textarea::make('reason')
                    ->label('Grund (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $withdrawn = $proposal && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->withdraw($proposal, $this->currentUser(), $data['reason'] ?? null),
                    'Zurückziehen nicht möglich',
                );

                if ($withdrawn) {
                    Notification::make()->title('Vorschlag zurückgezogen.')->success()->send();
                }
            });
    }

    public function deleteProposalAction(): Action
    {
        return Action::make('deleteProposal')
            ->label('Löschen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalFromArguments($arguments)))
            ->requiresConfirmation()
            ->modalHeading('Entwurf löschen?')
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $deleted = $proposal && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->delete($proposal),
                    'Löschen nicht möglich',
                );

                if ($deleted) {
                    Notification::make()->title('Entwurf gelöscht.')->success()->send();
                }
            });
    }

    /**
     * Counter-proposals and new versions start as a copy — with the prices
     * the suppliers confirmed and the corrections made so far.
     */
    public function newProposalVersionAction(): Action
    {
        return Action::make('newProposalVersion')
            ->label(fn (): string => $this->canManage() ? 'Neue Version' : 'Gegenvorschlag machen')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->size('sm')
            ->tooltip('Übernimmt Gebinde, Preise und Korrekturen und rechnet mit den aktuellen Warenkörben neu — ohne ausgeschlossene Teilnehmer.')
            ->visible(function (array $arguments): bool {
                $proposal = $this->proposalFromArguments($arguments);

                return $proposal !== null
                    && ! $proposal->isDraft()
                    && $this->currentUser()->can('propose', $this->getRound());
            })
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->canManage() ? 'Neue Version erstellen?' : 'Gegenvorschlag machen?')
            ->modalDescription('Die Kopie startet als Entwurf, den du anpassen kannst. Danach geht sie zur Abstimmung.')
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $created = $proposal && $this->attempt(
                    fn () => app(ProposalBuilder::class)->createNewVersion($proposal, $this->currentUser()),
                    'Neue Version nicht möglich',
                );

                if ($created) {
                    Notification::make()->title('Entwurf angelegt.')->body('Pass ihn an und gib ihn dann zur Abstimmung frei.')->success()->send();
                }
            });
    }

    /**
     * Counter-proposals go up for a vote on their own; the lead's proposal
     * goes up with the switch to the confirmation phase.
     */
    public function publishProposalAction(): Action
    {
        return Action::make('publishProposal')
            ->label('Zur Abstimmung freigeben')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('info')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalFromArguments($arguments))
                && $this->getRound()->phase === RoundPhase::Finalizing)
            ->requiresConfirmation()
            ->modalHeading('Zur Abstimmung freigeben?')
            ->modalDescription('Danach lässt sich der Vorschlag nicht mehr ändern — für Änderungen gibt es eine neue Version.')
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $published = $proposal && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->publish($proposal, $this->currentUser()),
                    'Freigabe nicht möglich',
                );

                if ($published) {
                    Notification::make()->title('Vorschlag ist zur Abstimmung freigegeben.')->success()->send();
                }
            });
    }

    public function voteUpAction(): Action
    {
        return Action::make('voteUp')
            ->label('👍')
            ->tooltip(fn (array $arguments): string => $this->decidesOn($arguments)
                ? 'Daumen hoch — passt für mich'
                : 'Du hast das nicht bestellt — deine Stimme zählt nur als Meinung.')
            ->color(fn (array $arguments): string => $this->myVote($arguments) === VoteValue::Up ? 'success' : 'gray')
            ->outlined(fn (array $arguments): bool => $this->myVote($arguments) !== VoteValue::Up)
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canVoteOn($this->proposalItemFromArguments($arguments)))
            ->action(function (array $arguments): void {
                $item = $this->proposalItemFromArguments($arguments);

                $item && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->vote($item, $this->currentUser(), VoteValue::Up),
                    'Abstimmen nicht möglich',
                );
            });
    }

    public function voteDownAction(): Action
    {
        return Action::make('voteDown')
            ->label('👎')
            ->tooltip(fn (array $arguments): string => $this->decidesOn($arguments)
                ? 'Daumen runter — mit Begründung'
                : 'Du hast das nicht bestellt — deine Stimme zählt nur als Meinung.')
            ->color(fn (array $arguments): string => $this->myVote($arguments) === VoteValue::Down ? 'danger' : 'gray')
            ->outlined(fn (array $arguments): bool => $this->myVote($arguments) !== VoteValue::Down)
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canVoteOn($this->proposalItemFromArguments($arguments)))
            ->modalHeading('Daumen runter')
            ->modalDescription('Sag dem Lead, was nicht passt — sonst kann er den Vorschlag nicht verbessern.')
            ->modalSubmitActionLabel('Abstimmen')
            ->fillForm(fn (array $arguments): array => [
                'reason' => $this->proposalItemFromArguments($arguments)?->votes
                    ->firstWhere('user_id', $this->currentUser()->id)?->reason,
            ])
            ->schema([
                Textarea::make('reason')
                    ->label('Begründung')
                    ->required()
                    ->minLength(ProposalWorkflow::MIN_REASON_LENGTH)
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = $this->proposalItemFromArguments($arguments);

                $item && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->vote($item, $this->currentUser(), VoteValue::Down, $data['reason']),
                    'Abstimmen nicht möglich',
                );
            });
    }

    /**
     * One click instead of a thumbs up per position.
     */
    public function approveAllAction(): Action
    {
        return Action::make('approveAll')
            ->label('Allem zustimmen')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->size('sm')
            ->visible(function (array $arguments): bool {
                $proposal = $this->proposalFromArguments($arguments);

                return $proposal !== null
                    && $proposal->isPublished()
                    && $this->currentUser()->can('vote', $this->getRound())
                    && $this->itemsAwaitingMyApproval($proposal) !== [];
            })
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $approved = $proposal && $this->attempt(function () use ($proposal): void {
                    DB::transaction(function () use ($proposal): void {
                        foreach ($this->itemsAwaitingMyApproval($proposal) as $item) {
                            app(ProposalWorkflow::class)->vote($item, $this->currentUser(), VoteValue::Up);
                        }
                    });
                }, 'Abstimmen nicht möglich');

                if ($approved) {
                    Notification::make()->title('Du hast allen deinen Positionen zugestimmt.')->success()->send();
                }
            });
    }

    /**
     * Choosing the final order and starting the payment phase are one step.
     */
    public function chooseProposalAction(): Action
    {
        return Action::make('chooseProposal')
            ->label('Als finale Bestellung wählen')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->getRound()->phase === RoundPhase::Finalizing
                && $this->canManage()
                && $this->proposalFromArguments($arguments)?->isPublished())
            ->disabled(fn (array $arguments): bool => ! ($this->consensusFor($this->proposalFromArguments($arguments))?->isUnanimous() ?? false))
            ->tooltip(function (array $arguments): ?string {
                $consensus = $this->consensusFor($this->proposalFromArguments($arguments));

                return $consensus && ! $consensus->isUnanimous()
                    ? app(ProposalWorkflow::class)->explainMissingConsensus($consensus)
                    : null;
            })
            ->modalHeading('Bestellung festmachen?')
            ->modalDescription('Alle Beteiligten haben zugestimmt. Die Runde geht in die Zahlungsphase — alle sehen ihren Betrag.')
            ->modalSubmitActionLabel('Festmachen')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => $this->notificationDefaults(RoundPhase::Payment))
            ->schema($this->notificationFields())
            ->action(function (array $data, array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $chosen = $proposal && $this->attempt(function () use ($proposal): void {
                    DB::transaction(function () use ($proposal): void {
                        app(ProposalWorkflow::class)->choose($proposal, $this->currentUser());
                        app(PhaseTransitioner::class)->transition($this->getRound()->fresh(), RoundPhase::Payment, $this->currentUser());
                    });
                }, 'Auswahl nicht möglich');

                if (! $chosen) {
                    return;
                }

                $this->selectedPhase = null;
                Notification::make()->title('Finale Bestellung gewählt — jetzt wird bezahlt.')->success()->send();

                if ($data['notify'] ?? false) {
                    $this->sendNotification(NotificationKind::PaymentDue, $data);
                }
            });
    }

    /**
     * Sets what somebody gets of a position by hand; an empty value hands
     * it back to the automatic distribution.
     */
    public function updateAllocation(int $itemId, int $userId, string $value): void
    {
        $item = $this->proposalItemFromArguments(['item' => $itemId]);

        if (! $this->canEditProposal($item?->proposal)) {
            return;
        }

        $quantity = $this->parseQuantity($value);

        if (trim($value) !== '' && $quantity === null) {
            Notification::make()->title('Bitte eine Menge wie 3,5 eingeben.')->danger()->send();

            return;
        }

        $this->attempt(fn () => app(ProposalBuilder::class)->setAllocation($item, $userId, $quantity), 'Menge nicht geändert');
    }

    public function resetAllocation(int $itemId, int $userId): void
    {
        $item = $this->proposalItemFromArguments(['item' => $itemId]);

        if ($this->canEditProposal($item?->proposal)) {
            $this->attempt(fn () => app(ProposalBuilder::class)->setAllocation($item, $userId, null), 'Menge nicht geändert');
        }
    }

    public function updateRounding(int $itemId, string $value): void
    {
        $item = $this->proposalItemFromArguments(['item' => $itemId]);

        if (! $this->canEditProposal($item?->proposal)) {
            return;
        }

        $step = $value === '' ? null : (float) $value;

        if ($step !== null && ! array_key_exists($value, $this->roundingOptions($item))) {
            return;
        }

        $this->attempt(fn () => app(ProposalBuilder::class)->setRounding($item, $step), 'Runden nicht möglich');
    }

    /**
     * Coarser steps a position's flexible shares can be rounded to — in
     * whole portions, so a 350 g pack is never split into half kilograms.
     *
     * @return array<string, string> step => label
     */
    public function roundingOptions(ProposalItem $item): array
    {
        if (! $item->isPortioned()) {
            return [];
        }

        $unit = $item->product?->unit;
        $steps = match ($unit) {
            ProductUnit::Kilogram, ProductUnit::Liter => [0.25, 0.5, 1.0],
            ProductUnit::Gram, ProductUnit::Milliliter => [50.0, 100.0, 250.0, 500.0],
            default => [],
        };

        return collect($steps)
            ->filter(fn (float $step): bool => $step > (float) $item->portion_size + 0.0001 && $item->isWholePortions($step))
            ->mapWithKeys(fn (float $step): array => [(string) $step => CartItem::formatAmount($step, $item->product?->unitLabel())])
            ->all();
    }

    public function consensusFor(?OrderProposal $proposal): ?ProposalConsensus
    {
        if ($proposal === null) {
            return null;
        }

        return $this->consensusCache[$proposal->id] ??= app(ConsensusChecker::class)->evaluate($proposal);
    }

    public function totalsFor(OrderProposal $proposal): ProposalTotals
    {
        return $this->totalsCache[$proposal->id] ??= app(OrderCalculator::class)->calculate($proposal);
    }

    /**
     * The version a proposal copies, if it is still around.
     */
    public function baseVersionOf(OrderProposal $proposal): ?OrderProposal
    {
        return $proposal->based_on_proposal_id !== null
            ? $this->getRound()->proposals->firstWhere('id', $proposal->based_on_proposal_id)
            : null;
    }

    /**
     * What changed compared to the version the proposal copies.
     */
    public function changesFor(OrderProposal $proposal): ?ProposalChanges
    {
        if (! array_key_exists($proposal->id, $this->changesCache)) {
            $base = $this->baseVersionOf($proposal);

            $this->changesCache[$proposal->id] = $base !== null
                ? app(ProposalComparison::class)->compare($base, $proposal)
                : null;
        }

        return $this->changesCache[$proposal->id];
    }

    /**
     * What everybody would have paid with the list prices — to show what the
     * suppliers' feedback changed.
     */
    public function listPriceEstimate(): RoundEstimate
    {
        return $this->listPriceEstimate ??= app(PriceEstimator::class)->estimate($this->getRound(), listPrices: true);
    }

    /**
     * People the lead may exclude while preparing the draft.
     *
     * @return array<int, string> user id => what they did not agree to
     */
    public function exclusionCandidates(OrderProposal $draft): array
    {
        return $this->exclusionCandidatesCache[$draft->id] ??= app(ParticipantExclusion::class)->candidatesFor($draft);
    }

    public function canEditProposal(?OrderProposal $proposal): bool
    {
        return $proposal !== null
            && $proposal->isDraft()
            && in_array($this->getRound()->phase, [RoundPhase::Negotiating, RoundPhase::Finalizing], true)
            && ($proposal->isProposedBy($this->currentUser()) || $this->canManage());
    }

    protected function forgetComputedProposalData(): void
    {
        $this->consensusCache = [];
        $this->totalsCache = [];
        $this->exclusionCandidatesCache = [];
        $this->changesCache = [];
        $this->listPriceEstimate = null;
    }

    protected function canVoteOn(?ProposalItem $item): bool
    {
        return $item !== null
            && $item->proposal->isPublished()
            && $this->currentUser()->can('vote', $this->getRound());
    }

    /**
     * Only the votes of people who ordered a position decide on it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function decidesOn(array $arguments): bool
    {
        return (bool) $this->proposalItemFromArguments($arguments)?->stakeholderIds()->contains($this->currentUser()->id);
    }

    /**
     * @return array<int, ProposalItem>
     */
    protected function itemsAwaitingMyApproval(OrderProposal $proposal): array
    {
        $userId = $this->currentUser()->id;

        return $proposal->items
            ->filter(fn (ProposalItem $item): bool => $item->stakeholderIds()->contains($userId)
                && $item->votes->firstWhere('user_id', $userId)?->value !== VoteValue::Up)
            ->values()
            ->all();
    }

    /**
     * Rounding "Mengen runden" applies: half kilograms or 100 grams — or the
     * next coarser step if the portions are that big already.
     */
    protected function defaultRounding(ProposalItem $item): ?float
    {
        $atLeast = match ($item->product?->unit) {
            ProductUnit::Kilogram, ProductUnit::Liter => 0.5,
            ProductUnit::Gram, ProductUnit::Milliliter => 100.0,
            default => null,
        };

        if ($atLeast === null) {
            return null;
        }

        $step = collect(array_keys($this->roundingOptions($item)))
            ->map(fn (int|string $step): float => (float) $step)
            ->first(fn (float $step): bool => $step >= $atLeast);

        return $step !== null && abs($step - (float) $item->rounding_step) > 0.0001 ? $step : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function myVote(array $arguments): ?VoteValue
    {
        return $this->proposalItemFromArguments($arguments)?->votes
            ->firstWhere('user_id', $this->currentUser()->id)?->value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function proposalFromArguments(array $arguments): ?OrderProposal
    {
        return $this->getRound()->proposals->firstWhere('id', (int) ($arguments['proposal'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function proposalItemFromArguments(array $arguments): ?ProposalItem
    {
        $itemId = (int) ($arguments['item'] ?? 0);

        foreach ($this->getRound()->proposals as $proposal) {
            if ($item = $proposal->items->firstWhere('id', $itemId)) {
                return $item->setRelation('proposal', $proposal);
            }
        }

        return null;
    }

    /**
     * German or English notation: "3,5" or "3.5" — and "2.500" or
     * "2.500,5" with a thousands separator, like Money::parse() reads it.
     * Null for anything else.
     */
    protected function parseQuantity(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return preg_match('/^\d+(\.\d{1,3})?$/', $value) === 1 ? (float) $value : null;
    }
}
