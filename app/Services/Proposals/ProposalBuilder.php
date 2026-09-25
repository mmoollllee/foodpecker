<?php

namespace App\Services\Proposals;

use App\Enums\ProposalStatus;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\User;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\Distributor;
use App\Services\Distribution\PackageSpec;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the carts of a round into order proposals and keeps their items
 * consistent when the lead changes package size, count or price.
 *
 * Only carts of participants who were not excluded are taken into account.
 */
class ProposalBuilder
{
    public function __construct(private Distributor $distributor) {}

    /**
     * @param  array{title: string, description?: string|null, shipping_cents?: int|null}  $attributes
     */
    public function createFromCarts(Round $round, User $proposer, array $attributes): OrderProposal
    {
        return DB::transaction(function () use ($round, $proposer, $attributes): OrderProposal {
            $proposal = $round->proposals()->create([
                'proposed_by_user_id' => $proposer->id,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'shipping_cents' => $attributes['shipping_cents'] ?? 0,
                'status' => ProposalStatus::Draft,
            ]);

            $cartItemsByProduct = $round->activeCartItems()
                ->with(['product.priceTiers', 'preferredTier', 'user'])
                ->get()
                ->groupBy('product_id');

            foreach ($cartItemsByProduct as $cartItems) {
                $this->addProduct($proposal, $cartItems);
            }

            $proposal->logActivity('created', ['title' => $proposal->title]);

            return $proposal->load('items.allocations');
        });
    }

    /**
     * Copies a proposal with the same package sizes and negotiated prices and
     * recalculates it from the current carts. The copy starts as a draft and
     * needs a new vote.
     */
    public function createNewVersion(OrderProposal $base, User $proposer): OrderProposal
    {
        return DB::transaction(function () use ($base, $proposer): OrderProposal {
            $base->loadMissing(['items.allocations', 'round']);

            $proposal = $base->round->proposals()->create([
                'proposed_by_user_id' => $proposer->id,
                'title' => $this->versionTitle($base->title),
                'description' => $base->description,
                'shipping_cents' => $base->shipping_cents,
                'status' => ProposalStatus::Draft,
            ]);

            // Copying the allocations keeps everybody at their pack size until
            // the recalculation below distributes the quantities anew.
            foreach ($base->items as $baseItem) {
                $item = $proposal->items()->create(Arr::except($baseItem->only($baseItem->getFillable()), ['proposal_id']));

                foreach ($baseItem->allocations as $allocation) {
                    $item->allocations()->create($allocation->only(['user_id', 'quantity', 'share_cents']));
                }
            }

            $this->recalculate($proposal);

            $proposal->logActivity('created', ['title' => $proposal->title, 'based_on' => $base->id]);

            return $proposal->load('items.allocations');
        });
    }

    /**
     * Brings a draft in line with the carts of the active participants, e.g.
     * after somebody was excluded or taken back in. Package sizes and
     * negotiated prices stay, the quantities are distributed anew. Products
     * nobody orders anymore are dropped; products without an item get one
     * with the best-fitting package size.
     */
    public function recalculate(OrderProposal $draft): OrderProposal
    {
        return DB::transaction(function () use ($draft): OrderProposal {
            $draft->load(['items.allocations', 'items.product.priceTiers', 'round']);

            $cartItemsByProduct = $draft->round->activeCartItems()
                ->with(['product.priceTiers', 'preferredTier', 'user'])
                ->get()
                ->groupBy('product_id')
                ->toBase();

            foreach ($draft->items->groupBy('product_id') as $productId => $items) {
                $this->redistributeProduct($draft, $items, $cartItemsByProduct->get($productId, collect()));
            }

            foreach ($cartItemsByProduct->except($draft->items->pluck('product_id')->all()) as $cartItems) {
                $this->addProduct($draft, $cartItems);
            }

            return $draft->load('items.allocations');
        });
    }

    /**
     * Applies a new package size, count or negotiated price to an item and
     * redistributes it between the people who ordered the product.
     */
    public function updateItem(ProposalItem $item, PackageSpec $spec, ?int $packages = null): ProposalItem
    {
        return DB::transaction(function () use ($item, $spec, $packages): ProposalItem {
            $result = $this->distributor->compute($this->cartItemsFor($item), $spec, $packages);

            $this->applyDistribution($item, $spec, $result);

            return $item->load('allocations');
        });
    }

    /**
     * What each price tier would mean for the current demand — the basis
     * for choosing the most economical package size.
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @return Collection<int, array{tier: PriceTier, result: DistributionResult, price_per_unit_cents: float}>
     */
    public function compareTiers(Product $product, Collection $cartItems): Collection
    {
        return $product->priceTiers->map(function (PriceTier $tier) use ($cartItems): array {
            $result = $this->distributor->compute($cartItems, $tier);

            return [
                'tier' => $tier,
                'result' => $result,
                'price_per_unit_cents' => $result->totalQuantity > 0 ? $result->totalPriceCents / $result->totalQuantity : 0.0,
            ];
        })->values();
    }

