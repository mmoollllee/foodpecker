<?php

namespace App\Services\Rounds;

use App\Enums\RoundPhase;
use App\Models\Round;
use App\Models\RoundPackagePrice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes the prices the suppliers confirmed in a round over into the
 * catalog, so the next round estimates with them. Only once the final order
 * stands: until then the difference to the list shows the group what the
 * suppliers changed.
 */
class CatalogPrices
{
    /**
     * @var array<int, RoundPhase>
     */
    public const PHASES = [RoundPhase::Payment, RoundPhase::Ordering, RoundPhase::Delivery, RoundPhase::Pickup, RoundPhase::Completed];

    /**
     * Confirmed prices that differ from the list price they were confirmed
     * against — as long as the catalog still shows that list price, so a
     * newer price (taken over from a later round, say) is never overwritten
     * and a price is taken over only once. Only products the user may
     * change; packages that couldn't be delivered keep their price.
     *
     * @return Collection<int, CatalogPriceChange>
     */
    public function pendingChanges(Round $round, User $user): Collection
    {
        return $round->packagePrices()
            ->with('priceTier.product')
            ->whereNotNull('price_cents')
            ->whereNotNull('list_price_cents')
            ->where('is_available', true)
            ->get()
            ->filter(fn (RoundPackagePrice $price): bool => $price->priceTier?->product !== null
                && $price->price_cents !== $price->list_price_cents
                && $price->priceTier->price_cents === $price->list_price_cents
                && $user->can('update', $price->priceTier->product))
            ->map(fn (RoundPackagePrice $price): CatalogPriceChange => new CatalogPriceChange($price->priceTier, $price->priceTier->price_cents, $price->price_cents))
            ->sortBy(fn (CatalogPriceChange $change): array => [$change->tier->product->name, $change->tier->sort_order, (float) $change->tier->package_amount])
            ->values();
    }

    /**
     * @return int Number of prices taken over.
     */
    public function adopt(Round $round, User $by): int
    {
        if (! in_array($round->phase, self::PHASES, true)) {
            throw ValidationException::withMessages(['prices' => 'Preise lassen sich ins Sortiment übernehmen, sobald die finale Bestellung steht.']);
        }

        $changes = $this->pendingChanges($round, $by);

        if ($changes->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($changes, $round, $by): void {
            foreach ($changes as $change) {
                $change->tier->update(['price_cents' => $change->toCents]);
            }

            $round->logActivity('catalog_prices_adopted', ['count' => $changes->count()], $by);
        });

        return $changes->count();
    }
}
