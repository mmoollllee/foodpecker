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
        'removed_at',
        'removed_by_user_id',
        'round_up_to_cents',
    ];

    protected function casts(): array
    {
        return [
            'removed' => 'boolean',
            'removed_at' => 'datetime',
            'round_up_to_cents' => 'integer',
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

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }
}
