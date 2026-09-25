<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

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
            'shipping_cents' => 'integer',
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

    /**
     * Proposals that are (or were) up for a vote: published or chosen.
     */
    public function scopeOpenForVoting(Builder $query): Builder
    {
        return $query->whereIn('status', [ProposalStatus::Published->value, ProposalStatus::Chosen->value]);
    }

    public function isDraft(): bool
    {
        return $this->status === ProposalStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === ProposalStatus::Published;
    }

    public function isProposedBy(User $user): bool
    {
        return $this->proposed_by_user_id === $user->getKey();
    }

    /**
     * Users who receive something from this proposal.
     *
     * @return Collection<int, int>
     */
    public function includedUserIds(): Collection
    {
        return $this->allocations()
            ->where('quantity', '>', 0)
            ->pluck('proposal_allocations.user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values();
    }
}
