<?php

namespace App\Services\Rounds;

use App\Enums\VoteValue;
use App\Models\OrderProposal;
use App\Models\ProposalItem;
use App\Models\ProposalVote;

/**
 * Evaluates the consensus rule of a proposal:
 *
 *   - Only stakeholders count: the people who receive a share of an item.
 *     Others may vote, but can't block an item they don't order from.
 *   - An item is approved when every stakeholder voted thumbs up.
 *   - A proposal is unanimous when every item is approved and no excluded
 *     participant is still part of it.
 */
class ConsensusChecker
{
    public function evaluate(OrderProposal $proposal): ProposalConsensus
    {
        $proposal->loadMissing(['items.allocations', 'items.votes', 'round.participants']);

        $excludedIds = $proposal->round->participants
            ->where('removed', true)
            ->map(fn ($participant): int => (int) $participant->user_id)
            ->all();

        $items = [];
        $excludedStakeholderIds = [];

        foreach ($proposal->items as $item) {
            $consensus = $this->evaluateItem($item);
            $items[$item->id] = $consensus;

            foreach (array_intersect($consensus->stakeholderIds, $excludedIds) as $userId) {
                $excludedStakeholderIds[] = $userId;
            }
        }

        return new ProposalConsensus($items, array_values(array_unique($excludedStakeholderIds)));
    }

    public function evaluateItem(ProposalItem $item): ItemConsensus
    {
        $item->loadMissing(['allocations', 'votes']);

        $stakeholderIds = $item->stakeholderIds()->all();
        $approvedBy = [];
        $rejectedBy = [];

        foreach ($item->votes as $vote) {
            /** @var ProposalVote $vote */
            $voterId = (int) $vote->user_id;

            if (! in_array($voterId, $stakeholderIds, true)) {
                continue;
            }

            if ($vote->value === VoteValue::Up) {
                $approvedBy[] = $voterId;
            } else {
                $rejectedBy[$voterId] = $vote->reason;
            }
        }

        $pendingIds = array_values(array_diff($stakeholderIds, $approvedBy, array_keys($rejectedBy)));

        return new ItemConsensus(
            itemId: $item->id,
            stakeholderIds: $stakeholderIds,
            approvedBy: $approvedBy,
            rejectedBy: $rejectedBy,
            pendingIds: $pendingIds,
        );
    }
}
