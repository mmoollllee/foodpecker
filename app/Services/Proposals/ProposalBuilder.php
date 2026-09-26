<?php

namespace App\Services\Proposals;

use App\Enums\ProposalStatus;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\ProposalItemPackage;
use App\Models\Round;
use App\Models\User;
use App\Services\Distribution\Demand;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\Distributor;
use App\Services\Distribution\PackageOption;
use App\Services\Rounds\RoundPriceBook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns the carts of a round into order proposals and keeps drafts in line
 * with the carts, the suppliers' feedback and the proposer's corrections:
 * amounts set by hand, fixed package counts and coarser rounding.
 *
 * Only carts of participants who were not excluded are taken into account.
 */
class ProposalBuilder
{
    public function __construct(private Distributor $distributor) {}

    /**
     * @param  array{title: string, description?: string|null}  $attributes
     */
    public function createFromCarts(Round $round, User $proposer, array $attributes): OrderProposal
    {
        return DB::transaction(function () use ($round, $proposer, $attributes): OrderProposal {
            $proposal = $round->proposals()->create([
                'proposed_by_user_id' => $proposer->id,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'status' => ProposalStatus::Draft,
            ]);

            $this->recalculate($proposal);

            $proposal->logActivity('created', ['title' => $proposal->title]);

            return $proposal;
        });
    }

    /**
     * Copies a proposal with its corrections — amounts set by hand, fixed
     * package counts, rounding — and recalculates it from the current carts
     * and prices. The copy starts as a draft and needs a new vote.
     */
    public function createNewVersion(OrderProposal $base, User $proposer): OrderProposal
    {
        return DB::transaction(function () use ($base, $proposer): OrderProposal {
            $base->loadMissing(['items.packages', 'items.allocations', 'round']);

            $proposal = $base->round->proposals()->create([
                'proposed_by_user_id' => $proposer->id,
                'based_on_proposal_id' => $base->id,
                'title' => $this->versionTitle($base->title),
                'description' => $base->description,
                'status' => ProposalStatus::Draft,
            ]);

            foreach ($base->items as $baseItem) {
                $item = $proposal->items()->create($baseItem->only(['product_id', 'portion_size', 'rounding_step', 'packages_fixed']));

                if ($baseItem->packages_fixed) {
                    foreach ($baseItem->packages as $package) {
                        $item->packages()->create($package->only($package->getFillable()));
                    }
                }

                foreach ($baseItem->allocations->where('is_manual', true) as $allocation) {
                    $item->allocations()->create($allocation->only(['user_id', 'quantity', 'is_manual']));
                }
            }

            $this->recalculate($proposal);

            $proposal->logActivity('created', ['title' => $proposal->title, 'based_on' => $base->id]);

            return $proposal;
        });
    }

    /**
     * Brings a draft in line with the active carts and the round's prices:
     * products nobody orders anymore are dropped, new ones added. Amounts
     * set by hand stay; the rest is distributed anew.
     */
    public function recalculate(OrderProposal $draft): OrderProposal
    {
        return DB::transaction(function () use ($draft): OrderProposal {
            $draft->load(['items.packages', 'items.allocations', 'round']);

            $round = $draft->round;
            $prices = RoundPriceBook::for($round);
            $cartItemsByProduct = $this->cartItemsByProduct($round);

            foreach ($draft->items as $item) {
                $cartItems = $cartItemsByProduct->get($item->product_id);

                if ($cartItems === null) {
                    $item->delete();

                    continue;
                }

                $this->calculateItem($item, $cartItems, $prices);
            }

            foreach ($cartItemsByProduct->except($draft->items->pluck('product_id')->all()) as $productId => $cartItems) {
                if ($cartItems->first()->product?->priceTiers->isEmpty() ?? true) {
                    continue;
                }

                $this->calculateItem($draft->items()->create(['product_id' => $productId]), $cartItems, $prices);
            }

            $this->refreshShipping($draft, $prices);

            return $draft->load(['items.packages', 'items.allocations']);
        });
    }

