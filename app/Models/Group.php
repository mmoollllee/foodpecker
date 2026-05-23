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
        'description',
        'contact_email',
        'locale',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if (blank($group->slug)) {
                $group->slug = static::generateUniqueSlug($group->name);
            }
        });
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

    public function manufacturers(): HasMany
    {
        return $this->hasMany(Manufacturer::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
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
}
