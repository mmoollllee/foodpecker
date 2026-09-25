<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Schemas\CartItemForm;
use App\Models\User;
use App\Services\Rounds\CartService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Adding cart items from the round page. Participants add to their own
 * cart; the lead may also add for somebody else (e.g. after a phone call).
 */
trait InteractsWithCarts
{
    public function addCartItemAction(): Action
    {
        return Action::make('addCartItem')
            ->label('Artikel hinzufügen')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('primary')
            ->slideOver()
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
}
