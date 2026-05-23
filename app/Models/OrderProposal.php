<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class OrderProposal extends Model
{
    use HasActivities;

    protected $fillable = [
        'round_id',
        'proposed_by_user_id',
        'title',
        'status',
        'description',
        'shipping_cents',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class, 'proposal_id');
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProposalAllocation::class,
            ProposalItem::class,
            'proposal_id',
            'proposal_item_id',
        );
    }

    public function votes(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProposalVote::class,
            ProposalItem::class,
            'proposal_id',
            'proposal_item_id',
        );
    }

    public function isPublished(): bool
    {
        return $this->status === ProposalStatus::Published || $this->status === ProposalStatus::Chosen;
    }

    public function totalGoodsCents(): int
    {
        return (int) $this->items->sum('total_price_cents');
    }
}
