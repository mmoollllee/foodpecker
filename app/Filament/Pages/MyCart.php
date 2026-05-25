<?php

namespace App\Filament\Pages;

use App\Enums\ProductCategory;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\Product;
use App\Models\Round;
use App\Models\RoundParticipant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class MyCart extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.my-cart';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Mein Warenkorb';

    protected static string|\UnitEnum|null $navigationGroup = 'Bestellungen';

    protected static ?int $navigationSort = 2;

    /**
     * Round-ID, an die die aktuell offene Add/Edit-Action gebunden ist.
     * Wird in mountUsing() aus den Action-Arguments gesetzt — `$arguments` ist
     * in Schema-Component-Closures nicht direkt verfügbar.
     */
    public ?int $contextRoundId = null;

    public ?int $contextCartItemId = null;

    public function getTitle(): string|Htmlable
    {
        return 'Mein Warenkorb';
    }

    public function getSubheading(): ?string
    {
        return 'Alle aktiven Bestellrunden, in denen du mitmachen kannst. Auf jeder Karte kannst du Artikel hinzufügen, bearbeiten oder entfernen.';
    }

    public function addItemAction(): Action
    {
        $modeOptions = collect(QuantityMode::cases())
            ->mapWithKeys(fn (QuantityMode $m) => [$m->value => $m->getLabel()])
            ->all();

        return Action::make('addItem')
            ->label('Artikel hinzufügen')
            ->icon(Heroicon::Plus)
            ->color('primary')
            ->mountUsing(function (array $arguments): void {
                $this->contextRoundId = (int) ($arguments['round_id'] ?? 0);
            })
            ->modalHeading(function (array $arguments): string {
                $round = $this->roundFromArguments($arguments);

                return 'Artikel zu „'.($round?->title ?? 'Bestellrunde').'" hinzufügen';
            })
            ->modalDescription(function (array $arguments): ?string {
                $round = $this->roundFromArguments($arguments);
                if (! $round) {
                    return null;
                }
                $deadline = $round->shopping_deadline?->format('d.m.Y');

                return 'Lead: '.($round->lead?->fullName() ?? '—')
                    .($deadline ? ' · Einkauf bis '.$deadline : '')
                    .' · Alle Teilnehmer der Gruppe sehen, was du in den Korb legst.';
            })
            ->modalSubmitActionLabel('In Warenkorb legen')
            ->fillForm(fn (array $arguments): array => [
                'round_id' => (int) ($arguments['round_id'] ?? $this->contextRoundId ?? 0),
                'quantity_mode' => QuantityMode::Exact->value,
            ])
            ->schema([
                Hidden::make('round_id'),
                Select::make('product_id')
                    ->label('Produkt')
                    ->options(fn (Get $get): array => $this->groupedProductOptionsForRound((int) $get('round_id')))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText('Sortiert nach Kategorie. Hersteller steht hinter dem Produktnamen.'),
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options($modeOptions)
                    ->default(QuantityMode::Exact->value)
                    ->required()
                    ->inline()
                    ->live(),
                TextInput::make('exact_quantity')
                    ->label('Exakte Menge')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value),
                TextInput::make('min_quantity')
                    ->label('Mindestmenge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                TextInput::make('max_quantity')
                    ->label('Maximale Menge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                Textarea::make('notes')
                    ->label('Notiz (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $roundId = (int) ($data['round_id'] ?? $this->contextRoundId ?? 0);
                if ($roundId === 0) {
                    return;
                }
                $payload = collect($data)->except('round_id')->all();
                CartItem::updateOrCreate(
                    [
                        'round_id' => $roundId,
                        'user_id' => auth()->id(),
                        'product_id' => $payload['product_id'],
                    ],
                    $payload,
                );
                RoundParticipant::firstOrCreate([
                    'round_id' => $roundId,
                    'user_id' => auth()->id(),
                ]);
                Notification::make()->title('Artikel im Warenkorb gespeichert.')->success()->send();
            });
    }

    /** @return array<int, string> */
    private function productOptionsForRound(int $roundId): array
    {
        if ($roundId === 0) {
            return [];
        }
        $round = Round::find($roundId);

        return $round
            ? $round->availableProductsForCart()->orderBy('name')->pluck('name', 'products.id')->all()
            : [];
    }

    /**
     * Nach Kategorie gruppierte Optionen mit Hersteller-Suffix als Label.
     *
     * @return array<string, array<int, string>>
     */
    private function groupedProductOptionsForRound(int $roundId): array
    {
        if ($roundId === 0) {
            return [];
        }
        $round = Round::find($roundId);
        if (! $round) {
            return [];
        }

        /** @var Collection<int, Product> $products */
        $products = $round->availableProductsForCart()
            ->with('manufacturer')
            ->get(['products.id', 'products.name', 'products.category', 'products.manufacturer_id']);

        $grouped = [];
        foreach ($products as $product) {
            $category = $product->category;
            $groupLabel = $category instanceof ProductCategory
                ? $category->getLabel()
                : 'Sonstiges';
            $sort = $category instanceof ProductCategory ? $category->sortOrder() : 99;
            $key = sprintf('%02d_%s', $sort, $groupLabel);

            $manufacturer = $product->manufacturer?->name;
            $label = $manufacturer ? $product->name.' · '.$manufacturer : $product->name;
            $grouped[$key][$product->id] = $label;
        }

        ksort($grouped);
        // Sort-Prefix wieder entfernen
        $result = [];
        foreach ($grouped as $key => $items) {
            $label = preg_replace('/^\d+_/', '', $key);
            asort($items);
            $result[$label] = $items;
        }

        return $result;
    }

    private function unitLabelForProduct(int $productId): ?string
    {
        if ($productId === 0) {
            return null;
        }
        $unit = Product::query()->where('id', $productId)->value('unit');

        return match ($unit) {
            'kg' => 'kg',
            'g' => 'g',
            'l' => 'l',
            'ml' => 'ml',
            'stk' => 'Stück',
            'glas' => 'Glas',
            'pkg' => 'Packung',
            default => $unit,
        };
    }

    private function roundFromArguments(array $arguments): ?Round
    {
        $id = (int) ($arguments['round_id'] ?? $this->contextRoundId ?? 0);
        if ($id === 0) {
            return null;
        }

        return Round::with('lead')->find($id);
    }

    public function editItemAction(): Action
    {
        $modeOptions = collect(QuantityMode::cases())
            ->mapWithKeys(fn (QuantityMode $m) => [$m->value => $m->getLabel()])
            ->all();

        return Action::make('editItem')
            ->label('Bearbeiten')
            ->icon(Heroicon::PencilSquare)
            ->color('gray')
            ->size('xs')
            ->modalHeading('Artikel bearbeiten')
            ->modalSubmitActionLabel('Speichern')
            ->mountUsing(function (array $arguments): void {
                $this->contextCartItemId = (int) ($arguments['cart_item_id'] ?? 0);
            })
            ->fillForm(function (): array {
                $item = CartItem::find($this->contextCartItemId);
                if (! $item) {
                    return [];
                }

                $data = $item->only([
                    'product_id', 'exact_quantity', 'min_quantity', 'max_quantity', 'notes',
                ]);
                // Enum-Cast → String, damit ToggleButtons den state mit === vergleichen kann
                $data['quantity_mode'] = $item->quantity_mode instanceof QuantityMode
                    ? $item->quantity_mode->value
                    : $item->quantity_mode;

                return $data;
            })
            ->schema([
                Select::make('product_id')
                    ->label('Produkt')
                    ->disabled()
                    ->dehydrated(false)
                    ->options(fn (): array => Product::pluck('name', 'id')->all()),
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options($modeOptions)
                    ->required()
                    ->inline()
                    ->live(),
                TextInput::make('exact_quantity')
                    ->label('Exakte Menge')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value),
                TextInput::make('min_quantity')
                    ->label('Mindestmenge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                TextInput::make('max_quantity')
                    ->label('Maximale Menge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                Textarea::make('notes')->label('Notiz')->rows(2),
            ])
            ->action(function (array $data): void {
                $item = CartItem::find($this->contextCartItemId);
                if ($item) {
                    $item->update($data);
                    Notification::make()->title('Artikel aktualisiert.')->success()->send();
                }
            });
    }

    public function removeItem(int $cartItemId): void
    {
        $item = CartItem::find($cartItemId);
        if ($item && $item->user_id === auth()->id()) {
            $item->delete();
            Notification::make()->title('Artikel entfernt.')->success()->send();
        }
    }

    public function contextRound(): ?Round
    {
        return $this->contextRoundId ? Round::find($this->contextRoundId) : null;
    }

    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Group || ! $user) {
            return ['rounds' => collect()];
        }

        $rounds = Round::query()
            ->where('group_id', $tenant->id)
            ->where('phase', RoundPhase::Shopping->value)
            ->with([
                'cartItems' => fn ($q) => $q->where('user_id', $user->id)->with('product.manufacturer', 'product.priceTiers'),
            ])
            ->latest('updated_at')
            ->get();

        return [
            'rounds' => $rounds,
            'detailUrl' => fn (Round $r) => RoundResource::getUrl('view', ['record' => $r]),
        ];
    }
}
