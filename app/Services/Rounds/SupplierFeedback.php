<?php

namespace App\Services\Rounds;

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\Round;
use App\Models\RoundPackagePrice;
use App\Models\RoundSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the suppliers answered in a round: prices per package, packages
 * they can't deliver, shipping. Recorded once per round and supplier, it
 * applies to every draft — proposals up for a vote don't change anymore.
 */
class SupplierFeedback
{
    /**
     * @var array<int, RoundPhase>
     */
    public const PHASES = [RoundPhase::Negotiating, RoundPhase::Finalizing];

    public function __construct(private ProposalBuilder $builder) {}

    /**
     * Suppliers of the products somebody wants in the round.
     *
     * @return Collection<int, Supplier>
     */
    public function suppliersFor(Round $round): Collection
    {
        return Supplier::query()
            ->whereIn('id', $round->activeCartItems()->join('products', 'products.id', '=', 'cart_items.product_id')->select('products.supplier_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Packages of the supplier's products somebody wants in the round.
     *
     * @return Collection<int, PriceTier>
     */
    public function packagesFor(Round $round, Supplier $supplier): Collection
    {
        return PriceTier::query()
            ->with(['product' => fn ($query) => $query->withTrashed()])
            ->whereIn('product_id', $round->activeCartItems()->select('product_id'))
            ->whereHas('product', fn ($query) => $query->withTrashed()->where('supplier_id', $supplier->id))
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->orderBy('package_amount')
            ->get();
    }

    /**
     * The lead asked the supplier for prices, e.g. with the prepared mail.
     */
    public function markInquired(Round $round, Supplier $supplier, User $by): RoundSupplier
    {
        $this->ensure(in_array($round->phase, self::PHASES, true), 'Preise werden in der Anpassungsphase angefragt.');

        $record = RoundSupplier::firstOrCreate(['round_id' => $round->id, 'supplier_id' => $supplier->id]);
        $record->update(['inquired_at' => now()]);

        $round->logActivity('supplier_inquired', ['supplier' => $supplier->name], $by);

        return $record;
    }

    /**
     * Packages missing from both lists stay as they were saved. Each price
     * keeps the list price it was confirmed against, so a later takeover
     * into the catalog can tell whether the catalog changed in between.
     *
     * @param  array{shipping_cents?: int|null, prices?: array<int, int|null>, unavailable?: array<int, bool>}  $feedback  Prices and availability by price tier id.
     */
    public function record(Round $round, Supplier $supplier, array $feedback, User $by): RoundSupplier
    {
        $this->ensure(in_array($round->phase, self::PHASES, true), 'Rückmeldungen werden in der Anpassungs- oder Bestätigungsphase eingetragen.');
        $this->ensure(($feedback['shipping_cents'] ?? 0) >= 0, 'Die Versandkosten dürfen nicht negativ sein.');

        $packages = $this->packagesFor($round, $supplier);

        return DB::transaction(function () use ($round, $supplier, $feedback, $by, $packages): RoundSupplier {
            $record = RoundSupplier::updateOrCreate(
                ['round_id' => $round->id, 'supplier_id' => $supplier->id],
                ['shipping_cents' => $feedback['shipping_cents'] ?? null, 'responded_at' => now()],
            );

            foreach ($packages as $tier) {
                if (! array_key_exists($tier->id, $feedback['prices'] ?? []) && ! array_key_exists($tier->id, $feedback['unavailable'] ?? [])) {
                    continue;
                }

                $price = $feedback['prices'][$tier->id] ?? null;
                $available = ! ($feedback['unavailable'][$tier->id] ?? false);

                $this->ensure($price === null || $price >= 0, 'Preise dürfen nicht negativ sein.');

                if ($price === null && $available) {
                    RoundPackagePrice::query()->where('round_id', $round->id)->where('price_tier_id', $tier->id)->delete();

                    continue;
                }

                RoundPackagePrice::updateOrCreate(
                    ['round_id' => $round->id, 'price_tier_id' => $tier->id],
                    ['price_cents' => $price, 'list_price_cents' => $tier->price_cents, 'is_available' => $available],
                );
            }

            $round->proposals()
                ->where('status', ProposalStatus::Draft->value)
                ->get()
                ->each(fn (OrderProposal $draft) => $this->builder->recalculate($draft));

            $round->logActivity('supplier_responded', ['supplier' => $supplier->name], $by);

            return $record;
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['supplier' => $message]);
        }
    }
}
