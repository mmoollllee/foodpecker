<?php

namespace App\Models;

use App\Enums\GroupRole;
use Carbon\CarbonInterface;
use Database\Factories\GroupInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class GroupInvitation extends Model
{
    /** @use HasFactory<GroupInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'group_id',
        'invited_by_user_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
    ];

    /**
     * The token alone lets anybody join — it only ever leaves the server
     * inside the signed link.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => GroupRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            if (blank($invitation->token)) {
                $invitation->token = static::generateToken();
            }
            if ($invitation->expires_at === null) {
                $invitation->expires_at = Carbon::now()->addDays(
                    (int) config('foodpecker.invitations.expires_after_days', 14)
                );
            }
        });
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute(
            'invitations.accept',
            $this->expires_at ?? Carbon::now()->addDays(14),
            ['token' => $this->token],
        );
    }

    /**
     * Whoever holds the link may join — with an account under another
     * address, the invitation moves to that address. An invitation somebody
     * else accepted in the meantime stays as it is.
     */
    public function acceptAs(User $user): void
    {
        if ($this->isAccepted()) {
            return;
        }

        $email = mb_strtolower($user->email);

        if ($email !== mb_strtolower($this->email)) {
            $this->group->invitations()->whereKeyNot($this->id)->where('email', $email)->delete();
            $this->forceFill(['email' => $email])->save();
        }

        $this->accept($user);
    }

    public function accept(User $user): void
    {
        if ($this->isAccepted()) {
            return;
        }

        $exists = $this->group->members()->where('users.id', $user->id)->exists();

        if (! $exists) {
            $this->group->members()->attach($user->id, [
                'role' => $this->role->value,
                'joined_at' => now(),
            ]);

            $this->group->logActivity('member_joined', ['role' => $this->role->value], $user);
        }

        $this->forceFill(['accepted_at' => now()])->save();

        if ($user->current_group_id === null) {
            $user->forceFill(['current_group_id' => $this->group_id])->save();
        }
    }

    public function reissueToken(?CarbonInterface $expiresAt = null): void
    {
        $this->forceFill([
            'token' => static::generateToken(),
            'expires_at' => $expiresAt ?? Carbon::now()->addDays(14),
            'accepted_at' => null,
        ])->save();
    }
}
