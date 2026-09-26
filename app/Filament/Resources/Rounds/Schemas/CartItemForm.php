<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Enums\QuantityMode;
use App\Models\Group;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Round;
use App\Services\Estimates\PriceEstimator;
use App\Services\Money\Money;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Form fields for a cart item, shared by "Mein Warenkorb" and the round page.
 */
class CartItemForm
{
    /**
     * @param  Closure(Get): array<int|string, mixed>  $options  Product options, may be grouped by category.
     * @param  (Closure(Get): ?string)|null  $hint  Optional hint below the select, e.g. last time's quantity.
     */
    public static function productSelect(Closure $options, ?Closure $hint = null): Select
    {
        return Select::make('product_id')
            ->label('Produkt')
            ->options($options)
            ->searchable()
            ->preload()
            ->required()
            ->live()
            ->helperText($hint)
            ->columnSpanFull();
    }

    public static function productCard(): Placeholder
    {
        return Placeholder::make('product_card')
            ->hiddenLabel()
            ->visible(fn (Get $get): bool => (int) $get('product_id') > 0)
            ->content(fn (Get $get): Htmlable => static::renderProductCard((int) $get('product_id')))
            ->columnSpanFull();
    }

    /**
     * The kind of quantity and its inputs side by side in one row.
     *
     * @return array<int, mixed>
     */
    public static function quantityFields(): array
    {
        return [
            Flex::make([
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options(QuantityMode::class)
                    ->default(QuantityMode::Exact->value)
                    ->required()
                    ->inline()
                    ->live()
                    ->grow(false),
                ...static::quantityInputs(),
            ])
                ->from('sm')
                ->columnSpanFull(),
        ];
    }

    /**
     * Amounts come in whole portions of the product — whole jars, whole
     * 350 g packs, half kilograms from the sack — or in hundredths for
     * products that go out in whole packages. The browser counts the step
     * from the min value, so both stay on that grid: a min of 0.001 would
     * reject 0.1 or 5 and only accept 0.101 or 5.001.
     *
     * @return array<int, TextInput>
     */
    private static function quantityInputs(): array
    {
        return [
            TextInput::make('exact_quantity')
                ->label('Exakte Menge')
                ->live(debounce: 600)
                ->numeric()
                ->minValue(fn (Get $get): float => static::stepFor($get))
                ->step(fn (Get $get): float => static::stepFor($get))
                ->suffix(fn (Get $get): ?string => static::unitFor($get))
                ->visible(fn (Get $get): bool => static::mode($get) === QuantityMode::Exact)
                ->required(fn (Get $get): bool => static::mode($get) === QuantityMode::Exact),
            TextInput::make('min_quantity')
                ->label('Mindestens')
                ->live(debounce: 600)
                ->numeric()
                ->minValue(0)
                ->step(fn (Get $get): float => static::stepFor($get))
                ->suffix(fn (Get $get): ?string => static::unitFor($get))
                ->visible(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible)
                ->required(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible),
            TextInput::make('max_quantity')
                ->label('Höchstens')
                ->live(debounce: 600)
                ->numeric()
                ->minValue(fn (Get $get): float => static::stepFor($get))
                ->gte('min_quantity')
                ->step(fn (Get $get): float => static::stepFor($get))
                ->suffix(fn (Get $get): ?string => static::unitFor($get))
                ->visible(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible)
                ->required(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible),
        ];
    }

    /**
     * Only asked for products with several pack sizes that can't be split,
     * e.g. spaghetti in 250 g packs or 2 kg bags.
     */
    public static function preferredTierField(): Select
    {
        return Select::make('preferred_price_tier_id')
            ->label('Packungsgröße')
            ->placeholder('Automatisch passend zur Menge')
            ->options(fn (Get $get): array => PriceTier::query()
                ->where('product_id', (int) $get('product_id'))
                ->orderBy('sort_order')
                ->orderBy('package_amount')
                ->get()
                ->mapWithKeys(fn (PriceTier $tier): array => [$tier->id => $tier->label.' · '.$tier->formattedPrice()])
                ->all())
            ->visible(fn (Get $get): bool => static::hasSeveralIndivisiblePackSizes((int) $get('product_id')))
            ->live()
            ->helperText('Jede Packung geht ganz an eine Person — welche Größe hättest du gern?')
            ->columnSpanFull();
    }

