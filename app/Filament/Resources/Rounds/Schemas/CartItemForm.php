<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Enums\QuantityMode;
use App\Models\Group;
use App\Models\PriceTier;
use App\Models\Product;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Flex;
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
     * The browser counts the step from the min value, so both stay on the
     * same 0.01 grid — a min of 0.001 would reject 0.1 or 5 and only accept
     * 0.101 or 5.001.
     *
     * @return array<int, TextInput>
     */
    private static function quantityInputs(): array
    {
        return [
            TextInput::make('exact_quantity')
                ->label('Exakte Menge')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->suffix(fn (Get $get): ?string => static::unitFor($get))
                ->visible(fn (Get $get): bool => static::mode($get) === QuantityMode::Exact)
                ->required(fn (Get $get): bool => static::mode($get) === QuantityMode::Exact),
            TextInput::make('min_quantity')
                ->label('Mindestens')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->suffix(fn (Get $get): ?string => static::unitFor($get))
                ->visible(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible)
                ->required(fn (Get $get): bool => static::mode($get) === QuantityMode::Flexible),
            TextInput::make('max_quantity')
                ->label('Höchstens')
                ->numeric()
                ->minValue(0.01)
                ->gte('min_quantity')
                ->step(0.01)
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
            ->helperText('Jede Packung geht ganz an eine Person — welche Größe hättest du gern?')
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
                ->with(['manufacturer', 'priceTiers'])
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

    private static function hasSeveralIndivisiblePackSizes(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $tiers = PriceTier::query()->where('product_id', $productId)->get(['id', 'is_divisible']);

        return $tiers->count() > 1 && $tiers->every(fn (PriceTier $tier): bool => ! $tier->is_divisible);
    }
}
