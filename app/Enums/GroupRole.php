<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GroupRole: string implements HasColor, HasLabel
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Participant = 'participant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Moderator => 'Moderator',
            self::Participant => 'Teilnehmer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'amber',
            self::Moderator => 'info',
            self::Participant => 'gray',
        };
    }

    /**
     * Berechtigungs-Matrix. Erweiterbar. Wildcards mit ":*".
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Moderator => [
                'group:update',
                'group:invite',
                'group:manage-members',
                'supplier:*',
                'product:*',
                'round:*',
                'cart:*',
                'proposal:*',
                'note:*',
                'attachment:*',
            ],
            self::Participant => [
                'cart:write',
                'cart:view-all',
                'proposal:create',
                'proposal:vote',
                'note:create',
                'attachment:create',
                'round:view',
                'supplier:view',
                'product:view',
            ],
        };
    }

    public function can(string $permission): bool
    {
        foreach ($this->permissions() as $granted) {
            if ($granted === '*' || $granted === $permission) {
                return true;
            }
            if (str_ends_with($granted, ':*')) {
                $prefix = substr($granted, 0, -2);
                if (str_starts_with($permission, $prefix.':')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function assignable(): array
    {
        return [self::Moderator, self::Participant];
    }
}
