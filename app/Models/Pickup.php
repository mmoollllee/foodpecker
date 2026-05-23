<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pickup extends Model
{
    protected $fillable = [
        'round_id',
        'user_id',
        'pickup_date_id',
        'picked_up_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'picked_up_at' => 'datetime',
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

    public function pickupDate(): BelongsTo
    {
        return $this->belongsTo(PickupDate::class);
    }

    public function isPickedUp(): bool
    {
        return $this->picked_up_at !== null;
    }
}
