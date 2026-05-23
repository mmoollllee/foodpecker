<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalAllocation extends Model
{
    protected $fillable = [
        'proposal_item_id',
        'user_id',
        'quantity',
        'share_cents',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
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
