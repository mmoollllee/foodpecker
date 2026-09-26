<?php

namespace App\Services\Proposals;

use App\Models\OrderProposal;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\ProposalItemPackage;
use App\Models\Supplier;
use App\Services\Money\OrderCalculator;
use App\Services\Money\ParticipantTotals;
use Illuminate\Support\Collection;

/**
 * What changed between two versions of an order proposal: per product the
 * packages, their prices and what everybody gets, then shipping and what
 * everybody pays.
 */
class ProposalComparison
{
    private const EPSILON = 0.001;

    public function __construct(private OrderCalculator $calculator) {}

    public function compare(OrderProposal $before, OrderProposal $after): ProposalChanges
    {
        $relations = ['items.product', 'items.packages', 'items.allocations.user'];
        $before->loadMissing($relations);
        $after->loadMissing($relations);

        $itemsBefore = $before->items->keyBy('product_id');
        $itemsAfter = $after->items->keyBy('product_id');

        $items = $itemsBefore->keys()
            ->merge($itemsAfter->keys())
            ->unique()
            ->map(fn (int|string $productId): ?ItemChange => $this->compareItem($itemsBefore->get($productId), $itemsAfter->get($productId)))
            ->filter()
            ->sortBy(fn (ItemChange $change): string => $change->product)
            ->values()
            ->all();

        $totalsBefore = $this->calculator->calculate($before);
        $totalsAfter = $this->calculator->calculate($after);

        return new ProposalChanges(
            items: $items,
            shipping: $this->shippingChanges($before, $after),
            subtotals: $this->subtotalChanges($totalsBefore->perParticipant, $totalsAfter->perParticipant, $before, $after),
            totalBefore: $totalsBefore->grandTotalCents,
            totalAfter: $totalsAfter->grandTotalCents,
        );
    }

    private function compareItem(?ProposalItem $before, ?ProposalItem $after): ?ItemChange
    {
        $item = $after ?? $before;
        $kind = match (true) {
            $before === null => ItemChange::ADDED,
            $after === null => ItemChange::REMOVED,
            default => ItemChange::CHANGED,
        };

        $packagesBefore = $before?->describePackages();
        $packagesAfter = $after?->describePackages();
        $prices = $before && $after ? $this->priceChanges($before, $after) : [];
        $quantities = $this->quantityChanges($before, $after);

        if ($kind === ItemChange::CHANGED && $packagesBefore === $packagesAfter && $prices === [] && $quantities === []) {
            return null;
        }

        return new ItemChange(
            kind: $kind,
            product: $item->product?->name ?? '—',
            unit: $item->product?->unitLabel() ?? '',
            packagesBefore: $packagesBefore,
            packagesAfter: $packagesAfter,
            prices: $prices,
            quantities: $quantities,
        );
    }

    /**
     * Packages both versions order, at a different price.
     *
     * @return array<int, array{label: string, before: int, after: int}>
     */
    private function priceChanges(ProposalItem $before, ProposalItem $after): array
    {
        $pricesBefore = $before->packages->keyBy('price_tier_id');

        return $after->packages
            ->filter(fn (ProposalItemPackage $package): bool => $pricesBefore->has($package->price_tier_id)
                && $pricesBefore->get($package->price_tier_id)->price_cents !== $package->price_cents)
            ->map(fn (ProposalItemPackage $package): array => [
                'label' => $package->label,
                'before' => (int) $pricesBefore->get($package->price_tier_id)->price_cents,
                'after' => (int) $package->price_cents,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{user_id: int, name: string, before: float, after: float}>
     */
    private function quantityChanges(?ProposalItem $before, ?ProposalItem $after): array
    {
        $amountsBefore = $this->amountsByUser($before);
        $amountsAfter = $this->amountsByUser($after);
        $names = $this->namesByUser($before)->union($this->namesByUser($after));

        return $amountsBefore->keys()
            ->merge($amountsAfter->keys())
            ->unique()
            ->map(fn (int $userId): array => [
                'user_id' => $userId,
                'name' => $names->get($userId, '—'),
                'before' => $amountsBefore->get($userId, 0.0),
                'after' => $amountsAfter->get($userId, 0.0),
            ])
            ->filter(fn (array $change): bool => abs($change['after'] - $change['before']) > self::EPSILON)
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{supplier: string, before: int, after: int}>
     */
    private function shippingChanges(OrderProposal $before, OrderProposal $after): array
    {
        $supplierIds = collect(array_keys($before->shipping_by_supplier ?? []))
            ->merge(array_keys($after->shipping_by_supplier ?? []))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $before->shippingCentsFor($id) === $after->shippingCentsFor($id));

        if ($supplierIds->isEmpty()) {
            return [];
        }

        $names = Supplier::query()->whereIn('id', $supplierIds)->pluck('name', 'id');

        return $supplierIds
            ->map(fn (int $id): array => [
                'supplier' => $names->get($id, '—'),
                'before' => $before->shippingCentsFor($id),
                'after' => $after->shippingCentsFor($id),
            ])
            ->sortBy('supplier')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, ParticipantTotals>  $before
     * @param  array<int, ParticipantTotals>  $after
     * @return array<int, array{user_id: int, name: string, before: int, after: int}>
     */
    private function subtotalChanges(array $before, array $after, OrderProposal $proposalBefore, OrderProposal $proposalAfter): array
    {
        $subtotalsBefore = collect($before)->mapWithKeys(fn (ParticipantTotals $totals): array => [$totals->userId => $totals->subtotalCents()]);
        $subtotalsAfter = collect($after)->mapWithKeys(fn (ParticipantTotals $totals): array => [$totals->userId => $totals->subtotalCents()]);
        $names = $proposalBefore->items->merge($proposalAfter->items)
            ->reduce(fn (Collection $names, ProposalItem $item): Collection => $names->union($this->namesByUser($item)), collect());

        return $subtotalsBefore->keys()
            ->merge($subtotalsAfter->keys())
            ->unique()
            ->map(fn (int $userId): array => [
                'user_id' => $userId,
                'name' => $names->get($userId, '—'),
                'before' => (int) $subtotalsBefore->get($userId, 0),
                'after' => (int) $subtotalsAfter->get($userId, 0),
            ])
            ->reject(fn (array $change): bool => $change['before'] === $change['after'])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, float>
     */
    private function amountsByUser(?ProposalItem $item): Collection
    {
        return ($item?->allocations ?? collect())
            ->filter(fn (ProposalAllocation $allocation): bool => (float) $allocation->quantity > self::EPSILON)
            ->mapWithKeys(fn (ProposalAllocation $allocation): array => [(int) $allocation->user_id => (float) $allocation->quantity]);
    }

    /**
     * @return Collection<int, string>
     */
    private function namesByUser(?ProposalItem $item): Collection
    {
        return ($item?->allocations ?? collect())
            ->mapWithKeys(fn (ProposalAllocation $allocation): array => [(int) $allocation->user_id => $allocation->user?->first_name ?? '—']);
    }
}
