<?php

namespace App\Services\Rounds;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Payment;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\OrderCalculator;
use App\Services\Money\ParticipantTotals;
use Illuminate\Support\Collection;

/**
 * What somebody ordered in past rounds of a group — for the personal order
 * history and as a template for the next cart.
 */
class OrderHistory
{
    public function __construct(private OrderCalculator $calculator) {}

    /**
     * Every round with a final order the user takes part in, newest first.
     *
     * @return Collection<int, array{round: Round, lines: Collection<int, array{product: string, package: string, quantity: float, unit: string, share_cents: int}>, totals: ?ParticipantTotals, payment: ?Payment}>
     */
    public function ordersFor(User $user, Group $group): Collection
    {
        return Round::query()
            ->where('group_id', $group->id)
            ->whereNotNull('chosen_proposal_id')
            ->whereHas('chosenProposal.allocations', fn ($query) => $query
                ->where('proposal_allocations.user_id', $user->id)
                ->where('proposal_allocations.quantity', '>', 0))
            ->with(['lead', 'chosenProposal.items.product', 'chosenProposal.items.allocations', 'chosenProposal.round.participants', 'payments'])
            ->orderByDesc('phase_changed_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Round $round) use ($user): array {
                $proposal = $round->chosenProposal;

                $lines = $proposal->items
                    ->map(function (ProposalItem $item) use ($user): ?array {
                        $allocation = $item->allocations->firstWhere('user_id', $user->id);

                        if ($allocation === null || (float) $allocation->quantity <= 0) {
                            return null;
                        }

                        return [
                            'product' => $item->product?->name ?? '—',
                            'package' => $item->packageLabel(),
                            'quantity' => (float) $allocation->quantity,
                            'unit' => $item->product?->unitLabel() ?? '',
                            'share_cents' => (int) $allocation->share_cents,
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'round' => $round,
                    'lines' => $lines,
                    'totals' => collect($this->calculator->calculate($proposal)->perParticipant)->firstWhere('userId', $user->id),
                    'payment' => $round->payments->firstWhere('user_id', $user->id),
                ];
            });
    }

    /**
     * @return Collection<int, array{product_id: int, product_name: string, quantity: float, unit: string, round_title: string}> keyed by product id
     */
    public function previousQuantities(User $user, Round $round): Collection
    {
        $previousRound = Round::query()
            ->where('group_id', $round->group_id)
            ->whereKeyNot($round->id)
            ->where('phase', RoundPhase::Completed->value)
            ->whereNotNull('chosen_proposal_id')
            ->whereHas('chosenProposal.allocations', fn ($query) => $query->where('proposal_allocations.user_id', $user->id))
            ->latest('phase_changed_at')
            ->latest('id')
            ->first();

        if ($previousRound === null) {
            return collect();
        }

        return ProposalAllocation::query()
            ->with('proposalItem.product')
            ->where('user_id', $user->id)
            ->where('quantity', '>', 0)
            ->whereHas('proposalItem', fn ($query) => $query->where('proposal_id', $previousRound->chosen_proposal_id))
            ->get()
            ->groupBy(fn (ProposalAllocation $allocation): int => $allocation->proposalItem->product_id)
            ->map(fn (Collection $allocations, int $productId): array => [
                'product_id' => $productId,
                'product_name' => $allocations->first()->proposalItem->product?->name ?? '—',
                'quantity' => (float) $allocations->sum('quantity'),
                'unit' => $allocations->first()->proposalItem->product?->unitLabel() ?? '',
                'round_title' => $previousRound->title,
            ]);
    }
}
