<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Schemas\CartItemForm;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Rounds\CartService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Carts on the round page. Participants change their own cart; the lead may
 * change anybody's (e.g. after a phone call) — by clicking a quantity in the
 * carts table or via "Artikel hinzufügen".
 */
trait InteractsWithCarts
{
    public function addCartItemAction(): Action
    {
        return Action::make('addCartItem')
            ->label('Artikel hinzufügen')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('primary')
            ->visible(fn (): bool => $this->getRound()->phase === RoundPhase::Shopping
                && ($this->canManage() || $this->currentUser()->can('shop', $this->getRound())))
            ->fillForm(fn (): array => [
                'user_id' => $this->currentUser()->id,
                'quantity_mode' => QuantityMode::Exact->value,
            ])
            ->schema([
                Select::make('user_id')
                    ->label('Für wen?')
                    ->options(fn (): array => $this->getRound()->group->memberOptions(
                        $this->getRound()->participants->where('removed', true)->pluck('user_id')->all(),
                    ))
                    ->searchable()
                    ->required()
                    ->visible(fn (): bool => $this->canManage())
                    ->columnSpanFull(),
                CartItemForm::productSelect(fn (): array => $this->getRound()->availableProductsForCart()
                    ->orderBy('name')
                    ->pluck('name', 'products.id')
                    ->all()),
                CartItemForm::productCard(),
                ...CartItemForm::quantityFields(),
                CartItemForm::preferredTierField(),
                CartItemForm::notesField(),
            ])
            ->modalSubmitActionLabel('In den Warenkorb')
            ->action(function (array $data): void {
                $user = $this->canManage() && filled($data['user_id'] ?? null)
                    ? User::findOrFail($data['user_id'])
                    : $this->currentUser();

                $saved = $this->attempt(
                    fn () => app(CartService::class)->save($this->getRound(), $user, $data),
                    'Artikel konnte nicht gespeichert werden',
                );

                if ($saved) {
                    Notification::make()->title('Artikel im Warenkorb gespeichert.')->success()->send();
                }
            });
    }

    /**
     * A click on a cell of the carts table: shows the product and changes
     * the quantity, or adds the product when the cell is still empty.
     */
    public function editCartItemAction(): Action
    {
        return Action::make('editCartItem')
            ->label('Menge ändern')
            ->visible(fn (array $arguments): bool => $this->cartProductFromArguments($arguments) !== null
                && $this->canEditCartOf($this->cartOwnerFromArguments($arguments)))
            ->modalHeading(function (array $arguments): string {
                $owner = $this->cartOwnerFromArguments($arguments);

                return $owner === null || $owner->is($this->currentUser()) ? 'Dein Warenkorb' : 'Warenkorb von '.$owner->fullName();
            })
            ->modalSubmitActionLabel(fn (array $arguments): string => $this->cartItemFromArguments($arguments) ? 'Speichern' : 'In den Warenkorb')
            ->fillForm(function (array $arguments): array {
                $item = $this->cartItemFromArguments($arguments);

                if ($item === null) {
                    return [
                        'product_id' => $this->cartProductFromArguments($arguments)?->id,
                        'quantity_mode' => QuantityMode::Exact->value,
                    ];
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
                CartItemForm::preferredTierField(),
                CartItemForm::notesField(),
            ])
            ->extraModalFooterActions(fn (array $arguments): array => $this->cartItemFromArguments($arguments) === null ? [] : [
                Action::make('removeCartItem')
                    ->label('Entfernen')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Aus dem Warenkorb entfernen?')
                    ->overlayParentActions()
                    ->cancelParentActions()
                    ->action(function () use ($arguments): void {
                        $item = $this->cartItemFromArguments($arguments);

                        if ($item && $this->attempt(fn () => app(CartService::class)->remove($item, $this->currentUser()), 'Entfernen nicht möglich')) {
                            Notification::make()->title('Artikel entfernt.')->success()->send();
                        }
                    }),
            ])
            ->action(function (array $data, array $arguments): void {
                $owner = $this->cartOwnerFromArguments($arguments);
                $product = $this->cartProductFromArguments($arguments);

                $saved = $owner && $product && $this->attempt(
                    fn () => app(CartService::class)->save($this->getRound(), $owner, [...$data, 'product_id' => $product->id]),
                    'Warenkorb konnte nicht gespeichert werden',
                );

                if ($saved) {
                    Notification::make()->title('Warenkorb gespeichert.')->success()->send();
                }
            });
    }

    /**
     * While shopping, the lead may change every cart, everybody else only
     * their own.
     */
    public function canEditCartOf(?User $owner): bool
    {
        if ($owner === null || $this->getRound()->phase !== RoundPhase::Shopping) {
            return false;
        }

        return $this->canManage()
            || ($owner->is($this->currentUser()) && $this->currentUser()->can('shop', $this->getRound()));
    }

    /**
     * Only people taking part in the round have a column in the carts table.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function cartOwnerFromArguments(array $arguments): ?User
    {
        $participant = $this->getRound()->participants->firstWhere('user_id', (int) ($arguments['user'] ?? 0));

        return $participant?->removed ? null : $participant?->user;
    }

    /**
     * A product somebody ordered in this round or that can be ordered in it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function cartProductFromArguments(array $arguments): ?Product
    {
        $productId = (int) ($arguments['product'] ?? 0);

        return $this->getRound()->cartItems->firstWhere('product_id', $productId)?->product
            ?? $this->getRound()->availableProductsForCart()->find($productId);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function cartItemFromArguments(array $arguments): ?CartItem
    {
        return $this->getRound()->cartItems->first(fn (CartItem $item): bool => (int) $item->product_id === (int) ($arguments['product'] ?? 0)
            && (int) $item->user_id === (int) ($arguments['user'] ?? 0));
    }
}
