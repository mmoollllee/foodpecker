<?php

namespace App\Filament\Pages;

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

class MyCart extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.my-cart';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Mein Warenkorb';

    protected static string|\UnitEnum|null $navigationGroup = 'Bestellungen';

    protected static ?int $navigationSort = 2;

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
        return Action::make('addItem')
            ->label('Artikel hinzufügen')
            ->icon(Heroicon::Plus)
            ->color('primary')
            ->modalHeading(fn (array $arguments) => 'Artikel zu „'.($this->roundFor((int) ($arguments['round_id'] ?? 0))?->title ?? '').'" hinzufügen')
            ->schema([
                Select::make('product_id')
                    ->label('Produkt')
                    ->options(function (array $arguments) {
                        $round = $this->roundFor((int) $arguments['round_id']);

                        return $round
                            ? $round->availableProductsForCart()->orderBy('name')->pluck('name', 'products.id')->all()
                            : [];
                    })
                    ->searchable()
                    ->preload()
                    ->required(),
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options(QuantityMode::class)
                    ->default(QuantityMode::Exact->value)
                    ->required()
                    ->inline()
                    ->live(),
                TextInput::make('exact_quantity')
                    ->label('Exakte Menge')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value),
                TextInput::make('min_quantity')
                    ->label('Mindestmenge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                TextInput::make('max_quantity')
                    ->label('Maximale Menge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                Textarea::make('notes')
                    ->label('Notiz (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $roundId = (int) $arguments['round_id'];
                CartItem::updateOrCreate(
                    [
                        'round_id' => $roundId,
                        'user_id' => auth()->id(),
                        'product_id' => $data['product_id'],
                    ],
                    $data,
                );
                RoundParticipant::firstOrCreate([
                    'round_id' => $roundId,
                    'user_id' => auth()->id(),
                ]);
                Notification::make()->title('Artikel im Warenkorb gespeichert.')->success()->send();
            });
    }

    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->label('Bearbeiten')
            ->icon(Heroicon::PencilSquare)
            ->color('gray')
            ->size('xs')
            ->modalHeading('Artikel bearbeiten')
            ->fillForm(function (array $arguments): array {
                $item = CartItem::find((int) ($arguments['cart_item_id'] ?? 0));

                return $item ? $item->only([
                    'product_id', 'quantity_mode', 'exact_quantity', 'min_quantity', 'max_quantity', 'notes',
                ]) : [];
            })
            ->schema([
                Select::make('product_id')
                    ->label('Produkt')
                    ->disabled()
                    ->dehydrated(false)
                    ->options(fn () => Product::pluck('name', 'id')),
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options(QuantityMode::class)
                    ->required()
                    ->inline()
                    ->live(),
                TextInput::make('exact_quantity')
                    ->label('Exakte Menge')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value),
                TextInput::make('min_quantity')
                    ->label('Mindestmenge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                TextInput::make('max_quantity')
                    ->label('Maximale Menge (flexibel)')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                Textarea::make('notes')->label('Notiz')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = CartItem::find((int) ($arguments['cart_item_id'] ?? 0));
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

    public function roundFor(int $id): ?Round
    {
        return Round::find($id);
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
