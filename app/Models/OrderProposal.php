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
        'based_on_proposal_id',
        'title',
        'status',
        'description',
        'shipping_by_supplier',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'shipping_by_supplier' => 'array',
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

    /**
     * The proposal this one copies — as a new version or counter-proposal.
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_proposal_id');
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
     * Shipping of a supplier as recorded for this proposal — for drafts the
     * round's current value, frozen once the proposal is up for a vote.
     */
    public function shippingCentsFor(int $supplierId): int
    {
        return (int) ($this->shipping_by_supplier[$supplierId] ?? $this->shipping_by_supplier[(string) $supplierId] ?? 0);
    }

    /**
     * Whether the supplier's shipping is known — 0 means free shipping.
     */
    public function hasShippingFor(int $supplierId): bool
    {
        return array_key_exists($supplierId, $this->shipping_by_supplier ?? []);
    }

    public function totalShippingCents(): int
    {
        return (int) array_sum(array_map('intval', $this->shipping_by_supplier ?? []));
    }

    /**
     * Users who decide on at least one position: everybody who ordered one
     * of its products.
     *
     * @return Collection<int, int>
     */
    public function stakeholderIds(): Collection
    {
        return $this->allocations()
            ->pluck('proposal_allocations.user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values();
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
