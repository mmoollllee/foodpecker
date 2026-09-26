<?php

namespace App\Services\Rounds;

use App\Models\OrderProposal;
use App\Models\Pickup;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who gets what from the final order: one block per person for handing
 * the goods out — in the order of the pickup dates — and per product how
 * the packages are split up.
 */
class PackingList
{
    /**
     * @return Collection<int, array{user: User, pickup: ?Pickup, lines: Collection<int, array{product: string, quantity: float, unit: string, packages: string, portioned: bool}>}>
     */
    public function peopleFor(Round $round): Collection
    {
        $proposal = $this->finalOrder($round);

        if ($proposal === null) {
            return collect();
        }

        $pickups = $round->pickups()->with('pickupDate')->get()->keyBy('user_id');

        return $proposal->items
            ->flatMap(fn (ProposalItem $item): Collection => $item->allocations
                ->filter(fn (ProposalAllocation $allocation): bool => (float) $allocation->quantity > 0)
                ->map(fn (ProposalAllocation $allocation): array => ['item' => $item, 'allocation' => $allocation]))
            ->groupBy(fn (array $entry): int => (int) $entry['allocation']->user_id)
            ->map(function (Collection $entries, int $userId) use ($pickups): array {
                $user = $entries->first()['allocation']->user;

                return [
                    'user' => $user,
                    'pickup' => $pickups->get($userId),
                    'lines' => $entries
                        ->map(fn (array $entry): array => [
                            'product' => $entry['item']->product?->name ?? '—',
                            'quantity' => (float) $entry['allocation']->quantity,
                            'unit' => $entry['item']->product?->unitLabel() ?? '',
                            'packages' => $entry['allocation']->describePackages($entry['item']),
                            'portioned' => $entry['item']->isPortioned(),
                        ])
                        ->sortBy('product')
                        ->values(),
                ];
            })
            ->sortBy(fn (array $person): array => [
                $person['pickup']?->pickupDate === null,
                $person['pickup']?->pickupDate?->scheduled_at?->timestamp ?? 0,
                $person['user']?->first_name,
            ])
            ->values();
    }

    /**
     * Per product what was ordered and who gets how much of it.
     *
     * @return Collection<int, array{product: string, unit: string, packages: string, total: float, leftover: float, shares: Collection<int, array{name: string, quantity: float}>}>
     */
    public function productsFor(Round $round): Collection
    {
        $proposal = $this->finalOrder($round);

        if ($proposal === null) {
            return collect();
        }

        return $proposal->items
            ->map(fn (ProposalItem $item): array => [
                'product' => $item->product?->name ?? '—',
                'unit' => $item->product?->unitLabel() ?? '',
                'packages' => $item->describePackages(),
                'total' => $item->totalQuantity(),
                'leftover' => $item->overhang(),
                'shares' => $item->allocations
                    ->filter(fn (ProposalAllocation $allocation): bool => (float) $allocation->quantity > 0)
                    ->map(fn (ProposalAllocation $allocation): array => [
                        'name' => $allocation->user?->first_name ?? '—',
                        'quantity' => (float) $allocation->quantity,
                    ])
                    ->sortBy('name')
                    ->values(),
            ])
            ->sortBy('product')
            ->values();
    }

    private function finalOrder(Round $round): ?OrderProposal
    {
        return $round->chosenProposal()
            ->with(['items.product', 'items.packages', 'items.allocations.user'])
            ->first();
    }
}
