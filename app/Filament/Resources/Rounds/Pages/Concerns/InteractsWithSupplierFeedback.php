<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\SupplierMailType;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\Supplier;
use App\Services\Money\Money;
use App\Services\Notifications\SupplierMailComposer;
use App\Services\Rounds\SupplierFeedback;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The supplier cards of the adjustment phase: ask for prices with one
 * click, then record what came back — prices per package, packages that
 * can't be delivered, shipping.
 */
trait InteractsWithSupplierFeedback
{
    /**
     * What the lead types into the cards, by supplier id.
     *
     * @var array<int|string, array{shipping: string, prices: array<int|string, string>, unavailable: array<int|string, bool>}>
     */
    public array $supplierFeedback = [];

    /**
     * @var Collection<int, Supplier>|null
     */
    protected ?Collection $feedbackSuppliersCache = null;

    public function mountInteractsWithSupplierFeedback(): void
    {
        $this->fillMissingSupplierFeedback();
    }

    /**
     * Suppliers of the products somebody wants in the round.
     *
     * @return Collection<int, Supplier>
     */
    public function feedbackSuppliers(): Collection
    {
        return $this->feedbackSuppliersCache ??= app(SupplierFeedback::class)->suppliersFor($this->getRound());
    }

    /**
     * The supplier's packages somebody wants, grouped by product.
     *
     * @return Collection<int, Collection<int, PriceTier>>
     */
    public function feedbackPackages(Supplier $supplier): Collection
    {
        return app(SupplierFeedback::class)->packagesFor($this->getRound(), $supplier)->groupBy('product_id');
    }

    /**
     * What the group wants of a product, e.g. "15–20 kg".
     */
    public function wantedQuantity(int $productId): string
    {
        $cartItems = $this->getRound()->cartItems
            ->where('product_id', $productId)
            ->reject(fn (CartItem $item): bool => $this->getRound()->isExcluded($item->user));

        $min = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMin());
        $max = $cartItems->sum(fn (CartItem $item): float => $item->effectiveMax());
        $unit = $cartItems->first()?->product?->unitLabel() ?? '';