    /**
     * Sets what somebody gets of a position by hand — or hands it back to
     * the automatic distribution with null.
     */
    public function setAllocation(ProposalItem $item, int $userId, ?float $quantity): ProposalItem
    {
        $this->ensureDraft($item->proposal);
        $this->ensure($quantity === null || $quantity >= 0, 'Die Menge darf nicht negativ sein.');
        $this->ensure($quantity === null || $item->isWholePortions($quantity), sprintf(
            'Die Menge muss ein Vielfaches der Portion (%s) sein.',
            CartItem::formatAmount((float) $item->portion_size, $item->product?->unitLabel()),
        ));

        /** @var ProposalAllocation|null $allocation */
        $allocation = $item->allocations()->where('user_id', $userId)->first();
        $this->ensure($allocation !== null, 'Diese Person hat das Produkt nicht bestellt.');

        return DB::transaction(function () use ($item, $allocation, $quantity): ProposalItem {
            $allocation->update(['is_manual' => $quantity !== null, 'quantity' => round($quantity ?? 0.0, 3)]);

            return $this->recalculateItem($item);
        });
    }

    /**
     * Fixes how many packages of each size are ordered — or lets the
     * cheapest combination be found again with null.
     *
     * @param  array<int, int>|null  $countsByTierId
     */
    public function setPackageCounts(ProposalItem $item, ?array $countsByTierId): ProposalItem
    {
        $this->ensureDraft($item->proposal);
        $this->ensure($item->isPortioned(), 'Bei ganzen Packungen ergibt sich die Anzahl aus den Wünschen.');

        return DB::transaction(function () use ($item, $countsByTierId): ProposalItem {
            if ($countsByTierId === null) {
                $item->update(['packages_fixed' => false]);

                return $this->recalculateItem($item);
            }

            $tiers = $item->product->priceTiers->keyBy('id');
            $counts = collect($countsByTierId)
                ->mapWithKeys(fn (mixed $count, int|string $tierId): array => [(int) $tierId => max(0, (int) $count)])
                ->filter(fn (int $count, int $tierId): bool => $count > 0 && $tiers->has($tierId));

            $this->ensure($counts->isNotEmpty(), 'Bitte mindestens ein Gebinde bestellen.');

            $available = collect(RoundPriceBook::for($item->proposal->round)->optionsFor($item->product))
                ->filter(fn (PackageOption $option): bool => $option->available)
                ->pluck('priceTierId');

            foreach ($counts->keys() as $tierId) {
                $this->ensure($available->contains($tierId), '„'.$tiers->get($tierId)->label.'“ ist laut Lieferant nicht lieferbar.');
            }

            $item->packages()->delete();

            foreach ($counts as $tierId => $count) {
                /** @var PriceTier $tier */
                $tier = $tiers->get($tierId);

                $item->packages()->create([
                    'price_tier_id' => $tier->id,
                    'label' => $tier->label,
                    'package_amount' => $tier->package_amount,
                    'price_cents' => $tier->price_cents,
                    'list_price_cents' => $tier->price_cents,
                    'count' => $count,
                ]);
            }

            $item->update(['packages_fixed' => true]);

            return $this->recalculateItem($item);
        });
    }

    /**
     * Distributes the flexible shares of a position in coarser steps, e.g.
     * half kilograms — or in portions again with null.
     */
    public function setRounding(ProposalItem $item, ?float $step): ProposalItem
    {
        $this->ensureDraft($item->proposal);
        $this->ensure($item->isPortioned(), 'Ganze Packungen lassen sich nicht runden.');
        $this->ensure($step === null || $step > 0, 'Bitte eine Schrittweite größer als 0 wählen.');

        $item->update(['rounding_step' => $step]);

        return $this->recalculateItem($item);
    }

    public function recalculateItem(ProposalItem $item): ProposalItem
    {
        $item->load(['packages', 'allocations', 'proposal.round']);

        $cartItems = $this->cartItemsByProduct($item->proposal->round, $item->product_id)->get($item->product_id);

        if ($cartItems === null) {
            $item->delete();

            return $item;
        }

        $this->calculateItem($item, $cartItems, RoundPriceBook::for($item->proposal->round));

        return $item->load(['packages', 'allocations']);
    }

