<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Services\Rounds\CatalogPriceChange;
use App\Services\Rounds\CatalogPrices;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Once the final order stands, the lead takes the prices the suppliers
 * confirmed over into the catalog with one click.
 */
trait InteractsWithCatalogPrices
{
    /**
     * @var Collection<int, CatalogPriceChange>|null
     */
    protected ?Collection $pendingCatalogPricesCache = null;

    public function adoptCatalogPricesAction(): Action
    {
        return Action::make('adoptCatalogPrices')
            ->label(fn (): string => 'Preise ins Sortiment übernehmen ('.$this->pendingCatalogPrices()->count().')')
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->canManage()
                && in_array($this->getRound()->phase, CatalogPrices::PHASES, true)
                && $this->pendingCatalogPrices()->isNotEmpty())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedTag)
            ->modalHeading('Bestätigte Preise ins Sortiment übernehmen?')
            ->modalDescription(fn (): Htmlable => new HtmlString(view('filament.rounds.partials.catalog-price-changes', [
                'changes' => $this->pendingCatalogPrices(),
            ])->render()))
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Übernehmen')
            ->action(function (): void {
                $count = 0;

                $adopted = $this->attempt(function () use (&$count): void {
                    $count = app(CatalogPrices::class)->adopt($this->getRound(), $this->currentUser());
                }, 'Preise nicht übernommen');

                if ($adopted) {
                    Notification::make()
                        ->title($count === 1 ? '1 Preis ins Sortiment übernommen.' : $count.' Preise ins Sortiment übernommen.')
                        ->body('Die nächste Runde schätzt mit diesen Preisen.')
                        ->success()
                        ->send();
                }
            });
    }

    /**
     * @return Collection<int, CatalogPriceChange>
     */
    public function pendingCatalogPrices(): Collection
    {
        return $this->pendingCatalogPricesCache ??= app(CatalogPrices::class)->pendingChanges($this->getRound(), $this->currentUser());
    }
}
