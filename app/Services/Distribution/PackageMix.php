<?php

namespace App\Services\Distribution;

use App\Models\CartItem;

/**
 * Packages to order for one product — possibly of several sizes, e.g.
 * 17 kg flour as one 10 kg sack, one 5 kg sack and two 1 kg bags.
 */
final readonly class PackageMix
{
    /**
     * @param  array<int, array{option: PackageOption, count: int}>  $lines  Sizes with at least one package.
     */
    public function __construct(public array $lines = []) {}

    /**
     * @param  array<int, PackageOption>  $options
     * @param  array<int, int>  $counts  Package count by option index.
     */
    public static function fromCounts(array $options, array $counts): self
    {
        $lines = [];

        foreach ($options as $index => $option) {
            $count = (int) ($counts[$index] ?? 0);

            if ($count > 0) {
                $lines[] = ['option' => $option, 'count' => $count];
            }
        }

        usort($lines, fn (array $a, array $b): int => $b['option']->amount <=> $a['option']->amount);

        return new self($lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function totalAmount(): float
    {
        return array_sum(array_map(fn (array $line): float => $line['count'] * $line['option']->amount, $this->lines));
    }

    public function totalPriceCents(): int
    {
        return array_sum(array_map(fn (array $line): int => $line['count'] * $line['option']->priceCents, $this->lines));
    }

    public function packageCount(): int
    {
        return array_sum(array_map(fn (array $line): int => $line['count'], $this->lines));
    }

    public function countFor(?int $priceTierId): int
    {
        foreach ($this->lines as $line) {
            if ($line['option']->priceTierId === $priceTierId) {
                return $line['count'];
            }
        }

        return 0;
    }

    /**
     * Adds packages of the given size, e.g. to reach a minimum order.
     */
    public function with(PackageOption $option, int $count): self
    {
        $lines = $this->lines;

        foreach ($lines as $index => $line) {
            if ($line['option']->priceTierId === $option->priceTierId && $line['option']->label === $option->label) {
                $lines[$index]['count'] += $count;

                return new self($lines);
            }
        }

        $lines[] = ['option' => $option, 'count' => $count];

        return new self($lines);
    }

    /**
     * E.g. "1 × 10 kg Sack, 2 × 1 kg Tüte".
     */
    public function describe(): string
    {
        return implode(', ', array_map(
            fn (array $line): string => $line['count'].' × '.$line['option']->label,
            $this->lines,
        ));
    }

    public function formattedAmount(string $unit): string
    {
        return CartItem::formatAmount($this->totalAmount(), $unit);
    }
}
