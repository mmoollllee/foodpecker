<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundParticipant extends Model
{
    protected $fillable = [
        'round_id',
        'user_id',
        'removed',
        'remove_reason',
        'round_up_to_cents',
    ];

    protected function casts(): array
    {
        return [
            'removed' => 'boolean',
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
}
