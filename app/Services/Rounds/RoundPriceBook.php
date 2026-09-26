<?php

namespace App\Services\Rounds;

use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Round;
use App\Models\RoundPackagePrice;
use App\Models\RoundSupplier;
use App\Services\Distribution\PackageOption;
use Illuminate\Support\Collection;

/**
 * The prices a round calculates with: the catalog's list prices, replaced
 * by what the suppliers confirmed for this round.
 */
class RoundPriceBook
{
    /**
     * @param  Collection<int, RoundPackagePrice>  $confirmed  Keyed by price tier id.
     * @param  Collection<int, RoundSupplier>  $suppliers  Keyed by supplier id.
     */
    private function __construct(
        private Collection $confirmed,
        private Collection $suppliers,
    ) {}

    public static function for(Round $round): self
    {
        return new self(
            $round->packagePrices()->get()->keyBy('price_tier_id'),
            $round->roundSuppliers()->get()->keyBy('supplier_id'),
        );
    }

    /**
     * Package sizes of a product as they can be ordered in the round.
     *
     * @param  bool  $listPrices  Ignore the suppliers' feedback, e.g. to show what it changed.
     * @return array<int, PackageOption>
     */
    public function optionsFor(Product $product, bool $listPrices = false): array
    {
        return $product->priceTiers
            ->map(function (PriceTier $tier) use ($listPrices): PackageOption {
                $confirmed = $listPrices ? null : $this->confirmed->get($tier->id);

                return PackageOption::fromTier($tier, $confirmed?->price_cents, $confirmed?->is_available ?? true);
            })
            ->values()
            ->all();
    }

    /**
     * Shipping per supplier, as far as the suppliers named it.
     *
     * @param  array<int, int>  $supplierIds
     * @return array<int, int> supplier id => cents
     */
    public function shippingFor(array $supplierIds): array
    {
        $shipping = [];

        foreach (array_unique($supplierIds) as $supplierId) {
            $cents = $this->suppliers->get($supplierId)?->shipping_cents;

            // 0 is an answer, too: free shipping.
            if ($cents !== null) {
                $shipping[$supplierId] = (int) $cents;
            }
        }

        return $shipping;
    }
}
