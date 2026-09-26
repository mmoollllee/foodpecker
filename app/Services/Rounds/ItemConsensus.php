<?php

namespace App\Services\Rounds;

/**
 * Voting state of a single proposal item.
 */
final readonly class ItemConsensus
{
    /**
     * @param  array<int, int>  $stakeholderIds  Users who ordered the product and weren't excluded.
     * @param  array<int, int>  $approvedBy  Stakeholders who voted thumbs up.
     * @param  array<int, string|null>  $rejectedBy  Stakeholders who voted thumbs down, keyed by user id, with their reason.
     * @param  array<int, int>  $pendingIds  Stakeholders who have not voted yet.
     */
    public function __construct(
        public int $itemId,
        public array $stakeholderIds,
        public array $approvedBy,
        public array $rejectedBy,
        public array $pendingIds,
    ) {}

    public function isApproved(): bool
    {
        return $this->stakeholderIds !== []
            && $this->rejectedBy === []
            && $this->pendingIds === [];
    }

    public function isStakeholder(int $userId): bool
    {
        return in_array($userId, $this->stakeholderIds, true);
    }
}