        return CartItem::formatRange($min, $max, $unit);
    }

    public function canRecordSupplierFeedback(): bool
    {
        return $this->canManage() && in_array($this->getRound()->phase, SupplierFeedback::PHASES, true);
    }

    /**
     * Opens the prepared mail in the mail program. A price inquiry marks the
     * supplier as asked.
     */
    public function openSupplierMail(int $supplierId, string $type): void
    {
        $supplier = $this->feedbackSuppliers()->firstWhere('id', $supplierId);
        $type = SupplierMailType::tryFrom($type);

        if (! $this->canRecordSupplierFeedback() || $supplier === null || $type === null || $type === SupplierMailType::Order) {
            return;
        }

        $mail = app(SupplierMailComposer::class)->compose($this->getRound(), $supplier, $type, $this->currentUser());

        if ($type === SupplierMailType::PriceInquiry) {
            $this->attempt(fn () => app(SupplierFeedback::class)->markInquired($this->getRound(), $supplier, $this->currentUser()), 'Anfrage nicht vermerkt');
        }

        if (blank($supplier->contact_email)) {
            Notification::make()
                ->title('Für '.$supplier->name.' ist keine E-Mail-Adresse hinterlegt.')
                ->body('Trag den Empfänger im Mailprogramm ein.')
                ->warning()
                ->send();
        }

        $url = 'mailto:'.rawurlencode((string) $supplier->contact_email)
            .'?subject='.rawurlencode($mail['subject'])
            .'&body='.rawurlencode($mail['body']);

        $this->js('window.location.href = '.json_encode($url).';');
    }

    public function saveSupplierFeedback(int $supplierId): void
    {
        $supplier = $this->feedbackSuppliers()->firstWhere('id', $supplierId);

        if (! $this->canRecordSupplierFeedback() || $supplier === null) {
            return;
        }

        $input = $this->supplierFeedback[$supplierId] ?? [];
        $shipping = $this->parseMoney($input['shipping'] ?? '', 'Versandkosten');
        $prices = [];

        // Only packages the card shows: what it doesn't know about stays as saved.
        foreach ($this->feedbackPackages($supplier)->flatten() as $tier) {
            if (array_key_exists($tier->id, $input['prices'] ?? [])) {
                $prices[$tier->id] = $this->parseMoney($input['prices'][$tier->id], $tier->label);
            }
        }

        if (in_array(false, [$shipping, ...$prices], true)) {
            return;
        }

        $saved = $this->attempt(fn () => app(SupplierFeedback::class)->record($this->getRound(), $supplier, [
            'shipping_cents' => $shipping,
            'prices' => $prices,
            'unavailable' => array_map(fn (mixed $value): bool => (bool) $value, $input['unavailable'] ?? []),
        ], $this->currentUser()), 'Rückmeldung nicht gespeichert');

        if ($saved) {
            $this->fillSupplierFeedback($supplierId);

            Notification::make()
                ->title('Rückmeldung von '.$supplier->name.' gespeichert.')
                ->body('Der Bestellvorschlag ist neu berechnet.')
                ->success()
                ->send();
        }
    }

    /**
     * Fills the cards' inputs from what is saved. With `$onlyMissing`, what
     * was typed but not saved yet stays; only suppliers and packages the
     * cards didn't show so far are added — e.g. after a readmission.
     */
    protected function fillSupplierFeedback(?int $onlySupplierId = null, bool $onlyMissing = false): void
    {
        $round = $this->getRound();
        $prices = $round->packagePrices()->get()->keyBy('price_tier_id');

        foreach ($this->feedbackSuppliers() as $supplier) {
            if ($onlySupplierId !== null && $supplier->id !== $onlySupplierId) {
                continue;
            }

            $record = $round->roundSuppliers()->where('supplier_id', $supplier->id)->first();
            $tiers = $this->feedbackPackages($supplier)->flatten();

            $saved = [
                'shipping' => $record?->shipping_cents !== null ? Money::toInputString($record->shipping_cents) : '',
                'prices' => $tiers->mapWithKeys(fn (PriceTier $tier): array => [
                    $tier->id => ($cents = $prices->get($tier->id)?->price_cents) !== null ? Money::toInputString($cents) : '',
                ])->all(),
                'unavailable' => $tiers->mapWithKeys(fn (PriceTier $tier): array => [
                    $tier->id => $prices->has($tier->id) && ! $prices->get($tier->id)->is_available,
                ])->all(),
            ];

            $typed = $onlyMissing ? ($this->supplierFeedback[$supplier->id] ?? null) : null;

            $this->supplierFeedback[$supplier->id] = $typed === null ? $saved : [
                'shipping' => $typed['shipping'] ?? $saved['shipping'],
                'prices' => ($typed['prices'] ?? []) + $saved['prices'],
                'unavailable' => ($typed['unavailable'] ?? []) + $saved['unavailable'],
            ];
        }
    }

    /**
     * Only the lead needs the inputs, and only while feedback is recorded.
     */
    protected function fillMissingSupplierFeedback(): void
    {
        if ($this->canRecordSupplierFeedback()) {
            $this->fillSupplierFeedback(onlyMissing: true);
        }
    }

    /**
     * Empty means "not given"; false marks an amount that couldn't be read.
     */
    private function parseMoney(mixed $value, string $label): int|false|null
    {
        if (blank($value)) {
            return null;
        }

        $cents = Money::parse((string) $value);

        if ($cents === null) {
            Notification::make()
                ->title($label.': „'.$value.'“ ist kein Betrag.')
                ->body('Bitte so eingeben: 12,50')
                ->danger()
                ->send();

            return false;
        }

        return $cents;
    }
}