    /**
     * Cheapest price tier that fits the demand. Without a fitting tier the
     * one with the smallest overshoot wins.
     *
     * @param  Collection<int, CartItem>  $cartItems
     */
    public function bestTier(Product $product, Collection $cartItems): ?PriceTier
    {
        $options = $this->compareTiers($product, $cartItems);

        if ($options->isEmpty()) {
            return null;
        }

        $feasible = $options->filter(fn (array $option): bool => $option['result']->feasible);

        if ($feasible->isNotEmpty()) {
            return $feasible->sortBy([
                fn (array $a, array $b): int => $a['price_per_unit_cents'] <=> $b['price_per_unit_cents'],
                fn (array $a, array $b): int => $a['result']->totalPriceCents <=> $b['result']->totalPriceCents,
            ])->first()['tier'];
        }

        return $options->sortBy([
            fn (array $a, array $b): int => $this->overshoot($a['result']) <=> $this->overshoot($b['result']),
            fn (array $a, array $b): int => $a['price_per_unit_cents'] <=> $b['price_per_unit_cents'],
        ])->first()['tier'];
    }

    /**
     * Products with several indivisible pack sizes (e.g. spaghetti in 250 g
     * and 2 kg) get one item per pack size, following each participant's
     * preference. Everything else gets a single item with the best tier.
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @return array<int, array{0: PriceTier, 1: Collection<int, CartItem>}>
     */
    private function packageGroups(Product $product, Collection $cartItems): array
    {
        if (! $this->isMultiSize($product)) {
            $tier = $this->bestTier($product, $cartItems);

            return $tier ? [[$tier, $cartItems]] : [];
        }

        $groups = [];

        foreach ($cartItems as $cartItem) {
            $tier = $this->packSizeFor($product, $cartItem);

            $groups[$tier->id] ??= [$tier, collect()];
            $groups[$tier->id][1]->push($cartItem);
        }

        return array_values($groups);
    }

    /**
     * Several indivisible pack sizes, e.g. spaghetti in 250 g and 2 kg bags:
     * everybody gets whole packs of the size they chose.
     */
    private function isMultiSize(Product $product): bool
    {
        $tiers = $product->priceTiers;

        return $tiers->count() > 1 && $tiers->every(fn (PriceTier $tier): bool => ! $tier->is_divisible);
    }

    /**
     * The pack size somebody chose in their cart, or the one that fits their
     * wish best.
     */
    private function packSizeFor(Product $product, CartItem $cartItem): PriceTier
    {
        return $product->priceTiers->firstWhere('id', $cartItem->preferred_price_tier_id)
            ?? $this->bestFittingPackSize($product->priceTiers, $cartItem);
    }

    /**
     * Pack size that covers the wish with the least overshoot, cheaper per
     * unit on a tie.
     *
     * @param  Collection<int, PriceTier>  $tiers
     */
    private function bestFittingPackSize(Collection $tiers, CartItem $cartItem): PriceTier
    {
        $wanted = $cartItem->effectiveMin();

        return $tiers->sortBy([
            function (PriceTier $a, PriceTier $b) use ($wanted): int {
                $overshootA = ceil($wanted / (float) $a->package_amount - 0.001) * (float) $a->package_amount - $wanted;
                $overshootB = ceil($wanted / (float) $b->package_amount - 0.001) * (float) $b->package_amount - $wanted;

                return $overshootA <=> $overshootB;
            },
            fn (PriceTier $a, PriceTier $b): int => $a->pricePerUnit() <=> $b->pricePerUnit(),
        ])->first();
    }

    /**
     * Adds the positions for one ordered product to a proposal.
     *
     * @param  Collection<int, CartItem>  $cartItems
     */
    private function addProduct(OrderProposal $proposal, Collection $cartItems): void
    {
        $product = $cartItems->first()?->product;

        if (! $product instanceof Product || $product->priceTiers->isEmpty()) {
            return;
        }

        foreach ($this->packageGroups($product, $cartItems) as [$tier, $groupItems]) {
            $this->createItem($proposal, $product, PackageSpec::fromTier($tier), $groupItems);
        }
    }

