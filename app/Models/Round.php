<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Concerns\HasAttachments;
use App\Concerns\HasNotes;
use App\Enums\RoundPhase;
use Database\Factories\RoundFactory;
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

    public function participantUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'round_participants')
            ->withPivot(['removed', 'remove_reason', 'round_up_to_cents'])
            ->withTimestamps();
    }

    public function pickupDates(): HasMany
    {
        return $this->hasMany(PickupDate::class)->orderBy('scheduled_at');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
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

    public function isActive(): bool
    {
        return $this->phase->isActive();
    }

    public function phaseProgressPercent(): int
    {
        return (int) round(($this->phase->order() / 8) * 100);
    }

    public function cartItemsForUser(User $user)
    {
        return $this->cartItems()->where('user_id', $user->id);
    }
}
