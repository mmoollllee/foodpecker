<?php

namespace App\Services\Notifications;

use App\Enums\SupplierMailType;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalItem;
use App\Models\ProposalItemPackage;
use App\Models\Round;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\Money;
use Illuminate\Support\Collection;

/**
 * Drafts the mails a lead sends to suppliers — a price inquiry with the
 * expected quantities, a reminder if the answer is missing and the order
 * of the chosen proposal, each with the supplier's article numbers — so
 * nothing important is forgotten. The lead sends them from their own
 * mailbox.
 */
class SupplierMailComposer
{
    /**
     * Suppliers of the products wanted in the round or in its final order.
     *
     * @return Collection<int, Supplier>
     */
    public function suppliersFor(Round $round): Collection
    {
        $productIds = $round->activeCartItems()->pluck('product_id')
            ->merge($round->chosenProposal?->items()->pluck('product_id') ?? [])
            ->unique();

        return Supplier::query()
            ->whereIn('id', Product::withTrashed()->whereIn('id', $productIds)->select('supplier_id'))
            ->orderBy('name')
            ->get();
    }

    public function canCompose(Round $round, Supplier $supplier, SupplierMailType $type): bool
    {
        return match ($type) {
            SupplierMailType::PriceInquiry, SupplierMailType::FollowUp => $this->demandFor($round, $supplier)->isNotEmpty(),
            SupplierMailType::Order => $this->orderedItemsFor($round, $supplier)->isNotEmpty(),
        };
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function compose(Round $round, Supplier $supplier, SupplierMailType $type, User $lead): array
    {
        return match ($type) {
            SupplierMailType::PriceInquiry => $this->priceInquiry($round, $supplier, $lead),
            SupplierMailType::FollowUp => $this->followUp($round, $supplier, $lead),
            SupplierMailType::Order => $this->order($round, $supplier, $lead),
        };
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function priceInquiry(Round $round, Supplier $supplier, User $lead): array
    {
        $body = [
            'Guten Tag,',
            '',
            'wir sind die Einkaufsgemeinschaft „'.$round->group->name.'“ und möchten gemeinsam bei Ihnen bestellen. Für unsere aktuelle Sammelbestellung planen wir ungefähr folgende Mengen:',
            '',
            ...$this->demandLines($round, $supplier),
            '',
            'Können Sie uns bitte Ihre aktuellen Preise für diese Gebinde sowie die Versandkosten nennen? Wir können die Gebindegrößen auch kombinieren.'
                .($round->expected_delivery ? ' Eine Lieferung bis zum '.$round->expected_delivery->format('d.m.Y').' wäre ideal.' : ''),
            '',
            'Vielen Dank und viele Grüße',
            ...$this->signature($round, $lead),
        ];

        return [
            'subject' => 'Preisanfrage Sammelbestellung — '.$round->group->name,
            'body' => implode("\n", $body),
        ];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function followUp(Round $round, Supplier $supplier, User $lead): array
    {
        $body = [
            'Guten Tag,',
            '',
            'vor ein paar Tagen hatten wir Sie um Preise für unsere Sammelbestellung gebeten. Damit wir die Bestellung in der Gruppe abstimmen können, wären wir für eine kurze Rückmeldung zu Preisen und Versandkosten dankbar. Zur Erinnerung die Mengen:',
            '',
            ...$this->demandLines($round, $supplier),
            '',
            'Vielen Dank und viele Grüße',
            ...$this->signature($round, $lead),
        ];

        return [
            'subject' => 'Nachfrage: Preisanfrage Sammelbestellung — '.$round->group->name,
            'body' => implode("\n", $body),
        ];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function order(Round $round, Supplier $supplier, User $lead): array
    {
        $items = $this->orderedItemsFor($round, $supplier);
        $shipping = $round->chosenProposal?->shippingCentsFor($supplier->id) ?? 0;
        $shippingKnown = $round->chosenProposal?->hasShippingFor($supplier->id) ?? false;

        $lines = $items->flatMap(fn (ProposalItem $item): Collection => $item->packages->map(fn (ProposalItemPackage $package): string => sprintf(
            '- %d × %s %s%s à %s = %s',
            $package->count,
            $package->label,
            $item->product?->name,
            $package->article_number ? ' (Art.-Nr. '.$package->article_number.')' : '',
            Money::format($package->price_cents),
            Money::format($package->totalPriceCents()),
        )));

        $body = [
            'Guten Tag,',
            '',
            'vielen Dank für Ihr Angebot. Für unsere Sammelbestellung „'.$round->title.'“ bestellen wir hiermit verbindlich (alle Preise brutto, inkl. MwSt. und ggf. Pfand):',
            '',
            ...$lines->values()->all(),
            '',
            'Warenwert laut Absprache: '.Money::format((int) $items->sum('total_price_cents')).' brutto'
                .match (true) {
                    $shipping > 0 => ', zuzüglich Versandkosten von '.Money::format($shipping).'.',
                    $shippingKnown => ', versandkostenfrei wie besprochen.',
                    default => ', zuzüglich Versandkosten wie besprochen.',
                },
            '',
            'Lieferadresse:',
            $round->pickup_location ?? '(bitte ergänzen)',
            '',
            'Bitte schicken Sie uns eine Bestellbestätigung mit dem voraussichtlichen Liefertermin und der Rechnung.',
            '',
            'Viele Grüße',
            ...$this->signature($round, $lead),
        ];

        return [
            'subject' => 'Bestellung Sammelbestellung — '.$round->group->name,
            'body' => implode("\n", $body),
        ];
    }

    /**
     * "- Dinkelmehl: ca. 15–17 kg — Gebinde: 10 kg Sack (Art.-Nr. 4711), 1 kg Tüte"
     *
     * @return array<int, string>
     */
    private function demandLines(Round $round, Supplier $supplier): array
    {
        return $this->demandFor($round, $supplier)->map(function (Collection $cartItems): string {
            /** @var Product $product */
            $product = $cartItems->first()->product;
            $unit = $product->unitLabel();
            $min = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMin());
            $max = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMax());
            $quantity = CartItem::formatRange($min, $max, $unit);
            $packages = $product->priceTiers
                ->map(fn (PriceTier $tier): string => $tier->label.($tier->article_number ? ' (Art.-Nr. '.$tier->article_number.')' : ''))
                ->implode(', ');

            return '- '.$product->name.': ca. '.$quantity.($packages !== '' ? ' — Gebinde: '.$packages : '');
        })->values()->all();
    }

    /**
     * Wanted quantities per product of this supplier.
     *
     * @return Collection<int, Collection<int, CartItem>>
     */
    private function demandFor(Round $round, Supplier $supplier): Collection
    {
        return $round->activeCartItems()
            ->with('product.priceTiers')
            ->whereIn('product_id', Product::withTrashed()->where('supplier_id', $supplier->id)->select('id'))
            ->get()
            ->groupBy('product_id');
    }

    /**
     * @return Collection<int, ProposalItem>
     */
    private function orderedItemsFor(Round $round, Supplier $supplier): Collection
    {
        $proposal = $round->chosenProposal;

        if ($proposal === null) {
            return collect();
        }

        return $proposal->items()
            ->with(['product', 'packages'])
            ->whereIn('product_id', Product::withTrashed()->where('supplier_id', $supplier->id)->select('id'))
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function signature(Round $round, User $lead): array
    {
        return array_values(array_filter([
            $lead->fullName(),
            $round->group->name,
            $lead->email,
            $lead->phone,
        ]));
    }
}