    /**
     * Distributes the positions of one product anew between the people who
     * order it. Everybody keeps their position; people new to the product
     * join the one that fits them — for several indivisible pack sizes the
     * one of their size, which is added if the proposal lacks it.
     *
     * @param  Collection<int, ProposalItem>  $items
     * @param  Collection<int, CartItem>  $cartItems
     */
    private function redistributeProduct(OrderProposal $proposal, Collection $items, Collection $cartItems): void
    {
        $product = $items->first()->product;
        $isMultiSize = $product instanceof Product && $this->isMultiSize($product);
        $cartItemsByItem = [];
        $missingPackSizes = [];

        foreach ($cartItems as $cartItem) {
            $item = $items->first(fn (ProposalItem $item): bool => $item->stakeholderIds()->contains($cartItem->user_id));

            if ($item === null && ! $isMultiSize) {
                $item = $items->first();
            }

            if ($item === null) {
                $tier = $this->packSizeFor($product, $cartItem);
                $item = $items->firstWhere('price_tier_id', $tier->id);

                if ($item === null) {
                    $missingPackSizes[$tier->id] ??= [$tier, collect()];
                    $missingPackSizes[$tier->id][1]->push($cartItem);

                    continue;
                }
            }

            $cartItemsByItem[$item->id] ??= collect();
            $cartItemsByItem[$item->id]->push($cartItem);
        }

        foreach ($items as $item) {
            $spec = $item->toPackageSpec();
            $result = $this->distributor->compute($cartItemsByItem[$item->id] ?? collect(), $spec);

            if ($result->packagesOrdered <= 0) {
                $item->delete();

                continue;
            }

            $this->applyDistribution($item, $spec, $result);
        }

        foreach ($missingPackSizes as [$tier, $groupItems]) {
            $this->createItem($proposal, $product, PackageSpec::fromTier($tier), $groupItems);
        }
    }

    /**
     * Adds one position for the given package to a proposal and distributes
     * it between the given cart items.
     *
     * @param  Collection<int, CartItem>  $cartItems
     */
    public function createItem(OrderProposal $proposal, Product $product, PackageSpec $spec, Collection $cartItems): ?ProposalItem
    {
        $result = $this->distributor->compute($cartItems, $spec);

        if ($result->packagesOrdered <= 0) {
            return null;
        }

        $item = $proposal->items()->create([
            'product_id' => $product->id,
            ...$this->itemAttributes($spec, $result),
        ]);

        $this->storeAllocations($item, $result);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(PackageSpec $spec, DistributionResult $result): array
    {
        return [
            'price_tier_id' => $spec->priceTierId,
            'tier_label' => $spec->label,
            'package_amount' => $spec->packageAmount,
            'package_price_cents' => $spec->priceCents,
            'is_divisible' => $spec->isDivisible,
            'divisible_step' => $spec->divisibleStep,
            'min_order_packages' => $spec->minOrderPackages,
            'packages_ordered' => $result->packagesOrdered,
            'total_price_cents' => $result->totalPriceCents,
        ];
    }

    /**
     * Stores a new distribution on an existing item. Votes are dropped: they
     * were given for the old quantities.
     */
    private function applyDistribution(ProposalItem $item, PackageSpec $spec, DistributionResult $result): void
    {
        $item->fill([
            ...$this->itemAttributes($spec, $result),
            'notes' => null,
        ])->save();

        $item->allocations()->delete();
        $item->votes()->delete();
        $this->storeAllocations($item, $result);
    }

    private function storeAllocations(ProposalItem $item, DistributionResult $result): void
    {
        foreach ($result->allocations as $allocation) {
            $item->allocations()->create([
                'user_id' => $allocation->userId,
                'quantity' => round($allocation->allocatedQuantity, 3),
                'share_cents' => $allocation->shareCents,
            ]);
        }

        if (! $result->feasible && $result->notes !== []) {
            $item->forceFill(['notes' => implode("\n", $result->notes)])->save();
        }
    }

    /**
     * Cart items the item is distributed between: everybody who ordered the
     * product, or — if the product is split into several pack sizes — the
     * people currently assigned to this pack size.
     *
     * @return Collection<int, CartItem>
     */
    private function cartItemsFor(ProposalItem $item): Collection
    {
        $item->loadMissing('proposal.round');

        $cartItems = $item->proposal->round->activeCartItems()
            ->where('product_id', $item->product_id)
            ->with(['product', 'user'])
            ->get();

        $siblings = $item->proposal->items()->where('product_id', $item->product_id)->count();

        if ($siblings > 1) {
            return $cartItems->whereIn('user_id', $item->stakeholderIds()->all())->values();
        }

        return $cartItems;
    }

    private function overshoot(DistributionResult $result): float
    {
        $sumMax = array_sum(array_map(fn ($line): float => $line->requestedMax, $result->allocations));

        return max(0.0, $result->totalQuantity - $sumMax);
    }

    private function versionTitle(string $title): string
    {
        if (preg_match('/^(.*) \(Version (\d+)\)$/', $title, $matches) === 1) {
            return $matches[1].' (Version '.((int) $matches[2] + 1).')';
        }

        return $title.' (Version 2)';
    }
}