    /**
     * What the wish would probably cost — with the wishes of everybody else
     * in the round, updated while typing.
     *
     * @param  Closure(): ?Round  $round
     * @param  Closure(Get): ?int  $userId  Whose wish it is.
     */
    public static function estimateHint(Closure $round, Closure $userId): Text
    {
        return Text::make(function (Get $get) use ($round, $userId): string {
            $tenant = Filament::getTenant();
            $product = Product::withTrashed()->visibleTo($tenant instanceof Group ? $tenant : null)->find((int) $get('product_id'));
            [$min, $max] = static::wishedRange($get);
            $theRound = $round();
            $owner = $userId($get);

            $estimate = $product && $theRound && $owner && $max > 0
                ? app(PriceEstimator::class)->estimateWish($theRound, $product, $owner, $min, $max, ((int) $get('preferred_price_tier_id')) ?: null)
                : null;

            if ($estimate === null) {
                return '';
            }

            $price = $estimate['from'] === $estimate['to']
                ? Money::format($estimate['to'])
                : Money::format($estimate['from']).' – '.Money::format($estimate['to']);
            $perUnit = $estimate['perUnit'] !== null
                ? ' ('.number_format($estimate['perUnit'] / 100, 2, ',', '.').' € / '.$product->unitLabel().' bei der aktuellen Gesamtmenge der Gruppe)'
                : '';

            return 'Voraussichtlich '.$price.' inkl. Beiträge'.$perUnit.' — Versand kommt nach den Rückmeldungen der Lieferanten dazu.';
        })
            ->visible(fn (Get $get): bool => (int) $get('product_id') > 0 && static::wishedRange($get)[1] > 0)
            ->columnSpanFull();
    }

    public static function notesField(): Textarea
    {
        return Textarea::make('notes')
            ->label('Notiz (optional)')
            ->rows(2)
            ->maxLength(1000)
            ->columnSpanFull();
    }

    /**
     * Only products the current group may see are rendered — the id comes
     * from form state and could be tampered with.
     */
    public static function renderProductCard(int $productId): Htmlable
    {
        $tenant = Filament::getTenant();

        $product = $productId > 0
            ? Product::withTrashed()
                ->visibleTo($tenant instanceof Group ? $tenant : null)
                ->with(['supplier', 'priceTiers'])
                ->find($productId)
            : null;

        return new HtmlString(
            view('components.foodpecker.product-card', [
                'product' => $product,
                'compact' => false,
                'showDescription' => true,
                'showPricing' => true,
            ])->render()
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function wishedRange(Get $get): array
    {
        return static::mode($get) === QuantityMode::Flexible
            ? [(float) $get('min_quantity'), (float) $get('max_quantity')]
            : [(float) $get('exact_quantity'), (float) $get('exact_quantity')];
    }

    private static function mode(Get $get): ?QuantityMode
    {
        $mode = $get('quantity_mode');

        return $mode instanceof QuantityMode ? $mode : QuantityMode::tryFrom((string) $mode);
    }

    private static function unitFor(Get $get): ?string
    {
        $productId = (int) $get('product_id');

        return $productId > 0 ? Product::withTrashed()->find($productId)?->unitLabel() : null;
    }

    /**
     * One portion of the chosen product, or a hundredth when it goes out in
     * whole packages.
     */
    private static function stepFor(Get $get): float
    {
        $productId = (int) $get('product_id');

        return ($productId > 0 ? Product::withTrashed()->find($productId)?->portionSize() : null) ?? 0.01;
    }

    private static function hasSeveralIndivisiblePackSizes(int $productId): bool
    {
        $product = $productId > 0 ? Product::withTrashed()->withCount('priceTiers')->find($productId) : null;

        return $product !== null && ! $product->isPortioned() && $product->price_tiers_count > 1;
    }
}
