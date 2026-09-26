<?php

namespace App\Services\Rounds;

use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes cart items — the rules are the same no matter
 * whether somebody uses "Mein Warenkorb" or the round page.
 */
class CartService
{
    /**
     * @param  array{product_id: int|string, quantity_mode: string|QuantityMode, exact_quantity?: mixed, min_quantity?: mixed, max_quantity?: mixed, preferred_price_tier_id?: int|string|null, notes?: string|null}  $data
     */
    public function save(Round $round, User $user, array $data): CartItem
    {
        $this->ensure($round->phase === RoundPhase::Shopping, 'Warenkörbe können nur in der Einkaufsphase geändert werden.');
        $this->ensure($round->group->hasMember($user), 'Nur Mitglieder der Gruppe können mitbestellen.');

        $participant = $round->participantFor($user);

        $this->ensure(! $participant?->removed, 'Diese Person wurde aus der Runde ausgeschlossen.');
        $this->ensure(
            $participant !== null || ! $round->hasReachedParticipantLimit(),
            sprintf('Die Runde ist voll (maximal %d Teilnehmer).', $round->max_participants),
        );

        $productId = (int) $data['product_id'];
        $product = $round->availableProductsForCart()->whereKey($productId)->first();
        $this->ensure($product !== null, 'Dieses Produkt ist in dieser Runde nicht bestellbar.');

        $mode = $data['quantity_mode'] instanceof QuantityMode
            ? $data['quantity_mode']
            : QuantityMode::from($data['quantity_mode']);

        $quantities = $this->quantities($product, $mode, $data);
        $preferredTierId = $this->preferredTierId($productId, $data['preferred_price_tier_id'] ?? null);

        return DB::transaction(function () use ($round, $user, $productId, $mode, $quantities, $preferredTierId, $data): CartItem {
            RoundParticipant::firstOrCreate(['round_id' => $round->id, 'user_id' => $user->id]);

            return CartItem::updateOrCreate(
                ['round_id' => $round->id, 'user_id' => $user->id, 'product_id' => $productId],
                [
                    'quantity_mode' => $mode,
                    ...$quantities,
                    'preferred_price_tier_id' => $preferredTierId,
                    'notes' => $data['notes'] ?? null,
                ],
            );
        });
    }

    /**
     * People remove their own items; the lead may remove anybody's.
     */
    public function remove(CartItem $item, User $by): void
    {
        $round = $item->round;

        $this->ensure($round->phase === RoundPhase::Shopping, 'Warenkörbe können nur in der Einkaufsphase geändert werden.');
        $this->ensure(
            $item->user_id === $by->id || $round->isManagedBy($by),
            'Du kannst nur deine eigenen Artikel entfernen.',
        );

        $item->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{exact_quantity: float|null, min_quantity: float|null, max_quantity: float|null}
     */
    private function quantities(Product $product, QuantityMode $mode, array $data): array
    {
        if ($mode === QuantityMode::Exact) {
            $exact = (float) ($data['exact_quantity'] ?? 0);
            $this->ensure($exact > 0, 'Die Menge muss größer als 0 sein.');
            $this->ensureWholePortions($product, $exact);

            return ['exact_quantity' => $exact, 'min_quantity' => null, 'max_quantity' => null];
        }

        $min = (float) ($data['min_quantity'] ?? 0);
        $max = (float) ($data['max_quantity'] ?? 0);

        $this->ensure($min >= 0 && $max > 0, 'Bitte eine Mindest- und eine Maximalmenge angeben.');
        $this->ensure($min <= $max, 'Die Mindestmenge darf nicht größer als die Maximalmenge sein.');
        $this->ensureWholePortions($product, $min, $max);

        return ['exact_quantity' => null, 'min_quantity' => $min, 'max_quantity' => $max];
    }

    private function ensureWholePortions(Product $product, float ...$quantities): void
    {
        foreach ($quantities as $quantity) {
            $this->ensure($product->isWholePortions($quantity), sprintf(
                '%s wird in Portionen zu %s verteilt — bitte ein Vielfaches davon angeben.',
                $product->name,
                CartItem::formatAmount((float) $product->portionSize(), $product->unitLabel()),
            ));
        }
    }

    private function preferredTierId(int $productId, mixed $tierId): ?int
    {
        if (blank($tierId)) {
            return null;
        }

        return PriceTier::query()
            ->where('product_id', $productId)
            ->whereKey((int) $tierId)
            ->value('id');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['cart' => $message]);
        }
    }
}
