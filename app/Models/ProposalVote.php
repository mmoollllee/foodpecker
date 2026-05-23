<?php

namespace App\Models;

use App\Enums\VoteValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalVote extends Model
{
    protected $table = 'proposal_votes';

    protected $fillable = [
        'proposal_item_id',
        'user_id',
        'value',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'value' => VoteValue::class,
        ];
    }

    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(ProposalItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
