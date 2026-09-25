<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\PaymentStatus;
use App\Enums\RoundPhase;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Payment;
use App\Models\Pickup;
use App\Models\PickupDate;
use App\Services\Money\Money;
use App\Services\Rounds\RoundUpDonation;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Payments (the lead ticks them off) and pickups (everybody picks a date
 * and may mark their own pickup; the lead may mark anybody's).
 */
trait InteractsWithFulfillment
{
    /**
     * @var array<int, RoundPhase>
     */
    protected static array $paymentPhases = [
        RoundPhase::Payment,
        RoundPhase::Ordering,
        RoundPhase::Delivery,
        RoundPhase::Pickup,
    ];

    public function markPaymentAction(): Action
    {
        return Action::make('markPayment')
            ->label(fn (array $arguments): string => $this->paymentFromArguments($arguments)?->status === PaymentStatus::Paid
                ? 'Doch offen'
                : 'Bezahlt')
            ->icon(fn (array $arguments) => $this->paymentFromArguments($arguments)?->status === PaymentStatus::Paid
                ? Heroicon::OutlinedArrowUturnLeft
                : Heroicon::OutlinedCheck)
            ->color(fn (array $arguments): string => $this->paymentFromArguments($arguments)?->status === PaymentStatus::Paid ? 'gray' : 'success')
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->paymentFromArguments($arguments) !== null
                && in_array($this->getRound()->phase, static::$paymentPhases, true)
                && $this->canManage())
            ->action(function (array $arguments): void {
                $payment = $this->paymentFromArguments($arguments);

                if (! $payment) {
                    return;
                }

                $paid = $payment->status !== PaymentStatus::Paid;

                $payment->update([
                    'status' => $paid ? PaymentStatus::Paid : PaymentStatus::Pending,
                    'paid_at' => $paid ? now() : null,
                ]);

                $this->getRound()->logActivity('payment_marked', [
                    'name' => $payment->user?->fullName(),
                    'status' => ($paid ? PaymentStatus::Paid : PaymentStatus::Pending)->getLabel(),
                ]);

                $this->refreshRound();
            });
    }

    public function waivePaymentAction(): Action
    {
        return Action::make('waivePayment')
            ->label('Erlassen')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->paymentFromArguments($arguments)?->status === PaymentStatus::Pending
                && in_array($this->getRound()->phase, static::$paymentPhases, true)
                && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Zahlung erlassen?')
            ->modalDescription('Z. B. wenn der Betrag verrechnet wurde. Die Zahlung zählt dann als erledigt.')
            ->action(function (array $arguments): void {
                $payment = $this->paymentFromArguments($arguments);

                if (! $payment) {
                    return;
                }

                $payment->update(['status' => PaymentStatus::Waived, 'paid_at' => null]);

                $this->getRound()->logActivity('payment_marked', [
                    'name' => $payment->user?->fullName(),
                    'status' => PaymentStatus::Waived->getLabel(),
                ]);

                $this->refreshRound();
            });
    }

    public function roundUpAction(): Action
    {
        return Action::make('roundUp')
            ->label('Aufrunden & spenden')
            ->icon(Heroicon::OutlinedHeart)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => ($payment = $this->myPayment()) !== null
                && $payment->status === PaymentStatus::Pending
                && $this->getRound()->phase === RoundPhase::Payment)
            ->modalHeading('Bestellsumme aufrunden')
            ->modalDescription(fn (): string => 'Dein Anteil beträgt '.Money::format((int) $this->myPayment()?->amount_cents).'. Was du aufrundest, geht zusätzlich zum Vereinsbeitrag als Spende an den Foodpecker-Verein — der Lead leitet es weiter.')
            ->modalSubmitActionLabel('Speichern')
            ->fillForm(fn (): array => [
                'mode' => match ($this->getRound()->participantFor($this->currentUser())?->round_up_to_cents) {
                    100 => RoundUpDonation::FULL_EURO,
                    1000 => RoundUpDonation::TEN_EUROS,
                    default => ($this->myPayment()?->round_up_donation_cents ?? 0) > 0 ? RoundUpDonation::CUSTOM : RoundUpDonation::NONE,
                },
                'total_cents' => $this->myPayment()?->totalCents(),
            ])
            ->schema([
                Radio::make('mode')
                    ->label('Wie möchtest du aufrunden?')
                    ->options(function (): array {
                        $options = app(RoundUpDonation::class)->options($this->myPayment());

                        return [
                            RoundUpDonation::NONE => 'Nicht aufrunden',
                            RoundUpDonation::FULL_EURO => 'Auf volle Euro (+'.Money::format($options[RoundUpDonation::FULL_EURO]).')',
                            RoundUpDonation::TEN_EUROS => 'Auf volle 10 Euro (+'.Money::format($options[RoundUpDonation::TEN_EUROS]).')',
                            RoundUpDonation::CUSTOM => 'Eigenen Betrag wählen',
                        ];
                    })
                    ->required()
                    ->live(),
                MoneyInput::make('total_cents')
                    ->label('Ich überweise insgesamt')
                    ->visible(fn (Get $get): bool => $get('mode') === RoundUpDonation::CUSTOM)
                    ->required(fn (Get $get): bool => $get('mode') === RoundUpDonation::CUSTOM),
            ])
            ->action(function (array $data): void {
                $payment = $this->myPayment();
                $donation = 0;

                $saved = $payment && $this->attempt(
                    function () use ($payment, $data, &$donation): void {
                        $donation = app(RoundUpDonation::class)->apply($payment, $this->currentUser(), $data['mode'], $data['total_cents'] ?? null);
                    },
                    'Aufrunden nicht möglich',
                );

                if ($saved) {
                    Notification::make()
                        ->title($donation > 0 ? 'Danke für deine Spende von '.Money::format($donation).'!' : 'Kein Aufrunden — alles klar.')
                        ->success()
                        ->send();
                }
            });
    }

    /**
     * What the lead forwards to the association: the fee of the final
     * order plus everybody's round-up donations.
     *
     * @return array{fee: int, donations: int}|null
     */
    public function associationTransfer(): ?array
    {
        $proposal = $this->getRound()->proposals->firstWhere('id', $this->getRound()->chosen_proposal_id);

        if ($proposal === null) {
            return null;
        }

        return [
            'fee' => $this->totalsFor($proposal)->platformFeeCents,
            'donations' => (int) $this->getRound()->payments->sum('round_up_donation_cents'),
        ];
    }

    protected function myPayment(): ?Payment
    {
        return $this->getRound()->payments->firstWhere('user_id', $this->currentUser()->id);
    }

    public function togglePickupAction(): Action
    {
        return Action::make('togglePickup')
            ->label(fn (array $arguments): string => $this->pickupFromArguments($arguments)?->isPickedUp() ? 'Doch nicht abgeholt' : 'Abgeholt')
            ->icon(fn (array $arguments) => $this->pickupFromArguments($arguments)?->isPickedUp() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBoxArrowDown)
            ->color(fn (array $arguments): string => $this->pickupFromArguments($arguments)?->isPickedUp() ? 'gray' : 'success')
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canChangePickup($this->pickupFromArguments($arguments))
                && in_array($this->getRound()->phase, [RoundPhase::Pickup, RoundPhase::Completed], true))
            ->action(function (array $arguments): void {
                $pickup = $this->pickupFromArguments($arguments);

                if (! $pickup) {
                    return;
                }

                $pickedUp = ! $pickup->isPickedUp();
                $pickup->update(['picked_up_at' => $pickedUp ? now() : null]);

                if ($pickedUp) {
                    $this->getRound()->logActivity('pickup_marked', ['name' => $pickup->user?->fullName()]);
                }

                $this->refreshRound();
            });
    }

    public function choosePickupDateAction(): Action
    {
        return Action::make('choosePickupDate')
            ->label(fn (array $arguments): string => $this->pickupFromArguments($arguments)?->pickup_date_id ? 'Termin ändern' : 'Termin wählen')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->canChangePickup($this->pickupFromArguments($arguments))
                && $this->getRound()->pickupDates->isNotEmpty()
                && in_array($this->getRound()->phase, [...static::$paymentPhases], true))
            ->modalHeading('Abholtermin wählen')
            ->modalWidth('md')
            ->fillForm(fn (array $arguments): array => [
                'pickup_date_id' => $this->pickupFromArguments($arguments)?->pickup_date_id,
            ])
            ->schema([
                Radio::make('pickup_date_id')
                    ->label('Wann holst du ab?')
                    ->options(fn (): array => $this->getRound()->pickupDates
                        ->mapWithKeys(fn (PickupDate $date): array => [
                            $date->id => $date->scheduled_at->translatedFormat('D, d.m.Y H:i').($date->location ? ' · '.$date->location : ''),
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $pickup = $this->pickupFromArguments($arguments);
                $dateId = $this->getRound()->pickupDates->firstWhere('id', (int) $data['pickup_date_id'])?->id;

                if (! $pickup || $dateId === null) {
                    return;
                }

                $pickup->update(['pickup_date_id' => $dateId]);

                Notification::make()->title('Abholtermin gespeichert.')->success()->send();
                $this->refreshRound();
            });
    }

    protected function canChangePickup(?Pickup $pickup): bool
    {
        return $pickup !== null
            && ($pickup->user_id === $this->currentUser()->id || $this->canManage());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function paymentFromArguments(array $arguments): ?Payment
    {
        return $this->getRound()->payments->firstWhere('id', (int) ($arguments['payment'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function pickupFromArguments(array $arguments): ?Pickup
    {
        return $this->getRound()->pickups->firstWhere('id', (int) ($arguments['pickup'] ?? 0));
    }
}
