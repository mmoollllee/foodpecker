<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's part in a round: when the lead asked for prices, when the
 * answer came, what shipping costs — and later when the order went out and
 * when the goods arrived.
 */
class RoundSupplier extends Model
{
    protected $fillable = [
        'round_id',
        'supplier_id',
        'inquired_at',
        'responded_at',
        'shipping_cents',
        'ordered_at',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'inquired_at' => 'datetime',
            'responded_at' => 'datetime',
            'shipping_cents' => 'integer',
            'ordered_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }

    public function isOrdered(): bool
    {
        return $this->ordered_at !== null;
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
