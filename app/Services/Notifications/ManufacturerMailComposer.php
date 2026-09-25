<?php

namespace App\Services\Notifications;

use App\Enums\ManufacturerMailType;
use App\Models\CartItem;
use App\Models\Manufacturer;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\Money;
use Illuminate\Support\Collection;

/**
 * Drafts the mails a lead sends to manufacturers — a price inquiry with the
 * expected quantities and the order of the chosen proposal — so nothing
 * important is forgotten. The lead sends them from their own mailbox.
 */
class ManufacturerMailComposer
{
    /**
     * Manufacturers of the products wanted in the round or in its final order.
     *
     * @return Collection<int, Manufacturer>
     */
    public function manufacturersFor(Round $round): Collection
    {
        $productIds = $round->activeCartItems()->pluck('product_id')
            ->merge($round->chosenProposal?->items()->pluck('product_id') ?? [])
            ->unique();

        return Manufacturer::query()
            ->whereIn('id', Product::withTrashed()->whereIn('id', $productIds)->select('manufacturer_id'))
            ->orderBy('name')
            ->get();
    }

    public function canCompose(Round $round, Manufacturer $manufacturer, ManufacturerMailType $type): bool
    {
        return match ($type) {
            ManufacturerMailType::PriceInquiry => $this->demandFor($round, $manufacturer)->isNotEmpty(),
            ManufacturerMailType::Order => $this->orderedItemsFor($round, $manufacturer)->isNotEmpty(),
        };
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function compose(Round $round, Manufacturer $manufacturer, ManufacturerMailType $type, User $lead): array
    {
        return match ($type) {
            ManufacturerMailType::PriceInquiry => $this->priceInquiry($round, $manufacturer, $lead),
            ManufacturerMailType::Order => $this->order($round, $manufacturer, $lead),
        };
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function priceInquiry(Round $round, Manufacturer $manufacturer, User $lead): array
    {
        $lines = $this->demandFor($round, $manufacturer)->map(function (Collection $cartItems): string {
            /** @var Product $product */
            $product = $cartItems->first()->product;
            $unit = $product->unitLabel();
            $min = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMin());
            $max = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMax());
            $quantity = abs($max - $min) < 0.001
                ? CartItem::formatQuantity($min).' '.$unit
                : CartItem::formatQuantity($min).'–'.CartItem::formatQuantity($max).' '.$unit;
            $packages = $product->priceTiers->map(fn (PriceTier $tier): string => $tier->label)->implode(' oder ');

            return '- '.$product->name.': ca. '.$quantity.($packages !== '' ? ' (z. B. als '.$packages.')' : '');
        });

        $body = [
            'Guten Tag,',
            '',
            'wir sind die Einkaufsgemeinschaft „'.$round->group->name.'“ und möchten gemeinsam bei Ihnen bestellen. Für unsere aktuelle Sammelbestellung planen wir ungefähr folgende Mengen:',
            '',
            ...$lines->values()->all(),
            '',
            'Können Sie uns bitte Ihre aktuellen Preise und Gebindegrößen für diese Mengen sowie die Versandkosten nennen?'
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
    private function order(Round $round, Manufacturer $manufacturer, User $lead): array
    {
        $items = $this->orderedItemsFor($round, $manufacturer);

        $lines = $items->map(fn (ProposalItem $item): string => sprintf(
            '- %d × %s %s à %s = %s',
            $item->packages_ordered,
            $item->packageLabel(),
            $item->product?->name,
            Money::format((int) $item->package_price_cents),
            Money::format($item->total_price_cents),
        ));

        $body = [
            'Guten Tag,',
            '',
            'vielen Dank für Ihr Angebot. Für unsere Sammelbestellung „'.$round->title.'“ bestellen wir hiermit verbindlich:',
            '',
            ...$lines->values()->all(),
            '',
            'Warenwert laut Absprache: '.Money::format((int) $items->sum('total_price_cents')).', zuzüglich Versandkosten wie besprochen.',
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
     * Wanted quantities per product of this manufacturer.
     *
     * @return Collection<int, Collection<int, CartItem>>
     */
    private function demandFor(Round $round, Manufacturer $manufacturer): Collection
    {
        return $round->activeCartItems()
            ->with('product.priceTiers')
            ->whereIn('product_id', Product::withTrashed()->where('manufacturer_id', $manufacturer->id)->select('id'))
            ->get()
            ->groupBy('product_id');
    }

    /**
     * @return Collection<int, ProposalItem>
     */
    private function orderedItemsFor(Round $round, Manufacturer $manufacturer): Collection
    {
        $proposal = $round->chosenProposal;

        if ($proposal === null) {
            return collect();
        }

        return $proposal->items()
            ->with('product')
            ->whereIn('product_id', Product::withTrashed()->where('manufacturer_id', $manufacturer->id)->select('id'))
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
