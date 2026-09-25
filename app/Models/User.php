<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Mmoollllee\FilamentUserProfile\Concerns\InteractsWithProfilePhoto;
use Mmoollllee\FilamentUserProfile\Contracts\HasProfilePhoto;

class User extends Authenticatable implements FilamentUser, HasAvatar, HasName, HasProfilePhoto, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithProfilePhoto, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'nickname',
        'name',
        'email',
        'phone',
        'postal_code',
        'city',
        'household_size',
        'profile_photo_path',
        'password',
        'current_group_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'household_size' => 'integer',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->groups;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Group && $this->groups()->whereKey($tenant->getKey())->exists();
    }

    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->using(GroupUser::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps()
            ->as('membership');
    }

    public function currentGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'current_group_id');
    }

    public function roleIn(Group $group): ?GroupRole
    {
        return $group->roleOf($this);
    }

    public function canInGroup(?Group $group, string $permission): bool
    {
        if (! $group) {
            return false;
        }

        return $group->userCan($this, $permission);
    }

    public function isOwnerOf(Group $group): bool
    {
        return $group->owner_id === $this->id;
    }

    /**
     * People who share a group see each other's photo and contact details.
     */
    public function sharesGroupWith(User $other): bool
    {
        return $this->groups()
            ->whereIn('groups.id', $other->groups()->select('groups.id'))
            ->exists();
    }

    public function isModeratorIn(Group $group): bool
    {
        $role = $group->roleOf($this);

        return in_array($role, [GroupRole::Owner, GroupRole::Moderator], true);
    }

    public function getFilamentName(): string
    {
        return $this->fullName();
    }

    /**
     * "10827 Berlin", or null while unknown.
     */
    public function location(): ?string
    {
        $location = trim(($this->postal_code ?? '').' '.($this->city ?? ''));

        return $location !== '' ? $location : null;
    }

    /**
     * The mobile number as a tel: link — digits and a leading plus only.
     */
    public function telephoneUri(): ?string
    {
        $number = preg_replace('/(?!^\+)[^\d]/', '', trim((string) $this->phone));

        return filled($number) ? 'tel:'.$number : null;
    }

    public function fullName(): string
    {
        if (filled($this->first_name) || filled($this->last_name)) {
            return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        }

        return $this->attributes['name'] ?? $this->email;
    }
}
