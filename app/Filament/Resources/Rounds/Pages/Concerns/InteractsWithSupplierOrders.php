<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Models\RoundSupplier;
use App\Models\Supplier;
use App\Services\Rounds\SupplierOrders;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Per supplier of the final order the lead ticks off "ordered" and
 * "delivered"; everybody sees how far it got.
 */
trait InteractsWithSupplierOrders
{
    /**
     * @var Collection<int, Supplier>|null
     */
    protected ?Collection $orderSuppliersCache = null;

    /**
     * Suppliers of the final order, by name.
     *
     * @return Collection<int, Supplier>
     */
    public function orderSuppliers(): Collection
    {
        return $this->orderSuppliersCache ??= app(SupplierOrders::class)->suppliersFor($this->getRound());
    }

    public function supplierOrderFor(int $supplierId): ?RoundSupplier
    {
        return $this->getRound()->roundSuppliers->firstWhere('supplier_id', $supplierId);
    }

    public function toggleSupplierOrderedAction(): Action
    {
        return Action::make('toggleSupplierOrdered')
            ->label(fn (array $arguments): string => $this->shownAsOrdered($arguments) ? 'Doch nicht bestellt' : 'Bestellt')
            ->icon(fn (array $arguments) => $this->shownAsOrdered($arguments) ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheck)
            ->color(fn (array $arguments): string => $this->shownAsOrdered($arguments) ? 'gray' : 'success')
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canManage()
                && in_array($this->getRound()->phase, SupplierOrders::ORDER_PHASES, true)
                && $this->orderSupplierFromArguments($arguments) !== null)
            ->action(function (array $arguments): void {
                $supplier = $this->orderSupplierFromArguments($arguments);
                $ordered = ! $this->shownAsOrdered($arguments);

                $supplier && $this->attempt(
                    fn () => app(SupplierOrders::class)->markOrdered($this->getRound(), $supplier, $this->currentUser(), $ordered),
                    'Bestellung nicht abgehakt',
                );
            });
    }

    public function toggleSupplierDeliveredAction(): Action
    {
        return Action::make('toggleSupplierDelivered')
            ->label(fn (array $arguments): string => $this->shownAsDelivered($arguments) ? 'Doch nicht angekommen' : 'Angekommen')
            ->icon(fn (array $arguments) => $this->shownAsDelivered($arguments) ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedTruck)
            ->color(fn (array $arguments): string => $this->shownAsDelivered($arguments) ? 'gray' : 'success')
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canManage()
                && in_array($this->getRound()->phase, SupplierOrders::DELIVERY_PHASES, true)
                && $this->orderSupplierFromArguments($arguments) !== null)
            ->action(function (array $arguments): void {
                $supplier = $this->orderSupplierFromArguments($arguments);
                $delivered = ! $this->shownAsDelivered($arguments);

                $supplier && $this->attempt(
                    fn () => app(SupplierOrders::class)->markDelivered($this->getRound(), $supplier, $this->currentUser(), $delivered),
                    'Lieferung nicht abgehakt',
                );
            });
    }

    /**
     * What the button showed when it was clicked — a click on an outdated
     * page (a double click, lead and owner at once) switches away from that,
     * not from what is saved, so it can't undo the other click.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function shownAsOrdered(array $arguments): bool
    {
        return (bool) ($arguments['ordered'] ?? $this->supplierOrderFromArguments($arguments)?->isOrdered());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function shownAsDelivered(array $arguments): bool
    {
        return (bool) ($arguments['delivered'] ?? $this->supplierOrderFromArguments($arguments)?->isDelivered());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function orderSupplierFromArguments(array $arguments): ?Supplier
    {
        return $this->orderSuppliers()->firstWhere('id', (int) ($arguments['supplier'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function supplierOrderFromArguments(array $arguments): ?RoundSupplier
    {
        return $this->supplierOrderFor((int) ($arguments['supplier'] ?? 0));
    }
}
