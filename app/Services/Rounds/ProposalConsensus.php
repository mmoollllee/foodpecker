<?php

namespace App\Services\Rounds;

/**
 * Consensus state of a whole proposal.
 */
final readonly class ProposalConsensus
{
    /**
     * @param  array<int, ItemConsensus>  $items  Keyed by proposal item id.
     * @param  array<int, int>  $excludedStakeholderIds  Excluded participants that still receive something.
     */
    public function __construct(
        public array $items,
        public array $excludedStakeholderIds = [],
    ) {}

    /**
     * A proposal may only be chosen when every stakeholder approved every
     * item they receive a share of, and nobody excluded is still part of it.
     */
    public function isUnanimous(): bool
    {
        if ($this->items === [] || $this->excludedStakeholderIds !== []) {
            return false;
        }

        foreach ($this->items as $item) {
            if (! $item->isApproved()) {
                return false;
            }
        }

        return true;
    }

    public function forItem(int $itemId): ?ItemConsensus
    {
        return $this->items[$itemId] ?? null;
    }

    /**
     * Users who still have to vote on at least one of their items.
     *
     * @return array<int, int>
     */
    public function pendingUserIds(): array
    {
        $ids = [];

        foreach ($this->items as $item) {
            $ids = [...$ids, ...$item->pendingIds];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Users blocking the proposal with a thumbs down, with their reasons.
     *
     * @return array<int, array<int, string|null>> user id => [item id => reason]
     */
    public function rejections(): array
    {
        $rejections = [];

        foreach ($this->items as $item) {
            foreach ($item->rejectedBy as $userId => $reason) {
                $rejections[$userId][$item->itemId] = $reason;
            }
        }

        return $rejections;
    }
}
