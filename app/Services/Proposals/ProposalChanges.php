<?php

namespace App\Services\Proposals;

/**
 * Everything that differs between two versions of a proposal.
 */
final readonly class ProposalChanges
{
    /**
     * @param  array<int, ItemChange>  $items  By product name.
     * @param  array<int, array{supplier: string, before: int, after: int}>  $shipping
     * @param  array<int, array{user_id: int, name: string, before: int, after: int}>  $subtotals  What each person pays, before and after.
     */
    public function __construct(
        public array $items,
        public array $shipping,
        public array $subtotals,
        public int $totalBefore,
        public int $totalAfter,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === []
            && $this->shipping === []
            && $this->subtotals === []
            && $this->totalBefore === $this->totalAfter;
    }
}
