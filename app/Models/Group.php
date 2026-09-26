<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Enums\GroupRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasActivities, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'pending_owner_id',
        'owner_transfer_requested_at',
        'description',
        'contact_email',
        'locale',
    ];

    protected function casts(): array
    {
        return [
            'owner_transfer_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if (blank($group->slug)) {
                $group->slug = static::generateUniqueSlug($group->name);
            }
        });

        static::created(function (self $group): void {
            $group->ensureOwnerMembership();
            $group->logActivity('created', ['title' => $group->name], $group->owner);
        });

        static::updated(function (self $group): void {
            $fields = array_values(array_intersect(array_keys($group->getChanges()), ['name', 'slug', 'description', 'contact_email']));

            if ($fields !== []) {
                $group->logActivity('updated', ['fields' => $fields]);
            }
        });
    }

    /**
     * Everything logged on a group belongs to its own history.
     */
    protected function activityGroupId(): ?int
    {
        return $this->getKey();
    }

    /**
     * The owner is always a member with the owner role, so membership
     * queries never need to special-case `owner_id`.
     */
    public function ensureOwnerMembership(): void
    {
        if ($this->owner_id === null) {
            return;
        }

        $this->members()->syncWithoutDetaching([
            $this->owner_id => [
                'role' => GroupRole::Owner->value,
                'joined_at' => now(),
            ],
        ]);
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'gruppe';
        $slug = $base;
        $i = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The member asked to take the group over — until they agree, the
     * current owner stays in charge.
     */
    public function pendingOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user')
            ->using(GroupUser::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps()
            ->as('membership');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(GroupInvitation::class);
    }

    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->whereNull('accepted_at');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /**
     * The round the group is running right now — there is at most one.
     */
    public function runningRound(): ?Round
    {
        return $this->rounds()->running()->latest('phase_changed_at')->first();
    }

    public function groupActivities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function roleOf(User $user): ?GroupRole
    {
        if ($this->owner_id === $user->id) {
            return GroupRole::Owner;
        }

        $membership = $this->members()->where('users.id', $user->id)->first()?->membership;
        if (! $membership) {
            return null;
        }

        return $membership->role instanceof GroupRole
            ? $membership->role
            : GroupRole::from($membership->role);
    }

    public function userCan(User $user, string $permission): bool
    {
        $role = $this->roleOf($user);

        return $role?->can($permission) ?? false;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    /**
     * Select options for picking a member, e.g. as round lead.
     *
     * @param  array<int, int>  $exceptUserIds
     * @return array<int, string>
     */
    public function memberOptions(array $exceptUserIds = []): array
    {
        return $this->members()
            ->whereKeyNot($exceptUserIds)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName().' · '.$user->email])
            ->all();
    }
}
