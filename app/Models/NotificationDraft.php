<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDraft extends Model
{
    protected $fillable = [
        'round_id',
        'prepared_by_user_id',
        'kind',
        'subject',
        'body',
        'generated_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }
}
