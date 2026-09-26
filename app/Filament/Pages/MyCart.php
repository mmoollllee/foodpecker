<?php

namespace App\Filament\Pages;

use App\Enums\ProductCategory;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Filament\Resources\Rounds\Schemas\CartItemForm;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use App\Services\Estimates\PriceEstimator;
use App\Services\Rounds\CartService;
use App\Services\Rounds\OrderHistory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The own cart in the round the group is running. It can be changed while
 * the round is shopping and stays visible afterwards.
 */
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
     * Filters of the product overview.
     */
    #[Url]
    public string $search = '';

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?int $supplier = null;

    protected ?Round $runningRound = null;

    protected bool $hasResolvedRunningRound = false;

    public function getTitle(): string|Htmlable
    {
        return 'Mein Warenkorb';
    }

    public function getSubheading(): ?string
    {
        $round = $this->getRunningRound();

        if ($round === null) {
            return null;
        }

        return collect([
            "Für „{$round->title}“",
            'Lead: '.($round->lead?->fullName() ?? '—'),
            $round->phase === RoundPhase::Shopping
                ? ($round->shopping_deadline ? 'Einkauf bis '.$round->shopping_deadline->format('d.m.Y') : null)
                : 'Phase: '.$round->phase->getLabel(),
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addItemAction(),
            Action::make('openRound')
                ->label('Zur Runde')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => $this->getRunningRound() !== null)
                ->url(fn (): ?string => ($round = $this->getRunningRound()) ? RoundResource::getUrl('view', ['record' => $round]) : null),
        ];
    }

    public function addItemAction(): Action
    {
        return Action::make('addItem')
            ->label('Artikel hinzufügen')
            ->icon(Heroicon::Plus)
            ->color('primary')
            ->visible(fn (): bool => $this->canShop())
            ->modalHeading('Artikel hinzufügen')
            ->modalDescription('Alle in der Gruppe sehen, was du in den Korb legst.')
            ->modalSubmitActionLabel('In den Warenkorb')
            ->fillForm(fn (array $arguments): array => [
                'product_id' => $arguments['product'] ?? null,
                'quantity_mode' => QuantityMode::Exact->value,
                'exact_quantity' => $arguments['quantity'] ?? null,
            ])
            ->schema(fn (): array => [
                CartItemForm::productSelect(
                    fn (): array => ($round = $this->getRunningRound()) ? $this->groupedProductOptions($round) : [],
                    fn (Get $get): ?string => ($round = $this->getRunningRound()) ? $this->previousQuantityHint($round, (int) $get('product_id')) : null,
                ),
                CartItemForm::productCard(),
                ...CartItemForm::quantityFields(),
                CartItemForm::estimateHint(fn (): ?Round => $this->getRunningRound(), fn (): int => $this->currentUser()->id),
                CartItemForm::preferredTierField(),
                CartItemForm::notesField(),
            ])
            ->action(function (array $data): void {
                $round = $this->getRunningRound();

                if ($round && $this->attempt(fn () => app(CartService::class)->save($round, $this->currentUser(), $data))) {
                    Notification::make()->title('Artikel im Warenkorb gespeichert.')->success()->send();
                }
            });
    }

    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->label('Ändern')
            ->icon(Heroicon::PencilSquare)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->cartItemFromArguments($arguments) !== null)
            ->modalHeading('Artikel ändern')
            ->modalSubmitActionLabel('Speichern')
            ->fillForm(function (array $arguments): array {
                $item = $this->cartItemFromArguments($arguments);

                if (! $item) {
                    return [];
                }

                return [
                    ...$item->only(['product_id', 'exact_quantity', 'min_quantity', 'max_quantity', 'preferred_price_tier_id', 'notes']),
                    'quantity_mode' => $item->quantity_mode->value,
                ];
            })
            ->schema([
                Hidden::make('product_id'),
                CartItemForm::productCard(),
                ...CartItemForm::quantityFields(),
                CartItemForm::estimateHint(fn (): ?Round => $this->getRunningRound(), fn (): int => $this->currentUser()->id),
                CartItemForm::preferredTierField(),
                CartItemForm::notesField(),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = $this->cartItemFromArguments($arguments);

                if ($item && $this->attempt(fn () => app(CartService::class)->save($item->round, $this->currentUser(), [
                    ...$data,
                    'product_id' => $item->product_id,
                ]))) {
                    Notification::make()->title('Artikel aktualisiert.')->success()->send();
                }
            });
    }

    public function removeItemAction(): Action
    {
        return Action::make('removeItem')
            ->label('Entfernen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->cartItemFromArguments($arguments) !== null)
            ->requiresConfirmation()
            ->modalHeading('Artikel entfernen?')
            ->action(function (array $arguments): void {
                $item = $this->cartItemFromArguments($arguments);

                if ($item && $this->attempt(fn () => app(CartService::class)->remove($item, $this->currentUser()))) {
                    Notification::make()->title('Artikel entfernt.')->success()->send();
                }
            });
    }

    public function copyPreviousOrderAction(): Action
    {
        return Action::make('copyPreviousOrder')
            ->label('Alles übernehmen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->canShop() && $this->previousSuggestions($this->getRunningRound())->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading('Mengen aus der letzten Runde übernehmen?')
            ->modalDescription('Alle Produkte, die du letztes Mal bekommen hast und die es diesmal gibt, landen mit derselben Menge in deinem Warenkorb. Du kannst sie danach noch ändern.')
            ->action(function (): void {
                $round = $this->getRunningRound();

                if (! $round) {
                    return;
                }

                $suggestions = $this->previousSuggestions($round);

                $copied = $this->attempt(function () use ($round, $suggestions): void {
                    foreach ($suggestions as $suggestion) {
                        app(CartService::class)->save($round, $this->currentUser(), [
                            'product_id' => $suggestion['product_id'],
                            'quantity_mode' => QuantityMode::Exact->value,
                            'exact_quantity' => $suggestion['quantity'],
                        ]);
                    }
                });

                if ($copied) {
                    Notification::make()->title($suggestions->count().' Artikel übernommen.')->success()->send();
                }
            });
    }

    public function getViewData(): array
    {
        $round = $this->getRunningRound();

        if ($round === null) {
            return ['round' => null];
        }

        $participant = $round->participantFor($this->currentUser());
        $items = $round->cartItems()
            ->where('user_id', $this->currentUser()->id)
            ->with(['product.supplier', 'product.priceTiers', 'preferredTier'])
            ->get();
        $products = $this->canShop() ? $this->orderableProducts($round) : collect();

        return [
            'round' => $round,
            'participant' => $participant,
            'items' => $items,
            'canShop' => $this->canShop(),
            'isFull' => $round->phase === RoundPhase::Shopping && $participant === null && $round->hasReachedParticipantLimit(),
            'suggestions' => $this->canShop() ? $this->previousSuggestions($round) : collect(),
            'roundUrl' => RoundResource::getUrl('view', ['record' => $round]),
            'estimate' => app(PriceEstimator::class)->estimate($round),
            'catalog' => $this->filterProducts($products)
                ->sortBy(fn (Product $product): string => sprintf('%02d %s', $product->category?->sortOrder() ?? 99, $product->name))
                ->groupBy(fn (Product $product): string => $product->category instanceof ProductCategory ? $product->category->getLabel() : 'Sonstiges'),
            'categories' => $products->pluck('category')->filter()->unique()->sortBy(fn (ProductCategory $category): int => $category->sortOrder())->values(),
            'suppliers' => $products->pluck('supplier')->filter()->unique('id')->sortBy('name')->values(),
            'myItems' => $items->keyBy('product_id'),
            'demand' => $round->activeCartItems()->get()->groupBy('product_id'),
        ];
    }

    public function filterByCategory(?string $category): void
    {
        $this->category = $this->category === $category ? null : $category;
    }

    /**
     * @return Collection<int, Product>
     */
    protected function orderableProducts(Round $round): Collection
    {
        return $round->availableProductsForCart()->with(['supplier', 'priceTiers'])->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    protected function filterProducts(Collection $products): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $products
            ->when($search !== '', fn (Collection $products): Collection => $products->filter(
                fn (Product $product): bool => str_contains(mb_strtolower($product->name.' '.$product->supplier?->name.' '.$product->description), $search),
            ))
            ->when($this->category !== null, fn (Collection $products): Collection => $products->filter(fn (Product $product): bool => $product->category?->value === $this->category))
            ->when($this->supplier !== null, fn (Collection $products): Collection => $products->where('supplier_id', $this->supplier))
            ->values();
    }

    /**
     * The round the group is running — the one this cart belongs to.
     */
    public function getRunningRound(): ?Round
    {
        if (! $this->hasResolvedRunningRound) {
            $group = Filament::getTenant();

            $this->runningRound = $group instanceof Group
                ? $group->runningRound()?->load(['lead', 'participants'])
                : null;
            $this->hasResolvedRunningRound = true;
        }

        return $this->runningRound;
    }

    /**
     * Products from last time that are orderable now and not yet in the
     * cart, in whole portions of today's product.
     *
     * @return Collection<int, array{product_id: int, product_name: string, quantity: float, unit: string, round_title: string}>
     */
    public function previousSuggestions(?Round $round): Collection
    {
        if ($round === null) {
            return collect();
        }

        $inCart = $round->cartItems()->where('user_id', $this->currentUser()->id)->pluck('product_id')->all();
        $available = $round->availableProductsForCart()->pluck('products.id')->all();

        $suggestions = app(OrderHistory::class)
            ->previousQuantities($this->currentUser(), $round)
            ->filter(fn (array $suggestion): bool => in_array($suggestion['product_id'], $available, true)
                && ! in_array($suggestion['product_id'], $inCart, true));
        $products = Product::query()->whereIn('id', $suggestions->pluck('product_id'))->get()->keyBy('id');

        return $suggestions
            ->map(fn (array $suggestion): array => [
                ...$suggestion,
                'quantity' => $products->get($suggestion['product_id'])?->toWholePortions($suggestion['quantity']) ?? $suggestion['quantity'],
            ])
            ->values();
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function canShop(): bool
    {
        $round = $this->getRunningRound();

        return $round !== null && $this->currentUser()->can('shop', $round);
    }

    /**
     * Only the own items of the running round, and only while it is shopping.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function cartItemFromArguments(array $arguments): ?CartItem
    {
        $round = $this->getRunningRound();

        if ($round?->phase !== RoundPhase::Shopping) {
            return null;
        }

        return $round->cartItems()
            ->with('round')
            ->where('user_id', $this->currentUser()->id)
            ->find((int) ($arguments['item'] ?? 0));
    }

    /**
     * Product options grouped by category, with the supplier as suffix.
     *
     * @return array<string, array<int, string>>
     */
    protected function groupedProductOptions(Round $round): array
    {
        return $round->availableProductsForCart()
            ->with('supplier')
            ->get()
            ->sortBy(fn (Product $product): string => sprintf('%02d %s', $product->category?->sortOrder() ?? 99, $product->name))
            ->groupBy(fn (Product $product): string => $product->category instanceof ProductCategory ? $product->category->getLabel() : 'Sonstiges')
            ->map(fn (Collection $products): array => $products
                ->mapWithKeys(fn (Product $product): array => [
                    $product->id => $product->supplier ? $product->name.' · '.$product->supplier->name : $product->name,
                ])
                ->all())
            ->all();
    }

    protected function previousQuantityHint(Round $round, int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        $previous = app(OrderHistory::class)->previousQuantities($this->currentUser(), $round)->get($productId);

        return $previous
            ? sprintf('Letztes Mal (%s) hast du %s bekommen.', $previous['round_title'], CartItem::formatAmount($previous['quantity'], $previous['unit']))
            : null;
    }

    /**
     * Runs a cart operation; a broken rule becomes a danger notification.
     */
    protected function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Das hat nicht geklappt')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            return false;
        }
    }
}
