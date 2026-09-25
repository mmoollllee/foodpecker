<?php

namespace App\Services\Rounds;

use App\Enums\PaymentStatus;
use App\Enums\RoundPhase;
use App\Models\Payment;
use App\Models\RoundParticipant;
use App\Models\User;
use App\Services\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * "Bestellsumme aufrunden": participants may round up their own share —
 * the difference goes to the Foodpecker association on top of the fee.
 */
class RoundUpDonation
{
    public const NONE = 'none';

    public const FULL_EURO = 'euro';

    public const TEN_EUROS = 'ten';

    public const CUSTOM = 'custom';

    /**
     * Donation in cents for each rounding option of this payment.
     *
     * @return array<string, int>
     */
    public function options(Payment $payment): array
    {
        $amount = (int) $payment->amount_cents;

        return [
            self::NONE => 0,
            self::FULL_EURO => Money::roundUpTo($amount, 100) - $amount,
            self::TEN_EUROS => Money::roundUpTo($amount, 1000) - $amount,
        ];
    }

    /**
     * @param  int|null  $totalCents  The total somebody wants to pay, for the custom option.
     */
    public function apply(Payment $payment, User $user, string $mode, ?int $totalCents = null): int
    {
        $this->ensure($payment->user_id === $user->id, 'Du kannst nur deinen eigenen Anteil aufrunden.');
        $this->ensure($payment->status === PaymentStatus::Pending, 'Dein Anteil ist schon bezahlt — die Spende lässt sich nicht mehr ändern.');
        $this->ensure($payment->round->phase === RoundPhase::Payment, 'Aufrunden geht nur in der Zahlungsphase.');

        $amount = (int) $payment->amount_cents;

        [$step, $donation] = match ($mode) {
            self::FULL_EURO => [100, Money::roundUpTo($amount, 100) - $amount],
            self::TEN_EUROS => [1000, Money::roundUpTo($amount, 1000) - $amount],
            self::CUSTOM => [null, (int) $totalCents - $amount],
            default => [null, 0],
        };

        $this->ensure($donation >= 0, 'Der Betrag muss mindestens so hoch sein wie dein Anteil von '.Money::format($amount).'.');

        $payment->update(['round_up_donation_cents' => $donation]);

        RoundParticipant::query()
            ->where('round_id', $payment->round_id)
            ->where('user_id', $user->id)
            ->update(['round_up_to_cents' => $step]);

        return $donation;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['donation' => $message]);
        }
    }
}
