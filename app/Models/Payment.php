<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'round_id',
        'user_id',
        'amount_cents',
        'round_up_donation_cents',
        'status',
        'paid_at',
        'notes',
    ];

    /**
     * Mirrors the column defaults, so new payments are pending right away.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'round_up_donation_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'round_up_donation_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalCents(): int
    {
        return (int) $this->amount_cents + (int) $this->round_up_donation_cents;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [PaymentStatus::Paid, PaymentStatus::Waived], true);
    }
}
