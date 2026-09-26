<?php

namespace App\Services\Proposals;

/**
 * How one product differs between two versions of a proposal.
 */
final readonly class ItemChange
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public const CHANGED = 'changed';

    /**
     * @param  array<int, array{label: string, before: int, after: int}>  $prices  Package prices that changed.
     * @param  array<int, array{user_id: int, name: string, before: float, after: float}>  $quantities  Amounts per person that changed.
     */
    public function __construct(
        public string $kind,
        public string $product,
        public string $unit,
        public ?string $packagesBefore,
        public ?string $packagesAfter,
        public array $prices,
        public array $quantities,
    ) {}

    public function isAdded(): bool
    {
        return $this->kind === self::ADDED;
    }

    public function isRemoved(): bool
    {
        return $this->kind === self::REMOVED;
    }

    public function packagesChanged(): bool
    {
        return $this->kind === self::CHANGED && $this->packagesBefore !== $this->packagesAfter;
    }
}
