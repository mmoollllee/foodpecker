<?php

namespace App\Services\Rounds;

use App\Enums\RoundPhase;
use App\Models\Product;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\RoundSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Per supplier of the final order the lead ticks off when the order went
 * out and when the goods arrived. Neither holds the round up — what is
 * still open shows up as a hint and a task.
 */
class SupplierOrders
{
    /**
     * @var array<int, RoundPhase>
     */
    public const ORDER_PHASES = [RoundPhase::Ordering, RoundPhase::Delivery];

    /**
     * @var array<int, RoundPhase>
     */
    public const DELIVERY_PHASES = [RoundPhase::Ordering, RoundPhase::Delivery, RoundPhase::Pickup];

    /**
     * Suppliers of the final order, by name.
     *
     * @return Collection<int, Supplier>
     */
    public function suppliersFor(Round $round): Collection
    {
        if ($round->chosen_proposal_id === null) {
            return collect();
        }

        return Supplier::query()
            ->whereIn('id', Product::withTrashed()
                ->whereIn('id', ProposalItem::query()->where('proposal_id', $round->chosen_proposal_id)->select('product_id'))
                ->select('supplier_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Ticks off that the order went out — or takes that back, together with
     * the delivery.
     */
    public function markOrdered(Round $round, Supplier $supplier, User $by, bool $ordered = true): RoundSupplier
    {
        $this->ensure(in_array($round->phase, self::ORDER_PHASES, true), 'Bestellungen werden in der Bestell- oder Lieferphase abgehakt.');
        $this->ensureSupplierOf($round, $supplier);

        $record = RoundSupplier::firstOrCreate(['round_id' => $round->id, 'supplier_id' => $supplier->id]);

        $record->update($ordered
            ? ['ordered_at' => $record->ordered_at ?? now()]
            : ['ordered_at' => null, 'delivered_at' => null]);

        if ($ordered) {
            $round->logActivity('supplier_ordered', ['supplier' => $supplier->name], $by);
        }

        return $record;
    }

    /**
     * Ticks off that the goods arrived — which means they were ordered, too.
     */
    public function markDelivered(Round $round, Supplier $supplier, User $by, bool $delivered = true): RoundSupplier
    {
        $this->ensure(in_array($round->phase, self::DELIVERY_PHASES, true), 'Lieferungen werden zwischen Bestellung und Abholung abgehakt.');
        $this->ensureSupplierOf($round, $supplier);

        $record = RoundSupplier::firstOrCreate(['round_id' => $round->id, 'supplier_id' => $supplier->id]);

        $record->update($delivered
            ? ['ordered_at' => $record->ordered_at ?? now(), 'delivered_at' => $record->delivered_at ?? now()]
            : ['delivered_at' => null]);

        if ($delivered) {
            $round->logActivity('supplier_delivered', ['supplier' => $supplier->name], $by);
        }

        return $record;
    }

    /**
     * Suppliers of the final order not marked as ordered yet.
     *
     * @return Collection<int, Supplier>
     */
    public function notOrdered(Round $round): Collection
    {
        $ordered = $round->roundSuppliers()->whereNotNull('ordered_at')->pluck('supplier_id');

        return $this->suppliersFor($round)->reject(fn (Supplier $supplier): bool => $ordered->contains($supplier->id))->values();
    }

    /**
     * Suppliers of the final order whose goods aren't marked as arrived yet.
     *
     * @return Collection<int, Supplier>
     */
    public function notDelivered(Round $round): Collection
    {
        $delivered = $round->roundSuppliers()->whereNotNull('delivered_at')->pluck('supplier_id');

        return $this->suppliersFor($round)->reject(fn (Supplier $supplier): bool => $delivered->contains($supplier->id))->values();
    }

    private function ensureSupplierOf(Round $round, Supplier $supplier): void
    {
        $this->ensure(
            $this->suppliersFor($round)->contains('id', $supplier->id),
            "„{$supplier->name}“ liefert nichts aus der finalen Bestellung.",
        );
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['supplier' => $message]);
        }
    }
}
