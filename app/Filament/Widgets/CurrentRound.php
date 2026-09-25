<?php

namespace App\Filament\Widgets;

use App\Enums\RoundPhase;
use App\Filament\Pages\MyCart;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * A group runs one round at a time — this is it, at a glance. Without a
 * running round it points to the last one and to starting the next.
 */
class CurrentRound extends Widget
{
    protected string $view = 'filament.widgets.current-round';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Group || ! $user instanceof User) {
            return ['round' => null, 'lastRound' => null, 'myDraft' => null, 'canCreate' => false];
        }

        $round = $tenant->runningRound()?->load(['lead', 'participants', 'pickupDates']);

        if ($round === null) {
            return [
                'round' => null,
                'lastRound' => $tenant->rounds()
                    ->whereIn('phase', [RoundPhase::Completed->value, RoundPhase::Cancelled->value])
                    ->latest('phase_changed_at')
                    ->first(),
                'myDraft' => $tenant->rounds()->where('phase', RoundPhase::Draft->value)->where('lead_user_id', $user->id)->latest()->first(),
                'canCreate' => $user->can('create', Round::class),
                'urlFor' => fn (Round $round): string => RoundResource::getUrl('view', ['record' => $round]),
                'createUrl' => RoundResource::getUrl('create'),
            ];
        }

        return [
            'round' => $round,
            'url' => RoundResource::getUrl('view', ['record' => $round]),
            'cartUrl' => $user->can('shop', $round) ? MyCart::getUrl() : null,
            'dates' => $this->dates($round),
            'participation' => $this->participation($round, $user),
        ];
    }

    /**
     * The schedule of the round, with the date of the current phase marked.
     *
     * @return array<string, array{0: ?Carbon, 1: bool}>
     */
    private function dates(Round $round): array
    {
        return [
            'Einkauf bis' => [$round->shopping_deadline, $round->phase === RoundPhase::Shopping],
            'Verhandlung bis' => [$round->negotiation_deadline, $round->phase === RoundPhase::Negotiating],
            'Bestätigung bis' => [$round->finalization_deadline, $round->phase === RoundPhase::Finalizing],
            'Zahlung bis' => [$round->payment_deadline, $round->phase === RoundPhase::Payment],
            'Lieferung' => [$round->expected_delivery, in_array($round->phase, [RoundPhase::Ordering, RoundPhase::Delivery], true)],
        ];
    }

    /**
     * Where the current user stands in the round.
     *
     * @return array{text: string, color: string}
     */
    private function participation(Round $round, User $user): array
    {
        $participant = $round->participantFor($user);

        if ($participant?->removed) {
            return ['text' => 'Du bist aus dieser Bestellung ausgeschlossen: „'.$participant->remove_reason.'“', 'color' => 'danger'];
        }

        if ($participant === null) {
            return [
                'text' => $round->phase === RoundPhase::Shopping
                    ? 'Du bist noch nicht dabei — leg einfach etwas in deinen Warenkorb.'
                    : 'Du bestellst diesmal nicht mit.',
                'color' => 'gray',
            ];
        }

        $payment = $round->payments()->where('user_id', $user->id)->first();

        if ($payment !== null) {
            return ['text' => 'Dein Anteil: '.Money::format($payment->totalCents()).' · '.$payment->status->getLabel(), 'color' => 'success'];
        }

        $items = $round->cartItems()->where('user_id', $user->id)->count();

        return ['text' => $items > 0 ? "Du bist dabei — {$items} Artikel im Warenkorb." : 'Du bist dabei.', 'color' => 'success'];
    }
}
