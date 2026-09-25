<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\ProposalItem;
use App\Services\Distribution\PackageSpec;
use App\Services\Money\OrderCalculator;
use App\Services\Money\ProposalTotals;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ConsensusChecker;
use App\Services\Rounds\ParticipantExclusion;
use App\Services\Rounds\ProposalConsensus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * Order proposals: creating, fine-tuning with negotiated prices, voting and
 * choosing the final order.
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

    public function createProposalAction(): Action
    {
        return Action::make('createProposal')
            ->label('Vorschlag erstellen')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('info')
            ->visible(fn (): bool => $this->currentUser()->can('propose', $this->getRound()))
            ->modalDescription('Der Vorschlag wird aus den aktuellen Warenkörben berechnet — mit der jeweils günstigsten passenden Gebindegröße. Preise, Gebindegrößen und Mengen kannst du danach pro Position anpassen, bevor du ihn zur Abstimmung freigibst.')
            ->schema([
                TextInput::make('title')
                    ->label('Titel des Vorschlags')
                    ->default(fn (): string => 'Vorschlag '.now()->format('d.m.'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Begründung / Hinweise')
                    ->rows(3)
                    ->maxLength(2000),
                MoneyInput::make('shipping_cents')
                    ->label('Versandkosten gesamt')
                    ->default(0)
                    ->helperText('Werden gleichmäßig auf alle Beteiligten verteilt.'),
            ])
            ->action(function (array $data): void {
                $created = $this->attempt(
                    fn () => app(ProposalBuilder::class)->createFromCarts($this->getRound(), $this->currentUser(), $data),
                    'Vorschlag konnte nicht erstellt werden',
                );

                if ($created) {
                    Notification::make()
                        ->title('Vorschlag erstellt.')
                        ->body('Pass Preise und Gebinde pro Position an und gib ihn dann zur Abstimmung frei.')
                        ->success()
                        ->send();
                }
            });
    }

    public function editProposalAction(): Action
    {
        return Action::make('editProposal')
            ->label('Bearbeiten')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalFromArguments($arguments)))
            ->modalHeading('Vorschlag bearbeiten')
            ->fillForm(fn (array $arguments): array => $this->proposalFromArguments($arguments)?->only(['title', 'description', 'shipping_cents']) ?? [])
            ->schema([
                TextInput::make('title')
                    ->label('Titel')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Begründung / Hinweise')
                    ->rows(3)
                    ->maxLength(2000),
                MoneyInput::make('shipping_cents')
                    ->label('Versandkosten gesamt')
                    ->required()
                    ->helperText('Werden gleichmäßig auf alle Beteiligten verteilt.'),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->proposalFromArguments($arguments)?->update($data);

                Notification::make()->title('Vorschlag gespeichert.')->success()->send();
                $this->refreshRound();
            });
    }

    public function editProposalItemAction(): Action
    {
        return Action::make('editProposalItem')
            ->label('Anpassen')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalItemFromArguments($arguments)?->proposal))
            ->modalHeading(fn (array $arguments): string => 'Position anpassen: '.($this->proposalItemFromArguments($arguments)?->product?->name ?? ''))
            ->modalWidth('3xl')
            ->modalContent(fn (array $arguments): ?View => $this->tierComparisonView($this->proposalItemFromArguments($arguments)))
            ->fillForm(function (array $arguments): array {
                $item = $this->proposalItemFromArguments($arguments);

                return [
                    'price_tier_id' => $item?->price_tier_id,
                    'packages' => $item?->packages_ordered,
                    'package_price_cents' => $item?->package_price_cents,
                ];
            })
            ->schema(function (array $arguments): array {
                $item = $this->proposalItemFromArguments($arguments);

                return [
                    Select::make('price_tier_id')
                        ->label('Gebindegröße')
                        ->options(fn (): array => $item?->product?->priceTiers
                            ->mapWithKeys(fn (PriceTier $tier): array => [$tier->id => $tier->label.' · Listenpreis '.$tier->formattedPrice()])
                            ->all() ?? [])
                        ->placeholder($item?->packageLabel() ?? '—')
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $tier = PriceTier::find($state);

                            if ($tier) {
                                $set('package_price_cents', $tier->price_cents);
                                $set('packages', null);
                            }
                        }),
                    TextInput::make('packages')
                        ->label('Anzahl Gebinde')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(9999)
                        ->placeholder('automatisch aus den Warenkörben')
                        ->helperText('Leer lassen, um die Anzahl aus den Wünschen zu berechnen.'),
                    MoneyInput::make('package_price_cents')
                        ->label('Verhandelter Preis pro Gebinde')
                        ->required()
                        ->helperText('Nur für diese Runde — die Produktdaten bleiben unverändert.'),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $item = $this->proposalItemFromArguments($arguments);

                if (! $item) {
                    return;
                }

                $tier = filled($data['price_tier_id'] ?? null)
                    ? $item->product?->priceTiers->firstWhere('id', (int) $data['price_tier_id'])
                    : null;

                $spec = ($tier ? PackageSpec::fromTier($tier) : $item->toPackageSpec())
                    ->withPrice((int) $data['package_price_cents']);

                $packages = filled($data['packages'] ?? null) ? (int) $data['packages'] : null;

                $updated = $this->attempt(
                    fn () => app(ProposalBuilder::class)->updateItem($item, $spec, $packages),
                    'Position konnte nicht angepasst werden',
                );

                if ($updated) {
                    Notification::make()->title('Position neu berechnet.')->success()->send();
                }
            });
    }

    public function publishProposalAction(): Action
    {
        return Action::make('publishProposal')
            ->label('Zur Abstimmung freigeben')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('info')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canEditProposal($this->proposalFromArguments($arguments))
                && in_array($this->getRound()->phase, [RoundPhase::Negotiating, RoundPhase::Finalizing], true))
            ->requiresConfirmation()
            ->modalHeading('Zur Abstimmung freigeben?')
            ->modalDescription('Danach lässt sich der Vorschlag nicht mehr ändern — für Änderungen erstellst du eine neue Version.')
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
                    && in_array($proposal->status, [ProposalStatus::Draft, ProposalStatus::Published], true)
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
            ->color('danger')
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

    public function newProposalVersionAction(): Action
    {
        return Action::make('newProposalVersion')
            ->label('Neue Version')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->size('sm')
            ->tooltip('Übernimmt Gebinde und verhandelte Preise und rechnet mit den aktuellen Warenkörben neu — ohne ausgeschlossene Teilnehmer.')
            ->visible(function (array $arguments): bool {
                $proposal = $this->proposalFromArguments($arguments);

                return $proposal !== null
                    && ! $proposal->isDraft()
                    && $this->currentUser()->can('propose', $this->getRound());
            })
            ->requiresConfirmation()
            ->modalHeading('Neue Version erstellen?')
            ->modalDescription('Die neue Version startet als Entwurf und braucht eine neue Abstimmung.')
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $created = $proposal && $this->attempt(
                    fn () => app(ProposalBuilder::class)->createNewVersion($proposal, $this->currentUser()),
                    'Neue Version nicht möglich',
                );

                if ($created) {
                    Notification::make()->title('Neue Version als Entwurf angelegt.')->success()->send();
                }
            });
    }

    public function voteUpAction(): Action
    {
        return Action::make('voteUp')
            ->label('👍')
            ->tooltip(fn (array $arguments): string => $this->receivesShareOf($arguments)
                ? 'Daumen hoch — passt für mich'
                : 'Du bekommst hiervon nichts — deine Stimme zählt nur als Meinung.')
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
            ->tooltip(fn (array $arguments): string => $this->receivesShareOf($arguments)
                ? 'Daumen runter — mit Begründung'
                : 'Du bekommst hiervon nichts — deine Stimme zählt nur als Meinung.')
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
            ->requiresConfirmation()
            ->modalHeading('Als finale Bestellung wählen?')
            ->modalDescription('Alle Beteiligten haben zugestimmt. Danach werden die Zahlungen angelegt — wechsle anschließend in die Zahlungsphase.')
            ->action(function (array $arguments): void {
                $proposal = $this->proposalFromArguments($arguments);

                $chosen = $proposal && $this->attempt(
                    fn () => app(ProposalWorkflow::class)->choose($proposal, $this->currentUser()),
                    'Auswahl nicht möglich',
                );

                if ($chosen) {
                    Notification::make()
                        ->title('Finale Bestellung gewählt.')
                        ->body('Wechsle jetzt in die Zahlungsphase, damit alle ihre Beträge sehen.')
                        ->success()
                        ->send();
                }
            });
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
     * People the lead may exclude while preparing the draft.
     *
     * @return array<int, string> user id => what they did not agree to
     */
    public function exclusionCandidates(OrderProposal $draft): array
    {
        return $this->exclusionCandidatesCache[$draft->id] ??= app(ParticipantExclusion::class)->candidatesFor($draft);
    }

    protected function forgetComputedProposalData(): void
    {
        $this->consensusCache = [];
        $this->totalsCache = [];
        $this->exclusionCandidatesCache = [];
    }

    protected function canEditProposal(?OrderProposal $proposal): bool
    {
        return $proposal !== null
            && $proposal->isDraft()
            && ($proposal->isProposedBy($this->currentUser()) || $this->canManage());
    }

    protected function canVoteOn(?ProposalItem $item): bool
    {
        return $item !== null
            && $item->proposal->isPublished()
            && $this->currentUser()->can('vote', $this->getRound());
    }

    /**
     * Only the votes of people who get something from an item decide on it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function receivesShareOf(array $arguments): bool
    {
        return (bool) $this->proposalItemFromArguments($arguments)?->stakeholderIds()->contains($this->currentUser()->id);
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

    protected function tierComparisonView(?ProposalItem $item): ?View
    {
        if ($item?->product === null) {
            return null;
        }

        $cartItems = $this->getRound()->activeCartItems()
            ->where('product_id', $item->product_id)
            ->with(['product', 'user'])
            ->get();

        return view('filament.rounds.partials.tier-comparison', [
            'item' => $item,
            'options' => app(ProposalBuilder::class)->compareTiers($item->product->loadMissing('priceTiers'), $cartItems),
        ]);
    }
}
