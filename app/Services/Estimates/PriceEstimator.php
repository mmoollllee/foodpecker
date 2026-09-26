<?php

namespace App\Services\Estimates;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Round;
use App\Services\Distribution\Demand;
use App\Services\Distribution\Distributor;
use App\Services\Distribution\PackageMixer;
use App\Services\Distribution\PackageOption;
use App\Services\Rounds\RoundPriceBook;

/**
 * Estimates what the wishes of a round would cost if it were ordered now,
 * with the same package combinations the order proposal will use — while
 * shopping, and to show what the suppliers' feedback changed.
 */
class PriceEstimator
{
    public function __construct(
        private Distributor $distributor,
        private PackageMixer $mixer,
    ) {}

    /**
     * @param  bool  $listPrices  Ignore what the suppliers confirmed for the round.
     */
    public function estimate(Round $round, bool $listPrices = false): RoundEstimate
    {
        $prices = RoundPriceBook::for($round);
        $products = [];

        $cartItemsByProduct = $round->activeCartItems()
            ->with(['product.priceTiers'])
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        foreach ($cartItemsByProduct as $productId => $cartItems) {
            $product = $cartItems->first()->product;

            if ($product === null || $product->priceTiers->isEmpty()) {
                continue;
            }

            $options = $prices->optionsFor($product, $listPrices);

            $products[$productId] = new ProductEstimate($product, $options, $this->distributor->distribute(
                $cartItems->map(fn (CartItem $cartItem): Demand => Demand::fromCartItem($cartItem))->values()->all(),
                $options,
                $product->portionSize(),
                $product->unitLabel(),
            ));
        }

        return new RoundEstimate($round, $products);
    }

    /**
     * What a wish would cost the person, fees included, with the wishes of
     * everybody else in the round — the moment the amount is typed in.
     *
     * @return array{from: int, to: int, perUnit: float|null}|null Cents; per unit at the upper amount.
     */
    public function estimateWish(Round $round, Product $product, int $userId, float $min, float $max, ?int $preferredTierId = null): ?array
    {
        $product->loadMissing('priceTiers');

        if ($product->priceTiers->isEmpty() || $max <= 0) {
            return null;
        }

        $options = RoundPriceBook::for($round)->optionsFor($product);
        $others = $round->activeCartItems()
            ->where('product_id', $product->id)
            ->where('user_id', '!=', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (CartItem $cartItem): Demand => Demand::fromCartItem($cartItem))
            ->values()
            ->all();

        $distribution = fn (float $amount) => $this->distributor->distribute(
            [...$others, new Demand($userId, $amount, $amount, $preferredTierId)],
            $options,
            $product->portionSize(),
            $product->unitLabel(),
        );

        $upper = $distribution($max);
        $lower = $min === $max ? $upper : $distribution($min);
        $estimate = new RoundEstimate($round, []);

        return [
            'from' => $estimate->withFees($lower->allocationFor($userId)?->shareCents ?? 0),
            'to' => $estimate->withFees($upper->allocationFor($userId)?->shareCents ?? 0),
            'perUnit' => $upper->totalQuantity() > 0 ? $upper->totalPriceCents() / $upper->totalQuantity() : null,
        ];
    }

    /**
     * Price of an amount between min and max for one person, in cents: at
     * the group's price per unit for portions, for whole packages at the
     * price of the packages that fit.
     *
     * @return array{0: int, 1: int}|null
     */
    public function rangeFor(ProductEstimate $estimate, float $min, float $max): ?array
    {
        if ($estimate->product->isPortioned()) {
            $perUnit = $estimate->pricePerUnitCents() ?? $this->cheapestPerUnit($estimate);

            return $perUnit === null ? null : [(int) round($min * $perUnit), (int) round($max * $perUnit)];
        }

        $options = array_map(fn (PackageOption $option): PackageOption => $option->withoutMinimumOrder(), $estimate->options);
        $low = $this->mixer->best($options, $min, $min);
        $high = $this->mixer->best($options, $max, $max);

        return $low->isEmpty() && $high->isEmpty() ? null : [$low->totalPriceCents(), $high->totalPriceCents()];
    }

    private function cheapestPerUnit(ProductEstimate $estimate): ?float
    {
        $prices = array_map(fn (PackageOption $option): float => $option->pricePerUnitCents(), array_filter($estimate->options, fn (PackageOption $option): bool => $option->available));

        return $prices === [] ? null : min($prices);
    }
}
