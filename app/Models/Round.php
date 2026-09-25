<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Concerns\HasAttachments;
use App\Concerns\HasNotes;
use App\Enums\RoundPhase;
use Carbon\CarbonInterface;
use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    /** @use HasFactory<RoundFactory> */
    use HasActivities, HasAttachments, HasFactory, HasNotes;

    protected $fillable = [
        'group_id',
        'lead_user_id',
        'pending_lead_user_id',
        'lead_handover_requested_at',
        'title',
        'phase',
        'description',
        'shopping_deadline',
        'negotiation_deadline',
        'finalization_deadline',
        'payment_deadline',
        'expected_delivery',
        'pickup_location',
        'max_participants',
        'lead_fee_percent',
        'platform_fee_percent',
        'chosen_proposal_id',
        'phase_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'phase' => RoundPhase::class,
            'shopping_deadline' => 'date',
            'negotiation_deadline' => 'date',
            'finalization_deadline' => 'date',
            'payment_deadline' => 'date',
            'expected_delivery' => 'date',
            'phase_changed_at' => 'datetime',
            'lead_handover_requested_at' => 'datetime',
            'max_participants' => 'integer',
            'lead_fee_percent' => 'decimal:2',
            'platform_fee_percent' => 'decimal:2',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /**
     * Member asked to take over as lead, until they accept or decline.
     */
    public function pendingLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_lead_user_id');
    }

    public function chosenProposal(): BelongsTo
    {
        return $this->belongsTo(OrderProposal::class, 'chosen_proposal_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RoundParticipant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->where('removed', false);
    }

    /**
     * Product selection curated by the lead for this round. An empty
     * selection means every product visible to the group is available.
     */
    public function availableProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'round_product')->withTimestamps();
    }

    /**
     * A curated round stays curated even when all of its products have been
     * archived in the meantime — then nothing is orderable, not everything.
     */
    public function availableProductsForCart(): Builder
    {
        if ($this->availableProducts()->withTrashed()->exists()) {
            return $this->availableProducts()->getQuery();
        }

        return Product::visibleTo($this->group);
    }

    public function pickupDates(): HasMany
    {
        return $this->hasMany(PickupDate::class)->orderBy('scheduled_at');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Cart items of participants who have not been excluded from the round.
     */
    public function activeCartItems(): HasMany
    {
        return $this->cartItems()->whereNotIn(
            'user_id',
            RoundParticipant::query()
                ->select('user_id')
                ->whereColumn('round_participants.round_id', 'cart_items.round_id')
                ->where('removed', true),
        );
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(OrderProposal::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(Pickup::class);
    }

    public function notificationDrafts(): HasMany
    {
        return $this->hasMany(NotificationDraft::class)->latest();
    }

    /**
     * Rounds that are neither completed nor cancelled.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('phase', [RoundPhase::Completed->value, RoundPhase::Cancelled->value]);
    }

    /**
     * Started rounds that are neither completed nor cancelled. A group runs
     * at most one of them at a time; drafts may be prepared in the meantime.
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNotIn('phase', [
            RoundPhase::Draft->value,
            RoundPhase::Completed->value,
            RoundPhase::Cancelled->value,
        ]);
    }

    /**
     * Drafts are private to their lead; every other phase is visible to the group.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('phase', '!=', RoundPhase::Draft->value)
                ->orWhere('lead_user_id', $user->getKey());
        });
    }

    /**
     * Whether the round went through the given phase already.
     */
    public function hasPassed(RoundPhase $phase): bool
    {
        return $this->phase !== RoundPhase::Cancelled && $phase->order() < $this->phase->order();
    }

    /**
     * The date that matters for a phase: its deadline, the expected
     * delivery or the first pickup date.
     *
     * @return array{0: string, 1: CarbonInterface}|null Prefix like "bis" and the date.
     */
    public function dateFor(RoundPhase $phase): ?array
    {
        [$prefix, $date] = match ($phase) {
            RoundPhase::Shopping => ['bis', $this->shopping_deadline],
            RoundPhase::Negotiating => ['bis', $this->negotiation_deadline],
            RoundPhase::Finalizing => ['bis', $this->finalization_deadline],
            RoundPhase::Payment => ['bis', $this->payment_deadline],
            RoundPhase::Delivery => ['ca.', $this->expected_delivery],
            RoundPhase::Pickup => ['ab', $this->pickupDates->first()?->scheduled_at],
            RoundPhase::Completed => ['am', $this->phase === RoundPhase::Completed ? $this->phase_changed_at : null],
            default => [null, null],
        };

        return $date instanceof CarbonInterface ? [$prefix, $date] : null;
    }

    public function isLead(User $user): bool
    {
        return $this->lead_user_id === $user->getKey();
    }

    /**
     * The lead manages the round; the group owner may step in as fallback.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->isLead($user) || $this->group?->owner_id === $user->getKey();
    }

    public function participantFor(User $user): ?RoundParticipant
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->firstWhere('user_id', $user->getKey());
        }

        return $this->participants()->where('user_id', $user->getKey())->first();
    }

    public function isExcluded(User $user): bool
    {
        return (bool) $this->participantFor($user)?->removed;
    }

    /**
     * Number of participants who have not been excluded.
     */
    public function activeParticipantCount(): int
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->where('removed', false)->count();
        }

        return $this->activeParticipants()->count();
    }

    public function hasReachedParticipantLimit(): bool
    {
        return $this->max_participants !== null && $this->activeParticipantCount() >= $this->max_participants;
    }
}