    /**
     * @param  Collection<int, CartItem>  $cartItems
     */
    private function calculateItem(ProposalItem $item, Collection $cartItems, RoundPriceBook $prices): void
    {
        /** @var Product $product */
        $product = $cartItems->first()->product;
        $item->loadMissing(['packages', 'allocations']);

        $manual = $item->allocations->where('is_manual', true)->keyBy('user_id');
        $demands = $cartItems
            ->map(fn (CartItem $cartItem): Demand => Demand::fromCartItem(
                $cartItem,
                $manual->has($cartItem->user_id) ? (float) $manual->get($cartItem->user_id)->quantity : null,
            ))
            ->values()
            ->all();

        $fixedCounts = $item->packages_fixed
            ? $item->packages->filter(fn (ProposalItemPackage $package): bool => $package->price_tier_id !== null)->pluck('count', 'price_tier_id')->all()
            : null;

        // Fixed sizes that can't be delivered anymore leave nothing to fix — find the best mix again.
        if ($fixedCounts === []) {
            $fixedCounts = null;
            $item->packages_fixed = false;
        }

        $result = $this->distributor->distribute(
            $demands,
            $prices->optionsFor($product),
            $product->portionSize(),
            $product->unitLabel(),
            $fixedCounts,
            $product->isPortioned() && $item->rounding_step !== null ? (float) $item->rounding_step : null,
        );

        // Nobody needs anything and no package fits the wishes: nothing to order.
        if ($result->mix->isEmpty() && collect($demands)->every(fn (Demand $demand): bool => $demand->wantedMin() <= 0.0005)) {
            $item->delete();

            return;
        }

        $this->store($item, $product, $result);
    }

    /**
     * Saves packages and allocations of a position. Votes are dropped: they
     * were given for other amounts.
     */
    private function store(ProposalItem $item, Product $product, DistributionResult $result): void
    {
        $item->packages()->delete();
        $packageIds = [];

        foreach ($result->mix->lines as $line) {
            $option = $line['option'];

            $package = $item->packages()->create([
                'price_tier_id' => $option->priceTierId,
                'label' => $option->label,
                'article_number' => $option->articleNumber,
                'package_amount' => $option->amount,
                'price_cents' => $option->priceCents,
                'list_price_cents' => $option->listPriceCents,
                'min_order_packages' => $option->minOrderPackages,
                'count' => $line['count'],
            ]);

            $packageIds[(int) $option->priceTierId] = $package->id;
        }

        $item->allocations()->delete();
        $item->votes()->delete();

        foreach ($result->allocations as $line) {
            $item->allocations()->create([
                'user_id' => $line->userId,
                'quantity' => round($line->allocatedQuantity, 3),
                'share_cents' => $line->shareCents,
                'is_manual' => $line->manual,
                'package_counts' => $line->packageCounts === []
                    ? null
                    : collect($line->packageCounts)->mapWithKeys(fn (int $count, int $tierId): array => [$packageIds[$tierId] ?? $tierId => $count])->all(),
            ]);
        }

        $item->fill([
            'portion_size' => $product->portionSize(),
            'rounding_step' => $product->isPortioned() ? $item->rounding_step : null,
            'packages_fixed' => $product->isPortioned() && $item->packages_fixed,
            'total_price_cents' => $result->totalPriceCents(),
            'notes' => $result->notes === [] ? null : implode("\n", $result->notes),
        ])->save();

        $item->unsetRelation('packages')->unsetRelation('allocations');
    }

    private function refreshShipping(OrderProposal $draft, RoundPriceBook $prices): void
    {
        $supplierIds = $draft->items()->with('product')->get()
            ->map(fn (ProposalItem $item): ?int => $item->product?->supplier_id)
            ->filter()
            ->all();

        $draft->update(['shipping_by_supplier' => $prices->shippingFor($supplierIds)]);
    }

    /**
     * @return Collection<int, Collection<int, CartItem>>
     */
    private function cartItemsByProduct(Round $round, ?int $productId = null): Collection
    {
        return $round->activeCartItems()
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->with(['product.priceTiers', 'product.supplier', 'user'])
            ->orderBy('id')
            ->get()
            ->groupBy('product_id')
            ->toBase();
    }

    private function ensureDraft(OrderProposal $proposal): void
    {
        $this->ensure($proposal->isDraft(), 'Nur Entwürfe lassen sich anpassen — für Änderungen gibt es eine neue Version.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['proposal' => $message]);
        }
    }

    private function versionTitle(string $title): string
    {
        if (preg_match('/^(.*) \(Version (\d+)\)$/', $title, $matches) === 1) {
            return $matches[1].' (Version '.((int) $matches[2] + 1).')';
        }

        return $title.' (Version 2)';
    }
}
